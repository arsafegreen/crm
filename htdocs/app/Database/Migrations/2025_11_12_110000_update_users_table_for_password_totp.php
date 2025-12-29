<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $existing = array_column($columns, 'name');

        if (!in_array('email', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT NULL');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email)');
        }

        if (!in_array('password_hash', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN password_hash TEXT NULL');
        }

        if (!in_array('password_updated_at', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN password_updated_at INTEGER NULL');
        }

        if (!in_array('totp_secret', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT NULL');
        }

        if (!in_array('totp_enabled', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0');
        }

        if (!in_array('totp_confirmed_at', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_confirmed_at INTEGER NULL');
        }

        if (!in_array('last_login_at', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at INTEGER NULL');
        }
    }
};
