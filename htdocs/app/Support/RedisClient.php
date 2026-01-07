<?php

declare(strict_types=1);

namespace App\Support;

use Predis\Client;

final class RedisClient
{
    private static ?Client $client = null;

    public static function connection(): ?Client
    {
        if (self::$client instanceof Client) {
            return self::$client;
        }

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int)env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD');
        $db = env('REDIS_DB', 0);

        try {
            $client = new Client([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'password' => $password ?: null,
                'database' => $db,
            ]);
            // Simple ping to validate connection
            $client->ping();
            self::$client = $client;
            return self::$client;
        } catch (\Throwable) {
            return null;
        }
    }
}
