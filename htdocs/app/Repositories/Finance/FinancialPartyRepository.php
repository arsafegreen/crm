<?php

declare(strict_types=1);

namespace App\Repositories\Finance;

use App\Database\Connection;
use PDO;

final class FinancialPartyRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_parties WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findByDocument(?string $document): ?array
    {
        if (!$document) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM financial_parties WHERE document = :document LIMIT 1');
        $stmt->execute([':document' => $document]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findByClientId(int $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_parties WHERE client_id = :client_id LIMIT 1');
        $stmt->execute([':client_id' => $clientId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function create(array $payload): int
    {
        $timestamp = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO financial_parties (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function upsertFromClient(array $client): int
    {
        if (!isset($client['id'])) {
            throw new \InvalidArgumentException('client id is required');
        }

        $existing = $this->findByClientId((int)$client['id']);
        $payload = [
            'name' => $client['full_name'] ?? $client['company'] ?? ($client['document'] ?? 'Cliente'),
            'document' => $client['document'] ?? null,
            'document_type' => strlen((string)($client['document'] ?? '')) === 14 ? 'cnpj' : 'cpf',
            'kind' => 'client',
            'email' => $client['email'] ?? null,
            'phone' => $client['phone'] ?? null,
            'client_id' => $client['id'],
        ];

        if ($existing !== null) {
            $this->update((int)$existing['id'], $payload);
            return (int)$existing['id'];
        }

        return $this->create($payload);
    }

    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $id;

        $assignments = [];
        foreach ($payload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE financial_parties SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    private function prefix(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }
}
