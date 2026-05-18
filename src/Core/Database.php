<?php

declare(strict_types=1);

namespace HitechFibre\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Database abstraction supporting MySQL (production) and SQLite (dev/fallback).
 * Uses a singleton per connection string to avoid re-connecting on every request.
 */
class Database
{
    private static array $instances = [];
    private PDO $pdo;
    private string $driver;

    private function __construct(private readonly array $config)
    {
        $this->connect();
    }

    public static function getInstance(array $config = []): self
    {
        $key = md5(json_encode($config));
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config);
        }
        return self::$instances[$key];
    }

    private function connect(): void
    {
        $driver = $this->config['driver'] ?? 'sqlite';
        $this->driver = $driver;

        try {
            if ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $this->config['host'] ?? '127.0.0.1',
                    $this->config['port'] ?? 3306,
                    $this->config['name'] ?? 'hitechfibre'
                );
                $this->pdo = new PDO(
                    $dsn,
                    $this->config['user'] ?? 'root',
                    $this->config['password'] ?? '',
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    ]
                );
            } else {
                $path = $this->config['path'] ?? '/var/www/html/state/hitechfibre.db';
                $dir  = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $this->pdo = new PDO(
                    "sqlite:{$path}",
                    null,
                    null,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                $this->pdo->exec('PRAGMA foreign_keys=ON');
            }
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /** Execute a query and return all rows. */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        return $stmt->fetchAll();
    }

    /** Execute a query and return the first row, or null. */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $row  = $stmt->fetch();
        return $row ?: null;
    }

    /** Execute a query and return a single scalar value. */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->run($sql, $params);
        $row  = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    /** INSERT / UPDATE / DELETE — returns affected row count. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** INSERT and return the new row ID. */
    public function insert(string $table, array $data): string|false
    {
        $cols        = array_keys($data);
        $placeholders = array_map(fn($c) => ":{$c}", $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        $this->execute($sql, $data);
        return $this->pdo->lastInsertId();
    }

    /** UPDATE rows matching $where conditions. Returns affected row count. */
    public function update(string $table, array $data, array $where): int
    {
        $setClauses   = array_map(fn($c) => "{$c} = :set_{$c}", array_keys($data));
        $whereClauses = array_map(fn($c) => "{$c} = :where_{$c}", array_keys($where));

        $params = [];
        foreach ($data  as $k => $v) { $params["set_{$k}"]   = $v; }
        foreach ($where as $k => $v) { $params["where_{$k}"] = $v; }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );
        return $this->execute($sql, $params);
    }

    public function beginTransaction(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void            { $this->pdo->commit(); }
    public function rollBack(): void          { $this->pdo->rollBack(); }

    public function inTransaction(): bool     { return $this->pdo->inTransaction(); }

    public function lastInsertId(): string    { return $this->pdo->lastInsertId(); }

    public function getDriver(): string       { return $this->driver; }

    /** Wrap a callable in a transaction, rolling back on exception. */
    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
