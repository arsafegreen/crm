<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ClientProtocolRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM client_protocols WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByProtocol(string $protocolNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM client_protocols WHERE protocol_number = :protocol LIMIT 1');
        $stmt->execute([':protocol' => $protocolNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function listByClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM client_protocols WHERE client_id = :client_id ORDER BY expires_at IS NULL, expires_at ASC'
        );
        $stmt->execute([':client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function insert(int $clientId, array $data): int
    {
        $now = now();
        $payload = array_merge([
            'client_id' => $clientId,
            'document' => (string)($data['document'] ?? ''),
            'protocol_number' => (string)($data['protocol_number'] ?? ''),
            'description' => $data['description'] ?? null,
            'starts_at' => $this->normalizeTimestamp($data['starts_at'] ?? null),
            'expires_at' => $this->normalizeTimestamp($data['expires_at'] ?? null),
            'status' => $data['status'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fields = array_keys($payload);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO client_protocols ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixArrayKeys($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $payload = $data;
        $payload['updated_at'] = now();

        if (array_key_exists('starts_at', $payload)) {
            $payload['starts_at'] = $this->normalizeTimestamp($payload['starts_at']);
        }

        if (array_key_exists('expires_at', $payload)) {
            $payload['expires_at'] = $this->normalizeTimestamp($payload['expires_at']);
        }

        $fields = array_keys($payload);
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), $fields));

        $payload['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE client_protocols SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefixArrayKeys($payload));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM client_protocols WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function existsProtocol(string $protocolNumber, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM client_protocols WHERE protocol_number = :protocol';
        $params = [':protocol' => $protocolNumber];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :ignore_id';
            $params[':ignore_id'] = $ignoreId;
        }

        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    private function normalizeTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);
            return $timestamp !== false ? $timestamp : null;
        }

        return null;
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
