<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class FinancialInsightService
{
    private PDO $pdo;
    private DateTimeZone $timezone;

    private const FORECAST_STATUSES = ['pending', 'approved', 'scheduled', 'issued'];
    private const MONTH_LABELS = [
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

    public function __construct(?PDO $pdo = null, ?DateTimeZone $timezone = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
        $this->timezone = $timezone ?? new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
    }

    public function cashflowProjection(int $startingBalanceCents, int $daysAhead = 21, int $daysBack = 7): array
    {
        $daysAhead = max(1, $daysAhead);
        $daysBack = max(0, $daysBack);

        $today = new DateTimeImmutable('today', $this->timezone);
        $startDate = $today->modify(sprintf('-%d days', $daysBack));
        $endDate = $today->modify(sprintf('+%d days', $daysAhead));

        $timeline = [];
        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $timeline[$key] = [
                'date_key' => $key,
                'timestamp' => $cursor->getTimestamp(),
                'actual_inflow' => 0,
                'actual_outflow' => 0,
                'net_actual' => 0,
                'expected_receivables' => 0,
                'expected_payables' => 0,
                'projected_balance' => null,
            ];
        }

        $startTimestamp = $startDate->getTimestamp();
        $endTimestamp = $endDate->setTime(23, 59, 59)->getTimestamp();
        $now = now();

        $actuals = $this->collectTransactionsDaily($startTimestamp, min($endTimestamp, $now));
        foreach ($actuals as $row) {
            if (!isset($timeline[$row['day_key']])) {
                continue;
            }

            $timeline[$row['day_key']]['actual_inflow'] = (int)$row['inflow'];
            $timeline[$row['day_key']]['actual_outflow'] = (int)$row['outflow'];
            $timeline[$row['day_key']]['net_actual'] = (int)$row['inflow'] - (int)$row['outflow'];
        }

        $forecast = $this->collectInvoicesDaily(max($now, $startTimestamp), $endTimestamp);
        foreach ($forecast as $row) {
            if (!isset($timeline[$row['day_key']])) {
                continue;
            }

            $timeline[$row['day_key']]['expected_receivables'] = (int)$row['receivables'];
            $timeline[$row['day_key']]['expected_payables'] = (int)$row['payables'];
        }

        $balance = $startingBalanceCents;
        foreach ($timeline as &$entry) {
            if ($entry['timestamp'] < $today->getTimestamp()) {
                continue;
            }

            $balance += (int)$entry['expected_receivables'] - (int)$entry['expected_payables'];
            $entry['projected_balance'] = $balance;
        }
        unset($entry);

        return [
            'series' => array_values($timeline),
            'start' => $startDate->getTimestamp(),
            'end' => $endDate->getTimestamp(),
            'today' => $today->getTimestamp(),
            'starting_balance' => $startingBalanceCents,
            'ending_balance' => $balance,
            'days_ahead' => $daysAhead,
        ];
    }

    public function dreSummary(int $months = 4): array
    {
        $months = max(1, $months);
        $currentMonth = new DateTimeImmutable('first day of this month', $this->timezone);
        $startMonth = $currentMonth->modify(sprintf('-%d months', $months - 1));
        $startTimestamp = $startMonth->getTimestamp();

        $rows = $this->collectMonthlyTransactions($startTimestamp);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['period']] = [
                'revenue' => (int)$row['revenue'],
                'expense' => (int)$row['expense'],
            ];
        }

        $periods = [];
        for ($i = 0; $i < $months; $i++) {
            $periodDate = $startMonth->modify(sprintf('+%d months', $i));
            $key = $periodDate->format('Y-m');
            $monthNumber = (int)$periodDate->format('n');
            $label = sprintf('%s/%s', self::MONTH_LABELS[$monthNumber] ?? $periodDate->format('m'), $periodDate->format('Y'));

            $revenue = $rowMap[$key]['revenue'] ?? 0;
            $expense = $rowMap[$key]['expense'] ?? 0;
            $periods[] = [
                'key' => $key,
                'label' => $label,
                'timestamp' => $periodDate->getTimestamp(),
                'revenue' => $revenue,
                'expense' => $expense,
                'net' => $revenue - $expense,
            ];
        }

        $totals = [
            'revenue' => array_sum(array_column($periods, 'revenue')),
            'expense' => array_sum(array_column($periods, 'expense')),
        ];
        $totals['net'] = $totals['revenue'] - $totals['expense'];

        return [
            'periods' => $periods,
            'totals' => $totals,
            'start' => $startTimestamp,
            'end' => $currentMonth->getTimestamp(),
            'months' => $months,
        ];
    }

    private function collectTransactionsDaily(int $startTimestamp, int $endTimestamp): array
    {
        if ($endTimestamp < $startTimestamp) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT strftime("%Y-%m-%d", datetime(occurred_at, "unixepoch")) AS day_key,
                    SUM(CASE WHEN transaction_type = "credit" THEN amount_cents ELSE 0 END) AS inflow,
                    SUM(CASE WHEN transaction_type = "debit" THEN amount_cents ELSE 0 END) AS outflow
             FROM financial_transactions
             WHERE occurred_at BETWEEN :start AND :end
             GROUP BY day_key
             ORDER BY day_key ASC'
        );
        $stmt->bindValue(':start', $startTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':end', $endTimestamp, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    private function collectInvoicesDaily(int $startTimestamp, int $endTimestamp): array
    {
        if ($endTimestamp < $startTimestamp) {
            return [];
        }

                $statuses = '"' . implode('","', self::FORECAST_STATUSES) . '"';
                $sql = 'SELECT strftime("%Y-%m-%d", datetime(due_date, "unixepoch")) AS day_key,
                                        SUM(CASE WHEN direction = "receivable" THEN amount_cents ELSE 0 END) AS receivables,
                                        SUM(CASE WHEN direction = "payable" THEN amount_cents ELSE 0 END) AS payables
                         FROM financial_invoices
                         WHERE status IN (' . $statuses . ')
                             AND due_date BETWEEN :start AND :end
                         GROUP BY day_key
                         ORDER BY day_key ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $startTimestamp, PDO::PARAM_INT);
        $stmt->bindValue(':end', $endTimestamp, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    private function collectMonthlyTransactions(int $startTimestamp): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT strftime("%Y-%m", datetime(occurred_at, "unixepoch")) AS period,
                    SUM(CASE WHEN transaction_type = "credit" THEN amount_cents ELSE 0 END) AS revenue,
                    SUM(CASE WHEN transaction_type = "debit" THEN amount_cents ELSE 0 END) AS expense
             FROM financial_transactions
             WHERE occurred_at >= :start
             GROUP BY period
             ORDER BY period DESC'
        );
        $stmt->bindValue(':start', $startTimestamp, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }
}
