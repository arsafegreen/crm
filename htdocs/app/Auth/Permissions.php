<?php

declare(strict_types=1);

namespace App\Auth;

final class Permissions
{
    public const MODULES = [
        'dashboard.overview' => 'Dashboard - Visão geral',
        'automation.control' => 'Automação - Controle de execuções',
        'crm.overview' => 'CRM - Acesso ao painel',
        'crm.dashboard.metrics' => 'CRM - Visão geral de clientes',
        'crm.dashboard.alerts' => 'CRM - Alertas de renovação',
        'crm.dashboard.performance' => 'CRM - Performance de emissões',
        'crm.dashboard.partners' => 'CRM - Parceiro / Contador',
        'crm.import' => 'CRM - Importação de planilhas',
        'crm.clients' => 'CRM - Carteira de clientes',
        'crm.partners' => 'CRM - Parceiros e contadores',
        'crm.off' => 'CRM - Carteira Off',
        'crm.agenda' => 'CRM - Agenda operacional',
        'rfb.base' => 'Base RFB - Prospecção',
        'campaigns.email' => 'Campanhas - Disparos por e-mail',
        'social_accounts.manage' => 'Marketing - Contas de redes sociais',
        'templates.library' => 'Marketing - Biblioteca de templates',
        'whatsapp.access' => 'Conversas - WhatsApp Copilot',
        'marketing.lists' => 'Marketing - Listas e contatos',
        'marketing.segments' => 'Marketing - Segmentos dinâmicos',
        'marketing.email_accounts' => 'Marketing - Contas de envio',
        'finance.overview' => 'Financeiro - Visão geral',
        'finance.calendar' => 'Financeiro - Calendário fiscal',
        'finance.accounts' => 'Financeiro - Contas & lançamentos',
        'finance.accounts.manage' => 'Financeiro - Gestão das contas',
        'finance.cost_centers' => 'Financeiro - Centros de custo',
        'finance.transactions' => 'Financeiro - Lançamentos manuais',
        'config.manage' => 'Configurações do sistema',
        'admin.access' => 'Administração - Liberação de acessos',
    ];

    private const LEGACY_MAP = [
        'dashboard' => ['dashboard.overview'],
        'automation' => ['automation.control'],
        'crm' => [
            'crm.overview',
            'crm.dashboard.metrics',
            'crm.dashboard.alerts',
            'crm.dashboard.performance',
            'crm.dashboard.partners',
            'crm.import',
            'crm.clients',
            'crm.partners',
            'crm.off',
            'crm.agenda',
            'rfb.base',
        ],
        'campaigns' => ['campaigns.email'],
        'social_accounts' => ['social_accounts.manage'],
        'templates' => ['templates.library'],
        'whatsapp' => ['whatsapp.access'],
        'marketing' => ['marketing.lists', 'marketing.segments', 'marketing.email_accounts'],
        'finance' => [
            'finance.overview',
            'finance.calendar',
            'finance.accounts',
            'finance.accounts.manage',
            'finance.cost_centers',
            'finance.transactions',
        ],
        'config' => ['config.manage'],
    ];

    /**
     * @return string[]
     */
    public static function validKeys(): array
    {
        return array_keys(self::MODULES);
    }

    /**
     * @return string[]
     */
    public static function defaultUserKeys(): array
    {
        $profile = (string)config('permissions.defaults.new_user_profile', 'operational');
        $default = self::profilePermissions($profile);

        if ($default !== []) {
            return $default;
        }

        $keys = self::validKeys();
        return array_values(array_filter($keys, static fn(string $key): bool => $key !== 'admin.access'));
    }

    /**
     * @return string[]
     */
    public static function profilePermissions(string $profile): array
    {
        $profiles = (array)config('permissions.profiles', []);
        $definition = $profiles[$profile] ?? null;
        if (!is_array($definition)) {
            return [];
        }

        $raw = (array)($definition['permissions'] ?? []);
        return self::sanitize($raw);
    }

    /**
     * @return array<string, array>
     */
    public static function profiles(): array
    {
        $profiles = (array)config('permissions.profiles', []);
        return $profiles;
    }

    /**
     * @return string[]
     */
    public static function defaultProfilePermissions(): array
    {
        $profile = (string)config('permissions.defaults.new_user_profile', 'operational');
        $permissions = self::profilePermissions($profile);
        if ($permissions !== []) {
            return $permissions;
        }

        return self::defaultUserKeysFallback();
    }

    /**
     * @return string[]
     */
    private static function defaultUserKeysFallback(): array
    {
        $keys = self::validKeys();
        return array_values(array_filter($keys, static fn(string $key): bool => $key !== 'admin.access'));
    }

    /**
     * @param array<int, string> $input
     * @return string[]
     */
    public static function sanitize(array $input): array
    {
        $allowed = self::validKeys();
        $normalized = [];

        foreach ($input as $item) {
            $value = trim((string)$item);
            if ($value === '') {
                continue;
            }

            if (isset(self::LEGACY_MAP[$value])) {
                foreach (self::LEGACY_MAP[$value] as $mapped) {
                    if (in_array($mapped, $allowed, true)) {
                        $normalized[$mapped] = true;
                    }
                }
                continue;
            }

            if (in_array($value, $allowed, true)) {
                $normalized[$value] = true;
            }
        }

        $dashboardDependencies = [
            'crm.dashboard.metrics',
            'crm.dashboard.alerts',
            'crm.dashboard.performance',
            'crm.dashboard.partners',
            'crm.import',
            'crm.clients',
            'crm.partners',
            'crm.off',
        ];

        foreach ($dashboardDependencies as $dependency) {
            if (isset($normalized[$dependency])) {
                $normalized['crm.overview'] = true;
                break;
            }
        }

        if (isset($normalized['marketing.lists']) || isset($normalized['marketing.segments'])) {
            $normalized['marketing.email_accounts'] = true;
        }

        $result = array_keys($normalized);
        sort($result);

        return $result;
    }
}
