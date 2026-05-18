<?php

declare(strict_types=1);

namespace HitechFibre\Webhook;

use HitechFibre\Core\Cache;
use HitechFibre\Core\Logger;

/**
 * EventFilter guards the bot against:
 *  1. Duplicate webhook deliveries (respond.io sends the same event 2-4×)
 *  2. Outgoing messages (we only process INCOMING from contacts)
 *  3. Non-text events (images, docs, etc. get their own handler later)
 *  4. Rate-limit spam (minimum 8 s between replies per contact)
 *
 * ALL checks are idempotent and can safely run in parallel.
 */
class EventFilter
{
    private const DEDUP_TTL   = 120;  // seconds to remember event IDs
    private const RATE_TTL    = 30;   // rate-limit window in seconds
    private const MIN_GAP     = 8;    // minimum seconds between bot replies
    private const MAX_PER_WIN = 10;   // max messages per RATE_TTL window

    public function __construct(
        private readonly Cache  $cache,
        private readonly Logger $logger
    ) {}

    /**
     * Returns true if the event SHOULD be processed.
     * Logs the reason for any rejection.
     */
    public function shouldProcess(array $payload): bool
    {
        $eventId = $this->extractEventId($payload);
        $phone   = $this->extractPhone($payload);
        $traffic = $payload['traffic'] ?? $payload['message']['traffic'] ?? 'unknown';
        $sender  = $payload['sender']  ?? $payload['message']['sender']  ?? 'unknown';
        $type    = $payload['type']    ?? $payload['message']['type']    ?? 'unknown';

        // ── 1. Must be incoming from a contact ──────────────────────
        if ($traffic !== 'incoming') {
            $this->logger->debug("EventFilter: skip outgoing", ['event_id' => $eventId, 'traffic' => $traffic]);
            return false;
        }
        if ($sender !== 'contact') {
            $this->logger->debug("EventFilter: skip non-contact sender", ['event_id' => $eventId, 'sender' => $sender]);
            return false;
        }

        // ── 2. Text messages only (for now) ─────────────────────────
        if (!in_array($type, ['text', 'TEXT', 'message'], true)) {
            $this->logger->info("EventFilter: skip non-text event", ['event_id' => $eventId, 'type' => $type]);
            return false;
        }

        // ── 3. Deduplicate by event ID ───────────────────────────────
        if ($eventId && !$this->cache->setNx("dedup:{$eventId}", 1, self::DEDUP_TTL)) {
            $this->logger->debug("EventFilter: duplicate event dropped", ['event_id' => $eventId]);
            return false;
        }

        // ── 4. Deduplicate by content hash (respond.io sends same text twice) ──
        $text       = $this->extractText($payload);
        $contentKey = "content:{$phone}:" . md5($text);
        if (!$this->cache->setNx($contentKey, 1, 5)) {
            $this->logger->debug("EventFilter: duplicate content dropped", ['phone' => $phone, 'hash' => md5($text)]);
            return false;
        }

        // ── 5. Rate limit: max messages per window ───────────────────
        $rateKey = "rate:{$phone}";
        $count   = $this->cache->increment($rateKey, 1, self::RATE_TTL);
        if ($count > self::MAX_PER_WIN) {
            $this->logger->warning("EventFilter: rate limit hit", ['phone' => $phone, 'count' => $count]);
            return false;
        }

        // ── 6. Bot reply cooldown (prevent double-replies) ───────────
        $lastReplyKey = "last_reply:{$phone}";
        $lastReply    = (int) ($this->cache->get($lastReplyKey) ?? 0);
        if ($lastReply && (time() - $lastReply) < self::MIN_GAP) {
            $this->logger->debug("EventFilter: within cooldown window", [
                'phone'   => $phone,
                'seconds' => time() - $lastReply,
            ]);
            return false;
        }

        return true;
    }

    /** Record that we sent a reply — updates the cooldown clock. */
    public function recordReply(string $phone): void
    {
        $this->cache->set("last_reply:{$phone}", time(), self::RATE_TTL * 2);
    }

    public function extractPhone(array $payload): string
    {
        return $payload['contact']['phone']
            ?? $payload['message']['contact']['phone']
            ?? $payload['from']
            ?? '';
    }

    public function extractText(array $payload): string
    {
        return $payload['message']['text']
            ?? $payload['text']
            ?? $payload['message']['body']
            ?? $payload['body']
            ?? '';
    }

    public function extractContactName(array $payload): string
    {
        return $payload['contact']['name']
            ?? $payload['message']['contact']['name']
            ?? $payload['contact_name']
            ?? 'Customer';
    }

    public function extractConversationId(array $payload): string
    {
        return (string) (
            $payload['conversation']['id']
            ?? $payload['conversation_id']
            ?? $payload['message']['conversation_id']
            ?? ''
        );
    }

    private function extractEventId(array $payload): string
    {
        return (string) (
            $payload['event_id']
            ?? $payload['id']
            ?? $payload['message']['id']
            ?? ''
        );
    }
}
