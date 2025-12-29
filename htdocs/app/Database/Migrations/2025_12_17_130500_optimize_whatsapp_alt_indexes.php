<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // Speed contact + line lookups when resolving threads
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_contact_line
             ON whatsapp_threads(contact_id, line_id, updated_at)'
        );

        // Accelerate message timelines and cursor pagination
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_thread_sent_id
             ON whatsapp_messages(thread_id, sent_at, id)'
        );
    }
};
