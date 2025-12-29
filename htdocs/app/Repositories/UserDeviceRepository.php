<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class UserDeviceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function findByFingerprint(int $userId, string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_devices WHERE user_id = :user_id AND fingerprint = :fingerprint LIMIT 1');
        $stmt->execute([
            ':user_id' => $userId,
            ':fingerprint' => $fingerprint,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function recordApprovedDevice(int $userId, string $fingerprint, ?string $userAgent, ?string $ip, ?string $location): array
    {
        $existing = $this->findByFingerprint($userId, $fingerprint);
        $now = now();

        if ($existing === null) {
            $stmt = $this->pdo->prepare('INSERT INTO user_devices (user_id, fingerprint, user_agent, label, created_at, updated_at, approved_at, approved_by, last_seen_at, last_ip, last_location) VALUES (:user_id, :fingerprint, :user_agent, NULL, :created_at, :updated_at, :approved_at, :approved_by, :last_seen_at, :last_ip, :last_location)');
            $stmt->execute([
                ':user_id' => $userId,
                ':fingerprint' => $fingerprint,
                ':user_agent' => $this->sanitizeNullable($userAgent),
                ':created_at' => $now,
                ':updated_at' => $now,
                ':approved_at' => $now,
                ':approved_by' => 'system-auto',
                ':last_seen_at' => $now,
                ':last_ip' => $this->sanitizeNullable($ip),
                ':last_location' => $this->sanitizeNullable($location),
            ]);
        } else {
            $updates = [
                ':id' => (int)$existing['id'],
                ':updated_at' => $now,
                ':last_seen_at' => $now,
                ':user_agent' => $this->sanitizeNullable($userAgent),
                ':last_ip' => $this->sanitizeNullable($ip),
                ':last_location' => $this->sanitizeNullable($location),
            ];

            $sql = 'UPDATE user_devices SET updated_at = :updated_at, last_seen_at = :last_seen_at, last_ip = :last_ip, last_location = :last_location';
            if ($updates[':user_agent'] !== null && $updates[':user_agent'] !== '') {
                $sql .= ', user_agent = :user_agent';
            } else {
                unset($updates[':user_agent']);
            }

            if (empty($existing['approved_at'])) {
                $sql .= ', approved_at = :approved_at, approved_by = :approved_by';
                $updates[':approved_at'] = $now;
                $updates[':approved_by'] = 'system-auto';
            }

            $sql .= ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($updates);
        }

        return $this->findByFingerprint($userId, $fingerprint) ?? [];
    }

    public function recordPendingDevice(int $userId, string $fingerprint, ?string $userAgent, ?string $ip, ?string $location): array
    {
        $existing = $this->findByFingerprint($userId, $fingerprint);
        $now = now();

        if ($existing === null) {
            $stmt = $this->pdo->prepare('INSERT INTO user_devices (user_id, fingerprint, user_agent, label, created_at, updated_at, approved_at, approved_by, last_seen_at, last_ip, last_location) VALUES (:user_id, :fingerprint, :user_agent, NULL, :created_at, :updated_at, NULL, NULL, :last_seen_at, :last_ip, :last_location)');
            $stmt->execute([
                ':user_id' => $userId,
                ':fingerprint' => $fingerprint,
                ':user_agent' => $this->sanitizeNullable($userAgent),
                ':created_at' => $now,
                ':updated_at' => $now,
                ':last_seen_at' => $now,
                ':last_ip' => $this->sanitizeNullable($ip),
                ':last_location' => $this->sanitizeNullable($location),
            ]);
        } else {
            $updates = [
                ':id' => (int)$existing['id'],
                ':updated_at' => $now,
                ':last_seen_at' => $now,
                ':user_agent' => $this->sanitizeNullable($userAgent),
                ':last_ip' => $this->sanitizeNullable($ip),
                ':last_location' => $this->sanitizeNullable($location),
            ];

            $sql = 'UPDATE user_devices SET updated_at = :updated_at, last_seen_at = :last_seen_at, last_ip = :last_ip, last_location = :last_location';
            if ($updates[':user_agent'] !== null && $updates[':user_agent'] !== '') {
                $sql .= ', user_agent = :user_agent';
            } else {
                unset($updates[':user_agent']);
            }

            $sql .= ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($updates);
        }

        return $this->findByFingerprint($userId, $fingerprint) ?? [];
    }

    public function markSeen(int $deviceId, ?string $userAgent, ?string $ip, ?string $location): void
    {
        $now = now();
        $updates = [
            ':id' => $deviceId,
            ':updated_at' => $now,
            ':last_seen_at' => $now,
            ':user_agent' => $this->sanitizeNullable($userAgent),
            ':last_ip' => $this->sanitizeNullable($ip),
            ':last_location' => $this->sanitizeNullable($location),
        ];

        $sql = 'UPDATE user_devices SET updated_at = :updated_at, last_seen_at = :last_seen_at, last_ip = :last_ip, last_location = :last_location';
        if ($updates[':user_agent'] !== null && $updates[':user_agent'] !== '') {
            $sql .= ', user_agent = :user_agent';
        } else {
            unset($updates[':user_agent']);
        }

        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($updates);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_devices WHERE user_id = :user_id ORDER BY (approved_at IS NULL) ASC, (last_seen_at IS NULL) ASC, last_seen_at DESC, updated_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function isApproved(array $device): bool
    {
        return !empty($device['approved_at']);
    }

    private function sanitizeNullable(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
