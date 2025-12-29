<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Repositories\Finance\FinancialAccountRepository;
use App\Repositories\Finance\TransactionImportRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SplFileObject;
use Throwable;

final class TransactionImportService
{
    private const BUFFER_SIZE = 250;
    private const REQUIRED_KEYS = ['date', 'description', 'amount'];
    private const COLUMN_ALIASES = [
        'date' => ['date', 'data', 'transaction_date', 'posted_at', 'dia'],
        'time' => ['time', 'hora', 'transaction_time', 'hour'],
        'description' => ['description', 'descrição', 'descricao', 'memo', 'detalhe', 'historico', 'histórico', 'name'],
        'amount' => ['amount', 'valor', 'value', 'amount_cents', 'transaction_amount', 'trnamt'],
        'reference' => ['reference', 'documento', 'doc', 'id', 'identificador', 'comprovante', 'fitid'],
        'cost_center' => ['cost_center', 'centro_de_custo', 'cc', 'costcentre'],
    ];

    private TransactionImportRepository $imports;
    private FinancialAccountRepository $accounts;

    public function __construct(
        ?TransactionImportRepository $imports = null,
        ?FinancialAccountRepository $accounts = null
    ) {
        $this->imports = $imports ?? new TransactionImportRepository();
        $this->accounts = $accounts ?? new FinancialAccountRepository();
    }

    /**
     * @return array{batch_id:int,status:string,total_rows:int,valid_rows:int,invalid_rows:int}
     */
    public function processBatch(int $batchId): array
    {
        $batch = $this->imports->findBatch($batchId);
        if ($batch === null) {
            throw new RuntimeException('Lote de importação não encontrado.');
        }

        $accountId = (int)$batch['account_id'];
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            throw new RuntimeException('A conta financeira vinculada ao lote não existe mais.');
        }

