<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS marketing_journeys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT NULL,
                status TEXT NOT NULL DEFAULT "draft",
                entry_type TEXT NOT NULL DEFAULT "list",
                entry_reference_id INTEGER NULL,
                timezone TEXT NULL,
                settings TEXT NULL,
                definition TEXT NULL,
                owner_id INTEGER NULL,
                published_at INTEGER NULL,
                archived_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_marketing_journeys_slug ON marketing_journeys(slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marketing_journeys_status ON marketing_journeys(status)');
    }
};
