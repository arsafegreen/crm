<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS financial_invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                cost_center_id INTEGER NULL,
                party_id INTEGER NULL,
                direction TEXT NOT NULL DEFAULT "payable",
                status TEXT NOT NULL DEFAULT "draft",
                document_number TEXT NULL,
                description TEXT NULL,
                amount_cents INTEGER NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT "BRL",
                issue_date INTEGER NOT NULL,
                due_date INTEGER NOT NULL,
                paid_at INTEGER NULL,
                recurrence_rule TEXT NULL,
                attachment_path TEXT NULL,
                notes TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
                FOREIGN KEY (party_id) REFERENCES financial_parties(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_invoices_status ON financial_invoices(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_invoices_due_date ON financial_invoices(due_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_invoices_direction ON financial_invoices(direction)');
    }
};
