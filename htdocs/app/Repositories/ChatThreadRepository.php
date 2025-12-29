<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ChatThreadRepository
{
    private PDO $pdo;
    private bool $schemaChecked = false;

    public function __construct()
    {
        $this->pdo = Connection::instance();
        $this->ensureSchema();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $timestamp = now();
        $payload['type'] = $payload['type'] ?? 'direct';
        $payload['subject'] = $payload['subject'] ?? null;
        $payload['created_by'] = (int)($payload['created_by'] ?? 0);
        $payload['status'] = $payload['status'] ?? 'open';
        $payload['last_message_id'] = $payload['last_message_id'] ?? null;
        $payload['last_message_at'] = $payload['last_message_at'] ?? null;
        $payload['closed_by'] = $payload['closed_by'] ?? null;
        $payload['closed_at'] = $payload['closed_at'] ?? null;
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_threads (
                type,
                subject,
                created_by,
                status,
                last_message_id,
                last_message_at,
                closed_by,
                closed_at,
                created_at,
                updated_at
            ) VALUES (
                :type,
                :subject,
                :created_by,
                :status,
                :last_message_id,
                :last_message_at,
                :closed_by,
                :closed_at,
                :created_at,
                :updated_at
            )'
        );

        $stmt->execute([
            ':type' => (string)$payload['type'],
            ':subject' => $payload['subject'] !== null ? (string)$payload['subject'] : null,
            ':created_by' => $payload['created_by'],
            ':status' => (string)$payload['status'],
            ':last_message_id' => $payload['last_message_id'],
            ':last_message_at' => $payload['last_message_at'],
            ':closed_by' => $payload['closed_by'],
            ':closed_at' => $payload['closed_at'],
            ':created_at' => $payload['created_at'],
            ':updated_at' => $payload['updated_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $threadId, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = $payload['updated_at'] ?? now();

        $assignments = [];
        $params = [':id' => $threadId];

        foreach ($payload as $key => $value) {
            $assignments[] = sprintf('%s = :%s', $key, $key);
            $params[':' . $key] = $value;
        }

        $sql = 'UPDATE chat_threads SET ' . implode(', ', $assignments) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $threadId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chat_threads WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castThread($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDirectThread(int $userA, int $userB): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM chat_threads t
             INNER JOIN chat_participants p1 ON p1.thread_id = t.id AND p1.user_id = :user_a
             INNER JOIN chat_participants p2 ON p2.thread_id = t.id AND p2.user_id = :user_b
             WHERE t.type = :type
             LIMIT 1'
        );
        $stmt->execute([
            ':user_a' => $userA,
            ':user_b' => $userB,
            ':type' => 'direct',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castThread($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOverviewForUser(int $userId, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                t.*,
                lm.body AS last_message_body,
                lm.author_id AS last_message_author_id,
                lm.created_at AS last_message_created_at,
                lm.is_system AS last_message_is_system,
                (
                    SELECT COUNT(*) FROM chat_messages m
                    WHERE m.thread_id = t.id
                      AND m.deleted_at IS NULL
                      AND m.author_id != :user_id
                      AND (p.last_read_message_id IS NULL OR m.id > p.last_read_message_id)
                ) AS unread_count
            FROM chat_threads t
            INNER JOIN chat_participants p ON p.thread_id = t.id AND p.user_id = :user_id
            LEFT JOIN chat_messages lm ON lm.id = t.last_message_id
            WHERE t.status = :status
            ORDER BY COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC, t.id DESC
            LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'open', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'castOverview'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOverviewForAdmin(int $limit = 60): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                t.*,
                lm.body AS last_message_body,
                lm.author_id AS last_message_author_id,
                lm.created_at AS last_message_created_at,
                lm.is_system AS last_message_is_system,
                0 AS unread_count
            FROM chat_threads t
            LEFT JOIN chat_messages lm ON lm.id = t.last_message_id
            ORDER BY COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC, t.id DESC
            LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'castOverview'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function participantsWithProfiles(int $threadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
             FROM chat_participants p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.thread_id = :thread
             ORDER BY COALESCE(u.name, "Usuário") ASC'
        );
        $stmt->execute([':thread' => $threadId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'thread_id' => (int)($row['thread_id'] ?? 0),
                    'user_id' => (int)($row['user_id'] ?? 0),
                    'role' => (string)($row['role'] ?? 'member'),
                    'last_read_message_id' => isset($row['last_read_message_id']) ? (int)$row['last_read_message_id'] : null,
                    'last_read_at' => isset($row['last_read_at']) ? (int)$row['last_read_at'] : null,
                    'name' => isset($row['user_name']) ? (string)$row['user_name'] : 'Usuário',
                    'email' => isset($row['user_email']) ? (string)$row['user_email'] : null,
                    'role_label' => isset($row['user_role']) ? (string)$row['user_role'] : 'member',
                ];
            },
            $rows
        );
    }

    public function ensureParticipant(int $threadId, int $userId, string $role = 'member'): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_participants (
                thread_id, user_id, role, created_at, updated_at
            ) VALUES (
                :thread_id, :user_id, :role, :created_at, :updated_at
            )
            ON CONFLICT(thread_id, user_id) DO UPDATE SET
                role = excluded.role,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':thread_id' => $threadId,
            ':user_id' => $userId,
            ':role' => $role,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function participantRecord(int $threadId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chat_participants WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            ':thread_id' => $threadId,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['id'] = (int)($row['id'] ?? 0);
        $row['thread_id'] = (int)($row['thread_id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
        $row['last_read_message_id'] = isset($row['last_read_message_id']) ? (int)$row['last_read_message_id'] : null;
        $row['last_read_at'] = isset($row['last_read_at']) ? (int)$row['last_read_at'] : null;

        return $row;
    }

    public function markAsRead(int $threadId, int $userId, int $messageId, int $timestamp): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chat_participants
             SET last_read_message_id = :message_id,
                 last_read_at = :read_at,
                 updated_at = :updated_at
             WHERE thread_id = :thread_id AND user_id = :user_id'
        );
        $stmt->execute([
            ':message_id' => $messageId,
            ':read_at' => $timestamp,
            ':updated_at' => $timestamp,
            ':thread_id' => $threadId,
            ':user_id' => $userId,
        ]);
    }

    public function touchLastMessage(int $threadId, int $messageId, int $timestamp): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chat_threads
             SET last_message_id = :message_id,
                 last_message_at = :message_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':message_id' => $messageId,
            ':message_at' => $timestamp,
            ':updated_at' => $timestamp,
            ':id' => $threadId,
        ]);
    }

    public function refreshLastMessageMetadata(int $threadId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at FROM chat_messages
             WHERE thread_id = :thread_id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([':thread_id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $this->update($threadId, [
                'last_message_id' => null,
                'last_message_at' => null,
            ]);
            return;
        }

        $this->update($threadId, [
            'last_message_id' => (int)$row['id'],
            'last_message_at' => (int)$row['created_at'],
        ]);
    }

    /**
     * @return int[]
     */
    public function participantIds(int $threadId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM chat_participants WHERE thread_id = :thread_id');
        $stmt->execute([':thread_id' => $threadId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castThread(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['created_by'] = (int)($row['created_by'] ?? 0);
        $row['status'] = (string)($row['status'] ?? 'open');
        $row['last_message_id'] = isset($row['last_message_id']) ? (int)$row['last_message_id'] : null;
        $row['last_message_at'] = isset($row['last_message_at']) ? (int)$row['last_message_at'] : null;
        $row['closed_by'] = isset($row['closed_by']) ? (int)$row['closed_by'] : null;
        $row['closed_at'] = isset($row['closed_at']) ? (int)$row['closed_at'] : null;
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['updated_at'] = (int)($row['updated_at'] ?? 0);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castOverview(array $row): array
    {
        $row = $this->castThread($row);
        $row['last_message_body'] = isset($row['last_message_body']) ? (string)$row['last_message_body'] : null;
        $row['last_message_author_id'] = isset($row['last_message_author_id']) ? (int)$row['last_message_author_id'] : null;
        $row['last_message_created_at'] = isset($row['last_message_created_at']) ? (int)$row['last_message_created_at'] : null;
        $row['last_message_is_system'] = !empty($row['last_message_is_system']);
        $row['unread_count'] = isset($row['unread_count']) ? (int)$row['unread_count'] : 0;

        return $row;
    }

    public function close(int $threadId, int $userId): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'UPDATE chat_threads
             SET status = :status,
                 closed_by = :closed_by,
                 closed_at = :closed_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'closed',
            ':closed_by' => $userId,
            ':closed_at' => $now,
            ':updated_at' => $now,
            ':id' => $threadId,
        ]);
    }

    public function reopen(int $threadId): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'UPDATE chat_threads
             SET status = :status,
                 closed_by = NULL,
                 closed_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'open',
            ':updated_at' => $now,
            ':id' => $threadId,
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->schemaChecked = true;

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL DEFAULT "direct",
                subject TEXT NULL,
                created_by INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "open",
                last_message_id INTEGER NULL,
                last_message_at INTEGER NULL,
                closed_by INTEGER NULL,
                closed_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $this->ensureColumnExists('chat_threads', 'status', 'TEXT NOT NULL DEFAULT "open"');
        $this->ensureColumnExists('chat_threads', 'closed_by', 'INTEGER NULL');
        $this->ensureColumnExists('chat_threads', 'closed_at', 'INTEGER NULL');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_threads_type ON chat_threads(type)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_threads_last_message_at ON chat_threads(last_message_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_threads_status ON chat_threads(status)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                last_read_message_id INTEGER NULL,
                last_read_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(thread_id, user_id)
            )'
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_participants_thread ON chat_participants(thread_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_participants_user ON chat_participants(user_id)');
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($columns as $col) {
            if (strcasecmp((string)$col['name'], $column) === 0) {
                return;
            }
        }

        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
