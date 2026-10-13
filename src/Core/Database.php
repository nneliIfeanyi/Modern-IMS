<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?self $instance = null;

    private ?PDO $connection = null;

    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    private function connect(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $config = $this->config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $this->connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->connection;
    }

    public static function init(array $config): void
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
    }

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            throw new PDOException('Database has not been initialized.');
        }

        return self::$instance->connect();
    }
}
