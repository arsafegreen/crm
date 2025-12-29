<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CopilotTrainingSampleRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function create(array $payload): int
    {
        $existingId = null;
        if (!empty($payload['thread_id'])) {
            $check = $this->pdo->prepare('SELECT id FROM copilot_training_samples WHERE thread_id = :thread_id LIMIT 1');
            $check->execute([':thread_id' => $payload['thread_id']]);
            $existingId = $check->fetchColumn();
            $existingId = $existingId !== false ? (int)$existingId : null;
        }

        if ($existingId !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE copilot_training_samples
                 SET contact_name = :contact_name,
                     contact_phone = :contact_phone,
                     category = :category,
                     summary = :summary,
                     messages_json = :messages_json,
                     created_at = :created_at
                 WHERE id = :id'
            );

            $stmt->execute([
                ':contact_name' => $payload['contact_name'],
                ':contact_phone' => $payload['contact_phone'],
                ':category' => $payload['category'],
                ':summary' => $payload['summary'],
                ':messages_json' => $payload['messages_json'],
                ':created_at' => $payload['created_at'],
                ':id' => $existingId,
            ]);

            return $existingId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO copilot_training_samples (thread_id, contact_name, contact_phone, category, summary, messages_json, created_at)
             VALUES (:thread_id, :contact_name, :contact_phone, :category, :summary, :messages_json, :created_at)'
        );

        $stmt->execute([
            ':thread_id' => $payload['thread_id'],
            ':contact_name' => $payload['contact_name'],
            ':contact_phone' => $payload['contact_phone'],
            ':category' => $payload['category'],
            ':summary' => $payload['summary'],
            ':messages_json' => $payload['messages_json'],
            ':created_at' => $payload['created_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function recent(int $limit = 5, ?string $category = null): array
    {
        $limit = max(1, min(20, $limit));
        $params = [':limit' => $limit];
        $sql = 'SELECT * FROM copilot_training_samples';
        if ($category !== null && $category !== '') {
            $sql .= ' WHERE category = :category';
            $params[':category'] = $category;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $key === ':limit' ? (int)$value : $value, $key === ':limit' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(1) FROM copilot_training_samples');
        return (int)$stmt->fetchColumn();
    }
}
