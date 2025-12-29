<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_protocols (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                document TEXT NOT NULL,
                protocol_number TEXT NOT NULL,
                description TEXT NULL,
                starts_at INTEGER NULL,
                expires_at INTEGER NULL,
                status TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(protocol_number),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_protocols_client_id ON client_protocols(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_protocols_document ON client_protocols(document)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_protocols_expires_at ON client_protocols(expires_at)');
    }
};
