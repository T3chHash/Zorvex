<?php

declare(strict_types=1);

namespace Zorvex\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO wrapper with a simple connection pool.
 *
 * Connections are created lazily on first use and pooled up to `maxConnections`.
 * Because PHP-FPM workers are single threaded per request, the pool primarily
 * serves as a bounded cache of connections keyed by DSN + credentials, while
 * also supporting explicit checkout/release for long-running workers.
 *
 * @package Zorvex\Core
 */
final class Database
{
    private PDO $pdo;

    private readonly string $dsn;

    private readonly string $user;

    private readonly string $password;

    private readonly int $maxConnections;

    private readonly array $driverOptions;

    /** @var array<int, PDO> Connections checked out (pooled). */
    private array $pool = [];

    /** @var array<int, PDO> Idle connections available for reuse. */
    private array $idle = [];

    public function __construct(
        string $host,
        string $database,
        string $user,
        string $password,
        int $port = 3306,
        string $charset = 'utf8mb4',
        array $options = [],
        int $maxConnections = 10,
    ) {
        $this->dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );
        $this->user = $user;
        $this->password = $password;
        $this->maxConnections = max(1, $maxConnections);
        $this->driverOptions = $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * Factory helper that reads configuration from config('database').
     */
    public static function fromConfig(): self
    {
        $db = config('database', []);

        return new self(
            host: (string) ($db['host'] ?? 'mysql'),
            database: (string) ($db['name'] ?? 'zorvex'),
            user: (string) ($db['user'] ?? 'zorvex'),
            password: (string) ($db['password'] ?? ''),
            port: (int) ($db['port'] ?? 3306),
            charset: (string) ($db['charset'] ?? 'utf8mb4'),
            options: (array) ($db['options'] ?? []),
            maxConnections: (int) ($db['pool']['max'] ?? 10),
        );
    }

    /**
     * The shared PDO connection (main connection for the request).
     */
    public function pdo(): PDO
    {
        if (!isset($this->pdo)) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Check out a connection from the idle pool or create a new one.
     */
    public function checkout(): PDO
    {
        if (!empty($this->idle)) {
            return array_pop($this->idle);
        }

        if (count($this->pool) >= $this->maxConnections) {
            $key = array_key_first($this->pool);
            if ($key === null) {
                return $this->connect();
            }

            // Evict the oldest pooled connection to respect the cap.
            $conn = $this->pool[$key];
            unset($this->pool[$key]);

            return $conn;
        }

        $conn = $this->connect();
        $this->pool[spl_object_id($conn)] = $conn;

        return $conn;
    }

    /**
     * Return a connection to the idle pool.
     */
    public function release(PDO $connection): void
    {
        $oid = spl_object_id($connection);

        if (isset($this->pool[$oid])) {
            $this->idle[$oid] = $connection;
        }
    }

    /**
     * Execute a parameterised statement and return success.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo()->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Fetch a single associative row.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch a single scalar value.
     *
     * @param array<int|string, mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Fetch all rows.
     *
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Return the last inserted auto-increment id.
     */
    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Run a closure inside a transaction with automatic rollback on failure.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Open and test a new connection.
     */
    private function connect(): PDO
    {
        try {
            return new PDO($this->dsn, $this->user, $this->password, $this->driverOptions);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}