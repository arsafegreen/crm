<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // Cover queue-based listings without closed threads or groups in arrival
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_queue_open
             ON whatsapp_threads(queue, status, chat_type, assigned_user_id, scheduled_for, last_message_at, updated_at)
             WHERE status != "closed"'
        );

        // Cover assignments filtered by status != closed
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_assigned_open
             ON whatsapp_threads(assigned_user_id, status, last_message_at, updated_at)
             WHERE status != "closed"'
        );

        // Cover group listings excluding closed threads
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_group_open
             ON whatsapp_threads(chat_type, status, last_message_at, updated_at)
             WHERE chat_type = "group" AND status != "closed"'
        );

        // Faster message pagination by thread/id cursor
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_thread_after
             ON whatsapp_messages(thread_id, id)'
        );
    }
};
