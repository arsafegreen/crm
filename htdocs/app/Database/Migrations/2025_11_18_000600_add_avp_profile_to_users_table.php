<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'users');

        if (!isset($columns['is_avp'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_avp INTEGER NOT NULL DEFAULT 0');
        }

        if (!isset($columns['avp_identity_label'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avp_identity_label TEXT NULL');
        }

        if (!isset($columns['avp_identity_cpf'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avp_identity_cpf TEXT NULL');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_is_avp ON users(is_avp)');
    }

    /**
     * @return array<string, bool>
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
