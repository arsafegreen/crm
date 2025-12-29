<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CertificateAccessRequestRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certificate_access_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certificate_access_requests WHERE certificate_fingerprint = :fingerprint LIMIT 1');
        $stmt->execute([':fingerprint' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function upsertPending(array $data): array
    {
        $existing = $this->findByFingerprint($data['certificate_fingerprint']);
        $timestamp = now();

        if ($existing === null) {
            $payload = $data + [
                'status' => 'pending',
                'reason' => null,
                'decided_at' => null,
                'decided_by' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $fields = array_keys($payload);
            $columns = implode(', ', $fields);
            $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));
            $stmt = $this->pdo->prepare("INSERT INTO certificate_access_requests ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($this->prefix($payload));
            $id = (int)$this->pdo->lastInsertId();
            return $this->find($id) ?? $payload + ['id' => $id];
        }

        $updates = $data + [
            'status' => 'pending',
            'reason' => null,
            'decided_at' => null,
            'decided_by' => null,
            'updated_at' => $timestamp,
        ];

        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($updates)));
        $updates['certificate_fingerprint'] = $data['certificate_fingerprint'];

        $stmt = $this->pdo->prepare("UPDATE certificate_access_requests SET {$assignments} WHERE certificate_fingerprint = :certificate_fingerprint");
        $stmt->execute($this->prefix($updates));

        return $this->find((int)$existing['id']) ?? ($existing + $updates);
    }

    public function markApproved(int $id, string $decidedBy, ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE certificate_access_requests SET status = :status, reason = :reason, decided_at = :decided_at, decided_by = :decided_by, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'approved',
            ':reason' => $reason,
            ':decided_at' => $now,
            ':decided_by' => $decidedBy,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function markDenied(int $id, string $decidedBy, ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE certificate_access_requests SET status = :status, reason = :reason, decided_at = :decided_at, decided_by = :decided_by, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'denied',
            ':reason' => $reason,
            ':decided_at' => $now,
            ':decided_by' => $decidedBy,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function listPending(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certificate_access_requests WHERE status = :status ORDER BY created_at ASC');
        $stmt->execute([':status' => 'pending']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listRecentDecisions(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM certificate_access_requests WHERE status IN ("approved", "denied") ORDER BY decided_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
