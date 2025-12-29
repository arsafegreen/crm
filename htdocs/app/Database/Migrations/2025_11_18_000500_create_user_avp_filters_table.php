<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $this->dropOldClientAccessTable($pdo);
        $this->createUserAvpFiltersTable($pdo);
        $this->ensureOnlineFlagColumn($pdo);
    }

    private function dropOldClientAccessTable(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS client_avp_access');
    }

    private function createUserAvpFiltersTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_avp_filters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                label TEXT NOT NULL,
                normalized_name TEXT NOT NULL,
                avp_cpf TEXT NULL,
                granted_by INTEGER NULL,
                created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\')),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_user_avp_filters_unique ON user_avp_filters(user_id, normalized_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_avp_filters_user ON user_avp_filters(user_id)');
    }

    private function ensureOnlineFlagColumn(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'users');

        if (!isset($columns['allow_online_clients'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN allow_online_clients INTEGER NOT NULL DEFAULT 0');
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
