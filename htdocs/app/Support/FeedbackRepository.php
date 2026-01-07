<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;

final class FeedbackRepository
{
    public function upsert(array $data): bool
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return false;
        }

        $payload = $this->normalize($data);
        $sql = <<<SQL
        INSERT INTO feedbacks (
            id, rater_user_id, target_user_id, target_cnpj, deal_id,
            score, body, visibility_score, visibility_body, status,
            allow_reply, request_id, created_at, updated_at
        ) VALUES (
            :id, :rater_user_id, :target_user_id, :target_cnpj, :deal_id,
            :score, :body, :visibility_score, :visibility_body, :status,
            :allow_reply, :request_id, :created_at, :updated_at
        )
        ON CONFLICT (rater_user_id, target_user_id, target_cnpj, deal_id)
        DO UPDATE SET
            score = EXCLUDED.score,
            body = EXCLUDED.body,
            visibility_score = EXCLUDED.visibility_score,
            visibility_body = EXCLUDED.visibility_body,
            status = EXCLUDED.status,
            allow_reply = EXCLUDED.allow_reply,
            request_id = EXCLUDED.request_id,
            updated_at = EXCLUDED.updated_at;
        SQL;

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($payload);
    }

    public function updateStatus(string $id, string $status): bool
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE feedbacks SET status = :status, updated_at = :updated_at WHERE id = :id');
        return $stmt->execute([
            'status' => $status,
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'id' => $id,
        ]);
    }

    public function aggregate(?string $targetUserId, ?string $targetCnpj): array
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return ['count_public' => 0, 'avg_public' => null, 'count_all' => 0, 'avg_all' => null];
        }

        $stmt = $pdo->prepare(
            'SELECT count_public, avg_public, count_all, avg_all
             FROM feedbacks_aggregate
             WHERE target_user_id = :target_user_id AND target_cnpj = :target_cnpj'
        );
        $stmt->execute([
            'target_user_id' => $targetUserId ?? '',
            'target_cnpj' => $targetCnpj ?? '',
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['count_public' => 0, 'avg_public' => null, 'count_all' => 0, 'avg_all' => null];
        }
        return $row;
    }

    /**
     * Retorna uma visão compacta para alertar notas negativas.
     * @return array{avg_public:?float,count_public:int,negative_alert:bool}
     */
    public function negativeSnapshot(?string $targetUserId, ?string $targetCnpj, float $threshold = 3.0, int $minCount = 3): array
    {
        $agg = $this->aggregate($targetUserId, $targetCnpj);
        $avg = isset($agg['avg_public']) ? (float)$agg['avg_public'] : null;
        $count = (int)($agg['count_public'] ?? 0);
        $alert = $avg !== null && $count >= $minCount && $avg < $threshold;

        return [
            'avg_public' => $avg,
            'count_public' => $count,
            'negative_alert' => $alert,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public function listPublic(?string $targetUserId, ?string $targetCnpj, int $limit = 20, int $offset = 0): array
    {
        $pdo = Database::connection();
        if (!$pdo instanceof PDO) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT id, score, body, visibility_score, visibility_body, created_at, updated_at
             FROM feedbacks
             WHERE target_user_id = :target_user_id AND target_cnpj = :target_cnpj
               AND status = :status AND visibility_score = :visibility_score
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':target_user_id', $targetUserId ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':target_cnpj', $targetCnpj ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':status', 'active', PDO::PARAM_STR);
        $stmt->bindValue(':visibility_score', 'public', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed> */
    private function normalize(array $data): array
    {
        $now = new DateTimeImmutable();
        $id = $data['id'] ?? null;
        $id = is_string($id) && $id !== '' ? $id : $this->uuid();

        $visScore = $data['visibility_score'] ?? 'public';
        if (!in_array($visScore, ['public','aggregate_only','private_admin'], true)) {
            $visScore = 'public';
        }
        $visBody = $data['visibility_body'] ?? 'private_admin';
        if (!in_array($visBody, ['public','private_admin'], true)) {
            $visBody = 'private_admin';
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active','under_review','removed'], true)) {
            $status = 'active';
        }

        $score = (int)($data['score'] ?? 0);
        if ($score < 0) {
            $score = 0;
        } elseif ($score > 10) {
            $score = 10;
        }

        $body = (string)($data['body'] ?? '');
        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000);
        }

        return [
            'id' => $id,
            'rater_user_id' => (string)($data['rater_user_id'] ?? ''),
            'target_user_id' => (string)($data['target_user_id'] ?? ''),
            'target_cnpj' => (string)($data['target_cnpj'] ?? ''),
            'deal_id' => (string)($data['deal_id'] ?? ''),
            'score' => $score,
            'body' => $body,
            'visibility_score' => $visScore,
            'visibility_body' => $visBody,
            'status' => $status,
            'allow_reply' => (bool)($data['allow_reply'] ?? true),
            'request_id' => (string)($data['request_id'] ?? $this->randomRequest()),
            'created_at' => $this->toDateTime($data['created_at'] ?? null)?->format(DATE_ATOM) ?? $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
        ];
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

    private function randomRequest(): string
    {
        try {
            return 'fbreq_' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'fbreq_' . uniqid('', true);
        }
    }
}
