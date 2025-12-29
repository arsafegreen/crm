<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS marketing_segments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                list_id INTEGER NULL,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT NULL,
                definition TEXT NULL,
                refresh_mode TEXT NOT NULL DEFAULT "dynamic",
                status TEXT NOT NULL DEFAULT "draft",
                refresh_interval INTEGER NULL,
                last_refreshed_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (list_id) REFERENCES audience_lists(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_marketing_segments_slug ON marketing_segments(slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marketing_segments_list ON marketing_segments(list_id)');
    }
};
