<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                channel TEXT NOT NULL,
                subject TEXT NOT NULL,
                body_html TEXT NULL,
                body_text TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_templates_channel ON templates(channel)');

        if (!$this->columnExists($pdo, 'campaigns', 'template_id')) {
            $pdo->exec('ALTER TABLE campaigns ADD COLUMN template_id INTEGER NULL');
        }
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
