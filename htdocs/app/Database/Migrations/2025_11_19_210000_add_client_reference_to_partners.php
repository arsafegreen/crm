<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo);

        if (!in_array('client_id', $columns, true)) {
            $pdo->exec('ALTER TABLE partners ADD COLUMN client_id INTEGER NULL');
        }

        if (!in_array('source_document', $columns, true)) {
            $pdo->exec('ALTER TABLE partners ADD COLUMN source_document TEXT NULL');
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS partners_client_id_unique ON partners(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS partners_source_document_index ON partners(source_document)');

        $pdo->exec(
            'UPDATE partners
                SET client_id = (
                    SELECT id FROM clients WHERE clients.document = partners.document LIMIT 1
                )
              WHERE client_id IS NULL AND document IS NOT NULL'
        );

        $pdo->exec(
            'UPDATE partners
                SET source_document = document
              WHERE source_document IS NULL AND document IS NOT NULL'
        );
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
