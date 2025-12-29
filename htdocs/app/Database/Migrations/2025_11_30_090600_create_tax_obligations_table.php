<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tax_obligations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                code TEXT NULL,
                description TEXT NULL,
                periodicity TEXT NOT NULL DEFAULT "monthly",
                due_day INTEGER NULL,
                due_date INTEGER NULL,
                amount_estimate INTEGER NULL,
                status TEXT NOT NULL DEFAULT "pending",
                account_id INTEGER NULL,
                cost_center_id INTEGER NULL,
                notes TEXT NULL,
                attachment_path TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
                FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tax_obligations_status ON tax_obligations(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tax_obligations_due_date ON tax_obligations(due_date)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tax_obligations_code ON tax_obligations(code)');
    }
};
