<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Finance contract for cross-module billing.
 */
interface FinanceServiceInterface
{
    /**
     * Ensure a financial party exists for a given customer.
     * @return int party id
     */
    public function ensureParty(int $customerId): int;

    /**
     * @param array<int, array{description:string, amount_cents:int, cost_center_id?:int, meta?:array<string, mixed>}> $items
     * @param array<string, mixed> $meta
     * @return int invoice id
     */
    public function createInvoice(int $partyId, array $items, ?int $dueDate = null, array $meta = []): int;

    /**
     * @return array<string, mixed>|null
     */
    public function getInvoice(int $invoiceId): ?array;
}
