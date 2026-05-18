<?php

namespace HitechFibre\Services;

use HitechFibre\Core\Config;
use HitechFibre\Core\Logger;
use HitechFibre\Core\Cache;

class OpenAIService
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private float $temperature;
    private Logger $logger;
    private Cache $cache;

    private const SYSTEM_PROMPT = <<<PROMPT
You are a helpful support agent for HitechFibre, a South African fibre internet service provider.

Your role:
- Help customers with technical support, billing, and account queries
- Be friendly, professional, and concise (WhatsApp messages should be short)
- Use South African English naturally
- Never make up account details, service status, or pricing
- If you don't know something specific, say so and offer to connect them with a human agent

Company info:
- HitechFibre provides residential and business fibre internet in South Africa
- Support is available Mon-Fri 8am-5pm, Sat 8am-1pm (SAST)
- After hours, emergency technical issues are escalated

Tone guidelines:
- Warm but efficient — customers on WhatsApp want quick answers
- Use "Hi" not "Hello", "Thanks" not "Thank you very much"
- Short sentences. One idea per message.
- Never use corporate jargon

Format rules:
- Keep responses under 160 words
- Use plain text only (no markdown, no asterisks for bold)
- For lists, use simple numbering: 1. 2. 3.
- Never use emojis unless the customer used them first

