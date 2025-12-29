<?php

declare(strict_types=1);

$databasePath = __DIR__ . '/../storage/database.sqlite';
if (!file_exists($databasePath)) {
    fwrite(STDERR, "Arquivo de banco de dados não encontrado em {$databasePath}.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$schema = <<<SQL
CREATE TABLE IF NOT EXISTS appointments (
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
    status TEXT NOT NULL DEFAULT 'scheduled',
    starts_at INTEGER NOT NULL,
    ends_at INTEGER NOT NULL,
    allow_conflicts INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_appointments_owner_start ON appointments(owner_user_id, starts_at);
CREATE INDEX IF NOT EXISTS idx_appointments_range ON appointments(starts_at, ends_at);

CREATE TABLE IF NOT EXISTS appointment_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    appointment_id INTEGER NOT NULL REFERENCES appointments(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'participant',
    created_at INTEGER NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_participants_unique ON appointment_participants(appointment_id, user_id);
CREATE INDEX IF NOT EXISTS idx_participants_appointment ON appointment_participants(appointment_id);
SQL;

$pdo->exec($schema);

echo "Tabelas de agenda garantidas com sucesso.\n";
