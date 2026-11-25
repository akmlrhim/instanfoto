<?php

declare(strict_types=1);

namespace InstanFoto;

use PDO;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    private static ?Config $config = null;

    private static bool $schemaReady = false;

    public static function init(Config $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = self::$config ?? throw new \LogicException('Database belum diinisialisasi.');

        if (!is_dir($config->dataDir) && !mkdir($config->dataDir, 0775, true) && !is_dir($config->dataDir)) {
            throw new \RuntimeException('Direktori data tidak dapat dibuat: ' . $config->dataDir);
        }

        $pdo = new PDO('sqlite:' . $config->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::ensureSchema($pdo);

        return $pdo;
    }

    public static function query(string $sql, array $args = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);

        foreach (array_values($args) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($index + 1, $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    public static function close(): void
    {
        self::$pdo = null;
        self::$config = null;
        self::$schemaReady = false;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        $schema = [
            "CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                event TEXT NOT NULL DEFAULT '',
                finished INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                filename TEXT NOT NULL UNIQUE,
                web_url TEXT NOT NULL DEFAULT '',
                thumb_url TEXT NOT NULL DEFAULT '',
                bytes INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )",
            'CREATE INDEX IF NOT EXISTS idx_photos_session ON photos(session_id)',
            "CREATE TABLE IF NOT EXISTS rate_limits (
                bucket TEXT NOT NULL,
                ip TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, ip, window_start)
            )",
        ];

        foreach ($schema as $statement) {
            $pdo->exec($statement);
        }

        self::$schemaReady = true;
    }
}