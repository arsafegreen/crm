<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;

final class AdminNotificationRepository
{
    public function create(string $title, string $body = '', string $severity = 'info'): bool
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return false;
        }

        $severity = $this->normalizeSeverity($severity);
        $id = $this->uuid();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);

        $stmt = $pdo->prepare(
            'INSERT INTO admin_notifications (id, title, body, severity, status, created_at) VALUES (:id, :title, :body, :severity, :status, :created_at)'
        );

        return $stmt->execute([
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'severity' => $severity,
            'status' => 'unread',
            'created_at' => $now,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listUnread(int $limit = 20): array
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare('SELECT * FROM admin_notifications WHERE status = :status ORDER BY severity DESC, created_at DESC LIMIT :limit');
        $stmt->bindValue(':status', 'unread', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return $rows !== false ? $rows : [];
    }

    public function markRead(array $ids): int
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO || $ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE admin_notifications SET status = "read", read_at = :read_at WHERE id IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':read_at', (new DateTimeImmutable())->format(DATE_ATOM));
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, (string)$id, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, ['info', 'warning', 'urgent'], true) ? $severity : 'info';
    }

    private function uuid(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return uniqid('', true);
        }
    }
}
