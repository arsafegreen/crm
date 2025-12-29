<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;
use PDOException;

final class CampaignSendRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function exists(string $campaign, string $reference, string $cpf): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM campaign_sends WHERE campaign = :campaign AND reference = :reference AND cpf = :cpf LIMIT 1'
        );
        $stmt->execute([
            ':campaign' => $campaign,
            ':reference' => $reference,
            ':cpf' => $cpf,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function create(array $data): ?int
    {
        $timestamp = now();
        $payload = array_merge([
            'cpf' => null,
            'phone' => null,
            'campaign' => null,
            'reference' => null,
            'status' => 'pending',
            'gateway' => null,
            'message_id' => null,
            'error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $data);

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $sql = sprintf('INSERT OR IGNORE INTO campaign_sends (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

        $stmt = $this->pdo->prepare($sql);

        try {
            $stmt->execute($this->prefix($payload));
        } catch (PDOException $exception) {
            return null;
        }

        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return null;
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function markSent(int $id, ?string $gateway = null, ?string $messageId = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE campaign_sends SET status = "sent", gateway = :gateway, message_id = :message_id, updated_at = :updated_at WHERE id = :id'
        );

        $stmt->execute([
            ':gateway' => $gateway,
            ':message_id' => $messageId,
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE campaign_sends SET status = "failed", error = :error, updated_at = :updated_at WHERE id = :id'
        );

        $stmt->execute([
            ':error' => mb_substr($error, 0, 500),
            ':updated_at' => now(),
            ':id' => $id,
        ]);
    }

    public function recent(string $campaign, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM campaign_sends WHERE campaign = :campaign ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':campaign', $campaign, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'decode'], $rows);
    }

    private function decode(array $row): array
    {
        foreach (['id', 'created_at', 'updated_at'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int)$row[$key];
            }
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function prefix(array $values): array
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[':' . $key] = $value;
        }

        return $prefixed;
    }
}
