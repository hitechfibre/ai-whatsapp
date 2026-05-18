<?php

declare(strict_types=1);

namespace HitechFibre\Core;

/**
 * Lightweight PSR-3-compatible logger.
 * Writes structured JSON lines to a rotating daily log file.
 */
class Logger
{
    private static ?self $instance = null;
    private string $logDir;
    private string $level;

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    private function __construct(array $config)
    {
        $this->logDir = $config['log_dir'] ?? '/var/www/html/logs';
        $this->level  = $config['log_level'] ?? 'debug';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public static function getInstance(array $config = []): self
    {
        if (!self::$instance) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->level] ?? 0)) {
            return;
        }

        $entry = json_encode([
            'ts'      => date('Y-m-d\TH:i:s.') . substr((string) microtime(), 2, 3),
            'level'   => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = $this->logDir . '/bot_' . date('Y-m-d') . '.log';
        file_put_contents($path, $entry . "\n", FILE_APPEND | LOCK_EX);

        // Keep last 7 days only
        $this->rotateLogs();
    }

    private function rotateLogs(): void
    {
        static $lastCleanup = 0;
        if (time() - $lastCleanup < 3600) return; // only once per hour
        $lastCleanup = time();

        $cutoff = strtotime('-7 days');
        foreach (glob($this->logDir . '/bot_*.log') as $file) {
            $dateStr = preg_replace('/.*bot_(\d{4}-\d{2}-\d{2})\.log/', '$1', $file);
            if (strtotime($dateStr) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Retrieve recent log entries (for admin dashboard).
     */
    public function getRecentEntries(int $lines = 200, string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        $path = $this->logDir . '/bot_' . $date . '.log';
        if (!file_exists($path)) return [];

        $all    = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recent = array_slice($all, -$lines);
        return array_map(fn($line) => json_decode($line, true), $recent);
    }
}
