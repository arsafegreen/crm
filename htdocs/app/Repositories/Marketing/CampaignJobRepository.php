<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class CampaignJobRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaign_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->decode($row) : null;
    }

    public function findByKind(string $kind): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaign_jobs WHERE kind = :kind LIMIT 1');
        $stmt->execute([':kind' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->decode($row) : null;
    }

    public function upsert(string $kind, array $data): array
    {
        $existing = $this->findByKind($kind);
        if ($existing === null) {
            return $this->create(array_merge($data, ['kind' => $kind]));
        }

        $this->update((int)$existing['id'], $data);
        return $this->find((int)$existing['id']) ?? $this->findByKind($kind) ?? $existing;
    }

    public function create(array $data): array
    {
        $timestamp = now();
        $payload = array_merge([
            'kind' => null,
            'target_day' => null,
            'target_month' => null,
            'start_time' => null,
            'pacing_seconds' => 40,
            'enabled' => 0,
            'status' => 'scheduled',
            'meta' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $data);

        if (isset($payload['meta'])) {
            $payload['meta'] = $this->encode($payload['meta']);
        }

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO campaign_jobs (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        $id = (int)$this->pdo->lastInsertId();
        $row = $this->find($id);
        if ($row !== null) {
            return $row;
        }

        return array_merge($payload, ['id' => $id]);
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $payload = $data;
        $payload['updated_at'] = now();

        if (isset($payload['meta'])) {
            $payload['meta'] = $this->encode($payload['meta']);
        }

        $assignments = [];
        foreach (array_keys($payload) as $column) {
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE campaign_jobs SET %s WHERE id = :id', implode(', ', $assignments));
        $payload['id'] = $id;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function listByKind(string $kind, ?string $status = null): array
    {
        $where = ['kind = :kind'];
        $params = [':kind' => $kind];

        if ($status !== null) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $sql = 'SELECT * FROM campaign_jobs WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'decode'], $rows);
    }

    private function decode(array $row): array
    {
        if (isset($row['id'])) {
            $row['id'] = (int)$row['id'];
        }
        if (isset($row['target_day'])) {
            $row['target_day'] = $row['target_day'] !== null ? (int)$row['target_day'] : null;
        }
        if (isset($row['target_month'])) {
            $row['target_month'] = $row['target_month'] !== null ? (int)$row['target_month'] : null;
        }
        if (isset($row['pacing_seconds'])) {
            $row['pacing_seconds'] = (int)$row['pacing_seconds'];
        }
        if (isset($row['enabled'])) {
            $row['enabled'] = (int)$row['enabled'];
        }
        if (isset($row['created_at'])) {
            $row['created_at'] = (int)$row['created_at'];
        }
        if (isset($row['updated_at'])) {
            $row['updated_at'] = (int)$row['updated_at'];
        }
        if (isset($row['meta'])) {
            $decoded = is_string($row['meta']) ? json_decode((string)$row['meta'], true) : $row['meta'];
            $row['meta'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['meta'] = [];
        }

        return $row;
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function prefix(array $values): array
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[':' . $key] = $value;
        }

        return $prefixed;
    }
}
