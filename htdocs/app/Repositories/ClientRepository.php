<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use function digits_only;

final class ClientRepository
{
    private PDO $pdo;
    private ?bool $clientsHasExtraPhonesColumn = null;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findWithAccess(int $id, ?array $restrictedAvpNames = null, bool $allowOnline = false): ?array
    {
        $params = [':id' => $id];
        $where = ['id = :id'];

        $avpClause = $this->buildAvpAccessClause($restrictedAvpNames, $allowOnline, $params, 'avp_find');
        if ($avpClause !== null) {
            $where[] = $avpClause;
        }

        $sql = 'SELECT * FROM clients WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByDocument(string $document): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE document = :document LIMIT 1');
        $stmt->execute([':document' => $document]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByEmail(string $email): ?array
    {
        $normalized = trim(mb_strtolower($email));
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByProtocol(string $protocolNumber): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM client_protocols cp INNER JOIN clients c ON c.id = cp.client_id WHERE cp.protocol_number = :protocol LIMIT 1'
        );
        $stmt->execute([':protocol' => $protocolNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findByPhoneDigits(string $rawPhone): ?array
    {
        $digits = preg_replace('/\D+/', '', $rawPhone) ?: '';
        if ($digits === '') {
            return null;
        }

        $variants = $this->buildPhoneVariants($digits);
        if ($variants === []) {
            return null;
        }

        $expression = static function (string $column): string {
            return sprintf(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(%s,''), '+',''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')",
                $column
            );
        };

        $sql = sprintf(
            'SELECT * FROM clients
             WHERE %1$s = :phone OR %2$s = :whatsapp
             ORDER BY updated_at DESC
             LIMIT 1',
            $expression('phone'),
            $expression('whatsapp')
        );

        $stmt = $this->pdo->prepare($sql);
        $extraStmt = null;
        if ($this->hasClientsExtraPhonesColumn()) {
            $extraStmt = $this->pdo->prepare(
                'SELECT * FROM clients
                 WHERE extra_phones IS NOT NULL AND extra_phones != "" AND extra_phones LIKE :needle
                 ORDER BY updated_at DESC
                 LIMIT 1'
            );
        }
        foreach ($variants as $variant) {
            $stmt->execute([
                ':phone' => $variant,
                ':whatsapp' => $variant,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }

            if ($extraStmt !== null) {
                $needle = '%"' . $variant . '"%';
                $extraStmt->execute([':needle' => $needle]);
                $row = $extraStmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false && $this->extraPhonesContains($row, $variant)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function hasClientsExtraPhonesColumn(): bool
    {
        if ($this->clientsHasExtraPhonesColumn !== null) {
            return $this->clientsHasExtraPhonesColumn;
        }

        try {
            $stmt = $this->pdo->query('PRAGMA table_info(clients)');
            $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'extra_phones') {
                    $this->clientsHasExtraPhonesColumn = true;
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // ignore and mark as absent
        }

        $this->clientsHasExtraPhonesColumn = false;
        return false;
    }

    public function findByNameExact(string $name): ?array
    {
        $normalized = trim(mb_strtolower($name));
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE LOWER(name) = :name ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([':name' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function buildPhoneVariants(string $digits): array
    {
        $queue = [$digits];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === '' || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $length = strlen($current);

            // Remove country code 55 (Brasil) if presente
            if ($length > 2 && str_starts_with($current, '55')) {
                $queue[] = substr($current, 2);
            }

            // Ajustar nono dígito móvel (após DDD)
            if ($length === 11 && $current[2] === '9') {
                $queue[] = substr($current, 0, 2) . substr($current, 3);
            } elseif ($length === 10) {
                $queue[] = substr($current, 0, 2) . '9' . substr($current, 2);
            }
        }

        return array_values(array_keys($seen));
    }

    private function extraPhonesContains(array $row, string $phoneDigits): bool
    {
        if (!isset($row['extra_phones']) || !is_string($row['extra_phones'])) {
            return false;
        }

        $decoded = json_decode((string)$row['extra_phones'], true);
        if (!is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $value) {
            $digits = digits_only((string)$value);
            if ($digits !== '' && $digits === $phoneDigits) {
                return true;
            }
        }

        return false;
    }

    public function listByTitularDocument(string $document): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, document, status FROM clients WHERE titular_document = :document ORDER BY name ASC'
        );
        $stmt->execute([':document' => $document]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function insert(array $data): int
    {
        $timestamp = now();
        $data['created_at'] = $timestamp;
        $data['updated_at'] = $timestamp;

        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO clients ({$columns}) VALUES ({$placeholders})");
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

        $stmt = $this->pdo->prepare("UPDATE clients SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefixArrayKeys($data));
    }

    public function metrics(): array
    {
        $now = now();
        $day = 86400;

        $counts = function (string $sql, array $params = []): int {
            return (int)($this->scalar($sql, $params) ?? 0);
        };

    $total = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 0');
        $recentExpired = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = :status', [':status' => 'recent_expired']);
        $inactiveStatus = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = :status', [':status' => 'inactive']);
        $lostStatus = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = :status', [':status' => 'lost']);
        $prospectStatus = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = :status', [':status' => 'prospect']);

        $totalProtocols = $counts('SELECT COUNT(*) FROM certificates');

        $activeValid = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "active" AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at >= :now',
            [':now' => $now]
        );

        $expiring010 = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status IN ("active", "recent_expired") AND last_certificate_expires_at BETWEEN :start AND :end',
            [
                ':start' => $now,
                ':end' => $now + (10 * $day),
            ]
        );

        $expiring1120 = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "active" AND last_certificate_expires_at BETWEEN :start AND :end',
            [
                ':start' => $now + (10 * $day) + 1,
                ':end' => $now + (20 * $day),
            ]
        );

        $expiring2130 = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "active" AND last_certificate_expires_at BETWEEN :start AND :end',
            [
                ':start' => $now + (20 * $day) + 1,
                ':end' => $now + (30 * $day),
            ]
        );

        $expired30 = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "recent_expired" AND last_certificate_expires_at BETWEEN :start AND :end',
            [
                ':start' => $now - (30 * $day),
                ':end' => $now,
            ]
        );

        $inactiveExpired = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at < :now',
            [':now' => $now]
        );

        $lostOff = $counts('SELECT COUNT(*) FROM clients WHERE is_off = 1');

        $prospectionWindow = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND last_certificate_expires_at IS NOT NULL AND ((last_certificate_expires_at BETWEEN :now AND :future_limit) OR (last_certificate_expires_at BETWEEN :past_limit AND :now))',
            [
                ':now' => $now,
                ':future_limit' => $now + (40 * $day),
                ':past_limit' => $now - (20 * $day),
            ]
        );

        $activeCnpj = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "active" AND LENGTH(document) = 14 AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at >= :now',
            [':now' => $now]
        );

        $activeCpf = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND status = "active" AND LENGTH(document) = 11 AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at >= :now',
            [':now' => $now]
        );

        $windowCnpj = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND LENGTH(document) = 14 AND last_certificate_expires_at IS NOT NULL AND ((last_certificate_expires_at BETWEEN :now AND :future_limit) OR (last_certificate_expires_at BETWEEN :past_limit AND :now))',
            [
                ':now' => $now,
                ':future_limit' => $now + (40 * $day),
                ':past_limit' => $now - (20 * $day),
            ]
        );

        $windowCpf = $counts(
            'SELECT COUNT(*) FROM clients WHERE is_off = 0 AND LENGTH(document) = 11 AND last_certificate_expires_at IS NOT NULL AND ((last_certificate_expires_at BETWEEN :now AND :future_limit) OR (last_certificate_expires_at BETWEEN :past_limit AND :now))',
            [
                ':now' => $now,
                ':future_limit' => $now + (40 * $day),
                ':past_limit' => $now - (20 * $day),
            ]
        );

        $cnpjTotal = $counts('SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 14');
        $cpfTotal = $counts('SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 11');

        $cnpjRevoked = $counts(
            'SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 14 AND document_status = "dropped"'
        );

        $cpfRevoked = $counts(
            'SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 11 AND document_status = "dropped"'
        );

        $cnpjExpired = $counts(
            'SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 14 AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at < :now',
            [':now' => $now]
        );

        $cpfExpired = $counts(
            'SELECT COUNT(*) FROM clients WHERE LENGTH(document) = 11 AND last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at < :now',
            [':now' => $now]
        );

        $cpfWithCnpjProtocols = $counts(
            'SELECT COUNT(DISTINCT c.titular_document) FROM clients c WHERE c.titular_document IS NOT NULL AND LENGTH(c.titular_document) = 11 AND EXISTS (SELECT 1 FROM certificates cert INNER JOIN clients owner ON owner.id = cert.client_id WHERE owner.titular_document = c.titular_document)'
        );

        return [
            'total' => $total,
            'active' => $activeValid,
            'recent_expired' => $recentExpired,
            'inactive' => $inactiveStatus,
            'lost' => $lostStatus,
            'prospect' => $prospectStatus,
            'expiring_0_10' => $expiring010,
            'expiring_11_20' => $expiring1120,
            'expiring_21_30' => $expiring2130,
            'expired_30' => $expired30,
            'total_protocols' => $totalProtocols,
            'active_valid' => $activeValid,
            'inactive_expired' => $inactiveExpired,
            'lost_off' => $lostOff,
            'prospection_window' => $prospectionWindow,
            'active_cnpj' => $activeCnpj,
            'active_cpf' => $activeCpf,
            'window_cnpj' => $windowCnpj,
            'window_cpf' => $windowCpf,
            'cnpj_total' => $cnpjTotal,
            'cnpj_revoked' => $cnpjRevoked,
            'cnpj_expired' => $cnpjExpired,
            'cpf_total' => $cpfTotal,
            'cpf_revoked' => $cpfRevoked,
            'cpf_expired' => $cpfExpired,
            'cpf_with_cnpj_protocols' => $cpfWithCnpjProtocols,
        ];
    }