When to escalate:
- Billing disputes or account credits
- Service outages affecting multiple customers
- Customer is clearly frustrated or angry
- Technical issue not resolved after basic troubleshooting
PROMPT;

    public function __construct()
    {
        $this->apiKey = Config::get('openai.api_key', '');
        $this->model = Config::get('openai.model', 'gpt-4o-mini');
        $this->maxTokens = Config::get('openai.max_tokens', 300);
        $this->temperature = Config::get('openai.temperature', 0.7);
        $this->logger = new Logger('openai');
        $this->cache = new Cache();
    }

    public function isEnabled(): bool
    {
        return !empty($this->apiKey) && Config::get('openai.enabled', false);
    }

    /**
     * Generate a response given conversation history and current context.
     *
     * @param array $conversationHistory Array of ['role' => 'user'|'assistant', 'content' => string]
     * @param array $context             Customer context: name, account, services, overdue status, etc.
     * @param string $department         Current department: tech_support|accounts|sales|general
     */
    public function generateResponse(
        array $conversationHistory,
        array $context = [],
        string $department = 'general'
    ): string {
        if (!$this->isEnabled()) {
            return '';
        }

        $systemPrompt = $this->buildSystemPrompt($context, $department);
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        // Append history (cap at last 10 exchanges to keep tokens low)
        $recent = array_slice($conversationHistory, -20);
        foreach ($recent as $entry) {
            if (isset($entry['role'], $entry['content']) && !empty(trim($entry['content']))) {
                $messages[] = [
                    'role' => $entry['role'],
                    'content' => substr($entry['content'], 0, 500), // truncate long messages
                ];
            }
        }

        // Cache key: hash of last 3 user messages (avoids re-calling for repeated inputs)
        $userMessages = array_filter($messages, fn($m) => $m['role'] === 'user');
        $cacheKey = 'openai:' . md5(json_encode(array_slice(array_values($userMessages), -3)));

        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            $this->logger->debug('OpenAI cache hit', ['key' => $cacheKey]);
            return $cached;
        }

        try {
            $response = $this->callAPI($messages);
            $text = $this->extractText($response);

            if ($text) {
                $this->cache->set($cacheKey, $text, 300); // cache 5 min
            }

            $this->logger->info('OpenAI response generated', [
                'department' => $department,
                'tokens' => $response['usage']['total_tokens'] ?? 0,
            ]);

            return $text;
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI API error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Classify intent from a single message (cheaper than full conversation).
     * Returns one of: tech_support, accounts, sales, human_request, greeting, unknown
     */
    public function classifyIntent(string $message): string
    {
        if (!$this->isEnabled()) {
            return 'unknown';
        }

        $cacheKey = 'openai:intent:' . md5($message);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $prompt = <<<PROMPT
Classify this WhatsApp message from a South African ISP customer into exactly one category.

Message: "{$message}"

Categories:
- tech_support: internet not working, slow speed, router issues, outage, fiber cut, no connection
- accounts: billing, payment, invoice, overdue, disconnection, upgrade, downgrade
- sales: new installation, sign up, move house, new address, pricing, packages
- human_request: wants to speak to human, agent, consultant
- greeting: just saying hi or hello with no specific issue
- unknown: cannot determine

Reply with ONLY the category name, nothing else.
PROMPT;

        try {
            $response = $this->callAPI([
                ['role' => 'system', 'content' => 'You classify customer support messages. Reply with one word only.'],
                ['role' => 'user', 'content' => $prompt],
            ], 10);

            $intent = strtolower(trim($this->extractText($response)));
            $valid = ['tech_support', 'accounts', 'sales', 'human_request', 'greeting', 'unknown'];

            if (!in_array($intent, $valid)) {
                $intent = 'unknown';
            }

            $this->cache->set($cacheKey, $intent, 600);
            return $intent;
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI intent classification error', ['error' => $e->getMessage()]);
            return 'unknown';
        }
    }

    /**
     * Summarise a full conversation into a ticket description.
     */
    public function summariseForTicket(array $transcript): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $transcriptText = '';
        foreach ($transcript as $entry) {
            $role = ucfirst($entry['role'] ?? 'unknown');
            $transcriptText .= "{$role}: {$entry['content']}\n";
        }

        $messages = [
            ['role' => 'system', 'content' => 'You summarise customer support conversations into concise ticket descriptions for ISP technicians.'],
            ['role' => 'user', 'content' => "Summarise this conversation into a 3-5 sentence ticket description. Include: issue reported, troubleshooting done, current status, and any urgency.\n\n{$transcriptText}"],
        ];

        try {
            $response = $this->callAPI($messages, 200);
            return $this->extractText($response);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI summarise error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function buildSystemPrompt(array $context, string $department): string
    {
        $prompt = self::SYSTEM_PROMPT;

        if (!empty($context)) {
            $prompt .= "\n\nCurrent customer context:\n";

            if (!empty($context['name'])) {
                $prompt .= "- Name: {$context['name']}\n";
            }
            if (!empty($context['account_id'])) {
                $prompt .= "- Account ID: {$context['account_id']}\n";
            }
            if (!empty($context['services'])) {
                $services = is_array($context['services'])
                    ? implode(', ', array_column($context['services'], 'description'))
                    : $context['services'];
                $prompt .= "- Services: {$services}\n";
            }
            if (!empty($context['overdue'])) {
                $prompt .= "- Account status: OVERDUE - do not promise reconnection, route to accounts\n";
            }
            if (!empty($context['address'])) {
                $prompt .= "- Address: {$context['address']}\n";
            }
        }

        $deptInstructions = match($department) {
            'tech_support' => "\n\nYou are handling a TECHNICAL SUPPORT query. Focus on: connection status, router troubleshooting, outage checks. Ask for one piece of info at a time.",
            'accounts'     => "\n\nYou are handling an ACCOUNTS query. Focus on: payment methods, invoice queries, reconnection. Do not make promises about waiving fees.",
            'sales'        => "\n\nYou are handling a SALES/INSTALLATIONS query. Focus on: coverage area, package options, installation timeline. Be enthusiastic but honest.",
            default        => '',
        };

        return $prompt . $deptInstructions;
    }

    private function callAPI(array $messages, int $maxTokens = 0): array
    {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens > 0 ? $maxTokens : $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('OpenAI API returned invalid JSON');
        }

        return $data;
    }

    private function extractText(array $response): string
    {
        return $response['choices'][0]['message']['content'] ?? '';
    }
}
