<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class MailDeliveryLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function record(?int $queueJobId, string $eventType, array $payload = [], ?int $contactId = null, array $context = []): void
    {
        $timestamp = now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_delivery_logs (
                queue_job_id,
                contact_id,
                provider_event_id,
                event_type,
                occurred_at,
                payload,
                actor_ip,
                actor_agent,
                created_at
             ) VALUES (
                :queue_job_id,
                :contact_id,
                :provider_event_id,
                :event_type,
                :occurred_at,
                :payload,
                :actor_ip,
                :actor_agent,
                :created_at
             )'
        );

        $stmt->execute([
            ':queue_job_id' => $queueJobId,
            ':contact_id' => $contactId,
            ':provider_event_id' => $payload['event_id'] ?? null,
            ':event_type' => $eventType,
            ':occurred_at' => $payload['occurred_at'] ?? $timestamp,
            ':payload' => $this->encode($payload),
            ':actor_ip' => $context['ip'] ?? null,
            ':actor_agent' => $context['agent'] ?? null,
            ':created_at' => $timestamp,
        ]);
    }

    public function forContact(int $contactId, ?array $eventTypes = null, int $limit = 200): array
    {
        $sql = 'SELECT * FROM mail_delivery_logs WHERE contact_id = :contact_id';
        $params = [':contact_id' => $contactId];

        if ($eventTypes !== null && $eventTypes !== []) {
            $placeholders = [];
            foreach ($eventTypes as $index => $eventType) {
                $placeholder = ':event_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $eventType;
            }
            $sql .= ' AND event_type IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            if (isset($row['payload'])) {
                $decoded = json_decode((string)$row['payload'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['payload'] = $decoded;
                }
            }
        }

        return $rows;
    }

    /**
     * @param string[]|null $eventTypes
     * @return array<string, int>
     */
    public function totalsByEventType(?array $eventTypes = null): array
    {
        $sql = 'SELECT event_type, COUNT(*) AS total FROM mail_delivery_logs';
        $params = [];

        if ($eventTypes !== null && $eventTypes !== []) {
            $placeholders = [];
            foreach ($eventTypes as $index => $eventType) {
                $placeholder = ':event_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $eventType;
            }
            $sql .= ' WHERE event_type IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' GROUP BY event_type';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totals = [];
        foreach ($rows as $row) {
            $key = (string)($row['event_type'] ?? '');
            if ($key === '') {
                continue;
            }
            $totals[$key] = (int)($row['total'] ?? 0);
        }

        return $totals;
    }

    /**
     * @param string[] $eventTypes
     * @return array<int, array<string, mixed>>
     */
    public function recentByEventType(array $eventTypes, int $limit = 50): array
    {
        if ($eventTypes === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($eventTypes as $index => $eventType) {
            $placeholder = ':event_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $eventType;
        }

        $sql = 'SELECT id, queue_job_id, contact_id, provider_event_id, event_type, occurred_at, payload, created_at
                FROM mail_delivery_logs
                WHERE event_type IN (' . implode(', ', $placeholders) . ')
                ORDER BY occurred_at DESC, id DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            if (isset($row['payload'])) {
                $decoded = json_decode((string)$row['payload'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['payload'] = $decoded;
                }
            }
        }

        return $rows;
    }

    private function encode(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