    public function statusLabels(): array
    {
        return [
            '' => 'Todos',
            'active' => 'Ativo',
            'recent_expired' => 'Recém vencido',
            'inactive' => 'Inativo',
            'lost' => 'Perdido',
            'prospect' => 'Prospecção',
            'scheduled' => 'Agendado',
            'notify' => 'Avisar',
        ];
    }

    public function paginate(int $page = 1, int $perPage = 25, array $filters = [], ?array $restrictedAvpNames = null, bool $allowOnline = false): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $today = new DateTimeImmutable('today', $timezone);

        $offScope = (string)($filters['off_scope'] ?? 'active');
        if (!in_array($offScope, ['active', 'off', 'all'], true)) {
            $offScope = 'active';
        }

        if ($offScope === 'active') {
            $where[] = 'is_off = 0';
        } elseif ($offScope === 'off') {
            $where[] = 'is_off = 1';
        }

        $status = $filters['status'] ?? '';
        if ($status !== '' && array_key_exists($status, $this->statusLabels())) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $query = trim((string)($filters['query'] ?? ''));
        if ($query !== '') {
            $normalized = mb_strtolower($query);
            $docQuery = digits_only($query);

            $clauses = ['LOWER(name) LIKE :query', 'LOWER(titular_name) LIKE :query'];
            if ($docQuery !== '') {
                $clauses[] = 'document LIKE :doc_query';
                $params[':doc_query'] = '%' . $docQuery . '%';
            }

            $clauses[] = 'EXISTS (SELECT 1 FROM client_protocols cp WHERE cp.client_id = clients.id AND LOWER(cp.protocol_number) LIKE :protocol_query)';

            $where[] = '(' . implode(' OR ', $clauses) . ')';
            $params[':query'] = '%' . $normalized . '%';
            $params[':protocol_query'] = '%' . $normalized . '%';
        }

