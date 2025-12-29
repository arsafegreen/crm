<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Auth\AuthenticatedUser;
use App\Repositories\ClientRepository;
use App\Repositories\Marketing\CampaignJobRepository;
use App\Repositories\Marketing\CampaignSendRepository;
use App\Repositories\Marketing\CampaignSendLogRepository;
use App\Repositories\SettingRepository;
use App\Services\Whatsapp\WhatsappService;
use App\Support\WhatsappTemplatePresets;
use RuntimeException;
use function digits_only;
use function format_date;
use function format_document;
use function now;
use function str_starts_with;

final class WhatsappAutomationService
{
    private const CAMPAIGN_BIRTHDAY = 'whatsapp_birthday';
    private const CAMPAIGN_RENEWAL = 'whatsapp_renewal';
    private const DEFAULT_PACING = 40;

    private ClientRepository $clients;
    private CampaignJobRepository $jobs;
    private CampaignSendRepository $sends;
    private CampaignSendLogRepository $sendLogs;
    private SettingRepository $settings;
    private WhatsappService $whatsapp;
    private const BLOCK_KEY_ENABLED = 'whatsapp.automation.block.enabled';
    private const BLOCK_KEY_HOURS = 'whatsapp.automation.block.hours';

    public function __construct(
        ?ClientRepository $clients = null,
        ?CampaignJobRepository $jobs = null,
        ?CampaignSendRepository $sends = null,
        ?CampaignSendLogRepository $sendLogs = null,
        ?SettingRepository $settings = null,
        ?WhatsappService $whatsapp = null
    ) {
        $this->clients = $clients ?? new ClientRepository();
        $this->jobs = $jobs ?? new CampaignJobRepository();
        $this->sends = $sends ?? new CampaignSendRepository();
        $this->sendLogs = $sendLogs ?? new CampaignSendLogRepository();
        $this->settings = $settings ?? new SettingRepository();
        $this->whatsapp = $whatsapp ?? new WhatsappService();
    }

    public function status(): array
    {
        $status = $this->buildStatus('birthday_auto', 'birthday_manual', self::CAMPAIGN_BIRTHDAY);
        $status['block'] = $this->blockSettings();
        return $status;
    }

    public function statusRenewal(): array
    {
        $status = $this->buildStatus('renewal_auto', 'renewal_manual', self::CAMPAIGN_RENEWAL);
        $status['block'] = $this->blockSettings();
        return $status;
    }

    private function buildStatus(string $autoKind, string $manualKind, string $campaign): array
    {
        $autoJob = $this->jobs->findByKind($autoKind);
        $manualJob = $this->jobs->findByKind($manualKind);

        $autoMeta = $autoJob['meta'] ?? [];
        $manualMeta = $manualJob['meta'] ?? [];

        return [
            'auto' => [
                'enabled' => (bool)($autoJob['enabled'] ?? false),
                'start_time' => $autoJob['start_time'] ?? '12:00',
                'pacing_seconds' => (int)($autoJob['pacing_seconds'] ?? self::DEFAULT_PACING),
                'last_run_at' => $autoMeta['last_run_at'] ?? null,
                'last_result' => $autoMeta['last_result'] ?? null,
                'meta' => $autoMeta,
            ],
            'manual' => [
                'scheduled_for' => $manualMeta['scheduled_for'] ?? null,
                'status' => $manualJob['status'] ?? 'idle',
                'pacing_seconds' => (int)($manualJob['pacing_seconds'] ?? self::DEFAULT_PACING),
                'last_run_at' => $manualMeta['last_run_at'] ?? null,
                'last_result' => $manualMeta['last_result'] ?? null,
                'meta' => $manualMeta,
            ],
            'recent_sends' => $this->sends->recent($campaign, 12),
            'block' => $this->blockSettings(),
        ];
    }

    public function blockSettings(): array
    {
        $enabled = (bool)$this->settings->get(self::BLOCK_KEY_ENABLED, false);
        $hours = (int)$this->settings->get(self::BLOCK_KEY_HOURS, 24);
        $hours = max(1, min(240, $hours));

        return [
            'enabled' => $enabled,
            'window_hours' => $hours,
        ];
    }

