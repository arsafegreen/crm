<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS financial_parties (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                document TEXT NULL,
                document_type TEXT NULL,
                kind TEXT NOT NULL DEFAULT "client",
                email TEXT NULL,
                phone TEXT NULL,
                client_id INTEGER NULL,
                partner_id INTEGER NULL,
                notes TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
                FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_parties_document ON financial_parties(document)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_parties_kind ON financial_parties(kind)');
    }
};
