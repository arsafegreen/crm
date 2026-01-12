<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\PartnerRepository;
use App\Repositories\WhatsappMessageRepository;
use App\Services\AlertService;
use App\Services\Whatsapp\WhatsappService;
use InvalidArgumentException;
use Dompdf\Dompdf;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use function base_path;
use function hash_equals;
use function json_decode;

final class WhatsappController
{
    private WhatsappService $service;
    private PartnerRepository $partners;
    private WhatsappMessageRepository $messages;

    public function __construct(?WhatsappService $service = null, ?PartnerRepository $partners = null, ?WhatsappMessageRepository $messages = null)
    {
        $this->service = $service ?? new WhatsappService();
        $this->partners = $partners ?? new PartnerRepository();
        $this->messages = $messages ?? new WhatsappMessageRepository();
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);
        if ($redirect = $this->ensureStandalone($request, 'whatsapp')) {
            return $redirect;
        }
        $options = $this->service->globalOptions();
        $allowed = $this->service->allowedUserIds();
        try {
            $this->guardAccess($user, $options, $allowed);
        } catch (RuntimeException $e) {
            return new Response($e->getMessage(), 403);
        }

        $channel = $this->normalizeChannel((string)$request->query->get('channel', ''));
        $standaloneView = $request->query->get('standalone') === '1';

        // Mantém carregamento imediato por padrão para não deixar a tela vazia.
        $deferPanels = $request->query->getBoolean('defer_panels', false);

        $threadId = (int)$request->query->get('thread');
        $threadDetails = $threadId > 0 ? $this->service->threadDetails($threadId) : null;
        $probeAltGateways = $request->query->getBoolean('probe_alt_gateways', false);
        $sharedData = $this->buildSharedData($user, $options, $allowed, $probeAltGateways);

