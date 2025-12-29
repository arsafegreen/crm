<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;
use Throwable;

final class SegmentRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM marketing_segments ORDER BY name ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function allWithCounts(): array
    {
        $sql = 'SELECT s.*, l.name AS list_name,
                    (SELECT COUNT(*) FROM marketing_segment_contacts sc WHERE sc.segment_id = s.id) AS contacts_total
                FROM marketing_segments s
                LEFT JOIN audience_lists l ON l.id = s.list_id
                ORDER BY s.updated_at DESC';

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM marketing_segments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM marketing_segments WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
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
            'INSERT INTO marketing_segments (%s) VALUES (%s)',
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

        $sql = sprintf('UPDATE marketing_segments SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function replaceContacts(int $segmentId, array $contactIds): void
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn($value): int => (int)$value, $contactIds),
            static fn(int $value): bool => $value > 0
        )));

        $timestamp = now();

        $this->pdo->beginTransaction();

        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM marketing_segment_contacts WHERE segment_id = :segment_id');
            $deleteStmt->execute([':segment_id' => $segmentId]);

            if ($normalizedIds !== []) {
                $insertSql = 'INSERT INTO marketing_segment_contacts (
                        segment_id, contact_id, matched_at, match_reason, created_at, updated_at
                    ) VALUES (
                        :segment_id, :contact_id, :matched_at, :match_reason, :created_at, :updated_at
                    )';
                $insertStmt = $this->pdo->prepare($insertSql);

                foreach ($normalizedIds as $contactId) {
                    $insertStmt->execute([
                        ':segment_id' => $segmentId,
                        ':contact_id' => $contactId,
                        ':matched_at' => $timestamp,
                        ':match_reason' => null,
                        ':created_at' => $timestamp,
                        ':updated_at' => $timestamp,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM marketing_segments WHERE id = :id');
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
