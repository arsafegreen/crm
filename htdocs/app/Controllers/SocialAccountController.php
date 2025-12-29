<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SocialAccountService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SocialAccountController
{
    private SocialAccountService $service;

    public function __construct()
    {
        $this->service = new SocialAccountService();
    }

    public function index(Request $request): Response
    {
        return new RedirectResponse(url('config') . '#social');
    }

    public function store(Request $request): Response
    {
        $payload = json_decode((string)$request->getContent(), true);

        if (!is_array($payload) || $payload === []) {
            $payload = $request->request->all();
        }

        try {
            $this->service->createAccount($payload);
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
}
