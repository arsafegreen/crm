<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'marketing_contacts');

        if (!isset($columns['preferences_token'])) {
            $pdo->exec('ALTER TABLE marketing_contacts ADD COLUMN preferences_token TEXT NULL');
        }

        if (!isset($columns['preferences_token_generated_at'])) {
            $pdo->exec('ALTER TABLE marketing_contacts ADD COLUMN preferences_token_generated_at INTEGER NULL');
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_marketing_contacts_preferences_token ON marketing_contacts(preferences_token)');
    }

    /**
     * @return array<string, true>
     */
    private function columns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $info) {
            if (!empty($info['name'])) {
                $map[(string)$info['name']] = true;
            }
        }

        return $map;
    }
};
