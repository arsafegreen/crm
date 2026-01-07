<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): ?PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = config('database.connections.network_pg');
        if (!is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $db = $config['database'] ?? 'network';
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $sslmode = $config['sslmode'] ?? 'prefer';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $db, $sslmode);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo = $pdo;
            return self::$pdo;
        } catch (\Throwable) {
            return null;
        }
    }
}
