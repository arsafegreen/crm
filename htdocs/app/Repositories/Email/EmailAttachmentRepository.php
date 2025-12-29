<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailAttachmentRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function insertMany(int $messageId, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_attachments (
                message_id,
                filename,
                mime_type,
                size_bytes,
                storage_path,
                checksum,
                created_at
            ) VALUES (
                :message_id,
                :filename,
                :mime_type,
                :size_bytes,
                :storage_path,
                :checksum,
                :created_at
            )'
        );

        $timestamp = time();
        foreach ($attachments as $attachment) {
            if (empty($attachment['storage_path']) || empty($attachment['filename'])) {
                continue;
            }

            $payload = [
                ':message_id' => $messageId,
                ':filename' => $attachment['filename'],
                ':mime_type' => $attachment['mime_type'] ?? null,
                ':size_bytes' => (int)($attachment['size_bytes'] ?? 0),
                ':storage_path' => $attachment['storage_path'],
                ':checksum' => $attachment['checksum'] ?? null,
                ':created_at' => $timestamp,
            ];

            $stmt->execute($payload);
        }
    }

    public function listByMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_attachments WHERE message_id = :message_id ORDER BY id'
        );
        $stmt->execute([':message_id' => $messageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_attachments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function deleteByMessage(int $messageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_attachments WHERE message_id = :message_id');
        $stmt->execute([':message_id' => $messageId]);
    }
}