        $partner = trim((string)($filters['partner'] ?? ''));
        if ($partner !== '') {
            $where[] = '((partner_accountant IS NOT NULL AND LOWER(partner_accountant) LIKE :partner) OR (partner_accountant_plus IS NOT NULL AND LOWER(partner_accountant_plus) LIKE :partner))';
            $params[':partner'] = '%' . mb_strtolower($partner) . '%';
        }

        $lastAvpName = trim((string)($filters['last_avp_name'] ?? ''));
        if ($lastAvpName !== '') {
            $where[] = '(last_avp_name IS NOT NULL AND LOWER(last_avp_name) LIKE :last_avp_name)';
            $params[':last_avp_name'] = '%' . mb_strtolower($lastAvpName) . '%';
        }

        $windowOptions = [
            'next_30' => ['start' => 21, 'end' => 30],
            'next_20' => ['start' => 11, 'end' => 20],
            'next_10' => ['start' => 1, 'end' => 10],
            'today' => ['start' => -9, 'end' => 0],
            'past_10' => ['start' => -19, 'end' => -10],
            'past_20' => ['start' => -29, 'end' => -20],
            'past_30' => ['start' => -40, 'end' => -30],
        ];

        $expirationWindow = (string)($filters['expiration_window'] ?? '');
        if (!isset($windowOptions[$expirationWindow])) {
            $expirationWindow = '';
        } else {
            $window = $windowOptions[$expirationWindow];
            $startDate = $today->modify(sprintf('%+d days', $window['start']))->setTime(0, 0, 0);
            $endDate = $today->modify(sprintf('%+d days', $window['end']))->setTime(23, 59, 59);

            $where[] = '(last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at BETWEEN :expiration_range_start AND :expiration_range_end)';
            $params[':expiration_range_start'] = $startDate->getTimestamp();
            $params[':expiration_range_end'] = $endDate->getTimestamp();
        }

