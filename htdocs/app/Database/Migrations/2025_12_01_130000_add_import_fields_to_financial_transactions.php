<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'financial_transactions');

        if (!isset($columns['import_row_id'])) {
            $pdo->exec('ALTER TABLE financial_transactions ADD COLUMN import_row_id INTEGER NULL');
        }

        if (!isset($columns['checksum'])) {
            $pdo->exec('ALTER TABLE financial_transactions ADD COLUMN checksum TEXT NULL');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_import_row ON financial_transactions(import_row_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_financial_transactions_checksum ON financial_transactions(checksum)');
    }

    /**
     * @return array<string, bool>
     */
    private function columns(\PDO $pdo, string $table): array
    {
        $quotedTable = "'" . str_replace("'", "''", $table) . "'";
        $stmt = $pdo->query('PRAGMA table_info(' . $quotedTable . ')');
        $map = [];
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['name'])) {
                    $map[(string)$row['name']] = true;
                }
            }
        }

        return $map;
    }
};
