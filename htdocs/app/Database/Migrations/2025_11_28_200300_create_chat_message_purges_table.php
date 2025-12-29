<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_message_purges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                executed_at INTEGER NOT NULL,
                cutoff_timestamp INTEGER NOT NULL,
                rows_deleted INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_message_purges_admin ON chat_message_purges(admin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_message_purges_cutoff ON chat_message_purges(cutoff_timestamp)');
    }
};
