<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS interactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                certificate_id INTEGER NULL,
                type TEXT NOT NULL,
                subject TEXT NULL,
                notes TEXT NULL,
                occurred_at INTEGER NOT NULL,
                meta TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_interactions_client ON interactions(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_interactions_occurred_at ON interactions(occurred_at)');
    }
};
