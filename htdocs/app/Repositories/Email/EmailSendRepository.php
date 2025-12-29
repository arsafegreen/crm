<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailSendRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_sends WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listPending(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_sends WHERE status = "pending" ORDER BY id ASC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function listByBatch(int $batchId, array $filters = [], int $limit = 500): array
    {
        $sql = 'SELECT * FROM email_sends WHERE batch_id = :batch_id';
        $params = [':batch_id' => $batchId];

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statusPlaceholders = [];
            foreach (array_values($filters['statuses']) as $index => $status) {
                $placeholder = ':status_' . $index;
                $statusPlaceholders[] = $placeholder;
                $params[$placeholder] = $status;
            }
            $sql .= sprintf(' AND status IN (%s)', implode(', ', $statusPlaceholders));
        }

        if (!empty($filters['scheduled_before'])) {
            $sql .= ' AND (scheduled_at IS NULL OR scheduled_at <= :scheduled_before)';
            $params[':scheduled_before'] = (int)$filters['scheduled_before'];
        }

        $sql .= ' ORDER BY id ASC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function countByBatch(int $batchId, array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM email_sends WHERE batch_id = :batch_id';
        $params = [':batch_id' => $batchId];

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statusPlaceholders = [];
            foreach (array_values($filters['statuses']) as $index => $status) {
                $placeholder = ':status_' . $index;
                $statusPlaceholders[] = $placeholder;
                $params[$placeholder] = $status;
            }
            $sql .= sprintf(' AND status IN (%s)', implode(', ', $statusPlaceholders));
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        $count = $stmt->fetchColumn();

        return $count === false ? 0 : (int)$count;
    }

    public function insert(array $data): int
    {
        $timestamp = time();
        $data['created_at'] = $timestamp;
        $data['updated_at'] = $timestamp;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_sends ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function bulkInsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $timestamp = time();
        foreach ($rows as &$row) {
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;
        }

        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO email_sends (%s) VALUES %s',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($rows), $placeholders))
        );

        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function updateStatus(int $id, string $status, array $extra = []): void
    {
        $data = array_merge($extra, [
            'status' => $status,
            'updated_at' => time(),
            'id' => $id,
        ]);

        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($data)));
        $stmt = $this->pdo->prepare("UPDATE email_sends SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($data));
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_sends SET attempts = attempts + 1, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':updated_at' => time(),
            ':id' => $id,
        ]);
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
