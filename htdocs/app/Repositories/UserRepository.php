<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class UserRepository
{
    private PDO $pdo;
    private bool $sessionColumnsChecked = false;
    private bool $sessionColumnsAvailable = false;
    private bool $chatPermissionColumnsChecked = false;
    private bool $chatPermissionColumnsAvailable = false;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE certificate_fingerprint = :fingerprint LIMIT 1');
        $stmt->execute([':fingerprint' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findActiveByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE certificate_fingerprint = :fingerprint AND status = :status LIMIT 1');
        $stmt->execute([
            ':fingerprint' => $fingerprint,
            ':status' => 'active',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByCpf(string $cpf): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE cpf = :cpf LIMIT 1');
        $stmt->execute([':cpf' => $cpf]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => mb_strtolower($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $timestamp = now();
        $data['created_at'] = $data['created_at'] ?? $timestamp;
        $data['updated_at'] = $data['updated_at'] ?? $timestamp;

        if (isset($data['permissions'])) {
            if (is_array($data['permissions'])) {
                $data['permissions'] = json_encode(array_values($data['permissions']));
            } elseif (!is_string($data['permissions'])) {
                $data['permissions'] = json_encode([]);
            }
        } else {
            $data['permissions'] = json_encode([]);
        }

        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO users ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = now();
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $data['permissions'] = json_encode(array_values($data['permissions']));
        }
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($data)));
        $data['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE users SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($data));
    }

    public function touchLastSeen(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_seen_at = :last_seen, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':last_seen' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $current = $this->find($id);
        $previousHash = null;

        if ($current !== null) {
            $currentHash = (string)($current['password_hash'] ?? '');
            if ($currentHash !== '') {
                $previousHash = $currentHash;
            }
        }

        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash, previous_password_hash = :previous, password_updated_at = :updated_at, failed_login_attempts = 0, locked_until = NULL, updated_at = :updated WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':hash' => $hash,
            ':previous' => $previousHash,
            ':updated_at' => $now,
            ':updated' => $now,
            ':id' => $id,
        ]);
    }

    public function updateSessionState(int $id, ?string $token, ?int $forcedAt = null): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET session_token = :token, session_forced_at = :forced, session_started_at = :started, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':token' => $token,
            ':forced' => $forcedAt,
            ':started' => $token !== null ? now() : null,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function forceLogout(int $id): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $this->updateSessionState($id, null, now());
        $this->clearLoginAttempts($id);
    }

    public function updateTotp(int $id, ?string $secret, bool $enabled, ?int $confirmedAt = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET totp_secret = :secret, totp_enabled = :enabled, totp_confirmed_at = :confirmed_at, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':secret' => $secret,
            ':enabled' => $enabled ? 1 : 0,
            ':confirmed_at' => $confirmedAt,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function markLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :login_at, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':login_at' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function recordLoginMetadata(int $id, ?string $ip, ?string $location, ?string $userAgent): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET session_ip = :session_ip, session_location = :session_location, session_user_agent = :session_user_agent, last_login_ip = :last_login_ip, last_login_location = :last_login_location, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':session_ip' => $ip,
            ':session_location' => $location,
            ':session_user_agent' => $userAgent,
            ':last_login_ip' => $ip,
            ':last_login_location' => $location,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function updateSessionMetadata(int $id, ?string $ip, ?string $location, ?string $userAgent): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET session_ip = :session_ip, session_location = :session_location, session_user_agent = :session_user_agent, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':session_ip' => $ip,
            ':session_location' => $location,
            ':session_user_agent' => $userAgent,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function updateAccessRestrictions(int $id, ?int $startMinutes, ?int $endMinutes, bool $requireKnownDevice): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET access_allowed_from = :start, access_allowed_until = :end, require_known_device = :require, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':start' => $startMinutes,
            ':end' => $endMinutes,
            ':require' => $requireKnownDevice ? 1 : 0,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function updateClientAccessScope(int $id, string $scope): void
    {
        $normalized = $scope === 'custom' ? 'custom' : 'all';
        $stmt = $this->pdo->prepare('UPDATE users SET client_access_scope = :scope, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':scope' => $normalized,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function updateAllowOnlineClients(int $id, bool $allow): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET allow_online_clients = :allow_online, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':allow_online' => $allow ? 1 : 0,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function updateChatPermissions(int $id, bool $allowInternal, bool $allowExternal): void
    {
        if (!$this->ensureChatPermissionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET allow_internal_chat = :allow_internal, allow_external_chat = :allow_external, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':allow_internal' => $allowInternal ? 1 : 0,
            ':allow_external' => $allowExternal ? 1 : 0,
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function clearSessionMetadata(int $id): void
    {
        if (!$this->ensureSessionColumns()) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET session_ip = NULL, session_location = NULL, session_user_agent = NULL, session_started_at = NULL, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':updated' => now(),
            ':id' => $id,
        ]);
    }

    public function activeAdminsCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND status = :status');
        $stmt->execute([
            ':role' => 'admin',
            ':status' => 'active',
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function listPendingRegistrations(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE status = :status ORDER BY created_at ASC');
        $stmt->execute([':status' => 'pending']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function approveRegistration(int $id, string $approvedBy, array $permissions): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, approved_at = :approved_at, approved_by = :approved_by, permissions = :permissions, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'active',
            ':approved_at' => $now,
            ':approved_by' => $approvedBy,
            ':permissions' => json_encode(array_values($permissions)),
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function denyRegistration(int $id, string $deniedBy): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, approved_at = NULL, approved_by = :denied_by, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'denied',
            ':denied_by' => $deniedBy,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function updatePermissions(int $id, array $permissions): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET permissions = :permissions, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':permissions' => json_encode(array_values($permissions)),
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    public function updateLoginAttempts(int $id, int $attempts, ?int $lockedUntil): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked_until, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':attempts' => $attempts,
            ':locked_until' => $lockedUntil,
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    public function clearLoginAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    public function resetLockout(int $id): void
    {
        $this->clearLoginAttempts($id);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'disabled',
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        $this->forceLogout($id);
    }

    public function activate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $now = now();
        $stmt->execute([
            ':status' => 'active',
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->forceLogout($id);

        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = [':email' => mb_strtolower($email)];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :ignore';
            $params[':ignore'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    public function listActiveNonAdminUsers(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE status = :status AND role != :role ORDER BY name ASC');
        $stmt->execute([
            ':status' => 'active',
            ':role' => 'admin',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActiveAdmins(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE status = :status AND role = :role ORDER BY name ASC');
        $stmt->execute([
            ':status' => 'active',
            ':role' => 'admin',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActiveForChat(?int $excludeUserId = null): array
    {
        $hasChatColumns = $this->ensureChatPermissionColumns();

        $sql = 'SELECT id, name, email, role, chat_identifier, chat_display_name';
        if ($hasChatColumns) {
            $sql .= ', allow_external_chat, allow_internal_chat';
        }
        $sql .= ' FROM users WHERE status = :status';

        if ($hasChatColumns) {
            $sql .= ' AND allow_internal_chat = 1';
        }

        $params = [':status' => 'active'];

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= ' AND id != :exclude';
            $params[':exclude'] = $excludeUserId;
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];

        foreach ($rows as $row) {
            $allowExternal = $hasChatColumns
                ? (((int)($row['allow_external_chat'] ?? 0)) === 1)
                : true;

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'email' => isset($row['email']) ? (string)$row['email'] : null,
                'role' => isset($row['role']) ? (string)$row['role'] : 'user',
                'allow_external_chat' => $allowExternal,
                'chat_identifier' => isset($row['chat_identifier']) && trim((string)$row['chat_identifier']) !== ''
                    ? trim((string)$row['chat_identifier'])
                    : null,
                'chat_display_name' => isset($row['chat_display_name']) && trim((string)$row['chat_display_name']) !== ''
                    ? trim((string)$row['chat_display_name'])
                    : null,
            ];
        }

        return $result;
    }

    public function listAvps(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, role, avp_identity_label, avp_identity_cpf FROM users WHERE status = :status AND is_avp = 1 ORDER BY name ASC');
        $stmt->execute([':status' => 'active']);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? 'user'),
                'avp_identity_label' => $row['avp_identity_label'] !== null ? (string)$row['avp_identity_label'] : null,
                'avp_identity_cpf' => $row['avp_identity_cpf'] !== null ? (string)$row['avp_identity_cpf'] : null,
            ];
        }

        return $result;
    }

    public function updateAvpProfile(int $id, bool $isAvp, ?string $label, ?string $cpf): void
    {
        $payload = [
            'is_avp' => $isAvp ? 1 : 0,
            'avp_identity_label' => $label !== null && $label !== '' ? $label : null,
            'avp_identity_cpf' => $cpf !== null && $cpf !== '' ? $cpf : null,
        ];

        $this->update($id, $payload);
    }


    public function listDisabledNonAdminUsers(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE status = :status AND role != :role ORDER BY name ASC');
        $stmt->execute([
            ':status' => 'disabled',
            ':role' => 'admin',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActiveSessions(int $maxIdleSeconds = 600): array
    {
        if (!$this->ensureSessionColumns()) {
            return [];
        }

        $hasChatColumns = $this->ensureChatPermissionColumns();

        $columns = [
            'id',
            'name',
            'email',
            'role',
            'status',
            'session_ip',
            'session_location',
            'session_user_agent',
            'session_started_at',
            'last_seen_at',
            'last_login_at',
            'last_login_ip',
            'last_login_location',
        ];

        if ($hasChatColumns) {
            array_splice($columns, 5, 0, ['allow_internal_chat', 'allow_external_chat']);
        }

        $window = max(60, $maxIdleSeconds);
        $cutoff = now() - $window;
        if ($cutoff < 0) {
            $cutoff = 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', $columns)
            . ' FROM users'
            . ' WHERE status = :status'
            . ' AND session_token IS NOT NULL'
            . ' AND ((last_seen_at IS NOT NULL AND last_seen_at >= :cutoff)'
            . '      OR (last_seen_at IS NULL AND session_started_at IS NOT NULL AND session_started_at >= :cutoff))'
            . ' ORDER BY role DESC, name ASC'
        );

        $stmt->execute([
            ':status' => 'active',
            ':cutoff' => $cutoff,
        ]);

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

    private function ensureSessionColumns(): bool
    {
        if ($this->sessionColumnsChecked) {
            return $this->sessionColumnsAvailable;
        }

        $this->sessionColumnsChecked = true;

        try {
            $columnsRaw = $this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $exception) {
            $this->sessionColumnsAvailable = false;
            return false;
        }

        $columns = array_column($columnsRaw, 'name');
        $required = [
            'session_token' => 'TEXT NULL',
            'session_forced_at' => 'INTEGER NULL',
            'session_ip' => 'TEXT NULL',
            'session_location' => 'TEXT NULL',
            'session_user_agent' => 'TEXT NULL',
            'session_started_at' => 'INTEGER NULL',
            'last_login_ip' => 'TEXT NULL',
            'last_login_location' => 'TEXT NULL',
            'access_allowed_from' => 'INTEGER NULL',
            'access_allowed_until' => 'INTEGER NULL',
            'require_known_device' => 'INTEGER NOT NULL DEFAULT 0',
        ];

        foreach ($required as $column => $definition) {
            if (in_array($column, $columns, true)) {
                continue;
            }

            try {
                $this->pdo->exec(sprintf('ALTER TABLE users ADD COLUMN %s %s', $column, $definition));
            } catch (\Throwable) {
                // Ignore failure; column will be treated as unavailable below.
            }
        }

        try {
            $columnsRaw = $this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = array_column($columnsRaw, 'name');
        } catch (\Throwable) {
            $columns = [];
        }

        $diff = array_diff(array_keys($required), $columns);
        $this->sessionColumnsAvailable = $diff === [];

        return $this->sessionColumnsAvailable;
    }

    private function ensureChatPermissionColumns(): bool
    {
        if ($this->chatPermissionColumnsChecked) {
            return $this->chatPermissionColumnsAvailable;
        }

        $this->chatPermissionColumnsChecked = true;

        try {
            $columnsRaw = $this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $exception) {
            $this->chatPermissionColumnsAvailable = false;
            return false;
        }

        $columns = array_column($columnsRaw, 'name');
        $required = [
            'allow_internal_chat' => 'INTEGER NOT NULL DEFAULT 1',
            'allow_external_chat' => 'INTEGER NOT NULL DEFAULT 1',
        ];

        foreach ($required as $column => $definition) {
            if (in_array($column, $columns, true)) {
                continue;
            }

            try {
                $this->pdo->exec(sprintf('ALTER TABLE users ADD COLUMN %s %s', $column, $definition));
            } catch (\Throwable) {
                // ignore; we'll flag availability below
            }
        }

        try {
            $columnsRaw = $this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = array_column($columnsRaw, 'name');
        } catch (\Throwable) {
            $columns = [];
        }

        $diff = array_diff(array_keys($required), $columns);
        $this->chatPermissionColumnsAvailable = $diff === [];

        return $this->chatPermissionColumnsAvailable;
    }
}
