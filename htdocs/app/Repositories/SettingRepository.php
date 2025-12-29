<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class SettingRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return $default;
        }

        return $this->decodeValue((string)$value);
    }

    public function set(string $key, mixed $value): void
    {
        $encoded = $this->encodeValue($value);
        $timestamp = now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value, created_at, updated_at)
             VALUES (:key, :value, :created_at, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':key' => $key,
            ':value' => $encoded,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        $result = [];
        foreach ($keys as $key => $default) {
            if (is_int($key)) {
                $result[$default] = $this->get((string)$default);
            } else {
                $result[$key] = $this->get((string)$key, $default);
            }
        }
        return $result;
    }

    private function encodeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function decodeValue(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $jsonDecoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $jsonDecoded;
        }

        return $value;
    }
}
