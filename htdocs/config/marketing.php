<?php

declare(strict_types=1);

return [
    'consent_categories' => [
        'pref_campaigns' => [
            'label' => 'Campanhas comerciais',
            'description' => 'Promoções, upsell AVP e novidades sobre certificados digitais.',
            'default' => true,
        ],
        'pref_case_studies' => [
            'label' => 'Estudos de caso e NPS',
            'description' => 'Pesquisas de satisfação, depoimentos e conteúdos consultivos.',
            'default' => true,
        ],
        'pref_local_events' => [
            'label' => 'Eventos e workshops',
            'description' => 'Convites para eventos presenciais, tours in company e webinars regionais.',
            'default' => true,
        ],
        'pref_operational_alerts' => [
            'label' => 'Alertas AVP e operacionais',
            'description' => 'Mensagens críticas sobre segurança, manutenções ou prazos obrigatórios.',
            'default' => true,
        ],
    ],
    'imports' => [
        'max_rows' => 5000,
        'max_file_size_mb' => 5,
    ],
];
