            <span class="automation-label" data-automation-text></span>
<?php

use App\Auth\SessionAuthService;
use App\Repositories\SettingRepository;
use App\Support\ThemePresets;

$settingsRepo = new SettingRepository();
$currentThemeKey = (string)$settingsRepo->get('ui.background_theme', 'safegreen-blue');
$themePreset = ThemePresets::get($currentThemeKey);
$themeTokens = $themePreset['tokens'] ?? [];

$colorScheme = htmlspecialchars($themeTokens['color_scheme'] ?? 'dark', ENT_QUOTES, 'UTF-8');
$bgColor = htmlspecialchars($themeTokens['bg'] ?? '#0f172a', ENT_QUOTES, 'UTF-8');
$panelGradient = htmlspecialchars($themeTokens['panel'] ?? 'linear-gradient(145deg, rgba(17,30,55,0.88), rgba(17,24,39,0.72))', ENT_QUOTES, 'UTF-8');
$textColor = htmlspecialchars($themeTokens['text'] ?? '#f8fafc', ENT_QUOTES, 'UTF-8');
$mutedColor = htmlspecialchars($themeTokens['muted'] ?? '#94a3b8', ENT_QUOTES, 'UTF-8');
$accentColor = htmlspecialchars($themeTokens['accent'] ?? '#38bdf8', ENT_QUOTES, 'UTF-8');
$accentHover = htmlspecialchars($themeTokens['accent_hover'] ?? '#0ea5e9', ENT_QUOTES, 'UTF-8');
$borderColor = htmlspecialchars($themeTokens['border'] ?? 'rgba(148, 163, 184, 0.2)', ENT_QUOTES, 'UTF-8');
$successColor = htmlspecialchars($themeTokens['success'] ?? '#22c55e', ENT_QUOTES, 'UTF-8');
$shadowValue = htmlspecialchars($themeTokens['shadow'] ?? '0 30px 60px -30px rgba(14, 165, 233, 0.35)', ENT_QUOTES, 'UTF-8');
$bodyBackground = htmlspecialchars($themeTokens['body'] ?? 'radial-gradient(circle at 10% 20%, rgba(56, 189, 248, 0.15) 0%, rgba(15, 23, 42, 1) 25%), radial-gradient(circle at 90% 10%, rgba(34, 197, 94, 0.12) 0%, rgba(15, 23, 42, 1) 20%), var(--bg)', ENT_QUOTES, 'UTF-8');
$isChatWidget = isset($_GET['chat_widget']) && $_GET['chat_widget'] === '1';
$isStandaloneView = isset($_GET['standalone']) && $_GET['standalone'] !== '0';
$bodyClass = trim(($isChatWidget ? 'is-chat-widget' : '') . ' ' . ($isStandaloneView ? 'is-standalone' : ''));

$authService = new SessionAuthService();
$currentUser = $authService->currentUser();
$idleLimitSeconds = SessionAuthService::INACTIVITY_LIMIT;

$canPermission = static function ($user, string $permission): bool {
    if (!$user instanceof \App\Auth\AuthenticatedUser) {
        return false;
    }

    return $user->can($permission);
};

$showConfig = $canPermission($currentUser, 'config.manage');
$showAutomation = $canPermission($currentUser, 'automation.control');
$showApproval = $currentUser instanceof \App\Auth\AuthenticatedUser && $currentUser->isAdmin();

$navSections = [
    [
        'label' => 'Resumo',
        'legend' => 'Painel principal e CRM',
        'links' => [
            ['permission' => 'dashboard.overview', 'label' => 'Visão geral', 'href' => url()],
            ['permission' => 'crm.overview', 'label' => 'CRM & Renovações', 'href' => url('crm')],
        ],
    ],
    [
        'label' => 'Financeiro',
        'legend' => 'Fluxo de caixa e obrigações',
        'links' => [
            ['permission' => 'finance.overview', 'label' => 'Home Financeira', 'href' => url('finance')],
            ['permission' => 'finance.calendar', 'label' => 'Calendário Fiscal', 'href' => url('finance/calendar')],
            ['permission' => 'finance.accounts', 'label' => 'Contas & Lançamentos', 'href' => url('finance/accounts')],
            ['permission' => 'finance.accounts', 'label' => 'Gestão de Contas', 'href' => url('finance/accounts/manage')],
            ['permission' => 'finance.accounts', 'label' => 'Centros de Custo', 'href' => url('finance/cost-centers')],
            ['permission' => 'finance.accounts', 'label' => 'Lançamentos Manuais', 'href' => url('finance/transactions')],
        ],
    ],
    [
        'label' => 'Certificado Digital',
        'legend' => 'Gestão da carteira e campanhas',
        'links' => [
            ['permission' => 'crm.clients', 'label' => 'Carteira de Clientes', 'href' => url('crm/clients')],
            ['permission' => 'crm.partners', 'label' => 'Parceiros & Contadores', 'href' => url('crm/partners')],
            ['permission' => 'crm.off', 'label' => 'Carteira Off', 'href' => url('crm/clients/off')],
            ['permission' => 'crm.agenda', 'label' => 'Agenda Operacional', 'href' => url('agenda') . '?standalone=1', 'target' => '_blank'],
            ['permission' => 'campaigns.email', 'label' => 'Campanhas', 'href' => url('campaigns/email')],
        ],
    ],
    [
        'label' => 'Dados Publico',
        'legend' => 'Base RFB e captação',
        'links' => [
            ['permission' => 'rfb.base', 'label' => 'Base RFB', 'href' => url('rfb-base')],
        ],
    ],
    [
        'label' => 'Marketing digital',
        'legend' => 'Canais e materiais',
        'links' => [
            ['permission' => 'marketing.lists', 'label' => 'Listas & contatos', 'href' => url('marketing/lists')],
            ['permission' => 'marketing.segments', 'label' => 'Segmentos dinâmicos', 'href' => url('marketing/segments')],
            ['permission' => 'marketing.email_accounts', 'label' => 'Contas de e-mail', 'href' => url('marketing/email-accounts')],
            ['permission' => 'marketing.email_accounts', 'label' => 'Inbox de e-mail', 'href' => url('email/inbox') . '?standalone=1', 'target' => '_blank'],
            ['permission' => 'marketing.lists', 'label' => 'Automações (WhatsApp/E-mail)', 'href' => url('marketing/automations')],
            ['permission' => 'social_accounts.manage', 'label' => 'Contas sociais', 'href' => url('social-accounts')],
            ['permission' => 'templates.library', 'label' => 'Biblioteca de templates', 'href' => url('templates')],
        ],
    ],
    [
        'label' => 'Chatboot-IA',
        'legend' => 'Atendimento WhatsApp com IA',
        'links' => [
            ['permission' => 'whatsapp.access', 'label' => 'WhatsApp', 'href' => url('whatsapp') . '?standalone=1', 'target' => '_blank'],
            ['permission' => 'whatsapp.access', 'label' => 'Configurações', 'href' => url('whatsapp/config') . '?standalone=1', 'target' => '_blank', 'requires_admin' => true],
        ],
    ],
];

