<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CopilotProfileRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM copilot_profiles ORDER BY is_default DESC, name ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM copilot_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findDefault(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM copilot_profiles WHERE is_default = 1 ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $payload): int
    {
        $data = $this->preparePayload($payload);
        if ($data['is_default'] === 1) {
            $this->clearDefault();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO copilot_profiles (name, slug, description, objective, instructions, tone, temperature, default_queue, is_default, created_at, updated_at)
             VALUES (:name, :slug, :description, :objective, :instructions, :tone, :temperature, :default_queue, :is_default, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':description' => $data['description'],
            ':objective' => $data['objective'],
            ':instructions' => $data['instructions'],
            ':tone' => $data['tone'],
            ':temperature' => $data['temperature'],
            ':default_queue' => $data['default_queue'],
            ':is_default' => $data['is_default'],
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

        $data = $this->preparePayload($payload, false);
        if (array_key_exists('is_default', $data) && $data['is_default'] === 1) {
            $this->clearDefault();
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key === 'created_at') {
                continue;
            }
            $fields[] = sprintf('%s = :%s', $key, $key);
            $params[':' . $key] = $value;
        }

        if (!array_key_exists('updated_at', $data)) {
            $fields[] = 'updated_at = :updated_at';
            $params[':updated_at'] = now();
        }

        $stmt = $this->pdo->prepare('UPDATE copilot_profiles SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM copilot_profiles WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function preparePayload(array $payload, bool $isCreate = true): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Informe o nome do perfil IA.');
        }

        $slug = trim((string)($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        } else {
            $slug = $this->slugify($slug);
        }

        $instructions = trim((string)($payload['instructions'] ?? ''));
        if ($instructions === '') {
            $instructions = 'Responder com clareza.';
        }

        $toneValue = trim((string)($payload['tone'] ?? ''));
        if ($toneValue === '') {
            $toneValue = 'consultivo';
        }

        $temperature = is_numeric($payload['temperature'] ?? null) ? (float)$payload['temperature'] : 0.5;
        $temperature = max(0.0, min(1.0, $temperature));

        $data = [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string)($payload['description'] ?? '')),
            'objective' => trim((string)($payload['objective'] ?? '')),
            'instructions' => $instructions,
            'tone' => $toneValue,
            'temperature' => $temperature,
            'default_queue' => $this->normalizeQueue($payload['default_queue'] ?? null),
            'is_default' => !empty($payload['is_default']) ? 1 : 0,
            'updated_at' => now(),
        ];

        if ($isCreate) {
            $data['created_at'] = now();
        }

        return $data;
    }

    private function clearDefault(): void
    {
        $this->pdo->exec('UPDATE copilot_profiles SET is_default = 0');
    }

    private function slugify(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized);
        $normalized = trim((string)$normalized, '-');
        return $normalized !== '' ? $normalized : 'perfil-' . bin2hex(random_bytes(4));
    }

    private function normalizeQueue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $queue = strtolower(trim($value));
        if ($queue === '') {
            return null;
        }

        $allowed = ['arrival', 'scheduled', 'partner'];
        return in_array($queue, $allowed, true) ? $queue : null;
    }
}
