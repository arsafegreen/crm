<?php

declare(strict_types=1);

use App\Database\Migration;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $columns = $this->listColumns($pdo, 'whatsapp_lines');

        if (!in_array('provider', $columns, true)) {
            $pdo->exec('ALTER TABLE whatsapp_lines ADD COLUMN provider TEXT NOT NULL DEFAULT "meta"');
        }

        if (!in_array('api_base_url', $columns, true)) {
            $pdo->exec('ALTER TABLE whatsapp_lines ADD COLUMN api_base_url TEXT NULL');
        }
    }

    /**
     * @return string[]
     */
    private function listColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("PRAGMA table_info('" . $table . "')");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string)($row['name'] ?? '');
        }

        return $names;
    }
};
