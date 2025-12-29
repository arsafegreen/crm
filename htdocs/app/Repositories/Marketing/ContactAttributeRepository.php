<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class ContactAttributeRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    /**
     * @return array<string, string|null>
     */
    public function list(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT attribute_key, attribute_value FROM marketing_contact_attributes WHERE contact_id = :contact_id');
        $stmt->execute([':contact_id' => $contactId]);

        $attributes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string)($row['attribute_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $attributes[$key] = array_key_exists('attribute_value', $row) ? $row['attribute_value'] : null;
        }

        return $attributes;
    }

    public function upsert(int $contactId, string $key, ?string $value, ?string $valueType = null): void
    {
        $timestamp = now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO marketing_contact_attributes (contact_id, attribute_key, attribute_value, value_type, created_at, updated_at)
             VALUES (:contact_id, :attribute_key, :attribute_value, :value_type, :created_at, :updated_at)
             ON CONFLICT(contact_id, attribute_key) DO UPDATE SET
                attribute_value = excluded.attribute_value,
                value_type = excluded.value_type,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':contact_id' => $contactId,
            ':attribute_key' => $key,
            ':attribute_value' => $value,
            ':value_type' => $valueType,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);
    }

    /**
     * @param array<string, array{value: ?string, type?: ?string}> $attributes
     */
    public function upsertMany(int $contactId, array $attributes): void
    {
        foreach ($attributes as $key => $definition) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $value = $definition['value'] ?? null;
            $valueType = $definition['type'] ?? null;
            $this->upsert($contactId, $key, $value, $valueType);
        }
    }
}
