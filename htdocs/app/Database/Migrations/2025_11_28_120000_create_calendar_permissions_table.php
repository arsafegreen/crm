<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS calendar_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_user_id INTEGER NOT NULL,
                grantee_user_id INTEGER NOT NULL,
                granted_by_user_id INTEGER NOT NULL,
                scopes TEXT NOT NULL,
                expires_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(owner_user_id, grantee_user_id)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calendar_permissions_owner ON calendar_permissions(owner_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calendar_permissions_grantee ON calendar_permissions(grantee_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calendar_permissions_expires ON calendar_permissions(expires_at)');
    }
};
