<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\Finance\CostCenterRepository;
use App\Repositories\Finance\FinancialAccountRepository;
use App\Repositories\Finance\TransactionImportRepository;
use App\Services\Finance\TransactionImportReviewService;
use App\Services\Finance\TransactionImportService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class FinanceImportController
{
    private FinancialAccountRepository $accounts;
    private TransactionImportRepository $imports;
    private CostCenterRepository $costCenters;
    private TransactionImportService $importService;
    private TransactionImportReviewService $reviewService;

    public function __construct(
        ?FinancialAccountRepository $accounts = null,
        ?TransactionImportRepository $imports = null,
        ?CostCenterRepository $costCenters = null,
        ?TransactionImportService $importService = null,
        ?TransactionImportReviewService $reviewService = null
    ) {
        $this->accounts = $accounts ?? new FinancialAccountRepository();
        $this->imports = $imports ?? new TransactionImportRepository();
        $this->costCenters = $costCenters ?? new CostCenterRepository();
        $this->importService = $importService ?? new TransactionImportService($this->imports, $this->accounts);
        $this->reviewService = $reviewService ?? new TransactionImportReviewService($this->imports);
    }

    public function index(Request $request): Response
    {
        $statusFilter = trim((string)$request->query->get('status', ''));
        $status = $statusFilter !== '' ? $statusFilter : null;
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $batches = $this->imports->listBatches($perPage, $offset, $status);
        $summary = $this->imports->statusSummary();

        return view('finance/imports/index', [
            'batches' => $batches,
            'statusFilter' => $statusFilter,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'hasMore' => count($batches) === $perPage,
            ],
            'feedback' => $this->pullFeedback('finance_imports_feedback'),
        ]);
    }

    public function create(Request $request): Response
    {
        $accounts = $this->accounts->all(true);
        if ($accounts === []) {
            $this->flashFeedback('finance_imports_feedback', 'warning', 'Cadastre uma conta financeira antes de importar extratos.');
            return new RedirectResponse(url('finance/accounts/manage'));
        }

        [$values, $errors] = $this->resolveFormState();

        return view('finance/imports/create', [
            'accounts' => $accounts,
            'costCenters' => $this->costCenters->all(true),
            'form' => $values,
            'errors' => $errors,
        ]);
    }

    public function store(Request $request): Response
    {
        $form = $this->defaultForm();
        foreach ($form as $field => $default) {
            $form[$field] = (string)$request->request->get($field, $default);
        }

        $errors = [];

        $accountId = (int)$form['account_id'];
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            $errors['account_id'] = 'Selecione uma conta válida.';
        }

        $costCenterId = null;
        if ($form['default_cost_center_id'] !== '') {
            $candidate = (int)$form['default_cost_center_id'];
            $center = $candidate > 0 ? $this->costCenters->find($candidate) : null;
            if ($center === null) {
                $errors['default_cost_center_id'] = 'Centro de custo inválido.';
            } else {
                $costCenterId = $candidate;
            }
        }

        $fileType = in_array($form['file_type'], ['ofx', 'csv'], true) ? $form['file_type'] : 'ofx';
        $duplicateStrategy = in_array($form['duplicate_strategy'], ['ignore', 'override', 'skip_batch'], true)
            ? $form['duplicate_strategy']
            : 'ignore';
        $timezone = $form['timezone'] !== '' ? $form['timezone'] : 'America/Sao_Paulo';
        $categoryPrefix = mb_substr($form['category_prefix'], 0, 60, 'UTF-8');

        $file = $request->files->get('import_file');
        $upload = null;
        if (!$file instanceof UploadedFile) {
            $errors['import_file'] = 'Envie um arquivo OFX ou CSV.';
        } else {
            $upload = $this->persistUploadedFile($file, $fileType, $errors);
        }

        if ($errors !== []) {
            $this->flashFormState($form, $errors);
            return new RedirectResponse(url('finance/imports/create'));
        }

        if ($upload === null) {
            $this->flashFormState($form, ['import_file' => 'Falha ao processar o arquivo enviado.']);
            return new RedirectResponse(url('finance/imports/create'));
        }

        $metadata = json_encode([
            'file_type' => $fileType,
            'timezone' => $timezone,
            'default_cost_center_id' => $costCenterId,
            'category_prefix' => $categoryPrefix,
            'duplicate_strategy' => $duplicateStrategy,
        ], JSON_UNESCAPED_UNICODE);

        $batchId = $this->imports->createBatch([
            'account_id' => $accountId,
            'filename' => $upload['original_name'],
            'filepath' => $upload['path'],
            'status' => 'pending',
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => 0,
            'metadata' => $metadata ?: null,
        ]);

        $this->imports->recordEvent($batchId, 'info', 'Arquivo recebido para processamento', [
            'file_type' => $fileType,
            'size' => $upload['size'],
        ]);

        try {
            $result = $this->importService->processBatch($batchId);
            $message = sprintf(
                'Processamento concluído: %d válidas, %d inválidas.',
                (int)$result['valid_rows'],
                (int)$result['invalid_rows']
            );
            $level = ($result['status'] ?? '') === 'ready' ? 'success' : 'warning';
            $this->flashFeedback('finance_imports_feedback', $level, $message);
        } catch (Throwable $exception) {
            $this->flashFeedback('finance_imports_feedback', 'error', 'Arquivo salvo, mas houve erro ao processar. Veja os detalhes do lote.');
        }

        return new RedirectResponse(url('finance/imports/' . $batchId));
    }

    public function show(Request $request, array $vars): Response
    {
        $batch = $this->imports->findBatch((int)($vars['id'] ?? 0));
        if ($batch === null) {
            return abort(404, 'Lote não encontrado.');
        }

        $rows = [
            'pending' => $this->imports->rowsByStatus((int)$batch['id'], 'pending', 25),
            'valid' => $this->imports->rowsByStatus((int)$batch['id'], 'valid', 25),
            'invalid' => $this->imports->rowsByStatus((int)$batch['id'], 'invalid', 25),
            'imported' => $this->imports->rowsByStatus((int)$batch['id'], 'imported', 25),
        ];

        $status = (string)($batch['status'] ?? 'pending');
        $canRetry = in_array($status, ['failed', 'canceled'], true);
        $canCancel = in_array($status, ['pending', 'processing', 'ready', 'importing'], true);

        return view('finance/imports/show', [
            'batch' => $batch,
            'rows' => $rows,
            'events' => $this->imports->eventsForBatch((int)$batch['id']),
            'canRetry' => $canRetry,
            'canCancel' => $canCancel,
            'feedback' => $this->pullFeedback('finance_imports_batch_feedback'),
        ]);
    }

    public function retry(Request $request, array $vars): Response
    {
        $batch = $this->imports->findBatch((int)($vars['id'] ?? 0));
        if ($batch === null) {
            return abort(404, 'Lote não encontrado.');
        }

        if (!in_array((string)$batch['status'], ['failed', 'canceled'], true)) {
            $this->flashFeedback('finance_imports_batch_feedback', 'warning', 'Somente lotes com status falho ou cancelado podem ser reprocessados.');
            return new RedirectResponse(url('finance/imports/' . $batch['id']));
        }

        $this->imports->updateBatch((int)$batch['id'], [
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => 0,
        ]);
        $this->imports->recordEvent((int)$batch['id'], 'info', 'Lote reaberto para processamento manual');

        try {
            $result = $this->importService->processBatch((int)$batch['id']);
            $message = sprintf(
                'Processamento reexecutado: %d válidas, %d inválidas.',
                (int)$result['valid_rows'],
                (int)$result['invalid_rows']
            );
            $level = ($result['status'] ?? '') === 'ready' ? 'success' : 'warning';
            $this->flashFeedback('finance_imports_batch_feedback', $level, $message);
        } catch (Throwable $exception) {
            $this->flashFeedback('finance_imports_batch_feedback', 'error', 'Falha ao reprocessar o arquivo. Consulte os eventos abaixo.');
        }

        return new RedirectResponse(url('finance/imports/' . $batch['id']));
    }

    public function cancel(Request $request, array $vars): Response
    {
        $batch = $this->imports->findBatch((int)($vars['id'] ?? 0));
        if ($batch === null) {
            return abort(404, 'Lote não encontrado.');
        }

        if (!in_array((string)$batch['status'], ['pending', 'processing', 'ready', 'importing'], true)) {
            $this->flashFeedback('finance_imports_batch_feedback', 'warning', 'Este lote não pode mais ser cancelado.');
            return new RedirectResponse(url('finance/imports/' . $batch['id']));
        }

        $this->imports->updateBatch((int)$batch['id'], [
            'status' => 'canceled',
            'completed_at' => now(),
        ]);
        $this->imports->recordEvent((int)$batch['id'], 'warning', 'Lote cancelado manualmente');

        $this->flashFeedback('finance_imports_batch_feedback', 'success', 'Processamento cancelado. Nenhuma nova linha será importada.');
        return new RedirectResponse(url('finance/imports/' . $batch['id']));
    }

    public function importRows(Request $request, array $vars): Response
    {
        $batchId = (int)($vars['id'] ?? 0);
        $mode = (string)$request->request->get('mode', 'selected');
        $rowIds = $this->gatherRowIds($request);

        if ($mode !== 'all' && $rowIds === []) {
            $this->flashFeedback('finance_imports_batch_feedback', 'warning', 'Selecione ao menos uma linha para importar.');
            return new RedirectResponse(url('finance/imports/' . $batchId));
        }

        $overrideDuplicates = $this->boolFromRequest($request, 'override_duplicates');
        $targetRows = $mode === 'all' ? null : $rowIds;

        try {
            $stats = $this->reviewService->importRows($batchId, $targetRows, $overrideDuplicates);
            $type = $stats['errors'] === 0 ? 'success' : 'warning';
            $message = sprintf(
                '%d linha(s) importada(s). %d linha(s) com erro.',
                (int)$stats['imported'],
                (int)$stats['errors']
            );
            if ($stats['duplicates'] > 0 && $overrideDuplicates === false) {
                $message .= sprintf(' %d duplicidade(s) bloqueada(s).', (int)$stats['duplicates']);
            }
            $this->flashFeedback('finance_imports_batch_feedback', $type, $message);
        } catch (Throwable $exception) {
            $this->flashFeedback('finance_imports_batch_feedback', 'error', $exception->getMessage());
        }

        return new RedirectResponse(url('finance/imports/' . $batchId));
    }

    public function skipRow(Request $request, array $vars): Response
    {
        $batchId = (int)($vars['batch'] ?? 0);
        $rowId = (int)($vars['row'] ?? 0);
        $reason = (string)$request->request->get('reason', '');

        try {
            $result = $this->reviewService->skipRow($batchId, $rowId, $reason);
            $this->flashFeedback(
                'finance_imports_batch_feedback',
                'success',
                sprintf('Linha #%d marcada como ignorada.', (int)($result['row_id'] ?? $rowId))
            );
        } catch (Throwable $exception) {
            $this->flashFeedback('finance_imports_batch_feedback', 'error', $exception->getMessage());
        }

        return new RedirectResponse(url('finance/imports/' . $batchId));
    }

    private function persistUploadedFile(UploadedFile $file, string $fileType, array &$errors): ?array
    {
        if (!$file->isValid()) {
            $errors['import_file'] = 'Upload inválido. Tente novamente.';
            return null;
        }

        $size = $file->getSize() ?? 0;
        if ($size > 5 * 1024 * 1024) {
            $errors['import_file'] = 'Arquivo maior que 5MB. Gere um extrato menor.';
            return null;
        }

        $extension = strtolower((string)$file->getClientOriginalExtension() ?: $fileType);
        if (!in_array($extension, ['ofx', 'csv', 'txt'], true)) {
            $errors['import_file'] = 'Formato não suportado. Utilize OFX ou CSV.';
            return null;
        }

        $targetDir = storage_path('finance-imports/' . date('Y/m'));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $errors['import_file'] = 'Não foi possível preparar o diretório de importação.';
            return null;
        }

        try {
            $token = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        } catch (\Throwable $exception) {
            $token = date('Ymd_His') . '_' . uniqid('', true);
        }

        $filename = $token . '.' . $extension;
        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $exception) {
            $errors['import_file'] = 'Não foi possível salvar o arquivo enviado. Tente novamente.';
            return null;
        }

        return [
            'path' => $targetDir . DIRECTORY_SEPARATOR . $filename,
            'original_name' => $file->getClientOriginalName() ?: $filename,
            'size' => $size,
            'extension' => $extension,
        ];
    }

    private function resolveFormState(): array
    {
        $state = $_SESSION['finance_import_form'] ?? null;
        if ($state === null) {
            return [$this->defaultForm(), []];
        }

        unset($_SESSION['finance_import_form']);
        $values = array_merge($this->defaultForm(), $state['data'] ?? []);
        $errors = $state['errors'] ?? [];

        return [$values, $errors];
    }

    private function defaultForm(): array
    {
        return [
            'account_id' => '',
            'file_type' => 'ofx',
            'timezone' => 'America/Sao_Paulo',
            'default_cost_center_id' => '',
            'category_prefix' => '',
            'duplicate_strategy' => 'ignore',
        ];
    }

    private function flashFormState(array $data, array $errors): void
    {
        $_SESSION['finance_import_form'] = [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private function flashFeedback(string $key, string $type, string $message): void
    {
        $_SESSION[$key] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function pullFeedback(string $key): ?array
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $value;
    }

    private function gatherRowIds(Request $request): array
    {
        $raw = $request->request->all('row_ids');
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $candidate = (int)$value;
            if ($candidate > 0) {
                $ids[] = $candidate;
            }
        }

        return array_values(array_unique($ids));
    }

    private function boolFromRequest(Request $request, string $key, bool $default = false): bool
    {
        $value = $request->request->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool)$value;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);
            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return $default;
    }
}
