<?php

declare(strict_types=1);

namespace HitechFibre\Bot;

use HitechFibre\Core\Database;
use HitechFibre\Core\Cache;
use HitechFibre\Core\Logger;

/**
 * Conversation State Machine
 *
 * States:
 *   NEW            → No conversation started yet
 *   IDENTIFIED     → Customer found in Splynx
 *   UNKNOWN        → Customer NOT found (ask for account number)
 *   TECH_SUPPORT   → Collecting technical fault details
 *   ACCOUNTS       → Billing / overdue / payment query
 *   SALES          → New connection / upgrade interest
 *   WAITING_INFO   → Waiting for customer to provide requested detail
 *   ESCALATED      → Handed over to human agent
 *   CLOSED         → Conversation ended (ticket created)
 *   AFTER_HOURS    → Outside business hours
 */
class StateMachine
{
    // ─── State constants ────────────────────────────────────────────
    public const S_NEW           = 'NEW';
    public const S_IDENTIFIED    = 'IDENTIFIED';
    public const S_UNKNOWN       = 'UNKNOWN';
    public const S_TECH          = 'TECH_SUPPORT';
    public const S_ACCOUNTS      = 'ACCOUNTS';
    public const S_SALES         = 'SALES';
    public const S_WAITING       = 'WAITING_INFO';
    public const S_ESCALATED     = 'ESCALATED';
    public const S_CLOSED        = 'CLOSED';
    public const S_AFTER_HOURS   = 'AFTER_HOURS';

    // ─── Context keys ───────────────────────────────────────────────
    // These track what info has been collected so we NEVER ask twice.
    private const CTX_ADDRESS_CONFIRMED  = 'address_confirmed';
    private const CTX_REBOOTED           = 'rebooted';
    private const CTX_LIGHTS_REPORTED    = 'lights_reported';
    private const CTX_ISSUE_DESCRIPTION  = 'issue_description';
    private const CTX_WAITING_FOR        = 'waiting_for';
    private const CTX_INTENT             = 'intent';
    private const CTX_OVERDUE            = 'overdue';
    private const CTX_CUSTOMER           = 'customer';
    private const CTX_ACCOUNT_NUMBER     = 'account_number';
    private const CTX_ESCALATION_REASON  = 'escalation_reason';
    private const CTX_HUMAN_REQUESTED    = 'human_requested';

