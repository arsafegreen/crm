<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class CampaignSendLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function record(string $campaign, ?string $reference, ?string $cpf, ?string $phone, string $status, ?string $message = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO campaign_send_logs (campaign, reference, cpf, phone, status, message, created_at) VALUES (:campaign, :reference, :cpf, :phone, :status, :message, :created_at)'
        );

        $stmt->execute([
            ':campaign' => $campaign,
            ':reference' => $reference,
            ':cpf' => $cpf,
            ':phone' => $phone,
            ':status' => $status,
            ':message' => $message !== null ? mb_substr($message, 0, 500) : null,
            ':created_at' => now(),
        ]);
    }

    public function recent(string $campaign, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM campaign_send_logs WHERE campaign = :campaign ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':campaign', $campaign, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'decode'], $rows);
    }

    private function decode(array $row): array
    {
        foreach (['id', 'created_at'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int)$row[$key];
            }
        }

        return $row;
    }
}
