<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                direction TEXT NOT NULL,
                message_type TEXT NOT NULL DEFAULT "text",
                content TEXT NOT NULL,
                ai_summary TEXT NULL,
                suggestion_source TEXT NULL,
                meta_message_id TEXT NULL,
                status TEXT NOT NULL DEFAULT "sent",
                sent_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                metadata TEXT NULL,
                FOREIGN KEY (thread_id) REFERENCES whatsapp_threads(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_messages_meta ON whatsapp_messages(meta_message_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_thread ON whatsapp_messages(thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_sent_at ON whatsapp_messages(sent_at)');
    }
};
