<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $existing = array_column($columns, 'name');

        if (!in_array('session_token', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_token TEXT NULL');
        }

        if (!in_array('session_forced_at', $existing, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_forced_at INTEGER NULL');
        }
    }
};
