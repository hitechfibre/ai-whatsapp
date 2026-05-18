<?php

declare(strict_types=1);

namespace HitechFibre\Core;

class Config
{
    private static ?self $instance = null;
    private array $data = [];

    private function __construct(string $path)
    {
        if ($path === '/dev/null' || $path === '') {
            $this->data = [];
            return;
        }
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }
        $this->data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function load(string $path): self
    {
        self::$instance = new self($path);
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            throw new \RuntimeException("Config not loaded. Call Config::load() first.");
        }
        return self::$instance;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->getValue($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        if (!self::$instance) {
            self::$instance = new self('/dev/null');
        }
        self::$instance->setValue($key, $value);
    }

    public function getValue(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $data  = $this->data;
        foreach ($parts as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return $default;
            }
            $data = $data[$part];
        }
        return $data;
    }

    public function setValue(string $key, mixed $value): void
    {
        $parts  = explode('.', $key);
        $target = &$this->data;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $target[$part] = $value;
            } else {
                if (!isset($target[$part]) || !is_array($target[$part])) {
                    $target[$part] = [];
                }
                $target = &$target[$part];
            }
        }
    }

    public function getString(string $key, string $default = ''): string
    {
        return (string) ($this->getValue($key) ?? $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->getValue($key) ?? $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->getValue($key);
        if ($val === null) return $default;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public function getArray(string $key, array $default = []): array
    {
        $val = $this->getValue($key);
        return is_array($val) ? $val : $default;
    }

    public function all(): array { return $this->data; }
}