$filteredSections = [];
foreach ($navSections as $section) {
    $links = [];
    foreach ($section['links'] as $link) {
        $permission = $link['permission'] ?? null;
        $requiresAdmin = !empty($link['requires_admin']);
        $hasPermission = $permission === null || $canPermission($currentUser, $permission);
        if ($requiresAdmin && (!$currentUser instanceof \App\Auth\AuthenticatedUser || !$currentUser->isAdmin())) {
            continue;
        }
        if ($hasPermission) {
            $links[] = $link;
        }
    }

    if ($links === []) {
        continue;
    }

    $section['links'] = $links;
    $filteredSections[] = $section;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?= htmlspecialchars(config('app.name'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/finance')): ?>
        <link rel="stylesheet" href="<?= asset('css/finance.css'); ?>">
    <?php endif; ?>
    <style>
        :root {
            color-scheme: <?= $colorScheme; ?>;
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            --bg: <?= $bgColor; ?>;
            --panel: <?= $panelGradient; ?>;
            --text: <?= $textColor; ?>;
            --muted: <?= $mutedColor; ?>;
            --accent: <?= $accentColor; ?>;
            --accent-hover: <?= $accentHover; ?>;
            --border: <?= $borderColor; ?>;
            --success: <?= $successColor; ?>;
            --shadow: <?= $shadowValue; ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: <?= $bodyBackground; ?>;
            min-height: 100vh;
            color: var(--text);
            padding: 56px 24px;
        }
        body.is-standalone {
            padding: 28px 24px;
        }
        body.is-chat-widget {
            padding: 0;
            background: var(--panel);
            min-height: 100vh;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-widget-shell {
            min-height: 100vh;
            height: 100%;
            padding: 12px 18px 18px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            flex: 1;
            min-height: 0;
        }
        .floating-actions {
            position: fixed;
            top: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            align-items: stretch;
            z-index: 60;
        }
        .idle-warning {
            position: fixed;
            bottom: 110px;
            right: 24px;
            max-width: 320px;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 34px 64px -28px rgba(15, 23, 42, 0.85);
            color: var(--text);
            z-index: 80;
        }
        .idle-warning strong {
            display: block;
            margin-bottom: 6px;
            font-size: 1rem;
        }
        .idle-warning p {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .idle-warning-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .idle-warning button {
            border-radius: 999px;
            padding: 10px 16px;
            border: 1px solid rgba(56, 189, 248, 0.45);
            background: rgba(56, 189, 248, 0.18);
            color: #38bdf8;
            font-weight: 600;
            cursor: pointer;
        }
        .idle-warning button[data-idle-logout] {
            border-color: rgba(239, 68, 68, 0.45);
            background: rgba(239, 68, 68, 0.18);
            color: #f87171;
        }
        .user-menu {
            position: fixed;
            top: 24px;
            left: 24px;
            z-index: 70;
        }
        .user-menu-trigger {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid rgba(148, 163, 184, 0.45);
            background: rgba(15, 23, 42, 0.72);
            color: var(--text);
            font-weight: 600;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 10px 24px -18px rgba(15, 23, 42, 0.75);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .user-menu-trigger:hover,
        .user-menu-trigger:focus-visible {
            transform: translateY(-1px);
            outline: none;
            box-shadow: 0 16px 32px -18px rgba(56, 189, 248, 0.55);
            background: rgba(30, 41, 59, 0.82);
        }
        .user-menu[data-open="true"] .user-menu-trigger {
            box-shadow: 0 18px 40px -20px rgba(56, 189, 248, 0.65);
        }
        .user-menu-dropdown {
            position: absolute;
            top: 60px;
            left: 0;
            min-width: 240px;
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 18px;
            box-shadow: 0 28px 80px -48px rgba(15, 23, 42, 0.9);
            padding: 18px;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: none;
        }
        .user-menu[data-open="true"] .user-menu-dropdown {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .user-menu-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 14px;
        }
        .user-menu-header strong {
            font-size: 1rem;
        }
        .user-menu-header span {
            color: var(--muted);
            font-size: 0.85rem;
        }
        .user-menu-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: none;
            background: transparent;
            color: var(--text);
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .user-menu-item:hover,
        .user-menu-item:focus-visible {
            background: rgba(56, 189, 248, 0.18);
            color: var(--accent);
            outline: none;
        }
        .user-menu-item.logout {
            color: #fca5a5;
        }
        .user-menu-item.logout:hover,
        .user-menu-item.logout:focus-visible {
            background: rgba(248, 113, 113, 0.18);
            color: #fecaca;
        }
        .user-menu-divider {
            height: 1px;
            background: rgba(148, 163, 184, 0.18);
            margin: 12px 0;
        }
        .automation-control {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 6px;
            padding: 14px 18px 12px;
            border-radius: 999px;
            border: 1px solid rgba(34, 197, 94, 0.35);
            background: rgba(34, 197, 94, 0.16);
            color: #bbf7d0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            box-shadow: 0 12px 28px -18px rgba(34, 197, 94, 0.65);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border 0.2s ease;
            min-width: 120px;
        }
        .automation-control:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 40px -20px rgba(34, 197, 94, 0.75);
        }
        .automation-control:focus-visible {
            outline: 2px solid rgba(56, 189, 248, 0.65);
            outline-offset: 4px;
        }
        .automation-control.is-active {
            border-color: rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.22);
            box-shadow: 0 18px 42px -24px rgba(239, 68, 68, 0.65);
            color: #fecaca;
        }
        .automation-control.is-active:hover {
            box-shadow: 0 24px 54px -24px rgba(239, 68, 68, 0.75);
        }
        .automation-control .automation-status {
            display: block;
            font-size: 0.65rem;
            letter-spacing: 0.18em;
            opacity: 0.8;
        }
        .automation-control .automation-label {
            display: block;
            font-size: 0.95rem;
            letter-spacing: 0.1em;
        }
        .config-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 999px;
            background: rgba(56, 189, 248, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.32);
            color: var(--accent);
            font-weight: 600;
            text-decoration: none;
            font-size: 0.9rem;
            box-shadow: 0 14px 30px -20px rgba(56, 189, 248, 0.65);
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .config-button:hover {
            background: rgba(14, 165, 233, 0.25);
            transform: translateY(-2px);
        }
        .manual-button {
            background: rgba(249, 115, 22, 0.2);
            border-color: rgba(249, 115, 22, 0.4);
            color: #fdba74;
        }
        .manual-button:hover {
            background: rgba(249, 115, 22, 0.3);
        }
        .approval-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.32);
            color: #bfdbfe;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.9rem;
            box-shadow: 0 14px 30px -20px rgba(59, 130, 246, 0.55);
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .approval-button:hover {
            background: rgba(59, 130, 246, 0.28);
            transform: translateY(-2px);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 40px;
        }
        header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        header p {
            color: var(--muted);
            margin: 6px 0 0;
            font-size: 0.95rem;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 28px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
        }
        .module {
            position: relative;
            background: var(--panel);
            border: 1px solid rgba(148, 163, 184, 0.26);
            border-radius: 20px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
            overflow: hidden;
            margin: 28px 0;
            transition: border 0.3s ease, box-shadow 0.3s ease;
        }
        .module:first-of-type {
            margin-top: 0;
        }
        .module::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            border: 1px solid transparent;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.35), rgba(34, 197, 94, 0.2)) border-box;
            mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .module:hover::before {
            opacity: 0.7;
        }
        .module-toggle {
            width: 100%;
            border: none;
            background: transparent;
            color: inherit;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 24px;
            padding: 26px;
            cursor: pointer;
            text-align: left;
        }
        .module-toggle:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 4px;
        }
        .module-toggle-text {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .module-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .module-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .module-toggle-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .module-tags {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .module-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(56, 189, 248, 0.35);
            background: rgba(15, 23, 42, 0.65);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .module-toggle-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid rgba(56, 189, 248, 0.35);
            background: rgba(56, 189, 248, 0.14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            color: var(--accent);
            transition: transform 0.3s ease, background 0.3s ease;
            position: relative;
        }
        .module-toggle-icon::before,
        .module-toggle-icon::after {
            content: '';
            position: absolute;
            width: 12px;
            height: 2px;
            background: currentColor;
            border-radius: 999px;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .module-toggle-icon::before {
            transform: rotate(90deg);
        }
        [data-module][data-open="true"] .module-toggle-icon::before {
            transform: rotate(0deg);
            opacity: 0;
        }
        .module-body {
            padding: 0 28px 28px;
            transition: max-height 0.5s ease, opacity 0.3s ease, padding 0.3s ease;
        }
        .module.module-ready .module-body {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            padding: 0 28px;
            pointer-events: none;
        }
        .module.module-ready[data-open="true"] .module-body {
            max-height: 2000px;
            opacity: 1;
            padding: 0 28px 28px;
            pointer-events: auto;
        }
        .module.module-ready[data-open="true"] .module-toggle-icon {
            background: rgba(56, 189, 248, 0.25);
            transform: rotate(180deg);
        }
        .module-lead {
            color: var(--muted);
            margin: 0 0 18px;
            font-size: 0.92rem;
        }
        .module-highlight {
            display: flex;
            gap: 16px;
            align-items: center;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 18px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.28);
            box-shadow: 0 20px 40px -30px rgba(56, 189, 248, 0.35);
        }
        .module-highlight strong {
            display: block;
            margin-bottom: 4px;
            color: var(--highlight-color, var(--accent));
        }
        .module-highlight small {
            color: var(--muted);
            font-size: 0.8rem;
        }
        .module-highlight-icon {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.25);
            color: var(--highlight-color, var(--accent));
            font-size: 1.1rem;
        }
        .module-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(15, 23, 42, 0.55);
        }
        .module-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 560px;
            font-size: 0.92rem;
        }
        .module-table thead tr {
            background: rgba(30, 41, 59, 0.65);
        }
        .module-table th,
        .module-table td {
            padding: 14px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            text-align: left;
        }
        .module-table tbody tr:last-child td {
            border-bottom: none;
        }
        .module-table tbody tr.row-latest {
            background: rgba(22, 101, 52, 0.12);
        }
        .module-table-note {
            display: block;
            color: var(--muted);
            font-size: 0.75rem;
            margin-top: 4px;
        }
        .module-table-diff {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }
        .text-positive { color: #22c55e; }
        .text-negative { color: #ef4444; }
        .text-neutral { color: #94a3b8; }
        .module-form {
            display: grid;
            gap: 16px;
            max-width: 520px;
        }
        .module-input {
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(15, 23, 42, 0.6);
            color: var(--text);
        }
        .module-form-actions {
            display: flex;
            justify-content: flex-end;
        }
        .module-sort-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: inherit;
            text-decoration: none;
            font-weight: 600;
        }
        .module-sort-link:hover {
            color: var(--accent);
        }
        .module-sort-indicator {
            font-size: 0.75rem;
            color: var(--accent);
        }
        .module-rank-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        .module-rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(56, 189, 248, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: var(--accent);
            font-size: 0.85rem;
            font-weight: 600;
        }
        .module-rank-latest {
            display: grid;
            gap: 4px;
        }
        .module-rank-latest strong {
            font-size: 1rem;
        }
        .module-rank-latest small {
            color: var(--muted);
            font-size: 0.75rem;
        }
        @media (max-width: 880px) {
            .module-toggle {
                grid-template-columns: 1fr;
                gap: 18px;
            }
            .module-toggle-right {
                justify-content: space-between;
            }
            .module-tags {
                justify-content: flex-start;
            }
        }
        @media (max-width: 680px) {
            .module-table {
                min-width: auto;
            }
            .module-table thead {
                display: none;
            }
            .module-table tr {
                display: grid;
                gap: 12px;
                padding: 16px 12px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            }
            .module-table td {
                border: none;
                padding: 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
            }
            .module-table td::before {
                content: attr(data-label);
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--muted);
            }
            .module-table-note {
                margin-top: 0;
            }
        }
        .top-nav {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }
        .nav-section {
            position: relative;
            min-height: 180px;
            padding: 26px;
            border-radius: 22px;
            background: linear-gradient(160deg, rgba(15, 23, 42, 0.92) 0%, rgba(15, 23, 42, 0.65) 100%);
            border: 1px solid rgba(56, 189, 248, 0.2);
            box-shadow: 0 24px 60px -35px rgba(56, 189, 248, 0.55);
            backdrop-filter: blur(18px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 18px;
            cursor: default;
            transition: border 0.35s ease, transform 0.35s ease, box-shadow 0.35s ease;
        }
        .nav-section::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.35), transparent 55%),
                        radial-gradient(circle at bottom left, rgba(34, 197, 94, 0.25), transparent 60%);
            opacity: 0.4;
            pointer-events: none;
            transition: opacity 0.35s ease;
        }
        .nav-section:hover {
            transform: translateY(-4px);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 28px 70px -30px rgba(56, 189, 248, 0.65);
        }
        .nav-section:hover::after {
            opacity: 0.85;
        }
        .nav-surface {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .nav-label {
            display: block;
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #f8fafc;
        }
        .nav-legend {
            color: rgba(148, 163, 184, 0.9);
            font-size: 0.85rem;
        }
        .nav-hint {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: rgba(148, 163, 184, 0.85);
            border-radius: 999px;
            border: 1px dashed rgba(148, 163, 184, 0.4);
            background: rgba(15, 23, 42, 0.55);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .nav-section:hover .nav-hint,
        .nav-section:focus-within .nav-hint {
            opacity: 0;
            transform: translateY(-6px);
        }
        .nav-links {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 10px;
            opacity: 0;
            max-height: 0;
            transform: translateY(12px);
            pointer-events: none;
            overflow: hidden;
            mask-image: linear-gradient(to bottom, transparent, rgba(0, 0, 0, 0.95) 20px, rgba(0, 0, 0, 0.95) calc(100% - 20px), transparent);
            -webkit-mask-image: linear-gradient(to bottom, transparent, rgba(0, 0, 0, 0.95) 20px, rgba(0, 0, 0, 0.95) calc(100% - 20px), transparent);
            transition: opacity 0.35s ease, transform 0.35s ease, max-height 0.4s ease, mask-image 0.35s ease;
        }
        .nav-section:hover .nav-links,
        .nav-section:focus-within .nav-links {
            opacity: 1;
            transform: translateY(0);
            max-height: min(360px, 60vh);
            pointer-events: auto;
            overflow-y: auto;
            mask-image: none;
            -webkit-mask-image: none;
            scrollbar-width: thin;
            scrollbar-color: rgba(56, 189, 248, 0.6) transparent;
        }
        .nav-section:hover .nav-links::-webkit-scrollbar,
        .nav-section:focus-within .nav-links::-webkit-scrollbar {
            width: 4px;
        }
        .nav-section:hover .nav-links::-webkit-scrollbar-thumb,
        .nav-section:focus-within .nav-links::-webkit-scrollbar-thumb {
            background: rgba(56, 189, 248, 0.6);
            border-radius: 999px;
        }
        .nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 14px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            background: rgba(56, 189, 248, 0.14);
            border: 1px solid rgba(56, 189, 248, 0.28);
            transition: background 0.25s ease, border 0.25s ease, transform 0.25s ease;
        }
        .nav-link::after {
            content: '→';
            font-size: 0.85rem;
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .nav-link:hover {
            background: rgba(14, 165, 233, 0.28);
            border-color: rgba(56, 189, 248, 0.45);
            transform: translateX(4px);
        }
        .nav-link:hover::after {
            opacity: 1;
            transform: translateX(3px);
        }
        .nav-link--disabled {
            color: rgba(148, 163, 184, 0.85);
            background: rgba(15, 23, 42, 0.55);
            border: 1px dashed rgba(148, 163, 184, 0.4);
            cursor: not-allowed;
            pointer-events: none;
        }
        .nav-link--disabled::after {
            display: none;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 18px;
            margin: 0 0 32px;
        }
        .page-header-main {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .page-kicker {
            font-size: 0.7rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, 0.85);
        }
        .page-title {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .page-actions {
            display: inline-flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .page-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(56, 189, 248, 0.25);
            background: rgba(15, 23, 42, 0.6);
            color: var(--accent);
            transition: border 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }
        .page-action:hover {
            border-color: rgba(56, 189, 248, 0.45);
            background: rgba(14, 165, 233, 0.22);
            transform: translateY(-1px);
        }
        .page-action--primary {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.35), rgba(14, 165, 233, 0.5));
            border: 1px solid rgba(56, 189, 248, 0.45);
            color: #0f172a;
        }
        .page-action--primary:hover {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.45), rgba(14, 165, 233, 0.65));
        }
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin: 28px 0;
        }
        .metric {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            background: rgba(15, 23, 42, 0.68);
        }
        .metric span {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .metric strong {
            display: block;
            margin-top: 10px;
            font-size: 1.6rem;
            font-weight: 600;
        }
        button.primary {
            border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: #0f172a;
            font-weight: 600;
            border-radius: 12px;
            padding: 16px 26px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 20px 40px -25px rgba(14, 165, 233, 0.75);
        }
        button.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 32px 60px -25px rgba(14, 165, 233, 0.9);
        }
        button.primary:active {
            transform: translateY(0);
        }
        .status {
            font-size: 0.9rem;
            color: var(--muted);
            margin-top: 18px;
        }
        .status strong {
            color: var(--success);
        }
        footer {
            margin-top: 48px;
            text-align: center;
            color: var(--muted);
            font-size: 0.8rem;
        }
        @media (max-width: 680px) {
            header {
                flex-direction: column;
                align-items: flex-start;
            }
            button.primary {
                width: 100%;
            }
            .floating-actions {
                position: static;
                width: 100%;
                margin: 0 0 24px;
                gap: 12px;
            }
            .config-button,
            .automation-control,
            .approval-button {
                width: 100%;
                justify-content: center;
            }
            .page-header {
                flex-direction: column-reverse;
                align-items: flex-start;
                gap: 16px;
            }
            .page-actions {
                width: 100%;
            }
            .page-action {
                justify-content: center;
                width: 100%;
            }
            .nav-section {
                min-height: auto;
                padding: 22px;
            }
            .nav-hint {
                display: none;
            }
            .nav-links {
                opacity: 1;
                max-height: none;
                transform: none;
                pointer-events: auto;
            }
            .user-menu {
                top: 16px;
                left: 16px;
            }
            .user-menu-dropdown {
                left: 0;
                right: auto;
            }
            .idle-warning {
                left: 16px;
                right: 16px;
                bottom: 104px;
            }
        }
            .chat-floating-launcher {
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 64px;
                height: 64px;
                border-radius: 999px;
                border: 1px solid rgba(56, 189, 248, 0.35);
                background: rgba(15, 23, 42, 0.85);
                color: var(--text);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 24px 60px -30px rgba(15, 23, 42, 0.9);
                z-index: 95;
                transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border 0.2s ease;
            }
            .chat-floating-launcher:hover,
            .chat-floating-launcher:focus-visible {
                transform: translateY(-2px);
                outline: none;
                box-shadow: 0 32px 70px -36px rgba(56, 189, 248, 0.55);
            }
            .chat-floating-launcher[data-open="true"] {
                background: rgba(56, 189, 248, 0.18);
                border-color: rgba(56, 189, 248, 0.55);
                color: var(--accent);
            }
            .chat-floating-launcher[data-alert="external"] {
                background: rgba(185, 28, 28, 0.92);
                border-color: rgba(248, 113, 113, 0.8);
                color: #fee2e2;
                box-shadow: 0 28px 70px -34px rgba(185, 28, 28, 0.7);
            }
            .chat-floating-launcher[data-alert="external"][data-overdue="true"] {
                animation: chat-alert-blink 1s infinite;
            }
            .chat-floating-launcher[data-alert="internal"] {
                background: rgba(4, 120, 87, 0.92);
                border-color: rgba(52, 211, 153, 0.8);
                color: #ecfdf5;
                box-shadow: 0 28px 70px -34px rgba(5, 150, 105, 0.65);
            }
            .chat-floating-launcher svg {
                width: 22px;
                height: 22px;
            }
            @keyframes chat-alert-blink {
                0% { box-shadow: 0 28px 70px -34px rgba(185, 28, 28, 0.7); opacity: 1; }
                50% { box-shadow: 0 30px 80px -30px rgba(248, 113, 113, 0.85); opacity: 0.75; }
                100% { box-shadow: 0 28px 70px -34px rgba(185, 28, 28, 0.7); opacity: 1; }
            }
            .chat-floating-panel {
                position: fixed;
                top: 24px;
                bottom: 24px;
                left: 24px;
                right: 24px;
                width: auto;
                max-width: none;
                background: rgba(15, 23, 42, 0.96);
                border: 1px solid rgba(148, 163, 184, 0.35);
                border-radius: 24px;
                box-shadow: 0 50px 120px -48px rgba(15, 23, 42, 0.95);
                backdrop-filter: blur(18px);
                z-index: 95;
                display: flex;
                flex-direction: column;
            }
            .chat-floating-panel[hidden] {
                display: none;
            }
            .chat-floating-panel header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            }
            .chat-floating-panel header strong {
                font-size: 1rem;
            }
            .chat-floating-close {
                border: none;
                background: transparent;
                color: var(--muted);
                cursor: pointer;
                font-size: 1.2rem;
                line-height: 1;
            }
            .chat-floating-frame {
                flex: 1;
                min-height: 0;
            }
            .chat-floating-frame iframe {
                width: 100%;
                height: 100%;
                border: none;
                background: transparent;
            }
            @media (max-width: 640px) {
                .chat-floating-launcher {
                    width: auto;
                    padding: 0 20px;
                    height: 52px;
                }
                .chat-floating-panel {
                    top: 12px;
                    bottom: 86px;
                    right: 12px;
                    left: 12px;
                    width: auto;
                }
                .chat-floating-frame {
                    min-height: 320px;
                }
            }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isChatWidget): ?>
        <div class="chat-widget-shell">
            <?= $content ?? ''; ?>
        </div>
    <?php else: ?>
        <?php if (!$isStandaloneView && $currentUser instanceof \App\Auth\AuthenticatedUser): ?>
        <?php
        $nameSeed = trim((string)$currentUser->name);
        $initials = '';
        if ($nameSeed !== '') {
            $parts = preg_split('/\s+/', $nameSeed);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed === '') {
                        continue;
                    }
                    $initials .= mb_substr($trimmed, 0, 1);
                    if (mb_strlen($initials) >= 2) {
                        break;
                    }
                }
            }
        }
        if ($initials === '' && $currentUser->email !== '') {
            $initials = mb_substr($currentUser->email, 0, 1);
        }
        $initials = mb_strtoupper(mb_substr($initials !== '' ? $initials : 'U', 0, 2));
        ?>
        <div class="user-menu" data-user-menu data-open="false">
            <button type="button" class="user-menu-trigger" data-user-menu-trigger aria-haspopup="true" aria-expanded="false" title="Perfil do usuário">
                <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <div class="user-menu-dropdown" data-user-menu-dropdown>
                <div class="user-menu-header">
                    <strong><?= htmlspecialchars($currentUser->name, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span><?= htmlspecialchars($currentUser->email, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <a class="user-menu-item" href="<?= url('profile'); ?>">Ver perfil</a>
                    <a class="user-menu-item" href="<?= url('profile'); ?>#details">Editar dados</a>
                    <a class="user-menu-item" href="<?= url('profile'); ?>#security">Alterar senha</a>
                </div>
                <div class="user-menu-divider"></div>
                <form method="post" action="<?= url('auth/logout'); ?>">
                    <?= csrf_field(); ?>
                    <button type="submit" class="user-menu-item logout">Sair</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <div class="idle-warning" data-idle-warning hidden="hidden">
        <strong>Você está inativo</strong>
        <p>Para continuar conectado, clique em continuar. Tempo restante: <span data-idle-countdown>02:00</span>.</p>
        <div class="idle-warning-actions">
            <button type="button" data-idle-continue>Continuar sessão</button>
            <button type="button" data-idle-logout>Encerrar agora</button>
        </div>
    </div>
    <?php if (!$isStandaloneView): ?>
        <div class="floating-actions">
            <?php if ($showConfig): ?>
                <a class="config-button" href="<?= url('config'); ?>">Configurações</a>
                <a class="config-button manual-button" href="<?= url('backup-manager'); ?>">Backups</a>
            <?php endif; ?>
            <?php if ($showApproval): ?>
                <a class="config-button manual-button" href="<?= url('config/manual/whatsapp'); ?>" target="_blank" rel="noopener">Manual WhatsApp</a>
            <?php endif; ?>
            <?php if ($showAutomation): ?>
                <button class="automation-control" type="button" data-automation aria-label="Alternar automação">
                    <span class="automation-status">Automação</span>
                    <span class="automation-label">Iniciar</span>
                </button>
            <?php endif; ?>
            <?php if ($showApproval): ?>
                <a class="approval-button" href="<?= url('admin/access-requests'); ?>">Liberação</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="container">
        <?php if (!$isStandaloneView): ?>
            <?php if ($filteredSections !== []): ?>
                <nav class="top-nav">
                    <?php foreach ($filteredSections as $section): ?>
                        <div class="nav-section">
                            <div class="nav-surface">
                                <span class="nav-label"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="nav-legend"><?= htmlspecialchars($section['legend'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="nav-hint">Passe o mouse</span>
                            <div class="nav-links">
                                <?php foreach ($section['links'] as $link): ?>
                                    <?php $target = $link['target'] ?? null; ?>
                                    <a class="nav-link" href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>"<?= $target ? ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '" rel="noopener"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </nav>
            <?php else: ?>
                <div class="panel" style="margin-bottom:24px;">
                    <p style="margin:0;color:var(--muted);">Nenhum módulo foi liberado para o seu usuário. Solicite ao administrador a liberação dos acessos necessários.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?= $content ?? '' ?>
        <footer>
            Plataforma interna de marketing – <?= htmlspecialchars(config('app.name'), ENT_QUOTES, 'UTF-8'); ?>
        </footer>
    </div>
    <?php if (!$isStandaloneView): ?>
        <div class="chat-floating-panel" data-chat-widget-panel hidden role="dialog" aria-label="Chat interno">
            <header>
                <strong>Chat interno</strong>
                <button type="button" class="chat-floating-close" data-chat-widget-close aria-label="Minimizar chat">&times;</button>
            </header>
            <div class="chat-floating-frame">
                <iframe title="Chat interno" data-chat-widget-frame data-src="<?= htmlspecialchars(url('chat') . '?chat_widget=1', ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"></iframe>
            </div>
        </div>
        <button type="button" class="chat-floating-launcher" data-chat-launcher aria-expanded="false">
            <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12c0 3.866-3.582 7-8 7-1.264 0-2.456-.247-3.5-.684L5 20l1.5-3.5C6.04 15.455 5.5 13.795 5.5 12c0-3.866 3.582-7 8-7s8 3.134 8 7z" />
            </svg>
            <span>Chat</span>
        </button>
    <?php endif; ?>
    <?php endif; ?>
    <script>
        (function () {
            var meta = document.querySelector('meta[name="csrf-token"]');
            window.CSRF_TOKEN = meta ? meta.getAttribute('content') || '' : '';
        })();

        (function () {
            if (typeof window.fetch !== 'function') {
                return;
            }

            var originalFetch = window.fetch;
            window.fetch = function (input, init) {
                var requestInit = init ? Object.assign({}, init) : {};
                var baseHeaders = undefined;

                if (input instanceof Request) {
                    baseHeaders = input.headers;
                }

                var headers = new Headers(baseHeaders);

                if (requestInit.headers) {
                    new Headers(requestInit.headers).forEach(function (value, key) {
                        headers.set(key, value);
                    });
                }

                if (window.CSRF_TOKEN && !headers.has('X-CSRF-TOKEN')) {
                    headers.set('X-CSRF-TOKEN', window.CSRF_TOKEN);
                }

                requestInit.headers = headers;

                if (input instanceof Request) {
                    input = new Request(input, requestInit);
                    return originalFetch.call(this, input);
                }

                return originalFetch.call(this, input, requestInit);
            };
        })();

        (function () {
            var menu = document.querySelector('[data-user-menu]');
            if (!menu) {
                return;
            }

            var trigger = menu.querySelector('[data-user-menu-trigger]');
            var dropdown = menu.querySelector('[data-user-menu-dropdown]');
            if (!trigger || !dropdown) {
                return;
            }

            var isOpen = false;

            function setOpen(nextState) {
                isOpen = nextState;
                menu.setAttribute('data-open', nextState ? 'true' : 'false');
                trigger.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            }

            setOpen(false);

            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                setOpen(!isOpen);
            });

            dropdown.addEventListener('click', function (event) {
                var target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (target.closest('a') || target.closest('button')) {
                    setOpen(false);
                }
            });

            document.addEventListener('click', function (event) {
                var target = event.target;
                if (!(target instanceof Element)) {
                    setOpen(false);
                    return;
                }
                if (!menu.contains(target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });
        })();

        (function () {
            if (document.body.classList.contains('is-chat-widget')) {
                return;
            }

            var launcher = document.querySelector('[data-chat-launcher]');
            var panel = document.querySelector('[data-chat-widget-panel]');
            if (!launcher || !panel) {
                return;
            }

            var frame = panel.querySelector('[data-chat-widget-frame]');
            var closeBtn = panel.querySelector('[data-chat-widget-close]');
            var frameSrc = frame ? frame.getAttribute('data-src') : '';
            var loaded = false;
            var isOpen = false;

            function ensureFrameLoaded() {
                if (!frame || loaded || !frameSrc) {
                    return;
                }
                frame.setAttribute('src', frameSrc);
                loaded = true;
            }

            function setOpen(nextState) {
                isOpen = nextState;
                launcher.setAttribute('aria-expanded', nextState ? 'true' : 'false');
                if (nextState) {
                    launcher.setAttribute('data-open', 'true');
                    panel.removeAttribute('hidden');
                    ensureFrameLoaded();
                } else {
                    launcher.removeAttribute('data-open');
                    panel.setAttribute('hidden', 'hidden');
                }
            }

            launcher.addEventListener('click', function () {
                setOpen(!isOpen);
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    setOpen(false);
                    launcher.focus();
                });
            }

            document.addEventListener('click', function (event) {
                if (!isOpen) {
                    return;
                }
                var target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (!panel.contains(target) && !launcher.contains(target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && isOpen) {
                    setOpen(false);
                    launcher.focus();
                }
            });
        })();

        (function () {
            if (document.body.classList.contains('is-chat-widget')) {
                return;
            }

            var launcher = document.querySelector('[data-chat-launcher]');
            if (!launcher) {
                return;
            }

            launcher.setAttribute('aria-label', 'Chat interno');

            var summaryUrl = "<?= htmlspecialchars(url('chat/threads'), ENT_QUOTES, 'UTF-8'); ?>";
            var pollIntervalMs = 5000;
            var lastAlertState = 'none';
            var origin = (function () {
                try {
                    if (window.location && window.location.origin) {
                        return window.location.origin;
                    }
                    return window.location.protocol + '//' + window.location.host;
                } catch (error) {
                    return null;
                }
            })();

            var overdueThresholdMs = 60000;
            var externalActiveSince = null;
            var overdueTimerId = null;

            function clearOverdueTracking() {
                externalActiveSince = null;
                if (overdueTimerId) {
                    clearTimeout(overdueTimerId);
                    overdueTimerId = null;
                }
                launcher.removeAttribute('data-overdue');
            }

            function markOverdueIfNeeded() {
                if (externalActiveSince === null) {
                    return;
                }
                var elapsed = Date.now() - externalActiveSince;
                if (elapsed >= overdueThresholdMs) {
                    launcher.setAttribute('data-overdue', 'true');
                    return;
                }
                if (overdueTimerId) {
                    clearTimeout(overdueTimerId);
                }
                overdueTimerId = setTimeout(function () {
                    overdueTimerId = null;
                    launcher.setAttribute('data-overdue', 'true');
                }, overdueThresholdMs - elapsed);
            }

            function applyAlert(state) {
                if (state === lastAlertState) {
                    if (state === 'external') {
                        markOverdueIfNeeded();
                    }
                    return;
                }
                lastAlertState = state;
                if (state === 'external') {
                    if (externalActiveSince === null) {
                        externalActiveSince = Date.now();
                        launcher.removeAttribute('data-overdue');
                    }
                    markOverdueIfNeeded();
                    launcher.setAttribute('data-alert', 'external');
                    launcher.setAttribute('aria-label', 'Chat interno - mensagens externas não lidas');
                } else if (state === 'internal') {
                    clearOverdueTracking();
                    launcher.setAttribute('data-alert', 'internal');
                    launcher.setAttribute('aria-label', 'Chat interno - mensagens internas não lidas');
                } else {
                    clearOverdueTracking();
                    launcher.removeAttribute('data-alert');
                    launcher.setAttribute('aria-label', 'Chat interno');
                }
            }

            function hasPendingExternal(thread) {
                if (!thread || (thread.type || 'direct').toLowerCase() !== 'external') {
                    return false;
                }
                var lead = thread.external_lead || null;
                if (!lead) {
                    return false;
                }
                var status = String(lead.status || '').toLowerCase();
                return status === 'pending';
            }

            function summarizeThreads(list) {
                var counters = { external: 0, internal: 0 };
                if (!Array.isArray(list)) {
                    return counters;
                }
                list.forEach(function (thread) {
                    if (!thread) {
                        return;
                    }
                    var unread = parseInt(thread.unread_count || 0, 10) || 0;
                    var type = (thread.type || 'direct').toLowerCase();
                    if (unread > 0) {
                        if (type === 'external') {
                            counters.external += unread;
                        } else {
                            counters.internal += unread;
                        }
                        return;
                    }
                    if (type === 'external' && hasPendingExternal(thread)) {
                        counters.external += 1;
                    }
                });
                return counters;
            }

            function resolveAlertState(counters) {
                if ((counters.external || 0) > 0) {
                    return 'external';
                }
                if ((counters.internal || 0) > 0) {
                    return 'internal';
                }
                return 'none';
            }

            function updateFromThreads(list) {
                applyAlert(resolveAlertState(summarizeThreads(list)));
            }

            function updateFromSummary(summary) {
                summary = summary || {};
                var normalized = {
                    external: Number(summary.external) || 0,
                    internal: Number(summary.internal) || 0
                };
                applyAlert(resolveAlertState(normalized));
            }

            function fetchSummary() {
                if (!summaryUrl) {
                    return;
                }
                fetch(summaryUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        return Promise.reject(new Error('Não foi possível sincronizar o chat.'));
                    }
                    return response.json();
                }).then(function (payload) {
                    if (payload && Array.isArray(payload.threads)) {
                        updateFromThreads(payload.threads);
                    } else {
                        applyAlert('none');
                    }
                }).catch(function () {});
            }

            fetchSummary();
            setInterval(fetchSummary, pollIntervalMs);

            window.addEventListener('message', function (event) {
                if (origin && event.origin && event.origin !== origin) {
                    return;
                }
                if (!event.data || event.data.type !== 'chat:thread-unread-summary') {
                    return;
                }
                updateFromSummary(event.data.payload);
            });
        })();

        (function () {
            var automationToggle = document.querySelector('[data-automation]');
            if (automationToggle) {
                var automationLabel = automationToggle.querySelector('[data-automation-text]');
                var automationStatus = automationToggle.querySelector('.automation-status');
                var storageKey = 'marketing_suite_automation_status';
                var state = localStorage.getItem(storageKey) === 'active' ? 'active' : 'inactive';

                function renderAutomation() {
                    var isActive = state === 'active';
                    automationToggle.classList.toggle('is-active', isActive);
                    if (automationLabel) {
                        automationLabel.textContent = isActive ? 'Cancelar' : 'Iniciar';
                    }
                    if (automationStatus) {
                        automationStatus.textContent = 'Automação';
                    }
                    automationToggle.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                }

                renderAutomation();

                automationToggle.addEventListener('click', function () {
                    state = state === 'active' ? 'inactive' : 'active';
                    localStorage.setItem(storageKey, state);
                    renderAutomation();

                    var detail = state === 'active'
                        ? {
                            started_at: Date.now(),
                            status: 'em execução'
                        }
                        : {
                            started_at: Date.now(),
                            status: 'pausada'
                        };

                    window.dispatchEvent(new CustomEvent('automation:updated', {
                        detail: detail
                    }));
                });
            }

            var modules = document.querySelectorAll('[data-module]');
            modules.forEach(function (module) {
                var toggle = module.querySelector('.module-toggle');
                var body = module.querySelector('.module-body');
                if (!toggle || !body) {
                    return;
                }

                var defaultState = module.hasAttribute('data-default-open') ? 'true' : (module.getAttribute('data-open') || 'false');
                module.setAttribute('data-open', defaultState);
                toggle.setAttribute('aria-expanded', defaultState === 'true' ? 'true' : 'false');
                module.classList.add('module-ready');

                toggle.addEventListener('click', function () {
                    var isOpen = module.getAttribute('data-open') === 'true';
                    module.setAttribute('data-open', isOpen ? 'false' : 'true');
                    toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                });
            });
        })();

        (function () {
            var idleLimitMs = <?= (int)$idleLimitSeconds; ?> * 1000;
            if (!idleLimitMs || idleLimitMs <= 0) {
                return;
            }

            var warningThresholdMs = 120000;
            var heartbeatIntervalMs = 240000;
            var heartbeatUrl = '<?= url('auth/heartbeat'); ?>';
            var logoutUrl = '<?= url('auth/logout'); ?>';
            var loginUrl = '<?= url('auth/login'); ?>';
            var warning = document.querySelector('[data-idle-warning]');
            var countdown = warning ? warning.querySelector('[data-idle-countdown]') : null;
            var continueBtn = warning ? warning.querySelector('[data-idle-continue]') : null;
            var logoutBtn = warning ? warning.querySelector('[data-idle-logout]') : null;
            var lastActivity = Date.now();
            var nextHeartbeatAt = Date.now() + 15000;
            var heartbeatInFlight = false;

            function formatCountdown(ms) {
                var seconds = Math.max(0, Math.floor(ms / 1000));
                var minutes = Math.floor(seconds / 60);
                var remainingSeconds = seconds % 60;
                return minutes + ':' + String(remainingSeconds).padStart(2, '0');
            }

            function showWarning(remainingMs) {
                if (!warning) {
                    return;
                }
                if (countdown) {
                    countdown.textContent = formatCountdown(remainingMs);
                }
                warning.removeAttribute('hidden');
            }

            function hideWarning() {
                if (!warning) {
                    return;
                }
                if (!warning.hasAttribute('hidden')) {
                    warning.setAttribute('hidden', 'hidden');
                }
            }

            function noteActivity() {
                lastActivity = Date.now();
                hideWarning();
            }

            function redirectToLogin() {
                window.location.href = loginUrl;
            }

            function logoutNow() {
                fetch(logoutUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': window.CSRF_TOKEN
                    }
                }).finally(function () {
                    redirectToLogin();
                });
            }

            function sendHeartbeat(force) {
                var now = Date.now();
                if (!force) {
                    if (heartbeatInFlight || now < nextHeartbeatAt) {
                        return;
                    }
                }

                heartbeatInFlight = true;

                fetch(heartbeatUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': window.CSRF_TOKEN,
                        'Accept': 'application/json'
                    }
                }).then(function (response) {
                    if (response.status === 401) {
                        heartbeatInFlight = false;
                        redirectToLogin();
                        return Promise.reject(new Error('Sessão expirada'));
                    }
                    return response.json();
                }).then(function (payload) {
                    heartbeatInFlight = false;

                    if (!payload || payload.status !== 'ok') {
                        nextHeartbeatAt = Date.now() + 60000;
                        return;
                    }

                    lastActivity = Date.now();
                    nextHeartbeatAt = Date.now() + heartbeatIntervalMs;

                    if (typeof payload.remaining === 'number' && warning && !warning.hasAttribute('hidden')) {
                        showWarning(payload.remaining * 1000);
                    }
                }).catch(function () {
                    heartbeatInFlight = false;
                    nextHeartbeatAt = Date.now() + 60000;
                });
            }

            function checkIdle() {
                var now = Date.now();
                var elapsed = now - lastActivity;

                if (elapsed >= idleLimitMs) {
                    redirectToLogin();
                    return;
                }

                if (elapsed >= idleLimitMs - warningThresholdMs) {
                    showWarning(idleLimitMs - elapsed);
                } else {
                    hideWarning();
                }

                if (now >= nextHeartbeatAt && elapsed < idleLimitMs) {
                    sendHeartbeat(false);
                }
            }

            var activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart', 'touchmove'];
            activityEvents.forEach(function (eventName) {
                document.addEventListener(eventName, noteActivity, { passive: true });
            });

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    noteActivity();
                    sendHeartbeat(true);
                }
            });

            if (continueBtn) {
                continueBtn.addEventListener('click', function () {
                    noteActivity();
                    sendHeartbeat(true);
                });
            }

            if (logoutBtn) {
                logoutBtn.addEventListener('click', function () {
                    logoutNow();
                });
            }

            setInterval(checkIdle, 15000);
            setTimeout(function () {
                sendHeartbeat(true);
            }, 5000);
        })();
    </script>
</body>
</html>
