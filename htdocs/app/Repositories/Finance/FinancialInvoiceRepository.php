<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class FinancialInvoiceRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_invoices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function listByStatus(string $status, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM financial_invoices WHERE status = :status ORDER BY due_date ASC LIMIT :limit'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function overdue(int $referenceTimestamp, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM financial_invoices
             WHERE status IN ("pending", "approved") AND due_date < :reference
             ORDER BY due_date ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':reference', $referenceTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function create(array $payload, array $items = []): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO financial_invoices (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        $invoiceId = (int)$this->pdo->lastInsertId();
        if ($items !== []) {
            $this->syncItems($invoiceId, $items);
        }

        return $invoiceId;
    }

    public function update(int $id, array $payload, ?array $items = null): void
    {
        if ($payload !== []) {
            $payload['updated_at'] = now();
            $payload['id'] = $id;

            $assignments = [];
            foreach ($payload as $column => $value) {
                if ($column === 'id') {
                    continue;
                }
                $assignments[] = sprintf('%s = :%s', $column, $column);
            }

            $sql = sprintf('UPDATE financial_invoices SET %s WHERE id = :id', implode(', ', $assignments));
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->prefix($payload));
        }

        if ($items !== null) {
            $this->syncItems($id, $items);
        }
    }

    public function markAsPaid(int $id, int $paidAtTimestamp, ?int $amount = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE financial_invoices
             SET status = "paid",
                 paid_at = :paid_at,
                 amount_cents = COALESCE(:amount, amount_cents),
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':paid_at' => $paidAtTimestamp,
            ':amount' => $amount,
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    private function syncItems(int $invoiceId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM financial_invoice_items WHERE invoice_id = :invoice_id')
            ->execute([':invoice_id' => $invoiceId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO financial_invoice_items (
                invoice_id, description, cost_center_id, quantity, amount_cents, tax_code, metadata, created_at, updated_at
            ) VALUES (
                :invoice_id, :description, :cost_center_id, :quantity, :amount_cents, :tax_code, :metadata, :created_at, :updated_at
            )'
        );

        foreach ($items as $item) {
            $insert->execute([
                ':invoice_id' => $invoiceId,
                ':description' => $item['description'] ?? 'Item',
                ':cost_center_id' => $item['cost_center_id'] ?? null,
                ':quantity' => $item['quantity'] ?? 1,
                ':amount_cents' => $item['amount_cents'] ?? 0,
                ':tax_code' => $item['tax_code'] ?? null,
                ':metadata' => $item['metadata'] ?? null,
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }
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
