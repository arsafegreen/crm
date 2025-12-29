<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailMessageParticipantRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function replaceForMessage(int $messageId, array $participants): void
    {
        $this->deleteByMessage($messageId);

        if ($participants === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_message_participants (
                message_id,
                role,
                name,
                email,
                contact_id,
                client_id,
                rfb_prospect_id,
                created_at
            ) VALUES (
                :message_id,
                :role,
                :name,
                :email,
                :contact_id,
                :client_id,
                :rfb_prospect_id,
                :created_at
            )'
        );

        $timestamp = time();
        foreach ($participants as $participant) {
            if (empty($participant['email'])) {
                continue;
            }

            $payload = [
                ':message_id' => $messageId,
                ':role' => $participant['role'] ?? 'to',
                ':name' => $participant['name'] ?? null,
                ':email' => strtolower((string)$participant['email']),
                ':contact_id' => $participant['contact_id'] ?? null,
                ':client_id' => $participant['client_id'] ?? null,
                ':rfb_prospect_id' => $participant['rfb_prospect_id'] ?? null,
                ':created_at' => $timestamp,
            ];

            $stmt->execute($payload);
        }
    }

    public function listByMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_message_participants WHERE message_id = :message_id ORDER BY id'
        );
        $stmt->execute([':message_id' => $messageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function deleteByMessage(int $messageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_message_participants WHERE message_id = :message_id');
        $stmt->execute([':message_id' => $messageId]);
    }
}
