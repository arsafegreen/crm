<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS financial_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                invoice_id INTEGER NULL,
                cost_center_id INTEGER NULL,
                party_id INTEGER NULL,
                transaction_type TEXT NOT NULL DEFAULT "debit",
                category TEXT NULL,
                description TEXT NULL,
                amount_cents INTEGER NOT NULL,
                balance_after INTEGER NULL,
                occurred_at INTEGER NOT NULL,
                reference TEXT NULL,
                source TEXT NOT NULL DEFAULT "manual",
                source_payload TEXT NULL,
                attachment_path TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (invoice_id) REFERENCES financial_invoices(id) ON DELETE SET NULL,
                FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
                FOREIGN KEY (party_id) REFERENCES financial_parties(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_account ON financial_transactions(account_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_occurred_at ON financial_transactions(occurred_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_type ON financial_transactions(transaction_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_invoice ON financial_transactions(invoice_id)');
    }
};
