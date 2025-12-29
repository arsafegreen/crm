<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class WhatsappUserPermissionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM whatsapp_user_permissions');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findByUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_user_permissions WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function upsert(array $payload): void
    {
        $now = now();
        $data = $this->normalizePayload($payload, $now);

        $sql = 'INSERT INTO whatsapp_user_permissions (user_id, level, inbox_access, view_scope, view_scope_payload, panel_scope, can_forward, can_start_thread, can_view_completed, can_grant_permissions, created_at, updated_at)
                VALUES (:user_id, :level, :inbox_access, :view_scope, :view_scope_payload, :panel_scope, :can_forward, :can_start_thread, :can_view_completed, :can_grant_permissions, :created_at, :updated_at)
                ON CONFLICT(user_id) DO UPDATE SET
                    level = excluded.level,
                    inbox_access = excluded.inbox_access,
                    view_scope = excluded.view_scope,
                    view_scope_payload = excluded.view_scope_payload,
                    panel_scope = excluded.panel_scope,
                    can_forward = excluded.can_forward,
                    can_start_thread = excluded.can_start_thread,
                    can_view_completed = excluded.can_view_completed,
                    can_grant_permissions = excluded.can_grant_permissions,
                    updated_at = excluded.updated_at';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function deleteByUserId(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM whatsapp_user_permissions WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
    }

    private function normalizePayload(array $payload, int $timestamp): array
    {
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('user_id is required for permissions.');
        }

        $level = (int)($payload['level'] ?? 3);
        if ($level < 1 || $level > 4) {
            $level = 3;
        }

        $inboxAccess = $this->sanitizeAccessToken($payload['inbox_access'] ?? 'all', ['all', 'own_only']);
        $viewScope = $this->sanitizeAccessToken($payload['view_scope'] ?? 'own', ['all', 'own', 'selected', 'own_or_assigned']);

        $payloadUsers = $payload['view_scope_payload'] ?? null;
        if (is_string($payloadUsers) && $payloadUsers !== '') {
            $decoded = json_decode($payloadUsers, true);
            $payloadUsers = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payloadUsers)) {
            $payloadUsers = [];
        }
        $payloadUsers = array_values(array_unique(array_filter(array_map('intval', $payloadUsers), static fn(int $value): bool => $value > 0)));

        $panelScope = $payload['panel_scope'] ?? null;
        if (is_string($panelScope) && $panelScope !== '') {
            $decodedScope = json_decode($panelScope, true);
            $panelScope = is_array($decodedScope) ? $decodedScope : null;
        }
        if (!is_array($panelScope)) {
            $panelScope = [];
        }

        return [
            ':user_id' => $userId,
            ':level' => $level,
            ':inbox_access' => $inboxAccess,
            ':view_scope' => $viewScope,
            ':view_scope_payload' => $payloadUsers === [] ? null : json_encode($payloadUsers, JSON_THROW_ON_ERROR),
            ':panel_scope' => $panelScope === [] ? null : json_encode($panelScope, JSON_THROW_ON_ERROR),
            ':can_forward' => (int)(!empty($payload['can_forward'])),
            ':can_start_thread' => (int)(!empty($payload['can_start_thread'])),
            ':can_view_completed' => (int)(!empty($payload['can_view_completed'])),
            ':can_grant_permissions' => (int)(!empty($payload['can_grant_permissions'])),
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ];
    }

    private function sanitizeAccessToken(mixed $value, array $allowed): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($candidate, $allowed, true) ? $candidate : $allowed[0];
    }
}
