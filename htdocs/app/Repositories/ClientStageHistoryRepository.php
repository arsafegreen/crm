<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ClientStageHistoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function record(int $clientId, ?int $fromStageId, ?int $toStageId, ?string $notes = null, ?string $changedBy = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO client_stage_history (client_id, from_stage_id, to_stage_id, changed_at, changed_by, notes)
             VALUES (:client_id, :from_stage_id, :to_stage_id, :changed_at, :changed_by, :notes)'
        );

        $stmt->execute([
            ':client_id' => $clientId,
            ':from_stage_id' => $fromStageId,
            ':to_stage_id' => $toStageId,
            ':changed_at' => now(),
            ':changed_by' => $changedBy,
            ':notes' => $notes,
        ]);
    }

    public function forClient(int $clientId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                h.*, 
                fs.name AS from_stage_name,
                ts.name AS to_stage_name
             FROM client_stage_history h
             LEFT JOIN pipeline_stages fs ON fs.id = h.from_stage_id
             LEFT JOIN pipeline_stages ts ON ts.id = h.to_stage_id
             WHERE h.client_id = :client_id
             ORDER BY h.changed_at DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }
}
