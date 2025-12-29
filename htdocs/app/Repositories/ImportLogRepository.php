<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ImportLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function record(
        string $source,
        string $filename,
        array $stats,
        ?int $userId = null,
        array $meta = []
    ): void {
        $timestamp = now();

        $data = [
            'user_id' => $userId,
            'source' => $source,
            'filename' => $filename,
            'processed' => (int)($stats['processed'] ?? 0),
            'created_clients' => (int)($stats['created_clients'] ?? 0),
            'updated_clients' => (int)($stats['updated_clients'] ?? 0),
            'created_certificates' => (int)($stats['created_certificates'] ?? 0),
            'updated_certificates' => (int)($stats['updated_certificates'] ?? 0),
            'skipped' => (int)($stats['skipped'] ?? 0),
            'skipped_older' => (int)($stats['skipped_older_certificates'] ?? 0),
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp,
        ];

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $key): string => ':' . $key, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO import_logs ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_combine(
            array_map(static fn(string $key): string => ':' . $key, array_keys($data)),
            array_values($data)
        ));
    }

    public function latest(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
