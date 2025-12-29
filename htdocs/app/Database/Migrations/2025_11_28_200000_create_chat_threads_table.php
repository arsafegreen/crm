<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL DEFAULT "direct",
                subject TEXT NULL,
                created_by INTEGER NOT NULL,
                last_message_id INTEGER NULL,
                last_message_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_threads_type ON chat_threads(type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_threads_last_message_at ON chat_threads(last_message_at)');
    }
};
