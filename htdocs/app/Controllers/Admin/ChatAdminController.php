<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\AuthenticatedUser;
use App\Services\ChatService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChatAdminController
{
    public function __construct(private readonly ChatService $chatService = new ChatService())
    {
    }

    public function purge(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $cutoffInput = trim((string)$request->request->get('cutoff_date', ''));
        $cutoffTimestamp = $this->parseDateToTimestamp($cutoffInput);

        if ($cutoffTimestamp === null) {
            $_SESSION['chat_feedback'] = [
                'type' => 'error',
                'message' => 'Informe uma data válida para limpeza.',
            ];

            return new RedirectResponse(url('chat'));
        }

        $result = $this->chatService->purgeMessages($cutoffTimestamp, $user);

        if (isset($result['errors'])) {
            $_SESSION['chat_feedback'] = [
                'type' => 'error',
                'message' => $result['errors']['general'] ?? 'Não foi possível concluir a limpeza.',
            ];

            return new RedirectResponse(url('chat'));
        }

        $deletedCount = (int)($result['deleted'] ?? 0);
        $_SESSION['chat_feedback'] = [
            'type' => 'success',
            'message' => sprintf('Limpeza concluída: %d mensagens removidas.', $deletedCount),
        ];

        return new RedirectResponse(url('chat'));
    }

    public function updatePolicy(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $internalDays = max(0, (int)$request->request->get('internal_days', 0));
        $externalDays = max(0, (int)$request->request->get('external_days', 0));

        $result = $this->chatService->updateRetentionPolicies($user, $internalDays, $externalDays);

        if (isset($result['errors'])) {
            $_SESSION['chat_feedback'] = [
                'type' => 'error',
                'message' => $result['errors']['general'] ?? 'Não foi possível atualizar a política.',
            ];

            return new RedirectResponse(url('chat'));
        }

        $_SESSION['chat_feedback'] = [
            'type' => 'success',
            'message' => 'Políticas de retenção atualizadas.',
        ];

        return new RedirectResponse(url('chat'));
    }

    private function parseDateToTimestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $timezone);
        if ($date === false) {
            return null;
        }

        return $date->setTime(0, 0)->getTimestamp();
    }
}
