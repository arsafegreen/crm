<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(whatsapp_threads)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_column($columns, 'name');

        if (!in_array('line_id', $names, true)) {
            $pdo->exec('ALTER TABLE whatsapp_threads ADD COLUMN line_id INTEGER NULL REFERENCES whatsapp_lines(id)');
        }

        $pdo->exec("UPDATE whatsapp_threads SET status = 'open' WHERE status NOT IN ('open','closed','waiting') OR status IS NULL");
    }
};
