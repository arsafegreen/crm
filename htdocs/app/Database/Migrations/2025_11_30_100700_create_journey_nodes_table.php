<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS journey_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journey_id INTEGER NOT NULL,
                node_key TEXT NOT NULL,
                node_type TEXT NOT NULL,
                config TEXT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                parent_key TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (journey_id) REFERENCES marketing_journeys(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_journey_nodes_unique ON journey_nodes(journey_id, node_key)');
    }
};
