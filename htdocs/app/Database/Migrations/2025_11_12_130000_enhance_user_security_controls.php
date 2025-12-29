<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $existing = array_column($columns, 'name');

        if (!in_array('failed_login_attempts', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN failed_login_attempts INTEGER NOT NULL DEFAULT 0');
        }

        if (!in_array('locked_until', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN locked_until INTEGER NULL');
        }

        if (!in_array('previous_password_hash', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN previous_password_hash TEXT NULL');
        }
    }
};
