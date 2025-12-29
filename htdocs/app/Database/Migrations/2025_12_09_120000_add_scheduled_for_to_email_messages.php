<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        // Detect column existence via PRAGMA (SQLite-friendly) before altering.
        $hasColumn = false;
        $stmt = $pdo->query("PRAGMA table_info('email_messages')");
        if ($stmt !== false) {
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'scheduled_for') {
                    $hasColumn = true;
                    break;
                }
            }
        }

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE email_messages ADD COLUMN scheduled_for INTEGER NULL');
        }

        // Create index if missing (ignoring duplicate index errors).
        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_scheduled ON email_messages(status, scheduled_for)');
        } catch (\Throwable $exception) {
            // ignore
        }
    }
};
