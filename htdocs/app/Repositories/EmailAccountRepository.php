<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use Throwable;

final class EmailAccountRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function all(bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM email_accounts';
        if (!$includeDeleted) {
            $sql .= ' WHERE deleted_at IS NULL';
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function insert(array $data): int
    {
        $timestamp = now();
        $data['created_at'] = $timestamp;
        $data['updated_at'] = $timestamp;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_accounts ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixParams($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = now();
        $assignments = implode(', ', array_map(
            static fn(string $field): string => sprintf('%s = :%s', $field, $field),
            array_keys($data)
        ));
        $data['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE email_accounts SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefixParams($data));
    }

    public function softDelete(int $id, ?int $actorId = null): void
    {
        $data = [
            'deleted_at' => now(),
        ];

        if ($actorId !== null) {
            $data['updated_by'] = $actorId;
        }

        $this->update($id, $data);
    }

    public function listPolicies(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM email_account_policies WHERE account_id IN ({$placeholders}) ORDER BY id ASC");
        $stmt->execute($accountIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === false) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $accountId = (int)$row['account_id'];
            $grouped[$accountId][] = $row;
        }

        return $grouped;
    }

    public function replacePolicies(int $accountId, array $policies): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM email_account_policies WHERE account_id = :account_id');
            $stmt->execute([':account_id' => $accountId]);

            if ($policies !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO email_account_policies (account_id, policy_type, policy_key, policy_value, metadata, created_at, updated_at)
                    VALUES (:account_id, :policy_type, :policy_key, :policy_value, :metadata, :created_at, :updated_at)'
                );

                foreach ($policies as $policy) {
                    $payload = [
                        'account_id' => $accountId,
                        'policy_type' => (string)($policy['policy_type'] ?? ''),
                        'policy_key' => (string)($policy['policy_key'] ?? ''),
                        'policy_value' => $policy['policy_value'] ?? null,
                        'metadata' => $policy['metadata'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $insert->execute($this->prefixParams($payload));
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function sumHourlyByDomain(string $domainKey, ?int $excludeId = null): int
    {
        $sql = 'SELECT COALESCE(SUM(hourly_limit), 0) AS total FROM email_accounts WHERE provider = :provider AND deleted_at IS NULL AND domain = :domain';
        $params = [
            ':provider' => 'custom',
            ':domain' => $domainKey,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public function sumHourlyAllCustom(?int $excludeId = null): int
    {
        $sql = 'SELECT COALESCE(SUM(hourly_limit), 0) AS total FROM email_accounts WHERE provider = :provider AND deleted_at IS NULL';
        $params = [
            ':provider' => 'custom',
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private function prefixParams(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }

        return $prefixed;
    }

    public function findActiveSender(?int $accountId = null): ?array
    {
        $sql = 'SELECT * FROM email_accounts WHERE status = "active" AND deleted_at IS NULL';
        $params = [];

        if ($accountId !== null) {
            $sql .= ' AND id = :id';
            $params[':id'] = $accountId;
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
