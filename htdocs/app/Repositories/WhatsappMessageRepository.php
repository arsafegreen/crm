<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use PDOException;

final class WhatsappMessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function pruneToLatest(int $threadId, int $keep = 20): int
    {
        $threadId = max(0, $threadId);
        $keep = max(0, $keep);
        if ($threadId === 0 || $keep === 0) {
            return 0;
        }

        $this->ensureArchiveTable();

        $stmt = $this->pdo->prepare(
            'SELECT id FROM whatsapp_messages WHERE thread_id = :thread ORDER BY sent_at DESC, id DESC LIMIT -1 OFFSET :offset'
        );
        $stmt->bindValue(':thread', $threadId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $keep, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === false || $ids === []) {
            return 0;
        }

        $idPlaceholders = implode(', ', array_fill(0, count($ids), '?'));

        $archiveSql = 'INSERT INTO whatsapp_messages_archive (orig_id, thread_id, direction, message_type, content, ai_summary, suggestion_source, meta_message_id, status, sent_at, created_at, metadata, archived_at)
                       SELECT id, thread_id, direction, message_type, content, ai_summary, suggestion_source, meta_message_id, status, sent_at, created_at, metadata, strftime("%s", "now")
                       FROM whatsapp_messages WHERE id IN (' . $idPlaceholders . ')';

        $archiveStmt = $this->pdo->prepare($archiveSql);
        foreach ($ids as $i => $id) {
            $archiveStmt->bindValue($i + 1, (int)$id, PDO::PARAM_INT);
        }

        $this->pdo->beginTransaction();
        try {
            $archiveStmt->execute();

            $deleteStmt = $this->pdo->prepare('DELETE FROM whatsapp_messages WHERE id IN (' . $idPlaceholders . ')');
            foreach ($ids as $i => $id) {
                $deleteStmt->bindValue($i + 1, (int)$id, PDO::PARAM_INT);
            }
            $deleteStmt->execute();

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return count($ids);
    }

    private function ensureArchiveTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_messages_archive (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                orig_id INTEGER,
                thread_id INTEGER,
                direction TEXT,
                message_type TEXT,
                content TEXT,
                ai_summary TEXT,
                suggestion_source TEXT,
                meta_message_id TEXT,
                status TEXT,
                sent_at INTEGER,
                created_at INTEGER,
                metadata TEXT,
                archived_at INTEGER
            )'
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_wm_archive_thread_id ON whatsapp_messages_archive(thread_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_wm_archive_meta_message_id ON whatsapp_messages_archive(meta_message_id)');
    }

    public function create(array $data): int
    {
        $payload = [
            'thread_id' => (int)$data['thread_id'],
            'direction' => $data['direction'] ?? 'outgoing',
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'] ?? '',
            'ai_summary' => $data['ai_summary'] ?? null,
            'suggestion_source' => $data['suggestion_source'] ?? null,
            'meta_message_id' => $data['meta_message_id'] ?? null,
            'status' => $data['status'] ?? 'sent',
            'sent_at' => $data['sent_at'] ?? now(),
            'created_at' => now(),
            'metadata' => $data['metadata'] ?? null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_messages (thread_id, direction, message_type, content, ai_summary, suggestion_source, meta_message_id, status, sent_at, created_at, metadata)
             VALUES (:thread_id, :direction, :message_type, :content, :ai_summary, :suggestion_source, :meta_message_id, :status, :sent_at, :created_at, :metadata)'
        );

        $stmt->execute([
            ':thread_id' => $payload['thread_id'],
            ':direction' => $payload['direction'],
            ':message_type' => $payload['message_type'],
            ':content' => $payload['content'],
            ':ai_summary' => $payload['ai_summary'],
            ':suggestion_source' => $payload['suggestion_source'],
            ':meta_message_id' => $payload['meta_message_id'],
            ':status' => $payload['status'],
            ':sent_at' => $payload['sent_at'],
            ':created_at' => $payload['created_at'],
            ':metadata' => $payload['metadata'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findByMetaMessageId(string $metaMessageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_messages WHERE meta_message_id = :meta LIMIT 1');
        $stmt->execute([':meta' => $metaMessageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listForThread(int $threadId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM whatsapp_messages WHERE thread_id = :thread ORDER BY sent_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':thread', $threadId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === false) {
            return [];
        }

        return array_reverse($rows);
    }

    public function listAfterId(int $threadId, int $afterId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM whatsapp_messages WHERE thread_id = :thread AND id > :after ORDER BY id ASC LIMIT :limit'
        );
        $stmt->bindValue(':thread', $threadId, PDO::PARAM_INT);
        $stmt->bindValue(':after', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function listBeforeId(int $threadId, int $beforeId, int $limit = 100): array
    {
        $cursor = $beforeId > 0 ? $beforeId : PHP_INT_MAX;
        $stmt = $this->pdo->prepare(
            'SELECT * FROM whatsapp_messages WHERE thread_id = :thread AND id < :before ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':thread', $threadId, PDO::PARAM_INT);
        $stmt->bindValue(':before', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === false) {
            return [];
        }

        return array_reverse($rows);
    }

    public function lastMessageTimestamp(int $threadId, string $direction): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT sent_at FROM whatsapp_messages WHERE thread_id = :thread AND direction = :direction ORDER BY sent_at DESC, id DESC LIMIT 1'
        );
        $stmt->bindValue(':thread', $threadId, PDO::PARAM_INT);
        $stmt->bindValue(':direction', $direction);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            return null;
        }

        $value = $result['sent_at'] ?? null;
        return is_numeric($value) ? (int)$value : null;
    }

    public function find(int $messageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_messages WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $messageId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function updateStatus(int $messageId, string $status, ?string $metadata = null): void
    {
        $fields = ['status = :status'];
        $params = [
            ':id' => $messageId,
            ':status' => $status,
        ];

        if ($metadata !== null) {
            $fields[] = 'metadata = :metadata';
            $params[':metadata'] = $metadata;
        }

        $sql = 'UPDATE whatsapp_messages SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updateOutgoingMessage(int $messageId, array $payload): void
    {
        $fields = [];
        $params = [':id' => $messageId];

        if (array_key_exists('meta_message_id', $payload)) {
            $fields[] = 'meta_message_id = :meta_message_id';
            $params[':meta_message_id'] = $payload['meta_message_id'];
        }

        if (array_key_exists('status', $payload)) {
            $fields[] = 'status = :status';
            $params[':status'] = $payload['status'];
        }

        if (array_key_exists('metadata', $payload)) {
            $fields[] = 'metadata = :metadata';
            $params[':metadata'] = $payload['metadata'];
        }

        if ($fields === []) {
            return;
        }

        $sql = 'UPDATE whatsapp_messages SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updateIncomingMessage(int $messageId, array $payload): void
    {
        $fields = [];
        $params = [':id' => $messageId];

        if (array_key_exists('content', $payload)) {
            $fields[] = 'content = :content';
            $params[':content'] = (string)$payload['content'];
        }

        if (array_key_exists('message_type', $payload)) {
            $fields[] = 'message_type = :message_type';
            $params[':message_type'] = (string)$payload['message_type'];
        }

        if (array_key_exists('metadata', $payload)) {
            $fields[] = 'metadata = :metadata';
            $params[':metadata'] = $payload['metadata'];
        }

        if (array_key_exists('status', $payload)) {
            $fields[] = 'status = :status';
            $params[':status'] = (string)$payload['status'];
        }

        if (array_key_exists('sent_at', $payload)) {
            $fields[] = 'sent_at = :sent_at';
            $params[':sent_at'] = (int)$payload['sent_at'];
        }

        if ($fields === []) {
            return;
        }

        $sql = 'UPDATE whatsapp_messages SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function countOutgoingAltByPhoneSince(string $phone, int $sinceTimestamp): int
    {
        $normalized = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($normalized === '' || $sinceTimestamp <= 0) {
            return 0;
        }

        $sql =
            'SELECT COUNT(*) AS total
             FROM whatsapp_messages m
             INNER JOIN whatsapp_threads t ON t.id = m.thread_id
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             WHERE m.direction = "outgoing"
               AND m.sent_at >= :since
               AND c.phone = :phone
               AND t.channel_thread_id LIKE "alt:%"';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':since', $sinceTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':phone', $normalized);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }
}
