<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                provider TEXT NOT NULL,
                domain TEXT NULL,
                from_name TEXT NOT NULL,
                from_email TEXT NOT NULL,
                reply_to TEXT NULL,
                smtp_host TEXT NOT NULL,
                smtp_port INTEGER NOT NULL DEFAULT 587,
                encryption TEXT NOT NULL DEFAULT "tls",
                auth_mode TEXT NOT NULL DEFAULT "login",
                credentials TEXT NOT NULL,
                headers TEXT NULL,
                settings TEXT NULL,
                hourly_limit INTEGER NOT NULL DEFAULT 0,
                daily_limit INTEGER NOT NULL DEFAULT 0,
                burst_limit INTEGER NOT NULL DEFAULT 0,
                warmup_status TEXT NOT NULL DEFAULT "ready",
                status TEXT NOT NULL DEFAULT "active",
                notes TEXT NULL,
                last_health_check_at INTEGER NULL,
                last_error TEXT NULL,
                created_by INTEGER NULL,
                updated_by INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                deleted_at INTEGER NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_accounts_provider_status ON email_accounts(provider, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_accounts_domain ON email_accounts(domain)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_accounts_deleted ON email_accounts(deleted_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_account_policies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                policy_type TEXT NOT NULL,
                policy_key TEXT NOT NULL,
                policy_value TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES email_accounts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_account_policies_account ON email_account_policies(account_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_account_policies_type ON email_account_policies(policy_type)');
    }
};
