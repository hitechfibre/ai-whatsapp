<?php

declare(strict_types=1);

namespace HitechFibre\Services;

use HitechFibre\Core\Cache;
use HitechFibre\Core\Logger;
use RuntimeException;

/**
 * SplynxService — wraps the Splynx REST API.
 *
 * Key design decisions:
 *  • Customer lookup always pulls the FULL list and filters locally.
 *    (Splynx v3.x phone-filter endpoint is unreliable — confirmed in production.)
 *  • Full list is cached for CUSTOMER_CACHE_TTL seconds to avoid hammering the API.
 *  • Ticket creation MUST use form-encoded body, NOT JSON (Splynx returns HTTP 500 otherwise).
 *  • All HTTP calls use a short timeout + retry with exponential backoff.
 */
class SplynxService
{
    private const CUSTOMER_CACHE_TTL = 300; // 5 minutes
    private const REQUEST_TIMEOUT    = 10;  // seconds per request
    private const MAX_RETRIES        = 2;

    public function __construct(
        private readonly Cache  $cache,
        private readonly Logger $logger,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    //  Customer lookup
    // ─────────────────────────────────────────────────────────────────

    /**
     * Find a customer by their WhatsApp phone number.
     * Returns null if not found.
     */
    public function findByPhone(string $phone): ?array
    {
        $normalized = $this->normalizePhone($phone);

        // Check per-phone cache first (very hot path)
        $cacheKey = "customer:phone:{$normalized}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached === 'NOT_FOUND' ? null : $cached;
        }

        $customers = $this->getAllCustomers();

        foreach ($customers as $customer) {
            $phones = $this->extractPhones($customer);
            foreach ($phones as $p) {
                if ($this->normalizePhone($p) === $normalized) {
                    $this->cache->set($cacheKey, $customer, self::CUSTOMER_CACHE_TTL);
                    $this->logger->info("Splynx: customer found by phone", [
                        'phone' => $phone, 'id' => $customer['id'],
                    ]);
                    return $customer;
                }
            }
        }

        // Cache negative result briefly to avoid repeat lookups
        $this->cache->set($cacheKey, 'NOT_FOUND', 60);
        $this->logger->info("Splynx: no customer found for phone", ['phone' => $phone]);
        return null;
    }

