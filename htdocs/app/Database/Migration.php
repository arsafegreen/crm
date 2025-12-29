<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

abstract class Migration
{
    abstract public function up(PDO $pdo): void;

    public function down(PDO $pdo): void
    {
    }
}