        $filePath = (string)$batch['filepath'];
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Arquivo de extrato indisponível para processamento.');
        }

        $metadata = $this->decodeMetadata($batch['metadata'] ?? null);
        $options = [
            'file_type' => strtolower((string)($metadata['file_type'] ?? pathinfo($filePath, PATHINFO_EXTENSION) ?: 'ofx')),
            'timezone' => $metadata['timezone'] ?? 'America/Sao_Paulo',
            'default_cost_center_id' => isset($metadata['default_cost_center_id']) ? (int)$metadata['default_cost_center_id'] : null,
            'category_prefix' => trim((string)($metadata['category_prefix'] ?? '')),
        ];

        $this->imports->updateBatch($batchId, [
            'status' => 'processing',
            'started_at' => now(),
        ]);
        $this->imports->recordEvent($batchId, 'info', 'Processamento iniciado', [
            'file_type' => $options['file_type'],
        ]);

        $this->imports->clearRows($batchId);

        $stats = [
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
        ];

        $buffer = [];
        $rowNumber = 0;
        $seenChecksums = [];

        try {
            $iterator = $options['file_type'] === 'csv'
                ? $this->parseCsv($filePath, $options, $accountId)
                : $this->parseOfx($filePath, $options, $accountId);

            foreach ($iterator as $entry) {
                $rowNumber++;
                $stats['total_rows']++;

                $normalized = $entry['normalized'];
                $error = $entry['error'];

                if ($error === null && $normalized !== null) {
                    $checksum = $normalized['checksum'] ?? null;
                    if ($checksum !== null && isset($seenChecksums[$checksum])) {
                        $error = ['code' => 'duplicate_row', 'message' => 'Linha duplicada dentro do arquivo.'];
                    } elseif ($checksum !== null) {
                        $seenChecksums[$checksum] = true;
                    }
                }

                if ($error === null) {
                    $stats['valid_rows']++;
                } else {
                    $stats['invalid_rows']++;
                }

                $buffer[] = $this->buildRowRecord($rowNumber, $entry['raw'], $normalized, $error);

                if (count($buffer) >= self::BUFFER_SIZE) {
                    $this->imports->insertRows($batchId, $buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->imports->insertRows($batchId, $buffer);
            }

            if ($stats['total_rows'] === 0) {
                throw new RuntimeException('Nenhuma transação foi encontrada no arquivo enviado.');
            }

            $finalStatus = $stats['valid_rows'] > 0 ? 'ready' : 'failed';

            $this->imports->updateBatch($batchId, [
                'status' => $finalStatus,
                'total_rows' => $stats['total_rows'],
                'processed_rows' => $stats['total_rows'],
                'valid_rows' => $stats['valid_rows'],
                'invalid_rows' => $stats['invalid_rows'],
                'imported_rows' => 0,
                'failed_rows' => $finalStatus === 'failed' ? $stats['total_rows'] : 0,
                'completed_at' => now(),
            ]);

            $this->imports->recordEvent($batchId, 'info', 'Processamento concluído', [
                'status' => $finalStatus,
                'valid_rows' => $stats['valid_rows'],
                'invalid_rows' => $stats['invalid_rows'],
            ]);

            return ['batch_id' => $batchId, 'status' => $finalStatus] + $stats;
        } catch (Throwable $exception) {
            $this->imports->updateBatch($batchId, [
                'status' => 'failed',
                'failed_rows' => $stats['total_rows'],
                'completed_at' => now(),
            ]);

            $this->imports->recordEvent($batchId, 'error', $exception->getMessage(), [
                'exception' => get_class($exception),
            ]);

            throw $exception;
        }
    }

    /**
     * @return \Generator<int, array{raw: array<string,mixed>, normalized: ?array, error: ?array}>
     */
    private function parseCsv(string $filePath, array $options, int $accountId): \Generator
    {
        $file = new SplFileObject($filePath, 'rb');
        $file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);

        if ($file->eof()) {
            throw new RuntimeException('Arquivo CSV vazio.');
        }

        $headerLine = (string)$file->fgets();
        if (trim($headerLine) === '') {
            throw new RuntimeException('Arquivo CSV sem cabeçalho.');
        }

        $delimiter = $this->detectDelimiter($headerLine);
        $headers = $this->parseHeadersLine($headerLine, $delimiter);
        $this->ensureRequiredColumns($headers);
        $file->setCsvControl($delimiter);

        while (!$file->eof()) {
            $line = $file->fgetcsv();
            if ($line === false || $line === null) {
                continue;
            }

            if ($line === [null] || $line === []) {
                continue;
            }

            $assoc = $this->combineRow($headers, $line);
            [$normalized, $error] = $this->normalizeRow($assoc, $accountId, $options);

            yield [
                'raw' => $assoc,
                'normalized' => $normalized,
                'error' => $error,
            ];
        }
    }

    /**
     * @return \Generator<int, array{raw: array<string,mixed>, normalized: ?array, error: ?array}>
     */
    private function parseOfx(string $filePath, array $options, int $accountId): \Generator
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o arquivo OFX.');
        }

        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $contents, $matches) === 0) {
            throw new RuntimeException('Arquivo OFX não contém blocos de transação (STMTTRN).');
        }

        foreach ($matches[1] as $block) {
            $raw = $this->extractOfxRow($block);
            if ($raw === null) {
                continue;
            }

            [$normalized, $error] = $this->normalizeRow($raw, $accountId, $options);

            yield [
                'raw' => $raw,
                'normalized' => $normalized,
                'error' => $error,
            ];
        }
    }

    private function extractOfxRow(string $block): ?array
    {
        $tags = [
            'DTPOSTED' => null,
            'TRNAMT' => null,
            'FITID' => null,
            'TRNTYPE' => null,
            'NAME' => null,
            'MEMO' => null,
        ];

        foreach ($tags as $tag => $_) {
            if (preg_match(sprintf('/<%s>([^\r\n<]+)/i', $tag), $block, $match) === 1) {
                $tags[$tag] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
        }

        if ($tags['DTPOSTED'] === null || $tags['TRNAMT'] === null) {
            return null;
        }

        $description = trim(($tags['NAME'] ?? '') . ' ' . ($tags['MEMO'] ?? ''));
        if ($description === '') {
            $description = 'Transação OFX';
        }

        return [
            'date' => $tags['DTPOSTED'],
            'description' => $description,
            'amount' => $tags['TRNAMT'],
            'reference' => $tags['FITID'] ?? null,
            'type' => $tags['TRNTYPE'] ?? null,
        ];
    }

    private function buildRowRecord(int $rowNumber, array $raw, ?array $normalized, ?array $error): array
    {
        return [
            'row_number' => $rowNumber,
            'status' => $error === null ? 'valid' : 'invalid',
            'transaction_type' => $normalized['transaction_type'] ?? null,
            'amount_cents' => $normalized['amount_cents'] ?? null,
            'occurred_at' => $normalized['occurred_at'] ?? null,
            'description' => $normalized['description'] ?? null,
            'reference' => $normalized['reference'] ?? null,
            'checksum' => $normalized['checksum'] ?? null,
            'raw_payload' => $this->encodeJson($raw),
            'normalized_payload' => $this->encodeJson($normalized),
            'error_code' => $error['code'] ?? null,
            'error_message' => $error['message'] ?? null,
            'transaction_id' => null,
            'imported_at' => null,
        ];
    }

    /**
     * @return array{0:?array,1:?array}
     */
    private function normalizeRow(array $row, int $accountId, array $options): array
    {
        $timezone = $options['timezone'] ?? 'America/Sao_Paulo';
        $dateValue = $this->extractValue($row, 'date');
        $timeValue = $this->extractValue($row, 'time');
        $description = $this->extractValue($row, 'description');
        $amountValue = $this->extractValue($row, 'amount');
        $reference = $this->extractValue($row, 'reference');
        $costCenter = $this->extractValue($row, 'cost_center');

        if ($dateValue === null || $dateValue === '') {
            return [null, ['code' => 'missing_date', 'message' => 'Linha sem data.']];
        }

        if ($description === null || $description === '') {
            return [null, ['code' => 'missing_description', 'message' => 'Linha sem descrição.']];
        }

        if ($amountValue === null || $amountValue === '') {
            return [null, ['code' => 'missing_amount', 'message' => 'Linha sem valor.']];
        }

        $timestamp = $this->parseDateTime($dateValue, $timeValue, $timezone);
        if ($timestamp === null) {
            return [null, ['code' => 'invalid_date', 'message' => 'Data inválida: ' . $dateValue]];
        }

        $amountCents = $this->parseAmountToCents($amountValue);
        if ($amountCents === null || $amountCents === 0) {
            return [null, ['code' => 'invalid_amount', 'message' => 'Valor inválido: ' . $amountValue]];
        }

        $transactionType = $amountCents >= 0 ? 'credit' : 'debit';
        $descriptionClean = mb_substr($description, 0, 255, 'UTF-8');
        $referenceClean = $reference !== null ? mb_substr((string)$reference, 0, 120, 'UTF-8') : null;

        $payload = [
            'transaction_type' => $transactionType,
            'amount_cents' => abs($amountCents),
            'occurred_at' => $timestamp,
            'description' => $descriptionClean,
            'reference' => $referenceClean,
            'signed_amount_cents' => $amountCents,
        ];

        if ($options['category_prefix'] !== '') {
            $payload['category_prefix'] = $options['category_prefix'];
        }

        if ($options['default_cost_center_id'] !== null) {
            $payload['default_cost_center_id'] = $options['default_cost_center_id'];
        }

        if ($costCenter !== null && $costCenter !== '') {
            $payload['cost_center_code'] = $costCenter;
        }

        $payload['checksum'] = $this->buildChecksum(
            $accountId,
            $payload['occurred_at'],
            $transactionType,
            $payload['amount_cents'],
            $payload['description'],
            $payload['reference'] ?? ''
        );

        return [$payload, null];
    }

    private function buildChecksum(int $accountId, int $timestamp, string $type, int $amountCents, string $description, string $reference): string
    {
        return hash('sha256', implode('|', [
            (string)$accountId,
            (string)$timestamp,
            $type,
            (string)$amountCents,
            mb_strtolower($description, 'UTF-8'),
            mb_strtolower($reference, 'UTF-8'),
        ]));
    }

    private function parseHeadersLine(string $line, string $delimiter): array
    {
        $trimmed = $this->stripBom($line);
        $headers = str_getcsv($trimmed, $delimiter);
        if ($headers === false || $headers === []) {
            throw new RuntimeException('Não foi possível ler o cabeçalho da planilha.');
        }

        return array_map(static function ($value): string {
            $clean = is_string($value) ? trim($value) : '';
            return mb_strtolower($clean, 'UTF-8');
        }, $headers);
    }

    private function ensureRequiredColumns(array $headers): void
    {
        $available = array_flip($headers);
        foreach (self::REQUIRED_KEYS as $key) {
            $aliases = self::COLUMN_ALIASES[$key] ?? [];
            $found = false;
            foreach ($aliases as $alias) {
                if (isset($available[$alias])) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new RuntimeException(sprintf('Arquivo não contém coluna obrigatória "%s".', $key));
            }
        }
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);
        $delimiter = array_key_first($counts);
        return $delimiter === null || $counts[$delimiter] === 0 ? ',' : $delimiter;
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    private function combineRow(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($row as $index => $value) {
            $key = $headers[$index] ?? 'col_' . $index;
            $assoc[$key] = is_string($value) ? trim($value) : $value;
        }

        return $assoc;
    }

    private function extractValue(array $row, string $key): ?string
    {
        $aliases = self::COLUMN_ALIASES[$key] ?? [$key];
        foreach ($aliases as $alias) {
            $normalizedKey = mb_strtolower($alias, 'UTF-8');
            if (array_key_exists($normalizedKey, $row)) {
                $value = $row[$normalizedKey];
                return is_string($value) ? trim($value) : (string)$value;
            }
        }

        return null;
    }

    private function parseDateTime(string $dateValue, ?string $timeValue, string $timezone): ?int
    {
        $candidate = trim($dateValue . ' ' . ($timeValue ?? '')); // ensures OFX value preserved if no time alias

        // OFX format YYYYMMDDHHMMSS or subset
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?/', $dateValue, $matches) === 1) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = isset($matches[4]) ? (int)$matches[4] : 0;
            $minute = isset($matches[5]) ? (int)$matches[5] : 0;
            $second = isset($matches[6]) ? (int)$matches[6] : 0;

            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), new DateTimeZone($timezone));
            return $dt->getTimestamp();
        }

        $formats = ['Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm/d/Y H:i'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $candidate, new DateTimeZone($timezone));
            if ($dt instanceof DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }

        $timestamp = strtotime($candidate);
        if ($timestamp !== false) {
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone))->getTimestamp();
        }

        return null;
    }

    private function parseAmountToCents(string $value): ?int
    {
        $clean = trim(str_replace(["\u{00A0}", ' '], '', $value));
        $clean = str_ireplace('R$', '', $clean);
        $clean = str_replace(',', '.', $this->normalizeDecimalSeparators($clean));

        if (!is_numeric($clean)) {
            return null;
        }

        $number = (float)$clean;
        return (int)round($number * 100);
    }

    private function normalizeDecimalSeparators(string $value): string
    {
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');
            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    $value = str_replace('.', '', $value);
                } else {
                    $value = str_replace(',', '', $value);
                }
            }
        }

        if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
            return str_replace(',', '.', $value);
        }

        if (substr_count($value, '.') > 1 && substr_count($value, ',') === 0) {
            return str_replace('.', '', $value);
        }

        return $value;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function decodeMetadata(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
