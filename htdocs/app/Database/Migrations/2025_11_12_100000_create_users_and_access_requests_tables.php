<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cpf TEXT NULL,
                name TEXT NOT NULL,
                email TEXT NULL,
                role TEXT NOT NULL,
                status TEXT NOT NULL,
                certificate_fingerprint TEXT NOT NULL,
                certificate_subject TEXT NULL,
                certificate_serial TEXT NULL,
                certificate_valid_to INTEGER NULL,
                last_seen_at INTEGER NULL,
                approved_at INTEGER NULL,
                approved_by TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(certificate_fingerprint)
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_cpf ON users(cpf)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS certificate_access_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cpf TEXT NULL,
                name TEXT NULL,
                email TEXT NULL,
                certificate_subject TEXT NOT NULL,
                certificate_fingerprint TEXT NOT NULL,
                certificate_serial TEXT NULL,
                certificate_valid_to INTEGER NULL,
                status TEXT NOT NULL,
                reason TEXT NULL,
                raw_certificate TEXT NOT NULL,
                decided_at INTEGER NULL,
                decided_by TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(certificate_fingerprint)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_access_requests_status ON certificate_access_requests(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_access_requests_created_at ON certificate_access_requests(created_at)');
    }
};
