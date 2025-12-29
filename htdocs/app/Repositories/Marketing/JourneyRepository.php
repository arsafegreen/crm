<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;
use Throwable;

final class JourneyRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function create(array $payload, array $nodes = []): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $this->pdo->beginTransaction();

        try {
            $journeyId = $this->insertJourney($payload);

            if ($nodes !== []) {
                $this->replaceNodes($journeyId, $nodes, false);
            }

            $this->pdo->commit();
            return $journeyId;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
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
            if ($column === 'definition' || $column === 'settings') {
                $payload[$column] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE marketing_journeys SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function replaceNodes(int $journeyId, array $nodes, bool $wrapInTransaction = true): void
    {
        if ($wrapInTransaction) {
            $this->pdo->beginTransaction();
        }

        $timestamp = now();

        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM journey_nodes WHERE journey_id = :journey_id');
            $deleteStmt->execute([':journey_id' => $journeyId]);

            if ($nodes !== []) {
                $insertSql = 'INSERT INTO journey_nodes (
                        journey_id, node_key, node_type, config, position, parent_key, created_at, updated_at
                    ) VALUES (
                        :journey_id, :node_key, :node_type, :config, :position, :parent_key, :created_at, :updated_at
                    )';
                $insertStmt = $this->pdo->prepare($insertSql);

                foreach ($nodes as $node) {
                    $insertStmt->execute([
                        ':journey_id' => $journeyId,
                        ':node_key' => $node['node_key'] ?? uniqid('node_', true),
                        ':node_type' => $node['node_type'] ?? 'action',
                        ':config' => $this->encodeValue($node['config'] ?? null),
                        ':position' => (int)($node['position'] ?? 0),
                        ':parent_key' => $node['parent_key'] ?? null,
                        ':created_at' => $timestamp,
                        ':updated_at' => $timestamp,
                    ]);
                }
            }

            if ($wrapInTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($wrapInTransaction) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function enrollContact(int $journeyId, int $contactId, array $metadata = []): int
    {
        $timestamp = now();
        $data = [
            'journey_id' => $journeyId,
            'contact_id' => $contactId,
            'status' => $metadata['status'] ?? 'active',
            'current_node_key' => $metadata['current_node_key'] ?? null,
            'entered_at' => $metadata['entered_at'] ?? $timestamp,
            'exited_at' => $metadata['exited_at'] ?? null,
            'metadata' => $this->encodeValue($metadata['metadata'] ?? []),
            'created_at' => $metadata['created_at'] ?? $timestamp,
            'updated_at' => $timestamp,
        ];

        $sql = 'INSERT INTO journey_enrollments (
                    journey_id, contact_id, status, current_node_key,
                    entered_at, exited_at, metadata, created_at, updated_at
                ) VALUES (
                    :journey_id, :contact_id, :status, :current_node_key,
                    :entered_at, :exited_at, :metadata, :created_at, :updated_at
                )
                ON CONFLICT(journey_id, contact_id) DO UPDATE SET
                    status = excluded.status,
                    current_node_key = excluded.current_node_key,
                    entered_at = excluded.entered_at,
                    exited_at = excluded.exited_at,
                    metadata = COALESCE(excluded.metadata, journey_enrollments.metadata),
                    updated_at = excluded.updated_at';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($data));

        $idStmt = $this->pdo->prepare('SELECT id FROM journey_enrollments WHERE journey_id = :journey_id AND contact_id = :contact_id');
        $idStmt->execute([
            ':journey_id' => $journeyId,
            ':contact_id' => $contactId,
        ]);

        $id = $idStmt->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }

    public function updateEnrollmentStatus(int $enrollmentId, string $status, ?string $currentNodeKey = null, ?array $metadata = null): void
    {
        $fields = [
            'status' => $status,
            'updated_at' => now(),
        ];

        if ($currentNodeKey !== null) {
            $fields['current_node_key'] = $currentNodeKey;
        }

        if ($metadata !== null) {
            $fields['metadata'] = $this->encodeValue($metadata);
        }

        if (in_array($status, ['completed', 'cancelled', 'failed'], true)) {
            $fields['exited_at'] = now();
        }

        $fields['id'] = $enrollmentId;

        $assignments = [];
        foreach ($fields as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE journey_enrollments SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($fields));
    }

    private function insertJourney(array $payload): int
    {
        foreach (['definition', 'settings'] as $jsonField) {
            if (isset($payload[$jsonField]) && is_array($payload[$jsonField])) {
                $payload[$jsonField] = json_encode($payload[$jsonField], JSON_THROW_ON_ERROR);
            }
        }

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO marketing_journeys (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    private function encodeValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
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
