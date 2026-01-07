<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Database\Connection;
use App\Repositories\Email\EmailAccountRepository;
use App\Repositories\Email\EmailCampaignBatchRepository;
use App\Repositories\Email\EmailCampaignRepository;
use App\Repositories\Email\EmailSendRepository;
use App\Repositories\Email\EmailJobRepository;
use App\Repositories\Email\EmailAccountRateLimitRepository;
use App\Repositories\ClientRepository;
use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Repositories\RfbProspectRepository;
use App\Repositories\TemplateRepository;
use App\Services\Marketing\WhatsappAutomationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function config;
use function now;
use function time;

final class MarketingAutomationController
{
    private WhatsappAutomationService $automation;

    // Repositories for email scheduling
    private TemplateRepository $templates;
    private AudienceListRepository $lists;
    private ClientRepository $clients;
    private RfbProspectRepository $rfbProspects;
    private EmailAccountRepository $emailAccounts;
    private EmailCampaignRepository $emailCampaigns;
    private EmailCampaignBatchRepository $emailBatches;
    private EmailSendRepository $emailSends;
    private MarketingContactRepository $contacts;
    private EmailAccountRateLimitRepository $rateLimits;

    public function __construct(?WhatsappAutomationService $automation = null)
    {
        $this->automation = $automation ?? new WhatsappAutomationService();
        $this->templates = new TemplateRepository();
        $this->lists = new AudienceListRepository();
        $this->clients = new ClientRepository();
        $this->rfbProspects = new RfbProspectRepository();
        $this->emailAccounts = new EmailAccountRepository();
        $this->emailCampaigns = new EmailCampaignRepository();
        $this->emailBatches = new EmailCampaignBatchRepository();
        $this->emailSends = new EmailSendRepository();
        $this->contacts = new MarketingContactRepository();
        $this->rateLimits = new EmailAccountRateLimitRepository();
    }

    public function birthdayStatus(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $status = $this->automation->status();
        $status['server_time'] = now();

        return new JsonResponse($status);
    }

    public function renewalStatus(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $status = $this->automation->statusRenewal();
        $status['server_time'] = now();

        return new JsonResponse($status);
    }

    public function emailStatus(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $accountId = (int)$request->query->get('account_id', 0);
        $account = $this->emailAccounts->findActiveSender($accountId > 0 ? $accountId : null);
        if ($account === null) {
            return new JsonResponse(['error' => 'Conta de envio nÆo encontrada ou inativa.'], 422);
        }

        $limits = [
            'burst' => (int)($account['burst_limit'] ?? 0),
            'hourly' => (int)($account['hourly_limit'] ?? 0),
            'daily' => (int)($account['daily_limit'] ?? 0),
        ];

        $rate = $this->rateLimits->find((int)$account['id']) ?? [
            'hourly_sent' => 0,
            'daily_sent' => 0,
            'window_start' => time(),
            'last_reset_at' => time(),
        ];
        $now = time();
        $nextHourReset = ((int)($rate['window_start'] ?? $now)) + 3600;
        $nextDayReset = ((int)($rate['last_reset_at'] ?? $now)) + 86400;
        $availableBudget = $this->computeAvailableBudget($limits, $rate);

        $campaigns = array_filter(
            $this->emailCampaigns->list(),
            static fn(array $c): bool => (int)($c['from_account_id'] ?? 0) === (int)$account['id']
        );
        $campaigns = array_slice($campaigns, 0, 5);

        $campaignSummaries = [];
        foreach ($campaigns as $campaign) {
            $campaignId = (int)$campaign['id'];
            $batches = $this->emailBatches->listByCampaign($campaignId);
            $batchSummaries = [];
            $totalRemaining = 0;

            foreach ($batches as $batch) {
                $batchId = (int)$batch['id'];
                $remaining = $this->emailSends->countByBatch($batchId, ['statuses' => ['pending', 'retry']]);
                $totalRemaining += $remaining;
                $batchSummaries[] = [
                    'id' => $batchId,
                    'status' => (string)$batch['status'],
                    'total' => (int)($batch['total_recipients'] ?? 0),
                    'processed' => (int)($batch['processed_count'] ?? 0),
                    'failed' => (int)($batch['failed_count'] ?? 0),
                    'remaining' => $remaining,
                    'awaiting_window' => $remaining > 0 && $availableBudget === 0,
                ];
            }

            $campaignSummaries[] = [
                'id' => $campaignId,
                'subject' => $campaign['subject'] ?? '(sem assunto)',
                'status' => (string)($campaign['status'] ?? 'draft'),
                'created_at' => (int)($campaign['created_at'] ?? 0),
                'scheduled_for' => isset($campaign['scheduled_for']) ? (int)$campaign['scheduled_for'] : null,
                'batches' => $batchSummaries,
                'remaining' => $totalRemaining,
            ];
        }

        return new JsonResponse([
            'account' => [
                'id' => (int)$account['id'],
                'name' => $account['name'] ?? ($account['from_email'] ?? 'Conta'),
                'limits' => $limits,
                'usage' => [
                    'hourly_sent' => (int)($rate['hourly_sent'] ?? 0),
                    'daily_sent' => (int)($rate['daily_sent'] ?? 0),
                    'next_hour_reset' => $nextHourReset,
                    'next_day_reset' => $nextDayReset,
                ],
                'available_budget' => $availableBudget,
            ],
            'campaigns' => $campaignSummaries,
        ]);
    }

