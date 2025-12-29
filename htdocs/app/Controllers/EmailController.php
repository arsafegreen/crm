<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\TemplateRepository;
use App\Services\Email\InboxService;
use App\Services\Email\MailboxSyncService;
use App\Repositories\Marketing\AudienceListRepository;
use App\Services\AlertService;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EmailController
{
    private static bool $interactionLogPurged = false;

    public function __construct(private readonly InboxService $inbox = new InboxService())
    {
    }

    public function inbox(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $standalone = filter_var($request->query->get('standalone', false), FILTER_VALIDATE_BOOLEAN);
        $layout = $standalone ? 'layouts/plain' : 'layouts/main';

        $accounts = $this->inbox->listAccounts();
        if ($accounts === []) {
            return view('email/inbox', array_merge($this->emptyStatePayload(), ['_layout' => $layout]));
        }

        $accountId = (int)$request->query->get('account_id', (int)$accounts[0]['id']);
        $account = $this->inbox->resolveAccount($accountId);
        $accountId = (int)$account['id'];

        $folderId = $this->normalizeFolderId($request->query->get('folder_id'));
        $filters = [
            'folder_id' => $folderId,
            'search' => trim((string)$request->query->get('q', '')) ?: null,
        ];

        $threads = $this->inbox->listThreads($accountId, $filters, 25);
        $activeThreadId = (int)$request->query->get('thread_id', 0);
        $prefetchMessages = $activeThreadId > 0;

        if ($activeThreadId <= 0 && $threads !== []) {
            $activeThreadId = (int)$threads[0]['id'];
        }

        $threadPayload = null;
        if ($prefetchMessages && $activeThreadId > 0) {
            try {
                $threadPayload = $this->inbox->threadWithMessages($activeThreadId, ['limit' => 60]);
            } catch (RuntimeException $exception) {
                $threadPayload = null;
            }
        }

        $folders = $this->inbox->listFolders($accountId);
        $autoCompose = (string)$request->query->get('compose', '') === 'novo';

        return view('email/inbox', [
            'accounts' => $accounts,
            'activeAccountId' => $accountId,
            'folders' => $folders,
            'threads' => $threads,
            'activeThread' => $threadPayload['thread'] ?? null,
            'messages' => $threadPayload['messages'] ?? [],
            'filters' => $filters,
            'searchQuery' => $filters['search'] ?? '',
            'activeThreadId' => $threadPayload['thread']['id'] ?? $activeThreadId,
            'emailRoutes' => $this->emailRoutes(),
            'autoCompose' => $autoCompose,
            '_layout' => $layout,
        ]);
    }

    public function threads(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $accountId = $request->query->get('account_id');
        try {
            $account = $this->inbox->resolveAccount($accountId !== null ? (int)$accountId : null);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 404);
        }

        $filters = [
            'folder_id' => $this->normalizeFolderId($request->query->get('folder_id')),
            'search' => trim((string)$request->query->get('q', '')) ?: null,
            'unread_only' => (bool)$request->query->get('unread', false),
        ];

        $limit = max(1, min(80, (int)$request->query->get('limit', 30)));
        $threads = $this->inbox->listThreads((int)$account['id'], $filters, $limit);
        $includeFolders = filter_var($request->query->get('include_folders', false), FILTER_VALIDATE_BOOLEAN);

        $payload = [
            'threads' => $threads,
            'account' => [
                'id' => (int)$account['id'],
                'name' => $account['name'] ?? 'Conta de e-mail',
            ],
        ];

        if ($includeFolders) {
            $payload['folders'] = $this->inbox->listFolders((int)$account['id']);
        }

        return json_response($payload);
    }

    public function search(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $accountId = $request->query->get('account_id');
        try {
            $account = $this->inbox->resolveAccount($accountId !== null ? (int)$accountId : null);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 404);
        }

        $filters = [
            'folder_id' => $this->normalizeFolderId($request->query->get('folder_id')),
            'query' => trim((string)$request->query->get('q', '')) ?: null,
            'participant' => trim((string)$request->query->get('participant', '')) ?: null,
            'date_from' => $request->query->get('date_from') ?: null,
            'date_to' => $request->query->get('date_to') ?: null,
            'has_attachments' => filter_var($request->query->get('has_attachments', false), FILTER_VALIDATE_BOOLEAN),
            'unread_only' => filter_var($request->query->get('unread', false), FILTER_VALIDATE_BOOLEAN),
            'mentions_only' => filter_var($request->query->get('mentions', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $limit = max(1, min(120, (int)$request->query->get('limit', 50)));

        if (!empty($filters['mentions_only'])) {
            $filters['mention_email'] = $authUser->email ?? null;
        }

        $threads = $this->inbox->searchThreads((int)$account['id'], $filters, $limit);

        return json_response([
            'threads' => $threads,
            'meta' => [
                'account_id' => (int)$account['id'],
                'total' => count($threads),
            ],
        ]);
    }

    public function threadMessages(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        $options = [
            'limit' => max(1, min(120, (int)$request->query->get('limit', 60))),
        ];

        if ($request->query->get('before') !== null) {
            $options['before_id'] = (int)$request->query->get('before');
        }

        if ($request->query->get('after') !== null) {
            $options['after_id'] = (int)$request->query->get('after');
        }

        try {
            $payload = $this->inbox->threadWithMessages($threadId, $options);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 404);
        }

        return json_response($payload);
    }

    public function markThreadRead(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        $messageIdRaw = $request->request->get('message_id');
        $messageId = $messageIdRaw !== null ? (int)$messageIdRaw : null;

        try {
            $result = $this->inbox->markThreadRead($threadId, $messageId);
            $this->logButtonInteraction($authUser, 'thread_mark_read', [
                'status' => 'success',
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'ip' => $request->getClientIp(),
                'account_id' => $result['thread']['account_id'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_mark_read', [
                'status' => 'error',
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 404);
        }

        return json_response($result);
    }

    public function message(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $messageId = (int)($vars['id'] ?? 0);
        if ($messageId <= 0) {
            return json_response(['error' => 'Mensagem inválida.'], 400);
        }

        try {
            $payload = $this->inbox->messageDetail($messageId);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 404);
        }

        return json_response($payload);
    }

    public function messageView(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $messageId = (int)($vars['id'] ?? 0);
        if ($messageId <= 0) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => null,
                'thread' => null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => 'Mensagem inválida.',
            ]);
        }

        try {
            $payload = $this->inbox->messageDetail($messageId);
        } catch (RuntimeException $exception) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => null,
                'thread' => null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => $exception->getMessage(),
            ]);
        }

        $message = $payload['message'] ?? null;
        if ($message === null) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => null,
                'thread' => null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => 'Mensagem não encontrada.',
            ]);
        }

        $accountId = (int)($message['account_id'] ?? 0);
        if ($accountId <= 0) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => $message,
                'thread' => $payload['thread'] ?? null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => 'Conta associada à mensagem não foi identificada.',
            ]);
        }

        try {
            $account = $this->inbox->resolveAccount($accountId);
        } catch (RuntimeException $exception) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => $message,
                'thread' => $payload['thread'] ?? null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => $exception->getMessage(),
            ]);
        }

        if ((int)$account['id'] !== $accountId) {
            return view('email/message_detail', [
                '_layout' => 'layouts/plain',
                'message' => $message,
                'thread' => $payload['thread'] ?? null,
                'account' => null,
                'emailRoutes' => $this->emailRoutes(),
                'replyPresets' => [],
                'errorMessage' => 'A conta desta mensagem não está ativa.',
            ]);
        }

        $replyPresets = $this->buildMessageReplyPresets($message, $payload['thread'] ?? null, $account);

        return view('email/message_detail', [
            '_layout' => 'layouts/plain',
            'message' => $message,
            'thread' => $payload['thread'] ?? null,
            'account' => $account,
            'emailRoutes' => $this->emailRoutes(),
            'replyPresets' => $replyPresets,
            'errorMessage' => null,
        ]);
    }

    public function downloadAttachment(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new Response('Não autenticado.', 401);
        }

        $attachmentId = (int)($vars['id'] ?? 0);
        if ($attachmentId <= 0) {
            return new Response('Anexo inválido.', 400);
        }

        try {
            $attachment = $this->inbox->attachmentForDownload($attachmentId);
        } catch (RuntimeException $exception) {
            return new Response($exception->getMessage(), 404);
        }

        $response = new BinaryFileResponse($attachment['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment['filename'] ?? ('attachment-' . $attachmentId)
        );
        if (!empty($attachment['mime_type'])) {
            $response->headers->set('Content-Type', $attachment['mime_type']);
        }

        return $response;
    }

    public function syncAccount(Request $request, array $vars = []): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $targetAccount = $vars['id'] ?? $request->request->get('account_id') ?? $request->query->get('account_id');
        $requestedAccountId = $targetAccount !== null ? (int)$targetAccount : null;
        $mode = strtolower((string)($request->request->get('mode', $request->query->get('mode', 'sync'))));
        try {
            $account = $this->inbox->resolveAccount($targetAccount !== null ? (int)$targetAccount : null);
        } catch (RuntimeException $exception) {
            $this->notifySyncFailure($authUser, null, $exception->getMessage(), [
                'requested_account_id' => $requestedAccountId,
            ]);
            return json_response(['error' => $exception->getMessage()], 404);
        }

        $limit = (int)($request->request->get('limit', $request->query->get('limit', 150)));
        $limit = max(1, min(500, $limit));
        $lookbackDays = $request->request->get('lookback_days', $request->query->get('lookback_days', 365));
        $lookbackDays = $lookbackDays !== null ? (int)$lookbackDays : null;

        $foldersRaw = $request->request->get('folders', $request->query->get('folders'));
        $options = ['limit' => $limit];
        if (is_string($foldersRaw) && trim($foldersRaw) !== '') {
            $options['folders'] = array_values(array_filter(array_map(static fn(string $value): string => trim($value), explode(',', $foldersRaw)), static fn(string $value): bool => $value !== ''));
        }

        $service = new MailboxSyncService();

        if ($mode === 'async') {
            $dispatched = $this->dispatchAsyncMailboxSync($account, [
                'limit' => $limit,
                'folders' => $options['folders'] ?? null,
                'lookback_days' => $lookbackDays,
            ]);

            if ($dispatched) {
                AlertService::push('email.sync', 'Sincronização manual agendada', [
                    'account_id' => (int)$account['id'],
                    'account_name' => $account['name'] ?? null,
                    'user_id' => $authUser->id,
                    'mode' => 'async',
                    'folders' => $options['folders'] ?? [],
                ]);

                return json_response([
                    'status' => 'queued',
                    'message' => 'Sincronização em segundo plano iniciada.',
                ]);
            }

            AlertService::push('email.sync', 'Sincronização manual executada no modo síncrono (fallback)', [
                'account_id' => (int)$account['id'],
                'account_name' => $account['name'] ?? null,
                'user_id' => $authUser->id,
                'mode' => 'async_fallback',
                'folders' => $options['folders'] ?? [],
            ]);
        }


        try {
            $stats = $service->syncAccount((int)$account['id'], array_merge($options, [
                'lookback_days' => $lookbackDays,
            ]));
        } catch (RuntimeException $exception) {
            $this->notifySyncFailure($authUser, $account, $exception->getMessage(), [
                'options' => [
                    'limit' => $limit,
                    'folders' => $options['folders'] ?? null,
                    'lookback_days' => $lookbackDays,
                ],
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            $this->notifySyncFailure($authUser, $account, $exception->getMessage(), [
                'options' => [
                    'limit' => $limit,
                    'folders' => $options['folders'] ?? null,
                    'lookback_days' => $lookbackDays,
                ],
                'type' => 'unexpected',
            ]);
            return json_response(['error' => 'Erro inesperado ao sincronizar a conta.'], 500);
        }

        $folders = $this->inbox->listFolders((int)$account['id']);

        return json_response([
            'status' => 'ok',
            'account' => [
                'id' => (int)$account['id'],
                'name' => $account['name'] ?? 'Conta de e-mail',
            ],
            'stats' => $stats,
            'folders' => $folders,
        ]);
    }

    public function threadAction(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        $payload = $this->requestPayload($request);
        $action = trim((string)($payload['action'] ?? ''));
        if ($action === '') {
            return json_response(['error' => 'Informe a ação desejada.'], 422);
        }

        try {
            $result = $this->inbox->threadAction($threadId, $action, $payload);
            $this->logButtonInteraction($authUser, 'thread_action', [
                'status' => 'success',
                'thread_id' => $threadId,
                'action' => $action,
                'ip' => $request->getClientIp(),
                'account_id' => $result['thread']['account_id'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_action', [
                'status' => 'error',
                'thread_id' => $threadId,
                'action' => $action,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function starThread(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        $payload = $this->requestPayload($request);
        $starred = !empty($payload['starred']);

        try {
            $result = $this->inbox->toggleStarThread($threadId, $starred);
            $this->logButtonInteraction($authUser, 'thread_star', [
                'status' => 'success',
                'thread_id' => $threadId,
                'starred' => $starred,
                'ip' => $request->getClientIp(),
                'account_id' => $result['thread']['account_id'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_star', [
                'status' => 'error',
                'thread_id' => $threadId,
                'starred' => $starred,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function archiveThread(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        try {
            $result = $this->inbox->archiveThread($threadId);
            $this->logButtonInteraction($authUser, 'thread_archive', [
                'status' => 'success',
                'thread_id' => $threadId,
                'ip' => $request->getClientIp(),
                'account_id' => $result['thread']['account_id'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_archive', [
                'status' => 'error',
                'thread_id' => $threadId,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function moveThread(Request $request, array $vars): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Thread inválida.'], 400);
        }

        $payload = $this->requestPayload($request);
        $folderId = isset($payload['folder_id']) ? (int)$payload['folder_id'] : 0;
        if ($folderId <= 0) {
            return json_response(['error' => 'Selecione uma pasta válida.'], 422);
        }

        try {
            $result = $this->inbox->moveThread($threadId, $folderId);
            $this->logButtonInteraction($authUser, 'thread_move', [
                'status' => 'success',
                'thread_id' => $threadId,
                'target_folder_id' => $folderId,
                'ip' => $request->getClientIp(),
                'account_id' => $result['thread']['account_id'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_move', [
                'status' => 'error',
                'thread_id' => $threadId,
                'target_folder_id' => $folderId,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function bulkThreadAction(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $payload = $this->requestPayload($request);
        $action = trim((string)($payload['action'] ?? ''));
        if ($action === '') {
            return json_response(['error' => 'Informe a ação desejada.'], 422);
        }

        $threadIds = array_filter(array_map(static fn($value): int => (int)$value, (array)($payload['thread_ids'] ?? [])), static fn(int $id): bool => $id > 0);
        $threadIds = array_values(array_unique($threadIds));
        if ($threadIds === []) {
            return json_response(['error' => 'Selecione pelo menos uma conversa.'], 422);
        }

        $actionPayload = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : [];

        try {
            $result = $this->inbox->bulkThreadAction($threadIds, $action, $actionPayload);
            $this->logButtonInteraction($authUser, 'thread_bulk_action', [
                'status' => 'success',
                'action' => $action,
                'thread_ids' => $threadIds,
                'ip' => $request->getClientIp(),
                'affected' => $result['count'] ?? count($threadIds),
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'thread_bulk_action', [
                'status' => 'error',
                'action' => $action,
                'thread_ids' => $threadIds,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function emptyTrash(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $payload = $this->requestPayload($request);
        $accountSource = $payload['account_id']
            ?? $request->request->get('account_id')
            ?? $request->query->get('account_id');
        $accountId = $accountSource !== null ? (int)$accountSource : null;
        if ($accountId !== null && $accountId <= 0) {
            $accountId = null;
        }

        try {
            $result = $this->inbox->emptyTrash($accountId);
            $this->logButtonInteraction($authUser, 'empty_trash', [
                'status' => 'success',
                'account_id' => $result['account_id'] ?? $accountId,
                'deleted_threads' => $result['deleted_threads'] ?? 0,
                'ip' => $request->getClientIp(),
            ]);
        } catch (RuntimeException $exception) {
            $this->logButtonInteraction($authUser, 'empty_trash', [
                'status' => 'error',
                'account_id' => $accountId,
                'ip' => $request->getClientIp(),
                'error' => $exception->getMessage(),
            ]);
            return json_response(['error' => $exception->getMessage()], 400);
        }

        return json_response($result);
    }

    public function compose(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $input = $request->request->all();
        $files = $this->normalizeUploadedFiles($request->files->get('attachments'));

        try {
            $result = $this->inbox->sendMessage($input, $files);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 422);
        }

        return json_response($result);
    }

    public function saveDraft(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $input = $request->request->all();
        $files = $this->normalizeUploadedFiles($request->files->get('attachments'));

        try {
            $result = $this->inbox->saveDraft($input, $files);
        } catch (RuntimeException $exception) {
            return json_response(['error' => $exception->getMessage()], 422);
        }

        return json_response($result);
    }



    public function drafts(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $accountId = (int)$request->query->get('account_id', 0);
        if ($accountId <= 0) {
            try {
                $account = $this->inbox->resolveAccount(null);
                $accountId = (int)$account['id'];
            } catch (RuntimeException $exception) {
                return json_response(['error' => $exception->getMessage()], 404);
            }
        }

        $limit = max(1, min(30, (int)$request->query->get('limit', 10)));
        $drafts = $this->inbox->listDrafts($accountId, $limit);

        return json_response([
            'account_id' => $accountId,
            'drafts' => $drafts,
        ]);
    }

    public function composeWindow(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $audienceRepo = new AudienceListRepository();
        $audienceLists = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => trim((string)($row['name'] ?? 'Grupo')), 
                'contacts_subscribed' => (int)($row['contacts_subscribed'] ?? $row['contacts_total'] ?? 0),
            ];
        }, $audienceRepo->allWithStats());

        $accounts = $this->inbox->listAccounts();
        if ($accounts === []) {
            return view('email/compose_window', [
                'accounts' => [],
                'activeAccountId' => null,
                'emailRoutes' => $this->emailRoutes(),
                'templates' => [],
                'audienceLists' => $audienceLists,
            ]);
        }

        $requestedAccountId = (int)$request->query->get('account_id', (int)$accounts[0]['id']);
        try {
            $account = $this->inbox->resolveAccount($requestedAccountId);
        } catch (RuntimeException $exception) {
            $account = $accounts[0];
        }

        // Ensure default templates are available (idempotent, skips existing).
        $templateRepo = new TemplateRepository();
        $templateRepo->seedDefaults();
        $templateRows = $templateRepo->all('email');
        $templates = array_values(array_map(static function (array $row): array {
            $status = (string)($row['status'] ?? '');
            $versionStatus = (string)($row['latest_version']['status'] ?? '');
            $isPublished = $versionStatus === 'published' || $status === 'active' || $status === 'published';
            if (!$isPublished) {
                return [];
            }

            return [
                'id' => (string)($row['id'] ?? ''),
                'label' => trim((string)($row['name'] ?? 'Modelo')), 
                'subject' => (string)($row['subject'] ?? ''),
                'html' => (string)($row['body_html'] ?? ''),
                'text' => (string)($row['body_text'] ?? ''),
            ];
        }, $templateRows));

        // Filter out empties from unpublished templates
        $templates = array_values(array_filter($templates, static fn(array $tpl): bool => ($tpl['id'] ?? '') !== ''));

        return view('email/compose_window', [
            '_layout' => 'layouts/plain',
            'accounts' => $accounts,
            'activeAccountId' => (int)$account['id'],
            'emailRoutes' => $this->emailRoutes(),
            'templates' => $templates,
            'audienceLists' => $audienceLists,
        ]);
    }

    public function composeAudienceRecipients(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $listId = (int)$request->query->get('list_id', 0);
        if ($listId <= 0) {
            return json_response(['error' => 'Lista inválida.'], 400);
        }

        $repo = new AudienceListRepository();
        $list = $repo->find($listId);
        if ($list === null) {
            return json_response(['error' => 'Lista não encontrada.'], 404);
        }

        $emails = $repo->subscribedEmails($listId);

        return json_response([
            'list' => [
                'id' => $listId,
                'name' => $list['name'] ?? 'Lista',
            ],
            'emails' => array_values($emails),
            'total' => count($emails),
        ]);
    }

    private function buildMessageReplyPresets(array $message, ?array $thread, array $account): array
    {
        $threadSubject = trim((string)($thread['subject'] ?? $message['subject'] ?? ''));
        $threadId = (int)($message['thread_id'] ?? ($thread['id'] ?? 0));
        $accountId = (int)($account['id'] ?? ($message['account_id'] ?? 0));
        $accountEmail = strtolower((string)($account['from_email'] ?? '')) ?: null;

        $replyRecipients = $this->deriveReplyRecipients($message, $accountEmail, 'reply');
        $replyAllRecipients = $this->deriveReplyRecipients($message, $accountEmail, 'reply_all');

        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        $forwardAttachmentIds = array_values(array_filter(array_map(static function (array $attachment): ?int {
            $id = (int)($attachment['id'] ?? 0);
            return $id > 0 ? $id : null;
        }, $attachments)));
        $forwardAttachmentMeta = array_values(array_map(static function (array $attachment): array {
            return [
                'id' => (int)($attachment['id'] ?? 0),
                'filename' => $attachment['filename'] ?? 'anexo',
                'size_bytes' => (int)($attachment['size_bytes'] ?? 0),
            ];
        }, $attachments));

        $baseSubject = $message['subject'] ?? $threadSubject;

        return [
            'reply' => [
                'mode' => 'reply',
                'thread_id' => $threadId,
                'account_id' => $accountId,
                'message_id' => (int)$message['id'],
                'subject' => $this->formatReplySubject($baseSubject),
                'to' => implode(', ', $replyRecipients['to']),
                'cc' => implode(', ', $replyRecipients['cc']),
                'body_text' => $this->buildQuotedBody($message),
                'inherit_attachment_ids' => [],
            ],
            'reply_all' => [
                'mode' => 'reply_all',
                'thread_id' => $threadId,
                'account_id' => $accountId,
                'message_id' => (int)$message['id'],
                'subject' => $this->formatReplySubject($baseSubject),
                'to' => implode(', ', $replyAllRecipients['to']),
                'cc' => implode(', ', $replyAllRecipients['cc']),
                'body_text' => $this->buildQuotedBody($message),
                'inherit_attachment_ids' => [],
            ],
            'forward' => [
                'mode' => 'forward',
                'thread_id' => $threadId,
                'account_id' => $accountId,
                'message_id' => (int)$message['id'],
                'subject' => $this->formatForwardSubject($baseSubject),
                'to' => '',
                'cc' => '',
                'body_text' => $this->buildForwardBody($message),
                'inherit_attachment_ids' => $forwardAttachmentIds,
                'forward_attachments' => $forwardAttachmentMeta,
            ],
        ];
    }

    private function deriveReplyRecipients(array $message, ?string $accountEmail, string $mode): array
    {
        $participants = is_array($message['participants'] ?? null) ? $message['participants'] : [];
        $partitioned = $this->partitionParticipants($participants);
        $seen = [];
        $exclusions = [];
        if ($accountEmail !== null) {
            $exclusions[] = strtolower($accountEmail);
        }

        $append = static function (array $source, array &$target) use (&$seen, $exclusions): void {
            foreach ($source as $email) {
                $normalized = strtolower($email);
                if ($normalized === '' || isset($seen[$normalized]) || in_array($normalized, $exclusions, true)) {
                    continue;
                }
                $seen[$normalized] = true;
                $target[] = $email;
            }
        };

        $to = [];
        $cc = [];

        if ($mode === 'reply_all') {
            $append($partitioned['from'], $to);
            $append($partitioned['to'], $to);
            $append($partitioned['cc'], $cc);
        } else {
            $desiredRole = ($message['direction'] ?? 'inbound') === 'outbound' ? 'to' : 'from';
            $append($partitioned[$desiredRole] ?? [], $to);
            if ($desiredRole === 'from' && $to === []) {
                $append($partitioned['to'], $to);
            }
        }

        return [
            'to' => $to,
            'cc' => $cc,
        ];
    }

    private function partitionParticipants(array $participants): array
    {
        $buckets = [
            'from' => [],
            'to' => [],
            'cc' => [],
            'bcc' => [],
        ];

        foreach ($participants as $participant) {
            $email = trim((string)($participant['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $role = strtolower((string)($participant['role'] ?? 'to'));
            if (!isset($buckets[$role])) {
                $buckets[$role] = [];
            }
            $buckets[$role][] = $email;
        }

        return $buckets;
    }

    private function buildQuotedBody(array $message): string
    {
        $participants = is_array($message['participants'] ?? null) ? $message['participants'] : [];
        $author = $this->findParticipantByRole($participants, 'from');
        $authorLabel = trim((string)($author['name'] ?? $author['email'] ?? 'remetente')) ?: 'remetente';
        $sentAt = isset($message['sent_at']) ? $this->formatComposerDate((int)$message['sent_at']) : 'data desconhecida';
        $preview = $this->messagePreview($message);

        return "\n\n--- {$authorLabel} em {$sentAt} ---\n{$preview}";
    }

    private function buildForwardBody(array $message): string
    {
        $participants = is_array($message['participants'] ?? null) ? $message['participants'] : [];
        $partitioned = $this->partitionParticipants($participants);
        $from = $this->findParticipantByRole($participants, 'from');
        $fromLabel = trim((string)($from['name'] ?? $from['email'] ?? 'remetente')) ?: 'remetente';
        $sentAt = isset($message['sent_at']) ? $this->formatComposerDate((int)$message['sent_at']) : 'data desconhecida';

        $headerLines = array_filter([
            '---------- Mensagem encaminhada ----------',
            'De: ' . $fromLabel,
            'Enviado: ' . $sentAt,
            $partitioned['to'] !== [] ? 'Para: ' . implode(', ', $partitioned['to']) : null,
            $partitioned['cc'] !== [] ? 'Cc: ' . implode(', ', $partitioned['cc']) : null,
            'Assunto: ' . ($message['subject'] ?? '(sem assunto)'),
            '',
        ]);

        $preview = $this->messagePreview($message);

        return "\n\n" . implode("\n", $headerLines) . $preview;
    }

    private function findParticipantByRole(array $participants, string $role): ?array
    {
        $target = strtolower($role);
        foreach ($participants as $participant) {
            if (strtolower((string)($participant['role'] ?? '')) === $target) {
                return $participant;
            }
        }

        return null;
    }

    private function messagePreview(array $message): string
    {
        foreach (['body_text', 'body_preview', 'snippet'] as $field) {
            if (!empty($message[$field])) {
                return (string)$message[$field];
            }
        }

        return '';
    }

    private function formatReplySubject(?string $subject): string
    {
        $base = trim((string)($subject ?? ''));
        if ($base === '') {
            return 'Re: (sem assunto)';
        }
        if (str_starts_with(strtolower($base), 're:')) {
            return $base;
        }

        return 'Re: ' . $base;
    }

    private function formatForwardSubject(?string $subject): string
    {
        $base = trim((string)($subject ?? ''));
        if ($base === '') {
            return 'Enc: (sem assunto)';
        }
        if (str_starts_with(strtolower($base), 'enc:')) {
            return $base;
        }

        return 'Enc: ' . $base;
    }

    private function formatComposerDate(int $timestamp): string
    {
        return date('d/m/Y H:i', $timestamp);
    }

    private function requestPayload(Request $request): array
    {
        $content = trim((string)$request->getContent());
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    private function normalizeFolderId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function normalizeUploadedFiles(mixed $files): array
    {
        if ($files === null) {
            return [];
        }

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        $normalized = [];
        foreach ($files as $file) {
            $normalized = array_merge($normalized, $this->normalizeUploadedFiles($file));
        }

        return $normalized;
    }

    private function emptyStatePayload(): array
    {
        return [
            'accounts' => [],
            'activeAccountId' => null,
            'folders' => [],
            'threads' => [],
            'activeThread' => null,
            'messages' => [],
            'filters' => ['folder_id' => null, 'search' => null],
            'searchQuery' => '',
            'activeThreadId' => null,
            'emailRoutes' => $this->emailRoutes(),
        ];
    }

    private function emailRoutes(): array
    {
        $inboxBase = url('email/inbox');
        $base = url('email/inbox/threads');
        $messagesBase = url('email/inbox/messages');
        $attachmentsBase = url('email/inbox/attachments');
        $searchBase = url('email/inbox/search');
        $composeBase = url('email/inbox/compose');
        $draftBase = url('email/inbox/compose/draft');
        $draftsBase = url('email/inbox/compose/drafts');
        $audienceRecipientsBase = url('email/inbox/compose/audience-recipients');
        $bulkActionsBase = url('email/inbox/threads/bulk-actions');
        $accountSyncBase = url('email/inbox/accounts');
        $composeWindow = url('email/inbox/compose/window');
        $emptyTrash = url('email/inbox/trash/empty');

        return [
            'threads' => $base,
            'threadMessagesBase' => $base,
            'markReadBase' => $base,
            'threadActionsBase' => $base,
            'threadStarBase' => $base,
            'threadArchiveBase' => $base,
            'threadMoveBase' => $base,
            'threadBulkActions' => $bulkActionsBase,
            'messageDetailBase' => $messagesBase,
            'messageStandaloneBase' => $messagesBase,
            'attachmentDownloadBase' => $attachmentsBase,
            'searchThreads' => $searchBase,
            'composeSend' => $composeBase,
            'composeDraft' => $draftBase,
            'composeDrafts' => $draftsBase,
            'composeAudienceRecipients' => $audienceRecipientsBase,
            'composeWindow' => $composeWindow,
            'accountSyncBase' => $accountSyncBase,
            'emptyTrash' => $emptyTrash,
        ];
    }

    private function logButtonInteraction(AuthenticatedUser $user, string $action, array $context = []): void
    {
        try {
            $directory = storage_path('logs' . DIRECTORY_SEPARATOR . 'inbox-actions');
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return;
            }

            if (!self::$interactionLogPurged) {
                $this->purgeOldInteractionLogs($directory, 30);
                self::$interactionLogPurged = true;
            }

            $entry = [
                'timestamp' => time(),
                'datetime' => date('c'),
                'user_id' => $user->id,
                'user_email' => $user->email ?? null,
                'action' => $action,
                'context' => $context,
            ];

            $filename = $directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
            $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return;
            }

            file_put_contents($filename, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Logging failures should never break the request lifecycle.
        }
    }

    private function purgeOldInteractionLogs(string $directory, int $days): void
    {
        if (!is_dir($directory) || $days <= 0) {
            return;
        }

        $cutoff = time() - ($days * 86400);
        $files = scandir($directory) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }
            if (filemtime($path) !== false && filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $options
     */
    private function dispatchAsyncMailboxSync(array $account, array $options): bool
    {
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = base_path('scripts/email/sync_mailboxes.php');
        if (!is_file($script)) {
            return false;
        }

        $arguments = [
            '--account_id=' . (int)$account['id'],
            '--limit=' . (int)($options['limit'] ?? 100),
        ];

        if (!empty($options['lookback_days'])) {
            $arguments[] = '--lookback_days=' . (int)$options['lookback_days'];
        }

        if (!empty($options['folders']) && is_array($options['folders'])) {
            $encodedFolders = base64_encode(implode(',', $options['folders']));
            $arguments[] = '--folders64=' . $encodedFolders;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $parts = array_merge([$phpBinary, $script], $arguments);
            $quoted = array_map([$this, 'quoteWindowsArgument'], $parts);
            $command = implode(' ', $quoted);
            $shellCommand = 'cmd /c start "" /B ' . $command . ' > NUL 2>&1';
            $handle = @popen($shellCommand, 'r');
            if ($handle === false) {
                return false;
            }
            pclose($handle);
            return true;
        }

        $argumentString = implode(' ', array_map('escapeshellarg', $arguments));
        $command = trim(sprintf(
            '%s %s%s%s',
            escapeshellarg($phpBinary),
            escapeshellarg($script),
            $argumentString !== '' ? ' ' : '',
            $argumentString
        ));

        exec($command . ' > /dev/null 2>&1 &', $output, $exitCode);

        return $exitCode === 0;
    }

    private function quoteWindowsArgument(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        $needsQuotes = strpbrk($value, " \t\"&|<>^") !== false;
        $escaped = str_replace('"', '""', $value);

        return $needsQuotes ? '"' . $escaped . '"' : $escaped;
    }

    private function notifySyncFailure(?AuthenticatedUser $user, ?array $account, string $message, array $extra = []): void
    {
        $meta = array_merge([
            'account_id' => $account['id'] ?? null,
            'account_name' => $account['name'] ?? null,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'context' => 'manual_sync',
        ], $extra);

        AlertService::push('email.sync', $message, $meta);
    }
}
