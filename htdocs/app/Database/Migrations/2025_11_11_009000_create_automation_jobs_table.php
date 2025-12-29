<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS automation_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                payload TEXT NULL,
                scheduled_for INTEGER NULL,
                available_at INTEGER NULL,
                locked_at INTEGER NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_automation_jobs_type ON automation_jobs(type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_automation_jobs_schedule ON automation_jobs(scheduled_for)');
    }
};
