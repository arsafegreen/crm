<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transaction_import_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                total_rows INTEGER NOT NULL DEFAULT 0,
                processed_rows INTEGER NOT NULL DEFAULT 0,
                valid_rows INTEGER NOT NULL DEFAULT 0,
                invalid_rows INTEGER NOT NULL DEFAULT 0,
                imported_rows INTEGER NOT NULL DEFAULT 0,
                failed_rows INTEGER NOT NULL DEFAULT 0,
                started_at INTEGER NULL,
                completed_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_batches_account ON transaction_import_batches(account_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_batches_status ON transaction_import_batches(status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transaction_import_rows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER NOT NULL,
                row_number INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                transaction_type TEXT NULL,
                amount_cents INTEGER NULL,
                occurred_at INTEGER NULL,
                description TEXT NULL,
                reference TEXT NULL,
                checksum TEXT NULL,
                raw_payload TEXT NULL,
                normalized_payload TEXT NULL,
                error_code TEXT NULL,
                error_message TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (batch_id) REFERENCES transaction_import_batches(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_rows_batch ON transaction_import_rows(batch_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_rows_status ON transaction_import_rows(status)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_transaction_import_rows_checksum ON transaction_import_rows(batch_id, checksum)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transaction_import_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER NOT NULL,
                level TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (batch_id) REFERENCES transaction_import_batches(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_events_batch ON transaction_import_events(batch_id)');
    }
};
