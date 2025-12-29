<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailCampaignBatchRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_campaign_batches WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listByCampaign(int $campaignId, array $filters = []): array
    {
        $sql = 'SELECT * FROM email_campaign_batches WHERE email_campaign_id = :campaign_id';
        $params = [':campaign_id' => $campaignId];

        if (isset($filters['status'])) {
            $sql .= ' AND status = :status';
            $params[':status'] = $filters['status'];
        }

        $sql .= ' ORDER BY id ASC';
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

        $stmt = $this->pdo->prepare("INSERT INTO email_campaign_batches ({$columns}) VALUES ({$placeholders})");
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

        $stmt = $this->pdo->prepare("UPDATE email_campaign_batches SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($data));
    }

    public function markProcessing(int $id): void
    {
        $this->update($id, [
            'status' => 'processing',
            'started_at' => time(),
        ]);
    }

    public function markCompleted(int $id): void
    {
        $this->update($id, [
            'status' => 'completed',
            'finished_at' => time(),
        ]);
    }

    public function incrementCounters(int $id, array $deltas): void
    {
        $fields = [];
        $params = [
            ':id' => $id,
            ':updated_at' => time(),
        ];

        foreach (['processed_count', 'failed_count'] as $column) {
            if (!isset($deltas[$column]) || (int)$deltas[$column] === 0) {
                continue;
            }

            $fields[] = sprintf('%s = %s + :%s_delta', $column, $column, $column);
            $params[':' . $column . '_delta'] = (int)$deltas[$column];
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = :updated_at';
        $sql = sprintf('UPDATE email_campaign_batches SET %s WHERE id = :id', implode(', ', $fields));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
