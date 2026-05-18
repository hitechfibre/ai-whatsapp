<?php

declare(strict_types=1);

/**
 * HitechFibre AI WhatsApp Support — Webhook Entry Point
 *
 * Handles:
 *  • Incoming messages from respond.io (POST)
 *  • Conversation close events → creates Splynx ticket
 *  • Health check (GET)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HitechFibre\Core\Config;
use HitechFibre\Core\Database;
use HitechFibre\Core\Cache;
use HitechFibre\Core\Logger;
use HitechFibre\Webhook\EventFilter;
use HitechFibre\Bot\Engine;
use HitechFibre\Bot\StateMachine;
use HitechFibre\Bot\IntentDetector;
use HitechFibre\Bot\FlowManager;
use HitechFibre\Services\SplynxService;
use HitechFibre\Services\RespondIOService;

// ── Bootstrap ────────────────────────────────────────────────────────
$configPath = __DIR__ . '/../config/settings.json';
$config     = Config::load($configPath);

$db     = Database::getInstance($config->getArray('database'));
$cache  = Cache::getInstance($config->getArray('redis'));
$logger = Logger::getInstance([
    'log_dir'   => __DIR__ . '/../logs',
    'log_level' => $config->getString('log_level', 'info'),
]);

// ── Health check ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $secret = $config->getString('webhook.verify_token');
    if (isset($_GET['hub_challenge']) && ($_GET['hub_verify_token'] ?? '') === $secret) {
        http_response_code(200);
        echo $_GET['hub_challenge'];
        exit;
    }
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'ts' => time()]);
    exit;
}

// ── Accept POST only ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Respond 200 IMMEDIATELY so respond.io doesn't retry
http_response_code(200);
echo 'OK';

// Flush output to respond.io before doing any work
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_start();
    ob_end_flush();
    flush();
}

// ── Parse payload ────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!$payload) {
    $logger->warning("Webhook: invalid JSON payload", ['body' => substr($rawBody, 0, 200)]);
    exit;
}

$logger->debug("Webhook: received event", [
    'event_id' => $payload['id'] ?? '',
    'type'     => $payload['type'] ?? $payload['event'] ?? 'unknown',
]);

// ── Wire services ────────────────────────────────────────────────────
$filter   = new EventFilter($cache, $logger);
$splynx   = new SplynxService(
    $cache,
    $logger,
    $config->getString('splynx.api_url'),
    $config->getString('splynx.api_key'),
    $config->getString('splynx.api_secret'),
);
$respondIO = new RespondIOService(
    $logger,
    $config->getString('respond_io.api_token'),
    $config->getString('respond_io.channel_id'),
);
$sm      = new StateMachine($db, $cache, $logger);
$intent  = new IntentDetector();
$flow    = new FlowManager($sm, $splynx, $logger);
$engine  = new Engine($sm, $intent, $flow, $splynx, $respondIO, $logger, $config->all());

// ── Handle conversation-close event (create Splynx ticket) ───────────
$eventType = $payload['event'] ?? $payload['type'] ?? '';
if (in_array($eventType, ['conversation_resolved', 'conversation_closed', 'CONVERSATION_CLOSED'], true)) {
    handleConversationClose($payload, $sm, $splynx, $logger);
    exit;
}

// ── Filter and process ───────────────────────────────────────────────
if (!$filter->shouldProcess($payload)) {
    exit;
}

$phone          = $filter->extractPhone($payload);
$text           = $filter->extractText($payload);
$contactName    = $filter->extractContactName($payload);
$conversationId = $filter->extractConversationId($payload);

if (!$phone || !$text) {
    $logger->warning("Webhook: missing phone or text", ['payload_keys' => array_keys($payload)]);
    exit;
}

$logger->info("Webhook: processing message", ['phone' => $phone, 'text_len' => strlen($text)]);

try {
    $reply = $engine->handle($phone, $text, $contactName, $conversationId);
    if ($reply) {
        $filter->recordReply($phone);
    }
} catch (\Throwable $e) {
    $logger->error("Webhook: unhandled exception", [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'phone' => $phone,
    ]);
}

// ─────────────────────────────────────────────────────────────────────

/**
 * Called when respond.io closes a conversation.
 * Creates ONE Splynx ticket with the full transcript.
 */
function handleConversationClose(
    array $payload,
    StateMachine $sm,
    SplynxService $splynx,
    Logger $logger,
): void {
    $phone          = $payload['contact']['phone'] ?? $payload['phone'] ?? '';
    $conversationId = $payload['conversation']['id'] ?? $payload['conversation_id'] ?? '';

    if (!$phone) {
        $logger->warning("Conversation close: no phone in payload");
        return;
    }

    // Find the active session
    $session = $sm->getSession($phone, $conversationId);
    if (!$session || $session['state'] === StateMachine::S_CLOSED) {
        return; // Already handled or no session
    }

    $customer   = $sm->getCustomer($session);
    $intent     = $sm->getIntent($session);
    $transcript = $sm->buildTranscript($session);
    $issue      = $sm->getIssueDescription($session);

    $subject = sprintf(
        '[WhatsApp] %s — %s — %s',
        $phone,
        $customer['name'] ?? 'Unknown customer',
        $intent ?: 'General enquiry'
    );

    $ticketData = [
        'subject'     => $subject,
        'message'     => $transcript,
        'priority'    => $session['state'] === StateMachine::S_ESCALATED ? 'high' : 'medium',
        'type'        => match ($intent) {
            'tech_support' => 'problem',
            'accounts'     => 'task',
            'sales'        => 'feature',
            default        => 'question',
        },
    ];
    if ($customer) {
        $ticketData['customer_id'] = $customer['id'];
    }

    try {
        $ticketId = $splynx->createTicket($ticketData);
        if ($ticketId) {
            $sm->transition($session, StateMachine::S_CLOSED, "Ticket #{$ticketId} created");
            $session['splynx_ticket_id'] = $ticketId;
            $sm->saveSession($session);
            $logger->info("Ticket created on conversation close", ['ticket_id' => $ticketId, 'phone' => $phone]);
        }
    } catch (\Throwable $e) {
        $logger->error("Failed to create closing ticket", ['error' => $e->getMessage(), 'phone' => $phone]);
    }
}
