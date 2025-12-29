<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class AvpAccessRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    /**
     * @return array<int, array{id:int,label:string,normalized_name:string,avp_cpf:?string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, label, normalized_name, avp_cpf FROM user_avp_filters WHERE user_id = :user ORDER BY label ASC');
        $stmt->execute([':user' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'label' => (string)($row['label'] ?? ''),
                'normalized_name' => (string)($row['normalized_name'] ?? ''),
                'avp_cpf' => $row['avp_cpf'] !== null ? (string)$row['avp_cpf'] : null,
            ];
        }

        return $rows;
    }

    public function addAvp(int $userId, string $label, ?string $cpf = null, ?int $grantedBy = null): bool
    {
        $normalized = $this->normalizeName($label);
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_avp_filters (user_id, label, normalized_name, avp_cpf, granted_by)
             VALUES (:user, :label, :normalized, :cpf, :granted_by)
             ON CONFLICT(user_id, normalized_name) DO UPDATE SET label = excluded.label, avp_cpf = excluded.avp_cpf'
        );

        $stmt->execute([
            ':user' => $userId,
            ':label' => $label,
            ':normalized' => $normalized,
            ':cpf' => $cpf,
            ':granted_by' => $grantedBy,
        ]);

        return $this->pdo->lastInsertId() !== '0';
    }

    public function remove(int $userId, int $filterId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_avp_filters WHERE user_id = :user AND id = :id');
        $stmt->execute([
            ':user' => $userId,
            ':id' => $filterId,
        ]);
    }

    /**
     * @return string[]
     */
    public function allowedAvpNames(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT normalized_name FROM user_avp_filters WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<int,string> $names
     */
    public function addMany(int $userId, array $names, ?int $grantedBy = null): int
    {
        $inserted = 0;
        foreach ($names as $label) {
            $normalized = $this->normalizeName($label);
            if ($normalized === '') {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO user_avp_filters (user_id, label, normalized_name, granted_by)
                 VALUES (:user, :label, :normalized, :granted)
                 ON CONFLICT(user_id, normalized_name) DO NOTHING'
            );
            $stmt->execute([
                ':user' => $userId,
                ':label' => $label,
                ':normalized' => $normalized,
                ':granted' => $grantedBy,
            ]);

            if ($this->pdo->lastInsertId() !== '0') {
                $inserted++;
            }
        }

        return $inserted;
    }

    private function normalizeName(string $value): string
    {
        $trimmed = trim(mb_strtolower($value, 'UTF-8'));
        return $trimmed;
    }
}
