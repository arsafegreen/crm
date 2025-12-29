<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ChatExternalLeadRepository
{
    private PDO $pdo;
    private bool $schemaChecked = false;

    public function __construct()
    {
        $this->pdo = Connection::instance();
        $this->ensureSchema();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $timestamp = now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_external_leads (
                thread_id,
                full_name,
                ddd,
                phone,
                normalized_phone,
                message,
                source,
                status,
                ip_address,
                user_agent,
                claimed_by,
                claimed_at,
                closed_by,
                closed_at,
                public_token,
                created_at,
                updated_at
            ) VALUES (
                :thread_id,
                :full_name,
                :ddd,
                :phone,
                :normalized_phone,
                :message,
                :source,
                :status,
                :ip_address,
                :user_agent,
                :claimed_by,
                :claimed_at,
                :closed_by,
                :closed_at,
                :public_token,
                :created_at,
                :updated_at
            )'
        );

        $stmt->execute([
            ':thread_id' => (int)($payload['thread_id'] ?? 0),
            ':full_name' => (string)($payload['full_name'] ?? ''),
            ':ddd' => (string)($payload['ddd'] ?? ''),
            ':phone' => (string)($payload['phone'] ?? ''),
            ':normalized_phone' => (string)($payload['normalized_phone'] ?? ''),
            ':message' => (string)($payload['message'] ?? ''),
            ':source' => (string)($payload['source'] ?? ''),
            ':status' => (string)($payload['status'] ?? 'pending'),
            ':ip_address' => (string)($payload['ip_address'] ?? ''),
            ':user_agent' => (string)($payload['user_agent'] ?? ''),
            ':claimed_by' => $payload['claimed_by'] ?? null,
            ':claimed_at' => $payload['claimed_at'] ?? null,
            ':closed_by' => $payload['closed_by'] ?? null,
            ':closed_at' => $payload['closed_at'] ?? null,
            ':public_token' => $payload['public_token'] ?? null,
            ':created_at' => $payload['created_at'] ?? $timestamp,
            ':updated_at' => $payload['updated_at'] ?? $timestamp,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = $payload['updated_at'] ?? now();

        $assignments = [];
        $params = [':id' => $id];

        foreach ($payload as $field => $value) {
            $assignments[] = sprintf('%s = :%s', $field, $field);
            $params[':' . $field] = $value;
        }

        $sql = 'UPDATE chat_external_leads SET ' . implode(', ', $assignments) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByThread(int $threadId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chat_external_leads WHERE thread_id = :thread_id LIMIT 1');
        $stmt->execute([':thread_id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castLead($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chat_external_leads WHERE public_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castLead($row);
    }

    /**
     * @param int[] $threadIds
     * @return array<int, array<string, mixed>>
     */
    public function mapByThreadIds(array $threadIds): array
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds), static fn(int $id): bool => $id > 0));
        if ($threadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM chat_external_leads WHERE thread_id IN (' . $placeholders . ')');
        foreach ($threadIds as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $lead = $this->castLead($row);
            $map[(int)$lead['thread_id']] = $lead;
        }

        return $map;
    }

    public function claim(int $leadId, int $userId): void
    {
        $now = now();
        $stmt = $this->pdo->prepare('UPDATE chat_external_leads SET claimed_by = :user_id, claimed_at = :claimed_at, status = :status, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':user_id' => $userId,
            ':claimed_at' => $now,
            ':status' => 'assigned',
            ':updated' => $now,
            ':id' => $leadId,
        ]);
    }

    public function closeLead(int $leadId, int $userId): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'UPDATE chat_external_leads
             SET status = :status,
                 closed_by = :closed_by,
                 closed_at = :closed_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'closed',
            ':closed_by' => $userId,
            ':closed_at' => $now,
            ':updated_at' => $now,
            ':id' => $leadId,
        ]);
    }

    public function reopenLead(int $leadId): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'UPDATE chat_external_leads
             SET status = :status,
                 claimed_by = NULL,
                 claimed_at = NULL,
                 closed_by = NULL,
                 closed_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':updated_at' => $now,
            ':id' => $leadId,
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->schemaChecked = true;

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_external_leads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                full_name TEXT NOT NULL,
                ddd TEXT NOT NULL,
                phone TEXT NOT NULL,
                normalized_phone TEXT NOT NULL,
                message TEXT NOT NULL,
                source TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                claimed_by INTEGER NULL,
                claimed_at INTEGER NULL,
                closed_by INTEGER NULL,
                closed_at INTEGER NULL,
                public_token TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $this->ensureColumnExists('chat_external_leads', 'public_token', 'TEXT NULL');
        $this->ensureColumnExists('chat_external_leads', 'closed_by', 'INTEGER NULL');
        $this->ensureColumnExists('chat_external_leads', 'closed_at', 'INTEGER NULL');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_external_leads_status ON chat_external_leads(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_external_leads_thread ON chat_external_leads(thread_id)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_external_leads_token ON chat_external_leads(public_token)');
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($columns as $col) {
            if (strcasecmp((string)$col['name'], $column) === 0) {
                return;
            }
        }

        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castLead(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['thread_id'] = (int)($row['thread_id'] ?? 0);
        $row['claimed_by'] = isset($row['claimed_by']) ? (int)$row['claimed_by'] : null;
        $row['claimed_at'] = isset($row['claimed_at']) ? (int)$row['claimed_at'] : null;
        $row['closed_by'] = isset($row['closed_by']) ? (int)$row['closed_by'] : null;
        $row['closed_at'] = isset($row['closed_at']) ? (int)$row['closed_at'] : null;
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['updated_at'] = (int)($row['updated_at'] ?? 0);

        return $row;
    }
}
