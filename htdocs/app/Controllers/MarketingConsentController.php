<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Marketing\ConsentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarketingConsentController
{
    private ConsentService $service;

    public function __construct(?ConsentService $service = null)
    {
        $this->service = $service ?? new ConsentService();
    }

    public function show(Request $request, array $vars): Response
    {
        $token = $this->tokenFromVars($vars);
        if ($token === null) {
            return abort(404, 'Link de preferências inválido.');
        }

        $contact = $this->service->resolveContactByToken($token);
        if ($contact === null) {
            return view('public/preferences_invalid', [
                '_layout' => 'layouts/public_marketing',
            ]);
        }

        if ($request->query->has('confirm')) {
            $this->service->confirm($contact, $this->context($request));
            $this->flash('success', 'Consentimento confirmado com sucesso.');
            return new RedirectResponse(url('preferences/' . $token));
        }

        $feedback = $this->pullFeedback();
        $preferences = $this->service->preferenceValues($contact);

        return view('public/preferences', [
            '_layout' => 'layouts/public_marketing',
            'contact' => $contact,
            'preferences' => $preferences,
            'categories' => $this->service->categories(),
            'token' => $token,
            'feedback' => $feedback,
        ]);
    }

    public function update(Request $request, array $vars): Response
    {
        $token = $this->tokenFromVars($vars);
        if ($token === null) {
            return abort(404, 'Requisição inválida.');
        }

        $contact = $this->service->resolveContactByToken($token);
        if ($contact === null) {
            return view('public/preferences_invalid', [
                '_layout' => 'layouts/public_marketing',
            ]);
        }

        $input = $request->request->all();
        $result = $this->service->updatePreferences($contact, $input, $this->context($request));

        $message = $result['event'] === 'consent_opt_out'
            ? 'Você não receberá mais comunicações. Pode reativar quando quiser.'
            : 'Preferências atualizadas com sucesso.';

        $this->flash('success', $message);

        return new RedirectResponse(url('preferences/' . $token));
    }

    public function downloadLogs(Request $request, array $vars): Response
    {
        $token = $this->tokenFromVars($vars);
        if ($token === null) {
            return abort(404, 'Link inválido.');
        }

        $contact = $this->service->resolveContactByToken($token);
        if ($contact === null) {
            return abort(404, 'Contato não encontrado para este link.');
        }

        $logs = $this->service->consentLogs((int)$contact['id'], 500);
        $payload = [
            'contact' => [
                'id' => (int)$contact['id'],
                'email' => $contact['email'] ?? null,
                'status' => $contact['consent_status'] ?? null,
            ],
            'events' => $logs,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $slugSource = (string)($contact['email'] ?? $contact['id']);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slugSource) ?: 'contato';
        $filename = sprintf('consent-logs-%s.json', trim($slug, '-'));

        return new Response(
            $body ?: '[]',
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function tokenFromVars(array $vars): ?string
    {
        $token = isset($vars['token']) ? trim((string)$vars['token']) : '';
        return $token !== '' ? $token : null;
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['preferences_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function pullFeedback(): ?array
    {
        $feedback = $_SESSION['preferences_feedback'] ?? null;
        unset($_SESSION['preferences_feedback']);
        return $feedback;
    }

    private function context(Request $request): array
    {
        return [
            'ip' => $request->getClientIp(),
            'agent' => $request->headers->get('User-Agent'),
        ];
    }
}
