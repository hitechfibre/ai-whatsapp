<?php

declare(strict_types=1);

namespace HitechFibre\Services;

use HitechFibre\Core\Logger;
use RuntimeException;

/**
 * RespondIO API wrapper — sends WhatsApp messages through respond.io.
 *
 * Key operations:
 *  • sendMessage(): send a text reply to a contact
 *  • closeConversation(): close the conversation (triggers ticket creation via webhook)
 *  • assignConversation(): route to a human inbox
 */
class RespondIOService
{
    private const TIMEOUT    = 10;
    private const BASE_URL   = 'https://api.respond.io/v2';

    public function __construct(
        private readonly Logger $logger,
        private readonly string $apiToken,
        private readonly string $channelId,
    ) {}

    /**
     * Send a text message to a WhatsApp contact.
     *
     * @param string $phone          E.164 phone number (+27XXXXXXXXX)
     * @param string $message        The message text (WhatsApp markdown supported)
     * @param string $conversationId Optional respond.io conversation ID
     */
    public function sendMessage(string $phone, string $message, string $conversationId = ''): array
    {
        // Use conversation ID if available (faster), otherwise send to contact phone
        if ($conversationId) {
            $endpoint = "/conversation/{$conversationId}/messages";
        } else {
            $endpoint = "/contact/phone:{$this->sanitizePhone($phone)}/message";
        }

        $payload = [
            'message' => [
                'type'    => 'text',
                'text'    => $message,
            ],
            'channelId' => $this->channelId,
        ];

        try {
            $result = $this->post($endpoint, $payload);
            $this->logger->debug("RespondIO: message sent", [
                'phone' => $phone,
                'chars' => strlen($message),
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("RespondIO: send failed", [
                'phone' => $phone, 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Assign a conversation to a team / inbox for human handling.
     */
    public function assignToTeam(string $conversationId, string $teamId): bool
    {
        try {
            $this->post("/conversation/{$conversationId}/assign", [
                'assigneeType' => 'team',
                'assigneeId'   => $teamId,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RespondIO: assign failed", ['conv' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Add a note/comment to a conversation (visible to agents, not the customer).
     */
    public function addNote(string $conversationId, string $note): bool
    {
        try {
            $this->post("/conversation/{$conversationId}/notes", ['content' => $note]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RespondIO: note failed", ['conv' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Close a conversation — this triggers the respond.io webhook
     * with a "conversation_closed" event.
     */
    public function closeConversation(string $conversationId): bool
    {
        try {
            $this->post("/conversation/{$conversationId}/status", ['status' => 'resolved']);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("RespondIO: close failed", ['conv' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────

    private function post(string $path, array $data): array
    {
        $url = self::BASE_URL . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("RespondIO HTTP {$httpCode}: " . substr($body, 0, 200));
        }

        return json_decode($body, true) ?? [];
    }

    private function sanitizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        if (!str_starts_with($phone, '+')) {
            if (str_starts_with($phone, '27')) {
                $phone = '+' . $phone;
            } elseif (str_starts_with($phone, '0')) {
                $phone = '+27' . substr($phone, 1);
            }
        }
        return $phone;
    }
}
