<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE rfb_prospects ADD COLUMN exclusion_status TEXT NOT NULL DEFAULT 'active'");
        $pdo->exec('ALTER TABLE rfb_prospects ADD COLUMN exclusion_reason TEXT NULL');
        $pdo->exec('ALTER TABLE rfb_prospects ADD COLUMN excluded_at INTEGER NULL');

        $pdo->exec("UPDATE rfb_prospects
            SET exclusion_status = 'excluded',
                exclusion_reason = 'missing_contact',
                excluded_at = strftime('%s','now')
            WHERE (email IS NULL OR TRIM(email) = '')
              AND (phone IS NULL OR TRIM(phone) = '')");
    }
};
