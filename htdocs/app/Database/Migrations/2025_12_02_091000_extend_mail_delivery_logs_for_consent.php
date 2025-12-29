<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'mail_delivery_logs')) {
            $this->createTable($pdo);
            return;
        }

        $columns = $this->columns($pdo, 'mail_delivery_logs');
        $needsRebuild = false;

        $queueJobColumn = $columns['queue_job_id'] ?? null;
        if ($queueJobColumn === null || (($queueJobColumn['notnull'] ?? 1) === 1)) {
            $needsRebuild = true;
        }

        foreach (['contact_id', 'actor_ip', 'actor_agent'] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                $needsRebuild = true;
                break;
            }
        }

        if ($needsRebuild) {
            $backupName = 'mail_delivery_logs_backup_' . time();
            $pdo->exec('ALTER TABLE mail_delivery_logs RENAME TO ' . $backupName);
            $this->createTable($pdo);
            $pdo->exec(
                'INSERT INTO mail_delivery_logs (id, queue_job_id, provider_event_id, event_type, occurred_at, payload, created_at)
                 SELECT id, queue_job_id, provider_event_id, event_type, occurred_at, payload, created_at FROM ' . $backupName
            );
            $pdo->exec('DROP TABLE ' . $backupName);
            return;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_contact ON mail_delivery_logs(contact_id)');
    }

    private function createTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_delivery_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue_job_id INTEGER NULL,
                contact_id INTEGER NULL,
                provider_event_id TEXT NULL,
                event_type TEXT NOT NULL,
                occurred_at INTEGER NOT NULL,
                payload TEXT NULL,
                actor_ip TEXT NULL,
                actor_agent TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (queue_job_id) REFERENCES mail_queue_jobs(id) ON DELETE SET NULL,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_queue_job ON mail_delivery_logs(queue_job_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_event ON mail_delivery_logs(event_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_delivery_contact ON mail_delivery_logs(contact_id)');
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $info) {
            if (!empty($info['name'])) {
                $map[(string)$info['name']] = $info;
            }
        }

        return $map;
    }
};
