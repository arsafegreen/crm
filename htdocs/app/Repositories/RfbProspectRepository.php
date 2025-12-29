<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class RfbProspectRepository
{
    private const STATS_CACHE_TTL = 600; // seconds
    private const OPTIONS_CACHE_VERSION = 3;
    private const OPTIONS_CACHE_TTL = 900; // seconds

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rfb_prospects WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function paginate(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $statusFilter = strtolower(trim((string)($filters['status'] ?? 'active')));
        if (!in_array($statusFilter, ['active', 'excluded', 'all'], true)) {
            $statusFilter = 'active';
        }

        if ($statusFilter === 'active') {
            $where[] = "(exclusion_status IS NULL OR exclusion_status = 'active')";
        } elseif ($statusFilter === 'excluded') {
            $where[] = "exclusion_status = 'excluded'";
        }

        $query = trim((string)($filters['query'] ?? ''));
        if ($query !== '') {
            $normalized = mb_strtolower($query, 'UTF-8');
            $cnpjDigits = digits_only($query);

            $clauses = ['LOWER(company_name) LIKE :query'];
            $params[':query'] = '%' . $normalized . '%';

            if ($cnpjDigits !== '') {
                $clauses[] = 'cnpj LIKE :cnpj_query';
                $params[':cnpj_query'] = '%' . $cnpjDigits . '%';

                if (strlen($cnpjDigits) >= 8) {
                    $clauses[] = "REPLACE(REPLACE(REPLACE(COALESCE(ddd, '') || COALESCE(phone, ''), '-', ''), ' ', ''), '.', '') LIKE :phone_query";
                    $params[':phone_query'] = '%' . $cnpjDigits . '%';
                }
            }

            $clauses[] = 'LOWER(city) LIKE :city_query';
            $params[':city_query'] = '%' . $normalized . '%';

            $clauses[] = 'LOWER(email) LIKE :email_query';
            $params[':email_query'] = '%' . $normalized . '%';

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $state = strtoupper(trim((string)($filters['state'] ?? '')));
        if ($state !== '') {
            $where[] = 'state = :state';
            $params[':state'] = $state;
        }

        $city = trim((string)($filters['city'] ?? ''));
        if ($city !== '') {
            $where[] = 'LOWER(city) LIKE :city_exact';
            $params[':city_exact'] = '%' . mb_strtolower($city, 'UTF-8') . '%';
        }

        $cnae = trim((string)($filters['cnae'] ?? ''));
        if ($cnae !== '') {
            $where[] = 'cnae_code = :cnae';
            $params[':cnae'] = $cnae;
        }

        if (($filters['has_email'] ?? null) === '1') {
            $where[] = "email IS NOT NULL AND TRIM(email) <> ''";
        }

        if (($filters['has_whatsapp'] ?? null) === '1') {
            $where[] = "LENGTH(TRIM(COALESCE(ddd, '') || COALESCE(phone, ''))) >= 10";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM rfb_prospects {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM rfb_prospects {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $perPage)),
            ],
            'filters' => [
                'query' => $query,
                'state' => $state,
                'city' => $city,
                'cnae' => $cnae,
                'has_email' => $filters['has_email'] ?? '',
                'has_whatsapp' => $filters['has_whatsapp'] ?? '',
                'status' => $statusFilter,
            ],
        ];
    }

    public function bulkInsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rfb_prospects (
                    cnpj,
                    company_name,
                    email,
                    activity_started_at,
                    cnae_code,
                    city,
                    state,
                    ddd,
                    phone,
                    responsible_name,
                    responsible_birthdate,
                    source_file,
                    raw_payload,
                    exclusion_status,
                    exclusion_reason,
                    excluded_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :cnpj,
                    :company_name,
                    :email,
                    :activity_started_at,
                    :cnae_code,
                    :city,
                    :state,
                    :ddd,
                    :phone,
                    :responsible_name,
                    :responsible_birthdate,
                    :source_file,
                    :raw_payload,
                    :exclusion_status,
                    :exclusion_reason,
                    :excluded_at,
                    :created_at,
                    :updated_at
                )
                ON CONFLICT(cnpj) DO NOTHING'
            );

            foreach ($rows as $row) {
                $payload = $this->preparePayload($row);
                $stmt->execute($payload);
                $inserted += (int)$stmt->rowCount();
            }

            $this->pdo->commit();
            if ($inserted > 0) {
                $this->forgetStatsCache();
            }
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $inserted;
    }

    public function deleteByCnpjs(array $cnpjs): int
    {
        $cnpjs = $this->normalizeCnpjs($cnpjs);
        if ($cnpjs === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($cnpjs), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM rfb_prospects WHERE cnpj IN (' . $placeholders . ')');
        $stmt->execute($cnpjs);
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            $this->forgetStatsCache();
        }

        return $deleted;
    }

    public function existingCnpjs(array $cnpjs): array
    {
        $cnpjs = $this->normalizeCnpjs($cnpjs);
        if ($cnpjs === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cnpjs), '?'));
        $stmt = $this->pdo->prepare('SELECT cnpj FROM rfb_prospects WHERE cnpj IN (' . $placeholders . ')');
        $stmt->execute($cnpjs);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('strval', $rows);
    }

    public function stats(): array
    {
        $cached = $this->readStatsCache();
        if ($cached !== null) {
            return $cached;
        }

        $activeFilter = "(exclusion_status IS NULL OR exclusion_status = 'active')";
        $total = (int)$this->scalar('SELECT COUNT(*) FROM rfb_prospects WHERE ' . $activeFilter);
        $withEmail = (int)$this->scalar('SELECT COUNT(*) FROM rfb_prospects WHERE ' . $activeFilter . ' AND email IS NOT NULL AND TRIM(email) <> ""');
        $uniqueCities = (int)$this->scalar('SELECT COUNT(DISTINCT city) FROM rfb_prospects WHERE ' . $activeFilter . ' AND city IS NOT NULL AND TRIM(city) <> ""');
        $uniqueStates = (int)$this->scalar('SELECT COUNT(DISTINCT state) FROM rfb_prospects WHERE ' . $activeFilter . ' AND state IS NOT NULL AND TRIM(state) <> ""');
        $excludedTotal = (int)$this->scalar("SELECT COUNT(*) FROM rfb_prospects WHERE exclusion_status = 'excluded'");

        $stats = [
            'total' => $total,
            'with_email' => $withEmail,
            'unique_cities' => $uniqueCities,
            'unique_states' => $uniqueStates,
            'excluded_total' => $excludedTotal,
            'generated_at' => now(),
        ];

        $this->writeStatsCache($stats);

        return $stats;
    }

    public function latestEntries(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->pdo->prepare("SELECT * FROM rfb_prospects WHERE exclusion_status IS NULL OR exclusion_status = 'active' ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markExcluded(int $id, string $reason): bool
    {
        if ($id <= 0) {
            return false;
        }

        $allowedReasons = ['email_error', 'complaint', 'missing_contact', 'manual'];
        if (!in_array($reason, $allowedReasons, true)) {
            $reason = 'manual';
        }

        $stmt = $this->pdo->prepare(
            "UPDATE rfb_prospects
                SET exclusion_status = 'excluded',
                    exclusion_reason = :reason,
                    excluded_at = :ts,
                    updated_at = :updated
              WHERE id = :id"
        );

        $timestamp = now();
        $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
        $stmt->bindValue(':ts', $timestamp, PDO::PARAM_INT);
        $stmt->bindValue(':updated', $timestamp, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $stmt->execute();

        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->forgetStatsCache();
        }

        return $updated;
    }

    public function restore(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE rfb_prospects
                SET exclusion_status = 'active',
                    exclusion_reason = NULL,
                    excluded_at = NULL,
                    updated_at = :updated
              WHERE id = :id"
        );

        $timestamp = now();
        $stmt->bindValue(':updated', $timestamp, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->forgetStatsCache();
        }

        return $updated;
    }

    public function updateContact(int $id, ?string $email, ?string $ddd, ?string $phone, ?string $responsibleName, ?int $responsibleBirthdate): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rfb_prospects
                SET email = :email,
                    ddd = :ddd,
                    phone = :phone,
                    responsible_name = :responsible_name,
                    responsible_birthdate = :responsible_birthdate,
                    updated_at = :updated
              WHERE id = :id'
        );

        $stmt->bindValue(':email', $email !== null ? strtolower($email) : null);
        $stmt->bindValue(':ddd', $ddd !== null && $ddd !== '' ? $ddd : null);
        $stmt->bindValue(':phone', $phone !== null && $phone !== '' ? $phone : null);
        $stmt->bindValue(':responsible_name', $responsibleName !== null && $responsibleName !== '' ? $responsibleName : null);
        if ($responsibleBirthdate !== null) {
            $stmt->bindValue(':responsible_birthdate', $responsibleBirthdate, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':responsible_birthdate', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':updated', now(), PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->forgetStatsCache();
        }

        return $updated;
    }

    public function listCities(?string $state = null, string $search = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stateFilter = strtoupper(trim((string)$state));
        $searchFilter = mb_strtolower(trim($search), 'UTF-8');

        $options = $this->optionCache('cities', function (): array {
            return $this->buildCityOptions();
        });

        $results = [];
        foreach ($options as $option) {
            $optionState = strtoupper((string)($option['state'] ?? ''));
            if ($stateFilter !== '' && $optionState !== $stateFilter) {
                continue;
            }

            $haystack = (string)($option['filter'] ?? '');
            if ($searchFilter !== '' && ($haystack === '' || strpos($haystack, $searchFilter) === false)) {
                continue;
            }

            $results[] = [
                'city' => (string)($option['city'] ?? ''),
                'state' => $optionState,
                'total' => (int)($option['total'] ?? 0),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function listCnaes(string $search = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $searchFilter = mb_strtolower(trim($search), 'UTF-8');

        $options = $this->optionCache('cnaes', function (): array {
            return $this->buildCnaeOptions();
        });

        $results = [];
        foreach ($options as $option) {
            $haystack = (string)($option['filter'] ?? '');
            if ($searchFilter !== '' && ($haystack === '' || strpos($haystack, $searchFilter) === false)) {
                continue;
            }

            $results[] = [
                'cnae' => (string)($option['cnae'] ?? ''),
                'total' => (int)($option['total'] ?? 0),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function preparePayload(array $row): array
    {
        $timestamp = (int)($row['created_at'] ?? now());

        $cnpj = digits_only((string)($row['cnpj'] ?? ''));
        $company = trim((string)($row['company_name'] ?? ''));
        $email = $row['email'] ?? null;
        $city = $row['city'] ?? null;
        $state = $row['state'] ?? null;
        $ddd = $row['ddd'] ?? null;
        $phone = $row['phone'] ?? null;
        $sourceFile = $row['source_file'] ?? null;
        $rawPayload = $row['raw_payload'] ?? null;
        $responsibleName = isset($row['responsible_name']) ? trim((string)$row['responsible_name']) : null;
        $responsibleBirth = $row['responsible_birthdate'] ?? null;

        return [
            ':cnpj' => $cnpj,
            ':company_name' => $company,
            ':email' => $email !== null && $email !== '' ? strtolower((string)$email) : null,
            ':activity_started_at' => $row['activity_started_at'] ?? null,
            ':cnae_code' => $row['cnae_code'] ?? null,
            ':city' => $city !== null && $city !== '' ? mb_strtoupper((string)$city, 'UTF-8') : null,
            ':state' => $state !== null && $state !== '' ? strtoupper((string)$state) : null,
            ':ddd' => $ddd !== null && $ddd !== '' ? $ddd : null,
            ':phone' => $phone !== null && $phone !== '' ? $phone : null,
            ':responsible_name' => $responsibleName !== null && $responsibleName !== '' ? (string)$responsibleName : null,
            ':responsible_birthdate' => $responsibleBirth !== null ? (int)$responsibleBirth : null,
            ':source_file' => $sourceFile !== null && $sourceFile !== '' ? (string)$sourceFile : null,
            ':raw_payload' => is_string($rawPayload) ? $rawPayload : json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':exclusion_status' => $row['exclusion_status'] ?? 'active',
            ':exclusion_reason' => $row['exclusion_reason'] ?? null,
            ':excluded_at' => $row['excluded_at'] ?? null,
            ':created_at' => $timestamp,
            ':updated_at' => (int)($row['updated_at'] ?? $timestamp),
        ];
    }

    private function normalizeCnpjs(array $cnpjs): array
    {
        $normalized = [];
        foreach ($cnpjs as $cnpj) {
            $digits = digits_only((string)$cnpj);
            if ($digits !== '' && strlen($digits) === 14) {
                $normalized[] = $digits;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function rememberOptionValues(array $rows): void
    {
        $this->updateCityAllowlist($rows);
        $this->updateCnaeAllowlist($rows);
    }

    private function updateCityAllowlist(array $rows): void
    {
        $map = $this->buildCityMap($this->loadOptionCache('cities'));

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $city = $this->normalizeCity($row['city'] ?? null);
            $state = $this->normalizeState($row['state'] ?? null);
            if ($city === null || $state === null) {
                continue;
            }

            $key = $this->cityKey($city, $state);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'city' => $city,
                    'state' => $state,
                    'total' => 0,
                    'filter' => mb_strtolower($city . ' ' . $state, 'UTF-8'),
                ];
            }
        }

        if ($map === []) {
            return;
        }

        $this->writeOptionCache('cities', $this->sortCityMap($map));
    }

    private function updateCnaeAllowlist(array $rows): void
    {
        $map = $this->buildCnaeMap($this->loadOptionCache('cnaes'));

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $this->normalizeCnae($row['cnae_code'] ?? null);
            if ($code === null) {
                continue;
            }

            $key = $this->cnaeKey($code);
            if (!isset($map[$key])) {
                $digits = digits_only($code);
                $map[$key] = [
                    'cnae' => $code,
                    'total' => 0,
                    'filter' => trim(mb_strtolower($code, 'UTF-8') . ($digits !== '' ? ' ' . $digits : '')),
                ];
            }
        }

        if ($map === []) {
            return;
        }

        $this->writeOptionCache('cnaes', $this->sortCnaeMap($map));
    }

    private function buildCityMap(?array $list): array
    {
        $map = [];
        foreach ($list ?? [] as $row) {
            $city = $this->normalizeCity($row['city'] ?? null);
            $state = $this->normalizeState($row['state'] ?? null);
            if ($city === null || $state === null) {
                continue;
            }

            $key = $this->cityKey($city, $state);
            $map[$key] = [
                'city' => $city,
                'state' => $state,
                'total' => (int)($row['total'] ?? 0),
                'filter' => mb_strtolower($city . ' ' . $state, 'UTF-8'),
            ];
        }

        return $map;
    }

    private function sortCityMap(array $map): array
    {
        $list = array_values($map);
        usort($list, static function (array $a, array $b): int {
            return strcmp((string)($a['city'] ?? ''), (string)($b['city'] ?? ''));
        });

        return $list;
    }

    private function buildCnaeMap(?array $list): array
    {
        $map = [];
        foreach ($list ?? [] as $row) {
            $code = $this->normalizeCnae($row['cnae'] ?? null);
            if ($code === null) {
                continue;
            }

            $digits = digits_only($code);
            $key = $this->cnaeKey($code);
            $map[$key] = [
                'cnae' => $code,
                'total' => (int)($row['total'] ?? 0),
                'filter' => trim(mb_strtolower($code, 'UTF-8') . ($digits !== '' ? ' ' . $digits : '')),
            ];
        }

        return $map;
    }

    private function sortCnaeMap(array $map): array
    {
        $list = array_values($map);
        usort($list, static function (array $a, array $b): int {
            return strcmp((string)($a['cnae'] ?? ''), (string)($b['cnae'] ?? ''));
        });

        return $list;
    }

    private function normalizeCity($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) < 2) {
            return null;
        }

        if (preg_match('/\d/', $value) === 1) {
            return null;
        }

        if (preg_match('/\p{L}/u', $value) !== 1) {
            return null;
        }

        if ($this->looksLikeCompanyName($value)) {
            return null;
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    private function normalizeState($value): ?string
    {
        $value = strtoupper(trim((string)$value));
        return strlen($value) === 2 ? $value : null;
    }

    private function normalizeCnae($value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function cityKey(string $city, string $state): string
    {
        return $city . '|' . $state;
    }

    private function cnaeKey(string $code): string
    {
        return mb_strtoupper($code, 'UTF-8');
    }

    private function loadOptionCache(string $key): ?array
    {
        $path = $this->optionCachePath($key);
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string)@file_get_contents($path), true);
        if (!is_array($payload)) {
            return null;
        }

        $version = (int)($payload['version'] ?? 0);
        if ($version !== self::OPTIONS_CACHE_VERSION) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $sanitized = $this->sanitizeOptionCache($key, $data);
        if ($sanitized !== $data) {
            $this->writeOptionCache($key, $sanitized);
        }

        return $sanitized;
    }

    private function sanitizeOptionCache(string $key, array $data): array
    {
        if ($key === 'cities') {
            return $this->sortCityMap($this->buildCityMap($data));
        }

        if ($key === 'cnaes') {
            return $this->sortCnaeMap($this->buildCnaeMap($data));
        }

        return array_values(array_filter($data, static fn($row) => is_array($row)));
    }

    private function optionCache(string $key, callable $builder): array
    {
        $cached = $this->loadOptionCache($key);
        if ($cached !== null) {
            return $cached;
        }

        $data = $builder();
        if (!is_array($data)) {
            $data = [];
        }

        $this->writeOptionCache($key, $data);
        return $data;
    }

    private function buildCityOptions(): array
    {
        $sql = "SELECT city, state, COUNT(*) AS total
                FROM rfb_prospects
                WHERE city IS NOT NULL
                  AND TRIM(city) <> ''
                  AND (exclusion_status IS NULL OR exclusion_status = 'active')
                GROUP BY city, state
                ORDER BY city COLLATE NOCASE ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $map = [];
        foreach ($rows ?: [] as $row) {
            $city = $this->normalizeCity($row['city'] ?? null);
            $state = $this->normalizeState($row['state'] ?? null);
            if ($city === null || $state === null) {
                continue;
            }

            $key = $this->cityKey($city, $state);
            $map[$key] = [
                'city' => $city,
                'state' => $state,
                'total' => (int)($row['total'] ?? 0),
                'filter' => mb_strtolower($city . ' ' . $state, 'UTF-8'),
            ];
        }

        return $this->sortCityMap($map);
    }

    private function looksLikeCompanyName(string $value): bool
    {
        $upper = mb_strtoupper($value, 'UTF-8');
        if (preg_match('/\d/', $upper) === 1) {
            return true;
        }

        $companyTerms = '(LTDA|EPP|EIRELI|MEI|ME|S\/A|S\.A\.?|S A|S\. A\.|COMERCIO|SERVICOS|INDUSTRIA|TRANSPORTES|CONSULTORIA|DISTRIBUIDORA|REPRESENTACOES|SOLUCOES|TECNOLOGIA|IMPORTACAO|EXPORTACAO|HOLDING)';
        return preg_match('/\b' . $companyTerms . '\b/u', $upper) === 1;
    }

    private function buildCnaeOptions(): array
    {
        $sql = "SELECT cnae_code, COUNT(*) AS total
                FROM rfb_prospects
                WHERE cnae_code IS NOT NULL
                  AND TRIM(cnae_code) <> ''
                  AND (exclusion_status IS NULL OR exclusion_status = 'active')
                GROUP BY cnae_code
                ORDER BY cnae_code ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $map = [];
        foreach ($rows ?: [] as $row) {
            $code = $this->normalizeCnae($row['cnae_code'] ?? null);
            if ($code === null) {
                continue;
            }

            $digits = digits_only($code);
            $key = $this->cnaeKey($code);
            $map[$key] = [
                'cnae' => $code,
                'total' => (int)($row['total'] ?? 0),
                'filter' => trim(mb_strtolower($code, 'UTF-8') . ($digits !== '' ? ' ' . $digits : '')),
            ];
        }

        return $this->sortCnaeMap($map);
    }

    private function optionCachePath(string $key): string
    {
        return storage_path('cache' . DIRECTORY_SEPARATOR . 'rfb_options_' . $key . '.json');
    }

    private function writeOptionCache(string $key, array $data): void
    {
        $path = $this->optionCachePath($key);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'expires_at' => now() + self::OPTIONS_CACHE_TTL,
            'version' => self::OPTIONS_CACHE_VERSION,
            'data' => array_values($data),
        ];

        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function forgetOptionCache(?string $key = null): void
    {
        if ($key !== null) {
            $path = $this->optionCachePath($key);
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }

        $pattern = storage_path('cache' . DIRECTORY_SEPARATOR . 'rfb_options_*.json');
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function statsCachePath(): string
    {
        return storage_path('cache' . DIRECTORY_SEPARATOR . 'rfb_stats.json');
    }

    private function readStatsCache(): ?array
    {
        $path = $this->statsCachePath();
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string)@file_get_contents($path), true);
        if (!is_array($payload)) {
            return null;
        }

        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($expiresAt < now()) {
            @unlink($path);
            return null;
        }

        $data = $payload['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    private function writeStatsCache(array $stats): void
    {
        $directory = storage_path('cache');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'expires_at' => now() + self::STATS_CACHE_TTL,
            'data' => $stats,
        ];

        @file_put_contents($this->statsCachePath(), json_encode($payload));
    }

    private function forgetStatsCache(): void
    {
        $path = $this->statsCachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
