<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_user_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                level INTEGER NOT NULL DEFAULT 3,
                inbox_access TEXT NOT NULL DEFAULT "all",
                view_scope TEXT NOT NULL DEFAULT "own",
                view_scope_payload TEXT NULL,
                can_forward INTEGER NOT NULL DEFAULT 1,
                can_start_thread INTEGER NOT NULL DEFAULT 1,
                can_view_completed INTEGER NOT NULL DEFAULT 1,
                can_grant_permissions INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_user_permissions_user_id
             ON whatsapp_user_permissions(user_id)'
        );
    }
};
