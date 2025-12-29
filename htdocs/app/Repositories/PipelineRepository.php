<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class PipelineRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function findStageByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pipeline_stages WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findStage(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pipeline_stages WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function allStages(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM pipeline_stages ORDER BY pipeline_id, position ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }
}
