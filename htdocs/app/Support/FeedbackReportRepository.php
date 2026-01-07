<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;

final class FeedbackReportRepository
{
    public function create(array $data): bool
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return false;
        }

        $payload = $this->normalize($data);
        $sql = 'INSERT INTO feedback_reports (id, feedback_id, reporter_user_id, target_user_id, target_cnpj, reason, details, status, created_at, updated_at)
                VALUES (:id, :feedback_id, :reporter_user_id, :target_user_id, :target_cnpj, :reason, :details, :status, :created_at, :updated_at)';

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($payload);
    }

    public function updateStatus(string $id, string $status): bool
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return false;
        }

        $status = $this->sanitizeStatus($status);
        $stmt = $pdo->prepare('UPDATE feedback_reports SET status = :status, updated_at = :updated_at WHERE id = :id');
        return $stmt->execute([
            'status' => $status,
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'id' => $id,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listOpen(int $limit = 50, int $offset = 0): array
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return [];
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $pdo->prepare(
            'SELECT id, feedback_id, reporter_user_id, target_user_id, target_cnpj, reason, details, status, created_at, updated_at
             FROM feedback_reports
             WHERE status IN (\'open\', \'under_review\')
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return $rows !== false ? $rows : [];
    }

    private function normalize(array $data): array
    {
        $now = new DateTimeImmutable();
        $id = $data['id'] ?? null;
        $id = is_string($id) && $id !== '' ? $id : $this->uuid();

        $reason = trim((string)($data['reason'] ?? ''));
        if (mb_strlen($reason) > 120) {
            $reason = mb_substr($reason, 0, 120);
        }

        $details = (string)($data['details'] ?? '');
        if (mb_strlen($details) > 2000) {
            $details = mb_substr($details, 0, 2000);
        }

        return [
            'id' => $id,
            'feedback_id' => $this->nullOrString($data['feedback_id'] ?? null),
            'reporter_user_id' => (string)($data['reporter_user_id'] ?? ''),
            'target_user_id' => $this->nullOrString($data['target_user_id'] ?? null),
            'target_cnpj' => $this->nullOrString($data['target_cnpj'] ?? null),
            'reason' => $reason === '' ? 'unspecified' : $reason,
            'details' => $details,
            'status' => $this->sanitizeStatus($data['status'] ?? 'open'),
            'created_at' => $this->toDateTime($data['created_at'] ?? null)?->format(DATE_ATOM) ?? $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
        ];
    }

    private function sanitizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['open', 'under_review', 'closed', 'rejected'];
        return in_array($status, $allowed, true) ? $status : 'open';
    }

    private function nullOrString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    private function toDateTime(null|string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
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
