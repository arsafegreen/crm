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

        $addColumn($pdo, $existing, 'session_ip', 'TEXT NULL');
        $addColumn($pdo, $existing, 'session_location', 'TEXT NULL');
        $addColumn($pdo, $existing, 'session_user_agent', 'TEXT NULL');
        $addColumn($pdo, $existing, 'last_login_ip', 'TEXT NULL');
        $addColumn($pdo, $existing, 'last_login_location', 'TEXT NULL');
        $addColumn($pdo, $existing, 'session_started_at', 'INTEGER NULL');
    }
};
