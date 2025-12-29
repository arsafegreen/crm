<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NULL,
                client_name TEXT NULL,
                client_document TEXT NULL,
                owner_user_id INTEGER NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                category TEXT NULL,
                channel TEXT NULL,
                location TEXT NULL,
                status TEXT NOT NULL,
                starts_at INTEGER NOT NULL,
                ends_at INTEGER NOT NULL,
                allow_conflicts INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_owner ON appointments(owner_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_client ON appointments(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_starts_at ON appointments(starts_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_status ON appointments(status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS appointment_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appointment_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT "participant",
                created_at INTEGER NOT NULL,
                UNIQUE(appointment_id, user_id)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointment_participants_user ON appointment_participants(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointment_participants_appointment ON appointment_participants(appointment_id)');
    }
};
