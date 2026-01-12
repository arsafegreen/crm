<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use function digits_only;

final class WhatsappThreadRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_threads WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByChannelId(string $channelThreadId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_threads WHERE channel_thread_id = :channel LIMIT 1');
        $stmt->execute([':channel' => $channelThreadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findLatestByContact(int $contactId): ?array
    {
        return $this->findLatestByContactAndLine($contactId, null);
    }

    public function findLatestByContactAndLine(int $contactId, ?int $lineId = null): ?array
    {
        $sql = 'SELECT * FROM whatsapp_threads WHERE contact_id = :contact';
        $params = [':contact' => $contactId];

        if ($lineId !== null) {
            $sql .= ' AND line_id = :line_id';
            $params[':line_id'] = $lineId;
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        $payload = [
            'contact_id' => (int)$data['contact_id'],
            'subject' => $data['subject'] ?? null,
            'status' => $data['status'] ?? 'open',
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'channel_thread_id' => $data['channel_thread_id'] ?? null,
            'unread_count' => $data['unread_count'] ?? 0,
            'last_message_preview' => $data['last_message_preview'] ?? null,
            'last_message_at' => $data['last_message_at'] ?? null,
            'copilot_status' => $data['copilot_status'] ?? 'idle',
            'line_id' => $data['line_id'] ?? null,
            'queue' => $data['queue'] ?? 'arrival',
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'partner_id' => $data['partner_id'] ?? null,
            'responsible_user_id' => $data['responsible_user_id'] ?? null,
            'intake_summary' => $data['intake_summary'] ?? null,
            'chat_type' => $data['chat_type'] ?? 'direct',
            'group_subject' => $data['group_subject'] ?? null,
            'group_metadata' => $data['group_metadata'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
            'closed_at' => $data['closed_at'] ?? null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_threads (contact_id, subject, status, assigned_user_id, channel_thread_id, unread_count, last_message_preview, last_message_at, copilot_status, line_id, queue, scheduled_for, partner_id, responsible_user_id, intake_summary, chat_type, group_subject, group_metadata, created_at, updated_at, closed_at)
             VALUES (:contact_id, :subject, :status, :assigned_user_id, :channel_thread_id, :unread_count, :last_message_preview, :last_message_at, :copilot_status, :line_id, :queue, :scheduled_for, :partner_id, :responsible_user_id, :intake_summary, :chat_type, :group_subject, :group_metadata, :created_at, :updated_at, :closed_at)'
        );

        $stmt->execute([
            ':contact_id' => $payload['contact_id'],
            ':subject' => $payload['subject'],
            ':status' => $payload['status'],
            ':assigned_user_id' => $payload['assigned_user_id'],
            ':channel_thread_id' => $payload['channel_thread_id'],
            ':unread_count' => $payload['unread_count'],
            ':last_message_preview' => $payload['last_message_preview'],
            ':last_message_at' => $payload['last_message_at'],
            ':copilot_status' => $payload['copilot_status'],
            ':line_id' => $payload['line_id'],
            ':queue' => $payload['queue'],
            ':scheduled_for' => $payload['scheduled_for'],
            ':partner_id' => $payload['partner_id'],
            ':responsible_user_id' => $payload['responsible_user_id'],
            ':intake_summary' => $payload['intake_summary'],
            ':chat_type' => $payload['chat_type'],
            ':group_subject' => $payload['group_subject'],
            ':group_metadata' => $payload['group_metadata'],
            ':created_at' => $payload['created_at'],
            ':updated_at' => $payload['updated_at'],
            ':closed_at' => $payload['closed_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = [':id' => $id];
        foreach ($data as $key => $value) {
            $fields[] = sprintf('%s = :%s', $key, $key);
            $params[':' . $key] = $value;
        }

        $fields[] = 'updated_at = :updated_at';
        $params[':updated_at'] = now();

        $sql = 'UPDATE whatsapp_threads SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function listByStatus(array $statuses, int $limit = 25): array
    {
        if ($statuses === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.status IN (' . $placeholders . ')
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($statuses as $status) {
            $stmt->bindValue($i, $status);
            $i++;
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function listByQueue(string $queue, int $limit = 50): array
    {
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.queue = :queue
               AND (t.status IS NULL OR t.status != "closed")
               AND (t.queue != "arrival" OR t.assigned_user_id IS NULL OR t.assigned_user_id = 0)
             ORDER BY (t.scheduled_for IS NULL) ASC, t.scheduled_for ASC, (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':queue', $queue);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listInactiveOlderThan(int $thresholdTimestamp, int $limit = 200): array
    {
        $sql =
            'SELECT t.id
             FROM whatsapp_threads t
             WHERE (t.status IS NULL OR t.status != "closed")
               AND (t.chat_type IS NULL OR t.chat_type != "group")
               AND COALESCE(t.last_message_at, t.updated_at, t.created_at, 0) < :threshold
             ORDER BY t.last_message_at ASC, t.updated_at ASC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $thresholdTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
    }

    /**
     * @param array<int> $contactIds
     * @return array<int, array<string,mixed>>
     */
    public function listRecentByContactIds(array $contactIds, int $limit = 10): array
    {
        $filtered = array_values(array_unique(array_filter(array_map(static fn($id): int => (int)$id, $contactIds), static fn(int $id): bool => $id > 0)));
        if ($filtered === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.contact_id IN (' . $placeholders . ')
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($filtered as $id) {
            $stmt->bindValue($i, $id, PDO::PARAM_INT);
            $i++;
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function listIdsForQueues(array $queues, int $limit = 200): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static function ($queue) {
            return strtolower(trim((string)$queue));
        }, $queues))));

        if ($normalized === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
        $sql =
            'SELECT t.id
             FROM whatsapp_threads t
             WHERE t.queue IN (' . $placeholders . ')
               AND (t.status IS NULL OR t.status != "closed")
               AND (t.chat_type IS NULL OR t.chat_type != "group")
               AND (t.queue != "arrival" OR t.assigned_user_id IS NULL OR t.assigned_user_id = 0)
             ORDER BY (t.scheduled_for IS NULL) ASC, t.scheduled_for ASC, (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT ?';

        $stmt = $this->pdo->prepare($sql);
        $index = 1;
        foreach ($normalized as $queue) {
            $stmt->bindValue($index, $queue);
            $index++;
        }
        $stmt->bindValue($index, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    public function listGroupThreads(int $limit = 50): array
    {
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                      l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE (t.status IS NULL OR t.status != "closed") AND t.chat_type = "group"
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findGroupThreadsByChannelOrSubject(string $channelThreadId, string $subject, string $contactPhone = ''): array
    {
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                      l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
               FROM whatsapp_threads t
               INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
               LEFT JOIN partners p ON p.id = t.partner_id
               LEFT JOIN users u ON u.id = t.responsible_user_id
               LEFT JOIN whatsapp_lines l ON l.id = t.line_id
              WHERE t.chat_type = "group"
                AND (
                    (:channel IS NOT NULL AND :channel != "" AND t.channel_thread_id = :channel)
                    OR (:subject IS NOT NULL AND :subject != "" AND t.group_subject = :subject)
                    OR (:cphone IS NOT NULL AND :cphone != "" AND c.phone = :cphone)
                )
              ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':channel', $channelThreadId, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $subject, PDO::PARAM_STR);
        $stmt->bindValue(':cphone', $contactPhone, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listGroupIds(int $limit = 200): array
    {
        $sql =
            'SELECT t.id
               FROM whatsapp_threads t
             WHERE (t.status IS NULL OR t.status != "closed")
               AND t.chat_type = "group"
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    public function listAssignedTo(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.assigned_user_id = :user_id
               AND (t.status IS NULL OR t.status != "closed")
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActiveAssigned(int $limit = 60): array
    {
        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE (t.status IS NULL OR t.status != "closed")
               AND t.assigned_user_id IS NOT NULL
               AND t.assigned_user_id > 0
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findWithRelations(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function countClosed(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM whatsapp_threads WHERE status = "closed"');
        $value = $stmt ? $stmt->fetchColumn() : 0;
        return (int)($value ?? 0);
    }

    public function searchClosed(string $query, int $limit = 60): array
    {
        $query = trim(mb_strtolower($query));
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $digits = digits_only($query);

        $sql =
            'SELECT t.*, c.name AS contact_name, c.phone AS contact_phone, c.client_id AS contact_client_id, p.name AS partner_name, u.name AS responsible_name,
                    l.label AS line_label, l.display_phone AS line_display_phone, l.provider AS line_provider
             FROM whatsapp_threads t
             INNER JOIN whatsapp_contacts c ON c.id = t.contact_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.responsible_user_id
             LEFT JOIN whatsapp_lines l ON l.id = t.line_id
             WHERE t.status = "closed"
               AND (
                    LOWER(c.name) LIKE :qs
                 OR LOWER(t.last_message_preview) LIKE :qs
                 OR LOWER(COALESCE(t.channel_thread_id, "")) LIKE :qs
                 OR LOWER(COALESCE(p.name, "")) LIKE :qs
                 ' . ($digits !== '' ? 'OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, "+", ""), "(", ""), ")", ""), "-", ""), " ", "") LIKE :digits' : '') . '
               )
             ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, t.updated_at DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':qs', '%' . $query . '%');
        if ($digits !== '') {
            $stmt->bindValue(':digits', '%' . $digits . '%');
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,int>
     */
    public function countByQueue(): array
    {
        $stmt = $this->pdo->query(
            'SELECT queue, COUNT(*) AS total
             FROM whatsapp_threads
             WHERE (status IS NULL OR status != "closed")
               AND (chat_type IS NULL OR chat_type != "group")
               AND (queue != "arrival" OR assigned_user_id IS NULL OR assigned_user_id = 0)
             GROUP BY queue'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $queue = (string)($row['queue'] ?? '');
            if ($queue === '') {
                $queue = 'arrival';
            }
            $result[$queue] = (int)($row['total'] ?? 0);
        }

        return $result;
    }

    public function incrementUnread(int $threadId, int $amount = 1): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE whatsapp_threads
             SET unread_count = CASE WHEN unread_count + :amount < 0 THEN 0 ELSE unread_count + :amount END,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':amount' => $amount,
            ':updated_at' => now(),
            ':id' => $threadId,
        ]);
    }

    public function markAsRead(int $threadId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE whatsapp_threads SET unread_count = 0, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':updated_at' => now(),
            ':id' => $threadId,
        ]);
    }

    public function assignToUser(int $threadId, ?int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE whatsapp_threads SET assigned_user_id = :assigned_user_id, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':assigned_user_id' => $userId,
            ':updated_at' => now(),
            ':id' => $threadId,
        ]);
    }

    public function updateStatus(int $threadId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE whatsapp_threads SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => now(),
            ':id' => $threadId,
        ]);
    }
}
