<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_action_marks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                mark_type TEXT NOT NULL,
                created_by INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_client_action_marks_unique ON client_action_marks(client_id, mark_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_action_marks_expires_at ON client_action_marks(expires_at)');
    }
};
