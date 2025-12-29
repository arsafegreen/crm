<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS import_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                source TEXT NOT NULL,
                filename TEXT NOT NULL,
                processed INTEGER NOT NULL DEFAULT 0,
                created_clients INTEGER NOT NULL DEFAULT 0,
                updated_clients INTEGER NOT NULL DEFAULT 0,
                created_certificates INTEGER NOT NULL DEFAULT 0,
                updated_certificates INTEGER NOT NULL DEFAULT 0,
                skipped INTEGER NOT NULL DEFAULT 0,
                skipped_older INTEGER NOT NULL DEFAULT 0,
                meta TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
    }
};
