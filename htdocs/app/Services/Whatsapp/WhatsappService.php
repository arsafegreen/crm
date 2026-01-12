<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Auth\AuthenticatedUser;
use App\Repositories\ClientRepository;
use App\Repositories\ClientProtocolRepository;
use App\Repositories\CertificateRepository;
use App\Services\CertificateService;
use App\Services\CustomerService;
use App\Repositories\CopilotManualRepository;
use App\Repositories\CopilotProfileRepository;
use App\Repositories\CopilotTrainingSampleRepository;
use App\Repositories\PartnerRepository;
use App\Services\AlertService;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Repositories\WhatsappContactRepository;
use App\Repositories\WhatsappLineRepository;
use App\Repositories\WhatsappMessageRepository;
use App\Repositories\WhatsappThreadRepository;
use App\Repositories\WhatsappUserPermissionRepository;
use App\Repositories\WhatsappBroadcastRepository;
use App\Database\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use RuntimeException;
use PDOException;
use PDO;
use Throwable;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

use function digits_only;
use function format_document;
use function format_phone;
use function mb_strtolower;
use function mb_substr;
use function preg_split;
use function random_bytes;
use function slugify;
use function strtotime;
use function str_contains;
use function str_starts_with;
use function asset;
use function base_path;

final class WhatsappService
{
    private const QUEUES = ['arrival', 'scheduled', 'partner', 'reminder'];
    private const BROADCAST_BACKGROUND_THRESHOLD = 60;
    private const INITIAL_THREAD_MESSAGE_LIMIT = 20;
    private const LINES_CACHE_TTL = 5;
    private const AGENTS_CACHE_TTL = 5;
    private const STOPWORDS = [
        'para', 'com', 'essa', 'isso', 'este', 'esta', 'como', 'onde', 'quando', 'porque',
        'mais', 'ainda', 'pois', 'sobre', 'contato', 'cliente', 'mensagem', 'dessa', 'desse',
        'vamos', 'preciso', 'precisa', 'tambem', 'também', 'agora', 'apenas', 'favor', 'segue', 'seguei',
    ];
    private const KEYWORD_STOPWORDS = [
        'para', 'com', 'essa', 'isso', 'este', 'esta', 'como', 'onde', 'quando', 'porque',
        'mais', 'ainda', 'pois', 'sobre', 'contato', 'cliente', 'mensagem', 'dessa', 'desse',
        'vamos', 'preciso', 'precisa', 'tambem', 'também', 'agora', 'apenas', 'favor', 'segue', 'seguei',
        'ola', 'olá', 'bom', 'boa', 'dia', 'tarde', 'noite', 'obrigado', 'obrigada', 'ok', 'certo',
        'agradeco', 'agradeço',
    ];
    private const COPILOT_SUGGESTION_CACHE_TTL = 15;
    private const PANEL_DEFAULTS = [
        'entrada' => ['mode' => 'all', 'users' => []],
        'atendimento' => ['mode' => 'own', 'users' => []],
        'grupos' => ['mode' => 'own', 'users' => []],
        'parceiros' => ['mode' => 'own', 'users' => []],
        'lembrete' => ['mode' => 'own', 'users' => []],
        'agendamento' => ['mode' => 'own', 'users' => []],
        'concluidos' => ['mode' => 'own', 'users' => []],
    ];
    private const PANEL_ALLOWED_MODES = [
        'entrada' => ['all', 'none'],
        'atendimento' => ['own', 'own_or_assigned', 'selected', 'all', 'none'],
        'grupos' => ['own', 'selected', 'all', 'none'],
        'parceiros' => ['own', 'selected', 'all', 'none'],
        'lembrete' => ['own', 'selected', 'all', 'none'],
        'agendamento' => ['own', 'selected', 'all', 'none'],
        'concluidos' => ['own', 'selected', 'all', 'none'],
    ];

    private const PERMISSION_LEVELS = [
        1 => [
            'level' => 1,
            'inbox_access' => 'all',
            'view_scope' => 'all',
            'can_forward' => true,
            'can_start_thread' => true,
            'can_view_completed' => true,
            'can_grant_permissions' => true,
            'panel_scope' => [
                'entrada' => ['mode' => 'all', 'users' => []],
                'atendimento' => ['mode' => 'all', 'users' => []],
                'grupos' => ['mode' => 'all', 'users' => []],
                'parceiros' => ['mode' => 'all', 'users' => []],
                'lembrete' => ['mode' => 'all', 'users' => []],
                'agendamento' => ['mode' => 'all', 'users' => []],
                'concluidos' => ['mode' => 'all', 'users' => []],
            ],
        ],
        2 => [
            'level' => 2,
            'inbox_access' => 'all',
            'view_scope' => 'selected',
            'can_forward' => true,
            'can_start_thread' => true,
            'can_view_completed' => true,
            'can_grant_permissions' => false,
            'panel_scope' => [
                'entrada' => ['mode' => 'all', 'users' => []],
                'atendimento' => ['mode' => 'selected', 'users' => []],
                'grupos' => ['mode' => 'selected', 'users' => []],
                'parceiros' => ['mode' => 'selected', 'users' => []],
                'lembrete' => ['mode' => 'selected', 'users' => []],
                'agendamento' => ['mode' => 'selected', 'users' => []],
                'concluidos' => ['mode' => 'selected', 'users' => []],
            ],
        ],
        3 => [
            'level' => 3,
            'inbox_access' => 'all',
            'view_scope' => 'own',
            'can_forward' => true,
            'can_start_thread' => true,
            'can_view_completed' => true,
            'can_grant_permissions' => false,
            'panel_scope' => [
                'entrada' => ['mode' => 'all', 'users' => []],
                'atendimento' => ['mode' => 'own', 'users' => []],
                'grupos' => ['mode' => 'own', 'users' => []],
                'parceiros' => ['mode' => 'own', 'users' => []],
                'lembrete' => ['mode' => 'own', 'users' => []],
                'agendamento' => ['mode' => 'own', 'users' => []],
                'concluidos' => ['mode' => 'own', 'users' => []],
            ],
        ],
        4 => [
            'level' => 4,
            'inbox_access' => 'own_only',
            'view_scope' => 'own_or_assigned',
            'can_forward' => true,
            'can_start_thread' => false,
            'can_view_completed' => true,
            'can_grant_permissions' => false,
            'panel_scope' => [
                'entrada' => ['mode' => 'all', 'users' => []],
                'atendimento' => ['mode' => 'own_or_assigned', 'users' => []],
                'grupos' => ['mode' => 'none', 'users' => []],
                'parceiros' => ['mode' => 'own', 'users' => []],
                'lembrete' => ['mode' => 'own', 'users' => []],
                'agendamento' => ['mode' => 'own', 'users' => []],
                'concluidos' => ['mode' => 'own', 'users' => []],
            ],
        ],
    ];
    private const SESSION_WINDOW_SECONDS = 86400;
    private const RATE_LIMIT_PRESETS = [
        'starter_250' => [
            'label' => 'Nível inicial · 250 conversas/24h',
            'description' => 'Fase de aquecimento: ideal para números recém-validados ou reabilitados.',
            'window_seconds' => 86400,
            'max_messages' => 250,
        ],
        'tier_1000' => [
            'label' => 'Nível 1 · 1.000 conversas/24h',
            'description' => 'Tier padrão liberado pela Meta após histórico positivo.',
            'window_seconds' => 86400,
            'max_messages' => 1000,
        ],
        'tier_10000' => [
            'label' => 'Nível 2 · 10.000 conversas/24h',
            'description' => 'Recomendado para operações médias com verificação Meta concluída.',
            'window_seconds' => 86400,
            'max_messages' => 10000,
        ],
        'tier_100000' => [
            'label' => 'Nível 3 · 100.000 conversas/24h',
            'description' => 'Escala alta sob monitoramento (contas com histórico impecável).',
            'window_seconds' => 86400,
            'max_messages' => 100000,
        ],
        'unlimited' => [
            'label' => 'Meta aprovado · 250.000 conversas/24h',
            'description' => 'Cotas máximas disponibilizadas pela Meta para marcas globais.',
            'window_seconds' => 86400,
            'max_messages' => 250000,
        ],
    ];
    private const MESSAGE_STATUS_PRIORITY = [
        'failed' => -20,
        'error' => -10,
        'queued' => 5,
        'saving' => 8,
        'sent' => 10,
        'imported' => 15,
        'delivered' => 20,
        'read' => 30,
    ];
    private const BACKUP_TABLES = [
        'whatsapp_lines',
        'whatsapp_contacts',
        'whatsapp_threads',
        'whatsapp_messages',
        'whatsapp_user_permissions',
        'whatsapp_broadcasts',
    ];
    private const MAX_IMPORT_MESSAGES = 20000;
    private const QUEUE_SUMMARY_CACHE_TTL = 5;
    private const STATUS_SUMMARY_CACHE_TTL = 10;
    private const MESSAGE_RETAIN_LIMIT = 20;
    private const GATEWAY_SNAPSHOT_TTL = 2592000; // 30 days

    private SettingRepository $settings;
    private WhatsappContactRepository $contacts;
    private WhatsappThreadRepository $threads;
    private WhatsappMessageRepository $messages;
    private WhatsappLineRepository $lines;
    private UserRepository $users;
    private PartnerRepository $partners;
    private CopilotProfileRepository $profiles;
    private CopilotManualRepository $manuals;
    private ClientRepository $clients;
    private ClientProtocolRepository $clientProtocols;
    private CertificateRepository $certificates;
    private CertificateService $certificateService;
    private CustomerService $customerService;
    private CopilotTrainingSampleRepository $trainingSamples;
    private WhatsappUserPermissionRepository $userPermissions;
    private WhatsappBroadcastRepository $broadcasts;
    private array $permissionCache = [];
    private ?array $altGatewayConfig = null;
    private int $altGatewayDailyLimitDefault = 0;
    private int $altGatewayRotationWindowHours = 24;
    private float $altPerNumberWindowHours = 24.0;
    private int $altPerNumberLimit = 1;
    private ?float $altPerCampaignWindowHours = null;
    private ?int $altPerCampaignLimit = null;
    private ?array $altGatewayUsageCache = null;
    private ?string $lastAltGatewaySelected = null;
    private array $clientCacheById = [];
    private array $clientCacheByPhone = [];
    /** @var array<int,array{has_protocol:bool,protocol_state:?string,protocol_number:?string,protocol_expires_at:?int}> */
    private array $protocolSummaryCache = [];
    private ?array $mediaTemplatesCache = null;
    private array $queueSummaryCache = ['ts' => 0, 'data' => []];
    /** @var array<string,array{ts:int,data:array}> */
    private array $statusSummaryCache = [
        'with_alt' => ['ts' => 0, 'data' => []],
        'no_alt' => ['ts' => 0, 'data' => []],
    ];
    private array $linesCache = ['ts' => 0, 'data' => []];
    /** @var array<int,array{ts:int,data:array}> */
    private array $agentsCacheByExclude = [];
    /** @var array{ts:int,key:string,data:?array} */
    private array $copilotSuggestionCache = ['ts' => 0, 'key' => '', 'data' => null];
    private WhatsappGatewayBackup $gatewayBackup;
    private bool $archivedInactiveOnce = false;
    private array $completedCountCache = ['ts' => 0, 'value' => null];

    public function __construct(
        ?SettingRepository $settings = null,
        ?WhatsappContactRepository $contacts = null,
        ?WhatsappThreadRepository $threads = null,
        ?WhatsappMessageRepository $messages = null,
        ?WhatsappLineRepository $lines = null,
        ?UserRepository $users = null,
        ?PartnerRepository $partners = null,
        ?CopilotProfileRepository $profiles = null,
        ?CopilotManualRepository $manuals = null,
        ?CopilotTrainingSampleRepository $trainingSamples = null,
        ?WhatsappUserPermissionRepository $userPermissions = null,
        ?ClientRepository $clients = null,
        ?ClientProtocolRepository $clientProtocols = null,
        ?CertificateRepository $certificates = null,
        ?CertificateService $certificateService = null,
        ?CustomerService $customerService = null,
        ?WhatsappBroadcastRepository $broadcasts = null,
        ?WhatsappGatewayBackup $gatewayBackup = null
    ) {
        $this->settings = $settings ?? new SettingRepository();
        $this->contacts = $contacts ?? new WhatsappContactRepository();
        $this->threads = $threads ?? new WhatsappThreadRepository();
        $this->messages = $messages ?? new WhatsappMessageRepository();
        $this->lines = $lines ?? new WhatsappLineRepository();
        $this->users = $users ?? new UserRepository();
        $this->partners = $partners ?? new PartnerRepository();
        $this->profiles = $profiles ?? new CopilotProfileRepository();
        $this->manuals = $manuals ?? new CopilotManualRepository();
        $this->trainingSamples = $trainingSamples ?? new CopilotTrainingSampleRepository();
        $this->userPermissions = $userPermissions ?? new WhatsappUserPermissionRepository();
        $this->clients = $clients ?? new ClientRepository();
        $this->clientProtocols = $clientProtocols ?? new ClientProtocolRepository();
        $this->certificates = $certificates ?? new CertificateRepository();
        $this->certificateService = $certificateService ?? new CertificateService($this->certificates);
        $this->customerService = $customerService ?? new CustomerService($this->clients);
        $this->broadcasts = $broadcasts ?? new WhatsappBroadcastRepository();
        $this->gatewayBackup = $gatewayBackup ?? new WhatsappGatewayBackup();
    }

    public function lines(): array
    {
        $now = time();
        if (($this->linesCache['ts'] ?? 0) >= $now - self::LINES_CACHE_TTL) {
            return $this->linesCache['data'];
        }

        $data = $this->lines->all();
        $this->linesCache = ['ts' => $now, 'data' => $data];

        return $data;
    }

    public function rateLimitPresets(): array
    {
        return self::RATE_LIMIT_PRESETS;
    }

    public function detectRateLimitPreset(array $line): ?string
    {
        $window = (int)($line['rate_limit_window_seconds'] ?? 0);
        $max = (int)($line['rate_limit_max_messages'] ?? 0);
        foreach (self::RATE_LIMIT_PRESETS as $key => $preset) {
            if ((int)$preset['window_seconds'] === $window && (int)$preset['max_messages'] === $max) {
                return $key;
            }
        }

        return null;
    }

    public function copilotProfiles(): array
    {
        return $this->profiles->all();
    }

    public function copilotProfile(int $profileId): ?array
    {
        if ($profileId <= 0) {
            return null;
        }

        return $this->profiles->find($profileId);
    }

    public function createCopilotProfile(array $input): array
    {
        $id = $this->profiles->create($input);
        $profile = $this->profiles->find($id);
        if ($profile === null) {
            throw new RuntimeException('Perfil IA não pôde ser criado.');
        }

        return $profile;
    }

    public function updateCopilotProfile(int $profileId, array $input): array
    {
        if ($this->copilotProfile($profileId) === null) {
            throw new RuntimeException('Perfil IA não encontrado.');
        }

        $this->profiles->update($profileId, $input);
        $profile = $this->profiles->find($profileId);
        if ($profile === null) {
            throw new RuntimeException('Perfil IA atualizado não foi localizado.');
        }

        return $profile;
    }

    public function deleteCopilotProfile(int $profileId): void
    {
        $this->profiles->delete($profileId);
    }

    public function manuals(): array
    {
        return $this->manuals->all();
    }

    public function manual(int $manualId): ?array
    {
        if ($manualId <= 0) {
            return null;
        }

        return $this->manuals->find($manualId);
    }

    public function trainingSamplesSummary(int $limit = 6): array
    {
        return $this->trainingSamples->recent($limit);
    }

    public function mediaTemplates(): array
    {
        if ($this->mediaTemplatesCache !== null) {
            return $this->mediaTemplatesCache;
        }

        $config = config('whatsapp_templates', []);
        $catalogs = ['stickers', 'documents'];
        $result = [
            'stickers' => [],
            'documents' => [],
        ];

        foreach ($catalogs as $catalog) {
            $entries = $config[$catalog] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $normalized = $this->normalizeMediaTemplate($entry, $catalog);
                if ($normalized !== null) {
                    $result[$catalog][] = $normalized;
                }
            }
        }

        return $this->mediaTemplatesCache = $result;
    }

    public function recentBroadcasts(int $limit = 8): array
    {
        $records = $this->broadcasts->recent($limit);
        foreach ($records as &$record) {
            $criteria = $record['criteria'] ?? '[]';
            $decoded = is_string($criteria) ? json_decode($criteria, true) : [];
            $record['criteria'] = is_array($decoded) ? $decoded : [];
        }
        unset($record);

        return $records;
    }

    public function generateWhatsappBackup(): array
    {
        $dataset = $this->buildBackupDataset();
        $backupDir = storage_path('backups/whatsapp');
        $this->ensureDirectory($backupDir);

        $timestamp = date('Ymd_His');
        $filename = sprintf('whatsapp-backup-%s.zip', $timestamp);
        $zipPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo ZIP de backup.');
        }

        $json = json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            $zip->close();
            throw new RuntimeException('Falha ao serializar os dados do backup.');
        }

        $zip->addFromString('whatsapp-backup.json', $json);

        $mediaRoot = storage_path('whatsapp-media');
        if (is_dir($mediaRoot)) {
            $this->addDirectoryToZip($zip, $mediaRoot, 'media');
        }

        $zip->close();

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'size' => is_file($zipPath) ? (int)filesize($zipPath) : 0,
            'meta' => $dataset['meta'],
        ];
    }

    public function restoreWhatsappBackup(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Upload inválido. Tente reenviar o arquivo.');
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if ($extension !== 'zip') {
            throw new RuntimeException('Envie um arquivo ZIP gerado pelo painel de backup.');
        }

        $tempRoot = storage_path('temp');
        $this->ensureDirectory($tempRoot);
        $workDir = $tempRoot . DIRECTORY_SEPARATOR . 'whatsapp-restore-' . uniqid();
        $this->ensureDirectory($workDir);

        $uploadedName = 'backup.zip';
        $file->move($workDir, $uploadedName);
        $zipPath = $workDir . DIRECTORY_SEPARATOR . $uploadedName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->deleteDirectory($workDir);
            throw new RuntimeException('Não foi possível abrir o ZIP enviado.');
        }

        if ($zip->locateName('whatsapp-backup.json') === false) {
            $zip->close();
            $this->deleteDirectory($workDir);
            throw new RuntimeException('ZIP inválido: whatsapp-backup.json não encontrado.');
        }

        if (!$zip->extractTo($workDir)) {
            $zip->close();
            $this->deleteDirectory($workDir);
            throw new RuntimeException('Falha ao extrair o conteúdo do ZIP.');
        }
        $zip->close();

        $jsonPath = $workDir . DIRECTORY_SEPARATOR . 'whatsapp-backup.json';
        $raw = @file_get_contents($jsonPath);
        if (!is_string($raw) || $raw === '') {
            $this->deleteDirectory($workDir);
            throw new RuntimeException('Arquivo whatsapp-backup.json vazio ou inacessível.');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['tables']) || !is_array($payload['tables'])) {
            $this->deleteDirectory($workDir);
            throw new RuntimeException('Estrutura do backup inválida.');
        }

        $tables = array_intersect_key($payload['tables'], array_flip(self::BACKUP_TABLES));
        $stats = $this->importBackupTables($tables);

        $mediaSource = $workDir . DIRECTORY_SEPARATOR . 'media';
        if (is_dir($mediaSource)) {
            $this->replaceMediaDirectory($mediaSource);
        }

        $this->deleteDirectory($workDir);

        return [
            'tables' => $stats,
            'media_replaced' => is_dir(storage_path('whatsapp-media')),
        ];
    }