    public function saveBlockSettings(bool $enabled, int $hours): array
    {
        $hours = max(1, min(240, $hours));
        $this->settings->setMany([
            self::BLOCK_KEY_ENABLED => $enabled ? 1 : 0,
            self::BLOCK_KEY_HOURS => $hours,
        ]);

        return $this->blockSettings();
    }

    public function saveAutoBirthday(bool $enabled, string $startTime, int $pacingSeconds): array
    {
        $time = $this->sanitizeTime($startTime) ?? '12:00';
        $pacing = max(5, min(600, $pacingSeconds));

        $job = $this->jobs->upsert('birthday_auto', [
            'start_time' => $time,
            'pacing_seconds' => $pacing,
            'enabled' => $enabled ? 1 : 0,
        ]);

        return $job;
    }

    public function scheduleBirthday(int $scheduledFor, int $pacingSeconds): array
    {
        if ($scheduledFor <= 0) {
            throw new RuntimeException('Data/horário inválidos para agendamento.');
        }

        $pacing = max(5, min(600, $pacingSeconds));
        $meta = ['scheduled_for' => $scheduledFor];

        $job = $this->jobs->upsert('birthday_manual', [
            'start_time' => date('H:i', $scheduledFor),
            'pacing_seconds' => $pacing,
            'enabled' => 1,
            'status' => 'scheduled',
            'meta' => $meta,
        ]);

        return $job;
    }

    public function saveAutoRenewal(bool $enabled, string $startTime, int $pacingSeconds, string $scope = 'current', ?int $referenceYear = null): array
    {
        $time = $this->sanitizeTime($startTime) ?? '12:00';
        $pacing = max(5, min(600, $pacingSeconds));
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $referenceYear = $referenceYear ?? (int)date('Y');

        $meta = [
            'scope' => $scope,
            'reference_year' => $referenceYear,
        ];

        return $this->jobs->upsert('renewal_auto', [
            'start_time' => $time,
            'pacing_seconds' => $pacing,
            'enabled' => $enabled ? 1 : 0,
            'meta' => $meta,
        ]);
    }

    public function scheduleRenewal(int $scheduledFor, int $pacingSeconds, int $month, int $day, string $scope = 'current', ?int $referenceYear = null): array
    {
        if ($scheduledFor <= 0) {
            throw new RuntimeException('Data/horário inválidos para agendamento.');
        }

        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $pacing = max(5, min(600, $pacingSeconds));
        $referenceYear = $referenceYear ?? (int)date('Y');

        $meta = [
            'scheduled_for' => $scheduledFor,
            'target_month' => $month,
            'target_day' => $day,
            'scope' => $scope,
            'reference_year' => $referenceYear,
        ];

        return $this->jobs->upsert('renewal_manual', [
            'start_time' => date('H:i', $scheduledFor),
            'pacing_seconds' => $pacing,
            'enabled' => 1,
            'status' => 'scheduled',
            'meta' => $meta,
        ]);
    }

