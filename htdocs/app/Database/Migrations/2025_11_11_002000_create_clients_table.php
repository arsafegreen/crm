<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                titular_name TEXT NULL,
                titular_document TEXT NULL,
                titular_birthdate INTEGER NULL,
                email TEXT NULL,
                phone TEXT NULL,
                whatsapp TEXT NULL,
                status TEXT NOT NULL DEFAULT "prospect",
                status_changed_at INTEGER NULL,
                last_protocol TEXT NULL,
                last_certificate_expires_at INTEGER NULL,
                next_follow_up_at INTEGER NULL,
                partner_accountant TEXT NULL,
                partner_accountant_plus TEXT NULL,
                pipeline_stage_id INTEGER NULL,
                tags_cache TEXT NULL,
                notes TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (pipeline_stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_status ON clients(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_pipeline_stage ON clients(pipeline_stage_id)');
    }
};
