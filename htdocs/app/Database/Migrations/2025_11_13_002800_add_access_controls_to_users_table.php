<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columnsRaw = $pdo->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $existing = array_column($columnsRaw, 'name');

        $addColumn = static function (\PDO $pdo, array $existing, string $column, string $definition): void {
            if (in_array($column, $existing, true)) {
                return;
            }

            $pdo->exec(sprintf('ALTER TABLE users ADD COLUMN %s %s', $column, $definition));
        };

        $addColumn($pdo, $existing, 'access_allowed_from', 'INTEGER NULL');
        $addColumn($pdo, $existing, 'access_allowed_until', 'INTEGER NULL');
        $addColumn($pdo, $existing, 'require_known_device', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                fingerprint TEXT NOT NULL,
                user_agent TEXT NULL,
                label TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                approved_at INTEGER NULL,
                approved_by TEXT NULL,
                last_seen_at INTEGER NULL,
                last_ip TEXT NULL,
                last_location TEXT NULL,
                UNIQUE(user_id, fingerprint)
            )'
        );
    }
};
