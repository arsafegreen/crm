<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $hasColumn = false;
        $columns = $pdo->query('PRAGMA table_info(clients)');
        $columnData = $columns !== false ? $columns->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($columnData as $column) {
            if (($column['name'] ?? '') === 'extra_phones') {
                $hasColumn = true;
                break;
            }
        }

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN extra_phones TEXT NULL');
        }
    }
};
