<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class MailQueueRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function enqueue(array $job): int
    {
        $timestamp = now();

        foreach (['payload', 'headers'] as $jsonField) {
            if (isset($job[$jsonField])) {
                $job[$jsonField] = $this->encodeValue($job[$jsonField]);
            }
        }

        $job['status'] = $job['status'] ?? 'pending';
        $job['priority'] = $job['priority'] ?? 0;
        $job['scheduled_at'] = $job['scheduled_at'] ?? null;
        $job['available_at'] = $job['available_at'] ?? null;
        $job['attempts'] = $job['attempts'] ?? 0;
        $job['max_attempts'] = $job['max_attempts'] ?? 3;
        $job['created_at'] = $job['created_at'] ?? $timestamp;
        $job['updated_at'] = $job['updated_at'] ?? $timestamp;

        $columns = array_keys($job);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO mail_queue_jobs (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($job));

        return (int)$this->pdo->lastInsertId();
    }

    public function claimPending(string $workerId, int $limit = 10, int $staleAfterSeconds = 300): array
    {
        $now = now();
        $staleThreshold = $now - max(1, $staleAfterSeconds);

        $candidateSql = 'SELECT id FROM mail_queue_jobs
            WHERE (status = "pending" OR (status = "processing" AND (locked_at IS NULL OR locked_at <= :stale_threshold)))
              AND (scheduled_at IS NULL OR scheduled_at <= :now)
              AND (available_at IS NULL OR available_at <= :now)
            ORDER BY priority DESC, COALESCE(scheduled_at, created_at) ASC, id ASC
            LIMIT :limit';

        $candidateStmt = $this->pdo->prepare($candidateSql);
        $candidateStmt->bindValue(':stale_threshold', $staleThreshold, PDO::PARAM_INT);
        $candidateStmt->bindValue(':now', $now, PDO::PARAM_INT);
        $candidateStmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $candidateStmt->execute();

        $rows = $candidateStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            return [];
        }

        $claimed = [];
        $updateSql = 'UPDATE mail_queue_jobs
            SET status = "processing",
                locked_by = :worker_id,
                locked_at = :now,
                updated_at = :now
            WHERE id = :id
              AND (status = "pending" OR (status = "processing" AND (locked_at IS NULL OR locked_at <= :stale_threshold)))';
        $updateStmt = $this->pdo->prepare($updateSql);

        foreach ($rows as $jobId) {
            $updateStmt->execute([
                ':worker_id' => $workerId,
                ':now' => $now,
                ':id' => $jobId,
                ':stale_threshold' => $staleThreshold,
            ]);

            if ($updateStmt->rowCount() === 0) {
                continue;
            }

            $job = $this->find((int)$jobId);
            if ($job !== null) {
                $claimed[] = $job;
            }
        }

        return $claimed;
    }

    public function markSent(int $jobId, ?int $sentAt = null): void
    {
        $timestamp = $sentAt ?? now();
        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue_jobs
             SET status = "sent",
                 sent_at = :sent_at,
                 locked_by = NULL,
                 locked_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':sent_at' => $timestamp,
            ':updated_at' => now(),
            ':id' => $jobId,
        ]);
    }

    public function markFailed(int $jobId, string $errorMessage, ?int $retryAt = null): void
    {
        $job = $this->find($jobId);
        if ($job === null) {
            return;
        }

        $attempts = ((int)$job['attempts']) + 1;
        $maxAttempts = (int)($job['max_attempts'] ?? 3);
        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
        $availableAt = $status === 'pending' ? ($retryAt ?? (now() + 60)) : null;

        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue_jobs
             SET status = :status,
                 attempts = :attempts,
                 available_at = :available_at,
                 last_error = :last_error,
                 locked_by = NULL,
                 locked_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':available_at' => $availableAt,
            ':last_error' => $errorMessage,
            ':updated_at' => now(),
            ':id' => $jobId,
        ]);
    }

    public function release(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue_jobs
             SET locked_by = NULL,
                 locked_at = NULL,
                 status = CASE WHEN status = "processing" THEN "pending" ELSE status END,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':updated_at' => now(),
            ':id' => $jobId,
        ]);
    }

    public function logEvent(int $jobId, string $eventType, array $payload = [], array $context = []): void
    {
        $logRepo = new MailDeliveryLogRepository($this->pdo);
        $contactId = null;
        if (isset($payload['contact_id'])) {
            $contactId = (int)$payload['contact_id'];
        } else {
            $contactId = $this->jobContactId($jobId);
        }

        $logRepo->record($jobId, $eventType, $payload, $contactId, $context);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mail_queue_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
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

    private function jobContactId(int $jobId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT contact_id FROM mail_queue_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $contactId = (int)$value;
        return $contactId > 0 ? $contactId : null;
    }
}
