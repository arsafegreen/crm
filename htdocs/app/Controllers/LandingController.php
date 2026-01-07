<?php

declare(strict_types=1);

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LandingController
{
    public function home(Request $request): Response
    {
        $banners = [
            'Anuncie aqui seu negócio e alcance novos clientes.',
            'Impulsione sua marca com visibilidade imediata.',
            'Segmentação inteligente: fale com quem importa.',
            'Plano futuro pronto para crescer junto com você.',
        ];

        $features = [
            ['title' => 'Visão futurista', 'desc' => 'Interface neon, clara e direta, inspirada em cockpit de missão.'],
            ['title' => 'Pronto para anunciar', 'desc' => 'CTA único para publicidade: inscreva-se e publique seu slot.'],
            ['title' => 'Segmentação proativa', 'desc' => 'Roteamos por perfil, CNPJ e interesse, antes mesmo do CRM.'],
            ['title' => 'Segurança e isolamento', 'desc' => 'Área pública isolada: sem expor dados do CRM, só o que é seguro.'],
        ];

        $steps = [
            ['title' => 'Envie sua proposta', 'desc' => 'Conte o que você vende, público-alvo e o alcance desejado.'],
            ['title' => 'Escolha o destaque', 'desc' => 'Slots dinâmicos nos banners corridos e destaques fixos.'],
            ['title' => 'Acompanhe o impacto', 'desc' => 'Resumo de alcance e contatos gerados, com alertas urgentes.'],
        ];

        return view('public/home', [
            'banners' => $banners,
            'features' => $features,
            'steps' => $steps,
            'cta_lead' => url('network'),
        ], 'layouts/public_network');
    }
}
