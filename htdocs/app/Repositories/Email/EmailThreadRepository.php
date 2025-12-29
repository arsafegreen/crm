<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailThreadRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_threads WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findBySubject(int $accountId, string $subject): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_threads
             WHERE account_id = :account_id AND subject = :subject
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':subject' => $subject,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        $timestamp = time();
        $data['created_at'] = $data['created_at'] ?? $timestamp;
        $data['updated_at'] = $data['updated_at'] ?? $timestamp;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));

        $stmt = $this->pdo->prepare("INSERT INTO email_threads ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefix($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function touch(int $id, array $payload): void
    {
        $set = ['updated_at = :updated_at'];
        $params = [
            ':id' => $id,
            ':updated_at' => time(),
        ];

        foreach (['subject', 'snippet', 'folder_id', 'last_message_at', 'primary_contact_id', 'primary_client_id', 'flags'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $set[] = sprintf('%s = :%s', $field, $field);
            $params[':' . $field] = $payload[$field];
        }

        if (isset($payload['unread_increment']) && (int)$payload['unread_increment'] !== 0) {
            $set[] = 'unread_count = CASE WHEN unread_count + :unread_increment < 0 THEN 0 ELSE unread_count + :unread_increment END';
            $params[':unread_increment'] = (int)$payload['unread_increment'];
        }

        if (count($set) === 1) {
            return;
        }

        $sql = sprintf('UPDATE email_threads SET %s WHERE id = :id', implode(', ', $set));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function recentByAccount(int $accountId, array $filters = [], int $limit = 30): array
    {
        $sql = 'SELECT t.*, f.display_name AS folder_name, f.type AS folder_type, f.unread_count AS folder_unread_count
                FROM email_threads t
                LEFT JOIN email_folders f ON f.id = t.folder_id
                WHERE t.account_id = :account_id';
        $params = [':account_id' => $accountId];

        if (!empty($filters['folder_id'])) {
            $sql .= ' AND t.folder_id = :folder_id';
            $params[':folder_id'] = (int)$filters['folder_id'];
        }

        if (!empty($filters['unread_only'])) {
            $sql .= ' AND t.unread_count > 0';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (t.subject LIKE :search OR t.snippet LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['updated_since'])) {
            $sql .= ' AND t.updated_at >= :updated_since';
            $params[':updated_since'] = (int)$filters['updated_since'];
        }

        $limit = max(1, min($limit, 200));
        $sql .= ' ORDER BY COALESCE(t.last_message_at, t.updated_at) DESC, t.id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * Aggregated counters per folder for the given account.
     * Returns an associative array keyed by folder_id with keys: total, unread.
     */
    public function folderCounters(int $accountId): array
    {
        $sql = 'SELECT COALESCE(folder_id, 0) AS folder_id,
                       COUNT(*) AS total,
                       SUM(CASE WHEN unread_count > 0 THEN unread_count ELSE 0 END) AS unread
                FROM email_threads
                WHERE account_id = :account_id
                GROUP BY folder_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $counters = [];
        foreach ($rows as $row) {
            $id = (int)$row['folder_id'];
            $counters[$id] = [
                'total' => (int)$row['total'],
                'unread' => (int)($row['unread'] ?? 0),
            ];
        }

        return $counters;
    }

    public function searchAdvanced(int $accountId, array $filters = [], int $limit = 50): array
    {
        $sql = 'SELECT t.*, f.display_name AS folder_name, f.type AS folder_type, f.unread_count AS folder_unread_count
                FROM email_threads t
                LEFT JOIN email_folders f ON f.id = t.folder_id
                WHERE t.account_id = :account_id';
        $params = [':account_id' => $accountId];

        if (isset($filters['folder_id']) && $filters['folder_id'] !== null) {
            $sql .= ' AND t.folder_id = :folder_id';
            $params[':folder_id'] = (int)$filters['folder_id'];
        }

        if (!empty($filters['unread_only'])) {
            $sql .= ' AND t.unread_count > 0';
        }

        if (isset($filters['query']) && $filters['query'] !== null && $filters['query'] !== '') {
            $sql .= ' AND (
                t.subject LIKE :query
                OR t.snippet LIKE :query
                OR EXISTS (
                    SELECT 1 FROM email_messages m
                    WHERE m.thread_id = t.id
                    AND (
                        m.subject LIKE :query
                        OR m.snippet LIKE :query
                        OR m.body_preview LIKE :query
                    )
                )
            )';
            $params[':query'] = '%' . $filters['query'] . '%';
        }

        if (isset($filters['participant']) && $filters['participant'] !== null && $filters['participant'] !== '') {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM email_messages mp
                INNER JOIN email_message_participants p ON p.message_id = mp.id
                WHERE mp.thread_id = t.id
                AND (
                    p.email LIKE :participant
                    OR p.name LIKE :participant
                )
            )';
            $params[':participant'] = '%' . $filters['participant'] . '%';
        }

        if (!empty($filters['has_attachments'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM email_messages ma
                INNER JOIN email_attachments a ON a.message_id = ma.id
                WHERE ma.thread_id = t.id
            )';
        }

        if (!empty($filters['mention_email'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM email_messages mm
                INNER JOIN email_message_participants mp ON mp.message_id = mm.id
                WHERE mm.thread_id = t.id
                AND LOWER(mp.email) = LOWER(:mention_email)
            )';
            $params[':mention_email'] = $filters['mention_email'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM email_messages mf
                WHERE mf.thread_id = t.id
                AND COALESCE(mf.sent_at, mf.received_at, mf.created_at) >= :date_from
            )';
            $params[':date_from'] = (int)$filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM email_messages mt
                WHERE mt.thread_id = t.id
                AND COALESCE(mt.sent_at, mt.received_at, mt.created_at) <= :date_to
            )';
            $params[':date_to'] = (int)$filters['date_to'];
        }

        $limit = max(1, min($limit, 200));
        $sql .= ' ORDER BY COALESCE(t.last_message_at, t.updated_at) DESC, t.id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function findWithFolder(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
              'SELECT t.*, f.display_name AS folder_name, f.type AS folder_type, f.unread_count AS folder_unread_count
             FROM email_threads t
             LEFT JOIN email_folders f ON f.id = t.folder_id
             WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listByFolder(int $accountId, int $folderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, unread_count
             FROM email_threads
             WHERE account_id = :account_id AND folder_id = :folder_id
             ORDER BY id'
        );

        $stmt->execute([
            ':account_id' => $accountId,
            ':folder_id' => $folderId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function deleteByIds(array $threadIds): int
    {
        if ($threadIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($threadIds), '?'));
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM email_threads WHERE id IN (%s)', $placeholders));
        $stmt->execute(array_values(array_map(static fn($value): int => (int)$value, $threadIds)));

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
