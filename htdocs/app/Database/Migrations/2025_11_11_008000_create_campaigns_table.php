<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                channel TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "draft",
                description TEXT NULL,
                scheduled_for INTEGER NULL,
                template_subject TEXT NULL,
                template_body_html TEXT NULL,
                template_body_text TEXT NULL,
                filters TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaign_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                client_id INTEGER NULL,
                certificate_id INTEGER NULL,
                channel TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                scheduled_for INTEGER NULL,
                sent_at INTEGER NULL,
                delivered_at INTEGER NULL,
                opened_at INTEGER NULL,
                responded_at INTEGER NULL,
                error_message TEXT NULL,
                payload TEXT NULL,
                response_payload TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
                FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_messages_status ON campaign_messages(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_campaign_messages_channel ON campaign_messages(channel)');
    }
};
