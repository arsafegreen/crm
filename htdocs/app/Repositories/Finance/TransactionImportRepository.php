<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class TransactionImportRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function createBatch(array $payload): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO transaction_import_batches (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function updateBatch(int $batchId, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $batchId;

        $assignments = [];
        foreach ($payload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE transaction_import_batches SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertRows(int $batchId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT OR IGNORE INTO transaction_import_rows (
                    batch_id, row_number, status, transaction_type, amount_cents,
                    occurred_at, description, reference, checksum, raw_payload,
                    normalized_payload, error_code, error_message, transaction_id,
                    imported_at, created_at, updated_at
                ) VALUES (
                    :batch_id, :row_number, :status, :transaction_type, :amount_cents,
                    :occurred_at, :description, :reference, :checksum, :raw_payload,
                    :normalized_payload, :error_code, :error_message, :transaction_id,
                    :imported_at, :created_at, :updated_at
                )';

        $stmt = $this->pdo->prepare($sql);
        foreach ($rows as $row) {
            $timestamp = $row['created_at'] ?? now();
            $data = array_merge([
                'batch_id' => $batchId,
                'created_at' => $timestamp,
                'updated_at' => $row['updated_at'] ?? $timestamp,
            ], $row);

            $stmt->execute([
                ':batch_id' => $batchId,
                ':row_number' => $data['row_number'],
                ':status' => $data['status'] ?? 'pending',
                ':transaction_type' => $data['transaction_type'] ?? null,
                ':amount_cents' => $data['amount_cents'] ?? null,
                ':occurred_at' => $data['occurred_at'] ?? null,
                ':description' => $data['description'] ?? null,
                ':reference' => $data['reference'] ?? null,
                ':checksum' => $data['checksum'] ?? null,
                ':raw_payload' => $data['raw_payload'] ?? null,
                ':normalized_payload' => $data['normalized_payload'] ?? null,
                ':error_code' => $data['error_code'] ?? null,
                ':error_message' => $data['error_message'] ?? null,
                ':transaction_id' => $data['transaction_id'] ?? null,
                ':imported_at' => $data['imported_at'] ?? null,
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at'],
            ]);
        }
    }

    public function clearRows(int $batchId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM transaction_import_rows WHERE batch_id = :batch_id');
        $stmt->execute([':batch_id' => $batchId]);
    }

    public function findBatch(int $batchId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, fa.display_name AS account_name, fa.institution AS account_institution
             FROM transaction_import_batches b
             LEFT JOIN financial_accounts fa ON fa.id = b.account_id
             WHERE b.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $batchId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function listBatches(int $limit = 25, int $offset = 0, ?string $status = null): array
    {
        $sql = 'SELECT b.*, fa.display_name AS account_name
                FROM transaction_import_batches b
                LEFT JOIN financial_accounts fa ON fa.id = b.account_id';

        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE b.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY b.created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function statusSummary(): array
    {
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) AS total
             FROM transaction_import_batches
             GROUP BY status'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string)$row['status']] = (int)$row['total'];
        }

        return $summary;
    }

    public function rowsByStatus(int $batchId, string $status, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transaction_import_rows WHERE batch_id = :batch_id AND status = :status ORDER BY row_number ASC LIMIT :limit'
        );
        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function rowsForImport(int $batchId, ?array $rowIds = null): array
    {
        $sql = 'SELECT * FROM transaction_import_rows
                WHERE batch_id = :batch_id AND status = :status AND transaction_id IS NULL';

        $bindings = [];
        if ($rowIds !== null) {
            $filteredIds = array_values(array_unique(array_map('intval', $rowIds)));
            if ($filteredIds === []) {
                return [];
            }

            $placeholders = [];
            foreach ($filteredIds as $index => $rowId) {
                $key = ':row_' . $index;
                $placeholders[] = $key;
                $bindings[$key] = $rowId;
            }

            $sql .= ' AND id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY row_number ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'valid');
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function rowById(int $batchId, int $rowId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transaction_import_rows WHERE batch_id = :batch_id AND id = :id LIMIT 1'
        );
        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $rowId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function rowStatusSummary(int $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS total
             FROM transaction_import_rows
             WHERE batch_id = :batch_id
             GROUP BY status'
        );

        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string)$row['status']] = (int)$row['total'];
        }

        return $summary;
    }

    public function markRowStatus(int $rowId, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $rowId;

        $assignments = [];
        foreach ($payload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE transaction_import_rows SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function recordEvent(int $batchId, string $level, string $message, ?array $context = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transaction_import_events (batch_id, level, message, context, created_at)
             VALUES (:batch_id, :level, :message, :context, :created_at)'
        );

        $stmt->execute([
            ':batch_id' => $batchId,
            ':level' => $level,
            ':message' => $message,
            ':context' => $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null,
            ':created_at' => now(),
        ]);
    }

    public function eventsForBatch(int $batchId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transaction_import_events
             WHERE batch_id = :batch_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function recentBatches(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, fa.display_name AS account_name
             FROM transaction_import_batches b
             LEFT JOIN financial_accounts fa ON fa.id = b.account_id
             ORDER BY b.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
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
