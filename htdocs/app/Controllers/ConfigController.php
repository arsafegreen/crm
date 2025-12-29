<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\ImportLogRepository;
use App\Repositories\SystemReleaseRepository;
use App\Services\BaseRfbImportService;
use App\Repositories\SettingRepository;
use App\Repositories\TemplateRepository;
use App\Services\Import\ClientImportService;
use App\Services\MaintenanceService;
use App\Services\Manual\ManualBuilder;
use App\Services\SocialAccountService;
use App\Support\ThemePresets;
use App\Support\RfbWhatsappTemplates;
use App\Support\WhatsappTemplatePresets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

final class ConfigController
{
    private const RFB_CHUNK_SIZE_BYTES = 50 * 1024 * 1024; // 50 MB por bloco
    private SettingRepository $settings;
    private SocialAccountService $socialAccounts;
    private MaintenanceService $maintenance;
    private ImportLogRepository $importLogs;
    private SystemReleaseRepository $releases;

    public function __construct()
    {
        $this->settings = new SettingRepository();
        $this->socialAccounts = new SocialAccountService();
        $this->maintenance = new MaintenanceService();
        $this->importLogs = new ImportLogRepository();
        $this->releases = new SystemReleaseRepository();
    }

    public function index(Request $request): Response
    {
        $emailSettings = [
            'host' => (string)$this->settings->get('mail.host', ''),
            'port' => (string)$this->settings->get('mail.port', '587'),
            'encryption' => (string)$this->settings->get('mail.encryption', 'tls'),
            'username' => (string)$this->settings->get('mail.username', ''),
            'password' => (string)$this->settings->get('mail.password', ''),
            'from_email' => (string)$this->settings->get('mail.from_email', ''),
            'from_name' => (string)$this->settings->get('mail.from_name', ''),
            'reply_to' => (string)$this->settings->get('mail.reply_to', ''),
        ];

        $appearanceTheme = (string)$this->settings->get('ui.background_theme', 'safegreen-blue');
        $themeOptions = ThemePresets::all();

        $templatesRepo = new TemplateRepository();
        $templatesRepo->seedDefaults();
        $templates = $templatesRepo->all('email');

        $accounts = $this->socialAccounts->listAccounts();

        $feedback = $_SESSION['config_feedback'] ?? null;
        unset($_SESSION['config_feedback']);

        $emailFeedback = $_SESSION['config_email_feedback'] ?? null;
        unset($_SESSION['config_email_feedback']);

        $socialFeedback = $_SESSION['social_feedback'] ?? null;
        unset($_SESSION['social_feedback']);

        $rfbUploadFeedback = $_SESSION['rfb_upload_feedback'] ?? null;
        unset($_SESSION['rfb_upload_feedback']);

        $releaseFeedback = $_SESSION['release_feedback'] ?? null;
        unset($_SESSION['release_feedback']);

        $importSettings = [
            'reject_older_certificates' => (bool)$this->settings->get('import.reject_older_certificates', false),
        ];

        $securitySettings = [
            'access_start' => $this->minutesToTimeString($this->settings->get('security.access_start_minutes')),
            'access_end' => $this->minutesToTimeString($this->settings->get('security.access_end_minutes')),
            'require_known_device' => (bool)$this->settings->get('security.require_known_device', false),
        ];

        $securityFeedback = $_SESSION['config_security_feedback'] ?? null;
        unset($_SESSION['config_security_feedback']);

        $importLogs = $this->importLogs->latest(10);

        $user = $this->currentUser($request);

        $releases = $this->releases->all();

        $whatsappTemplates = $this->loadWhatsappTemplates();
        $whatsappTemplateFeedback = $_SESSION['whatsapp_template_feedback'] ?? null;
        unset($_SESSION['whatsapp_template_feedback']);

        $renewalWindowDefaults = ['before_days' => 60, 'after_days' => 60];
        $renewalWindow = [
            'before_days' => max(0, (int)$this->settings->get('whatsapp.renewal_window.before_days', $renewalWindowDefaults['before_days'])),
            'after_days' => max(0, (int)$this->settings->get('whatsapp.renewal_window.after_days', $renewalWindowDefaults['after_days'])),
        ];
        $renewalWindowFeedback = $_SESSION['renewal_window_feedback'] ?? null;
        unset($_SESSION['renewal_window_feedback']);

        $rfbWhatsappTemplates = $this->loadRfbWhatsappTemplates();
        $rfbWhatsappTemplateFeedback = $_SESSION['rfb_whatsapp_template_feedback'] ?? null;
        unset($_SESSION['rfb_whatsapp_template_feedback']);

        $alertEntries = \App\Services\AlertService::latest(300);

        return view('config/index', [
            'emailSettings' => $emailSettings,
            'templates' => $templates,
            'accounts' => $accounts,
            'feedback' => $feedback,
            'emailFeedback' => $emailFeedback,
            'socialFeedback' => $socialFeedback,
            'rfbUploadFeedback' => $rfbUploadFeedback,
            'currentTheme' => $appearanceTheme,
            'themeOptions' => $themeOptions,
            'showMaintenance' => $user?->isAdmin() ?? false,
            'importSettings' => $importSettings,
            'importLogs' => $importLogs,
            'securitySettings' => $securitySettings,
            'securityFeedback' => $securityFeedback,
            'releaseFeedback' => $releaseFeedback,
            'releases' => $releases,
            'whatsappTemplates' => $whatsappTemplates,
            'whatsappTemplateFeedback' => $whatsappTemplateFeedback,
            'renewalWindow' => $renewalWindow,
            'renewalWindowFeedback' => $renewalWindowFeedback,
            'rfbWhatsappTemplates' => $rfbWhatsappTemplates,
            'rfbWhatsappTemplateFeedback' => $rfbWhatsappTemplateFeedback,
            'alertEntries' => $alertEntries,
        ]);
    }

