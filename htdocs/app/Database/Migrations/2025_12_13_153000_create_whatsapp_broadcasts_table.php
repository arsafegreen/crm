<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS whatsapp_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            message TEXT,
            template_kind TEXT,
            template_key TEXT,
            criteria TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            stats_total INTEGER NOT NULL DEFAULT 0,
            stats_sent INTEGER NOT NULL DEFAULT 0,
            stats_failed INTEGER NOT NULL DEFAULT 0,
            initiated_by INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            completed_at INTEGER NULL,
            last_error TEXT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_broadcasts_status ON whatsapp_broadcasts(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_broadcasts_created_at ON whatsapp_broadcasts(created_at)');
    }
};
