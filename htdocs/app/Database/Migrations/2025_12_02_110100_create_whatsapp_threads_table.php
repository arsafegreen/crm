<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER NOT NULL,
                subject TEXT NULL,
                status TEXT NOT NULL DEFAULT "open",
                assigned_user_id INTEGER NULL,
                channel_thread_id TEXT NULL,
                unread_count INTEGER NOT NULL DEFAULT 0,
                last_message_preview TEXT NULL,
                last_message_at INTEGER NULL,
                copilot_status TEXT NOT NULL DEFAULT "idle",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                closed_at INTEGER NULL,
                FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_threads_channel ON whatsapp_threads(channel_thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_contact ON whatsapp_threads(contact_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_status ON whatsapp_threads(status)');
    }
};
