<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS avp_schedule_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                day_of_week INTEGER NOT NULL,
                slot_duration_minutes INTEGER NOT NULL DEFAULT 20,
                work_start_minutes INTEGER NOT NULL,
                work_end_minutes INTEGER NOT NULL,
                lunch_start_minutes INTEGER NULL,
                lunch_end_minutes INTEGER NULL,
                offline_blocks TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(user_id, day_of_week),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_avp_schedule_user ON avp_schedule_configs(user_id)');
    }
};
