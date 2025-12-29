<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailJobRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function enqueue(string $type, array $payload = [], array $options = []): int
    {
        $timestamp = time();
        $data = [
            'job_type' => $type,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'priority' => $options['priority'] ?? 0,
            'available_at' => $options['available_at'] ?? null,
            'max_attempts' => $options['max_attempts'] ?? 3,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_jobs ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function reserveNext(string $type, string $workerId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM email_jobs
                 WHERE job_type = :job_type
                   AND status = "pending"
                   AND (available_at IS NULL OR available_at <= :now)
                 ORDER BY priority DESC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                ':job_type' => $type,
                ':now' => time(),
            ]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job === false) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE email_jobs
                 SET status = "reserved",
                     reserved_at = :reserved_at,
                     reserved_by = :worker,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':reserved_at' => time(),
                ':worker' => $workerId,
                ':updated_at' => time(),
                ':id' => (int)$job['id'],
            ]);

            $this->pdo->commit();
            return $job;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markCompleted(int $jobId): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_jobs SET status = "completed", updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function markFailed(int $jobId, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_jobs
             SET status = "failed",
                 last_error = :error,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':error' => $errorMessage,
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function incrementAttempts(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_jobs SET attempts = attempts + 1, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function release(int $jobId, array $options = []): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_jobs
             SET status = "pending",
                 reserved_at = NULL,
                 reserved_by = NULL,
                 available_at = :available_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':available_at' => $options['available_at'] ?? time(),
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function countPendingByType(string $type): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM email_jobs WHERE job_type = :job_type AND status = "pending"'
        );
        $stmt->execute([':job_type' => $type]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int)$value;
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
