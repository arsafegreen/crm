<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CampaignMessageRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ClientRepository;
use App\Repositories\TemplateRepository;
use App\Services\EmailAccountService;
use App\Services\CampaignAutomationConfig;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CampaignController
{
    public function email(Request $request): Response
    {
        $clientRepository = new ClientRepository();
        $campaignRepository = new CampaignRepository();
        $campaignMessageRepository = new CampaignMessageRepository();
        $templateRepository = new TemplateRepository();
        $templateRepository->seedDefaults();
        $automationConfig = (new CampaignAutomationConfig())->load();
        $emailAccounts = $this->activeEmailAccounts();

        $currentYear = (int)date('Y');
        $monthOptions = $this->monthLabels();

        $selectedMonth = (int)$request->query->get('month', 0);
        $scope = (string)$request->query->get('scope', 'current');
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $selectedYear = (int)$request->query->get('year', $currentYear);
        if ($scope === 'all') {
            $selectedYear = $currentYear;
        }

        $selectedTemplateId = max(0, (int)$request->query->get('template_id', 0));

        $templates = $templateRepository->all('email');
        $selectedTemplate = null;
        $selectedTemplateVersion = null;
        if ($selectedTemplateId > 0) {
            $selectedTemplate = $templateRepository->find($selectedTemplateId);
            if ($selectedTemplate === null) {
                $selectedTemplateId = 0;
            } else {
                $selectedTemplateVersion = $selectedTemplate['latest_version'] ?? null;
            }
        }

        $monthName = $monthOptions[$selectedMonth] ?? null;
        $defaultSubject = $monthName !== null
            ? sprintf('Renove seu certificado digital - %s', $monthName)
            : 'Renove seu certificado digital';
        $defaultBody = $this->defaultEmailTemplate();

        if ($selectedTemplate !== null) {
            if (!empty($selectedTemplate['subject'])) {
                $defaultSubject = trim((string)$selectedTemplate['subject']);
            }
            if (!empty($selectedTemplate['body_html'])) {
                $defaultBody = (string)$selectedTemplate['body_html'];
            } elseif (!empty($selectedTemplate['body_text'])) {
                $defaultBody = (string)$selectedTemplate['body_text'];
            }
        }

        $recipients = [];
        if ($selectedMonth >= 1 && $selectedMonth <= 12) {
            $recipients = $clientRepository->recipientsByExpirationMonth(
                $selectedMonth,
                $scope,
                $scope === 'current' ? $selectedYear : null
            );
        }

        $previewCount = count($recipients);
        $previewSampleLimit = 200;
        $previewSample = $previewCount > 0 ? array_slice($recipients, 0, $previewSampleLimit) : [];
        $previewOverflow = $previewCount > $previewSampleLimit;

        $feedback = $_SESSION['campaign_feedback'] ?? null;
        unset($_SESSION['campaign_feedback']);

        $yearOptions = [$currentYear - 1, $currentYear, $currentYear + 1];

        return view('campaigns/email', [
            'monthOptions' => $monthOptions,
            'templates' => $templates,
            'selectedTemplateId' => $selectedTemplateId,
            'selectedTemplate' => $selectedTemplate,
            'selectedTemplateVersion' => $selectedTemplateVersion,
            'selectedMonth' => $selectedMonth,
            'scope' => $scope,
            'selectedYear' => $selectedYear,
            'yearOptions' => $yearOptions,
            'monthName' => $monthName,
            'previewCount' => $previewCount,
            'previewSample' => $previewSample,
            'previewOverflow' => $previewOverflow,
            'totalRecipients' => $previewCount,
            'defaultSubject' => $defaultSubject,
            'defaultBody' => $defaultBody,
            'feedback' => $feedback,
            'recentCampaigns' => $campaignRepository->recent(6),
            'recentMessages' => $campaignMessageRepository->recent(12),
            'statusLabels' => $clientRepository->statusLabels(),
            'automationConfig' => $automationConfig,
            'emailAccounts' => $emailAccounts,
        ]);
    }

    public function saveAutomation(Request $request): Response
    {
        $templateRepository = new TemplateRepository();
        $templateRepository->seedDefaults();

        $templates = $templateRepository->all('email');
        $validTemplateIds = array_map(static fn(array $tpl): int => (int)$tpl['id'], $templates);
        $emailAccounts = $this->activeEmailAccounts();
        $validAccountIds = array_map(static fn(array $row): int => (int)$row['id'], $emailAccounts);

        $renewalEnabled = in_array((string)$request->request->get('automation_renewal_enabled', '0'), ['1', 'on', 'true'], true);
        $renewalTemplateId = (int)$request->request->get('automation_renewal_template_id', 0);
        $renewalOffsets = $this->parseOffsetInput((string)$request->request->get('automation_renewal_offsets', ''), [50, 30, 15, 5, 0, -5, -15, -30]);
        $renewalSenderAccountId = (int)$request->request->get('automation_renewal_sender_account_id', 0);

        $birthdayEnabled = in_array((string)$request->request->get('automation_birthday_enabled', '0'), ['1', 'on', 'true'], true);
        $birthdayTemplateId = (int)$request->request->get('automation_birthday_template_id', 0);
        $birthdayOffsets = $this->parseOffsetInput((string)$request->request->get('automation_birthday_offsets', ''), [0]);
        $birthdaySenderAccountId = (int)$request->request->get('automation_birthday_sender_account_id', 0);

        if (!in_array($renewalTemplateId, $validTemplateIds, true)) {
            $renewalTemplateId = 0;
        }

        if (!in_array($birthdayTemplateId, $validTemplateIds, true)) {
            $birthdayTemplateId = 0;
        }

        if (!in_array($renewalSenderAccountId, $validAccountIds, true)) {
            $renewalSenderAccountId = 0;
        }

        if (!in_array($birthdaySenderAccountId, $validAccountIds, true)) {
            $birthdaySenderAccountId = 0;
        }

        $config = [
            'renewal' => [
                'enabled' => $renewalEnabled,
                'template_id' => $renewalTemplateId ?: null,
                'sender_account_id' => $renewalSenderAccountId ?: null,
                'offsets' => $renewalOffsets,
            ],
            'birthday' => [
                'enabled' => $birthdayEnabled,
                'template_id' => $birthdayTemplateId ?: null,
                'sender_account_id' => $birthdaySenderAccountId ?: null,
                'offsets' => $birthdayOffsets,
            ],
        ];

        (new CampaignAutomationConfig())->save($config);

        $this->flash('success', 'Régua automática atualizada.');

        return new RedirectResponse(url('campaigns/email'));
    }

    public function createEmailCampaign(Request $request): Response
    {
        $month = (int)$request->request->get('month');
        $scope = (string)$request->request->get('scope', 'current');
        $scope = in_array($scope, ['current', 'all'], true) ? $scope : 'current';
        $year = (int)$request->request->get('year', (int)date('Y'));
        if ($scope === 'all') {
            $year = (int)date('Y');
        }

        $templateId = max(0, (int)$request->request->get('template_id', 0));

        $subject = trim((string)$request->request->get('subject', ''));
        $bodyHtml = trim((string)$request->request->get('body_html', ''));
        $scheduledFor = $request->request->get('scheduled_for');

        if ($month < 1 || $month > 12) {
            $this->flash('error', 'Escolha um mês válido para a campanha.');
            return new RedirectResponse(url('campaigns/email'));
        }

        $monthLabels = $this->monthLabels();
        $monthName = $monthLabels[$month] ?? sprintf('Mês %02d', $month);

        $templateRepository = new TemplateRepository();
        $template = null;
        $templateVersionId = null;
        if ($templateId > 0) {
            $template = $templateRepository->find($templateId);
            if ($template !== null) {
                $latestVersionId = isset($template['latest_version_id']) ? (int)$template['latest_version_id'] : 0;
                if ($latestVersionId > 0) {
                    $templateVersionId = $latestVersionId;
                } elseif (!empty($template['latest_version']) && isset($template['latest_version']['id'])) {
                    $templateVersionId = (int)$template['latest_version']['id'];
                }
            }
        }

        if ($subject === '' && $template !== null && !empty($template['subject'])) {
            $subject = trim((string)$template['subject']);
        }

        if ($bodyHtml === '' && $template !== null) {
            if (!empty($template['body_html'])) {
                $bodyHtml = (string)$template['body_html'];
            } elseif (!empty($template['body_text'])) {
                $bodyHtml = (string)$template['body_text'];
            }
        }

        if ($subject === '') {
            $subject = sprintf('Renove seu certificado digital - %s', $monthName);
        }

        if ($bodyHtml === '') {
            $bodyHtml = $this->defaultEmailTemplate();
        }

        $bodyText = trim(strip_tags($bodyHtml));
        $bodyText = $bodyText !== '' ? $bodyText : null;

        $clientRepository = new ClientRepository();
        $recipients = $clientRepository->recipientsByExpirationMonth(
            $month,
            $scope,
            $scope === 'current' ? $year : null
        );

        if ($recipients === []) {
            $this->flash('warning', 'Nenhum cliente encontrado para os filtros informados.');
            $queryParams = [
                'month' => $month,
                'scope' => $scope,
                'year' => $year,
            ];
            if ($templateId > 0) {
                $queryParams['template_id'] = $templateId;
            }
            $query = http_build_query($queryParams);
            return new RedirectResponse(url('campaigns/email') . ($query !== '' ? '?' . $query : ''));
        }

        $scheduledTimestamp = null;
        if (is_string($scheduledFor) && $scheduledFor !== '') {
            $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $scheduledFor, $timezone);
            if ($date === false) {
                $this->flash('error', 'Formato de data e hora inválido para o agendamento.');
                $queryParams = [
                    'month' => $month,
                    'scope' => $scope,
                    'year' => $year,
                ];
                if ($templateId > 0) {
                    $queryParams['template_id'] = $templateId;
                }
                $query = http_build_query($queryParams);
                return new RedirectResponse(url('campaigns/email') . ($query !== '' ? '?' . $query : ''));
            }
            $scheduledTimestamp = $date->getTimestamp();
        }

        $campaignName = sprintf(
            'Renovação %s %s',
            $monthName,
            $scope === 'current' ? (string)$year : 'todos os anos'
        );

        $filters = [
            'type' => 'expiration_month',
            'month' => $month,
            'scope' => $scope,
            'year' => $scope === 'current' ? $year : null,
        ];

        $timestamp = now();
        $messages = [];
        foreach ($recipients as $recipient) {
            $placeholders = [
                'client_name' => $recipient['name'],
                'certificate_expires_at' => $recipient['expires_at'],
                'certificate_expires_at_formatted' => format_date($recipient['expires_at'] ?? null),
                'client_status' => $recipient['status'],
            ];

            $messages[] = [
                'client_id' => $recipient['client_id'] ?? null,
                'certificate_id' => $recipient['certificate_id'] ?? null,
                'channel' => 'email',
                'status' => 'pending',
                'scheduled_for' => $scheduledTimestamp,
                'payload' => json_encode([
                    'to_name' => $recipient['name'],
                    'to_email' => $recipient['email'],
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                    'template_id' => $templateId > 0 ? $templateId : null,
                    'template_version_id' => $templateVersionId,
                    'placeholders' => $placeholders,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'template_version_id' => $templateVersionId,
            ];
        }

        $campaignData = [
            'name' => $campaignName,
            'channel' => 'email',
            'status' => 'draft',
            'description' => sprintf(
                'Clientes com certificado vencendo em %s (%s).',
                $monthName,
                $scope === 'current' ? 'ano atual' : 'todos os anos'
            ),
            'scheduled_for' => $scheduledTimestamp,
            'template_subject' => $subject,
            'template_body_html' => $bodyHtml,
            'template_body_text' => $bodyText,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'template_id' => $templateId > 0 ? $templateId : null,
            'template_version_id' => $templateVersionId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $campaignRepository = new CampaignRepository();

        try {
            $result = $campaignRepository->createWithMessages($campaignData, $messages);
        } catch (Throwable $exception) {
            $this->flash('error', 'Erro ao criar a campanha: ' . $exception->getMessage());
            $queryParams = [
                'month' => $month,
                'scope' => $scope,
                'year' => $year,
            ];
            if ($templateId > 0) {
                $queryParams['template_id'] = $templateId;
            }
            $query = http_build_query($queryParams);
            return new RedirectResponse(url('campaigns/email') . ($query !== '' ? '?' . $query : ''));
        }

        $this->flash(
            'success',
            sprintf('Campanha criada com sucesso com %d destinatários.', $result['messages_created'] ?? count($messages)),
            [
                'campaign_id' => $result['campaign_id'] ?? null,
                'messages' => $result['messages_created'] ?? count($messages),
            ]
        );

        $queryParams = [
            'month' => $month,
            'scope' => $scope,
            'year' => $year,
        ];
        if ($templateId > 0) {
            $queryParams['template_id'] = $templateId;
        }
        $query = http_build_query($queryParams);

        return new RedirectResponse(url('campaigns/email') . ($query !== '' ? '?' . $query : ''));
    }

    private function monthLabels(): array
    {
        return [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];
    }

    private function defaultEmailTemplate(): string
    {
        return <<<HTML
<p>Olá {{client_name}},</p>
<p>Estamos acompanhando sua jornada com certificado digital e identificamos que o seu documento expira em <strong>{{certificate_expires_at_formatted}}</strong>.</p>
<p>Antecipar a renovação evita indisponibilidades e garante descontos exclusivos. Conte com nossa equipe para ajustar o melhor horário e formato de atendimento.</p>
<p>Responda este e-mail ou fale conosco pelo WhatsApp para agendar sua renovação.</p>
<p>Abraços,<br>Equipe Comercial</p>
HTML;
    }

    private function parseOffsetInput(string $input, array $fallback): array
    {
        $clean = trim($input);
        if ($clean === '') {
            return $fallback;
        }

        $parts = preg_split('/[;,\s]+/', $clean) ?: [];
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $value = (int)$part;
            $result[$value] = $value;
        }

        return $result === [] ? $fallback : array_values($result);
    }

    private function activeEmailAccounts(): array
    {
        $service = new EmailAccountService();
        $all = $service->listAccounts(false);
        return array_values(array_filter($all, static function (array $row): bool {
            return ($row['status'] ?? '') === 'active' && !isset($row['deleted_at']);
        }));
    }

    private function flash(string $type, string $message, ?array $meta = null): void
    {
        $_SESSION['campaign_feedback'] = [
            'type' => $type,
            'message' => $message,
            'meta' => $meta,
        ];
    }
}
