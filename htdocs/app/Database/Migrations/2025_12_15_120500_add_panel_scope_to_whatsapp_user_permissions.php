<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(whatsapp_user_permissions)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'panel_scope') {
                return;
            }
        }

        $pdo->exec('ALTER TABLE whatsapp_user_permissions ADD COLUMN panel_scope TEXT NULL');
    }
};