        $expirationMonth = (int)($filters['expiration_month'] ?? 0);
        if ($expirationMonth < 1 || $expirationMonth > 12) {
            $expirationMonth = 0;
        }

        $expirationScope = (string)($filters['expiration_scope'] ?? 'current');
        if (!in_array($expirationScope, ['current', 'all'], true)) {
            $expirationScope = 'current';
        }

        $currentYear = (int)date('Y');
        $expirationYear = (int)($filters['expiration_year'] ?? $currentYear);
        if ($expirationYear < 2000 || $expirationYear > 2100) {
            $expirationYear = $currentYear;
        }

        if ($expirationMonth > 0) {
            $where[] = 'last_certificate_expires_at IS NOT NULL';
            $where[] = "strftime('%m', datetime(last_certificate_expires_at, 'unixepoch', 'localtime')) = :expiration_month";
            $params[':expiration_month'] = str_pad((string)$expirationMonth, 2, '0', STR_PAD_LEFT);

            if ($expirationScope === 'current') {
                $where[] = "strftime('%Y', datetime(last_certificate_expires_at, 'unixepoch', 'localtime')) = :expiration_year";
                $params[':expiration_year'] = (string)$expirationYear;
            }
        }

        $expirationDateStart = $filters['expiration_date_start'] ?? null;
        $expirationDateEnd = $filters['expiration_date_end'] ?? null;
        if (is_int($expirationDateStart) && is_int($expirationDateEnd) && $expirationDateEnd >= $expirationDateStart) {
            $where[] = '(last_certificate_expires_at IS NOT NULL AND last_certificate_expires_at BETWEEN :expiration_date_start AND :expiration_date_end)';
            $params[':expiration_date_start'] = $expirationDateStart;
            $params[':expiration_date_end'] = $expirationDateEnd;
        }

        $documentType = (string)($filters['document_type'] ?? '');
        if ($documentType === 'cpf') {
            $where[] = 'LENGTH(document) = 11';
        } elseif ($documentType === 'cnpj') {
            $where[] = 'LENGTH(document) = 14';
        }

        $birthdayMonth = (int)($filters['birthday_month'] ?? 0);
        if ($birthdayMonth < 1 || $birthdayMonth > 12) {
            $birthdayMonth = 0;
        }

        $birthdayDay = (int)($filters['birthday_day'] ?? 0);
        if ($birthdayDay < 1 || $birthdayDay > 31) {
            $birthdayDay = 0;
        }

        if ($birthdayMonth > 0) {
            $where[] = 'titular_birthdate IS NOT NULL';
            $where[] = "strftime('%m', datetime(titular_birthdate, 'unixepoch', 'localtime')) = :birthday_month";
            $params[':birthday_month'] = str_pad((string)$birthdayMonth, 2, '0', STR_PAD_LEFT);

            if ($birthdayDay > 0) {
                $where[] = "strftime('%d', datetime(titular_birthdate, 'unixepoch', 'localtime')) = :birthday_day";
                $params[':birthday_day'] = str_pad((string)$birthdayDay, 2, '0', STR_PAD_LEFT);
            } else {
                $birthdayDay = 0;
            }
        } else {
            $birthdayDay = 0;
        }

        $pipelineStageId = (int)($filters['pipeline_stage_id'] ?? 0);
        if ($pipelineStageId > 0) {
            $where[] = 'pipeline_stage_id = :pipeline_stage_id';
            $params[':pipeline_stage_id'] = $pipelineStageId;
        }