    /**
     * Check if a customer's account is overdue.
     * Returns true if any service is blocked/suspended.
     */
    public function isAccountOverdue(string|int $customerId): bool
    {
        $cacheKey = "customer:overdue:{$customerId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return (bool) $cached;

        try {
            $services = $this->apiGet("/admin/customers/customer/{$customerId}/services/internet");
            $overdue  = false;
            foreach ($services as $service) {
                $status = strtolower($service['status'] ?? '');
                if (in_array($status, ['blocked', 'blocked_administratively', 'suspended'], true)) {
                    $overdue = true;
                    break;
                }
            }

            // Also check invoices
            if (!$overdue) {
                $invoices = $this->apiGet("/admin/customers/customer/{$customerId}/finance/invoices");
                foreach ($invoices as $invoice) {
                    if (($invoice['status'] ?? '') === 'unpaid') {
                        $overdue = true;
                        break;
                    }
                }
            }

            $this->cache->set($cacheKey, $overdue, 120);
            return $overdue;
        } catch (\Throwable $e) {
            $this->logger->error("Splynx: overdue check failed", ['customer_id' => $customerId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Ticket creation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Create a support ticket in Splynx.
     *
     * CRITICAL: Splynx rejects JSON bodies with HTTP 500.
     * This method ALWAYS sends application/x-www-form-urlencoded.
     */
    public function createTicket(array $data): ?string
    {
        $required = ['subject', 'message'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Ticket field '{$field}' is required.");
            }
        }

        $payload = [
            'subject'     => $data['subject'],
            'message'     => $data['message'],
            'priority'    => $data['priority']    ?? 'medium',
            'status'      => $data['status']       ?? 'new',
            'type'        => $data['type']          ?? 'question',
        ];
        if (!empty($data['customer_id'])) {
            $payload['customer_id'] = (int) $data['customer_id'];
        }
        if (!empty($data['assignee_id'])) {
            $payload['assignee_id'] = (int) $data['assignee_id'];
        }

        try {
            $result = $this->apiPost('/admin/tickets/ticket', $payload, 'form');
            $ticketId = $result['id'] ?? $result['ticket_id'] ?? null;
            $this->logger->info("Splynx: ticket created", ['ticket_id' => $ticketId]);
            return $ticketId ? (string) $ticketId : null;
        } catch (\Throwable $e) {
            $this->logger->error("Splynx: ticket creation failed", ['error' => $e->getMessage(), 'data' => $payload]);
            return null;
        }
    }

    /**
     * Add a reply/note to an existing ticket.
     */
    public function addTicketReply(string $ticketId, string $message, string $type = 'note'): bool
    {
        try {
            $this->apiPost("/admin/tickets/ticket/{$ticketId}/messages", [
                'message' => $message,
                'type'    => $type,
            ], 'form');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Splynx: add ticket reply failed", ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get ALL customers, cached aggressively.
     * This is the known-working strategy for phone lookup.
     */
    private function getAllCustomers(): array
    {
        $cacheKey = 'splynx:all_customers';
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        try {
            $customers = $this->apiGet('/admin/customers/customer', ['limit' => 5000]);
            $this->cache->set($cacheKey, $customers, self::CUSTOMER_CACHE_TTL);
            $this->logger->info("Splynx: fetched customer list", ['count' => count($customers)]);
            return $customers;
        } catch (\Throwable $e) {
            $this->logger->error("Splynx: failed to fetch customers", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Normalize a South African phone number to a canonical form: 0XXXXXXXXX */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (str_starts_with($phone, '+27')) {
            return '0' . substr($phone, 3);
        }
        if (str_starts_with($phone, '27') && strlen($phone) === 11) {
            return '0' . substr($phone, 2);
        }
        return $phone;
    }

    /** Extract all phone fields from a Splynx customer record. */
    private function extractPhones(array $customer): array
    {
        $phones = [];
        foreach (['phone', 'mobile', 'phone_number', 'work_phone', 'cell'] as $field) {
            if (!empty($customer[$field])) {
                $phones[] = $customer[$field];
            }
        }
        // Contacts may be nested
        foreach ($customer['contacts'] ?? [] as $contact) {
            foreach (['phone', 'mobile', 'phone_number'] as $field) {
                if (!empty($contact[$field])) {
                    $phones[] = $contact[$field];
                }
            }
        }
        return array_unique(array_filter($phones));
    }

    // ─────────────────────────────────────────────────────────────────
    //  HTTP abstraction
    // ─────────────────────────────────────────────────────────────────

    private function apiGet(string $path, array $query = []): array
    {
        $url = rtrim($this->apiUrl, '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    private function apiPost(string $path, array $data, string $encoding = 'form'): array
    {
        $url = rtrim($this->apiUrl, '/') . $path;
        return $this->request('POST', $url, $data, $encoding);
    }

    private function request(string $method, string $url, array $data = [], string $encoding = 'form'): array
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt <= self::MAX_RETRIES) {
            $attempt++;
            $ch = curl_init();

            $headers = [
                'Authorization: Basic ' . base64_encode("{$this->apiKey}:{$this->apiSecret}"),
            ];

            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ];

            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                if ($encoding === 'json') {
                    $headers[]              = 'Content-Type: application/json';
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                } else {
                    // IMPORTANT: Splynx requires form-encoded for ticket creation
                    $headers[]              = 'Content-Type: application/x-www-form-urlencoded';
                    $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
                }
                $opts[CURLOPT_HTTPHEADER] = $headers;
            }

            curl_setopt_array($ch, $opts);
            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $lastError = $error;
                if ($attempt <= self::MAX_RETRIES) {
                    sleep(min(2 ** ($attempt - 1), 8)); // 1s, 2s, 4s...
                    continue;
                }
                break;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($body, true);
                return is_array($decoded) ? $decoded : [];
            }

            $lastError = "HTTP {$httpCode}: " . substr($body, 0, 200);
            $this->logger->warning("Splynx API non-2xx", ['url' => $url, 'code' => $httpCode]);

            if ($httpCode >= 500 && $attempt <= self::MAX_RETRIES) {
                sleep(min(2 ** ($attempt - 1), 8));
                continue;
            }
            break;
        }

        throw new RuntimeException("Splynx API error after {$attempt} attempts: {$lastError}");
    }
    // ── Admin/utility methods ─────────────────────────

    public function syncCustomerCache(): int
    {
        $this->cache->delete('splynx:customers');
        $customers = $this->fetchAllCustomers();
        return count($customers);
    }

    public function testConnection(): array
    {
        $start = microtime(true);
        $data  = $this->apiGet('/admin/customers/customer?items_per_page=1');
        $ms    = round((microtime(true) - $start) * 1000);
        return ['status' => 'ok', 'response_ms' => $ms, 'sample' => array_slice($data, 0, 1)];
    }

    public function getCustomerServices(int $customerId): array
    {
        $cacheKey = "splynx:services:{$customerId}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached) return json_decode($cached, true) ?? [];
        try {
            $services = $this->apiGet("/admin/customers/customer/{$customerId}/internet-services");
            $this->cache->set($cacheKey, json_encode($services), 120);
            return $services;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch services', ['customer_id' => $customerId]);
            return [];
        }
    }

}
