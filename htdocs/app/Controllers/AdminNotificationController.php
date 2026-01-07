<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Support\AdminNotificationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminNotificationController
{
    private AdminNotificationRepository $notifications;

    public function __construct()
    {
        $this->notifications = new AdminNotificationRepository();
    }

    public function listUnread(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return abort(403, 'Acesso restrito ao admin.');
        }

        $limit = (int)$request->query->get('limit', 20);
        $items = $this->notifications->listUnread($limit);

        return json_response([
            'items' => $items,
        ]);
    }

    public function markRead(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return abort(403, 'Acesso restrito ao admin.');
        }

        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== ''));

        if ($ids === []) {
            return json_response(['error' => 'Nenhum ID informado.'], 422);
        }

        $count = $this->notifications->markRead($ids);

        return json_response(['updated' => $count]);
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser && $user->isAdmin();
    }
}
