<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Support\FeedbackReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FeedbackAdminController
{
    private FeedbackReportRepository $reports;

    public function __construct()
    {
        $this->reports = new FeedbackReportRepository();
    }

    public function listOpen(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return abort(403, 'Acesso restrito ao admin.');
        }

        $limit = (int)$request->query->get('limit', 50);
        $offset = (int)$request->query->get('offset', 0);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $items = $this->reports->listOpen($limit, $offset);

        return json_response([
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function updateStatus(Request $request, array $vars): Response
    {
        if (!$this->isAdmin($request)) {
            return abort(403, 'Acesso restrito ao admin.');
        }

        $id = (string)($vars['id'] ?? '');
        $status = (string)$request->request->get('status', '');
        $allowed = ['open', 'under_review', 'closed', 'rejected'];
        if ($status === '' || !in_array($status, $allowed, true)) {
            return json_response(['error' => 'Status inválido.'], 422);
        }

        $updated = $this->reports->updateStatus($id, $status);
        if (!$updated) {
            return json_response(['error' => 'Falha ao atualizar status.'], 500);
        }

        return json_response(['ok' => true, 'status' => $status]);
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser && $user->isAdmin();
    }
}