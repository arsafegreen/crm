<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query("PRAGMA table_info('avp_schedule_configs')")->fetchAll(PDO::FETCH_ASSOC);
        $hasColumn = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'is_closed') {
                $hasColumn = true;
                break;
            }
        }

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE avp_schedule_configs ADD COLUMN is_closed INTEGER NOT NULL DEFAULT 0");
        }
    }

    public function down(PDO $pdo): void
    {
        // SQLite does not support dropping columns easily; no-op.
    }
};
