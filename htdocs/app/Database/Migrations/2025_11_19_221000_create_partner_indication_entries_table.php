<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS partner_indication_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id INTEGER NOT NULL,
                certificate_id INTEGER NOT NULL,
                protocol TEXT NOT NULL,
                billing_mode TEXT NOT NULL DEFAULT "comissao",
                cost_value INTEGER NULL,
                sale_value INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
                FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS partner_indication_entries_unique ON partner_indication_entries(partner_id, certificate_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS partner_indication_entries_protocol ON partner_indication_entries(protocol)');
    }
};
