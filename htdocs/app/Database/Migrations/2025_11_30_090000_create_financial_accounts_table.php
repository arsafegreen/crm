<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS financial_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                display_name TEXT NOT NULL,
                institution TEXT NULL,
                account_number TEXT NULL,
                account_type TEXT NOT NULL DEFAULT "bank",
                currency TEXT NOT NULL DEFAULT "BRL",
                color TEXT NULL,
                initial_balance INTEGER NOT NULL DEFAULT 0,
                current_balance INTEGER NOT NULL DEFAULT 0,
                available_balance INTEGER NOT NULL DEFAULT 0,
                is_primary INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                sync_provider TEXT NULL,
                sync_identifier TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_accounts_type ON financial_accounts(account_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_accounts_active ON financial_accounts(is_active)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_financial_accounts_sync ON financial_accounts(sync_provider, sync_identifier)');
    }
};
