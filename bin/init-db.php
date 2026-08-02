<?php

declare(strict_types=1);

$databasePath = $argv[1] ?? getenv('JOT_DB_PATH') ?: dirname(__DIR__) . '/var/jot.sqlite';
$directory = dirname($databasePath);

if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    fwrite(STDERR, "Could not create database directory: {$directory}\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $databasePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS articles (
    id TEXT PRIMARY KEY,
    url TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    fwrite(STDOUT, "Initialized {$databasePath}\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Database initialization failed: {$error->getMessage()}\n");
    exit(1);
}
