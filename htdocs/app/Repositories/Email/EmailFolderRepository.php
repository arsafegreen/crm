<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailFolderRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function findByRemoteName(int $accountId, string $remoteName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_folders WHERE account_id = :account_id AND remote_name = :remote LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':remote' => $remoteName,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function upsert(int $accountId, string $remoteName, array $attributes = []): array
    {
        $existing = $this->findByRemoteName($accountId, $remoteName);
        $payload = array_merge([
            'account_id' => $accountId,
            'remote_name' => $remoteName,
            'display_name' => $attributes['display_name'] ?? null,
            'type' => $attributes['type'] ?? 'custom',
            'sync_token' => $attributes['sync_token'] ?? null,
            'last_synced_at' => $attributes['last_synced_at'] ?? null,
            'unread_count' => $attributes['unread_count'] ?? 0,
        ], $attributes);

        if ($existing === null) {
            $timestamp = time();
            $payload['created_at'] = $timestamp;
            $payload['updated_at'] = $timestamp;

            $columns = implode(', ', array_keys($payload));
            $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($payload)));

            $stmt = $this->pdo->prepare("INSERT INTO email_folders ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($this->prefix($payload));

            return $this->findByRemoteName($accountId, $remoteName) ?? $payload;
        }

        $this->update((int)$existing['id'], $payload);
        return $this->findByRemoteName($accountId, $remoteName) ?? $payload;
    }

    public function update(int $id, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $attributes['updated_at'] = time();
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($attributes)));
        $attributes['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE email_folders SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($attributes));
    }

    public function markSynced(int $id, array $state): void
    {
        $payload = [];
        if (array_key_exists('sync_token', $state)) {
            $payload['sync_token'] = $state['sync_token'];
        }
        if (array_key_exists('last_synced_at', $state)) {
            $payload['last_synced_at'] = $state['last_synced_at'];
        }
        if (array_key_exists('unread_count', $state)) {
            $payload['unread_count'] = $state['unread_count'];
        }

        if ($payload === []) {
            $payload['last_synced_at'] = time();
        }

        $this->update($id, $payload);
    }

    public function listByAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_folders WHERE account_id = :account_id ORDER BY remote_name');
        $stmt->execute([':account_id' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_folders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByType(int $accountId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_folders WHERE account_id = :account_id AND type = :type LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':type' => $type,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function adjustUnreadCount(int $id, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE email_folders
             SET unread_count = CASE WHEN unread_count + :delta < 0 THEN 0 ELSE unread_count + :delta END,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':delta' => $delta,
            ':updated_at' => time(),
            ':id' => $id,
        ]);
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
