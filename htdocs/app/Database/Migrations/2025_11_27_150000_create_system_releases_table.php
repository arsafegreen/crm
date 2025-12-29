<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_releases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT "available",
                origin TEXT NOT NULL DEFAULT "local",
                notes TEXT NULL,
                file_name TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                file_hash TEXT NOT NULL,
                include_vendor INTEGER NOT NULL DEFAULT 1,
                git_commit TEXT NULL,
                php_version TEXT NULL,
                manifest JSON NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                applied_at INTEGER NULL,
                applied_by INTEGER NULL,
                applied_exit_code INTEGER NULL,
                applied_stdout TEXT NULL,
                applied_stderr TEXT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_system_releases_version ON system_releases(version)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_releases_status ON system_releases(status)');
    }
};
