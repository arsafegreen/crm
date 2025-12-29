<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(whatsapp_lines)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasColumn = false;

        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'alt_gateway_instance') {
                $hasColumn = true;
                break;
            }
        }

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE whatsapp_lines ADD COLUMN alt_gateway_instance TEXT NULL');
        }
    }
};
