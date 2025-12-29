<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class AvpScheduleRepository
{
    private PDO $pdo;
    private bool $schemaEnsured = false;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function upsert(array $data): void
    {
        $this->ensureSchema();
        $keys = ['user_id', 'day_of_week'];
        $existing = $this->findConfig((int)$data['user_id'], (int)$data['day_of_week']);

        if ($existing !== null) {
            $this->update((int)$existing['id'], $data);
            return;
        }

        $this->create($data);
    }

    public function create(array $data): int
    {
        $this->ensureSchema();
        $timestamp = now();
        $data['created_at'] = $data['created_at'] ?? $timestamp;
        $data['updated_at'] = $data['updated_at'] ?? $timestamp;

        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));
        $stmt = $this->pdo->prepare("INSERT INTO avp_schedule_configs ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->ensureSchema();
        $data['updated_at'] = now();
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($data)));
        $data['id'] = $id;
        $stmt = $this->pdo->prepare("UPDATE avp_schedule_configs SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($data));
    }

    public function findConfig(int $userId, int $dayOfWeek): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM avp_schedule_configs WHERE user_id = :user AND day_of_week = :day LIMIT 1');
        $stmt->execute([
            ':user' => $userId,
            ':day' => $dayOfWeek,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function scheduleMatrixForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM avp_schedule_configs WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matrix = [];
        foreach ($rows as $row) {
            $day = (int)($row['day_of_week'] ?? 0);
            $matrix[$day] = $row;
        }
        return $matrix;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $statement = $this->pdo->query("PRAGMA table_info('avp_schedule_configs')");
        $columns = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $hasClosedColumn = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'is_closed') {
                $hasClosedColumn = true;
                break;
            }
        }

        if (!$hasClosedColumn) {
            $this->pdo->exec('ALTER TABLE avp_schedule_configs ADD COLUMN is_closed INTEGER NOT NULL DEFAULT 0');
        }

        $this->schemaEnsured = true;
    }

    private function prefix(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }
}
