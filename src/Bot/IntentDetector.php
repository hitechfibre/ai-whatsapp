<?php

declare(strict_types=1);

namespace HitechFibre\Bot;

/**
 * IntentDetector classifies incoming messages.
 *
 * Returns one of: tech_support | accounts | sales | human_request |
 *                 affirmative | negative | greeting | unknown
 *
 * Designed to be fast (no external calls) — keyword + pattern matching.
 * Swap the classify() output to OpenAI when budget allows.
 */
class IntentDetector
{
    // ── Human handover triggers (highest priority) ───────────────────
    private const HUMAN_TRIGGERS = [
        'human', 'agent', 'consultant', 'person', 'operator', 'manager',
        'call me', 'phone me', 'speak to someone', 'talk to someone',
        'stop bot', 'stop spamming', 'real person', 'support agent',
        'i want to speak', 'connect me to',
    ];

    // ── Technical support keywords ───────────────────────────────────
    private const TECH_KEYWORDS = [
        'no internet', 'no connection', 'not working', 'offline', 'down',
        'slow', 'packet loss', 'latency', 'ping', 'buffering',
        'router', 'modem', 'lights', 'red light', 'orange light',
        'outage', 'signal', 'wifi', 'wi-fi', 'wireless', 'cable',
        'fibre cut', 'fiber cut', 'link down', 'cant connect',
        "can't connect", 'no signal', 'speed', 'mbps', 'data',
        'speed test', 'drop', 'disconnect', 'intermittent',
    ];

    // ── Accounts / billing keywords ──────────────────────────────────
    private const ACCOUNTS_KEYWORDS = [
        'payment', 'invoice', 'bill', 'debit', 'overdue', 'balance',
        'account', 'pay', 'paid', 'eft', 'bank', 'debit order',
        'suspended', 'statement', 'receipt', 'credit', 'refund',
        'reactivate', 'reconnect', 'finance', 'owing', 'arrears',
        'upgrade', 'downgrade', 'package', 'plan', 'price', 'cost',
        'cancel', 'cancellation', 'terminate',
    ];

    // ── Sales / new service keywords ─────────────────────────────────
    private const SALES_KEYWORDS = [
        'new connection', 'install', 'installation', 'new service',
        'sign up', 'sign-up', 'register', 'coverage', 'available',
        'quote', 'how much', 'packages', 'plans', 'fiber', 'fibre',
        'new client', 'interested', 'move', 'relocate', 'new address',
        'new house', 'new home',
    ];

    // ── Affirmative responses ────────────────────────────────────────
    private const AFFIRMATIVE = [
        'yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'sure', 'correct',
        'right', 'confirmed', 'done', 'ja', 'ya', 'affirmative',
        'of course', 'absolutely', 'i have', 'i did', 'have done',
        'i rebooted', 'i restarted', 'rebooted', 'restarted',
    ];

    // ── Negative responses ───────────────────────────────────────────
    private const NEGATIVE = [
        'no', 'nope', 'nah', 'not yet', 'i have not', 'haven\'t',
        'didnt', "didn't", 'cannot', "can't", 'negative',
    ];

    // ── Greetings ────────────────────────────────────────────────────
    private const GREETINGS = [
        'hi', 'hello', 'helo', 'hey', 'good morning', 'good afternoon',
        'good evening', 'morning', 'afternoon', 'evening', 'howzit',
        'howsit', 'greetings', 'hiya',
    ];

    /**
     * Classify a message. Returns the most likely intent.
     * Multiple intents can match; priority order is:
     *   human_request > tech_support > accounts > sales > affirmative > negative > greeting > unknown
     */
    public function classify(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = preg_replace('/[^a-z0-9\s\']/u', ' ', $text);

        // Human handover is always highest priority
        if ($this->matchesAny($text, self::HUMAN_TRIGGERS)) {
            return 'human_request';
        }

        $scores = [
            'tech_support' => $this->score($text, self::TECH_KEYWORDS),
            'accounts'     => $this->score($text, self::ACCOUNTS_KEYWORDS),
            'sales'        => $this->score($text, self::SALES_KEYWORDS),
        ];

        $max = max($scores);
        if ($max >= 1) {
            return array_search($max, $scores, true);
        }

        if ($this->matchesAny($text, self::AFFIRMATIVE)) return 'affirmative';
        if ($this->matchesAny($text, self::NEGATIVE))    return 'negative';
        if ($this->matchesAny($text, self::GREETINGS))   return 'greeting';

        return 'unknown';
    }

    /**
     * Extract confirmation of reboot from message text.
     */
    public function extractsRebootConfirmation(string $message): bool
    {
        $text = mb_strtolower($message);
        $rebootWords = ['reboot', 'restart', 'turned off', 'switched off', 'power cycle'];
        foreach ($rebootWords as $word) {
            if (str_contains($text, $word)) return true;
        }
        return $this->classify($message) === 'affirmative';
    }

    /**
     * Detect if message contains a router lights description.
     */
    public function extractsLightDescription(string $message): bool
    {
        $text  = mb_strtolower($message);
        $words = ['red', 'orange', 'green', 'blue', 'blinking', 'flashing',
                  'solid', 'off', 'lights', 'leds', 'light is', 'lights are',
                  'power light', 'internet light', 'fibre light'];
        foreach ($words as $word) {
            if (str_contains($text, $word)) return true;
        }
        return false;
    }

    /**
     * Extract a service address from message text.
     * Returns null if no clear address detected.
     */
    public function extractAddress(string $message): ?string
    {
        // South African address patterns: "123 Main Street, Pretoria" / "34 Elm Rd, Centurion"
        if (preg_match('/\d+\s+[A-Za-z]+(\s+[A-Za-z]+)?\s+(street|st|road|rd|avenue|ave|drive|dr|close|cl|crescent|cres|place|pl|lane|ln)\b/i', $message, $m)) {
            return trim($m[0]);
        }
        // Generic: if message is entirely an address (short, has a number)
        if (preg_match('/^\d+\s+\w/', $message) && strlen($message) < 100) {
            return trim($message);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────

    private function score(string $text, array $keywords): int
    {
        $score = 0;
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $score++;
            }
        }
        return $score;
    }

    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) return true;
        }
        return false;
    }
}
