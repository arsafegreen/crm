<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\SegmentRepository;
use App\Repositories\Marketing\MailDeliveryLogRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Repositories\Email\EmailMessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use App\Repositories\PartnerRepository;
use App\Repositories\ClientRepository;
use App\Repositories\TemplateRepository;
use App\Database\Connection;
use App\Services\Email\InboxService;
use App\Services\EmailAccountService;
use App\Services\Marketing\MarketingContactImportService;
use App\Services\Marketing\ListMaintenanceService;
use App\Services\AlertService;
use App\Support\EmailProviderLimitDefaults;
use App\ViewModels\Marketing\ListDashboard;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use PDO;
use ZipArchive;
use function config;

final class MarketingController
{
	private const PARTNER_AUDIENCE_SLUG = 'parceiros';
	private const PARTNER_AUDIENCE_NAME = 'Parceiros';
	private const ALL_AUDIENCE_SLUG = 'todos';
	private const ALL_AUDIENCE_NAME = 'Todos';
	private const RFB_CONTAB_SLUG = 'rfb-contab';
	private const RFB_CONTAB_NAME = 'RFB-CONTAB';

	/**
	 * Grupos mensais por início de atividade (base RFB), um por mês do ano.
	 * Nome e slug alinhados ao requisito: Inicio-em-Mes.
	 * @var array<int, array{name:string,slug:string}>
	 */
	private const RFB_START_MONTH_GROUPS = [
		1 => ['name' => 'Inicio-em-Janeiro', 'slug' => 'inicio-em-janeiro'],
		2 => ['name' => 'Inicio-em-Fevereiro', 'slug' => 'inicio-em-fevereiro'],
		3 => ['name' => 'Inicio-em-Março', 'slug' => 'inicio-em-marco'],
		4 => ['name' => 'Inicio-em-Abril', 'slug' => 'inicio-em-abril'],
		5 => ['name' => 'Inicio-em-Maio', 'slug' => 'inicio-em-maio'],
		6 => ['name' => 'Inicio-em-Junho', 'slug' => 'inicio-em-junho'],
		7 => ['name' => 'Inicio-em-Julho', 'slug' => 'inicio-em-julho'],
		8 => ['name' => 'Inicio-em-Agosto', 'slug' => 'inicio-em-agosto'],
		9 => ['name' => 'Inicio-em-Setembro', 'slug' => 'inicio-em-setembro'],
		10 => ['name' => 'Inicio-em-Outubro', 'slug' => 'inicio-em-outubro'],
		11 => ['name' => 'Inicio-em-Novembro', 'slug' => 'inicio-em-novembro'],
		12 => ['name' => 'Inicio-em-Dezembro', 'slug' => 'inicio-em-dezembro'],
	];
	private const AUTO_MAINTENANCE_SLUGS = [
		self::ALL_AUDIENCE_SLUG,
		self::PARTNER_AUDIENCE_SLUG,
		self::RFB_CONTAB_SLUG,
		'inicio-em-janeiro',
		'inicio-em-fevereiro',
		'inicio-em-marco',
		'inicio-em-abril',
		'inicio-em-maio',
		'inicio-em-junho',
		'inicio-em-julho',
		'inicio-em-agosto',
		'inicio-em-setembro',
		'inicio-em-outubro',
		'inicio-em-novembro',
		'inicio-em-dezembro',
	];

	private AudienceListRepository $lists;
	private SegmentRepository $segments;
	private EmailAccountService $emailAccounts;
	private MarketingContactImportService $contactImport;
	private MailDeliveryLogRepository $deliveryLogs;
	private ListMaintenanceService $listMaintenance;

	/** @var array<string, string> */
	private array $emailProviders = [
		'gmail' => 'Google Workspace / Gmail',
		'outlook' => 'Outlook.com',
		'hotmail' => 'Hotmail / Live',
		'office365' => 'Microsoft 365',
		'zoho' => 'Zoho Mail',
		'amazonses' => 'Amazon SES',
		'sendgrid' => 'SendGrid',
		'mailtrap' => 'Mailtrap',
		'mailgrid' => 'MailGrid',
		'custom' => 'SMTP personalizado',
	];

	/** @var string[] */
	private array $emailEncryptionOptions = ['tls', 'ssl', 'none'];

	/** @var string[] */
	private array $emailAuthModes = ['login', 'oauth2', 'apikey'];

	/** @var string[] */
	private array $emailStatuses = ['active', 'paused', 'disabled'];

	/** @var string[] */
	private array $emailWarmupStatuses = ['ready', 'warming', 'cooldown'];

	/** @var array<string, string> */
	private array $templateEngines = [
		'mjml' => 'MJML responsivo',
		'email_editor_pro' => 'Email Editor Pro',
		'html_custom' => 'HTML customizado',
	];

	/** @var array<string, string> */
	private array $dnsStatusOptions = [
		'pending' => 'Pendente',
		'aligned' => 'Alinhado',
		'failing' => 'Falhando',
	];

	/** @var array<string, string> */
	private array $bouncePolicies = [
		'smart' => 'Inteligente (diferencia hard/soft)',
		'basic' => 'Básico',
		'none' => 'Sem automação',
	];

	/** @var array<string, string> */
	private array $apiProviders = [
		'none' => 'Sem integração (SMTP puro)',
		'amazon_ses' => 'Amazon SES',
		'sendgrid' => 'SendGrid',
		'mailtrap' => 'Mailtrap',
	];

	/** @var array<string, string> */
	private array $listCleaningFrequencies = [
		'weekly' => 'Semanal',
		'biweekly' => 'Quinzenal',
		'monthly' => 'Mensal',
		'quarterly' => 'Trimestral',
	];

	public function __construct(
		?AudienceListRepository $lists = null,
		?SegmentRepository $segments = null,
		?EmailAccountService $emailAccounts = null,
		?MarketingContactImportService $contactImport = null,
		?MailDeliveryLogRepository $deliveryLogs = null,
		?ListMaintenanceService $listMaintenance = null
	) {
		$this->lists = $lists ?? new AudienceListRepository();
		$this->segments = $segments ?? new SegmentRepository();
		$this->emailAccounts = $emailAccounts ?? new EmailAccountService();
		$this->contactImport = $contactImport ?? new MarketingContactImportService();
		$this->deliveryLogs = $deliveryLogs ?? new MailDeliveryLogRepository();
		$this->listMaintenance = $listMaintenance ?? new ListMaintenanceService($this->lists);
	}

	public function lists(Request $request): Response
	{
		// Garante que grupos principais existam sem duplicar lógica no controller.
		$this->listMaintenance->bootstrapDefaults($this->maintenanceDefinitions());

		$lists = $this->lists->allWithStats();
		$segments = $this->segments->allWithCounts();
		$deliveryStatsRaw = $this->deliveryLogs->totalsByEventType(['sent', 'soft_bounce', 'hard_bounce']);
		$deliveryStats = [
			'sent' => (int)($deliveryStatsRaw['sent'] ?? 0),
			'soft_bounce' => (int)($deliveryStatsRaw['soft_bounce'] ?? 0),
			'hard_bounce' => (int)($deliveryStatsRaw['hard_bounce'] ?? 0),
		];
		$sentRecent = $this->deliveryLogs->recentByEventType(['sent'], 50);
		$errorRecent = $this->deliveryLogs->recentByEventType(['soft_bounce', 'hard_bounce'], 50);

		$contactRepo = new MarketingContactRepository();
		$contactBounceTotals = $contactRepo->bounceTotals();
		if (($deliveryStats['soft_bounce'] ?? 0) === 0) {
			$deliveryStats['soft_bounce'] = $contactBounceTotals['soft'] ?? 0;
		}
		if (($deliveryStats['hard_bounce'] ?? 0) === 0) {
			$deliveryStats['hard_bounce'] = $contactBounceTotals['hard'] ?? 0;
		}

		$scheduledEmails = (new EmailMessageRepository())->listScheduledAll(null, 30);
		$dashboard = ListDashboard::summarize($lists, $segments);

		return view('marketing/lists', [
			'lists' => $lists,
			'segmentsPreview' => array_slice($segments, 0, 4),
			'segmentsTotal' => count($segments),
			'feedback' => $this->pullFeedback('marketing_lists_feedback'),
			'deliveryStats' => $deliveryStats,
			'sentRecent' => $sentRecent,
			'errorRecent' => $errorRecent,
			'sweepStatus' => $this->readSweepStatus(),
			'autoMaintenanceSlugs' => self::AUTO_MAINTENANCE_SLUGS,
			'scheduledEmails' => $scheduledEmails,
			'listTotals' => $dashboard['totals'],
			'statusLabels' => $dashboard['statusLabels'],
		]);
	}

	public function automations(Request $request): Response
	{
		// Página hub para automações de WhatsApp e e-mail.
		$whatsappUrl = url('whatsapp') . '?standalone=1&channel=alt';
		$emailAutomationsUrl = url('campaigns/email');

		return view('marketing/automations', [
			'whatsappUrl' => $whatsappUrl,
			'emailAutomationsUrl' => $emailAutomationsUrl,
		]);
	}

	public function updateScheduledEmail(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$datetime = (string)$request->request->get('scheduled_for', '');

		$repo = new EmailMessageRepository();
		$row = $repo->find($id);
		if ($row === null || ($row['status'] ?? '') !== 'scheduled') {
			return new RedirectResponse(url('marketing/lists'));
		}

		$timestamp = $this->parseDatetimeLocal($datetime);
		if ($timestamp === null) {
			$_SESSION['marketing_feedback'] = ['type' => 'error', 'message' => 'Data/hora inválida para reagendar.'];
			return new RedirectResponse(url('marketing/lists'));
		}

		$repo->update($id, [
			'scheduled_for' => $timestamp,
			'updated_at' => time(),
		]);

		$_SESSION['marketing_feedback'] = ['type' => 'success', 'message' => 'Agendamento atualizado.'];
		return new RedirectResponse(url('marketing/lists'));
	}

	public function cancelScheduledEmail(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$repo = new EmailMessageRepository();
		$row = $repo->find($id);
		if ($row === null || ($row['status'] ?? '') !== 'scheduled') {
			return new RedirectResponse(url('marketing/lists'));
		}

		$repo->update($id, [
			'status' => 'draft',
			'scheduled_for' => null,
			'updated_at' => time(),
		]);

		$_SESSION['marketing_feedback'] = ['type' => 'success', 'message' => 'Agendamento cancelado e salvo como rascunho.'];
		return new RedirectResponse(url('marketing/lists'));
	}

	private function parseDatetimeLocal(string $value): ?int
	{
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
		$dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);
		if ($dt === false) {
			return null;
		}
		return $dt->getTimestamp();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function maintenanceDefinitions(): array
	{
		$definitions = [
			[
				'name' => self::ALL_AUDIENCE_NAME,
				'slug' => self::ALL_AUDIENCE_SLUG,
				'description' => 'Todos os contatos ativos na base.',
				'origin' => 'system',
				'purpose' => 'Envios gerais e comunicados.',
				'status' => 'active',
			],
			[
				'name' => self::PARTNER_AUDIENCE_NAME,
				'slug' => self::PARTNER_AUDIENCE_SLUG,
				'description' => 'Contatos de parceiros/indicadores.',
				'origin' => 'crm_partners',
				'purpose' => 'Relacionamento com parceiros.',
				'status' => 'active',
			],
			[
				'name' => self::RFB_CONTAB_NAME,
				'slug' => self::RFB_CONTAB_SLUG,
				'description' => 'Base RFB com e-mails contendo "contab" (CNPJ + razão social + e-mail).',
				'origin' => 'rfb_contab',
				'purpose' => 'Campanhas para contabilidades identificadas na base RFB.',
				'status' => 'active',
			],
		];

		// Grupos mensais de início de atividade (RFB).
		foreach (self::RFB_START_MONTH_GROUPS as $config) {
			$definitions[] = [
				'name' => $config['name'],
				'slug' => $config['slug'],
				'description' => 'Empresas com início de atividade no mês de ' . $config['name'],
				'origin' => 'rfb_inicio_atividade',
				'purpose' => 'Campanhas segmentadas por mês de abertura na base RFB.',
				'status' => 'active',
			];
		}

		return $definitions;
	}

	public function sweepStart(Request $request): Response
	{
		$current = $this->readSweepStatus();
		if ($current === 'running') {
			$this->flashFeedback('marketing_lists_feedback', 'info', 'Varredura já está em execução.');
			return new RedirectResponse(url('marketing/lists'));
		}

		$this->writeSweepStatus('running');
		$this->launchSweepAsync();
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Varredura marcada como iniciada.');
		return new RedirectResponse(url('marketing/lists'));
	}

	public function sweepStop(Request $request): Response
	{
		$this->writeSweepStatus('stopped');
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Varredura marcada como parada.');
		return new RedirectResponse(url('marketing/lists'));
	}

	private function sweepStateFile(): string
	{
		return dirname(__DIR__, 2) . '/storage/sweep_state.json';
	}

