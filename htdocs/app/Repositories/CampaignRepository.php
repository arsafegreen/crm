<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use Throwable;

final class CampaignRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function createWithMessages(array $campaignData, array $messages): array
    {
        $timestamp = now();
        $campaignData['created_at'] = $campaignData['created_at'] ?? $timestamp;
        $campaignData['updated_at'] = $campaignData['updated_at'] ?? $timestamp;

        $this->pdo->beginTransaction();

        try {
            $campaignId = $this->insertCampaign($campaignData);

            if ($messages !== []) {
                $this->insertMessages($campaignId, $messages);
            }

            $this->pdo->commit();

            return [
                'campaign_id' => $campaignId,
                'messages_created' => count($messages),
            ];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function recent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, 
                (SELECT COUNT(*) FROM campaign_messages m WHERE m.campaign_id = c.id) AS messages_count,
                (SELECT COUNT(*) FROM campaign_messages m WHERE m.campaign_id = c.id AND m.status = "sent") AS sent_count,
                t.name AS template_name,
                tv.version AS template_version_number
             FROM campaigns c
             LEFT JOIN templates t ON t.id = c.template_id
             LEFT JOIN template_versions tv ON tv.id = c.template_version_id
             ORDER BY c.created_at DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['messages_count'] = isset($row['messages_count']) ? (int)$row['messages_count'] : 0;
            $row['sent_count'] = isset($row['sent_count']) ? (int)$row['sent_count'] : 0;
            $row['created_at'] = isset($row['created_at']) ? (int)$row['created_at'] : null;
            $row['updated_at'] = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            $row['scheduled_for'] = isset($row['scheduled_for']) ? (int)$row['scheduled_for'] : null;
            $row['template_version_id'] = isset($row['template_version_id']) ? (int)$row['template_version_id'] : null;
            $row['template_version_number'] = isset($row['template_version_number']) ? (int)$row['template_version_number'] : null;
            $row['filters'] = isset($row['filters']) ? json_decode((string)$row['filters'], true) ?? [] : [];
            return $row;
        }, $rows);
    }

    private function insertCampaign(array $data): int
    {
        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO campaigns ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixArrayKeys($data));

        return (int)$this->pdo->lastInsertId();
    }

    private function insertMessages(int $campaignId, array $messages): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO campaign_messages (
                campaign_id,
                template_version_id,
                client_id,
                certificate_id,
                channel,
                status,
                scheduled_for,
                payload,
                created_at,
                updated_at
            ) VALUES (
                :campaign_id,
                :template_version_id,
                :client_id,
                :certificate_id,
                :channel,
                :status,
                :scheduled_for,
                :payload,
                :created_at,
                :updated_at
            )'
        );

        foreach ($messages as $message) {
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':template_version_id' => $message['template_version_id'] ?? null,
                ':client_id' => $message['client_id'] ?? null,
                ':certificate_id' => $message['certificate_id'] ?? null,
                ':channel' => $message['channel'] ?? 'email',
                ':status' => $message['status'] ?? 'pending',
                ':scheduled_for' => $message['scheduled_for'] ?? null,
                ':payload' => $message['payload'] ?? null,
                ':created_at' => $message['created_at'] ?? now(),
                ':updated_at' => $message['updated_at'] ?? now(),
            ]);
        }
    }

    private function prefixArrayKeys(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }
}
