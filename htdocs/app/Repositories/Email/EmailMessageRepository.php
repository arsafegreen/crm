<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailMessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function findByExternalUid(int $accountId, string $externalUid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_messages WHERE account_id = :account_id AND external_uid = :uid LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':uid' => $externalUid,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByInternetMessageId(int $accountId, string $messageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_messages WHERE account_id = :account_id AND internet_message_id = :message_id LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':message_id' => $messageId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function insert(array $data): int
    {
        $timestamp = time();
        $data['created_at'] = $data['created_at'] ?? $timestamp;
        $data['updated_at'] = $data['updated_at'] ?? $timestamp;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_messages ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $attributes['updated_at'] = time();
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($attributes)));
        $attributes['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE email_messages SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefix($attributes));
    }

    public function upsertByExternalUid(int $accountId, string $externalUid, array $payload): int
    {
        $existing = $this->findByExternalUid($accountId, $externalUid);
        if ($existing === null) {
            return $this->insert($payload);
        }

        $this->update((int)$existing['id'], $payload);
        return (int)$existing['id'];
    }
    public function markThreadMessagesRead(int $threadId, ?int $upToMessageId = null): int
    {
        $timestamp = time();
        $conditions = [
            'thread_id = :thread_id',
            'direction = "inbound"',
            '(read_at IS NULL OR read_at = 0)'
        ];
        $params = [
            ':thread_id' => $threadId,
            ':timestamp' => $timestamp,
        ];

        if ($upToMessageId !== null) {
            $conditions[] = 'id <= :message_id';
            $params[':message_id'] = $upToMessageId;
        }

        $sql = sprintf(
            'UPDATE email_messages SET read_at = :timestamp, updated_at = :timestamp WHERE %s',
            implode(' AND ', $conditions)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_messages WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listByThread(int $threadId, array $options = []): array
    {
        $sql = 'SELECT * FROM email_messages WHERE thread_id = :thread_id';
        $params = [':thread_id' => $threadId];

        if (!empty($options['before_id'])) {
            $sql .= ' AND id < :before_id';
            $params[':before_id'] = (int)$options['before_id'];
        }

        if (!empty($options['after_id'])) {
            $sql .= ' AND id > :after_id';
            $params[':after_id'] = (int)$options['after_id'];
        }

        $limit = max(1, min((int)($options['limit'] ?? 50), 200));
        $sql .= ' ORDER BY sent_at DESC, id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function listAllByThread(int $threadId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_messages WHERE thread_id = :thread_id ORDER BY id');
        $stmt->execute([':thread_id' => $threadId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function listDrafts(int $accountId, int $limit = 10): array
    {
        $sql = 'SELECT * FROM email_messages
                WHERE account_id = :account_id
                  AND direction = "outbound"
                                    AND status = "draft"
                ORDER BY updated_at DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function listScheduled(int $accountId, int $limit = 20): array
    {
        $sql = 'SELECT * FROM email_messages
                WHERE account_id = :account_id
                  AND direction = "outbound"
                  AND status = "scheduled"
                ORDER BY COALESCE(scheduled_for, updated_at) ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function listScheduledAll(?int $accountId = null, int $limit = 50): array
    {
        $conditions = [
            'direction = "outbound"',
            'status = "scheduled"'
        ];
        $params = [':limit' => max(1, $limit)];

        if ($accountId !== null) {
            $conditions[] = 'account_id = :account_id';
            $params[':account_id'] = $accountId;
        }

        $sql = sprintf(
            'SELECT * FROM email_messages WHERE %s ORDER BY COALESCE(scheduled_for, updated_at) ASC LIMIT :limit',
            implode(' AND ', $conditions)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function listScheduledDue(int $limit = 25, ?int $accountId = null, ?int $now = null): array
    {
        $timestamp = $now ?? time();
        $conditions = [
            'direction = "outbound"',
            'status = "scheduled"',
            'scheduled_for IS NOT NULL',
            'scheduled_for <= :now'
        ];
        $params = [
            ':now' => $timestamp,
            ':limit' => max(1, $limit),
        ];

        if ($accountId !== null) {
            $conditions[] = 'account_id = :account_id';
            $params[':account_id'] = $accountId;
        }

        $sql = sprintf(
            'SELECT * FROM email_messages WHERE %s ORDER BY scheduled_for ASC LIMIT :limit',
            implode(' AND ', $conditions)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function deleteByIds(array $messageIds): int
    {
        if ($messageIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM email_messages WHERE id IN (%s)', $placeholders));
        $stmt->execute(array_values(array_map(static fn($value): int => (int)$value, $messageIds)));

        return (int)$stmt->rowCount();
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