	private function readSweepStatus(): string
	{
		$file = $this->sweepStateFile();
		if (!is_file($file)) {
			return 'stopped';
		}
		$raw = @file_get_contents($file);
		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) && isset($decoded['status'])) {
			return (string)$decoded['status'];
		}
		return 'stopped';
	}

	private function writeSweepStatus(string $status): void
	{
		$file = $this->sweepStateFile();
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		$current = [];
		if (is_file($file)) {
			$raw = @file_get_contents($file);
			$decoded = json_decode((string)$raw, true);
			if (is_array($decoded)) {
				$current = $decoded;
			}
		}

		if ($status === 'running') {
			$payload = [
				'status' => 'running',
				'updated_at' => now(),
				'started_at' => now(),
				'last_id' => 0,
				'batch_size' => 0,
				'total_checked' => 0,
				'hard_blocked' => 0,
				'skipped' => 0,
				'batches' => 0,
				'finished_at' => null,
			];
		} else {
			$payload = array_merge($current, ['status' => $status, 'updated_at' => now()]);
		}

		@file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES));
	}

	private function launchSweepAsync(): void
	{
		$root = dirname(__DIR__, 3);
		$script = $root . '/scripts/marketing/sweep_contacts.php';
		if (!is_file($script)) {
			return;
		}

		$phpCandidates = [
			$root . '/php/php.exe',
			$root . '/php/php',
			'php',
		];
		$phpBinary = 'php';
		foreach ($phpCandidates as $candidate) {
			if (is_file($candidate)) {
				$phpBinary = $candidate;
				break;
			}
		}
		$stateDir = dirname($this->sweepStateFile());
		$log = $stateDir . '/sweep.log';
		$dir = dirname($log);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		// Use start /B to avoid opening a new window; log stdout/stderr to storage/sweep.log
		$cmd = 'cmd /C start "" /B ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1';
		@popen($cmd, 'r');
	}

	public function suppressUpload(Request $request): Response
	{
		$file = $request->files->get('suppress_file');
		if (!$file instanceof UploadedFile) {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Envie um arquivo TXT ou CSV com e-mails.');
			return new RedirectResponse(url('marketing/lists'));
		}

		$content = @file_get_contents($file->getRealPath());
		if ($content === false || trim($content) === '') {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Arquivo vazio ou ilegível.');
			return new RedirectResponse(url('marketing/lists'));
		}

		$tokens = preg_split('/[\s,;]+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$unique = [];
		foreach ($tokens as $token) {
			$email = strtolower(trim($token));
			if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			$unique[$email] = true;
		}

		if ($unique === []) {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Nenhum e-mail válido encontrado.');
			return new RedirectResponse(url('marketing/lists'));
		}

		$contactRepo = new MarketingContactRepository();
		$suppressed = 0;
		$created = 0;

		foreach (array_keys($unique) as $email) {
			$existing = $contactRepo->findByEmail($email);
			if ($existing !== null) {
				$contactRepo->incrementBounce((int)$existing['id'], false);
				$contactRepo->markOptOut((int)$existing['id'], 'imported_suppression');
				$suppressed++;
				continue;
			}

			$contactRepo->create([
				'email' => $email,
				'status' => 'inactive',
				'consent_status' => 'opted_out',
				'bounce_count' => 3,
			]);
			$created++;
		}

		$message = sprintf('Lista negativa aplicada: %d contatos suprimidos, %d novos bloqueados.', $suppressed, $created);
		$this->flashFeedback('marketing_lists_feedback', 'success', $message);

		return new RedirectResponse(url('marketing/lists'));
	}

	public function createList(Request $request): Response
	{
		[$values, $errors] = $this->resolveFormState('marketing_list_form', 'create', $this->listFormDefaults());

		return view('marketing/list_form', [
			'mode' => 'create',
			'list' => $values,
			'errors' => $errors,
			'action' => url('marketing/lists'),
			'title' => 'Nova lista de audiência',
		]);
	}

	public function editList(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$current = $this->lists->find($id);
		if ($current === null) {
			return abort(404, 'Lista não encontrada.');
		}

		[$values, $errors] = $this->resolveFormState('marketing_list_form', 'edit', $this->listFormDefaults($current), $id);
		$payload = array_merge($current, $values, ['id' => $id]);
		$page = max(1, (int)$request->query->get('page', 1));
		$perPage = 100;
		$offset = ($page - 1) * $perPage;
		$contacts = $this->lists->contacts($id, null, $perPage, $offset);
		$contactsTotal = $this->lists->countContacts($id);
		$pages = (int)max(1, (int)ceil($contactsTotal / $perPage));
		$page = min($page, $pages);

		return view('marketing/list_form', [
			'mode' => 'edit',
			'list' => $payload,
			'errors' => $errors,
			'contacts' => $contacts,
			'contactsTotal' => $contactsTotal,
			'contactsPage' => $page,
			'contactsPerPage' => $perPage,
			'contactsPages' => $pages,
			'action' => url('marketing/lists/' . $id . '/update'),
			'title' => 'Editar lista de audiência',
		]);
	}

	public function storeList(Request $request): Response
	{
		$payload = $this->extractListPayload($request, null);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_list_form', [
				'mode' => 'create',
				'data' => $payload['old'],
				'errors' => $payload['errors'],
			]);

			return new RedirectResponse(url('marketing/lists/create'));
		}

		$this->lists->create($payload['data']);
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista criada com sucesso.');

		return new RedirectResponse(url('marketing/lists'));
	}

	public function pdfTestSend(Request $request, array $vars): Response
	{
		$listId = (int)($vars['id'] ?? 0);
		$list = $this->lists->find($listId);
		if ($list === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$limit = (int)$request->request->get('limit', 1000);
		$limit = max(1, min($limit, 1000));
		$contacts = $this->lists->contacts($listId, 'subscribed', $limit, 0);
		if ($contacts === []) {
			// Gera pelo menos um PDF de referência mesmo sem contatos inscritos
			$contacts = [[
				'email' => 'teste-pdf@example.com',
				'first_name' => 'Contato',
				'last_name' => 'Teste',
				'company' => $list['name'] ?? 'Lista',
			]];
		}

		$timestamp = date('Ymd_His');
		$root = dirname(__DIR__, 3);
		$baseDir = $root . '/storage/pdf_tests';
		if (!is_dir($baseDir)) {
			@mkdir($baseDir, 0777, true);
		}

		$templateRepo = new TemplateRepository();
		$templateRepo->seedDefaults();
		$templateHtml = null;
		$templateSubject = null;
		foreach ($templateRepo->all('email') as $tpl) {
			$tplName = strtolower((string)($tpl['name'] ?? ''));
			$category = strtolower((string)($tpl['metadata']['category'] ?? ''));
			if ($tplName === 'natal' || $category === 'natal') {
				$templateHtml = (string)($tpl['body_html'] ?? '');
				$templateSubject = (string)($tpl['subject'] ?? '');
				break;
			}
		}

		if (trim((string)$templateHtml) === '') {
			$templateHtml = $this->pdfTestTemplateHtml($list['name'] ?? 'Lista', $list['slug'] ?? '');
		}
		if (trim((string)$templateSubject) === '') {
			$templateSubject = 'Feliz Natal, {{nome}}!';
		}

		$perDocument = $this->resolveListPerDocument($list);
		$expanded = [];
		foreach ($contacts as $contact) {
			$cnpjDigits = preg_replace('/[^0-9]/', '', (string)($contact['cnpj'] ?? '')) ?? '';
			$cpfDigits = preg_replace('/[^0-9]/', '', (string)($contact['cpf'] ?? '')) ?? '';
			$docDigits = preg_replace('/[^0-9]/', '', (string)($contact['document'] ?? '')) ?? '';
			$added = false;

			if ($perDocument) {
				$seenDocs = [];
				if (strlen($cnpjDigits) === 14) {
					$expanded[] = array_merge($contact, ['__doc_digits' => $cnpjDigits, '__doc_type' => 'cnpj']);
					$seenDocs[$cnpjDigits] = true;
					$added = true;
				}
				if (strlen($cpfDigits) === 11 && !isset($seenDocs[$cpfDigits])) {
					$expanded[] = array_merge($contact, ['__doc_digits' => $cpfDigits, '__doc_type' => 'cpf']);
					$seenDocs[$cpfDigits] = true;
					$added = true;
				}
				if (!$added && $docDigits !== '') {
					$expanded[] = array_merge($contact, ['__doc_digits' => $docDigits, '__doc_type' => strlen($docDigits) === 14 ? 'cnpj' : 'cpf']);
					$added = true;
				}
			}

			if (!$added) {
				$expanded[] = $contact;
			}
		}

		$pages = [];
		$txtLines = [];
		foreach ($expanded as $index => $contact) {
			$recipient = [
				'email' => $contact['email'] ?? '',
				'name' => trim((string)($contact['first_name'] ?? '')) !== ''
					? trim((string)($contact['first_name'] ?? '') . ' ' . (string)($contact['last_name'] ?? ''))
					: ($contact['name'] ?? ($contact['email'] ?? '')),
			];

			$tokens = $this->buildEmailTokensForTest($recipient, $contact, null, $list, $perDocument);
			$subject = str_replace(array_keys($tokens), array_values($tokens), $templateSubject);
			$body = str_replace(array_keys($tokens), array_values($tokens), $templateHtml);

			$pages[] = $this->wrapPdfHtml($subject, $body, $tokens);

			$txtLines[] = 'Contato #' . ($index + 1) . ' - ' . ($recipient['email'] ?: 'sem-email');
			foreach ($tokens as $key => $value) {
				$txtLines[] = sprintf("%s: %s", $key, $value);
			}
			$txtLines[] = str_repeat('-', 40);
		}

		if ($pages === []) {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Nenhum PDF gerado.');
			return new RedirectResponse(url('marketing/lists'));
		}

		$htmlDocument = '<html><head><meta charset="utf-8"><style>'
			. '@page { margin: 12mm 12mm 12mm 12mm; }'
			. 'html,body{margin:0;padding:0;font-family:Arial,sans-serif;color:#0f172a;background:#f8fafc;display:block;}'
			. '.page{page-break-after:always;display:block;margin:0 auto;padding:0;}'
			. '.wrap{box-sizing:border-box;max-width:600px;width:100%;margin:0 auto;padding:12px 12px 14px 12px;}'
			. '.email-preview{box-sizing:border-box;max-width:560px;width:100%;margin:0 auto;padding:8px 0 10px 0;overflow-wrap:break-word;word-break:break-word;text-align:left;}'
			. '.email-preview img{max-width:100%;height:auto;display:block;}'
			. '</style></head><body>'
			. implode('<div class="page-break"></div>', $pages)
			. '</body></html>';

		$pdfPath = $baseDir . '/list_' . $listId . '_' . $timestamp . '.pdf';
		$this->renderPdfToFile($htmlDocument, $pdfPath);

		$txtPath = $baseDir . '/list_' . $listId . '_' . $timestamp . '.txt';
		file_put_contents($txtPath, implode(PHP_EOL, $txtLines));

		$downloadNameBase = 'teste-pdf-lista-' . ($list['slug'] ?? $listId) . '-' . $timestamp;

		if (class_exists(\ZipArchive::class)) {
			$zipPath = $baseDir . '/' . $downloadNameBase . '.zip';
			$zip = new ZipArchive();
			if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
				$zip->addFile($pdfPath, $downloadNameBase . '.pdf');
				$zip->addFile($txtPath, $downloadNameBase . '.txt');
				$zip->close();

				$response = new BinaryFileResponse($zipPath);
				$response->setContentDisposition('attachment', $downloadNameBase . '.zip');
				$response->deleteFileAfterSend(true);

				return $response;
			}
		}

		// Fallback: retorna apenas o PDF se não houver ZipArchive ou falha ao criar o ZIP.
		$response = new BinaryFileResponse($pdfPath);
		$response->setContentDisposition('attachment', $downloadNameBase . '.pdf');
		$response->deleteFileAfterSend(true);
		$this->flashFeedback('marketing_lists_feedback', 'success', 'ZIP indisponível; baixamos apenas o PDF. O TXT está salvo em storage/pdf_tests.');

		return $response;
	}

	private function pdfTestTemplateHtml(string $listName, string $slug): string
	{
		$attrs = ['{{nome}}','{{empresa}}','{{razao_social}}','{{email}}','{{telefone}}','{{documento}}','{{cpf}}','{{cnpj}}','{{titular_nome}}','{{titular_documento}}','{{data_nascimento}}'];
		$chips = array_map(static fn(string $attr): string => '<span style="display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:12px;background:rgba(220,38,38,0.08);color:#7f1d1d;border:1px solid rgba(220,38,38,0.2);font-weight:700;font-size:13px;">' . htmlspecialchars($attr, ENT_QUOTES, 'UTF-8') . '</span>', $attrs);

		$header = '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px;">'
			. '<div>'
			. '<div style="font-size:24px;font-weight:800;color:#0f172a;">Feliz Natal!</div>'
			. '<div style="color:#475569;font-size:14px;">Lista: ' . htmlspecialchars($listName, ENT_QUOTES, 'UTF-8') . ' · Slug: ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</div>'
			. '</div>'
			. '<div style="padding:10px 14px;border-radius:14px;background:linear-gradient(135deg,#b91c1c,#0f172a);color:#fee2e2;font-weight:700;">Prévia do e-mail</div>'
			. '</div>';

		$body = '<div style="padding:18px;border:1px solid #f1aeb5;border-radius:18px;background:linear-gradient(180deg,#fff5f5,#ffffff);box-shadow:0 10px 30px rgba(185,28,28,0.15);">'
			. '<div style="font-size:16px;color:#0f172a;line-height:1.6;">'
			. '<p style="margin:0 0 12px 0;">Olá {{nome}},</p>'
			. '<p style="margin:0 0 12px 0;">Que a magia do Natal ilumine seu caminho. Preparamos esta prévia para validar os placeholders do disparo.</p>'
			. '<div style="margin:16px 0;border:1px dashed #e11d48;border-radius:14px;padding:14px 16px;background:rgba(248,250,252,0.9);">
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 14px;font-size:13px;color:#0f172a;">
					<div><strong>Nome</strong><br>{{nome}}</div>
					<div><strong>Empresa/Razão</strong><br>{{empresa}} / {{razao_social}}</div>
					<div><strong>Email</strong><br>{{email}}</div>
					<div><strong>Telefone</strong><br>{{telefone}}</div>
					<div><strong>Documento</strong><br>{{documento}}</div>
					<div><strong>CPF</strong><br>{{cpf}}</div>
					<div><strong>CNPJ</strong><br>{{cnpj}}</div>
					<div><strong>Titular</strong><br>{{titular_nome}}</div>
					<div><strong>Doc. Titular</strong><br>{{titular_documento}}</div>
					<div><strong>Data de nascimento</strong><br>{{data_nascimento}}</div>
				</div>
			</div>'
			. '<p style="margin:12px 0 0 0;">Se tudo estiver correto, esta página representa o e-mail que cada contato receberia.</p>'
			. '</div>'
			. '</div>';

		return '<div style="font-family:Segoe UI, Arial, sans-serif; color:#0f172a;">'
			. $header
			. '<div style="color:#7f1d1d;font-size:14px;margin-bottom:10px;">Atributos disponíveis neste envio:</div>'
			. '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px 0;">' . implode('', $chips) . '</div>'
			. $body
			. '<div style="margin-top:18px;font-size:12px;color:#b91c1c;">Prévia gerada como se fosse um e-mail individual.</div>'
			. '</div>';
	}

	/** @param array<string,mixed> $recipient @param array<string,mixed> $contact @param array<string,mixed>|null $client @param array<string,mixed> $list */
	private function buildEmailTokensForTest(array $recipient, array $contact, ?array $client, array $list = [], bool $perDocument = false): array
	{
		$defaultName = 'Contato Teste';
		$meta = $this->decodeMetadata($contact['metadata'] ?? null);
		$listMeta = $this->decodeMetadata($list['metadata'] ?? null);

		$contactFirst = trim((string)($meta['first_name'] ?? $contact['first_name'] ?? ''));
		$contactLast = trim((string)($meta['last_name'] ?? $contact['last_name'] ?? ''));
		$contactName = trim($contactFirst . ' ' . $contactLast);
		if ($contactName === '' && !empty($meta['nome'])) {
			$contactName = trim((string)$meta['nome']);
		}
		if ($contactName === '' && !empty($recipient['name'])) {
			$contactName = trim((string)$recipient['name']);
		}
		if ($contactName === '') {
			$contactName = ucfirst(strtok((string)($recipient['email'] ?? ''), '@')) ?: $defaultName;
		}

		$company = (string)($meta['empresa'] ?? $meta['razao_social'] ?? $contact['company'] ?? $contact['razao_social'] ?? $client['name'] ?? $list['name'] ?? 'Empresa Exemplo');

		$cnpjDigits = preg_replace('/[^0-9]/', '', (string)($meta['cnpj'] ?? $contact['cnpj'] ?? '')) ?? '';
		$cpfDigits = preg_replace('/[^0-9]/', '', (string)($meta['cpf'] ?? $contact['cpf'] ?? '')) ?? '';
		$docDigits = preg_replace('/[^0-9]/', '', (string)($meta['documento'] ?? ($client['document'] ?? ($contact['document'] ?? '')))) ?? '';

		if (strlen($cnpjDigits) !== 14) {
			$cnpjDigits = strlen($docDigits) === 14 ? $docDigits : '';
		}
		if (strlen($cpfDigits) !== 11) {
			$cpfDigits = strlen($docDigits) === 11 ? $docDigits : '';
		}

		$formattedCnpj = strlen($cnpjDigits) === 14 ? $this->formatDocumentDigits($cnpjDigits) : '';
		$formattedCpf = strlen($cpfDigits) === 11 ? $this->formatDocumentDigits($cpfDigits) : '';
		$documentDigits = $contact['__doc_digits'] ?? ($cnpjDigits !== '' ? $cnpjDigits : ($cpfDigits !== '' ? $cpfDigits : $docDigits));
		$formattedDoc = $documentDigits !== '' ? $this->formatDocumentDigits((string)$documentDigits) : '';

		$phone = (string)($meta['telefone'] ?? $contact['phone'] ?? $contact['telefone'] ?? '');

		$titularName = trim((string)($meta['titular_nome'] ?? $client['titular_name'] ?? ($contact['titular_name'] ?? '')));
		if ($titularName === '' && $company !== '') {
			$titularName = $company;
		}
		if ($titularName === '') {
			$titularName = $contactName !== '' ? $contactName : $defaultName;
		}

		$titularDocDigits = preg_replace('/[^0-9]/', '', (string)($meta['titular_documento'] ?? $client['titular_document'] ?? ($contact['titular_document'] ?? '')) ?? '');
		if ($titularDocDigits === '' && $cpfDigits !== '') {
			$titularDocDigits = $cpfDigits;
		}
		$titularDoc = $titularDocDigits !== '' ? $this->formatDocumentDigits($titularDocDigits) : '';
		if ($titularDoc === '') {
			$titularDoc = $formattedCpf !== '' ? $formattedCpf : $formattedDoc;
		}

		$birthdate = $this->formatBirthdate($meta['data_nascimento'] ?? $client['titular_birthdate'] ?? ($contact['titular_birthdate'] ?? ($contact['birthdate'] ?? null)));

		if ($perDocument) {
			$formattedCnpj = strlen($cnpjDigits) === 14
				? $formattedCnpj
				: ((($contact['__doc_type'] ?? '') === 'cnpj') ? $formattedDoc : '');
			$formattedCpf = strlen($cpfDigits) === 11
				? $formattedCpf
				: ((($contact['__doc_type'] ?? '') === 'cpf') ? $formattedDoc : '');
			if (($contact['__doc_type'] ?? '') === 'cnpj') {
				$formattedDoc = $formattedCnpj;
			} elseif (($contact['__doc_type'] ?? '') === 'cpf') {
				$formattedDoc = $formattedCpf;
			}
		}

		$tokens = [
			'{{nome}}' => $contactName,
			'{{empresa}}' => $company,
			'{{razao_social}}' => $company,
			'{{email}}' => (string)($recipient['email'] ?? ''),
			'{{telefone}}' => $phone,
			'{{documento}}' => $formattedDoc,
			'{{cnpj}}' => $formattedCnpj,
			'{{cpf}}' => $formattedCpf,
			'{{titular_nome}}' => $titularName,
			'{{titular_documento}}' => $titularDoc,
			'{{data_nascimento}}' => $birthdate,
			'{{assinatura}}' => $listMeta['assinatura'] ?? $list['name'] ?? 'SafeGreen Certificado Digital',
			'{{ano}}' => $this->resolveAnoToken(),
		];

		// Anexa metadados adicionais como tokens extras: {{meta_chave}}
		foreach ($meta as $key => $value) {
			$keyStr = trim((string)$key);
			if ($keyStr === '' || isset($tokens['{{' . $keyStr . '}}'])) {
				continue;
			}
			$tokens['{{meta_' . $keyStr . '}}'] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return $tokens;
	}

	private function formatBirthdate(mixed $value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		try {
			if (is_int($value) || ctype_digit((string)$value)) {
				$timestamp = (int)$value;
				if ($timestamp <= 0) {
					return '';
				}
				$dt = (new \DateTimeImmutable())->setTimestamp($timestamp);
				return $dt->format('d/m/Y');
			}

			$raw = trim((string)$value);
			if ($raw === '') {
				return '';
			}
			// Tenta formatos comuns: d/m/Y e Y-m-d
			if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m) === 1) {
				return sprintf('%s/%s/%s', $m[1], $m[2], $m[3]);
			}
			if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $raw, $m) === 1) {
				return sprintf('%s/%s/%s', $m[3], $m[2], $m[1]);
			}

			$ts = strtotime($raw);
			if ($ts !== false) {
				return (new \DateTimeImmutable())->setTimestamp($ts)->format('d/m/Y');
			}
		} catch (\Exception $e) {
			return '';
		}

		return '';
	}

	/** @return array<string,mixed> */
	private function decodeMetadata(?string $value): array
	{
		if ($value === null || trim($value) === '') {
			return [];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function resolveAnoToken(): int
	{
		$timezone = config('app.timezone', 'America/Sao_Paulo');
		$now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
		$year = (int)$now->format('Y');
		$month = (int)$now->format('n');

		return $month === 12 ? $year + 1 : $year;
	}

	private function normalizeCpf(string $cpf): string
	{
		$digits = preg_replace('/\D+/', '', $cpf);
		if ($digits === null || strlen($digits) !== 11) {
			return '';
		}

		return $digits;
	}

	private function normalizeCnpj(string $cnpj): string
	{
		$digits = preg_replace('/\D+/', '', $cnpj);
		if ($digits === null || strlen($digits) !== 14) {
			return '';
		}

		return $digits;
	}

	private function isRfbStartMonthSlug(string $slug): bool
	{
		$slug = strtolower(trim($slug));
		foreach (self::RFB_START_MONTH_GROUPS as $config) {
			if ($slug === strtolower($config['slug'])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{0:int,1:array{name:string,slug:string}}
	 */
	private function rfbStartMonthConfig(string $slug): array
	{
		$slug = strtolower(trim($slug));
		foreach (self::RFB_START_MONTH_GROUPS as $month => $config) {
			if ($slug === strtolower($config['slug'])) {
				return [(int)$month, $config];
			}
		}

		throw new \InvalidArgumentException('Slug de mês RFB inválido: ' . $slug);
	}

	private function resolveListPerDocument(array $list): bool
	{
		if (isset($list['per_document'])) {
			return $this->asBool($list['per_document']);
		}

		$meta = $this->decodeMetadata($list['metadata'] ?? null);
		return isset($meta['per_document']) ? $this->asBool($meta['per_document']) : false;
	}

	private function wrapPdfHtml(string $subject, string $body, array $tokens): string
	{
		return '<div class="page">'
			. '<div class="wrap">'
				. '<div class="email-preview">' . $body . '</div>'
			. '</div>'
		. '</div>';
	}

	private function renderPdfToFile(string $html, string $filePath): void
	{
		$options = new Options();
		$options->set('isRemoteEnabled', true);
		$options->set('isHtml5ParserEnabled', true);

		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html);
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();

		$bytes = $dompdf->output();
		if ($bytes === false) {
			throw new RuntimeException('Falha ao gerar PDF de teste.');
		}
		file_put_contents($filePath, $bytes);
	}

	private function formatDocumentDigits(string $digits): string
	{
		$len = strlen($digits);
		if ($len === 14) {
			return sprintf('%s.%s.%s/%s-%s',
				substr($digits, 0, 2),
				substr($digits, 2, 3),
				substr($digits, 5, 3),
				substr($digits, 8, 4),
				substr($digits, 12, 2)
			);
		}
		if ($len === 11) {
			return sprintf('%s.%s.%s-%s',
				substr($digits, 0, 3),
				substr($digits, 3, 3),
				substr($digits, 6, 3),
				substr($digits, 9, 2)
			);
		}
		return $digits;
	}

	public function searchListContacts(Request $request, array $vars): Response
	{
		$listId = (int)($vars['id'] ?? 0);
		if ($listId <= 0) {
			return abort(404, 'Lista inválida.');
		}

		$query = trim((string)$request->query->get('q', ''));
		$results = [];

		if ($query !== '') {
			$contactRepo = new MarketingContactRepository();
			$partnerRepo = new PartnerRepository();
			$clientRepo = new ClientRepository();

			if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
				$results[] = [
					'label' => $query,
					'email' => strtolower($query),
				];
			}

			foreach ($partnerRepo->searchByName($query, 12, 0)['items'] ?? [] as $partner) {
				$email = strtolower(trim((string)($partner['email'] ?? '')));
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					continue;
				}
				$results[] = [
					'label' => sprintf('%s <%s>', $partner['name'] ?? 'Parceiro', $email),
					'email' => $email,
				];
			}

			foreach ($clientRepo->searchContactEmails($query, null, false, 12, true) as $client) {
				$email = strtolower(trim((string)($client['email'] ?? '')));
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					continue;
				}
				$labelName = trim((string)($client['name'] ?? 'Cliente'));
				$results[] = [
					'label' => $labelName !== '' ? sprintf('%s <%s>', $labelName, $email) : $email,
					'email' => $email,
				];
			}

			foreach ($contactRepo->searchByEmailFragment($query, 12) as $contact) {
				$results[] = [
					'label' => $contact['email'],
					'email' => $contact['email'],
				];
			}
		}

		$unique = [];
		$final = [];
		foreach ($results as $row) {
			$email = strtolower(trim((string)($row['email'] ?? '')));
			if ($email === '' || isset($unique[$email])) {
				continue;
			}
			$unique[$email] = true;
			$final[] = [
				'label' => $row['label'] ?? $email,
				'email' => $email,
			];
		}

		return new Response(json_encode(['items' => $final], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
			'Content-Type' => 'application/json',
		]);
	}

	public function unsubscribeListContact(Request $request, array $vars): Response
	{
		$listId = (int)($vars['id'] ?? 0);
		$contactId = (int)($vars['contact_id'] ?? 0);
		if ($listId <= 0 || $contactId <= 0) {
			return abort(404, 'Lista ou contato inválido.');
		}

		$this->lists->unsubscribe($listId, $contactId, 'manual_unsubscribe');
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Contato desativado para envios nesta lista.');

		return new RedirectResponse(url('marketing/lists/' . $listId . '/edit'));
	}

	public function resubscribeListContact(Request $request, array $vars): Response
	{
		$listId = (int)($vars['id'] ?? 0);
		$contactId = (int)($vars['contact_id'] ?? 0);
		if ($listId <= 0 || $contactId <= 0) {
			return abort(404, 'Lista ou contato inválido.');
		}

		$this->lists->attachContact($listId, $contactId, [
			'subscription_status' => 'subscribed',
			'source' => 'manual_resubscribe',
			'subscribed_at' => now(),
		]);
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Contato reativado para envios nesta lista.');

		return new RedirectResponse(url('marketing/lists/' . $listId . '/edit'));
	}

	public function addListContacts(Request $request, array $vars): Response
	{
		$listId = (int)($vars['id'] ?? 0);
		$list = $this->lists->find($listId);
		if ($list === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$payloadJson = (string)$request->request->get('contacts_json', '');
		$batch = [];
		if ($payloadJson !== '') {
			$decoded = json_decode($payloadJson, true);
			if (is_array($decoded)) {
				$batch = $decoded;
			}
		}

		// fallback para incluir um único contato se não veio batch
		if ($batch === []) {
			$batch = [[
				'cpf' => (string)$request->request->get('cpf', ''),
				'email' => (string)$request->request->get('email', ''),
				'nome' => (string)$request->request->get('titular_nome', ''),
				'telefone' => (string)$request->request->get('telefone', ''),
			]];
		}

		$existing = $this->lists->contacts($listId, null, 5000, 0);
		$existingCpfSet = [];
		$existingCnpjSet = [];
		$existingEmailSet = [];
		foreach ($existing as $row) {
			$emailExisting = strtolower(trim((string)($row['email'] ?? '')));
			if ($emailExisting !== '') {
				$existingEmailSet[$emailExisting] = true;
			}
			$meta = $this->decodeMetadata($row['metadata'] ?? null);
			$existingCpf = $this->normalizeCpf((string)($meta['cpf'] ?? ''));
			if ($existingCpf !== '') {
				$existingCpfSet[$existingCpf] = true;
			}
			$existingCnpj = $this->normalizeCnpj((string)($meta['cnpj'] ?? ''));
			if ($existingCnpj !== '') {
				$existingCnpjSet[$existingCnpj] = true;
			}
		}

		$contactRepo = new MarketingContactRepository();
		$inserted = 0;
		$skipped = 0;
		foreach ($batch as $entry) {
			$cpf = $this->normalizeCpf((string)($entry['cpf'] ?? ''));
			$cnpj = $this->normalizeCnpj((string)($entry['cnpj'] ?? ''));
			$email = strtolower(trim((string)($entry['email'] ?? '')));
			$name = trim((string)($entry['nome'] ?? $entry['titular_nome'] ?? ''));
			$phone = trim((string)($entry['telefone'] ?? ''));

			if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
				$skipped++;
				continue;
			}
			if ($cpf === '' && $cnpj === '') {
				$skipped++;
				continue;
			}

			if (isset($existingEmailSet[$email])) {
				$skipped++;
				continue; // e-mail já na lista
			}
			if (($cpf !== '' && isset($existingCpfSet[$cpf])) || ($cnpj !== '' && isset($existingCnpjSet[$cnpj]))) {
				$skipped++;
				continue; // documento já na lista
			}

			$contact = $contactRepo->findByEmail($email);
			if ($contact === null) {
				$contactId = $contactRepo->create([
					'email' => $email,
					'first_name' => $name,
					'last_name' => null,
					'status' => 'active',
					'consent_status' => 'pending',
				]);
				$contact = ['id' => $contactId];
			} else {
				$contactId = (int)$contact['id'];
				$contactRepo->update($contactId, [
					'first_name' => $name,
				]);
			}

			$metadata = json_encode([
				'cpf' => $cpf,
				'cnpj' => $cnpj,
				'titular_nome' => $name,
				'telefone' => $phone,
				'email_duplicado' => false,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			$this->lists->attachContact($listId, (int)$contact['id'], [
				'subscription_status' => 'subscribed',
				'source' => 'manual_add',
				'subscribed_at' => now(),
				'consent_token' => null,
				'created_at' => now(),
				'updated_at' => now(),
				'metadata' => $metadata,
			]);

			if ($email !== '') {
				$existingEmailSet[$email] = true;
			}
			if ($cpf !== '') {
				$existingCpfSet[$cpf] = true;
			}
			if ($cnpj !== '') {
				$existingCnpjSet[$cnpj] = true;
			}
			$inserted++;
		}

		if ($inserted === 0) {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Nenhum contato válido para incluir.');
		} else {
			$message = sprintf('Incluímos %d contato(s). Ignorados %d.', $inserted, $skipped);
			$this->flashFeedback('marketing_lists_feedback', 'success', $message);
		}

		return new RedirectResponse(url('marketing/lists/' . $listId . '/edit'));
	}

	public function updateList(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$current = $this->lists->find($id);
		if ($current === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$payload = $this->extractListPayload($request, $current);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_list_form', [
				'mode' => 'edit',
				'id' => $id,
				'data' => $payload['old'],
				'errors' => $payload['errors'],
			]);

			return new RedirectResponse(url('marketing/lists/' . $id . '/edit'));
		}

		$this->lists->update($id, $payload['data']);
		$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista atualizada com sucesso.');

		return new RedirectResponse(url('marketing/lists'));
	}

	public function archiveList(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$current = $this->lists->find($id);
		if ($current === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$this->lists->update($id, [
			'status' => 'archived',
			'archived_at' => now(),
		]);

		$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista arquivada.');
		return new RedirectResponse(url('marketing/lists'));
	}

	public function refreshList(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$list = $this->lists->find($id);
		if ($list === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$slug = strtolower((string)($list['slug'] ?? ''));
		try {
			if ($slug === self::ALL_AUDIENCE_SLUG) {
				$this->ensureAllContactsAudience();
				$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista "Todos" atualizada.');
			} elseif ($slug === self::PARTNER_AUDIENCE_SLUG) {
				$this->syncPartnerAudienceList();
				$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista "Parceiros" atualizada.');
			} elseif ($slug === self::RFB_CONTAB_SLUG) {
				$this->syncRfbContabAudienceList();
				$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista "RFB-CONTAB" atualizada.');
			} elseif ($this->isRfbStartMonthSlug($slug)) {
				[$month, $config] = $this->rfbStartMonthConfig($slug);
				$this->syncRfbStartMonthAudienceList($month, $config['slug'], $config['name']);
				$this->flashFeedback('marketing_lists_feedback', 'success', 'Lista "' . $config['name'] . '" atualizada.');
			} else {
				$allowed = implode(', ', array_map(static fn(string $slug): string => '"' . strtoupper($slug) . '"', self::AUTO_MAINTENANCE_SLUGS));
				$this->flashFeedback('marketing_lists_feedback', 'info', 'Manutenção automática disponível apenas para as listas: ' . $allowed . '.');
			}
		} catch (\Throwable $exception) {
			$this->flashFeedback('marketing_lists_feedback', 'error', 'Falha ao atualizar a lista: ' . $exception->getMessage());
		}

		return new RedirectResponse(url('marketing/lists'));
	}

	public function importList(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$list = $this->lists->find($id);
		if ($list === null) {
			return abort(404, 'Lista não encontrada.');
		}

		[$form, $errors] = $this->resolveFormState('marketing_list_import_form', 'import', $this->importFormDefaults(), $id);

		return view('marketing/list_import', [
			'list' => $list,
			'form' => $form,
			'errors' => $errors,
			'limits' => $this->importLimits(),
			'result' => $this->pullImportResult($id),
			'feedback' => $this->pullFeedback('marketing_import_feedback'),
		]);
	}

	public function processImport(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$list = $this->lists->find($id);
		if ($list === null) {
			return abort(404, 'Lista não encontrada.');
		}

		$form = $this->importFormDefaults();
		$form['source_label'] = trim((string)$request->request->get('source_label', $form['source_label']));
		$form['respect_opt_out'] = $this->asBool($request->request->get('respect_opt_out', '0')) ? '1' : '0';

		$errors = [];
		$limits = $this->importLimits();
		$file = $request->files->get('import_file');
		$upload = null;
		if (!$file instanceof UploadedFile) {
			$errors['import_file'] = 'Envie um arquivo CSV com cabeçalho.';
		} else {
			$upload = $this->persistImportFile($file, (int)$limits['max_file_size_mb'], $errors);
		}

		if ($errors !== []) {
			$this->flashFormState('marketing_list_import_form', [
				'mode' => 'import',
				'id' => $id,
				'errors' => $errors,
				'data' => $form,
			]);

			return new RedirectResponse(url('marketing/lists/' . $id . '/import'));
		}

		if ($upload === null) {
			$this->flashFormState('marketing_list_import_form', [
				'mode' => 'import',
				'id' => $id,
				'errors' => ['import_file' => 'Falha ao processar o arquivo enviado.'],
				'data' => $form,
			]);

			return new RedirectResponse(url('marketing/lists/' . $id . '/import'));
		}

		$options = [
			'source_label' => $form['source_label'] !== '' ? $form['source_label'] : null,
			'respect_opt_out' => $form['respect_opt_out'] === '1',
			'user_id' => $this->resolveActorId($request),
			'filename' => $upload['original_name'],
		];

		$result = null;
		$processingError = null;

		try {
			$result = $this->contactImport->import($id, $upload['path'], $options);
		} catch (RuntimeException $exception) {
			$processingError = $exception->getMessage();
		} catch (Throwable $exception) {
			$processingError = 'Falha ao processar o CSV enviado. Tente novamente.';
		} finally {
			if (is_file($upload['path'])) {
				@unlink($upload['path']);
			}
		}

		if ($processingError !== null || $result === null) {
			$this->flashFormState('marketing_list_import_form', [
				'mode' => 'import',
				'id' => $id,
				'errors' => ['import_file' => $processingError ?? 'Erro inesperado durante a importação.'],
				'data' => $form,
			]);

			return new RedirectResponse(url('marketing/lists/' . $id . '/import'));
		}

		$stats = $result['stats'] ?? [];
		$this->flashImportResult($id, [
			'filename' => $options['filename'],
			'stats' => $stats,
			'errors' => array_slice($result['errors'] ?? [], 0, 50),
			'completed_at' => now(),
		]);

		$summary = sprintf(
			'Importação concluída: %d processadas, %d novos contatos, %d atualizados.',
			(int)($stats['processed'] ?? 0),
			(int)($stats['created_contacts'] ?? 0),
			(int)($stats['updated_contacts'] ?? 0)
		);

		$this->flashFeedback('marketing_import_feedback', 'success', $summary);
		$this->flashFeedback(
			'marketing_lists_feedback',
			'success',
			sprintf(
				'Importação na lista "%s": %d linhas (%d novos, %d atualizados).',
				(string)($list['name'] ?? 'Lista'),
				(int)($stats['processed'] ?? 0),
				(int)($stats['created_contacts'] ?? 0),
				(int)($stats['updated_contacts'] ?? 0)
			)
		);

		return new RedirectResponse(url('marketing/lists/' . $id . '/import'));
	}

	public function segments(Request $request): Response
	{
		return view('marketing/segments', [
			'segments' => $this->segments->allWithCounts(),
			'lists' => $this->lists->all(),
			'feedback' => $this->pullFeedback('marketing_segments_feedback'),
		]);
	}

	public function createSegment(Request $request): Response
	{
		[$values, $errors] = $this->resolveFormState('marketing_segment_form', 'create', $this->segmentFormDefaults());

		return view('marketing/segment_form', [
			'mode' => 'create',
			'segment' => $values,
			'errors' => $errors,
			'action' => url('marketing/segments'),
			'title' => 'Novo segmento',
			'lists' => $this->lists->all(),
		]);
	}

	public function storeSegment(Request $request): Response
	{
		$payload = $this->extractSegmentPayload($request, null);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_segment_form', [
				'mode' => 'create',
				'data' => $payload['old'],
				'errors' => $payload['errors'],
			]);

			return new RedirectResponse(url('marketing/segments/create'));
		}

		$this->segments->create($payload['data']);
		$this->flashFeedback('marketing_segments_feedback', 'success', 'Segmento criado com sucesso.');

		return new RedirectResponse(url('marketing/segments'));
	}

	public function editSegment(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$segment = $this->segments->find($id);
		if ($segment === null) {
			return abort(404, 'Segmento não encontrado.');
		}

		[$values, $errors] = $this->resolveFormState('marketing_segment_form', 'edit', $this->segmentFormDefaults($segment), $id);

		return view('marketing/segment_form', [
			'mode' => 'edit',
			'segment' => $values,
			'errors' => $errors,
			'action' => url('marketing/segments/' . $id . '/update'),
			'title' => 'Editar segmento',
			'lists' => $this->lists->all(),
		]);
	}

	public function updateSegment(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$segment = $this->segments->find($id);
		if ($segment === null) {
			return abort(404, 'Segmento não encontrado.');
		}

		$payload = $this->extractSegmentPayload($request, $segment);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_segment_form', [
				'mode' => 'edit',
				'id' => $id,
				'data' => $payload['old'],
				'errors' => $payload['errors'],
			]);

			return new RedirectResponse(url('marketing/segments/' . $id . '/edit'));
		}

		$this->segments->update($id, $payload['data']);
		$this->flashFeedback('marketing_segments_feedback', 'success', 'Segmento atualizado com sucesso.');

		return new RedirectResponse(url('marketing/segments'));
	}

	public function deleteSegment(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$segment = $this->segments->find($id);
		if ($segment === null) {
			return abort(404, 'Segmento não encontrado.');
		}

		$this->segments->delete($id);
		$this->flashFeedback('marketing_segments_feedback', 'success', 'Segmento removido.');

		return new RedirectResponse(url('marketing/segments'));
	}

	public function emailAccounts(Request $request): Response
	{
		$accounts = $this->emailAccounts->listAccounts();
		$overview = $this->buildEmailAccountOverview($accounts);

		return view('marketing/email_accounts', [
			'accounts' => $overview['accounts'],
			'summary' => $overview['summary'],
			'feedback' => $this->pullFeedback('marketing_email_accounts_feedback'),
		]);
	}

	public function createEmailAccount(Request $request): Response
	{
		[$values, $errors] = $this->resolveFormState('marketing_email_account_form', 'create', $this->emailAccountFormDefaults());
		$domainForCaps = $this->resolveAccountDomain($values);
		$caps = $this->emailAccounts->customCapSnapshot($domainForCaps, null);
		$providerLimits = $this->providerLimitSnapshot($values['provider'] ?? 'custom');

		return view('marketing/email_account_form', [
			'mode' => 'create',
			'account' => $values,
			'errors' => $errors,
			'action' => url('marketing/email-accounts'),
			'title' => 'Nova conta de envio',
			'providers' => $this->emailProviders,
			'encryptionOptions' => $this->emailEncryptionOptions,
			'authModes' => $this->emailAuthModes,
			'statusOptions' => $this->emailStatuses,
			'warmupOptions' => $this->emailWarmupStatuses,
			'templateEngines' => $this->templateEngines,
			'dnsStatusOptions' => $this->dnsStatusOptions,
			'bouncePolicies' => $this->bouncePolicies,
			'apiProviders' => $this->apiProviders,
			'listCleaningOptions' => $this->listCleaningFrequencies,
			'caps' => $caps,
			'providerLimits' => $providerLimits,
		]);
	}

	public function storeEmailAccount(Request $request): Response
	{
		$payload = $this->extractEmailAccountPayload($request, null);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_email_account_form', [
				'mode' => 'create',
				'errors' => $payload['errors'],
				'data' => $payload['old'],
			]);

			return new RedirectResponse(url('marketing/email-accounts/create'));
		}

		$this->emailAccounts->createAccount($payload['data'], $this->resolveActorId($request));
		$this->flashFeedback('marketing_email_accounts_feedback', 'success', 'Conta de envio cadastrada com sucesso.');

		return new RedirectResponse(url('marketing/email-accounts'));
	}

	public function editEmailAccount(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$account = $this->emailAccounts->getAccount($id);
		if ($account === null) {
			return abort(404, 'Conta não encontrada.');
		}

		[$values, $errors] = $this->resolveFormState('marketing_email_account_form', 'edit', $this->emailAccountFormDefaults($account), $id);
		$domainForCaps = $this->resolveAccountDomain($values);
		$caps = $this->emailAccounts->customCapSnapshot($domainForCaps, $id);
		$providerLimits = $this->providerLimitSnapshot($values['provider'] ?? 'custom');

		return view('marketing/email_account_form', [
			'mode' => 'edit',
			'account' => $values,
			'errors' => $errors,
			'action' => url('marketing/email-accounts/' . $id . '/update'),
			'title' => 'Editar conta de envio',
			'providers' => $this->emailProviders,
			'encryptionOptions' => $this->emailEncryptionOptions,
			'authModes' => $this->emailAuthModes,
			'statusOptions' => $this->emailStatuses,
			'warmupOptions' => $this->emailWarmupStatuses,
			'templateEngines' => $this->templateEngines,
			'dnsStatusOptions' => $this->dnsStatusOptions,
			'bouncePolicies' => $this->bouncePolicies,
			'apiProviders' => $this->apiProviders,
			'listCleaningOptions' => $this->listCleaningFrequencies,
			'caps' => $caps,
			'providerLimits' => $providerLimits,
		]);
	}

	private function resolveAccountDomain(array $account): string
	{
		$domain = trim((string)($account['domain'] ?? ''));
		if ($domain !== '') {
			return strtolower($domain);
		}

		$fromEmail = trim((string)($account['from_email'] ?? ''));
		if (str_contains($fromEmail, '@')) {
			return strtolower((string)substr(strstr($fromEmail, '@'), 1));
		}

		return 'custom-domain';
	}

	private function providerLimitSnapshot(string $provider): array
	{
		$defaults = EmailProviderLimitDefaults::for($provider);
		$perMinute = (int)($defaults['hourly_limit'] ?? 0);
		$perHour = $perMinute > 0 ? $perMinute * 60 : 0;
		$perDay = (int)($defaults['daily_limit'] ?? 0);

		return [
			'per_minute' => $perMinute,
			'per_hour' => $perHour,
			'per_day' => $perDay,
		];
	}

	public function updateEmailAccount(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$account = $this->emailAccounts->getAccount($id);
		if ($account === null) {
			return abort(404, 'Conta não encontrada.');
		}

		$payload = $this->extractEmailAccountPayload($request, $account);
		if ($payload['errors'] !== []) {
			$this->flashFormState('marketing_email_account_form', [
				'mode' => 'edit',
				'id' => $id,
				'errors' => $payload['errors'],
				'data' => $payload['old'],
			]);

			return new RedirectResponse(url('marketing/email-accounts/' . $id . '/edit'));
		}

		$this->emailAccounts->updateAccount($id, $payload['data'], $this->resolveActorId($request));
		$this->flashFeedback('marketing_email_accounts_feedback', 'success', 'Conta atualizada com sucesso.');

		return new RedirectResponse(url('marketing/email-accounts'));
	}

	public function archiveEmailAccount(Request $request, array $vars): Response
	{
		$id = (int)($vars['id'] ?? 0);
		$account = $this->emailAccounts->getAccount($id);
		if ($account === null) {
			return abort(404, 'Conta não encontrada.');
		}

		$this->emailAccounts->archiveAccount($id, $this->resolveActorId($request));
		$this->flashFeedback('marketing_email_accounts_feedback', 'success', 'Conta arquivada.');

		return new RedirectResponse(url('marketing/email-accounts'));
	}

	private function extractListPayload(Request $request, ?array $current): array
	{
		$name = trim((string)$request->request->get('name', $current['name'] ?? ''));
		$slugInput = trim((string)$request->request->get('slug', $current['slug'] ?? ''));
		$slug = $slugInput !== '' ? slugify($slugInput) : slugify($name);
		$description = trim((string)$request->request->get('description', $current['description'] ?? ''));
		$origin = trim((string)$request->request->get('origin', $current['origin'] ?? ''));
		$purpose = trim((string)$request->request->get('purpose', $current['purpose'] ?? ''));
		$consentMode = (string)$request->request->get('consent_mode', $current['consent_mode'] ?? 'single_opt_in');
		$doubleOptIn = $this->asBool($request->request->get('double_opt_in', $current['double_opt_in'] ?? '0'));
		$optInStatement = trim((string)$request->request->get('opt_in_statement', $current['opt_in_statement'] ?? ''));
		$retentionPolicy = trim((string)$request->request->get('retention_policy', $current['retention_policy'] ?? ''));
		$status = (string)$request->request->get('status', $current['status'] ?? 'active');
		$metadataInput = trim((string)$request->request->get('metadata', $current['metadata'] ?? ''));
		$currentPerDoc = $this->resolveListPerDocument($current ?? []);
		$perDocument = $this->asBool($request->request->get('per_document', $currentPerDoc ? '1' : '0'));

		$metadataArray = $this->decodeMetadata($metadataInput !== '' ? $metadataInput : ($current['metadata'] ?? null));
		if (!is_array($metadataArray)) {
			$metadataArray = [];
		}
		$metadataArray['per_document'] = $perDocument;
		$metadata = $metadataArray !== [] ? json_encode($metadataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'Informe um nome para identificar a lista.';
		}

		if ($slug === '') {
			$errors['slug'] = 'Gere um identificador amigável (slug).';
		} else {
			$existing = $this->lists->findBySlug($slug);
			if ($existing !== null && ($current === null || (int)$existing['id'] !== (int)$current['id'])) {
				$errors['slug'] = 'Já existe uma lista com este slug.';
			}
		}

		$allowedConsent = ['single_opt_in', 'double_opt_in', 'custom_policy'];
		if (!in_array($consentMode, $allowedConsent, true)) {
			$consentMode = 'single_opt_in';
		}

		$allowedStatus = ['active', 'paused', 'archived'];
		if (!in_array($status, $allowedStatus, true)) {
			$status = 'active';
		}

		$data = [
			'name' => $name,
			'slug' => $slug,
			'description' => $description !== '' ? $description : null,
			'origin' => $origin !== '' ? $origin : null,
			'purpose' => $purpose !== '' ? $purpose : null,
			'consent_mode' => $consentMode,
			'double_opt_in' => $doubleOptIn ? 1 : 0,
			'opt_in_statement' => $optInStatement !== '' ? $optInStatement : null,
			'retention_policy' => $retentionPolicy !== '' ? $retentionPolicy : null,
			'status' => $status,
			'metadata' => $metadata !== '' ? $metadata : null,
			'archived_at' => $status === 'archived' ? ($current['archived_at'] ?? now()) : null,
		];

		return [
			'errors' => $errors,
			'data' => $data,
			'old' => [
				'name' => $name,
				'slug' => $slug,
				'description' => $description,
				'origin' => $origin,
				'purpose' => $purpose,
				'consent_mode' => $consentMode,
				'double_opt_in' => $doubleOptIn ? '1' : '0',
				'opt_in_statement' => $optInStatement,
				'retention_policy' => $retentionPolicy,
				'status' => $status,
				'metadata' => $metadata,
				'per_document' => $perDocument ? '1' : '0',
			],
		];
	}

	private function extractSegmentPayload(Request $request, ?array $current): array
	{
		$name = trim((string)$request->request->get('name', $current['name'] ?? ''));
		$slugInput = trim((string)$request->request->get('slug', $current['slug'] ?? ''));
		$slug = $slugInput !== '' ? slugify($slugInput) : slugify($name);
		$listIdInput = (string)$request->request->get('list_id', $current['list_id'] ?? '');
		$description = trim((string)$request->request->get('description', $current['description'] ?? ''));
		$definition = trim((string)$request->request->get('definition', $current['definition'] ?? ''));
		$refreshMode = (string)$request->request->get('refresh_mode', $current['refresh_mode'] ?? 'dynamic');
		$status = (string)$request->request->get('status', $current['status'] ?? 'draft');
		$refreshIntervalInput = (string)$request->request->get('refresh_interval', (string)($current['refresh_interval'] ?? ''));
		$metadata = trim((string)$request->request->get('metadata', $current['metadata'] ?? ''));

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'Nome do segmento é obrigatório.';
		}

		if ($slug === '') {
			$errors['slug'] = 'Crie um slug válido para o segmento.';
		} else {
			$existing = $this->segments->findBySlug($slug);
			if ($existing !== null && ($current === null || (int)$existing['id'] !== (int)$current['id'])) {
				$errors['slug'] = 'Já existe um segmento com este slug.';
			}
		}

		$listId = null;
		if ($listIdInput !== '') {
			$candidate = (int)$listIdInput;
			if ($candidate > 0 && $this->lists->find($candidate) === null) {
				$errors['list_id'] = 'Selecione uma lista válida.';
			} else {
				$listId = $candidate > 0 ? $candidate : null;
			}
		}

		$allowedRefresh = ['dynamic', 'manual'];
		if (!in_array($refreshMode, $allowedRefresh, true)) {
			$refreshMode = 'dynamic';
		}

		$allowedStatus = ['draft', 'active', 'paused'];
		if (!in_array($status, $allowedStatus, true)) {
			$status = 'draft';
		}

		$refreshInterval = null;
		if ($refreshIntervalInput !== '') {
			$refreshInterval = max(5, (int)$refreshIntervalInput);
		}

		$data = [
			'name' => $name,
			'slug' => $slug,
			'list_id' => $listId,
			'description' => $description !== '' ? $description : null,
			'definition' => $definition !== '' ? $definition : null,
			'refresh_mode' => $refreshMode,
			'status' => $status,
			'refresh_interval' => $refreshInterval,
			'metadata' => $metadata !== '' ? $metadata : null,
		];

		return [
			'errors' => $errors,
			'data' => $data,
			'old' => [
				'name' => $name,
				'slug' => $slug,
				'list_id' => $listIdInput,
				'description' => $description,
				'definition' => $definition,
				'refresh_mode' => $refreshMode,
				'status' => $status,
				'refresh_interval' => $refreshIntervalInput,
				'metadata' => $metadata,
			],
		];
	}

	private function resolveFormState(string $key, string $mode, array $defaults, ?int $id = null): array
	{
		$state = $_SESSION[$key] ?? null;
		if ($state === null || ($state['mode'] ?? '') !== $mode) {
			return [$defaults, []];
		}

		if ($mode === 'edit' && (int)($state['id'] ?? 0) !== (int)$id) {
			return [$defaults, []];
		}

		unset($_SESSION[$key]);

		$values = array_merge($defaults, $state['data'] ?? []);
		$errors = $state['errors'] ?? [];

		return [$values, $errors];
	}

	private function flashFormState(string $key, array $payload): void
	{
		$_SESSION[$key] = $payload;
	}

	private function flashFeedback(string $key, string $type, string $message): void
	{
		$_SESSION[$key] = [
			'type' => $type,
			'message' => $message,
		];
	}

	private function pullFeedback(string $key): ?array
	{
		$value = $_SESSION[$key] ?? null;
		unset($_SESSION[$key]);
		return $value;
	}

	private function listFormDefaults(?array $current = null): array
	{
		$perDocument = $this->resolveListPerDocument($current ?? []);

		return [
			'name' => $current['name'] ?? '',
			'slug' => $current['slug'] ?? '',
			'description' => $current['description'] ?? '',
			'origin' => $current['origin'] ?? '',
			'purpose' => $current['purpose'] ?? '',
			'consent_mode' => $current['consent_mode'] ?? 'single_opt_in',
			'double_opt_in' => isset($current['double_opt_in']) && (int)$current['double_opt_in'] === 1 ? '1' : '0',
			'opt_in_statement' => $current['opt_in_statement'] ?? '',
			'retention_policy' => $current['retention_policy'] ?? '',
			'status' => $current['status'] ?? 'active',
			'metadata' => $current['metadata'] ?? '',
			'per_document' => $perDocument ? '1' : '0',
		];
	}

	private function segmentFormDefaults(?array $current = null): array
	{
		return [
			'name' => $current['name'] ?? '',
			'slug' => $current['slug'] ?? '',
			'list_id' => isset($current['list_id']) ? (string)$current['list_id'] : '',
			'description' => $current['description'] ?? '',
			'definition' => $current['definition'] ?? '',
			'refresh_mode' => $current['refresh_mode'] ?? 'dynamic',
			'status' => $current['status'] ?? 'draft',
			'refresh_interval' => isset($current['refresh_interval']) ? (string)$current['refresh_interval'] : '',
			'metadata' => $current['metadata'] ?? '',
		];
	}

	private function importFormDefaults(): array
	{
		return [
			'source_label' => '',
			'respect_opt_out' => '1',
		];
	}

	private function importLimits(): array
	{
		$config = (array)config('marketing.imports', []);
		$maxRows = max(1, (int)($config['max_rows'] ?? 5000));
		$maxFileSize = max(1, (int)($config['max_file_size_mb'] ?? 5));

		return [
			'max_rows' => $maxRows,
			'max_file_size_mb' => $maxFileSize,
		];
	}

	private function emailAccountFormDefaults(?array $current = null): array
	{
		$credentials = is_array($current['credentials'] ?? null) ? $current['credentials'] : [];
		$policies = is_array($current['policies'] ?? null) ? $current['policies'] : [];
		$providerValue = $current['provider'] ?? 'gmail';
		$limitDefaults = EmailProviderLimitDefaults::for($providerValue);
		$customMinuteDefault = 2000 / 60;
		$defaultMinute = ($limitDefaults['hourly_limit'] ?? null) !== null
			? (int)$limitDefaults['hourly_limit']
			: ($providerValue === 'custom' ? (int)ceil($customMinuteDefault) : 4);
		$defaultDaily = ($limitDefaults['daily_limit'] ?? null) !== null
			? (int)$limitDefaults['daily_limit']
			: ($providerValue === 'custom' ? 48000 : 240);
		$defaultBurst = ($limitDefaults['burst_limit'] ?? null) !== null
			? (int)$limitDefaults['burst_limit']
			: ($providerValue === 'custom' ? 60 : 20);

		$tooling = $this->policyArray($policies, 'tooling', 'vscode_stack', [
			'vs_mail' => false,
			'postie' => false,
			'email_editing_tools' => false,
			'emaildev_utilities' => false,
			'live_server' => false,
			'template_manager' => false,
			'notes' => '',
		]);

		$templateEngine = $this->policyScalar($policies, 'templates', 'engine', 'mjml');
		$templatePreview = $this->policyArray($policies, 'templates', 'preview', [
			'preview_url' => '',
			'template_manager' => '',
			'notes' => '',
		]);

		$complianceFooter = $this->policyArray($policies, 'compliance', 'footer', [
			'unsubscribe_url' => '',
			'privacy_url' => '',
			'company_address' => '',
			'list_unsubscribe_header' => '',
		]);
		$dnsStatus = $this->policyArray($policies, 'compliance', 'dns', [
			'spf' => 'pending',
			'dkim' => 'pending',
			'dmarc' => 'pending',
		]);
		$doubleOptIn = $this->policyBool($policies, 'automation', 'double_opt_in', true);

		$automationSending = $this->policyArray($policies, 'automation', 'sending', [
			'bounce_policy' => 'smart',
			'test_recipient' => '',
			'log_retention_days' => 90,
			'list_cleaning' => 'weekly',
			'ab_testing' => true,
			'reputation_monitoring' => true,
		]);
		$warmupPlan = $this->policyArray($policies, 'automation', 'warmup', [
			'plan' => '',
			'target_volume' => 1000,
		]);

		$apiIntegration = $this->policyArray($policies, 'integration', 'api', [
			'provider' => 'none',
			'region' => '',
			'key_id' => '',
			'status' => 'inactive',
		]);

		$appUrl = rtrim((string)config('app.url', 'http://localhost:8080'), '/');
		$domainHost = parse_url($appUrl, PHP_URL_HOST) ?: 'suaempresa.com.br';
		$defaultFooter = [
			'unsubscribe_url' => $appUrl . '/descadastro',
			'privacy_url' => $appUrl . '/privacidade',
			'company_address' => 'Rua do Marketing, 123 - São Paulo/SP',
			'list_unsubscribe_header' => sprintf('<mailto:descadastro@%s>, <%s/descadastro>', $domainHost, $appUrl),
		];
		$providedFooter = array_filter($complianceFooter, static fn($value): bool => $value !== null && $value !== '');
		$complianceFooter = array_merge($defaultFooter, $providedFooter);

		return [
			'name' => $current['name'] ?? '',
			'provider' => $providerValue,
			'domain' => $current['domain'] ?? '',
			'from_name' => $current['from_name'] ?? '',
			'from_email' => $current['from_email'] ?? '',
			'reply_to' => $current['reply_to'] ?? '',
			'smtp_host' => $current['smtp_host'] ?? '',
			'smtp_port' => isset($current['smtp_port']) ? (string)$current['smtp_port'] : '587',
			'encryption' => $current['encryption'] ?? 'tls',
			'auth_mode' => $current['auth_mode'] ?? 'login',
			'username' => (string)($credentials['username'] ?? ''),
			'password' => '',
			'oauth_token' => '',
			'api_key' => '',
			'has_password' => !empty($credentials['has_password']),
			'has_oauth_token' => !empty($credentials['has_oauth_token']),
			'has_api_key' => !empty($credentials['has_api_key']),
			'headers' => $this->formatJsonField($current['headers'] ?? null),
			'settings' => $this->formatJsonField($current['settings'] ?? null),
			'hourly_limit' => isset($current['hourly_limit'])
				? (string)$current['hourly_limit']
				: (string)$defaultMinute,
			'daily_limit' => isset($current['daily_limit'])
				? (string)$current['daily_limit']
				: (string)$defaultDaily,
			'burst_limit' => isset($current['burst_limit'])
				? (string)$current['burst_limit']
				: (string)$defaultBurst,
			'warmup_status' => $current['warmup_status'] ?? 'ready',
			'status' => $current['status'] ?? 'active',
			'notes' => $current['notes'] ?? '',
			'limit_source' => $current === null ? 'auto' : 'manual',
			'tooling_vs_mail' => $tooling['vs_mail'] ? '1' : '0',
			'tooling_postie' => $tooling['postie'] ? '1' : '0',
			'tooling_email_editing_tools' => $tooling['email_editing_tools'] ? '1' : '0',
			'tooling_emaildev_utilities' => $tooling['emaildev_utilities'] ? '1' : '0',
			'tooling_live_server' => $tooling['live_server'] ? '1' : '0',
			'tooling_template_manager' => $tooling['template_manager'] ? '1' : '0',
			'tooling_notes' => $tooling['notes'] ?? '',
			'template_engine' => $templateEngine,
			'template_manager_folder' => $templatePreview['template_manager'] ?? '',
			'template_preview_url' => $templatePreview['preview_url'] ?? '',
			'template_notes' => $templatePreview['notes'] ?? '',
			'footer_unsubscribe_url' => $complianceFooter['unsubscribe_url'] ?? '',
			'footer_privacy_url' => $complianceFooter['privacy_url'] ?? '',
			'footer_company_address' => $complianceFooter['company_address'] ?? '',
			'list_unsubscribe_header' => $complianceFooter['list_unsubscribe_header'] ?? '',
			'dns_spf_status' => $dnsStatus['spf'] ?? 'pending',
			'dns_dkim_status' => $dnsStatus['dkim'] ?? 'pending',
			'dns_dmarc_status' => $dnsStatus['dmarc'] ?? 'pending',
			'double_opt_in_enabled' => $doubleOptIn ? '1' : '0',
			'bounce_policy' => $automationSending['bounce_policy'] ?? 'smart',
			'test_recipient' => $automationSending['test_recipient'] ?? '',
			'log_retention_days' => isset($automationSending['log_retention_days']) ? (string)$automationSending['log_retention_days'] : '90',
			'list_cleaning_frequency' => $automationSending['list_cleaning'] ?? 'weekly',
			'ab_testing_enabled' => !empty($automationSending['ab_testing']) ? '1' : '0',
			'reputation_monitoring_enabled' => !empty($automationSending['reputation_monitoring']) ? '1' : '0',
			'warmup_plan' => $warmupPlan['plan'] ?? '',
			'warmup_target_volume' => isset($warmupPlan['target_volume']) ? (string)$warmupPlan['target_volume'] : '1000',
			'api_provider' => $apiIntegration['provider'] ?? 'none',
			'api_region' => $apiIntegration['region'] ?? '',
			'api_key_id' => $apiIntegration['key_id'] ?? '',
			'api_status' => $apiIntegration['status'] ?? 'inactive',
		];
	}

	private function extractEmailAccountPayload(Request $request, ?array $current): array
	{
		$currentCredentials = is_array($current['credentials'] ?? null) ? $current['credentials'] : [];

		$name = trim((string)$request->request->get('name', $current['name'] ?? ''));
		$provider = (string)$request->request->get('provider', $current['provider'] ?? 'custom');
		$domain = trim((string)$request->request->get('domain', $current['domain'] ?? ''));
		$fromName = trim((string)$request->request->get('from_name', $current['from_name'] ?? ''));
		$fromEmail = trim((string)$request->request->get('from_email', $current['from_email'] ?? ''));
		$replyTo = trim((string)$request->request->get('reply_to', $current['reply_to'] ?? ''));
		$smtpHost = trim((string)$request->request->get('smtp_host', $current['smtp_host'] ?? ''));
		$smtpPortInput = (string)$request->request->get('smtp_port', (string)($current['smtp_port'] ?? '587'));
		$encryption = (string)$request->request->get('encryption', $current['encryption'] ?? 'tls');
		$authMode = (string)$request->request->get('auth_mode', $current['auth_mode'] ?? 'login');
		$username = trim((string)$request->request->get('username', $currentCredentials['username'] ?? ''));
		$password = (string)$request->request->get('password', '');
		$oauthToken = (string)$request->request->get('oauth_token', '');
		$apiKey = (string)$request->request->get('api_key', '');
		$headersInput = trim((string)$request->request->get('headers', ''));
		$settingsInput = trim((string)$request->request->get('settings', ''));
		$hourlyLimitInput = (string)$request->request->get('hourly_limit', (string)($current['hourly_limit'] ?? '0'));
		$dailyLimitInput = (string)$request->request->get('daily_limit', (string)($current['daily_limit'] ?? '0'));
		$burstLimitInput = (string)$request->request->get('burst_limit', (string)($current['burst_limit'] ?? '0'));
		$limitSourceInput = strtolower((string)$request->request->get('limit_source', $current === null ? 'auto' : 'manual'));
		$warmupStatus = (string)$request->request->get('warmup_status', $current['warmup_status'] ?? 'ready');
		$status = (string)$request->request->get('status', $current['status'] ?? 'active');
		$notes = trim((string)$request->request->get('notes', $current['notes'] ?? ''));

		$templateEngine = (string)$request->request->get('template_engine', 'mjml');
		$templateManagerFolder = trim((string)$request->request->get('template_manager_folder', ''));
		$templatePreviewUrl = trim((string)$request->request->get('template_preview_url', ''));
		$templateNotes = trim((string)$request->request->get('template_notes', ''));

		$toolingFlags = [
			'vs_mail' => $this->asBool($request->request->get('tooling_vs_mail', '0')),
			'postie' => $this->asBool($request->request->get('tooling_postie', '0')),
			'email_editing_tools' => $this->asBool($request->request->get('tooling_email_editing_tools', '0')),
			'emaildev_utilities' => $this->asBool($request->request->get('tooling_emaildev_utilities', '0')),
			'live_server' => $this->asBool($request->request->get('tooling_live_server', '0')),
			'template_manager' => $this->asBool($request->request->get('tooling_template_manager', '0')),
		];
		$toolingNotes = trim((string)$request->request->get('tooling_notes', ''));

		$footerUnsubscribeUrl = trim((string)$request->request->get('footer_unsubscribe_url', ''));
		$footerPrivacyUrl = trim((string)$request->request->get('footer_privacy_url', ''));
		$footerCompanyAddress = trim((string)$request->request->get('footer_company_address', ''));
		$listUnsubscribeHeader = trim((string)$request->request->get('list_unsubscribe_header', ''));

		$dnsSpfStatus = (string)$request->request->get('dns_spf_status', 'pending');
		$dnsDkimStatus = (string)$request->request->get('dns_dkim_status', 'pending');
		$dnsDmarcStatus = (string)$request->request->get('dns_dmarc_status', 'pending');
		$doubleOptInEnabled = $this->asBool($request->request->get('double_opt_in_enabled', '1'));

		$bouncePolicy = (string)$request->request->get('bounce_policy', 'smart');
		$testRecipient = trim((string)$request->request->get('test_recipient', ''));
		$logRetentionDaysInput = (string)$request->request->get('log_retention_days', '90');
		$logRetentionDays = (int)$logRetentionDaysInput;
		$listCleaningFrequency = (string)$request->request->get('list_cleaning_frequency', 'weekly');
		$abTestingEnabled = $this->asBool($request->request->get('ab_testing_enabled', '1'));
		$reputationMonitoringEnabled = $this->asBool($request->request->get('reputation_monitoring_enabled', '1'));

		$warmupPlan = trim((string)$request->request->get('warmup_plan', ''));
		$warmupTargetVolumeInput = (string)$request->request->get('warmup_target_volume', '1000');
		$warmupTargetVolume = (int)$warmupTargetVolumeInput;

		$apiProvider = (string)$request->request->get('api_provider', 'none');
		$apiRegion = trim((string)$request->request->get('api_region', ''));
		$apiKeyId = trim((string)$request->request->get('api_key_id', ''));
		$apiStatus = trim((string)$request->request->get('api_status', 'inactive'));

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'Nome é obrigatório.';
		}

		if (!array_key_exists($provider, $this->emailProviders)) {
			$errors['provider'] = 'Selecione um provedor válido.';
		}

		if ($fromName === '') {
			$errors['from_name'] = 'Informe o nome do remetente.';
		}

		if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
			$errors['from_email'] = 'E-mail de remetente inválido.';
		}

		if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
			$errors['reply_to'] = 'E-mail de resposta inválido.';
		}

		if ($smtpHost === '') {
			$errors['smtp_host'] = 'Host SMTP é obrigatório.';
		}

		$smtpPort = (int)$smtpPortInput;
		if ($smtpPort <= 0) {
			$errors['smtp_port'] = 'Porta SMTP inválida.';
		}

		if (!in_array($encryption, $this->emailEncryptionOptions, true)) {
			$errors['encryption'] = 'Tipo de criptografia inválido.';
		}

		if (!in_array($authMode, $this->emailAuthModes, true)) {
			$errors['auth_mode'] = 'Modo de autenticação inválido.';
		}

		if (!in_array($status, $this->emailStatuses, true)) {
			$status = 'active';
		}

		if (!in_array($warmupStatus, $this->emailWarmupStatuses, true)) {
			$warmupStatus = 'ready';
		}

		if (!array_key_exists($templateEngine, $this->templateEngines)) {
			$errors['template_engine'] = 'Selecione um editor suportado.';
		}

		if ($templatePreviewUrl !== '' && filter_var($templatePreviewUrl, FILTER_VALIDATE_URL) === false) {
			$errors['template_preview_url'] = 'Informe uma URL válida para pré-visualização.';
		}

		if ($footerUnsubscribeUrl === '' || filter_var($footerUnsubscribeUrl, FILTER_VALIDATE_URL) === false) {
			$errors['footer_unsubscribe_url'] = 'Link de descadastro é obrigatório e deve ser válido.';
		}

		if ($footerPrivacyUrl === '' || filter_var($footerPrivacyUrl, FILTER_VALIDATE_URL) === false) {
			$errors['footer_privacy_url'] = 'Informe uma URL válida para a política de privacidade.';
		}

		if ($footerCompanyAddress === '') {
			$errors['footer_company_address'] = 'Endereço físico é obrigatório.';
		}

		if ($listUnsubscribeHeader === '') {
			$errors['list_unsubscribe_header'] = 'Inclua o cabeçalho List-Unsubscribe.';
		}

		if (!array_key_exists($dnsSpfStatus, $this->dnsStatusOptions)) {
			$errors['dns_spf_status'] = 'Status SPF inválido.';
		}

		if (!array_key_exists($dnsDkimStatus, $this->dnsStatusOptions)) {
			$errors['dns_dkim_status'] = 'Status DKIM inválido.';
		}

		if (!array_key_exists($dnsDmarcStatus, $this->dnsStatusOptions)) {
			$errors['dns_dmarc_status'] = 'Status DMARC inválido.';
		}

		if (!array_key_exists($bouncePolicy, $this->bouncePolicies)) {
			$errors['bounce_policy'] = 'Selecione uma política de bounce válida.';
		}

		if ($testRecipient !== '' && filter_var($testRecipient, FILTER_VALIDATE_EMAIL) === false) {
			$errors['test_recipient'] = 'Informe um e-mail de teste válido.';
		}

		if ($logRetentionDays <= 0) {
			$errors['log_retention_days'] = 'Defina dias de retenção maiores que zero.';
		}

		if (!array_key_exists($listCleaningFrequency, $this->listCleaningFrequencies)) {
			$errors['list_cleaning_frequency'] = 'Frequência de limpeza inválida.';
		}

		if ($warmupTargetVolume <= 0) {
			$errors['warmup_target_volume'] = 'Informe um volume alvo positivo.';
		}

		if (!array_key_exists($apiProvider, $this->apiProviders)) {
			$errors['api_provider'] = 'Selecione um provedor de API suportado.';
		}

		if ($apiProvider !== 'none' && $apiKeyId === '') {
			$errors['api_key_id'] = 'Para integrações API informe o identificador da chave.';
		}

		$headersPayload = $this->interpretKeyValueInput($headersInput, 'headers', $errors);
		$settingsPayload = $this->interpretKeyValueInput($settingsInput, 'settings', $errors);

		$hourlyLimit = max(1, (int)$hourlyLimitInput);
		$dailyLimit = max(1, (int)$dailyLimitInput);
		$burstLimit = max(1, (int)$burstLimitInput);
		$warmupTargetVolume = max(0, $warmupTargetVolume);

		$allowedLimitSources = ['auto', 'manual', 'preset'];
		if (!in_array($limitSourceInput, $allowedLimitSources, true)) {
			$limitSourceInput = $current === null ? 'auto' : 'manual';
		}

		$passwordExisting = trim((string)($currentCredentials['password'] ?? ''));
		$oauthExisting = trim((string)($currentCredentials['oauth_token'] ?? ''));
		$apiKeyExisting = trim((string)($currentCredentials['api_key'] ?? ''));

		if ($authMode === 'login') {
			if ($username === '') {
				$errors['username'] = 'Usuário SMTP é obrigatório.';
			}

			if (trim($password) === '' && $passwordExisting === '') {
				$errors['password'] = 'Informe a senha da conta de e-mail.';
			}
		}

		if ($authMode === 'oauth2' && trim($oauthToken) === '' && $oauthExisting === '') {
			$errors['oauth_token'] = 'Token OAuth2 é obrigatório.';
		}

		if ($authMode === 'apikey' && trim($apiKey) === '' && $apiKeyExisting === '') {
			$errors['api_key'] = 'API key é obrigatória.';
		}

		$policyInput = [
			'tooling' => array_merge($toolingFlags, ['notes' => $toolingNotes]),
			'templates' => [
				'engine' => $templateEngine,
				'preview' => [
					'preview_url' => $templatePreviewUrl,
					'template_manager' => $templateManagerFolder,
					'notes' => $templateNotes,
				],
			],
			'compliance' => [
				'footer' => [
					'unsubscribe_url' => $footerUnsubscribeUrl,
					'privacy_url' => $footerPrivacyUrl,
					'company_address' => $footerCompanyAddress,
					'list_unsubscribe_header' => $listUnsubscribeHeader,
				],
				'dns' => [
					'spf' => $dnsSpfStatus,
					'dkim' => $dnsDkimStatus,
					'dmarc' => $dnsDmarcStatus,
				],
			],
			'automation' => [
				'double_opt_in' => $doubleOptInEnabled,
				'sending' => [
					'bounce_policy' => $bouncePolicy,
					'test_recipient' => $testRecipient,
					'log_retention_days' => $logRetentionDays,
					'list_cleaning' => $listCleaningFrequency,
					'ab_testing' => $abTestingEnabled,
					'reputation_monitoring' => $reputationMonitoringEnabled,
				],
				'warmup' => [
					'plan' => $warmupPlan,
					'target_volume' => $warmupTargetVolume,
				],
			],
			'integration' => [
				'api' => [
					'provider' => $apiProvider,
					'region' => $apiRegion,
					'key_id' => $apiKeyId,
					'status' => $apiStatus !== '' ? $apiStatus : 'inactive',
				],
			],
		];

		$data = [
			'name' => $name,
			'provider' => $provider,
			'domain' => $domain !== '' ? $domain : null,
			'from_name' => $fromName,
			'from_email' => $fromEmail,
			'reply_to' => $replyTo !== '' ? $replyTo : null,
			'smtp_host' => $smtpHost,
			'smtp_port' => $smtpPort,
			'encryption' => $encryption,
			'auth_mode' => $authMode,
			'username' => $username !== '' ? $username : null,
			'password' => trim($password) !== '' ? $password : null,
			'oauth_token' => trim($oauthToken) !== '' ? $oauthToken : null,
			'api_key' => trim($apiKey) !== '' ? $apiKey : null,
			'headers' => $headersPayload,
			'settings' => $settingsPayload,
			'hourly_limit' => $hourlyLimit,
			'daily_limit' => $dailyLimit,
			'burst_limit' => $burstLimit,
			'limit_source' => $limitSourceInput,
			'warmup_status' => $warmupStatus,
			'status' => $status,
			'notes' => $notes !== '' ? $notes : null,
			'policies' => $this->buildEmailPolicyPayload($policyInput),
		];

		return [
			'errors' => $errors,
			'data' => $data,
			'old' => [
				'name' => $name,
				'provider' => $provider,
				'domain' => $domain,
				'from_name' => $fromName,
				'from_email' => $fromEmail,
				'reply_to' => $replyTo,
				'smtp_host' => $smtpHost,
				'smtp_port' => (string)$smtpPort,
				'encryption' => $encryption,
				'auth_mode' => $authMode,
				'username' => $username,
				'password' => '',
				'oauth_token' => '',
				'api_key' => '',
				'headers' => $headersInput,
				'settings' => $settingsInput,
				'hourly_limit' => (string)$hourlyLimit,
				'daily_limit' => (string)$dailyLimit,
				'burst_limit' => (string)$burstLimit,
				'limit_source' => $limitSourceInput,
				'warmup_status' => $warmupStatus,
				'status' => $status,
				'notes' => $notes,
				'template_engine' => $templateEngine,
				'template_manager_folder' => $templateManagerFolder,
				'template_preview_url' => $templatePreviewUrl,
				'template_notes' => $templateNotes,
				'tooling_vs_mail' => $toolingFlags['vs_mail'] ? '1' : '0',
				'tooling_postie' => $toolingFlags['postie'] ? '1' : '0',
				'tooling_email_editing_tools' => $toolingFlags['email_editing_tools'] ? '1' : '0',
				'tooling_emaildev_utilities' => $toolingFlags['emaildev_utilities'] ? '1' : '0',
				'tooling_live_server' => $toolingFlags['live_server'] ? '1' : '0',
				'tooling_template_manager' => $toolingFlags['template_manager'] ? '1' : '0',
				'tooling_notes' => $toolingNotes,
				'footer_unsubscribe_url' => $footerUnsubscribeUrl,
				'footer_privacy_url' => $footerPrivacyUrl,
				'footer_company_address' => $footerCompanyAddress,
				'list_unsubscribe_header' => $listUnsubscribeHeader,
				'dns_spf_status' => $dnsSpfStatus,
				'dns_dkim_status' => $dnsDkimStatus,
				'dns_dmarc_status' => $dnsDmarcStatus,
				'double_opt_in_enabled' => $doubleOptInEnabled ? '1' : '0',
				'bounce_policy' => $bouncePolicy,
				'test_recipient' => $testRecipient,
				'log_retention_days' => (string)$logRetentionDays,
				'list_cleaning_frequency' => $listCleaningFrequency,
				'ab_testing_enabled' => $abTestingEnabled ? '1' : '0',
				'reputation_monitoring_enabled' => $reputationMonitoringEnabled ? '1' : '0',
				'warmup_plan' => $warmupPlan,
				'warmup_target_volume' => (string)$warmupTargetVolume,
				'api_provider' => $apiProvider,
				'api_region' => $apiRegion,
				'api_key_id' => $apiKeyId,
				'api_status' => $apiStatus,
			],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $policies
	 */
	private function policyValue(array $policies, string $type, string $key, mixed $default = null): mixed
	{
		foreach ($policies as $policy) {
			if ((string)($policy['policy_type'] ?? '') !== $type) {
				continue;
			}
			if ((string)($policy['policy_key'] ?? '') !== $key) {
				continue;
			}

			$value = $policy['policy_value'] ?? null;
			return $this->decodePolicyValue($value, $default);
		}

		return $default;
	}

	/**
	 * @param array<int, array<string, mixed>> $policies
	 */
	private function policyScalar(array $policies, string $type, string $key, mixed $default = null): mixed
	{
		$value = $this->policyValue($policies, $type, $key, $default);
		if (is_array($value)) {
			return $default;
		}

		return $value ?? $default;
	}

	/**
	 * @param array<int, array<string, mixed>> $policies
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	private function policyArray(array $policies, string $type, string $key, array $defaults = []): array
	{
		$value = $this->policyValue($policies, $type, $key, $defaults);
		if (!is_array($value)) {
			return $defaults;
		}

		return array_merge($defaults, $value);
	}

	/**
	 * @param array<int, array<string, mixed>> $policies
	 */
	private function policyBool(array $policies, string $type, string $key, bool $default = false): bool
	{
		$value = $this->policyValue($policies, $type, $key, $default);
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'enabled'], true);
		}

		if (is_numeric($value)) {
			return (int)$value === 1;
		}

		return (bool)$value;
	}

	private function decodePolicyValue(mixed $value, mixed $fallback): mixed
	{
		if (is_array($value)) {
			return $value;
		}

		if (is_string($value)) {
			$decoded = json_decode($value, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			}

			return $value;
		}

		return $value ?? $fallback;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<int, array<string, mixed>>
	 */
	private function buildEmailPolicyPayload(array $input): array
	{
		$policies = [];

		if (isset($input['tooling']) && is_array($input['tooling'])) {
			$policies[] = [
				'policy_type' => 'tooling',
				'policy_key' => 'vscode_stack',
				'policy_value' => $input['tooling'],
			];
		}

		if (isset($input['templates']) && is_array($input['templates'])) {
			$templates = $input['templates'];
			if (isset($templates['engine'])) {
				$policies[] = [
					'policy_type' => 'templates',
					'policy_key' => 'engine',
					'policy_value' => (string)$templates['engine'],
				];
			}
			if (isset($templates['preview']) && is_array($templates['preview']) && $this->hasStructuredData($templates['preview'])) {
				$policies[] = [
					'policy_type' => 'templates',
					'policy_key' => 'preview',
					'policy_value' => $templates['preview'],
				];
			}
		}

		if (isset($input['compliance']) && is_array($input['compliance'])) {
			$compliance = $input['compliance'];
			if (isset($compliance['footer'])) {
				$policies[] = [
					'policy_type' => 'compliance',
					'policy_key' => 'footer',
					'policy_value' => $compliance['footer'],
				];
			}
			if (isset($compliance['dns'])) {
				$policies[] = [
					'policy_type' => 'compliance',
					'policy_key' => 'dns',
					'policy_value' => $compliance['dns'],
				];
			}
		}

		if (isset($input['automation']) && is_array($input['automation'])) {
			$automation = $input['automation'];
			if (array_key_exists('double_opt_in', $automation)) {
				$policies[] = [
					'policy_type' => 'automation',
					'policy_key' => 'double_opt_in',
					'policy_value' => $automation['double_opt_in'] ? '1' : '0',
				];
			}
			if (isset($automation['sending'])) {
				$policies[] = [
					'policy_type' => 'automation',
					'policy_key' => 'sending',
					'policy_value' => $automation['sending'],
				];
			}
			if (isset($automation['warmup']) && $this->hasStructuredData((array)$automation['warmup'])) {
				$policies[] = [
					'policy_type' => 'automation',
					'policy_key' => 'warmup',
					'policy_value' => $automation['warmup'],
				];
			}
		}

		if (isset($input['integration']) && is_array($input['integration']) && isset($input['integration']['api'])) {
			$policies[] = [
				'policy_type' => 'integration',
				'policy_key' => 'api',
				'policy_value' => $input['integration']['api'],
			];
		}

		return array_values(array_filter($policies, static fn(array $policy): bool => array_key_exists('policy_value', $policy)));
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function hasStructuredData(array $payload): bool
	{
		foreach ($payload as $value) {
			if (is_bool($value)) {
				if ($value === true) {
					return true;
				}
				continue;
			}

			if (is_string($value)) {
				if (trim($value) !== '') {
					return true;
				}
				continue;
			}

			if ($value !== null && $value !== '') {
				return true;
			}
		}

		return false;
	}

	private function interpretKeyValueInput(string $raw, string $field, array &$errors): array|string|null
	{
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if ($decoded !== null && json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			return $decoded;
		}

		$lines = preg_split('/\r?\n/', $raw) ?: [];
		$result = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$parts = explode(':', $line, 2);
			$key = trim($parts[0] ?? '');
			$value = trim($parts[1] ?? '');
			if ($key === '') {
				continue;
			}
			$result[$key] = $value;
		}

		if ($result !== []) {
			return $result;
		}

		$errors[$field] = 'Use JSON válido ou linhas no formato "Chave: Valor".';
		return null;
	}

	private function formatJsonField(mixed $value): string
	{
		if ($value === null) {
			return '';
		}

		if (is_string($value)) {
			return trim($value);
		}

		if (is_array($value)) {
			return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
		}

		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $accounts
	 * @return array{summary: array<string, int>, accounts: array<int, array<string, mixed>>}
	 */
	private function buildEmailAccountOverview(array $accounts): array
	{
		$summary = [
			'totalAccounts' => count($accounts),
			'activeAccounts' => 0,
			'readyAccounts' => 0,
			'totalPerMinute' => 0,
			'totalPerHour' => 0,
			'totalDaily' => 0,
		];

		$toolingKeys = ['vs_mail', 'postie', 'email_editing_tools', 'emaildev_utilities', 'live_server', 'template_manager'];
		$formatted = [];

		foreach ($accounts as $account) {
			$status = strtolower((string)($account['status'] ?? 'active'));
			$warmupStatus = strtolower((string)($account['warmup_status'] ?? 'ready'));
			if ($status === 'active') {
				$summary['activeAccounts']++;
			}
			if ($warmupStatus === 'ready') {
				$summary['readyAccounts']++;
			}

			$minuteLimit = (int)($account['hourly_limit'] ?? 0);
			$dailyLimit = (int)($account['daily_limit'] ?? 0);
			$burstLimit = (int)($account['burst_limit'] ?? 0);
			if ($minuteLimit > 0) {
				$summary['totalPerMinute'] += $minuteLimit;
				$summary['totalPerHour'] += $minuteLimit * 60;
			}
			if ($dailyLimit > 0) {
				$summary['totalDaily'] += $dailyLimit;
			}

			$credentials = is_array($account['credentials'] ?? null) ? $account['credentials'] : [];
			$policies = is_array($account['policies'] ?? null) ? $account['policies'] : [];

			$tooling = $this->policyArray($policies, 'tooling', 'vscode_stack', [
				'vs_mail' => false,
				'postie' => false,
				'email_editing_tools' => false,
				'emaildev_utilities' => false,
				'live_server' => false,
				'template_manager' => false,
				'notes' => '',
			]);
			$extensionsReady = 0;
			foreach ($toolingKeys as $key) {
				if (!empty($tooling[$key])) {
					$extensionsReady++;
				}
			}

			$templateEngine = (string)($this->policyScalar($policies, 'templates', 'engine', 'mjml') ?? 'mjml');
			$templatePreview = $this->policyArray($policies, 'templates', 'preview', [
				'preview_url' => '',
				'notes' => '',
			]);
			$complianceFooter = $this->policyArray($policies, 'compliance', 'footer', [
				'unsubscribe_url' => '',
				'privacy_url' => '',
				'company_address' => '',
				'list_unsubscribe_header' => '',
			]);
			$dnsStatus = $this->policyArray($policies, 'compliance', 'dns', [
				'spf' => 'pending',
				'dkim' => 'pending',
				'dmarc' => 'pending',
			]);
			$doubleOptIn = $this->policyBool($policies, 'automation', 'double_opt_in', true);
			$automation = $this->policyArray($policies, 'automation', 'sending', [
				'bounce_policy' => 'smart',
				'test_recipient' => '',
				'log_retention_days' => 90,
				'list_cleaning' => 'weekly',
				'ab_testing' => true,
				'reputation_monitoring' => true,
			]);
			$warmupPlan = $this->policyArray($policies, 'automation', 'warmup', [
				'plan' => '',
				'target_volume' => 0,
			]);
			$apiIntegration = $this->policyArray($policies, 'integration', 'api', [
				'provider' => 'none',
				'region' => '',
				'key_id' => '',
				'status' => '',
			]);

			$formatted[] = [
				'id' => (int)($account['id'] ?? 0),
				'name' => (string)($account['name'] ?? 'Conta'),
				'provider' => (string)($account['provider'] ?? 'custom'),
				'domain' => $account['domain'] ?? null,
				'status' => $status,
				'warmup_status' => $warmupStatus,
				'from_label' => $this->formatMailboxIdentity($account['from_name'] ?? null, $account['from_email'] ?? null),
				'reply_to' => $account['reply_to'] ?? null,
				'smtp_summary' => $this->formatSmtpSummary($account),
				'credentials_username' => $credentials['username'] ?? null,
				'limits' => [
					'per_minute' => $this->formatEmailLimit($minuteLimit),
					'per_hour' => $minuteLimit > 0 ? $this->formatEmailLimit($minuteLimit * 60) : 'Ilimitado',
					'daily' => $this->formatEmailLimit($dailyLimit),
					'burst' => $this->formatEmailLimit($burstLimit),
				],
				'last_health_check' => isset($account['last_health_check_at']) && $account['last_health_check_at']
					? format_datetime((int)$account['last_health_check_at'])
					: '—',
				'tooling' => [
					'ready' => $extensionsReady,
					'total' => count($toolingKeys),
					'notes' => (string)($tooling['notes'] ?? ''),
				],
				'template' => [
					'engine' => strtoupper($templateEngine),
					'preview_url' => (string)($templatePreview['preview_url'] ?? ''),
				],
				'compliance' => [
					'footer' => [
						'unsubscribe_url' => (string)($complianceFooter['unsubscribe_url'] ?? ''),
						'privacy_url' => (string)($complianceFooter['privacy_url'] ?? ''),
						'company_address' => (string)($complianceFooter['company_address'] ?? ''),
					],
					'list_unsubscribe_header' => (string)($complianceFooter['list_unsubscribe_header'] ?? ''),
					'dns' => [
						'spf' => (string)($dnsStatus['spf'] ?? 'pending'),
						'dkim' => (string)($dnsStatus['dkim'] ?? 'pending'),
						'dmarc' => (string)($dnsStatus['dmarc'] ?? 'pending'),
					],
				],
				'automation' => [
					'double_opt_in' => $doubleOptIn,
					'bounce_policy_label' => ucfirst((string)($automation['bounce_policy'] ?? 'smart')),
					'test_recipient' => (string)($automation['test_recipient'] ?? ''),
					'log_retention_days' => (int)($automation['log_retention_days'] ?? 0),
					'list_cleaning_label' => (string)($automation['list_cleaning'] ?? 'weekly'),
					'ab_testing' => !empty($automation['ab_testing']),
					'reputation_monitoring' => !empty($automation['reputation_monitoring']),
				],
				'warmup' => [
					'target_label' => !empty($warmupPlan['target_volume']) ? number_format((int)$warmupPlan['target_volume'], 0, ',', '.') . '/dia' : 'Planejar',
					'plan' => (string)($warmupPlan['plan'] ?? ''),
				],
				'api' => [
					'provider_label' => strtoupper((string)($apiIntegration['provider'] ?? 'none')),
					'region' => (string)($apiIntegration['region'] ?? ''),
					'key_id' => (string)($apiIntegration['key_id'] ?? ''),
					'status' => (string)($apiIntegration['status'] ?? ''),
				],
				'notes' => $account['notes'] ?? null,
			];
		}

		return [
			'summary' => $summary,
			'accounts' => $formatted,
		];
	}

	private function formatEmailLimit(int $value): string
	{
		return $value > 0 ? number_format($value, 0, ',', '.') : 'Ilimitado';
	}

	private function formatMailboxIdentity(?string $name, ?string $email): string
	{
		$name = trim((string)($name ?? ''));
		$email = trim((string)($email ?? ''));

		if ($name === '' && $email === '') {
			return 'Remetente não definido';
		}

		if ($email === '') {
			return $name;
		}

		if ($name === '') {
			return $email;
		}

		return $name . ' <' . $email . '>';
	}

	/**
	 * @param array<string, mixed> $account
	 */
	private function formatSmtpSummary(array $account): string
	{
		$host = trim((string)($account['smtp_host'] ?? 'SMTP'));
		$port = (int)($account['smtp_port'] ?? 0);
		$encryption = strtoupper((string)($account['encryption'] ?? 'tls'));

		if ($host === '') {
			$host = 'SMTP';
		}

		return sprintf('%s:%d · %s', $host, $port, $encryption);
	}

	private function resolveActorId(Request $request): ?int
	{
		$user = $request->attributes->get('user');
		if (!is_array($user)) {
			return null;
		}

		$id = (int)($user['id'] ?? 0);
		return $id > 0 ? $id : null;
	}

	private function persistImportFile(UploadedFile $file, int $maxSizeMb, array &$errors): ?array
	{
		if (!$file->isValid()) {
			$errors['import_file'] = 'Upload inválido. Tente novamente.';
			return null;
		}

		$size = (int)($file->getSize() ?? 0);
		$limitBytes = max(1, $maxSizeMb) * 1024 * 1024;
		if ($size <= 0) {
			$errors['import_file'] = 'Arquivo vazio. Gere um CSV com conteúdo.';
			return null;
		}

		if ($size > $limitBytes) {
			$errors['import_file'] = sprintf('Arquivo maior que %dMB. Quebre o lote e tente novamente.', $maxSizeMb);
			return null;
		}

		$extension = strtolower((string)($file->getClientOriginalExtension() ?: 'csv'));
		if ($extension !== 'csv') {
			$errors['import_file'] = 'Envie arquivos no formato CSV (.csv).';
			return null;
		}

		$targetDir = storage_path('marketing-imports/' . date('Y/m'));
		if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
			$errors['import_file'] = 'Não foi possível preparar o diretório temporário.';
			return null;
		}

		try {
			$token = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
		} catch (Throwable $exception) {
			$token = date('Ymd_His') . '_' . uniqid('', true);
		}

		$filename = $token . '.csv';
		$path = $targetDir . DIRECTORY_SEPARATOR . $filename;

		try {
			$file->move($targetDir, $filename);
		} catch (Throwable $exception) {
			$errors['import_file'] = 'Não foi possível salvar o arquivo enviado.';
			return null;
		}

		return [
			'path' => $path,
			'original_name' => $file->getClientOriginalName() ?: $filename,
			'size' => $size,
		];
	}

	private function flashImportResult(int $listId, array $payload): void
	{
		$_SESSION['marketing_import_result'] = [
			'list_id' => $listId,
			'result' => $payload,
		];
	}

	private function pullImportResult(int $listId): ?array
	{
		$value = $_SESSION['marketing_import_result'] ?? null;
		if (!is_array($value) || (int)($value['list_id'] ?? 0) !== $listId) {
			return null;
		}

		unset($_SESSION['marketing_import_result']);
		return is_array($value['result'] ?? null) ? $value['result'] : null;
	}

	private function ensureAllContactsAudience(): void
	{
		try {
			$listId = $this->lists->upsert([
				'name' => self::ALL_AUDIENCE_NAME,
				'slug' => self::ALL_AUDIENCE_SLUG,
				'description' => 'Todos os contatos com CPF, nome e e-mail.',
				'origin' => 'auto_all',
				'purpose' => 'Envios gerais para toda a base.',
				'status' => 'active',
			]);

			// Carrega todos os contatos já presentes na lista em páginas para evitar corte por limite.
			$existing = [];
			$pageSize = 5000;
			$offset = 0;
			do {
				$chunk = $this->lists->contacts($listId, null, $pageSize, $offset);
				if ($chunk === []) {
					break;
				}
				$existing = array_merge($existing, $chunk);
				$offset += $pageSize;
			} while (count($chunk) === $pageSize);

			$existingByCpf = [];
			$existingUnsubByCpf = [];
			$existingContactIdsByCpf = [];
			$duplicateCpfContactIds = [];
			$noCpfContactIds = [];
			foreach ($existing as $row) {
				$meta = $this->decodeMetadata($row['metadata'] ?? null);
				$cpfMeta = $this->normalizeCpf((string)($meta['cpf'] ?? $row['document'] ?? ''));
				$contactId = (int)($row['contact_id'] ?? $row['id'] ?? 0);
				$status = strtolower((string)($row['subscription_status'] ?? 'subscribed'));

				if ($cpfMeta === '') {
					if ($contactId > 0) {
						$noCpfContactIds[] = $contactId; // remove entradas sem CPF para cumprir regra
					}
					continue;
				}

				if (isset($existingByCpf[$cpfMeta])) {
					$duplicateCpfContactIds[] = $contactId;
					continue;
				}

				$existingByCpf[$cpfMeta] = $row;
				$existingContactIdsByCpf[$cpfMeta] = $contactId;
				if ($status === 'unsubscribed') {
					$existingUnsubByCpf[$cpfMeta] = true;
				}
			}

			$pdo = Connection::instance();
			// Carrega todos os clientes com e-mail informado; CPF/Titular CPF será validado antes de incluir.
			$stmt = $pdo->query("SELECT name, titular_name, email, document, titular_document, phone FROM clients WHERE email IS NOT NULL AND TRIM(email) <> ''");
			$clients = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

			// Deduplica por CPF, permitindo e-mails repetidos, mas garantindo CPF único.
			$recordsByCpf = [];
			$emailUseCount = [];
			$skippedInvalid = 0;
			foreach ($clients as $client) {
				$documentCpf = $this->normalizeCpf((string)($client['document'] ?? ''));
				$titularCpf = $this->normalizeCpf((string)($client['titular_document'] ?? ''));
				$cpf = $documentCpf !== '' ? $documentCpf : $titularCpf;
				$email = strtolower(trim((string)($client['email'] ?? '')));
				$name = trim((string)($client['name'] ?? ($client['titular_name'] ?? '')));
				$phone = trim((string)($client['phone'] ?? ''));

				if ($cpf === '' || strlen($cpf) !== 11) {
					$skippedInvalid++;
					continue;
				}
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					$skippedInvalid++;
					continue;
				}
				if ($name === '') {
					$skippedInvalid++;
					continue;
				}

				if (!isset($recordsByCpf[$cpf])) {
					$recordsByCpf[$cpf] = [
						'cpf' => $cpf,
						'email' => $email,
						'titular_nome' => $name,
						'telefone' => $phone,
					];
				}
				$emailUseCount[$email] = ($emailUseCount[$email] ?? 0) + 1;
			}

			// Inclui parceiros/indicadores (e.g., contabilistas) com CPF+email.
			$partnerRepo = new PartnerRepository();
			$partners = $partnerRepo->listAll();
			foreach ($partners as $partner) {
				$cpf = $this->normalizeCpf((string)($partner['document'] ?? ''));
				$email = strtolower(trim((string)($partner['email'] ?? '')));
				$name = trim((string)($partner['name'] ?? ''));
				$phone = trim((string)($partner['phone'] ?? ''));

				if ($cpf === '' || strlen($cpf) !== 11) {
					$skippedInvalid++;
					continue;
				}
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					$skippedInvalid++;
					continue;
				}
				if ($name === '') {
					$skippedInvalid++;
					continue;
				}

				if (!isset($recordsByCpf[$cpf])) {
					$recordsByCpf[$cpf] = [
						'cpf' => $cpf,
						'email' => $email,
						'titular_nome' => $name,
						'telefone' => $phone,
					];
				}
				$emailUseCount[$email] = ($emailUseCount[$email] ?? 0) + 1;
			}

			$contactRepo = new MarketingContactRepository();
			$allowedCpfs = $existingUnsubByCpf; // preserva opt-outs por CPF

			foreach ($recordsByCpf as $cpf => $record) {
				$email = (string)$record['email'];
				$name = (string)$record['titular_nome'];
				$phone = (string)$record['telefone'];
				$emailDuplicate = ($emailUseCount[$email] ?? 0) > 1;

				if (isset($existingUnsubByCpf[$cpf])) {
					$allowedCpfs[$cpf] = true;
					continue; // respeita opt-out
				}
				if (isset($existingByCpf[$cpf])) {
					$allowedCpfs[$cpf] = true; // já está na lista
					continue;
				}

				$contact = $contactRepo->findByEmail($email);
				if ($contact === null) {
					$contactId = $contactRepo->create([
						'email' => $email,
						'first_name' => $name,
						'last_name' => null,
						'consent_status' => 'pending',
						'status' => 'active',
					]);
					$contact = ['id' => $contactId];
				} else {
					$contactId = (int)$contact['id'];
					$contactRepo->update($contactId, [
						'first_name' => $name,
					]);
				}

				$metadata = json_encode([
					'cpf' => $cpf,
					'titular_nome' => $name,
					'telefone' => $phone,
					'email_duplicado' => $emailDuplicate,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				$this->lists->attachContact($listId, (int)$contact['id'], [
					'subscription_status' => 'subscribed',
					'source' => 'all_auto',
					'subscribed_at' => now(),
					'consent_token' => null,
					'created_at' => now(),
					'updated_at' => now(),
					'metadata' => $metadata,
				]);

				$allowedCpfs[$cpf] = true;
			}

			$toDetach = [];
			foreach ($existingByCpf as $cpf => $row) {
				if (!isset($allowedCpfs[$cpf])) {
					$contactId = $existingContactIdsByCpf[$cpf] ?? null;
					if ($contactId !== null && $contactId > 0) {
						$toDetach[] = (int)$contactId;
					}
				}
			}

			$toDetach = array_values(array_filter(array_unique(array_merge($toDetach, $duplicateCpfContactIds, $noCpfContactIds)), static fn(int $id): bool => $id > 0));

			if ($skippedInvalid > 0 || $duplicateCpfContactIds !== [] || $noCpfContactIds !== []) {
				AlertService::push('sync.crm.todos', 'Anomalias ao sincronizar lista Todos', [
					'list_id' => $listId,
					'skipped_invalid' => $skippedInvalid,
					'duplicates_cpf' => count($duplicateCpfContactIds),
					'no_cpf_removed' => count($noCpfContactIds),
				]);
			}

			if ($toDetach !== []) {
				$this->lists->detachContacts($listId, $toDetach);
			}
		} catch (\Throwable $exception) {
			@error_log('Falha ao sincronizar lista de todos: ' . $exception->getMessage());
		}
	}

	private function syncRfbContabAudienceList(): void
	{
		try {
			@set_time_limit(0);
			$listId = $this->lists->upsert([
				'name' => self::RFB_CONTAB_NAME,
				'slug' => self::RFB_CONTAB_SLUG,
				'description' => 'Base RFB com e-mails contendo "contab" (CNPJ + razão social + e-mail).',
				'origin' => 'rfb_contab',
				'purpose' => 'Campanhas para contabilidades identificadas na base RFB.',
				'status' => 'active',
			]);

			// Carrega existentes em páginas para evitar cortes.
			$existing = [];
			$pageSize = 5000;
			$offset = 0;
			do {
				$chunk = $this->lists->contacts($listId, null, $pageSize, $offset);
				if ($chunk === []) {
					break;
				}
				$existing = array_merge($existing, $chunk);
				$offset += $pageSize;
			} while (count($chunk) === $pageSize);

			$existingByEmail = [];
			$existingUnsubByEmail = [];
			$existingContactIdsByEmail = [];
			$existingCnpjs = [];
			$duplicateCnpjContactIds = [];
			$duplicateEmailContactIds = [];
			foreach ($existing as $row) {
				$email = strtolower(trim((string)($row['email'] ?? '')));
				if ($email !== '') {
					$contactIdExisting = (int)($row['contact_id'] ?? $row['id'] ?? 0);
					if (isset($existingByEmail[$email])) {
						$duplicateEmailContactIds[] = $contactIdExisting;
					} else {
						$existingByEmail[$email] = $row;
						$existingContactIdsByEmail[$email] = $contactIdExisting;
					}
					$status = strtolower((string)($row['subscription_status'] ?? 'subscribed'));
					if ($status === 'unsubscribed') {
						$existingUnsubByEmail[$email] = true;
					}
				}

				$meta = $this->decodeMetadata($row['metadata'] ?? null);
				$cnpjMeta = preg_replace('/\D+/', '', (string)($meta['cnpj'] ?? ''));
				if ($cnpjMeta !== '' && strlen($cnpjMeta) === 14) {
					$contactIdMeta = (int)($row['contact_id'] ?? $row['id'] ?? 0);
					if (isset($existingCnpjs[$cnpjMeta])) {
						$duplicateCnpjContactIds[] = $contactIdMeta;
					} else {
						$existingCnpjs[$cnpjMeta] = [
							'email' => $email,
							'contact_id' => $contactIdMeta,
						];
					}
				}
			}

			$pdo = Connection::instance();
			$stmt = $pdo->prepare(
				"SELECT cnpj, company_name, email FROM rfb_prospects "
				. "WHERE email IS NOT NULL AND TRIM(email) <> '' "
				. "AND LOWER(email) LIKE '%contab%' "
				. "AND (exclusion_status IS NULL OR exclusion_status = 'active') "
				. "ORDER BY email ASC"
			);
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

			$contactRepo = new MarketingContactRepository();
			$allowedEmails = $existingUnsubByEmail; // preserva opt-outs como mapa (chaves = email)
			$seenEmails = [];
			$seenCnpjs = [];
			$skippedInvalid = 0;
			$now = now();
			foreach ($rows as $row) {
				$email = strtolower(trim((string)($row['email'] ?? '')));
				$cnpj = preg_replace('/\D+/', '', (string)($row['cnpj'] ?? ''));
				$name = trim((string)($row['company_name'] ?? ''));

				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					$skippedInvalid++;
					continue;
				}
				if ($cnpj === '' || strlen($cnpj) !== 14) {
					$skippedInvalid++;
					continue;
				}
				if ($name === '') {
					$skippedInvalid++;
					continue;
				}

				if (isset($seenCnpjs[$cnpj])) {
					continue; // evita CNPJ duplicado no grupo
				}
				if (isset($existingCnpjs[$cnpj])) {
					$allowedEmails[$existingCnpjs[$cnpj]['email']] = true; // mantém o registro já existente para este CNPJ
					$seenCnpjs[$cnpj] = true;
					continue;
				}

				if (isset($seenEmails[$email])) {
					continue; // evita e-mail duplicado mesmo com CNPJ diferente
				}
				if (isset($existingByEmail[$email])) {
					$allowedEmails[$email] = true; // mantém e-mail já presente na lista
					$seenEmails[$email] = true;
					$seenCnpjs[$cnpj] = true;
					continue;
				}
				$seenEmails[$email] = true;
				$seenCnpjs[$cnpj] = true;

				if (isset($existingUnsubByEmail[$email])) {
					$allowedEmails[$email] = true; // respeita opt-out já existente
					continue;
				}

				$allowedEmails[$email] = true;

				$contact = $contactRepo->findByEmail($email);
				if ($contact === null) {
					$contactId = $contactRepo->create([
						'email' => $email,
						'first_name' => $name,
						'last_name' => null,
						'status' => 'active',
						'consent_status' => 'pending',
					]);
					$contact = ['id' => $contactId];
				} else {
					$contactId = (int)$contact['id'];
					$contactRepo->update($contactId, [
						'first_name' => $name,
					]);
				}

				$metadata = json_encode([
					'cnpj' => $cnpj,
					'razao_social' => $name,
					'email_duplicado' => false,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				$this->lists->attachContact($listId, (int)$contact['id'], [
					'subscription_status' => 'subscribed',
					'source' => 'rfb_contab',
					'subscribed_at' => $now,
					'consent_token' => null,
					'created_at' => $now,
					'updated_at' => $now,
					'metadata' => $metadata,
				]);
			}

			// Desanexa quem não está na lista final de permitidos, em batches para economizar memória.
			$toDetach = [];
			$offset = 0;
			do {
				$chunk = $this->lists->contacts($listId, null, $pageSize, $offset);
				if ($chunk === []) {
					break;
				}
				foreach ($chunk as $row) {
					$email = strtolower(trim((string)($row['email'] ?? '')));
					if ($email === '' || isset($allowedEmails[$email])) {
						continue;
					}
					$contactId = (int)($row['contact_id'] ?? $row['id'] ?? 0);
					if ($contactId > 0) {
						$toDetach[] = $contactId;
					}
					if (count($toDetach) >= 1000) {
						$this->lists->detachContacts($listId, $toDetach);
						$toDetach = [];
					}
				}
				$offset += $pageSize;
			} while (count($chunk) === $pageSize);

			if ($skippedInvalid > 0 || $duplicateCnpjContactIds !== [] || $duplicateEmailContactIds !== []) {
				AlertService::push('sync.rfb_contab', 'Anomalias ao sincronizar RFB-CONTAB', [
					'list_id' => $listId,
					'skipped_invalid' => $skippedInvalid,
					'duplicates_cnpj' => count($duplicateCnpjContactIds),
					'duplicates_email' => count($duplicateEmailContactIds),
				]);
			}

			$toDetach = array_merge($toDetach, $duplicateCnpjContactIds, $duplicateEmailContactIds);
			$toDetach = array_values(array_filter(array_unique($toDetach), static fn(int $id): bool => $id > 0));
			if ($toDetach !== []) {
				$this->lists->detachContacts($listId, $toDetach);
			}
		} catch (\Throwable $exception) {
			@error_log('Falha ao sincronizar lista RFB-CONTAB: ' . $exception->getMessage());
		}
	}

	private function syncRfbStartMonthAudienceList(int $month, string $slug, string $name): void
	{
		try {
			@set_time_limit(0);
			$listId = $this->lists->upsert([
				'name' => $name,
				'slug' => $slug,
				'description' => 'Empresas com início de atividade no mês ' . $name,
				'origin' => 'rfb_inicio_atividade',
				'purpose' => 'Campanhas por mês de abertura na base RFB.',
				'status' => 'active',
			]);

			// Carrega existentes em streaming para manter opt-outs e mapear e-mails sem estourar memória.
			$pageSize = 5000;
			$existingUnsubByEmail = [];
			$existingEmailContactIds = [];
			$existingCnpjs = [];
			$duplicateCnpjContactIds = [];
			$duplicateEmailContactIds = [];
			$allowedEmails = $existingUnsubByEmail; // preserva opt-outs (map)
			$offset = 0;
			do {
				$chunk = $this->lists->contacts($listId, null, $pageSize, $offset);
				if ($chunk === []) {
					break;
				}
				foreach ($chunk as $row) {
					$email = strtolower(trim((string)($row['email'] ?? '')));
					if ($email === '') {
						continue;
					}
					$status = strtolower((string)($row['subscription_status'] ?? 'subscribed'));
					if ($status === 'unsubscribed') {
						$existingUnsubByEmail[$email] = true;
					}

					$contactIdExisting = (int)($row['contact_id'] ?? $row['id'] ?? 0);
					if (isset($existingEmailContactIds[$email])) {
						$duplicateEmailContactIds[] = $contactIdExisting;
					} else {
						$existingEmailContactIds[$email] = $contactIdExisting;
					}

					$meta = $this->decodeMetadata($row['metadata'] ?? null);
					$cnpjMeta = preg_replace('/\D+/', '', (string)($meta['cnpj'] ?? ''));
					if ($cnpjMeta !== '' && strlen($cnpjMeta) === 14) {
						$contactIdMeta = (int)($row['contact_id'] ?? $row['id'] ?? 0);
						if (isset($existingCnpjs[$cnpjMeta])) {
							$duplicateCnpjContactIds[] = $contactIdMeta;
						} else {
							$existingCnpjs[$cnpjMeta] = [
								'email' => $email,
								'contact_id' => $contactIdMeta,
							];
						}
					}

					if ($status === 'unsubscribed') {
						$allowedEmails[$email] = true; // mantém opt-outs
					}
				}
				$offset += $pageSize;
			} while (count($chunk) === $pageSize);

			$pdo = Connection::instance();
			$stmt = $pdo->prepare(
				"SELECT cnpj, company_name, email, activity_started_at FROM rfb_prospects "
				. "WHERE email IS NOT NULL AND TRIM(email) <> '' "
				. "AND LOWER(email) NOT LIKE '%contab%' "
				. "AND activity_started_at IS NOT NULL "
				. "AND (exclusion_status IS NULL OR exclusion_status = 'active') "
				. "ORDER BY email ASC"
			);
			$stmt->execute();

			$contactRepo = new MarketingContactRepository();
			$allowedEmails = $existingUnsubByEmail; // preserva opt-outs (map)
			$seenEmails = [];
			$seenCnpjs = [];
			$skippedInvalid = 0;
			$now = now();
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$openedAtTs = (int)($row['activity_started_at'] ?? 0);
				if ($openedAtTs <= 0) {
					continue;
				}
				if ((int)date('n', $openedAtTs) !== $month) {
					continue; // filtra apenas pelo mês desejado, qualquer ano
				}

				$email = strtolower(trim((string)($row['email'] ?? '')));
				$cnpj = preg_replace('/\D+/', '', (string)($row['cnpj'] ?? ''));
				$nameCompany = trim((string)($row['company_name'] ?? ''));
				$openedAt = trim((string)($row['activity_started_at'] ?? ''));

				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					$skippedInvalid++;
					continue;
				}
				if (str_contains($email, 'contab')) {
					continue;
				}
				if ($cnpj === '' || strlen($cnpj) !== 14) {
					$skippedInvalid++;
					continue;
				}
				if ($nameCompany === '') {
					$skippedInvalid++;
					continue;
				}
				if (isset($seenCnpjs[$cnpj])) {
					continue; // CNPJ duplicado no dataset atual
				}
				if (isset($existingCnpjs[$cnpj])) {
					$allowedEmails[$existingCnpjs[$cnpj]['email']] = true; // mantém registro existente para este CNPJ
					$seenCnpjs[$cnpj] = true;
					continue;
				}
				if (isset($seenEmails[$email])) {
					continue; // evita e-mail duplicado no grupo
				}
				if (isset($existingEmailContactIds[$email])) {
					$allowedEmails[$email] = true; // já existe na lista
					$seenEmails[$email] = true;
					$seenCnpjs[$cnpj] = true;
					continue;
				}
				$seenEmails[$email] = true;
				$seenCnpjs[$cnpj] = true;

				if (isset($existingUnsubByEmail[$email])) {
					$allowedEmails[$email] = true; // mantém opt-out
					continue;
				}

				$allowedEmails[$email] = true;

				$contact = $contactRepo->findByEmail($email);
				$contactMeta = [
					'cnpj' => $cnpj,
					'razao_social' => $nameCompany,
					'activity_started_at' => $openedAtTs,
					'mes_inicio' => $month,
				];

				if ($contact === null) {
					$contactId = $contactRepo->create([
						'email' => $email,
						'first_name' => $nameCompany,
						'last_name' => null,
						'metadata' => json_encode($contactMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
						'consent_status' => 'pending',
						'status' => 'active',
					]);
					$contact = ['id' => $contactId];
				} else {
					$contactId = (int)$contact['id'];
					$existingMeta = $this->decodeMetadata($contact['metadata'] ?? null);
					$mergedMeta = array_merge($existingMeta, $contactMeta);
					$contactRepo->update($contactId, [
						'first_name' => $nameCompany,
						'metadata' => json_encode($mergedMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
					]);
				}

				$metadata = json_encode([
					'cnpj' => $cnpj,
					'razao_social' => $nameCompany,
					'activity_started_at' => $openedAtTs,
					'mes_inicio' => $month,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				$this->lists->attachContact($listId, (int)$contact['id'], [
					'subscription_status' => 'subscribed',
					'source' => 'rfb_inicio_atividade',
					'subscribed_at' => $now,
					'consent_token' => null,
					'created_at' => $now,
					'updated_at' => $now,
					'metadata' => $metadata,
				]);
			}

			// Desanexa quem não está na lista final de permitidos, em batches para economizar memória.
			$toDetach = [];
			$offset = 0;
			do {
				$chunk = $this->lists->contacts($listId, null, $pageSize, $offset);
				if ($chunk === []) {
					break;
				}
				foreach ($chunk as $row) {
					$email = strtolower(trim((string)($row['email'] ?? '')));
					if ($email === '' || isset($allowedEmails[$email])) {
						continue;
					}
					$contactId = (int)($row['contact_id'] ?? $row['id'] ?? 0);
					if ($contactId > 0) {
						$toDetach[] = $contactId;
					}
					if (count($toDetach) >= 1000) {
						$this->lists->detachContacts($listId, $toDetach);
						$toDetach = [];
					}
				}
				$offset += $pageSize;
			} while (count($chunk) === $pageSize);

			if ($skippedInvalid > 0 || $duplicateCnpjContactIds !== [] || $duplicateEmailContactIds !== []) {
				AlertService::push('sync.rfb_inicio', 'Anomalias ao sincronizar RFB início atividade', [
					'list_id' => $listId,
					'month' => $month,
					'skipped_invalid' => $skippedInvalid,
					'duplicates_cnpj' => count($duplicateCnpjContactIds),
					'duplicates_email' => count($duplicateEmailContactIds),
				]);
			}

			$toDetach = array_merge($toDetach, $duplicateCnpjContactIds, $duplicateEmailContactIds);
			$toDetach = array_values(array_filter(array_unique($toDetach), static fn(int $id): bool => $id > 0));
			if ($toDetach !== []) {
				$this->lists->detachContacts($listId, $toDetach);
			}
		} catch (\Throwable $exception) {
			@error_log('Falha ao sincronizar lista de início de atividade (' . $name . '): ' . $exception->getMessage());
		}
	}

	private function syncPartnerAudienceList(): void
	{
		try {
			$listId = $this->lists->upsert([
				'name' => self::PARTNER_AUDIENCE_NAME,
				'slug' => self::PARTNER_AUDIENCE_SLUG,
				'description' => 'Contatos de todos os parceiros/indicadores cadastrados.',
				'origin' => 'crm_partners',
				'purpose' => 'Comunicação com parceiros e indicadores.',
				'status' => 'active',
			]);

			$partnerRepo = new PartnerRepository();
			$partners = $partnerRepo->listAll();
			$existing = $this->lists->contacts($listId, null, 5000, 0);
			$existingByCpf = [];
			$existingByEmail = [];
			$existingUnsubByCpf = [];
			$existingUnsubByEmail = [];
			$existingContactIdsByCpf = [];
			$duplicateCpfContactIds = [];
			$duplicateEmailContactIds = [];
			foreach ($existing as $row) {
				$meta = $this->decodeMetadata($row['metadata'] ?? null);
				$cpf = $this->normalizeCpf((string)($meta['cpf'] ?? $row['document'] ?? ''));
				$email = strtolower(trim((string)($row['email'] ?? '')));
				$contactId = (int)($row['contact_id'] ?? $row['id'] ?? 0);
				$status = strtolower((string)($row['subscription_status'] ?? 'subscribed'));

				if ($cpf !== '') {
					if (isset($existingByCpf[$cpf])) {
						$duplicateCpfContactIds[] = $contactId;
					} else {
						$existingByCpf[$cpf] = $row;
						$existingContactIdsByCpf[$cpf] = $contactId;
					}
					if ($status === 'unsubscribed') {
						$existingUnsubByCpf[$cpf] = true;
					}
				}

				if ($email !== '') {
					if (isset($existingByEmail[$email])) {
						$duplicateEmailContactIds[] = $contactId;
					} else {
						$existingByEmail[$email] = $row;
						$existingContactIdsByEmail[$email] = $contactId;
					}

					if ($status === 'unsubscribed') {
						$existingUnsubByEmail[$email] = true; // preserva opt-out por e-mail
					}
				}
			}

			$contactRepo = new MarketingContactRepository();
			$seenCpf = [];
			$seenEmail = [];
			$allowedCpfs = $existingUnsubByCpf; // preserva opt-out
			$allowedEmails = $existingUnsubByEmail; // preserva opt-out por e-mail
			$skippedMissingCpfOrName = 0;
			$skippedInvalidEmail = 0;
			$skippedDuplicateCpfSync = 0;
			$skippedDuplicateEmailSync = 0;
			$toAttach = [];
			foreach ($partners as $partner) {
				$name = trim((string)($partner['name'] ?? ''));
				$email = strtolower(trim((string)($partner['email'] ?? '')));
				$cpf = $this->normalizeCpf((string)($partner['document'] ?? ''));
				$phone = trim((string)($partner['phone'] ?? ''));

				if ($name === '' || $cpf === '') {
					$skippedMissingCpfOrName++;
					continue; // exige nome e CPF válido
				}
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
					$skippedInvalidEmail++;
					continue; // ignora parceiros sem e-mail válido
				}
				if (isset($seenCpf[$cpf])) {
					$skippedDuplicateCpfSync++;
					continue; // evita duplicar o mesmo CPF na sincronização atual
				}
				if (isset($seenEmail[$email])) {
					$skippedDuplicateEmailSync++;
					continue; // evita duplicar o mesmo e-mail na sincronização atual
				}
				if (isset($existingUnsubByCpf[$cpf]) || isset($existingUnsubByEmail[$email])) {
					$allowedCpfs[$cpf] = true;
					$allowedEmails[$email] = true;
					continue; // respeita opt-out manual
				}
				if (isset($existingByCpf[$cpf])) {
					$allowedCpfs[$cpf] = true; // já existe na lista por CPF
					$allowedEmails[$email] = true;
					continue;
				}

				$seenCpf[$cpf] = true;

				$contact = $contactRepo->findByEmail($email);
				if ($contact === null) {
					$contactId = $contactRepo->create([
						'email' => $email,
						'first_name' => $name,
						'last_name' => null,
						'company' => null,
						'status' => 'active',
						'consent_status' => 'pending',
					]);
					$contact = ['id' => $contactId];
				} else {
					$contactId = (int)$contact['id'];
					$contactRepo->update($contactId, [
						'first_name' => $name,
					]);
				}

				$seenCpf[$cpf] = true;
				$seenEmail[$email] = true;

				$metadata = json_encode([
					'cpf' => $cpf,
					'titular_nome' => $name,
					'telefone' => $phone,
					'email_duplicado' => false,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				$toAttach[] = [
					'contact_id' => $contact['id'],
					'contactId' => $contact['id'],
					'payload' => [
						'subscription_status' => 'subscribed',
						'source' => 'partners_auto',
						'subscribed_at' => now(),
						'consent_token' => null,
						'created_at' => now(),
						'updated_at' => now(),
						'metadata' => $metadata,
					],
					'cpf' => $cpf,
					'email' => $email,
				];

				$allowedCpfs[$cpf] = true;
				$allowedEmails[$email] = true;
			}

			foreach ($toAttach as $row) {
				$this->lists->attachContact($listId, (int)$row['contact_id'], $row['payload']);
			}

			$toDetach = [];
			foreach ($existing as $row) {
				$meta = $this->decodeMetadata($row['metadata'] ?? null);
				$cpf = $this->normalizeCpf((string)($meta['cpf'] ?? $row['document'] ?? ''));
				$email = strtolower(trim((string)($row['email'] ?? '')));
				$contactId = (int)($row['contact_id'] ?? $row['id'] ?? 0);

				$keep = false;
				if ($cpf !== '' && isset($allowedCpfs[$cpf])) {
					$keep = true;
				}
				if ($email !== '' && isset($allowedEmails[$email])) {
					$keep = true;
				}

				if (!$keep && $contactId > 0) {
					$toDetach[] = $contactId;
				}
			}

			$duplicateCpfCount = $skippedDuplicateCpfSync + count($duplicateCpfContactIds);
			$duplicateEmailCount = $skippedDuplicateEmailSync + count($duplicateEmailContactIds);
			$skippedInvalid = $skippedMissingCpfOrName + $skippedInvalidEmail;
			if ($skippedInvalid > 0 || $duplicateCpfCount > 0 || $duplicateEmailCount > 0) {
				AlertService::push('sync.partners', 'Anomalias ao sincronizar lista de parceiros', [
					'list_id' => $listId,
					'skipped_missing_name_or_cpf' => $skippedMissingCpfOrName,
					'skipped_invalid_email' => $skippedInvalidEmail,
					'duplicates_cpf' => $duplicateCpfCount,
					'duplicates_email' => $duplicateEmailCount,
				]);
			}

			$toDetach = array_merge($toDetach, $duplicateCpfContactIds, $duplicateEmailContactIds);
			$toDetach = array_values(array_filter(array_unique($toDetach), static fn(int $id): bool => $id > 0));

			if ($toDetach !== []) {
				$this->lists->detachContacts($listId, $toDetach);
			}
		} catch (\Throwable $exception) {
			@error_log('Falha ao sincronizar lista de parceiros: ' . $exception->getMessage());
		}
	}

	private function asBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return (int)$value === 1;
		}

		return in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
	}
}
