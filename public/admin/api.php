<?php

/**
 * HitechFibre Admin API
 * Serves JSON data to the admin dashboard.
 * Auth: HTTP Basic over HTTPS or token header.
 */

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/vendor/autoload.php';

use HitechFibre\Core\Config;
use HitechFibre\Core\Env;
use HitechFibre\Core\Database;
use HitechFibre\Core\Logger;
use HitechFibre\Services\SplynxService;
use HitechFibre\Services\RespondIOService;

Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config/settings.json');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────
function authenticate(): void
{
    $adminUser = Config::get('admin.username', 'admin');
    $adminPass = Config::get('admin.password', '');

    // Token auth (from custom header — for JS clients)
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($token && $token === Config::get('admin.api_token', '')) {
        return;
    }

    // HTTP Basic
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

    if (!($user === $adminUser && password_verify($pass, $adminPass))) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="HitechFibre Admin"');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

authenticate();

// ── Route ─────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

try {
    $result = match ($action) {
        'stats'           => actionStats(),
        'conversations'   => actionConversations(),
        'messages'        => actionMessages(),
        'logs'            => actionLogs(),
        'analytics'       => actionAnalytics(),
        'settings'        => actionSettings(),
        'splynx_lookup'   => actionSplynxLookup(),
        'splynx_health'   => actionSplynxHealth(),
        'splynx_sync'     => actionSplynxSync(),
        'send_message'    => actionSendMessage($body),
        'takeover'        => actionTakeover($body),
        'close_conversation' => actionCloseConversation($body),
        'test_ticket'     => actionTestTicket($body),
        default           => throw new \InvalidArgumentException("Unknown action: {$action}"),
    };

    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Actions ───────────────────────────────────

function actionStats(): array
{
    $db = Database::getInstance();

    $active      = $db->query("SELECT COUNT(*) as c FROM conversations WHERE status='active'")->fetch()['c']  ?? 0;
    $escalated   = $db->query("SELECT COUNT(*) as c FROM conversations WHERE status='escalated'")->fetch()['c'] ?? 0;
    $closedToday = $db->query(
        "SELECT COUNT(*) as c FROM conversations WHERE status='closed' AND DATE(updated_at)=CURDATE()"
    )->fetch()['c'] ?? 0;
    $tickets     = $db->query(
        "SELECT COUNT(*) as c FROM tickets WHERE DATE(created_at)=CURDATE()"
    )->fetch()['c'] ?? 0;
    $afterHours  = $db->query(
        "SELECT COUNT(*) as c FROM conversations WHERE after_hours=1 AND status IN ('active','waiting')"
    )->fetch()['c'] ?? 0;
    $errorsToday = 0; // Would come from log file scan

    // Outage detection: >3 tech complaints in last 30 min
    $techRecent  = $db->query(
        "SELECT COUNT(*) as c FROM conversations 
         WHERE department='tech_support' AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    )->fetch()['c'] ?? 0;

    return [
        'active'          => (int)$active,
        'escalated'       => (int)$escalated,
        'closed_today'    => (int)$closedToday,
        'tickets_today'   => (int)$tickets,
        'after_hours'     => (int)$afterHours,
        'errors_today'    => (int)$errorsToday,
        'possible_outage' => $techRecent >= 3,
    ];
}

function actionConversations(): array
{
    $db = Database::getInstance();

    $status = $_GET['status'] ?? '';
    $dept   = $_GET['dept']   ?? '';
    $limit  = min((int)($_GET['limit'] ?? 40), 100);

    $where  = ['1=1'];
    $params = [];

    if ($status) {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    }
    if ($dept) {
        $where[]  = 'c.department = ?';
        $params[] = $dept;
    }

    $whereStr = implode(' AND ', $where);

    $rows = $db->query(
        "SELECT c.id, c.phone, c.customer_name, c.status, c.department,
                c.updated_at,
                (SELECT content FROM messages WHERE conversation_id=c.id ORDER BY created_at DESC LIMIT 1) as preview
         FROM conversations c
         WHERE {$whereStr}
         ORDER BY c.updated_at DESC
         LIMIT {$limit}",
        $params
    )->fetchAll();

    $conversations = array_map(function ($row) {
        return [
            'id'      => $row['id'],
            'phone'   => $row['phone'],
            'name'    => $row['customer_name'] ?? 'Unknown',
            'status'  => $row['status'],
            'dept'    => $row['department'],
            'preview' => $row['preview'] ? substr($row['preview'], 0, 80) : '',
            'time'    => formatRelativeTime($row['updated_at']),
        ];
    }, $rows);

    return ['conversations' => $conversations];
}

function actionMessages(): array
{
    $db     = Database::getInstance();
    $convId = $_GET['conv_id'] ?? '';

    if (!$convId) {
        return ['messages' => []];
    }

    $rows = $db->query(
        "SELECT role, content, created_at FROM messages 
         WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 100",
        [$convId]
    )->fetchAll();

    $messages = array_map(fn($r) => [
        'role'    => $r['role'],
        'content' => $r['content'],
        'time'    => date('H:i', strtotime($r['created_at'])),
    ], $rows);

    return ['messages' => $messages];
}

function actionLogs(): array
{
    $level = $_GET['level'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    $logger  = new Logger('webhook');
    $entries = $logger->getRecentEntries($limit);

    if ($level) {
        $levelOrder = ['error' => 3, 'warning' => 2, 'info' => 1, 'debug' => 0];
        $minLevel   = $levelOrder[$level] ?? 0;
        $entries    = array_filter($entries, fn($e) => ($levelOrder[$e['level'] ?? 'info'] ?? 0) >= $minLevel);
    }

    $errorCount = count(array_filter($entries, fn($e) => ($e['level'] ?? '') === 'error'));

    $formatted = array_map(fn($e) => [
        'time'    => isset($e['timestamp']) ? date('H:i:s', strtotime($e['timestamp'])) : '',
        'level'   => $e['level'] ?? 'info',
        'message' => $e['message'] ?? '',
    ], array_values($entries));

    return ['entries' => $formatted, 'error_count' => $errorCount];
}

function actionAnalytics(): array
{
    $db = Database::getInstance();

    $byDept = $db->query(
        "SELECT department, COUNT(*) as cnt FROM conversations 
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY department"
    )->fetchAll();

    $deptData = [];
    foreach ($byDept as $row) {
        $deptData[$row['department'] ?: 'unknown'] = (int)$row['cnt'];
    }

    // Resolution stats
    $total      = (int)($db->query("SELECT COUNT(*) as c FROM conversations WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch()['c'] ?? 0);
    $botClosed  = (int)($db->query("SELECT COUNT(*) as c FROM conversations WHERE status='closed' AND escalated=0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch()['c'] ?? 0);
    $escalated  = (int)($db->query("SELECT COUNT(*) as c FROM conversations WHERE escalated=1 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch()['c'] ?? 0);
    $avgMin     = (float)($db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,updated_at)) as a FROM conversations WHERE status='closed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch()['a'] ?? 0);

    // Daily breakdown
    $daily = $db->query(
        "SELECT DATE_FORMAT(created_at,'%a') as day, COUNT(*) as count 
         FROM conversations 
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at) ASC"
    )->fetchAll();

    return [
        'by_department' => $deptData,
        'resolution'    => [
            'resolved_by_bot' => $total > 0 ? round($botClosed / $total * 100) : 0,
            'escalated'       => $total > 0 ? round($escalated  / $total * 100) : 0,
            'abandoned'       => 0,
            'avg_duration_min' => round($avgMin),
        ],
        'daily' => array_map(fn($r) => ['day' => $r['day'], 'count' => (int)$r['count']], $daily),
    ];
}

function actionSettings(): array
{
    return [
        'Environment'      => Config::get('app.env', 'unknown'),
        'Database'         => ucfirst(Config::get('database.driver', 'sqlite')),
        'Redis'            => !empty(Config::get('redis.host')),
        'Splynx'           => !empty(Config::get('splynx.url')),
        'OpenAI'           => Config::get('openai.enabled', false),
        'Anti-spam delay'  => Config::get('bot.anti_spam_delay', 8) . ' seconds',
        'After-hours'      => Config::get('bot.after_hours_enabled', true),
        'Timezone'         => Config::get('business_hours.timezone', 'Africa/Johannesburg'),
    ];
}

function actionSplynxLookup(): array
{
    $phone   = $_GET['phone'] ?? '';
    $splynx  = new SplynxService();
    $customer = $splynx->findCustomerByPhone($phone);

    if (!$customer) {
        return ['found' => false, 'phone' => $phone];
    }

    // Also check overdue
    $overdue  = $splynx->isCustomerOverdue($customer['id']);
    $services = $splynx->getCustomerServices($customer['id']);

    return [
        'found'    => true,
        'customer' => $customer,
        'overdue'  => $overdue,
        'services' => $services,
    ];
}

function actionSplynxHealth(): array
{
    $splynx = new SplynxService();
    $start  = microtime(true);

    try {
        $result = $splynx->testConnection();
        $ms     = round((microtime(true) - $start) * 1000);
        return ['status' => 'ok', 'response_ms' => $ms, 'detail' => $result];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

function actionSplynxSync(): array
{
    $splynx = new SplynxService();
    $count  = $splynx->syncCustomerCache();
    return ['status' => 'ok', 'customers_synced' => $count];
}

function actionSendMessage(array $body): array
{
    $convId  = $body['conv_id']  ?? '';
    $message = $body['message']  ?? '';

    if (!$convId || !$message) {
        throw new \InvalidArgumentException('conv_id and message required');
    }

    $db      = Database::getInstance();
    $conv    = $db->query("SELECT * FROM conversations WHERE id=?", [$convId])->fetch();

    if (!$conv) {
        throw new \RuntimeException('Conversation not found');
    }

    $respondIO = new RespondIOService();
    $respondIO->sendMessage($conv['contact_id'], $message);

    // Save to DB
    $db->query(
        "INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?,?,?,NOW())",
        [$convId, 'agent', $message]
    );

    $logger = new Logger('admin');
    $logger->info('Agent sent message', ['conv_id' => $convId]);

    return ['status' => 'ok'];
}

function actionTakeover(array $body): array
{
    $convId = $body['conv_id'] ?? '';
    if (!$convId) throw new \InvalidArgumentException('conv_id required');

    $db = Database::getInstance();
    $db->query(
        "UPDATE conversations SET status='escalated', bot_paused=1, updated_at=NOW() WHERE id=?",
        [$convId]
    );

    $logger = new Logger('admin');
    $logger->info('Agent took over conversation', ['conv_id' => $convId]);

    return ['status' => 'ok'];
}

function actionCloseConversation(array $body): array
{
    $convId = $body['conv_id'] ?? '';
    if (!$convId) throw new \InvalidArgumentException('conv_id required');

    $db   = Database::getInstance();
    $conv = $db->query("SELECT * FROM conversations WHERE id=?", [$convId])->fetch();

    if (!$conv) throw new \RuntimeException('Conversation not found');

    // Create Splynx ticket if not already created
    if (empty($conv['ticket_id'])) {
        $messages = $db->query(
            "SELECT role, content, created_at FROM messages WHERE conversation_id=? ORDER BY created_at ASC",
            [$convId]
        )->fetchAll();

        $transcript = "Conversation #{$convId} — {$conv['phone']}\n";
        $transcript .= str_repeat('─', 50) . "\n";
        foreach ($messages as $msg) {
            $role = ucfirst($msg['role']);
            $time = date('H:i d/m/Y', strtotime($msg['created_at']));
            $transcript .= "[{$time}] {$role}: {$msg['content']}\n";
        }

        $splynx  = new SplynxService();
        $subject = "WhatsApp Support — {$conv['customer_name']} ({$conv['phone']})";
        $ticketId = $splynx->createTicket(
            $conv['splynx_customer_id'] ?? 0,
            $subject,
            $transcript
        );

        if ($ticketId) {
            $db->query("UPDATE conversations SET ticket_id=? WHERE id=?", [$ticketId, $convId]);
        }
    }

    $db->query(
        "UPDATE conversations SET status='closed', updated_at=NOW() WHERE id=?",
        [$convId]
    );

    return ['status' => 'ok'];
}

function actionTestTicket(array $body): array
{
    $splynx = new SplynxService();
    $result = $splynx->createTicket(
        1,
        'TEST: Admin Dashboard Ticket Test',
        "This is a test ticket created from the HitechFibre admin dashboard.\n\nTime: " . date('Y-m-d H:i:s')
    );
    return ['status' => 'ok', 'ticket_id' => $result];
}

// ── Helpers ──────────────────────────────────

function formatRelativeTime(string $datetime): string
{
    $diff = time() - strtotime($datetime);

    if ($diff < 60)          return 'just now';
    if ($diff < 3600)        return floor($diff / 60)   . 'm ago';
    if ($diff < 86400)       return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