        $arrivalThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'arrival'), $channel));
        $scheduledThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'scheduled'), $channel));
        $partnerThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'partner'), $channel));
        $myThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->threadsAssignedTo($user->id), $channel));
        $groupThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->groupThreadsForUser($user), $channel));
        $reminderThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->reminderThreadsForUser($user), $channel));
        $completedThreads = $deferPanels ? [] : $this->limitThreads($this->filterThreadsByChannel($this->service->completedThreadsForUser($user), $channel));

        if ($threadDetails !== null && !$this->threadMatchesChannel($threadDetails['thread'] ?? [], $channel)) {
            $threadDetails = null;
        }

        $queueSummary = $this->queueSummaryForChannel($sharedData['queueSummary'] ?? [], [
            'arrival' => $arrivalThreads,
            'scheduled' => $scheduledThreads,
            'partner' => $partnerThreads,
            'reminder' => $reminderThreads,
        ]);

        return view('whatsapp/index', array_merge($sharedData, [
            'arrivalThreads' => $arrivalThreads,
            'scheduledThreads' => $scheduledThreads,
            'partnerThreads' => $partnerThreads,
            'myThreads' => $myThreads,
            'groupThreads' => $groupThreads,
            'reminderThreads' => $reminderThreads,
            'completedThreads' => $completedThreads,
            'thread' => $threadDetails,
            'selectedChannel' => $channel,
            'deferPanels' => $deferPanels,
            'queueSummary' => $queueSummary,
            'standalone' => $standaloneView,
        ]));
    }

    public function pollThread(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->query->get('thread_id', $request->request->get('thread_id', 0));
        if ($threadId <= 0) {
            return new JsonResponse(['error' => 'Thread inválida.'], 422);
        }

        $lastIdRaw = $request->query->get('last_message_id', $request->request->get('last_message_id', 0));
        $lastMessageId = is_numeric($lastIdRaw) ? (int)$lastIdRaw : 0;
        $prefetch = (string)$request->query->get('prefetch', $request->request->get('prefetch', '0')) === '1';
        $beforeIdRaw = $request->query->get('before_id', $request->request->get('before_id', 0));
        $beforeId = is_numeric($beforeIdRaw) ? (int)$beforeIdRaw : 0;
        $pageLimitRaw = $request->query->get('limit', $request->request->get('limit', 20));
        $pageLimit = is_numeric($pageLimitRaw) ? (int)$pageLimitRaw : 20;
        if ($pageLimit < 1) {
            $pageLimit = 1;
        }
        if ($pageLimit > 50) {
            $pageLimit = 50;
        }
        $isStandalone = $request->query->get('standalone') === '1';
        $fastMode = $request->query->getBoolean('fast', $isStandalone);

        try {
            if ($beforeId > 0) {
                $result = $this->service->loadOlderMessages($threadId, $beforeId, $pageLimit);
                return new JsonResponse([
                    'thread_id' => $threadId,
                    'messages' => $result['messages'],
                    'before_id_next' => $result['before_id_next'],
                    'has_more' => $result['has_more'],
                ]);
            }

            $result = $this->service->pollThreadMessages($threadId, $lastMessageId, !$prefetch);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'thread_id' => $threadId,
            'messages' => $result['messages'],
            'last_message_id' => $result['last_message_id'],
            'thread_unread' => $result['thread']['unread_count'] ?? 0,
            'contact' => $result['contact'] ?? null,
            'queue_summary' => $fastMode ? null : $this->service->queueSummary(),
        ]);
    }

    public function panelRefresh(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 403);
        }

        $standaloneView = $request->query->get('standalone') === '1';
        $channel = $this->normalizeChannel((string)$request->query->get('channel', ''));

        $compactPanels = $request->query->getBoolean('compact', false);
        $searchTerm = trim(mb_strtolower((string)$request->query->get('search', '')));

        $activeThreadId = (int)$request->query->get('thread', 0);

        $helperPath = base_path('resources/views/whatsapp/partials/thread_helpers.php');
        if (is_file($helperPath)) {
            require_once $helperPath;
        }

        $lines = $this->service->lines();
        $lineById = [];
        $lineByAltSlug = [];
        foreach ($lines as $line) {
            $lineId = (int)($line['id'] ?? 0);
            if ($lineId > 0) {
                $lineById[$lineId] = $line;
            }
            $altSlug = strtolower(trim((string)($line['alt_gateway_instance'] ?? '')));
            if ($altSlug !== '') {
                $lineByAltSlug[$altSlug] = $line;
            }
        }

        $altGatewayLookup = [];
        foreach ($this->service->altGatewayDirectory() as $gateway) {
            $slug = strtolower(trim((string)($gateway['slug'] ?? '')));
            if ($slug !== '') {
                $altGatewayLookup[$slug] = $gateway;
            }
        }

        $agentsById = [];
        foreach ($this->service->availableAgents() as $agent) {
            if (isset($agent['id'])) {
                $agentsById[(int)$agent['id']] = $agent;
            }
        }

        $panelDefinitions = $this->buildPanelDefinitions($user, $searchTerm, $channel);
        $linkBuilder = $this->buildWhatsappLinkGenerator($standaloneView, $channel);

        $renderContext = [
            'active_thread_id' => $activeThreadId,
            'queue_labels' => $this->queueLabels(),
            'agents_by_id' => $agentsById,
            'build_url' => $linkBuilder,
            'line_by_id' => $lineById,
            'line_by_alt_slug' => $lineByAltSlug,
            'alt_gateway_lookup' => $altGatewayLookup,
        ];

        $panelsPayload = [];
        foreach ($panelDefinitions as $panelKey => $panelData) {
            $groupedThreads = function_exists('wa_group_threads_by_contact')
                ? wa_group_threads_by_contact($panelData['threads'])
                : $panelData['threads'];
            $metaEntries = [];
            $items = [];
            $htmlSegments = [];
            foreach ($groupedThreads as $thread) {
                if (function_exists('wa_collect_thread_meta')) {
                    $meta = wa_collect_thread_meta($thread, $panelData['options'], $renderContext);
                    if ($meta !== null) {
                        $metaEntries[] = $meta;
                    }
                }

                if (function_exists('wa_render_thread_card')) {
                    $htmlSegments[] = wa_render_thread_card($thread, $panelData['options'], $renderContext);
                }

                $items[] = [
                    'id' => (int)($thread['id'] ?? 0),
                    'queue' => $thread['queue'] ?? null,
                    'status' => $thread['status'] ?? null,
                    'unread' => (int)($thread['unread_count'] ?? 0),
                    'last_message_at' => isset($thread['last_message_at']) ? (int)$thread['last_message_at'] : null,
                    'updated_at' => isset($thread['updated_at']) ? (int)$thread['updated_at'] : null,
                    'created_at' => isset($thread['created_at']) ? (int)$thread['created_at'] : null,
                    'contact_name' => $thread['contact_name'] ?? null,
                    'contact_phone' => $thread['contact_phone'] ?? null,
                    'line_label' => $thread['line_label'] ?? null,
                    'assigned_user_id' => isset($thread['assigned_user_id']) ? (int)$thread['assigned_user_id'] : null,
                    'last_message_preview' => $thread['last_message_preview'] ?? null,
                    'partner_name' => $thread['partner_name'] ?? null,
                    'responsible_name' => $thread['responsible_name'] ?? null,
                ];
            }

            $unreadTotal = function_exists('wa_count_unread_threads')
                ? wa_count_unread_threads($groupedThreads)
                : 0;

            $panelsPayload[$panelKey] = [
                'label' => $panelData['label'],
                'count' => isset($panelData['count_override']) ? (int)$panelData['count_override'] : count($groupedThreads),
                'unread' => $unreadTotal,
                'html' => implode('', $htmlSegments),
                'empty' => $panelData['empty'],
                'meta' => $metaEntries,
                'items' => $items,
            ];
        }

        $queueSummary = $this->queueSummaryForChannel([], [
            'arrival' => $panelDefinitions['entrada']['threads'] ?? [],
            'scheduled' => $panelDefinitions['agendamento']['threads'] ?? [],
            'partner' => $panelDefinitions['parceiros']['threads'] ?? [],
            'reminder' => $panelDefinitions['lembrete']['threads'] ?? [],
        ]);

        return new JsonResponse([
            'panels' => $panelsPayload,
            'queue_summary' => $queueSummary,
        ]);
    }

    public function config(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new Response('Somente administradores podem acessar as configurações.', 403);
        }
        if ($redirect = $this->ensureStandalone($request, 'whatsapp/config')) {
            return $redirect;
        }

        $options = $this->service->globalOptions();
        $allowed = $this->service->allowedUserIds();
        // Config tela standalone: evita sondar gateways alternativos para acelerar carregamento (JS atualiza depois)
        $sharedData = $this->buildSharedData($user, $options, $allowed, false, true);

        return view('whatsapp/config', $sharedData);
    }

    public function guidePdf(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new Response('Somente administradores podem baixar o guia.', 403);
        }

        $html = $this->buildGuideHtml();
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="guia-whatsapp.pdf"',
        ]);
    }

    private function buildGuideHtml(): string
    {
        $webhookUrl = htmlspecialchars(url('whatsapp/webhook'), ENT_QUOTES, 'UTF-8');
        $generated = htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Guia de configuração do WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; margin:24px; color:#0f172a; background:#f8fafc; }
        h1 { margin:0 0 6px; font-size:22px; }
        p.lead { margin:0 0 14px; color:#334155; }
        .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
        .card { border:1px dashed #cbd5e1; border-radius:12px; padding:12px; background:#fff; box-shadow:0 6px 20px rgba(15,23,42,0.08); }
        .badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:4px 10px; border-radius:999px; border:1px solid #94a3b8; color:#0f172a; background:#e0f2fe; }
        .card strong { display:block; margin:6px 0; font-size:16px; }
        ul { margin:0; padding-left:16px; color:#334155; font-size:14px; }
        li { margin-bottom:6px; }
        a { color:#0b4f9c; text-decoration:underline; }
    </style>
</head>
<body>
    <h1>Guia de configuração do WhatsApp</h1>
    <p class="lead">Passo a passo do zero para habilitar número oficial na API da Meta e registrar no CRM.</p>
    <div class="grid">
        <div class="card">
            <span class="badge">Passo 1 · Conta e BM</span>
            <strong>Subir o ambiente Meta</strong>
            <ul>
                <li>Criar conta Meta e acessar Business Settings.</li>
                <li>Ser Administrador do Business Manager.</li>
                <li>Separar documento para verificação do BM; se possível, verificar.</li>
                <li>Opcional: criar System User para tokens longos.</li>
            </ul>
        </div>
        <div class="card">
            <span class="badge">Passo 2 · Número dedicado</span>
            <strong>Preparar telefone</strong>
            <ul>
                <li>Usar número exclusivo; desconectar do WhatsApp Mobile.</li>
                <li>Garantir SMS/voz disponíveis; evitar IVR.</li>
                <li>Remover 2FA do WhatsApp anterior, se existir.</li>
            </ul>
        </div>
        <div class="card">
            <span class="badge">Passo 3 · App, WABA e credenciais</span>
            <strong>Criar app e validar número</strong>
            <ul>
                <li>Criar app em developers.facebook.com e adicionar o produto WhatsApp.</li>
                <li>Escolher/criar a WABA, registrar o número e validar por SMS/voz.</li>
                <li>Anotar Phone Number ID e Business Account ID.</li>
                <li>Gerar Access Token de longo prazo (whatsapp_business_messaging/management).</li>
            </ul>
        </div>
        <div class="card">
            <span class="badge">Passo 4 · Webhook</span>
            <strong>Registrar callback</strong>
            <ul>
                <li>Callback URL: {$webhookUrl}</li>
                <li>Verify token simples (ex.: token-webhook).</li>
                <li>Assinar: messages, message_template_status_update, messaging_product.</li>
                <li>Salvar e validar (200 OK) com teste do painel.</li>
            </ul>
        </div>
        <div class="card">
            <span class="badge">Passo 5 · Registrar no CRM</span>
            <strong>Meta Cloud nesta tela</strong>
            <ul>
                <li>Modo Nova linha, modelo Meta Cloud.</li>
                <li>Preencher: Rótulo, Display Phone (+55...), Phone Number ID, Business Account ID.</li>
                <li>Colar Access Token longo e Verify Token do webhook.</li>
                <li>Salvar (opcional: marcar como padrão, ajustar limitador).</li>
            </ul>
        </div>
        <div class="card">
            <span class="badge">Passo 6 · Testes e produção</span>
            <strong>Validar e operar</strong>
            <ul>
                <li>Teste inbound: enviar "Olá" e confirmar recepção.</li>
                <li>Teste outbound: responder pelo CRM e checar entrega.</li>
                <li>Iniciados pela empresa: usar template aprovado e respeitar janela de 24h.</li>
                <li>Manter opt-in registrado e monitorar qualidade/limites.</li>
            </ul>
        </div>
    </div>
    <p style="margin-top:14px; color:#334155;">Gerado em {$generated}. Links úteis: <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started">Get Started</a> · <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-webhooks">Webhooks</a> · <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/support#policies">Políticas</a>.</p>
</body>
</html>
HTML;
    }


    public function updateBlockedNumbers(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem alterar a lista bloqueada.'], 403);
        }

        $raw = (string)$request->request->get('blocked_numbers', '');
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $blocked = $this->service->updateBlockedNumbers($parts);

        return new JsonResponse([
            'blocked_numbers' => $blocked,
        ]);
    }

    public function blockContact(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $contactId = (int)$request->request->get('contact_id');
        if ($contactId <= 0) {
            return new JsonResponse(['error' => 'Contato inválido.'], 422);
        }

        $block = (bool)$request->request->get('block', true);

        try {
            $result = $this->service->blockContact($contactId, $block);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse($result);
    }

    public function sendMessage(Request $request): Response
    {
        $user = $this->requireUser($request);
        $expectsJson = $this->expectsJson($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            $payload = ['error' => $e->getMessage()];
            return $expectsJson
                ? new JsonResponse($payload, 403)
                : $this->redirectBack($request, $payload);
        }

        if (!$this->service->canForward($user)) {
            $payload = ['error' => 'Você não tem permissão para alterar o status desta conversa.'];
            return $expectsJson
                ? new JsonResponse($payload, 403)
                : $this->redirectBack($request, $payload);
        }

        if (!$this->service->canForward($user)) {
            $payload = ['error' => 'Você não tem permissão para direcionar conversas.'];
            return $expectsJson
                ? new JsonResponse($payload, 403)
                : $this->redirectBack($request, $payload);
        }

        $threadId = (int)$request->request->get('thread_id');
        $message = (string)$request->request->get('message', '');
        $mediaFile = $request->files->get('media');
        if (!$mediaFile instanceof UploadedFile || !$mediaFile->isValid()) {
            $mediaFile = null;
        }

        $templateSelection = null;
        $templateKind = trim((string)$request->request->get('template_kind', ''));
        $templateKey = trim((string)$request->request->get('template_key', ''));
        if ($templateKind !== '' && $templateKey !== '') {
            $templateSelection = [
                'kind' => $templateKind,
                'key' => $templateKey,
            ];
        }

        try {
            $result = $this->service->sendMessage($threadId, $message, $user, $mediaFile, $templateSelection);
        } catch (RuntimeException $e) {
            AlertService::push('whatsapp.send', $e->getMessage(), [
                'thread_id' => $threadId,
                'user_id' => $user->id,
            ]);
            $payload = ['error' => $e->getMessage()];
            return $expectsJson
                ? new JsonResponse($payload, 422)
                : $this->redirectBack($request, $payload);
        } catch (\Throwable $e) {
            $messageText = $e->getMessage() !== '' ? $e->getMessage() : 'Erro inesperado ao enviar mensagem.';
            AlertService::push('whatsapp.send', $messageText, [
                'thread_id' => $threadId,
                'user_id' => $user->id,
                'exception' => get_class($e),
            ]);
            $payload = ['error' => $messageText];
            return $expectsJson
                ? new JsonResponse($payload, 500)
                : $this->redirectBack($request, $payload);
        }

        $payload = [
            'message_id' => $result['message_id'],
            'status' => $result['status'],
            'meta' => $result['meta'],
            'message' => $result['message'] ?? null,
        ];

        return $expectsJson
            ? new JsonResponse($payload)
            : $this->redirectBack($request, ['sent' => 1, 'thread' => $threadId]);
    }

    public function broadcast(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem enviar comunicados.'], 403);
        }

        $queues = $request->request->get('queues');
        if (!is_array($queues)) {
            $queues = $queues !== null && $queues !== '' ? [$queues] : [];
        }

        $input = [
            'title' => $request->request->get('title'),
            'message' => $request->request->get('message'),
            'queues' => $queues,
            'limit' => $request->request->get('limit'),
            'template_kind' => $request->request->get('template_kind'),
            'template_key' => $request->request->get('template_key'),
        ];

        try {
            $result = $this->service->dispatchBroadcast($input, $user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'stats' => $result['stats'],
            'broadcast' => $result['broadcast'],
            'recent' => $this->service->recentBroadcasts(),
        ]);
    }

    public function media(Request $request, array $vars): Response
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new Response($e->getMessage(), 403);
        }

        $messageId = isset($vars['message']) ? (int)$vars['message'] : 0;
        if ($messageId <= 0) {
            return new Response('Arquivo não encontrado.', 404);
        }

        $message = $this->messages->find($messageId);
        if ($message === null) {
            return new Response('Arquivo não encontrado.', 404);
        }

        $metadata = [];
        if (!empty($message['metadata'])) {
            $decoded = json_decode((string)$message['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $media = $metadata['media'] ?? null;
        if (!is_array($media) || empty($media['path'])) {
            return new Response('Arquivo não disponível.', 404);
        }

        $absolutePath = storage_path($media['path']);
        if (!is_file($absolutePath)) {
            return new Response('Arquivo não disponível.', 404);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $media['mime'] ?? 'application/octet-stream');
        $filename = $media['original_name'] ?? basename($absolutePath);
        $disposition = $request->query->get('download') === '1' ? 'attachment' : 'inline';
        $response->setContentDisposition($disposition, $filename);

        return $response;
    }

    public function startManualThread(Request $request): Response
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        if (!$this->service->canStartManualThread($user)) {
            return new JsonResponse(['error' => 'Você não tem permissão para iniciar novas conversas.'], 403);
        }

        $selectedChannel = $this->normalizeChannel((string)$request->request->get('channel', $request->query->get('channel', '')));
        $gatewayInstance = (string)$request->request->get('gateway_instance', '');
        if ($gatewayInstance === '' && $selectedChannel !== '') {
            $config = config('whatsapp_alt_gateways', []);
            $instances = is_array($config['instances'] ?? null) ? $config['instances'] : [];
            $pick = static function (array $instances, string $prefix): ?string {
                foreach ($instances as $slug => $instance) {
                    if (!is_string($slug)) {
                        continue;
                    }
                    if (str_starts_with($slug, $prefix)) {
                        return $slug;
                    }
                }
                return null;
            };
            if ($selectedChannel === 'alt_wpp') {
                $gatewayInstance = $pick($instances, 'wpp') ?? $gatewayInstance;
            } elseif ($selectedChannel === 'alt_lab') {
                $gatewayInstance = $pick($instances, 'lab') ?? $gatewayInstance;
            }
        }

        $payload = [
            'contact_name' => $request->request->get('contact_name'),
            'contact_phone' => $request->request->get('contact_phone'),
            'message' => $request->request->get('message'),
            'initial_queue' => $request->request->get('initial_queue'),
            'campaign_kind' => $request->request->get('campaign_kind'),
            'campaign_token' => $request->request->get('campaign_token'),
            'gateway_instance' => $gatewayInstance,
        ];

        \App\Services\AlertService::push('whatsapp.manual_click', 'WhatsApp manual disparo solicitado.', [
            'user_id' => $user->id,
            'phone' => $payload['contact_phone'] ?? null,
            'kind' => $payload['campaign_kind'] ?? null,
            'gateway_instance' => $request->request->get('gateway_instance') ?? null,
        ]);

        try {
            $result = $this->service->startManualConversation($payload, $user);
        } catch (RuntimeException $e) {
            \App\Services\AlertService::push('whatsapp.manual_fail', 'WhatsApp manual falhou antes do envio.', [
                'user_id' => $user->id,
                'phone' => $payload['contact_phone'] ?? null,
                'kind' => $payload['campaign_kind'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        \App\Services\AlertService::push('whatsapp.manual_success', 'WhatsApp manual enviado.', [
            'user_id' => $user->id,
            'phone' => $payload['contact_phone'] ?? null,
            'kind' => $payload['campaign_kind'] ?? null,
            'thread_id' => $result['thread_id'] ?? null,
        ]);

        $acceptHeader = strtolower((string)$request->headers->get('accept', ''));
        $shouldRedirect = $request->request->getBoolean('redirect', false)
            || (!$request->isXmlHttpRequest() && !str_contains($acceptHeader, 'application/json'));

        if ($shouldRedirect) {
            $params = ['thread' => $result['thread_id']];
            $channel = $this->normalizeChannel((string)$request->request->get('channel', $request->query->get('channel', '')));
            if ($channel !== '') {
                $params['channel'] = $channel;
            }
            if ((string)$request->request->get('standalone', $request->query->get('standalone', '0')) === '1') {
                $params['standalone'] = '1';
            }
            if ((string)$request->request->get('conversation_only', $request->query->get('conversation_only', '0')) === '1') {
                $params['conversation_only'] = '1';
            }

            return new RedirectResponse(url('whatsapp') . '?' . http_build_query($params));
        }

        return new JsonResponse([
            'ok' => true,
            'thread_id' => $result['thread_id'],
        ]);
    }

    public function storeInternalNote(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        $note = (string)$request->request->get('note');
        $mentions = $request->request->all('mentions');
        if (!is_array($mentions)) {
            $mentions = $mentions !== null ? [$mentions] : [];
        }

        try {
            $message = $this->service->addInternalNote($threadId, $note, $user, $mentions);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['message' => $message]);
    }

    public function updateContact(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $contactId = (int)$request->request->get('contact_id');
        if ($contactId <= 0) {
            return new JsonResponse(['error' => 'Contato inválido.'], 422);
        }

        try {
            $contact = $this->service->updateContactTags($contactId, (string)$request->request->get('tags', ''));
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['contact' => $contact]);
    }

    public function updateContactPhone(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $contactId = (int)$request->request->get('contact_id');
        if ($contactId <= 0) {
            return new JsonResponse(['error' => 'Contato inválido.'], 422);
        }

        $name = (string)$request->request->get('contact_name', '');
        $phone = (string)$request->request->get('contact_phone', '');

        try {
            $contact = $this->service->updateContactPhone($contactId, $name, $phone);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['contact' => $contact]);
    }

    public function registerContact(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        if ($threadId <= 0) {
            return new JsonResponse(['error' => 'Thread inválida.'], 422);
        }

        $input = [
            'contact_id' => (int)$request->request->get('contact_id', 0),
            'name' => (string)$request->request->get('contact_name', ''),
            'phone' => (string)$request->request->get('contact_phone', ''),
            'cpf' => (string)$request->request->get('contact_cpf', ''),
            'birthdate' => (string)$request->request->get('contact_birthdate', ''),
            'email' => (string)$request->request->get('contact_email', ''),
            'address' => (string)$request->request->get('contact_address', ''),
            'client_id' => (int)$request->request->get('client_id', 0),
        ];

        try {
            $result = $this->service->registerContactIdentity($threadId, $input);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse($result);
    }

    public function clientSummary(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $clientId = (int)$request->query->get('id', 0);
        if ($clientId < 1) {
            return new JsonResponse(['error' => 'Cliente inválido.'], 422);
        }

        try {
            $summary = $this->service->clientSummary($clientId);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        $summary['link'] = url('crm/clients/' . $summary['id']);

        return new JsonResponse(['client' => $summary]);
    }

    public function saveIntegration(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem atualizar as chaves.'], 403);
        }

        try {
            $data = $this->service->saveIntegration([
                'copilot_api_key' => $request->request->get('copilot_api_key'),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'integration' => $data,
            'status' => $this->service->statusSummary(),
        ]);
    }

    public function storeCopilotProfile(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem gerenciar perfis IA.'], 403);
        }

        try {
            $profile = $this->service->createCopilotProfile($request->request->all());
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['profile' => $profile]);
    }

    public function updateCopilotProfile(Request $request, array $vars): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem gerenciar perfis IA.'], 403);
        }

        $profileId = (int)($vars['profileId'] ?? 0);
        if ($profileId <= 0) {
            return new JsonResponse(['error' => 'Perfil inválido.'], 404);
        }

        try {
            $profile = $this->service->updateCopilotProfile($profileId, $request->request->all());
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['profile' => $profile]);
    }

    public function deleteCopilotProfile(Request $request, array $vars): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem gerenciar perfis IA.'], 403);
        }

        $profileId = (int)($vars['profileId'] ?? 0);
        if ($profileId <= 0) {
            return new JsonResponse(['error' => 'Perfil inválido.'], 404);
        }

        $this->service->deleteCopilotProfile($profileId);
        return new JsonResponse(['deleted' => true]);
    }

    public function uploadManual(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem atualizar a base de conhecimento.'], 403);
        }

        $file = $request->files->get('manual_file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return new JsonResponse(['error' => 'Envie um arquivo .txt ou .md.'], 422);
        }

        try {
            $manual = $this->service->importKnowledgeManual([
                'title' => $request->request->get('manual_title'),
                'description' => $request->request->get('manual_description'),
            ], $file);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'manual' => $manual,
            'status' => $this->service->statusSummary(),
        ]);
    }

    public function deleteManual(Request $request, array $vars): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem atualizar a base de conhecimento.'], 403);
        }

        $manualId = (int)($vars['manualId'] ?? 0);
        if ($manualId <= 0) {
            return new JsonResponse(['error' => 'Manual inválido.'], 404);
        }

        try {
            $this->service->deleteManual($manualId);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['deleted' => true, 'status' => $this->service->statusSummary()]);
    }

    public function downloadManual(Request $request, array $vars): Response
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new Response('Somente administradores podem baixar o manual.', 403);
        }

        $manualId = (int)($vars['manualId'] ?? 0);
        $manual = $this->service->manual($manualId);
        if ($manual === null) {
            return new Response('Manual não encontrado.', 404);
        }

        $path = (string)($manual['storage_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return new Response('Arquivo indisponível.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            'attachment',
            (string)($manual['filename'] ?? ('manual-' . $manualId . '.txt'))
        );
        $response->headers->set('Content-Type', (string)($manual['mime_type'] ?? 'text/plain'));

        return $response;
    }

    public function updateAccessControl(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem atualizar permissões.'], 403);
        }

        $allowedIds = $request->request->all('allowed_user_ids');
        if (!is_array($allowedIds)) {
            $allowedIds = [$allowedIds];
        }

        $blockAvp = (bool)$request->request->get('block_avp', false);

        $result = $this->service->updateAllowedUsers($allowedIds, $blockAvp);

        return new JsonResponse($result);
    }

    public function updateUserPermissions(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem ajustar níveis.'], 403);
        }

        $rawEntries = $request->request->get('entries', '[]');
        if (is_string($rawEntries)) {
            $decoded = json_decode($rawEntries, true);
            if (!is_array($decoded)) {
                return new JsonResponse(['error' => 'Formato inválido para permissões.'], 422);
            }
        } elseif (is_array($rawEntries)) {
            $decoded = $rawEntries;
        } else {
            $decoded = [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $userId = (int)($entry['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $entries[] = [
                'user_id' => $userId,
                'level' => (int)($entry['level'] ?? 0),
                'inbox_access' => (string)($entry['inbox_access'] ?? ''),
                'view_scope' => (string)($entry['view_scope'] ?? ''),
                'view_scope_users' => $this->normalizePermissionUsers($entry['view_scope_users'] ?? []),
                'display_name' => array_key_exists('display_name', $entry) ? (string)$entry['display_name'] : null,
                'panel_scope' => $entry['panel_scope'] ?? [],
                'can_forward' => !empty($entry['can_forward']),
                'can_start_thread' => !empty($entry['can_start_thread']),
                'can_view_completed' => !empty($entry['can_view_completed']),
                'can_grant_permissions' => !empty($entry['can_grant_permissions']),
            ];
        }

        if ($entries === []) {
            return new JsonResponse(['error' => 'Nenhuma alteração informada.'], 422);
        }

        $result = $this->service->updateUserPermissions($entries);

        return new JsonResponse(['updated' => $result['updated'] ?? 0]);
    }

    public function storeLine(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem cadastrar linhas.'], 403);
        }

        try {
            $input = $request->request->all();
            $this->guardWebGatewayEdit($request, $input, null);
            $line = $this->service->createLine($input);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['line' => $line]);
    }

    public function updateLine(Request $request, array $vars): JsonResponse
    {
        $lineId = (int)($vars['lineId'] ?? 0);
        if ($lineId <= 0) {
            return new JsonResponse(['error' => 'Linha inválida.'], 404);
        }
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem editar linhas.'], 403);
        }

        try {
            $current = $this->service->findLine($lineId);
            if ($current === null) {
                return new JsonResponse(['error' => 'Linha não encontrada.'], 404);
            }
            $input = $request->request->all();
            $this->guardWebGatewayEdit($request, $input, $current);
            $line = $this->service->updateLine($lineId, $input);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['line' => $line]);
    }

    public function deleteLine(Request $request, array $vars): JsonResponse
    {
        $lineId = (int)($vars['lineId'] ?? 0);
        if ($lineId <= 0) {
            return new JsonResponse(['error' => 'Linha inválida.'], 404);
        }
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem remover linhas.'], 403);
        }

        $current = $this->service->findLine($lineId);
        if ($current === null) {
            return new JsonResponse(['error' => 'Linha não encontrada.'], 404);
        }

        $this->guardWebGatewayEdit($request, ['provider' => $current['provider'] ?? null, 'alt_gateway_instance' => $current['alt_gateway_instance'] ?? null], $current);

        $this->service->deleteLine($lineId);

        return new JsonResponse(['deleted' => true]);
    }

    public function assignThread(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        if ($threadId <= 0) {
            return new JsonResponse(['error' => 'Conversa inválida.'], 422);
        }

        $requestedUser = $request->request->get('user_id');
        $assignedUserId = $requestedUser;

        if ($assignedUserId === 'self') {
            $assignedUserId = $user->id;
        }

        if ($assignedUserId !== null && $assignedUserId !== '') {
            $assignedUserId = (int)$assignedUserId;
            if (!$user->isAdmin() && $assignedUserId !== $user->id) {
                $thread = $this->service->findThread($threadId);
                if ($thread === null) {
                    return new JsonResponse(['error' => 'Conversa não encontrada.'], 404);
                }

                $currentOwner = (int)($thread['assigned_user_id'] ?? 0);
                $canRedirect = $currentOwner === 0 || $currentOwner === $user->id;
                if (!$canRedirect) {
                    return new JsonResponse(['error' => 'Esta conversa está com outro atendente. Solicite a liberação antes de transferir.'], 403);
                }
            }
        } else {
            $assignedUserId = null;
        }

        $this->service->assignThread($threadId, $assignedUserId);

        return new JsonResponse(['ok' => true]);
    }

    public function updateThreadStatus(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        $status = (string)$request->request->get('status');

        if ($status === 'waiting' && !$user->isAdmin()) {
            // qualquer agente pode mover para aguardando; mantemos consistente
        }

        try {
            $this->service->updateThreadStatus($threadId, $status);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function updateQueue(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        if ($threadId <= 0) {
            return new JsonResponse(['error' => 'Thread inválida.'], 422);
        }

        $queue = (string)$request->request->get('queue', 'arrival');
        $scheduledRaw = $request->request->get('scheduled_for');
        $scheduledTimestamp = null;
        if (is_string($scheduledRaw) && trim($scheduledRaw) !== '') {
            $scheduledTimestamp = $this->parseDateTimeInput($scheduledRaw);
            if ($scheduledTimestamp === null) {
                return new JsonResponse(['error' => 'Data de agendamento inválida.'], 422);
            }
        }

        $meta = [
            'intake_summary' => $request->request->get('intake_summary'),
        ];

        if ($scheduledTimestamp !== null) {
            $meta['scheduled_for'] = $scheduledTimestamp;
        }

        $partnerId = $request->request->get('partner_id');
        if ($partnerId !== null && $partnerId !== '') {
            $meta['partner_id'] = (int)$partnerId;
        }

        $responsibleId = $request->request->get('responsible_user_id');
        if ($responsibleId !== null && $responsibleId !== '') {
            $meta['responsible_user_id'] = (int)$responsibleId;
        }

        try {
            $thread = $this->service->updateQueue($threadId, $queue, $meta);
        } catch (RuntimeException $e) {
            AlertService::push('whatsapp.queue', $e->getMessage(), [
                'thread_id' => $threadId,
                'queue' => $queue,
                'actor_id' => $user->id,
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'thread' => $thread,
             'thread_card' => $this->service->threadCard((int)$thread['id']),
            'queue_summary' => $this->service->queueSummary(),
        ]);
    }

    public function injectSandboxMessage(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $lineId = (int)$request->request->get('line_id');
        $phone = (string)$request->request->get('contact_phone');
        $name = (string)$request->request->get('contact_name');
        $message = (string)$request->request->get('message');

        try {
            $result = $this->service->simulateIncomingMessage($lineId, $phone, $message, [
                'contact_name' => $name,
            ]);
        } catch (RuntimeException $e) {
            AlertService::push('whatsapp.sandbox', $e->getMessage(), [
                'line_id' => $lineId,
                'phone' => $phone,
                'actor_id' => $user->id,
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        $result['queue_summary'] = $this->service->queueSummary();

        return new JsonResponse($result);
    }

    public function runPreTriage(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        try {
            $this->guardAccess($user);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        $threadId = (int)$request->request->get('thread_id');
        if ($threadId <= 0) {
            return new JsonResponse(['error' => 'Thread inválida.'], 422);
        }

        try {
            $result = $this->service->preTriage($threadId);
        } catch (RuntimeException $e) {
            AlertService::push('whatsapp.pretriage', $e->getMessage(), [
                'thread_id' => $threadId,
                'actor_id' => $user->id,
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        $result['queue_summary'] = $this->service->queueSummary();

        return new JsonResponse($result);
    }

    public function copilotSuggestion(Request $request): JsonResponse
    {
        $context = [
            'contact_name' => $request->request->get('contact_name'),
            'last_message' => $request->request->get('last_message'),
            'tone' => $request->request->get('tone'),
            'goal' => $request->request->get('goal'),
            'thread_id' => (int)$request->request->get('thread_id', 0),
        ];

        $profileIdRaw = $request->request->get('profile_id');
        $profileId = $profileIdRaw !== null && $profileIdRaw !== '' ? (int)$profileIdRaw : null;

        $suggestion = $this->service->generateSuggestion($context, $profileId);

        return new JsonResponse($suggestion);
    }

    public function verifyWebhook(Request $request): Response
    {
        $mode = (string)$request->query->get('hub_mode', $request->query->get('hub.mode'));
        $token = (string)$request->query->get('hub_verify_token', $request->query->get('hub.verify_token'));
        $challenge = (string)$request->query->get('hub_challenge', $request->query->get('hub.challenge'));

        $lines = $this->service->lines();
        $tokens = array_filter(array_map(static fn(array $line): ?string => $line['verify_token'] ?? null, $lines));

        if ($mode === 'subscribe' && $token !== '' && $tokens !== []) {
            foreach ($tokens as $candidate) {
                if ($candidate !== null && hash_equals((string)$candidate, $token)) {
                    return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
                }
            }
        }

        return new Response('Token inválido', 403);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['processed' => 0, 'error' => 'invalid_payload'], 400);
        }

        $processed = $this->service->handleWebhookPayload($payload);
        return new JsonResponse(['processed' => $processed]);
    }

    public function exportBackup(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new Response('Somente administradores podem gerar backups.', 403);
        }

        try {
            $backup = $this->service->generateWhatsappBackup();
        } catch (RuntimeException $exception) {
            return new Response('Não foi possível gerar o backup: ' . $exception->getMessage(), 422);
        }

        $response = new BinaryFileResponse($backup['path']);
        $response->setContentDisposition('attachment', $backup['filename']);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    public function importBackup(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem restaurar backups.'], 403);
        }

        $file = $request->files->get('backup_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Envie um arquivo ZIP válido.'], 422);
        }

        try {
            $result = $this->service->restoreWhatsappBackup($file);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'message' => 'Backup restaurado com sucesso.',
            'stats' => $result,
        ]);
    }

    public function importGatewayBackup(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Somente administradores podem restaurar backups de gateway.'], 403);
        }

        $file = $request->files->get('gateway_backup_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Envie um arquivo de backup gerado pelo gateway (.tar.gz, .tar ou .zip).'], 422);
        }

        try {
            $stats = $this->service->restoreGatewayBackup($file->getPathname());
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'message' => 'Backup do gateway restaurado com sucesso.',
            'stats' => $stats,
        ]);
    }

    public function gatewayBackupSummary(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if (!$user->isAdmin()) {
            return new JsonResponse(['error' => 'Apenas administradores podem visualizar o monitor de backup do gateway.'], 403);
        }

        $summary = $this->service->gatewayBackupSummary();

        return new JsonResponse([
            'summary' => $summary,
            'refreshed_at' => time(),
        ]);
    }

    private function requireUser(Request $request): AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException('Usuário não autenticado.');
        }

        return $user;
    }

    private function guardAccess(AuthenticatedUser $user, ?array $options = null, ?array $allowed = null): void
    {
        $options ??= $this->service->globalOptions();
        $allowed ??= $this->service->allowedUserIds();

        if (($options['block_avp_access'] ?? false) && $user->isAvp && !$user->isAdmin()) {
            throw new RuntimeException('Acesso bloqueado para usuários AVP.');
        }

        if ($user->isAdmin()) {
            return;
        }

        if ($allowed !== [] && !in_array($user->id, $allowed, true)) {
            throw new RuntimeException('Você não tem permissão para usar o WhatsApp.');
        }
    }

    /**
     * @param mixed $value
     * @return array<int>
     */
    private function normalizePermissionUsers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $filtered = array_filter(array_map(static fn($entry): int => (int)$entry, $value), static fn(int $id): bool => $id > 0);

        return array_values(array_unique($filtered));
    }

    private function parseDateTimeInput(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $timestamp = strtotime($normalized);
        if ($timestamp === false) {
            return null;
        }

        return $timestamp;
    }

    /**
     * @param array<int> $allowed
     */
    private function buildSharedData(AuthenticatedUser $user, array $options, array $allowed, bool $probeAltGateways = true, bool $includeWebEditCode = false): array
    {
        $lines = array_map(function (array $line): array {
            $line['rate_limit_preset'] = $this->service->detectRateLimitPreset($line);
            return $line;
        }, $this->service->lines());
        $sandboxLines = array_values(array_filter($lines, static function (array $line): bool {
            return ($line['provider'] ?? 'meta') === 'sandbox';
        }));
        $agentsDirectory = $this->service->availableAgents();
        $currentPermission = $this->service->resolveUserPermission($user);
        $agentPermissions = $user->isAdmin() ? $this->service->permissionsForAgents($agentsDirectory) : [];
        $permissionPresets = $user->isAdmin() ? $this->service->permissionPresets() : [];
        $features = (array)config('app.features', []);

        return [
            'status' => $this->service->statusSummary($probeAltGateways),
            'queueSummary' => $this->service->queueSummary(),
            'lines' => $lines,
            'sandboxLines' => $sandboxLines,
            'options' => $options,
            'allowedUserIds' => $allowed,
            'agentsDirectory' => $agentsDirectory,
            'agentPermissions' => $agentPermissions,
            'permissionPresets' => $permissionPresets,
            'partnersDirectory' => $this->partners->listAll(200),
            'actor' => $user,
            'canManage' => $user->isAdmin(),
            'copilotProfiles' => $this->service->copilotProfiles(),
            'manuals' => $this->service->manuals(),
            'trainingSamples' => $this->service->trainingSamplesSummary(),
            'altGateways' => $this->service->altGatewayDirectory(),
            'currentPermission' => $currentPermission,
            'rateLimitPresets' => $this->service->rateLimitPresets(),
            'mediaTemplates' => $this->service->mediaTemplates(),
            'recentBroadcasts' => $this->service->recentBroadcasts(),
            'features' => $features,
            'webEditCode' => $includeWebEditCode ? $this->resolveWebEditCode() : null,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildPanelDefinitions(AuthenticatedUser $user, string $search = '', ?string $channel = null): array
    {
        $search = trim(mb_strtolower($search));
        $hasSearch = $search !== '';
        $completedCount = $this->service->completedCount();
        $completedThreads = $hasSearch ? $this->service->searchCompletedForUser($user, $search, 80) : [];

        $definitions = [
            'entrada' => [
                'label' => 'Entrada',
                'description' => 'Novas conversas aguardando triagem.',
                'empty' => 'Nenhum cliente aguardando.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'arrival'), $channel)),
                'options' => ['panel' => 'entrada', 'allow_claim' => true, 'show_line' => true, 'show_agent' => true],
            ],
            'atendimento' => [
                'label' => 'Atendimento',
                'description' => 'Conversas em andamento com você.',
                'empty' => 'Nenhuma conversa assumida.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->atendimentoThreadsForUser($user), $channel)),
                'options' => ['panel' => 'atendimento', 'show_line' => true, 'show_agent' => true],
            ],
            'grupos' => [
                'label' => 'Grupos',
                'description' => 'Conversas em grupos via WhatsApp Web.',
                'empty' => 'Nenhum grupo com atividade.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->groupThreadsForUser($user, 20), $channel)),
                'options' => ['panel' => 'grupos', 'show_preview' => true, 'show_line' => true, 'show_agent' => true],
            ],
            'parceiros' => [
                'label' => 'Parceiros',
                'description' => 'Leads encaminhados por parceiros.',
                'empty' => 'Nenhum parceiro aguardando.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'partner'), $channel)),
                'options' => ['panel' => 'parceiros', 'show_line' => true, 'show_agent' => true],
            ],
            'lembrete' => [
                'label' => 'Lembrete',
                'description' => 'Conversas aguardando follow-up.',
                'empty' => 'Sem lembretes programados.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->reminderThreadsForUser($user), $channel)),
                'options' => ['panel' => 'lembrete', 'show_line' => true, 'show_agent' => true],
            ],
            'agendamento' => [
                'label' => 'Agendamento',
                'description' => 'Clientes com horário marcado.',
                'empty' => 'Nenhum agendamento pendente.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($this->service->queueThreadsForUser($user, 'scheduled'), $channel)),
                'options' => ['panel' => 'agendamento', 'show_line' => true, 'show_agent' => true],
            ],
            'concluidos' => [
                'label' => 'Concluidos',
                'description' => 'Histórico finalizado (pode reabrir).',
                'empty' => 'Nenhuma conversa encerrada.',
                'threads' => $this->limitThreads($this->filterThreadsByChannel($completedThreads, $channel), 80),
                'count_override' => $completedCount,
                'options' => ['panel' => 'concluidos', 'show_preview' => true, 'allow_reopen' => true, 'show_queue' => true, 'show_line' => true, 'show_agent' => true],
            ],
        ];

        if (isset($definitions['concluidos'])) {
            $definitions['concluidos']['count_override'] = count($definitions['concluidos']['threads']);
        }

        if ($hasSearch) {
            foreach ($definitions as $key => &$def) {
                if ($key === 'concluidos') {
                    $def['count_override'] = count($def['threads']);
                    continue;
                }
                $filtered = $this->filterThreadsBySearch($def['threads'], $search);
                $def['threads'] = $filtered;
                $def['count_override'] = count($filtered);
            }
            unset($def);
        }

        if (!$user->isAdmin()) {
            unset($definitions['grupos']);
        }

        return $definitions;
    }

    private function normalizeChannel(string $channel): ?string
    {
        $normalized = strtolower(trim($channel));
        $allowed = ['meta', 'alt', 'alt_lab', 'alt_wpp'];
        if ($normalized === '' || !in_array($normalized, $allowed, true)) {
            return null;
        }
        return $normalized;
    }

    private function isAltThread(array $thread): bool
    {
        $channelId = (string)($thread['channel_thread_id'] ?? '');
        return str_starts_with($channelId, 'alt:');
    }

    private function parseAltSlugFromThread(array $thread): array
    {
        $channelId = (string)($thread['channel_thread_id'] ?? '');
        if (!str_starts_with($channelId, 'alt:')) {
            return [null, null];
        }

        $payload = substr($channelId, 4);
        $parts = explode(':', $payload, 2);
        $slug = trim((string)($parts[0] ?? ''));
        $digits = isset($parts[1]) ? preg_replace('/\D+/', '', (string)$parts[1]) : null;

        return [$slug !== '' ? strtolower($slug) : null, $digits !== '' ? $digits : null];
    }

    /**
     * Limits the amount of threads while keeping the most recently updated first.
     *
     * @param array<int,array<string,mixed>> $threads
     * @return array<int,array<string,mixed>>
     */
    private function limitThreads(array $threads, int $limit = 60): array
    {
        if ($limit <= 0 || count($threads) <= $limit) {
            return $threads;
        }

        usort($threads, static function (array $a, array $b): int {
            $aDate = $a['last_message_at'] ?? $a['updated_at'] ?? $a['created_at'] ?? null;
            $bDate = $b['last_message_at'] ?? $b['updated_at'] ?? $b['created_at'] ?? null;

            if ($aDate === $bDate) {
                return 0;
            }

            return ($aDate <=> $bDate) * -1; // desc
        });

        $limited = array_slice($threads, 0, $limit);
        // Se por algum motivo o corte esvaziar a lista, mantém o resultado original para não sumir no UI.
        return $limited === [] ? $threads : $limited;
    }

    private function filterThreadsByChannel(array $threads, ?string $channel): array
    {
        if ($channel === null) {
            return $threads;
        }

        $channel = strtolower($channel);

        if ($channel === 'meta') {
            return array_values(array_filter($threads, fn(array $thread): bool => !$this->isAltThread($thread)));
        }

        $slugFilter = null;
        if ($channel === 'alt_wpp') {
            $slugFilter = static fn(?string $slug): bool => $slug !== null && str_starts_with($slug, 'wpp');
        } elseif ($channel === 'alt_lab') {
            $slugFilter = static fn(?string $slug): bool => $slug !== null && str_starts_with($slug, 'lab');
        }

        return array_values(array_filter($threads, function (array $thread) use ($slugFilter): bool {
            if (!$this->isAltThread($thread)) {
                return false;
            }

            if ($slugFilter === null) {
                return true;
            }

            [$slug] = $this->parseAltSlugFromThread($thread);
            return $slugFilter($slug);
        }));
    }

    private function queueSummaryForChannel(array $base, array $lists): array
    {
        $summary = array_merge([
            'arrival' => 0,
            'scheduled' => 0,
            'partner' => 0,
            'reminder' => 0,
        ], $base);

        foreach (['arrival', 'scheduled', 'partner', 'reminder'] as $key) {
            if (array_key_exists($key, $lists)) {
                $summary[$key] = is_array($lists[$key]) ? count($lists[$key]) : (int)$summary[$key];
            }
        }

        return $summary;
    }

    private function threadMatchesChannel(array $thread, ?string $channel): bool
    {
        if ($channel === null) {
            return true;
        }

        $filtered = $this->filterThreadsByChannel([$thread], $channel);
        return $filtered !== [];
    }

    /**
     * @param array<int,array<string,mixed>> $threads
     * @return array<int,array<string,mixed>>
     */
    private function filterThreadsBySearch(array $threads, string $search): array
    {
        if ($search === '') {
            return $threads;
        }

        $digits = preg_replace('/\D+/', '', $search) ?: '';
        $fields = [
            'contact_name',
            'contact_phone',
            'contact_display',
            'contact_display_secondary',
            'last_message_preview',
            'channel_thread_id',
            'partner_name',
            'responsible_name',
            'line_label',
        ];

        return array_values(array_filter($threads, static function (array $thread) use ($search, $digits, $fields): bool {
            foreach ($fields as $field) {
                $value = isset($thread[$field]) ? (string)$thread[$field] : '';
                if ($value !== '' && str_contains(mb_strtolower($value), $search)) {
                    return true;
                }
            }

            if ($digits !== '') {
                $phoneRaw = isset($thread['contact_phone']) ? (string)$thread['contact_phone'] : '';
                $phoneDigits = preg_replace('/\D+/', '', $phoneRaw) ?: '';
                if ($phoneDigits !== '' && str_contains($phoneDigits, $digits)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = strtolower((string)$request->headers->get('accept', ''));
        return str_contains($accept, 'application/json') || str_contains($accept, 'text/json');
    }

    private function redirectBack(Request $request, array $params = []): RedirectResponse
    {
        $target = (string)$request->headers->get('referer', '/whatsapp');
        if ($target === '') {
            $target = '/whatsapp';
        }

        if ($params !== []) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $target .= $separator . http_build_query($params);
        }

        return new RedirectResponse($target);
    }

    private function queueLabels(): array
    {
        return [
            'arrival' => 'Fila de chegada',
            'scheduled' => 'Agendamentos',
            'partner' => 'Parceiros / Indicadores',
            'reminder' => 'Lembretes',
        ];
    }

    /**
     * @return callable(array<string,mixed>):string
     */
    private function buildWhatsappLinkGenerator(bool $standaloneView, ?string $channel): callable
    {
        return static function (array $params = []) use ($standaloneView, $channel): string {
            $query = $params;
            if ($standaloneView) {
                $query['standalone'] = '1';
            }
            if ($channel !== null && $channel !== '') {
                $query['channel'] = $channel;
            }
            $queryString = http_build_query($query);
            return url('whatsapp') . ($queryString !== '' ? '?' . $queryString : '');
        };
    }

    private function guardWebGatewayEdit(Request $request, ?array $payload, ?array $current): void
    {
        if (!$this->shouldRequireWebGatewayCode($payload, $current)) {
            return;
        }

        $expectedCode = $this->resolveWebEditCode();

        $providedCode = '';
        if (is_array($payload) && array_key_exists('web_edit_code', $payload)) {
            $providedCode = (string)$payload['web_edit_code'];
        } else {
            $providedCode = (string)$request->request->get('web_edit_code', '');
        }
        $providedCode = trim($providedCode);

        if ($providedCode === '') {
            throw new RuntimeException('Informe o código de autorização para alterar linhas do WhatsApp Web.');
        }

        if (!hash_equals($expectedCode, $providedCode)) {
            throw new RuntimeException('Código de autorização inválido para ajustar linhas do WhatsApp Web.');
        }
    }

    /**
     * Resolve o código de edição de linhas Web. Se não houver no .env, gera e persiste de forma segura.
     */
    private function resolveWebEditCode(): string
    {
        $expectedCode = trim((string)env('WHATSAPP_WEB_EDIT_CODE', ''));
        $cachePath = base_path('storage/whatsapp_web_edit_code.txt');

        if ($expectedCode === '') {
            if (is_file($cachePath)) {
                $cached = trim((string)file_get_contents($cachePath));
                if ($cached !== '') {
                    $expectedCode = $cached;
                }
            }

            if ($expectedCode === '') {
                $expectedCode = strtoupper(bin2hex(random_bytes(4))); // 8 chars
                @file_put_contents($cachePath, $expectedCode . "\n", LOCK_EX);
            }
        }

        if ($expectedCode === '') {
            throw new RuntimeException('Não foi possível determinar o código de autorização para linhas WhatsApp Web.');
        }

        return $expectedCode;
    }

    private function shouldRequireWebGatewayCode(?array $payload, ?array $current): bool
    {
        if (!$this->isWebGatewayPayload($payload) && !$this->isWebGatewayPayload($current)) {
            return false;
        }

        return true;
    }

    private function isWebGatewayPayload(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        $altInstance = trim((string)($payload['alt_gateway_instance'] ?? ''));
        if ($altInstance !== '') {
            return true;
        }

        $template = strtolower(trim((string)($payload['api_template'] ?? '')));
        if ($template === 'alt') {
            return true;
        }

        $provider = strtolower(trim((string)($payload['provider'] ?? '')));
        return $provider === 'sandbox';
    }

    private function ensureStandalone(Request $request, string $route): ?RedirectResponse
    {
        if ($request->query->get('standalone') === '1') {
            return null;
        }

        $query = $request->query->all();
        $query['standalone'] = '1';
        $target = url($route);
        if ($query !== []) {
            $target .= '?' . http_build_query($query);
        }

        return new RedirectResponse($target);
    }
}
