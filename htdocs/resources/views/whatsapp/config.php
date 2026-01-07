<?php
$statusData = is_array($status ?? null) ? $status : [];
$status = array_merge([
    'connected' => false,
    'missing' => [],
    'webhook_ready' => false,
    'copilot_ready' => false,
    'lines_total' => 0,
    'waiting_threads' => 0,
], $statusData);

$queueSource = $queueSummary ?? ($status['queues'] ?? []);
$queueSummary = array_merge([
    'arrival' => 0,
    'scheduled' => 0,
    'partner' => 0,
    'reminder' => 0,
], is_array($queueSource) ? $queueSource : []);

$lines = is_array($lines ?? null) ? $lines : [];
$sandboxLines = is_array($sandboxLines ?? null) ? $sandboxLines : [];
$options = is_array($options ?? null) ? $options : [];
$copilotProfiles = is_array($copilotProfiles ?? null) ? $copilotProfiles : [];
$manuals = is_array($manuals ?? null) ? $manuals : [];
$trainingSamples = is_array($trainingSamples ?? null) ? $trainingSamples : [];
$blockedNumbersList = array_map('strval', $options['blocked_numbers'] ?? []);
$mediaTemplatesInput = is_array($mediaTemplates ?? null) ? $mediaTemplates : [];
$mediaTemplates = array_replace([
    'stickers' => [],
    'documents' => [],
], $mediaTemplatesInput);
$recentBroadcasts = is_array($recentBroadcasts ?? null) ? $recentBroadcasts : [];
$queueLabels = array_merge([
    'arrival' => 'Fila de chegada',
    'scheduled' => 'Agendamentos',
    'partner' => 'Parceiros / Indicadores',
    'reminder' => 'Lembretes',
], is_array($queueLabels ?? null) ? $queueLabels : []);
$broadcastQueueLabels = array_merge($queueLabels, ['groups' => 'Grupos']);
$knowledge = array_merge([
    'manuals' => 0,
    'samples' => 0,
], is_array($status['knowledge'] ?? null) ? $status['knowledge'] : []);
$allowedUserIds = array_map('intval', is_array($allowedUserIds ?? null) ? $allowedUserIds : []);
$agentsDirectory = is_array($agentsDirectory ?? null) ? $agentsDirectory : [];
$agentPermissions = is_array($agentPermissions ?? null) ? $agentPermissions : [];
$permissionPresets = is_array($permissionPresets ?? null) ? $permissionPresets : [];

$altGatewayMap = [];
if (!empty($altGateways ?? [])) {
    foreach ($altGateways as $gateway) {
        $slug = (string)($gateway['slug'] ?? '');
        if ($slug !== '') {
            $altGatewayMap[$slug] = $gateway;
        }
    }
}
$defaultAltGatewaySlug = '';
if (!empty($status['alt_gateway']['slug'])) {
    $defaultAltGatewaySlug = (string)$status['alt_gateway']['slug'];
} elseif (!empty($altGateways)) {
    $defaultAltGatewaySlug = (string)($altGateways[0]['slug'] ?? '');
}

