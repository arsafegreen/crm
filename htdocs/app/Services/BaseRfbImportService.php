<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\RfbProspectRepository;
use RuntimeException;

final class BaseRfbImportService
{
    private const CHUNK_SIZE = 5000;
    private const SKIP_REASON_MISSING_CONTACT = 'missing_contact';

    private ClientRepository $clients;
    private RfbProspectRepository $prospects;

    public function __construct(?ClientRepository $clients = null, ?RfbProspectRepository $prospects = null)
    {
        $this->clients = $clients ?? new ClientRepository();
        $this->prospects = $prospects ?? new RfbProspectRepository();
    }

    /**
     * @return array{
     *     processed:int,
     *     imported:int,
     *     skipped_invalid:int,
     *     skipped_existing_clients:int,
     *     skipped_duplicates:int,
     *     already_in_base:int,
     *     skipped_missing_contact:int,
     *     lines_read:int
     * }
     */
    public function import(string $filePath, ?string $originalFilename = null): array
    {
        $path = $this->resolvePath($filePath);
        if (!is_readable($path)) {
            throw new RuntimeException('Arquivo de upload não pode ser lido.');
        }

        $file = new \SplFileObject($path, 'rb');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE);

        if ($file->eof()) {
            throw new RuntimeException('Planilha vazia.');
        }

        $headerLine = (string)$file->fgets();
        if ($headerLine === '') {
            throw new RuntimeException('Planilha vazia.');
        }

        $delimiter = $this->detectDelimiter($headerLine);
        $headers = $this->parseHeadersLine($headerLine, $delimiter);
        $file->setCsvControl($delimiter);

        $stats = [
            'processed' => 0,
            'imported' => 0,
            'skipped_invalid' => 0,
            'skipped_existing_clients' => 0,
            'skipped_duplicates' => 0,
            'already_in_base' => 0,
            'skipped_missing_contact' => 0,
            'lines_read' => 0,
        ];

        $seenCnpjs = [];
        $chunk = [];

