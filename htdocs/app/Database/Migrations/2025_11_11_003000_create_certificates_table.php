<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                protocol TEXT NOT NULL UNIQUE,
                product_name TEXT NULL,
                product_description TEXT NULL,
                validity_label TEXT NULL,
                media_description TEXT NULL,
                serial_number TEXT NULL,
                status TEXT NOT NULL DEFAULT "emitido",
                is_revoked INTEGER NOT NULL DEFAULT 0,
                revocation_reason TEXT NULL,
                start_at INTEGER NULL,
                end_at INTEGER NULL,
                avp_name TEXT NULL,
                avp_cpf TEXT NULL,
                aci_name TEXT NULL,
                aci_cpf TEXT NULL,
                location_name TEXT NULL,
                location_alias TEXT NULL,
                city_name TEXT NULL,
                emission_type TEXT NULL,
                requested_type TEXT NULL,
                partner_name TEXT NULL,
                partner_accountant TEXT NULL,
                partner_accountant_plus TEXT NULL,
                renewal_protocol TEXT NULL,
                status_raw TEXT NULL,
                source_payload TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_certificates_client ON certificates(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_certificates_end_at ON certificates(end_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_certificates_partner ON certificates(partner_accountant_plus)');
    }
};
