<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                type TEXT NOT NULL DEFAULT "follow_up",
                due_at INTEGER NULL,
                completed_at INTEGER NULL,
                created_by TEXT NULL,
                assigned_to TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_client ON tasks(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_due_at ON tasks(due_at)');
    }
};
