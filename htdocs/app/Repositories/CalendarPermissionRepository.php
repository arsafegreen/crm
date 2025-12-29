<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CalendarPermissionRepository
{
    private const VALID_SCOPES = ['view', 'create', 'edit', 'cancel'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    /**
     * @param string[] $scopes
     */
    public function upsert(int $ownerUserId, int $granteeUserId, int $grantedByUserId, array $scopes, ?int $expiresAt = null): void
    {
        $normalizedScopes = $this->prepareScopes($scopes);
        $timestamp = now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_permissions (owner_user_id, grantee_user_id, granted_by_user_id, scopes, expires_at, created_at, updated_at)
             VALUES (:owner_user_id, :grantee_user_id, :granted_by_user_id, :scopes, :expires_at, :created_at, :updated_at)
             ON CONFLICT(owner_user_id, grantee_user_id)
             DO UPDATE SET scopes = excluded.scopes, expires_at = excluded.expires_at, updated_at = excluded.updated_at, granted_by_user_id = excluded.granted_by_user_id'
        );

        $stmt->execute([
            ':owner_user_id' => $ownerUserId,
            ':grantee_user_id' => $granteeUserId,
            ':granted_by_user_id' => $grantedByUserId,
            ':scopes' => json_encode($normalizedScopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':expires_at' => $expiresAt,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);
    }

    public function revoke(int $ownerUserId, int $granteeUserId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_permissions WHERE owner_user_id = :owner AND grantee_user_id = :grantee');
        $stmt->execute([
            ':owner' => $ownerUserId,
            ':grantee' => $granteeUserId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForOwner(int $ownerUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_permissions WHERE owner_user_id = :owner ORDER BY grantee_user_id');
        $stmt->execute([':owner' => $ownerUserId]);

        return array_map([$this, 'hydrateRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForGrantee(int $granteeUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_permissions WHERE grantee_user_id = :grantee ORDER BY owner_user_id');
        $stmt->execute([':grantee' => $granteeUserId]);

        return array_map([$this, 'hydrateRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function can(int $ownerUserId, int $granteeUserId, string $scope): bool
    {
        $row = $this->find($ownerUserId, $granteeUserId);
        if ($row === null) {
            return false;
        }

        if ($row['expires_at'] !== null && $row['expires_at'] < now()) {
            return false;
        }

        return in_array($scope, $row['scopes'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $ownerUserId, int $granteeUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_permissions WHERE owner_user_id = :owner AND grantee_user_id = :grantee LIMIT 1');
        $stmt->execute([
            ':owner' => $ownerUserId,
            ':grantee' => $granteeUserId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $decodedScopes = json_decode((string)($row['scopes'] ?? '[]'), true);
        if (!is_array($decodedScopes)) {
            $decodedScopes = [];
        }

        $row['scopes'] = $this->prepareScopes($decodedScopes);
        $row['expires_at'] = isset($row['expires_at']) && $row['expires_at'] !== null ? (int)$row['expires_at'] : null;

        return $row;
    }

    /**
     * @param array<int, string> $scopes
     * @return array<int, string>
     */
    private function prepareScopes(array $scopes): array
    {
        $unique = [];
        foreach ($scopes as $scope) {
            $scopeKey = strtolower(trim((string)$scope));
            if ($scopeKey === '' || !in_array($scopeKey, self::VALID_SCOPES, true)) {
                continue;
            }
            $unique[$scopeKey] = true;
        }

        if ($unique === []) {
            return ['view'];
        }

        return array_keys($unique);
    }
}
