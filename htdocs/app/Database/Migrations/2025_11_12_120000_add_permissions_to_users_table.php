<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $existing = array_column($columns, 'name');

        if (!in_array('permissions', $existing, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NOT NULL DEFAULT '[]'");
        }

        $pdo->exec("UPDATE users SET permissions = '[]' WHERE permissions IS NULL");
    }
};
