<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaign_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                target_day INTEGER NULL,
                target_month INTEGER NULL,
                start_time TEXT NULL,
                pacing_seconds INTEGER NOT NULL DEFAULT 40,
                enabled INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "scheduled",
                meta TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaign_sends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cpf TEXT NULL,
                phone TEXT NULL,
                campaign TEXT NOT NULL,
                reference TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                gateway TEXT NULL,
                message_id TEXT NULL,
                error TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_jobs_kind_status ON campaign_jobs(kind, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_jobs_enabled ON campaign_jobs(enabled)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_sends_campaign ON campaign_sends(campaign)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_sends_status ON campaign_sends(status)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_campaign_sends_cpf_campaign_ref ON campaign_sends(cpf, campaign, reference)');
    }
};
