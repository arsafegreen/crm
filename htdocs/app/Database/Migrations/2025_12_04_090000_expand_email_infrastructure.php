<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $this->addColumn($pdo, 'email_accounts', 'imap_host TEXT NULL');
        $this->addColumn($pdo, 'email_accounts', 'imap_port INTEGER NOT NULL DEFAULT 993');
        $this->addColumn($pdo, 'email_accounts', 'imap_encryption TEXT NOT NULL DEFAULT "ssl"');
        $this->addColumn($pdo, 'email_accounts', 'imap_username TEXT NULL');
        $this->addColumn($pdo, 'email_accounts', 'imap_password TEXT NULL');
        $this->addColumn($pdo, 'email_accounts', 'imap_sync_enabled INTEGER NOT NULL DEFAULT 0');
        $this->addColumn($pdo, 'email_accounts', 'imap_last_uid TEXT NULL');
        $this->addColumn($pdo, 'email_accounts', 'imap_last_sync_at INTEGER NULL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                remote_name TEXT NOT NULL,
                display_name TEXT NULL,
                type TEXT NOT NULL DEFAULT "custom",
                sync_token TEXT NULL,
                last_synced_at INTEGER NULL,
                unread_count INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_email_folders_account_remote ON email_folders(account_id, remote_name)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                folder_id INTEGER NULL,
                subject TEXT NULL,
                snippet TEXT NULL,
                primary_contact_id INTEGER NULL,
                primary_client_id INTEGER NULL,
                last_message_at INTEGER NULL,
                unread_count INTEGER NOT NULL DEFAULT 0,
                flags TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES email_folders(id) ON DELETE SET NULL,
                FOREIGN KEY (primary_contact_id) REFERENCES marketing_contacts(id) ON DELETE SET NULL,
                FOREIGN KEY (primary_client_id) REFERENCES clients(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_threads_account_folder ON email_threads(account_id, folder_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_threads_last_message ON email_threads(last_message_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NULL,
                account_id INTEGER NOT NULL,
                folder_id INTEGER NULL,
                direction TEXT NOT NULL DEFAULT "inbound",
                status TEXT NOT NULL DEFAULT "received",
                subject TEXT NULL,
                sender_name TEXT NULL,
                sender_email TEXT NOT NULL,
                to_recipients TEXT NULL,
                cc_recipients TEXT NULL,
                bcc_recipients TEXT NULL,
                external_uid TEXT NULL,
                internet_message_id TEXT NULL,
                in_reply_to TEXT NULL,
                references_header TEXT NULL,
                sent_at INTEGER NULL,
                received_at INTEGER NULL,
                read_at INTEGER NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                body_text_path TEXT NULL,
                body_html_path TEXT NULL,
                headers TEXT NULL,
                metadata TEXT NULL,
                hash TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (thread_id) REFERENCES email_threads(id) ON DELETE SET NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES email_folders(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_thread ON email_messages(thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_account_direction ON email_messages(account_id, direction)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_external_uid ON email_messages(external_uid)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_message_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                name TEXT NULL,
                email TEXT NOT NULL,
                contact_id INTEGER NULL,
                client_id INTEGER NULL,
                rfb_prospect_id INTEGER NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (message_id) REFERENCES email_messages(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE SET NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
                FOREIGN KEY (rfb_prospect_id) REFERENCES rfb_prospects(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_participants_message ON email_message_participants(message_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_participants_email ON email_message_participants(email)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                mime_type TEXT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                storage_path TEXT NOT NULL,
                checksum TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (message_id) REFERENCES email_messages(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_attachments_message ON email_attachments(message_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "draft",
                source_type TEXT NOT NULL DEFAULT "list",
                list_id INTEGER NULL,
                segment_id INTEGER NULL,
                rfb_filter TEXT NULL,
                template_id INTEGER NULL,
                subject TEXT NULL,
                from_account_id INTEGER NULL,
                scheduled_for INTEGER NULL,
                settings TEXT NULL,
                created_by INTEGER NULL,
                updated_by INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
                FOREIGN KEY (list_id) REFERENCES audience_lists(id) ON DELETE SET NULL,
                FOREIGN KEY (segment_id) REFERENCES marketing_segments(id) ON DELETE SET NULL,
                FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
                FOREIGN KEY (from_account_id) REFERENCES email_accounts(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_campaigns_status ON email_campaigns(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_campaigns_list_segment ON email_campaigns(list_id, segment_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_campaign_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_campaign_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                total_recipients INTEGER NOT NULL DEFAULT 0,
                processed_count INTEGER NOT NULL DEFAULT 0,
                failed_count INTEGER NOT NULL DEFAULT 0,
                scheduled_for INTEGER NULL,
                started_at INTEGER NULL,
                finished_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (email_campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_campaign_batches_status ON email_campaign_batches(status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_sends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_campaign_id INTEGER NULL,
                batch_id INTEGER NULL,
                account_id INTEGER NULL,
                contact_id INTEGER NULL,
                client_id INTEGER NULL,
                rfb_prospect_id INTEGER NULL,
                target_email TEXT NOT NULL,
                target_name TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                scheduled_at INTEGER NULL,
                sent_at INTEGER NULL,
                delivered_at INTEGER NULL,
                opened_at INTEGER NULL,
                clicked_at INTEGER NULL,
                bounced_at INTEGER NULL,
                complaint_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (email_campaign_id) REFERENCES email_campaigns(id) ON DELETE SET NULL,
                FOREIGN KEY (batch_id) REFERENCES email_campaign_batches(id) ON DELETE SET NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE SET NULL,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE SET NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
                FOREIGN KEY (rfb_prospect_id) REFERENCES rfb_prospects(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_sends_status ON email_sends(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_sends_account ON email_sends(account_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_sends_contact ON email_sends(contact_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                send_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                occurred_at INTEGER NOT NULL,
                payload TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (send_id) REFERENCES email_sends(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_events_send ON email_events(send_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_events_type ON email_events(event_type)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL UNIQUE,
                window_start INTEGER NOT NULL,
                hourly_sent INTEGER NOT NULL DEFAULT 0,
                daily_sent INTEGER NOT NULL DEFAULT 0,
                last_reset_at INTEGER NULL,
                metadata TEXT NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_type TEXT NOT NULL,
                payload TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                priority INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NULL,
                reserved_at INTEGER NULL,
                reserved_by TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                last_error TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_jobs_status ON email_jobs(status, available_at)');
    }

    private function addColumn(\PDO $pdo, string $table, string $definition): void
    {
        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $definition));
    }
};