    public function blockingStatus(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        return new JsonResponse($this->automation->blockSettings());
    }

    public function saveBlocking(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $enabled = (bool)$request->request->get('enabled', false);
        $hours = (int)$request->request->get('window_hours', 24);

        $settings = $this->automation->saveBlockSettings($enabled, $hours);

        return new JsonResponse([
            'ok' => true,
            'settings' => $settings,
        ]);
    }

    public function toggleAuto(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $enabled = (bool)$request->request->get('enabled', false);
        $startTime = (string)$request->request->get('start_time', '12:00');
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);

        $job = $this->automation->saveAutoBirthday($enabled, $startTime, $pacingSeconds);

        return new JsonResponse([
            'ok' => true,
            'job' => $job,
        ]);
    }

    public function toggleRenewalAuto(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $enabled = (bool)$request->request->get('enabled', false);
        $startTime = (string)$request->request->get('start_time', '12:00');
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);
        $scope = (string)$request->request->get('scope', 'current');
        $referenceYear = $request->request->get('reference_year');
        $referenceYear = is_numeric($referenceYear) ? (int)$referenceYear : null;

        $job = $this->automation->saveAutoRenewal($enabled, $startTime, $pacingSeconds, $scope, $referenceYear);

        return new JsonResponse([
            'ok' => true,
            'job' => $job,
        ]);
    }

    public function schedule(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $date = (string)$request->request->get('date', '');
        $time = (string)$request->request->get('time', '');
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);

        $scheduledFor = $this->parseLocalDateTime($date, $time);
        if ($scheduledFor === null) {
            return new JsonResponse(['error' => 'Data e hora inválidas.'], 422);
        }

        $job = $this->automation->scheduleBirthday($scheduledFor, $pacingSeconds);

        return new JsonResponse([
            'ok' => true,
            'scheduled_for' => $scheduledFor,
            'job' => $job,
        ]);
    }

    public function scheduleRenewal(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $date = (string)$request->request->get('date', '');
        $time = (string)$request->request->get('time', '');
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);
        $scope = (string)$request->request->get('scope', 'current');

        $scheduledFor = $this->parseLocalDateTime($date, $time);
        if ($scheduledFor === null) {
            return new JsonResponse(['error' => 'Data e hora inválidas.'], 422);
        }

        $month = (int)date('n', $scheduledFor);
        $day = (int)date('j', $scheduledFor);
        $referenceYear = $scope === 'all' ? null : (int)date('Y', $scheduledFor);

        $job = $this->automation->scheduleRenewal($scheduledFor, $pacingSeconds, $month, $day, $scope, $referenceYear);

        return new JsonResponse([
            'ok' => true,
            'scheduled_for' => $scheduledFor,
            'job' => $job,
        ]);
    }

    public function run(Request $request, array $vars = []): JsonResponse|Response
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $date = (string)$request->request->get('date', '');
        $time = (string)$request->request->get('time', '');
        $dryRun = $request->request->getBoolean('dry_run', false);
        $simulate = $request->request->getBoolean('simulate', false);
        $exportPdf = $request->request->getBoolean('export_pdf', false);
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);

        $referenceTimestamp = null;
        if ($date !== '' || $time !== '') {
            $referenceTimestamp = $this->parseLocalDateTime($date, $time !== '' ? $time : '12:00');
            if ($referenceTimestamp === null) {
                return new JsonResponse(['error' => 'Data/horário inválidos.'], 422);
            }
        }

        try {
            $result = $this->automation->runBirthday(
                $referenceTimestamp,
                $pacingSeconds,
                $dryRun || $simulate || $exportPdf,
                $referenceTimestamp,
                $simulate || $exportPdf
            );
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => 'Falha ao processar envios: ' . $exception->getMessage()], 500);
        }

        if ($exportPdf) {
            return $this->renderSimulationPdf('Simulação - Aniversário', $result);
        }

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function runRenewal(Request $request, array $vars = []): JsonResponse|Response
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $date = (string)$request->request->get('date', '');
        $time = (string)$request->request->get('time', '');
        $dryRun = $request->request->getBoolean('dry_run', false);
        $simulate = $request->request->getBoolean('simulate', false);
        $exportPdf = $request->request->getBoolean('export_pdf', false);
        $pacingSeconds = (int)$request->request->get('pacing_seconds', 40);
        $scope = (string)$request->request->get('scope', 'current');

        $referenceTs = $this->parseLocalDateTime($date, $time !== '' ? $time : '00:00');
        if ($referenceTs === null) {
            $referenceTs = time();
        }

        $month = (int)date('n', $referenceTs);
        $day = (int)date('j', $referenceTs);
        $referenceYear = $scope === 'all' ? null : (int)date('Y', $referenceTs);

        try {
            $result = $this->automation->runRenewal(
                $month,
                $day,
                $scope,
                $referenceYear,
                $pacingSeconds,
                $dryRun || $simulate || $exportPdf,
                $referenceTs,
                $simulate || $exportPdf
            );
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => 'Falha ao processar envios: ' . $exception->getMessage()], 500);
        }

        if ($exportPdf) {
            return $this->renderSimulationPdf('Simulação - Renovação', $result);
        }

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function forecastRenewal(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if (!$this->canControl($user)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        $date = (string)$request->get('date', '');
        $scope = (string)$request->get('scope', 'current');

        $referenceTs = $this->parseLocalDateTime($date, '00:00');
        if ($referenceTs === null) {
            $referenceTs = time();
        }

        $month = (int)date('n', $referenceTs);
        $day = (int)date('j', $referenceTs);
        $referenceYear = $scope === 'all' ? null : (int)date('Y', $referenceTs);

        $result = $this->automation->forecastRenewal($month, $day, $scope, $referenceYear);

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function emailOptions(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($user === null && !$this->hasAutomationToken($request)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        try {
            return $this->emailOptionsPayload();
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => 'Falha ao carregar opções de e-mail: ' . $exception->getMessage()], 500);
        }
    }

    private function emailOptionsPayload(): JsonResponse
    {
        $templates = array_values(array_map(static function (array $tpl): array {
            return [
                'id' => (int)$tpl['id'],
                'name' => (string)$tpl['name'],
                'subject' => (string)($tpl['subject'] ?? ''),
                'preview_text' => $tpl['preview_text'] ?? null,
                'status' => (string)($tpl['status'] ?? ''),
            ];
        }, $this->templates->all('email')));

        $accountsRaw = $this->emailAccounts->all(false);
        $accounts = [];
        foreach ($accountsRaw as $row) {
            if (($row['status'] ?? '') !== 'active' || !empty($row['deleted_at'])) {
                continue;
            }
            $accounts[] = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? 'Conta'),
                'from_email' => $row['from_email'] ?? null,
                'from_name' => $row['from_name'] ?? null,
                'hourly_limit' => isset($row['hourly_limit']) ? (int)$row['hourly_limit'] : null,
            ];
        }

        if ($accounts === []) {
            return new JsonResponse(['error' => 'Nenhuma conta de envio ativa disponível.'], 200);
        }

        $listSummary = null;
        try {
            $list = $this->lists->findBySlug('todos');
            if ($list !== null) {
                $listSummary = [
                    'id' => (int)$list['id'],
                    'name' => (string)($list['name'] ?? 'Todos'),
                    'slug' => (string)$list['slug'],
                    'contacts' => $this->lists->countContacts((int)$list['id']),
                ];
            }
        } catch (\Throwable) {
            $listSummary = null;
        }

        $crmCount = $this->countCrmEmails();
        $rfbCount = 0;
        try {
            $rfbStats = $this->rfbProspects->stats();
            $rfbCount = (int)($rfbStats['with_email'] ?? 0);
        } catch (\Throwable) {
            $rfbCount = 0;
        }
        $sources = [];
        if ($listSummary !== null) {
            $sources[] = [
                'key' => 'audience_list',
                'label' => 'Lista padrão (Todos)',
                'slug' => $listSummary['slug'],
                'count' => $listSummary['contacts'],
            ];
        }
        $sources[] = [
            'key' => 'crm_clients',
            'label' => 'CRM - Clientes',
            'count' => $crmCount,
        ];
        $sources[] = [
            'key' => 'rfb_prospects',
            'label' => 'Base RFB',
            'count' => $rfbCount,
        ];

        return new JsonResponse([
            'ok' => true,
            'templates' => $templates,
            'accounts' => $accounts,
            'list' => $listSummary,
            'sources' => $sources,
            'defaults' => [
                'window_hours' => 72,
                'dedupe' => true,
                'source' => 'audience_list',
                'list_slug' => $listSummary['slug'] ?? 'todos',
            ],
        ]);
    }

    public function scheduleEmail(Request $request, array $vars = []): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($user === null && !$this->hasAutomationToken($request)) {
            return new JsonResponse(['error' => 'Acesso não autorizado.'], 403);
        }

        try {
            return $this->handleScheduleEmail($request);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => 'Falha ao agendar/envio: ' . $exception->getMessage()], 500);
        }
    }

    private function handleScheduleEmail(Request $request): JsonResponse
    {
        $templateId = (int)$request->request->get('template_id', 0);
        $accountId = (int)$request->request->get('account_id', 0);
        $source = (string)$request->request->get('source', 'audience_list');
        $listSlug = (string)$request->request->get('list_slug', 'todos');
        $dedupe = $request->request->getBoolean('dedupe', true);
        $windowHours = (int)$request->request->get('window_hours', 72);
        $windowHours = max(24, min(360, $windowHours));
        $startMode = (string)$request->request->get('start_mode', 'now');
        $startDate = (string)$request->request->get('start_date', '');
        $startTime = (string)$request->request->get('start_time', '08:00');

        $startTs = $startMode === 'schedule' ? $this->parseLocalDateTime($startDate, $startTime) : time();
        if ($startMode === 'schedule' && $startTs === null) {
            return new JsonResponse(['error' => 'Data/horário inválidos para agendar.'], 422);
        }
        $nowTs = time();
        if ($startTs === null || $startTs < $nowTs) {
            $startTs = $nowTs;
        }

        $template = $templateId > 0 ? $this->templates->find($templateId) : null;
        if ($template === null || ($template['channel'] ?? 'email') !== 'email') {
            return new JsonResponse(['error' => 'Template de e-mail não encontrado.'], 404);
        }

        $accountRow = $this->emailAccounts->findActiveSender($accountId ?: null);
        if ($accountRow === null) {
            return new JsonResponse(['error' => 'Conta de envio ativa não encontrada.'], 422);
        }

        $sourceType = $source !== '' ? $source : 'audience_list';
        $allowedSources = ['audience_list', 'crm_clients', 'rfb_prospects'];
        if (!in_array($sourceType, $allowedSources, true)) {
            $sourceType = 'audience_list';
        }
        $recipients = [];
        $list = null;
        if ($sourceType === 'crm_clients') {
            $recipients = $this->collectCrmRecipients($dedupe);
        } elseif ($sourceType === 'rfb_prospects') {
            $recipients = $this->collectRfbRecipients($dedupe);
        } else {
            $list = $this->lists->findBySlug($listSlug);
            if ($list === null) {
                return new JsonResponse(['error' => 'Lista de contatos não encontrada.'], 404);
            }
            $recipients = $this->collectListRecipients((int)$list['id'], $dedupe);
        }

        if ($recipients === []) {
            return new JsonResponse(['error' => 'Nenhum contato elegível na base selecionada.'], 422);
        }

        $subject = trim((string)($template['subject'] ?? ''));
        if ($subject === '') {
            $subject = (string)($template['name'] ?? 'Campanha de e-mail');
        }

        $bodyHtml = $template['body_html'] ?? null;
        $bodyText = $template['body_text'] ?? null;
        if ($bodyHtml === null && $bodyText === null) {
            return new JsonResponse(['error' => 'Template sem conteúdo para envio.'], 422);
        }

        $templateVersionId = null;
        if (isset($template['latest_version']['id'])) {
            $templateVersionId = (int)$template['latest_version']['id'];
        } elseif (isset($template['latest_version_id'])) {
            $templateVersionId = (int)$template['latest_version_id'];
        }

        $total = count($recipients);
        $hourlyLimit = (int)($accountRow['hourly_limit'] ?? 0);
        $perHourBase = (int)max(1, ceil($total / $windowHours));
        $perHour = $hourlyLimit > 0 ? max(1, min($hourlyLimit, $perHourBase)) : $perHourBase;
        $secondsPerSlot = (int)max(1, floor(3600 / max(1, $perHour)));
        $hoursNeeded = (int)max(1, ceil($total / $perHour));
        $endTs = $startTs + (($hoursNeeded - 1) * 3600);

        $settings = [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'template_id' => $templateId,
            'template_version_id' => $templateVersionId,
            'headers' => [],
        ];

        $campaignName = sprintf('Automação e-mail · %s · %s', $template['name'] ?? 'Template', date('Y-m-d H:i', $startTs));
        $now = time();
        $campaignId = $this->emailCampaigns->insert([
            'name' => $campaignName,
            'status' => 'scheduled',
            'subject' => $subject,
            'channel' => 'email',
            'from_account_id' => $accountRow['id'] ?? null,
            'source_type' => $sourceType,
            'list_id' => $list['id'] ?? null,
            'scheduled_for' => $startTs,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'template_id' => $templateId,
            'template_version_id' => $templateVersionId,
            'metadata' => json_encode([
                'window_hours' => $windowHours,
                'per_hour' => $perHour,
                'dedupe' => $dedupe,
                'list_slug' => $sourceType === 'audience_list' ? $listSlug : null,
                'source' => $sourceType,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $batchId = $this->emailBatches->insert([
            'email_campaign_id' => $campaignId,
            'status' => 'pending',
            'total_recipients' => $total,
            'processed_count' => 0,
            'failed_count' => 0,
            'scheduled_for' => $startTs,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];
        foreach ($recipients as $idx => $recipient) {
            $hourOffset = (int)floor($idx / $perHour);
            $withinHour = $idx % $perHour;
            $scheduledAt = $startTs + ($hourOffset * 3600) + ($withinHour * $secondsPerSlot);
            $maxTs = $startTs + ($windowHours * 3600);
            if ($scheduledAt > $maxTs) {
                $scheduledAt = $maxTs;
            }

            $rows[] = [
                'email_campaign_id' => $campaignId,
                'batch_id' => $batchId,
                'account_id' => $accountRow['id'] ?? null,
                'contact_id' => $recipient['contact_id'],
                'target_email' => $recipient['email'],
                'target_name' => $recipient['name'],
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'scheduled_at' => $scheduledAt,
                'metadata' => json_encode([
                    'source' => $sourceType,
                    'list_id' => isset($list['id']) ? (int)$list['id'] : null,
                    'dedupe' => $dedupe,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->emailSends->bulkInsert($rows);
        (new EmailJobRepository())->enqueue('email.campaign.dispatch', ['batch_id' => $batchId], [
            'priority' => 10,
            'available_at' => $startTs,
        ]);

        $previewSchedule = array_slice(array_map(static function (array $row): array {
            return [
                'email' => $row['target_email'],
                'scheduled_at' => $row['scheduled_at'],
            ];
        }, $rows), 0, 10);

        return new JsonResponse([
            'ok' => true,
            'campaign_id' => $campaignId,
            'batch_id' => $batchId,
            'recipients' => $total,
            'per_hour' => $perHour,
            'window_hours' => $windowHours,
            'start_at' => $startTs,
            'end_at' => $endTs,
            'preview_schedule' => $previewSchedule,
        ]);
    }

    private function countCrmEmails(): int
    {
        try {
            $pdo = Connection::instance();
            $stmt = $pdo->query("SELECT COUNT(*) FROM clients WHERE email IS NOT NULL AND TRIM(email) <> '' AND (is_off = 0 OR is_off IS NULL)");
            $count = $stmt !== false ? (int)$stmt->fetchColumn() : 0;
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function collectCrmRecipients(bool $dedupe): array
    {
        $limit = 500;
        $offset = 0;
        $seen = [];
        $recipients = [];
        $pdo = Connection::instance();
        $sql = 'SELECT id, name, email FROM clients WHERE email IS NOT NULL AND TRIM(email) <> "" AND (is_off = 0 OR is_off IS NULL) ORDER BY id ASC LIMIT :limit OFFSET :offset';

        while (true) {
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $chunk = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                $email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                if ($dedupe && isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;

                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    $name = $email;
                }

                $recipients[] = [
                    'contact_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'email' => $email,
                    'name' => $name,
                ];
            }

            $offset += $limit;
            if (count($chunk) < $limit) {
                break;
            }
        }

        return $recipients;
    }

    private function collectRfbRecipients(bool $dedupe): array
    {
        $limit = 500;
        $offset = 0;
        $seen = [];
        $recipients = [];
        $pdo = Connection::instance();
        $sql = 'SELECT id, company_name, responsible_name, email FROM rfb_prospects WHERE (exclusion_status IS NULL OR exclusion_status = "active") AND email IS NOT NULL AND TRIM(email) <> "" ORDER BY id ASC LIMIT :limit OFFSET :offset';

        while (true) {
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $chunk = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                $email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                if ($dedupe && isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;

                $name = trim((string)($row['responsible_name'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($row['company_name'] ?? ''));
                }
                if ($name === '') {
                    $name = $email;
                }

                $recipients[] = [
                    'contact_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'email' => $email,
                    'name' => $name,
                ];
            }

            $offset += $limit;
            if (count($chunk) < $limit) {
                break;
            }
        }

        return $recipients;
    }

    /**
     * @param array{burst:int,hourly:int,daily:int} $limits
     * @param array<string,mixed> $rate
     */
    private function computeAvailableBudget(array $limits, array $rate): int
    {
        $remaining = PHP_INT_MAX;
        $burst = max(0, (int)$limits['burst']);
        $hourly = max(0, (int)$limits['hourly']);
        $daily = max(0, (int)$limits['daily']);
        $hourlySent = (int)($rate['hourly_sent'] ?? 0);
        $dailySent = (int)($rate['daily_sent'] ?? 0);

        if ($burst > 0) {
            $remaining = min($remaining, $burst);
        }
        if ($hourly > 0) {
            $remaining = min($remaining, max(0, $hourly - $hourlySent));
        }
        if ($daily > 0) {
            $remaining = min($remaining, max(0, $daily - $dailySent));
        }

        if ($remaining === PHP_INT_MAX) {
            return 1000000; // trata como sem limite efetivo
        }

        return max(0, $remaining);
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function canControl(?AuthenticatedUser $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->can('automation.control');
    }

    private function canControlEmail(?AuthenticatedUser $user): bool
    {
        if ($this->canControl($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->can('campaigns.email')
            || $user->can('marketing.lists')
            || $user->can('marketing.email_accounts')
            || $user->can('templates.library');
    }

    private function hasAutomationToken(Request $request): bool
    {
        $expected = trim((string)config('app.automation_token', ''));
        if ($expected === '') {
            return false;
        }

        $provided = $request->headers->get('X-Automation-Token');
        if (is_string($provided) && $provided !== '') {
            return hash_equals($expected, $provided);
        }

        $queryToken = $request->query->get('token');
        return is_string($queryToken) && $queryToken !== '' && hash_equals($expected, $queryToken);
    }

    private function parseLocalDateTime(string $date, string $time): ?int
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '') {
            return null;
        }

        $input = $date . ' ' . ($time !== '' ? $time : '00:00');
        $timezone = new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $input, $timezone);

        if ($dt === false) {
            return null;
        }

        return $dt->getTimestamp();
    }

    private function renderSimulationPdf(string $title, array $result): Response
    {
        $rows = $result['preview'] ?? [];
        $schedule = $result['schedule'] ?? [];
        $total = (int)($result['total_candidates'] ?? count($rows));

        $htmlRows = '';
        foreach ($rows as $idx => $row) {
            $htmlRows .= sprintf(
                '<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%d</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:pre-wrap;">%s</td></tr>',
                $idx + 1,
                htmlspecialchars((string)($row['name'] ?? 'Contato'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($row['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($row['cpf'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($row['message'] ?? ''), ENT_QUOTES, 'UTF-8')
            );
        }

        $htmlSchedule = '';
        foreach ($schedule as $idx => $item) {
            $when = isset($item['scheduled_at']) ? date('d/m/Y H:i:s', (int)$item['scheduled_at']) : '—';
            $htmlSchedule .= sprintf(
                '<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%d</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">%s</td></tr>',
                $idx + 1,
                htmlspecialchars((string)($item['name'] ?? 'Contato'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($item['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($item['cpf'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($when, ENT_QUOTES, 'UTF-8')
            );
        }

        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:Arial, sans-serif;font-size:12px;color:#0f172a;}h1{font-size:18px;margin:0 0 8px;}h2{font-size:14px;margin:14px 0 6px;}table{border-collapse:collapse;width:100%;}th{background:#f1f5f9;text-align:left;padding:8px 8px;border-bottom:1px solid #e5e7eb;}td{font-size:11px;}small{color:#475569;}</style></head><body>';
        $html .= sprintf('<h1>%s</h1>', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
        $html .= sprintf('<p><strong>Total de candidatos:</strong> %d | <strong>Dry-run:</strong> sim | <strong>Mensagem real renderizada na simulação</strong></p>', $total);

        if ($rows !== []) {
            $html .= '<h2>Prévia das mensagens</h2><table><thead><tr><th>#</th><th>Nome</th><th>Telefone</th><th>CPF</th><th>Mensagem</th></tr></thead><tbody>' . $htmlRows . '</tbody></table>';
        } else {
            $html .= '<p>Nenhum destinatário encontrado.</p>';
        }

        if ($htmlSchedule !== '') {
            $html .= '<h2>Agenda simulada (horário previsto)</h2><table><thead><tr><th>#</th><th>Nome</th><th>Telefone</th><th>CPF</th><th>Agendado para</th></tr></thead><tbody>' . $htmlSchedule . '</tbody></table>';
        }

        $html .= '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $bytes = $dompdf->output();

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="simulacao.pdf"',
        ]);
    }

    /**
     * @return array<int, array{contact_id:int|null,email:string,name:string}>
     */
    private function collectListRecipients(int $listId, bool $dedupe): array
    {
        $limit = 500;
        $offset = 0;
        $seen = [];
        $recipients = [];

        while (true) {
            $chunk = $this->lists->contacts($listId, 'subscribed', $limit, $offset);
            if ($chunk === []) {
                break;
            }

            foreach ($chunk as $contact) {
                $email = isset($contact['email']) ? strtolower(trim((string)$contact['email'])) : '';
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                if ($dedupe && isset($seen[$email])) {
                    continue;
                }

                $seen[$email] = true;
                $name = trim((string)(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
                if ($name === '') {
                    $name = $email;
                }

                $recipients[] = [
                    'contact_id' => isset($contact['id']) ? (int)$contact['id'] : null,
                    'email' => $email,
                    'name' => $name,
                ];
            }

            $offset += $limit;
            if (count($chunk) < $limit) {
                break;
            }
        }

        return $recipients;
    }
}


