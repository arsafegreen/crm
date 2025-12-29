<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NULL,
                name TEXT NOT NULL,
                phone TEXT NOT NULL,
                tags TEXT NULL,
                preferred_language TEXT NOT NULL DEFAULT "pt-BR",
                last_interaction_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_contacts_phone ON whatsapp_contacts(phone)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_contacts_client ON whatsapp_contacts(client_id)');
    }
};
