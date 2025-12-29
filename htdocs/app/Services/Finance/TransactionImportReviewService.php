<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Repositories\Finance\FinancialTransactionRepository;
use App\Repositories\Finance\TransactionImportRepository;
use JsonException;
use RuntimeException;
use Throwable;

final class TransactionImportReviewService
{
    private TransactionImportRepository $imports;
    private FinancialTransactionRepository $transactions;

    public function __construct(
        ?TransactionImportRepository $imports = null,
        ?FinancialTransactionRepository $transactions = null
    ) {
        $this->imports = $imports ?? new TransactionImportRepository();
        $this->transactions = $transactions ?? new FinancialTransactionRepository();
    }

    /**
     * @return array{imported:int, errors:int, duplicates:int}
     */
    public function importRows(int $batchId, ?array $rowIds = null, bool $overrideDuplicates = false): array
    {
        $batch = $this->imports->findBatch($batchId);
        if ($batch === null) {
            throw new RuntimeException('Lote não encontrado.');
        }

        $status = (string)($batch['status'] ?? 'pending');
        if (!in_array($status, ['ready', 'importing'], true)) {
            throw new RuntimeException('Este lote não está pronto para importação.');
        }

        $rows = $this->imports->rowsForImport($batchId, $rowIds);
        if ($rows === []) {
            throw new RuntimeException('Nenhuma linha válida disponível para importação.');
        }

        $stats = [
            'imported' => 0,
            'errors' => 0,
            'duplicates' => 0,
        ];

        $accountId = (int)$batch['account_id'];
        $now = now();
        $since = $now - (90 * 24 * 60 * 60);

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $normalized = $this->decodeNormalizedPayload($row);

            if ($normalized === null) {
                $this->markRowError($rowId, 'invalid_payload', 'Payload normalizado ausente ou inválido.');
                $stats['errors']++;
                continue;
            }

            $checksum = $this->resolveChecksum($row, $normalized);
            if ($checksum === null) {
                $this->markRowError($rowId, 'missing_checksum', 'Linha sem checksum calculado.');
                $stats['errors']++;
                continue;
            }

            if (!$overrideDuplicates && $this->transactions->findByChecksum($accountId, $checksum, $since) !== null) {
                $this->markRowError($rowId, 'duplicate_existing', 'Já existe um lançamento com este checksum.');
                $stats['errors']++;
                $stats['duplicates']++;
                $this->imports->recordEvent($batchId, 'warning', 'Linha bloqueada por duplicidade', [
                    'row_id' => $rowId,
                    'checksum' => $checksum,
                ]);
                continue;
            }

            try {
                $transactionPayload = $this->buildTransactionPayload($batch, $row, $normalized, $checksum);
                $transactionId = $this->transactions->create($transactionPayload);
            } catch (Throwable $exception) {
                $this->markRowError($rowId, 'transaction_error', $exception->getMessage());
                $stats['errors']++;
                $this->imports->recordEvent($batchId, 'error', 'Falha ao importar linha', [
                    'row_id' => $rowId,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $this->imports->markRowStatus($rowId, [
                'status' => 'imported',
                'transaction_id' => $transactionId,
                'imported_at' => $now,
                'error_code' => null,
                'error_message' => null,
            ]);

            $stats['imported']++;
            $this->imports->recordEvent($batchId, 'info', 'Linha importada', [
                'row_id' => $rowId,
                'transaction_id' => $transactionId,
                'checksum' => $checksum,
            ]);
        }

        if ($stats['imported'] > 0) {
            $this->transactions->recalculateBalance($accountId);
        }

        $summary = $this->imports->rowStatusSummary($batchId);
        $this->refreshBatchCounters($batchId, $batch, $summary);

        $this->imports->recordEvent($batchId, 'info', 'Resumo da importação manual', [
            'imported' => $stats['imported'],
            'errors' => $stats['errors'],
            'duplicates' => $stats['duplicates'],
            'remaining_valid' => $summary['valid'] ?? 0,
        ]);

        return $stats;
    }

    public function skipRow(int $batchId, int $rowId, ?string $reason = null): array
    {
        $batch = $this->imports->findBatch($batchId);
        if ($batch === null) {
            throw new RuntimeException('Lote não encontrado.');
        }

        $row = $this->imports->rowById($batchId, $rowId);
        if ($row === null) {
            throw new RuntimeException('Linha não encontrada.');
        }

        $status = (string)($row['status'] ?? '');
        if ($status === 'imported') {
            throw new RuntimeException('Linhas já importadas não podem ser marcadas como ignoradas.');
        }

        $message = trim((string)$reason) !== '' ? trim((string)$reason) : 'Marcado manualmente como ignorado.';

        $this->imports->markRowStatus($rowId, [
            'status' => 'skipped',
            'error_code' => 'skipped_manual',
            'error_message' => $message,
            'transaction_id' => null,
            'imported_at' => null,
        ]);

        $this->imports->recordEvent($batchId, 'warning', 'Linha marcada como ignorada', [
            'row_id' => $rowId,
            'reason' => $message,
        ]);

        $summary = $this->imports->rowStatusSummary($batchId);
        $this->refreshBatchCounters($batchId, $batch, $summary);

        return [
            'row_id' => $rowId,
            'status' => 'skipped',
            'reason' => $message,
        ];
    }

    private function decodeNormalizedPayload(array $row): ?array
    {
        $payload = $row['normalized_payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveChecksum(array $row, array $normalized): ?string
    {
        $checksum = $row['checksum'] ?? null;
        if ($checksum === null || $checksum === '') {
            $checksum = $normalized['checksum'] ?? null;
        }

        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $row
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildTransactionPayload(array $batch, array $row, array $normalized, string $checksum): array
    {
        $required = ['transaction_type', 'amount_cents', 'occurred_at', 'description'];
        foreach ($required as $field) {
            if (!isset($normalized[$field])) {
                throw new RuntimeException('Linha com dados insuficientes para importação.');
            }
        }

        $timestamp = now();
        $reference = $normalized['reference'] ?? ($row['reference'] ?? null);
        $costCenterId = $this->extractCostCenterId($normalized);

        $metadata = [
            'import_batch_id' => $row['batch_id'] ?? $batch['id'] ?? null,
            'row_number' => $row['row_number'] ?? null,
            'signed_amount_cents' => $normalized['signed_amount_cents'] ?? null,
            'duplicate_hint' => $normalized['duplicate_hint'] ?? null,
        ];

        try {
            $metadataJson = $this->encodeMetadata($metadata);
        } catch (JsonException $exception) {
            $metadataJson = null;
        }

        return [
            'account_id' => (int)$batch['account_id'],
            'cost_center_id' => $costCenterId,
            'transaction_type' => (string)$normalized['transaction_type'],
            'description' => mb_substr((string)$normalized['description'], 0, 255, 'UTF-8'),
            'amount_cents' => (int)$normalized['amount_cents'],
            'occurred_at' => (int)$normalized['occurred_at'],
            'reference' => $reference !== null ? mb_substr((string)$reference, 0, 120, 'UTF-8') : null,
            'source' => 'import',
            'source_payload' => $row['raw_payload'] ?? null,
            'metadata' => $metadataJson,
            'import_row_id' => (int)$row['id'],
            'checksum' => $checksum,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): ?string
    {
        $filtered = array_filter($metadata, static fn($value) => $value !== null && $value !== '');
        if ($filtered === []) {
            return null;
        }

        return json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function extractCostCenterId(array $normalized): ?int
    {
        foreach (['cost_center_id', 'default_cost_center_id'] as $key) {
            if (isset($normalized[$key])) {
                $candidate = (int)$normalized[$key];
                if ($candidate > 0) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function markRowError(int $rowId, string $code, string $message): void
    {
        $this->imports->markRowStatus($rowId, [
            'status' => 'error',
            'error_code' => $code,
            'error_message' => $message,
            'transaction_id' => null,
            'imported_at' => null,
        ]);
    }

    private function refreshBatchCounters(int $batchId, array $batch, array $summary): void
    {
        $pending = (int)($summary['pending'] ?? 0);
        $valid = (int)($summary['valid'] ?? 0);
        $imported = (int)($summary['imported'] ?? 0);
        $skipped = (int)($summary['skipped'] ?? 0);
        $errors = (int)($summary['error'] ?? 0);

        $currentStatus = (string)($batch['status'] ?? 'pending');
        $status = $currentStatus;

        if (!in_array($currentStatus, ['failed', 'canceled'], true)) {
            if ($valid === 0 && $pending === 0) {
                $status = 'completed';
            } elseif ($imported > 0 || $skipped > 0 || $errors > 0) {
                $status = 'importing';
            }
        }

        $this->imports->updateBatch($batchId, [
            'status' => $status,
            'valid_rows' => $valid,
            'invalid_rows' => (int)($summary['invalid'] ?? 0),
            'imported_rows' => $imported,
            'failed_rows' => $errors + $skipped,
            'processed_rows' => max(0, (int)$batch['total_rows'] - $pending),
        ]);
    }
}
