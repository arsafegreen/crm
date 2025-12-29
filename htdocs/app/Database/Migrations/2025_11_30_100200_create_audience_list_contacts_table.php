<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audience_list_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                list_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                subscription_status TEXT NOT NULL DEFAULT "subscribed",
                source TEXT NULL,
                subscribed_at INTEGER NOT NULL,
                unsubscribed_at INTEGER NULL,
                consent_token TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (list_id) REFERENCES audience_lists(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_audience_list_contacts_unique ON audience_list_contacts(list_id, contact_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audience_list_contacts_status ON audience_list_contacts(subscription_status)');
    }
};