    public function runBirthday(?int $referenceTimestamp, int $pacingSeconds, bool $dryRun = false, ?int $startTimestamp = null, bool $simulate = false): array
    {
        @set_time_limit(0);

        $timestamp = $referenceTimestamp ?? strtotime('today midnight') ?: time();
        $month = (int)date('n', $timestamp);
        $day = (int)date('j', $timestamp);
        $reference = (string)date('Y', $timestamp);
        $pacing = max(0, min(900, $pacingSeconds));
        $sendStart = $startTimestamp ?? time();
        $actor = $this->automationUser();
        $block = $this->blockSettings();
        $enforceBlock = (bool)($block['enabled'] ?? false);
        $blockHours = (int)($block['window_hours'] ?? 24);
        $block = $this->blockSettings();
        $enforceBlock = (bool)($block['enabled'] ?? false);
        $blockHours = (int)($block['window_hours'] ?? 24);
        $block = $this->blockSettings();
        $enforceBlock = (bool)($block['enabled'] ?? false);
        $blockHours = (int)($block['window_hours'] ?? 24);

        $candidates = $this->clients->recipientsByBirthday($month, $day);
        $targets = $this->dedupeByCpf($candidates);

        $stats = [
            'reference' => $reference,
            'total_candidates' => count($targets),
            'skipped_duplicate' => 0,
            'skipped_no_phone' => 0,
            'sent' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'preview' => [],
            'schedule' => [],
            'log' => [],
        ];

        foreach (array_values($targets) as $index => $client) {
            $cpf = $this->resolveCpf($client) ?? ('client:' . ($client['id'] ?? uniqid('client_', true)));
            $phone = $this->resolvePhone($client);
            $name = $client['titular_name'] ?? $client['name'] ?? 'Cliente';
            $name = $client['titular_name'] ?? $client['name'] ?? 'Cliente';

            if ($phone === null) {
                $stats['skipped_no_phone']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, null, 'skipped_no_phone', 'Sem telefone', $name);
                if (!$dryRun) {
                    $this->sendLogs->record(self::CAMPAIGN_BIRTHDAY, $reference, $cpf, null, 'skipped_no_phone', 'Sem telefone');
                }
                continue;
            }

            if ($this->sends->exists(self::CAMPAIGN_BIRTHDAY, $reference, $cpf)) {
                $stats['skipped_duplicate']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período', $name);
                if (!$dryRun) {
                    $this->sendLogs->record(self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período');
                }
                continue;
            }

            $message = $this->renderBirthdayMessage($client);
            if ($dryRun) {
                $stats['preview'][] = [
                    'name' => $name,
                    'phone' => $phone,
                    'cpf' => $cpf,
                    'message' => $message,
                ];
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'simulated', $message, $name);
                if ($simulate) {
                    $stats['schedule'][] = [
                        'name' => $name,
                        'phone' => $phone,
                        'cpf' => $cpf,
                        'scheduled_at' => $sendStart + ($pacing * $index),
                        'pacing' => $pacing,
                    ];
                }
                continue;
            }

            $sendId = $this->sends->create([
                'cpf' => $cpf,
                'phone' => $phone,
                'campaign' => self::CAMPAIGN_BIRTHDAY,
                'reference' => $reference,
            ]);

            if ($sendId === null) {
                $stats['skipped_duplicate']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período', $name);
                $this->sendLogs->record(self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período');
                continue;
            }

            $result = null;
            try {
                $result = $this->whatsapp->startManualConversation([
                    'contact_name' => $client['titular_name'] ?? $client['name'] ?? 'Contato',
                    'contact_phone' => $phone,
                    'message' => $message,
                ], $actor, $enforceBlock, true, $blockHours);

                $this->sends->markSent($sendId, $client['gateway'] ?? null, null);
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'sent', $message, $name);
                $this->sendLogs->record(self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'sent', 'Enviado com sucesso');
                $stats['sent']++;

                if (isset($result['thread_id'])) {
                    $threadId = (int)$result['thread_id'];
                    $note = sprintf('[Automação aniversário %s] Status: sent · Tel: %s · CPF: %s', $reference, $phone, $cpf);
                    $this->whatsapp->addInternalNote($threadId, $note, $actor);
                    $this->whatsapp->closeThread($threadId, $actor);
                }
            } catch (\Throwable $exception) {
                $this->sends->markFailed($sendId, $exception->getMessage());
                $errorMsg = $message . ' | Erro: ' . $exception->getMessage();
                $this->pushLog($stats['log'], self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'failed', $errorMsg, $name);
                $this->sendLogs->record(self::CAMPAIGN_BIRTHDAY, $reference, $cpf, $phone, 'failed', $exception->getMessage());
                $stats['failed']++;

                if (isset($result['thread_id'])) {
                    $threadId = (int)$result['thread_id'];
                    $note = sprintf('[Automação aniversário %s] Status: failed · Tel: %s · CPF: %s · Erro: %s', $reference, $phone, $cpf, $exception->getMessage());
                    $this->whatsapp->addInternalNote($threadId, $note, $actor);
                    $this->whatsapp->closeThread($threadId, $actor);
                }
            }

            if ($pacing > 0 && $index < (count($targets) - 1)) {
                sleep($pacing);
            }
        }