    public function __construct(
        private readonly Database $db,
        private readonly Cache    $cache,
        private readonly Logger   $logger,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────────────

    /** Load or create a conversation session for a phone number. */
    public function getSession(string $phone, string $conversationId = ''): array
    {
        // Try DB first, then cache
        $session = $this->loadFromDb($phone);

        if (!$session) {
            $session = $this->createSession($phone, $conversationId);
        }

        return $session;
    }

    /** Persist updated session data. */
    public function saveSession(array $session): void
    {
        $this->db->update(
            'conversations',
            [
                'state'           => $session['state'],
                'context'         => json_encode($session['context'] ?? []),
                'messages'        => json_encode($session['messages'] ?? []),
                'last_message_at' => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ],
            ['phone' => $session['phone']]
        );

        // Also keep a hot copy in cache
        $this->cache->set("session:{$session['phone']}", $session, 600);
    }

    /** Transition to a new state, recording the reason. */
    public function transition(array &$session, string $newState, string $reason = ''): void
    {
        $old = $session['state'];
        $session['state'] = $newState;
        $session['state_history'][] = [
            'from'   => $old,
            'to'     => $newState,
            'reason' => $reason,
            'at'     => date('Y-m-d H:i:s'),
        ];
        $this->logger->info("State transition", [
            'phone'  => $session['phone'],
            'from'   => $old,
            'to'     => $newState,
            'reason' => $reason,
        ]);
    }

    /** Append a message to the conversation transcript. */
    public function addMessage(array &$session, string $role, string $text): void
    {
        $session['messages'][] = [
            'role' => $role, // 'customer' | 'bot' | 'agent'
            'text' => $text,
            'at'   => date('Y-m-d H:i:s'),
        ];
    }

    /** Check a context flag — prevents asking the same question twice. */
    public function has(array $session, string $key): bool
    {
        return !empty($session['context'][$key]);
    }

    /** Set a context flag or value. */
    public function set(array &$session, string $key, mixed $value): void
    {
        $session['context'][$key] = $value;
    }

    /** Get a context value. */
    public function ctx(array $session, string $key, mixed $default = null): mixed
    {
        return $session['context'][$key] ?? $default;
    }

    // ─── Convenience wrappers ────────────────────────────────────────

    public function setCustomer(array &$session, array $customer): void
    {
        $session['context'][self::CTX_CUSTOMER] = $customer;
        $session['splynx_id'] = $customer['id'] ?? null;
    }

    public function getCustomer(array $session): ?array
    {
        return $session['context'][self::CTX_CUSTOMER] ?? null;
    }

    public function setOverdue(array &$session, bool $overdue): void
    {
        $session['context'][self::CTX_OVERDUE] = $overdue;
    }

    public function isOverdue(array $session): bool
    {
        return (bool) ($session['context'][self::CTX_OVERDUE] ?? false);
    }

    public function setAddressConfirmed(array &$session, bool $confirmed = true): void
    {
        $session['context'][self::CTX_ADDRESS_CONFIRMED] = $confirmed;
    }

    public function addressConfirmed(array $session): bool
    {
        return (bool) ($session['context'][self::CTX_ADDRESS_CONFIRMED] ?? false);
    }

    public function setRebooted(array &$session, bool $rebooted = true): void
    {
        $session['context'][self::CTX_REBOOTED] = $rebooted;
    }

    public function hasRebooted(array $session): bool
    {
        return (bool) ($session['context'][self::CTX_REBOOTED] ?? false);
    }

    public function setLightsReported(array &$session, bool $reported = true): void
    {
        $session['context'][self::CTX_LIGHTS_REPORTED] = $reported;
    }

    public function lightsReported(array $session): bool
    {
        return (bool) ($session['context'][self::CTX_LIGHTS_REPORTED] ?? false);
    }

    public function setIssueDescription(array &$session, string $desc): void
    {
        $session['context'][self::CTX_ISSUE_DESCRIPTION] = $desc;
    }

    public function getIssueDescription(array $session): string
    {
        return $session['context'][self::CTX_ISSUE_DESCRIPTION] ?? '';
    }

    public function setIntent(array &$session, string $intent): void
    {
        $session['context'][self::CTX_INTENT] = $intent;
    }

    public function getIntent(array $session): string
    {
        return $session['context'][self::CTX_INTENT] ?? '';
    }

    public function setWaitingFor(array &$session, string $field): void
    {
        $session['context'][self::CTX_WAITING_FOR] = $field;
    }

    public function getWaitingFor(array $session): string
    {
        return $session['context'][self::CTX_WAITING_FOR] ?? '';
    }

    public function setHumanRequested(array &$session, bool $v = true): void
    {
        $session['context'][self::CTX_HUMAN_REQUESTED] = $v;
    }

    public function humanRequested(array $session): bool
    {
        return (bool) ($session['context'][self::CTX_HUMAN_REQUESTED] ?? false);
    }

    public function setEscalationReason(array &$session, string $reason): void
    {
        $session['context'][self::CTX_ESCALATION_REASON] = $reason;
    }

    /**
     * Returns true if ALL required tech-support info has been collected,
     * meaning the bot can safely hand off to a human tech.
     */
    public function techInfoComplete(array $session): bool
    {
        return $this->addressConfirmed($session)
            && $this->hasRebooted($session)
            && $this->lightsReported($session)
            && !empty($this->getIssueDescription($session));
    }

    /** Build a clean transcript string for attaching to a ticket. */
    public function buildTranscript(array $session): string
    {
        $lines   = ["=== HitechFibre WhatsApp Support Transcript ==="];
        $lines[] = "Customer : " . ($session['contact_name'] ?? 'Unknown');
        $lines[] = "Phone    : " . $session['phone'];

        $customer = $this->getCustomer($session);
        if ($customer) {
            $lines[] = "Splynx ID: " . ($customer['id']          ?? 'N/A');
            $lines[] = "Account  : " . ($customer['login']        ?? 'N/A');
            $lines[] = "Status   : " . ($customer['status']       ?? 'N/A');
        }

        $lines[] = "Intent   : " . $this->getIntent($session);
        $lines[] = "Started  : " . ($session['created_at'] ?? '');
        $lines[] = str_repeat('-', 60);

        foreach ($session['messages'] ?? [] as $msg) {
            $prefix = match($msg['role']) {
                'customer' => "👤 Customer",
                'bot'      => "🤖 Bot     ",
                'agent'    => "👷 Agent   ",
                default    => "   " . $msg['role'],
            };
            $lines[] = "[{$msg['at']}] {$prefix}: {$msg['text']}";
        }

        $lines[] = str_repeat('=', 60);
        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────

    private function loadFromDb(string $phone): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM conversations WHERE phone = ? AND state NOT IN (?, ?) ORDER BY created_at DESC LIMIT 1",
            [$phone, self::S_CLOSED, self::S_ESCALATED]
        );
        if (!$row) return null;

        $row['context']       = json_decode($row['context']       ?? '{}', true) ?? [];
        $row['messages']      = json_decode($row['messages']       ?? '[]', true) ?? [];
        $row['state_history'] = json_decode($row['state_history']  ?? '[]', true) ?? [];

        return $row;
    }

    private function createSession(string $phone, string $conversationId): array
    {
        $id = $this->db->insert('conversations', [
            'phone'           => $phone,
            'conversation_id' => $conversationId,
            'state'           => self::S_NEW,
            'context'         => '{}',
            'messages'        => '[]',
            'state_history'   => '[]',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'              => $id,
            'phone'           => $phone,
            'conversation_id' => $conversationId,
            'state'           => self::S_NEW,
            'context'         => [],
            'messages'        => [],
            'state_history'   => [],
            'created_at'      => date('Y-m-d H:i:s'),
        ];
    }
}