        $avpClause = $this->buildAvpAccessClause($restrictedAvpNames, $allowOnline, $params, 'avp_paginate');
        if ($avpClause !== null) {
            $where[] = $avpClause;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $select = 'SELECT clients.*, (
                SELECT CASE WHEN EXISTS (
                    SELECT 1 FROM partners p
                    WHERE p.client_id = clients.id
                       OR (p.client_id IS NULL AND p.document IS NOT NULL AND p.document = clients.document)
                ) THEN 1 ELSE 0 END
            ) AS is_partner_indicator
            FROM clients';

        $sql = "$select {$whereSql} ORDER BY (last_certificate_expires_at IS NULL), last_certificate_expires_at DESC, updated_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countSql = "SELECT COUNT(*) FROM clients {$whereSql}";
        $total = (int)$this->scalar($countSql, $params);
        $pages = $total > 0 ? (int)ceil($total / $perPage) : 1;

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, $pages),
            ],
            'filters' => [
                'status' => $status,
                'query' => $query,
                'partner' => $partner,
                'expiration_window' => $expirationWindow,
                'expiration_month' => $expirationMonth,
                'expiration_scope' => $expirationScope,
                'expiration_year' => $expirationYear,
                'expiration_date' => (string)($filters['expiration_date'] ?? ''),
                'document_type' => $documentType,
                'birthday_month' => $birthdayMonth,
                'birthday_day' => $birthdayDay,
                'off_scope' => $offScope,
                'last_avp_name' => $lastAvpName,
            ],
            'meta' => [
                'count' => count($data),
                'total' => $total,
            ],
        ];
    }

    public function recipientsByBirthday(int $month, int $day): array
    {
        $month = max(1, min(12, $month));
        $day = max(1, min(31, $day));

        $stmt = $this->pdo->prepare(
            'SELECT id, name, titular_name, document, titular_document, titular_birthdate,
                    phone, whatsapp, extra_phones, status, last_certificate_expires_at, updated_at, is_off
             FROM clients
             WHERE is_off = 0
               AND titular_birthdate IS NOT NULL
               AND strftime("%m", datetime(titular_birthdate, "unixepoch", "localtime")) = :month
               AND strftime("%d", datetime(titular_birthdate, "unixepoch", "localtime")) = :day'
        );

        $stmt->execute([
            ':month' => str_pad((string)$month, 2, '0', STR_PAD_LEFT),
            ':day' => str_pad((string)$day, 2, '0', STR_PAD_LEFT),
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['id'] = (int)($row['id'] ?? 0);
            $row['last_certificate_expires_at'] = isset($row['last_certificate_expires_at']) && $row['last_certificate_expires_at'] !== null
                ? (int)$row['last_certificate_expires_at']
                : null;
            $row['titular_birthdate'] = isset($row['titular_birthdate']) && $row['titular_birthdate'] !== null
                ? (int)$row['titular_birthdate']
                : null;
            $row['updated_at'] = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            return $row;
        }, $rows);
    }

    public function recipientsByExpirationDay(int $month, int $day, string $scope = 'current', ?int $referenceYear = null): array
    {
        $month = max(1, min(12, $month));
        $day = max(1, min(31, $day));
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $referenceYear = $referenceYear ?? (int)date('Y');

        $sql = 'SELECT id, name, titular_name, document, titular_document, phone, whatsapp, extra_phones, status, last_certificate_expires_at, updated_at, is_off
                FROM clients
                WHERE is_off = 0
                  AND last_certificate_expires_at IS NOT NULL
                  AND strftime("%m", datetime(last_certificate_expires_at, "unixepoch", "localtime")) = :month
                  AND strftime("%d", datetime(last_certificate_expires_at, "unixepoch", "localtime")) = :day';

        if ($scope === 'current') {
            $sql .= ' AND strftime("%Y", datetime(last_certificate_expires_at, "unixepoch", "localtime")) = :year';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':month', str_pad((string)$month, 2, '0', STR_PAD_LEFT), PDO::PARAM_STR);
        $stmt->bindValue(':day', str_pad((string)$day, 2, '0', STR_PAD_LEFT), PDO::PARAM_STR);
        if ($scope === 'current') {
            $stmt->bindValue(':year', (string)$referenceYear, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['id'] = (int)($row['id'] ?? 0);
            $row['last_certificate_expires_at'] = isset($row['last_certificate_expires_at']) && $row['last_certificate_expires_at'] !== null
                ? (int)$row['last_certificate_expires_at']
                : null;
            $row['updated_at'] = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            return $row;
        }, $rows);
    }

    public function partnerIndicators(?string $scope = null): array
    {
        $allowedScopes = ['active', 'off', 'all'];
        $scope = in_array($scope, $allowedScopes, true) ? $scope : null;

        $sql = <<<SQL
            SELECT
                TRIM(partner_accountant_plus) AS partner_name,
                COUNT(*) AS total
            FROM clients
            WHERE partner_accountant_plus IS NOT NULL
              AND TRIM(partner_accountant_plus) <> ''
        SQL;

        if ($scope === 'active') {
            $sql .= "\n              AND is_off = 0";
        } elseif ($scope === 'off') {
            $sql .= "\n              AND is_off = 1";
        }

        $sql .= "\n            GROUP BY TRIM(partner_accountant_plus)
            ORDER BY total DESC, partner_name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'name' => (string)($row['partner_name'] ?? ''),
                'total' => (int)($row['total'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return int[]
     */
    public function allIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM clients');
        if ($stmt === false) {
            return [];
        }

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_map('intval', $ids));
    }

    public function searchForPartner(?string $query, int $limit = 10, array $options = []): array
    {
        $query = trim((string)$query);
        $limit = max(1, min(50, $limit));

        $listWhenEmpty = (bool)($options['list_when_empty'] ?? false);
        $onlyWithoutPartner = (bool)($options['only_without_partner'] ?? false);
        $documentType = isset($options['document_type']) ? strtolower((string)$options['document_type']) : null;
        if (!in_array($documentType, ['cpf', 'cnpj'], true)) {
            $documentType = null;
        }

        if ($query === '' && !$listWhenEmpty) {
            return [];
        }

        $params = [':limit' => $limit];
        $where = [];

        if ($query !== '') {
            $document = digits_only($query);
            $clauses = ['LOWER(name) LIKE :name_query', 'LOWER(titular_name) LIKE :name_query'];
            $params[':name_query'] = '%' . mb_strtolower($query) . '%';

            if ($document !== '') {
                $clauses[] = 'document LIKE :document_query';
                $params[':document_query'] = '%' . $document . '%';
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $partnerClause = 'EXISTS (
            SELECT 1 FROM partners p
            WHERE (p.client_id IS NOT NULL AND p.client_id = clients.id)
               OR (p.client_id IS NULL AND p.document IS NOT NULL AND p.document = clients.document)
        )';

        if ($onlyWithoutPartner) {
            $where[] = 'NOT ' . $partnerClause;
        }

        if ($documentType === 'cpf') {
            $where[] = 'LENGTH(clients.document) = 11';
        } elseif ($documentType === 'cnpj') {
            $where[] = 'LENGTH(clients.document) = 14';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $sql = 'SELECT
                                        clients.id,
                                        clients.name,
                                        clients.document,
                                        clients.status,
                                        clients.partner_accountant,
                                        clients.partner_accountant_plus,
                                        clients.last_certificate_expires_at,
                                        CASE WHEN ' . $partnerClause . ' THEN 1 ELSE 0 END AS has_partner,
                                        (
                                                SELECT p.id FROM partners p
                                                WHERE (
                                                                p.client_id IS NOT NULL AND p.client_id = clients.id
                                                            )
                                                     OR (
                                                                p.client_id IS NULL AND p.document IS NOT NULL AND p.document = clients.document
                                                            )
                                                ORDER BY p.updated_at DESC, p.id DESC
                                                LIMIT 1
                                        ) AS partner_id
                                FROM clients
                                ' . $whereSql . '
                                ORDER BY has_partner ASC, clients.updated_at DESC
                                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $documentValue = (string)($row['document'] ?? '');
            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'document' => $documentValue,
                'document_formatted' => format_document($documentValue),
                'status' => (string)($row['status'] ?? ''),
                'partner_accountant' => $row['partner_accountant'] ?? null,
                'partner_accountant_plus' => $row['partner_accountant_plus'] ?? null,
                'last_certificate_expires_at' => $row['last_certificate_expires_at'] ?? null,
                'has_partner' => ((int)($row['has_partner'] ?? 0)) === 1,
                'partner_id' => isset($row['partner_id']) && $row['partner_id'] !== null ? (int)$row['partner_id'] : null,
            ];
        }, $rows);
    }

    public function recipientsByExpirationMonth(int $month, string $scope = 'current', ?int $referenceYear = null): array
    {
        $month = max(1, min(12, $month));
        $monthValue = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $referenceYear = $referenceYear ?? (int)date('Y');

        $sql = <<<SQL
            SELECT
                c.*,
                cert.id AS certificate_id,
                cert.protocol AS certificate_protocol,
                cert.end_at AS certificate_end_at
            FROM clients c
            LEFT JOIN certificates cert ON cert.protocol = c.last_protocol
            WHERE c.email IS NOT NULL
              AND TRIM(c.email) <> ''
              AND c.last_certificate_expires_at IS NOT NULL
              AND strftime('%m', datetime(c.last_certificate_expires_at, 'unixepoch', 'localtime')) = :month
        SQL;

        if ($scope === 'current') {
            $sql .= "\n              AND strftime('%Y', datetime(c.last_certificate_expires_at, 'unixepoch', 'localtime')) = :year";
        }

        $sql .= "\n            ORDER BY c.last_certificate_expires_at ASC, c.updated_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':month', $monthValue, PDO::PARAM_STR);
        if ($scope === 'current') {
            $stmt->bindValue(':year', (string)$referenceYear, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'client_id' => (int)$row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'document' => $row['document'],
                'status' => $row['status'],
                'partner' => $row['partner_accountant_plus'] ?: ($row['partner_accountant'] ?? null),
                'expires_at' => $row['last_certificate_expires_at'] !== null ? (int)$row['last_certificate_expires_at'] : null,
                'next_follow_up_at' => $row['next_follow_up_at'] !== null ? (int)$row['next_follow_up_at'] : null,
                'certificate_id' => $row['certificate_id'] !== null ? (int)$row['certificate_id'] : null,
                'certificate_protocol' => $row['certificate_protocol'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @return int[]
     */
    public function findIdsByAssignedAvp(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE assigned_avp_id = :user');
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    }

    /**
     * @return int[]
     */
    public function findIdsByLastAvpName(string $name): array
    {
        $needle = trim($name);
        if ($needle === '') {
            return [];
        }

        $normalized = mb_strtolower($needle, 'UTF-8');

        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE last_avp_name IS NOT NULL AND LOWER(last_avp_name) = :needle');
        $stmt->bindValue(':needle', $normalized, PDO::PARAM_STR);
        $stmt->execute();

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    }

    /**
     * @return array<int, array{id:int,name:string,document:?string,status:?string,last_avp_name:?string}>
     */
    public function searchPeopleForAvpLink(string $query, int $limit = 10): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $normalized = mb_strtolower($needle, 'UTF-8');
        $documentDigits = digits_only($needle);

        $clauses = [
            'LOWER(name) LIKE :name_term',
            'LOWER(titular_name) LIKE :name_term',
            'LOWER(last_avp_name) LIKE :name_term',
        ];

        $params = [
            ':name_term' => '%' . $normalized . '%',
        ];

        if ($documentDigits !== '') {
            $clauses[] = 'document LIKE :doc_term';
            $clauses[] = 'titular_document LIKE :doc_term';
            $params[':doc_term'] = '%' . $documentDigits . '%';
        }

        $where = '(' . implode(' OR ', $clauses) . ')';
        $sql = 'SELECT id, name, document, status, last_avp_name FROM clients WHERE ' . $where . ' ORDER BY updated_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $document = isset($row['document']) ? digits_only((string)$row['document']) : null;
            if ($document === '') {
                $document = null;
            }

            $results[] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'document' => $document,
                'status' => isset($row['status']) ? (string)$row['status'] : null,
                'last_avp_name' => isset($row['last_avp_name']) ? (string)$row['last_avp_name'] : null,
            ];
        }

        return $results;
    }

    /**
     * @return string[]
     */
    public function listAvpNamesByAssignedUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(last_avp_name) AS label
             FROM clients
             WHERE assigned_avp_id = :user
               AND last_avp_name IS NOT NULL
               AND TRIM(last_avp_name) <> ""
             ORDER BY label COLLATE NOCASE ASC'
        );
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $this->sanitizeAvpNameList($rows);
    }

    public function findDocuments(array $documents): array
    {
        $normalized = [];
        foreach ($documents as $document) {
            $digits = digits_only((string)$document);
            if ($digits !== '') {
                $normalized[$digits] = true;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $cnpjs = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($cnpjs), '?'));
        $stmt = $this->pdo->prepare('SELECT document FROM clients WHERE document IN (' . $placeholders . ')');
        $stmt->execute($cnpjs);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_unique(array_map('strval', $rows)));
    }

    /**
     * @param int[] $clientIds
     * @return array<int, string>
     */
    public function documentsByIds(array $clientIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $clientIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT id, document FROM clients WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $document = digits_only((string)($row['document'] ?? ''));
            if ($id > 0 && $document !== '') {
                $map[$id] = $document;
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    public function listDistinctAvpNames(int $limit = 200): array
    {
        $limit = max(10, min(500, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(last_avp_name) AS label
             FROM clients
             WHERE last_avp_name IS NOT NULL
               AND TRIM(last_avp_name) <> ""
             ORDER BY label COLLATE NOCASE ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $this->sanitizeAvpNameList($rows);
    }

    public function searchContactEmails(
        string $query,
        ?array $restrictedAvpNames = null,
        bool $allowOnline = false,
        int $limit = 8,
        bool $includeOff = false
    ): array {
        $limit = max(1, min(20, $limit));
        $normalized = trim(mb_strtolower($query));
        if ($normalized === '') {
            return [];
        }

        $where = [
            'email IS NOT NULL',
            "TRIM(email) <> ''",
        ];

        if (!$includeOff) {
            $where[] = 'is_off = 0';
        }

        $params = [
            ':like' => '%' . $normalized . '%',
        ];

        $clauses = [
            'LOWER(email) LIKE :like',
            'LOWER(name) LIKE :like',
            'LOWER(titular_name) LIKE :like',
        ];

        $documentDigits = digits_only($query);
        if ($documentDigits !== '') {
            $clauses[] = 'document LIKE :doc_query';
            $params[':doc_query'] = '%' . $documentDigits . '%';
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';

        $avpClause = $this->buildAvpAccessClause($restrictedAvpNames, $allowOnline, $params, 'avp_contact');
        if ($avpClause !== null) {
            $where[] = $avpClause;
        }

        $sql = 'SELECT id, name, email, document, status
                FROM clients
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY name COLLATE NOCASE ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function canAccessClient(int $clientId, ?array $restrictedAvpNames = null, bool $allowOnline = false): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        if ($restrictedAvpNames === null) {
            return true;
        }

        $params = [':id' => $clientId];
        $avpClause = $this->buildAvpAccessClause($restrictedAvpNames, $allowOnline, $params, 'avp_check');

        if ($avpClause === null) {
            return false;
        }

        $sql = 'SELECT 1 FROM clients WHERE id = :id AND ' . $avpClause . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function prefixArrayKeys(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }

    private function buildAvpAccessClause(?array $restrictedAvpNames, bool $allowOnline, array &$params, string $prefix): ?string
    {
        if ($restrictedAvpNames === null) {
            return null;
        }

        if ($restrictedAvpNames === [] && !$allowOnline) {
            return '0 = 1';
        }

        $clauses = [];

        if ($restrictedAvpNames !== []) {
            $placeholders = [];
            $values = array_values($restrictedAvpNames);
            foreach ($values as $index => $name) {
                $normalized = is_string($name) ? trim(mb_strtolower($name, 'UTF-8')) : '';
                if ($normalized === '') {
                    continue;
                }

                $placeholder = sprintf(':%s_%d', $prefix, $index);
                $placeholders[] = $placeholder;
                $params[$placeholder] = $normalized;
            }

            if ($placeholders !== []) {
                $clauses[] = '(
                    last_avp_name IS NOT NULL
                    AND TRIM(last_avp_name) <> ""
                    AND LOWER(TRIM(last_avp_name)) IN (' . implode(', ', $placeholders) . ')
                )';
            }
        }

        if ($allowOnline) {
            $clauses[] = '(last_avp_name IS NULL OR TRIM(last_avp_name) = "")';
        }

        if ($clauses === []) {
            return $allowOnline ? null : '0 = 1';
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function sanitizeAvpNameList(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
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
}