        if ($stats['preview'] !== [] && !$simulate) {
            $stats['preview'] = array_slice($stats['preview'], 0, 30);
        }

        if ($stats['schedule'] !== [] && !$simulate) {
            $stats['schedule'] = array_slice($stats['schedule'], 0, 200);
        }

        if ($stats['log'] !== [] && !$simulate) {
            $stats['log'] = array_slice($stats['log'], 0, 300);
        }

        return $stats;
    }

    public function runRenewal(int $month, int $day, string $scope, ?int $referenceYear, int $pacingSeconds, bool $dryRun = false, ?int $startTimestamp = null, bool $simulate = false): array
    {
        @set_time_limit(0);

        $month = max(1, min(12, $month));
        $day = max(1, min(31, $day));
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $referenceYear = $scope === 'current' ? ($referenceYear ?? (int)date('Y')) : null;
        $referenceKey = $scope === 'all' ? 'all' : (string)$referenceYear;
        $pacing = max(0, min(900, $pacingSeconds));
        $sendStart = $startTimestamp ?? time();
        $actor = $this->automationUser();
        $block = $this->blockSettings();
        $enforceBlock = (bool)($block['enabled'] ?? false);
        $blockHours = (int)($block['window_hours'] ?? 24);

        $candidates = $this->clients->recipientsByExpirationDay($month, $day, $scope, $referenceYear);
        $targets = $this->dedupeByCpf($candidates);

        $stats = [
            'reference' => $referenceKey,
            'scope' => $scope,
            'month' => $month,
            'day' => $day,
            'total_candidates' => count($targets),
            'skipped_duplicate' => 0,
            'skipped_no_phone' => 0,
            'sent' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'preview' => [],
            'schedule' => [],
            'log' => [],
        ];

        foreach (array_values($targets) as $index => $client) {
            $cpf = $this->resolveCpf($client) ?? ('client:' . ($client['id'] ?? uniqid('client_', true)));
            $phone = $this->resolvePhone($client);

            if ($phone === null) {
                $stats['skipped_no_phone']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, null, 'skipped_no_phone', 'Sem telefone', $name);
                if (!$dryRun) {
                    $this->sendLogs->record(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, null, 'skipped_no_phone', 'Sem telefone');
                }
                continue;
            }

            if ($this->sends->exists(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf)) {
                $stats['skipped_duplicate']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período', $name);
                if (!$dryRun) {
                    $this->sendLogs->record(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período');
                }
                continue;
            }

            $message = $this->renderRenewalMessage($client);
            if ($dryRun) {
                $stats['preview'][] = [
                    'name' => $name,
                    'phone' => $phone,
                    'cpf' => $cpf,
                    'message' => $message,
                ];
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'simulated', $message, $name);
                if ($simulate) {
                    $stats['schedule'][] = [
                        'name' => $name,
                        'phone' => $phone,
                        'cpf' => $cpf,
                        'scheduled_at' => $sendStart + ($pacing * $index),
                        'pacing' => $pacing,
                    ];
                }
                continue;
            }

            $sendId = $this->sends->create([
                'cpf' => $cpf,
                'phone' => $phone,
                'campaign' => self::CAMPAIGN_RENEWAL,
                'reference' => $referenceKey,
            ]);

            if ($sendId === null) {
                $stats['skipped_duplicate']++;
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período', $name);
                $this->sendLogs->record(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'skipped_duplicate', 'Duplicado no período');
                continue;
            }

            $result = null;
            try {
                $result = $this->whatsapp->startManualConversation([
                    'contact_name' => $client['titular_name'] ?? $client['name'] ?? 'Contato',
                    'contact_phone' => $phone,
                    'message' => $message,
                ], $actor, $enforceBlock, true, $blockHours);

                $this->sends->markSent($sendId, $client['gateway'] ?? null, null);
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'sent', $message, $name);
                $this->sendLogs->record(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'sent', 'Enviado com sucesso');
                $stats['sent']++;

                if (isset($result['thread_id'])) {
                    $threadId = (int)$result['thread_id'];
                    $note = sprintf('[Automação renovação %s] Status: sent · Tel: %s · CPF: %s', $referenceKey, $phone, $cpf);
                    $this->whatsapp->addInternalNote($threadId, $note, $actor);
                    $this->whatsapp->closeThread($threadId, $actor);
                }
            } catch (\Throwable $exception) {
                $this->sends->markFailed($sendId, $exception->getMessage());
                $errorMsg = $message . ' | Erro: ' . $exception->getMessage();
                $this->pushLog($stats['log'], self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'failed', $errorMsg, $name);
                $this->sendLogs->record(self::CAMPAIGN_RENEWAL, $referenceKey, $cpf, $phone, 'failed', $exception->getMessage());
                $stats['failed']++;

                if (isset($result['thread_id'])) {
                    $threadId = (int)$result['thread_id'];
                    $note = sprintf('[Automação renovação %s] Status: failed · Tel: %s · CPF: %s · Erro: %s', $referenceKey, $phone, $cpf, $exception->getMessage());
                    $this->whatsapp->addInternalNote($threadId, $note, $actor);
                    $this->whatsapp->closeThread($threadId, $actor);
                }
            }

            if ($pacing > 0 && $index < (count($targets) - 1)) {
                sleep($pacing);
            }
        }

        if ($stats['preview'] !== [] && !$simulate) {
            $stats['preview'] = array_slice($stats['preview'], 0, 30);
        }

        if ($stats['schedule'] !== [] && !$simulate) {
            $stats['schedule'] = array_slice($stats['schedule'], 0, 200);
        }

        if ($stats['log'] !== [] && !$simulate) {
            $stats['log'] = array_slice($stats['log'], 0, 300);
        }

        return $stats;
    }

    public function forecastRenewal(int $month, int $day, string $scope = 'current', ?int $referenceYear = null): array
    {
        $stats = $this->runRenewal($month, $day, $scope, $referenceYear, 0, true);
        $withPhone = $stats['total_candidates'] - ($stats['skipped_no_phone'] ?? 0);
        $alreadySent = (int)($stats['skipped_duplicate'] ?? 0);
        $remaining = max(0, $withPhone - $alreadySent);

        return [
            'scope' => $stats['scope'] ?? $scope,
            'reference' => $stats['reference'] ?? ($scope === 'all' ? 'all' : (string)($referenceYear ?? date('Y'))),
            'month' => $month,
            'day' => $day,
            'total' => $stats['total_candidates'],
            'with_phone' => $withPhone,
            'already_sent' => $alreadySent,
            'remaining' => $remaining,
            'sample' => array_slice($stats['preview'] ?? [], 0, 10),
        ];
    }

    public function processDueJobs(): array
    {
        $results = [];
        $now = time();

        // Birthday auto
        $auto = $this->jobs->findByKind('birthday_auto');
        if ($auto !== null && (int)($auto['enabled'] ?? 0) === 1) {
            if ($this->shouldRunAutoToday($auto, $now)) {
                $pacing = (int)($auto['pacing_seconds'] ?? self::DEFAULT_PACING);
                $result = $this->runBirthday($now, $pacing, false);
                $meta = $auto['meta'] ?? [];
                $meta['last_run_at'] = $now;
                $meta['last_result'] = $result;
                $this->jobs->update((int)$auto['id'], ['meta' => $meta]);

                $results[] = ['kind' => 'birthday_auto', 'result' => $result];
            }
        }

        // Birthday manual
        $manualJobs = $this->jobs->listByKind('birthday_manual', 'scheduled');
        foreach ($manualJobs as $job) {
            $meta = $job['meta'] ?? [];
            $scheduledFor = isset($meta['scheduled_for']) ? (int)$meta['scheduled_for'] : null;
            if ($scheduledFor === null || $scheduledFor > $now) {
                continue;
            }

            $pacing = (int)($job['pacing_seconds'] ?? self::DEFAULT_PACING);
            $result = $this->runBirthday($scheduledFor, $pacing, false);
            $meta['last_run_at'] = $now;
            $meta['last_result'] = $result;
            $meta['completed_at'] = $now;

            $this->jobs->update((int)$job['id'], [
                'status' => 'completed',
                'enabled' => 0,
                'meta' => $meta,
            ]);

            $results[] = ['kind' => 'birthday_manual', 'job_id' => (int)$job['id'], 'result' => $result];
        }

        // Renewal auto
        $renewalAuto = $this->jobs->findByKind('renewal_auto');
        if ($renewalAuto !== null && (int)($renewalAuto['enabled'] ?? 0) === 1) {
            if ($this->shouldRunAutoToday($renewalAuto, $now)) {
                $pacing = (int)($renewalAuto['pacing_seconds'] ?? self::DEFAULT_PACING);
                $meta = $renewalAuto['meta'] ?? [];
                $scope = isset($meta['scope']) && in_array($meta['scope'], ['current', 'all'], true) ? $meta['scope'] : 'current';
                $referenceYear = $scope === 'current'
                    ? ($meta['reference_year'] ?? (int)date('Y', $now))
                    : null;

                $month = (int)date('n', $now);
                $day = (int)date('j', $now);

                $result = $this->runRenewal($month, $day, $scope, $referenceYear, $pacing, false);
                $meta['last_run_at'] = $now;
                $meta['last_result'] = $result;
                $this->jobs->update((int)$renewalAuto['id'], ['meta' => $meta]);

                $results[] = ['kind' => 'renewal_auto', 'result' => $result];
            }
        }

        // Renewal manual
        $renewalManualJobs = $this->jobs->listByKind('renewal_manual', 'scheduled');
        foreach ($renewalManualJobs as $job) {
            $meta = $job['meta'] ?? [];
            $scheduledFor = isset($meta['scheduled_for']) ? (int)$meta['scheduled_for'] : null;
            if ($scheduledFor === null || $scheduledFor > $now) {
                continue;
            }

            $pacing = (int)($job['pacing_seconds'] ?? self::DEFAULT_PACING);
            $scope = isset($meta['scope']) && in_array($meta['scope'], ['current', 'all'], true) ? $meta['scope'] : 'current';
            $referenceYear = $scope === 'current'
                ? ($meta['reference_year'] ?? (int)date('Y', $scheduledFor))
                : null;
            $month = isset($meta['target_month']) ? (int)$meta['target_month'] : (int)date('n', $scheduledFor);
            $day = isset($meta['target_day']) ? (int)$meta['target_day'] : (int)date('j', $scheduledFor);

            $result = $this->runRenewal($month, $day, $scope, $referenceYear, $pacing, false);
            $meta['last_run_at'] = $now;
            $meta['last_result'] = $result;
            $meta['completed_at'] = $now;

            $this->jobs->update((int)$job['id'], [
                'status' => 'completed',
                'enabled' => 0,
                'meta' => $meta,
            ]);

            $results[] = ['kind' => 'renewal_manual', 'job_id' => (int)$job['id'], 'result' => $result];
        }

        return $results;
    }

    private function shouldRunAutoToday(array $job, int $now): bool
    {
        $startTime = $this->sanitizeTime($job['start_time'] ?? '') ?? '12:00';
        $todayStart = strtotime(date('Y-m-d', $now) . ' ' . $startTime) ?: $now;

        if ($now < $todayStart) {
            return false;
        }

        $lastRun = isset($job['meta']['last_run_at']) ? (int)$job['meta']['last_run_at'] : 0;
        $startOfDay = strtotime(date('Y-m-d 00:00:00', $now)) ?: ($now - 86400);

        return $lastRun < $startOfDay;
    }

    private function dedupeByCpf(array $rows): array
    {
        $score = static function (array $row): int {
            $status = (string)($row['status'] ?? '');
            return match ($status) {
                'active' => 3,
                'recent_expired', 'notify', 'scheduled' => 2,
                'inactive', 'prospect' => 1,
                default => 0,
            };
        };

        $deduped = [];
        foreach ($rows as $row) {
            $key = $this->resolveCpf($row) ?? ('client:' . ($row['id'] ?? uniqid('client_', true)));
            if (!isset($deduped[$key])) {
                $deduped[$key] = $row;
                continue;
            }

            $current = $deduped[$key];
            $currentScore = $score($current);
            $candidateScore = $score($row);
            $updatedAtCurrent = isset($current['updated_at']) ? (int)$current['updated_at'] : 0;
            $updatedAtCandidate = isset($row['updated_at']) ? (int)$row['updated_at'] : 0;

            if ($candidateScore > $currentScore || ($candidateScore === $currentScore && $updatedAtCandidate > $updatedAtCurrent)) {
                $deduped[$key] = $row;
            }
        }

        return array_values($deduped);
    }

    private function pushLog(array &$log, string $campaign, string $reference, ?string $cpf, ?string $phone, string $status, ?string $message = null, ?string $name = null): void
    {
        $log[] = [
            'campaign' => $campaign,
            'reference' => $reference,
            'cpf' => $cpf,
            'phone' => $phone,
            'status' => $status,
            'message' => $message ?? '',
            'name' => $name ?? '',
        ];
    }

    private function resolveCpf(array $client): ?string
    {
        $titular = digits_only((string)($client['titular_document'] ?? ''));
        if ($titular !== '' && strlen($titular) === 11) {
            return $titular;
        }

        $document = digits_only((string)($client['document'] ?? ''));
        if ($document !== '' && strlen($document) === 11) {
            return $document;
        }

        return null;
    }

    private function resolvePhone(array $client): ?string
    {
        $candidates = [];
        foreach (['whatsapp', 'phone'] as $field) {
            $digits = digits_only((string)($client[$field] ?? ''));
            if ($digits !== '') {
                $candidates[] = $digits;
            }
        }

        $extraRaw = $client['extra_phones'] ?? null;
        if (is_string($extraRaw)) {
            $decoded = json_decode($extraRaw, true);
            if (is_array($decoded)) {
                $extraRaw = $decoded;
            }
        }

        if (is_array($extraRaw)) {
            foreach ($extraRaw as $value) {
                $digits = digits_only((string)$value);
                if ($digits !== '') {
                    $candidates[] = $digits;
                }
            }
        }
        $ordered = array_values(array_unique($candidates, SORT_STRING));
        if ($ordered === []) {
            return null;
        }

        $phone = (string)$ordered[0];

        // Adiciona DDI BR se vier apenas com DDD e número.
        if (!str_starts_with($phone, '55') && (strlen($phone) === 10 || strlen($phone) === 11)) {
            $phone = '55' . $phone;
        }

        return $phone;
    }

    private function renderBirthdayMessage(array $client): string
    {
        $defaults = WhatsappTemplatePresets::defaults();
        $template = (string)$this->settings->get('whatsapp.template.birthday', $defaults['birthday'] ?? '');

        $context = [
            'nome' => $this->resolveName($client),
            'empresa' => trim((string)($client['name'] ?? '')),
            'documento' => format_document((string)($client['document'] ?? '')),
            'cpf' => '',
            'cnpj' => '',
            'titular_documento' => format_document((string)($client['titular_document'] ?? '')),
            'data_nascimento' => isset($client['titular_birthdate']) && $client['titular_birthdate'] !== null ? format_date((int)$client['titular_birthdate']) : '',
            'vencimento' => isset($client['last_certificate_expires_at']) && $client['last_certificate_expires_at'] !== null ? format_date((int)$client['last_certificate_expires_at']) : '',
            'status' => (string)($client['status'] ?? ''),
        ];

        $documentDigits = digits_only((string)($client['document'] ?? ''));
        $cpf = $this->resolveCpf($client);
        if ($cpf !== null) {
            $context['cpf'] = format_document($cpf);
        }
        if ($documentDigits !== '' && strlen($documentDigits) === 14) {
            $context['cnpj'] = format_document($documentDigits);
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string)$value;
        }

        $rendered = strtr(trim($template), $replacements);
        return $rendered !== '' ? $rendered : ($defaults['birthday'] ?? '');
    }

    private function renderRenewalMessage(array $client): string
    {
        $defaults = WhatsappTemplatePresets::defaults();
        $template = (string)$this->settings->get('whatsapp.template.renewal', $defaults['renewal'] ?? '');

        $docDigits = digits_only((string)($client['document'] ?? ''));
        $cpfResolved = $this->resolveCpf($client);

        // Regra: se tiver CNPJ (14 dígitos), usa CNPJ; caso contrário, trata como CPF
        if ($docDigits !== '' && strlen($docDigits) === 14) {
            $docLabel = 'CNPJ';
            $docFormatted = format_document($docDigits);
        } else {
            $docLabel = 'CPF';
            $cpfDigits = $cpfResolved !== null ? digits_only($cpfResolved) : ($docDigits !== '' ? $docDigits : '');
            $docFormatted = $cpfDigits !== '' ? format_document($cpfDigits) : '';
        }

        $context = [
            'nome' => $this->resolveName($client),
            'empresa' => trim((string)($client['name'] ?? '')),
            'documento' => $docFormatted,
            'documento_label' => $docLabel,
            'cpf' => $docLabel === 'CPF' ? $docFormatted : '',
            'cnpj' => $docLabel === 'CNPJ' ? $docFormatted : '',
            'titular_documento' => format_document((string)($client['titular_document'] ?? '')),
            // Vence em: usar apenas dia/mês
            'vencimento' => (isset($client['last_certificate_expires_at']) && $client['last_certificate_expires_at'] !== null)
                ? date('d/m', (int)$client['last_certificate_expires_at'])
                : '',
            'status' => (string)($client['status'] ?? ''),
        ];

        $documentDigits = digits_only((string)($client['document'] ?? ''));
        $cpf = $this->resolveCpf($client);
        if ($cpf !== null) {
            $context['cpf'] = format_document($cpf);
        }
        if ($documentDigits !== '' && strlen($documentDigits) === 14) {
            $context['cnpj'] = format_document($documentDigits);
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string)$value;
        }
        // Corrigir rótulo literal em templates antigos (CNPJ/CPF fixo)
        $replacements['CNPJ:'] = $docLabel . ':';
        $replacements['CPF:'] = $docLabel . ':';

        $rendered = strtr(trim($template), $replacements);
        return $rendered !== '' ? $rendered : ($defaults['renewal'] ?? '');
    }

    private function resolveName(array $client): string
    {
        $titular = trim((string)($client['titular_name'] ?? ''));
        if ($titular !== '') {
            return $titular;
        }

        $name = trim((string)($client['name'] ?? ''));
        return $name !== '' ? $name : 'Cliente';
    }

    private function sanitizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('H:i', $value);
        if ($dt === false) {
            return null;
        }

        return $dt->format('H:i');
    }

    private function automationUser(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: 0,
            name: '',
            email: 'automation@safegreen.local',
            role: 'admin',
            fingerprint: 'automation',
            cpf: null,
            permissions: ['automation.control'],
            sessionIp: null,
            sessionLocation: null,
            sessionUserAgent: 'automation',
            sessionStartedAt: null,
            lastSeenAt: null,
            accessWindowStart: null,
            accessWindowEnd: null,
            requireKnownDevice: false,
            clientAccessScope: 'all',
            allowOnlineClients: true,
            allowInternalChat: true,
            allowExternalChat: true,
            isAvp: false,
            avpIdentityLabel: null,
            avpIdentityCpf: null,
            chatIdentifier: null,
            chatDisplayName: null,
        );
    }
}
