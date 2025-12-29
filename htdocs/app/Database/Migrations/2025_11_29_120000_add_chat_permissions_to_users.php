<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'users');

        if (!isset($columns['allow_internal_chat'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN allow_internal_chat INTEGER NOT NULL DEFAULT 1');
        }

        if (!isset($columns['allow_external_chat'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN allow_external_chat INTEGER NOT NULL DEFAULT 1');
        }
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
