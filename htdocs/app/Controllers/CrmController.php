<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\CertificateRepository;
use App\Repositories\AvpAccessRepository;
use App\Repositories\ClientActionMarkRepository;
use App\Repositories\ClientProtocolRepository;
use App\Repositories\ClientRepository;
use App\Repositories\ClientStageHistoryRepository;
use App\Repositories\PipelineRepository;
use App\Repositories\PartnerRepository;
use App\Repositories\RfbProspectRepository;
use App\Repositories\SettingRepository;
use App\Repositories\WhatsappContactRepository;
use App\Repositories\WhatsappThreadRepository;
use App\Services\Import\ClientImportService;
use App\Support\WhatsappTemplatePresets;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CrmController
{
    private AvpAccessRepository $avpAccess;
    private const CLIENT_MARK_TTL_SECONDS = 172800; // 48 horas
    private const CLIENT_MARK_TTL_BY_TYPE = [
        'renewal' => 432000, // 120 horas / 5 dias
        'rescue' => 432000, // 120 horas / 5 dias
        'birthday' => 360 * 86400, // 360 dias
    ];
    private const CLIENT_MARK_TYPES = [
        'birthday' => ['label' => 'Felicitações', 'color' => '#4ade80'],
        'renewal' => ['label' => 'Renovação', 'color' => '#38bdf8'],
        'rescue' => ['label' => 'Resgate', 'color' => '#fb923c'],
        'schedule' => ['label' => 'Agenda', 'color' => '#a5b4fc'],
    ];
    /** @var array<int, string[]> */
    private array $avpFilterCache = [];

    public function __construct()
    {
        $this->avpAccess = new AvpAccessRepository();
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);

        $moduleAccess = [
            'overview' => $this->canAny($user, [
                'crm.overview',
                'crm.dashboard.metrics',
                'crm.dashboard.alerts',
                'crm.dashboard.performance',
                'crm.dashboard.partners',
                'crm.import',
            ]),
            'metrics' => $this->canAny($user, ['crm.dashboard.metrics']),
            'alerts' => $this->canAny($user, ['crm.dashboard.alerts']),
            'performance' => $this->canAny($user, ['crm.dashboard.performance']),
            'import' => $this->canAny($user, ['crm.import']),
            'partners' => $this->canAny($user, ['crm.dashboard.partners']),
        ];

        $metrics = [];
        if ($moduleAccess['metrics'] || $moduleAccess['alerts']) {
            $clientRepo = new ClientRepository();
            $metrics = $clientRepo->metrics();
        }

        $monthlyGrowth = [];
        $partnerStats = [
            'rolling' => [],
            'sort' => 'latest',
            'direction' => 'desc',
        ];

        $now = null;
        $certificateRepo = null;
        if ($moduleAccess['performance'] || $moduleAccess['partners']) {
            $certificateRepo = new CertificateRepository();
            $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
            $now = now();

            if ($moduleAccess['performance']) {
                $monthlyGrowth = $certificateRepo->monthlyComparative(null, $now);
            }

            if ($moduleAccess['partners']) {
                $twelveMonthsAgo = (new DateTimeImmutable('now', $timezone))->modify('-12 months')->getTimestamp();

                $sortField = strtolower((string)$request->query->get('sort', 'latest'));
                $sortDirection = strtolower((string)$request->query->get('direction', 'desc'));

                $allowedSortFields = ['partner', 'latest', 'total'];
                if (!in_array($sortField, $allowedSortFields, true)) {
                    $sortField = 'latest';
                }

                $allowedDirections = ['asc', 'desc'];
                if (!in_array($sortDirection, $allowedDirections, true)) {
                    $sortDirection = 'desc';
                }

                $partnerStatsData = $certificateRepo->partnerStats($twelveMonthsAgo, $now, null, $sortField, $sortDirection);

                $partnerStats = [
                    'rolling' => $partnerStatsData,
                    'sort' => $sortField,
                    'direction' => $sortDirection,
                ];
            }
        }

        $feedback = null;
        if ($moduleAccess['import']) {
            $feedback = $_SESSION['crm_import_feedback'] ?? null;
        }
        unset($_SESSION['crm_import_feedback']);

        return view('crm/index', [
            'metrics' => $metrics,
            'partnerStats' => $partnerStats,
            'monthlyGrowth' => $monthlyGrowth,
            'feedback' => $feedback,
            'moduleAccess' => $moduleAccess,
        ]);
    }

    public function import(Request $request): Response
    {
        $file = $request->files->get('spreadsheet');
        if ($file === null) {
            $this->flash('error', 'Selecione um arquivo para importar.');
            return new RedirectResponse(url('crm'));
        }

        if (!$file->isValid()) {
            $this->flash('error', 'Falha no upload do arquivo.');
            return new RedirectResponse(url('crm'));
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if (!in_array($extension, ['xls', 'xlsx', 'csv'], true)) {
            $this->flash('error', 'Formato não suportado. Utilize arquivos XLS ou XLSX.');
            return new RedirectResponse(url('crm'));
        }

        $targetDir = storage_path('uploads');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $filename = 'import_' . date('Ymd_His') . '_' . uniqid('', false) . '.' . $extension;
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $file->move($targetDir, $filename);

        $service = new ClientImportService();

        try {
            $stats = $service->import($filePath);
            $message = sprintf(
                'Importação concluída: %d processados, %d clientes novos, %d atualizados.',
                $stats['processed'] ?? 0,
                $stats['created_clients'] ?? 0,
                $stats['updated_clients'] ?? 0
            );
            $this->flash('success', $message, $stats);
        } catch (Throwable $exception) {
            $this->flash('error', 'Erro ao importar: ' . $exception->getMessage());
        }

        return new RedirectResponse(url('crm'));
    }

    public function clients(Request $request): Response
    {
        return $this->renderClients($request, 'active');
    }

    public function offClients(Request $request): Response
    {
        return $this->renderClients($request, 'off');
    }

    public function contactSearch(Request $request): Response
    {
        $authUser = $this->currentUser($request);
        if ($authUser === null) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $query = trim((string)$request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return json_response(['items' => []]);
        }

        $limit = (int)$request->query->get('limit', 8);
        $limit = max(1, min(20, $limit));

        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $results = $clientRepo->searchContactEmails($query, $restrictedAvpNames, $allowOnlineClients, $limit);

        $items = array_values(array_filter(array_map(static function (array $row): ?array {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($email === '') {
                return null;
            }

            $name = trim((string)($row['name'] ?? ''));
            $documentDigits = digits_only((string)($row['document'] ?? ''));

            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => $name !== '' ? $name : null,
                'email' => $email,
                'document' => $documentDigits !== '' ? $documentDigits : null,
                'document_formatted' => $documentDigits !== '' ? format_document($documentDigits) : null,
                'status' => $row['status'] ?? null,
            ];
        }, $results)));

        return json_response(['items' => $items]);
    }

    public function createClient(Request $request): Response
    {
        $clientRepo = new ClientRepository();
        $pipelineRepo = new PipelineRepository();

        $statusOptions = $clientRepo->statusLabels();
        unset($statusOptions['']);

        $pipelineStages = $pipelineRepo->allStages();

        $errors = $_SESSION['crm_create_errors'] ?? [];
        $old = $_SESSION['crm_create_old'] ?? [];
        $feedback = $_SESSION['crm_create_feedback'] ?? null;

        unset($_SESSION['crm_create_errors'], $_SESSION['crm_create_old'], $_SESSION['crm_create_feedback']);

        $defaultForm = [
            'document' => '',
            'name' => '',
            'titular_name' => '',
            'titular_document' => '',
            'titular_birthdate' => '',
            'email' => '',
            'phone' => '',
            'status' => 'prospect',
            'pipeline_stage_id' => '',
            'next_follow_up_at' => '',
            'partner_accountant' => '',
            'partner_accountant_plus' => '',
            'extra_phones' => [],
            'notes' => '',
        ];

        $extraPhonesOld = $old['extra_phones'] ?? [];
        unset($old['extra_phones']);

        $formData = array_merge($defaultForm, array_map(static fn($value) => is_scalar($value) ? (string)$value : '', $old));
        $formData['extra_phones'] = $this->normalizeExtraPhoneInput($extraPhonesOld);

        return view('crm/clients/create', [
            'formData' => $formData,
            'errors' => $errors,
            'statusOptions' => $statusOptions,
            'pipelineStages' => $pipelineStages,
            'feedback' => $feedback,
        ]);
    }

    public function checkClient(Request $request): Response
    {
        $documentDigits = digits_only((string)$request->request->get('document', ''));

        if ($documentDigits === '' || !in_array(strlen($documentDigits), [11, 14], true)) {
            return json_response([
                'found' => false,
                'error' => 'Informe um CPF ou CNPJ válido com 11 ou 14 dígitos.',
            ], 422);
        }

        $clientRepo = new ClientRepository();
        $client = $clientRepo->findByDocument($documentDigits);

        if ($client === null) {
            return json_response([
                'found' => false,
                'message' => 'Nenhum cliente cadastrado com este documento.',
            ]);
        }

        $clientId = (int)$client['id'];
        $authUser = $this->currentUser($request);
        if (!$this->userHasAccessToClient($authUser, $clientId)) {
            return json_response([
                'found' => false,
                'error' => 'Cliente não está liberado para o seu usuário.',
            ], 403);
        }

        return json_response([
            'found' => true,
            'redirect' => url('crm/clients/' . $clientId),
            'client' => [
                'id' => $clientId,
                'name' => $client['name'] ?? '',
            ],
        ]);
    }

    public function lookupTitular(Request $request): Response
    {
        $documentDigits = digits_only((string)$request->request->get('titular_document', ''));

        if ($documentDigits === '' || strlen($documentDigits) !== 11) {
            return json_response([
                'found' => false,
                'error' => 'Informe um CPF com 11 dígitos.',
            ], 422);
        }

        $clientRepo = new ClientRepository();
        $clients = $clientRepo->listByTitularDocument($documentDigits);
        $authUser = $this->currentUser($request);
        $clients = array_values(array_filter($clients, function (array $row) use ($authUser): bool {
            $clientId = (int)($row['id'] ?? 0);
            return $clientId > 0 && $this->userHasAccessToClient($authUser, $clientId);
        }));

        if ($clients === []) {
            return json_response([
                'found' => false,
                'message' => 'Nenhum CNPJ vinculado a este CPF.'
            ]);
        }

        $statusLabels = $clientRepo->statusLabels();

        $items = array_map(static function (array $row) use ($statusLabels): array {
            $id = (int)($row['id'] ?? 0);
            $document = (string)($row['document'] ?? '');
            $status = (string)($row['status'] ?? '');

            return [
                'id' => $id,
                'name' => $row['name'] ?? '',
                'document' => $document,
                'document_formatted' => format_document($document),
                'status' => $status,
                'status_label' => $statusLabels[$status] ?? $status,
                'url' => url('crm/clients/' . $id),
            ];
        }, $clients);

        return json_response([
            'found' => true,
            'count' => count($items),
            'titular_document' => $documentDigits,
            'titular_document_formatted' => format_document($documentDigits),
            'clients' => $items,
        ]);
    }

    public function storeClient(Request $request): Response
    {
        $authUser = $this->currentUser($request);
        $input = [
            'document' => (string)$request->request->get('document', ''),
            'name' => (string)$request->request->get('name', ''),
            'titular_name' => (string)$request->request->get('titular_name', ''),
            'titular_document' => (string)$request->request->get('titular_document', ''),
            'titular_birthdate' => (string)$request->request->get('titular_birthdate', ''),
            'email' => (string)$request->request->get('email', ''),
            'phone' => (string)$request->request->get('phone', ''),
            'extra_phones' => $request->request->get('extra_phones', []),
            'status' => (string)$request->request->get('status', 'prospect'),
            'pipeline_stage_id' => (string)$request->request->get('pipeline_stage_id', ''),
            'next_follow_up_at' => (string)$request->request->get('next_follow_up_at', ''),
            'partner_accountant' => (string)$request->request->get('partner_accountant', ''),
            'partner_accountant_plus' => (string)$request->request->get('partner_accountant_plus', ''),
            'notes' => (string)$request->request->get('notes', ''),
        ];

        $clientRepo = new ClientRepository();
        $pipelineRepo = new PipelineRepository();

        $statusOptions = $clientRepo->statusLabels();
        unset($statusOptions['']);

        $documentDigits = digits_only($input['document']);
        $titularDocumentDigits = digits_only($input['titular_document']);
        $phoneDigits = digits_only($input['phone']);
        $extraPhonesInput = $this->normalizeExtraPhoneInput($input['extra_phones']);
        $status = array_key_exists($input['status'], $statusOptions) ? $input['status'] : 'prospect';

        $pipelineStages = $pipelineRepo->allStages();
        $stageMap = [];
        foreach ($pipelineStages as $stage) {
            $stageId = (int)($stage['id'] ?? 0);
            if ($stageId > 0) {
                $stageMap[$stageId] = $stage;
            }
        }

        $selectedStageId = null;
        if ($input['pipeline_stage_id'] !== '') {
            $candidate = (int)$input['pipeline_stage_id'];
            if (isset($stageMap[$candidate])) {
                $selectedStageId = $candidate;
            }
        }

        $errors = [];
        $existingClient = null;

        if ($documentDigits === '' || !in_array(strlen($documentDigits), [11, 14], true)) {
            $errors['document'] = 'Informe um CPF ou CNPJ válido.';
        } else {
            $existingClient = $clientRepo->findByDocument($documentDigits);
            if ($existingClient !== null) {
                $errors['document'] = 'Já existe um cliente com este documento.';
            }
        }

        $name = trim($input['name']);
        if ($name === '') {
            $errors['name'] = 'Informe o nome do cliente.';
        }

        $email = trim(strtolower($input['email']));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-mail inválido.';
        }

        if ($titularDocumentDigits !== '' && !in_array(strlen($titularDocumentDigits), [11, 14], true)) {
            $errors['titular_document'] = 'Documento do titular deve conter 11 ou 14 dígitos.';
        }

        $birthdateTimestamp = null;
        if ($input['titular_birthdate'] !== '') {
            $birthdateTimestamp = $this->parseLocalDate($input['titular_birthdate']);
            if ($birthdateTimestamp === null) {
                $errors['titular_birthdate'] = 'Data de nascimento inválida.';
            }
        }

        $nextFollowUpAt = null;
        if ($input['next_follow_up_at'] !== '') {
            $nextFollowUpAt = $this->parseLocalDateTime($input['next_follow_up_at']);
            if ($nextFollowUpAt === null) {
                $errors['next_follow_up_at'] = 'Data e hora de acompanhamento inválidas.';
            }
        }

        $invalidExtraPhones = array_filter($extraPhonesInput, static fn(string $digits): bool => strlen($digits) < 10);
        if ($invalidExtraPhones !== []) {
            $errors['extra_phones'] = 'Telefones adicionais devem ter ao menos 10 dígitos.';
        }

        $primaryNumbers = $phoneDigits !== '' ? [$phoneDigits] : [];
        if ($primaryNumbers !== []) {
            $extraPhonesInput = array_values(array_filter($extraPhonesInput, static function (string $digits) use ($primaryNumbers): bool {
                return !in_array($digits, $primaryNumbers, true);
            }));
        }

        if ($errors !== []) {
            $_SESSION['crm_create_errors'] = $errors;
            $_SESSION['crm_create_old'] = array_merge($input, [
                'document' => $documentDigits,
                'titular_document' => $titularDocumentDigits,
                'phone' => $phoneDigits,
                'extra_phones' => $extraPhonesInput,
                'status' => $status,
            ]);

            $feedback = [
                'type' => 'error',
                'message' => 'Não foi possível criar o cliente. Revise os campos destacados.',
            ];

            if ($existingClient !== null) {
                $feedback['meta'] = [
                    'client_id' => (int)$existingClient['id'],
                ];
            }

            $_SESSION['crm_create_feedback'] = $feedback;

            return new RedirectResponse(url('crm/clients/create'));
        }

        $payload = [
            'document' => $documentDigits,
            'name' => mb_strtoupper($name, 'UTF-8'),
            'titular_name' => trim($input['titular_name']) !== '' ? trim($input['titular_name']) : null,
            'titular_document' => $titularDocumentDigits !== '' ? $titularDocumentDigits : null,
            'titular_birthdate' => $birthdateTimestamp,
            'email' => $email !== '' ? $email : null,
            'phone' => $phoneDigits !== '' ? $phoneDigits : null,
            'whatsapp' => $phoneDigits !== '' ? $phoneDigits : null,
            'extra_phones' => $extraPhonesInput !== [] ? json_encode($extraPhonesInput) : null,
            'status' => $status,
            'status_changed_at' => now(),
            'last_protocol' => null,
            'last_certificate_expires_at' => null,
            'next_follow_up_at' => $nextFollowUpAt,
            'partner_accountant' => trim($input['partner_accountant']) !== '' ? trim($input['partner_accountant']) : null,
            'partner_accountant_plus' => trim($input['partner_accountant_plus']) !== '' ? trim($input['partner_accountant_plus']) : null,
            'pipeline_stage_id' => $selectedStageId,
            'tags_cache' => null,
            'notes' => trim($input['notes']) !== '' ? trim($input['notes']) : null,
            'document_status' => 'active',
            'is_off' => 0,
        ];

        if ($payload['pipeline_stage_id'] === null) {
            unset($payload['pipeline_stage_id']);
        }

        $clientId = $clientRepo->insert($payload);

        (new RfbProspectRepository())->deleteByCnpjs([$documentDigits]);

        if ($selectedStageId !== null) {
            $historyRepo = new ClientStageHistoryRepository();
            $historyRepo->record($clientId, null, $selectedStageId, 'Cadastro manual', 'Painel CRM');
        }

        $partnerRepo = new PartnerRepository();
        foreach ([$payload['partner_accountant'] ?? null, $payload['partner_accountant_plus'] ?? null] as $partnerName) {
            if ($partnerName !== null) {
                $partnerRepo->findOrCreate($partnerName, 'contador');
            }
        }

        $this->flashClient('success', 'Cliente criado com sucesso.');
        return new RedirectResponse(url('crm/clients/' . $clientId));
    }

    public function showClient(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return abort(404, 'Cliente não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        $certificateRepo = new CertificateRepository();
        $protocolRepo = new ClientProtocolRepository();
        $markRepo = new ClientActionMarkRepository();

        $titularDocumentDigits = digits_only((string)($client['titular_document'] ?? ''));
        $relatedClientIds = [$clientId];
        $owners = [
            $clientId => [
                'id' => $clientId,
                'name' => $client['name'] ?? '',
                'document' => $client['document'] ?? '',
            ],
        ];

        if ($titularDocumentDigits !== '' && strlen($titularDocumentDigits) === 11) {
            $relatedClients = $clientRepo->listByTitularDocument($titularDocumentDigits);
            if ($this->requiresClientRestriction($authUser)) {
                $relatedClients = array_values(array_filter($relatedClients, function (array $row) use ($authUser): bool {
                    $relatedId = (int)($row['id'] ?? 0);
                    return $relatedId > 0 && $this->userHasAccessToClient($authUser, $relatedId);
                }));
            }

            foreach ($relatedClients as $related) {
                $relatedId = (int)($related['id'] ?? 0);
                if ($relatedId <= 0) {
                    continue;
                }

                $relatedClientIds[] = $relatedId;

                if (!isset($owners[$relatedId])) {
                    $owners[$relatedId] = [
                        'id' => $relatedId,
                        'name' => $related['name'] ?? '',
                        'document' => $related['document'] ?? '',
                    ];
                }
            }
        }

        $relatedClientIds = array_values(array_unique(array_filter($relatedClientIds, static fn(int $id): bool => $id > 0)));

        $certificates = $certificateRepo->forClients($relatedClientIds);

        $certificateScope = [
            'mode' => count($relatedClientIds) > 1 ? 'titular' : 'client',
            'titular_document' => $titularDocumentDigits,
            'titular_document_formatted' => $titularDocumentDigits !== '' ? format_document($titularDocumentDigits) : '',
            'client_ids' => $relatedClientIds,
            'client_count' => count($relatedClientIds),
            'owners' => $owners,
        ];

        $pipelineRepo = new PipelineRepository();
        $pipelineStages = $pipelineRepo->allStages();

        $stageHistoryRepo = new ClientStageHistoryRepository();
        $stageHistory = $stageHistoryRepo->forClient($clientId, 25);

        $feedback = $_SESSION['crm_client_feedback'] ?? null;
        unset($_SESSION['crm_client_feedback']);

        $protocolFeedback = $_SESSION['crm_protocol_feedback'] ?? null;
        unset($_SESSION['crm_protocol_feedback']);

        $clientDocumentRaw = (string)($client['document'] ?? '');

        $protocolForm = [
            'protocol_number' => '',
            'description' => '',
            'document' => $clientDocumentRaw !== '' ? format_document($clientDocumentRaw) : '',
            'starts_at' => '',
            'expires_at' => '',
        ];

        if (
            isset($protocolFeedback['old'])
            && is_array($protocolFeedback['old'])
            && (int)($protocolFeedback['target'] ?? 0) === 0
        ) {
            $protocolForm = array_merge($protocolForm, array_map(static fn($value) => is_scalar($value) ? (string)$value : '', $protocolFeedback['old']));
        }

        $protocols = $protocolRepo->listByClient($clientId);

        $settingsRepo = new SettingRepository();
        $whatsappTemplateDefaults = WhatsappTemplatePresets::defaults();
        $whatsappTemplates = [];
        foreach (WhatsappTemplatePresets::TEMPLATE_KEYS as $templateKey) {
            $whatsappTemplates[$templateKey] = (string)$settingsRepo->get('whatsapp.template.' . $templateKey, $whatsappTemplateDefaults[$templateKey] ?? '');
        }

        $renewalWindowBeforeDays = max(0, (int)$settingsRepo->get('whatsapp.renewal_window.before_days', 60));
        $renewalWindowAfterDays = max(0, (int)$settingsRepo->get('whatsapp.renewal_window.after_days', 60));

        $contactRepo = new WhatsappContactRepository();
        $threadRepo = new WhatsappThreadRepository();

        $candidatePhones = [];
        $phoneDigits = digits_only((string)($client['phone'] ?? ''));
        $whatsappDigits = digits_only((string)($client['whatsapp'] ?? ''));
        if ($phoneDigits !== '') {
            $candidatePhones[] = $phoneDigits;
        }
        if ($whatsappDigits !== '') {
            $candidatePhones[] = $whatsappDigits;
        }

        $extraPhones = $this->normalizeExtraPhoneInput($client['extra_phones'] ?? []);
        foreach ($extraPhones as $extraPhone) {
            if (!in_array($extraPhone, $candidatePhones, true)) {
                $candidatePhones[] = $extraPhone;
            }
        }

        $whatsappContacts = $contactRepo->listByClientId($clientId);
        if ($candidatePhones !== []) {
            $byPhone = $contactRepo->listByPhones($candidatePhones);
            $whatsappContacts = array_values(array_reduce([$whatsappContacts, $byPhone], static function (array $carry, array $list): array {
                foreach ($list as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id === 0) {
                        continue;
                    }
                    $carry[$id] = $row;
                }
                return $carry;
            }, []));
        }

        $whatsappContacts = array_map(static function (array $row): array {
            $metadataDecoded = [];
            if (!empty($row['metadata']) && is_string($row['metadata'])) {
                $decoded = json_decode((string)$row['metadata'], true);
                if (is_array($decoded)) {
                    $metadataDecoded = $decoded;
                }
            }

            $profilePhoto = $metadataDecoded['profile_photo'] ?? null;
            if (!is_string($profilePhoto) || ($profilePhoto !== '' && !str_starts_with($profilePhoto, 'http'))) {
                $profilePhoto = null;
            }

            $profileName = (string)($metadataDecoded['profile'] ?? ($row['name'] ?? ''));

            $row['metadata_decoded'] = $metadataDecoded;
            $row['profile_photo'] = $profilePhoto;
            $row['profile_name'] = $profileName;

            return $row;
        }, $whatsappContacts);

        $contactIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $whatsappContacts);
        $recentThreads = $contactIds !== [] ? $threadRepo->listRecentByContactIds($contactIds, 8) : [];

        $whatsappOverview = [
            'contacts' => $whatsappContacts,
            'threads' => $recentThreads,
        ];

        return view('crm/clients/show', [
            'client' => $client,
            'certificates' => $certificates,
            'certificateScope' => $certificateScope,
            'pipelineStages' => $pipelineStages,
            'statusLabels' => $clientRepo->statusLabels(),
            'stageHistory' => $stageHistory,
            'feedback' => $feedback,
            'protocols' => $protocols,
            'protocolFeedback' => $protocolFeedback,
            'protocolForm' => $protocolForm,
            'whatsappTemplates' => $whatsappTemplates,
            'renewalWindowBeforeDays' => $renewalWindowBeforeDays,
            'renewalWindowAfterDays' => $renewalWindowAfterDays,
            'clientActionMarks' => $markRepo->activeMarksForClient($clientId),
            'clientMarkTypes' => $this->clientMarkTypes(),
            'clientMarkTtlHours' => $this->clientMarkTtlHours(),
            'clientMarkTtlSecondsDefault' => $this->clientMarkTtlSecondsDefault(),
            'clientMarkTtlSecondsByType' => $this->clientMarkTtlSecondsByType(),
            'whatsappOverview' => $whatsappOverview,
        ]);
    }

    private function renderClients(Request $request, string $defaultOffScope): Response
    {
        $clientRepo = new ClientRepository();
        $pipelineRepo = new PipelineRepository();
        $authUser = $this->currentUser($request);
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $pipelineStages = $pipelineRepo->allStages();
        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));

        $offScope = (string)$request->query->get('off_scope', $defaultOffScope);
        if (!in_array($offScope, ['active', 'off', 'all'], true)) {
            $offScope = $defaultOffScope;
        }

        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = (int)$request->query->get('per_page', 25);
        if ($perPage <= 0) {
            $perPage = 25;
        }
        $perPage = min($perPage, 100);

        $validExpirationWindows = ['next_30', 'next_20', 'next_10', 'today', 'past_10', 'past_20', 'past_30'];
        $expirationWindow = (string)$request->query->get('expiration_window', '');
        if (!in_array($expirationWindow, $validExpirationWindows, true)) {
            $expirationWindow = '';
        }

        $expirationMonth = (int)$request->query->get('expiration_month', 0);
        if ($expirationMonth < 1 || $expirationMonth > 12) {
            $expirationMonth = 0;
        }

        $expirationScope = (string)$request->query->get('expiration_scope', 'current');
        if (!in_array($expirationScope, ['current', 'all'], true)) {
            $expirationScope = 'current';
        }

        $currentYear = (int)date('Y');
        $expirationYear = (int)$request->query->get('expiration_year', $currentYear);
        if ($expirationYear < 2020 || $expirationYear > 2040) {
            $expirationYear = $currentYear;
        }

        $documentType = (string)$request->query->get('document_type', '');
        if (!in_array($documentType, ['', 'cpf', 'cnpj'], true)) {
            $documentType = '';
        }

        $birthdayMonth = (int)$request->query->get('birthday_month', 0);
        if ($birthdayMonth < 1 || $birthdayMonth > 12) {
            $birthdayMonth = 0;
        }

        $birthdayDay = (int)$request->query->get('birthday_day', 0);
        if ($birthdayDay < 1 || $birthdayDay > 31) {
            $birthdayDay = 0;
        }

        if ($birthdayMonth === 0) {
            $birthdayDay = 0;
        }

        $availableStageIds = array_map(static fn(array $stage): int => (int)($stage['id'] ?? 0), $pipelineStages);
        $pipelineStageId = (int)$request->query->get('pipeline_stage_id', 0);
        if ($pipelineStageId > 0 && !in_array($pipelineStageId, $availableStageIds, true)) {
            $pipelineStageId = 0;
        }

        $expirationDateInput = trim((string)$request->query->get('expiration_date', ''));
        $expirationDateStart = null;
        $expirationDateEnd = null;
        if ($expirationDateInput !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateInput, $timezone);
            if ($date instanceof DateTimeImmutable) {
                $expirationDateStart = $date->setTime(0, 0, 0)->getTimestamp();
                $expirationDateEnd = $date->setTime(23, 59, 59)->getTimestamp();
            } else {
                $expirationDateInput = '';
            }
        }

        $lastAvpFilter = trim((string)$request->query->get('last_avp_name', ''));

        $filters = [
            'status' => (string)$request->query->get('status', ''),
            'query' => (string)$request->query->get('query', ''),
            'partner' => (string)$request->query->get('partner', ''),
            'pipeline_stage_id' => $pipelineStageId,
            'expiration_window' => $expirationWindow,
            'expiration_month' => $expirationMonth,
            'expiration_scope' => $expirationScope,
            'expiration_year' => $expirationYear,
            'expiration_date' => $expirationDateInput,
            'expiration_date_start' => $expirationDateStart,
            'expiration_date_end' => $expirationDateEnd,
            'document_type' => $documentType,
            'birthday_month' => $birthdayMonth,
            'birthday_day' => $birthdayDay,
            'off_scope' => $offScope,
            'last_avp_name' => $lastAvpFilter,
        ];
        $filtersApplied = (string)$request->query->get('applied', '0') === '1';
        $hasSearchFilters = $this->hasClientListFilters($filters);
        $shouldQuery = $filtersApplied && $hasSearchFilters;

        if ($shouldQuery) {
            $result = $clientRepo->paginate($page, $perPage, $filters, $restrictedAvpNames, $allowOnlineClients);
            $result['filters'] = array_merge($filters, $result['filters'], [
                'applied' => 1,
                'blocked' => 0,
            ]);
        } else {
            $result = [
                'data' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'pages' => 1,
                ],
                'filters' => array_merge($filters, [
                    'applied' => 0,
                    'blocked' => $filtersApplied && !$hasSearchFilters ? 1 : 0,
                ]),
                'meta' => [
                    'count' => 0,
                    'total' => 0,
                ],
            ];
        }

        $partnerIndicators = $clientRepo->partnerIndicators($offScope);
        if (!empty($result['data'])) {
            $markRepo = new ClientActionMarkRepository();
            $clientIds = array_values(array_unique(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $result['data'])));
            $markMap = $markRepo->activeMarksForClients($clientIds);

            foreach ($result['data'] as &$row) {
                $rowId = (int)($row['id'] ?? 0);
                $row['action_marks'] = $rowId > 0 ? ($markMap[$rowId] ?? []) : [];
            }
            unset($row);
        }

        $viewMode = $defaultOffScope === 'off' ? 'off' : 'active';

        return view('crm/clients/index', [
            'clients' => $result['data'],
            'pagination' => $result['pagination'],
            'filters' => $result['filters'],
            'meta' => $result['meta'] ?? ['count' => count($result['data']), 'total' => $result['pagination']['total'] ?? 0],
            'statusLabels' => $clientRepo->statusLabels(),
            'perPage' => $perPage,
            'yearOptions' => range(2020, 2040),
            'viewMode' => $viewMode,
            'offScope' => $offScope,
            'partnerIndicators' => $partnerIndicators,
            'pipelineStages' => $pipelineStages,
            'clientMarkTypes' => $this->clientMarkTypes(),
            'clientMarkTtlHours' => $this->clientMarkTtlHours(),
        ]);
    }

    private function hasClientListFilters(array $filters): bool
    {
        return ($filters['query'] ?? '') !== ''
            || ($filters['status'] ?? '') !== ''
            || ($filters['partner'] ?? '') !== ''
            || (int)($filters['pipeline_stage_id'] ?? 0) > 0
            || ($filters['expiration_window'] ?? '') !== ''
            || (int)($filters['expiration_month'] ?? 0) > 0
            || ($filters['document_type'] ?? '') !== ''
            || ($filters['expiration_scope'] ?? 'current') === 'all'
            || (int)($filters['birthday_month'] ?? 0) > 0
            || ($filters['expiration_date'] ?? '') !== ''
            || ($filters['last_avp_name'] ?? '') !== '';
    }

    public function updateClient(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return abort(404, 'Cliente não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        $currentDocumentDigits = digits_only((string)($client['document'] ?? ''));
        $documentOverride = $request->request->get('document');
        if ($documentOverride !== null) {
            $documentOverrideDigits = digits_only((string)$documentOverride);
            if ($documentOverrideDigits !== '' && $documentOverrideDigits !== $currentDocumentDigits) {
                $this->flashClient('error', 'Não é permitido alterar o CNPJ do cliente.');
                return new RedirectResponse(url('crm/clients/' . $clientId));
            }
        }

        $currentTitularDocumentDigits = digits_only((string)($client['titular_document'] ?? ''));
        $titularDocumentRaw = $request->request->get('titular_document');
        $titularDocumentCandidate = $currentTitularDocumentDigits;
        $shouldUpdateTitularDocument = false;
        if ($titularDocumentRaw !== null) {
            $candidateDigits = digits_only((string)$titularDocumentRaw);
            if ($currentTitularDocumentDigits !== '') {
                if ($candidateDigits !== '' && $candidateDigits !== $currentTitularDocumentDigits) {
                    $this->flashClient('error', 'Não é permitido alterar o CPF do titular.');
                    return new RedirectResponse(url('crm/clients/' . $clientId));
                }
            } elseif ($candidateDigits !== '') {
                if (strlen($candidateDigits) !== 11) {
                    $this->flashClient('error', 'CPF do titular deve conter 11 dígitos.');
                    return new RedirectResponse(url('crm/clients/' . $clientId));
                }
                $titularDocumentCandidate = $candidateDigits;
                $shouldUpdateTitularDocument = true;
            }
        }

        $statusLabels = $clientRepo->statusLabels();
        unset($statusLabels['']);

        $statusInput = (string)$request->request->get('status', $client['status']);
        if ($statusInput === '') {
            $statusInput = $client['status'];
        }

        if ($statusInput !== $client['status'] && !array_key_exists($statusInput, $statusLabels)) {
            $this->flashClient('error', 'Status selecionado é inválido.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }

        $pipelineRepo = new PipelineRepository();
        $pipelineStages = $pipelineRepo->allStages();
        $stageMap = [];
        foreach ($pipelineStages as $stage) {
            $stageMap[(int)$stage['id']] = $stage;
        }

        $stageInput = $request->request->get('pipeline_stage_id');
        $newStageId = null;
        if ($stageInput !== null && $stageInput !== '') {
            $stageId = (int)$stageInput;
            if (!isset($stageMap[$stageId])) {
                $this->flashClient('error', 'Etapa selecionada é inválida.');
                return new RedirectResponse(url('crm/clients/' . $clientId));
            }
            $newStageId = $stageId;
        }

        $clearFollowUp = $request->request->get('clear_follow_up') === '1';
        $nextFollowUpRaw = $request->request->get('next_follow_up_at');
        $shouldUpdateFollowUp = false;
        $nextFollowUpValue = null;

        if ($clearFollowUp) {
            if ($client['next_follow_up_at'] !== null) {
                $shouldUpdateFollowUp = true;
                $nextFollowUpValue = null;
            }
        } elseif (is_string($nextFollowUpRaw) && trim($nextFollowUpRaw) !== '') {
            $parsedFollowUp = $this->parseLocalDateTime($nextFollowUpRaw);
            if ($parsedFollowUp === null) {
                $this->flashClient('error', 'Data e hora de acompanhamento inválida.');
                return new RedirectResponse(url('crm/clients/' . $clientId));
            }
            if ((int)($client['next_follow_up_at'] ?? 0) !== $parsedFollowUp) {
                $shouldUpdateFollowUp = true;
                $nextFollowUpValue = $parsedFollowUp;
            }
        }

        $notesInput = trim((string)$request->request->get('notes', ''));
        $stageNote = trim((string)$request->request->get('stage_note', ''));

        $nameInput = trim((string)$request->request->get('name', (string)$client['name']));
        if ($nameInput === '') {
            $this->flashClient('error', 'O nome do cliente não pode ficar vazio.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }
        $normalizedName = mb_strtoupper($nameInput, 'UTF-8');

        $emailInputRaw = trim((string)$request->request->get('email', ''));
        if ($emailInputRaw !== '' && filter_var($emailInputRaw, FILTER_VALIDATE_EMAIL) === false) {
            $this->flashClient('error', 'E-mail informado é inválido.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }
        $emailValue = $emailInputRaw !== '' ? strtolower($emailInputRaw) : null;

        $phoneDigits = digits_only((string)$request->request->get('phone', ''));
        if ($phoneDigits !== '' && strlen($phoneDigits) < 10) {
            $this->flashClient('error', 'Telefone deve conter ao menos 10 dígitos.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }
        $phoneValue = $phoneDigits !== '' ? $phoneDigits : null;

        $whatsappDigits = digits_only((string)$request->request->get('whatsapp', ''));
        if ($whatsappDigits !== '' && strlen($whatsappDigits) < 10) {
            $this->flashClient('error', 'WhatsApp deve conter ao menos 10 dígitos.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }
        if ($whatsappDigits === '' && $phoneDigits !== '') {
            $whatsappDigits = $phoneDigits;
        }
        $whatsappValue = $whatsappDigits !== '' ? $whatsappDigits : null;

        $extraPhonesInput = $this->normalizeExtraPhoneInput($request->request->get('extra_phones', []));
        foreach ($extraPhonesInput as $extraDigits) {
            if (strlen($extraDigits) < 10) {
                $this->flashClient('error', 'Telefones adicionais devem ter ao menos 10 dígitos.');
                return new RedirectResponse(url('crm/clients/' . $clientId));
            }
        }

        $primaryNumbers = array_values(array_filter([$phoneValue, $whatsappValue], static function ($value): bool {
            return $value !== null && $value !== '';
        }));
        if ($primaryNumbers !== []) {
            $extraPhonesInput = array_values(array_filter($extraPhonesInput, static function (string $digits) use ($primaryNumbers): bool {
                return !in_array($digits, $primaryNumbers, true);
            }));
        }

        $partnerAccountant = trim((string)$request->request->get('partner_accountant', ''));
        $partnerAccountant = $partnerAccountant !== '' ? $partnerAccountant : null;

        $partnerAccountantPlus = trim((string)$request->request->get('partner_accountant_plus', ''));
        $partnerAccountantPlus = $partnerAccountantPlus !== '' ? $partnerAccountantPlus : null;

        $titularNameInput = trim((string)$request->request->get('titular_name', ''));
        $titularNameValue = $titularNameInput !== '' ? $titularNameInput : null;

        $updates = [];
        $changes = [];

        if ($normalizedName !== (string)$client['name']) {
            $updates['name'] = $normalizedName;
            $changes[] = 'name';
        }

        $currentEmail = $client['email'] !== null ? strtolower(trim((string)$client['email'])) : null;
        if ($emailValue !== $currentEmail) {
            $updates['email'] = $emailValue;
            $changes[] = 'email';
        }

        $currentPhoneDigits = digits_only((string)($client['phone'] ?? ''));
        $currentPhone = $currentPhoneDigits !== '' ? $currentPhoneDigits : null;
        if ($phoneValue !== $currentPhone) {
            $updates['phone'] = $phoneValue;
            $changes[] = 'phone';
        }

        $currentWhatsappDigits = digits_only((string)($client['whatsapp'] ?? ''));
        $currentWhatsapp = $currentWhatsappDigits !== '' ? $currentWhatsappDigits : null;
        if ($whatsappValue !== $currentWhatsapp) {
            $updates['whatsapp'] = $whatsappValue;
            $changes[] = 'whatsapp';
        }

        $currentExtraPhones = $this->normalizeExtraPhoneInput($client['extra_phones'] ?? []);
        $sortedCurrentExtra = $currentExtraPhones;
        $sortedNewExtra = $extraPhonesInput;
        sort($sortedCurrentExtra);
        sort($sortedNewExtra);
        if ($sortedNewExtra !== $sortedCurrentExtra) {
            $updates['extra_phones'] = $extraPhonesInput !== [] ? json_encode($extraPhonesInput) : null;
            $changes[] = 'extra_phones';
        }

        $currentPartnerAccountant = $client['partner_accountant'] !== null ? trim((string)$client['partner_accountant']) : null;
        $currentPartnerAccountant = $currentPartnerAccountant !== '' ? $currentPartnerAccountant : null;
        if ($partnerAccountant !== $currentPartnerAccountant) {
            $updates['partner_accountant'] = $partnerAccountant;
            $changes[] = 'partner_accountant';
        }

        $currentPartnerAccountantPlus = $client['partner_accountant_plus'] !== null ? trim((string)$client['partner_accountant_plus']) : null;
        $currentPartnerAccountantPlus = $currentPartnerAccountantPlus !== '' ? $currentPartnerAccountantPlus : null;
        if ($partnerAccountantPlus !== $currentPartnerAccountantPlus) {
            $updates['partner_accountant_plus'] = $partnerAccountantPlus;
            $changes[] = 'partner_accountant_plus';
        }

        $currentTitularName = $client['titular_name'] !== null ? trim((string)$client['titular_name']) : null;
        $currentTitularName = $currentTitularName !== '' ? $currentTitularName : null;
        if ($titularNameValue !== $currentTitularName) {
            $updates['titular_name'] = $titularNameValue;
            $changes[] = 'titular_name';
        }

        if ($shouldUpdateTitularDocument) {
            $updates['titular_document'] = $titularDocumentCandidate;
            $changes[] = 'titular_document';
        }

        if ($statusInput !== $client['status']) {
            $updates['status'] = $statusInput;
            $updates['status_changed_at'] = now();
            $changes[] = 'status';
        }

        $currentStageId = $client['pipeline_stage_id'] !== null ? (int)$client['pipeline_stage_id'] : null;
        if ($newStageId !== $currentStageId) {
            $updates['pipeline_stage_id'] = $newStageId;
            $changes[] = 'pipeline_stage';
        }

        if ($shouldUpdateFollowUp) {
            $updates['next_follow_up_at'] = $nextFollowUpValue;
            $changes[] = 'next_follow_up_at';
        }

        $currentNotes = trim((string)($client['notes'] ?? ''));
        if ($notesInput !== $currentNotes) {
            $updates['notes'] = $notesInput === '' ? null : $notesInput;
            $changes[] = 'notes';
        }

        if ($updates === []) {
            $this->flashClient('info', 'Nenhuma alteração detectada.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }

        $clientRepo->update($clientId, $updates);

        if (in_array('partner_accountant', $changes, true) || in_array('partner_accountant_plus', $changes, true)) {
            $partnerRepo = new PartnerRepository();
            foreach ([$partnerAccountant, $partnerAccountantPlus] as $partnerName) {
                if ($partnerName !== null) {
                    $partnerRepo->findOrCreate($partnerName, 'contador');
                }
            }
        }

        if (in_array('pipeline_stage', $changes, true)) {
            $historyRepo = new ClientStageHistoryRepository();
            $historyRepo->record(
                $clientId,
                $currentStageId,
                $newStageId,
                $stageNote !== '' ? $stageNote : null,
                'Painel CRM'
            );
        }

        $this->flashClient('success', 'Dados do cliente atualizados com sucesso.');
        return new RedirectResponse(url('crm/clients/' . $clientId));
    }

    public function moveClientOff(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return abort(404, 'Cliente não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        if ((int)($client['is_off'] ?? 0) === 1) {
            $this->flashClient('info', 'Cliente já está na carteira off.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }

        $updates = [
            'is_off' => 1,
            'next_follow_up_at' => null,
        ];

        $currentStatus = (string)($client['status'] ?? '');
        if ($currentStatus !== 'lost' && $currentStatus !== 'inactive') {
            $updates['status'] = 'inactive';
            $updates['status_changed_at'] = now();
        }

        $clientRepo->update($clientId, $updates);

        $this->flashClient('success', 'Cliente movido para a carteira off.');
        return new RedirectResponse(url('crm/clients/' . $clientId));
    }

    public function restoreClient(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return abort(404, 'Cliente não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        if ((int)($client['is_off'] ?? 0) === 0) {
            $this->flashClient('info', 'Cliente já está na carteira principal.');
            return new RedirectResponse(url('crm/clients/' . $clientId));
        }

        $updates = [
            'is_off' => 0,
        ];

        $clientRepo->update($clientId, $updates);

        $service = new ClientImportService();
        $service->refreshClients([$clientId]);

        $this->flashClient('success', 'Cliente retornou para a carteira principal.');
        return new RedirectResponse(url('crm/clients/' . $clientId));
    }

    public function storeProtocol(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return abort(404, 'Cliente não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        $protocolNumberRaw = (string)$request->request->get('protocol_number', '');
        $description = trim((string)$request->request->get('description', ''));
        $documentRaw = (string)$request->request->get('document', '');
        $startsAtRaw = (string)$request->request->get('starts_at', '');
        $expiresAtRaw = (string)$request->request->get('expires_at', '');

        $old = [
            'protocol_number' => $protocolNumberRaw,
            'description' => $description,
            'document' => $documentRaw,
            'starts_at' => $startsAtRaw,
            'expires_at' => $expiresAtRaw,
        ];

        $errors = [];

        $protocolNumber = mb_strtoupper(trim($protocolNumberRaw), 'UTF-8');
        if ($protocolNumber === '') {
            $errors['protocol_number'] = 'Informe o número do protocolo.';
        }

        $documentDigits = digits_only($documentRaw);
        if ($documentDigits === '' || !in_array(strlen($documentDigits), [11, 14], true)) {
            $errors['document'] = 'Informe um CPF ou CNPJ válido.';
        }

        $clientDocumentDigits = digits_only((string)($client['document'] ?? ''));
        if ($clientDocumentDigits === '') {
            $errors['document'] = 'Cliente sem CPF/CNPJ cadastrado. Atualize os dados antes de criar protocolos.';
        } elseif ($documentDigits !== '' && $documentDigits !== $clientDocumentDigits) {
            $errors['document'] = 'O CPF/CNPJ informado deve ser o mesmo deste cliente.';
        }

        $startsAt = null;
        if ($startsAtRaw !== '') {
            $startsAt = $this->parseLocalDate($startsAtRaw);
            if ($startsAt === null) {
                $errors['starts_at'] = 'Data inicial inválida.';
            }
        }

        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            $expiresAt = $this->parseLocalDate($expiresAtRaw);
            if ($expiresAt === null) {
                $errors['expires_at'] = 'Data de vencimento inválida.';
            }
        }

        if ($startsAt !== null && $expiresAt !== null && $startsAt > $expiresAt) {
            $errors['expires_at'] = 'Vencimento não pode ser anterior à data inicial.';
        }

        $protocolRepo = new ClientProtocolRepository();
        if ($protocolNumber !== '' && $protocolRepo->existsProtocol($protocolNumber)) {
            $errors['protocol_number'] = 'Este protocolo já está cadastrado para outro cliente.';
        }

        if ($errors !== []) {
            $this->flashProtocol('error', 'Não foi possível salvar o protocolo. Corrija os campos e tente novamente.', [
                'errors' => $errors,
                'old' => $old,
            ]);

            return new RedirectResponse(url('crm/clients/' . $clientId) . '#protocolos');
        }

        $status = null;
        if ($expiresAt !== null) {
            $status = $expiresAt < time() ? 'expired' : 'active';
        }

        $protocolRepo->insert($clientId, [
            'document' => $documentDigits,
            'protocol_number' => $protocolNumber,
            'description' => $description !== '' ? $description : null,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);

        $this->flashProtocol('success', 'Protocolo cadastrado com sucesso.');
        return new RedirectResponse(url('crm/clients/' . $clientId) . '#protocolos');
    }

    public function updateProtocol(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        $protocolId = (int)($vars['protocolId'] ?? 0);

        if ($clientId < 1 || $protocolId < 1) {
            return abort(404, 'Registro não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        $protocolRepo = new ClientProtocolRepository();
        $protocol = $protocolRepo->find($protocolId);
        if ($protocol === null || (int)$protocol['client_id'] !== $clientId) {
            return abort(404, 'Protocolo não localizado para este cliente.');
        }

        $protocolNumberRaw = (string)$request->request->get('protocol_number', $protocol['protocol_number']);
        $description = trim((string)$request->request->get('description', (string)($protocol['description'] ?? '')));
        $documentRaw = (string)$request->request->get('document', (string)($protocol['document'] ?? ''));
        $startsAtRaw = (string)$request->request->get('starts_at', $protocol['starts_at'] ? date('Y-m-d', (int)$protocol['starts_at']) : '');
        $expiresAtRaw = (string)$request->request->get('expires_at', $protocol['expires_at'] ? date('Y-m-d', (int)$protocol['expires_at']) : '');

        $old = [
            'protocol_number' => $protocolNumberRaw,
            'description' => $description,
            'document' => $documentRaw,
            'starts_at' => $startsAtRaw,
            'expires_at' => $expiresAtRaw,
        ];

        $errors = [];

        $protocolNumber = mb_strtoupper(trim($protocolNumberRaw), 'UTF-8');
        if ($protocolNumber === '') {
            $errors['protocol_number'] = 'Informe o número do protocolo.';
        }

        $documentDigits = digits_only($documentRaw);
        if ($documentDigits === '' || !in_array(strlen($documentDigits), [11, 14], true)) {
            $errors['document'] = 'Informe um CPF ou CNPJ válido.';
        }

        $clientDocumentDigits = digits_only((string)($client['document'] ?? ''));
        if ($clientDocumentDigits === '') {
            $errors['document'] = 'Cliente sem CPF/CNPJ cadastrado. Atualize os dados antes de editar protocolos.';
        } elseif ($documentDigits !== '' && $documentDigits !== $clientDocumentDigits) {
            $errors['document'] = 'O CPF/CNPJ informado deve ser o mesmo deste cliente.';
        }

        $startsAt = null;
        if ($startsAtRaw !== '') {
            $startsAt = $this->parseLocalDate($startsAtRaw);
            if ($startsAt === null) {
                $errors['starts_at'] = 'Data inicial inválida.';
            }
        }

        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            $expiresAt = $this->parseLocalDate($expiresAtRaw);
            if ($expiresAt === null) {
                $errors['expires_at'] = 'Data de vencimento inválida.';
            }
        }

        if ($startsAt !== null && $expiresAt !== null && $startsAt > $expiresAt) {
            $errors['expires_at'] = 'Vencimento não pode ser anterior à data inicial.';
        }

        if ($protocolNumber !== '' && $protocolRepo->existsProtocol($protocolNumber, $protocolId)) {
            $errors['protocol_number'] = 'Este protocolo já está cadastrado para outro cliente.';
        }

        if ($errors !== []) {
            $this->flashProtocol('error', 'Não foi possível atualizar o protocolo. Corrija os campos destacados.', [
                'errors' => $errors,
                'old' => $old,
                'target' => $protocolId,
            ]);

            return new RedirectResponse(url('crm/clients/' . $clientId) . '#protocolos');
        }

        $status = $protocol['status'] ?? null;
        if ($expiresAt !== null) {
            $status = $expiresAt < time() ? 'expired' : 'active';
        } else {
            $status = null;
        }

        $protocolRepo->update($protocolId, [
            'protocol_number' => $protocolNumber,
            'description' => $description !== '' ? $description : null,
            'document' => $documentDigits,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);

        $this->flashProtocol('success', 'Protocolo atualizado com sucesso.');
        return new RedirectResponse(url('crm/clients/' . $clientId) . '#protocolos');
    }

    public function deleteProtocol(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        $protocolId = (int)($vars['protocolId'] ?? 0);

        if ($clientId < 1 || $protocolId < 1) {
            return abort(404, 'Registro não encontrado.');
        }

        $authUser = $this->currentUser($request);
        $clientRepo = new ClientRepository();
        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($authUser);
        $client = $clientRepo->findWithAccess($clientId, $restrictedAvpNames, $allowOnlineClients);
        if ($client === null) {
            return abort(404, 'Cliente não encontrado.');
        }

        $protocolRepo = new ClientProtocolRepository();
        $protocol = $protocolRepo->find($protocolId);
        if ($protocol === null || (int)$protocol['client_id'] !== $clientId) {
            return abort(404, 'Protocolo não localizado para este cliente.');
        }

        $protocolRepo->delete($protocolId);

        $this->flashProtocol('success', 'Protocolo removido com sucesso.');
        return new RedirectResponse(url('crm/clients/' . $clientId) . '#protocolos');
    }

    public function markClientAction(Request $request, array $vars): Response
    {
        $clientId = (int)($vars['id'] ?? 0);
        if ($clientId < 1) {
            return json_response(['error' => 'Cliente não encontrado.'], 404);
        }

        $authUser = $this->currentUser($request);
        if ($authUser === null) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $markType = (string)$request->request->get('type', '');
        $allowedMarks = $this->clientMarkTypes();
        if (!array_key_exists($markType, $allowedMarks)) {
            return json_response(['error' => 'Tipo de marca inválido.'], 422);
        }

        $clientRepo = new ClientRepository();
        $client = $clientRepo->find($clientId);
        if ($client === null) {
            return json_response(['error' => 'Cliente não encontrado.'], 404);
        }

        if (!$this->userHasAccessToClient($authUser, $clientId)) {
            return json_response(['error' => 'Cliente não está liberado para o seu usuário.'], 403);
        }

        $markRepo = new ClientActionMarkRepository();
        $ttlSeconds = $this->clientMarkTtlSecondsForType($markType);
        $mark = $markRepo->upsert($clientId, $markType, $authUser->id, $ttlSeconds);

        return json_response([
            'marked' => true,
            'type' => $markType,
            'expires_at' => $mark['expires_at'],
            'ttl_hours' => max(1, intdiv($ttlSeconds, 3600)),
        ]);
    }

    private function requiresClientRestriction(?AuthenticatedUser $user): bool
    {
        return $user !== null && !$user->isAdmin() && $user->clientAccessScope === 'custom';
    }

    private function userHasAccessToClient(?AuthenticatedUser $user, int $clientId): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        [$restrictedAvpNames, $allowOnlineClients] = $this->avpRestrictionContext($user);
        if ($restrictedAvpNames === null) {
            return true;
        }

        $clientRepo = new ClientRepository();
        return $clientRepo->canAccessClient($clientId, $restrictedAvpNames, $allowOnlineClients);
    }

    /**
     * @return array{0:?array,1:bool}
     */
    private function avpRestrictionContext(?AuthenticatedUser $user): array
    {
        if (!$this->requiresClientRestriction($user)) {
            return [null, false];
        }

        $userId = $user->id;
        if (!array_key_exists($userId, $this->avpFilterCache)) {
            $this->avpFilterCache[$userId] = $this->avpAccess->allowedAvpNames($userId);
        }

        return [$this->avpFilterCache[$userId], $user->allowOnlineClients];
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * @param array<int, string> $permissions
     */
    private function canAny(?AuthenticatedUser $user, array $permissions): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function flashClient(string $type, string $message): void
    {
        $_SESSION['crm_client_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * @param mixed $input
     * @return array<int, string>
     */
    private function normalizeExtraPhoneInput($input): array
    {
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                $input = $decoded;
            } else {
                $input = [$input];
            }
        }

        if (!is_array($input)) {
            return [];
        }

        $phones = [];
        foreach ($input as $value) {
            $digits = digits_only((string)$value);
            if ($digits !== '') {
                $phones[] = $digits;
            }
        }

        return array_values(array_unique($phones));
    }

    private function parseLocalDateTime(?string $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);

        if ($date === false) {
            return null;
        }

        return $date->getTimestamp();
    }

    private function parseLocalDate(?string $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $timezone);

        if ($date === false) {
            return null;
        }

        $date = $date->setTime(0, 0, 0);
        return $date->getTimestamp();
    }

    private function flash(string $type, string $message, ?array $meta = null): void
    {
        $_SESSION['crm_import_feedback'] = [
            'type' => $type,
            'message' => $message,
            'meta' => $meta,
        ];
    }

    private function flashProtocol(string $type, string $message, array $payload = []): void
    {
        $_SESSION['crm_protocol_feedback'] = array_merge([
            'type' => $type,
            'message' => $message,
        ], $payload);
    }

    /**
     * @return array<string, array{label:string,color:string}>
     */
    private function clientMarkTypes(): array
    {
        return self::CLIENT_MARK_TYPES;
    }

    private function clientMarkTtlHours(): int
    {
        return max(1, intdiv(self::CLIENT_MARK_TTL_SECONDS, 3600));
    }

    private function clientMarkTtlSecondsDefault(): int
    {
        return self::CLIENT_MARK_TTL_SECONDS;
    }

    private function clientMarkTtlSecondsForType(string $type): int
    {
        return self::CLIENT_MARK_TTL_BY_TYPE[$type] ?? self::CLIENT_MARK_TTL_SECONDS;
    }

    private function clientMarkTtlSecondsByType(): array
    {
        return self::CLIENT_MARK_TTL_BY_TYPE;
    }
}
