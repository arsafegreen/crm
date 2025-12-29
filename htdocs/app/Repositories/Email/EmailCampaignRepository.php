<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailCampaignRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM email_campaigns';
        $params = [];
        $conditions = [];

        if (isset($filters['status'])) {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function insert(array $data): int
    {
        $timestamp = time();
        $data['created_at'] = $timestamp;
        $data['updated_at'] = $timestamp;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_campaigns ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = time();
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($data)));
        $data['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE email_campaigns SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($data));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_campaigns WHERE id = :id');
        $stmt->execute([':id' => $id]);
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
