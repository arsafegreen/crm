<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS financial_invoice_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL,
                description TEXT NOT NULL,
                cost_center_id INTEGER NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                amount_cents INTEGER NOT NULL DEFAULT 0,
                tax_code TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (invoice_id) REFERENCES financial_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_invoice_items_invoice ON financial_invoice_items(invoice_id)');
    }
};
