<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                author_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT "text",
                attachment_path TEXT NULL,
                attachment_name TEXT NULL,
                attachment_mime TEXT NULL,
                attachment_size INTEGER NULL,
                attachment_meta TEXT NULL,
                is_system INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                deleted_at INTEGER NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_thread ON chat_messages(thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_author ON chat_messages(author_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_created_at ON chat_messages(created_at)');
    }
};
