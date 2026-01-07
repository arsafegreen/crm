<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Certificate module contract (issue, renew, read summaries).
 */
interface CertificateServiceInterface
{
    /**
     * @return array<string, mixed>|null Expected keys: id, customer_id, protocol, start_at, end_at, status, is_revoked.
     */
    public function latestForCustomer(int $customerId): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByCustomer(int $customerId): array;

    /**
     * @param array<string, mixed> $payload Required: customer_id, protocol. Optional: start_at, end_at, status, partner_accountant, partner_accountant_plus, avp_cpf, avp_name, source_payload.
     * @return int Certificate id.
     */
    public function issue(array $payload): int;

    /**
     * @param array<string, mixed> $payload Same shape as issue; can apply specific renewal rules.
     * @return int Certificate id.
     */
    public function renew(array $payload): int;

    /**
     * Partner leaderboard within a window.
     * @return array<int, array<string, mixed>>
     */
    public function partnerStats(?int $startAt, ?int $endAt, ?int $limit = 10, string $sortField = 'latest', string $sortDirection = 'desc'): array;
}
