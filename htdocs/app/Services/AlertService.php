<?php

declare(strict_types=1);

namespace App\Services;

final class AlertService
{
    private const FILE = __DIR__ . '/../../storage/logs/alerts.log';

    public static function push(string $source, string $message, array $meta = []): void
    {
        $entry = [
            'ts' => time(),
            'source' => $source,
            'message' => $message,
            'meta' => $meta,
        ];

        $dir = dirname(self::FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        @file_put_contents(self::FILE, $line . PHP_EOL, FILE_APPEND);
    }

    /**
     * @return array<int, array{ts:int,source:string,message:string,meta:array}>
     */
    public static function latest(int $limit = 200): array
    {
        if (!is_file(self::FILE)) {
            return [];
        }

        $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -1 * max(1, $limit));
        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entries[] = [
                'ts' => (int)($decoded['ts'] ?? 0),
                'source' => (string)($decoded['source'] ?? 'unknown'),
                'message' => (string)($decoded['message'] ?? ''),
                'meta' => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
            ];
        }

        return $entries;
    }
}
