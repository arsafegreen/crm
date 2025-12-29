<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS copilot_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT NULL,
                objective TEXT NULL,
                instructions TEXT NOT NULL,
                tone TEXT NOT NULL DEFAULT "consultivo",
                temperature REAL NOT NULL DEFAULT 0.5,
                default_queue TEXT NULL,
                is_default INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_copilot_profiles_slug ON copilot_profiles(slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_copilot_profiles_default ON copilot_profiles(is_default)');
    }
};
