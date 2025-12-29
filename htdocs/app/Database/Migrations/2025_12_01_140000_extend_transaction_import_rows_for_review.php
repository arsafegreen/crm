<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'transaction_import_rows');

        if (!isset($columns['transaction_id'])) {
            $pdo->exec('ALTER TABLE transaction_import_rows ADD COLUMN transaction_id INTEGER NULL');
        }

        if (!isset($columns['imported_at'])) {
            $pdo->exec('ALTER TABLE transaction_import_rows ADD COLUMN imported_at INTEGER NULL');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_rows_transaction ON transaction_import_rows(transaction_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transaction_import_rows_batch_status ON transaction_import_rows(batch_id, status)');
    }

    /**
     * @return array<string, bool>
     */
    private function columns(PDO $pdo, string $table): array
    {
        $quotedTable = "'" . str_replace("'", "''", $table) . "'";
        $stmt = $pdo->query('PRAGMA table_info(' . $quotedTable . ')');

        $columns = [];
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['name'])) {
                    $columns[(string)$row['name']] = true;
                }
            }
        }

        return $columns;
    }
};
