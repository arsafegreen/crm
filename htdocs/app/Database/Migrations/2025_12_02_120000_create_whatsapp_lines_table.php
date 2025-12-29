<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL,
                phone_number_id TEXT NOT NULL,
                display_phone TEXT NOT NULL,
                business_account_id TEXT NOT NULL,
                access_token TEXT NOT NULL,
                verify_token TEXT NULL,
                is_default INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "active",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_lines_phone_number_id ON whatsapp_lines(phone_number_id)');
    }
};
