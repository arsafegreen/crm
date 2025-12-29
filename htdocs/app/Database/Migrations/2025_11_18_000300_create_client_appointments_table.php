<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                avp_user_id INTEGER NOT NULL,
                pipeline_stage_id INTEGER NULL,
                status TEXT NOT NULL DEFAULT "scheduled",
                scheduled_for INTEGER NOT NULL,
                duration_minutes INTEGER NOT NULL DEFAULT 20,
                origin TEXT NULL,
                location TEXT NULL,
                notes TEXT NULL,
                created_by INTEGER NOT NULL,
                completed_at INTEGER NULL,
                canceled_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY(avp_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(pipeline_stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL,
                FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_appointments_client ON client_appointments(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_appointments_avp ON client_appointments(avp_user_id, scheduled_for)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_client_appointments_status ON client_appointments(status)');
    }
};
