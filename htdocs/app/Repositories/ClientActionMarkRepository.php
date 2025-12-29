<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ClientActionMarkRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    /**
     * @return array{client_id:int,mark_type:string,created_by:int,created_at:int,expires_at:int}
     */
    public function upsert(int $clientId, string $markType, int $userId, int $ttlSeconds): array
    {
        $now = now();
        $expiresAt = $now + max(1, $ttlSeconds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO client_action_marks (client_id, mark_type, created_by, created_at, expires_at)
             VALUES (:client_id, :mark_type, :created_by, :created_at, :expires_at)
             ON CONFLICT(client_id, mark_type) DO UPDATE SET
                created_by = excluded.created_by,
                created_at = excluded.created_at,
                expires_at = excluded.expires_at'
        );

        $stmt->execute([
            ':client_id' => $clientId,
            ':mark_type' => $markType,
            ':created_by' => $userId,
            ':created_at' => $now,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'client_id' => $clientId,
            'mark_type' => $markType,
            'created_by' => $userId,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<int, array<int, array{type:string,created_by:int,created_at:int,expires_at:int}>>
     */
    public function activeMarksForClients(array $clientIds): array
    {
        $ids = $this->normalizeClientIds($clientIds);
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [':now' => now()];
        foreach ($ids as $index => $clientId) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $clientId;
        }

        $sql = sprintf(
            'SELECT client_id, mark_type, created_by, created_at, expires_at
             FROM client_action_marks
             WHERE client_id IN (%s) AND expires_at >= :now
             ORDER BY client_id ASC, mark_type ASC',
            implode(',', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];
        foreach ($rows as $row) {
            $clientId = (int)($row['client_id'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }

            $grouped[$clientId][] = [
                'type' => (string)($row['mark_type'] ?? ''),
                'created_by' => (int)($row['created_by'] ?? 0),
                'created_at' => (int)($row['created_at'] ?? 0),
                'expires_at' => (int)($row['expires_at'] ?? 0),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array{type:string,created_by:int,created_at:int,expires_at:int}>
     */
    public function activeMarksForClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }

        $all = $this->activeMarksForClients([$clientId]);
        return $all[$clientId] ?? [];
    }

    public function purgeExpired(?int $cutoff = null): int
    {
        $threshold = $cutoff ?? now();
        $stmt = $this->pdo->prepare('DELETE FROM client_action_marks WHERE expires_at < :cutoff');
        $stmt->execute([':cutoff' => $threshold]);
        return $stmt->rowCount();
    }

    /**
     * @return int[]
     */
    private function normalizeClientIds(array $clientIds): array
    {
        $normalized = [];
        foreach ($clientIds as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }
}
