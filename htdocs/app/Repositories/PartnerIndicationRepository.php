<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class PartnerIndicationRepository
{
    private PDO $pdo;
    private bool $supportsAvpAt;

    public function __construct()
    {
        $this->pdo = Connection::instance();
        $this->supportsAvpAt = $this->tableHasColumn('certificates', 'avp_at');
    }

    /**
     * @param array{range: array{start:int,end:int}, mode:string} $filters
     */
    public function reportForPartner(array $partner, array $filters): array
    {
        $partnerName = trim((string)($partner['name'] ?? ''));
        if ($partnerName === '') {
            return [
                'filters' => $filters,
                'rows' => [],
                'totals' => $this->emptyTotals(),
            ];
        }

        $needle = mb_strtolower($partnerName, 'UTF-8');
        $rawName = $partnerName;
        $range = $filters['range'] ?? ['start' => 0, 'end' => now()];
        $rangeStart = (int)($range['start'] ?? 0);
        $rangeEnd = (int)($range['end'] ?? now());

        $referenceExpr = $this->timestampExpression('cert.');

        $stmt = $this->pdo->prepare(
            "SELECT
                cert.id AS certificate_id,
                cert.protocol,
                cert.status,
                clients.document AS client_document,
                clients.name AS client_name,
                $referenceExpr AS reference_ts,
                entries.cost_value,
                entries.sale_value
            FROM certificates cert
            INNER JOIN clients ON clients.id = cert.client_id
            LEFT JOIN partner_indication_entries entries
                ON entries.certificate_id = cert.id
               AND entries.partner_id = :partner_id
            WHERE (
                    LOWER(TRIM(cert.partner_accountant_plus)) = :needle
                 OR LOWER(TRIM(cert.partner_accountant)) = :needle
                 OR TRIM(cert.partner_accountant_plus) = :raw
                 OR TRIM(cert.partner_accountant) = :raw
            )
              AND $referenceExpr IS NOT NULL
              AND $referenceExpr BETWEEN :range_start AND :range_end
            ORDER BY reference_ts DESC, cert.protocol DESC"
        );

        $stmt->bindValue(':partner_id', (int)($partner['id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':raw', $rawName, PDO::PARAM_STR);
        $stmt->bindValue(':range_start', $rangeStart, PDO::PARAM_INT);
        $stmt->bindValue(':range_end', $rangeEnd, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        $totals = $this->emptyTotals();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cost = array_key_exists('cost_value', $row) && $row['cost_value'] !== null
                ? (int)$row['cost_value']
                : null;
            $sale = array_key_exists('sale_value', $row) && $row['sale_value'] !== null
                ? (int)$row['sale_value']
                : null;
            $result = ($cost !== null && $sale !== null) ? $sale - $cost : null;

            $rows[] = [
                'certificate_id' => (int)($row['certificate_id'] ?? 0),
                'protocol' => (string)($row['protocol'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'document' => (string)($row['client_document'] ?? ''),
                'client_name' => (string)($row['client_name'] ?? ''),
                'reference_ts' => isset($row['reference_ts']) ? (int)$row['reference_ts'] : null,
                'cost_value' => $cost,
                'sale_value' => $sale,
                'result_value' => $result,
            ];

            if ($cost !== null) {
                $totals['cost'] += $cost;
            }
            if ($sale !== null) {
                $totals['sale'] += $sale;
            }
            if ($result !== null) {
                $totals['result'] += $result;
            }
        }

        $totals['count'] = count($rows);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<int, array{certificate_id:int, protocol:string|null, cost_value:?int, sale_value:?int}> $entries
     */
    public function saveEntries(int $partnerId, string $billingMode, array $entries): void
    {
        $partnerId = max(1, $partnerId);
        $billingMode = in_array($billingMode, ['custo', 'comissao'], true) ? $billingMode : 'custo';
        $now = now();

        if ($billingMode !== 'comissao') {
            $clearStmt = $this->pdo->prepare('DELETE FROM partner_indication_entries WHERE partner_id = :partner_id');
            $clearStmt->execute([':partner_id' => $partnerId]);
            return;
        }

        $deleteStmt = $this->pdo->prepare(
            'DELETE FROM partner_indication_entries WHERE partner_id = :partner_id AND certificate_id = :certificate_id'
        );

        $upsertSql = 'INSERT INTO partner_indication_entries (
                partner_id,
                certificate_id,
                protocol,
                billing_mode,
                cost_value,
                sale_value,
                created_at,
                updated_at
            ) VALUES (
                :partner_id,
                :certificate_id,
                :protocol,
                :billing_mode,
                :cost_value,
                :sale_value,
                :created_at,
                :updated_at
            )
            ON CONFLICT(partner_id, certificate_id) DO UPDATE SET
                protocol = excluded.protocol,
                billing_mode = excluded.billing_mode,
                cost_value = excluded.cost_value,
                sale_value = excluded.sale_value,
                updated_at = excluded.updated_at';

        $upsertStmt = $this->pdo->prepare($upsertSql);

        foreach ($entries as $entry) {
            $certificateId = (int)($entry['certificate_id'] ?? 0);
            if ($certificateId <= 0) {
                continue;
            }

            $cost = $entry['cost_value'] ?? null;
            $sale = $entry['sale_value'] ?? null;
            $protocol = $this->resolveProtocol($certificateId, $entry['protocol'] ?? null);

            if ($protocol === null) {
                continue;
            }

            if ($cost === null && $sale === null) {
                $deleteStmt->execute([
                    ':partner_id' => $partnerId,
                    ':certificate_id' => $certificateId,
                ]);
                continue;
            }

            $upsertStmt->execute([
                ':partner_id' => $partnerId,
                ':certificate_id' => $certificateId,
                ':protocol' => $protocol,
                ':billing_mode' => $billingMode,
                ':cost_value' => $cost,
                ':sale_value' => $sale,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

    private function resolveProtocol(int $certificateId, ?string $protocol): ?string
    {
        $protocol = trim((string)$protocol);
        if ($protocol !== '') {
            return $protocol;
        }

        $stmt = $this->pdo->prepare('SELECT protocol FROM certificates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $certificateId]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return null;
        }

        $protocol = trim((string)$value);
        return $protocol !== '' ? $protocol : null;
    }

    private function emptyTotals(): array
    {
        return [
            'count' => 0,
            'cost' => 0,
            'sale' => 0,
            'result' => 0,
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
}
