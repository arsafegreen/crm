<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo);

        if (!in_array('billing_mode', $columns, true)) {
            $pdo->exec("ALTER TABLE partners ADD COLUMN billing_mode TEXT NOT NULL DEFAULT 'custo'");
            $pdo->exec("UPDATE partners SET billing_mode = 'custo' WHERE billing_mode IS NULL OR billing_mode = ''");
        }

        if (!in_array('billing_mode_updated_at', $columns, true)) {
            $pdo->exec('ALTER TABLE partners ADD COLUMN billing_mode_updated_at INTEGER NULL');
        }
    }

    /**
     * @return array<int, string>
     */
    private function columns(\PDO $pdo): array
    {
        $stmt = $pdo->query('PRAGMA table_info(partners)');
        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_values(array_map(static function (array $row): string {
            return (string)($row['name'] ?? '');
        }, $rows));
    }
};
