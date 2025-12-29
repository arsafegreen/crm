<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                module TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NULL,
                trigger_type TEXT NOT NULL,
                threshold_value INTEGER NULL,
                advance_minutes INTEGER NOT NULL DEFAULT 0,
                channel TEXT NOT NULL DEFAULT "email",
                recipients TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_triggered_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reminder_rules_module ON reminder_rules(module)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reminder_rules_active ON reminder_rules(is_active)');
    }
};
