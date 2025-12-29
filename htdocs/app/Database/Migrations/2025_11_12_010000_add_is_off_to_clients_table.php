<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'clients', 'is_off')) {
            return;
        }

        $pdo->exec('ALTER TABLE clients ADD COLUMN is_off INTEGER NOT NULL DEFAULT 0');
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($columns as $info) {
            if (($info['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
};