public function dispatchBroadcast(array $input, AuthenticatedUser $actor): array
{
    if (!$actor->isAdmin()) {
        throw new RuntimeException('Somente administradores podem disparar comunicados.');
    }

    $title = trim((string)($input['title'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    $queuesInput = $input['queues'] ?? [];
    if (!is_array($queuesInput)) {
        $queuesInput = $queuesInput !== null ? [$queuesInput] : [];
    }

    $allowedQueues = ['arrival', 'scheduled', 'partner', 'reminder', 'groups'];
    $selectedQueues = [];
    foreach ($queuesInput as $queue) {
        $normalized = strtolower(trim((string)$queue));
        if ($normalized === '' || !in_array($normalized, $allowedQueues, true)) {
            continue;
        }
        $selectedQueues[] = $normalized;
    }
    $selectedQueues = array_values(array_unique($selectedQueues));

    if ($title === '') {
        throw new RuntimeException('Informe um título para identificar o comunicado.');
    }

    if ($selectedQueues === []) {
        throw new RuntimeException('Selecione pelo menos uma fila para enviar o comunicado.');
    }

    $templateKind = trim((string)($input['template_kind'] ?? ''));
    $templateKey = trim((string)($input['template_key'] ?? ''));
    $templateSelection = ($templateKind !== '' && $templateKey !== '')
        ? ['kind' => $templateKind, 'key' => $templateKey]
        : null;

    if ($message === '' && $templateSelection === null) {
        throw new RuntimeException('Escreva a mensagem ou escolha um modelo de mídia para o comunicado.');
    }

    $limit = (int)($input['limit'] ?? 200);
    $limit = max(1, min(500, $limit));
    $threadIds = $this->resolveBroadcastTargets($selectedQueues, $limit);
    if ($threadIds === []) {
        throw new RuntimeException('Nenhuma conversa encontrada para as filas selecionadas.');
    }

    $criteria = [
        'queues' => $selectedQueues,
        'limit' => $limit,
        'has_message' => $message !== '',
        'thread_ids' => $threadIds,
    ];
    if ($templateSelection !== null) {
        $criteria['template'] = $templateSelection;
    }

    $modeInput = strtolower(trim((string)($input['mode'] ?? 'auto')));
    $shouldQueue = $this->shouldProcessBroadcastInBackground(count($threadIds), $modeInput);

    $broadcastId = $this->broadcasts->create([
        'title' => $title,
        'message' => $message === '' ? null : $message,
        'template_kind' => $templateSelection['kind'] ?? null,
        'template_key' => $templateSelection['key'] ?? null,
        'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'status' => $shouldQueue ? 'pending' : 'running',
        'stats_total' => count($threadIds),
        'initiated_by' => $actor->id,
        'created_at' => now(),
        'completed_at' => null,
        'last_error' => null,
    ]);

    if ($shouldQueue) {
        $this->queueBroadcastWorker($broadcastId);

        return [
            'stats' => ['total' => count($threadIds), 'sent' => 0, 'failed' => 0],
            'broadcast' => $this->broadcasts->find($broadcastId),
            'mode' => 'queued',
            'recent' => $this->recentBroadcasts(),
        ];
    }

    $record = $this->broadcasts->find($broadcastId);
    if ($record === null) {
        throw new RuntimeException('Broadcast recém-criado não foi encontrado.');
    }

    $result = $this->runBroadcastNow($record, $actor, $threadIds);
    $result['mode'] = 'immediate';
    $result['recent'] = $this->recentBroadcasts();

    return $result;
}



    public function importKnowledgeManual(array $input, UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Falha ao processar o upload. Tente novamente.');
        }

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $originalName = (string)$file->getClientOriginalName();
            $title = $originalName !== '' ? pathinfo($originalName, PATHINFO_FILENAME) : 'Manual IA';
        }

        $description = trim((string)($input['description'] ?? ''));
        $allowedExtensions = ['txt', 'md', 'markdown'];
        $extension = strtolower((string)$file->getClientOriginalExtension());
        $mime = (string)$file->getClientMimeType();

        if ($extension === '' && str_contains($mime, 'markdown')) {
            $extension = 'md';
        }

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Envie arquivos .txt ou .md exportados do sistema.');
        }

        $size = (int)$file->getSize();
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new RuntimeException('Limite de 2 MB por manual. Reduza o arquivo e tente novamente.');
        }

        $storageDir = storage_path('copilot/manuals/' . date('Y/m'));
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('NÆo foi poss¡vel preparar a pasta de uploads.');
        }

        $safeName = uniqid('manual_', true) . '.' . $extension;
        $file->move($storageDir, $safeName);
        $fullPath = $storageDir . DIRECTORY_SEPARATOR . $safeName;

        $contents = @file_get_contents($fullPath);
        if (!is_string($contents) || $contents === '') {
            @unlink($fullPath);
            throw new RuntimeException('Conteœdo vazio ou ileg¡vel no manual enviado.');
        }

        $text = $this->sanitizeManualText($contents);
        if ($text === '') {
            @unlink($fullPath);
            throw new RuntimeException('NÆo foi poss¡vel extrair texto do manual.');
        }

        $chunks = $this->chunkManualText($text);
        if ($chunks === []) {
            $chunks[] = $this->buildManualChunkPayload(0, $text);
        }

        $preview = mb_substr($text, 0, 320, 'UTF-8');
        $now = now();

        try {
            return $this->manuals->create([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'filename' => (string)($file->getClientOriginalName() ?: $safeName),
                'storage_path' => $fullPath,
                'mime_type' => $mime !== '' ? $mime : 'text/plain',
                'size_bytes' => $size,
                'content_preview' => $preview,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunks);
        } catch (\Throwable $exception) {
            @unlink($fullPath);
            throw $exception;
        }
    }

    public function deleteManual(int $manualId): void
    {
        $manual = $this->manual($manualId);
        if ($manual === null) {
            throw new RuntimeException('Manual nÆo encontrado.');
        }

        $this->manuals->delete($manualId);
        $path = (string)($manual['storage_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public function createLine(array $input): array
    {
        $payload = $this->sanitizeLinePayload($input);
        $id = $this->lines->create($payload);
        return $this->lines->find($id) ?? $payload;
    }

    public function findLine(int $lineId): ?array
    {
        return $this->lines->find($lineId);
    }

    public function updateLine(int $lineId, array $input): array
    {
        $payload = $this->sanitizeLinePayload($input, false);
        $this->lines->update($lineId, $payload);
        return $this->lines->find($lineId) ?? $payload;
    }

    public function deleteLine(int $lineId): void
    {
        $this->lines->delete($lineId);
    }

    public function statusSummary(bool $probeAltGateways = true): array
    {
        $now = time();
        $cacheKey = $probeAltGateways ? 'with_alt' : 'no_alt';
        $cached = $this->statusSummaryCache[$cacheKey] ?? ['ts' => 0, 'data' => []];
        if (($cached['ts'] ?? 0) >= $now - self::STATUS_SUMMARY_CACHE_TTL) {
            return $cached['data'];
        }

        $lines = $this->lines();
        $queueCounts = $this->threads->countByQueue();
        $options = $this->globalOptions();
        $altInstances = $probeAltGateways ? $this->altGatewayInstances() : [];
        $altStatuses = [];
        $linesByAlt = [];
        $usageWindowHours = $this->altGatewayUsageWindowHours();
        $usageSnapshot = $probeAltGateways ? $this->altGatewayUsage() : [];
        if ($probeAltGateways) {
            foreach ($lines as $line) {
                $altSlug = trim((string)($line['alt_gateway_instance'] ?? ''));
                if ($altSlug !== '') {
                    $linesByAlt[$altSlug] = true;
                }
            }
            $readyCount = 0;
            foreach ($altInstances as $slug => $instance) {
                $status = $this->probeAltGateway($instance);
                if ($status === null) {
                    $altStatuses[$slug] = [
                        'slug' => $slug,
                        'label' => $instance['label'],
                        'ready' => false,
                        'ok' => false,
                        'usage' => [
                            'window_hours' => $usageWindowHours,
                            'sent' => $usageSnapshot[$slug] ?? 0,
                            'limit' => max(0, (int)($instance['daily_limit'] ?? 0)),
                        ],
                    ];
                    continue;
                }
                $status['slug'] = $slug;
                $status['label'] = $instance['label'];
                $status['usage'] = [
                    'window_hours' => $usageWindowHours,
                    'sent' => $usageSnapshot[$slug] ?? 0,
                    'limit' => max(0, (int)($instance['daily_limit'] ?? 0)),
                ];
                if (!empty($status['ready'])) {
                    $readyCount++;
                }
                $altStatuses[$slug] = $status;
            }
        } else {
            $readyCount = 0;
        }
        $lineCount = count($lines);

        $summary = [
            'connected' => $lineCount > 0,
            'lines_total' => $lineCount,
            'webhook_ready' => array_reduce($lines, static fn(bool $carry, array $line): bool => $carry || ((string)($line['verify_token'] ?? '') !== ''), false),
            'copilot_ready' => (string)$options['copilot_api_key'] !== '',
            'open_threads' => $queueCounts['arrival'] ?? 0,
            'waiting_threads' => $queueCounts['scheduled'] ?? 0,
            'partner_threads' => $queueCounts['partner'] ?? 0,
            'reminder_threads' => $queueCounts['reminder'] ?? 0,
            'queues' => [
                'arrival' => $queueCounts['arrival'] ?? 0,
                'scheduled' => $queueCounts['scheduled'] ?? 0,
                'partner' => $queueCounts['partner'] ?? 0,
                'reminder' => $queueCounts['reminder'] ?? 0,
            ],
            'knowledge' => [
                'manuals' => $this->manuals->count(),
                'samples' => $this->trainingSamples->count(),
            ],
            'alt_gateway' => $this->selectPrimaryAltGateway($altStatuses),
            'alt_gateways' => [
                'instances' => array_values($altStatuses),
                'ready' => $readyCount,
                'total' => $probeAltGateways ? count($altInstances) : 0,
            ],
            'alt_gateway_status' => $altStatuses,
        ];

        $this->statusSummaryCache[$cacheKey] = ['ts' => $now, 'data' => $summary];

        return $summary;
    }

    public function permissionPresets(): array
    {
        return self::PERMISSION_LEVELS;
    }

    public function resolveUserPermission(AuthenticatedUser $user): array
    {
        if (isset($this->permissionCache[$user->id])) {
            return $this->permissionCache[$user->id];
        }

        $record = $this->userPermissions->findByUserId($user->id);
        if ($record !== null) {
            return $this->permissionCache[$user->id] = $this->normalizePermissionRow($record);
        }

        $default = $this->defaultPermissionForRole($user->role);
        $default['user_id'] = $user->id;

        return $this->permissionCache[$user->id] = $default;
    }

    public function permissionForUser(int $userId, string $role): array
    {
        $record = $this->userPermissions->findByUserId($userId);
        if ($record !== null) {
            return $this->normalizePermissionRow($record);
        }

        $default = $this->defaultPermissionForRole($role);
        $default['user_id'] = $userId;

        return $default;
    }

    public function userPermissionMatrix(): array
    {
        $records = $this->userPermissions->all();
        $matrix = [];
        foreach ($records as $record) {
            $permission = $this->normalizePermissionRow($record);
            $matrix[$permission['user_id']] = $permission;
        }

        return $matrix;
    }

    public function permissionsForAgents(array $agents): array
    {
        $matrix = $this->userPermissionMatrix();
        $result = [];
        foreach ($agents as $agent) {
            $userId = (int)($agent['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (isset($matrix[$userId])) {
                $result[$userId] = $matrix[$userId];
                continue;
            }
            $default = $this->defaultPermissionForRole((string)($agent['role'] ?? 'user'));
            $default['user_id'] = $userId;
            $result[$userId] = $default;
        }

        return $result;
    }

    public function updateUserPermissions(array $entries): array
    {
        $updated = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $userId = (int)($entry['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $displayProvided = array_key_exists('display_name', $entry);
            $payload = [
                'user_id' => $userId,
                'level' => $entry['level'] ?? null,
                'inbox_access' => $entry['inbox_access'] ?? null,
                'view_scope' => $entry['view_scope'] ?? null,
                'view_scope_payload' => $entry['view_scope_users'] ?? [],
                'panel_scope' => $this->normalizePanelScopeValue($entry['panel_scope'] ?? [], self::PANEL_DEFAULTS),
                'can_forward' => !empty($entry['can_forward']),
                'can_start_thread' => !empty($entry['can_start_thread']),
                'can_view_completed' => !empty($entry['can_view_completed']),
                'can_grant_permissions' => !empty($entry['can_grant_permissions']),
            ];

            $this->userPermissions->upsert($payload);
            if ($displayProvided) {
                $alias = $this->sanitizeDisplayName($entry['display_name'] ?? null);
                try {
                    $this->users->update($userId, ['chat_display_name' => $alias]);
                } catch (\Throwable $exception) {
                    AlertService::push('whatsapp.permissions', 'Falha ao atualizar nome exibido do atendente', [
                        'user_id' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            $updated++;
        }

        $this->permissionCache = [];

        return ['updated' => $updated];
    }

    private function defaultPermissionForRole(string $role): array
    {
        $level = $role === 'admin' ? 1 : 3;
        $preset = self::PERMISSION_LEVELS[$level] ?? self::PERMISSION_LEVELS[3];
        $preset['panel_scope'] = $this->normalizePanelScopeValue($preset['panel_scope'] ?? [], self::PANEL_DEFAULTS);

        return array_merge($preset, [
            'user_id' => 0,
            'view_scope_users' => [],
        ]);
    }

    private function normalizePermissionRow(array $row): array
    {
        $userId = (int)($row['user_id'] ?? 0);
        $level = (int)($row['level'] ?? 3);
        $preset = self::PERMISSION_LEVELS[$level] ?? self::PERMISSION_LEVELS[3];

        $inboxAccess = $this->sanitizePermissionToken($row['inbox_access'] ?? $preset['inbox_access'], ['all', 'own_only']);
        $viewScope = $this->sanitizePermissionToken($row['view_scope'] ?? $preset['view_scope'], ['all', 'own', 'selected', 'own_or_assigned']);

        $viewScopeUsers = $this->decodeScopePayload($row['view_scope_payload'] ?? null);

        return [
            'user_id' => $userId,
            'level' => $level,
            'inbox_access' => $inboxAccess,
            'view_scope' => $viewScope,
            'view_scope_users' => $viewScopeUsers,
            'panel_scope' => $this->normalizePanelScopeValue($row['panel_scope'] ?? null, $preset['panel_scope'] ?? self::PANEL_DEFAULTS),
            'can_forward' => $this->boolValue($row['can_forward'] ?? ($preset['can_forward'] ? 1 : 0)),
            'can_start_thread' => $this->boolValue($row['can_start_thread'] ?? ($preset['can_start_thread'] ? 1 : 0)),
            'can_view_completed' => $this->boolValue($row['can_view_completed'] ?? ($preset['can_view_completed'] ? 1 : 0)),
            'can_grant_permissions' => $this->boolValue($row['can_grant_permissions'] ?? ($preset['can_grant_permissions'] ? 1 : 0)),
        ];
    }

    private function sanitizePermissionToken(mixed $value, array $allowed): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($candidate, $allowed, true) ? $candidate : $allowed[0];
    }

    private function decodeScopePayload(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            $raw = $value;
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            $raw = is_array($decoded) ? $decoded : [];
        } else {
            $raw = [];
        }

        $filtered = array_filter(array_map(static fn($entry) => (int)$entry, $raw), static fn(int $id): bool => $id > 0);

        return array_values(array_unique($filtered));
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ((int)$value) === 1;
    }

    private function normalizePanelScopeValue(mixed $value, ?array $fallback = null): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($value)) {
            $value = [];
        }

        $base = $fallback ?? self::PANEL_DEFAULTS;
        $result = [];
        foreach (self::PANEL_DEFAULTS as $panel => $defaults) {
            $source = $value[$panel] ?? $base[$panel] ?? $defaults;
            if (!is_array($source)) {
                $source = $defaults;
            }
            $result[$panel] = [
                'mode' => $this->sanitizePanelMode($panel, $source['mode'] ?? ($defaults['mode'] ?? 'none')),
                'users' => $this->decodeScopePayload($source['users'] ?? []),
            ];
        }

        return $result;
    }

    private function sanitizePanelMode(string $panel, mixed $mode): string
    {
        $allowed = self::PANEL_ALLOWED_MODES[$panel] ?? ['all', 'none'];
        $candidate = is_string($mode) ? strtolower(trim($mode)) : '';
        if (!in_array($candidate, $allowed, true)) {
            return $allowed[0];
        }

        return $candidate;
    }

    private function panelScopeEntry(array $permission, string $panel): array
    {
        $scope = $permission['panel_scope'][$panel] ?? self::PANEL_DEFAULTS[$panel] ?? ['mode' => 'none', 'users' => []];
        $mode = $this->sanitizePanelMode($panel, $scope['mode'] ?? null);
        $users = $scope['users'] ?? [];
        if (!is_array($users)) {
            $users = [];
        }
        $filtered = array_values(array_unique(array_filter(array_map(static fn($value) => (int)$value, $users), static fn(int $id): bool => $id > 0)));

        return [
            'mode' => $mode,
            'users' => $filtered,
        ];
    }

    private function panelUserLookup(array $users, int $currentUserId): array
    {
        $list = array_values(array_unique(array_filter(array_map(static fn($value) => (int)$value, $users), static fn(int $id): bool => $id > 0)));
        if ($currentUserId > 0) {
            $list[] = $currentUserId;
        }

        return $list === [] ? [] : array_fill_keys($list, true);
    }

    private function panelKeyForQueue(string $queue): ?string
    {
        return match ($queue) {
            'arrival' => 'entrada',
            'scheduled' => 'agendamento',
            'partner' => 'parceiros',
            'reminder' => 'lembrete',
            default => null,
        };
    }

    private function sanitizeDisplayName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = preg_replace('/\s+/', ' ', (string)$value);
        $raw = $raw !== null ? trim($raw) : '';
        if ($raw === '') {
            return null;
        }
        if (mb_strlen($raw, 'UTF-8') > 80) {
            $raw = mb_substr($raw, 0, 80, 'UTF-8');
        }

        return $raw;
    }

    private function resolveUserDisplayName(AuthenticatedUser $user): string
    {
        $alias = trim((string)($user->chatDisplayName ?? ''));
        if ($alias !== '') {
            return $alias;
        }

        $name = trim($user->name);
        return $name !== '' ? $name : 'Atendente';
    }

    private function resolveAgentDisplayLabel(array $agent): string
    {
        $alias = trim((string)($agent['chat_display_name'] ?? ''));
        if ($alias !== '') {
            return $alias;
        }
        $name = trim((string)($agent['name'] ?? 'Usuário'));
        return $name !== '' ? $name : 'Usuário';
    }

    public function openThreads(): array
    {
        return $this->queueThreads('arrival');
    }

    public function waitingThreads(): array
    {
        return $this->queueThreads('scheduled');
    }

    public function partnerThreads(): array
    {
        return $this->queueThreads('partner');
    }

    public function threadsAssignedTo(int $userId, int $limit = 30): array
    {
        if ($userId <= 0) {
            return [];
        }

        $threads = $this->decorateThreads($this->threads->listAssignedTo($userId, $limit));
        return $this->filterOutGroupThreads($threads);
    }

    public function reminderThreads(int $fallbackHoursAhead = 24): array
    {
        $explicit = $this->queueThreads('reminder', 100);
        if ($explicit !== []) {
            return $explicit;
        }

        return $this->deriveReminderThreadsFromSchedule($fallbackHoursAhead);
    }

    private function deriveReminderThreadsFromSchedule(int $hoursAhead = 24): array
    {
        $hoursAhead = max(1, $hoursAhead);
        $now = now();
        $windowLimit = $now + ($hoursAhead * 3600);
        $lowerBound = $now - 86400;
        $scheduled = $this->waitingThreads();

        $reminders = array_filter($scheduled, static function (array $thread) use ($windowLimit, $lowerBound): bool {
            $scheduledFor = isset($thread['scheduled_for']) ? (int)$thread['scheduled_for'] : 0;
            if ($scheduledFor <= 0) {
                return false;
            }

            if ($scheduledFor > $windowLimit) {
                return false;
            }

            return $scheduledFor >= $lowerBound;
        });

        return array_values($reminders);
    }

    public function completedThreads(int $limit = 120): array
    {
        $threads = $this->decorateThreads($this->threads->listByStatus(['closed'], $limit));
        return $this->filterOutGroupThreads($threads);
    }

    public function queueThreads(string $queue, int $limit = 50): array
    {
        $queue = $this->normalizeQueue($queue);
        $threads = $this->decorateThreads($this->threads->listByQueue($queue, $limit));
        return $this->filterOutGroupThreads($threads);
    }

    public function archiveInactiveThreads(int $days = 30, int $batch = 200): void
    {
        if ($this->archivedInactiveOnce) {
            return;
        }
        $this->archivedInactiveOnce = true;

        $days = max(1, $days);
        $threshold = now() - ($days * 86400);
        $ids = $this->threads->listInactiveOlderThan($threshold, $batch);
        if ($ids === []) {
            return;
        }

        $closedAt = now();
        foreach ($ids as $id) {
            if ($id > 0) {
                $this->threads->update($id, [
                    'status' => 'closed',
                    'queue' => 'concluidos',
                    'assigned_user_id' => null,
                    'closed_at' => $closedAt,
                ]);
            }
        }
    }

    public function closeThread(int $threadId, ?AuthenticatedUser $actor = null): void
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $update = [
            'status' => 'closed',
            'queue' => 'concluidos',
            'closed_at' => now(),
        ];

        $this->threads->update($threadId, $update);
    }

    public function queueThreadsForUser(AuthenticatedUser $user, string $queue, int $limit = 50): array
    {
        $this->archiveInactiveThreads();
        $permission = $this->resolveUserPermission($user);
        if ($permission['inbox_access'] === 'own_only') {
            return [];
        }

        $panelKey = $this->panelKeyForQueue($queue);
        if ($panelKey !== null) {
            $panelScope = $this->panelScopeEntry($permission, $panelKey);
            if ($panelScope['mode'] === 'none') {
                return [];
            }
        }

        $threads = $this->queueThreads($queue, $limit);

        if ($panelKey !== null) {
            return $this->filterThreadsForPermission($threads, $user, $permission, $panelKey);
        }

        return $this->filterThreadsForPermission($threads, $user, $permission);
    }

    public function groupThreadsForUser(AuthenticatedUser $user, int $limit = 50): array
    {
        $permission = $this->resolveUserPermission($user);
        $threads = $this->decorateThreads($this->threads->listGroupThreads($limit));
        return $this->filterThreadsForPermission($threads, $user, $permission, 'grupos');
    }

    public function atendimentoThreadsForUser(AuthenticatedUser $user, int $limit = 60): array
    {
        $permission = $this->resolveUserPermission($user);
        $scope = $this->panelScopeEntry($permission, 'atendimento');
        if ($scope['mode'] === 'none') {
            return [];
        }

        $threads = $this->decorateThreads($this->threads->listActiveAssigned($limit));
        return $this->filterThreadsForPermission($threads, $user, $permission, 'atendimento');
    }

    public function reminderThreadsForUser(AuthenticatedUser $user, int $fallbackHoursAhead = 24): array
    {
        $permission = $this->resolveUserPermission($user);

        return $this->filterThreadsForPermission(
            $this->reminderThreads($fallbackHoursAhead),
            $user,
            $permission,
            'lembrete'
        );
    }

    public function completedThreadsForUser(AuthenticatedUser $user, int $limit = 200): array
    {
        $permission = $this->resolveUserPermission($user);
        if (!$permission['can_view_completed']) {
            return [];
        }

        return $this->filterThreadsForPermission($this->completedThreads($limit), $user, $permission, 'concluidos');
    }

    public function searchCompletedForUser(AuthenticatedUser $user, string $search, int $limit = 80): array
    {
        $search = trim(mb_strtolower($search));
        if ($search === '') {
            return [];
        }

        $permission = $this->resolveUserPermission($user);
        if (!$permission['can_view_completed']) {
            return [];
        }

        $threads = $this->decorateThreads($this->threads->searchClosed($search, $limit));
        return $this->filterThreadsForPermission($threads, $user, $permission, 'concluidos');
    }

    public function completedCount(): int
    {
        $now = time();
        if (isset($this->completedCountCache['ts'], $this->completedCountCache['value'])
            && ($this->completedCountCache['ts'] >= $now - 30)
            && is_int($this->completedCountCache['value'])) {
            return $this->completedCountCache['value'];
        }

        $count = $this->threads->countClosed();
        $this->completedCountCache = ['ts' => $now, 'value' => $count];

        return $count;
    }

    public function canForward(AuthenticatedUser $user): bool
    {
        $permission = $this->resolveUserPermission($user);
        return $permission['can_forward'] || $user->isAdmin();
    }

    public function canStartManualThread(AuthenticatedUser $user): bool
    {
        $permission = $this->resolveUserPermission($user);
        return $permission['can_start_thread'] || $user->isAdmin();
    }

    private function filterThreadsForPermission(array $threads, AuthenticatedUser $user, array $permission, ?string $panel = null): array
    {
        if ($panel !== null) {
            return $this->filterThreadsByPanelScope($threads, $user, $permission, $panel);
        }

        $scope = $permission['view_scope'] ?? 'all';
        if ($scope === 'all') {
            return $threads;
        }

        $allowed = [$user->id];
        if ($scope === 'selected') {
            $allowed = array_values(array_unique(array_merge($allowed, $permission['view_scope_users'] ?? [])));
        }

        $allowedLookup = array_fill_keys($allowed, true);

        return array_values(array_filter($threads, static function (array $thread) use ($scope, $allowedLookup, $user): bool {
            $assigned = (int)($thread['assigned_user_id'] ?? 0);
            if (isset($allowedLookup[$assigned])) {
                return true;
            }

            if ($scope === 'own_or_assigned') {
                $responsible = (int)($thread['responsible_user_id'] ?? 0);
                if (isset($allowedLookup[$responsible])) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function filterThreadsByPanelScope(array $threads, AuthenticatedUser $user, array $permission, string $panel): array
    {
        $scope = $this->panelScopeEntry($permission, $panel);
        $mode = $scope['mode'];
        if ($mode === 'none') {
            return [];
        }
        if ($mode === 'all') {
            return $threads;
        }

        $lookup = $this->panelUserLookup($scope['users'], $user->id);

        return array_values(array_filter($threads, static function (array $thread) use ($mode, $lookup, $user): bool {
            $assigned = (int)($thread['assigned_user_id'] ?? 0);
            $responsible = (int)($thread['responsible_user_id'] ?? 0);

            if ($mode === 'own') {
                return $assigned === $user->id;
            }
            if ($mode === 'own_or_assigned') {
                return $assigned === $user->id || $responsible === $user->id;
            }
            if ($mode === 'selected') {
                if ($lookup === []) {
                    return $assigned === $user->id;
                }
                return isset($lookup[$assigned]) || isset($lookup[$responsible]);
            }

            return true;
        }));
    }

    public function threadDetails(int $threadId): ?array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return null;
        }

        $decorated = $this->decorateThreads([$thread]);
        $thread = $decorated[0];

        $isGroupThread = ($thread['chat_type'] ?? '') === 'group';
        $groupThreads = [];
        if ($isGroupThread) {
            $groupThreads = $this->threads->findGroupThreadsByChannelOrSubject(
                (string)($thread['channel_thread_id'] ?? ''),
                (string)($thread['group_subject'] ?? ''),
                (string)($thread['contact_phone'] ?? '')
            );
            if ($groupThreads !== []) {
                $groupThreads = $this->decorateThreads($groupThreads);
                $found = false;
                foreach ($groupThreads as $decoratedThread) {
                    if ((int)($decoratedThread['id'] ?? 0) === $threadId) {
                        $thread = $decoratedThread;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $groupThreads[] = $thread;
                }
            }
        }

        $contact = $this->contacts->find((int)$thread['contact_id']);
        if ($contact === null) {
            return null;
        }

        $snapshot = $this->extractContactSnapshot($contact);
        if ($snapshot !== null) {
            $contact['gateway_snapshot'] = $snapshot;
        }

        $clientSummary = $thread['contact_client'] ?? null;
        if (is_array($clientSummary)) {
            $contact['client_summary'] = $clientSummary;
            if (!empty($clientSummary['id'])) {
                $contact['client_id'] = (int)$clientSummary['id'];
            }
            if (!empty($clientSummary['name'])) {
                $contact['name'] = $clientSummary['name'];
            }
        }

        $threadsForMessages = $isGroupThread && $groupThreads !== [] ? $groupThreads : [$thread];
        $messages = [];
        foreach ($threadsForMessages as $threadItem) {
            $targetId = (int)($threadItem['id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            $threadMessages = $this->messages->listForThread($targetId, 100);
            $threadMessages = array_values(array_map(function (array $row) use ($targetId): array {
                $messageId = (int)($row['id'] ?? 0);
                $row['metadata'] = $this->sanitizeMessageMetadata($row['metadata'] ?? null, $messageId);
                $row['source_thread_id'] = $targetId;
                return $row;
            }, $threadMessages));
            $messages = array_merge($messages, $threadMessages);
            $this->threads->markAsRead($targetId);
        }
        usort($messages, static function (array $a, array $b): int {
            $aSent = (int)($a['sent_at'] ?? $a['created_at'] ?? 0);
            $bSent = (int)($b['sent_at'] ?? $b['created_at'] ?? 0);
            return $aSent <=> $bSent;
        });

        $thread['unread_count'] = 0;

        return [
            'thread' => $thread,
            'contact' => $contact,
            'messages' => $messages,
            'group_threads' => $isGroupThread ? $threadsForMessages : [],
        ];
    }

    public function pollThreadMessages(int $threadId, int $afterMessageId = 0, bool $markAsRead = true): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $contact = $this->contacts->find((int)$thread['contact_id']);
        if ($contact !== null && !empty($contact['metadata']) && is_string($contact['metadata'])) {
            $decodedMeta = json_decode((string)$contact['metadata'], true);
            if (is_array($decodedMeta)) {
                $contact['metadata'] = $decodedMeta;
            }
        }

        if ($contact !== null) {
            $snapshot = $this->extractContactSnapshot($contact);
            if ($snapshot !== null) {
                $contact['gateway_snapshot'] = $snapshot;
            }
        }

        $isGroupThread = ($thread['chat_type'] ?? '') === 'group';
        $groupThreads = [];
        if ($isGroupThread) {
            $groupThreads = $this->threads->findGroupThreadsByChannelOrSubject(
                (string)($thread['channel_thread_id'] ?? ''),
                (string)($thread['group_subject'] ?? ''),
                (string)($thread['contact_phone'] ?? '')
            );
        }
        $threadsForMessages = $isGroupThread && $groupThreads !== [] ? $groupThreads : [$thread];

        $after = max(0, $afterMessageId);
        $formatted = [];
        $lastId = $after;

        foreach ($threadsForMessages as $threadItem) {
            $targetId = (int)($threadItem['id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            $rows = $this->messages->listAfterId($targetId, $after, 100);
            foreach ($rows as $row) {
                $row['source_thread_id'] = $targetId;
                $message = $this->formatMessageForClient($row);
                if ($message !== null) {
                    $formatted[] = $message;
                }
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId > $lastId) {
                    $lastId = $rowId;
                }
            }
            if ($markAsRead) {
                $this->threads->markAsRead($targetId);
            }
        }

        usort($formatted, static function (array $a, array $b): int {
            $aSent = (int)($a['sent_at'] ?? $a['created_at'] ?? 0);
            $bSent = (int)($b['sent_at'] ?? $b['created_at'] ?? 0);
            return $aSent <=> $bSent;
        });

        if ($markAsRead) {
            $thread['unread_count'] = 0;
        }

        return [
            'thread' => [
                'id' => (int)$thread['id'],
                'unread_count' => (int)($thread['unread_count'] ?? 0),
            ],
            'contact' => $contact,
            'messages' => $formatted,
            'last_message_id' => $lastId,
        ];
    }

    public function loadOlderMessages(int $threadId, int $beforeMessageId, int $limit = 50): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $before = max(0, $beforeMessageId);
        $rows = $this->messages->listBeforeId($threadId, $before, max(1, $limit));
        $formatted = [];
        $minId = $before;

        foreach ($rows as $row) {
            $message = $this->formatMessageForClient($row);
            if ($message !== null) {
                $formatted[] = $message;
            }
            $rowId = (int)($row['id'] ?? 0);
            if ($minId === 0 || ($rowId > 0 && $rowId < $minId)) {
                $minId = $rowId;
            }
        }

        $hasMore = count($rows) >= max(1, $limit);

        return [
            'messages' => $formatted,
            'before_id_next' => $minId > 0 ? $minId : null,
            'has_more' => $hasMore,
        ];
    }

    public function findThread(int $threadId): ?array
    {
        if ($threadId <= 0) {
            return null;
        }

        return $this->threads->find($threadId);
    }

    public function sendMessage(int $threadId, string $body, AuthenticatedUser $actor, ?UploadedFile $mediaFile = null, ?array $templateSelection = null, ?array $extraMetadata = null): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $contact = $this->contacts->find((int)$thread['contact_id']);
        if ($contact === null) {
            throw new RuntimeException('Contato não encontrado para a conversa.');
        }

        if ($this->isBlockedNumber((string)$contact['phone'])) {
            throw new RuntimeException('Número bloqueado para envio.');
        }

        $messageTextRaw = trim($body);
        $mediaUpload = null;

        if ($mediaFile instanceof UploadedFile) {
            $mediaUpload = $this->processUploadedMedia($mediaFile);
        } elseif ($templateSelection !== null) {
            $mediaUpload = $this->loadTemplateMedia($templateSelection, $thread);
        }

        if ($mediaUpload === null && $messageTextRaw === '') {
            throw new RuntimeException('Escreva uma mensagem ou selecione um modelo antes de enviar.');
        }

        $messageText = $messageTextRaw !== '' ? $this->decorateOutgoingBody($messageTextRaw, $actor) : '';

        // Anti-duplicação adicional: evita gravar/enviar duas saídas idênticas em janela curta (duplo clique).
        $recentOutgoing = $this->messages->listForThread($threadId, 3);
        $dedupeWindow = 10;
        foreach ($recentOutgoing as $recent) {
            if ((string)($recent['direction'] ?? '') !== 'outgoing') {
                continue;
            }
            $recentContent = trim((string)($recent['content'] ?? ''));
            $recentSentAt = isset($recent['sent_at']) ? (int)$recent['sent_at'] : 0;
            $recentMeta = is_string($recent['metadata'] ?? null) ? json_decode((string)$recent['metadata'], true) : (array)($recent['metadata'] ?? []);
            $recentActor = (int)($recentMeta['actor_id'] ?? 0);
            if ($recentContent === $messageText && $recentActor === $actor->id && $recentSentAt > 0 && (time() - $recentSentAt) <= $dedupeWindow) {
                return [
                    'message_id' => (int)($recent['id'] ?? 0),
                    'status' => (string)($recent['status'] ?? 'queued'),
                    'message' => $this->formatMessageForClient($recent),
                ];
            }
        }

        $useAltGateway = $this->shouldUseAltGateway($thread);
        $line = $useAltGateway ? null : $this->resolveLineForThread($thread, null);
        $resolvedGateway = $useAltGateway ? $this->extractAltGatewaySlugFromThread($thread) : null;

        if ($useAltGateway) {
            $dispatchResult = $this->dispatchAltGatewayMessage(
                (string)$contact['phone'],
                $messageText,
                $thread,
                $mediaUpload['dispatch'] ?? null
            );

            $thread = $dispatchResult['thread'] ?? $thread;
            $resolvedGateway = $dispatchResult['gateway_instance'] ?? $this->extractAltGatewaySlugFromThread($thread);
            $metaDispatch = [
                'status' => $dispatchResult['status'] ?? 'error',
                'meta_message_id' => $dispatchResult['meta_message_id'] ?? null,
                'response' => $dispatchResult['response'] ?? null,
                'error' => $dispatchResult['error'] ?? null,
                'attempted' => $dispatchResult['attempted'] ?? null,
                'http_status' => $dispatchResult['http_status'] ?? null,
                'raw_response' => $dispatchResult['raw_response'] ?? null,
            ];
        } elseif ($line === null) {
            $metaDispatch = ['status' => 'queued', 'error' => 'missing_line'];
        } elseif ($mediaUpload !== null) {
            throw new RuntimeException('Envio de mídia está disponível apenas pelo gateway alternativo neste ambiente de testes.');
        } else {
            $this->enforceLineRateLimit($line, $thread);
            $metaDispatch = $this->dispatchMetaMessage($line, (string)$contact['phone'], $messageText);
        }
        $status = $metaDispatch['status'] ?? 'queued';

        if ($status === 'error') {
            AlertService::push('whatsapp.send', 'Falha ao enviar mensagem WhatsApp', [
                'thread_id' => $threadId,
                'contact_id' => $contact['id'] ?? null,
                'line_id' => $line['id'] ?? null,
                'error' => $metaDispatch['error'] ?? null,
            ]);
        }

        $timestamp = now();

        $mediaMeta = $mediaUpload['storage'] ?? null;
        $messageType = $mediaMeta['type'] ?? 'text';
        $messageContent = $messageText !== '' ? $messageText : sprintf('[%s]', strtoupper($messageType));

        $actorDisplayName = $this->resolveUserDisplayName($actor);

        $metadataPayload = [
            'actor_id' => $actor->id,
            'actor_name' => $actorDisplayName,
            'actor_identifier' => $actor->chatIdentifier,
            'line_id' => $line['id'] ?? null,
            'gateway_instance' => $useAltGateway ? $resolvedGateway : null,
            'response' => $metaDispatch['response'] ?? null,
            'error' => $metaDispatch['error'] ?? null,
            'http_status' => $metaDispatch['http_status'] ?? null,
            'raw_response' => $metaDispatch['raw_response'] ?? null,
        ];

        if (is_array($extraMetadata) && $extraMetadata !== []) {
            $metadataPayload = array_merge($metadataPayload, $extraMetadata);
        }

        if ($mediaMeta !== null) {
            $metadataPayload['media'] = $mediaMeta;
        }

        $metaMessageId = trim((string)($metaDispatch['meta_message_id'] ?? ''));
        if ($metaMessageId !== '') {
            $existingByMeta = $this->messages->findByMetaMessageId($metaMessageId);
            if ($existingByMeta !== null) {
                $mergedMetadata = $this->mergeMessageMetadata($existingByMeta['metadata'] ?? null, $metadataPayload);
                $resolvedStatus = $this->prioritizeMessageStatus((string)($existingByMeta['status'] ?? 'sent'), $status);

                $this->messages->updateOutgoingMessage((int)$existingByMeta['id'], [
                    'meta_message_id' => $metaMessageId,
                    'status' => $resolvedStatus,
                    'metadata' => $mergedMetadata,
                ]);

                $this->threads->update($threadId, [
                    'last_message_preview' => mb_substr($messageText !== '' ? $messageText : $messageContent, 0, 160),
                    'last_message_at' => $timestamp,
                ]);
                $this->threads->markAsRead($threadId);
                $this->contacts->touchInteraction((int)$contact['id'], $timestamp);

                $updated = $this->messages->find((int)$existingByMeta['id']);

                $this->logOutgoingBackup($contact, $line, $thread, $messageContent, $messageType, $metaDispatch, (int)$existingByMeta['id'], $threadId, $timestamp, $actor);

                return [
                    'message_id' => (int)$existingByMeta['id'],
                    'status' => $resolvedStatus,
                    'message' => $this->formatMessageForClient($updated ?? $existingByMeta),
                ];
            }
        }

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'direction' => 'outgoing',
            'message_type' => $messageType,
            'content' => $messageContent,
            'status' => $status,
            'meta_message_id' => $metaDispatch['meta_message_id'] ?? null,
            'metadata' => json_encode($metadataPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $timestamp,
        ]);

        $this->messages->pruneToLatest($threadId, self::MESSAGE_RETAIN_LIMIT);

        $update = [
            'last_message_preview' => mb_substr($messageText !== '' ? $messageText : $messageContent, 0, 160),
            'last_message_at' => $timestamp,
        ];

        if (($thread['line_id'] ?? null) === null && isset($line['id'])) {
            $update['line_id'] = (int)$line['id'];
        }

        $this->threads->update($threadId, $update);
        $this->threads->markAsRead($threadId);
        $this->contacts->touchInteraction((int)$contact['id'], $timestamp);

        $messageRow = $this->messages->find($messageId);

        $this->logOutgoingBackup($contact, $line, $thread, $messageContent, $messageType, $metaDispatch, $messageId, $threadId, $timestamp, $actor);

        return [
            'message_id' => $messageId,
            'status' => $status,
            'meta' => $metaDispatch,
            'message' => $this->formatMessageForClient($messageRow),
        ];
    }

    private function logOutgoingBackup(array $contact, ?array $line, ?array $thread, string $content, string $messageType, array $metaDispatch, int $messageId, int $threadId, int $timestamp, AuthenticatedUser $actor): void
    {
        try {
            $this->backupGatewayOutgoing([
                'direction' => 'outgoing',
                'phone' => digits_only((string)($contact['phone'] ?? '')),
                'message' => $content,
                'contact_name' => $contact['name'] ?? null,
                'timestamp' => $timestamp,
                'line_label' => $line['label'] ?? ($line['name'] ?? null),
                'instance' => $metaDispatch['gateway_instance'] ?? ($line['provider'] ?? null),
                'message_type' => $messageType,
                'meta_message_id' => $metaDispatch['meta_message_id'] ?? null,
                'meta' => array_merge($metaDispatch, [
                    'thread_id' => $threadId,
                    'message_id' => $messageId,
                    'actor_id' => $actor->id,
                ]),
                'raw' => [
                    'thread' => $thread,
                    'line' => $line,
                    'contact' => [
                        'id' => $contact['id'] ?? null,
                        'client_id' => $contact['client_id'] ?? null,
                    ],
                ],
            ]);
        } catch (Throwable $exception) {
            AlertService::push('whatsapp.gateway_backup', 'Falha ao salvar backup de saída do WhatsApp.', [
                'error' => $exception->getMessage(),
                'thread_id' => $threadId,
                'message_id' => $messageId,
            ]);
        }
    }

    public function startManualConversation(array $input, AuthenticatedUser $actor, bool $enforceDailyLimit = true, bool $failOnGatewayError = false, ?int $blockWindowHours = null): array
    {
        $name = trim((string)($input['contact_name'] ?? $input['name'] ?? ''));
        $phone = digits_only((string)($input['contact_phone'] ?? $input['phone'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        $targetQueue = $this->normalizeQueue((string)($input['initial_queue'] ?? $input['queue'] ?? 'arrival'));
        $campaignKind = mb_strtolower(trim((string)($input['campaign_kind'] ?? '')));
        $campaignToken = trim((string)($input['campaign_token'] ?? ''));

        if ($phone === '' || strlen($phone) < 8) {
            throw new RuntimeException('Informe um telefone válido.');
        }

        if ($message === '') {
            throw new RuntimeException('Escreva a mensagem inicial antes de enviar.');
        }

        if ($name === '') {
            $name = 'Contato WhatsApp';
        }

        $contact = $this->contacts->findOrCreate($phone, [
            'name' => $name,
            'metadata' => json_encode(['source' => 'manual_start'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_interaction_at' => now(),
        ]);

        $campaignKey = $this->buildCampaignKey($campaignKind, $campaignToken);

        $windowHours = $blockWindowHours !== null ? max(0.01, min(240, (float)$blockWindowHours)) : $this->altPerNumberWindowHours;
        $perNumberCount = $this->messages->countOutgoingAltByPhoneSince($phone, time() - (int)round($windowHours * 3600));
        $perCampaignCount = null;
        if ($campaignKey !== null && $this->altPerCampaignWindowHours !== null) {
            $perCampaignCount = $this->messages->countOutgoingByCampaignSince(
                $campaignKey,
                time() - (int)round($this->altPerCampaignWindowHours * 3600)
            );
        }

        AlertService::push('whatsapp.manual_precheck', 'Pré-checagem de limites antes do envio manual.', [
            'phone' => $phone,
            'campaign_key' => $campaignKey,
            'campaign_kind' => $campaignKind,
            'per_number_window_h' => $windowHours,
            'per_number_count' => $perNumberCount,
            'per_campaign_window_h' => $this->altPerCampaignWindowHours,
            'per_campaign_count' => $perCampaignCount,
        ]);

        $instanceSlug = $this->sanitizeAltGatewaySlug($input['gateway_instance'] ?? null) ?? $this->defaultAltGatewaySlug();
        $instance = $instanceSlug !== null ? $this->altGatewayInstance($instanceSlug) : null;
        if ($instance === null) {
            throw new RuntimeException('Nenhum gateway alternativo habilitado.');
        }

        AlertService::push('whatsapp.manual_instance', 'Gateway selecionado para envio manual.', [
            'phone' => $phone,
            'instance_slug' => $instanceSlug,
            'instance_base_url' => $instance['base_url'] ?? null,
            'actor_id' => $actor->id,
        ]);

        $channelId = $this->buildAltChannelId($phone, $instanceSlug);
        $thread = $this->threads->findByChannelId($channelId);

        if ($thread === null) {
            $legacyChannel = 'alt:' . digits_only($phone);
            $thread = $this->threads->findByChannelId($legacyChannel);
        }

        if ($thread === null) {
            try {
                $threadId = $this->threads->create([
                    'contact_id' => (int)$contact['id'],
                    'status' => 'open',
                    'queue' => $targetQueue,
                    'channel_thread_id' => $channelId,
                    'line_id' => null,
                    'unread_count' => 0,
                ]);
                $thread = $this->threads->find($threadId);
            } catch (PDOException $e) {
                // Se outra execução criou o thread entre o find e o create, reabra o existente.
                $thread = $this->threads->findByChannelId($channelId);
                if ($thread === null) {
                    throw $e;
                }
            }
        } else {
            $this->threads->update((int)$thread['id'], [
                'status' => 'open',
                'queue' => $targetQueue,
                'closed_at' => null,
                'channel_thread_id' => $channelId,
            ]);
            $thread['channel_thread_id'] = $channelId;
        }

        if ($thread === null) {
            throw new RuntimeException('Falha ao preparar a conversa manual.');
        }

        if (!$this->shouldUseAltGateway($thread)) {
            throw new RuntimeException('Configure o gateway alternativo antes de iniciar conversas manuais.');
        }

        if ($enforceDailyLimit) {
            $this->enforceAltWindowLimit($phone, $blockWindowHours);
        }

        if ($campaignKey !== null) {
            $customMessage = $campaignKind === 'birthday'
                ? 'Felicitações já foram enviadas para este CPF nas últimas 24h.'
                : null;
            $this->enforcePerCampaignLimit($campaignKey, $customMessage);
        }

        // Anti-duplicação: evita duplo clique imediato (janela curta) para o mesmo contato.
        $recentMessages = $this->messages->listForThread((int)$thread['id'], 5);
        $dedupeWindow = 10;
        $decoratedMessage = $this->decorateOutgoingBody($message, $actor);
        $decoratedMessageNormalized = trim($decoratedMessage);
        foreach ($recentMessages as $recent) {
            if ((string)($recent['direction'] ?? '') !== 'outgoing') {
                continue;
            }
            $sentAt = isset($recent['sent_at']) ? (int)$recent['sent_at'] : 0;
            $content = trim((string)($recent['content'] ?? ''));
            $meta = is_string($recent['metadata'] ?? null) ? json_decode((string)$recent['metadata'], true) : (array)($recent['metadata'] ?? []);
            $recentActor = (int)($meta['actor_id'] ?? 0);
            if ($content === $decoratedMessageNormalized && $recentActor === $actor->id && $sentAt > 0 && (time() - $sentAt) <= $dedupeWindow) {
                return [
                    'thread_id' => (int)$thread['id'],
                    'message_id' => (int)($recent['id'] ?? 0),
                ];
            }
        }

        $extraMeta = $campaignKey !== null ? ['campaign_key' => $campaignKey, 'campaign_kind' => $campaignKind] : null;
        $result = $this->sendMessage((int)$thread['id'], $message, $actor, null, null, $extraMeta);

        AlertService::push('whatsapp.manual_dispatch', 'Envio manual disparado.', [
            'thread_id' => $result['thread_id'] ?? null,
            'message_id' => $result['message_id'] ?? null,
            'status' => $result['status'] ?? null,
            'gateway_instance' => $instanceSlug,
            'phone' => $phone,
            'channel_thread_id' => $channelId,
        ]);

        AlertService::push('whatsapp.manual_sent', 'Mensagem manual enviada via gateway alternativo.', [
            'thread_id' => $result['thread_id'] ?? null,
            'message_id' => $result['message_id'] ?? null,
            'status' => $result['status'] ?? null,
            'campaign_key' => $campaignKey,
            'gateway_instance' => $instanceSlug,
        ]);

        if ($failOnGatewayError && ($result['status'] ?? null) === 'error') {
            $error = 'Falha ao enviar mensagem.';
            $messageRow = $this->messages->find((int)($result['message_id'] ?? 0));
            $meta = is_string($messageRow['metadata'] ?? null) ? json_decode((string)$messageRow['metadata'], true) : (array)($messageRow['metadata'] ?? []);
            if (is_array($meta) && ($meta['error'] ?? '') !== '') {
                $error = (string)$meta['error'];
                $status = $meta['http_status'] ?? null;
                $raw = $meta['raw_response'] ?? null;
                if ($status) {
                    $error .= ' (HTTP ' . $status . ')';
                }
                if (is_string($raw) && $raw !== '') {
                    $snippet = mb_substr($raw, 0, 200);
                    $error .= ' | resp: ' . $snippet;
                }
            }

            throw new RuntimeException($error);
        }

        return [
            'thread_id' => (int)$thread['id'],
            'message_id' => $result['message_id'],
            'status' => $result['status'] ?? null,
        ];
    }

    public function addInternalNote(int $threadId, string $note, AuthenticatedUser $actor, array $mentions = []): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa nÆo encontrada.');
        }

        $body = trim($note);
        if ($body === '') {
            throw new RuntimeException('Escreva a anota‡Æo antes de salvar.');
        }

        $mentionRecords = [];
        foreach ($mentions as $mentionId) {
            $id = (int)$mentionId;
            if ($id <= 0 || $id === $actor->id) {
                continue;
            }
            $user = $this->users->find($id);
            if ($user === null) {
                continue;
            }
            $mentionRecords[$id] = [
                'id' => $id,
                'name' => $user['name'] ?? ('Usuario #' . $id),
            ];
        }

        $metadata = [
            'actor_id' => $actor->id,
            'actor_name' => $this->resolveUserDisplayName($actor),
             'actor_identifier' => $actor->chatIdentifier,
            'mentions' => array_values($mentionRecords),
            'created_at' => now(),
        ];

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'direction' => 'internal',
            'message_type' => 'note',
            'content' => $body,
            'status' => 'saved',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->messages->pruneToLatest($threadId, self::MESSAGE_RETAIN_LIMIT);

        $preview = '[Nota interna] ' . mb_substr($body, 0, 120);
        $this->threads->update($threadId, [
            'last_message_preview' => $preview,
            'last_message_at' => now(),
        ]);

        return [
            'id' => $messageId,
            'thread_id' => $threadId,
            'direction' => 'internal',
            'content' => $body,
            'metadata' => $metadata,
        ];
    }

    public function handleWebhookPayload(array $payload): int
    {
        $processed = 0;
        $entries = $payload['entry'] ?? [];
        if (!is_array($entries)) {
            return 0;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (!is_array($message)) {
                        continue;
                    }

                    $from = (string)($message['from'] ?? '');
                    $text = (string)($message['text']['body'] ?? '');
                    if ($from === '' || $text === '') {
                        continue;
                    }

                    $metadata = [
                        'meta_message_id' => $message['id'] ?? null,
                        'timestamp' => isset($message['timestamp']) ? (int)$message['timestamp'] : null,
                        'profile' => $value['contacts'][0]['profile']['name'] ?? null,
                        'profile_photo' => $value['contacts'][0]['profile']['photo'] ?? ($value['contacts'][0]['profile']['picture'] ?? null),
                        'line' => $this->lineFromWebhookValue($value),
                    ];

                    $line = is_array($metadata['line'] ?? null) ? $metadata['line'] : null;
                    try {
                        $this->backupGatewayIncoming([
                            'phone' => digits_only($from),
                            'direction' => 'incoming',
                            'message' => $text,
                            'contact_name' => $metadata['profile'] ?? null,
                            'timestamp' => $metadata['timestamp'] ?? null,
                            'line_label' => $line['label'] ?? ($line['name'] ?? null),
                            'instance' => $line['provider'] ?? null,
                            'meta' => $metadata,
                            'raw' => $message,
                        ]);
                    } catch (Throwable $exception) {
                        AlertService::push('whatsapp.gateway_backup', 'Falha ao salvar backup de entrada do webhook.', [
                            'error' => $exception->getMessage(),
                            'phone' => $from,
                        ]);
                    }

                    $this->registerIncomingMessage($from, $text, $metadata);
                    $processed++;
                }
            }
        }

        return $processed;
    }

    public function simulateIncomingMessage(int $lineId, string $phone, string $message, array $meta = []): array
    {
        $line = $this->lines->find($lineId);
        if ($line === null) {
            throw new RuntimeException('Linha não encontrada.');
        }
        if (($line['provider'] ?? 'meta') !== 'sandbox') {
            throw new RuntimeException('Esta linha não está configurada como sandbox.');
        }

        $phone = trim($phone);
        if ($phone === '') {
            throw new RuntimeException('Informe o telefone do cliente.');
        }

        $text = trim($message);
        if ($text === '') {
            throw new RuntimeException('Escreva a mensagem recebida.');
        }

        $metadata = [
            'line' => $line,
            'profile' => $meta['contact_name'] ?? null,
            'timestamp' => now(),
            'meta_message_id' => 'sandbox-' . bin2hex(random_bytes(4)),
        ];

        $threadId = $this->registerIncomingMessage($phone, $text, $metadata);
        if ($threadId === null) {
            throw new RuntimeException('Não foi possível registrar a mensagem.');
        }

        return [
            'thread_id' => $threadId,
            'thread' => $this->threads->find($threadId),
            'thread_card' => $this->threadCard($threadId),
        ];
    }

    public function ingestLogEntry(array $entry, array $options = []): array
    {
        $direction = strtolower(trim((string)($entry['direction'] ?? '')));
        if ($direction === 'in' || $direction === 'entrada') {
            $direction = 'incoming';
        } elseif (in_array($direction, ['out', 'saida', 'saída'], true)) {
            $direction = 'outgoing';
        }

        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new RuntimeException('Dire‡Æo inv lida no arquivo de log.');
        }

        $phone = trim((string)($entry['phone'] ?? $entry['contact_phone'] ?? ''));
        if ($phone === '') {
            throw new RuntimeException('Telefone nÆo informado no registro.');
        }

        $body = trim((string)($entry['message'] ?? $entry['content'] ?? ''));
        $mediaPayload = $entry['media'] ?? null;
        if (!is_array($mediaPayload)) {
            $mediaPayload = null;
        }
        $mediaMeta = $mediaPayload ? $this->handleIncomingMediaPayload($mediaPayload) : null;
        if ($body === '' && $mediaMeta !== null) {
            $label = strtoupper((string)($mediaMeta['type'] ?? 'MIDIA'));
            $body = '[' . $label . ']';
        }
        if ($body === '') {
            throw new RuntimeException('Mensagem vazia no registro.');
        }

        $contactName = trim((string)($entry['contact_name'] ?? $entry['name'] ?? ''));
        $line = $this->resolveLineFromEntry($entry);
        if ($line === null) {
            $hasLineId = ((int)($entry['line_id'] ?? 0)) > 0;
            $hasLineLabel = trim((string)($entry['line_label'] ?? $entry['line'] ?? '')) !== '';
            if ($hasLineId || $hasLineLabel) {
                throw new RuntimeException('Linha indicada no arquivo nÆo foi encontrada nas configura‡äes.');
            }
        }

        $timestamp = $this->parseTimestamp($entry['timestamp'] ?? $entry['sent_at'] ?? null);
        $markRead = array_key_exists('mark_read', $options) ? (bool)$options['mark_read'] : true;
        $extras = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
        $messageType = $mediaMeta['type'] ?? (is_string($entry['message_type'] ?? null) ? strtolower((string)$entry['message_type']) : 'text');

        $meta = [
            'contact_name' => $contactName,
            'line' => $line,
            'timestamp' => $timestamp,
            'meta_message_id' => $entry['external_id'] ?? $entry['message_id'] ?? null,
            'metadata' => $extras,
            'media' => $mediaMeta,
            'message_type' => $messageType,
            'profile_photo' => $this->resolveProfilePhoto($entry, $extras),
        ];

        if ($meta['meta_message_id'] === null) {
            $meta['meta_message_id'] = $extras['meta_message_id'] ?? $extras['message_id'] ?? null;
        }

        if ($direction === 'incoming') {
            $threadId = $this->registerIncomingMessage($phone, $body, [
                'line' => $line,
                'profile' => $contactName,
                'timestamp' => $timestamp,
                'meta_message_id' => $meta['meta_message_id'],
                'source' => 'log_import',
                'gateway_metadata' => $extras,
                'media' => $mediaMeta,
                'message_type' => $messageType,
            ]);

            if ($threadId === null) {
                throw new RuntimeException('Falha ao registrar a mensagem recebida.');
            }

            if ($threadId === 0) {
                return [
                    'thread_id' => null,
                    'message_id' => null,
                    'direction' => 'incoming',
                    'status' => 'blocked',
                ];
            }

            if ($markRead) {
                $this->threads->markAsRead($threadId);
            }

            return [
                'thread_id' => $threadId,
                'message_id' => null,
                'direction' => 'incoming',
                'status' => 'delivered',
            ];
        }

        $metaMessageId = (string)($meta['meta_message_id'] ?? '');
        $isHistory = (bool)($extras['history'] ?? false);
        if ($metaMessageId !== '' && !$isHistory) {
            $existing = $this->messages->findByMetaMessageId($metaMessageId);
            $ackValue = $this->coerceAckValue($extras['ack'] ?? null);
            if ($existing !== null && $ackValue !== null) {
                $update = $this->applyAckStatusUpdate($existing, $ackValue, $meta);
                return [
                    'thread_id' => $update['thread_id'],
                    'message_id' => $update['message_id'],
                    'direction' => 'outgoing',
                    'status' => $update['status'],
                ];
            }
        }

        $result = $this->registerOutgoingLogMessage($phone, $body, $meta, $markRead);

        return [
            'thread_id' => $result['thread_id'],
            'message_id' => $result['message_id'],
            'direction' => 'outgoing',
            'status' => 'imported',
        ];
    }

    public function restoreGatewayBackup(string $archivePath): array
    {
        $entries = $this->gatewayBackup->loadArchiveEntries($archivePath);
        $stats = [
            'total' => count($entries),
            'imported' => 0,
            'errors' => [],
        ];

        foreach ($entries as $entry) {
            try {
                $this->ingestLogEntry($entry, ['mark_read' => false]);
                $stats['imported']++;
            } catch (RuntimeException $exception) {
                $stats['errors'][] = [
                    'meta_message_id' => $entry['metadata']['meta_message_id'] ?? $entry['metadata']['message_id'] ?? null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $stats;
    }

    /** @param array<string,mixed> $payload */
    public function backupGatewayIncoming(array $payload): void
    {
        try {
            $meta = $payload['meta'] ?? [];
            if (!is_array($meta)) {
                $meta = [];
            }
            if (!isset($meta['timestamp'])) {
                $meta['timestamp'] = time();
            }
            $payload['meta'] = $meta;

            $this->gatewayBackup->backupIncoming($payload);
        } catch (Throwable $exception) {
            AlertService::push('whatsapp.gateway_backup', 'Falha ao salvar backup de entrada do gateway.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    public function backupGatewayOutgoing(array $payload): void
    {
        try {
            $meta = $payload['meta'] ?? [];
            if (!is_array($meta)) {
                $meta = [];
            }
            if (!isset($meta['timestamp'])) {
                $meta['timestamp'] = time();
            }
            $payload['meta'] = $meta;

            $this->gatewayBackup->backupOutgoing($payload);
        } catch (Throwable $exception) {
            AlertService::push('whatsapp.gateway_backup', 'Falha ao salvar backup de saída do gateway.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function gatewayBackupSummary(): array
    {
        try {
            return $this->gatewayBackup->summarize();
        } catch (Throwable $exception) {
            return [
                'date' => date('Y-m-d'),
                'total' => 0,
                'lines' => [],
                'errors' => ['Falha ao ler backups: ' . $exception->getMessage()],
            ];
        }
    }

    public function refreshContactProfilePhotos(int $limit = 50, int $maxAgeSeconds = 604800): array
    {
        $limit = max(1, $limit);
        $maxAgeSeconds = max(60, $maxAgeSeconds);
        $threshold = time() - $maxAgeSeconds;

        $candidates = $this->contacts->listForSnapshotRefresh($threshold, $limit);
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($candidates as $contact) {
            $contactId = (int)($contact['id'] ?? 0);
            if ($contactId <= 0) {
                $skipped++;
                continue;
            }

            $meta = [];
            if (!empty($contact['metadata']) && is_string($contact['metadata'])) {
                $decoded = json_decode((string)$contact['metadata'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $snapshot = isset($meta['gateway_snapshot']) && is_array($meta['gateway_snapshot']) ? $meta['gateway_snapshot'] : null;
            if ($snapshot === null) {
                $skipped++;
                continue;
            }

            $photoUrl = isset($snapshot['profile_photo']) && is_string($snapshot['profile_photo']) ? trim($snapshot['profile_photo']) : '';
            if ($photoUrl === '' || !(str_starts_with($photoUrl, 'http://') || str_starts_with($photoUrl, 'https://'))) {
                $skipped++;
                continue;
            }

            $ctx = stream_context_create([
                'http' => ['timeout' => 6],
                'https' => ['timeout' => 6],
            ]);

            $binary = @file_get_contents($photoUrl, false, $ctx);
            if ($binary === false) {
                $errors[] = ['contact_id' => $contactId, 'error' => 'fetch_failed'];
                continue;
            }

            $etag = sha1($binary);
            $snapshot['profile_photo_etag'] = $etag;
            $snapshot['captured_at'] = time();
            $meta['gateway_snapshot'] = $snapshot;
            $meta['gateway_snapshot_at'] = $snapshot['captured_at'];

            if (empty($meta['profile_photo'])) {
                $meta['profile_photo'] = $snapshot['profile_photo'];
            }

            $this->contacts->update($contactId, [
                'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $updated++;
        }

        return [
            'scanned' => count($candidates),
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function registerGatewayAck(string $phone, array $meta): array
    {
        $messageId = trim((string)($meta['meta_message_id'] ?? ''));
        if ($messageId === '') {
            return [
                'message_id' => null,
                'thread_id' => null,
                'status' => null,
                'changed' => false,
                'warning' => 'ack_missing_id',
            ];
        }

        $message = $this->messages->findByMetaMessageId($messageId);
        if ($message === null) {
            return [
                'message_id' => null,
                'thread_id' => null,
                'status' => null,
                'changed' => false,
                'warning' => 'ack_message_not_found',
            ];
        }

        $ackValue = $this->coerceAckValue($meta['ack'] ?? null);
        if ($ackValue === null) {
            return [
                'message_id' => (int)$message['id'],
                'thread_id' => (int)$message['thread_id'],
                'status' => $message['status'],
                'changed' => false,
            ];
        }

        $update = $this->applyAckStatusUpdate($message, $ackValue, $meta);

        return [
            'message_id' => $update['message_id'],
            'thread_id' => $update['thread_id'],
            'status' => $update['status'],
            'changed' => $update['changed'],
            'warning' => null,
        ];
    }

    public function generateSuggestion(array $context, ?int $profileId = null): array
    {
        $contactName = trim((string)($context['contact_name'] ?? 'cliente'));
        $lastMessage = trim((string)($context['last_message'] ?? ''));
        $tone = (string)($context['tone'] ?? 'consultivo');
        $goal = (string)($context['goal'] ?? 'avancar na venda');
        $threadId = isset($context['thread_id']) ? (int)$context['thread_id'] : null;

        $profile = $profileId !== null ? $this->copilotProfile($profileId) : null;
        if ($profile === null) {
            $profile = $this->profiles->findDefault();
        }

        $cacheKey = sha1(implode('|', [
            $contactName,
            $lastMessage,
            $tone,
            $goal,
            (string)$threadId,
            (string)($profile['id'] ?? 'default'),
        ]));
        $now = time();
        if ($this->copilotSuggestionCache['key'] === $cacheKey
            && ($this->copilotSuggestionCache['ts'] ?? 0) >= $now - self::COPILOT_SUGGESTION_CACHE_TTL
            && $this->copilotSuggestionCache['data'] !== null) {
            return $this->copilotSuggestionCache['data'];
        }

        if ($profile !== null) {
            if (!empty($profile['tone'])) {
                $tone = (string)$profile['tone'];
            }
            if (!empty($profile['objective'])) {
                $goal = (string)$profile['objective'];
            }
        }

        $options = $this->globalOptions();
        $apiKey = trim((string)($options['copilot_api_key'] ?? ''));

        $thread = null;
        $contact = null;
        $recentMessages = [];
        if ($threadId !== null && $threadId > 0) {
            $thread = $this->threads->findWithRelations($threadId);
            if ($thread !== null) {
                $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : null;
                $contact = $contactId ? $this->contacts->find($contactId) : null;
                $recentMessages = $this->messages->listForThread($threadId, 12);
                if ($contactName === 'cliente' && !empty($thread['contact_name'])) {
                    $contactName = (string)$thread['contact_name'];
                }
                if ($lastMessage === '' && $recentMessages !== []) {
                    $incoming = $this->extractLastIncoming($recentMessages);
                    $lastMessage = trim((string)($incoming['content'] ?? ''));
                }
            }
        }

        $sentiment = $this->inferSentiment($lastMessage);
        $keywords = $this->extractKeywords($lastMessage);
        if ($thread !== null && !empty($thread['intake_summary'])) {
            $keywords = array_merge($keywords, $this->extractKeywords((string)$thread['intake_summary'], 3));
        }

        $manualChunks = $this->manuals->searchChunks($keywords, 5);
        $samples = $this->trainingSamples->recent(2, $this->guessThreadCategory($thread, $contact));
        $knowledgeStats = [
            'manuals' => count($manualChunks),
            'samples' => count($samples),
        ];

        if ($apiKey === '') {
            $fallback = $this->buildRuleBasedSuggestion($contactName, $tone, $goal, $profile, $sentiment, $knowledgeStats);
            $this->copilotSuggestionCache = ['ts' => $now, 'key' => $cacheKey, 'data' => $fallback];
            return $fallback;
        }

        $systemPrompt = $this->buildSystemPrompt($tone, $goal, $profile);
        $conversationExcerpt = $this->buildConversationExcerpt($recentMessages);
        $manualContext = $this->summarizeManualChunks($manualChunks);
        $samplesContext = $this->summarizeTrainingSamples($samples);

        $userPromptParts = [
            'Contato: ' . $contactName,
            'Objetivo atual: ' . $goal,
        ];

        if ($thread !== null) {
            $userPromptParts[] = 'Fila atual: ' . ($thread['queue'] ?? 'arrival');
        }
        if ($lastMessage !== '') {
            $userPromptParts[] = 'Ultima mensagem do cliente: ' . $lastMessage;
        }

        $prompt = implode("
", array_filter($userPromptParts));
        if ($conversationExcerpt !== '') {
            $prompt .= "

Histórico recente:
" . $conversationExcerpt;
        }
        if ($manualContext !== '') {
            $prompt .= "

Trechos dos manuais oficiais:
" . $manualContext;
        }
        if ($samplesContext !== '') {
            $prompt .= "

Exemplos reais de atendimento:
" . $samplesContext;
        }
        $prompt .= "

Produza uma única resposta curta, mantendo o tom solicitado e convidando o cliente para o próximo passo.";

        $payloadMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];

        $temperature = $profile !== null ? (float)$profile['temperature'] : 0.5;
        $response = $this->requestCopilotCompletion($apiKey, $payloadMessages, $temperature);
        if (($response['success'] ?? false) && trim((string)($response['message'] ?? '')) !== '') {
            $result = [
                'suggestion' => trim((string)$response['message']),
                'confidence' => $this->confidenceFromSignals($sentiment, $knowledgeStats),
                'sentiment' => $sentiment,
                'source' => 'copilot_llm',
                'profile' => $profile !== null ? [
                    'id' => (int)$profile['id'],
                    'name' => (string)$profile['name'],
                    'slug' => (string)$profile['slug'],
                ] : null,
                'knowledge' => $knowledgeStats,
            ];
            $this->copilotSuggestionCache = ['ts' => $now, 'key' => $cacheKey, 'data' => $result];
            return $result;
        }

        if (!empty($response['error'])) {
            AlertService::push('whatsapp.copilot', 'Copilot API indisponível', [
                'error' => $response['error'],
            ]);
        }

        $fallback = $this->buildRuleBasedSuggestion($contactName, $tone, $goal, $profile, $sentiment, $knowledgeStats);
        $this->copilotSuggestionCache = ['ts' => $now, 'key' => $cacheKey, 'data' => $fallback];

        return $fallback;
    }


    public function assignThread(int $threadId, ?int $userId): void
    {
        $this->threads->assignToUser($threadId, $userId);
    }

    public function updateThreadStatus(int $threadId, string $status): void
    {
        $allowed = ['open', 'waiting', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Status inválido.');
        }

        $threadSnapshot = null;
        if ($status === 'closed') {
            $threadSnapshot = $this->threads->findWithRelations($threadId);
        }

        $this->threads->updateStatus($threadId, $status);

        if ($status === 'closed' && $threadSnapshot !== null) {
            try {
                $this->captureTrainingSampleFromThread($threadSnapshot);
            } catch (\Throwable $exception) {
                AlertService::push('whatsapp.copilot.training', 'Falha ao registrar amostra IA', [
                    'thread_id' => $threadId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function availableAgents(?int $excludeUserId = null): array
    {
        $cacheKey = (int)($excludeUserId ?? 0);
        $now = time();
        $cache = $this->agentsCacheByExclude[$cacheKey] ?? ['ts' => 0, 'data' => []];
        if (($cache['ts'] ?? 0) >= $now - self::AGENTS_CACHE_TTL) {
            return $cache['data'];
        }

        $agents = $this->users->listActiveForChat($excludeUserId);
        foreach ($agents as $index => $agent) {
            $agents[$index]['display_label'] = $this->resolveAgentDisplayLabel($agent);
        }

        $this->agentsCacheByExclude[$cacheKey] = ['ts' => $now, 'data' => $agents];

        return $agents;
    }

    /**
     * @return array<string,int>
     */
    public function queueSummary(): array
    {
        $now = time();
        if (($this->queueSummaryCache['ts'] ?? 0) >= $now - self::QUEUE_SUMMARY_CACHE_TTL) {
            return $this->queueSummaryCache['data'];
        }

        $counts = $this->threads->countByQueue();
        $summary = [];
        foreach (self::QUEUES as $queue) {
            $summary[$queue] = $counts[$queue] ?? 0;
        }

        $this->queueSummaryCache = ['ts' => $now, 'data' => $summary];

        return $summary;
    }

    public function preTriage(int $threadId): array
    {
        $thread = $this->threads->findWithRelations($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $messages = $this->messages->listForThread($threadId, 20);
        $incoming = $this->extractLastIncoming($messages);
        $lastText = $incoming['content'] ?? ($thread['last_message_preview'] ?? '');
        $copilot = $this->generateSuggestion([
            'contact_name' => $thread['contact_name'] ?? 'cliente',
            'last_message' => $lastText,
            'tone' => 'consultivo',
            'goal' => 'agendar_validacao',
            'thread_id' => (int)$thread['id'],
        ]);

        $summary = $this->buildIntakeSummary($thread, $incoming, $copilot);
        $this->threads->update((int)$thread['id'], ['intake_summary' => $summary]);

        $suggestion = $this->suggestQueueNextStep($thread, $incoming, $copilot);

        return [
            'intake_summary' => $summary,
            'suggestion' => $suggestion,
            'copilot' => $copilot,
            'thread_card' => $this->threadCard($threadId),
        ];
    }

    public function updateQueue(int $threadId, string $queue, array $meta = []): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $queue = $this->normalizeQueue($queue);
        $currentQueue = $this->normalizeQueue((string)($thread['queue'] ?? 'arrival'));

        // Enforce exclusivity: reminder/scheduled threads only leave via atendimento (arrival) or closing.
        if (in_array($currentQueue, ['reminder', 'scheduled'], true) && !in_array($queue, [$currentQueue, 'arrival'], true)) {
            throw new RuntimeException('Este atendimento só pode sair de lembrete/agendamento indo para atendimento ou concluído.');
        }

        $update = ['queue' => $queue];

        if ($queue === 'scheduled') {
            $scheduledFor = isset($meta['scheduled_for']) ? (int)$meta['scheduled_for'] : null;
            if ($scheduledFor === null || $scheduledFor <= 0) {
                throw new RuntimeException('Informe a data/hora do agendamento.');
            }
            $update['scheduled_for'] = $scheduledFor;
            $update['status'] = 'waiting';
        } else {
            $update['scheduled_for'] = null;
        }

        if ($queue === 'partner') {
            // Apenas mover para a fila de parceiros; não exigir seleção.
            $partnerId = (int)($meta['partner_id'] ?? 0);
            $update['partner_id'] = $partnerId > 0 ? $partnerId : null;
            $update['responsible_user_id'] = null;
        } else {
            $update['partner_id'] = null;
            $update['responsible_user_id'] = null;
        }

        if (in_array($queue, ['reminder', 'partner', 'scheduled'], true)) {
            $update['assigned_user_id'] = null;
        }

        if (array_key_exists('intake_summary', $meta)) {
            $summary = trim((string)$meta['intake_summary']);
            $update['intake_summary'] = $summary !== '' ? $summary : null;
        }

        if ($queue === 'arrival' && ($thread['status'] ?? '') === 'waiting') {
            $update['status'] = 'open';
        }

        $this->threads->update($threadId, $update);

        return $this->threads->find($threadId) ?? $thread;
    }

    public function updateContactTags(int $contactId, string $rawTags): array
    {
        $contact = $this->contacts->find($contactId);
        if ($contact === null) {
            throw new RuntimeException('Contato nÆo encontrado.');
        }

        $tags = $this->sanitizeTags($rawTags);
        $stored = $tags === [] ? null : implode(',', $tags);
        $this->contacts->update($contactId, ['tags' => $stored]);

        $fresh = $this->contacts->find($contactId);
        if ($fresh !== null) {
            return $fresh;
        }

        $contact['tags'] = $stored;
        return $contact;
    }

    public function updateContactPhone(int $contactId, string $name, string $phone): array
    {
        $contact = $this->contacts->find($contactId);
        if ($contact === null) {
            throw new RuntimeException('Contato não encontrado.');
        }

        $normalizedPhone = digits_only($phone);
        if ($normalizedPhone === '' || strlen($normalizedPhone) < 8) {
            throw new RuntimeException('Informe um telefone válido.');
        }

        $payload = [
            'phone' => $normalizedPhone,
        ];

        $trimmedName = trim($name);
        if ($trimmedName !== '') {
            $payload['name'] = $trimmedName;
        }

        $this->contacts->update($contactId, $payload);

        $updated = $this->contacts->find($contactId);
        if ($updated !== null) {
            return $updated;
        }

        return array_merge($contact, $payload);
    }

    /**
     * Registra os dados mínimos de identificação de um contato sem telefone (fluxo alt).
     *
     * @param array{contact_id?:int,name?:string,phone?:string,cpf?:string,birthdate?:string,email?:string,address?:string,client_id?:int} $input
     */
    public function registerContactIdentity(int $threadId, array $input): array
    {
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('Conversa não encontrada.');
        }

        $contactId = (int)($input['contact_id'] ?? ($thread['contact_id'] ?? 0));
        if ($contactId <= 0) {
            throw new RuntimeException('Contato não encontrado.');
        }

        $contact = $this->contacts->find($contactId);
        if ($contact === null) {
            throw new RuntimeException('Contato não encontrado.');
        }

        $name = trim((string)($input['name'] ?? ''));
        $phone = digits_only((string)($input['phone'] ?? ''));
        $cpf = digits_only((string)($input['cpf'] ?? ''));

        if ($name === '' || $phone === '' || $cpf === '') {
            throw new RuntimeException('Informe nome, telefone e CPF.');
        }

        if (!$this->isLikelyPhone($phone)) {
            throw new RuntimeException('Informe um telefone válido (DDD + número).');
        }

        $birthdate = trim((string)($input['birthdate'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));

        $clientId = (int)($input['client_id'] ?? 0);
        if ($clientId <= 0 && $cpf !== '') {
            $client = $this->clients->findByDocument($cpf);
            if ($client !== null) {
                $clientId = (int)$client['id'];
            }
        }

        $metadata = [];
        $rawMetadata = $contact['metadata'] ?? null;
        if (is_string($rawMetadata)) {
            $decoded = json_decode($rawMetadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (is_array($rawMetadata)) {
            $metadata = $rawMetadata;
        }

        $metadata['cpf'] = $cpf;
        if ($birthdate !== '') {
            $metadata['birthdate'] = $birthdate;
        } else {
            unset($metadata['birthdate']);
        }
        if ($email !== '') {
            $metadata['email'] = $email;
        } else {
            unset($metadata['email']);
        }
        if ($address !== '') {
            $metadata['address'] = $address;
        } else {
            unset($metadata['address']);
        }

        $payload = [
            'name' => $name,
            'phone' => $phone,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($clientId > 0) {
            $payload['client_id'] = $clientId;
        }

        $this->contacts->update($contactId, $payload);

        $updatedContact = $this->contacts->find($contactId) ?? $contact;

        $updatedThread = $this->threads->find($threadId);
        $decoratedThread = $updatedThread !== null ? $this->decorateThreads([$updatedThread])[0] : null;

        return [
            'contact' => $updatedContact,
            'thread' => $decoratedThread,
        ];
    }

    public function clientSummary(int $clientId): array
    {
        if ($clientId <= 0) {
            throw new RuntimeException('Cliente inválido.');
        }

        $client = $this->clients->find($clientId);
        if ($client === null) {
            throw new RuntimeException('Cliente não encontrado.');
        }

        return $this->summarizeClientForThread($client);
    }

    public function allowedUserIds(): array
    {
        $allowed = $this->settings->get('whatsapp.allowed_user_ids', []);
        if (!is_array($allowed)) {
            return [];
        }

        return array_values(array_map('intval', $allowed));
    }

    public function updateAllowedUsers(array $userIds, bool $blockAvp): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $this->settings->setMany([
            'whatsapp.allowed_user_ids' => $ids,
            'whatsapp.block_avp_access' => $blockAvp,
        ]);

        return [
            'allowed' => $ids,
            'block_avp' => $blockAvp,
        ];
    }

    public function blockedNumbers(): array
    {
        $stored = $this->settings->get('whatsapp.blocked_numbers', []);
        if (!is_array($stored)) {
            return [];
        }

        $normalized = [];
        foreach ($stored as $entry) {
            $digits = digits_only((string)$entry);
            if ($digits === '' || strlen($digits) < 8) {
                continue;
            }
            $normalized[] = $digits;
        }

        return array_values(array_unique($normalized));
    }

    public function updateBlockedNumbers(array $rawNumbers): array
    {
        $normalized = [];
        foreach ($rawNumbers as $entry) {
            $digits = digits_only((string)$entry);
            if ($digits === '' || strlen($digits) < 8) {
                continue;
            }
            $normalized[] = $digits;
        }
        $normalized = array_values(array_unique($normalized));
        $this->settings->set('whatsapp.blocked_numbers', $normalized);
        return $normalized;
    }

    public function addBlockedNumber(string $phone): array
    {
        $digits = digits_only($phone);
        if ($digits === '' || strlen($digits) < 8) {
            throw new RuntimeException('Informe um telefone válido para bloqueio.');
        }

        $list = $this->blockedNumbers();
        $list[] = $digits;
        $list = array_values(array_unique($list));
        $this->settings->set('whatsapp.blocked_numbers', $list);

        return $list;
    }

    public function removeBlockedNumber(string $phone): array
    {
        $digits = digits_only($phone);
        $list = array_filter($this->blockedNumbers(), static function (string $entry) use ($digits): bool {
            return $entry !== $digits;
        });
        $list = array_values(array_unique($list));
        $this->settings->set('whatsapp.blocked_numbers', $list);
        return $list;
    }

    public function isBlockedNumber(string $phone): bool
    {
        $digits = digits_only($phone);
        if ($digits === '') {
            return false;
        }
        return in_array($digits, $this->blockedNumbers(), true);
    }

    public function blockContact(int $contactId, bool $block = true): array
    {
        $contact = $this->contacts->find($contactId);
        if ($contact === null) {
            throw new RuntimeException('Contato não encontrado.');
        }

        $phone = (string)($contact['phone'] ?? '');
        if ($phone === '') {
            throw new RuntimeException('Contato sem telefone para bloquear.');
        }

        $blockedList = $block ? $this->addBlockedNumber($phone) : $this->removeBlockedNumber($phone);

        return [
            'contact' => $contact,
            'blocked' => $block,
            'blocked_numbers' => $blockedList,
        ];
    }

    public function blockAvpEnabled(): bool
    {
        return (bool)$this->settings->get('whatsapp.block_avp_access', false);
    }

    public function globalOptions(): array
    {
        $stored = $this->settings->getMany([
            'whatsapp.copilot_api_key' => '',
            'whatsapp.block_avp_access' => false,
            'whatsapp.blocked_numbers' => [],
        ]);

        return [
            'copilot_api_key' => (string)($stored['whatsapp.copilot_api_key'] ?? ''),
            'block_avp_access' => (bool)($stored['whatsapp.block_avp_access'] ?? false),
            'blocked_numbers' => $this->blockedNumbers(),
        ];
    }

    public function saveIntegration(array $input): array
    {
        $payload = [
            'whatsapp.copilot_api_key' => trim((string)($input['copilot_api_key'] ?? '')),
        ];

        $this->settings->setMany($payload);
        return $this->globalOptions();
    }

    private function registerOutgoingLogMessage(string $phone, string $body, array $meta, bool $markRead): array
    {
        $timestamp = $meta['timestamp'] ?? now();
        $metaExtras = is_array($meta['metadata'] ?? null) ? $meta['metadata'] : [];
        $metaMessageId = trim((string)($meta['meta_message_id'] ?? ''));
        $ackValue = $this->coerceAckValue($metaExtras['ack'] ?? null);

        // Normaliza telefone para evitar salvar IDs de contato no lugar do numero.
        $mappedPhone = $this->mapAltJidToPhone($phone, $metaExtras);
        $normalizedPhone = $mappedPhone !== null ? $mappedPhone : $phone;
        if (!str_starts_with($phone, 'group:')) {
            $rawCandidates = [
                $phone,
                $metaExtras['contact_phone'] ?? null,
                $meta['contact_phone'] ?? null,
                $meta['phone'] ?? null,
                $meta['contact'] ?? null,
                $meta['from'] ?? null,
                $meta['sender'] ?? null,
                $meta['sender_id'] ?? null,
                $meta['id'] ?? null,
                $meta['channel_thread_id'] ?? null,
                $meta['contact_id'] ?? null,
                $meta['contactId'] ?? null,
                $meta['chat_id'] ?? null,
                $meta['chatId'] ?? null,
                $metaExtras['jid'] ?? null,
                $metaExtras['from'] ?? null,
                $metaExtras['sender'] ?? null,
                $metaExtras['sender_id'] ?? null,
                $metaExtras['id'] ?? null,
                $metaExtras['contact_id'] ?? null,
                $metaExtras['contactId'] ?? null,
                $metaExtras['remote_jid'] ?? null,
                $metaExtras['remoteJid'] ?? null,
                $metaExtras['remote'] ?? null,
                $metaExtras['chat_id'] ?? null,
                $metaExtras['chatId'] ?? null,
                $metaExtras['wa_id'] ?? null,
                $metaExtras['waId'] ?? null,
                $metaExtras['wid'] ?? null,
                $metaExtras['whatsapp_id'] ?? null,
            ];

            $candidates = [];
            foreach ($rawCandidates as $raw) {
                if ($raw === null || $raw === '') {
                    continue;
                }

                $mapped = $this->mapAltJidToPhone((string)$raw, $metaExtras);
                if ($mapped !== null) {
                    $raw = $mapped;
                }

                if (is_string($raw) && str_contains((string)$raw, '@lid')) {
                    continue;
                }

                $digits = digits_only((string)$raw);
                if ($digits === '') {
                    continue;
                }

                $lenDigits = strlen($digits);
                if ($lenDigits > 15) {
                    continue;
                }

                if (!str_starts_with($digits, '55') && $lenDigits >= 10 && $lenDigits <= 11) {
                    $digits = '55' . $digits;
                }

                $candidates[] = $digits;
            }

            if ($candidates !== []) {
                $unique = array_values(array_unique($candidates));
                $best = array_reduce($unique, static function ($carry, $item) {
                    if ($carry === null) {
                        return $item;
                    }

                    $lenCarry = strlen($carry);
                    $lenItem = strlen($item);

                    if (str_starts_with($item, '55') && !str_starts_with($carry, '55')) {
                        return $item;
                    }

                    if ($lenItem > $lenCarry) {
                        return $item;
                    }

                    return $carry;
                }, null);

                if ($best !== null) {
                    $normalizedPhone = $best;
                }
            }
        }
        $phone = $normalizedPhone;
        $groupThreadKey = $this->resolveGroupThreadKey($metaExtras, $meta, (string)$phone);

        if ($metaMessageId !== '') {
            $existingByMeta = $this->messages->findByMetaMessageId($metaMessageId);
            if ($existingByMeta !== null) {
                if ($ackValue !== null) {
                    $this->applyAckStatusUpdate($existingByMeta, $ackValue, $meta);
                }

                return [
                    'thread_id' => (int)$existingByMeta['thread_id'],
                    'message_id' => (int)$existingByMeta['id'],
                ];
            }
        }

        $chatMetadata = $metaExtras;
        $isGroupChat = $groupThreadKey !== null
            || str_starts_with($phone, 'group:')
            || (($chatMetadata['chat_type'] ?? '') === 'group');
        if ($isGroupChat && $groupThreadKey !== null) {
            $phone = $groupThreadKey;
        }
        $groupSubject = null;
        $groupMetadata = null;
        if ($isGroupChat) {
            $groupSubject = trim((string)($chatMetadata['group_subject'] ?? $meta['contact_name'] ?? 'Grupo WhatsApp'));
            $participant = $chatMetadata['group_participant'] ?? null;
            if (!is_array($participant)) {
                $participant = [];
            }
            $groupMetaPayload = array_filter([
                'chat_id' => $chatMetadata['group_jid'] ?? $chatMetadata['chat_id'] ?? null,
                'subject' => $groupSubject,
                'participant' => array_filter([
                    'name' => $participant['name'] ?? null,
                    'phone' => isset($participant['phone']) ? digits_only((string)$participant['phone']) : null,
                ], static fn($value) => $value !== null && $value !== ''),
            ], static fn($value) => $value !== null && $value !== '' && $value !== []);
            if ($groupMetaPayload !== []) {
                $groupMetadata = json_encode($groupMetaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($isGroupChat && $groupThreadKey === null) {
            $groupThreadKey = $this->normalizeGroupKeyCandidate($phone);
            if ($groupThreadKey !== null) {
                $phone = $groupThreadKey;
            }
        }

        $contactMetadata = [
            'profile' => $meta['contact_name'] ?? null,
            'source' => 'log_import',
        ];
        if ($isGroupChat) {
            $contactMetadata['chat_type'] = 'group';
        }
        $contactMetadata['normalized_from'] = $phone;

        $contact = $this->contacts->findOrCreate($phone, [
            'name' => $meta['contact_name'] ?? ($isGroupChat ? 'Grupo WhatsApp' : ''),
            'metadata' => json_encode($contactMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_interaction_at' => $timestamp,
        ]);

        $line = $meta['line'] ?? null;
        $lineId = $line['id'] ?? null;

        $gatewayInstance = null;
        $channelValue = '';
        if (isset($meta['channel_thread_id']) && is_string($meta['channel_thread_id'])) {
            $channelValue = trim((string)$meta['channel_thread_id']);
        }
        if (isset($metaExtras['gateway_instance']) && is_string($metaExtras['gateway_instance'])) {
            $gatewayInstance = $this->sanitizeAltGatewaySlug((string)$metaExtras['gateway_instance']);
        } elseif (isset($meta['gateway_instance']) && is_string($meta['gateway_instance'])) {
            $gatewayInstance = $this->sanitizeAltGatewaySlug((string)$meta['gateway_instance']);
        }

        $thread = null;
        if ($channelValue !== '') {
            $thread = $this->threads->findByChannelId($channelValue);
        }
        if ($thread === null) {
            $thread = $lineId !== null
                ? $this->threads->findLatestByContactAndLine((int)$contact['id'], (int)$lineId)
                : $this->threads->findLatestByContact((int)$contact['id']);
        }

        if ($thread === null && $gatewayInstance !== null && !$isGroupChat) {
            $channelValue = $this->buildAltChannelId((string)$contact['phone'], $gatewayInstance);
            $thread = $this->threads->findByChannelId($channelValue);
        }

        if ($channelValue === '') {
            if ($isGroupChat) {
                $baseChannel = $groupThreadKey ?? (string)$contact['phone'];
                $channelValue = $gatewayInstance !== null
                    ? 'alt:' . $gatewayInstance . ':' . $baseChannel
                    : $baseChannel;
            } else {
                $channelValue = $gatewayInstance !== null
                    ? $this->buildAltChannelId((string)$contact['phone'], $gatewayInstance)
                    : 'contact:' . $contact['phone'];
            }
        }

        if ($thread === null) {
            try {
                $threadId = $this->threads->create([
                    'contact_id' => (int)$contact['id'],
                    'status' => 'open',
                    'queue' => 'arrival',
                    'channel_thread_id' => $channelValue,
                    'line_id' => $lineId,
                    'chat_type' => $isGroupChat ? 'group' : 'direct',
                    'group_subject' => $isGroupChat ? $groupSubject : null,
                    'group_metadata' => $isGroupChat ? $groupMetadata : null,
                ]);
                $thread = $this->threads->find($threadId);
            } catch (PDOException $exception) {
                if (!$this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
                // Outra thread já usa o mesmo canal; reaproveita para evitar 500 no webhook.
                $existing = $this->threads->findByChannelId($channelValue);
                if ($existing !== null) {
                    $thread = $existing;
                }
            }
        }

        if ($thread === null) {
            throw new RuntimeException('Falha ao gerar conversa para o contato.');
        }

        // Garante que o channel_thread_id acompanhe o valor do gateway, se disponível.
        if ($channelValue !== '' && (string)($thread['channel_thread_id'] ?? '') !== $channelValue) {
            try {
                $this->threads->update((int)$thread['id'], ['channel_thread_id' => $channelValue]);
                $thread['channel_thread_id'] = $channelValue;
            } catch (PDOException $exception) {
                if (!$this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
                $existing = $this->threads->findByChannelId($channelValue);
                if ($existing !== null) {
                    $thread = $existing;
                    $contact = $this->contacts->find((int)($thread['contact_id'] ?? 0)) ?? $contact;
                }
            }
        }

        if ($isGroupChat) {
            $threadUpdates = [];
            if (($thread['chat_type'] ?? 'direct') !== 'group') {
                $threadUpdates['chat_type'] = 'group';
            }
            if ($groupSubject !== null && $groupSubject !== ($thread['group_subject'] ?? null)) {
                $threadUpdates['group_subject'] = $groupSubject;
            }
            if ($groupMetadata !== null && $groupMetadata !== ($thread['group_metadata'] ?? null)) {
                $threadUpdates['group_metadata'] = $groupMetadata;
            }
            if ($threadUpdates !== []) {
                $this->threads->update((int)$thread['id'], $threadUpdates);
                $thread = array_merge($thread, $threadUpdates);
            }
        }

        $mediaMeta = isset($meta['media']) && is_array($meta['media']) ? $meta['media'] : null;
        $messageType = $mediaMeta['type'] ?? (is_string($meta['message_type'] ?? null) ? strtolower((string)$meta['message_type']) : 'text');

        $normalizedBody = trim($body);
        if ($normalizedBody !== '') {
            $dedupeWindow = 20;
            $recentMessages = $this->messages->listForThread((int)$thread['id'], 5);
            foreach ($recentMessages as $recent) {
                if ((string)($recent['direction'] ?? '') !== 'outgoing') {
                    continue;
                }

                $recentContent = trim((string)($recent['content'] ?? ''));
                $recentSentAt = isset($recent['sent_at']) ? (int)$recent['sent_at'] : 0;

                if ($recentContent !== $normalizedBody) {
                    continue;
                }

                if ($recentSentAt <= 0 || abs($timestamp - $recentSentAt) > $dedupeWindow) {
                    continue;
                }

                $recentMetaId = trim((string)($recent['meta_message_id'] ?? ''));
                $updates = [];

                if ($metaMessageId !== '' && $recentMetaId === '') {
                    $updates['meta_message_id'] = $metaMessageId;
                }

                $mergedMeta = $this->mergeMessageMetadata($recent['metadata'] ?? null, [
                    'external_id' => $metaMessageId !== '' ? $metaMessageId : null,
                    'message_type' => $messageType,
                    'import_source' => 'log_import',
                ]);

                if ($mergedMeta !== ($recent['metadata'] ?? null)) {
                    $updates['metadata'] = $mergedMeta;
                }

                if ($updates !== []) {
                    $this->messages->updateOutgoingMessage((int)$recent['id'], $updates);
                }

                if ($ackValue !== null) {
                    $updatedMessage = array_merge($recent, [
                        'meta_message_id' => $updates['meta_message_id'] ?? $recentMetaId,
                        'metadata' => $updates['metadata'] ?? ($recent['metadata'] ?? null),
                    ]);
                    $this->applyAckStatusUpdate($updatedMessage, $ackValue, $meta);
                }

                if ($markRead) {
                    $this->threads->markAsRead((int)$thread['id']);
                }

                $this->contacts->touchInteraction((int)$contact['id'], $timestamp);

                return [
                    'thread_id' => (int)$thread['id'],
                    'message_id' => (int)$recent['id'],
                ];
            }
        }

        $metadata = [
            'source' => 'log_import',
            'line_label' => $line['label'] ?? null,
            'external_id' => $meta['meta_message_id'] ?? null,
            'extra' => $meta['metadata'] ?? null,
        ];
        if ($mediaMeta !== null) {
            $metadata['media'] = $mediaMeta;
        }
        $metadata['message_type'] = $messageType;

        $messageId = $this->messages->create([
            'thread_id' => (int)$thread['id'],
            'direction' => 'outgoing',
            'message_type' => $messageType,
            'content' => $body,
            'status' => 'imported',
            'meta_message_id' => $meta['meta_message_id'] ?? null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $timestamp,
        ]);
            $this->messages->pruneToLatest((int)$thread['id'], self::MESSAGE_RETAIN_LIMIT);

        $update = [
            'last_message_preview' => mb_substr($body, 0, 160),
            'last_message_at' => $timestamp,
        ];

        if ($lineId !== null && ((int)($thread['line_id'] ?? 0)) !== (int)$lineId) {
            $update['line_id'] = (int)$lineId;
        }

        if ($isGroupChat) {
            $update['chat_type'] = 'group';
            if ($groupSubject !== null) {
                $update['group_subject'] = $groupSubject;
            }
            if ($groupMetadata !== null) {
                $update['group_metadata'] = $groupMetadata;
            }
        }

        $this->threads->update((int)$thread['id'], $update);

        if ($markRead) {
            $this->threads->markAsRead((int)$thread['id']);
        }

        // Se chegar nova mensagem em um thread fechado/concluído, reabre e leva para a fila de chegada.
        $currentStatus = strtolower((string)($thread['status'] ?? 'open'));
        $currentQueue = strtolower((string)($thread['queue'] ?? 'arrival'));
        if ($currentStatus === 'closed' || $currentQueue === 'concluidos') {
            $this->threads->update((int)$thread['id'], [
                'status' => 'open',
                'queue' => 'arrival',
                'assigned_user_id' => null,
            ]);
        }

        $this->contacts->touchInteraction((int)$contact['id'], $timestamp);

        return [
            'thread_id' => (int)$thread['id'],
            'message_id' => $messageId,
        ];
    }

    private function resolveLineFromEntry(array $entry): ?array
    {
        $lineId = isset($entry['line_id']) ? (int)$entry['line_id'] : null;
        if ($lineId !== null && $lineId > 0) {
            $line = $this->lines->find($lineId);
            if ($line !== null) {
                return $line;
            }
        }

        $label = trim((string)($entry['line_label'] ?? $entry['line'] ?? ''));
        if ($label !== '') {
            return $this->findLineByLabel($label);
        }

        return null;
    }

    private function findLineByLabel(string $label): ?array
    {
        $line = $this->lines->findByLabel($label);
        if ($line !== null) {
            return $line;
        }

        $normalized = mb_strtolower($label);
        foreach ($this->lines->all() as $candidate) {
            $candidateLabel = mb_strtolower((string)($candidate['label'] ?? ''));
            if ($candidateLabel === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    private function parseTimestamp(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                if (ctype_digit($trimmed)) {
                    return (int)$trimmed;
                }

                $parsed = strtotime($trimmed);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        if (is_float($value) && $value > 0) {
            return (int)$value;
        }

        return now();
    }

    private function registerIncomingMessage(string $from, string $text, array $metadata): ?int
    {
        $gatewayMeta = isset($metadata['gateway_metadata']) && is_array($metadata['gateway_metadata'])
            ? $metadata['gateway_metadata']
            : [];
        $isHistoryEvent = !empty($gatewayMeta['history']);
        $altInstanceSlug = $this->detectAltGatewaySlug($gatewayMeta);
        $isAltGateway = $altInstanceSlug !== null;

        $fromNormalized = $this->selectIncomingPhone($from, $gatewayMeta);
        $normalizedFrom = digits_only($fromNormalized);
        if ($normalizedFrom === '' && isset($metadata['channel_thread_id'])) {
            [, $channelPhone] = $this->parseAltChannelId((string)$metadata['channel_thread_id']);
            if ($channelPhone !== null && $channelPhone !== '') {
                $normalizedFrom = digits_only($channelPhone);
            }
        }

        if ($normalizedFrom === '') {
            $this->logInboundMissingPhone($from, $gatewayMeta, $metadata);
            return null;
        }

        if ($this->isBlockedNumber($normalizedFrom)) {
            $this->logBlockedInbound($normalizedFrom, $text, $metadata);
            return 0; // indica bloqueado, mas evita erro no gateway
        }

        $chatType = 'direct';
        $groupSubject = null;
        $groupMetadata = null;
        $groupThreadKey = null;

        $remoteJid = (string)($gatewayMeta['remote_jid'] ?? $gatewayMeta['remoteJid'] ?? $gatewayMeta['chat_id'] ?? $gatewayMeta['chatId'] ?? '');
        $channelThreadIdMeta = (string)($gatewayMeta['channel_thread_id'] ?? '');
        $looksLikeGroupJid = $remoteJid !== '' && (str_contains($remoteJid, '@g.us') || str_contains($remoteJid, '-'));
        [, $channelPhoneFromMeta] = $this->parseAltChannelId($channelThreadIdMeta);
        $looksLikeGroupChannel = $channelThreadIdMeta !== '' && str_starts_with($channelThreadIdMeta, 'group:');
        if (!$looksLikeGroupChannel && is_string($channelPhoneFromMeta)) {
            $looksLikeGroupChannel = str_starts_with($channelPhoneFromMeta, 'group:');
        }
        $looksLikeNewsletter = str_contains($remoteJid, '@newsletter')
            || str_contains($channelThreadIdMeta, '@newsletter')
            || str_contains((string)($gatewayMeta['chat_type'] ?? ''), 'newsletter')
            || str_contains((string)($gatewayMeta['chatId'] ?? ''), '@newsletter');

        $hasGroupHints = !empty($gatewayMeta['group_jid'])
            || !empty($gatewayMeta['group_participant'])
            || !empty($gatewayMeta['group_subject'])
            || ($metadata['profile'] ?? '') === 'Grupo WhatsApp';

        if (($gatewayMeta['chat_type'] ?? '') === 'group'
            || str_starts_with($from, 'group:')
            || $looksLikeGroupJid
            || $looksLikeGroupChannel
            || $looksLikeNewsletter
            || $hasGroupHints) {
            $chatType = 'group';
            $subjectHint = trim((string)($gatewayMeta['group_subject'] ?? $metadata['profile'] ?? ''));
            $groupSubject = $subjectHint !== '' ? $subjectHint : ($looksLikeNewsletter ? 'Canal WhatsApp' : 'Grupo WhatsApp');
            $metadata['profile'] = $groupSubject;
            $groupThreadKey = $this->resolveGroupThreadKey($gatewayMeta, $metadata, $from);

            $participantData = $gatewayMeta['group_participant'] ?? [];
            $participantName = $participantData['name'] ?? ($gatewayMeta['participant_name'] ?? null);
            $participantPhone = $participantData['phone'] ?? ($gatewayMeta['participant_phone'] ?? null);
            $participantPhone = $participantPhone !== null ? digits_only((string)$participantPhone) : null;

            $groupMetaPayload = array_filter([
                'chat_id' => $gatewayMeta['group_jid'] ?? $gatewayMeta['chat_id'] ?? null,
                'subject' => $groupSubject,
                'participant' => array_filter([
                    'name' => $participantName,
                    'phone' => $participantPhone ?: null,
                ], static fn($value) => $value !== null && $value !== ''),
            ], static fn($value) => $value !== null && $value !== '' && $value !== []);

            if ($groupMetaPayload !== []) {
                $groupMetadata = json_encode($groupMetaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($chatType === 'group' && $groupThreadKey === null) {
            $groupThreadKey = $this->resolveGroupThreadKey($gatewayMeta, $metadata, $from);
            if ($groupThreadKey === null) {
                $groupThreadKey = $this->normalizeGroupKeyCandidate($fromNormalized);
            }
        }

        $contactMetadata = [
            'profile' => $metadata['profile'] ?? null,
        ];
        if ($chatType === 'group') {
            $contactMetadata['chat_type'] = 'group';
            if ($normalizedFrom !== '') {
                $contactMetadata['participant_phone'] = $normalizedFrom;
            }
        }

        $profileName = (string)($metadata['profile'] ?? '');
        $profilePhoto = $this->resolveProfilePhoto($metadata, $gatewayMeta);
        if ($profilePhoto !== null && !str_starts_with($profilePhoto, 'http')) {
            $profilePhoto = null;
        }
        $contactMetadata['profile_photo'] = $profilePhoto;
        $contactMetadata['raw_from'] = $from;
        $contactPhone = $chatType === 'group' && $groupThreadKey !== null ? $groupThreadKey : $fromNormalized;
        if ($contactPhone === '' && $normalizedFrom !== '') {
            $contactPhone = $normalizedFrom;
        }
        $contactMetadata['normalized_from'] = $contactPhone;

        $timestamp = $metadata['timestamp'] ?? now();
        $existingSnapshotAt = 0;
        $existingSnapshot = null;

        $contact = $this->contacts->findOrCreate($contactPhone, [
            'name' => $profileName,
            'metadata' => json_encode($contactMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_interaction_at' => $metadata['timestamp'] ?? now(),
        ]);

        $contactHasClient = !empty($contact['client_id']);
        $isPlaceholderName = trim((string)($contact['name'] ?? '')) === '' || trim((string)($contact['name'] ?? '')) === 'Contato WhatsApp';

        $existingMeta = [];
        if (!empty($contact['metadata']) && is_string($contact['metadata'])) {
            $decodedMeta = json_decode((string)$contact['metadata'], true);
            if (is_array($decodedMeta)) {
                $existingMeta = $decodedMeta;
                $existingSnapshot = $decodedMeta['gateway_snapshot'] ?? null;
                $existingSnapshotAt = isset($decodedMeta['gateway_snapshot_at']) ? (int)$decodedMeta['gateway_snapshot_at'] : 0;
            }
        }

        $mergedMeta = array_filter(array_merge($existingMeta, $contactMetadata), static fn($value) => $value !== null && $value !== '');

        $snapshot = $this->buildGatewaySnapshot(
            $metadata,
            $gatewayMeta,
            $contactPhone,
            $profileName,
            $profilePhoto,
            $timestamp,
            $chatType,
            $groupSubject,
            $groupMetadata,
            is_array($existingSnapshot) ? $existingSnapshot : null
        );

        $needsSnapshotRefresh = ($existingSnapshot === null)
            || ($timestamp - $existingSnapshotAt) > self::GATEWAY_SNAPSHOT_TTL
            || $this->gatewaySnapshotChanged($existingSnapshot, $snapshot);

        if ($snapshot !== null && $needsSnapshotRefresh) {
            $mergedMeta['gateway_snapshot'] = $snapshot;
            $mergedMeta['gateway_snapshot_at'] = $timestamp;
        }

        $updatePayload = [];

        // Auto-vincular ao cliente quando houver match por telefone; evita salvar "SafeGreen" como contato do cliente.
        if (!$contactHasClient && $chatType !== 'group') {
            $linkedClient = $this->findClientByPhone($contactPhone);
            if ($linkedClient !== null) {
                $contactHasClient = true;
                $updatePayload['client_id'] = (int)$linkedClient['id'];
                $mergedMeta['auto_link'] = $mergedMeta['auto_link'] ?? 'phone';

                $clientName = trim((string)($linkedClient['name'] ?? ''));
                if ($clientName !== '' && ($isPlaceholderName || trim((string)($contact['name'] ?? '')) === '' || trim((string)($contact['name'] ?? '')) === $profileName)) {
                    $updatePayload['name'] = $clientName;
                    $contact['name'] = $clientName;
                }
            }
        }

        $needsMetaUpdate = $mergedMeta !== $existingMeta || array_key_exists('client_id', $updatePayload);
        $needsNameUpdate = (!$contactHasClient && $isPlaceholderName && $profileName !== '');

        // Always refresh name/photo metadata on new inbound hits to keep the contact card accurate for the UI
        if (($metadata['direction'] ?? null) === 'incoming') {
            $needsMetaUpdate = true;
            if ($profileName !== '' && !isset($updatePayload['name'])) {
                $needsNameUpdate = true;
            }
        }

        if ($needsMetaUpdate) {
            $updatePayload['metadata'] = json_encode($mergedMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($needsNameUpdate && !isset($updatePayload['name'])) {
            $updatePayload['name'] = $profileName;
            $contact['name'] = $profileName;
        }

        if ($updatePayload !== []) {
            $this->contacts->update((int)$contact['id'], $updatePayload);
            if (isset($updatePayload['metadata'])) {
                $contact['metadata'] = $updatePayload['metadata'];
            }
        }

        $line = $metadata['line'] ?? null;
        $lineId = $line['id'] ?? null;

        $thread = $lineId !== null
            ? $this->threads->findLatestByContactAndLine((int)$contact['id'], (int)$lineId)
            : $this->threads->findLatestByContact((int)$contact['id']);

        // Safety net: if the line changed or was renamed, still reuse the most recent thread for the contact
        // before creating a new one. This avoids duplicate threads when the gateway switches line metadata.
        if ($thread === null && $lineId !== null) {
            $thread = $this->threads->findLatestByContact((int)$contact['id']);
        }

        if ($chatType === 'group') {
            $channelValueBase = $groupThreadKey ?? (string)$contact['phone'];
            $channelValue = $isAltGateway && $altInstanceSlug !== null
                ? 'alt:' . $altInstanceSlug . ':' . $channelValueBase
                : $channelValueBase;
        } else {
            if ($isAltGateway) {
                if ($channelThreadIdMeta !== '') {
                    $channelValue = str_starts_with($channelThreadIdMeta, 'alt:')
                        ? $channelThreadIdMeta
                        : 'alt:' . ($altInstanceSlug ?? $this->defaultAltGatewaySlug() ?? 'default') . ':' . $channelThreadIdMeta;
                } else {
                    $channelValue = $this->buildAltChannelId((string)$contact['phone'], $altInstanceSlug);
                }
            } else {
                $channelValue = 'contact:' . $contact['phone'];
            }
        }

        if ($thread === null && $channelValue !== '') {
            $existingThread = $this->threads->findByChannelId($channelValue);
            if ($existingThread !== null) {
                $thread = $existingThread;
            }
        }

        if ($thread === null) {
            $threadPayload = [
                'contact_id' => (int)$contact['id'],
                'status' => 'open',
                'queue' => 'arrival',
                'channel_thread_id' => $channelValue,
                'line_id' => $lineId,
                'chat_type' => $chatType,
                'group_subject' => $groupSubject,
                'group_metadata' => $groupMetadata,
            ];

            try {
                $threadId = $this->threads->create($threadPayload);
                $thread = $this->threads->find($threadId);
            } catch (PDOException $exception) {
                if (!$this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $thread = $this->threads->findByChannelId($channelValue);
            }
        }

        if ($thread === null) {
            return null;
        }

        // Keep channel id consistent for alt gateway threads; also reuse existing channel ids when possible.
        if ($isAltGateway) {
            $channelId = (string)($thread['channel_thread_id'] ?? '');
            $needsChannelUpdate = $channelId === '' || $channelId !== $channelValue || ($chatType !== 'group' && !str_starts_with($channelId, 'alt:'));
            if ($needsChannelUpdate) {
                try {
                    $this->threads->update((int)$thread['id'], [
                        'channel_thread_id' => $channelValue,
                    ]);
                    $thread['channel_thread_id'] = $channelValue;
                } catch (PDOException $exception) {
                    if (!$this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $existingThread = $this->threads->findByChannelId($channelValue);
                    if ($existingThread !== null) {
                        $thread = $existingThread;
                        $linkedContactId = (int)($thread['contact_id'] ?? 0);
                        if ($linkedContactId > 0 && $linkedContactId !== (int)$contact['id']) {
                            $resolvedContact = $this->contacts->find($linkedContactId);
                            if (is_array($resolvedContact)) {
                                $contact = $resolvedContact;
                            }
                        }
                    }
                }
            }

            // If we reused an older thread that lacked a line_id, attach the current line to avoid future splits.
            if ($lineId !== null && ((int)($thread['line_id'] ?? 0)) !== (int)$lineId) {
                $this->threads->update((int)$thread['id'], ['line_id' => (int)$lineId]);
                $thread['line_id'] = (int)$lineId;
            }
        } else {
            // Meta/default gateway: alinhar channel_thread_id ao contato para evitar threads paralelas por linha.
            $channelId = (string)($thread['channel_thread_id'] ?? '');
            if ($channelId === '' || $channelId !== $channelValue) {
                try {
                    $this->threads->update((int)$thread['id'], ['channel_thread_id' => $channelValue]);
                    $thread['channel_thread_id'] = $channelValue;
                } catch (PDOException $exception) {
                    if (!$this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $existingThread = $this->threads->findByChannelId($channelValue);
                    if ($existingThread !== null) {
                        $thread = $existingThread;
                        $linkedContactId = (int)($thread['contact_id'] ?? 0);
                        if ($linkedContactId > 0 && $linkedContactId !== (int)$contact['id']) {
                            $resolvedContact = $this->contacts->find($linkedContactId);
                            if (is_array($resolvedContact)) {
                                $contact = $resolvedContact;
                            }
                        }
                    }
                }
            }
        }

        if ($thread === null) {
            return null;
        }

        if ($chatType === 'group') {
            $updates = [];
            if (($thread['chat_type'] ?? 'direct') !== 'group') {
                $updates['chat_type'] = 'group';
            }
            if ($groupSubject !== null && $groupSubject !== ($thread['group_subject'] ?? null)) {
                $updates['group_subject'] = $groupSubject;
            }
            if ($groupMetadata !== null && $groupMetadata !== ($thread['group_metadata'] ?? null)) {
                $updates['group_metadata'] = $groupMetadata;
            }
            if ($updates !== []) {
                $this->threads->update((int)$thread['id'], $updates);
                $thread = array_merge($thread, $updates);
            }
        }

        if (($thread['status'] ?? 'open') === 'closed') {
            $this->threads->update((int)$thread['id'], [
                'status' => 'open',
                'queue' => 'arrival',
                'assigned_user_id' => null,
                'closed_at' => null,
                'line_id' => $lineId !== null ? (int)$lineId : $thread['line_id'] ?? null,
            ]);
            $thread['status'] = 'open';
            $thread['queue'] = 'arrival';
            $thread['assigned_user_id'] = null;
            if ($lineId !== null) {
                $thread['line_id'] = (int)$lineId;
            }
        }

        $isGroup = (($thread['chat_type'] ?? 'direct') === 'group');
        if ($isGroup && ($thread['queue'] ?? '') !== 'groups') {
            $this->threads->update((int)$thread['id'], [
                'queue' => 'groups',
                'status' => 'open',
                'assigned_user_id' => null,
                'scheduled_for' => null,
                'partner_id' => null,
                'responsible_user_id' => null,
            ]);
            $thread['queue'] = 'groups';
            $thread['status'] = 'open';
            $thread['assigned_user_id'] = null;
        }

        $timestamp = $metadata['timestamp'] ?? now();
        $mediaMeta = isset($metadata['media']) && is_array($metadata['media']) ? $metadata['media'] : null;
        $messageType = $mediaMeta['type'] ?? (is_string($metadata['message_type'] ?? null) ? strtolower((string)$metadata['message_type']) : 'text');
        $messageMeta = [
            'meta_message_id' => $metadata['meta_message_id'] ?? null,
            'raw' => $metadata,
        ];
        if ($mediaMeta !== null) {
            $messageMeta['media'] = $mediaMeta;
        }
        $messageMeta['message_type'] = $messageType;

        $metaMessageId = isset($metadata['meta_message_id']) ? trim((string)$metadata['meta_message_id']) : '';
        $existingMessage = null;
        $dedupeWindow = null;
        $dedupeEnabled = filter_var(env('WHATSAPP_DEDUPE_ENABLED', true), FILTER_VALIDATE_BOOL);

        if ($dedupeEnabled && $metaMessageId !== '') {
            $existingMessage = $this->messages->findByMetaMessageId($metaMessageId);
        }

        if ($dedupeEnabled && $existingMessage === null) {
            $dedupeWindow = $isHistoryEvent
                ? (int)max(60, env('WHATSAPP_HISTORY_DEDUPE_WINDOW', 86400))
                : (int)max(30, env('WHATSAPP_INCOMING_DEDUPE_WINDOW', 300));
            $existingMessage = $this->messages->findIncomingDuplicate(
                (int)$thread['id'],
                $text,
                (int)$timestamp,
                $messageType,
                $metaMessageId !== '' ? $metaMessageId : null,
                $dedupeWindow
            );
        }

        if ($existingMessage !== null) {
            if (($existingMessage['direction'] ?? '') === 'incoming') {
                $this->applyIncomingMessageEdit($existingMessage, $thread, $text, $messageMeta);

                $updatePayload = [];
                if ($metaMessageId !== '' && empty($existingMessage['meta_message_id'])) {
                    $updatePayload['meta_message_id'] = $metaMessageId;
                }
                if ($updatePayload !== []) {
                    $this->messages->updateIncomingMessage((int)$existingMessage['id'], $updatePayload);
                }

                $this->logInboundDedupe([
                    'action' => 'reuse',
                    'reason' => $metaMessageId !== '' ? 'meta_message_id' : 'content_window',
                    'dedupe_window' => $dedupeWindow ?? null,
                    'gateway' => $isAltGateway ? 'alt' : 'meta',
                    'gateway_slug' => $altInstanceSlug,
                    'normalized_phone' => $normalizedFrom,
                    'raw_from' => $from,
                    'channel_thread_id' => $thread['channel_thread_id'] ?? null,
                    'thread_id' => $existingMessage['thread_id'] ?? ($thread['id'] ?? null),
                    'message_id' => $existingMessage['id'] ?? null,
                    'meta_message_id' => $metaMessageId !== '' ? $metaMessageId : ($existingMessage['meta_message_id'] ?? null),
                    'message_type' => $messageType,
                    'history' => $isHistoryEvent,
                ]);

                return (int)$existingMessage['thread_id'];
            }

            return (int)($existingMessage['thread_id'] ?? $thread['id']);
        }

        $this->messages->create([
            'thread_id' => (int)$thread['id'],
            'direction' => 'incoming',
            'message_type' => $messageType,
            'content' => $text,
            'status' => 'delivered',
            'meta_message_id' => $metadata['meta_message_id'] ?? null,
            'metadata' => json_encode($messageMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $timestamp,
        ]);

        $this->logInboundDedupe([
            'action' => 'create',
            'reason' => 'new_message',
            'dedupe_window' => $dedupeWindow ?? null,
            'gateway' => $isAltGateway ? 'alt' : 'meta',
            'gateway_slug' => $altInstanceSlug,
            'normalized_phone' => $normalizedFrom,
            'raw_from' => $from,
            'channel_thread_id' => $thread['channel_thread_id'] ?? null,
            'thread_id' => $thread['id'] ?? null,
            'meta_message_id' => $metaMessageId !== '' ? $metaMessageId : null,
            'message_type' => $messageType,
            'history' => $isHistoryEvent,
        ]);

        $this->messages->pruneToLatest((int)$thread['id'], self::MESSAGE_RETAIN_LIMIT);

        $update = [
            'last_message_preview' => mb_substr($text, 0, 160),
            'last_message_at' => $timestamp,
        ];

        if ($lineId !== null && ((int)($thread['line_id'] ?? 0)) !== (int)$lineId) {
            $update['line_id'] = (int)$lineId;
        }

        if ($chatType === 'group') {
            $update['chat_type'] = 'group';
            $update['group_subject'] = $groupSubject;
            if ($groupMetadata !== null) {
                $update['group_metadata'] = $groupMetadata;
            }
        }

        $this->threads->update((int)$thread['id'], $update);
        $this->threads->incrementUnread((int)$thread['id']);
        $this->contacts->touchInteraction((int)$contact['id'], $timestamp);

        return (int)$thread['id'];
    }

    private function applyIncomingMessageEdit(array $existingMessage, ?array $thread, string $text, array $messageMeta): void
    {
        $messageId = (int)($existingMessage['id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }

        $metadata = [];
        if (!empty($existingMessage['metadata']) && is_string($existingMessage['metadata'])) {
            $decoded = json_decode($existingMessage['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        $metadata['raw'] = $messageMeta['raw'] ?? $messageMeta;
        $metadata['message_type'] = $messageMeta['message_type'] ?? ($metadata['message_type'] ?? 'text');
        $metadata['edited_at'] = now();

        if (isset($messageMeta['media'])) {
            $metadata['media'] = $messageMeta['media'];
        } elseif (isset($metadata['media'])) {
            unset($metadata['media']);
        }

        $this->messages->updateIncomingMessage($messageId, [
            'content' => $text,
            'message_type' => $messageMeta['message_type'] ?? ($existingMessage['message_type'] ?? 'text'),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'delivered',
        ]);

        $threadId = (int)($existingMessage['thread_id'] ?? 0);
        if ($threadId <= 0) {
            return;
        }

        $threadRow = $thread;
        if (!is_array($threadRow) || (int)($threadRow['id'] ?? 0) !== $threadId) {
            $threadRow = $this->threads->find($threadId);
        }

        if ($threadRow === null) {
            return;
        }

        $messageSentAt = (int)($existingMessage['sent_at'] ?? ($threadRow['last_message_at'] ?? now()));
        $lastMessageAt = (int)($threadRow['last_message_at'] ?? 0);
        if ($messageSentAt !== $lastMessageAt) {
            return;
        }

        $this->threads->update($threadId, [
            'last_message_preview' => mb_substr($text, 0, 160),
        ]);
    }

    private function extractLastIncoming(array $messages): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (($message['direction'] ?? '') === 'incoming') {
                return $message;
            }
        }

        return $messages !== [] ? end($messages) : [];
    }

    private function buildIntakeSummary(array $thread, array $lastIncoming, array $copilot): string
    {
        $contact = $thread['contact_name'] ?? 'Contato';
        $lastText = trim((string)($lastIncoming['content'] ?? $thread['last_message_preview'] ?? ''));
        $copilotText = trim((string)($copilot['suggestion'] ?? ''));
        $parts = [];
        $parts[] = sprintf('Pré-atendimento IA para %s', $contact);

        if ($lastText !== '') {
            $parts[] = 'Ultima mensagem: "' . mb_substr($lastText, 0, 180) . '"';
        }

        if ($copilotText !== '') {
            $parts[] = 'Sugestão IA: ' . $copilotText;
        }

        $queue = $thread['queue'] ?? 'arrival';
        $parts[] = 'Fila atual: ' . ($thread['queue'] ?? 'arrival');

        return implode(' | ', $parts);
    }

    private function suggestQueueNextStep(array $thread, array $lastIncoming, array $copilot): array
    {
        $text = mb_strtolower(trim((string)($lastIncoming['content'] ?? $thread['last_message_preview'] ?? '')));
        $queue = 'arrival';
        $scheduled = null;
        $confidence = (float)($copilot['confidence'] ?? 0.6);

        $scheduleKeywords = ['agendar', 'agenda', 'horário', 'horario', 'marcar', 'marcação', 'marcacao'];
        $partnerKeywords = ['parceiro', 'indicador', 'indiquei', 'cliente meu', 'repasse', 'contador'];

        foreach ($scheduleKeywords as $keyword) {
            if ($keyword !== '' && str_contains($text, $keyword)) {
                $queue = 'scheduled';
                $scheduled = now() + 3600;
                $confidence = max($confidence, 0.75);
                break;
            }
        }

        if ($queue === 'arrival') {
            foreach ($partnerKeywords as $keyword) {
                if ($keyword !== '' && str_contains($text, $keyword)) {
                    $queue = 'partner';
                    $confidence = max($confidence, 0.7);
                    break;
                }
            }
        }

        if ($queue === 'arrival' && in_array($thread['queue'] ?? '', ['scheduled', 'partner'], true)) {
            $queue = (string)($thread['queue'] ?? 'arrival');
        }

        $suggestion = [
            'queue' => $queue,
            'scheduled_for_hint' => $scheduled,
            'partner_id' => isset($thread['partner_id']) ? (int)$thread['partner_id'] : null,
            'responsible_user_id' => isset($thread['responsible_user_id']) ? (int)$thread['responsible_user_id'] : null,
            'confidence' => $confidence,
        ];

        return $suggestion;
    }

    public function threadCard(int $threadId): ?array
    {
        $thread = $this->threads->findWithRelations($threadId);
        if ($thread === null) {
            return null;
        }

        return [
            'id' => (int)$thread['id'],
            'queue' => (string)($thread['queue'] ?? 'arrival'),
            'contact_name' => (string)($thread['contact_name'] ?? 'Contato'),
            'contact_phone' => (string)($thread['contact_phone'] ?? ''),
            'contact_phone_formatted' => format_phone($thread['contact_phone'] ?? ''),
            'contact_client_id' => isset($thread['contact_client_id']) ? (int)$thread['contact_client_id'] : null,
            'last_message_preview' => $thread['last_message_preview'] ?? '',
            'status' => (string)($thread['status'] ?? 'open'),
            'unread_count' => (int)($thread['unread_count'] ?? 0),
            'line_id' => (int)($thread['line_id'] ?? 0),
            'line_label' => $thread['line_label'] ?? null,
            'line_display_phone' => $thread['line_display_phone'] ?? null,
            'line_provider' => $thread['line_provider'] ?? null,
            'scheduled_for' => isset($thread['scheduled_for']) ? (int)$thread['scheduled_for'] : null,
            'partner_name' => $thread['partner_name'] ?? null,
            'responsible_name' => $thread['responsible_name'] ?? null,
        ];
    }

    private function dispatchMetaMessage(array $line, string $to, string $body): array
    {
        $provider = (string)($line['provider'] ?? 'meta');

        if ($provider === 'sandbox') {
            return $this->dispatchSandboxMessage($line, $to, $body);
        }

        if ($provider === 'dialog360') {
            return $this->dispatchDialog360Message($line, $to, $body);
        }

        return $this->dispatchMetaGraphMessage($line, $to, $body);
    }

    private function dispatchMetaGraphMessage(array $line, string $to, string $body): array
    {
        $accessToken = (string)($line['access_token'] ?? '');
        $phoneNumberId = (string)($line['phone_number_id'] ?? '');

        if ($accessToken === '' || $phoneNumberId === '') {
            return [
                'status' => 'queued',
                'error' => 'missing_credentials',
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => digits_only($to),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ];

        $url = sprintf('https://graph.facebook.com/v17.0/%s/messages', $phoneNumberId);
        $response = $this->performHttpRequest('POST', $url, $payload, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        if ($response['success'] === false) {
            return [
                'status' => 'error',
                'error' => $response['error'],
                'response' => $response['body'] ?? null,
            ];
        }

        $bodyResponse = $response['body'];
        $metaMessageId = $bodyResponse['messages'][0]['id'] ?? null;

        return [
            'status' => 'sent',
            'meta_message_id' => $metaMessageId,
            'response' => $bodyResponse,
        ];
    }

    private function dispatchDialog360Message(array $line, string $to, string $body): array
    {
        $apiKey = (string)($line['access_token'] ?? '');
        if ($apiKey === '') {
            return [
                'status' => 'queued',
                'error' => 'missing_credentials',
            ];
        }

        $baseUrl = (string)($line['api_base_url'] ?? '');
        if ($baseUrl === '') {
            $baseUrl = 'https://waba.360dialog.io';
        }
        $url = rtrim($baseUrl, '/') . '/v1/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => digits_only($to),
            'type' => 'text',
            'text' => [
                'body' => $body,
            ],
        ];

        $response = $this->performHttpRequest('POST', $url, $payload, [
            'D360-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
        ]);

        if ($response['success'] === false) {
            return [
                'status' => 'error',
                'error' => $response['error'],
                'response' => $response['body'] ?? null,
            ];
        }

        $bodyResponse = $response['body'];
        $messageId = $bodyResponse['messages'][0]['id'] ?? null;

        return [
            'status' => 'sent',
            'meta_message_id' => $messageId,
            'response' => $bodyResponse,
        ];
    }

    private function performHttpRequest(string $method, string $url, array $payload, array $headers): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{}';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Length: ' . strlen($body)]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success' => false,
                'error' => $error ?: 'curl_error',
                'raw_response' => null,
                'status_code' => $status ?: null,
            ];
        }

        $rawResponse = (string)$raw;
        $decoded = json_decode($rawResponse, true);
        $hasJsonBody = is_array($decoded);
        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'error' => $decoded['error']['message'] ?? ('http_error_' . $status),
                'body' => $hasJsonBody ? $decoded : null,
                'raw_response' => $rawResponse,
                'status_code' => $status,
            ];
        }

        return [
            'success' => true,
            'body' => $hasJsonBody ? $decoded : [],
            'raw_response' => $rawResponse,
            'status_code' => $status,
        ];
    }

    private function inferSentiment(string $message): string
    {
        $message = mb_strtolower($message);
        if ($message === '') {
            return 'neutral';
        }

        if (preg_match('/problema|triste|cancelar|reclam/i', $message) === 1) {
            return 'negative';
        }

        if (preg_match('/obrigad|excelente|ótimo|perfeito/i', $message) === 1) {
            return 'positive';
        }

        return 'neutral';
    }

    private function tonePrefix(string $tone, string $contactName): string
    {
        return match ($tone) {
            'formal' => sprintf('Prezada(o) %s,', $contactName),
            'direto' => sprintf('%s, vamos direto ao ponto:', $contactName),
            default => sprintf('Oi %s!', $contactName ?: 'cliente'),
        };
    }

    private function goalCallToAction(string $goal): string
    {
        $normalized = strtolower(trim($goal));
        return match ($normalized) {
            'agendar_validacao' => 'podemos confirmar o melhor horário de validação ainda hoje? Tenho duas janelas liberadas e garanto o acompanhamento completo.',
            'renovar_certificado' => 'posso acionar a equipe para liberar o link de pagamento e garantir a renovação com antecedência?',
            default => trim($goal) !== ''
                ? trim($goal)
                : 'vamos avançar para o próximo passo? Posso cuidar dos detalhes e te envio a confirmação em minutos.',
        };
    }

    private function normalizeQueue(string $queue): string
    {
        $queue = strtolower(trim($queue));
        return in_array($queue, self::QUEUES, true) ? $queue : 'arrival';
    }

    private function decorateThreads(array $threads): array
    {
        foreach ($threads as &$thread) {
            $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : 0;
            $contactRow = $contactId > 0 ? $this->contacts->find($contactId) : null;

            $isGroup = (($thread['chat_type'] ?? 'direct') === 'group');
            $thread['is_group'] = $isGroup;
            if ($isGroup && ($thread['queue'] ?? '') !== 'groups') {
                $this->threads->update((int)$thread['id'], [
                    'queue' => 'groups',
                    'status' => 'open',
                    'assigned_user_id' => null,
                    'scheduled_for' => null,
                    'partner_id' => null,
                    'responsible_user_id' => null,
                ]);
                $thread['queue'] = 'groups';
                $thread['status'] = 'open';
                $thread['assigned_user_id'] = null;
            }
            $thread['last_message_preview'] = $thread['last_message_preview'] ?? '';
            $thread['last_message_at'] = $thread['last_message_at'] ?? $thread['updated_at'];
            $thread['contact_client_id'] = isset($thread['contact_client_id']) ? (int)$thread['contact_client_id'] : null;

            $rawPhone = (string)($thread['contact_phone'] ?? '');
            $altPhone = $this->extractAltPhone($thread);
            $hasClient = !empty($thread['contact_client_id']);
            $rawDigits = preg_replace('/\D+/', '', $rawPhone) ?: '';
            $altDigits = preg_replace('/\D+/', '', $altPhone) ?: '';

            // Use alt phone only when we have no usable contact phone and no linked client phone.
            if ($altDigits !== '' && !$hasClient && ($rawDigits === '' || !$this->isLikelyPhone($rawDigits))) {
                $rawPhone = $altDigits;
                $thread['contact_phone'] = $rawPhone;
            }

            $digits = preg_replace('/\D+/', '', $rawPhone) ?: '';
            $thread['contact_phone_formatted'] = $this->isLikelyPhone($rawPhone)
                ? format_phone($rawPhone)
                : $digits;

            $clientRow = $this->resolveThreadClient($thread);
            if ($clientRow !== null) {
                $thread['contact_client_id'] = (int)$clientRow['id'];
                $thread['contact_client'] = $this->summarizeClientForThread($clientRow);
                $clientName = trim((string)($clientRow['name'] ?? ''));
                if ($clientName !== '') {
                    $thread['contact_name'] = $clientName;
                }
            } else {
                $thread['contact_client'] = null;
            }

            if ($contactRow !== null) {
                $thread['contact_snapshot'] = $this->extractContactSnapshot($contactRow);
            }

            $thread['contact_photo'] = $this->resolveContactPhoto($thread, $contactRow);

            $this->applyContactDisplayMetadata($thread);
        }

        return $threads;
    }

    private function applyContactDisplayMetadata(array &$thread): void
    {
        $isGroup = !empty($thread['is_group']);
        if ($isGroup) {
            $label = trim((string)($thread['group_subject'] ?? $thread['contact_name'] ?? 'Grupo WhatsApp'));
            $thread['contact_display'] = $label !== '' ? $label : 'Grupo WhatsApp';
            $thread['contact_display_secondary'] = '';
            return;
        }

        $clientSummary = isset($thread['contact_client']) && is_array($thread['contact_client']) ? $thread['contact_client'] : null;
        $clientName = $clientSummary ? trim((string)($clientSummary['name'] ?? '')) : '';
        $clientPhoneRaw = $clientSummary ? (string)($clientSummary['whatsapp'] ?? ($clientSummary['phone'] ?? '')) : '';
        $clientPhone = $this->isLikelyPhone($clientPhoneRaw) ? format_phone($clientPhoneRaw) : '';

        // Do not override a valid contact or client phone with alt channel phone; alt is fallback only when nothing else is usable.
        $altChannelPhone = $this->extractAltPhone($thread);
        $contactPhoneRaw = (string)($thread['contact_phone'] ?? '');
        $contactPhoneDigits = preg_replace('/\D+/', '', $contactPhoneRaw) ?: '';
        $altDigits = preg_replace('/\D+/', '', $altChannelPhone) ?: '';
        $hasUsableContact = $this->isLikelyPhone($contactPhoneDigits);
        $hasUsableClient = $clientPhone !== '';
        if (!$hasUsableContact && !$hasUsableClient && $altDigits !== '') {
            $thread['contact_phone'] = $altDigits;
            $thread['contact_phone_formatted'] = format_phone($altDigits);
        }

        $name = $clientName !== '' ? $clientName : trim((string)($thread['contact_name'] ?? ''));
        $contactPhoneRaw = (string)($thread['contact_phone'] ?? '');
        $rawDigits = preg_replace('/\D+/', '', $contactPhoneRaw) ?: '';
        $incomingPhone = $this->isLikelyPhone($contactPhoneRaw)
            ? format_phone($contactPhoneRaw)
            : ($rawDigits !== '' ? $rawDigits : '');

        // Prioritize client phone; if absent, use incoming phone. If incoming looks invalid for BR, fall back to line display phone.
        $phone = $clientPhone !== '' ? $clientPhone : $incomingPhone;

        $lineDisplayPhone = trim((string)($thread['line_display_phone'] ?? ''));
        $lineDisplayFormatted = $lineDisplayPhone !== '' ? format_phone($lineDisplayPhone) : '';
        $incomingDigits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($lineDisplayFormatted !== '') {
            $lineDigits = preg_replace('/\D+/', '', $lineDisplayFormatted) ?: '';
            $incomingLooksInvalid = $incomingDigits === '' || !$this->isValidBrazilDisplayPhone($incomingDigits);
            if ($incomingLooksInvalid) {
                $phone = $lineDisplayFormatted;
            }
        }

        if ($name === '' && $phone !== '') {
            $name = $phone;
            $phone = '';
        } elseif ($name === '' && $phone === '') {
            $name = 'Contato WhatsApp';
        }

        $thread['contact_display'] = $name;
        $thread['contact_display_secondary'] = $phone;
    }

    private function resolveContactPhoto(array $thread, ?array $contactRow = null): ?string
    {
        $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : 0;
        if ($contactId <= 0) {
            return null;
        }

        $contact = $contactRow ?? $this->contacts->find($contactId);
        if (!is_array($contact)) {
            return null;
        }

        $meta = [];
        if (!empty($contact['metadata']) && is_string($contact['metadata'])) {
            $decoded = json_decode((string)$contact['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $snapshot = isset($meta['gateway_snapshot']) && is_array($meta['gateway_snapshot']) ? $meta['gateway_snapshot'] : [];
        $candidates = [
            $snapshot['profile_photo'] ?? null,
            $meta['profile_photo'] ?? null,
            $meta['photo'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, 'http')) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractContactSnapshot(array $contact): ?array
    {
        $meta = [];
        if (!empty($contact['metadata']) && is_string($contact['metadata'])) {
            $decoded = json_decode((string)$contact['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($contact['metadata'] ?? null)) {
            $meta = (array)$contact['metadata'];
        }

        $snapshot = isset($meta['gateway_snapshot']) && is_array($meta['gateway_snapshot']) ? $meta['gateway_snapshot'] : null;
        if ($snapshot === null) {
            return null;
        }

        $allowed = [
            'name', 'phone', 'profile_photo', 'profile_photo_etag', 'captured_at', 'source', 'meta_message_id',
            'wa_id', 'chat_id', 'presence', 'about', 'last_seen_at', 'verified_level', 'verified_name',
            'is_business', 'business_name', 'business_description', 'business_category', 'business_hours',
            'group_subject', 'group_metadata', 'group_participants',
        ];

        $filtered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $snapshot)) {
                $filtered[$key] = $snapshot[$key];
            }
        }

        return $filtered === [] ? null : $filtered;
    }

    private function resolveThreadClient(array $thread): ?array
    {
        $clientId = isset($thread['contact_client_id']) ? (int)$thread['contact_client_id'] : 0;
        if ($clientId > 0) {
            $client = $this->findClientById($clientId);
            if ($client !== null) {
                return $client;
            }
        }

        $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : 0;
        if ($contactId > 0) {
            $contact = $this->contacts->find($contactId);
            if ($contact && !empty($contact['client_id'])) {
                $linkedClientId = (int)$contact['client_id'];
                $client = $this->findClientById($linkedClientId);
                if ($client !== null) {
                    $thread['contact_client_id'] = $linkedClientId;
                    // Persist link so subsequent fetches already come with client id (ignore if column doesn't exist).
                    if (!empty($thread['id'])) {
                        try {
                            $this->threads->update((int)$thread['id'], ['contact_client_id' => $linkedClientId]);
                        } catch (\Throwable $e) {
                            // Column may not exist on this deployment; soft-fail.
                        }
                    }
                    return $client;
                }
            }
        }

        $phone = (string)($thread['contact_phone'] ?? '');
        if ($phone === '') {
            $phone = $this->extractAltPhone($thread);
        }

        if ($phone !== '' && $this->isLikelyPhone($phone)) {
            $client = $this->findClientByPhone($phone);
            if ($client !== null) {
                // Persistir o vínculo cliente-contato para que os próximos loads já venham com contact_client_id.
                $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : 0;
                if ($contactId > 0 && ($thread['contact_client_id'] ?? null) === null) {
                    try {
                        $this->contacts->update($contactId, ['client_id' => (int)$client['id']]);
                    } catch (PDOException $exception) {
                        // Se o cliente estiver faltando (FK), não quebra o painel; registra alerta e segue sem vincular.
                        if ((string)$exception->getCode() === '23000') {
                            AlertService::push('whatsapp.contact_link', 'Falha ao vincular contato ao cliente (FK).', [
                                'contact_id' => $contactId,
                                'client_id' => (int)$client['id'],
                                'error' => $exception->getMessage(),
                            ]);
                        } else {
                            throw $exception;
                        }
                    }
                }

                return $client;
            }
        }

        // Não tentar vincular por nome para evitar colisão de nomes iguais; somente telefone vincula.

        return null;
    }

    private function isLikelyPhone(string $raw): bool
    {
        $digits = preg_replace('/\D+/', '', $raw);
        $len = strlen($digits);
        return $len >= 10 && $len <= 15;
    }

    private function isValidBrazilDisplayPhone(string $digits): bool
    {
        $len = strlen($digits);
        if ($len === 10) {
            return true; // fixo com DDD
        }
        if ($len === 11) {
            return $digits[2] === '9'; // celular deve começar com 9 após DDD
        }
        return false;
    }

    private function normalizeIncomingDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if ($digits === '') {
            return '';
        }

        if ($this->isLikelyPhone($digits) && $this->isValidBrazilDisplayPhone($digits) && !str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        if (strlen($digits) > 15) {
            $digits = substr($digits, -15);
        }

        return $digits;
    }

    private function selectIncomingPhone(string $from, array $gatewayMeta): string
    {
        $candidates = [];
        $seenDigits = [];

        // Capture candidate while avoiding duplicates; store raw for future tweaks if needed.
        $push = static function (?string $value) use (&$candidates, &$seenDigits): void {
            if (!is_string($value)) {
                return;
            }
            if (str_contains($value, '@lid')) {
                return;
            }
            $digits = preg_replace('/\D+/', '', $value) ?: '';
            if ($digits === '') {
                return;
            }
            if (isset($seenDigits[$digits])) {
                return;
            }
            $seenDigits[$digits] = true;
            $candidates[] = ['raw' => $value, 'digits' => $digits];
        };

        // Prefer explicit participant data when gateways provide the sender separately from the chat.
        $participantMeta = isset($gatewayMeta['participant']) && is_array($gatewayMeta['participant']) ? $gatewayMeta['participant'] : null;
        $participantPhone = $participantMeta['phone'] ?? $gatewayMeta['participant_phone'] ?? null;
        $groupParticipantMeta = isset($gatewayMeta['group_participant']) && is_array($gatewayMeta['group_participant']) ? $gatewayMeta['group_participant'] : null;
        $groupParticipantPhone = $groupParticipantMeta['phone'] ?? null;
        $push($participantPhone);
        $push($groupParticipantPhone);

        // Then try well-known sender keys from gateway metadata.
        $keys = [
            'wa_id', 'msisdn', 'phone', 'peer', 'remote', 'remote_jid', 'jid', 'chat_id', 'chatId',
            'channel_thread_id', 'channel_id', 'conversation_id',
            'from', 'sender', 'sender_id', 'contact', 'contact_phone', 'id',
        ];
        foreach ($keys as $key) {
            if (isset($gatewayMeta[$key])) {
                $push((string)$gatewayMeta[$key]);
            }
        }
        if (isset($gatewayMeta['sender_id_digits'])) {
            $push((string)$gatewayMeta['sender_id_digits']);
        }

        // Parse alt channel id to recover phone if provided as alt:<slug>:<digits>.
        if (isset($gatewayMeta['channel_thread_id'])) {
            [$slug, $channelPhone] = $this->parseAltChannelId((string)$gatewayMeta['channel_thread_id']);
            if ($channelPhone !== null) {
                $push($channelPhone);
            }
        }

        // Fallback to the raw `from` parameter last, to avoid line numbers overriding participant phones.
        $push($from);

        foreach ($candidates as $candidate) {
            if ($this->isLikelyPhone($candidate['digits'])) {
                return $this->normalizeIncomingDigits($candidate['digits']);
            }
        }

        $fallback = $candidates[0]['digits'] ?? '';

        return $fallback !== '' ? $this->normalizeIncomingDigits($fallback) : '';
    }

    private function resolveGroupThreadKey(array $gatewayMeta, array $metadata, string $rawFrom): ?string
    {
        $candidates = [];
        $push = static function ($value) use (&$candidates): void {
            if (!is_string($value)) {
                return;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $candidates[] = $trimmed;
            }
        };

        $push($gatewayMeta['channel_thread_id'] ?? null);
        $push($metadata['channel_thread_id'] ?? null);
        $push($gatewayMeta['group_jid'] ?? ($gatewayMeta['groupJid'] ?? null));
        $push($gatewayMeta['chat_id'] ?? ($gatewayMeta['chatId'] ?? null));
        $push($gatewayMeta['remote_jid'] ?? ($gatewayMeta['remoteJid'] ?? null));
        $push($gatewayMeta['conversation_id'] ?? ($gatewayMeta['conversationId'] ?? null));
        $push($gatewayMeta['id'] ?? null);
        $push($rawFrom);

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeGroupKeyCandidate($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeGroupKeyCandidate(string $candidate): ?string
    {
        $value = trim($candidate);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'alt:')) {
            [, $channelPhone] = $this->parseAltChannelId($value);
            if (is_string($channelPhone) && $channelPhone !== '') {
                $value = $channelPhone;
            }
        }

        if (str_starts_with($value, 'group:')) {
            $value = substr($value, 6);
        }

        if (str_contains($value, '@')) {
            $value = substr($value, 0, strpos($value, '@'));
        }

        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '', $value) ?: '';
        if ($normalized === '') {
            return null;
        }

        return 'group:' . strtolower($normalized);
    }

    private function logBlockedInbound(string $phone, string $text, array $metadata): void
    {
        try {
            $payload = [
                'phone' => $phone,
                'message' => $text,
                'timestamp' => $metadata['timestamp'] ?? now(),
                'meta_message_id' => $metadata['meta_message_id'] ?? null,
                'gateway_metadata' => $metadata['gateway_metadata'] ?? ($metadata['metadata'] ?? null),
            ];

            $dir = storage_path('logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $path = $dir . DIRECTORY_SEPARATOR . 'whatsapp_blocked.log';
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // Ignora falhas de log para não afetar o fluxo.
        }
    }

    private function logInboundMissingPhone(string $from, array $gatewayMeta, array $metadata): void
    {
        try {
            $payload = [
                'raw_from' => $from,
                'gateway_meta_keys' => array_keys($gatewayMeta),
                'channel_thread_id' => $gatewayMeta['channel_thread_id'] ?? $metadata['channel_thread_id'] ?? null,
                'meta_message_id' => $metadata['meta_message_id'] ?? null,
                'timestamp' => $metadata['timestamp'] ?? now(),
            ];

            $dir = storage_path('logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $line = sprintf('[%s] missing_phone %s%s', date('c'), $payload['meta_message_id'] ?? '-', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            error_log($line . PHP_EOL, 3, $dir . '/whatsapp_incoming_missing_phone.log');
        } catch (\Throwable $exception) {
            // Intencionalmente suprime para não quebrar ingestão.
        }
    }

    private function resolveProfilePhoto(array $meta, array $gatewayMeta = []): ?string
    {
        $candidates = [];

        $push = static function ($value) use (&$candidates): void {
            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
                return;
            }
            if (is_array($value)) {
                $keys = ['eurl', 'url', 'link', 'full', 'preview', 'picture'];
                foreach ($keys as $key) {
                    if (!empty($value[$key]) && is_string($value[$key])) {
                        $candidates[] = (string)$value[$key];
                    }
                }
            }
        };

        $push($meta['profile_photo'] ?? null);
        $push($meta['photo'] ?? null);
        $push($meta['avatar'] ?? null);
        $push($meta['picture'] ?? null);
        $push($meta['profilePicThumbObj'] ?? null);

        $push($gatewayMeta['profile_photo'] ?? null);
        $push($gatewayMeta['photo'] ?? null);
        $push($gatewayMeta['avatar'] ?? null);
        $push($gatewayMeta['picture'] ?? null);
        $push($gatewayMeta['profilePicThumbObj'] ?? null);

        foreach ($candidates as $candidate) {
            $url = trim((string)$candidate);
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return null;
    }

    private function gatewaySnapshotChanged($existingSnapshot, ?array $newSnapshot): bool
    {
        if ($newSnapshot === null) {
            return false;
        }
        if (!is_array($existingSnapshot)) {
            return true;
        }

        $old = $existingSnapshot;
        $current = $newSnapshot;
        unset($old['captured_at'], $current['captured_at']);

        return $old !== $current;
    }

    private function buildGatewaySnapshot(
        array $metadata,
        array $gatewayMeta,
        string $normalizedFrom,
        string $profileName,
        ?string $profilePhoto,
        int $timestamp,
        string $chatType,
        ?string $groupSubject,
        ?string $groupMetadata,
        ?array $existingSnapshot
    ): ?array {
        $snapshot = is_array($existingSnapshot) ? $existingSnapshot : [];
        $snapshot['captured_at'] = $timestamp;

        if ($profileName !== '') {
            $snapshot['name'] = $profileName;
        }
        if ($normalizedFrom !== '') {
            $snapshot['phone'] = $normalizedFrom;
        }
        if ($profilePhoto !== null) {
            $snapshot['profile_photo'] = $profilePhoto;
        }

        $source = $this->coerceSnapshotString($metadata['origin'] ?? null) ?? ($snapshot['source'] ?? 'whatsapp');
        $snapshot['source'] = $source;

        $metaMessageId = $this->coerceSnapshotString($metadata['meta_message_id'] ?? ($metadata['message_id'] ?? null));
        if ($metaMessageId !== null) {
            $snapshot['meta_message_id'] = $metaMessageId;
        }

        $snapshot['wa_id'] = $this->coerceSnapshotString($gatewayMeta['wa_id'] ?? $gatewayMeta['waId'] ?? null);
        $snapshot['chat_id'] = $this->coerceSnapshotString(
            $gatewayMeta['chat_id'] ?? $gatewayMeta['chatId'] ?? $gatewayMeta['remote_jid'] ?? $gatewayMeta['remoteJid'] ?? null
        );

        $snapshot['presence'] = $this->normalizePresenceValue(
            $gatewayMeta['presence'] ?? $gatewayMeta['status_presence'] ?? $metadata['presence'] ?? null
        );
        $snapshot['about'] = $this->coerceSnapshotString(
            $gatewayMeta['about'] ?? $gatewayMeta['status'] ?? $gatewayMeta['status_message'] ?? $metadata['about'] ?? null
        );
        $snapshot['last_seen_at'] = $this->coerceSnapshotTimestamp(
            $gatewayMeta['last_seen'] ?? $gatewayMeta['last_seen_at'] ?? $gatewayMeta['lastSeen'] ?? $gatewayMeta['last_activity'] ?? null
        );

        $isBusiness = $this->coerceSnapshotBool(
            $gatewayMeta['is_business'] ?? $gatewayMeta['business'] ?? $gatewayMeta['verified_business'] ?? null
        );
        if ($isBusiness !== null) {
            $snapshot['is_business'] = $isBusiness;
        }

        $snapshot['business_name'] = $this->coerceSnapshotString(
            $gatewayMeta['business_name'] ?? $gatewayMeta['verified_name'] ?? $gatewayMeta['businessName'] ?? null
        );
        $snapshot['business_description'] = $this->coerceSnapshotString(
            $gatewayMeta['business_description'] ?? $gatewayMeta['business_profile_description'] ?? null
        );
        $snapshot['business_category'] = $this->coerceSnapshotString(
            $gatewayMeta['business_category'] ?? $gatewayMeta['category'] ?? null
        );
        $businessHours = $this->decodeJsonArray($gatewayMeta['business_hours'] ?? $gatewayMeta['businessHours'] ?? null);
        if ($businessHours !== null) {
            $snapshot['business_hours'] = $businessHours;
        }

        $snapshot['verified_level'] = $this->coerceSnapshotString(
            $gatewayMeta['verified_level'] ?? $gatewayMeta['verification'] ?? null
        );
        $snapshot['verified_name'] = $this->coerceSnapshotString(
            $gatewayMeta['verified_name'] ?? $gatewayMeta['business_name'] ?? null
        );

        $photoEtag = $this->coerceSnapshotString(
            $gatewayMeta['profile_photo_id'] ?? $gatewayMeta['photo_id'] ?? $gatewayMeta['photo_hash'] ?? null
        );
        if ($photoEtag !== null) {
            $snapshot['profile_photo_etag'] = $photoEtag;
        }

        if ($chatType === 'group') {
            if ($groupSubject !== null && $groupSubject !== '') {
                $snapshot['group_subject'] = $groupSubject;
            }

            $groupMetaDecoded = $this->decodeJsonArray($groupMetadata);
            if ($groupMetaDecoded === null) {
                $groupMetaDecoded = $this->decodeJsonArray($gatewayMeta['group_metadata'] ?? $gatewayMeta['groupMetadata'] ?? null);
            }
            if ($groupMetaDecoded !== null) {
                $snapshot['group_metadata'] = $groupMetaDecoded;
            }

            $participants = $this->normalizeGroupParticipants($gatewayMeta);
            if ($participants !== []) {
                $snapshot['group_participants'] = $participants;
            }
        }

        $cleaned = array_filter($snapshot, static fn($value) => $value !== null && $value !== '' && $value !== []);
        if (isset($cleaned['group_participants']) && $cleaned['group_participants'] === []) {
            unset($cleaned['group_participants']);
        }

        return $cleaned === [] ? null : $cleaned;
    }

    private function coerceSnapshotString($value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        return null;
    }

    private function coerceSnapshotBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int)$value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', 'yes', 'sim', '1', 'y'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', 'no', 'nao', 'não', '0', 'n'], true)) {
                return false;
            }
        }

        return null;
    }

    private function coerceSnapshotTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $intValue = (int)$value;
            return $intValue > 0 ? $intValue : null;
        }

        if (is_numeric($value)) {
            $intValue = (int)$value;
            return $intValue > 0 ? $intValue : null;
        }

        $parsed = strtotime((string)$value);
        return $parsed !== false ? $parsed : null;
    }

    private function decodeJsonArray($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode((string)$value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function normalizeGroupParticipants(array $gatewayMeta, int $limit = 50): array
    {
        $sources = [
            $gatewayMeta['participants'] ?? null,
            $gatewayMeta['group_participants'] ?? null,
            $gatewayMeta['groupParticipants'] ?? null,
        ];

        $groupMetaDecoded = $this->decodeJsonArray($gatewayMeta['group_metadata'] ?? $gatewayMeta['groupMetadata'] ?? null);
        if (is_array($groupMetaDecoded) && isset($groupMetaDecoded['participants'])) {
            $sources[] = $groupMetaDecoded['participants'];
        }

        $participants = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $participant = [];

                $name = $this->coerceSnapshotString($entry['name'] ?? $entry['pushname'] ?? $entry['display'] ?? null);
                if ($name !== null) {
                    $participant['name'] = $name;
                }

                $rawPhone = $entry['phone'] ?? $entry['id'] ?? $entry['jid'] ?? $entry['participant'] ?? null;
                $phoneDigits = $rawPhone !== null ? digits_only((string)$rawPhone) : '';
                if ($phoneDigits !== '') {
                    $participant['phone'] = $phoneDigits;
                }

                $isAdmin = $this->coerceSnapshotBool($entry['is_admin'] ?? $entry['isAdmin'] ?? $entry['admin'] ?? null);
                if ($isAdmin !== null) {
                    $participant['is_admin'] = $isAdmin;
                }

                $isSuperadmin = $this->coerceSnapshotBool($entry['is_superadmin'] ?? $entry['isSuperAdmin'] ?? $entry['superadmin'] ?? null);
                if ($isSuperadmin !== null) {
                    $participant['is_superadmin'] = $isSuperadmin;
                }

                if ($participant === []) {
                    continue;
                }

                $participants[] = $participant;

                if (count($participants) >= $limit) {
                    break 2;
                }
            }
        }

        return $participants;
    }

    private function normalizePresenceValue($value): ?string
    {
        $string = $this->coerceSnapshotString($value);
        if ($string === null) {
            return null;
        }

        $normalized = strtolower($string);
        $map = [
            'available' => 'online',
            'online' => 'online',
            'unavailable' => 'offline',
            'offline' => 'offline',
            'composing' => 'typing',
            'recording' => 'recording',
            'paused' => 'paused',
        ];

        return $map[$normalized] ?? $normalized;
    }

    private function logInboundDedupe(array $payload): void
    {
        try {
            $dir = storage_path('logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $payload['timestamp'] = $payload['timestamp'] ?? now();
            $payload['app_env'] = env('APP_ENV', 'local');
            $payload['app_debug'] = env('APP_DEBUG', false);

            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                @file_put_contents($dir . DIRECTORY_SEPARATOR . 'whatsapp_dedupe.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // Falha de log não deve quebrar o fluxo.
        }
    }

    private function extractAltPhone(array $thread): string
    {
        $channel = (string)($thread['channel_thread_id'] ?? '');
        if (!str_starts_with($channel, 'alt:')) {
            return '';
        }

        $payload = substr($channel, 4);
        $parts = explode(':', $payload, 2);
        $raw = $parts[1] ?? '';

        return is_string($raw) ? preg_replace('/\D+/', '', $raw) ?? '' : '';
    }

    private function findClientById(int $clientId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }

        if (!array_key_exists($clientId, $this->clientCacheById)) {
            $this->clientCacheById[$clientId] = $this->clients->find($clientId) ?: null;
        }

        return $this->clientCacheById[$clientId];
    }

    private function findClientByPhone(string $phone): ?array
    {
        $digits = digits_only($phone);
        if ($digits === '') {
            return null;
        }

        if (!array_key_exists($digits, $this->clientCacheByPhone)) {
            $this->clientCacheByPhone[$digits] = $this->clients->findByPhoneDigits($digits) ?: null;
        }

        return $this->clientCacheByPhone[$digits];
    }

    private function summarizeProtocolForClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [
                'has_protocol' => false,
                'protocol_state' => null,
                'protocol_number' => null,
                'protocol_expires_at' => null,
            ];
        }

        if (isset($this->protocolSummaryCache[$clientId])) {
            return $this->protocolSummaryCache[$clientId];
        }

        $now = time();

        // Prefer certificates table (protocol column) as ground truth.
        $certificate = $this->certificateService->latestForCustomer($clientId);
        if ($certificate) {
            $protocolNumber = (string)($certificate['protocol'] ?? '');
            $endAt = isset($certificate['end_at']) ? (int)$certificate['end_at'] : null;
            $isRevoked = (int)($certificate['is_revoked'] ?? 0) === 1;
            $statusRaw = mb_strtolower(trim((string)($certificate['status'] ?? '')), 'UTF-8');
            $statusNorm = $statusRaw;
            $activeLabels = ['ativo', 'ativo_revenda', 'active', 'valido', 'válido'];
            $inactiveLabels = ['revogado', 'revoked', 'cancelado', 'cancelada', 'expirado', 'expirada', 'inativo', 'inactive'];
            $isExpired = $endAt !== null && $endAt < $now;
            $isActiveByLabel = in_array($statusNorm, $activeLabels, true);
            $isInactiveByLabel = in_array($statusNorm, $inactiveLabels, true);
            $isActive = !$isRevoked && !$isExpired && ($isActiveByLabel || (!$isInactiveByLabel));

            $summary = [
                'has_protocol' => $protocolNumber !== '' || $certificate !== null,
                'protocol_state' => $isActive ? 'active' : 'inactive',
                'protocol_number' => $protocolNumber !== '' ? $protocolNumber : null,
                'protocol_expires_at' => $endAt,
            ];

            $this->protocolSummaryCache[$clientId] = $summary;

            return $summary;
        }

        // Fallback to legacy client_protocols table.
        $protocols = $this->clientProtocols->listByClient($clientId);

        $hasProtocol = $protocols !== [];
        $activeCandidate = null;
        $inactiveCandidate = null;

        foreach ($protocols as $protocol) {
            $expiresAt = isset($protocol['expires_at']) ? (int)$protocol['expires_at'] : null;
            $status = mb_strtolower(trim((string)($protocol['status'] ?? '')), 'UTF-8');
            $isExpired = $expiresAt !== null && $expiresAt < $now;
            $isActive = !$isExpired && ($status === '' || $status === 'active' || $status === 'ativo');

            $candidate = [
                'protocol_number' => (string)($protocol['protocol_number'] ?? ''),
                'protocol_expires_at' => $expiresAt,
                'protocol_state' => $isActive ? 'active' : 'inactive',
            ];

            if ($isActive) {
                if ($activeCandidate === null || ($expiresAt !== null && ($activeCandidate['protocol_expires_at'] ?? 0) < $expiresAt)) {
                    $activeCandidate = $candidate;
                }
            } else {
                if ($inactiveCandidate === null || ($expiresAt !== null && ($inactiveCandidate['protocol_expires_at'] ?? 0) < $expiresAt)) {
                    $inactiveCandidate = $candidate;
                }
            }
        }

        $summary = [
            'has_protocol' => $hasProtocol,
            'protocol_state' => null,
            'protocol_number' => null,
            'protocol_expires_at' => null,
        ];

        if ($activeCandidate !== null) {
            $summary['protocol_state'] = 'active';
            $summary['protocol_number'] = $activeCandidate['protocol_number'];
            $summary['protocol_expires_at'] = $activeCandidate['protocol_expires_at'];
        } elseif ($inactiveCandidate !== null) {
            $summary['protocol_state'] = 'inactive';
            $summary['protocol_number'] = $inactiveCandidate['protocol_number'];
            $summary['protocol_expires_at'] = $inactiveCandidate['protocol_expires_at'];
        }

        $this->protocolSummaryCache[$clientId] = $summary;

        return $summary;
    }

    private function summarizeClientForThread(array $client): array
    {
        $documentDigits = digits_only((string)($client['document'] ?? ''));
        $document = $documentDigits !== '' ? format_document($documentDigits) : '';
        $titularDocumentDigits = digits_only((string)($client['titular_document'] ?? ''));
        $titularDocument = $titularDocumentDigits !== '' ? format_document($titularDocumentDigits) : '';

        $protocolSummary = $this->summarizeProtocolForClient((int)($client['id'] ?? 0));

        return [
            'id' => (int)($client['id'] ?? 0),
            'name' => trim((string)($client['name'] ?? '')),
            'document' => $document,
            'titular_name' => trim((string)($client['titular_name'] ?? '')),
            'titular_document' => $titularDocument,
            'email' => trim((string)($client['email'] ?? '')),
            'phone' => format_phone((string)($client['phone'] ?? '')),
            'phone_digits' => digits_only((string)($client['phone'] ?? '')),
            'whatsapp' => format_phone((string)($client['whatsapp'] ?? '')),
            'whatsapp_digits' => digits_only((string)($client['whatsapp'] ?? '')),
            'status' => trim((string)($client['status'] ?? '')),
            'partner' => trim((string)($client['partner_accountant_plus'] ?? $client['partner_accountant'] ?? '')),
            'last_avp_name' => trim((string)($client['last_avp_name'] ?? '')),
            'next_follow_up_at' => isset($client['next_follow_up_at']) && $client['next_follow_up_at'] !== null ? (int)$client['next_follow_up_at'] : null,
            'created_at' => isset($client['created_at']) ? (int)$client['created_at'] : null,
            'updated_at' => isset($client['updated_at']) ? (int)$client['updated_at'] : null,
            'last_certificate_expires_at' => isset($client['last_certificate_expires_at']) ? (int)$client['last_certificate_expires_at'] : null,
            'pipeline_stage_id' => isset($client['pipeline_stage_id']) ? (int)$client['pipeline_stage_id'] : null,
            'has_protocol' => $protocolSummary['has_protocol'],
            'protocol_state' => $protocolSummary['protocol_state'],
            'protocol_number' => $protocolSummary['protocol_number'],
            'protocol_expires_at' => $protocolSummary['protocol_expires_at'],
        ];
    }

    private function filterOutGroupThreads(array $threads): array
    {
        return array_values(array_filter($threads, static fn(array $thread): bool => ($thread['chat_type'] ?? 'direct') !== 'group'));
    }

    private function resolveLineForThread(array $thread, ?int $lineIdOverride): ?array
    {
        if ($lineIdOverride !== null) {
            $line = $this->lines->find($lineIdOverride);
            if ($line !== null) {
                return $line;
            }
        }

        $lineId = $thread['line_id'] ?? null;
        if ($lineId !== null) {
            $line = $this->lines->find((int)$lineId);
            if ($line !== null) {
                return $line;
            }
        }

        return $this->lines->findDefault();
    }

    private function lineFromWebhookValue(array $value): ?array
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = (string)($metadata['phone_number_id'] ?? '');
        if ($phoneNumberId === '') {
            return $this->lines->findDefault();
        }

        $line = $this->lines->findByPhoneNumberId($phoneNumberId);
        return $line ?? $this->lines->findDefault();
    }

    private function selectPrimaryAltGateway(array $statuses): ?array
    {
        if ($statuses === []) {
            return null;
        }

        $default = $this->defaultAltGatewaySlug();
        if ($default !== null && isset($statuses[$default])) {
            return $statuses[$default];
        }

        return reset($statuses) ?: null;
    }

    public function altGatewayDirectory(): array
    {
        $directory = [];
        foreach ($this->altGatewayInstances() as $instance) {
            $directory[] = [
                'slug' => $instance['slug'],
                'label' => $instance['label'],
                'description' => $instance['description'],
                'base_url' => $instance['base_url'],
                'start_command' => $instance['start_command'],
                'default_line_label' => $instance['default_line_label'],
                'session_hint' => $instance['session_hint'],
                'enabled' => $instance['enabled'],
            ];
        }

        return $directory;
    }

    public function lineByAltSlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        foreach ($this->lines() as $line) {
            if (trim((string)($line['alt_gateway_instance'] ?? '')) === $slug) {
                return $line;
            }
        }

        return null;
    }

    public function altGatewayInstances(bool $onlyEnabled = false): array
    {
        $config = $this->loadAltGatewayConfig();
        $instances = $config['instances'];

        if ($onlyEnabled) {
            $instances = array_filter($instances, static fn(array $instance): bool => $instance['enabled']);
        }

        return $instances;
    }

    public function altGatewayInstance(string $slug): ?array
    {
        $sanitized = $this->sanitizeAltGatewaySlug($slug);
        if ($sanitized === null) {
            return null;
        }

        $config = $this->loadAltGatewayConfig();
        return $config['instances'][$sanitized] ?? null;
    }

    public function defaultAltGatewaySlug(): ?string
    {
        $config = $this->loadAltGatewayConfig();
        return $config['default'];
    }

    public function defaultAltGatewayInstance(): ?array
    {
        $slug = $this->defaultAltGatewaySlug();
        return $slug !== null ? $this->altGatewayInstance($slug) : null;
    }

    private function loadAltGatewayConfig(): array
    {
        if ($this->altGatewayConfig !== null) {
            return $this->altGatewayConfig;
        }

        $raw = config('whatsapp_alt_gateways', []);
        $this->altGatewayDailyLimitDefault = max(0, (int)($raw['default_daily_limit'] ?? 0));
        $this->altGatewayRotationWindowHours = max(1, (int)($raw['rotation_window_hours'] ?? 24));

        $perNumberHours = (float)($raw['per_number_window_hours'] ?? 24);
        $this->altPerNumberWindowHours = $perNumberHours > 0 ? min(240.0, $perNumberHours) : 0.01;
        $this->altPerNumberLimit = max(1, (int)($raw['per_number_limit'] ?? 1));

        $perCampaignHours = $raw['per_campaign_window_hours'] ?? null;
        $perCampaignLimit = $raw['per_campaign_limit'] ?? null;
        $this->altPerCampaignWindowHours = $perCampaignHours !== null
            ? max(0.01, min(240.0, (float)$perCampaignHours))
            : null;
        $this->altPerCampaignLimit = $perCampaignLimit !== null ? max(1, (int)$perCampaignLimit) : null;
        $instances = [];
        $rawInstances = is_array($raw['instances'] ?? null) ? $raw['instances'] : [];
        $default = isset($raw['default_instance']) ? $this->sanitizeAltGatewaySlug($raw['default_instance']) : null;

        foreach ($rawInstances as $slug => $data) {
            $normalized = $this->normalizeAltGatewayInstance((string)$slug, (array)$data);
            if ($normalized === null) {
                continue;
            }
            $instances[$normalized['slug']] = $normalized;
        }

        if ($default === null || !isset($instances[$default])) {
            $default = $instances === [] ? null : array_key_first($instances);
        }

        $this->altGatewayConfig = [
            'default' => $default,
            'instances' => $instances,
        ];

        return $this->altGatewayConfig;
    }

    private function normalizeAltGatewayInstance(string $slug, array $config): ?array
    {
        $normalizedSlug = $this->sanitizeAltGatewaySlug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        $label = trim((string)($config['label'] ?? $normalizedSlug));
        if ($label === '') {
            $label = strtoupper($normalizedSlug);
        }

        $description = trim((string)($config['description'] ?? ''));
        $baseUrl = trim((string)($config['base_url'] ?? ''));
        $startCommand = trim((string)($config['start_command'] ?? ''));
        $defaultLine = trim((string)($config['default_line_label'] ?? 'WhatsApp Web Alternativo'));
        $sessionHint = trim((string)($config['session_hint'] ?? ''));
        $dailyLimit = array_key_exists('daily_limit', $config)
            ? (int)$config['daily_limit']
            : $this->altGatewayDailyLimitDefault;
        $dailyLimit = max(0, $dailyLimit);
        $enabled = array_key_exists('enabled', $config) ? (bool)$config['enabled'] : true;

        if ($enabled === false) {
            return null;
        }

        return [
            'slug' => $normalizedSlug,
            'label' => $label,
            'description' => $description,
            'base_url' => $baseUrl,
            'start_command' => $startCommand,
            'command_token' => trim((string)($config['command_token'] ?? '')),
            'webhook_token' => trim((string)($config['webhook_token'] ?? '')),
            'default_line_label' => $defaultLine,
            'session_hint' => $sessionHint,
            'daily_limit' => $dailyLimit,
            'enabled' => $enabled,
        ];
    }

    private function sanitizeAltGatewaySlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $normalized = strtolower(trim((string)$slug));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized ?? '');
        $normalized = trim((string)$normalized, '-_');

        return $normalized !== '' ? $normalized : null;
    }

    private function probeAltGateway(array $instance): ?array
    {
        $baseUrl = rtrim((string)$instance['base_url'], '/');
        if ($baseUrl === '') {
            return null;
        }

        $url = $baseUrl . '/health';
        $ch = @\curl_init($url);
        if ($ch === false) {
            return null;
        }

        // Fail fast: avoid travar carregamento do painel quando gateway estiver offline.
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        \curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function shouldUseAltGateway(?array $thread): bool
    {
        return $this->resolveAltGatewayInstanceForThread($thread) !== null;
    }

    private function resolveAltGatewayInstanceForThread(?array $thread): ?array
    {
        $slug = $this->extractAltGatewaySlugFromThread($thread);
        return $slug !== null ? $this->altGatewayInstance($slug) : null;
    }

    private function extractAltGatewaySlugFromThread(?array $thread): ?string
    {
        if ($thread === null) {
            return null;
        }

        $channelId = (string)($thread['channel_thread_id'] ?? '');
        [$slug] = $this->parseAltChannelId($channelId);

        if ($slug !== null) {
            return $slug;
        }

        return $this->defaultAltGatewaySlug();
    }

    private function parseAltChannelId(?string $channelId): array
    {
        if (!is_string($channelId) || $channelId === '' || !str_starts_with($channelId, 'alt:')) {
            return [null, null];
        }

        $payload = substr($channelId, 4);
        $parts = explode(':', $payload);

        if (count($parts) >= 2) {
            $slug = array_shift($parts);
            $phone = implode(':', $parts);
        } else {
            $slug = null;
            $phone = $parts[0] ?? '';
        }

        $slug = $this->sanitizeAltGatewaySlug($slug);
        return [$slug, $phone];
    }

    private function buildAltChannelId(string $phone, ?string $instanceSlug = null): string
    {
        $digits = digits_only($phone);
        if ($digits === '') {
            $digits = (string)substr(bin2hex(random_bytes(6)), 0, 10);
        }

        $slug = $this->sanitizeAltGatewaySlug($instanceSlug) ?? $this->defaultAltGatewaySlug() ?? 'default';
        return 'alt:' . $slug . ':' . $digits;
    }

    private function mapAltJidToPhone(string $raw, array $meta = []): ?string
    {
        $map = config('whatsapp_alt_jid_map', []);
        if (!is_array($map) || $map === []) {
            return null;
        }

        $candidates = [$raw];
        $digitsRaw = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digitsRaw !== '') {
            $candidates[] = $digitsRaw;
        }

        $messageId = (string)($meta['meta_message_id'] ?? ($meta['message_id'] ?? ''));
        $chatId = (string)($meta['chat_id'] ?? ($meta['chatId'] ?? ''));
        if ($messageId !== '') {
            $candidates[] = $messageId;
        }
        if ($chatId !== '') {
            $candidates[] = $chatId;
        }

        foreach ($candidates as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (isset($map[$key]) && is_string($map[$key]) && trim((string)$map[$key]) !== '') {
                return trim((string)$map[$key]);
            }
        }

        return null;
    }

    private function altGatewayUsageWindowHours(): int
    {
        return max(1, $this->altGatewayRotationWindowHours);
    }

    private function altGatewayUsage(bool $forceRefresh = false): array
    {
        $now = time();
        if (!$forceRefresh && $this->altGatewayUsageCache !== null && ($this->altGatewayUsageCache['expires_at'] ?? 0) > $now) {
            return $this->altGatewayUsageCache['data'];
        }

        $since = $now - ($this->altGatewayUsageWindowHours() * 3600);
        $usage = [];
        foreach ($this->altGatewayInstances(true) as $instance) {
            $usage[$instance['slug']] = $this->messages->countOutgoingByAltGatewaySince($instance['slug'], $since);
        }

        $this->altGatewayUsageCache = [
            'expires_at' => $now + 30,
            'data' => $usage,
        ];

        return $usage;
    }

    private function buildCampaignKey(?string $campaignKind, ?string $campaignToken): ?string
    {
        $kind = trim((string)$campaignKind);
        if ($kind === '') {
            return null;
        }

        $normalizedKind = mb_strtolower($kind);
        $token = trim((string)$campaignToken);
        if ($token !== '') {
            $digits = digits_only($token);
            $token = $digits !== '' ? $digits : $token;
        }

        if ($normalizedKind === 'birthday') {
            if ($token === '') {
                return null;
            }

            return 'birthday:' . $token;
        }

        if ($token !== '') {
            return $normalizedKind . ':' . $token;
        }

        return null;
    }

    private function enforceAltDailyLimit(string $phone): void
    {
        $this->enforceAltWindowLimit($phone, null);
    }

    private function enforceAltWindowLimit(string $phone, ?int $hours = null): void
    {
        $windowHours = $hours !== null ? max(0.01, min(240, (float)$hours)) : $this->altPerNumberWindowHours;
        $limit = max(1, $this->altPerNumberLimit);

        $since = time() - (int)round($windowHours * 3600);
        $count = $this->messages->countOutgoingAltByPhoneSince($phone, $since);
        if ($count >= $limit) {
            $windowLabel = $windowHours >= 1
                ? rtrim(rtrim(number_format($windowHours, 2, ',', ''), '0'), ',') . ' horas'
                : max(1, (int)round($windowHours * 60)) . ' minutos';
            throw new RuntimeException(
                'Limite atingido: ' . $count . '/' . $limit . ' mensagens para este número nas últimas ' . $windowLabel . '. Tente novamente mais tarde ou ajuste o limite em config/whatsapp_alt_gateways.php.'
            );
        }
    }

    private function enforcePerCampaignLimit(string $campaignKey, ?string $customMessage = null): void
    {
        if ($this->altPerCampaignLimit === null || $this->altPerCampaignWindowHours === null) {
            return;
        }

        $windowSeconds = (int)round($this->altPerCampaignWindowHours * 3600);
        if ($windowSeconds <= 0 || $this->altPerCampaignLimit <= 0) {
            return;
        }

        $since = time() - $windowSeconds;
        $count = $this->messages->countOutgoingByCampaignSince($campaignKey, $since);
        if ($count >= $this->altPerCampaignLimit) {
            $message = $customMessage ?? 'Limite de campanha atingido para este período.';
            throw new RuntimeException($message);
        }
    }

    private function normalizeMediaTemplate(array $entry, string $catalog): ?array
    {
        $path = trim((string)($entry['path'] ?? $entry['file'] ?? ''));
        if ($path === '') {
            return null;
        }

        $absolute = $this->resolveTemplateAbsolutePath($path);
        if ($absolute === '' || !is_file($absolute)) {
            return null;
        }

        $id = trim((string)($entry['id'] ?? ''));
        if ($id === '') {
            $id = slugify(pathinfo($absolute, PATHINFO_FILENAME));
        }

        if ($id === '') {
            return null;
        }

        $label = trim((string)($entry['label'] ?? pathinfo($absolute, PATHINFO_FILENAME)));
        $description = trim((string)($entry['description'] ?? ''));
        $mime = (string)($entry['mime'] ?? $this->guessMimeByPath($absolute));
        $type = $this->resolveMediaType($mime, $entry['type'] ?? $catalog);
        $preview = $this->buildTemplatePublicUrl($absolute, $entry['preview'] ?? null);

        return [
            'id' => $id,
            'label' => $label !== '' ? $label : $id,
            'description' => $description,
            'mime' => $mime,
            'type' => $type,
            'catalog' => $catalog,
            'absolute_path' => $absolute,
            'relative_path' => $path,
            'size' => @filesize($absolute) ?: null,
            'preview_url' => $preview,
            'caption' => trim((string)($entry['caption'] ?? '')),
            'filename' => basename($absolute),
        ];
    }

    private function resolveTemplateAbsolutePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $startsWithDrive = (bool)preg_match('/^[a-zA-Z]:\\\\/', $trimmed);
        if ($startsWithDrive || str_starts_with($trimmed, '/') || str_starts_with($trimmed, '\\\\')) {
            return $trimmed;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        return base_path(ltrim($normalized, DIRECTORY_SEPARATOR));
    }

    private function buildTemplatePublicUrl(string $absolutePath, ?string $custom = null): ?string
    {
        if ($custom !== null && trim($custom) !== '') {
            return asset(ltrim($custom, '/'));
        }

        $publicRoot = realpath(base_path('public'));
        $realAbsolute = realpath($absolutePath);
        if ($publicRoot === false || $realAbsolute === false) {
            return null;
        }

        $normalizedRoot = rtrim(str_replace('\\\\', '/', $publicRoot), '/');
        $normalizedAbsolute = str_replace('\\\\', '/', $realAbsolute);
        if (!str_starts_with(strtolower($normalizedAbsolute), strtolower($normalizedRoot))) {
            return null;
        }

        $relative = ltrim(substr($normalizedAbsolute, strlen($normalizedRoot)), '/');
        return $relative !== '' ? asset($relative) : null;
    }

    private function guessMimeByPath(string $path): string
    {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'mp4' => 'video/mp4',
            '3gp' => 'video/3gpp',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function loadTemplateMedia(?array $selection, array $thread): ?array
    {
        if ($selection === null) {
            return null;
        }

        $kind = trim((string)($selection['kind'] ?? ''));
        $key = trim((string)($selection['key'] ?? ''));
        if ($kind === '' || $key === '') {
            return null;
        }

        if (!$this->shouldUseAltGateway($thread)) {
            throw new RuntimeException('Figurinhas e anexos rápidos estão disponíveis apenas nas linhas conectadas ao gateway alternativo.');
        }

        $templates = $this->mediaTemplates();
        $catalog = $templates[$kind] ?? null;
        if ($catalog === null) {
            throw new RuntimeException('Modelo de mídia selecionado é inválido.');
        }

        foreach ($catalog as $template) {
            if (($template['id'] ?? '') === $key) {
                return $this->processTemplateMedia($template);
            }
        }

        throw new RuntimeException('Modelo de mídia configurado não foi encontrado.');
    }



public function processBroadcast(int $broadcastId): array
{
    $record = $this->broadcasts->find($broadcastId);
    if ($record === null) {
        throw new RuntimeException('Broadcast não encontrado.');
    }

    $actor = $this->buildBroadcastActor((int)($record['initiated_by'] ?? 0));
    return $this->runBroadcastNow($record, $actor);
}

public function processPendingBroadcasts(int $limit = 1): array
{
    $rows = $this->broadcasts->listByStatus('pending', max(1, $limit));
    $results = [];
    foreach ($rows as $row) {
        try {
            $actor = $this->buildBroadcastActor((int)($row['initiated_by'] ?? 0));
        } catch (\Throwable $exception) {
            $identifier = (int)($row['id'] ?? 0);
            if ($identifier > 0) {
                $this->broadcasts->update($identifier, [
                    'status' => 'failed',
                    'completed_at' => now(),
                    'last_error' => $exception->getMessage(),
                ]);
            }
            continue;
        }

        $results[] = $this->runBroadcastNow($row, $actor);
    }

    return $results;
}

private function runBroadcastNow(array $record, AuthenticatedUser $actor, ?array $preloadedThreadIds = null): array
{
    $broadcastId = (int)($record['id'] ?? 0);
    if ($broadcastId <= 0) {
        throw new RuntimeException('Broadcast inválido.');
    }

    $criteria = $this->decodeBroadcastCriteria($record);
    $threadIds = $preloadedThreadIds ?? $this->extractThreadIdsFromCriteria($criteria);

    if ($threadIds === []) {
        $queues = isset($criteria['queues']) && is_array($criteria['queues']) ? $criteria['queues'] : self::QUEUES;
        $limit = (int)($criteria['limit'] ?? 200);
        $threadIds = $this->resolveBroadcastTargets($queues, $limit > 0 ? $limit : 200);
        $criteria['thread_ids'] = $threadIds;
    }

    $templateSelection = null;
    $kind = trim((string)($record['template_kind'] ?? ''));
    $key = trim((string)($record['template_key'] ?? ''));
    if ($kind !== '' && $key !== '') {
        $templateSelection = ['kind' => $kind, 'key' => $key];
    } elseif (isset($criteria['template']) && is_array($criteria['template'])) {
        $candidate = $criteria['template'];
        $candidateKind = trim((string)($candidate['kind'] ?? ''));
        $candidateKey = trim((string)($candidate['key'] ?? ''));
        if ($candidateKind !== '' && $candidateKey !== '') {
            $templateSelection = ['kind' => $candidateKind, 'key' => $candidateKey];
        }
    }

    $this->broadcasts->update($broadcastId, [
        'status' => 'running',
        'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'last_error' => null,
    ]);

    return $this->executeBroadcastLoop(
        $broadcastId,
        $threadIds,
        $actor,
        (string)($record['message'] ?? ''),
        $templateSelection,
        $criteria
    );
}

private function executeBroadcastLoop(
    int $broadcastId,
    array $threadIds,
    AuthenticatedUser $actor,
    string $message,
    ?array $templateSelection,
    array $criteria
): array {
    $stats = [
        'total' => count($threadIds),
        'sent' => 0,
        'failed' => 0,
        'limit_skipped' => 0,
    ];
    $errors = [];
    $lineLimitHits = [];

    foreach ($threadIds as $rawThreadId) {
        $threadId = (int)$rawThreadId;
        if ($threadId <= 0) {
            continue;
        }

        $thread = $this->threads->findWithRelations($threadId);
        if ($thread === null) {
            $stats['failed']++;
            continue;
        }

        $lineId = (int)($thread['line_id'] ?? 0);
        if ($lineId > 0 && isset($lineLimitHits[$lineId])) {
            $stats['limit_skipped']++;
            continue;
        }

        try {
            $this->sendMessage($threadId, $message, $actor, null, $templateSelection);
            $stats['sent']++;
        } catch (\Throwable $exception) {
            $stats['failed']++;
            if ($lineId > 0 && $this->isRateLimitError($exception->getMessage())) {
                $lineLimitHits[$lineId] = true;
            }
            if (count($errors) < 10) {
                $errors[] = [
                    'thread_id' => $threadId,
                    'error' => $exception->getMessage(),
                ];
            }
        }
    }

    if (!empty($lineLimitHits)) {
        $criteria['limit_notes'] = array_values(array_unique(array_map('intval', array_keys($lineLimitHits))));
    }

    $status = $this->determineBroadcastStatusFromStats($stats);
    $this->broadcasts->update($broadcastId, [
        'status' => $status,
        'stats_total' => $stats['total'],
        'stats_sent' => $stats['sent'],
        'stats_failed' => $stats['failed'],
        'completed_at' => now(),
        'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'last_error' => $errors !== [] ? json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);

    return [
        'stats' => $stats,
        'broadcast' => $this->broadcasts->find($broadcastId),
    ];
}

private function decodeBroadcastCriteria(array $record): array
{
    $raw = $record['criteria'] ?? [];
    if (is_array($raw)) {
        return $raw;
    }
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

private function extractThreadIdsFromCriteria(array &$criteria): array
{
    $stored = $criteria['thread_ids'] ?? [];
    if (!is_array($stored)) {
        return [];
    }

    $ids = array_values(array_filter(array_map(static fn($value) => (int)$value, $stored), static fn(int $id): bool => $id > 0));
    $criteria['thread_ids'] = $ids;

    return $ids;
}

private function determineBroadcastStatusFromStats(array $stats): string
{
    if (($stats['sent'] ?? 0) === 0 && ($stats['failed'] ?? 0) > 0) {
        return 'failed';
    }

    if (($stats['failed'] ?? 0) > 0) {
        return 'completed_with_errors';
    }

    return 'completed';
}

private function shouldProcessBroadcastInBackground(int $audienceSize, string $mode): bool
{
    $normalized = strtolower(trim($mode));
    if (in_array($normalized, ['sync', 'immediate'], true)) {
        return false;
    }
    if (in_array($normalized, ['async', 'queued', 'background'], true)) {
        return true;
    }

    return $audienceSize >= self::BROADCAST_BACKGROUND_THRESHOLD;
}

private function queueBroadcastWorker(int $broadcastId): void
{
    $script = base_path('scripts/whatsapp/process_broadcast.php');
    if (!is_file($script)) {
        return;
    }

    $phpBinary = PHP_BINARY;
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' --broadcast=' . (int)$broadcastId;

    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        @pclose(@popen('start "" /B ' . $command, 'r'));
        return;
    }

    @pclose(@popen($command . ' > /dev/null 2>&1 &', 'r'));
}

private function buildBroadcastActor(int $userId): AuthenticatedUser
{
    if ($userId <= 0) {
        throw new RuntimeException('Usuário responsável pelo comunicado não foi informado.');
    }

    $user = $this->users->find($userId);
    if ($user === null) {
        throw new RuntimeException('Usuário responsável pelo comunicado não está mais ativo.');
    }

    $permissions = $this->decodeUserPermissions($user['permissions'] ?? []);

    return new AuthenticatedUser(
        $userId,
        (string)($user['name'] ?? 'Usuário'),
        (string)($user['email'] ?? ''),
        (string)($user['role'] ?? 'user'),
        'broadcast-worker:' . $userId,
        $user['cpf'] ?? null,
        $permissions
    );
}

private function decodeUserPermissions(mixed $payload): array
{
    if (is_array($payload)) {
        return array_values(array_map(static fn($permission): string => (string)$permission, $payload));
    }

    if (is_string($payload) && $payload !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return array_values(array_map(static fn($permission): string => (string)$permission, $decoded));
        }
    }

    return [];
}

private function isRateLimitError(string $message): bool
{
    $normalized = mb_strtolower($message, 'UTF-8');
    return str_contains($normalized, 'limitador desta linha')
        || str_contains($normalized, 'limitador anti')
        || str_contains($normalized, 'limite de mensagens');
}
    private function processTemplateMedia(array $template): array
    {
        $absolutePath = (string)($template['absolute_path'] ?? '');
        if ($absolutePath === '' || !is_file($absolutePath)) {
            throw new RuntimeException('Arquivo do modelo configurado não está disponível.');
        }

        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException('Falha ao ler o arquivo do modelo configurado.');
        }

        $mime = (string)($template['mime'] ?? 'application/octet-stream');
        $type = $this->resolveMediaType($mime, $template['type'] ?? null);
        $filename = $template['filename'] ?? basename($absolutePath);
        $storage = $this->persistMediaBinary($contents, $mime, $type, $filename);
        $caption = trim((string)($template['caption'] ?? ''));
        if ($caption !== '') {
            $storage['caption'] = $caption;
        }

        $dispatch = [
            'type' => $type,
            'mimetype' => $mime,
            'filename' => $storage['original_name'] ?? $filename,
            'data' => base64_encode($contents),
        ];

        if ($caption !== '') {
            $dispatch['caption'] = $caption;
        }

        return [
            'storage' => $storage,
            'dispatch' => $dispatch,
        ];
    }

    private function resolveBroadcastTargets(array $queues, int $limit): array
    {
        $queues = array_values(array_unique(array_filter(array_map(static function ($queue) {
            return strtolower(trim((string)$queue));
        }, $queues))));

        if ($queues === []) {
            return [];
        }

        $targets = [];
        $queueTargets = array_values(array_filter($queues, static fn(string $queue): bool => $queue !== 'groups'));
        if ($queueTargets !== []) {
            $targets = $this->threads->listIdsForQueues($queueTargets, $limit);
        }

        if (in_array('groups', $queues, true)) {
            $remaining = max(0, $limit - count($targets));
            if ($remaining > 0) {
                $targets = array_merge($targets, $this->threads->listGroupIds($remaining));
            }
        }

        $targets = array_values(array_unique(array_map('intval', $targets)));
        return array_slice($targets, 0, $limit);
    }

    private function resolveMediaType(string $mime, ?string $declared = null): string
    {
        $declared = strtolower((string)$declared);
        if (in_array($declared, ['image', 'video', 'audio', 'document'], true)) {
            return $declared;
        }
        $normalizedMime = strtolower($mime);
        if (str_starts_with($normalizedMime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($normalizedMime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($normalizedMime, 'audio/')) {
            return 'audio';
        }
        return 'document';
    }

    private function resolveMediaExtension(string $mime, ?string $originalName = null): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
        ];

        $normalizedMime = strtolower($mime);
        if (isset($map[$normalizedMime])) {
            return $map[$normalizedMime];
        }

        if ($originalName && str_contains($originalName, '.')) {
            return strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        }

        if (str_starts_with($normalizedMime, 'image/')) {
            return substr($normalizedMime, 6);
        }
        if (str_starts_with($normalizedMime, 'video/')) {
            return substr($normalizedMime, 6);
        }
        if (str_starts_with($normalizedMime, 'audio/')) {
            return substr($normalizedMime, 6);
        }

        return 'bin';
    }

    private function persistMediaBinary(string $binary, string $mime, string $type, ?string $originalName = null): array
    {
        $relativeDir = 'whatsapp-media/' . date('Y/m');
        $directory = storage_path($relativeDir);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o diretório de mídia.');
        }

        $extension = $this->resolveMediaExtension($mime, $originalName);
        $filename = uniqid('wa_', true) . '.' . $extension;
        $relativePath = $relativeDir . '/' . $filename;
        $absolutePath = storage_path($relativePath);

        if (@file_put_contents($absolutePath, $binary) === false) {
            throw new RuntimeException('Falha ao salvar o arquivo de mídia.');
        }

        return [
            'type' => $type,
            'mime' => $mime,
            'size' => strlen($binary),
            'original_name' => $originalName ? basename($originalName) : null,
            'path' => $relativePath,
        ];
    }

    private function decodeBase64Media(?string $data): ?string
    {
        if (!is_string($data)) {
            return null;
        }
        $payload = trim($data);
        if ($payload === '') {
            return null;
        }
        if (str_starts_with($payload, 'data:')) {
            $parts = explode('base64,', $payload, 2);
            $payload = $parts[1] ?? '';
        }
        $payload = preg_replace('/\s+/', '', $payload);
        if ($payload === '') {
            return null;
        }
        $decoded = base64_decode($payload, true);
        return $decoded === false ? null : $decoded;
    }

    private function handleIncomingMediaPayload(array $payload): ?array
    {
        $binary = $this->decodeBase64Media($payload['data'] ?? '');
        if ($binary === null) {
            return null;
        }
        $mime = (string)($payload['mimetype'] ?? 'application/octet-stream');
        $type = $this->resolveMediaType($mime, $payload['type'] ?? null);
        $storage = $this->persistMediaBinary($binary, $mime, $type, $payload['filename'] ?? null);
        if (!empty($payload['caption'])) {
            $storage['caption'] = $payload['caption'];
        }
        return $storage;
    }

    private function processUploadedMedia(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Falha ao processar o arquivo enviado.');
        }
        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }
        $mime = $this->safeUploadedMime($file, $contents);
        $type = $this->resolveMediaType($mime, $file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName() ?: null;
        $storage = $this->persistMediaBinary($contents, $mime, $type, $originalName);
        $storage['original_name'] = $originalName ? basename($originalName) : ($storage['original_name'] ?? null);

        return [
            'storage' => $storage,
            'dispatch' => [
                'type' => $type,
                'mimetype' => $mime,
                'filename' => $storage['original_name'] ?? basename($storage['path']),
                'data' => base64_encode($contents),
            ],
        ];
    }

    private function safeUploadedMime(UploadedFile $file, string $contents): string
    {
        try {
            $guessed = $file->getMimeType();
            if (is_string($guessed) && $guessed !== '') {
                return $guessed;
            }
        } catch (\Throwable $e) {
            // Fallback paths below.
        }

        $clientMime = $file->getClientMimeType();
        if (is_string($clientMime) && $clientMime !== '') {
            return $clientMime;
        }

        $finfo = function_exists('finfo_buffer') ? @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $contents) : false;
        if (is_string($finfo) && $finfo !== '') {
            return $finfo;
        }

        $mime = @mime_content_type($file->getRealPath());
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        return 'application/octet-stream';
    }

    private function sanitizeMessageMetadata($rawMetadata, int $messageId): array
    {
        $decoded = [];
        if (is_array($rawMetadata)) {
            $decoded = $rawMetadata;
        } elseif (is_string($rawMetadata) && trim($rawMetadata) !== '') {
            $decoded = json_decode((string)$rawMetadata, true) ?? [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $allowedKeys = [
            'actor_id',
            'actor_name',
            'actor_identifier',
            'mentions',
            'template_kind',
            'template_key',
            'copilot_status',
            'note',
            'line_id',
            'gateway_instance',
            'message_source',
            'ai_summary',
            'error',
            'ack',
            'reply_to',
            'forwarded',
            'history',
            'channel_thread_id',
            'wa_id',
            'chat_id',
            'presence',
            'about',
            'last_seen_at',
            'profile_photo_etag',
            'group_subject',
            'group_metadata',
            'group_participants',
            'is_business',
            'business_name',
            'business_description',
            'business_category',
            'business_hours',
            'gateway_snapshot',
            'raw',
            'participant',
            'group_participant',
        ];

        $meta = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                $meta[$key] = $decoded[$key];
            }
        }

        $media = $decoded['media'] ?? null;
        $mediaMeta = $this->sanitizeMediaMetadata($media, $messageId);
        if ($mediaMeta !== null) {
            $meta['media'] = $mediaMeta;
        }

        return $meta;
    }

    private function sanitizeMediaMetadata($rawMedia, int $messageId): ?array
    {
        if (!is_array($rawMedia)) {
            return null;
        }

        $allowedMediaKeys = ['type', 'mimetype', 'mime', 'caption', 'size', 'original_name', 'path'];
        $media = [];
        foreach ($allowedMediaKeys as $key) {
            if (array_key_exists($key, $rawMedia) && $rawMedia[$key] !== null && $rawMedia[$key] !== '') {
                $media[$key] = $rawMedia[$key];
            }
        }

        if (isset($media['size']) && is_numeric($media['size'])) {
            $media['size'] = (int)$media['size'];
        }

        if (!empty($rawMedia['url'])) {
            $media['url'] = (string)$rawMedia['url'];
        }
        if (!empty($rawMedia['download_url'])) {
            $media['download_url'] = (string)$rawMedia['download_url'];
        }

        if (!isset($media['url']) && $messageId > 0) {
            $baseUrl = url('whatsapp/media/' . $messageId);
            $media['url'] = $baseUrl;
            $media['download_url'] = $baseUrl . '?download=1';
        } elseif (isset($media['url']) && !isset($media['download_url'])) {
            $media['download_url'] = $media['url'] . '?download=1';
        }

        return $media === [] ? null : $media;
    }

    private function formatMessageForClient(?array $message): ?array
    {
        if ($message === null) {
            return null;
        }

        $meta = $this->sanitizeMessageMetadata($message['metadata'] ?? null, (int)($message['id'] ?? 0));

        return [
            'id' => (int)$message['id'],
            'direction' => (string)$message['direction'],
            'content' => (string)$message['content'],
            'message_type' => (string)$message['message_type'],
            'sent_at' => ((int)$message['sent_at']) * 1000,
            'metadata' => $meta,
            'actor' => $meta['actor_identifier'] ?? ($meta['actor_name'] ?? null),
            'status' => (string)($message['status'] ?? ''),
        ];
    }

    private function buildActorClientLabel(AuthenticatedUser $actor): ?string
    {
        $identifier = trim((string)$actor->chatIdentifier);
        $name = $this->resolveUserDisplayName($actor);

        $hasAlias = trim((string)($actor->chatDisplayName ?? '')) !== '';

        if ($identifier !== '' && !$hasAlias && $name !== '') {
            return $identifier . ' - ' . $name;
        }

        if ($identifier !== '' && !$hasAlias && $name === '') {
            return $identifier;
        }

        return $name !== '' ? $name : null;
    }

    private function decorateOutgoingBody(string $body, AuthenticatedUser $actor): string
    {
        $label = $this->buildActorClientLabel($actor);
        $normalized = trim($body);

        if ($label === null || $normalized === '') {
            return $normalized;
        }

        $formattedLabel = '*_' . $label . '_*';

        $haystack = mb_strtolower($normalized, 'UTF-8');
        $needles = [
            mb_strtolower($label, 'UTF-8'),
            mb_strtolower($formattedLabel, 'UTF-8'),
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return $normalized;
            }
        }

        return $formattedLabel . ":\n" . $normalized;
    }

    private function altGatewayCandidates(array $thread, int $limit = 3): array
    {
        $instances = $this->altGatewayInstances(true);
        if ($instances === []) {
            return [];
        }

        $usage = $this->altGatewayUsage();
        $current = $this->extractAltGatewaySlugFromThread($thread);
        $family = $this->altGatewayFamily($current);
        if ($family !== null) {
            $instances = array_values(array_filter($instances, function (array $instance) use ($family): bool {
                return $this->altGatewayFamily($instance['slug']) === $family;
            }));

            if ($instances === []) {
                throw new RuntimeException('Nenhum gateway habilitado para o canal informado.');
            }
        }

        $lastUsed = $this->lastAltGatewaySelected;

        $candidates = [];
        foreach ($instances as $instance) {
            $slug = $instance['slug'];
            $sentCount = $usage[$slug] ?? 0;
            $limitPerDay = max(0, (int)($instance['daily_limit'] ?? 0));
            $available = $limitPerDay === 0 || $sentCount < $limitPerDay;

            $candidates[] = [
                'slug' => $slug,
                'usage' => $sentCount,
                'limit' => $limitPerDay,
                'available' => $available,
                'is_current' => $current !== null && $current === $slug,
                'is_last_used' => $lastUsed !== null && $lastUsed === $slug,
            ];
        }

        $available = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['available']));
        if ($available === []) {
            throw new RuntimeException('Limite diário dos gateways alternativos atingido. Ative outro gateway (lab03/lab04/lab05) ou aumente o limite em config/whatsapp_alt_gateways.php.');
        }

        usort($available, function (array $a, array $b): int {
            if ($a['is_current'] !== $b['is_current']) {
                return $a['is_current'] ? -1 : 1;
            }

            if ($a['usage'] !== $b['usage']) {
                return $a['usage'] <=> $b['usage'];
            }

            if ($a['is_last_used'] !== $b['is_last_used']) {
                return $a['is_last_used'] ? 1 : -1;
            }

            return $a['slug'] <=> $b['slug'];
        });

        $ordered = array_column($available, 'slug');

        return array_slice($ordered, 0, max(1, $limit));
    }

    private function altGatewayFamily(?string $slug): ?string
    {
        $normalized = $this->sanitizeAltGatewaySlug($slug);
        if ($normalized === null) {
            return null;
        }

        if (str_starts_with($normalized, 'wpp')) {
            return 'wpp';
        }

        if (str_starts_with($normalized, 'lab')) {
            return 'lab';
        }

        return 'other';
    }

    private function dispatchAltGatewayMessage(string $phone, string $body, array $thread, ?array $media = null): array
    {
        $attempted = [];
        $currentThread = $thread;
        $lastError = 'alt_gateway_unavailable';
        $lastStatus = null;
        $lastRaw = null;

        try {
            $candidates = $this->altGatewayCandidates($thread, 3);
        } catch (RuntimeException $exception) {
            throw $exception;
        }

        foreach ($candidates as $candidateSlug) {
            $instance = $this->altGatewayInstance($candidateSlug);
            if ($instance === null) {
                $attempted[] = ['slug' => $candidateSlug, 'status' => 'skipped', 'error' => 'alt_gateway_missing'];
                continue;
            }

            if (!$instance['enabled']) {
                $attempted[] = ['slug' => $candidateSlug, 'status' => 'skipped', 'error' => 'alt_gateway_disabled'];
                continue;
            }

            $result = $this->dispatchAltGatewayMessageSingle($instance, $phone, $body, $media);
            $result['gateway_instance'] = $candidateSlug;
            $attempted[] = [
                'slug' => $candidateSlug,
                'status' => $result['status'] ?? 'error',
                'error' => $result['error'] ?? null,
                'http_status' => $result['http_status'] ?? null,
            ];

            if (($result['status'] ?? 'error') === 'sent') {
                $targetChannelId = $this->buildAltChannelId($phone, $candidateSlug);
                if ((string)($currentThread['channel_thread_id'] ?? '') !== $targetChannelId) {
                    $this->threads->update((int)$currentThread['id'], ['channel_thread_id' => $targetChannelId]);
                    $currentThread['channel_thread_id'] = $targetChannelId;
                }

                $this->lastAltGatewaySelected = $candidateSlug;
                $this->altGatewayUsageCache = null;

                return array_merge($result, [
                    'thread' => $currentThread,
                    'attempted' => $attempted,
                ]);
            }

            $lastError = $result['error'] ?? $lastError;
            $lastStatus = $result['http_status'] ?? $lastStatus;
            $lastRaw = $result['raw_response'] ?? $lastRaw;
        }

        return [
            'status' => 'error',
            'error' => $lastError,
            'http_status' => $lastStatus,
            'raw_response' => $lastRaw,
            'gateway_instance' => $this->extractAltGatewaySlugFromThread($currentThread),
            'thread' => $currentThread,
            'attempted' => $attempted,
        ];
    }

    private function dispatchAltGatewayMessageSingle(array $instance, string $phone, string $body, ?array $media = null): array
    {
        $baseUrl = rtrim((string)$instance['base_url'], '/');
        $token = (string)$instance['command_token'];

        if ($baseUrl === '' || $token === '') {
            return [
                'status' => 'error',
                'error' => 'alt_gateway_unconfigured',
            ];
        }

        $payload = [
            'phone' => digits_only($phone),
            'message' => $body,
        ];
        if ($media !== null) {
            $payload['media'] = $this->normalizeMediaForGateway($media);
        }

        $response = $this->performHttpRequest('POST', $baseUrl . '/send-message', $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Gateway-Token: ' . $token,
        ]);

        if ($response['success'] === false) {
            $this->backupGatewayOutgoing([
                'phone' => $payload['phone'] ?? $phone,
                'message' => $payload['message'] ?? $body,
                'media' => $media,
                'instance' => $instance['slug'] ?? null,
                'line_label' => $instance['label'] ?? null,
                'meta' => [
                    'gateway_instance' => $instance['slug'] ?? null,
                    'line_label' => $instance['label'] ?? null,
                    'status' => 'error',
                ],
                'raw' => [
                    'request' => $payload,
                    'response' => $response['body'] ?? null,
                    'raw_response' => $response['raw_response'] ?? null,
                    'http_success' => false,
                    'http_error' => $response['error'] ?? null,
                    'http_status' => $response['status_code'] ?? null,
                ],
            ]);

            AlertService::push('whatsapp.gateway_http', 'Falha ao enviar via gateway alternativo.', [
                'instance' => $instance['slug'] ?? null,
                'status' => $response['status_code'] ?? null,
                'error' => $response['error'] ?? null,
                'phone' => $payload['phone'] ?? $phone,
                'raw_response' => $response['raw_response'] ?? null,
            ]);

            return [
                'status' => 'error',
                'error' => $response['error'],
                'response' => $response['body'] ?? null,
                'raw_response' => $response['raw_response'] ?? null,
                'http_status' => $response['status_code'] ?? null,
            ];
        }

        $bodyResponse = $response['body'] ?? [];

        $this->backupGatewayOutgoing([
            'phone' => $payload['phone'] ?? $phone,
            'message' => $payload['message'] ?? $body,
            'media' => $media,
            'instance' => $instance['slug'] ?? null,
            'line_label' => $instance['label'] ?? null,
            'meta' => [
                'gateway_instance' => $instance['slug'] ?? null,
                'line_label' => $instance['label'] ?? null,
                'status' => 'sent',
                'meta_message_id' => $bodyResponse['message_id'] ?? $bodyResponse['messageId'] ?? null,
            ],
            'raw' => [
                'request' => $payload,
                'response' => $bodyResponse,
                'raw_response' => $response['raw_response'] ?? null,
                'http_success' => true,
                'http_status' => $response['status_code'] ?? null,
            ],
        ]);

        return [
            'status' => 'sent',
            'meta_message_id' => $bodyResponse['message_id'] ?? $bodyResponse['messageId'] ?? null,
            'response' => $bodyResponse,
            'http_status' => $response['status_code'] ?? null,
        ];
    }

    /**
     * Ajusta mídia (especialmente áudio) para o formato esperado pelo gateway alt.
     * - Força áudio para OGG/Opus quando possível.
     * - Garante extensão .ogg e mimetype consistente.
     * - Mantém outros tipos intactos.
     * @param array<string,mixed> $media
     * @return array<string,mixed>
     */
    private function normalizeMediaForGateway(array $media): array
    {
        $type = strtolower((string)($media['type'] ?? $media['mimetype'] ?? ''));
        if ($type === '' && isset($media['mime'])) {
            $type = strtolower((string)$media['mime']);
        }

        // Apenas áudio recebe normalização extra
        if (str_contains($type, 'audio')) {
            $normalized = $media;
            $normalized['type'] = 'audio';
            $normalized['mimetype'] = 'audio/ogg; codecs=opus';

            if (!empty($media['filename']) && is_string($media['filename'])) {
                $normalized['filename'] = preg_replace('/\.[^.]+$/', '.ogg', $media['filename']) ?? $media['filename'];
            } elseif (!empty($media['original_name']) && is_string($media['original_name'])) {
                $normalized['filename'] = preg_replace('/\.[^.]+$/', '.ogg', $media['original_name']) ?? $media['original_name'];
            } else {
                $normalized['filename'] = 'audio-message.ogg';
            }

            // Hint para gateways que diferenciam áudio de voz
            $normalized['ptt'] = true;

            return $normalized;
        }

        return $media;
    }

    private function detectAltGatewaySlug(array $metadata): ?string
    {
        $explicit = $this->sanitizeAltGatewaySlug($metadata['gateway_instance'] ?? null);
        if ($explicit !== null && $this->altGatewayInstance($explicit) !== null) {
            return $explicit;
        }

        $session = strtolower(trim((string)($metadata['session'] ?? $metadata['session_hint'] ?? '')));
        if ($session !== '') {
            foreach ($this->altGatewayInstances() as $instance) {
                if ($instance['session_hint'] !== '' && strtolower($instance['session_hint']) === $session) {
                    return $instance['slug'];
                }
            }
        }

        $origin = strtolower(trim((string)($metadata['origin'] ?? $metadata['source'] ?? '')));
        if (str_contains($origin, ':')) {
            [$prefix, $maybeSlug] = explode(':', $origin, 2);
            if ($prefix === 'whatsapp_web_alt') {
                $candidate = $this->sanitizeAltGatewaySlug($maybeSlug);
                if ($candidate !== null && $this->altGatewayInstance($candidate) !== null) {
                    return $candidate;
                }
            }
        } elseif ($origin === 'whatsapp_web_alt') {
            return $this->defaultAltGatewaySlug();
        }

        return $this->defaultAltGatewaySlug();
    }

    private function sanitizeLinePayload(array $input, bool $requireToken = true): array
    {
        $provider = strtolower(trim((string)($input['provider'] ?? 'meta')));
        $allowedProviders = ['meta', 'commercial', 'dialog360', 'sandbox'];
        if (!in_array($provider, $allowedProviders, true)) {
            $provider = 'meta';
        }

        $apiBase = trim((string)($input['api_base_url'] ?? ''));
        if ($apiBase !== '') {
            $apiBase = rtrim($apiBase, '/');
        }

        if ($provider === 'dialog360' && $apiBase === '') {
            $apiBase = 'https://waba.360dialog.io';
        } elseif (in_array($provider, ['meta', 'commercial'], true) && $apiBase === '') {
            $apiBase = 'https://graph.facebook.com/v19.0';
        }

        $rateLimitEnabled = !empty($input['rate_limit_enabled']);
        $windowSeconds = (int)($input['rate_limit_window_seconds'] ?? 3600);
        if ($windowSeconds < 60) {
            $windowSeconds = 60;
        }
        $maxMessages = (int)($input['rate_limit_max_messages'] ?? 500);
        if ($maxMessages < 1) {
            $maxMessages = 1;
        }

        $altGatewayInstance = $input['alt_gateway_instance'] ?? null;
        if (is_string($altGatewayInstance) && trim($altGatewayInstance) !== '') {
            $altGatewayInstance = $this->sanitizeAltGatewaySlug($altGatewayInstance);
        } else {
            $altGatewayInstance = null;
        }

        $payload = [
            'label' => trim((string)($input['label'] ?? 'Linha WhatsApp')),
            'phone_number_id' => trim((string)($input['phone_number_id'] ?? '')),
            'display_phone' => trim((string)($input['display_phone'] ?? '')),
            'business_account_id' => trim((string)($input['business_account_id'] ?? '')),
            'access_token' => trim((string)($input['access_token'] ?? '')),
            'verify_token' => trim((string)($input['verify_token'] ?? '')),
            'provider' => $provider,
            'api_base_url' => $apiBase !== '' ? $apiBase : null,
            'is_default' => (bool)($input['is_default'] ?? false),
            'status' => $input['status'] ?? 'active',
            'rate_limit_enabled' => $rateLimitEnabled,
            'rate_limit_window_seconds' => $windowSeconds,
            'rate_limit_max_messages' => $maxMessages,
            'alt_gateway_instance' => $altGatewayInstance,
        ];

        if ($payload['label'] === '') {
            throw new RuntimeException('Informe um rótulo para identificar a linha.');
        }

        if ($provider === 'sandbox') {
            if ($payload['phone_number_id'] === '') {
                $payload['phone_number_id'] = 'sandbox-' . bin2hex(random_bytes(4));
            }
            if ($payload['display_phone'] === '') {
                $payload['display_phone'] = 'Sandbox';
            }
            if ($payload['business_account_id'] === '') {
                $payload['business_account_id'] = 'sandbox';
            }
            if ($payload['access_token'] === '') {
                $payload['access_token'] = 'sandbox';
            }
            if ($payload['verify_token'] === '') {
                $payload['verify_token'] = null;
            }
        } else {
            $mandatory = ['phone_number_id', 'display_phone'];
            if (in_array($provider, ['meta', 'commercial'], true)) {
                $mandatory[] = 'business_account_id';
            }
            foreach ($mandatory as $field) {
                if ($payload[$field] === '') {
                    throw new RuntimeException('Campo obrigatório ausente: ' . $field);
                }
            }
            if ($requireToken && $payload['access_token'] === '') {
                throw new RuntimeException('Informe o Access Token emitido pelo provedor selecionado.');
            }
        }

        return $payload;
    }

    private function dispatchSandboxMessage(array $line, string $to, string $body): array
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'whatsapp-sandbox.log';
        $record = [
            'timestamp' => now(),
            'line_id' => $line['id'] ?? null,
            'to' => $to,
            'message' => $body,
        ];
        $serialized = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($logFile, $serialized . PHP_EOL, FILE_APPEND);

        return [
            'status' => 'sent',
            'meta_message_id' => 'sandbox-' . bin2hex(random_bytes(4)),
            'response' => ['provider' => 'sandbox'],
        ];
    }

    private function enforceLineRateLimit(array $line, ?array $thread = null): void
    {
        if (empty($line['rate_limit_enabled'])) {
            return;
        }

        if (!$this->shouldCountAgainstRateLimit($thread)) {
            return;
        }

        $lineId = (int)($line['id'] ?? 0);
        if ($lineId <= 0) {
            return;
        }

        $window = max(60, (int)($line['rate_limit_window_seconds'] ?? 0));
        $maxMessages = max(1, (int)($line['rate_limit_max_messages'] ?? 0));
        if ($window <= 0 || $maxMessages <= 0) {
            return;
        }

        $key = 'whatsapp_line_limit_' . $lineId;
        $state = $this->settings->get($key, null);
        if (!is_array($state)) {
            $state = [];
        }

        $windowStart = (int)($state['window_started_at'] ?? 0);
        $count = (int)($state['count'] ?? 0);
        $now = now();

        if ($windowStart === 0 || $now - $windowStart >= $window) {
            $windowStart = $now;
            $count = 0;
        }

        if ($count >= $maxMessages) {
            $resetIn = max(1, ($windowStart + $window) - $now);
            $message = sprintf(
                'O limitador desta linha atingiu %d envios na janela de %s. Aguarde %s e tente novamente.',
                $maxMessages,
                $this->humanizeInterval($window),
                $this->humanizeInterval($resetIn)
            );
            throw new RuntimeException($message);
        }

        $count++;
        $this->settings->set($key, [
            'window_started_at' => $windowStart,
            'count' => $count,
        ]);
    }

    private function shouldCountAgainstRateLimit(?array $thread): bool
    {
        if ($thread === null) {
            return true;
        }

        $threadId = (int)($thread['id'] ?? 0);
        if ($threadId <= 0) {
            return true;
        }

        $lastIncomingAt = $this->messages->lastMessageTimestamp($threadId, 'incoming');
        if ($lastIncomingAt !== null) {
            $elapsed = now() - $lastIncomingAt;
            if ($elapsed <= self::SESSION_WINDOW_SECONDS) {
                return false;
            }
        }

        return true;
    }

    private function humanizeInterval(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'alguns segundos';
        }

        if ($seconds < 60) {
            return $seconds . ' segundo' . ($seconds === 1 ? '' : 's');
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            $label = $minutes . ' minuto' . ($minutes === 1 ? '' : 's');
            if ($remainingSeconds > 0) {
                $label .= ' e ' . $remainingSeconds . 's';
            }
            return $label;
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $parts = [];
        $parts[] = $hours . ' hora' . ($hours === 1 ? '' : 's');
        if ($remainingMinutes > 0) {
            $parts[] = $remainingMinutes . ' minuto' . ($remainingMinutes === 1 ? '' : 's');
        }
        if ($remainingSeconds > 0) {
            $parts[] = $remainingSeconds . 's';
        }

        return implode(' e ', $parts);
    }

    /**
     * @return array<int,string>
     */
    private function sanitizeTags(string $raw): array
    {
        $parts = preg_split('/[,;]/', $raw) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $tag = trim((string)$part);
            if ($tag === '') {
                continue;
            }
            $normalized[mb_strtolower($tag)] = $tag;
        }

        return array_values($normalized);
    }

    private function buildBackupDataset(): array
    {
        $tables = [];
        foreach (self::BACKUP_TABLES as $table) {
            $tables[$table] = $this->fetchTableRows($table);
        }

        return [
            'meta' => [
                'generated_at' => now(),
                'generated_at_iso' => date('c'),
                'app_version' => (string)config('app.version', 'dev'),
                'table_counts' => array_map('count', $tables),
            ],
            'tables' => $tables,
        ];
    }

    private function fetchTableRows(string $table): array
    {
        $pdo = Connection::instance('whatsapp');
        $stmt = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $tables
     * @return array<string, int>
     */
    private function importBackupTables(array $tables): array
    {
        $pdo = Connection::instance('whatsapp');
        $stats = [];

        $pdo->beginTransaction();
        try {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            foreach (self::BACKUP_TABLES as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }

            foreach (self::BACKUP_TABLES as $table) {
                $rows = $tables[$table] ?? [];
                $stats[$table] = count($rows);
                if ($rows === []) {
                    continue;
                }

                $columns = $this->describeTableColumns($pdo, $table);
                if ($columns === []) {
                    continue;
                }

                $columnList = implode(', ', $columns);
                $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns));
                $stmt = $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $columnList, $placeholders));

                foreach ($rows as $row) {
                    $params = [];
                    foreach ($columns as $column) {
                        $params[':' . $column] = array_key_exists($column, $row) ? $row[$column] : null;
                    }
                    $stmt->execute($params);
                }
            }

            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw new RuntimeException('Falha ao restaurar os dados: ' . $exception->getMessage(), 0, $exception);
        }

        return $stats;
    }

    /**
     * @return list<string>
     */
    private function describeTableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($row['name'])) {
                continue;
            }
            $columns[] = (string)$row['name'];
        }

        return $columns;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $basePath): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }
            $relativePath = ltrim(str_replace($directory, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $zip->addFile($fileInfo->getPathname(), rtrim($basePath, '/') . '/' . $relativePath);
        }
    }

    private function replaceMediaDirectory(string $sourceDir): void
    {
        $targetDir = storage_path('whatsapp-media');
        if (is_dir($targetDir)) {
            $this->deleteDirectory($targetDir);
        }

        if (!@rename($sourceDir, $targetDir)) {
            $this->ensureDirectory($targetDir);
            $this->copyDirectory($sourceDir, $targetDir);
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $parent = dirname($targetPath);
            $this->ensureDirectory($parent);
            if (@copy($item->getPathname(), $targetPath) === false) {
                throw new RuntimeException('Não foi possível copiar o arquivo de mídia: ' . $item->getPathname());
            }
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Não foi possível preparar o diretório: ' . $path);
        }
    }

    private function sanitizeManualText(string $raw): string
    {
        $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (!is_string($text)) {
            $text = $raw;
        }

        $text = preg_replace('/[^\S\r\n]+/', ' ', $text);
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

        return trim((string)$text);
    }

    /**
     * @return array<int,array{chunk_index:int,content:string,tokens_estimate:int,created_at:int}>
     */
    private function chunkManualText(string $text, int $maxLength = 900): array
    {
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [];
        $chunks = [];
        $buffer = '';
        $index = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string)$paragraph);
            if ($paragraph === '') {
                continue;
            }

            while (mb_strlen($paragraph, 'UTF-8') > $maxLength) {
                $part = mb_substr($paragraph, 0, $maxLength, 'UTF-8');
                $chunks[] = $this->buildManualChunkPayload($index++, trim((string)$part));
                $paragraph = mb_substr($paragraph, $maxLength, null, 'UTF-8');
            }

            if (mb_strlen($buffer . ' ' . $paragraph, 'UTF-8') > $maxLength && $buffer !== '') {
                $chunks[] = $this->buildManualChunkPayload($index++, trim($buffer));
                $buffer = '';
            }

            $buffer = $buffer === '' ? $paragraph : ($buffer . "\n" . $paragraph);
        }

        if ($buffer !== '') {
            $chunks[] = $this->buildManualChunkPayload($index++, trim($buffer));
        }

        return $chunks;
    }

    private function buildManualChunkPayload(int $index, string $content): array
    {
        $length = max(1, mb_strlen($content, 'UTF-8'));
        return [
            'chunk_index' => $index,
            'content' => $content,
            'tokens_estimate' => (int)ceil($length / 4),
            'created_at' => now(),
        ];
    }

    private function maskSensitiveText(string $text): string
    {
        $masked = $text;

        $patterns = [
            '/\\b\\d{3}\\.?\\d{3}\\.?\\d{3}-?\\d{2}\\b/u' => '[cpf]',
            '/\\b\\d{2}\\.?\\d{3}\\.?\\d{3}\\/\\d{4}-?\\d{2}\\b/u' => '[cnpj]',
            '/\\+?\\d{0,2}\\s?\\(?\\d{2}\\)?\\s?\\d{4,5}-?\\d{4}\\b/u' => '[telefone]',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/iu' => '[email]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $masked = (string)preg_replace($pattern, $replacement, $masked);
        }

        $masked = (string)preg_replace('/\\d{10,}/', '[dado]', $masked);

        return trim((string)preg_replace('/\\s+/', ' ', $masked));
    }

    private function captureTrainingSampleFromThread(array $thread): void
    {
        $threadId = (int)($thread['id'] ?? 0);
        if ($threadId <= 0) {
            return;
        }

        $contactId = isset($thread['contact_id']) ? (int)$thread['contact_id'] : null;
        $contact = $contactId !== null ? $this->contacts->find($contactId) : null;
        $messages = $this->messages->listForThread($threadId, 40);
        $messages = array_slice($messages, -10);

        $structured = [];
        foreach ($messages as $message) {
            $structured[] = [
                'direction' => $message['direction'] ?? 'incoming',
                'content' => $this->maskSensitiveText(trim((string)($message['content'] ?? ''))),
                'sent_at' => isset($message['sent_at']) ? (int)$message['sent_at'] : null,
            ];
        }

        $summary = trim((string)($thread['intake_summary'] ?? ''));
        if ($summary === '') {
            $lastIncoming = $this->extractLastIncoming($messages);
            $summary = sprintf(
                'Fila: %s | Ultima mensagem: %s',
                $thread['queue'] ?? 'arrival',
                mb_substr($this->maskSensitiveText(trim((string)($lastIncoming['content'] ?? ''))), 0, 180)
            );
        }

        $category = $this->guessThreadCategory($thread, $contact);

        $payload = [
            'thread_id' => $threadId,
            'contact_name' => (string)($thread['contact_name'] ?? $contact['name'] ?? 'Contato'),
            'contact_phone' => (string)($thread['contact_phone'] ?? $contact['phone'] ?? ''),
            'category' => $category,
            'summary' => mb_substr($summary, 0, 400, 'UTF-8'),
            'messages_json' => json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ];

        if (!is_string($payload['messages_json'])) {
            return;
        }

        $this->trainingSamples->create($payload);
    }

    private function guessThreadCategory(?array $thread, ?array $contact): string
    {
        $queue = $thread['queue'] ?? '';
        if ($queue === 'scheduled') {
            return 'vendas';
        }
        if ($queue === 'partner') {
            return 'parcerias';
        }

        $tags = isset($contact['tags']) ? mb_strtolower((string)$contact['tags'], 'UTF-8') : '';
        $intake = isset($thread['intake_summary']) ? mb_strtolower((string)$thread['intake_summary'], 'UTF-8') : '';
        $combined = $tags . ' ' . $intake;

        $supportKeywords = ['suporte', 'erro', 'falha', 'problema', 'bug', 'ajuda'];
        foreach ($supportKeywords as $keyword) {
            if ($keyword !== '' && str_contains($combined, $keyword)) {
                return 'suporte';
            }
        }

        return 'lead';
    }

    private function buildSystemPrompt(string $tone, string $goal, ?array $profile): string
    {
        $parts = [
            'Você é um copiloto de WhatsApp que auxilia um escritório contábil brasileiro.',
            'Responda sempre em português brasileiro, com clareza, empatia e objetividade.',
            'Limite-se a um ou dois parágrafos curtos e inclua chamada para o próximo passo quando fizer sentido.',
        ];

        if ($tone !== '') {
            $parts[] = 'Tom desejado: ' . $tone . '.';
        }
        if ($goal !== '') {
            $parts[] = 'Objetivo do atendimento: ' . $goal . '.';
        }
        if ($profile !== null && !empty($profile['instructions'])) {
            $parts[] = 'Instruções específicas do agente: ' . trim((string)$profile['instructions']);
        }

        return implode(' ', $parts);
    }

    private function buildConversationExcerpt(array $messages, int $limit = 8): string
    {
        if ($messages === []) {
            return '';
        }

        $slice = array_slice($messages, -$limit);
        $lines = [];
        foreach ($slice as $message) {
            $direction = $message['direction'] ?? 'incoming';
            $speaker = match ($direction) {
                'outgoing' => 'Equipe',
                'internal' => 'Nota interna',
                default => 'Cliente',
            };
            $content = trim(preg_replace('/\s+/', ' ', (string)($message['content'] ?? '')));
            if ($content === '') {
                continue;
            }
            $lines[] = sprintf('%s: %s', $speaker, mb_substr($content, 0, 220, 'UTF-8'));
        }

        return implode("\n", $lines);
    }

    private function summarizeManualChunks(array $chunks): string
    {
        if ($chunks === []) {
            return '';
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            $title = trim((string)($chunk['title'] ?? 'Manual'));
            $content = trim((string)($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $lines[] = sprintf('- %s: %s', $title, mb_substr($content, 0, 260, 'UTF-8'));
            if (count($lines) >= 5) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    private function summarizeTrainingSamples(array $samples): string
    {
        if ($samples === []) {
            return '';
        }

        $lines = [];
        foreach ($samples as $sample) {
            $category = (string)($sample['category'] ?? 'lead');
            $summary = trim((string)($sample['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }
            $lines[] = sprintf('- [%s] %s', ucfirst($category), mb_substr($summary, 0, 200, 'UTF-8'));
            if (count($lines) >= 3) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    private function extractKeywords(string $text, int $limit = 6): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$normalized);
        $parts = preg_split('/\s+/', (string)$normalized) ?: [];
        $keywords = [];

        foreach ($parts as $word) {
            $word = trim($word);
            if ($word === '' || mb_strlen($word, 'UTF-8') < 4) {
                continue;
            }
            if (in_array($word, self::KEYWORD_STOPWORDS, true)) {
                continue;
            }
            $keywords[$word] = true;
            if (count($keywords) >= $limit) {
                break;
            }
        }

        return array_keys($keywords);
    }

    private function requestCopilotCompletion(string $apiKey, array $messages, float $temperature): array
    {
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => max(0.0, min(1.0, $temperature)),
            'max_tokens' => 240,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['success' => false, 'error' => 'invalid_payload'];
        }

        $ch = curl_init('https://models.githubcopilot.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: whatsapp-crm-copilot',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => $error ?: 'curl_error'];
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? 'http_' . $status) : ('http_' . $status);
            return ['success' => false, 'error' => $message];
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        return [
            'success' => trim((string)$content) !== '',
            'message' => $content,
            'error' => null,
        ];
    }

    private function confidenceFromSignals(string $sentiment, array $knowledge): float
    {
        $confidence = 0.75;
        if ($sentiment === 'positive') {
            $confidence += 0.04;
        } elseif ($sentiment === 'negative') {
            $confidence -= 0.05;
        }

        if (($knowledge['manuals'] ?? 0) > 0) {
            $confidence += 0.06;
        }
        if (($knowledge['samples'] ?? 0) > 0) {
            $confidence += 0.04;
        }

        return max(0.55, min(0.95, $confidence));
    }

    private function buildRuleBasedSuggestion(string $contactName, string $tone, string $goal, ?array $profile, string $sentiment, array $knowledgeStats): array
    {
        $opening = $sentiment === 'negative'
            ? 'Entendo totalmente a sua preocupação e já estou cuidando disso:'
            : 'Obrigado por retornar, tenho uma sugestão clara para seguirmos:';

        $body = sprintf(
            "%s %s %s",
            $this->tonePrefix($tone, $contactName),
            $opening,
            $this->goalCallToAction($goal)
        );

        if ($profile !== null && trim((string)$profile['instructions']) !== '') {
            $body .= ' ' . trim((string)$profile['instructions']);
        }

        $profileData = $profile !== null ? [
            'id' => (int)$profile['id'],
            'name' => (string)$profile['name'],
            'slug' => (string)$profile['slug'],
        ] : null;

        return [
            'suggestion' => trim(preg_replace('/\s+/', ' ', $body)),
            'confidence' => $this->confidenceFromSignals($sentiment, $knowledgeStats),
            'sentiment' => $sentiment,
            'source' => 'rule_based_copilot',
            'profile' => $profileData,
            'knowledge' => $knowledgeStats,
        ];
    }

    private function applyAckStatusUpdate(array $message, int $ackValue, array $meta): array
    {
        $currentStatus = (string)($message['status'] ?? 'sent');
        $candidateStatus = $this->statusFromAck($ackValue) ?? $currentStatus;
        $resolvedStatus = $this->prioritizeMessageStatus($currentStatus, $candidateStatus);

        $metadataPayload = $this->mergeMessageMetadata($message['metadata'] ?? null, [
            'gateway' => [
                'ack' => $ackValue,
                'timestamp' => $this->coerceTimestamp($meta['timestamp'] ?? null) ?? now(),
                'instance' => $meta['gateway_instance'] ?? null,
                'origin' => $meta['origin'] ?? null,
            ],
        ]);

        $changed = ($resolvedStatus !== $currentStatus) || ($metadataPayload !== ($message['metadata'] ?? null));
        if ($changed) {
            $this->messages->updateStatus((int)$message['id'], $resolvedStatus, $metadataPayload);
        }

        return [
            'message_id' => (int)$message['id'],
            'thread_id' => (int)$message['thread_id'],
            'status' => $resolvedStatus,
            'changed' => $changed,
        ];
    }

    private function prioritizeMessageStatus(string $current, string $candidate): string
    {
        $currentScore = self::MESSAGE_STATUS_PRIORITY[strtolower($current)] ?? 0;
        $candidateScore = self::MESSAGE_STATUS_PRIORITY[strtolower($candidate)] ?? $currentScore;

        return $candidateScore >= $currentScore ? $candidate : $current;
    }

    private function statusFromAck(?int $ack): ?string
    {
        return match ($ack) {
            0 => 'queued',
            1 => 'sent',
            2 => 'delivered',
            3, 4 => 'read',
            default => null,
        };
    }

    private function coerceAckValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    private function coerceTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $intValue = (int)$value;
            return $intValue > 0 ? $intValue : null;
        }

        $parsed = strtotime((string)$value);
        return $parsed !== false ? $parsed : null;
    }

    private function mergeMessageMetadata(?string $existing, array $extra): ?string
    {
        $filtered = $this->filterMetadataArray($extra);
        if ($filtered === []) {
            return $existing;
        }

        $current = $this->decodeMessageMetadata($existing);
        $merged = array_replace_recursive($current, $filtered);

        return $this->encodeMessageMetadata($merged);
    }

    private function filterMetadataArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = $this->filterMetadataArray($value);
                if ($nested === []) {
                    continue;
                }
                $result[$key] = $nested;
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function isUniqueConstraintViolation(Throwable $exception): bool
    {
        if ($exception instanceof PDOException && (string)$exception->getCode() === '23000') {
            return true;
        }

        $message = $exception->getMessage();
        return stripos($message, 'UNIQUE constraint failed') !== false
            || stripos($message, 'unique constraint failed') !== false
            || stripos($message, 'duplicate') !== false;
    }

    private function decodeMessageMetadata(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeMessageMetadata(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
