<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Repositories\ImportLogRepository;
use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\ContactAttributeRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use RuntimeException;
use SplFileObject;

final class MarketingContactImportService
{
    private MarketingContactRepository $contacts;
    private AudienceListRepository $lists;
    private ContactAttributeRepository $attributes;
    private ImportLogRepository $logs;
    private int $maxRows;

    public function __construct(
        ?MarketingContactRepository $contacts = null,
        ?AudienceListRepository $lists = null,
        ?ContactAttributeRepository $attributes = null,
        ?ImportLogRepository $logs = null,
        ?int $maxRows = null
    ) {
        $this->contacts = $contacts ?? new MarketingContactRepository();
        $this->lists = $lists ?? new AudienceListRepository();
        $this->attributes = $attributes ?? new ContactAttributeRepository();
        $this->logs = $logs ?? new ImportLogRepository();
        $this->maxRows = $maxRows ?? (int)config('marketing.imports.max_rows', 5000);
    }

    /**
     * @param array{source_label?: string|null, respect_opt_out?: bool, user_id?: ?int, filename?: ?string} $options
     * @return array{stats: array<string, int>, errors: string[]}
     */
    public function import(int $listId, string $filePath, array $options = []): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Arquivo de importação não foi encontrado.');
        }

        $file = new SplFileObject($filePath, 'rb');
        $file->setFlags(SplFileObject::READ_CSV);
        $firstLine = (string)$file->fgets();
        if (trim($firstLine) === '') {
            throw new RuntimeException('Arquivo sem conteúdo. Inclua cabeçalho e pelo menos uma linha.');
        }

        $delimiter = $this->detectDelimiter($firstLine);
        $file->rewind();
        $file->setCsvControl($delimiter);

        $headers = $file->fgetcsv();
        if ($this->isEmptyRow($headers)) {
            throw new RuntimeException('Cabeçalho do CSV não foi identificado.');
        }
        $normalizedHeaders = $this->normalizeHeaders($headers);

        if (!in_array('email', $normalizedHeaders, true)) {
            throw new RuntimeException('O campo "email" é obrigatório no cabeçalho.');
        }

        $stats = [
            'processed' => 0,
            'created_contacts' => 0,
            'updated_contacts' => 0,
            'attached' => 0,
            'duplicates_in_file' => 0,
            'invalid' => 0,
        ];
        $errors = [];
        $seenEmails = [];
        $lineNumber = 1; // header already consumed

        while (!$file->eof()) {
            $row = $file->fgetcsv();
            $lineNumber++;
            if ($row === false || $this->isEmptyRow($row)) {
                continue;
            }

            if ($this->maxRows > 0 && $stats['processed'] >= $this->maxRows) {
                throw new RuntimeException(sprintf('Limite de %d linhas por importação atingido.', $this->maxRows));
            }

            $stats['processed']++;
            $assoc = $this->combineRow($normalizedHeaders, $row);
            $email = $this->normalizeEmail($assoc['email'] ?? '');
            if ($email === null) {
                $stats['invalid']++;
                $errors[] = sprintf('Linha %d: e-mail ausente ou inválido.', $lineNumber);
                continue;
            }

            if (isset($seenEmails[$email])) {
                $stats['duplicates_in_file']++;
                $errors[] = sprintf('Linha %d: e-mail duplicado no próprio arquivo (%s).', $lineNumber, $email);
                continue;
            }
            $seenEmails[$email] = true;

            try {
                $result = $this->upsertContact($listId, $email, $assoc, $options);
                $stats['created_contacts'] += $result['created'] ? 1 : 0;
                $stats['updated_contacts'] += $result['updated'] ? 1 : 0;
                $stats['attached'] += $result['attached'] ? 1 : 0;
            } catch (RuntimeException $exception) {
                $stats['invalid']++;
                $errors[] = sprintf('Linha %d: %s', $lineNumber, $exception->getMessage());
            }
        }

        $this->recordImportLog($listId, $options, $stats, $errors);

        return [
            'stats' => $stats,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{source_label?: string|null, respect_opt_out?: bool, user_id?: ?int, filename?: ?string} $options
     * @return array{created: bool, updated: bool, attached: bool}
     */
    private function upsertContact(int $listId, string $email, array $row, array $options): array
    {
        $existing = $this->contacts->findByEmail($email);
        $isNew = $existing === null;
        $contactPayload = $this->buildContactPayload($email, $row, $existing);

        $created = false;
        $updated = false;
        if ($isNew) {
            $contactId = $this->contacts->create($contactPayload);
            $created = true;
        } else {
            $contactId = (int)$existing['id'];
            if ($contactPayload !== []) {
                $this->contacts->update($contactId, $contactPayload);
                $updated = true;
            }
        }

        $this->syncAttributes($contactId, $row);
        $subscriptionStatus = $this->resolveSubscriptionStatus($row, $existing, (bool)($options['respect_opt_out'] ?? true));
        $this->syncConsent($contactId, $row, $existing, $subscriptionStatus, (string)($options['source_label'] ?? '')); 

        $this->lists->attachContact($listId, $contactId, [
            'subscription_status' => $subscriptionStatus,
            'source' => $options['source_label'] ?? null,
            'metadata' => $this->buildListMetadata($row),
            'subscribed_at' => now(),
            'unsubscribed_at' => $subscriptionStatus === 'unsubscribed' ? now() : null,
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'attached' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildContactPayload(string $email, array $row, ?array $existing): array
    {
        $payload = [];
        if ($existing === null) {
            $payload['email'] = $email;
            $payload['status'] = 'active';
            $payload['consent_status'] = 'pending';
            $payload['locale'] = $row['locale'] ?? 'pt_BR';
        }

        $firstName = trim((string)($row['first_name'] ?? ''));
        if ($firstName !== '') {
            $payload['first_name'] = $firstName;
        }

        $lastName = trim((string)($row['last_name'] ?? ''));
        if ($lastName !== '') {
            $payload['last_name'] = $lastName;
        }

        $phone = isset($row['phone']) ? digits_only((string)$row['phone']) : '';
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        $tags = $this->mergeTags($existing['tags'] ?? null, (string)($row['tags'] ?? ''));
        if ($tags !== null && $tags !== ($existing['tags'] ?? null)) {
            $payload['tags'] = $tags;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSubscriptionStatus(array $row, ?array $existing, bool $respectOptOut): string
    {
        $incoming = strtolower(trim((string)($row['consent_status'] ?? '')));
        if ($incoming === 'opted_out') {
            return 'unsubscribed';
        }

        if ($respectOptOut && ($existing['consent_status'] ?? '') === 'opted_out') {
            return 'unsubscribed';
        }

        return 'subscribed';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function syncConsent(int $contactId, array $row, ?array $existing, string $subscriptionStatus, string $sourceLabel): void
    {
        $status = strtolower(trim((string)($row['consent_status'] ?? '')));
        $source = trim((string)($row['consent_source'] ?? $sourceLabel));
        $timestamp = $this->parseDate((string)($row['consent_at'] ?? ''));

        if ($status === 'opted_out') {
            $this->contacts->markOptOut($contactId, $source !== '' ? $source : 'import_csv');
            return;
        }

        if ($subscriptionStatus === 'unsubscribed' && ($existing['consent_status'] ?? '') === 'opted_out') {
            return;
        }

        if ($status === 'confirmed') {
            $this->contacts->recordConsent($contactId, 'confirmed', $source !== '' ? $source : 'import_csv', $timestamp);
            $this->contacts->update($contactId, ['status' => 'active']);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function syncAttributes(int $contactId, array $row): void
    {
        $payload = [];
        foreach ($row as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'custom.')) {
                continue;
            }

            $attributeKey = trim(substr($key, 7));
            if ($attributeKey === '') {
                continue;
            }

            $attributeValue = is_scalar($value) ? (string)$value : null;
            if ($attributeValue === null || $attributeValue === '') {
                continue;
            }

            $payload[$attributeKey] = [
                'value' => $attributeValue,
                'type' => 'string',
            ];
        }

        if ($payload !== []) {
            $this->attributes->upsertMany($contactId, $payload);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildListMetadata(array $row): ?string
    {
        $metadata = [];
        foreach (['tags', 'consent_status', 'consent_source'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $metadata[$key] = $row[$key];
        }

        if ($metadata === []) {
            return null;
        }

        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function recordImportLog(int $listId, array $options, array $stats, array $errors): void
    {
        $meta = [
            'list_id' => $listId,
            'attached' => $stats['attached'],
            'duplicates_in_file' => $stats['duplicates_in_file'],
            'invalid_rows' => $stats['invalid'],
            'errors' => array_slice($errors, 0, 20),
        ];

        $this->logs->record(
            'marketing_contacts',
            (string)($options['filename'] ?? 'contacts.csv'),
            [
                'processed' => $stats['processed'],
                'created_clients' => $stats['created_contacts'],
                'updated_clients' => $stats['updated_contacts'],
                'skipped' => $stats['invalid'],
            ],
            $options['user_id'] ?? null,
            $meta
        );
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

    /**
     * @param array<int|string, mixed>|false $row
     */
    private function isEmptyRow($row): bool
    {
        if ($row === false || $row === null) {
            return true;
        }

        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(static function ($value): string {
            $clean = is_string($value) ? trim($value) : '';
            return mb_strtolower($clean, 'UTF-8');
        }, $headers);
    }

    /**
     * @param array<int, string|null> $headers
     * @param array<int, mixed> $row
     * @return array<string, mixed>
     */
    private function combineRow(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($row as $index => $value) {
            $key = $headers[$index] ?? 'col_' . $index;
            $assoc[$key] = is_string($value) ? trim($value) : $value;
        }

        return $assoc;
    }

    private function normalizeEmail(string $email): ?string
    {
        $normalized = trim(mb_strtolower($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    private function mergeTags(?string $existing, ?string $incoming): ?string
    {
        $current = $this->splitTags($existing);
        $candidates = $this->splitTags($incoming);
        $merged = array_values(array_unique(array_filter(array_merge($current, $candidates))));
        return $merged === [] ? ($existing !== null ? $existing : null) : implode(',', $merged);
    }

    /**
     * @return string[]
     */
    private function splitTags(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[;,]/', $value) ?: [];
        return array_values(array_filter(array_map(static fn(string $part): string => strtolower(trim($part)), $parts)));
    }

    private function parseDate(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        return $timestamp === false ? null : $timestamp;
    }
}
