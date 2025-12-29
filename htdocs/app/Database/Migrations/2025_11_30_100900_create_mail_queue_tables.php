<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                driver TEXT NOT NULL,
                from_name TEXT NULL,
                from_email TEXT NULL,
                reply_to TEXT NULL,
                credentials TEXT NULL,
                settings TEXT NULL,
                max_messages_per_hour INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "active",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_mail_providers_name ON mail_providers(name)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id INTEGER NULL,
                campaign_message_id INTEGER NULL,
                journey_enrollment_id INTEGER NULL,
                contact_id INTEGER NULL,
                list_id INTEGER NULL,
                segment_id INTEGER NULL,
                recipient_email TEXT NOT NULL,
                recipient_name TEXT NULL,
                subject TEXT NULL,
                body_html TEXT NULL,
                body_text TEXT NULL,
                payload TEXT NULL,
                headers TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                priority INTEGER NOT NULL DEFAULT 0,
                scheduled_at INTEGER NULL,
                available_at INTEGER NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                locked_by TEXT NULL,
                locked_at INTEGER NULL,
                sent_at INTEGER NULL,
                last_error TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (provider_id) REFERENCES mail_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (campaign_message_id) REFERENCES campaign_messages(id) ON DELETE SET NULL,
                FOREIGN KEY (journey_enrollment_id) REFERENCES journey_enrollments(id) ON DELETE SET NULL,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE SET NULL,
                FOREIGN KEY (list_id) REFERENCES audience_lists(id) ON DELETE SET NULL,
                FOREIGN KEY (segment_id) REFERENCES marketing_segments(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_status ON mail_queue_jobs(status, scheduled_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_locked ON mail_queue_jobs(locked_by)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_provider ON mail_queue_jobs(provider_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_delivery_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue_job_id INTEGER NOT NULL,
                provider_event_id TEXT NULL,
                event_type TEXT NOT NULL,
                occurred_at INTEGER NOT NULL,
                payload TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (queue_job_id) REFERENCES mail_queue_jobs(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_queue_job ON mail_delivery_logs(queue_job_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_event ON mail_delivery_logs(event_type)');
    }
};
