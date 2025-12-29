<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use InvalidArgumentException;

final class PartnerRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function findOrCreate(string $name, string $type = 'contador'): ?array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        $existing = $this->findByNormalizedName($name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create([
            'name' => $name,
            'type' => $type,
        ]);
    }

    public function findByDocument(string $document): ?array
    {
        $cleanDocument = digits_only($document);
        if ($cleanDocument === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE document = :document LIMIT 1');
        $stmt->execute([':document' => $cleanDocument]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByNormalizedName(string $name): ?array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE normalized_name = :normalized LIMIT 1');
        $stmt->execute([':normalized' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function searchByName(string $query, int $limit = 10, int $offset = 0): array
    {
        $normalized = $this->normalizeName($query);
        if ($normalized === '') {
            return ['items' => [], 'has_more' => false, 'total' => 0];
        }

        $limitValue = max(1, $limit);
        $offsetValue = max(0, $offset);
        $fetchLimit = $limitValue + 1;
        $needle = '%' . $normalized . '%';

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM partners WHERE normalized_name LIKE :needle'
        );
        $countStmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $countStmt->execute();
        $total = (int)($countStmt->fetchColumn() ?: 0);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, document, email, phone
             FROM partners
             WHERE normalized_name LIKE :needle
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offsetValue, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limitValue;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limitValue);
        }

        return ['items' => $rows, 'has_more' => $hasMore, 'total' => $total];
    }

    public function listAllLimited(int $limit): array
    {
        $limit = max(1, $limit);
        $fetchLimit = $limit + 1;

        $stmt = $this->pdo->prepare(
            'SELECT id, name, document, email, phone
             FROM partners
             ORDER BY name ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'items' => $rows,
            'has_more' => $hasMore,
        ];
    }

    public function listWithoutDocument(?string $name = null, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, name, normalized_name, document, email, phone, client_id FROM partners WHERE (document IS NULL OR TRIM(document) = "")';
        $params = [':limit' => $limit];

        $needle = trim((string)$name);
        if ($needle !== '') {
            $normalized = $this->normalizeName($needle);
            if ($normalized !== '') {
                $sql .= ' AND normalized_name LIKE :needle';
                $params[':needle'] = '%' . $normalized . '%';
            }
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':limit' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string[] $names
     * @return array<string, array{id:int,name:string,document:?string,email:?string,phone:?string}>
     */
    public function findByNames(array $names): array
    {
        $uniqueNames = [];
        foreach ($names as $name) {
            $clean = trim((string)$name);
            if ($clean === '') {
                continue;
            }
            $uniqueNames[$clean] = true;
        }

        if ($uniqueNames === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($uniqueNames), '?'));
        $sql = 'SELECT id, name, document, email, phone FROM partners WHERE name IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_keys($uniqueNames));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $this->nameKey($row['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = $row;
        }

        return $map;
    }

    public function listAll(?int $limit = null): array
    {
        $sql = 'SELECT id, name, document, email, phone FROM partners ORDER BY name ASC';
        $params = [];

        if ($limit !== null) {
            $limit = max(1, $limit);
            $sql .= ' LIMIT :limit';
            $params[':limit'] = $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listAllPaginated(int $limit, int $offset = 0): array
    {
        $limitValue = max(1, $limit);
        $offsetValue = max(0, $offset);
        $fetchLimit = $limitValue + 1;

        $countStmt = $this->pdo->query('SELECT COUNT(*) FROM partners');
        $total = (int)($countStmt->fetchColumn() ?: 0);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, document, email, phone
             FROM partners
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offsetValue, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limitValue;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limitValue);
        }

        return [
            'items' => $rows,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    public function listPendingClientSync(?string $name = null, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, name, normalized_name, document, client_id FROM partners WHERE client_id IS NULL';
        $params = [':limit' => $limit];

        $needle = trim((string)$name);
        if ($needle !== '') {
            $normalized = $this->normalizeName($needle);
            if ($normalized !== '') {
                $sql .= ' AND normalized_name LIKE :needle';
                $params[':needle'] = '%' . $normalized . '%';
            }
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':limit' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByClientId(int $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE client_id = :client_id LIMIT 1');
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Nome do parceiro é obrigatório.');
        }

        $existing = $this->findByNormalizedName($name);
        if ($existing !== null) {
            $this->update((int)$existing['id'], $data);
            return $this->find((int)$existing['id']);
        }

        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            throw new InvalidArgumentException('Nome do parceiro é inválido.');
        }

        $document = digits_only($data['document'] ?? '');
        $document = $document !== '' ? $document : null;

        $sourceDocument = digits_only($data['source_document'] ?? '') ?: $document;

        $type = trim((string)($data['type'] ?? 'contador'));
        $type = $type !== '' ? $type : 'contador';

        $email = $this->sanitizeEmail($data['email'] ?? null);

        $phoneDigits = digits_only($data['phone'] ?? '');
        $phone = $phoneDigits !== '' ? $phoneDigits : null;

        $notes = trim((string)($data['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;

        $timestamp = now();
        $billingMode = $this->normalizeBillingMode($data['billing_mode'] ?? 'custo');

        $clientId = isset($data['client_id']) ? (int)$data['client_id'] : null;
        if ($clientId !== null && $clientId <= 0) {
            $clientId = null;
        }

        $stmt = $this->pdo->prepare('INSERT INTO partners (name, normalized_name, type, document, source_document, email, phone, notes, client_id, billing_mode, billing_mode_updated_at, created_at, updated_at) VALUES (:name, :normalized, :type, :document, :source_document, :email, :phone, :notes, :client_id, :billing_mode, :billing_mode_updated_at, :created_at, :updated_at)');
        $stmt->execute([
            ':name' => $name,
            ':normalized' => $normalized,
            ':type' => $type,
            ':document' => $document,
            ':source_document' => $sourceDocument,
            ':email' => $email,
            ':phone' => $phone,
            ':notes' => $notes,
            ':client_id' => $clientId,
            ':billing_mode' => $billingMode,
            ':billing_mode_updated_at' => $timestamp,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name !== '') {
                $payload['name'] = $name;
                $payload['normalized_name'] = $this->normalizeName($name);
            }
        }

        if (array_key_exists('type', $data)) {
            $type = trim((string)$data['type']);
            if ($type === '') {
                $type = 'contador';
            }
            $payload['type'] = $type;
        }

        if (array_key_exists('document', $data)) {
            $document = digits_only($data['document'] ?? '');
            $payload['document'] = $document !== '' ? $document : null;
        }

        if (array_key_exists('source_document', $data)) {
            $sourceDocument = digits_only($data['source_document'] ?? '');
            $payload['source_document'] = $sourceDocument !== '' ? $sourceDocument : ($payload['document'] ?? null);
        }

        if (array_key_exists('email', $data)) {
            $payload['email'] = $this->sanitizeEmail($data['email'] ?? null);
        }

        if (array_key_exists('phone', $data)) {
            $phoneDigits = digits_only($data['phone'] ?? '');
            $payload['phone'] = $phoneDigits !== '' ? $phoneDigits : null;
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string)$data['notes']);
            $payload['notes'] = $notes !== '' ? $notes : null;
        }

        if (array_key_exists('client_id', $data)) {
            $clientId = $data['client_id'];
            if ($clientId !== null) {
                $clientId = (int)$clientId;
                if ($clientId <= 0) {
                    $clientId = null;
                }
            }
            $payload['client_id'] = $clientId;
        }

        if (array_key_exists('billing_mode', $data)) {
            $payload['billing_mode'] = $this->normalizeBillingMode($data['billing_mode']);
            $payload['billing_mode_updated_at'] = now();
        }

        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $id;

        $assignments = [];
        foreach ($payload as $column => $_value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = 'UPDATE partners SET ' . implode(', ', $assignments) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $params = [];
        foreach ($payload as $column => $value) {
            $params[':' . $column] = $value;
        }
        $stmt->execute($params);
    }

    public function syncFromClient(array $client): array
    {
        $clientId = (int)($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Cliente inválido informado para vincular parceiro.');
        }

        $document = digits_only((string)($client['document'] ?? ''));
        $name = trim((string)($client['name'] ?? ''));

        if ($name === '' || $document === '') {
            throw new InvalidArgumentException('Cliente precisa ter nome e documento para virar parceiro.');
        }

        $payload = [
            'name' => $name,
            'document' => $document,
            'source_document' => $document,
            'type' => 'contador',
            'email' => $client['email'] ?? null,
            'phone' => $client['phone'] ?? null,
            'notes' => $client['notes'] ?? null,
            'client_id' => $clientId,
        ];

        $existing = $this->findByClientId($clientId)
            ?? $this->findByDocument($document)
            ?? $this->findByNormalizedName($name);

        if ($existing !== null) {
            $this->update((int)$existing['id'], $payload);
            return $this->find((int)$existing['id']);
        }

        return $this->create($payload);
    }

    private function normalizeBillingMode(mixed $value): string
    {
        $mode = strtolower(trim((string)$value));
        return in_array($mode, ['custo', 'comissao'], true) ? $mode : 'custo';
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $ascii = strtolower((string)$ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii ?? '');
        return trim((string)$ascii);
    }

    private function nameKey(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return mb_strtolower($value, 'UTF-8');
    }

    private function sanitizeEmail(mixed $value): ?string
    {
        $email = trim(strtolower((string)$value));
        $email = $email !== '' ? $email : null;
        if ($email === null) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
