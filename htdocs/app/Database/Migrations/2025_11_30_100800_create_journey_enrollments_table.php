<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS journey_enrollments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journey_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                current_node_key TEXT NULL,
                entered_at INTEGER NOT NULL,
                exited_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (journey_id) REFERENCES marketing_journeys(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_journey_enrollments_unique ON journey_enrollments(journey_id, contact_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_journey_enrollments_status ON journey_enrollments(status)');
    }
};