$actor = $actor ?? null;
$canManage = (bool)($canManage ?? false);
$standaloneView = isset($_GET['standalone']) && $_GET['standalone'] !== '0';
if (!$standaloneView) {
    $queryParams = $_GET;
    $queryParams['standalone'] = '1';
    $redirectUrl = url('whatsapp/config');
    if ($queryParams !== []) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$collapseContext = false;
$activeThreadId = 0;
$activeContact = ['name' => '', 'phone' => ''];
$latestMessageText = '';
$messages = [];

$configSections = [
    [
        'key' => 'overview',
        'label' => 'Panorama',
        'description' => 'Status das integrações',
    ],
    [
        'key' => 'lines',
        'label' => 'Linhas e gateways',
        'description' => 'Cadastro e monitoramento',
    ],
    [
        'key' => 'sandbox',
        'label' => 'Testes e sandbox',
        'description' => 'Injetar mensagens internas',
    ],
    [
        'key' => 'broadcast',
        'label' => 'Comunicados rápidos',
        'description' => 'Envios para filas/grupos',
    ],
    [
        'key' => 'blocked',
        'label' => 'Bloqueios',
        'description' => 'Lista negativa de números',
    ],
    [
        'key' => 'permissions',
        'label' => 'Permissões e IA',
        'description' => 'Acesso e chave Copilot',
    ],
    [
        'key' => 'knowledge',
        'label' => 'Base de conhecimento',
        'description' => 'Manuais e amostras',
    ],
    [
        'key' => 'profiles',
        'label' => 'Perfis do Copilot',
        'description' => 'Agentes e instruções',
    ],
    [
        'key' => 'backup',
        'label' => 'Backups',
        'description' => 'Exportar e restaurar dados',
    ],
];

$panelDefinitions = [
    'entrada' => [
        'label' => 'Entrada',
        'hint' => 'Libera a fila de chegada.',
        'options' => [
            'all' => 'Sim, pode ver Entrada',
            'none' => 'Não pode ver',
        ],
        'allow_users' => false,
    ],
    'atendimento' => [
        'label' => 'Atendimento',
        'hint' => 'Controla as conversas em andamento.',
        'options' => [
            'own' => 'Somente as próprias',
            'own_or_assigned' => 'Próprias + direcionadas a ele(a)',
            'selected' => 'Escolher usuários específicos',
            'all' => 'Todas as conversas',
            'none' => 'Não pode ver',
        ],
        'allow_users' => true,
    ],
    'grupos' => [
        'label' => 'Grupos',
        'hint' => 'Acesso às conversas em grupo.',
        'options' => [
            'own' => 'Somente grupos assumidos por ele(a)',
            'selected' => 'Selecionar responsáveis',
            'all' => 'Todos os grupos',
            'none' => 'Sem acesso',
        ],
        'allow_users' => true,
    ],
    'parceiros' => [
        'label' => 'Parceiros',
        'hint' => 'Fila de indicações/parceiros.',
        'options' => [
            'own' => 'Somente as próprias',
            'selected' => 'Selecionar usuários',
            'all' => 'Todas',
            'none' => 'Sem acesso',
        ],
        'allow_users' => true,
    ],
    'lembrete' => [
        'label' => 'Lembretes',
        'hint' => 'Conversas aguardando follow-up.',
        'options' => [
            'own' => 'Somente lembretes próprios',
            'selected' => 'Selecionar usuários',
            'all' => 'Todos',
            'none' => 'Sem acesso',
        ],
        'allow_users' => true,
    ],
    'agendamento' => [
        'label' => 'Agendamentos',
        'hint' => 'Clientes com horário marcado.',
        'options' => [
            'own' => 'Somente os próprios',
            'selected' => 'Selecionar usuários',
            'all' => 'Todos',
            'none' => 'Sem acesso',
        ],
        'allow_users' => true,
    ],
    'concluidos' => [
        'label' => 'Concluídos',
        'hint' => 'Histórico de atendimentos encerrados.',
        'options' => [
            'own' => 'Somente os próprios',
            'selected' => 'Selecionar usuários',
            'all' => 'Todos',
            'none' => 'Sem acesso',
        ],
        'allow_users' => true,
    ],
];
?>
<header class="wa-header">
    <div>
        <h1>Configurações do Chatboot para WhatsApp</h1>
        <p>Centralize integrações, permissões, linhas oficiais e o laboratório alternativo sem depender do VS Code ou de planilhas paralelas.</p>
    </div>
    <div class="wa-header-cta">
        <span class="wa-chip <?= $status['connected'] ? 'wa-chip--queue' : ''; ?>">Rede <?= $status['connected'] ? 'operante' : 'pendente'; ?></span>
        <span class="wa-chip <?= ($status['waiting_threads'] ?? 0) > 0 ? 'wa-chip--queue' : ''; ?>">Aguardando: <?= (int)($status['waiting_threads'] ?? 0); ?></span>
        <a class="ghost" href="<?= url('whatsapp'); ?>?standalone=1" target="_blank" rel="noopener">Abrir atendimento</a>
    </div>
</header>

<style>
    .config-layout {
        display:flex;
        gap:24px;
        align-items:flex-start;
        margin-top:24px;
    }
    .config-menu {
        flex:0 0 260px;
        display:flex;
        flex-direction:column;
        gap:10px;
        position:sticky;
        top:90px;
        max-height:calc(100vh - 120px);
        overflow-y:auto;
        padding-right:4px;
    }
    .config-menu-item {
        display:flex;
        flex-direction:column;
        gap:4px;
        padding:14px 16px;
        border-radius:14px;
        border:1px solid rgba(148,163,184,0.3);
        background:rgba(15,23,42,0.55);
        color:var(--text);
        text-align:left;
        font-size:0.9rem;
        cursor:pointer;
        transition:border-color 0.2s ease, background 0.2s ease;
    }
    .config-menu-item strong { font-size:1rem; }
    .config-menu-item span { color:rgba(148,163,184,0.9); font-size:0.8rem; }
    .config-menu-item.is-active { border-color:var(--accent); background:rgba(56,189,248,0.12); }
    .config-panels {
        flex:1;
        display:flex;
        flex-direction:column;
        gap:28px;
    }
    .wa-guide-summary {
        border:1px solid rgba(148,163,184,0.25);
        border-radius:12px;
        padding:12px;
        background:rgba(15,23,42,0.5);
        color:var(--muted);
        font-size:0.95rem;
    }
    .wa-guide-grid {
        display:grid;
        gap:12px;
        grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
        align-items:flex-start;
    }
    .wa-guide-card {
        border:1px dashed rgba(148,163,184,0.35);
        border-radius:14px;
        padding:12px;
        background:rgba(15,23,42,0.45);
        display:flex;
        flex-direction:column;
        gap:8px;
    }
    .wa-guide-card[hidden] { display:none; }
    .wa-guide-grid[hidden], .wa-guide-summary[hidden] { display:none !important; }
    .wa-guide-card a { color:#7dd3fc; text-decoration:underline; }
    .wa-guide-badge {
        display:inline-flex;
        align-items:center;
        gap:6px;
        font-size:0.75rem;
        padding:4px 10px;
        border-radius:999px;
        border:1px solid rgba(148,163,184,0.35);
        color:var(--muted);
        background:rgba(56,189,248,0.08);
    }
    .wa-guide-list {
        margin:0;
        padding-left:16px;
        color:var(--muted);
        font-size:0.92rem;
    }
    .wa-guide-list li { margin-bottom:6px; }
    .wa-guide-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .panel {
        border:1px solid rgba(148,163,184,0.25);
        border-radius:18px;
        padding:26px;
        background:rgba(15,23,42,0.65);
    }
    .config-section { display:none; }
    .config-section.is-active { display:block; }
    .wa-header {
        display:flex;
        justify-content:space-between;
        gap:20px;
        flex-wrap:wrap;
        align-items:flex-start;
        margin-bottom:18px;
    }
    .wa-header h1 { margin:0 0 6px; font-size:1.8rem; }
    .wa-header p { margin:0; color:var(--muted); max-width:720px; }
    .wa-header-cta { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .wa-metrics {
        display:grid;
        gap:16px;
        grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
        margin-bottom:16px;
    }
    .wa-card, .wa-admin-card {
        border:1px solid rgba(148,163,184,0.25);
        border-radius:18px;
        background:rgba(15,23,42,0.82);
        padding:18px;
        box-shadow:var(--shadow);
    }
    .wa-card-header {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
    }
    .wa-card-header h3 { margin:0; font-size:1.05rem; }
    .wa-card-header small, .wa-card-header p { color:var(--muted); margin:0; }
    .wa-chip {
        display:inline-flex;
        align-items:center;
        gap:4px;
        padding:3px 10px;
        border-radius:999px;
        border:1px solid rgba(148,163,184,0.35);
        font-size:0.75rem;
        color:var(--muted);
    }
    .wa-chip--queue { border-color:rgba(56,189,248,0.5); color:#7dd3fc; }
    .wa-empty { color:var(--muted); font-size:0.9rem; margin:0; }
    .wa-button--ghost { background: transparent; color: var(--text); border:1px solid var(--border); }
    .wa-section-grid {
        display:grid;
        gap:18px;
        grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
        align-items:flex-start;
    }
    .wa-message-form textarea,
    .wa-message-form input,
    .wa-message-form select {
        width:100%;
        border-radius:14px;
        border:1px solid rgba(148,163,184,0.3);
        background:rgba(15,23,42,0.55);
        color:var(--text);
        padding:12px;
        font-size:0.95rem;
    }
    .wa-form-field {
        display:flex;
        flex-direction:column;
        gap:6px;
        margin-bottom:12px;
    }
    .wa-feedback { color:var(--muted); font-size:0.8rem; display:block; margin-top:6px; min-height:1.2em; }
    .wa-line-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px; }
    .wa-line { border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; }
    .wa-line-actions { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
    .wa-line-gateway { border-radius:999px; padding:6px 14px; font-size:0.78rem; border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.55); color:var(--text); }
    .wa-line-gateway.is-online { border-color:rgba(34,197,94,0.55); background:rgba(34,197,94,0.12); color:#22c55e; }
    .wa-line-gateway.is-warning { border-color:rgba(250,204,21,0.55); background:rgba(250,204,21,0.12); color:#facc15; }
    .wa-line-gateway.is-offline { border-color:rgba(248,113,113,0.45); background:rgba(248,113,113,0.12); color:#f87171; }
    .wa-line-gateway-row { margin-top:12px; padding-top:10px; border-top:1px dashed rgba(148,163,184,0.25); display:flex; flex-direction:column; gap:6px; }
    .wa-alt-inline { border:1px solid rgba(148,163,184,0.3); border-radius:14px; padding:14px; background:rgba(15,23,42,0.4); display:flex; flex-direction:column; gap:12px; }
    .wa-alt-inline-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .wa-alt-inline-panel { border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:14px; background:rgba(2,6,23,0.6); margin-top:16px; }
    .wa-alt-grid { display:flex; flex-direction:column; gap:16px; }
    .wa-alt-card { border:1px solid rgba(82,101,143,0.4); border-radius:16px; padding:16px; background:rgba(9,15,30,0.8); }
    .wa-alt-card header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .wa-alt-actions { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; }
    .wa-gateway-toggle { border:1px solid #38bdf8; background:linear-gradient(135deg,#0ea5e9,#0284c7); color:#0b1224; font-weight:600; box-shadow:0 10px 30px rgba(14,165,233,0.25); }
    .wa-gateway-toggle.is-stop { border-color:#f87171; background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; box-shadow:0 10px 30px rgba(248,113,113,0.3); }
    .wa-gateway-toggle.is-busy { opacity:0.85; pointer-events:none; position:relative; }
    .wa-gateway-toggle.is-busy::after { content:''; position:absolute; right:10px; top:50%; width:14px; height:14px; margin-top:-7px; border-radius:999px; border:2px solid rgba(255,255,255,0.5); border-top-color:#fff; animation:wa-spin 0.9s linear infinite; }
    .wa-gateway-clean { border-color:#fbbf24; color:#fef3c7; background:rgba(251,191,36,0.15); }
    @keyframes wa-spin { to { transform:rotate(360deg); } }
    .wa-alt-meta { list-style:none; margin:12px 0 0; padding:0; font-size:0.85rem; color:var(--muted); }
    .wa-alt-meta li { display:flex; justify-content:space-between; gap:8px; padding:4px 0; border-bottom:1px dashed rgba(148,163,184,0.25); }
    .wa-alt-qr { margin-top:12px; border:1px dashed rgba(56,189,248,0.4); padding:12px; border-radius:14px; }
    .wa-alt-qr img { display:block; width:260px; max-width:100%; margin:8px auto; background:#fff; padding:12px; border-radius:12px; border:1px solid rgba(148,163,184,0.35); image-rendering:pixelated; }
    .wa-alt-qr-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .wa-alt-history-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
    .wa-alt-history-mode { display:flex; flex-direction:column; gap:6px; font-size:0.85rem; color:var(--muted); }
    .wa-alt-history-controls { display:flex; flex-direction:column; gap:8px; }
    .wa-template-preview { border:1px dashed rgba(56,189,248,0.35); border-radius:14px; padding:12px; margin-top:10px; background:rgba(13,23,42,0.55); display:flex; flex-direction:column; gap:6px; }
    .wa-template-preview img { max-width:140px; border-radius:10px; border:1px solid rgba(148,163,184,0.25); background:#fff; padding:6px; }
    .wa-permission-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
    .wa-permission-cards { display:flex; flex-direction:column; gap:14px; margin-top:16px; }
    .wa-user-permission { border:1px solid rgba(82,101,143,0.4); border-radius:16px; padding:16px; background:rgba(7,12,28,0.85); display:flex; flex-direction:column; gap:14px; }
    .wa-user-permission header { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center; }
    .wa-user-permission-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
    .wa-permission-scope-users[hidden] { display:none; }
    .wa-permission-toggle-group { display:flex; flex-wrap:wrap; gap:12px; }
    .wa-permission-toggle-group label { display:flex; gap:6px; align-items:center; font-size:0.9rem; }
    .wa-permission-toggle-group input[type="checkbox"] { width:16px; height:16px; }
    .wa-permission-users { display:grid; gap:6px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:10px; background:rgba(11,18,34,0.6); max-height:220px; overflow:auto; }
    .wa-user-scope-option { display:flex; gap:6px; align-items:center; font-size:0.85rem; }
    .wa-permission-actions { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .wa-permission-actions small { color:var(--muted); min-height:1.2em; }
    .wa-panel-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-top:6px; }
    .wa-panel-users { display:grid; gap:6px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); border:1px dashed rgba(148,163,184,0.25); border-radius:12px; padding:10px; background:rgba(8,13,28,0.6); max-height:200px; overflow:auto; margin-top:8px; }
    .wa-panel-users[hidden] { display:none; }
    .wa-broadcast-grid { display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); align-items:flex-start; }
    .wa-broadcast-history { border:1px solid rgba(82,101,143,0.4); border-radius:16px; padding:16px; background:rgba(7,12,28,0.85); max-height:420px; overflow:auto; }
    .wa-broadcast-row { border-bottom:1px dashed rgba(148,163,184,0.25); padding:12px 0; display:flex; flex-direction:column; gap:6px; }
    .wa-broadcast-row:last-child { border-bottom:none; }
    .wa-status-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:0.75rem; border:1px solid rgba(148,163,184,0.35); color:#e2e8f0; }
    .wa-status-badge.is-success { border-color:rgba(34,197,94,0.45); color:#86efac; }
    .wa-status-badge.is-warning { border-color:rgba(250,204,21,0.45); color:#fde68a; }
    .wa-status-badge.is-error { border-color:rgba(248,113,113,0.45); color:#fecdd3; }
    .wa-qr-modal {
        position:fixed;
        inset:0;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:24px;
        z-index:1200;
        background:rgba(2,6,23,0.75);
    }
    .wa-qr-modal[hidden] { display:none; }
    .wa-qr-modal__overlay {
        position:absolute;
        inset:0;
        background:rgba(2,6,23,0.65);
        backdrop-filter:blur(2px);
    }
    .wa-qr-modal__card {
        position:relative;
        z-index:1;
        width:min(420px,100%);
        border-radius:18px;
        border:1px solid rgba(148,163,184,0.35);
        background:rgba(15,23,42,0.96);
        padding:20px;
        box-shadow:0 35px 80px rgba(2,6,23,0.55);
        display:flex;
        flex-direction:column;
        gap:12px;
    }
    .wa-qr-modal__header {
        display:flex;
        justify-content:space-between;
        gap:12px;
        align-items:flex-start;
    }
    .wa-qr-modal__close {
        font-size:1.8rem;
        line-height:1;
        padding:4px 8px;
    }
    .wa-qr-modal__image {
        min-height:240px;
        border-radius:16px;
        border:1px dashed rgba(148,163,184,0.35);
        background:rgba(15,23,42,0.7);
        display:flex;
        align-items:center;
        justify-content:center;
        padding:16px;
    }
    .wa-qr-modal__image img {
        width:260px;
        max-width:100%;
        border-radius:12px;
        background:#fff;
        padding:12px;
        border:1px solid rgba(148,163,184,0.35);
        image-rendering:pixelated;
    }
    .wa-qr-placeholder {
        text-align:center;
        color:var(--muted);
        font-size:0.95rem;
    }
    .wa-qr-modal__actions {
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        justify-content:flex-end;
    }
    .wa-section-title { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .wa-section-title p { margin:0; color:var(--muted); }
    @media (max-width:1024px) {
        .config-layout { flex-direction:column; }
        .config-menu { position:static; max-height:none; width:100%; flex-direction:row; flex-wrap:wrap; }
        .config-menu-item { flex:1 1 180px; }
    }
</style>
<script>
// Navegação básica dos painéis (reduzida e resiliente)
document.addEventListener('DOMContentLoaded', function() {
    try {
        const layout = document.querySelector('[data-config-layout]');
        if (!layout) return;
        const buttons = Array.from(layout.querySelectorAll('[data-config-target]'));
        const sections = Array.from(layout.querySelectorAll('[data-config-section]'));
        if (sections.length === 0 || buttons.length === 0) return;

        const activate = (key, updateHash = true) => {
            let found = false;
            sections.forEach((section) => {
                const match = section.dataset.configSection === key;
                section.classList.toggle('is-active', match);
                if (match) found = true;
            });
            buttons.forEach((button) => {
                button.classList.toggle('is-active', button.dataset.configTarget === key);
            });
            if (found && updateHash) {
                const hash = '#' + key;
                if (history.replaceState) {
                    history.replaceState(null, '', hash);
                } else {
                    window.location.hash = hash;
                }
            }
        };

        const initialHash = window.location.hash ? window.location.hash.substring(1) : '';
        const defaultKey = sections[0].dataset.configSection || '';
        const startKey = sections.some((s) => s.dataset.configSection === initialHash) ? initialHash : defaultKey;
        if (startKey) activate(startKey, false);

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.configTarget || defaultKey;
                if (key) activate(key);
            });
        });
    } catch (err) {
        // Se algo falhar, mostra todas as seções para não travar o usuário
        document.querySelectorAll('[data-config-section]').forEach((section) => section.classList.add('is-active'));
    }
});
</script>
<section class="config-layout" data-config-layout>
    <nav class="config-menu">
        <?php foreach ($configSections as $index => $section): ?>
            <button type="button" class="config-menu-item<?= $index === 0 ? ' is-active' : ''; ?>" data-config-target="<?= htmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?= htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8'); ?></span>
            </button>
        <?php endforeach; ?>
    </nav>
    <div class="config-panels">
        <?php foreach ($configSections as $index => $section): ?>
            <?php $sectionKey = $section['key']; ?>
            <section id="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8'); ?>" class="panel config-section<?= $index === 0 ? ' is-active' : ''; ?>" data-config-section="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($sectionKey === 'overview'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Status em tempo real</h2>
                            <p>Confira se webhooks, Copilot e filas estão saudáveis.</p>
                        </div>
                    </div>
                    <div class="wa-metrics">
                        <article class="wa-card">
                            <small style="color:var(--muted);">Linhas conectadas</small>
                            <strong style="display:block; margin:6px 0; font-size:1.3rem;"><?= (int)$status['lines_total']; ?> linhas</strong>
                            <p class="wa-empty">Gerencie até quatro números com tokens independentes.</p>
                        </article>
                        <article class="wa-card">
                            <small style="color:var(--muted);">Webhook / Tokens</small>
                            <strong class="wa-chip <?= $status['webhook_ready'] ? 'wa-chip--queue' : ''; ?>" style="font-size:0.9rem;"><?= $status['webhook_ready'] ? 'Verify token registrado' : 'Configure o verify token'; ?></strong>
                            <p class="wa-empty" style="margin-top:10px;">Callback: <?= htmlspecialchars(url('whatsapp/webhook'), ENT_QUOTES, 'UTF-8'); ?></p>
                        </article>
                        <article class="wa-card">
                            <small style="color:var(--muted);">IA Copilot</small>
                            <strong class="wa-chip <?= $status['copilot_ready'] ? 'wa-chip--queue' : ''; ?>"><?= $status['copilot_ready'] ? 'Sugestões ativas' : 'Informe a API key'; ?></strong>
                            <p class="wa-empty" style="margin-top:10px;">Pré-triagem automática e sugestões de resposta.</p>
                        </article>
                        <article class="wa-card">
                            <small style="color:var(--muted);">Resumo das filas</small>
                            <p class="wa-empty" style="margin-top:8px;">
                                Chegada: <strong><?= (int)$queueSummary['arrival']; ?></strong><br>
                                Agendados: <strong><?= (int)$queueSummary['scheduled']; ?></strong><br>
                                Parceiros: <strong><?= (int)$queueSummary['partner']; ?></strong><br>
                                Lembretes: <strong><?= (int)$queueSummary['reminder']; ?></strong>
                            </p>
                        </article>
                    </div>
                <?php elseif ($sectionKey === 'lines'): ?>
                    <article class="wa-admin-card" data-line-guide-wrapper>
                        <div class="wa-card-header" style="align-items:flex-start; gap:12px; flex-wrap:wrap;">
                            <div>
                                <h3>Manual passo a passo (PDF)</h3>
                                <p>Mostre só o essencial e abra os detalhes quando precisar.</p>
                            </div>
                            <div class="wa-guide-actions">
                                <button type="button" class="ghost" data-line-guide-toggle>Ver passo a passo</button>
                                <a class="ghost" href="<?= url('whatsapp/config/guide-pdf'); ?>" target="_blank" rel="noopener">Salvar guia em PDF</a>
                                <button type="button" class="ghost" data-line-guide-open>Ver guia em tela</button>
                            </div>
                        </div>
                        <div class="wa-guide-summary" data-line-guide-summary>
                            <strong>Resumo básico:</strong>
                            <div style="display:grid; gap:4px; margin-top:6px;">
                                <span><strong>1)</strong> Criar/validar Business Manager (admin) e ter documento pronto para verificação <a href="https://www.facebook.com/business/help/2058515294227817" target="_blank" rel="noopener">(verificar BM)</a>.</span>
                                <span><strong>2)</strong> Preparar número dedicado: desconectar do WhatsApp Mobile, habilitar SMS/voz, sem 2FA ativo.</span>
                                <span><strong>3)</strong> Criar app, habilitar WhatsApp, vincular WABA, validar número e coletar Phone Number ID, Business Account ID e Access Token longo <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started" target="_blank" rel="noopener">(guia oficial)</a>.</span>
                                <span><strong>4)</strong> Registrar webhook: <?= htmlspecialchars(url('whatsapp/webhook'), ENT_QUOTES, 'UTF-8'); ?> com verify token (ex.: token-webhook) e assinar eventos.</span>
                                <span><strong>5)</strong> Cadastrar a linha aqui (Meta Cloud), salvar, testar envio/recebimento, revisar opt-in e limites.</span>
                            </div>
                        </div>
                        <div class="wa-guide-grid" data-line-guide-content hidden>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 1 · Conta e BM</span>
                                <strong>Subir do zero o ambiente Meta</strong>
                                <ul class="wa-guide-list">
                                    <li>Criar conta Meta se não existir e acessar <a href="https://business.facebook.com/settings" target="_blank" rel="noopener">Business Settings</a>.</li>
                                    <li>Garantir perfil como <strong>Administrador</strong> do Business Manager.</li>
                                    <li>Separar documento para verificação do BM (CNPJ/razão social) e, se possível, concluir a verificação: <a href="https://www.facebook.com/business/help/2058515294227817" target="_blank" rel="noopener">verificar BM</a>.</li>
                                    <li>Opcional: criar um <strong>System User</strong> para tokens de longo prazo (permite rodar sem usuário humano).</li>
                                </ul>
                            </div>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 2 · Número dedicado</span>
                                <strong>Preparar o telefone para uso oficial</strong>
                                <ul class="wa-guide-list">
                                    <li>Escolher número exclusivo para o canal. Se estiver no WhatsApp Mobile, desconectar e aguardar alguns minutos.</li>
                                    <li>Confirmar que o número recebe <strong>SMS ou chamada de voz</strong> (evitar IVR na ligação).</li>
                                    <li>Desabilitar 2FA do WhatsApp antigo, se houver, para não bloquear a migração.</li>
                                    <li>Preferir linha estável (fixo ou móvel) com cobertura consistente.</li>
                                </ul>
                                <small class="wa-feedback">Dica: se a linha estava em uso, faça backup e remova do app móvel antes de registrar na Cloud API.</small>
                            </div>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 3 · App, WABA e credenciais</span>
                                <strong>Criar app, vincular WABA e validar o número</strong>
                                <ul class="wa-guide-list">
                                    <li>Ir em <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> → Criar App → Outros → Consumidor.</li>
                                    <li>Adicionar o produto <strong>WhatsApp</strong> e escolher/criar a <strong>WhatsApp Business Account (WABA)</strong>.</li>
                                    <li>Registrar o número: receber código por SMS/voz, validar e concluir. Depois disso, anotar <strong>Phone Number ID</strong> e <strong>Business Account ID</strong>.</li>
                                    <li>Gerar <strong>Access Token de longo prazo</strong> (System User com permissão whatsapp_business_messaging e whatsapp_business_management). Salvar em cofre seguro.</li>
                                </ul>
                                <small class="wa-feedback">Referência oficial: <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started" target="_blank" rel="noopener">Cloud API - Get Started</a></small>
                            </div>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 4 · Webhook</span>
                                <strong>Registrar callback e validar assinatura</strong>
                                <ul class="wa-guide-list">
                                    <li>Callback URL: <?= htmlspecialchars(url('whatsapp/webhook'), ENT_QUOTES, 'UTF-8'); ?> (precisa estar acessível em HTTPS público).</li>
                                    <li>Verify token: o escolhido no início (ex.: token-webhook). Guarde-o para o formulário aqui.</li>
                                    <li>Assinar tópicos: <strong>messages</strong>, <strong>message_template_status_update</strong>, <strong>messaging_product</strong>.</li>
                                    <li>Salvar e confirmar que a verificação retorna 200 OK. Faça um "Test webhook" no painel da Meta para validar.</li>
                                </ul>
                                <small class="wa-feedback">Referência: <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-webhooks" target="_blank" rel="noopener">Webhooks - Guia</a></small>
                            </div>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 5 · Registrar no CRM</span>
                                <strong>Preencher a linha aqui (Meta Cloud)</strong>
                                <ul class="wa-guide-list">
                                    <li>Modo: Nova linha. Modelo: Meta Cloud (padrão).</li>
                                    <li>Campos: Rótulo, Display Phone em E.164 (+5511999990000), Phone Number ID, Business Account ID.</li>
                                    <li>Colar o Access Token de longo prazo e o Verify Token cadastrado no webhook.</li>
                                    <li>Salvar e marcar como padrão (se principal). Ajustar limitador se necessário.</li>
                                </ul>
                                <small class="wa-feedback">Guarde IDs/tokens em cofre (ex.: Vault/Secrets) e renove tokens antes de expirar.</small>
                            </div>
                            <div class="wa-guide-card">
                                <span class="wa-guide-badge">Passo 6 · Testes e produção</span>
                                <strong>Validar fluxo e conformidade</strong>
                                <ul class="wa-guide-list">
                                    <li>Teste inbound: envie "Olá" para o número; confirme que aparece e recebe resposta.</li>
                                    <li>Teste outbound: responda pelo CRM; valide entrega/recebimento na UI e no painel da Meta.</li>
                                    <li>Envios iniciados pela empresa: usar <strong>template aprovado</strong>; respeitar janelas de 24h.</li>
                                    <li>Consentimento: registre opt-in dos contatos e mantenha evidência.</li>
                                    <li>Monitorar qualidade e limites; se cair, reduza volume e revise templates.</li>
                                </ul>
                                <small class="wa-feedback">Políticas: <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/support#policies" target="_blank" rel="noopener">Políticas e qualidade</a></small>
                            </div>
                        </div>
                        <small class="wa-feedback">Clique para abrir os passos completos ou exporte para PDF.</small>
                    </article>
                    <div class="wa-section-grid">
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start; gap:12px;">
                                <div>
                                    <h3>Cadastrar / editar linha</h3>
                                    <p>Preencha os campos para conectar novas linhas ou ajustar as existentes.</p>
                                </div>
                                <div style="display:flex; flex-direction:column; gap:6px; min-width:240px;">
                                    <button type="button" class="wa-button" style="width:100%;" data-maintenance-toggle>
                                        Ativar espera 22h-05h
                                    </button>
                                    <button type="button" class="wa-button wa-button--ghost" style="width:100%;" data-maintenance-run-now>
                                        Rodar manutenção agora
                                    </button>
                                    <small class="wa-feedback" data-maintenance-status>Pronto para agendar na janela 22h-05h.</small>
                                </div>
                            </div>
                            <form id="wa-line-form" class="wa-message-form">
                                <input type="hidden" name="line_id" value="">
                                <input type="hidden" name="mode" value="new" data-line-mode>
                                <div class="wa-form-field">
                                    <label style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                        <span>Modo</span>
                                        <select name="form_mode" data-line-picker>
                                            <option value="new" selected>Nova linha</option>
                                            <option value="edit">Editar existente</option>
                                            <option value="migrate">Migrar credenciais</option>
                                        </select>
                                    </label>
                                    <small class="wa-feedback" data-line-mode-section="new">Inclua a linha e as credenciais completas.</small>
                                    <small class="wa-feedback" data-line-mode-section="edit" hidden>Escolha a linha, ajuste telefone/status/limitador e salve.</small>
                                    <small class="wa-feedback" data-line-mode-section="migrate" hidden>Selecione qual linha receberá as novas credenciais e informe o modelo.</small>
                                </div>
                                <label class="wa-form-field">
                                    <span>Rótulo</span>
                                    <input type="text" name="label" placeholder="Linha Norte" required>
                                </label>
                                <label class="wa-form-field">
                                    <span>Display Phone</span>
                                    <input type="text" name="display_phone" placeholder="+55 11 99999-0000" required>
                                </label>
                                <div data-line-mode-visibility="new,migrate">
                                    <label class="wa-form-field">
                                        <span>Modelo de integração</span>
                                        <select name="api_template" data-line-template>
                                            <option value="meta" selected>Meta Cloud (padrão)</option>
                                            <option value="commercial">Plataforma comercial (WABA própria)</option>
                                            <option value="dialog360">Dialog360 (360dialog)</option>
                                            <option value="sandbox">Sandbox interno</option>
                                            <?php if (!empty($altGateways ?? [])): ?>
                                                <option value="alt">WhatsApp Web alternativo</option>
                                            <?php endif; ?>
                                            <option value="custom">Personalizado</option>
                                        </select>
                                    </label>
                                    <label class="wa-form-field" data-template-hide="alt">
                                        <span>API Provider</span>
                                        <select name="provider">
                                            <option value="meta" selected>Meta Cloud API</option>
                                            <option value="commercial">Plataforma comercial</option>
                                            <option value="dialog360">Dialog360</option>
                                            <option value="sandbox">Sandbox local</option>
                                        </select>
                                    </label>
                                    <label class="wa-form-field" data-template-hide="alt">
                                        <span>API Base URL</span>
                                        <input type="text" name="api_base_url" placeholder="https://graph.facebook.com">
                                    </label>
                                    <div class="wa-template-hints">
                                        <small class="wa-template-hint" data-template-hint="meta" data-template-hide="alt">Use credenciais oficiais da Meta Cloud.</small>
                                        <small class="wa-template-hint" data-template-hint="commercial" data-template-hide="alt">Conecte diretamente à Plataforma Comercial do WhatsApp (WABA própria).</small>
                                        <small class="wa-template-hint" data-template-hint="dialog360" data-template-hide="alt">Informe o token fornecido pela 360dialog.</small>
                                        <small class="wa-template-hint" data-template-hint="sandbox" data-template-hide="alt">Modo interno para testes: não envia mensagens reais.</small>
                                        <small class="wa-template-hint" data-template-hint="alt">Linha conectada ao gateway alternativo.</small>
                                        <small class="wa-template-hint" data-template-hint="custom">Preencha manualmente a URL e campos obrigatórios.</small>
                                    </div>
                                    <div class="wa-template-block" data-template-block="meta,commercial,dialog360,custom" data-template-hide="alt">
                                        <label class="wa-form-field">
                                            <span>Phone Number ID</span>
                                            <input type="text" name="phone_number_id" required>
                                        </label>
                                    </div>
                                    <div class="wa-template-block" data-template-block="meta,commercial" data-template-hide="alt">
                                        <label class="wa-form-field">
                                            <span>Business Account ID</span>
                                            <input type="text" name="business_account_id" required>
                                        </label>
                                    </div>
                                    <div class="wa-template-block" data-template-block="meta,commercial,dialog360,custom" data-template-hide="alt">
                                        <label class="wa-form-field">
                                            <span>Access Token</span>
                                            <input type="password" name="access_token" placeholder="EAAG..." required>
                                        </label>
                                    </div>
                                    <div class="wa-template-block" data-template-block="meta,commercial,custom" data-template-hide="alt">
                                        <label class="wa-form-field">
                                            <span>Verify Token</span>
                                            <input type="text" name="verify_token" placeholder="token-webhook">
                                        </label>
                                    </div>
                                    <div class="wa-template-block" data-template-block="sandbox">
                                        <small class="wa-feedback" style="margin:4px 0 12px;">Sandbox não exige credenciais adicionais; use apenas para fluxo interno.</small>
                                    </div>
                                    <label class="wa-form-field" data-web-lock-field hidden>
                                        <span>Código de autorização (WhatsApp Web)</span>
                                        <input type="password" name="web_edit_code" autocomplete="off" placeholder="Informe o código para alterar linhas baseadas em WhatsApp Web" value="<?= htmlspecialchars((string)($webEditCode ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </label>
                                    <small class="wa-feedback" data-web-lock-hint hidden>
                                        Precisamos do código de autorização antes de salvar ajustes no gateway via WhatsApp Web.
                                        <?php if (!empty($webEditCode ?? '')): ?>
                                            Código já preenchido para você (admin). Guarde-o em local seguro.
                                        <?php endif; ?>
                                    </small>
                                    <div class="wa-alt-inline" data-line-alt-inline hidden>
                                        <p class="wa-empty" style="margin:0;">Associe esta linha a uma instância do gateway alternativo.</p>
                                        <label class="wa-form-field">
                                            <span>Instância do gateway</span>
                                            <select name="alt_gateway_instance" data-alt-gateway-select data-default-alt="<?= htmlspecialchars($defaultAltGatewaySlug, ENT_QUOTES, 'UTF-8'); ?>">
                                                <option value=""><?= empty($altGateways ?? []) ? 'Nenhuma instância configurada' : 'Selecione a instância vinculada'; ?></option>
                                                <?php foreach ($altGateways ?? [] as $gateway): ?>
                                                    <option value="<?= htmlspecialchars((string)$gateway['slug'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string)$gateway['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <?php if (empty($altGateways ?? [])): ?>
                                            <small class="wa-empty">Cadastre instâncias em <code>config/whatsapp_alt_gateways.php</code>.</small>
                                        <?php else: ?>
                                            <small class="wa-feedback" style="margin:0;">Use o painel abaixo para iniciar, gerar QR e puxar histórico.</small>
                                            <div class="wa-alt-inline-actions">
                                                <button type="button" class="ghost" data-alt-gateway-toggle>Mostrar painel do laboratório</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <label class="wa-form-field">
                                    <span>Status</span>
                                    <select name="status">
                                        <option value="active">Ativo</option>
                                        <option value="maintenance">Manutenção</option>
                                    </select>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="checkbox" name="is_default" value="1"> Definir como padrão
                                </label>
                                <div class="wa-form-field" style="margin-top:12px;">
                                    <strong style="font-size:0.95rem;">Limitador anti-banimento</strong>
                                    <small class="wa-feedback" style="margin:0;">Opcional: limite o volume por janela para seguir a política da Meta.</small>
                                </div>
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                                    <input type="checkbox" name="rate_limit_enabled" value="1">
                                    Ativar limitador por linha
                                </label>
                                <label class="wa-form-field" data-rate-limit-preset-wrapper>
                                    <span>Nível (conversas em 24h)</span>
                                    <select name="rate_limit_preset" data-rate-limit-preset data-presets='<?= htmlspecialchars(json_encode($rateLimitPresets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>
                                        <option value="">Personalizado (definir manualmente)</option>
                                        <?php foreach (($rateLimitPresets ?? []) as $presetKey => $preset): ?>
                                            <option value="<?= htmlspecialchars($presetKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string)$preset['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <small class="wa-feedback" data-rate-limit-hint>Selecione um nível Meta para preencher os campos automaticamente.</small>
                                <div class="wa-section-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); margin-bottom:12px;">
                                    <label class="wa-form-field">
                                        <span>Janela (segundos)</span>
                                        <input type="number" name="rate_limit_window_seconds" min="60" step="60" value="3600">
                                    </label>
                                    <label class="wa-form-field">
                                        <span>Máximo de mensagens</span>
                                        <input type="number" name="rate_limit_max_messages" min="1" step="1" value="500">
                                    </label>
                                </div>
                                <div class="wa-composer-actions" style="display:flex; justify-content:flex-end; gap:10px;">
                                    <button type="button" class="ghost" id="wa-line-reset">Limpar formulário</button>
                                    <button type="submit" class="primary">Salvar linha</button>
                                </div>
                                <small id="wa-line-feedback" class="wa-feedback"></small>
                                <?php if (!empty($altGateways ?? [])): ?>
                                    <div class="wa-alt-inline-panel" data-alt-gateway-container hidden>
                                        <header class="wa-card-header" style="margin-bottom:12px;">
                                            <div>
                                                <h4 style="margin:0;">Laboratório WhatsApp Web alternativo</h4>
                                                <p class="wa-empty" style="margin:4px 0 0;">Inicie ou monitore os gateways de teste.</p>
                                            </div>
                                        </header>
                                        <div class="wa-alt-grid">
                                            <?php foreach ($altGateways as $gateway): ?>
                                                <?php
                                                    $slug = (string)$gateway['slug'];
                                                    $label = (string)$gateway['label'];
                                                    $description = trim((string)($gateway['description'] ?? 'Instância alternativa'));
                                                ?>
                                                <article class="wa-alt-card" data-alt-gateway-card data-gateway-instance="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <header>
                                                        <div>
                                                            <h4><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h4>
                                                            <p class="wa-empty"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
                                                        </div>
                                                        <span class="wa-chip">Instância: <?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </header>
                                                    <strong data-alt-gateway-status>Carregando status...</strong>
                                                    <div class="wa-alt-actions">
                                                        <button type="button" class="ghost wa-gateway-toggle" data-alt-gateway-start>Iniciar gateway</button>
                                                        <button type="button" class="ghost" data-alt-gateway-refresh>Atualizar</button>
                                                        <button type="button" class="ghost" data-alt-gateway-qr>Mostrar QR</button>
                                                        <button type="button" class="ghost wa-gateway-clean" data-alt-gateway-clean>Limpar sessão / QR</button>
                                                    </div>
                                                    <small class="wa-feedback" data-alt-gateway-feedback></small>
                                                    <ul class="wa-alt-meta">
                                                        <li><span>Sessão</span><strong data-alt-gateway-session>--</strong></li>
                                                        <li><span>Última mensagem</span><strong data-alt-gateway-incoming>--</strong></li>
                                                        <li><span>Heartbeat</span><strong data-alt-gateway-heartbeat>--</strong></li>
                                                        <li><span>Histórico</span><strong data-alt-gateway-history>--</strong></li>
                                                    </ul>
                                                    <div class="wa-alt-qr" data-alt-gateway-qr-panel hidden>
                                                        <p class="wa-empty" data-alt-gateway-qr-placeholder>Escaneie com o número de testes desta instância.</p>
                                                        <img data-alt-gateway-qr-image src="" alt="QR Code WhatsApp Web">
                                                        <div class="wa-alt-qr-actions">
                                                            <button type="button" class="ghost" data-alt-gateway-qr-popup disabled>Abrir em nova aba</button>
                                                        </div>
                                                        <small class="wa-feedback" data-alt-gateway-qr-meta></small>
                                                    </div>
                                                    <form class="wa-message-form" data-alt-history-form style="margin-top:16px;">
                                                        <input type="hidden" name="instance" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="history_lookback" value="">
                                                        <p class="wa-empty" style="margin-bottom:10px;">Precisa resgatar mensagens antigas desta instância? Escolha uma estratégia e ajuste os filtros.</p>
                                                        <div class="wa-alt-history-mode">
                                                            <label>
                                                                <input type="radio" name="history_mode" value="all" checked>
                                                                <span>Todas as mensagens disponíveis</span>
                                                            </label>
                                                            <label>
                                                                <input type="radio" name="history_mode" value="range">
                                                                <span>Intervalo específico (data/hora)</span>
                                                            </label>
                                                            <label>
                                                                <input type="radio" name="history_mode" value="hours">
                                                                <span>Últimas X horas</span>
                                                            </label>
                                                        </div>
                                                        <div class="wa-alt-history-grid" data-history-range hidden>
                                                            <label class="wa-form-field">
                                                                <span>De</span>
                                                                <input type="datetime-local" name="history_from">
                                                            </label>
                                                            <label class="wa-form-field">
                                                                <span>Até</span>
                                                                <input type="datetime-local" name="history_to">
                                                            </label>
                                                        </div>
                                                        <div class="wa-alt-history-grid" data-history-hours hidden>
                                                            <label class="wa-form-field">
                                                                <span>Últimas horas</span>
                                                                <input type="number" name="history_hours" min="1" max="168" placeholder="24">
                                                            </label>
                                                        </div>
                                                        <div class="wa-alt-history-controls">
                                                            <label class="wa-form-field">
                                                                <span>Máximo de conversas</span>
                                                                <input type="number" name="history_max_chats" min="1" placeholder="150">
                                                                <small class="wa-feedback">Deixe em branco para buscar todas.</small>
                                                            </label>
                                                            <label class="wa-form-field">
                                                                <span>Máximo de mensagens por conversa</span>
                                                                <input type="number" name="history_max_messages" min="1" placeholder="500">
                                                                <small class="wa-feedback">Deixe em branco para não limitar.</small>
                                                            </label>
                                                        </div>
                                                        <button type="submit" class="ghost">Carregar histórico</button>
                                                        <small class="wa-feedback" data-alt-history-feedback></small>
                                                    </form>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </article>
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start; gap:12px;">
                                <div>
                                    <h3>Linhas registradas</h3>
                                    <p>Monitore status, limitador e gateways vinculados.</p>
                                </div>
                                <button type="button" class="ghost" onclick="window.location.reload();">Atualizar listas</button>
                            </div>
                            <?php if ($lines === []): ?>
                                <p class="wa-empty">Nenhuma linha cadastrada.</p>
                            <?php else: ?>
                                <ul class="wa-line-list">
                                    <?php foreach ($lines as $line): ?>
                                        <?php
                                            $altSlug = trim((string)($line['alt_gateway_instance'] ?? ''));
                                            $altInstance = $altSlug !== '' ? ($altGatewayMap[$altSlug] ?? null) : null;
                                            $gatewayStatus = $status['alt_gateway_status'][$altSlug] ?? [];
                                            $gatewayReachable = !empty($gatewayStatus) && !empty($gatewayStatus['ok']);
                                            $statusHint = strtolower((string)($gatewayStatus['status'] ?? ''));
                                            $looksReady = str_contains($statusHint, 'open')
                                                || str_contains($statusHint, 'online')
                                                || str_contains($statusHint, 'connected')
                                                || str_contains($statusHint, 'ready');
                                            $gatewayReady = $gatewayReachable && (!empty($gatewayStatus['ready']) || $looksReady);
                                            $gatewayLabel = $altInstance['label'] ?? 'Gateway alternativo';
                                            $statusMap = [
                                                'qr' => 'aguardando QR',
                                                'pairing' => 'pareando',
                                                'opening' => 'abrindo sessão',
                                                'open' => 'online',
                                                'connected' => 'online',
                                                'synced' => 'online',
                                                'disconnectedmobile' => 'telefone offline',
                                            ];
                                            $gatewayText = 'offline';
                                            $gatewayClass = 'is-offline';
                                            if ($gatewayReady) {
                                                $gatewayClass = 'is-online';
                                                $gatewayText = $statusMap[$statusHint] ?? 'online';
                                            } elseif ($gatewayReachable) {
                                                $gatewayClass = 'is-warning';
                                                $gatewayText = $statusMap[$statusHint] ?? 'em pareamento';
                                            }
                                        ?>
                                        <li class="wa-line">
                                            <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                                                <strong><?= htmlspecialchars((string)$line['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <?php if ((int)($line['is_default'] ?? 0) === 1): ?>
                                                    <span class="wa-chip wa-chip--queue">Padrão</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="wa-empty" style="display:block; margin-top:4px;">
                                                <?= htmlspecialchars((string)$line['display_phone'], ENT_QUOTES, 'UTF-8'); ?> · Modelo: <?= htmlspecialchars((string)$line['provider'], ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                            <div class="wa-line-gateway-row">
                                                <div class="wa-line-gateway-inline" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                                    <span class="wa-line-gateway <?= (int)($line['rate_limit_enabled'] ?? 0) === 1 ? 'is-online' : ''; ?>">
                                                        Limitador <?= (int)($line['rate_limit_enabled'] ?? 0) === 1 ? 'ativado' : 'desativado'; ?>
                                                    </span>
                                                    <?php if ($altInstance !== null): ?>
                                                        <div
                                                            class="wa-line-gateway-inline"
                                                            data-line-gateway
                                                            data-gateway-instance="<?= htmlspecialchars($altSlug, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-line-label="<?= htmlspecialchars((string)$line['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-line-phone="<?= htmlspecialchars((string)$line['display_phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <span class="wa-line-gateway <?= $gatewayClass; ?>" data-line-gateway-status>
                                                                Gateway: <?= htmlspecialchars($gatewayLabel, ENT_QUOTES, 'UTF-8'); ?> (<?= htmlspecialchars($gatewayText, ENT_QUOTES, 'UTF-8'); ?>)
                                                            </span>
                                                            <div class="wa-line-actions">
                                                                <button type="button" class="ghost" data-line-gateway-qr>Novo QR</button>
                                                                <button type="button" class="ghost" data-line-gateway-qr-history>QR histórico</button>
                                                                <button type="button" class="ghost wa-gateway-toggle" data-line-gateway-start>
                                                                    Iniciar gateway
                                                                </button>
                                                                <button type="button" class="ghost wa-gateway-toggle" data-line-gateway-stop>Parar gateway</button>
                                                            </div>
                                                        </div>
                                                    <?php elseif (!empty($altGateways ?? [])): ?>
                                                        <small class="wa-empty" style="max-width:320px;">Associe esta linha a um gateway alternativo para liberar os botões.</small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php
                                                    $linePayload = [
                                                        'id' => (int)$line['id'],
                                                        'label' => (string)$line['label'],
                                                        'display_phone' => (string)$line['display_phone'],
                                                        'api_template' => (string)($line['api_template'] ?? 'meta'),
                                                        'provider' => (string)$line['provider'],
                                                        'api_base_url' => (string)$line['api_base_url'],
                                                        'phone_number_id' => (string)$line['phone_number_id'],
                                                        'business_account_id' => (string)$line['business_account_id'],
                                                        'access_token' => (string)$line['access_token'],
                                                        'verify_token' => (string)$line['verify_token'],
                                                        'status' => (string)$line['status'],
                                                        'is_default' => (int)$line['is_default'],
                                                        'rate_limit_enabled' => (int)($line['rate_limit_enabled'] ?? 0),
                                                        'rate_limit_window_seconds' => (int)($line['rate_limit_window_seconds'] ?? 3600),
                                                        'rate_limit_max_messages' => (int)($line['rate_limit_max_messages'] ?? 500),
                                                        'alt_gateway_instance' => (string)($line['alt_gateway_instance'] ?? ''),
                                                        'rate_limit_preset' => (string)($line['rate_limit_preset'] ?? ''),
                                                    ];
                                                    $linePayloadJson = htmlspecialchars(json_encode($linePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                                ?>
                                                <div class="wa-line-actions">
                                                    <button type="button" class="ghost" data-line-edit='<?= $linePayloadJson; ?>'>Editar</button>
                                                    <button type="button" class="ghost" data-line-delete="<?= (int)$line['id']; ?>" data-line-delete-payload='<?= $linePayloadJson; ?>'>Remover</button>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    </div>
                <?php elseif ($sectionKey === 'sandbox'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Lab sandbox</h2>
                            <p>Injete mensagens para validar filas e triagens sem depender do gateway.</p>
                        </div>
                    </div>
                    <?php if ($sandboxLines === []): ?>
                        <p class="wa-empty">Cadastre uma linha com modelo "Sandbox" para habilitar este laboratório.</p>
                    <?php else: ?>
                        <article class="wa-admin-card">
                            <form id="wa-sandbox-form" class="wa-message-form">
                                <label class="wa-form-field">
                                    <span>Linha sandbox</span>
                                    <select name="line_id">
                                        <?php foreach ($sandboxLines as $sandboxLine): ?>
                                            <option value="<?= (int)$sandboxLine['id']; ?>"><?= htmlspecialchars((string)$sandboxLine['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="wa-form-field">
                                    <span>Telefone do contato</span>
                                    <input type="text" name="contact_phone" placeholder="11999887766">
                                </label>
                                <label class="wa-form-field">
                                    <span>Nome</span>
                                    <input type="text" name="contact_name" placeholder="Contato teste">
                                </label>
                                <label class="wa-form-field">
                                    <span>Mensagem</span>
                                    <textarea name="message" rows="2" placeholder="Texto recebido"></textarea>
                                </label>
                                <button class="primary" type="submit">Injetar mensagem</button>
                                <small id="wa-sandbox-feedback" class="wa-feedback"></small>
                            </form>
                        </article>
                    <?php endif; ?>
                <?php elseif ($sectionKey === 'broadcast'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Comunicados rápidos</h2>
                            <p>Dispare avisos operacionais respeitando o limitador e registre consentimentos.</p>
                        </div>
                        <button type="button" class="ghost" id="wa-broadcast-refresh">Atualizar histórico</button>
                    </div>
                    <div class="wa-broadcast-grid">
                        <form id="wa-broadcast-form" class="wa-message-form" novalidate>
                            <label class="wa-form-field">
                                <span>Título</span>
                                <input type="text" name="title" placeholder="Ex.: Aviso manutenção WhatsApp" required>
                            </label>
                            <label class="wa-form-field">
                                <span>Mensagem</span>
                                <textarea name="message" rows="3" placeholder="Texto enviado aos contatos selecionados"></textarea>
                                <small class="wa-feedback">Opcional: deixe vazio para disparar apenas um modelo de mídia.</small>
                            </label>
                            <div class="wa-form-field">
                                <span>Filas de destino</span>
                                <div class="wa-permission-grid wa-broadcast-queues">
                                    <?php foreach ($broadcastQueueLabels as $queueKey => $queueLabel): ?>
                                        <label style="border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:8px; display:flex; gap:6px; align-items:center;">
                                            <input type="checkbox" name="queues[]" value="<?= htmlspecialchars($queueKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $queueKey === 'arrival' ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($queueLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="wa-section-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">
                                <label class="wa-form-field">
                                    <span>Limite (conversas)</span>
                                    <input type="number" name="limit" min="1" max="500" value="120">
                                </label>
                                <label class="wa-form-field">
                                    <span>Modelo de mídia</span>
                                    <select name="template_kind" data-broadcast-template-kind <?= $mediaTemplates['stickers'] === [] && $mediaTemplates['documents'] === [] ? 'disabled' : ''; ?>>
                                        <option value="">Somente texto</option>
                                        <?php
                                            $templateCatalogLabels = [
                                                'stickers' => 'Figurinhas corporativas',
                                                'documents' => 'Documentos rápidos',
                                            ];
                                            $availableTemplateKinds = [];
                                            foreach ($mediaTemplates as $catalogKey => $entries) {
                                                if (!empty($entries)) {
                                                    $availableTemplateKinds[$catalogKey] = $templateCatalogLabels[$catalogKey] ?? ucfirst((string)$catalogKey);
                                                }
                                            }
                                        ?>
                                        <?php foreach ($availableTemplateKinds as $catalogKey => $catalogLabel): ?>
                                            <option value="<?= htmlspecialchars($catalogKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($catalogLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($availableTemplateKinds === []): ?>
                                        <small class="wa-feedback">Cadastre figurinhas/documentos em <code>config/whatsapp_templates.php</code>.</small>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <label class="wa-form-field">
                                <span>Modelo disponível</span>
                                <select name="template_key" data-broadcast-template-key disabled>
                                    <option value="">Selecione o modelo</option>
                                </select>
                            </label>
                            <div class="wa-template-preview" id="wa-broadcast-template-preview" hidden>
                                <strong data-broadcast-template-label></strong>
                                <p class="wa-empty" data-broadcast-template-description></p>
                                <img data-broadcast-template-image hidden alt="Pré-visualização do modelo">
                            </div>
                            <div class="wa-composer-actions" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost" data-broadcast-template-clear hidden>Remover modelo</button>
                                <button type="submit" class="primary">Enviar comunicado</button>
                            </div>
                            <small id="wa-broadcast-feedback" class="wa-feedback"></small>
                        </form>
                        <div class="wa-broadcast-history" id="wa-broadcast-history">
                            <p class="wa-empty wa-broadcast-empty">Nenhum comunicado enviado ainda.</p>
                        </div>
                    </div>
                    <?php elseif ($sectionKey === 'blocked'): ?>
                        <div class="wa-section-title">
                            <div>
                                <h2 style="margin:0;">Bloqueios de WhatsApp</h2>
                                <p>Defina a lista negativa que impede envio e leitura de mensagens.</p>
                            </div>
                            <span class="wa-chip wa-chip--danger"><?= count($blockedNumbersList); ?> número(s) bloqueado(s)</span>
                        </div>
                        <article class="wa-admin-card">
                            <p class="wa-empty">A lista usa somente números; separação por linha, vírgula ou ponto e vírgula.</p>
                            <form id="wa-blocked-form" class="wa-message-form">
                                <label class="wa-form-field">
                                    <span>Números bloqueados</span>
                                    <textarea name="blocked_numbers" rows="6" placeholder="5511999990000&#10;5511988887777" spellcheck="false"><?= htmlspecialchars(implode("\n", $blockedNumbersList), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <small class="wa-feedback">Mensagens para estes números não serão enviadas e novas mensagens serão descartadas.</small>
                                </label>
                                <div style="display:flex; gap:10px; justify-content:flex-end;">
                                    <button type="submit" class="primary">Salvar bloqueios</button>
                                </div>
                                <small id="wa-blocked-feedback" class="wa-feedback"></small>
                            </form>
                            <p class="wa-feedback" style="margin-top:10px;">Use o botão "Bloquear contato" dentro de uma conversa para preencher automaticamente aqui.</p>
                        </article>
                <?php elseif ($sectionKey === 'permissions'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Permissões e IA</h2>
                            <p>Controle quem acessa o módulo e habilite a chave do Copilot.</p>
                        </div>
                    </div>
                    <article class="wa-admin-card">
                        <h3>Permissões de acesso</h3>
                        <form id="wa-permission-form" class="wa-message-form">
                            <label class="wa-form-field">
                                <span>Usuários liberados</span>
                                <div class="wa-permission-grid">
                                    <?php foreach ($agentsDirectory as $agent): ?>
                                        <label style="border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:8px; display:flex; gap:6px; align-items:center;">
                                            <input type="checkbox" name="allowed_user_ids[]" value="<?= (int)$agent['id']; ?>" <?= in_array((int)$agent['id'], $allowedUserIds, true) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars((string)$agent['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="block_avp" value="1" <?= !empty($options['block_avp_access']) ? 'checked' : ''; ?>> Bloquear acesso de AVPs
                            </label>
                            <button type="submit" class="ghost">Salvar permissões</button>
                            <small id="wa-permission-feedback" class="wa-feedback"></small>
                        </form>
                        <?php if ($agentsDirectory === []): ?>
                            <p class="wa-empty" style="margin-top:14px;">Nenhum colaborador ativo para configurar permissões.</p>
                        <?php else: ?>
                            <p class="wa-feedback" style="margin:16px 0 0;">Defina painel por painel o que cada usuário pode visualizar.</p>
                            <div class="wa-permission-cards">
                                <?php foreach ($agentsDirectory as $agent):
                                    $userId = (int)($agent['id'] ?? 0);
                                    if ($userId <= 0) {
                                        continue;
                                    }
                                    $permission = $agentPermissions[$userId] ?? [
                                        'panel_scope' => [],
                                        'can_forward' => true,
                                        'can_start_thread' => true,
                                        'can_view_completed' => true,
                                        'can_grant_permissions' => false,
                                    ];
                                    $panelScope = is_array($permission['panel_scope'] ?? null) ? $permission['panel_scope'] : [];
                                    $roleLabel = ucfirst((string)($agent['role'] ?? 'Usuário'));
                                ?>
                                    <?php
                                        $chatDisplayName = trim((string)($agent['chat_display_name'] ?? ''));
                                        $publicAgentName = $chatDisplayName !== '' ? $chatDisplayName : (string)$agent['name'];
                                        $internalAgentName = $chatDisplayName !== '' ? (string)$agent['name'] : '';
                                    ?>
                                    <div class="wa-user-permission" data-permission-card data-user-id="<?= $userId; ?>">
                                        <header>
                                            <div>
                                                <strong><?= htmlspecialchars($publicAgentName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                                <?php if ($internalAgentName !== ''): ?>
                                                    <small class="wa-chip" style="margin-top:4px;">Interno: <?= htmlspecialchars($internalAgentName, ENT_QUOTES, 'UTF-8'); ?></small><br>
                                                <?php endif; ?>
                                                <small style="color:var(--muted);"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                        </header>
                                        <label class="wa-form-field">
                                            <span>Nome exibido ao cliente</span>
                                            <input type="text"
                                                data-display-name
                                                maxlength="80"
                                                value="<?= htmlspecialchars($chatDisplayName, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="<?= htmlspecialchars($internalAgentName !== '' ? $internalAgentName : (string)$agent['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <small class="wa-feedback">Aparece nos painéis e dentro da conversa.</small>
                                        </label>
                                        <div class="wa-panel-grid">
                                            <?php foreach ($panelDefinitions as $panelKey => $panelDef):
                                                $panelState = $panelScope[$panelKey] ?? ['mode' => array_key_first($panelDef['options']), 'users' => []];
                                                $panelMode = (string)($panelState['mode'] ?? array_key_first($panelDef['options']));
                                                if (!array_key_exists($panelMode, $panelDef['options'])) {
                                                    $panelMode = array_key_first($panelDef['options']);
                                                }
                                                $panelUsers = array_map('intval', (array)($panelState['users'] ?? []));
                                            ?>
                                                <div>
                                                    <label class="wa-form-field">
                                                        <span><?= htmlspecialchars($panelDef['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <select data-panel-scope="<?= $panelKey; ?>">
                                                            <?php foreach ($panelDef['options'] as $value => $label): ?>
                                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= $panelMode === $value ? 'selected' : ''; ?>>
                                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="wa-feedback"><?= htmlspecialchars($panelDef['hint'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                    </label>
                                                    <?php if (!empty($panelDef['allow_users'])): ?>
                                                        <div class="wa-panel-users" data-panel-users="<?= $panelKey; ?>" <?= $panelMode === 'selected' ? '' : 'hidden'; ?>>
                                                            <?php foreach ($agentsDirectory as $target): ?>
                                                                <?php $targetId = (int)($target['id'] ?? 0); ?>
                                                                <?php if ($targetId <= 0 || $targetId === $userId) { continue; } ?>
                                                                <label class="wa-user-scope-option">
                                                                    <input type="checkbox" value="<?= $targetId; ?>" <?= in_array($targetId, $panelUsers, true) ? 'checked' : ''; ?>>
                                                                    <span><?= htmlspecialchars((string)$target['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="wa-permission-toggle-group">
                                            <label>
                                                <input type="checkbox" data-permission-field="can_forward" <?= !empty($permission['can_forward']) ? 'checked' : ''; ?>>
                                                Direcionar mensagens
                                            </label>
                                            <label>
                                                <input type="checkbox" data-permission-field="can_start_thread" <?= !empty($permission['can_start_thread']) ? 'checked' : ''; ?>>
                                                Iniciar novas conversas
                                            </label>
                                            <label>
                                                <input type="checkbox" data-permission-field="can_view_completed" <?= !empty($permission['can_view_completed']) ? 'checked' : ''; ?>>
                                                Ver histórico concluído
                                            </label>
                                            <label>
                                                <input type="checkbox" data-permission-field="can_grant_permissions" <?= !empty($permission['can_grant_permissions']) ? 'checked' : ''; ?>>
                                                Ajustar permissões de outros
                                            </label>
                                        </div>
                                        <div class="wa-permission-actions">
                                            <small data-permission-status></small>
                                            <button type="button" class="ghost" data-permission-save>Salvar usuário</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <form id="wa-copilot-form" class="wa-message-form" style="margin-top:14px;">
                        <label class="wa-form-field">
                            <span>Copilot API Key</span>
                            <input type="password" name="copilot_api_key" value="<?= htmlspecialchars((string)($options['copilot_api_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="sk-live...">
                        </label>
                            <button type="submit" class="primary">Salvar chave</button>
                            <small id="wa-copilot-feedback" class="wa-feedback"></small>
                        </form>
                    </article>
                <?php elseif ($sectionKey === 'knowledge'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Base de conhecimento IA</h2>
                            <p>Envie manuais e acompanhe as últimas amostras usadas pelo Copilot.</p>
                        </div>
                    </div>
                    <article class="wa-admin-card">
                        <p class="wa-empty">Manuais ativos: <strong><?= (int)$knowledge['manuals']; ?></strong> · Exemplos registrados: <strong><?= (int)$knowledge['samples']; ?></strong></p>
                        <?php if ($manuals === []): ?>
                            <p class="wa-empty">Nenhum manual enviado ainda.</p>
                        <?php else: ?>
                            <ul class="wa-line-list">
                                <?php foreach ($manuals as $manual): ?>
                                    <li class="wa-line">
                                        <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                                            <strong><?= htmlspecialchars((string)$manual['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="wa-chip"><?= date('d/m/y H:i', (int)($manual['created_at'] ?? 0)); ?></span>
                                        </div>
                                        <?php if (!empty($manual['description'])): ?>
                                            <small class="wa-empty"><?= htmlspecialchars((string)$manual['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                        <small class="wa-empty">
                                            <?= sprintf('Tamanho: %.1f KB', ((int)($manual['size_bytes'] ?? 0)) / 1024); ?>
                                        </small>
                                        <?php if (!empty($manual['content_preview'])): ?>
                                            <p style="font-size:0.85rem; color:var(--muted); margin-top:6px;">
                                                <?= htmlspecialchars(mb_substr((string)$manual['content_preview'], 0, 160), ENT_QUOTES, 'UTF-8'); ?>...
                                            </p>
                                        <?php endif; ?>
                                        <div class="wa-line-actions">
                                            <a class="ghost" href="<?= url('whatsapp/copilot-manuals/' . (int)$manual['id'] . '/download'); ?>" target="_blank" rel="noopener">Download</a>
                                            <button type="button" class="ghost" data-manual-delete="<?= (int)$manual['id']; ?>">Excluir</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($trainingSamples !== []): ?>
                            <div style="margin-top:12px;">
                                <strong style="font-size:0.9rem;">Últimas amostras</strong>
                                <ul class="wa-line-list" style="margin-top:6px;">
                                    <?php foreach ($trainingSamples as $sample): ?>
                                        <li class="wa-line" style="padding:10px; border-style:dashed;">
                                            <div style="display:flex; justify-content:space-between;">
                                                <span class="wa-chip"><?= htmlspecialchars(ucfirst((string)$sample['category']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <small class="wa-empty"><?= date('d/m H:i', (int)($sample['created_at'] ?? 0)); ?></small>
                                            </div>
                                            <p style="font-size:0.85rem; color:var(--muted); margin:6px 0 0;">
                                                <?= htmlspecialchars(mb_substr((string)($sample['summary'] ?? ''), 0, 180), ENT_QUOTES, 'UTF-8'); ?>...
                                            </p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <form id="wa-manual-form" class="wa-message-form" style="margin-top:14px;" enctype="multipart/form-data">
                            <label class="wa-form-field">
                                <span>Título do manual</span>
                                <input type="text" name="manual_title" placeholder="Ex.: Script suporte fiscal">
                            </label>
                            <label class="wa-form-field">
                                <span>Descrição</span>
                                <textarea name="manual_description" rows="2" placeholder="Resumo para identificar o conteúdo."></textarea>
                            </label>
                            <label class="wa-form-field">
                                <span>Arquivo (.txt ou .md)</span>
                                <input type="file" name="manual_file" accept=".txt,.md,.markdown" required>
                            </label>
                            <button type="submit" class="ghost">Enviar manual</button>
                            <small id="wa-manual-feedback" class="wa-feedback"></small>
                        </form>
                    </article>
                <?php elseif ($sectionKey === 'profiles'): ?>
                    <div class="wa-section-title">
                        <div>
                            <h2 style="margin:0;">Perfis do Copilot</h2>
                            <p>Crie agentes especializados para qualificar leads, fazer suporte ou conduzir vendas.</p>
                        </div>
                    </div>
                    <article class="wa-admin-card">
                        <?php if ($copilotProfiles === []): ?>
                            <p class="wa-empty">Nenhum perfil cadastrado.</p>
                        <?php else: ?>
                            <ul class="wa-line-list">
                                <?php foreach ($copilotProfiles as $profile): ?>
                                    <li class="wa-line">
                                        <div style="flex:1;">
                                            <strong><?= htmlspecialchars((string)$profile['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if (!empty($profile['objective'])): ?>
                                                <span class="wa-chip"><?= htmlspecialchars((string)$profile['objective'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($profile['default_queue'])): ?>
                                                <span class="wa-chip">Fila: <?= htmlspecialchars($queueLabels[(string)$profile['default_queue']] ?? ucfirst((string)$profile['default_queue']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($profile['is_default'])): ?>
                                                <span class="wa-chip wa-chip--queue">Padrão</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <button type="button" class="ghost" data-copilot-profile-edit='<?= htmlspecialchars(json_encode([
                                                'id' => (int)$profile['id'],
                                                'name' => (string)$profile['name'],
                                                'objective' => (string)($profile['objective'] ?? ''),
                                                'description' => (string)($profile['description'] ?? ''),
                                                'tone' => (string)($profile['tone'] ?? ''),
                                                'temperature' => (float)($profile['temperature'] ?? 0.5),
                                                'default_queue' => (string)($profile['default_queue'] ?? ''),
                                                'instructions' => (string)($profile['instructions'] ?? ''),
                                                'is_default' => (int)($profile['is_default'] ?? 0),
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>Editar</button>
                                            <button type="button" class="ghost" data-copilot-profile-delete="<?= (int)$profile['id']; ?>">Remover</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <form id="wa-copilot-profile-form" class="wa-message-form" style="margin-top:14px;">
                            <input type="hidden" name="profile_id" value="">
                            <label class="wa-form-field">
                                <span>Nome do agente</span>
                                <input type="text" name="name" placeholder="Ex.: Qualificação de Leads">
                            </label>
                            <label class="wa-form-field">
                                <span>Objetivo</span>
                                <input type="text" name="objective" placeholder="Ex.: Validar se o contato tem interesse real">
                            </label>
                            <label class="wa-form-field">
                                <span>Descrição</span>
                                <textarea name="description" rows="2" placeholder="Resumo visível apenas no painel."></textarea>
                            </label>
                            <label class="wa-form-field">
                                <span>Tom</span>
                                <select name="tone">
                                    <option value="consultivo">Consultivo</option>
                                    <option value="formal">Formal</option>
                                    <option value="direto">Direto</option>
                                    <option value="acolhedor">Acolhedor</option>
                                </select>
                            </label>
                            <label class="wa-form-field">
                                <span>Fila preferida</span>
                                <select name="default_queue">
                                    <option value="">Nenhuma</option>
                                    <?php foreach ($queueLabels as $queueKey => $queueLabel): ?>
                                        <option value="<?= htmlspecialchars($queueKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($queueLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="wa-form-field">
                                <span>Temperatura (0-1)</span>
                                <input type="number" name="temperature" step="0.1" min="0" max="1" value="0.5">
                            </label>
                            <label class="wa-form-field">
                                <span>Instruções específicas</span>
                                <textarea name="instructions" rows="3" placeholder="Ex.: Faça perguntas fechadas e registre tags 'lead quente' ou 'lead frio'."></textarea>
                            </label>
                            <label class="wa-checkbox" style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="is_default" value="1">
                                <span>Definir como padrão</span>
                            </label>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                                <button type="submit" class="primary">Salvar perfil</button>
                                <button type="button" class="ghost" id="wa-copilot-profile-reset">Novo perfil</button>
                            </div>
                            <small id="wa-copilot-profile-feedback" class="wa-feedback"></small>
                        </form>
                    </article>
                <?php elseif ($sectionKey === 'backup'): ?>
                    <div class="wa-section-grid">
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start;">
                                <div>
                                    <h3>Gerar backup completo</h3>
                                    <p>Exporta contatos, conversas, mensagens e mídias para migrar entre versões.</p>
                                </div>
                            </div>
                            <form method="post" action="<?= url('whatsapp/backup/export'); ?>" style="display:flex; flex-direction:column; gap:12px;">
                                <?= csrf_field(); ?>
                                <p class="wa-empty">
                                    O arquivo ZIP inclui todas as tabelas do módulo de WhatsApp e a pasta <code>storage/whatsapp-media</code>.
                                    Gere um backup antes de atualizar o sistema ou mover dados entre ambientes.
                                </p>
                                <button type="submit" class="primary" style="align-self:flex-start;">Baixar backup ZIP</button>
                                <small class="wa-feedback">Dependendo da quantidade de mídias o download pode levar alguns minutos.</small>
                            </form>
                        </article>
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start;">
                                <div>
                                    <h3>Restaurar backup</h3>
                                    <p>Substitui completamente as conversas atuais pelo arquivo selecionado.</p>
                                </div>
                            </div>
                            <form id="wa-backup-restore-form" class="wa-message-form" method="post" action="<?= url('whatsapp/backup/import'); ?>" enctype="multipart/form-data">
                                <?= csrf_field(); ?>
                                <label class="wa-form-field">
                                    <span>Arquivo do backup (.zip)</span>
                                    <input type="file" name="backup_file" accept=".zip,application/zip" required>
                                </label>
                                <p class="wa-empty" style="margin-top:-4px;">
                                    Atenção: a restauração apaga os registros atuais de WhatsApp e substitui a pasta de mídias.
                                    Execute apenas após gerar um backup local.
                                </p>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="submit" class="ghost danger">Restaurar backup</button>
                                </div>
                                <small id="wa-backup-restore-feedback" class="wa-feedback"></small>
                            </form>
                        </article>
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start;">
                                <div>
                                    <h3>Restaurar backup do gateway</h3>
                                    <p>Reprocessa mensagens brutas (entrada/saída) capturadas no gateway alternativo antes do CRM.</p>
                                </div>
                            </div>
                            <form id="wa-gateway-backup-restore-form" class="wa-message-form" method="post" action="<?= url('whatsapp/alt/gateway-backup/import'); ?>" enctype="multipart/form-data">
                                <?= csrf_field(); ?>
                                <label class="wa-form-field">
                                    <span>Arquivo do gateway (.tar.gz, .tar ou .zip)</span>
                                    <input type="file" name="gateway_backup_file" accept=".tar,.gz,.tar.gz,.zip,application/gzip,application/x-gzip,application/x-tar,application/zip" required>
                                </label>
                                <p class="wa-empty" style="margin-top:-4px;">
                                    A restauração não apaga conversas atuais: apenas insere mensagens que ainda não existem (usa dedupe por meta_message_id).
                                </p>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="submit" class="ghost">Restaurar backup do gateway</button>
                                </div>
                                <small id="wa-gateway-backup-feedback" class="wa-feedback"></small>
                            </form>
                        </article>
                        <article class="wa-admin-card">
                            <div class="wa-card-header" style="align-items:flex-start;">
                                <div>
                                    <h3>Monitor do backup do gateway</h3>
                                    <p>Mostra o volume de mensagens do dia (entrada/saída) registradas no backup por linha/gateway.</p>
                                </div>
                                <span class="wa-chip" id="wa-gateway-backup-refresh">Atualizar</span>
                            </div>
                            <div id="wa-gateway-backup-summary" class="wa-summary-table">
                                <p class="wa-empty">Carregando...</p>
                            </div>
                            <small class="wa-feedback" id="wa-gateway-backup-summary-meta"></small>
                        </article>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</section>
<script>
(function() {
    const toggleBtn = document.querySelector('[data-maintenance-toggle]');
    const runBtn = document.querySelector('[data-maintenance-run-now]');
    const statusEl = document.querySelector('[data-maintenance-status]');
    const STORAGE_KEY = 'wa_maintenance_standby_enabled';
    const WINDOW_START = 22; // 22h
    const WINDOW_END = 5;   // 05h

    function isWithinWindow() {
        const now = new Date();
        const hour = now.getHours();
        return hour >= WINDOW_START || hour < WINDOW_END;
    }

    function setStatus(text, tone = 'muted') {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.style.color = tone === 'ok' ? '#1a7f37' : (tone === 'warn' ? '#c26c0a' : 'var(--muted)');
    }

    async function triggerMaintenance(manual = false) {
        setStatus(manual ? 'Executando manutenção...' : 'Executando manutenção agendada...', 'warn');
        try {
            const resp = await fetch('<?= htmlspecialchars(url('whatsapp-run-maintenance'), ENT_QUOTES, 'UTF-8'); ?>', {
                method: 'POST',
                headers: {'Accept': 'application/json'}
            });
            const data = await resp.json();
            if (resp.ok && data.ok) {
                setStatus('Manutenção concluída às ' + new Date().toLocaleTimeString(), 'ok');
            } else {
                setStatus('Falhou: ' + (data.error || resp.status), 'warn');
            }
        } catch (err) {
            setStatus('Erro ao chamar manutenção', 'warn');
        }
    }

    function syncToggleLabel(active) {
        if (!toggleBtn) return;
        toggleBtn.textContent = active ? 'Standby ativo (22h-05h)' : 'Ativar espera 22h-05h';
    }

    function scheduleLoop() {
        const active = localStorage.getItem(STORAGE_KEY) === '1';
        syncToggleLabel(active);
        if (!active) {
            setStatus('Pronto para agendar na janela 22h-05h.');
            return;
        }
        if (isWithinWindow()) {
            triggerMaintenance(false);
            // desarma para não rodar múltiplas vezes na mesma janela
            localStorage.setItem(STORAGE_KEY, '0');
            syncToggleLabel(false);
        } else {
            setStatus('Standby ativo, aguardando 22h-05h...', 'ok');
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const current = localStorage.getItem(STORAGE_KEY) === '1';
            const next = current ? '0' : '1';
            localStorage.setItem(STORAGE_KEY, next);
            syncToggleLabel(next === '1');
            setStatus(next === '1' ? 'Standby ativo, aguardando 22h-05h...' : 'Standby desligado.');
        });
    }

    if (runBtn) {
        runBtn.addEventListener('click', () => triggerMaintenance(true));
    }

    // Tick on load and every 10 minutes
    scheduleLoop();
    setInterval(scheduleLoop, 10 * 60 * 1000);
})();
</script>
<div class="wa-qr-modal" data-line-qr-modal hidden>
    <div class="wa-qr-modal__overlay" data-line-qr-close></div>
    <div class="wa-qr-modal__card" role="dialog" aria-modal="true">
        <div class="wa-qr-modal__header">
            <div>
                <span class="wa-chip" data-line-qr-mode>Reconexão rápida</span>
                <h3 style="margin:8px 0 4px;" data-line-qr-title>QR do gateway</h3>
                <p class="wa-empty" data-line-qr-description></p>
            </div>
            <button type="button" class="ghost wa-qr-modal__close" data-line-qr-close>&times;</button>
        </div>
        <p class="wa-empty" style="margin:-4px 0 6px;" data-line-qr-helper hidden></p>
        <div class="wa-qr-modal__image">
            <div class="wa-qr-placeholder" data-line-qr-placeholder>Gerando QR...</div>
            <img data-line-qr-image src="" alt="QR Code WhatsApp Web" hidden>
        </div>
        <small class="wa-feedback" data-line-qr-meta></small>
        <div class="wa-qr-modal__actions">
            <button type="button" class="ghost" data-line-qr-refresh>Atualizar QR</button>
            <button type="button" class="ghost danger" data-line-qr-close>Fechar</button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.SafeGreenWhatsApp = window.SafeGreenWhatsApp || {};
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';

    const guideWrapper = document.querySelector('[data-line-guide-wrapper]');
    const guideContent = document.querySelector('[data-line-guide-content]');
    const guideSummary = document.querySelector('[data-line-guide-summary]');
    const guidePrintBtn = null; // substituído por link direto para PDF
    const guideOpenBtn = document.querySelector('[data-line-guide-open]');
    const guideToggleBtn = document.querySelector('[data-line-guide-toggle]');

    function setGuideExpanded(expanded) {
        if (guideContent) guideContent.hidden = !expanded;
        if (guideSummary) guideSummary.hidden = expanded;
        if (guideToggleBtn) {
            guideToggleBtn.textContent = expanded ? 'Ocultar passo a passo' : 'Ver passo a passo';
            guideToggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    if (guideToggleBtn) {
        setGuideExpanded(false);
        guideToggleBtn.addEventListener('click', () => {
            const expanded = guideToggleBtn.getAttribute('aria-expanded') === 'true';
            setGuideExpanded(!expanded);
        });
    }

    function buildGuideHtml() {
        if (!guideContent) return '';
        const guideHtml = guideContent.innerHTML;
        return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Guia de configuração do WhatsApp</title>
<style>
body { font-family: Arial, sans-serif; margin:24px; color:#0f172a; background:#f8fafc; }
h1 { margin:0 0 6px; font-size:22px; }
p.lead { margin:0 0 14px; color:#334155; }
.guide-grid, .wa-guide-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
.guide-card, .wa-guide-card { border:1px dashed #cbd5e1; border-radius:12px; padding:12px; background:#fff; box-shadow:0 6px 20px rgba(15,23,42,0.08); }
.guide-badge, .wa-guide-badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:4px 10px; border-radius:999px; border:1px solid #94a3b8; color:#0f172a; background:#e0f2fe; }
.guide-card strong, .wa-guide-card strong { display:block; margin:6px 0; font-size:16px; }
.guide-list, .wa-guide-list { margin:0; padding-left:16px; color:#334155; font-size:14px; }
.guide-list li, .wa-guide-list li { margin-bottom:6px; }
.guide-card a, .wa-guide-card a { color:#0b4f9c; text-decoration:underline; }
@media print {
    a[href] { color:#0b4f9c; text-decoration:underline; }
    a[href]:after { content:' (' attr(href) ')'; font-size:12px; color:#0f172a; }
}
footer { margin-top:14px; color:#334155; font-size:13px; }
</style>
</head>
<body>
    <h1>Guia de configuração do WhatsApp</h1>
    <p class="lead">Passo a passo resumido para gerar tokens, registrar o webhook e cadastrar a linha no sistema.</p>
    <div class="guide-grid">${guideHtml}</div>
    <footer>Gerado em ${new Date().toLocaleString('pt-BR')} · Baixe e compartilhe.</footer>
</body>
</html>`;
    }

    function openGuideWindow(mode = 'view') {
        const html = buildGuideHtml();
        if (!html) return;
        const guideWindow = window.open('', '_blank', 'width=1024,height=768');
        if (!guideWindow) {
            alert('Permita pop-ups para visualizar o guia.');
            return;
        }
        guideWindow.document.open();
        guideWindow.document.write(html);
        guideWindow.document.close();
    }

    if (guideOpenBtn && guideContent) {
        guideOpenBtn.addEventListener('click', () => openGuideWindow('view'));
    }

    // Lista de bloqueios
    const blockedForm = document.getElementById('wa-blocked-form');
    const blockedFeedback = document.getElementById('wa-blocked-feedback');
    if (blockedForm) {
        blockedForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const textarea = blockedForm.querySelector('textarea[name="blocked_numbers"]');
            if (!textarea) {
                return;
            }
            if (blockedFeedback) {
                blockedFeedback.textContent = 'Salvando lista...';
            }
            const payload = new URLSearchParams();
            payload.set('blocked_numbers', textarea.value || '');
            try {
                const response = await fetch('<?= url('whatsapp/blocked-numbers'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: payload.toString(),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.error || 'Falha ao salvar bloqueios.');
                }
                const list = Array.isArray(data.blocked_numbers) ? data.blocked_numbers : [];
                textarea.value = list.join('\n');
                if (blockedFeedback) {
                    blockedFeedback.textContent = 'Lista atualizada (' + list.length + ').';
                }
            } catch (error) {
                if (blockedFeedback) {
                    blockedFeedback.textContent = error && error.message ? error.message : 'Erro ao salvar bloqueios.';
                }
            }
        });
    }

    // Formulário de linha (novo/editar/migrar)
    const lineForm = document.getElementById('wa-line-form');
    const lineModeInput = lineForm ? lineForm.querySelector('[data-line-mode]') : null;
    const lineModePicker = lineForm ? lineForm.querySelector('[data-line-picker]') : null;
    const lineModeSections = lineForm ? Array.from(lineForm.querySelectorAll('[data-line-mode-section]')) : [];
    const lineModeVisibility = lineForm ? Array.from(lineForm.querySelectorAll('[data-line-mode-visibility]')) : [];
    const lineTemplateField = lineForm ? lineForm.querySelector('[data-line-template]') : null;
    const lineProviderField = lineForm ? lineForm.querySelector('select[name="provider"]') : null;
    const lineBaseField = lineForm ? lineForm.querySelector('input[name="api_base_url"]') : null;
    const linePhoneNumberField = lineForm ? lineForm.querySelector('input[name="phone_number_id"]') : null;
    const lineBusinessAccountField = lineForm ? lineForm.querySelector('input[name="business_account_id"]') : null;
    const lineAccessField = lineForm ? lineForm.querySelector('input[name="access_token"]') : null;
    const lineVerifyTokenField = lineForm ? lineForm.querySelector('input[name="verify_token"]') : null;
    const lineAltInline = lineForm ? lineForm.querySelector('[data-line-alt-inline]') : null;
    const lineAltSelect = lineForm ? lineForm.querySelector('[data-alt-gateway-select]') : null;
    const lineAltDefault = lineAltSelect ? (lineAltSelect.getAttribute('data-default-alt') || '') : '';
    const templateBlocks = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-block]')) : [];
    const templateHints = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-hint]')) : [];
    const templateHideNodes = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-hide]')) : [];
    const templateShowNodes = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-show]')) : [];
    const webLockField = lineForm ? lineForm.querySelector('[data-web-lock-field]') : null;
    const webLockHint = lineForm ? lineForm.querySelector('[data-web-lock-hint]') : null;
    const lineIdField = lineForm ? lineForm.querySelector('input[name="line_id"]') : null;
    const lineStatusField = lineForm ? lineForm.querySelector('select[name="status"]') : null;
    const lineDefaultField = lineForm ? lineForm.querySelector('input[name="is_default"]') : null;
    const lineLimitEnabledField = lineForm ? lineForm.querySelector('input[name="rate_limit_enabled"]') : null;
    const lineLimitWindowField = lineForm ? lineForm.querySelector('input[name="rate_limit_window_seconds"]') : null;
    const lineLimitMaxField = lineForm ? lineForm.querySelector('input[name="rate_limit_max_messages"]') : null;
    const rateLimitPresetField = lineForm ? lineForm.querySelector('[data-rate-limit-preset]') : null;
    const rateLimitHint = lineForm ? lineForm.querySelector('[data-rate-limit-hint]') : null;
    const lineFeedback = document.getElementById('wa-line-feedback');
    const lineReset = document.getElementById('wa-line-reset');
    const lineEditButtons = Array.from(document.querySelectorAll('[data-line-edit]'));
    const lineDeleteButtons = Array.from(document.querySelectorAll('[data-line-delete]'));
    let rateLimitPresets = {};
    if (rateLimitPresetField) {
        try {
            rateLimitPresets = JSON.parse(rateLimitPresetField.getAttribute('data-presets') || '{}') || {};
        } catch (error) {
            rateLimitPresets = {};
        }
    }

    const setVisible = (node, visible) => {
        if (!node) return;
        node.hidden = !visible;
        node.style.display = visible ? '' : 'none';
    };

    function syncLineModeUI() {
        if (!lineForm) return;
        const mode = lineModePicker ? (lineModePicker.value || 'new') : 'new';
        if (lineModeInput) {
            lineModeInput.value = mode;
        }
        lineModeSections.forEach((section) => {
            const targets = (section.dataset.lineModeSection || '').split(',').map((v) => v.trim()).filter(Boolean);
            setVisible(section, targets.includes(mode));
            section.querySelectorAll('input,select,textarea').forEach((control) => {
                const requiredFlag = control.dataset.modeRequired === '1';
                if (!targets.includes(mode)) {
                    if (control.required) control.dataset.modeRequired = '1';
                    control.required = false;
                } else if (requiredFlag) {
                    control.required = true;
                    control.dataset.modeRequired = '0';
                }
            });
        });
        lineModeVisibility.forEach((node) => {
            const targets = (node.dataset.lineModeVisibility || '').split(',').map((v) => v.trim()).filter(Boolean);
            const shouldShow = targets.length === 0 || targets.includes(mode);
            setVisible(node, shouldShow);
        });
    }

    function syncTemplateVisibility(template) {
        const normalized = (template || 'meta').toString();
        templateBlocks.forEach((block) => {
            const targets = (block.dataset.templateBlock || '').split(',').map((v) => v.trim()).filter(Boolean);
            const visible = targets.length === 0 || targets.includes(normalized);
            setVisible(block, visible);
            block.querySelectorAll('input,select,textarea').forEach((control) => {
                const had = control.dataset.templateRequired === '1';
                if (!visible) {
                    if (control.required) control.dataset.templateRequired = '1';
                    control.required = false;
                } else if (had) {
                    control.required = true;
                    control.dataset.templateRequired = '0';
                }
            });
        });
        templateHints.forEach((hint) => {
            const targets = (hint.dataset.templateHint || '').split(',').map((v) => v.trim()).filter(Boolean);
            const visible = targets.includes(normalized);
            setVisible(hint, visible);
        });
        templateHideNodes.forEach((node) => {
            const targets = (node.dataset.templateHide || '').split(',').map((v) => v.trim()).filter(Boolean);
            setVisible(node, !targets.includes(normalized));
        });
        templateShowNodes.forEach((node) => {
            const targets = (node.dataset.templateShow || '').split(',').map((v) => v.trim()).filter(Boolean);
            const shouldShow = targets.length === 0 || targets.includes(normalized);
            setVisible(node, shouldShow);
        });
    }

    function syncLineRequirements(template) {
        const normalized = template || 'meta';
        const isAlt = normalized === 'alt';
        const isSandbox = normalized === 'sandbox';
        const requireBusinessId = normalized === 'meta';
        const requireAccessToken = !(isAlt || isSandbox);
        const requirePhoneId = !(isAlt || isSandbox);

        if (lineBusinessAccountField) lineBusinessAccountField.required = requireBusinessId;
        if (lineAccessField) lineAccessField.required = requireAccessToken;
        if (linePhoneNumberField) linePhoneNumberField.required = requirePhoneId;
    }

    function syncTemplateDefaults(template, { userInitiated = false } = {}) {
        const normalized = template || 'meta';
        const presets = {
            meta: 'https://graph.facebook.com/v19.0',
            commercial: 'https://graph.facebook.com/v19.0',
            dialog360: 'https://waba.360dialog.io',
        };
        if (lineProviderField) {
            if (normalized === 'meta') lineProviderField.value = 'meta';
            else if (normalized === 'commercial') lineProviderField.value = 'commercial';
            else if (normalized === 'dialog360') lineProviderField.value = 'dialog360';
            else if (normalized === 'sandbox' || normalized === 'alt') lineProviderField.value = 'sandbox';
        }
        if (lineBaseField && userInitiated) {
            if (normalized === 'meta') lineBaseField.value = presets.meta;
            else if (normalized === 'commercial') lineBaseField.value = presets.commercial;
            else if (normalized === 'dialog360') lineBaseField.value = presets.dialog360;
            else lineBaseField.value = '';
        }
        if (normalized === 'alt' && lineAltSelect && lineAltSelect.value === '') {
            if (lineAltDefault && Array.from(lineAltSelect.options).some((opt) => opt.value === lineAltDefault)) {
                lineAltSelect.value = lineAltDefault;
            } else if (lineAltSelect.options.length > 1) {
                lineAltSelect.selectedIndex = 1;
            }
        }
        if (normalized !== 'alt' && lineAltSelect) {
            lineAltSelect.value = '';
        }
    }

    function syncAltInline(template) {
        const normalized = template || 'meta';
        const show = normalized === 'alt';
        setVisible(lineAltInline, show);
    }

    function syncWebLock(template) {
        const normalized = template || 'meta';
        const show = normalized === 'alt';
        setVisible(webLockField, show);
        setVisible(webLockHint, show);
    }

    function applyTemplateChange(options = {}) {
        if (!lineTemplateField) return;
        const template = lineTemplateField.value || 'meta';
        syncTemplateDefaults(template, options);
        syncTemplateVisibility(template);
        syncLineRequirements(template);
        syncAltInline(template);
        syncWebLock(template);
    }

    if (lineForm && !lineForm.dataset.webLockOrigin) {
        lineForm.dataset.webLockOrigin = '0';
    }

    const toParams = (payload) => Object.entries(payload)
        .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value == null ? '' : value)}`)
        .join('&');

    async function postForm(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: toParams(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Falha na solicitação');
        }
        return data;
    }

    function parseJsonText(rawText, fallback = null) {
        if (!rawText) return fallback;
        const normalized = rawText
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
        try {
            return JSON.parse(normalized);
        } catch (error) {
            return fallback;
        }
    }

    function isWebLinePayload(payload) {
        if (!payload || typeof payload !== 'object') return false;
        const altInstance = (payload.alt_gateway_instance || '').toString().trim();
        if (altInstance !== '') return true;
        const template = (payload.api_template || '').toString().toLowerCase();
        if (template === 'alt') return true;
        const provider = (payload.provider || '').toString().toLowerCase();
        return provider === 'sandbox';
    }

    function deriveTemplateFromPayload(payload) {
        if (!payload) return 'meta';
        if (payload.alt_gateway_instance) return 'alt';
        if (payload.provider === 'commercial') return 'commercial';
        if (payload.provider === 'dialog360') return 'dialog360';
        if (payload.provider === 'sandbox' && !payload.alt_gateway_instance) return 'sandbox';
        return 'meta';
    }

    function setRateLimitHint(presetKey) {
        if (!rateLimitHint) return;
        if (presetKey && rateLimitPresets[presetKey]) {
            const preset = rateLimitPresets[presetKey];
            rateLimitHint.textContent = preset.description || preset.label || 'Nível Meta selecionado.';
        } else {
            rateLimitHint.textContent = 'Selecione um nível Meta para preencher os campos automaticamente.';
        }
    }

    function applyRateLimitPresetSelection(presetKey, options = {}) {
        if (!rateLimitPresetField) return;
        const preset = rateLimitPresets[presetKey];
        if (!preset) {
            setRateLimitHint('');
            return;
        }
        if (lineLimitWindowField) lineLimitWindowField.value = preset.window_seconds;
        if (lineLimitMaxField) lineLimitMaxField.value = preset.max_messages;
        if (!options.silent) {
            rateLimitPresetField.value = presetKey;
        }
        setRateLimitHint(presetKey);
    }

    function syncRateLimitPresetFromValues() {
        if (!rateLimitPresetField) return;
        const windowValue = Number(lineLimitWindowField ? lineLimitWindowField.value : 0);
        const maxValue = Number(lineLimitMaxField ? lineLimitMaxField.value : 0);
        const matched = Object.entries(rateLimitPresets).find(([, preset]) => {
            return Number(preset.window_seconds) === windowValue && Number(preset.max_messages) === maxValue;
        });
        if (matched) {
            const [key] = matched;
            rateLimitPresetField.value = key;
            setRateLimitHint(key);
        } else {
            rateLimitPresetField.value = '';
            setRateLimitHint('');
        }
    }

    function getLineMode() {
        return lineModeInput ? (lineModeInput.value || 'new') : 'new';
    }

    function resetLineForm() {
        if (!lineForm) return;
        lineForm.reset();
        lineForm.dataset.webLockOrigin = '0';
        if (lineModeInput) lineModeInput.value = 'new';
        if (lineModePicker) lineModePicker.value = 'new';
        applyTemplateChange({ userInitiated: false });
        syncLineModeUI();
        syncRateLimitPresetFromValues();
        if (lineFeedback) lineFeedback.textContent = '';
    }

    function populateLineForm(payload) {
        if (!lineForm || !payload) return;
        const targetId = payload.id ?? payload.line_id ?? '';
        lineForm.dataset.webLockOrigin = isWebLinePayload(payload) ? '1' : '0';
        if (lineIdField) lineIdField.value = targetId;
        if (lineModeInput) lineModeInput.value = 'edit';
        if (lineModePicker) lineModePicker.value = 'edit';
        if (lineFeedback) lineFeedback.textContent = targetId ? 'Editando linha #' + targetId : 'Editando linha';
        if (lineForm.querySelector('input[name="label"]')) lineForm.querySelector('input[name="label"]').value = payload.label || '';
        if (lineForm.querySelector('input[name="display_phone"]')) lineForm.querySelector('input[name="display_phone"]').value = payload.display_phone || '';
        const template = payload.api_template || deriveTemplateFromPayload(payload);
        if (lineTemplateField) lineTemplateField.value = template;
        if (lineProviderField) lineProviderField.value = payload.provider || lineProviderField.value || 'meta';
        if (lineBaseField) lineBaseField.value = payload.api_base_url || '';
        if (linePhoneNumberField) linePhoneNumberField.value = payload.phone_number_id || '';
        if (lineBusinessAccountField) lineBusinessAccountField.value = payload.business_account_id || '';
        if (lineAccessField) lineAccessField.value = payload.access_token || '';
        if (lineVerifyTokenField) lineVerifyTokenField.value = payload.verify_token || '';
        if (lineAltSelect) lineAltSelect.value = payload.alt_gateway_instance || '';
        if (lineStatusField) lineStatusField.value = payload.status || 'active';
        if (lineDefaultField) lineDefaultField.checked = Number(payload.is_default || 0) === 1;
        if (lineLimitEnabledField) lineLimitEnabledField.checked = Number(payload.rate_limit_enabled || 0) === 1;
        if (lineLimitWindowField) lineLimitWindowField.value = payload.rate_limit_window_seconds ? String(payload.rate_limit_window_seconds) : '3600';
        if (lineLimitMaxField) lineLimitMaxField.value = payload.rate_limit_max_messages ? String(payload.rate_limit_max_messages) : '500';
        if (rateLimitPresetField) {
            rateLimitPresetField.value = payload.rate_limit_preset || '';
            setRateLimitHint(rateLimitPresetField.value || '');
        }
        applyTemplateChange({ userInitiated: false });
        syncRateLimitPresetFromValues();
        syncLineModeUI();
    }

    function needsWebCode(payload, templateValue) {
        if (lineForm && lineForm.dataset.webLockOrigin === '1') return true;
        if (isWebLinePayload(payload)) return true;
        const normalizedTemplate = (templateValue || '').toString().toLowerCase();
        return normalizedTemplate === 'alt';
    }

    if (lineForm) {
        syncLineModeUI();
        applyTemplateChange({ userInitiated: false });
        if (rateLimitPresetField) {
            rateLimitPresetField.addEventListener('change', () => applyRateLimitPresetSelection(rateLimitPresetField.value));
        }
        syncRateLimitPresetFromValues();
    }
    if (lineModePicker) {
        lineModePicker.addEventListener('change', () => syncLineModeUI());
    }
    if (lineTemplateField) {
        lineTemplateField.addEventListener('change', () => applyTemplateChange({ userInitiated: true }));
    }

    if (lineReset) {
        lineReset.addEventListener('click', (event) => {
            event.preventDefault();
            resetLineForm();
        });
    }

    lineEditButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const payload = parseJsonText(button.getAttribute('data-line-edit') || '{}', null);
            if (!payload) {
                alert('Não foi possível carregar os dados desta linha.');
                return;
            }
            populateLineForm(payload);
        });
    });

    lineDeleteButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Remover esta linha?')) return;
            const lineId = button.getAttribute('data-line-delete');
            const payload = parseJsonText(button.getAttribute('data-line-delete-payload') || '{}', {});
            const deletePayload = {};
            if (isWebLinePayload(payload)) {
                const code = prompt('Informe o código de autorização para remover linhas do WhatsApp Web:');
                if (!code) return;
                deletePayload.web_edit_code = code;
            }
            try {
                await postForm('<?= url('whatsapp/lines'); ?>/' + lineId + '/delete', deletePayload);
                window.location.reload();
            } catch (error) {
                alert(error && error.message ? error.message : 'Falha ao remover linha.');
            }
        });
    });

    if (lineForm) {
        lineForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (lineFeedback) lineFeedback.textContent = 'Salvando linha...';
            const formData = new FormData(lineForm);
            const payload = Object.fromEntries(formData.entries());
            const templateValue = (payload.api_template || '').toString().toLowerCase();
            if (needsWebCode(payload, templateValue)) {
                const codeValue = (payload.web_edit_code || '').toString().trim();
                if (codeValue === '') {
                    if (lineFeedback) lineFeedback.textContent = 'Informe o código de autorização para alterar linhas do WhatsApp Web.';
                    return;
                }
            }
            const mode = getLineMode();
            const lineId = (payload.line_id || '').toString().trim();
            if ((mode === 'edit' || mode === 'migrate') && lineId === '') {
                if (lineFeedback) lineFeedback.textContent = 'Selecione uma linha antes de salvar.';
                return;
            }
            const url = lineId ? '<?= url('whatsapp/lines'); ?>/' + lineId : '<?= url('whatsapp/lines'); ?>';
            try {
                await postForm(url, payload);
                if (lineFeedback) lineFeedback.textContent = 'Linha salva.';
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                if (lineFeedback) lineFeedback.textContent = error && error.message ? error.message : 'Falha ao salvar linha.';
            }
        });
    }

    // Monitor de backup do gateway
    const summaryBox = document.getElementById('wa-gateway-backup-summary');
    const summaryMeta = document.getElementById('wa-gateway-backup-summary-meta');
    const refreshChip = document.getElementById('wa-gateway-backup-refresh');
    const SUMMARY_ENDPOINT = '<?= url('whatsapp/alt/gateway-backup/summary'); ?>';

    async function fetchGatewayBackupSummary() {
        if (!summaryBox) return;
        summaryBox.innerHTML = '<p class="wa-empty">Atualizando...</p>';
        if (summaryMeta) {
            summaryMeta.textContent = '';
        }
        try {
            const resp = await fetch(SUMMARY_ENDPOINT, { headers: { 'Accept': 'application/json' } });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok) {
                throw new Error(data.error || 'Falha ao carregar monitor.');
            }
            const summary = data.summary || {};
            const lines = summary.lines || {};
            const total = summary.total || 0;
            const errors = Array.isArray(summary.errors) ? summary.errors : [];

            if (Object.keys(lines).length === 0) {
                summaryBox.innerHTML = '<p class="wa-empty">Nenhum registro de backup para hoje.</p>';
            } else {
                const rows = Object.entries(lines).map(([label, stats]) => {
                    const incoming = stats.incoming ?? 0;
                    const outgoing = stats.outgoing ?? 0;
                    const sum = stats.total ?? (incoming + outgoing);
                    return `<tr><td>${label}</td><td>${incoming}</td><td>${outgoing}</td><td>${sum}</td></tr>`;
                }).join('');
                summaryBox.innerHTML = `
                    <div class="wa-table-wrapper">
                        <table class="wa-table">
                            <thead><tr><th>Linha/Gateway</th><th>Entrou</th><th>Saiu</th><th>Total</th></tr></thead>
                            <tbody>${rows}</tbody>
                            <tfoot><tr><th>Total</th><th colspan="3">${total}</th></tr></tfoot>
                        </table>
                    </div>`;
            }

            if (summaryMeta) {
                const now = new Date();
                const errorsText = errors.length ? ' | Alertas: ' + errors.join(' ; ') : '';
                summaryMeta.textContent = `Data ${summary.date || ''} · Atualizado às ${now.toLocaleTimeString()}${errorsText}`;
            }
        } catch (error) {
            summaryBox.innerHTML = `<p class="wa-empty">${error && error.message ? error.message : 'Erro ao carregar monitor.'}</p>`;
        }
    }

    if (summaryBox) {
        fetchGatewayBackupSummary();
        setInterval(fetchGatewayBackupSummary, 15 * 60 * 1000);
    }
    if (refreshChip) {
        refreshChip.addEventListener('click', (e) => {
            e.preventDefault();
            fetchGatewayBackupSummary();
        });
    }
});
</script>

<?php /* script.php desabilitado nesta tela para evitar conflitos JS do painel principal */ ?>
<script>
(function () {
    const globalState = window.SafeGreenWhatsApp || {};
    if (globalState.lineGatewayInit) {
        return;
    }
    const wrappers = document.querySelectorAll('[data-line-gateway]');
    const modal = document.querySelector('[data-line-qr-modal]');
    if (!modal || wrappers.length === 0) {
        return;
    }
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';
    const elements = {
        mode: modal.querySelector('[data-line-qr-mode]'),
        title: modal.querySelector('[data-line-qr-title]'),
        description: modal.querySelector('[data-line-qr-description]'),
        helper: modal.querySelector('[data-line-qr-helper]'),
        placeholder: modal.querySelector('[data-line-qr-placeholder]'),
        image: modal.querySelector('[data-line-qr-image]'),
        meta: modal.querySelector('[data-line-qr-meta]'),
        refresh: modal.querySelector('[data-line-qr-refresh]'),
        closeButtons: modal.querySelectorAll('[data-line-qr-close]'),
    };
    const endpoints = {
        reset: '<?= url('whatsapp/alt/gateway-reset'); ?>',
        qr: '<?= url('whatsapp/alt/qr'); ?>',
        start: '<?= url('whatsapp/alt/gateway-start'); ?>',
        status: '<?= url('whatsapp/alt/gateway-status'); ?>',
    };
    const state = {
        slug: null,
        mode: 'standard',
        timer: null,
        bodyOverflow: '',
    };

    const toParams = (payload) => Object.entries(payload)
        .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value == null ? '' : value)}`)
        .join('&');

    function setPlaceholder(text) {
        if (elements.placeholder) {
            elements.placeholder.hidden = text === '';
            elements.placeholder.textContent = text;
        }
        if (elements.image) {
            elements.image.hidden = true;
            elements.image.removeAttribute('src');
        }
    }

    function setMeta(text) {
        if (elements.meta) {
            elements.meta.textContent = text || '';
        }
    }

    function stopPoll() {
        if (state.timer) {
            clearInterval(state.timer);
            state.timer = null;
        }
    }

    async function postForm(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: toParams(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Falha na solicitação');
        }
        return data;
    }

    async function fetchQr(silent = false) {
        if (!state.slug) {
            return;
        }
        if (!silent) {
            setPlaceholder('Atualizando QR...');
            setMeta('');
        }
        try {
            const response = await fetch(`${endpoints.qr}?instance=${encodeURIComponent(state.slug)}`);
            if (response.status === 204) {
                setPlaceholder('Sessão conectada. QR não é necessário.');
                setMeta('');
                stopPoll();
                return;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.error || 'QR indisponível no momento.');
            }
            if (data.qr && elements.image) {
                elements.placeholder.hidden = true;
                elements.image.hidden = false;
                elements.image.src = data.qr;
                elements.image.alt = 'QR Code WhatsApp Web';
                const details = [];
                if (data.generated_at) {
                    details.push('Gerado há instantes');
                }
                if (data.expires_at) {
                    details.push('Expira em breve');
                }
                setMeta(details.join(' | '));
                stopPoll();
                state.timer = setInterval(() => fetchQr(true), 20000);
            } else {
                setPlaceholder('Sem QR disponível. Aguarde a reconexão.');
                setMeta('');
                stopPoll();
            }
        } catch (error) {
            setPlaceholder(error.message || 'Falha ao carregar QR.');
            setMeta('');
        }
    }

    function openModal(copy) {
        if (!modal) {
            return;
        }
        if (!state.bodyOverflow) {
            state.bodyOverflow = document.body.style.overflow || '';
        }
        document.body.style.overflow = 'hidden';
        modal.removeAttribute('hidden');
        if (elements.mode) {
            elements.mode.textContent = copy.modeLabel || (state.mode === 'history' ? 'Importar histórico' : 'Reconexão rápida');
        }
        if (elements.title) {
            elements.title.textContent = copy.title || 'QR do gateway';
        }
        if (elements.description) {
            elements.description.textContent = copy.description || '';
        }
        if (elements.helper) {
            elements.helper.textContent = copy.helper || '';
            elements.helper.hidden = (copy.helper || '') === '';
        }
        setPlaceholder(copy.statusText || 'Gerando QR...');
        setMeta('');
        stopPoll();
    }

    function closeModal() {
        if (!modal) {
            return;
        }
        stopPoll();
        modal.setAttribute('hidden', 'hidden');
        document.body.style.overflow = state.bodyOverflow || '';
        state.bodyOverflow = '';
        state.slug = null;
        setPlaceholder('');
        setMeta('');
    }

    function buildCopy(wrapper, mode) {
        const label = wrapper.getAttribute('data-line-label') || 'Linha WhatsApp';
        const phone = wrapper.getAttribute('data-line-phone') || '';
        const description = phone ? `${label} · ${phone}` : label;
        const isHistory = mode === 'history';
        return {
            modeLabel: isHistory ? 'Importar histórico' : 'Reconexão rápida',
            title: `${isHistory ? 'QR histórico' : 'Novo QR'} - ${label}`,
            description,
            helper: isHistory
                ? 'Escaneie com o celular desta linha e deixe o WhatsApp aberto até concluir.'
                : 'Use o celular desta linha para reconectar o WhatsApp Web imediatamente.',
            statusText: isHistory ? 'Preparando QR histórico...' : 'Gerando novo QR...',
        };
    }

    async function handleReset(mode, slug, wrapper, button) {
        if (!slug) {
            alert('Instância do gateway não encontrada.');
            return;
        }
        const statusBadge = wrapper.querySelector('[data-line-gateway-status]');
        const copy = buildCopy(wrapper, mode);
        state.slug = slug;
        state.mode = mode;
        openModal(copy);
        if (button) {
            button.disabled = true;
        }
        if (statusBadge) {
            statusBadge.textContent = mode === 'history'
                ? 'Reiniciando sessão para histórico...'
                : 'Gerando novo QR...';
        }
        try {
            await postForm(endpoints.reset, { instance: slug });
            const placeholderText = mode === 'history'
                ? 'Escaneie o QR histórico e deixe o WhatsApp aberto para sincronizar tudo.'
                : 'Sessão reiniciada. Aguarde o QR aparecer no celular.';
            setPlaceholder(placeholderText);
            setMeta('');
            if (statusBadge) {
                statusBadge.textContent = placeholderText;
            }
            fetchQr(true);
        } catch (error) {
            const message = error && error.message ? error.message : 'Falha ao solicitar novo QR.';
            setPlaceholder(message);
            setMeta('');
            if (statusBadge) {
                statusBadge.textContent = message;
            } else {
                alert(message);
            }
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    wrappers.forEach((wrapper) => {
        const slug = wrapper.getAttribute('data-gateway-instance');
        if (!slug) {
            return;
        }
        const statusBadge = wrapper.querySelector('[data-line-gateway-status]');

        const renderStatus = (payload) => {
            if (!statusBadge || !payload) return;
            const statusHint = (payload.status || payload.state || '').toLowerCase();
            const ready = !!payload.ready;
            const ok = !!payload.ok;
            const statusMap = {
                qr: 'aguardando QR',
                pairing: 'pareando',
                opening: 'abrindo sessão',
                open: 'online',
                connected: 'online',
                synced: 'online',
                disconnectedmobile: 'telefone offline',
            };
            let text = statusMap[statusHint] || (ready ? 'online' : ok ? 'em pareamento' : 'offline');
            statusBadge.textContent = `Gateway: ${payload.label || slug} (${text})`;
            statusBadge.classList.remove('is-offline', 'is-warning', 'is-online');
            if (ready) {
                statusBadge.classList.add('is-online');
            } else if (ok) {
                statusBadge.classList.add('is-warning');
            } else {
                statusBadge.classList.add('is-offline');
            }
        };

        async function refreshGatewayStatus() {
            if (!slug || !statusBadge) return;
            statusBadge.textContent = 'Atualizando status...';
            statusBadge.classList.remove('is-offline', 'is-warning', 'is-online');
            statusBadge.classList.add('is-warning');
            try {
                const resp = await fetch(`${endpoints.status}?instance=${encodeURIComponent(slug)}`, { headers: { 'Accept': 'application/json' } });
                const data = await resp.json().catch(() => ({}));
                if (!resp.ok) throw new Error(data.error || 'Falha ao consultar gateway.');
                renderStatus(data.gateway || {});
            } catch (error) {
                statusBadge.textContent = error && error.message ? error.message : 'Gateway offline';
                statusBadge.classList.remove('is-warning', 'is-online');
                statusBadge.classList.add('is-offline');
            }
        }

        // Reflex pós-carregamento: atualiza status sem travar o render inicial.
        setTimeout(refreshGatewayStatus, 300);

        const standardButton = wrapper.querySelector('[data-line-gateway-qr]');
        if (standardButton) {
            standardButton.addEventListener('click', (event) => {
                if (window.SafeGreenWhatsApp && window.SafeGreenWhatsApp.lineGatewayInit) {
                    return;
                }
                event.preventDefault();
                event.stopImmediatePropagation();
                handleReset('standard', slug, wrapper, standardButton);
            });
        }
        const historyButton = wrapper.querySelector('[data-line-gateway-qr-history]');
        if (historyButton) {
            historyButton.addEventListener('click', (event) => {
                if (window.SafeGreenWhatsApp && window.SafeGreenWhatsApp.lineGatewayInit) {
                    return;
                }
                event.preventDefault();
                event.stopImmediatePropagation();
                handleReset('history', slug, wrapper, historyButton);
            });
        }

        const startButton = wrapper.querySelector('[data-line-gateway-start]');
        if (startButton) {
            startButton.addEventListener('click', async (event) => {
                event.preventDefault();
                const statusBadge = wrapper.querySelector('[data-line-gateway-status]');
                startButton.disabled = true;
                if (statusBadge) {
                    statusBadge.textContent = 'Iniciando gateway...';
                    statusBadge.classList.remove('is-offline');
                    statusBadge.classList.add('is-warning');
                }
                try {
                    const data = await postForm(endpoints.start, { instance: slug });
                    const message = data && data.message ? data.message : 'Gateway iniciado.';
                    if (statusBadge) {
                        statusBadge.textContent = message;
                        statusBadge.classList.remove('is-offline');
                        statusBadge.classList.remove('is-warning');
                        statusBadge.classList.add('is-online');
                    }
                } catch (error) {
                    if (statusBadge) {
                        statusBadge.textContent = error && error.message ? error.message : 'Falha ao iniciar gateway.';
                        statusBadge.classList.add('is-offline');
                        statusBadge.classList.remove('is-online');
                    } else {
                        alert(error && error.message ? error.message : 'Falha ao iniciar gateway.');
                    }
                } finally {
                    startButton.disabled = false;
                }
            });
        }
    });

    elements.closeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeModal();
        });
    });

    if (elements.refresh) {
        elements.refresh.addEventListener('click', () => {
            fetchQr(false);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
            closeModal();
        }
    });

    window.SafeGreenWhatsApp = Object.assign(globalState, { lineGatewayFallback: true });
})();
</script>
