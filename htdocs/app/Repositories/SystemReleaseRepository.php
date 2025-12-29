<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class SystemReleaseRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM system_releases ORDER BY created_at DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM system_releases WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByVersion(string $version): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM system_releases WHERE version = :version LIMIT 1');
        $stmt->execute([':version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        $timestamp = now();
        $data['created_at'] = $data['created_at'] ?? $timestamp;
        $data['updated_at'] = $data['updated_at'] ?? $timestamp;

        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO system_releases (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefixKeys($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = now();

        $assignments = [];
        foreach ($data as $column => $value) {
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $data['id'] = $id;
        $sql = sprintf('UPDATE system_releases SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefixKeys($data));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM system_releases WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array $log {exit_code:int, stdout:string, stderr:string}
     */
    public function recordApplication(int $id, array $log, string $status, ?int $userId): void
    {
        $this->update($id, [
            'status' => $status,
            'applied_at' => now(),
            'applied_by' => $userId,
            'applied_exit_code' => $log['exit_code'] ?? null,
            'applied_stdout' => $log['stdout'] ?? null,
            'applied_stderr' => $log['stderr'] ?? null,
        ]);
    }

    private function prefixKeys(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }
}
