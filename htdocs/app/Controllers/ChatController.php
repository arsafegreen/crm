<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\UserRepository;
use App\Services\ChatService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChatController
{
    public function __construct(
        private readonly ChatService $chatService = new ChatService(),
        private readonly UserRepository $users = new UserRepository()
    ) {
    }

    public function index(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $this->chatService->enforceRetentionPolicies();

        $isChatWidget = (int)$request->query->get('chat_widget', 0) === 1;
        $threads = $this->chatService->threadsForSidebar($authUser);
        $activeThreadId = (int)$request->query->get('thread', 0);

        if ($activeThreadId <= 0 && $threads !== []) {
            $activeThreadId = (int)$threads[0]['id'];
        }

        $activeThread = null;
        $messages = [];

        if ($activeThreadId > 0) {
            $activeThread = $this->chatService->loadThread($activeThreadId, $authUser);
            if ($activeThread !== null) {
                $messages = $this->chatService->messagesForThread($activeThreadId, $authUser, 80) ?? [];
            }
        }

        if ($activeThread === null && $threads !== []) {
            $activeThreadId = (int)$threads[0]['id'];
            $activeThread = $this->chatService->loadThread($activeThreadId, $authUser);
            $messages = $activeThread !== null
                ? $this->chatService->messagesForThread($activeThreadId, $authUser, 80) ?? []
                : [];
        }

        $userOptions = $this->users->listActiveForChat($authUser->id);
        $activeSessions = $this->users->listActiveSessions();
        $feedback = $_SESSION['chat_feedback'] ?? null;
        unset($_SESSION['chat_feedback']);
        $retentionPolicies = $this->chatService->retentionSettings();

        return view('chat/index', [
            'threads' => $threads,
            'activeThread' => $activeThread,
            'activeThreadId' => $activeThreadId,
            'messages' => $messages,
            'userOptions' => $userOptions,
            'currentUser' => $authUser,
            'chatFeedback' => $feedback,
            'activeSessions' => $activeSessions,
            'chatRoutes' => [
                'threads' => url('chat/threads'),
                'messagesBase' => url('chat/threads'),
                'markReadBase' => url('chat/threads'),
                'createThread' => url('chat/threads'),
                'createGroup' => url('chat/groups'),
                'claimExternalBase' => url('chat/external-thread'),
                'externalStatusBase' => url('chat/external-thread/status'),
                'externalMessagesBase' => url('chat/external-thread'),
                'closeThreadBase' => url('chat/threads'),
            ],
            'isChatWidget' => $isChatWidget,
            'retentionPolicies' => $retentionPolicies,
        ]);
    }

    public function threads(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threads = $this->chatService->threadsForSidebar($user);
        return json_response(['threads' => $threads]);
    }

    public function startThread(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $targetId = (int)$request->request->get('user_id', 0);
        $result = $this->chatService->startDirectThread($user, $targetId);

        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response([
            'thread' => $result['thread'] ?? null,
            'created' => !empty($result['created']),
        ]);
    }

    public function createGroup(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $subject = (string)$request->request->get('subject', '');
        $payload = $request->request->all();
        $participants = $payload['participants'] ?? $request->request->get('participants', []);

        if (!is_array($participants)) {
            $participants = [$participants];
        }

        $result = $this->chatService->createGroupThread($user, $subject, $participants);

        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response([
            'thread' => $result['thread'] ?? null,
            'created' => !empty($result['created']),
        ]);
    }

    public function messages(Request $request, array $vars): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Conversa inválida.'], 400);
        }

        $beforeId = $request->query->get('before');
        $afterId = $request->query->get('after');
        $messages = $this->chatService->messagesForThread(
            $threadId,
            $user,
            80,
            $beforeId !== null ? (int)$beforeId : null,
            $afterId !== null ? (int)$afterId : null
        );

        if ($messages === null) {
            return json_response(['error' => 'Conversa não encontrada.'], 404);
        }

        return json_response(['messages' => $messages]);
    }

    public function sendMessage(Request $request, array $vars): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['error' => 'Conversa inválida.'], 400);
        }

        $body = (string)$request->request->get('body', '');
        $result = $this->chatService->sendMessage($threadId, $body, $user);

        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response(['message' => $result['message'] ?? null]);
    }

    public function closeThread(Request $request, array $vars): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['errors' => ['thread' => 'Conversa inválida.']], 400);
        }

        $result = $this->chatService->closeThread($threadId, $user);
        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response(['closed' => true]);
    }

    public function markRead(Request $request, array $vars): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        $messageId = (int)$request->request->get('message_id', 0);

        if ($threadId <= 0 || $messageId <= 0) {
            return json_response(['updated' => false], 400);
        }

        $updated = $this->chatService->markThreadRead($threadId, $messageId, $user);

        return json_response(['updated' => $updated]);
    }

    public function externalThread(Request $request): Response
    {
        $fullName = trim((string)$request->request->get('full_name', ''));
        $ddd = preg_replace('/\D+/', '', (string)$request->request->get('ddd', '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string)$request->request->get('phone', '')) ?? '';
        $message = trim((string)$request->request->get('message', ''));
        $source = trim((string)$request->request->get('source', 'landing-index'));

        $errors = [];

        if (mb_strlen($fullName, 'UTF-8') < 3) {
            $errors['full_name'] = 'Informe um nome válido.';
        }

        if (strlen($ddd) !== 2) {
            $errors['ddd'] = 'DDD inválido.';
        }

        if ($phone === '' || strlen($phone) < 8) {
            $errors['phone'] = 'Telefone inválido.';
        }

        if ($message === '' || mb_strlen($message, 'UTF-8') < 5) {
            $errors['message'] = 'Descreva brevemente sua necessidade.';
        }

        if ($errors !== []) {
            return json_response(['errors' => $errors], 422);
        }

        $result = $this->chatService->createExternalLead(
            $fullName,
            $ddd,
            $phone,
            $message,
            [
                'source' => mb_substr($source, 0, 60, 'UTF-8') ?: 'landing-index',
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
            ]
        );

        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response([
            'created' => true,
            'lead_id' => $result['lead_id'] ?? null,
            'thread_id' => $result['thread_id'] ?? null,
            'lead_token' => $result['lead_token'] ?? null,
        ]);
    }

    public function claimExternal(Request $request, array $vars): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return json_response(['error' => 'Não autenticado.'], 401);
        }

        $threadId = (int)($vars['id'] ?? 0);
        if ($threadId <= 0) {
            return json_response(['errors' => ['thread' => 'Conversa inválida.']], 400);
        }

        $result = $this->chatService->claimExternalLead($threadId, $user);
        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response(['claimed' => true]);
    }

    public function externalStatus(Request $request, array $vars): Response
    {
        $token = (string)($vars['token'] ?? '');
        $status = $this->chatService->externalLeadStatus($token);
        if ($status === null) {
            return json_response(['error' => 'Lead não encontrado.'], 404);
        }

        return json_response($status);
    }

    public function externalMessages(Request $request, array $vars): Response
    {
        $token = (string)($vars['token'] ?? '');
        $afterId = $request->query->get('after');
        $payload = $this->chatService->externalMessages(
            $token,
            $afterId !== null ? (int)$afterId : null
        );

        if ($payload === null) {
            return json_response(['error' => 'Conversa não encontrada.'], 404);
        }

        return json_response($payload);
    }

    public function sendExternalMessage(Request $request, array $vars): Response
    {
        $token = (string)($vars['token'] ?? '');
        $body = (string)$request->request->get('body', '');

        $result = $this->chatService->sendExternalMessage($token, $body, [
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ]);

        if (isset($result['errors'])) {
            return json_response(['errors' => $result['errors']], 422);
        }

        return json_response(['message' => $result['message'] ?? null]);
    }
}
