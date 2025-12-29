<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(whatsapp_threads)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_column($columns, 'name');

        if (!in_array('queue', $names, true)) {
            $pdo->exec("ALTER TABLE whatsapp_threads ADD COLUMN queue TEXT NOT NULL DEFAULT 'arrival'");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_queue ON whatsapp_threads(queue)");
        }

        if (!in_array('scheduled_for', $names, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN scheduled_for INTEGER NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_scheduled_for ON whatsapp_threads(scheduled_for)');
        }

        if (!in_array('partner_id', $names, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN partner_id INTEGER NULL REFERENCES partners(id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_threads_partner_id ON whatsapp_threads(partner_id)');
        }

        if (!in_array('responsible_user_id', $names, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN responsible_user_id INTEGER NULL REFERENCES users(id)');
        }

        if (!in_array('intake_summary', $names, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN intake_summary TEXT NULL');
        }

        $pdo->exec("UPDATE whatsapp_threads SET queue = 'arrival' WHERE queue IS NULL OR TRIM(queue) = ''");
    }
};
