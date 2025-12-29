<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaign_send_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign TEXT NOT NULL,
                reference TEXT NULL,
                cpf TEXT NULL,
                phone TEXT NULL,
                status TEXT NOT NULL,
                message TEXT NULL,
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_send_logs_campaign ON campaign_send_logs(campaign)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_send_logs_status ON campaign_send_logs(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_send_logs_created_at ON campaign_send_logs(created_at)');
    }
};
