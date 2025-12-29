<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasColumn = false;

        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'chat_identifier') {
                $hasColumn = true;
                break;
            }
        }

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE users ADD COLUMN chat_identifier TEXT NULL');
        }
    }
};
