<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS marketing_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_client_id INTEGER NULL,
                email TEXT NOT NULL,
                phone TEXT NULL,
                first_name TEXT NULL,
                last_name TEXT NULL,
                locale TEXT NOT NULL DEFAULT "pt_BR",
                timezone TEXT NULL,
                status TEXT NOT NULL DEFAULT "active",
                consent_status TEXT NOT NULL DEFAULT "pending",
                consent_source TEXT NULL,
                consent_at INTEGER NULL,
                opt_out_at INTEGER NULL,
                suppression_reason TEXT NULL,
                bounce_count INTEGER NOT NULL DEFAULT 0,
                complaint_count INTEGER NOT NULL DEFAULT 0,
                tags TEXT NULL,
                last_interaction_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (crm_client_id) REFERENCES clients(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_marketing_contacts_email ON marketing_contacts(email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marketing_contacts_status ON marketing_contacts(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marketing_contacts_client ON marketing_contacts(crm_client_id)');
    }
};
