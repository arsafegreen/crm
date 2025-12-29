<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class WhatsappBroadcastRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_broadcasts (title, message, template_kind, template_key, criteria, status, stats_total, stats_sent, stats_failed, initiated_by, created_at, completed_at, last_error)
             VALUES (:title, :message, :template_kind, :template_key, :criteria, :status, :stats_total, :stats_sent, :stats_failed, :initiated_by, :created_at, :completed_at, :last_error)'
        );

        $stmt->execute([
            ':title' => $data['title'],
            ':message' => $data['message'] ?? null,
            ':template_kind' => $data['template_kind'] ?? null,
            ':template_key' => $data['template_key'] ?? null,
            ':criteria' => $data['criteria'],
            ':status' => $data['status'] ?? 'pending',
            ':stats_total' => $data['stats_total'] ?? 0,
            ':stats_sent' => $data['stats_sent'] ?? 0,
            ':stats_failed' => $data['stats_failed'] ?? 0,
            ':initiated_by' => (int)$data['initiated_by'],
            ':created_at' => $data['created_at'] ?? now(),
            ':completed_at' => $data['completed_at'] ?? null,
            ':last_error' => $data['last_error'] ?? null,
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

        $sql = 'UPDATE whatsapp_broadcasts SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_broadcasts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function recent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_broadcasts ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    public function listByStatus(string $status, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_broadcasts WHERE status = :status ORDER BY created_at ASC LIMIT :limit');
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

}
