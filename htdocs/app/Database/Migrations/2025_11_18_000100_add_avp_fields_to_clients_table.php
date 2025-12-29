<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'clients');

        if (!isset($columns['assigned_avp_id'])) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN assigned_avp_id INTEGER NULL');
        }

        if (!isset($columns['appointment_status'])) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN appointment_status TEXT NULL");
        }

        if (!isset($columns['appointment_alert_at'])) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN appointment_alert_at INTEGER NULL');
        }

        if (!isset($columns['appointment_notes'])) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN appointment_notes TEXT NULL');
        }

        if (!$this->indexExists($pdo, 'clients', 'idx_clients_assigned_avp')) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_assigned_avp ON clients(assigned_avp_id)');
        }
        if (!$this->indexExists($pdo, 'clients', 'idx_clients_appointment_status')) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_appointment_status ON clients(appointment_status)');
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

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('PRAGMA index_list(' . $table . ')');
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $info) {
            if (isset($info['name']) && $info['name'] === $index) {
                return true;
            }
        }
        return false;
    }
};
