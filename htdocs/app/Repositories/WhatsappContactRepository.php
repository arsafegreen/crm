<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use PDOException;

final class WhatsappContactRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_contacts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByPhone(string $phone): ?array
    {
        $normalized = $this->normalizePhone($phone);
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_contacts WHERE phone = :phone LIMIT 1');
        $stmt->execute([':phone' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listByClientId(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_contacts WHERE client_id = :client ORDER BY updated_at DESC');
        $stmt->execute([':client' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    /**
     * @param array<int,string> $phones
     * @return array<int, array<string,mixed>>
     */
    public function listByPhones(array $phones): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(fn($p) => $this->normalizePhone((string)$p), $phones), fn($p) => $p !== '')));
        if ($normalized === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_contacts WHERE phone IN (' . $placeholders . ') ORDER BY updated_at DESC');
        foreach ($normalized as $i => $phone) {
            $stmt->bindValue($i + 1, $phone);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    /**
     * Lista contatos com snapshot potencialmente obsoleto para refresh de foto.
     * Critério: metadata faltando gateway_snapshot_at ou last_interaction_at muito antigo.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listForSnapshotRefresh(int $thresholdTimestamp, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $thresholdTimestamp = max(0, $thresholdTimestamp);

        $sql =
            'SELECT * FROM whatsapp_contacts
             WHERE (metadata IS NULL OR metadata NOT LIKE "%gateway_snapshot_at%" OR metadata = "")
                OR COALESCE(last_interaction_at, updated_at, created_at, 0) < :threshold
             ORDER BY COALESCE(last_interaction_at, updated_at, created_at, 0) DESC
             LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $thresholdTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function findOrCreate(string $phone, array $attributes = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $existing = $this->findByPhone($normalizedPhone);
        if ($existing !== null) {
            return $existing;
        }

        $rawName = trim((string)($attributes['name'] ?? ''));
        $payload = [
            'client_id' => $attributes['client_id'] ?? null,
            'name' => $rawName !== '' ? $rawName : 'Contato WhatsApp',
            'phone' => $normalizedPhone,
            'tags' => $attributes['tags'] ?? null,
            'preferred_language' => $attributes['preferred_language'] ?? 'pt-BR',
            'last_interaction_at' => $attributes['last_interaction_at'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO whatsapp_contacts (client_id, name, phone, tags, preferred_language, last_interaction_at, metadata, created_at, updated_at)
                 VALUES (:client_id, :name, :phone, :tags, :preferred_language, :last_interaction_at, :metadata, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':client_id' => $payload['client_id'],
                ':name' => $payload['name'],
                ':phone' => $payload['phone'],
                ':tags' => $payload['tags'],
                ':preferred_language' => $payload['preferred_language'],
                ':last_interaction_at' => $payload['last_interaction_at'],
                ':metadata' => $payload['metadata'],
                ':created_at' => $payload['created_at'],
                ':updated_at' => $payload['updated_at'],
            ]);
        } catch (PDOException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $existing = $this->findByPhone($normalizedPhone);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }

        $payload['id'] = (int)$this->pdo->lastInsertId();
        return $payload;
    }

    public function touchInteraction(int $contactId, ?int $timestamp = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE whatsapp_contacts SET last_interaction_at = :last_interaction_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':last_interaction_at' => $timestamp ?? now(),
            ':updated_at' => now(),
            ':id' => $contactId,
        ]);
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = [':id' => $id];
        foreach ($data as $key => $value) {
            $fields[] = sprintf('%s = :%s', $key, $key);
            $params[':' . $key] = $value;
        }

        $fields[] = 'updated_at = :updated_at';
        $params[':updated_at'] = now();

        $sql = 'UPDATE whatsapp_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        if ((string)$exception->getCode() === '23000') {
            return true;
        }

        $message = $exception->getMessage();
        return stripos($message, 'UNIQUE constraint failed') !== false
            || stripos($message, 'unique constraint failed') !== false
            || stripos($message, 'duplicate') !== false;
    }

    private function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'group:')) {
            $suffix = substr($trimmed, 6);
            $sanitized = preg_replace('/[^a-zA-Z0-9]+/', '', (string)$suffix) ?? '';
            if ($sanitized === '') {
                $sanitized = substr(bin2hex(random_bytes(6)), 0, 12);
            }
            return 'group:' . $sanitized;
        }

        return preg_replace('/\D+/', '', $trimmed) ?? $trimmed;
    }

    public function findByName(string $name): ?array
    {
        $normalized = trim(mb_strtolower($name));
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_contacts WHERE LOWER(name) = :name LIMIT 1');
        $stmt->execute([':name' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
