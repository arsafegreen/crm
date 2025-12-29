<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class SocialAccountRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM social_accounts ORDER BY platform, label');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return array_map(static function (array $row): array {
            if (isset($row['expires_at']) && $row['expires_at'] !== null) {
                $row['expires_at_iso'] = date('Y-m-d\TH:i', (int)$row['expires_at']);
            } else {
                $row['expires_at_iso'] = null;
            }
            return $row;
        }, $rows);
    }

    public function insert(array $data): void
    {
        $sql = 'INSERT INTO social_accounts (platform, label, token, external_id, expires_at, created_at, updated_at)
                VALUES (:platform, :label, :token, :external_id, :expires_at, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);

        $now = time();

        $stmt->execute([
            ':platform' => $data['platform'],
            ':label' => $data['label'],
            ':token' => $data['token'],
            ':external_id' => $data['external_id'],
            ':expires_at' => $data['expires_at'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}
