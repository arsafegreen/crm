<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cost_centers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                code TEXT NOT NULL,
                description TEXT NULL,
                parent_id INTEGER NULL,
                default_account_id INTEGER NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (parent_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
                FOREIGN KEY (default_account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_cost_centers_code ON cost_centers(code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cost_centers_parent ON cost_centers(parent_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cost_centers_active ON cost_centers(is_active)');
    }
};
