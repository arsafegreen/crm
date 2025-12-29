<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS marketing_segment_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                segment_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                matched_at INTEGER NOT NULL,
                match_reason TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (segment_id) REFERENCES marketing_segments(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_segment_contacts_unique ON marketing_segment_contacts(segment_id, contact_id)');
    }
};