        while (!$file->eof()) {
            $line = $file->fgetcsv();
            if ($line === false || $line === null) {
                continue;
            }

            if ($line === [null] || $line === []) {
                continue;
            }

            $stats['lines_read']++;

            $assoc = $this->combineRow($headers, $line);
            $skipReason = null;
            $normalized = $this->normalizeRow($assoc, $skipReason);
            if ($normalized === null) {
                if ($skipReason === self::SKIP_REASON_MISSING_CONTACT) {
                    $stats['skipped_missing_contact']++;
                } else {
                    $stats['skipped_invalid']++;
                }
                continue;
            }

            $cnpj = $normalized['cnpj'];
            if (isset($seenCnpjs[$cnpj])) {
                $stats['skipped_duplicates']++;
                continue;
            }
            $seenCnpjs[$cnpj] = true;

            $normalized['source_file'] = $originalFilename ?? basename($path);
            $chunk[] = $normalized;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->flushChunk($chunk, $stats);
                $chunk = [];
                $seenCnpjs = [];
            }
        }

        if ($chunk !== []) {
            $this->flushChunk($chunk, $stats);
            $seenCnpjs = [];
        }

        return $stats;
    }

    private function resolvePath(string $filePath): string
    {
        if (str_starts_with($filePath, storage_path())) {
            return $filePath;
        }

        $candidate = storage_path(trim($filePath, DIRECTORY_SEPARATOR));
        if (file_exists($candidate)) {
            return $candidate;
        }

        return $filePath;
    }

    private function parseHeadersLine(string $line, string $delimiter): array
    {
        $cleanLine = $this->stripBom($line);
        $headers = str_getcsv($cleanLine, $delimiter);
        if ($headers === false || $headers === []) {
            throw new RuntimeException('Planilha vazia.');
        }

        return array_map(static function ($header): string {
            $value = is_string($header) ? trim(mb_strtolower($header, 'UTF-8')) : '';
            return $value;
        }, $headers);
    }

    private function combineRow(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($row as $index => $value) {
            $key = $headers[$index] ?? 'col_' . $index;
            $assoc[$key] = $value;
        }
        return $assoc;
    }

    private function normalizeRow(array $row, ?string &$skipReason = null): ?array
    {
        $skipReason = null;
        $cnpjDigits = digits_only((string)($row['cnpj'] ?? ''));
        if ($cnpjDigits === '' || strlen($cnpjDigits) !== 14) {
            return null;
        }

        $company = trim((string)($row['razao_social'] ?? $row['nome_empresarial'] ?? $row['empresa'] ?? ''));
        if ($company === '') {
            return null;
        }

        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $activityRaw = trim((string)($row['inicio_atividade'] ?? ''));
        $activityTimestamp = $this->parseYmdDate($activityRaw);

        $ddd = $this->normalizeDigits($row['ddd1'] ?? $row['ddd'] ?? null);
        $phoneDigits = $this->normalizeDigits($row['celular'] ?? $row['telefone'] ?? null);
        if ($ddd !== null && $phoneDigits !== null && str_starts_with($phoneDigits, $ddd)) {
            $phoneDigits = substr($phoneDigits, strlen($ddd));
        }

        $payload = $row;

        $hasEmail = $email !== '';
        $hasPhone = $phoneDigits !== null && $phoneDigits !== '';
        $hasContact = $hasEmail || $hasPhone;
        if (!$hasContact) {
            $skipReason = self::SKIP_REASON_MISSING_CONTACT;
            return null;
        }

        return [
            'cnpj' => $cnpjDigits,
            'company_name' => mb_strtoupper($company, 'UTF-8'),
            'email' => $email !== '' ? strtolower($email) : null,
            'activity_started_at' => $activityTimestamp,
            'cnae_code' => trim((string)($row['cnae'] ?? $row['cnae_fiscal'] ?? '')) ?: null,
            'city' => $this->sanitizeName($row['nome_cidade'] ?? $row['cidade'] ?? null),
            'state' => $this->sanitizeUf($row['uf'] ?? $row['estado'] ?? null),
            'ddd' => $ddd,
            'phone' => $phoneDigits,
            'raw_payload' => $payload,
            'exclusion_status' => 'active',
            'exclusion_reason' => null,
            'excluded_at' => null,
        ];
    }

    private function parseYmdDate(?string $raw): ?int
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $matches) === 1) {
            return mktime(0, 0, 0, (int)$matches[2], (int)$matches[3], (int)$matches[1]);
        }

        return null;
    }

    private function normalizeDigits($value): ?string
    {
        $digits = digits_only((string)$value);
        return $digits !== '' ? $digits : null;
    }

    private function sanitizeName($value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? mb_strtoupper($value, 'UTF-8') : null;
    }

    private function sanitizeUf($value): ?string
    {
        $value = strtoupper(trim((string)$value));
        return strlen($value) === 2 ? $value : null;
    }

    private function detectDelimiter(string $line): string
    {
        $commaCount = substr_count($line, ',');
        $semicolonCount = substr_count($line, ';');
        $tabCount = substr_count($line, "\t");

        if ($semicolonCount > $commaCount && $semicolonCount >= $tabCount) {
            return ';';
        }

        if ($tabCount > $commaCount && $tabCount > $semicolonCount) {
            return "\t";
        }

        return ',';
    }

    private function stripBom(string $line): string
    {
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            return substr($line, 3);
        }

        return $line;
    }

    private function flushChunk(array $rows, array &$stats): void
    {
        if ($rows === []) {
            return;
        }

        $cnpjs = array_column($rows, 'cnpj');
        if ($cnpjs === []) {
            return;
        }

        $existingClients = $this->clients->findDocuments($cnpjs);
        if ($existingClients !== []) {
            $stats['skipped_existing_clients'] += count($existingClients);
            $clientMap = array_flip($existingClients);
            $rows = array_values(array_filter($rows, static fn(array $row): bool => !isset($clientMap[$row['cnpj']])));
            $this->prospects->deleteByCnpjs($existingClients);
        }

        if ($rows === []) {
            return;
        }

        $cnpjs = array_column($rows, 'cnpj');
        $existingProspects = $this->prospects->existingCnpjs($cnpjs);
        if ($existingProspects !== []) {
            $stats['already_in_base'] += count($existingProspects);
            $existingMap = array_flip($existingProspects);
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($existingMap): bool {
                    return !isset($existingMap[$row['cnpj']]);
                }
            ));
        }

        if ($rows === []) {
            return;
        }

        $timestamp = now();
        foreach ($rows as &$row) {
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;
            $row['raw_payload'] = json_encode($row['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        unset($row);

        $stats['processed'] += count($rows);
        $imported = $this->prospects->bulkInsert($rows);
        if ($imported > 0 && method_exists($this->prospects, 'rememberOptionValues')) {
            $this->prospects->rememberOptionValues($rows);
        }
        $stats['imported'] += $imported;
    }
}
