<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Database\Connection;
use App\Repositories\EmailAccountRepository;
use App\Repositories\Email\EmailAccountRateLimitRepository;
use App\Repositories\Email\EmailJobRepository;

final class EmailHealthService
{
    private EmailAccountRepository $accounts;
    private EmailAccountRateLimitRepository $rateLimits;
    private EmailJobRepository $jobs;
    private \PDO $pdo;

    public function __construct(
        ?EmailAccountRepository $accounts = null,
        ?EmailAccountRateLimitRepository $rateLimits = null,
        ?EmailJobRepository $jobs = null,
        ?\PDO $pdo = null
    ) {
        $this->accounts = $accounts ?? new EmailAccountRepository();
        $this->rateLimits = $rateLimits ?? new EmailAccountRateLimitRepository();
        $this->jobs = $jobs ?? new EmailJobRepository();
        $this->pdo = $pdo ?? Connection::instance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summarize(): array
    {
        $rows = $this->accounts->all();
        $summaries = [];
        $pendingJobs = $this->jobs->countPendingByType('email.campaign.dispatch');

        foreach ($rows as $account) {
            $accountId = (int)$account['id'];
            $rateState = $this->rateLimits->find($accountId);
            $hourlyUsage = (int)($rateState['hourly_sent'] ?? 0);
            $dailyUsage = (int)($rateState['daily_sent'] ?? 0);
            $lastSync = isset($account['imap_last_sync_at']) ? (int)$account['imap_last_sync_at'] : null;
            $lagMinutes = $lastSync ? (int)floor((time() - $lastSync) / 60) : null;

            $summaries[] = [
                'account_id' => $accountId,
                'name' => $account['name'],
                'status' => $account['status'],
                'provider' => $account['provider'],
                'imap' => [
                    'enabled' => (int)($account['imap_sync_enabled'] ?? 0) === 1,
                    'last_sync_at' => $lastSync,
                    'lag_minutes' => $lagMinutes,
                ],
                'rate_limit' => [
                    'hourly' => [
                        'limit' => (int)($account['hourly_limit'] ?? 0),
                        'usage' => $hourlyUsage,
                    ],
                    'daily' => [
                        'limit' => (int)($account['daily_limit'] ?? 0),
                        'usage' => $dailyUsage,
                    ],
                ],
                'queues' => [
                    'pending_batches' => $this->countPendingBatches($accountId),
                    'pending_sends' => $this->countPendingSends($accountId),
                    'pending_jobs' => $pendingJobs,
                ],
            ];
        }

        return $summaries;
    }

    /**
     * @param array{imap_lag_threshold?: int, limit_threshold?: float, batch_threshold?: int, jobs_threshold?: int} $options
     * @return array<int, array<string, string|int>>
     */
    public function detectAlerts(array $options = []): array
    {
        $summaries = $this->summarize();
        $alerts = [];
        $lagThreshold = max(1, (int)($options['imap_lag_threshold'] ?? 60));
        $limitThreshold = max(0.1, (float)($options['limit_threshold'] ?? 0.9));
        $batchThreshold = max(1, (int)($options['batch_threshold'] ?? 50));
        $jobsThreshold = max(1, (int)($options['jobs_threshold'] ?? 25));

        foreach ($summaries as $summary) {
            if ($summary['imap']['enabled'] && $summary['imap']['lag_minutes'] !== null && $summary['imap']['lag_minutes'] > $lagThreshold) {
                $alerts[] = [
                    'account_id' => $summary['account_id'],
                    'type' => 'imap_lag',
                    'message' => sprintf('IMAP fora de sincronia há %d minutos.', $summary['imap']['lag_minutes']),
                ];
            }

            $hourlyLimit = $summary['rate_limit']['hourly']['limit'];
            if ($hourlyLimit > 0) {
                $ratio = $summary['rate_limit']['hourly']['usage'] / $hourlyLimit;
                if ($ratio >= $limitThreshold) {
                    $alerts[] = [
                        'account_id' => $summary['account_id'],
                        'type' => 'hourly_limit',
                        'message' => sprintf('Uso horário em %.0f%% da cota.', $ratio * 100),
                    ];
                }
            }

            if ($summary['queues']['pending_batches'] >= $batchThreshold) {
                $alerts[] = [
                    'account_id' => $summary['account_id'],
                    'type' => 'pending_batches',
                    'message' => sprintf('%d lotes aguardando processamento.', $summary['queues']['pending_batches']),
                ];
            }

            if ($summary['queues']['pending_jobs'] >= $jobsThreshold) {
                $alerts[] = [
                    'account_id' => $summary['account_id'],
                    'type' => 'pending_jobs',
                    'message' => sprintf('%d jobs de campanha na fila.', $summary['queues']['pending_jobs']),
                ];
                break;
            }
        }

        return $alerts;
    }

    private function countPendingBatches(int $accountId): int
    {
        $sql = 'SELECT COUNT(*)
                FROM email_campaign_batches b
                INNER JOIN email_campaigns c ON c.id = b.email_campaign_id
                WHERE c.from_account_id = :account_id
                  AND b.status IN ("pending", "processing")';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':account_id' => $accountId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int)$value;
    }

    private function countPendingSends(int $accountId): int
    {
        $sql = 'SELECT COUNT(*)
                FROM email_sends s
                WHERE s.status IN ("pending", "retry")
                  AND (s.account_id = :account_id OR EXISTS (
                      SELECT 1 FROM email_campaigns c
                      WHERE c.id = s.email_campaign_id AND c.from_account_id = :account_id
                  ))';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':account_id' => $accountId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int)$value;
    }
}
