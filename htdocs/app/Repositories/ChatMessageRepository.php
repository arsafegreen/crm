<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ChatMessageRepository
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
        $payload['thread_id'] = (int)($payload['thread_id'] ?? 0);
        $payload['author_id'] = (int)($payload['author_id'] ?? 0);
        $payload['body'] = (string)($payload['body'] ?? '');
        $payload['type'] = (string)($payload['type'] ?? 'text');
        $payload['external_author'] = $payload['external_author'] ?? null;
        $payload['attachment_path'] = $payload['attachment_path'] ?? null;
        $payload['attachment_name'] = $payload['attachment_name'] ?? null;
        $payload['attachment_mime'] = $payload['attachment_mime'] ?? null;
        $payload['attachment_size'] = $payload['attachment_size'] ?? null;
        $payload['attachment_meta'] = $payload['attachment_meta'] ?? null;
        $payload['is_system'] = !empty($payload['is_system']) ? 1 : 0;
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_messages (
                thread_id,
                author_id,
                body,
                type,
                external_author,
                attachment_path,
                attachment_name,
                attachment_mime,
                attachment_size,
                attachment_meta,
                is_system,
                created_at,
                updated_at,
                deleted_at
            ) VALUES (
                :thread_id,
                :author_id,
                :body,
                :type,
                :external_author,
                :attachment_path,
                :attachment_name,
                :attachment_mime,
                :attachment_size,
                :attachment_meta,
                :is_system,
                :created_at,
                :updated_at,
                NULL
            )'
        );

        $stmt->execute([
            ':thread_id' => $payload['thread_id'],
            ':author_id' => $payload['author_id'],
            ':body' => $payload['body'],
            ':type' => $payload['type'],
            ':external_author' => $payload['external_author'],
            ':attachment_path' => $payload['attachment_path'],
            ':attachment_name' => $payload['attachment_name'],
            ':attachment_mime' => $payload['attachment_mime'],
            ':attachment_size' => $payload['attachment_size'],
            ':attachment_meta' => $payload['attachment_meta'],
            ':is_system' => $payload['is_system'],
            ':created_at' => $payload['created_at'],
            ':updated_at' => $payload['updated_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $messageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.name AS author_name, u.email AS author_email
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.author_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castMessage($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForThread(int $threadId, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array
    {
        $conditions = ['m.thread_id = :thread_id', 'm.deleted_at IS NULL'];
        $params = [':thread_id' => $threadId];

        if ($beforeId !== null && $beforeId > 0) {
            $conditions[] = 'm.id < :before_id';
            $params[':before_id'] = $beforeId;
        }

        if ($afterId !== null && $afterId > 0) {
            $conditions[] = 'm.id > :after_id';
            $params[':after_id'] = $afterId;
        }

        $sql = 'SELECT m.*, u.name AS author_name, u.email AS author_email
                FROM chat_messages m
                LEFT JOIN users u ON u.id = m.author_id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY m.id DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $messages = array_map([$this, 'castMessage'], $rows);

        return array_reverse($messages);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestMessage(int $threadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM chat_messages
             WHERE thread_id = :thread_id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([':thread_id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castMessage($row);
    }

    /**
     * @return array{deleted:int, threads: int[]}
     */
    public function purgeBefore(int $cutoffTimestamp): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmtThreads = $this->pdo->prepare('SELECT DISTINCT thread_id FROM chat_messages WHERE created_at < :cutoff');
            $stmtThreads->execute([':cutoff' => $cutoffTimestamp]);
            $threadIds = array_map('intval', $stmtThreads->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $stmtDelete = $this->pdo->prepare('DELETE FROM chat_messages WHERE created_at < :cutoff');
            $stmtDelete->execute([':cutoff' => $cutoffTimestamp]);
            $deletedRows = $stmtDelete->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return [
            'deleted' => $deletedRows,
            'threads' => $threadIds,
        ];
    }

    /**
     * @param string[] $threadTypes
     * @return array{deleted:int, threads:int[]}
     */
    public function purgeByThreadTypesBefore(array $threadTypes, int $cutoffTimestamp): array
    {
        $types = array_values(array_filter(array_map(static fn(string $type): string => trim(strtolower($type)), $threadTypes), static fn(string $type): bool => $type !== ''));
        if ($types === [] || $cutoffTimestamp <= 0) {
            return ['deleted' => 0, 'threads' => []];
        }

        $placeholders = implode(',', array_fill(0, count($types), '?'));

        $this->pdo->beginTransaction();

        try {
            $stmtThreads = $this->pdo->prepare(
                'SELECT DISTINCT m.thread_id
                 FROM chat_messages m
                 INNER JOIN chat_threads t ON t.id = m.thread_id
                 WHERE t.type IN (' . $placeholders . ')
                   AND m.created_at < :cutoff'
            );

            foreach ($types as $index => $value) {
                $stmtThreads->bindValue($index + 1, $value, PDO::PARAM_STR);
            }
            $stmtThreads->bindValue(':cutoff', $cutoffTimestamp, PDO::PARAM_INT);
            $stmtThreads->execute();
            $threadIds = array_map('intval', $stmtThreads->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $stmtDelete = $this->pdo->prepare(
                'DELETE FROM chat_messages
                 WHERE thread_id IN (
                    SELECT t.id FROM chat_threads t WHERE t.type IN (' . $placeholders . ')
                 ) AND created_at < :cutoff'
            );

            foreach ($types as $index => $value) {
                $stmtDelete->bindValue($index + 1, $value, PDO::PARAM_STR);
            }
            $stmtDelete->bindValue(':cutoff', $cutoffTimestamp, PDO::PARAM_INT);
            $stmtDelete->execute();
            $deletedRows = $stmtDelete->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return [
            'deleted' => $deletedRows,
            'threads' => $threadIds,
        ];
    }

    public function recordPurge(int $adminId, int $cutoffTimestamp, int $rowsDeleted): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_message_purges (
                admin_id,
                executed_at,
                cutoff_timestamp,
                rows_deleted,
                created_at
            ) VALUES (
                :admin_id,
                :executed_at,
                :cutoff,
                :rows_deleted,
                :created_at
            )'
        );

        $now = now();
        $stmt->execute([
            ':admin_id' => $adminId,
            ':executed_at' => $now,
            ':cutoff' => $cutoffTimestamp,
            ':rows_deleted' => $rowsDeleted,
            ':created_at' => $now,
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->schemaChecked = true;

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                author_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'text',
                external_author TEXT NULL,
                attachment_path TEXT NULL,
                attachment_name TEXT NULL,
                attachment_mime TEXT NULL,
                attachment_size INTEGER NULL,
                attachment_meta TEXT NULL,
                is_system INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                deleted_at INTEGER NULL
            )"
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_thread ON chat_messages(thread_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_author ON chat_messages(author_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_messages_created_at ON chat_messages(created_at)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_message_purges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                executed_at INTEGER NOT NULL,
                cutoff_timestamp INTEGER NOT NULL,
                rows_deleted INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )'
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_message_purges_admin ON chat_message_purges(admin_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_message_purges_cutoff ON chat_message_purges(cutoff_timestamp)');
        $this->ensureColumnExists('chat_messages', 'external_author', 'TEXT NULL');
        $this->ensureColumnExists('chat_messages', 'type', "TEXT NOT NULL DEFAULT 'text'");
        $this->ensureColumnExists('chat_messages', 'attachment_mime', 'TEXT NULL');
        $this->ensureColumnExists('chat_messages', 'attachment_size', 'INTEGER NULL');
        $this->ensureColumnExists('chat_messages', 'attachment_meta', 'TEXT NULL');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castMessage(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['thread_id'] = (int)($row['thread_id'] ?? 0);
        $row['author_id'] = (int)($row['author_id'] ?? 0);
        $row['body'] = (string)($row['body'] ?? '');
        $row['type'] = isset($row['type']) ? (string)$row['type'] : 'text';
        $row['external_author'] = isset($row['external_author']) ? (string)$row['external_author'] : null;
        $row['attachment_path'] = isset($row['attachment_path']) ? (string)$row['attachment_path'] : null;
        $row['attachment_name'] = isset($row['attachment_name']) ? (string)$row['attachment_name'] : null;
        $row['attachment_mime'] = isset($row['attachment_mime']) ? (string)$row['attachment_mime'] : null;
        $row['attachment_size'] = isset($row['attachment_size']) ? (int)$row['attachment_size'] : null;
        $row['attachment_meta'] = isset($row['attachment_meta']) ? $this->decodeMeta($row['attachment_meta']) : null;
        $row['is_system'] = !empty($row['is_system']);
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['updated_at'] = (int)($row['updated_at'] ?? 0);
        $row['deleted_at'] = isset($row['deleted_at']) ? (int)$row['deleted_at'] : null;
        $row['author_name'] = isset($row['author_name']) ? (string)$row['author_name'] : null;
        $row['author_email'] = isset($row['author_email']) ? (string)$row['author_email'] : null;

        return $row;
    }

    private function decodeMeta(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
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
