<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audience_lists (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT NULL,
                origin TEXT NULL,
                purpose TEXT NULL,
                consent_mode TEXT NOT NULL DEFAULT "single_opt_in",
                double_opt_in INTEGER NOT NULL DEFAULT 0,
                opt_in_statement TEXT NULL,
                retention_policy TEXT NULL,
                status TEXT NOT NULL DEFAULT "active",
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                archived_at INTEGER NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_audience_lists_slug ON audience_lists(slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audience_lists_status ON audience_lists(status)');
    }
};
