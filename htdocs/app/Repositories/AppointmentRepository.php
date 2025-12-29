<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class AppointmentRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $timestamp = now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO appointments (
                client_id,
                client_name,
                client_document,
                owner_user_id,
                created_by_user_id,
                title,
                description,
                category,
                channel,
                location,
                status,
                starts_at,
                ends_at,
                allow_conflicts,
                created_at,
                updated_at
            ) VALUES (
                :client_id,
                :client_name,
                :client_document,
                :owner_user_id,
                :created_by_user_id,
                :title,
                :description,
                :category,
                :channel,
                :location,
                :status,
                :starts_at,
                :ends_at,
                :allow_conflicts,
                :created_at,
                :updated_at
            )'
        );

        $stmt->execute([
            ':client_id' => $payload['client_id'] ?? null,
            ':client_name' => $payload['client_name'] ?? null,
            ':client_document' => $payload['client_document'] ?? null,
            ':owner_user_id' => (int)($payload['owner_user_id'] ?? 0),
            ':created_by_user_id' => (int)($payload['created_by_user_id'] ?? 0),
            ':title' => (string)($payload['title'] ?? ''),
            ':description' => $payload['description'] ?? null,
            ':category' => $payload['category'] ?? null,
            ':channel' => $payload['channel'] ?? null,
            ':location' => $payload['location'] ?? null,
            ':status' => (string)($payload['status'] ?? 'scheduled'),
            ':starts_at' => (int)($payload['starts_at'] ?? 0),
            ':ends_at' => (int)($payload['ends_at'] ?? 0),
            ':allow_conflicts' => !empty($payload['allow_conflicts']) ? 1 : 0,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $appointmentId, array $payload): bool
    {
        $fields = [];
        $params = [':id' => $appointmentId];

        foreach ([
            'client_id',
            'client_name',
            'client_document',
            'owner_user_id',
            'title',
            'description',
            'category',
            'channel',
            'location',
            'status',
            'starts_at',
            'ends_at',
            'allow_conflicts',
        ] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $placeholder = ':' . $field;
            $fields[] = sprintf('%s = %s', $field, $placeholder);

            if ($field === 'allow_conflicts') {
                $params[$placeholder] = !empty($payload[$field]) ? 1 : 0;
            } elseif (in_array($field, ['owner_user_id', 'client_id', 'starts_at', 'ends_at'], true)) {
                $params[$placeholder] = $payload[$field] !== null ? (int)$payload[$field] : null;
            } else {
                $params[$placeholder] = $payload[$field];
            }
        }

        if ($fields === []) {
            return false;
        }

        $fields[] = 'updated_at = :updated_at';
        $params[':updated_at'] = now();

        $stmt = $this->pdo->prepare(
            'UPDATE appointments SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );

        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $appointmentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateAppointment($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForOwner(int $ownerUserId, ?int $startsAt = null, ?int $endsAt = null): array
    {
        return $this->listWithinRange($startsAt, $endsAt, [$ownerUserId]);
    }

    /**
     * @param array<int, int>|null $ownerUserIds
     * @return array<int, array<string, mixed>>
     */
    public function listWithinRange(?int $startsAt = null, ?int $endsAt = null, ?array $ownerUserIds = null): array
    {
        $conditions = [];
        $params = [];

        if ($ownerUserIds !== null && $ownerUserIds !== []) {
            $placeholders = [];
            foreach ($ownerUserIds as $index => $ownerId) {
                $placeholder = ':owner_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (int)$ownerId;
            }
            $conditions[] = 'owner_user_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($startsAt !== null) {
            $conditions[] = 'ends_at >= :range_start';
            $params[':range_start'] = $startsAt;
        }

        if ($endsAt !== null) {
            $conditions[] = 'starts_at <= :range_end';
            $params[':range_end'] = $endsAt;
        }

        $sql = 'SELECT * FROM appointments';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY starts_at';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateAppointment'], $rows);
    }

    /**
     * @return array<int, array{start:int,end:int}>
     */
    public function busyIntervalsForOwner(int $ownerUserId, int $rangeStart, int $rangeEnd, ?int $ignoreAppointmentId = null): array
    {
        $conditions = [
            'owner_user_id = :owner',
            'starts_at <= :range_end',
            'ends_at >= :range_start'
        ];
        $params = [
            ':owner' => $ownerUserId,
            ':range_start' => $rangeStart,
            ':range_end' => $rangeEnd,
        ];

        if ($ignoreAppointmentId !== null) {
            $conditions[] = 'id != :ignore_id';
            $params[':ignore_id'] = $ignoreAppointmentId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, starts_at, ends_at, status, allow_conflicts FROM appointments WHERE ' . implode(' AND ', $conditions)
        );
        $stmt->execute($params);

        $intervals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $startsAt = isset($row['starts_at']) ? (int)$row['starts_at'] : 0;
            $endsAt = isset($row['ends_at']) ? (int)$row['ends_at'] : 0;
            $status = strtolower((string)($row['status'] ?? 'scheduled'));

            if ($startsAt <= 0 || $endsAt <= 0 || $endsAt <= $startsAt) {
                continue;
            }

            if (in_array($status, ['canceled', 'cancelled', 'draft'], true)) {
                continue;
            }

            $intervals[] = ['start' => $startsAt, 'end' => $endsAt];
        }

        return $intervals;
    }

    /**
     * @param int[] $userIds
     */
    public function syncParticipants(int $appointmentId, array $userIds): void
    {
        $unique = [];
        foreach ($userIds as $userId) {
            $id = (int)$userId;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }

        $this->pdo->beginTransaction();

        $stmtDelete = $this->pdo->prepare('DELETE FROM appointment_participants WHERE appointment_id = :appointment_id');
        $stmtDelete->execute([':appointment_id' => $appointmentId]);

        if ($unique !== []) {
            $stmtInsert = $this->pdo->prepare(
                'INSERT INTO appointment_participants (appointment_id, user_id, role, created_at)
                 VALUES (:appointment_id, :user_id, :role, :created_at)'
            );

            $createdAt = now();
            foreach (array_keys($unique) as $userId) {
                $stmtInsert->execute([
                    ':appointment_id' => $appointmentId,
                    ':user_id' => $userId,
                    ':role' => 'participant',
                    ':created_at' => $createdAt,
                ]);
            }
        }

        $this->pdo->commit();
    }

    public function delete(int $appointmentId): void
    {
        $stmtParticipants = $this->pdo->prepare('DELETE FROM appointment_participants WHERE appointment_id = :appointment_id');
        $stmtParticipants->execute([':appointment_id' => $appointmentId]);

        $stmtAppointment = $this->pdo->prepare('DELETE FROM appointments WHERE id = :id');
        $stmtAppointment->execute([':id' => $appointmentId]);
    }

    /**
     * @return array<int, int>
     */
    public function participants(int $appointmentId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM appointment_participants WHERE appointment_id = :appointment_id');
        $stmt->execute([':appointment_id' => $appointmentId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateAppointment(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['client_id'] = isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null;
        $row['owner_user_id'] = (int)($row['owner_user_id'] ?? 0);
        $row['created_by_user_id'] = (int)($row['created_by_user_id'] ?? 0);
        $row['starts_at'] = (int)($row['starts_at'] ?? 0);
        $row['ends_at'] = (int)($row['ends_at'] ?? 0);
        $row['allow_conflicts'] = !empty($row['allow_conflicts']);
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['updated_at'] = (int)($row['updated_at'] ?? 0);

        return $row;
    }
}
