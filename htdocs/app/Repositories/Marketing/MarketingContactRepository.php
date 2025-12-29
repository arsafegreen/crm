<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use PDO;

final class MarketingContactRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $this->normalizeEmail($email)]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findByPreferencesToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE preferences_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function create(array $payload): int
    {
        $timestamp = now();
        $payload['email'] = $this->normalizeEmail($payload['email'] ?? '');
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamp;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO marketing_contacts (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        if (isset($payload['email'])) {
            $payload['email'] = $this->normalizeEmail((string)$payload['email']);
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

        $sql = sprintf('UPDATE marketing_contacts SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function ensurePreferencesToken(int $id, ?string $currentToken = null): string
    {
        $token = $currentToken;
        if ($token === null || $token === '') {
            $token = bin2hex(random_bytes(24));
            $stmt = $this->pdo->prepare(
                'UPDATE marketing_contacts
                 SET preferences_token = :token,
                     preferences_token_generated_at = :generated_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );

            $stmt->execute([
                ':token' => $token,
                ':generated_at' => now(),
                ':updated_at' => now(),
                ':id' => $id,
            ]);
        }

        return $token;
    }

    public function recordConsent(int $contactId, string $status, ?string $source = null, ?int $timestamp = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE marketing_contacts
             SET consent_status = :status,
                 consent_source = COALESCE(:source, consent_source),
                 consent_at = COALESCE(:timestamp, consent_at),
                 updated_at = :updated_at,
                 status = CASE WHEN :status = "opted_out" THEN "inactive" ELSE status END
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => $status,
            ':source' => $source,
            ':timestamp' => $timestamp ?? now(),
            ':updated_at' => now(),
            ':id' => $contactId,
        ]);
    }

    public function markOptOut(int $contactId, ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE marketing_contacts
             SET status = "inactive",
                 consent_status = "opted_out",
                 opt_out_at = :timestamp,
                 suppression_reason = COALESCE(:reason, suppression_reason),
                 updated_at = :timestamp
             WHERE id = :id'
        );

        $stmt->execute([
            ':timestamp' => now(),
            ':reason' => $reason,
            ':id' => $contactId,
        ]);
    }

    public function incrementBounce(int $contactId, bool $complaint = false): void
    {
        $sql = 'UPDATE marketing_contacts
                SET bounce_count = bounce_count + 1,
                    complaint_count = complaint_count + :complaint,
                    status = CASE WHEN bounce_count + 1 >= 5 THEN "inactive" ELSE status END,
                    updated_at = :timestamp
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':complaint' => $complaint ? 1 : 0,
            ':timestamp' => now(),
            ':id' => $contactId,
        ]);
    }

    public function restoreSuppression(int $contactId): void
    {
        $sql = 'UPDATE marketing_contacts
                SET status = "active",
                    consent_status = COALESCE(NULLIF(consent_status, "blocked"), "pending"),
                    suppression_reason = NULL,
                    opt_out_at = NULL,
                    updated_at = :timestamp
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':timestamp' => now(),
            ':id' => $contactId,
        ]);
    }

    /**
     * @return array{total:int,items:array<int,array{id:int,email:string,suppression_reason:?string,updated_at:?int}}>
     */
    public function listSuppressed(string $query = '', int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = 'WHERE status = "inactive" OR consent_status IN ("opted_out", "blocked") OR suppression_reason IS NOT NULL OR bounce_count >= 3';
        $params = [];
        if (trim($query) !== '') {
            $where .= ' AND email LIKE :needle';
            $params[':needle'] = '%' . mb_strtolower(trim($query)) . '%';
        }

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM marketing_contacts ' . $where);
        $countStmt->execute($params);
        $total = (int)($countStmt->fetchColumn() ?: 0);

        $sql = 'SELECT id, email, suppression_reason, updated_at
                FROM marketing_contacts ' . $where . '
                ORDER BY updated_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'email' => $email,
                'suppression_reason' => $row['suppression_reason'] ?? null,
                'updated_at' => isset($row['updated_at']) ? (int)$row['updated_at'] : null,
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @return array<int, array{id:int,email:string}>
     */
    public function searchByEmailFragment(string $query, int $limit = 15): array
    {
        $needle = trim(mb_strtolower($query));
        if ($needle === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT id, email
             FROM marketing_contacts
             WHERE email LIKE :needle
             ORDER BY email ASC
             LIMIT :limit'
        );

        $stmt->bindValue(':needle', '%' . $needle . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter(array_map(static function (array $row): ?array {
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
            return [
                'id' => (int)($row['id'] ?? 0),
                'email' => strtolower($email),
            ];
        }, $rows)));
    }

    /**
     * @return array{soft:int, hard:int}
     */
    public function bounceTotals(): array
    {
        $sql = 'SELECT
                    SUM(CASE WHEN bounce_count BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS soft,
                    SUM(CASE WHEN bounce_count >= 3 OR consent_status IN ("opted_out", "blocked") OR suppression_reason IS NOT NULL THEN 1 ELSE 0 END) AS hard
                FROM marketing_contacts';

        $stmt = $this->pdo->query($sql);
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        return [
            'soft' => isset($row['soft']) ? (int)$row['soft'] : 0,
            'hard' => isset($row['hard']) ? (int)$row['hard'] : 0,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return trim(mb_strtolower($email));
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
