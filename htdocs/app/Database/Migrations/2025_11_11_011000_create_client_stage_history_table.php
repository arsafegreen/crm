<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_stage_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                from_stage_id INTEGER NULL,
                to_stage_id INTEGER NULL,
                changed_at INTEGER NOT NULL,
                changed_by TEXT NULL,
                notes TEXT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (from_stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL,
                FOREIGN KEY (to_stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_stage_history_client ON client_stage_history(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_stage_history_changed_at ON client_stage_history(changed_at)');
    }
};
