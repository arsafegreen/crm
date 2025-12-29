<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class TaxObligationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function upcoming(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tax_obligations WHERE status IN ("pending", "scheduled")
             ORDER BY COALESCE(due_date, due_day) ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function create(array $payload): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO tax_obligations (%s) VALUES (%s)',
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

        $sql = sprintf('UPDATE tax_obligations SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function markAsPaid(int $id, ?int $amount = null, ?int $paidAt = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tax_obligations
             SET status = "paid",
                 amount_estimate = COALESCE(:amount, amount_estimate),
                 due_date = COALESCE(due_date, :paid_at),
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':amount' => $amount,
            ':paid_at' => $paidAt,
            ':updated_at' => now(),
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
