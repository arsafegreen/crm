<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(whatsapp_threads)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $existing = array_map(static fn(array $column): string => (string)$column['name'], $columns);

        if (!in_array('chat_type', $existing, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN chat_type TEXT NOT NULL DEFAULT "direct"');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_chat_type ON whatsapp_threads(chat_type)');
        }

        if (!in_array('group_subject', $existing, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN group_subject TEXT NULL');
        }

        if (!in_array('group_metadata', $existing, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN group_metadata TEXT NULL');
        }
    }
};
