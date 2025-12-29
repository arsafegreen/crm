<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                last_read_message_id INTEGER NULL,
                last_read_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(thread_id, user_id)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_participants_thread ON chat_participants(thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_participants_user ON chat_participants(user_id)');
    }
};
