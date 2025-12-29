<?php

declare(strict_types=1);

use App\Auth\Permissions;

return [
    'defaults' => [
        'new_user_profile' => 'operational',
    ],
    'profiles' => [
        'operational' => [
            'label' => 'Operacional',
            'description' => 'Acesso completo ao CRM, listas de marketing e calendário.',
            'permissions' => [
                'dashboard.overview',
                'crm.overview',
                'crm.dashboard.metrics',
                'crm.dashboard.alerts',
                'crm.dashboard.performance',
                'crm.dashboard.partners',
                'crm.clients',
                'crm.partners',
                'crm.off',
                'crm.agenda',
                'crm.import',
                'rfb.base',
                'marketing.lists',
                'marketing.segments',
                'marketing.email_accounts',
                'campaigns.email',
                'social_accounts.manage',
                'whatsapp.access',
                'templates.library',
                'finance.overview',
                'finance.calendar',
                'finance.accounts',
                'finance.accounts.manage',
                'finance.cost_centers',
                'finance.transactions',
            ],
        ],
        'marketing' => [
            'label' => 'Marketing & Conteúdo',
            'description' => 'Campanhas, listas e redes sociais.',
            'permissions' => [
                'dashboard.overview',
                'marketing.lists',
                'marketing.segments',
                'marketing.email_accounts',
                'campaigns.email',
                'social_accounts.manage',
                'whatsapp.access',
                'templates.library',
            ],
        ],
        'readonly' => [
            'label' => 'Somente visualização',
            'description' => 'Dashboard e relatórios do CRM sem edição.',
            'permissions' => [
                'dashboard.overview',
                'crm.overview',
                'crm.dashboard.metrics',
                'crm.dashboard.alerts',
                'crm.dashboard.performance',
            ],
        ],
        'admin' => [
            'label' => 'Administrador',
            'description' => 'Todos os módulos e painel de liberação.',
            'permissions' => Permissions::validKeys(),
        ],
    ],
];
