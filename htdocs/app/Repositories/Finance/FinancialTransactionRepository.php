<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class FinancialTransactionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function listByAccount(int $accountId, int $limit = 200, ?int $before = null): array
    {
        $sql = 'SELECT * FROM financial_transactions WHERE account_id = :account_id';
        if ($before !== null) {
            $sql .= ' AND occurred_at < :before';
        }

        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        if ($before !== null) {
            $stmt->bindValue(':before', $before, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function recent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ft.*, fa.display_name AS account_name, cc.name AS cost_center_name
             FROM financial_transactions ft
             INNER JOIN financial_accounts fa ON fa.id = ft.account_id
             LEFT JOIN cost_centers cc ON cc.id = ft.cost_center_id
             ORDER BY ft.occurred_at DESC, ft.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
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
            'INSERT INTO financial_transactions (%s) VALUES (%s)',
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

        $sql = sprintf('UPDATE financial_transactions SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function recalculateBalance(int $accountId): void
    {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare(
            'SELECT id, amount_cents, transaction_type FROM financial_transactions
             WHERE account_id = :account_id
             ORDER BY occurred_at ASC, id ASC'
        );
        $stmt->execute([':account_id' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $balance = 0;
        $update = $this->pdo->prepare('UPDATE financial_transactions SET balance_after = :balance WHERE id = :id');
        foreach ($rows as $row) {
            $amount = (int)$row['amount_cents'];
            $balance += ($row['transaction_type'] ?? 'debit') === 'credit' ? $amount : -$amount;
            $update->execute([
                ':balance' => $balance,
                ':id' => $row['id'],
            ]);
        }

        $this->pdo->prepare(
            'UPDATE financial_accounts SET current_balance = :balance, available_balance = :balance, updated_at = :updated_at
             WHERE id = :account_id'
        )->execute([
            ':balance' => $balance,
            ':updated_at' => now(),
            ':account_id' => $accountId,
        ]);

        $this->pdo->commit();
    }

    public function findByChecksum(int $accountId, string $checksum, ?int $since = null): ?array
    {
        $sql = 'SELECT * FROM financial_transactions WHERE account_id = :account_id AND checksum = :checksum';
        if ($since !== null) {
            $sql .= ' AND occurred_at >= :since';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':checksum', $checksum);
        if ($since !== null) {
            $stmt->bindValue(':since', $since, PDO::PARAM_INT);
        }
        $stmt->execute();

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM financial_transactions WHERE id = :id');
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