    public function downloadManual(Request $request): Response
    {
        try {
            $builder = new ManualBuilder();
            $pdfPath = $builder->ensurePdf();
        } catch (\Throwable $exception) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível gerar o manual técnico: ' . $exception->getMessage(),
            ];

            return new RedirectResponse(url('config'));
        }

        if (!is_file($pdfPath)) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Manual técnico indisponível no momento.',
            ];

            return new RedirectResponse(url('config'));
        }

        $response = new BinaryFileResponse($pdfPath);
        $response->setContentDisposition('attachment', 'manual-tecnico.pdf');

        return $response;
    }

    public function whatsappManual(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin();
        }

        $logExample = implode(PHP_EOL, [
            '{"phone":"5511999999999","contact_name":"Joao Cliente","direction":"incoming","message":"Enviei os documentos","timestamp":"2025-12-10T09:30:00-03:00","line_label":"Sandbox Copilot"}',
            '{"phone":"5511999999999","direction":"outgoing","message":"Recebido, vou validar e retorno ainda hoje.","timestamp":1733825400,"line_label":"Sandbox Copilot"}',
        ]);

        $importCommand = 'php scripts/whatsapp/import_logs.php --arquivo=storage/logs/whatsapp-sandbox.ndjson --mark-read=1';

        return view('config/manual-guide', [
            'logExample' => $logExample,
            'importCommand' => $importCommand,
        ]);
    }

    public function updateEmail(Request $request): Response
    {
        $host = trim((string)$request->request->get('host', ''));
        $port = trim((string)$request->request->get('port', '587'));
        $encryption = trim((string)$request->request->get('encryption', 'tls'));
        $username = trim((string)$request->request->get('username', ''));
        $password = (string)$request->request->get('password', '');
        $fromEmail = trim((string)$request->request->get('from_email', ''));
        $fromName = trim((string)$request->request->get('from_name', ''));
        $replyTo = trim((string)$request->request->get('reply_to', ''));

        $errors = [];

        if ($host === '') {
            $errors['host'] = 'Informe o servidor SMTP.';
        }

        if ($port === '' || !ctype_digit($port)) {
            $errors['port'] = 'A porta deve ser numérica.';
        }

        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_email'] = 'Informe um e-mail de remetente válido.';
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reply_to'] = 'Informe um e-mail de resposta válido ou deixe em branco.';
        }

        if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $errors['encryption'] = 'Criptografia inválida.';
        }

        if ($errors !== []) {
            $_SESSION['config_email_feedback'] = [
                'type' => 'error',
                'message' => 'Revise os campos destacados e tente novamente.',
                'errors' => $errors,
                'old' => [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'username' => $username,
                    'password' => $password,
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'reply_to' => $replyTo,
                ],
            ];

            return new RedirectResponse(url('config') . '#email');
        }

        $this->settings->setMany([
            'mail.host' => $host,
            'mail.port' => $port,
            'mail.encryption' => $encryption,
            'mail.username' => $username,
            'mail.password' => $password,
            'mail.from_email' => $fromEmail,
            'mail.from_name' => $fromName,
            'mail.reply_to' => $replyTo,
        ]);

        $_SESSION['config_email_feedback'] = [
            'type' => 'success',
            'message' => 'Configurações de e-mail salvas com sucesso.',
        ];

        return new RedirectResponse(url('config') . '#email');
    }

    public function updateTheme(Request $request): Response
    {
        $theme = (string)$request->request->get('theme', 'safegreen-blue');
        $themes = ThemePresets::all();

        if (!array_key_exists($theme, $themes)) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Tema selecionado é inválido. Escolha uma das opções disponíveis.'
            ];

            return new RedirectResponse(url('config') . '#theme');
        }

        $this->settings->set('ui.background_theme', $theme);

        $_SESSION['config_feedback'] = [
            'type' => 'success',
            'message' => 'Tema atualizado. O novo fundo já está aplicado na interface.'
        ];

        return new RedirectResponse(url('config') . '#theme');
    }

    public function updateSecurity(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#security');
        }

        $startInput = trim((string)$request->request->get('access_start', ''));
        $endInput = trim((string)$request->request->get('access_end', ''));
        $requireKnown = (string)$request->request->get('require_known_device', '0') === '1';

        $errors = [];

        $startMinutes = $this->parseTimeToMinutes($startInput);
        if ($startInput !== '' && $startMinutes === null) {
            $errors['access_start'] = 'Use o formato HH:MM (24h) ou deixe em branco.';
        }

        $endMinutes = $this->parseTimeToMinutes($endInput);
        if ($endInput !== '' && $endMinutes === null) {
            $errors['access_end'] = 'Use o formato HH:MM (24h) ou deixe em branco.';
        }

        if ($errors !== []) {
            $_SESSION['config_security_feedback'] = [
                'type' => 'error',
                'message' => 'Revise os horários informados antes de salvar.',
                'errors' => $errors,
                'old' => [
                    'access_start' => $startInput,
                    'access_end' => $endInput,
                    'require_known_device' => $requireKnown ? '1' : '0',
                ],
            ];

            return new RedirectResponse(url('config') . '#security');
        }

        $this->settings->set('security.access_start_minutes', $startMinutes);
        $this->settings->set('security.access_end_minutes', $endMinutes);
        $this->settings->set('security.require_known_device', $requireKnown ? '1' : '0');

        $_SESSION['config_security_feedback'] = [
            'type' => 'success',
            'message' => 'Políticas de acesso atualizadas com sucesso.',
        ];

        return new RedirectResponse(url('config') . '#security');
    }

    public function storeSocialAccount(Request $request): Response
    {
        $payload = $request->request->all();
        if ($payload === []) {
            $payload = json_decode((string)$request->getContent(), true) ?? [];
        }

        try {
            $this->socialAccounts->createAccount($payload);
            $_SESSION['social_feedback'] = [
                'type' => 'success',
                'message' => 'Canal conectado com sucesso. Tokens armazenados com segurança.'
            ];
        } catch (\Throwable $e) {
            $_SESSION['social_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível salvar o canal: ' . $e->getMessage(),
            ];
        }

        return new RedirectResponse(url('config') . '#social');
    }

    public function uploadRfbBase(Request $request): Response
    {
        $file = $request->files->get('rfb_spreadsheet');
        if ($file === null) {
            return $this->rfbUploadFeedback('error', 'Selecione um arquivo CSV para importar.');
        }

        if (!$file->isValid()) {
            return $this->rfbUploadFeedback('error', 'Upload inválido. Tente novamente.');
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            return $this->rfbUploadFeedback('error', 'Formato não suportado. Utilize arquivos CSV exportados da base RFB.');
        }

        $targetDir = storage_path('uploads/rfb');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->rfbUploadFeedback('error', 'Não foi possível preparar a pasta de upload.');
        }

        $filename = 'rfb_' . date('Ymd_His') . '_' . uniqid('', false) . '.' . $extension;
        $file->move($targetDir, $filename);
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        $service = new BaseRfbImportService();

        try {
            $chunks = $this->chunkCsvFile($filePath, self::RFB_CHUNK_SIZE_BYTES);
        } catch (\Throwable $exception) {
            @unlink($filePath);
            return $this->rfbUploadFeedback('error', 'Falha ao preparar o arquivo para importação: ' . $exception->getMessage());
        }

        @unlink($filePath);

        if ($chunks === []) {
            return $this->rfbUploadFeedback('error', 'Nenhum dado válido foi encontrado na planilha.');
        }

        $summary = [
            'processed' => 0,
            'imported' => 0,
            'skipped_invalid' => 0,
            'skipped_existing_clients' => 0,
            'skipped_duplicates' => 0,
            'already_in_base' => 0,
            'skipped_missing_contact' => 0,
            'lines_read' => 0,
            'chunks_total' => count($chunks),
            'chunk_size_mb' => round(self::RFB_CHUNK_SIZE_BYTES / (1024 * 1024), 1),
        ];

        foreach ($chunks as $index => $chunkPath) {
            $label = sprintf('%s (parte %d/%d)', $file->getClientOriginalName(), $index + 1, $summary['chunks_total']);

            try {
                $chunkStats = $service->import($chunkPath, $label);
                $summary = $this->mergeRfbStats($summary, $chunkStats);
            } catch (\Throwable $exception) {
                return $this->rfbUploadFeedback('error', sprintf('Falha ao importar a parte %d: %s', $index + 1, $exception->getMessage()), $summary);
            } finally {
                @unlink($chunkPath);
            }
        }

        $imported = (int)($summary['imported'] ?? 0);
        if ($imported === 0) {
            return $this->rfbUploadFeedback('info', 'Nenhum registro novo foi adicionado. Verifique se os CNPJs já estavam na base.', $summary);
        }

        return $this->rfbUploadFeedback(
            'success',
            sprintf('%d novos registros adicionados em %d parte(s).', $imported, $summary['chunks_total'] ?? 1),
            $summary
        );
    }

    public function exportClientBackup(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        try {
            [$zipPath, $zipName] = $this->maintenance->generateClientBackupZip();
        } catch (\Throwable $exception) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível gerar o backup: ' . $exception->getMessage(),
            ];

            return new RedirectResponse(url('config') . '#maintenance');
        }

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition('attachment', $zipName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    public function refreshRouteCache(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        try {
            $context = $this->maintenance->refreshRouteCache();
            $_SESSION['config_feedback'] = [
                'type' => 'success',
                'message' => 'Cache de rotas limpo. Será regenerado automaticamente no próximo acesso.',
                'context' => $context,
            ];
        } catch (\Throwable $exception) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível limpar o cache de rotas: ' . $exception->getMessage(),
            ];
        }

        return new RedirectResponse(url('config') . '#maintenance');
    }

    public function exportImportTemplate(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        try {
            [$excelPath, $excelName] = $this->maintenance->generateClientImportSpreadsheet();
        } catch (\Throwable $exception) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível gerar a planilha de importação: ' . $exception->getMessage(),
            ];

            return new RedirectResponse(url('config') . '#maintenance');
        }

        $response = new BinaryFileResponse($excelPath);
        $response->setContentDisposition('attachment', $excelName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    public function importClientSpreadsheet(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        $file = $request->files->get('spreadsheet');
        if ($file === null) {
            return $this->maintenanceFeedback('error', 'Selecione um arquivo para importar.');
        }

        if (!$file->isValid()) {
            return $this->maintenanceFeedback('error', 'Falha no upload do arquivo.');
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if (!in_array($extension, ['xls', 'xlsx', 'csv'], true)) {
            return $this->maintenanceFeedback('error', 'Formato não suportado. Utilize arquivos XLS, XLSX ou CSV.');
        }

        $targetDir = storage_path('uploads');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->maintenanceFeedback('error', 'Não foi possível preparar a pasta de upload.');
        }

        $filename = 'config_import_' . date('Ymd_His') . '_' . uniqid('', false) . '.' . $extension;
        $file->move($targetDir, $filename);
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        $service = new ClientImportService();
        $options = [
            'reject_older_certificates' => (bool)$this->settings->get('import.reject_older_certificates', false),
        ];

        try {
            $stats = $service->import($filePath, $options);
            $message = sprintf(
                'Importação concluída: %d processados, %d clientes novos, %d atualizados.',
                $stats['processed'] ?? 0,
                $stats['created_clients'] ?? 0,
                $stats['updated_clients'] ?? 0
            );

            $duplicateProtocols = (int)($stats['skipped_duplicate_protocols'] ?? 0);
            if ($duplicateProtocols > 0) {
                $message .= sprintf(' (%d protocolos duplicados ignorados)', $duplicateProtocols);
            }

            $user = $this->currentUser($request);
            $this->importLogs->record(
                'config',
                basename($filePath),
                $stats,
                $user?->id,
                array_filter([
                    'path' => $filePath,
                    'skipped_duplicate_protocols' => $duplicateProtocols > 0 ? $duplicateProtocols : null,
                ])
            );

            return $this->maintenanceFeedback('success', $message, $stats);
        } catch (\Throwable $exception) {
            return $this->maintenanceFeedback('error', 'Erro ao importar: ' . $exception->getMessage());
        }
    }

    public function updateImportSettings(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        $rejectOlder = (string)$request->request->get('reject_older_certificates', '0') === '1';
        $this->settings->set('import.reject_older_certificates', $rejectOlder ? '1' : '0');

        $_SESSION['config_feedback'] = [
            'type' => 'success',
            'message' => 'Preferências de importação atualizadas.',
        ];

        return new RedirectResponse(url('config') . '#rfb-base');
    }

    public function factoryReset(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#maintenance');
        }

        $confirmation = strtoupper(trim((string)$request->request->get('confirmation', '')));
        if ($confirmation !== 'REDEFINIR') {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Digite REDEFINIR para confirmar a restauração.',
            ];

            return new RedirectResponse(url('config') . '#maintenance');
        }

        try {
            $this->maintenance->factoryReset();
        } catch (\Throwable $exception) {
            $_SESSION['config_feedback'] = [
                'type' => 'error',
                'message' => 'Falha ao restaurar dados: ' . $exception->getMessage(),
            ];

            return new RedirectResponse(url('config') . '#maintenance');
        }

        $_SESSION['config_feedback'] = [
            'type' => 'success',
            'message' => 'Banco e arquivos foram limpos. Um funil padrão e os modelos de e-mail foram recriados.',
        ];

        return new RedirectResponse(url('config') . '#maintenance');
    }

    public function generateRelease(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#releases');
        }

        $version = $this->sanitizeVersion((string)$request->request->get('version', ''));
        $notes = trim((string)$request->request->get('notes', ''));
        $skipVendor = (string)$request->request->get('skip_vendor', '0') === '1';

        if ($version === '') {
            $version = 'release_' . date('Ymd_His');
        }

        $arguments = [escapeshellarg($version)];

        if ($skipVendor) {
            $arguments[] = '--skip-vendor';
        }

        if ($notes !== '') {
            $arguments[] = '--notes=' . escapeshellarg($notes);
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg($this->detectPhpBinary()),
            escapeshellarg(base_path('scripts/package_release.php')),
            implode(' ', $arguments)
        );

        try {
            $result = $this->runShellCommand($command);
        } catch (\Throwable $exception) {
            return $this->releaseFeedback('error', 'Falha ao gerar a release: ' . $exception->getMessage());
        }

        $exitCode = (int)($result['exit_code'] ?? 1);
        if ($exitCode !== 0) {
            return $this->releaseFeedback('error', 'Script de geração retornou erro. Verifique o log para detalhes.', $result);
        }

        return $this->releaseFeedback('success', 'Release criada e registrada. Faça o download para distribuir.', $result);
    }

    public function importRelease(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#releases');
        }

        $file = $request->files->get('release_package');
        if ($file === null) {
            return $this->releaseFeedback('error', 'Selecione um arquivo `.zip` gerado pelo script de release.');
        }

        if (!$file->isValid()) {
            return $this->releaseFeedback('error', 'Upload inválido. Tente novamente.');
        }

        if (strtolower((string)$file->getClientOriginalExtension()) !== 'zip') {
            return $this->releaseFeedback('error', 'Somente arquivos `.zip` são aceitos.');
        }

        $targetDir = storage_path('releases/imported');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->releaseFeedback('error', 'Não foi possível preparar a pasta de releases.');
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$file->getClientOriginalName());
        $filename = date('Ymd_His') . '_' . ($name === '' ? 'release' : $name);
        $file->move($targetDir, $filename);
        $packagePath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        try {
            $manifest = $this->readReleaseManifest($packagePath);
        } catch (\Throwable $exception) {
            @unlink($packagePath);
            return $this->releaseFeedback('error', 'Pacote inválido: ' . $exception->getMessage());
        }

        $version = $this->sanitizeVersion((string)($manifest['version'] ?? ''));
        if ($version === '' || $this->releases->findByVersion($version) !== null) {
            @unlink($packagePath);
            return $this->releaseFeedback('error', 'Versão ausente ou já registrada neste ambiente.');
        }

        $relativePath = trim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $packagePath), '\\/');
        $this->releases->create([
            'version' => $version,
            'status' => 'available',
            'origin' => 'imported',
            'notes' => $manifest['notes'] ?? null,
            'file_name' => $relativePath,
            'file_size' => filesize($packagePath) ?: 0,
            'file_hash' => hash_file('sha256', $packagePath) ?: '',
            'include_vendor' => !empty($manifest['include_vendor']) ? 1 : 0,
            'git_commit' => $manifest['git_commit'] ?? null,
            'php_version' => $manifest['php_version'] ?? null,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->releaseFeedback('success', sprintf('Release %s importada com sucesso.', $version));
    }

    public function applyRelease(Request $request, array $vars): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#releases');
        }

        $releaseId = isset($vars['id']) ? (int)$vars['id'] : 0;
        if ($releaseId <= 0) {
            return $this->releaseFeedback('error', 'Release inválida.');
        }

        $release = $this->releases->find($releaseId);
        if ($release === null) {
            return $this->releaseFeedback('error', 'Release não localizada.');
        }

        $filePath = base_path($release['file_name']);
        if (!is_file($filePath)) {
            return $this->releaseFeedback('error', 'Arquivo da release não foi encontrado.');
        }

        try {
            $result = $this->runApplyUpdateScript($filePath);
        } catch (\Throwable $exception) {
            return $this->releaseFeedback('error', 'Falha ao executar o script de atualização: ' . $exception->getMessage());
        }

        $exitCode = (int)($result['exit_code'] ?? 1);
        $status = $exitCode === 0 ? 'applied' : 'failed';
        $this->releases->recordApplication($releaseId, $result, $status, $this->currentUser($request)?->id);

        $message = $exitCode === 0
            ? sprintf('Release %s aplicada com sucesso.', $release['version'])
            : sprintf('Release %s apresentou erros. Consulte o log abaixo.', $release['version']);

        return $this->releaseFeedback($exitCode === 0 ? 'success' : 'error', $message, $result);
    }

    public function downloadRelease(Request $request, array $vars): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#releases');
        }

        $releaseId = isset($vars['id']) ? (int)$vars['id'] : 0;
        $release = $releaseId > 0 ? $this->releases->find($releaseId) : null;
        if ($release === null) {
            return $this->releaseFeedback('error', 'Release não encontrada.');
        }

        $filePath = base_path($release['file_name']);
        if (!is_file($filePath)) {
            return $this->releaseFeedback('error', 'Arquivo da release não está disponível neste servidor.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition('attachment', $release['version'] . '.zip');
        return $response;
    }

    private function minutesToTimeString(mixed $value): string
    {
        $minutes = $this->convertToMinutes($value);
        if ($minutes === null) {
            return '';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $rest);
    }

    private function convertToMinutes(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return max(0, min(1439, $value));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (ctype_digit($trimmed)) {
                return max(0, min(1439, (int)$trimmed));
            }

            if (preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches) === 1) {
                $hours = (int)$matches[1];
                $minutes = (int)$matches[2];

                if ($hours > 23 || $minutes > 59) {
                    return null;
                }

                return ($hours * 60) + $minutes;
            }
        }

        return null;
    }

    private function parseTimeToMinutes(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function isAdmin(Request $request): bool
    {
        return $this->currentUser($request)?->isAdmin() ?? false;
    }

    private function denyForNonAdmin(string $anchor = ''): RedirectResponse
    {
        $_SESSION['config_feedback'] = [
            'type' => 'error',
            'message' => 'Apenas administradores podem executar esta ação.',
        ];

        $target = url('config');
        if ($anchor !== '') {
            $target .= $anchor;
        }

        return new RedirectResponse($target);
    }

    private function rfbUploadFeedback(string $type, string $message, ?array $details = null): RedirectResponse
    {
        $_SESSION['rfb_upload_feedback'] = [
            'type' => $type,
            'message' => $message,
            'details' => $details,
        ];

        return new RedirectResponse(url('config') . '#rfb-base');
    }

    private function releaseFeedback(string $type, string $message, ?array $context = null): RedirectResponse
    {
        $_SESSION['release_feedback'] = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
        ];

        return new RedirectResponse(url('config') . '#releases');
    }

    private function runApplyUpdateScript(string $packagePath): array
    {
        $phpBinary = $this->detectPhpBinary();
        $scriptPath = base_path('scripts/apply_update.php');

        if (!is_file($scriptPath)) {
            throw new RuntimeException('Script apply_update.php não encontrado.');
        }

        $command = sprintf('%s %s %s', escapeshellarg($phpBinary), escapeshellarg($scriptPath), escapeshellarg($packagePath));
        $result = $this->runShellCommand($command, base_path());
        $result['package'] = $packagePath;

        return $result;
    }

    private function runShellCommand(string $command, ?string $cwd = null): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd ?? base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível iniciar o processo solicitado.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'command' => $command,
        ];
    }

    private function detectPhpBinary(): string
    {
        $candidates = [];

        if (PHP_BINARY !== '' && @is_file(PHP_BINARY)) {
            $candidates[] = PHP_BINARY;
        }

        $phpName = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'php.exe' : 'php';
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $phpName;
        $candidates[] = $phpName;

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function readReleaseManifest(string $packagePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Não foi possível abrir o pacote ZIP.');
        }

        $content = $zip->getFromName('release_manifest.json');
        $zip->close();

        if ($content === false) {
            throw new RuntimeException('release_manifest.json não encontrado no pacote.');
        }

        $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Manifesto inválido.');
        }

        return $manifest;
    }

    private function sanitizeVersion(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($value));
        return $clean === null ? '' : $clean;
    }

    /**
     * @return string[]
     */
    private function chunkCsvFile(string $path, int $maxBytes): array
    {
        $maxBytes = max(5 * 1024 * 1024, $maxBytes);

        $file = new \SplFileObject($path, 'rb');
        if ($file->eof()) {
            return [];
        }

        $firstLine = (string)$file->fgets();
        if ($firstLine === '') {
            return [];
        }

        $delimiter = $this->detectDelimiter($firstLine);
        $file->rewind();
        $file->setFlags(\SplFileObject::READ_CSV);
        $file->setCsvControl($delimiter);

        $headerRow = $file->fgetcsv();
        if ($this->isEmptyCsvRow($headerRow)) {
            return [];
        }

        $buffer = $this->createCsvBuffer();
        $headerLine = $this->csvRowToString($headerRow, $delimiter, $buffer);
        $headerSize = strlen($headerLine);

        $chunkDir = storage_path('uploads/rfb/chunks');
        $this->ensureDirectoryExists($chunkDir);

        $chunks = [];
        $chunkIndex = 0;
        $chunkHandle = null;
        $chunkPath = '';
        $currentSize = 0;

        $openChunk = function () use (&$chunkHandle, &$chunkPath, &$chunks, &$chunkIndex, &$currentSize, $chunkDir, $path, $headerLine, $headerSize): void {
            if ($chunkHandle !== null) {
                fclose($chunkHandle);
            }

            $chunkIndex++;
            $chunkPath = $chunkDir . DIRECTORY_SEPARATOR . basename($path) . '.part' . str_pad((string)$chunkIndex, 3, '0', STR_PAD_LEFT) . '.csv';
            $chunkHandle = fopen($chunkPath, 'wb');
            if ($chunkHandle === false) {
                throw new RuntimeException('Não foi possível criar arquivo temporário para divisão da Base RFB.');
            }

            fwrite($chunkHandle, $headerLine);
            $currentSize = $headerSize;
            $chunks[] = $chunkPath;
        };

        try {
            $openChunk();

            while (!$file->eof()) {
                $row = $file->fgetcsv();
                if ($this->isEmptyCsvRow($row)) {
                    continue;
                }

                $line = $this->csvRowToString($row, $delimiter, $buffer);
                $lineLength = strlen($line);

                if ($currentSize + $lineLength > $maxBytes && $currentSize > $headerSize) {
                    $openChunk();
                }

                fwrite($chunkHandle, $line);
                $currentSize += $lineLength;
            }
        } catch (\Throwable $exception) {
            foreach ($chunks as $created) {
                @unlink($created);
            }
            throw $exception;
        } finally {
            if ($chunkHandle !== null) {
                fclose($chunkHandle);
            }
            fclose($buffer);
        }

        if ($chunks !== []) {
            $lastPath = $chunks[count($chunks) - 1];
            if (is_file($lastPath) && filesize($lastPath) !== false && filesize($lastPath) <= $headerSize) {
                @unlink($lastPath);
                array_pop($chunks);
            }
        }

        return $chunks;
    }

    private function mergeRfbStats(array $summary, array $chunk): array
    {
        $keys = [
            'processed',
            'imported',
            'skipped_invalid',
            'skipped_existing_clients',
            'skipped_duplicates',
            'already_in_base',
            'skipped_missing_contact',
            'lines_read',
        ];

        foreach ($keys as $key) {
            $summary[$key] = (int)($summary[$key] ?? 0) + (int)($chunk[$key] ?? 0);
        }

        return $summary;
    }

    private function csvRowToString(array $row, string $delimiter, $buffer): string
    {
        if (!is_resource($buffer)) {
            throw new RuntimeException('Buffer CSV inválido.');
        }

        ftruncate($buffer, 0);
        rewind($buffer);

        if (fputcsv($buffer, $row, $delimiter) === false) {
            throw new RuntimeException('Falha ao montar linha CSV temporária.');
        }

        rewind($buffer);
        $line = stream_get_contents($buffer);
        if ($line === false) {
            throw new RuntimeException('Falha ao ler linha CSV temporária.');
        }

        return $line;
    }

    /**
     * @return resource
     */
    private function createCsvBuffer()
    {
        $buffer = fopen('php://temp', 'w+');
        if ($buffer === false) {
            throw new RuntimeException('Não foi possível criar buffer temporário para processar o CSV.');
        }

        return $buffer;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório %s.', $path));
        }
    }

    private function isEmptyCsvRow($row): bool
    {
        if ($row === false || $row === null) {
            return true;
        }

        if (!is_array($row)) {
            return false;
        }

        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function detectDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semicolon = substr_count($line, ';');
        $tab = substr_count($line, "\t");

        if ($semicolon > $comma && $semicolon >= $tab) {
            return ';';
        }

        if ($tab > $comma && $tab > $semicolon) {
            return "\t";
        }

        return ',';
    }

    private function maintenanceFeedback(string $type, string $message, array $meta = []): RedirectResponse
    {
        $_SESSION['config_feedback'] = [
            'type' => $type,
            'message' => $message,
            'meta' => $meta,
        ];

        return new RedirectResponse(url('config') . '#maintenance');
    }

    public function updateWhatsappTemplates(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#whatsapp');
        }

        $input = $request->request->all('templates');
        if (!is_array($input)) {
            $input = [];
        }

        $labels = WhatsappTemplatePresets::labels();
        $defaults = WhatsappTemplatePresets::defaults();

        $errors = [];
        $payload = [];

        foreach (WhatsappTemplatePresets::TEMPLATE_KEYS as $key) {
            $value = $this->normalizeTemplateInput($input[$key] ?? '');

            if ($value === '') {
                $errors[$key] = sprintf('Informe um texto para %s.', strtolower($labels[$key] ?? 'este modelo'));
            } elseif (mb_strlen($value) > 1200) {
                $errors[$key] = 'Limite de 1.200 caracteres por mensagem.';
            }

            $payload[$key] = $value;
        }

        if ($errors !== []) {
            $_SESSION['whatsapp_template_feedback'] = [
                'type' => 'error',
                'message' => 'Revise os textos destacados antes de salvar.',
                'errors' => $errors,
                'old' => $input,
            ];

            return new RedirectResponse(url('config') . '#whatsapp');
        }

        foreach ($payload as $key => $value) {
            $final = $value !== '' ? $value : ($defaults[$key] ?? '');
            $this->settings->set('whatsapp.template.' . $key, $final);
        }

        $_SESSION['whatsapp_template_feedback'] = [
            'type' => 'success',
            'message' => 'Modelos de mensagens para WhatsApp atualizados com sucesso.',
        ];

        return new RedirectResponse(url('config') . '#whatsapp');
    }

    public function updateRenewalWindow(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#whatsapp');
        }

        $beforeInput = trim((string)$request->request->get('before_days', '60'));
        $afterInput = trim((string)$request->request->get('after_days', '60'));

        $errors = [];

        if ($beforeInput === '' || filter_var($beforeInput, FILTER_VALIDATE_INT) === false) {
            $errors['before_days'] = 'Informe um número inteiro para dias antes.';
        }

        if ($afterInput === '' || filter_var($afterInput, FILTER_VALIDATE_INT) === false) {
            $errors['after_days'] = 'Informe um número inteiro para dias após.';
        }

        $beforeDays = (int)$beforeInput;
        $afterDays = (int)$afterInput;

        $minDays = 0;
        $maxDays = 365;

        if (!isset($errors['before_days']) && ($beforeDays < $minDays || $beforeDays > $maxDays)) {
            $errors['before_days'] = sprintf('Use entre %d e %d dias.', $minDays, $maxDays);
        }

        if (!isset($errors['after_days']) && ($afterDays < $minDays || $afterDays > $maxDays)) {
            $errors['after_days'] = sprintf('Use entre %d e %d dias.', $minDays, $maxDays);
        }

        if ($errors !== []) {
            $_SESSION['renewal_window_feedback'] = [
                'type' => 'error',
                'message' => 'Revise os valores informados e tente novamente.',
                'errors' => $errors,
                'old' => [
                    'before_days' => $beforeInput,
                    'after_days' => $afterInput,
                ],
            ];

            return new RedirectResponse(url('config') . '#whatsapp');
        }

        $this->settings->setMany([
            'whatsapp.renewal_window.before_days' => (string)$beforeDays,
            'whatsapp.renewal_window.after_days' => (string)$afterDays,
        ]);

        $_SESSION['renewal_window_feedback'] = [
            'type' => 'success',
            'message' => 'Janela da mensagem de renovação atualizada.',
        ];

        return new RedirectResponse(url('config') . '#whatsapp');
    }

    public function updateRfbWhatsappTemplates(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return $this->denyForNonAdmin('#whatsapp');
        }

        $defaults = RfbWhatsappTemplates::defaults();

        $cleanTemplates = [
            'rfb.whatsapp.partnership_template' => RfbWhatsappTemplates::sanitize(
                (string)$request->request->get('partnership_template', ''),
                $defaults['partnership']
            ),
            'rfb.whatsapp.general_template' => RfbWhatsappTemplates::sanitize(
                (string)$request->request->get('general_template', ''),
                $defaults['general']
            ),
        ];

        try {
            $this->settings->setMany($cleanTemplates);
            $_SESSION['rfb_whatsapp_template_feedback'] = [
                'type' => 'success',
                'message' => 'Mensagens da Base RFB atualizadas com sucesso.',
            ];
        } catch (\Throwable $exception) {
            $_SESSION['rfb_whatsapp_template_feedback'] = [
                'type' => 'error',
                'message' => 'Não foi possível salvar agora: ' . $exception->getMessage(),
                'old' => [
                    'partnership_template' => $request->request->get('partnership_template', ''),
                    'general_template' => $request->request->get('general_template', ''),
                ],
            ];
        }

        return new RedirectResponse(url('config') . '#whatsapp');
    }

    private function loadWhatsappTemplates(): array
    {
        $defaults = WhatsappTemplatePresets::defaults();
        $templates = [];

        foreach (WhatsappTemplatePresets::TEMPLATE_KEYS as $key) {
            $templates[$key] = (string)$this->settings->get('whatsapp.template.' . $key, $defaults[$key] ?? '');
        }

        return $templates;
    }

    private function loadRfbWhatsappTemplates(): array
    {
        $defaults = RfbWhatsappTemplates::defaults();

        return [
            'partnership' => (string)$this->settings->get('rfb.whatsapp.partnership_template', $defaults['partnership']),
            'general' => (string)$this->settings->get('rfb.whatsapp.general_template', $defaults['general']),
        ];
    }

    private function normalizeTemplateInput(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        return $normalized;
    }
}
