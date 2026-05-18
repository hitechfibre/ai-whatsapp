<?php

declare(strict_types=1);

namespace HitechFibre\Core;

use Redis;
use RedisException;

/**
 * Cache abstraction with Redis primary and file-based fallback.
 * The file cache is safe for single-server use; swap to Redis for multi-server.
 */
class Cache
{
    private static ?self $instance = null;
    private ?Redis $redis  = null;
    private string $prefix = 'htf:';
    private string $fileDir;

    private function __construct(private readonly array $config)
    {
        $this->fileDir = $config['file_dir'] ?? '/var/www/html/state/cache';
        if (!is_dir($this->fileDir)) {
            mkdir($this->fileDir, 0755, true);
        }
        $this->connectRedis();
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    private function connectRedis(): void
    {
        if (!class_exists(Redis::class)) {
            return;
        }
        try {
            $this->redis = new Redis();
            $connected = $this->redis->connect(
                $this->config['host']    ?? '127.0.0.1',
                (int) ($this->config['port'] ?? 6379),
                (float) ($this->config['timeout'] ?? 1.0)
            );
            if (!$connected) {
                $this->redis = null;
                return;
            }
            if (!empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }
            $this->redis->ping();
        } catch (RedisException) {
            $this->redis = null;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;

        if ($this->redis) {
            try {
                $val = $this->redis->get($key);
                if ($val === false) return $default;
                return $this->unserialize($val);
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        return $this->fileGet($key, $default);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $key = $this->prefix . $key;
        $val = $this->serialize($value);

        if ($this->redis) {
            try {
                return $ttl > 0
                    ? (bool) $this->redis->setex($key, $ttl, $val)
                    : (bool) $this->redis->set($key, $val);
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        return $this->fileSet($key, $val, $ttl);
    }

    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;

        if ($this->redis) {
            try {
                $this->redis->del($key);
                return true;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        return $this->fileDelete($key);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Atomic "set if not exists" — returns true if the key was set (i.e. lock acquired).
     * Used for dedup locks on webhook event IDs.
     */
    public function setNx(string $key, mixed $value, int $ttl = 30): bool
    {
        $key = $this->prefix . $key;
        $val = $this->serialize($value);

        if ($this->redis) {
            try {
                $result = $this->redis->set($key, $val, ['nx', 'ex' => $ttl]);
                return $result !== false;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        // File-based atomic-ish NX using exclusive lock
        $path = $this->filePath($key);
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if ($data && $data['expires'] > time()) {
                return false; // key exists and not expired
            }
        }
        return $this->fileSet($key, $val, $ttl);
    }

    /**
     * Increment a counter — used for rate-limiting.
     * Returns the new value after increment.
     */
    public function increment(string $key, int $by = 1, int $ttl = 60): int
    {
        $key = $this->prefix . $key;

        if ($this->redis) {
            try {
                $new = $this->redis->incrBy($key, $by);
                if ($new === $by) {
                    $this->redis->expire($key, $ttl);
                }
                return $new;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        $current = (int) ($this->get(ltrim($key, $this->prefix)) ?? 0);
        $new = $current + $by;
        $this->set(ltrim($key, $this->prefix), $new, $ttl);
        return $new;
    }

    /** Push a job onto a Redis list queue. Falls back to a file queue. */
    public function queuePush(string $queue, array $job): void
    {
        $key = $this->prefix . 'queue:' . $queue;
        $payload = json_encode($job);

        if ($this->redis) {
            try {
                $this->redis->rPush($key, $payload);
                return;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        $this->fileQueuePush($queue, $job);
    }

    /** Pop a job from the queue (non-blocking). Returns null if empty. */
    public function queuePop(string $queue): ?array
    {
        $key = $this->prefix . 'queue:' . $queue;

        if ($this->redis) {
            try {
                $val = $this->redis->lPop($key);
                return $val ? json_decode($val, true) : null;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        return $this->fileQueuePop($queue);
    }

    public function isRedisAvailable(): bool { return $this->redis !== null; }

    // ─────────────── File-based fallback ───────────────

    private function filePath(string $key): string
    {
        return $this->fileDir . '/' . md5($key) . '.cache';
    }

    private function fileGet(string $key, mixed $default): mixed
    {
        $path = $this->filePath($key);
        if (!file_exists($path)) return $default;
        $data = json_decode(@file_get_contents($path), true);
        if (!$data) return $default;
        if ($data['expires'] && $data['expires'] < time()) {
            @unlink($path);
            return $default;
        }
        return $this->unserialize($data['value']);
    }

    private function fileSet(string $key, string $val, int $ttl): bool
    {
        $path = $this->filePath($key);
        $data = json_encode([
            'value'   => $val,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ]);
        return file_put_contents($path, $data, LOCK_EX) !== false;
    }

    private function fileDelete(string $key): bool
    {
        $path = $this->filePath($key);
        return !file_exists($path) || @unlink($path);
    }

    private function fileQueuePush(string $queue, array $job): void
    {
        $path = $this->fileDir . '/queue_' . $queue . '.jsonl';
        file_put_contents($path, json_encode($job) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function fileQueuePop(string $queue): ?array
    {
        $path = $this->fileDir . '/queue_' . $queue . '.jsonl';
        if (!file_exists($path)) return null;
        $fp   = fopen($path, 'r+');
        flock($fp, LOCK_EX);
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) { flock($fp, LOCK_UN); fclose($fp); return null; }
        $job = json_decode(array_shift($lines), true);
        file_put_contents($path, implode("\n", $lines) . (count($lines) ? "\n" : ''), LOCK_EX);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $job;
    }

    private function serialize(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    private function unserialize(string $value): mixed
    {
        return unserialize(base64_decode($value));
    }
}
