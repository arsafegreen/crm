<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class WhatsappLineRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('whatsapp');
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM whatsapp_lines ORDER BY is_default DESC, label ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_lines WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByLabel(string $label): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_lines WHERE label = :label LIMIT 1');
        $stmt->execute([':label' => $label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_lines WHERE LOWER(label) = LOWER(:label) LIMIT 1');
        $stmt->execute([':label' => $label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByPhoneNumberId(string $phoneNumberId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_lines WHERE phone_number_id = :phone LIMIT 1');
        $stmt->execute([':phone' => $phoneNumberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
    
    public function findDefault(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM whatsapp_lines ORDER BY is_default DESC, id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $payload): int
    {
        $data = [
            'label' => (string)$payload['label'],
            'phone_number_id' => (string)$payload['phone_number_id'],
            'display_phone' => (string)$payload['display_phone'],
            'business_account_id' => (string)$payload['business_account_id'],
            'access_token' => (string)$payload['access_token'],
            'verify_token' => $payload['verify_token'] ?? null,
            'provider' => $payload['provider'] ?? 'meta',
            'api_base_url' => $payload['api_base_url'] ?? null,
            'is_default' => !empty($payload['is_default']) ? 1 : 0,
            'status' => $payload['status'] ?? 'active',
            'rate_limit_enabled' => !empty($payload['rate_limit_enabled']) ? 1 : 0,
            'rate_limit_window_seconds' => (int)($payload['rate_limit_window_seconds'] ?? 3600),
            'rate_limit_max_messages' => (int)($payload['rate_limit_max_messages'] ?? 500),
            'alt_gateway_instance' => $payload['alt_gateway_instance'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($data['is_default'] === 1) {
            $this->clearDefault();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_lines (label, phone_number_id, display_phone, business_account_id, access_token, verify_token, provider, api_base_url, is_default, status, rate_limit_enabled, rate_limit_window_seconds, rate_limit_max_messages, alt_gateway_instance, created_at, updated_at)
             VALUES (:label, :phone_number_id, :display_phone, :business_account_id, :access_token, :verify_token, :provider, :api_base_url, :is_default, :status, :rate_limit_enabled, :rate_limit_window_seconds, :rate_limit_max_messages, :alt_gateway_instance, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':label' => $data['label'],
            ':phone_number_id' => $data['phone_number_id'],
            ':display_phone' => $data['display_phone'],
            ':business_account_id' => $data['business_account_id'],
            ':access_token' => $data['access_token'],
            ':verify_token' => $data['verify_token'],
            ':provider' => $data['provider'],
            ':api_base_url' => $data['api_base_url'],
            ':is_default' => $data['is_default'],
            ':status' => $data['status'],
            ':rate_limit_enabled' => $data['rate_limit_enabled'],
            ':rate_limit_window_seconds' => $data['rate_limit_window_seconds'],
            ':rate_limit_max_messages' => $data['rate_limit_max_messages'],
            ':alt_gateway_instance' => $data['alt_gateway_instance'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $fields = [];
        $params = [':id' => $id];
        foreach ($payload as $key => $value) {
            if ($key === 'is_default' && $value) {
                $this->clearDefault();
            }
            $fields[] = sprintf('%s = :%s', $key, $key);
            $params[':' . $key] = $value;
        }
        $fields[] = 'updated_at = :updated_at';
        $params[':updated_at'] = now();

        $stmt = $this->pdo->prepare('UPDATE whatsapp_lines SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whatsapp_lines WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function clearDefault(): void
    {
        $this->pdo->exec('UPDATE whatsapp_lines SET is_default = 0');
    }
}
