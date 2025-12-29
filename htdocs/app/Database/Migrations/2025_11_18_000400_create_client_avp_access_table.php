<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $this->ensureClientAccessScopeColumn($pdo);
        $this->createClientAvpAccessTable($pdo);
    }

    private function ensureClientAccessScopeColumn(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'users');

        if (!isset($columns['client_access_scope'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN client_access_scope TEXT NOT NULL DEFAULT 'all'");
        }
    }

    private function createClientAvpAccessTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_avp_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                avp_user_id INTEGER NOT NULL,
                granted_by INTEGER NULL,
                granted_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_client_avp_access_unique ON client_avp_access(client_id, avp_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_avp_access_user ON client_avp_access(avp_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_avp_access_client ON client_avp_access(client_id)');
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
