<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE rfb_prospects ADD COLUMN responsible_name TEXT NULL');
        $pdo->exec('ALTER TABLE rfb_prospects ADD COLUMN responsible_birthdate INTEGER NULL');
    }
};
