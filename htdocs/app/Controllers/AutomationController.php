<?php

declare(strict_types=1);

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AutomationController
{
    public function start(Request $request): Response
    {
        $payload = [
            'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone(env('TIMEZONE', 'America/Sao_Paulo'))))->format(DATE_ATOM),
            'status' => 'queued',
            'message' => 'Processo de automação iniciado. Em breve os envios serão processados.'
        ];

        return json_response($payload);
    }
}
