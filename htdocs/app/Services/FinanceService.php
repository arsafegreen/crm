<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FinanceServiceInterface;
use App\Repositories\ClientRepository;
use App\Repositories\Finance\FinancialAccountRepository;
use App\Repositories\Finance\FinancialInvoiceRepository;
use App\Repositories\Finance\FinancialPartyRepository;
use InvalidArgumentException;

class FinanceService implements FinanceServiceInterface
{
    private FinancialPartyRepository $parties;
    private ClientRepository $clients;
    private FinancialInvoiceRepository $invoices;
    private FinancialAccountRepository $accounts;

    public function __construct(
        ?FinancialPartyRepository $parties = null,
        ?ClientRepository $clients = null,
        ?FinancialInvoiceRepository $invoices = null,
        ?FinancialAccountRepository $accounts = null
    ) {
        $this->parties = $parties ?? new FinancialPartyRepository();
        $this->clients = $clients ?? new ClientRepository();
        $this->invoices = $invoices ?? new FinancialInvoiceRepository();
        $this->accounts = $accounts ?? new FinancialAccountRepository();
    }

    public function ensureParty(int $customerId): int
    {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('customer_id é obrigatório');
        }

        $client = $this->clients->find($customerId);
        if ($client === null) {
            throw new InvalidArgumentException('Cliente não encontrado para vincular ao financeiro.');
        }

        return $this->parties->upsertFromClient($client);
    }

    public function createInvoice(int $partyId, array $items, ?int $dueDate = null, array $meta = []): int
    {
        if ($partyId <= 0) {
            throw new InvalidArgumentException('party_id é obrigatório');
        }
        if ($items === []) {
            throw new InvalidArgumentException('Informe ao menos um item para a fatura.');
        }

        $accountId = $this->resolveDefaultAccountId();
        $now = now();
        $due = $dueDate ?? $now;

        $normalizedItems = [];
        $total = 0;
        foreach ($items as $item) {
            $amount = (int)($item['amount_cents'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 1);
            $lineTotal = $amount * ($quantity > 0 ? $quantity : 1);
            $total += $lineTotal;

            $normalizedItems[] = [
                'description' => $item['description'] ?? 'Item',
                'amount_cents' => $amount,
                'quantity' => $quantity > 0 ? $quantity : 1,
                'cost_center_id' => $item['cost_center_id'] ?? null,
                'tax_code' => $item['tax_code'] ?? null,
                'metadata' => isset($item['meta']) ? json_encode($item['meta']) : ($item['metadata'] ?? null),
            ];
        }

        $payload = [
            'account_id' => $accountId,
            'party_id' => $partyId,
            'direction' => 'receivable',
            'status' => 'draft',
            'amount_cents' => $total,
            'currency' => 'BRL',
            'issue_date' => $now,
            'due_date' => $due,
            'description' => $meta['description'] ?? null,
            'document_number' => $meta['document_number'] ?? null,
            'metadata' => $meta !== [] ? json_encode($meta) : null,
        ];

        return $this->invoices->create($payload, $normalizedItems);
    }

    public function getInvoice(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        return $this->invoices->find($invoiceId);
    }

    private function resolveDefaultAccountId(): int
    {
        $active = $this->accounts->all(true);
        if ($active !== []) {
            return (int)$active[0]['id'];
        }

        $any = $this->accounts->all(false);
        if ($any === []) {
            throw new InvalidArgumentException('Nenhuma conta financeira configurada.');
        }

        return (int)$any[0]['id'];
    }
}
