<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rfb_prospects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cnpj TEXT NOT NULL UNIQUE,
                company_name TEXT NOT NULL,
                email TEXT NULL,
                activity_started_at INTEGER NULL,
                cnae_code TEXT NULL,
                city TEXT NULL,
                state TEXT NULL,
                ddd TEXT NULL,
                phone TEXT NULL,
                source_file TEXT NULL,
                raw_payload TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rfb_prospects_state ON rfb_prospects(state)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rfb_prospects_city ON rfb_prospects(city)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rfb_prospects_cnae ON rfb_prospects(cnae_code)');
    }
};
