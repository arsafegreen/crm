<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\Finance\CostCenterRepository;
use App\Repositories\Finance\FinancialAccountRepository;
use App\Repositories\Finance\FinancialTransactionRepository;
use App\Repositories\Finance\TaxObligationRepository;
use App\Repositories\Finance\TransactionImportRepository;
use App\Services\Finance\FinancialInsightService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FinanceController
{
    private FinancialAccountRepository $accounts;
    private FinancialTransactionRepository $transactions;
    private TaxObligationRepository $taxes;
    private TransactionImportRepository $imports;
    private CostCenterRepository $costCenters;
    private FinancialInsightService $insights;

    public function __construct(
        ?FinancialAccountRepository $accounts = null,
        ?FinancialTransactionRepository $transactions = null,
        ?TaxObligationRepository $taxes = null,
        ?TransactionImportRepository $imports = null,
        ?CostCenterRepository $costCenters = null,
        ?FinancialInsightService $insights = null
    ) {
        $this->accounts = $accounts ?? new FinancialAccountRepository();
        $this->transactions = $transactions ?? new FinancialTransactionRepository();
        $this->taxes = $taxes ?? new TaxObligationRepository();
        $this->imports = $imports ?? new TransactionImportRepository();
        $this->costCenters = $costCenters ?? new CostCenterRepository();
        $this->insights = $insights ?? new FinancialInsightService();
    }

    public function overview(Request $request): Response
    {
        $accounts = $this->accounts->all();
        $totals = [
            'accounts' => count($accounts),
            'current_balance' => 0,
            'available_balance' => 0,
        ];

        foreach ($accounts as $account) {
            $totals['current_balance'] += (int)($account['current_balance'] ?? 0);
            $totals['available_balance'] += (int)($account['available_balance'] ?? 0);
        }

        $recentTransactions = $this->transactions->recent(10);
        $upcomingObligations = $this->taxes->upcoming(6);
        $recentImports = $this->imports->recentBatches(6);
        $importSummary = $this->imports->statusSummary();

        $importOverview = [
            'ongoing' => (int)(($importSummary['pending'] ?? 0) + ($importSummary['processing'] ?? 0)),
            'ready' => (int)($importSummary['ready'] ?? 0),
            'failed' => (int)($importSummary['failed'] ?? 0),
            'completed' => (int)($importSummary['completed'] ?? 0),
        ];

        $importWatchlist = array_values(array_filter(
            $recentImports,
            static fn(array $batch): bool => in_array(($batch['status'] ?? 'pending'), ['ready', 'pending', 'processing', 'failed'], true)
        ));

        $cashflow = $this->insights->cashflowProjection((int)$totals['current_balance']);
        $dreSummary = $this->insights->dreSummary(4);

        return view('finance/overview', [
            'accounts' => $accounts,
            'totals' => $totals,
            'recentTransactions' => $recentTransactions,
            'upcomingObligations' => $upcomingObligations,
            'recentImports' => $recentImports,
            'importOverview' => $importOverview,
            'importSummary' => $importSummary,
            'importWatchlist' => $importWatchlist,
            'cashflow' => $cashflow,
            'dreSummary' => $dreSummary,
        ]);
    }

    public function calendar(Request $request): Response
    {
        $obligations = $this->taxes->upcoming(120);
        $statusTotals = [];
        foreach ($obligations as $obligation) {
            $status = (string)($obligation['status'] ?? 'pending');
            $statusTotals[$status] = ($statusTotals[$status] ?? 0) + 1;
        }

        return view('finance/calendar', [
            'obligations' => $obligations,
            'statusTotals' => $statusTotals,
        ]);
    }

    public function accounts(Request $request): Response
    {
        $accounts = $this->accounts->all();
        $transactionsByAccount = [];

        foreach ($accounts as $account) {
            $transactionsByAccount[(int)$account['id']] = $this->transactions->listByAccount((int)$account['id'], 5);
        }

        return view('finance/accounts', [
            'accounts' => $accounts,
            'transactionsByAccount' => $transactionsByAccount,
        ]);
    }

    public function manageAccounts(Request $request): Response
    {
        $feedback = $this->pullFeedback('finance_accounts_feedback');
        $accounts = $this->accounts->all();

        return view('finance/accounts_manage', [
            'accounts' => $accounts,
            'feedback' => $feedback,
        ]);
    }

    public function createAccount(Request $request): Response
    {
        [$values, $errors] = $this->resolveAccountFormState('create');

        return view('finance/accounts_form', [
            'mode' => 'create',
            'account' => $values,
            'errors' => $errors,
            'action' => url('finance/accounts'),
            'title' => 'Nova conta financeira',
        ]);
    }

    public function storeAccount(Request $request): Response
    {
        $payload = $this->extractAccountPayload($request, null);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_account_form', [
                'mode' => 'create',
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/accounts/create'));
        }

        $this->accounts->create($payload['data']);
        $this->flashFeedback('finance_accounts_feedback', 'success', 'Conta criada com sucesso.');

        return new RedirectResponse(url('finance/accounts/manage'));
    }

    public function editAccount(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $account = $this->accounts->find($id);
        if ($account === null) {
            return abort(404, 'Conta não encontrada.');
        }

        [$values, $errors] = $this->resolveAccountFormState('edit', $id, $account);

        return view('finance/accounts_form', [
            'mode' => 'edit',
            'account' => $values,
            'errors' => $errors,
            'action' => url('finance/accounts/' . $id . '/update'),
            'title' => 'Editar conta financeira',
        ]);
    }

    public function updateAccount(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $account = $this->accounts->find($id);
        if ($account === null) {
            return abort(404, 'Conta não encontrada.');
        }

        $payload = $this->extractAccountPayload($request, $account);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_account_form', [
                'mode' => 'edit',
                'id' => $id,
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/accounts/' . $id . '/edit'));
        }

        $this->accounts->update($id, $payload['data']);
        $this->flashFeedback('finance_accounts_feedback', 'success', 'Conta atualizada com sucesso.');

        return new RedirectResponse(url('finance/accounts/manage'));
    }

    public function deleteAccount(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $account = $this->accounts->find($id);
        if ($account === null) {
            return abort(404, 'Conta não encontrada.');
        }

        $this->accounts->delete($id);
        $this->flashFeedback('finance_accounts_feedback', 'success', 'Conta removida. Transações associadas também foram excluídas.');

        return new RedirectResponse(url('finance/accounts/manage'));
    }

    public function costCenters(Request $request): Response
    {
        $feedback = $this->pullFeedback('finance_cost_centers_feedback');
        [$formValues, $formErrors] = $this->resolveCostCenterFormState();

        $costCenters = $this->costCenters->all();
        $accounts = $this->accounts->all(true);

        return view('finance/cost_centers', [
            'costCenters' => $costCenters,
            'accounts' => $accounts,
            'form' => [
                'values' => $formValues,
                'errors' => $formErrors,
            ],
            'feedback' => $feedback,
        ]);
    }

    public function storeCostCenter(Request $request): Response
    {
        $payload = $this->extractCostCenterPayload($request, null);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_cost_center_form', [
                'mode' => 'create',
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/cost-centers'));
        }

        $this->costCenters->create($payload['data']);
        $this->flashFeedback('finance_cost_centers_feedback', 'success', 'Centro de custo criado.');

        return new RedirectResponse(url('finance/cost-centers'));
    }

    public function editCostCenter(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $costCenter = $this->costCenters->find($id);
        if ($costCenter === null) {
            return abort(404, 'Centro de custo não encontrado.');
        }

        [$values, $errors] = $this->resolveCostCenterFormState($costCenter, $id);
        $accounts = $this->accounts->all(true);
        $parentOptions = array_filter(
            $this->costCenters->all(),
            static fn(array $row): bool => (int)$row['id'] !== $id
        );

        return view('finance/cost_centers_form', [
            'mode' => 'edit',
            'costCenter' => $values,
            'errors' => $errors,
            'accounts' => $accounts,
            'parentOptions' => $parentOptions,
            'action' => url('finance/cost-centers/' . $id . '/update'),
        ]);
    }

    public function updateCostCenter(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $costCenter = $this->costCenters->find($id);
        if ($costCenter === null) {
            return abort(404, 'Centro de custo não encontrado.');
        }

        $payload = $this->extractCostCenterPayload($request, $costCenter, $id);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_cost_center_form', [
                'mode' => 'edit',
                'id' => $id,
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/cost-centers/' . $id . '/edit'));
        }

        $this->costCenters->update($id, $payload['data']);
        $this->flashFeedback('finance_cost_centers_feedback', 'success', 'Centro de custo atualizado.');

        return new RedirectResponse(url('finance/cost-centers'));
    }

    public function deleteCostCenter(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $costCenter = $this->costCenters->find($id);
        if ($costCenter === null) {
            return abort(404, 'Centro de custo não encontrado.');
        }

        $this->costCenters->delete($id);
        $this->flashFeedback('finance_cost_centers_feedback', 'success', 'Centro de custo removido.');

        return new RedirectResponse(url('finance/cost-centers'));
    }

    public function transactions(Request $request): Response
    {
        $feedback = $this->pullFeedback('finance_transactions_feedback');
        $transactions = $this->transactions->recent(50);
        $accounts = $this->accounts->all();
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)$account['id']] = $account;
        }

        $costCenters = $this->costCenters->all();
        $costCenterMap = [];
        foreach ($costCenters as $center) {
            $costCenterMap[(int)$center['id']] = $center;
        }

        return view('finance/transactions', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'accountMap' => $accountMap,
            'costCenters' => $costCenters,
            'costCenterMap' => $costCenterMap,
            'feedback' => $feedback,
        ]);
    }

    public function createTransaction(Request $request): Response
    {
        $accounts = $this->accounts->all(true);
        if ($accounts === []) {
            $this->flashFeedback('finance_transactions_feedback', 'error', 'Cadastre uma conta antes de registrar lançamentos.');
            return new RedirectResponse(url('finance/accounts/manage'));
        }

        $costCenters = $this->costCenters->all(true);
        [$values, $errors] = $this->resolveTransactionFormState();

        return view('finance/transactions_form', [
            'mode' => 'create',
            'transaction' => $values,
            'errors' => $errors,
            'accounts' => $accounts,
            'costCenters' => $costCenters,
            'action' => url('finance/transactions'),
            'title' => 'Novo lançamento manual',
        ]);
    }

    public function storeTransaction(Request $request): Response
    {
        $payload = $this->extractTransactionPayload($request, null);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_transaction_form', [
                'mode' => 'create',
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/transactions/create'));
        }

        $this->transactions->create($payload['data']);
        $this->transactions->recalculateBalance((int)$payload['data']['account_id']);

        $this->flashFeedback('finance_transactions_feedback', 'success', 'Lançamento registrado.');
        return new RedirectResponse(url('finance/transactions'));
    }

    public function editTransaction(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $transaction = $this->transactions->find($id);
        if ($transaction === null) {
            return abort(404, 'Lançamento não encontrado.');
        }

        [$values, $errors] = $this->resolveTransactionFormState($transaction, $id);
        $accounts = $this->accounts->all(true);
        $costCenters = $this->costCenters->all(true);

        return view('finance/transactions_form', [
            'mode' => 'edit',
            'transaction' => $values,
            'errors' => $errors,
            'accounts' => $accounts,
            'costCenters' => $costCenters,
            'action' => url('finance/transactions/' . $id . '/update'),
            'title' => 'Editar lançamento',
        ]);
    }

    public function updateTransaction(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $transaction = $this->transactions->find($id);
        if ($transaction === null) {
            return abort(404, 'Lançamento não encontrado.');
        }

        $payload = $this->extractTransactionPayload($request, $transaction);
        if ($payload['errors'] !== []) {
            $this->flashFormState('finance_transaction_form', [
                'mode' => 'edit',
                'id' => $id,
                'data' => $payload['old'],
                'errors' => $payload['errors'],
            ]);

            return new RedirectResponse(url('finance/transactions/' . $id . '/edit'));
        }

        $originalAccount = (int)$transaction['account_id'];
        $this->transactions->update($id, $payload['data']);
        $updatedAccount = (int)$payload['data']['account_id'];

        $this->transactions->recalculateBalance($originalAccount);
        if ($updatedAccount !== $originalAccount) {
            $this->transactions->recalculateBalance($updatedAccount);
        }

        $this->flashFeedback('finance_transactions_feedback', 'success', 'Lançamento atualizado.');
        return new RedirectResponse(url('finance/transactions'));
    }

    public function deleteTransaction(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $transaction = $this->transactions->find($id);
        if ($transaction === null) {
            return abort(404, 'Lançamento não encontrado.');
        }

        $accountId = (int)$transaction['account_id'];
        $this->transactions->delete($id);
        $this->transactions->recalculateBalance($accountId);

        $this->flashFeedback('finance_transactions_feedback', 'success', 'Lançamento removido.');
        return new RedirectResponse(url('finance/transactions'));
    }

    private function resolveAccountFormState(string $mode, ?int $id = null, ?array $account = null): array
    {
        $defaults = $this->accountFormDefaults($account);
        $errors = [];
        $state = $this->pullFormState('finance_account_form');

        if ($state !== null && ($state['mode'] ?? '') === $mode) {
            if ($mode === 'edit' && (int)($state['id'] ?? 0) !== (int)$id) {
                return [$defaults, $errors];
            }

            $data = $state['data'] ?? [];
            $errors = $state['errors'] ?? [];
            $defaults = array_merge($defaults, $data);
        }

        return [$defaults, $errors];
    }

    private function resolveCostCenterFormState(?array $current = null, ?int $id = null): array
    {
        $defaults = $this->costCenterFormDefaults($current);
        $errors = [];
        $state = $this->pullFormState('finance_cost_center_form');

        if ($state !== null) {
            $mode = $state['mode'] ?? 'create';
            if ($mode === 'edit' && (int)($state['id'] ?? 0) !== (int)$id) {
                return [$defaults, $errors];
            }

            if ($mode === 'create' && $current !== null) {
                return [$defaults, $errors];
            }

            $defaults = array_merge($defaults, $state['data'] ?? []);
            $errors = $state['errors'] ?? [];
        }

        return [$defaults, $errors];
    }

    private function resolveTransactionFormState(?array $current = null, ?int $id = null): array
    {
        $defaults = $this->transactionFormDefaults($current);
        $errors = [];
        $state = $this->pullFormState('finance_transaction_form');

        if ($state !== null) {
            $mode = $state['mode'] ?? 'create';
            if ($mode === 'edit' && (int)($state['id'] ?? 0) !== (int)$id) {
                return [$defaults, $errors];
            }

            if ($mode === 'create' && $current !== null) {
                return [$defaults, $errors];
            }

            $defaults = array_merge($defaults, $state['data'] ?? []);
            $errors = $state['errors'] ?? [];
        }

        return [$defaults, $errors];
    }

    private function extractAccountPayload(Request $request, ?array $current): array
    {
        $displayName = trim((string)$request->request->get('display_name', $current['display_name'] ?? ''));
        $institution = trim((string)$request->request->get('institution', $current['institution'] ?? ''));
        $accountNumber = trim((string)$request->request->get('account_number', $current['account_number'] ?? ''));
        $accountType = (string)$request->request->get('account_type', $current['account_type'] ?? 'bank');
        $currency = strtoupper(substr(trim((string)$request->request->get('currency', $current['currency'] ?? 'BRL')), 0, 3));
        $color = trim((string)$request->request->get('color', $current['color'] ?? ''));

        $initialInput = trim((string)$request->request->get('initial_balance', $current !== null ? format_money_input((int)($current['initial_balance'] ?? 0)) : ''));
        $currentInput = trim((string)$request->request->get('current_balance', $current !== null ? format_money_input((int)($current['current_balance'] ?? 0)) : $initialInput));
        $availableInput = trim((string)$request->request->get('available_balance', $current !== null ? format_money_input((int)($current['available_balance'] ?? 0)) : $currentInput));

        $isPrimary = $request->request->get('is_primary') === '1';
        if (!$request->request->has('is_primary') && $current !== null) {
            $isPrimary = (int)($current['is_primary'] ?? 0) === 1;
        }

        if ($request->request->has('is_active')) {
            $isActive = $request->request->get('is_active') === '1';
        } else {
            $isActive = $current === null ? true : ((int)($current['is_active'] ?? 1) === 1);
        }

        $errors = [];
        if ($displayName === '') {
            $errors['display_name'] = 'Informe um nome amigável para a conta.';
        }

        $allowedTypes = ['bank', 'cash', 'investment', 'credit'];
        if (!in_array($accountType, $allowedTypes, true)) {
            $errors['account_type'] = 'Tipo de conta inválido.';
            $accountType = 'bank';
        }

        if ($currency === '' || strlen($currency) < 2) {
            $currency = 'BRL';
        }

        if ($color === '') {
            $color = null;
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors['color'] = 'Use um código hexadecimal válido (ex.: #38bdf8).';
        }

        $initialBalance = $this->normalizeMoneyInput($initialInput, (int)($current['initial_balance'] ?? 0), $errors, 'initial_balance');
        $currentBalance = $this->normalizeMoneyInput($currentInput !== '' ? $currentInput : $initialInput, (int)($current['current_balance'] ?? $initialBalance), $errors, 'current_balance', $initialBalance);
        $availableBalance = $this->normalizeMoneyInput($availableInput !== '' ? $availableInput : $currentInput, (int)($current['available_balance'] ?? $currentBalance), $errors, 'available_balance', $currentBalance);

        $data = [
            'display_name' => $displayName,
            'institution' => $institution !== '' ? $institution : null,
            'account_number' => $accountNumber !== '' ? $accountNumber : null,
            'account_type' => $accountType,
            'currency' => $currency,
            'color' => $color,
            'initial_balance' => $initialBalance,
            'current_balance' => $currentBalance,
            'available_balance' => $availableBalance,
            'is_primary' => $isPrimary ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
        ];

        return [
            'errors' => $errors,
            'data' => $data,
            'old' => [
                'display_name' => $displayName,
                'institution' => $institution,
                'account_number' => $accountNumber,
                'account_type' => $accountType,
                'currency' => $currency,
                'color' => $color ?? '',
                'initial_balance' => $initialInput,
                'current_balance' => $currentInput,
                'available_balance' => $availableInput,
                'is_primary' => $isPrimary ? '1' : '0',
                'is_active' => $isActive ? '1' : '0',
            ],
        ];
    }

    private function extractCostCenterPayload(Request $request, ?array $current, ?int $id = null): array
    {
        $name = trim((string)$request->request->get('name', $current['name'] ?? ''));
        $code = strtoupper(trim((string)$request->request->get('code', $current['code'] ?? '')));
        $description = trim((string)$request->request->get('description', $current['description'] ?? ''));
        $parentIdInput = $request->request->get('parent_id', $current['parent_id'] ?? '');
        $defaultAccountInput = $request->request->get('default_account_id', $current['default_account_id'] ?? '');
        $isActive = $request->request->get('is_active', (string)($current['is_active'] ?? 1)) !== '0';

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Nome é obrigatório.';
        }

        if ($code === '') {
            $errors['code'] = 'Código interno é obrigatório.';
        } elseif ($this->costCenters->existsByCode($code, $id)) {
            $errors['code'] = 'Já existe um centro com este código.';
        }

        $parentId = null;
        if ($parentIdInput !== '' && $parentIdInput !== null) {
            $candidate = (int)$parentIdInput;
            if ($candidate === $id) {
                $errors['parent_id'] = 'Centro não pode ser pai de si mesmo.';
            } elseif ($candidate > 0 && $this->costCenters->find($candidate) === null) {
                $errors['parent_id'] = 'Centro pai inválido.';
            } else {
                $parentId = $candidate > 0 ? $candidate : null;
            }
        }

        $defaultAccountId = null;
        if ($defaultAccountInput !== '' && $defaultAccountInput !== null) {
            $candidate = (int)$defaultAccountInput;
            if ($candidate > 0 && $this->accounts->find($candidate) === null) {
                $errors['default_account_id'] = 'Selecione uma conta válida.';
            } else {
                $defaultAccountId = $candidate > 0 ? $candidate : null;
            }
        }

        $data = [
            'name' => $name,
            'code' => $code,
            'description' => $description !== '' ? $description : null,
            'parent_id' => $parentId,
            'default_account_id' => $defaultAccountId,
            'is_active' => $isActive ? 1 : 0,
        ];

        return [
            'errors' => $errors,
            'data' => $data,
            'old' => [
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'parent_id' => $parentIdInput !== null ? (string)$parentIdInput : '',
                'default_account_id' => $defaultAccountInput !== null ? (string)$defaultAccountInput : '',
                'is_active' => $isActive ? '1' : '0',
            ],
        ];
    }

    private function extractTransactionPayload(Request $request, ?array $current): array
    {
        $accountId = (int)$request->request->get('account_id', $current['account_id'] ?? 0);
        $costCenterInput = $request->request->get('cost_center_id', $current['cost_center_id'] ?? '');
        $transactionType = (string)$request->request->get('transaction_type', $current['transaction_type'] ?? 'debit');
        $category = trim((string)$request->request->get('category', $current['category'] ?? ''));
        $description = trim((string)$request->request->get('description', $current['description'] ?? ''));
        $amountInput = trim((string)$request->request->get('amount', $current !== null ? format_money_input((int)$current['amount_cents']) : ''));
        $occurredAtInput = (string)$request->request->get('occurred_at', $current !== null ? $this->formatDateTimeInput((int)$current['occurred_at']) : '');
        $reference = trim((string)$request->request->get('reference', $current['reference'] ?? ''));

        $errors = [];
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            $errors['account_id'] = 'Conta selecionada é inválida.';
        }

        $allowedTypes = ['debit', 'credit'];
        if (!in_array($transactionType, $allowedTypes, true)) {
            $errors['transaction_type'] = 'Tipo de lançamento inválido.';
            $transactionType = 'debit';
        }

        if ($description === '') {
            $errors['description'] = 'Descreva o lançamento para facilitar conciliações.';
        }

        $amountCents = null;
        if ($amountInput === '') {
            $errors['amount'] = 'Informe um valor.';
        } else {
            $amountCents = money_to_cents($amountInput);
            if ($amountCents === null || $amountCents <= 0) {
                $errors['amount'] = 'Valor inválido.';
            }
        }

        $occurredAt = $this->parseDateTimeInput($occurredAtInput);
        if ($occurredAt === null) {
            $errors['occurred_at'] = 'Data/hora do lançamento é obrigatória.';
        }

        $costCenterId = null;
        if ($costCenterInput !== '' && $costCenterInput !== null) {
            $candidate = (int)$costCenterInput;
            if ($candidate > 0 && $this->costCenters->find($candidate) === null) {
                $errors['cost_center_id'] = 'Centro de custo inválido.';
            } else {
                $costCenterId = $candidate > 0 ? $candidate : null;
            }
        }

        $data = [
            'account_id' => $accountId,
            'cost_center_id' => $costCenterId,
            'transaction_type' => $transactionType,
            'category' => $category !== '' ? $category : null,
            'description' => $description,
            'amount_cents' => $amountCents ?? 0,
            'occurred_at' => $occurredAt ?? now(),
            'reference' => $reference !== '' ? $reference : null,
            'source' => $current['source'] ?? 'manual',
        ];

        return [
            'errors' => $errors,
            'data' => $data,
            'old' => [
                'account_id' => (string)$accountId,
                'cost_center_id' => (string)$costCenterInput,
                'transaction_type' => $transactionType,
                'category' => $category,
                'description' => $description,
                'amount' => $amountInput,
                'occurred_at' => $occurredAtInput,
                'reference' => $reference,
            ],
        ];
    }

    private function accountFormDefaults(?array $account = null): array
    {
        return [
            'display_name' => $account['display_name'] ?? '',
            'institution' => $account['institution'] ?? '',
            'account_number' => $account['account_number'] ?? '',
            'account_type' => $account['account_type'] ?? 'bank',
            'currency' => $account['currency'] ?? 'BRL',
            'color' => $account['color'] ?? '#38bdf8',
            'initial_balance' => $account !== null ? format_money_input((int)($account['initial_balance'] ?? 0)) : format_money_input(0),
            'current_balance' => $account !== null ? format_money_input((int)($account['current_balance'] ?? 0)) : format_money_input(0),
            'available_balance' => $account !== null ? format_money_input((int)($account['available_balance'] ?? 0)) : format_money_input(0),
            'is_primary' => (isset($account['is_primary']) && (int)$account['is_primary'] === 1) ? '1' : '0',
            'is_active' => (isset($account['is_active']) ? (int)$account['is_active'] === 1 : true) ? '1' : '0',
        ];
    }

    private function costCenterFormDefaults(?array $current = null): array
    {
        return [
            'name' => $current['name'] ?? '',
            'code' => $current['code'] ?? '',
            'description' => $current['description'] ?? '',
            'parent_id' => isset($current['parent_id']) ? (string)$current['parent_id'] : '',
            'default_account_id' => isset($current['default_account_id']) ? (string)$current['default_account_id'] : '',
            'is_active' => (isset($current['is_active']) ? (int)$current['is_active'] === 1 : true) ? '1' : '0',
        ];
    }

    private function transactionFormDefaults(?array $current = null): array
    {
        return [
            'account_id' => isset($current['account_id']) ? (string)$current['account_id'] : '',
            'cost_center_id' => isset($current['cost_center_id']) ? (string)$current['cost_center_id'] : '',
            'transaction_type' => $current['transaction_type'] ?? 'debit',
            'category' => $current['category'] ?? '',
            'description' => $current['description'] ?? '',
            'amount' => $current !== null ? format_money_input((int)$current['amount_cents']) : '',
            'occurred_at' => $current !== null ? $this->formatDateTimeInput((int)$current['occurred_at']) : $this->formatDateTimeInput(null),
            'reference' => $current['reference'] ?? '',
        ];
    }

    private function normalizeMoneyInput(string $input, int $fallback, array &$errors, string $field, ?int $default = null): int
    {
        if ($input === '') {
            return $default ?? $fallback;
        }

        $value = money_to_cents($input);
        if ($value === null) {
            $errors[$field] = 'Valor inválido.';
            return $fallback;
        }

        return $value;
    }

    private function formatDateTimeInput(?int $timestamp): string
    {
        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $reference = $timestamp !== null
            ? (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)
            : new DateTimeImmutable('now', $timezone);

        return $reference->format('Y-m-d\\TH:i');
    }

    private function parseDateTimeInput(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $formats = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }

        return null;
    }

    private function flashFeedback(string $key, string $type, string $message): void
    {
        $_SESSION[$key] = ['type' => $type, 'message' => $message];
    }

    private function pullFeedback(string $key): ?array
    {
        $feedback = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_array($feedback) ? $feedback : null;
    }

    private function flashFormState(string $key, array $state): void
    {
        $_SESSION[$key] = $state;
    }

    private function pullFormState(string $key): ?array
    {
        $state = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_array($state) ? $state : null;
    }
}
