<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CampaignMessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cm.*, c.name AS campaign_name, c.template_version_id AS campaign_template_version_id,
                    tv.version AS template_version_number, cl.name AS client_name, cl.email AS client_email
             FROM campaign_messages cm
             LEFT JOIN campaigns c ON c.id = cm.campaign_id
             LEFT JOIN clients cl ON cl.id = cm.client_id
             LEFT JOIN template_versions tv ON tv.id = cm.template_version_id
             ORDER BY cm.created_at DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['campaign_id'] = isset($row['campaign_id']) ? (int)$row['campaign_id'] : null;
            $row['template_version_id'] = isset($row['template_version_id']) ? (int)$row['template_version_id'] : null;
            $row['template_version_number'] = isset($row['template_version_number']) ? (int)$row['template_version_number'] : null;
            $row['created_at'] = isset($row['created_at']) ? (int)$row['created_at'] : null;
            $row['updated_at'] = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            $row['scheduled_for'] = isset($row['scheduled_for']) ? (int)$row['scheduled_for'] : null;
            $row['sent_at'] = isset($row['sent_at']) ? (int)$row['sent_at'] : null;
            return $row;
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM campaign_messages
             WHERE status = "pending"
             ORDER BY
                 CASE WHEN scheduled_for IS NULL THEN 1 ELSE 0 END,
                 COALESCE(scheduled_for, created_at) ASC,
                 id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function markQueued(int $id): void
    {
        $this->performUpdate($id, [
            'status' => 'queued',
            'error_message' => null,
        ]);
    }

    public function markProcessing(int $id): void
    {
        $this->performUpdate($id, [
            'status' => 'sending',
            'error_message' => null,
        ]);
    }

    public function markSent(int $id, ?int $sentAt = null): void
    {
        $this->performUpdate($id, [
            'status' => 'sent',
            'sent_at' => $sentAt ?? now(),
            'error_message' => null,
        ]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->performUpdate($id, [
            'status' => 'failed',
            'error_message' => $message,
        ]);
    }

    private function performUpdate(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = now();

        $columns = [];
        foreach ($data as $column => $value) {
            $columns[] = sprintf('%s = :%s', $column, $column);
        }

        $data['id'] = $id;

        $sql = sprintf('UPDATE campaign_messages SET %s WHERE id = :id', implode(', ', $columns));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefixParameters($data));
    }

    private function prefixParameters(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }

        return $prefixed;
    }
}
