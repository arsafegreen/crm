<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class FinancialAccountRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM financial_accounts';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY is_primary DESC, display_name ASC';

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findBySyncIdentifier(?string $provider, ?string $identifier): ?array
    {
        if (!$provider || !$identifier) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM financial_accounts WHERE sync_provider = :provider AND sync_identifier = :identifier LIMIT 1'
        );
        $stmt->execute([
            ':provider' => $provider,
            ':identifier' => $identifier,
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function create(array $payload): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO financial_accounts (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $id;

        $assignments = [];
        foreach ($payload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE financial_accounts SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM financial_accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function adjustBalance(int $accountId, int $delta): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE financial_accounts
             SET current_balance = current_balance + :delta,
                 available_balance = available_balance + :delta,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':delta' => $delta,
            ':updated_at' => now(),
            ':id' => $accountId,
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
