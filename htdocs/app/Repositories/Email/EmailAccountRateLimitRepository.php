<?php

declare(strict_types=1);

namespace App\Repositories\Email;

use App\Database\Connection;
use PDO;

final class EmailAccountRateLimitRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function find(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_rate_limits WHERE account_id = :account_id LIMIT 1');
        $stmt->execute([':account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function upsert(int $accountId, array $payload): void
    {
        $existing = $this->find($accountId);
        $timestamp = time();

        if ($existing === null) {
            $data = [
                'account_id' => $accountId,
                'window_start' => $payload['window_start'] ?? $timestamp,
                'hourly_sent' => $payload['hourly_sent'] ?? 0,
                'daily_sent' => $payload['daily_sent'] ?? 0,
                'last_reset_at' => $payload['last_reset_at'] ?? $timestamp,
                'metadata' => $payload['metadata'] ?? null,
            ];

            $columns = implode(', ', array_keys($data));
            $values = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));
            $stmt = $this->pdo->prepare("INSERT INTO email_rate_limits ({$columns}) VALUES ({$values})");
            $stmt->execute($this->prefix($data));
        } else {
            $data = $payload;
            $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), array_keys($data)));
            $data['account_id'] = $accountId;
            $stmt = $this->pdo->prepare("UPDATE email_rate_limits SET {$assignments} WHERE account_id = :account_id");
            $stmt->execute($this->prefix($data));
        }
    }

    public function increment(int $accountId, int $hourlyDelta, int $dailyDelta): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_rate_limits
             SET hourly_sent = hourly_sent + :hourly,
                 daily_sent = daily_sent + :daily
             WHERE account_id = :account_id'
        );
        $stmt->execute([
            ':hourly' => $hourlyDelta,
            ':daily' => $dailyDelta,
            ':account_id' => $accountId,
        ]);
    }

    public function resetWindow(int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_rate_limits
             SET window_start = :window_start,
                 hourly_sent = 0,
                 daily_sent = 0
             WHERE account_id = :account_id'
        );
        $stmt->execute([
            ':window_start' => time(),
            ':account_id' => $accountId,
        ]);
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
