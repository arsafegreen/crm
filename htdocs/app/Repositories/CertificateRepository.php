<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CertificateRepository
{
    private PDO $pdo;
    private bool $supportsAvpAt;

    public function __construct()
    {
        $this->pdo = Connection::instance();
        $this->supportsAvpAt = $this->tableHasColumn('certificates', 'avp_at');
    }

    public function findByProtocol(string $protocol): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certificates WHERE protocol = :protocol LIMIT 1');
        $stmt->execute([':protocol' => $protocol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return int[]
     */
    public function listClientIdsByAvpCpf(string $cpf): array
    {
        $digits = digits_only($cpf);
        if ($digits === '') {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT DISTINCT client_id FROM certificates WHERE client_id IS NOT NULL AND avp_cpf = :cpf');
        $stmt->bindValue(':cpf', $digits, PDO::PARAM_STR);
        $stmt->execute();

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    }

    /**
     * @return int[]
     */
    public function listClientIdsByAvpName(string $name): array
    {
        $needle = trim($name);
        if ($needle === '') {
            return [];
        }

        $normalized = mb_strtolower($needle, 'UTF-8');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT client_id FROM certificates WHERE client_id IS NOT NULL AND avp_name IS NOT NULL AND LOWER(avp_name) = :needle'
        );
        $stmt->bindValue(':needle', $normalized, PDO::PARAM_STR);
        $stmt->execute();

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    }

    /**
     * @return string[]
     */
    public function listAvpNamesByCpf(string $cpf): array
    {
        $digits = digits_only($cpf);
        if ($digits === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(avp_name) AS label
             FROM certificates
             WHERE avp_cpf = :cpf
               AND avp_name IS NOT NULL
               AND TRIM(avp_name) <> ""'
        );
        $stmt->bindValue(':cpf', $digits, PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $names = [];
        foreach ($rows as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $label = trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
            if ($label === '') {
                continue;
            }
            $names[] = $label;
        }

        return array_values(array_unique($names));
    }

    public function insert(array $data): int
    {
        $timestamp = now();
        $data['created_at'] = $timestamp;
        $data['updated_at'] = $timestamp;

        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO certificates ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixArrayKeys($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = now();
        $fields = array_keys($data);
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), $fields));
        $data['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE certificates SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefixArrayKeys($data));
    }

    public function partnerStats(
        ?int $startAt,
        ?int $endAt,
        ?int $limit = 10,
        string $sortField = 'latest',
        string $sortDirection = 'desc'
    ): array
    {
    $rangeEnd = $endAt ?? now();
    $windowLength = 366 * 86400;
    $defaultStart = max(0, $rangeEnd - $windowLength);
    $windowStart = $startAt !== null ? max($startAt, $defaultStart) : $defaultStart;
    $windowEnd = $rangeEnd;

        $sortField = strtolower($sortField);
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';

        switch ($sortField) {
            case 'partner':
                $orderBy = "p.partner_name {$sortDirection}, last_import_at DESC";
                break;
            case 'total':
                $orderBy = "total {$sortDirection}, last_import_at DESC";
                break;
            case 'latest':
            default:
                $sortField = 'latest';
                $orderBy = "last_start_at {$sortDirection}, last_protocol {$sortDirection}";
                break;
        }

        $importExpr = "COALESCE(cert.updated_at, cert.created_at)";

        $sql = <<<SQL
            WITH partners AS (
                SELECT
                    TRIM(cert.partner_accountant) AS partner_name,
                    cert.protocol,
                    cert.start_at,
                    $importExpr AS import_at
                FROM certificates cert
                WHERE TRIM(cert.partner_accountant) <> ''
                  AND $importExpr >= :import_window_start
                  AND $importExpr <= :import_window_end
                  AND cert.start_at IS NOT NULL
                  AND cert.start_at >= :start_window_start
                  AND cert.start_at <= :start_window_end
        SQL;

        $params = [
            ':import_window_start' => $windowStart,
            ':import_window_end' => $windowEnd,
            ':start_window_start' => $windowStart,
            ':start_window_end' => $windowEnd,
        ];

        $sql .= <<<SQL
                UNION ALL
                SELECT
                    TRIM(cert.partner_accountant_plus) AS partner_name,
                    cert.protocol,
                    cert.start_at,
                    $importExpr AS import_at
                FROM certificates cert
                WHERE TRIM(cert.partner_accountant_plus) <> ''
                                    AND $importExpr >= :import_window_start
                                    AND $importExpr <= :import_window_end
                  AND cert.start_at IS NOT NULL
                  AND cert.start_at >= :start_window_start
                                    AND cert.start_at <= :start_window_end
        SQL;

        $sql .= <<<SQL
            )
            SELECT
                p.partner_name,
                COUNT(*) AS total,
                (
                    SELECT sp.protocol
                    FROM partners sp
                    WHERE sp.partner_name = p.partner_name
                    ORDER BY COALESCE(sp.start_at, 0) DESC, sp.import_at DESC, sp.protocol DESC
                    LIMIT 1
                ) AS last_protocol,
                (
                    SELECT COALESCE(sp.start_at, 0)
                    FROM partners sp
                    WHERE sp.partner_name = p.partner_name
                    ORDER BY COALESCE(sp.start_at, 0) DESC, sp.import_at DESC, sp.protocol DESC
                    LIMIT 1
                ) AS last_start_at
            FROM partners p
            WHERE p.partner_name <> ''
            GROUP BY p.partner_name
            ORDER BY {$orderBy}
        SQL;

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }

    public function partnerMonthlyActivity(string $partnerName, int $months = 12, ?int $endAt = null): array
    {
        $partnerName = trim($partnerName);
        if ($partnerName === '') {
            return [];
        }

        $months = max(1, min(60, $months));

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $referenceTimestamp = $endAt ?? now();
        $referenceDate = (new DateTimeImmutable('@' . $referenceTimestamp))
            ->setTimezone($timezone)
            ->setTime(0, 0, 0)
            ->modify('first day of this month');

        $startMonth = $referenceDate->modify('-' . ($months - 1) . ' months');
        $windowStart = $startMonth->getTimestamp();
        $windowEnd = $referenceDate->modify('+1 month')->getTimestamp();

            $activityExpr = $this->timestampExpression();
        $needle = mb_strtolower($partnerName, 'UTF-8');
        $rawName = $partnerName;

        $stmt = $this->pdo->prepare(
            "SELECT
                strftime('%Y-%m', datetime($activityExpr, 'unixepoch', 'localtime')) AS year_month,
                COUNT(*) AS total
            FROM certificates
            WHERE $activityExpr IS NOT NULL
              AND $activityExpr >= :window_start
              AND $activityExpr < :window_end
              AND (
                    LOWER(TRIM(partner_accountant_plus)) = :needle
                 OR LOWER(TRIM(partner_accountant)) = :needle
                 OR TRIM(partner_accountant_plus) = :raw_name
                 OR TRIM(partner_accountant) = :raw_name
              )
            GROUP BY year_month
            ORDER BY year_month"
        );

        $stmt->bindValue(':window_start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':window_end', $windowEnd, PDO::PARAM_INT);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':raw_name', $rawName, PDO::PARAM_STR);
        $stmt->execute();

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = $row['year_month'] ?? null;
            if ($key === null) {
                continue;
            }
            $totals[$key] = (int)($row['total'] ?? 0);
        }

        $series = [];
        $current = $startMonth;
        for ($i = 0; $i < $months; $i++) {
            $key = $current->format('Y-m');
            $series[] = [
                'key' => $key,
                'label' => $this->formatMonthLabel((int)$current->format('n'), (int)$current->format('Y')),
                'total' => $totals[$key] ?? 0,
            ];
            $current = $current->modify('+1 month');
        }

        return $series;
    }

    public function lastIndicationTimestampForPartner(string $partnerName, ?string $partnerDocument = null): ?int
    {
        $partnerName = trim($partnerName);
        if ($partnerName === '') {
            return null;
        }

        $needle = mb_strtolower($partnerName, 'UTF-8');
        $rawName = $partnerName;
        $referenceExpr = $this->timestampExpression('cert.');
        $document = digits_only($partnerDocument ?? '');

        $sql = "SELECT MAX($referenceExpr) AS last_ts
                FROM certificates cert
                INNER JOIN clients c ON c.id = cert.client_id
                WHERE $referenceExpr IS NOT NULL
                  AND (
                        LOWER(TRIM(cert.partner_accountant_plus)) = :needle
                     OR LOWER(TRIM(cert.partner_accountant)) = :needle
                     OR TRIM(cert.partner_accountant_plus) = :raw
                     OR TRIM(cert.partner_accountant) = :raw
                  )";

        if ($document !== '') {
            $sql .= ' AND (c.document IS NULL OR c.document <> :document)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':raw', $rawName, PDO::PARAM_STR);
        if ($document !== '') {
            $stmt->bindValue(':document', $document, PDO::PARAM_STR);
        }
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $timestamp = (int)$value;
        if ($timestamp <= 0) {
            return null;
        }

        $document = digits_only($partnerDocument ?? '');
        if ($document === '') {
            return $timestamp;
        }

           $timestampColumnExpr = $this->timestampExpression('cert.');
           $clientStmt = $this->pdo->prepare(
              'SELECT c.document, c.titular_document
               FROM certificates cert
               INNER JOIN clients c ON c.id = cert.client_id
               WHERE (
                     LOWER(TRIM(cert.partner_accountant_plus)) = :needle
                   OR LOWER(TRIM(cert.partner_accountant)) = :needle
                   OR TRIM(cert.partner_accountant_plus) = :raw
                   OR TRIM(cert.partner_accountant) = :raw
               )
               AND ' . $timestampColumnExpr . ' = :reference_ts
               ORDER BY cert.id DESC
               LIMIT 1'
           );
        $clientStmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $clientStmt->bindValue(':raw', $rawName, PDO::PARAM_STR);
        $clientStmt->bindValue(':reference_ts', $timestamp, PDO::PARAM_INT);
        $clientStmt->execute();
        $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($clientRow === null) {
            return $timestamp;
        }

        $clientDocument = digits_only((string)($clientRow['document'] ?? ''));
        $titularDocument = digits_only((string)($clientRow['titular_document'] ?? ''));
        if ($clientDocument === $document || ($titularDocument !== '' && $titularDocument === $document)) {
            return null;
        }

        return $timestamp;
    }

    public function partnerValidIndicationCount(string $partnerName, ?string $partnerDocument = null): int
    {
        $partnerName = trim($partnerName);
        if ($partnerName === '') {
            return 0;
        }

        $needle = mb_strtolower($partnerName, 'UTF-8');
        $rawName = $partnerName;
        $document = digits_only($partnerDocument ?? '');
        $referenceExpr = $this->timestampExpression('cert.');

        $sql = "SELECT c.document, c.titular_document
                FROM certificates cert
                INNER JOIN clients c ON c.id = cert.client_id
                WHERE $referenceExpr IS NOT NULL
                  AND (
                        LOWER(TRIM(cert.partner_accountant_plus)) = :needle
                     OR LOWER(TRIM(cert.partner_accountant)) = :needle
                     OR TRIM(cert.partner_accountant_plus) = :raw
                     OR TRIM(cert.partner_accountant) = :raw
                  )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':raw', $rawName, PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $clientDocument = digits_only((string)($row['document'] ?? ''));
            $titularDocument = digits_only((string)($row['titular_document'] ?? ''));

            if ($document !== '') {
                if ($clientDocument === $document) {
                    continue;
                }
                if ($titularDocument !== '' && $titularDocument === $document) {
                    continue;
                }
            }

            $count++;
        }

        return $count;
    }

    public function partnerValidIndicationCountForMonth(
        string $partnerName,
        int $month,
        int $year,
        ?string $partnerDocument = null
    ): int {
        $partnerName = trim($partnerName);
        if ($partnerName === '' || $month < 1 || $month > 12 || $year < 2000) {
            return 0;
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $startDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone))->setTime(0, 0, 0);
        $endDate = $startDate->modify('+1 month');

        $startTs = $startDate->getTimestamp();
        $endTs = $endDate->getTimestamp();

        $needle = mb_strtolower($partnerName, 'UTF-8');
        $rawName = $partnerName;
        $document = digits_only($partnerDocument ?? '');

        $referenceExpr = $this->timestampExpression('cert.');
        $sql = "SELECT c.document, c.titular_document
                FROM certificates cert
                INNER JOIN clients c ON c.id = cert.client_id
                WHERE $referenceExpr IS NOT NULL
                  AND $referenceExpr >= :start_ts
                  AND $referenceExpr < :end_ts
                  AND (
                        LOWER(TRIM(cert.partner_accountant_plus)) = :needle
                     OR LOWER(TRIM(cert.partner_accountant)) = :needle
                     OR TRIM(cert.partner_accountant_plus) = :raw
                     OR TRIM(cert.partner_accountant) = :raw
                  )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start_ts', $startTs, PDO::PARAM_INT);
        $stmt->bindValue(':end_ts', $endTs, PDO::PARAM_INT);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':raw', $rawName, PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return 0;
        }

        if ($document === '') {
            return count($rows);
        }

        $count = 0;
        foreach ($rows as $row) {
            $clientDocument = digits_only((string)($row['document'] ?? ''));
            $titularDocument = digits_only((string)($row['titular_document'] ?? ''));
            if ($clientDocument === $document) {
                continue;
            }
            if ($titularDocument !== '' && $titularDocument === $document) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function partnerHasIndicationInMonth(
        string $partnerName,
        int $month,
        int $year,
        ?string $partnerDocument = null
    ): bool {
        return $this->partnerValidIndicationCountForMonth($partnerName, $month, $year, $partnerDocument) > 0;
    }

    /**
     * @return array<int, array{name:string,count:int}>
     */
    public function partnerNamesWithIndicationInMonth(int $month, int $year, int $limit = 200, int $offset = 0): array
    {
        if ($month < 1 || $month > 12 || $year < 2000) {
            return ['items' => [], 'has_more' => false];
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $fetchLimit = $limit + 1;
        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $startDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone))->setTime(0, 0, 0);
        $endDate = $startDate->modify('+1 month');
        $startTs = $startDate->getTimestamp();
        $endTs = $endDate->getTimestamp();

        $referenceExpr = $this->timestampExpression('cert.');
        $basePartnersSql = "SELECT TRIM(cert.partner_accountant_plus) AS partner_name
                FROM certificates cert
                WHERE $referenceExpr IS NOT NULL
                  AND $referenceExpr >= :start_ts
                  AND $referenceExpr < :end_ts
                  AND TRIM(cert.partner_accountant_plus) <> ''
            UNION ALL
            SELECT TRIM(cert.partner_accountant) AS partner_name
                FROM certificates cert
                WHERE $referenceExpr IS NOT NULL
                  AND $referenceExpr >= :start_ts
                  AND $referenceExpr < :end_ts
                  AND TRIM(cert.partner_accountant) <> ''";

        $groupedSql = "SELECT partner_name, COUNT(*) AS total
            FROM ($basePartnersSql) AS partners
            GROUP BY partner_name";

        $listSql = $groupedSql . '
            ORDER BY total DESC, partner_name ASC
            LIMIT :limit OFFSET :offset';

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $groupedSql . ') AS grouped');
        $countStmt->bindValue(':start_ts', $startTs, PDO::PARAM_INT);
        $countStmt->bindValue(':end_ts', $endTs, PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int)($countStmt->fetchColumn() ?: 0);

        $stmt = $this->pdo->prepare($listSql);
        $stmt->bindValue(':start_ts', $startTs, PDO::PARAM_INT);
        $stmt->bindValue(':end_ts', $endTs, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $results = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['partner_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $results[] = [
                'name' => $name,
                'count' => (int)($row['total'] ?? 0),
            ];
        }

        return [
            'items' => $results,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    private function timestampExpression(string $prefix = ''): string
    {
        $columnPrefix = $prefix !== '' ? $prefix : '';
        if ($this->supportsAvpAt) {
            return sprintf(
                'COALESCE(%savp_at, %sstart_at, %supdated_at, %screated_at)',
                $columnPrefix,
                $columnPrefix,
                $columnPrefix,
                $columnPrefix
            );
        }

        return sprintf(
            'COALESCE(%sstart_at, %supdated_at, %screated_at)',
            $columnPrefix,
            $columnPrefix,
            $columnPrefix
        );
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('PRAGMA table_info(' . $table . ')');
        if ($stmt === false || $stmt->execute() === false) {
            return false;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $info) {
            if (!isset($info['name'])) {
                continue;
            }
            if (strcasecmp((string)$info['name'], $column) === 0) {
                return true;
            }
        }

        return false;
    }

    private function appendPartnerFilters(string &$sql, array &$params, string $needle, string $rawName): void
    {
        $sql .= ' AND (
                LOWER(TRIM(cert.partner_accountant_plus)) = :needle
             OR LOWER(TRIM(cert.partner_accountant)) = :needle
             OR TRIM(cert.partner_accountant_plus) = :raw
             OR TRIM(cert.partner_accountant) = :raw
        )';
        $params[':needle'] = $needle;
        $params[':raw'] = $rawName;
    }

    /**
     * Build a month-over-month series comparing emissions against the same month last year.
     * If $months is null, the full history (since the first emission) is returned.
     */
    public function monthlyComparative(?int $months = 12, ?int $referenceTimestamp = null): array
    {
        $referenceTimestamp = $referenceTimestamp ?? now();

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $referenceDate = (new DateTimeImmutable('@' . $referenceTimestamp))
            ->setTimezone($timezone)
            ->setTime(0, 0, 0)
            ->modify('first day of this month');

        $periods = [];

        if ($months === null) {
            $minStartStmt = $this->pdo->query('SELECT MIN(start_at) AS min_start FROM certificates WHERE start_at IS NOT NULL');
            $minStart = $minStartStmt !== false ? $minStartStmt->fetchColumn() : false;
            $minStart = $minStart !== false ? (int)$minStart : null;

            if ($minStart === null || $minStart <= 0) {
                return [];
            }

            $firstMonth = (new DateTimeImmutable('@' . $minStart))
                ->setTimezone($timezone)
                ->setTime(0, 0, 0)
                ->modify('first day of this month');

            $current = $firstMonth;
            while ($current <= $referenceDate) {
                $currentEnd = $current->modify('last day of this month 23:59:59');
                $previousStart = $current->modify('-1 year');
                $previousEnd = $currentEnd->modify('-1 year');

                $periods[] = [
                    'key' => $current->format('Y-m'),
                    'previous_key' => $previousStart->format('Y-m'),
                    'month' => (int)$current->format('n'),
                    'year' => (int)$current->format('Y'),
                    'current_start' => $current,
                    'current_end' => $currentEnd,
                    'previous_start' => $previousStart,
                    'previous_end' => $previousEnd,
                ];

                $current = $current->modify('+1 month');
            }
        } else {
            $months = max(1, min(240, $months));

            for ($i = $months - 1; $i >= 0; $i--) {
                $currentStart = $referenceDate->modify("-{$i} months");
                $currentEnd = $currentStart->modify('last day of this month 23:59:59');
                $previousStart = $currentStart->modify('-1 year');
                $previousEnd = $currentEnd->modify('-1 year');

                $periods[] = [
                    'key' => $currentStart->format('Y-m'),
                    'previous_key' => $previousStart->format('Y-m'),
                    'month' => (int)$currentStart->format('n'),
                    'year' => (int)$currentStart->format('Y'),
                    'current_start' => $currentStart,
                    'current_end' => $currentEnd,
                    'previous_start' => $previousStart,
                    'previous_end' => $previousEnd,
                ];
            }
        }

        if ($periods === []) {
            return [];
        }

        $windowStart = max(0, min(array_map(static fn(array $period): int => $period['previous_start']->getTimestamp(), $periods)));
        $windowEnd = max(array_map(static fn(array $period): int => $period['current_end']->getTimestamp(), $periods));

        $stmt = $this->pdo->prepare(
            "SELECT
                strftime('%Y', datetime(start_at, 'unixepoch', 'localtime')) AS year,
                strftime('%m', datetime(start_at, 'unixepoch', 'localtime')) AS month,
                COUNT(*) AS total
            FROM certificates
            WHERE start_at IS NOT NULL
              AND start_at BETWEEN :window_start AND :window_end
            GROUP BY year, month"
        );
        $stmt->bindValue(':window_start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':window_end', $windowEnd, PDO::PARAM_INT);
        $stmt->execute();

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = sprintf('%04d-%02d', (int)$row['year'], (int)$row['month']);
            $totals[$key] = (int)($row['total'] ?? 0);
        }

        $series = [];
        foreach ($periods as $period) {
            $currentKey = $period['key'];
            $previousKey = $period['previous_key'];

            $currentTotal = $totals[$currentKey] ?? 0;
            $previousTotal = $totals[$previousKey] ?? 0;

            $difference = $currentTotal - $previousTotal;
            $differencePercent = null;
            if ($previousTotal > 0) {
                $differencePercent = ($difference / $previousTotal) * 100;
            }

            $series[] = [
                'label' => $this->formatMonthLabel($period['month'], $period['year']),
                'month' => $period['month'],
                'current_year' => $period['year'],
                'current_total' => $currentTotal,
                'previous_year' => (int)$period['previous_start']->format('Y'),
                'previous_total' => $previousTotal,
                'difference' => $difference,
                'difference_percent' => $differencePercent,
                'trend' => $difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'flat'),
            ];
        }

        return $series;
    }

    public function forClient(int $clientId): array
    {
        return $this->forClients([$clientId]);
    }

    public function forClients(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        $clientIds = array_values(array_filter($clientIds, static fn(int $id): bool => $id > 0));

        if ($clientIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($clientIds), '?'));

        $sql = <<<SQL
            SELECT
                cert.*,
                c.document AS client_document,
                c.name AS client_name
            FROM certificates cert
            INNER JOIN clients c ON c.id = cert.client_id
            WHERE cert.client_id IN ($placeholders)
            ORDER BY (cert.start_at IS NULL), cert.start_at ASC, cert.end_at ASC, cert.id ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);

        foreach ($clientIds as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    public function latestForClient(int $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM certificates
             WHERE client_id = :client_id
             ORDER BY CAST(protocol AS INTEGER) DESC,
                      updated_at DESC,
                      CASE WHEN end_at IS NULL THEN 1 ELSE 0 END,
                      end_at DESC,
                      CASE WHEN start_at IS NULL THEN 1 ELSE 0 END,
                      start_at DESC,
                      id DESC
             LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function formatMonthLabel(int $month, int $year): string
    {
        $names = [
            1 => 'Jan',
            2 => 'Fev',
            3 => 'Mar',
            4 => 'Abr',
            5 => 'Mai',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Set',
            10 => 'Out',
            11 => 'Nov',
            12 => 'Dez',
        ];

        $abbr = $names[$month] ?? str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        return sprintf('%s/%d', $abbr, $year);
    }

    private function prefixArrayKeys(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }
}
