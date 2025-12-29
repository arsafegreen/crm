<header style="margin-bottom:24px;display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;">
    <div>
        <h1 style="margin:0;font-size:1.6rem;">Solicitações de acesso</h1>
        <p style="margin:6px 0 0;color:var(--muted);">
            Gerencie cadastros aguardando aprovação. Libere logins padrão ou certificados conforme a política interna.
        </p>
    </div>
    <a href="<?= url(); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;">&larr; Voltar ao painel</a>
</header>

<?php
$permissionLabels = $permissionLabels ?? [];
$allPermissionKeys = array_keys($permissionLabels);
$permissionGroups = $permissionGroups ?? [];
$availableAvps = $availableAvps ?? [];
$globalAccessWindow = $globalAccessWindow ?? [];
$avpIdentityLookups = $avpIdentityLookups ?? [];

$groupedKeys = [];
$normalizedPermissionGroups = [];

foreach ($permissionGroups as $group) {
    if (!is_array($group)) {
        continue;
    }

    $keys = [];
    foreach (($group['keys'] ?? []) as $key) {
        $key = (string)$key;
        if ($key === '' || !isset($permissionLabels[$key])) {
            continue;
        }
        $keys[] = $key;
        $groupedKeys[$key] = true;
    }

    if ($keys === []) {
        continue;
    }

    $normalizedPermissionGroups[] = [
        'title' => isset($group['title']) ? (string)$group['title'] : 'Outras permissões',
        'subtitle' => isset($group['subtitle']) ? (string)$group['subtitle'] : null,
        'keys' => $keys,
    ];
}

$ungroupedKeys = [];
foreach ($allPermissionKeys as $key) {
    if (!isset($groupedKeys[$key])) {
        $ungroupedKeys[] = $key;
    }
}

if ($ungroupedKeys !== []) {
    $normalizedPermissionGroups[] = [
        'title' => 'Outras permissões',
        'subtitle' => null,
        'keys' => $ungroupedKeys,
    ];
}

$renderPermissionMatrix = static function (array $selected) use ($normalizedPermissionGroups, $permissionLabels): void {
    ?>
    <div style="display:grid;gap:12px;">
        <?php foreach ($normalizedPermissionGroups as $groupIndex => $group):
            $title = htmlspecialchars((string)($group['title'] ?? 'Permissões'), ENT_QUOTES, 'UTF-8');
            $subtitle = isset($group['subtitle']) && $group['subtitle'] !== '' ? htmlspecialchars((string)$group['subtitle'], ENT_QUOTES, 'UTF-8') : null;
        ?>
            <fieldset style="border:1px solid rgba(148,163,184,0.25);border-radius:12px;padding:12px 16px;background:rgba(15,23,42,0.45);display:grid;gap:8px;">
                <legend style="font-weight:600;color:var(--text);font-size:0.9rem;margin:0;"><?= $title; ?></legend>
                <?php if ($subtitle !== null): ?>
                    <p style="margin:0;color:var(--muted);font-size:0.78rem;"><?= $subtitle; ?></p>
                <?php endif; ?>
                <div style="display:grid;gap:6px;">
                    <?php foreach ($group['keys'] as $keyIndex => $permissionKey):
                        if (!isset($permissionLabels[$permissionKey])) {
                            continue;
                        }
                        $checkboxId = 'perm-' . $groupIndex . '-' . $keyIndex;
                        $labelText = htmlspecialchars($permissionLabels[$permissionKey], ENT_QUOTES, 'UTF-8');
                        $isChecked = in_array($permissionKey, $selected, true);
                    ?>
                        <label for="<?= htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                            <input type="checkbox" id="<?= htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>" name="permissions[]" value="<?= htmlspecialchars($permissionKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $isChecked ? 'checked' : ''; ?> style="accent-color:var(--accent);width:16px;height:16px;">
                            <span><?= $labelText; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderPermissionSummary = static function (array $selected, int $previewLimit = 2) use ($normalizedPermissionGroups, $permissionLabels): void {
    $entries = [];

    foreach ($normalizedPermissionGroups as $group) {
        $groupKeys = isset($group['keys']) && is_array($group['keys']) ? $group['keys'] : [];
        $matching = array_values(array_intersect($groupKeys, $selected));
        if ($matching === []) {
            continue;
        }

        $labels = array_values(array_map(static function (string $key) use ($permissionLabels): string {
            return $permissionLabels[$key] ?? $key;
        }, $matching));

        $entries[] = [
            'title' => isset($group['title']) ? (string)$group['title'] : 'Permissões',
            'preview' => array_slice($labels, 0, max(1, $previewLimit)),
            'extra' => max(count($labels) - max(1, $previewLimit), 0),
        ];
    }
    ?>
    <div class="permission-summary" role="list">
        <?php if ($entries === []): ?>
            <span class="permission-summary-empty" role="listitem">Sem módulos adicionais selecionados</span>
        <?php else: ?>
            <?php foreach ($entries as $entry):
                $title = htmlspecialchars($entry['title'], ENT_QUOTES, 'UTF-8');
                $preview = htmlspecialchars(implode(', ', $entry['preview']), ENT_QUOTES, 'UTF-8');
                $extra = (int)$entry['extra'];
            ?>
                <span class="permission-summary-item" role="listitem">
                    <strong><?= $title; ?></strong>
                    <span>
                        <?= $preview; ?>
                        <?php if ($extra > 0): ?>
                            <small>+<?= $extra; ?></small>
                        <?php endif; ?>
                    </span>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
};

$permissionPanelCounter = 0;
$nextPermissionPanelId = static function () use (&$permissionPanelCounter): string {
    $permissionPanelCounter++;
    return 'permission-panel-' . $permissionPanelCounter;
};

$adminUsers = $adminUsers ?? [];
$loginPending = $loginPending ?? [];
$pending = $pending ?? [];
$activeUsers = $activeUsers ?? [];
$disabledUsers = $disabledUsers ?? [];
$recent = $recent ?? [];

$loginPendingCount = count($loginPending);
$certificatePendingCount = count($pending);

$liberacaoMenuItems = [
    [
        'key' => 'admins',
        'label' => 'Administradores',
        'description' => 'Sessões e políticas',
        'badge' => null,
        'has_alert' => false,
    ],
    [
        'key' => 'login',
        'label' => 'Solicitações de login',
        'description' => 'Senha + TOTP',
        'badge' => $loginPendingCount > 0 ? $loginPendingCount : null,
        'has_alert' => $loginPendingCount > 0,
    ],
    [
        'key' => 'certificates',
        'label' => 'Liberação de certificados',
        'description' => 'Documentos e AVPs',
        'badge' => $certificatePendingCount > 0 ? $certificatePendingCount : null,
        'has_alert' => $certificatePendingCount > 0,
    ],
    [
        'key' => 'active',
        'label' => 'Colaboradores ativos',
        'description' => 'Permissões e agenda',
        'badge' => null,
        'has_alert' => false,
    ],
    [
        'key' => 'disabled',
        'label' => 'Desativados',
        'description' => 'Reativações e limpeza',
        'badge' => null,
        'has_alert' => false,
    ],
    [
        'key' => 'history',
        'label' => 'Histórico',
        'description' => 'Decisões recentes',
        'badge' => null,
        'has_alert' => false,
    ],
];
?>

<style>
    .avp-multiselect-grid {
        display: grid;
        gap: 6px;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        max-height: 260px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid rgba(148, 163, 184, 0.25);
        border-radius: 12px;
        background: rgba(15, 23, 42, 0.45);
    }

    .avp-multiselect-grid label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        color: var(--muted);
    }

    .avp-multiselect-grid input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: var(--accent);
    }

    .liberacao-layout {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        margin-top: 24px;
    }
    .liberacao-menu {
        flex: 0 0 260px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        position: sticky;
        top: 90px;
        max-height: calc(100vh - 120px);
        overflow-y: auto;
        padding-right: 4px;
    }
    .liberacao-menu-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 14px 16px;
        border-radius: 14px;
        border: 1px solid rgba(148,163,184,0.3);
        background: rgba(15,23,42,0.55);
        color: var(--text);
        text-align: left;
        font-size: 0.9rem;
        transition: border 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        cursor: pointer;
    }
    .liberacao-menu-item span {
        color: var(--muted);
        font-size: 0.75rem;
    }
    .liberacao-menu-item.is-active {
        border-color: rgba(56,189,248,0.6);
        box-shadow: 0 15px 35px -25px rgba(56,189,248,0.8);
        background: rgba(56,189,248,0.08);
    }
    .liberacao-menu-item.has-alert {
        border-color: rgba(248,113,113,0.6);
        color: #f87171;
        box-shadow: 0 12px 30px -20px rgba(248,113,113,0.65);
    }
    .liberacao-menu-item.is-active.has-alert {
        border-color: rgba(248,113,113,0.8);
        background: rgba(248,113,113,0.12);
        box-shadow: 0 18px 40px -25px rgba(248,113,113,0.75);
    }
    .liberacao-menu-item.has-alert span {
        color: #fecaca;
    }
    .liberacao-menu-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        background: rgba(248,113,113,0.18);
        border: 1px solid rgba(248,113,113,0.45);
        color: #f87171;
        margin-top: 6px;
        width: fit-content;
    }
    .liberacao-content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    .liberacao-section {
        margin: 0;
    }
    .liberacao-layout.liberacao-js-ready .liberacao-section {
        display: none;
    }
    .liberacao-layout.liberacao-js-ready .liberacao-section.is-active {
        display: block;
    }
    @media (max-width: 1024px) {
        .liberacao-layout {
            flex-direction: column;
        }
        .liberacao-menu {
            position: static;
            flex-direction: row;
            flex-wrap: wrap;
            max-height: none;
        }
        .liberacao-menu-item {
            flex: 1 1 200px;
        }
    }
    @media (max-width: 640px) {
        .liberacao-menu-item {
            flex: 1 1 100%;
        }
    }

    .permission-summary-wrapper {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        justify-content: space-between;
        margin-top: 10px;
    }

    .permission-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: stretch;
    }

    .permission-summary-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 8px 12px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: rgba(15, 23, 42, 0.55);
        font-size: 0.8rem;
        color: var(--text);
    }

    .permission-summary-item strong {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #94a3b8;
    }

    .permission-summary-item span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .permission-summary-item span small {
        font-size: 0.7rem;
        color: #94a3b8;
    }

    .permission-summary-empty {
        padding: 8px 12px;
        border-radius: 999px;
        border: 1px dashed rgba(148, 163, 184, 0.35);
        font-size: 0.78rem;
        color: var(--muted);
    }

    .permission-toggle {
        padding: 8px 14px;
        border-radius: 999px;
        border: 1px solid rgba(56, 189, 248, 0.35);
        background: rgba(56, 189, 248, 0.12);
        color: #38bdf8;
        font-weight: 600;
        font-size: 0.78rem;
        cursor: pointer;
    }

    .permission-toggle:focus-visible,
    .permission-toggle:hover {
        border-color: rgba(56, 189, 248, 0.6);
        outline: none;
    }

    .permission-matrix {
        margin-top: 10px;
    }

    .permission-matrix[hidden] {
        display: none !important;
    }

    .user-collapsible {
        border: 1px solid rgba(148, 163, 184, 0.25);
        border-radius: 16px;
        background: rgba(15, 23, 42, 0.55);
    }

    .user-collapsible + .user-collapsible {
        margin-top: 10px;
    }

    .user-collapsible summary {
        list-style: none;
        cursor: pointer;
        padding: 16px 18px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
        font-size: 0.88rem;
        color: var(--text);
    }

    .user-collapsible summary::-webkit-details-marker {
        display: none;
    }

    .user-collapsible summary span {
        color: var(--muted);
        font-size: 0.78rem;
    }

    .user-collapsible summary .user-collapsible-meta {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .user-collapsible summary .user-collapsible-meta strong {
        font-size: 0.9rem;
        color: var(--text);
    }

    .user-collapsible summary .user-collapsible-meta small {
        font-size: 0.75rem;
        color: var(--muted);
    }

    .user-collapsible-body {
        padding: 0 18px 18px 18px;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .action-button-busy {
        opacity: 0.75;
        cursor: not-allowed;
    }

    .action-button-loading {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .action-button-spinner {
        width: 14px;
        height: 14px;
        border-radius: 999px;
        border: 2px solid rgba(148, 163, 184, 0.45);
        border-top-color: var(--accent);
        animation: liberacao-spin 0.8s linear infinite;
    }

    @keyframes liberacao-spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<div class="liberacao-layout" data-liberacao-layout>
    <aside class="liberacao-menu">
        <?php foreach ($liberacaoMenuItems as $index => $menuItem):
            $target = htmlspecialchars((string)($menuItem['key'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)($menuItem['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $description = isset($menuItem['description']) ? htmlspecialchars((string)$menuItem['description'], ENT_QUOTES, 'UTF-8') : null;
            $hasAlert = !empty($menuItem['has_alert']);
            $badge = isset($menuItem['badge']) ? (int)$menuItem['badge'] : null;
            $buttonClasses = 'liberacao-menu-item' . ($index === 0 ? ' is-active' : '') . ($hasAlert ? ' has-alert' : '');
        ?>
            <button type="button" class="<?= $buttonClasses; ?>" data-liberacao-target="<?= $target; ?>">
                <strong><?= $label; ?></strong>
                <?php if ($description !== null && $description !== ''): ?>
                    <span><?= $description; ?></span>
                <?php endif; ?>
                <?php if ($badge !== null && $badge > 0): ?>
                    <span class="liberacao-menu-badge"><?= number_format($badge, 0, ',', '.'); ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </aside>
    <div class="liberacao-content">

<section id="admins" class="panel liberacao-section" data-liberacao-section="admins" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.2rem;">Administradores</h2>
        <span style="font-size:0.9rem;color:var(--muted);">Total: <?= count($adminUsers); ?></span>
    </div>

    <?php if (empty($adminUsers)): ?>
        <p style="margin-top:18px;color:var(--muted);">Nenhum administrador encontrado.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;margin-top:18px;">
            <?php foreach ($adminUsers as $admin):
                $email = isset($admin['email']) ? (string)$admin['email'] : '—';
                $lastLogin = isset($admin['last_login_at']) && $admin['last_login_at'] ? format_datetime((int)$admin['last_login_at'], 'd/m/Y H:i') : 'Nunca';
                $lastSeen = isset($admin['last_seen_at']) && $admin['last_seen_at'] ? format_datetime((int)$admin['last_seen_at'], 'd/m/Y H:i') : 'Sem atividade recente';
                $isOnline = !empty($admin['is_online']);
                $statusLabel = $isOnline ? 'Online' : 'Offline';
                $statusColor = $isOnline ? '#22c55e' : '#94a3b8';
                $forcedAt = isset($admin['session_forced_at']) && $admin['session_forced_at'] ? format_datetime((int)$admin['session_forced_at'], 'd/m/Y H:i') : null;
                $windowLabel = isset($admin['access_window_label']) ? (string)$admin['access_window_label'] : 'Segue horário padrão';
            ?>
                <details style="border:1px solid var(--border);border-radius:16px;background:rgba(15,23,42,0.65);">
                    <summary style="padding:18px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;cursor:pointer;list-style:none;">
                        <div>
                            <strong style="display:block;font-size:1.05rem;color:var(--text);">
                                <?= htmlspecialchars((string)($admin['name'] ?? 'Administrador'), ENT_QUOTES, 'UTF-8'); ?>
                            </strong>
                            <span style="display:block;margin-top:6px;color:var(--muted);font-size:0.85rem;">
                                E-mail: <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.8rem;">
                                Último login: <?= htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?> | Último visto: <?= htmlspecialchars($lastSeen, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.78rem;">
                                Janela aplicada: <?= htmlspecialchars($windowLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:140px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid <?= $statusColor; ?>;color:<?= $statusColor; ?>;font-weight:600;font-size:0.8rem;">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $statusColor; ?>;"></span>
                                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($forcedAt !== null): ?>
                                <span style="font-size:0.75rem;color:#facc15;">Sessão forçada às <?= htmlspecialchars($forcedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($admin['require_known_device'])): ?>
                                <span style="font-size:0.75rem;color:#facc15;">Restrição por dispositivo ativa</span>
                            <?php endif; ?>
                        </div>
                    </summary>
                    <div style="padding:0 18px 18px 18px;display:flex;flex-direction:column;gap:16px;">
                        <?php if (!empty($admin['session_ip']) || !empty($admin['session_location']) || !empty($admin['session_user_agent'])): ?>
                            <div style="padding:14px;border-radius:14px;background:rgba(15,23,42,0.55);border:1px solid rgba(56,189,248,0.25);display:grid;gap:8px;">
                                <strong style="font-size:0.9rem;color:var(--text);">Sessão atual</strong>
                                <?php if (!empty($admin['session_ip'])): ?>
                                    <span style="font-size:0.85rem;color:var(--muted);">IP: <code style="font-size:0.8rem;"><?= htmlspecialchars((string)$admin['session_ip'], ENT_QUOTES, 'UTF-8'); ?></code></span>
                                <?php endif; ?>
                                <?php if (!empty($admin['session_location'])): ?>
                                    <span style="font-size:0.85rem;color:var(--muted);">Localidade aproximada: <?= htmlspecialchars((string)$admin['session_location'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($admin['session_started_label'])): ?>
                                    <span style="font-size:0.85rem;color:var(--muted);">Conectado desde: <?= htmlspecialchars((string)$admin['session_started_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($admin['session_user_agent'])): ?>
                                    <span style="font-size:0.8rem;color:var(--muted);">Navegador: <?= htmlspecialchars((string)$admin['session_user_agent'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($admin['current_device'])): ?>
                                    <span style="font-size:0.85rem;color:var(--muted);display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                        Equipamento atual:
                                        <span style="display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(94,234,212,0.35);background:rgba(13,148,136,0.15);color:#5eead4;font-size:0.8rem;">
                                            <span><?= htmlspecialchars($admin['current_device']['status_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <code style="font-size:0.75rem;"><?= htmlspecialchars($admin['current_device']['fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code>
                                        </span>
                                    </span>
                                    <?php if (!empty($admin['current_device']['last_seen_at_label'])): ?>
                                        <span style="font-size:0.78rem;color:var(--muted);">Último sinal: <?= htmlspecialchars($admin['current_device']['last_seen_at_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($admin['devices'])): ?>
                            <details class="device-list">
                                <summary style="cursor:pointer;font-size:0.85rem;color:var(--muted);display:flex;align-items:center;gap:8px;">
                                    Equipamentos registrados (<?= count($admin['devices']); ?>)
                                </summary>
                                <div style="margin-top:12px;display:grid;gap:10px;">
                                    <?php foreach ($admin['devices'] as $device): ?>
                                        <div style="padding:12px;border-radius:12px;background:rgba(15,23,42,0.45);border:1px solid rgba(148,163,184,0.25);display:grid;gap:6px;">
                                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                                <strong style="font-size:0.85rem;color:var(--text);">
                                                    <?= htmlspecialchars($device['label'] ?? 'Equipamento sem nome', ENT_QUOTES, 'UTF-8'); ?>
                                                </strong>
                                                <span style="padding:2px 8px;border-radius:999px;font-size:0.7rem;lettering-spacing:0.04em;color:<?= $device['is_approved'] ? '#22c55e' : '#facc15'; ?>;border:1px solid <?= $device['is_approved'] ? 'rgba(34,197,94,0.45)' : 'rgba(250,204,21,0.45)'; ?>;background:<?= $device['is_approved'] ? 'rgba(34,197,94,0.12)' : 'rgba(250,204,21,0.12)'; ?>;">
                                                    <?= htmlspecialchars($device['status_label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </div>
                                            <div style="font-size:0.8rem;color:var(--muted);">Impressão digital: <code style="font-size:0.75rem;word-break:break-all;"><?= htmlspecialchars($device['fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                                            <?php if (!empty($device['last_ip']) || !empty($device['last_location'])): ?>
                                                <div style="font-size:0.8rem;color:var(--muted);">
                                                    Último IP conhecido: <?= htmlspecialchars($device['last_ip'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($device['last_location'])): ?>
                                                        <span>• <?= htmlspecialchars($device['last_location'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['last_seen_at_label'])): ?>
                                                <div style="font-size:0.8rem;color:var(--muted);">Último uso: <?= htmlspecialchars($device['last_seen_at_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['approved_at_label'])): ?>
                                                <div style="font-size:0.75rem;color:var(--muted);">Aprovado em <?= htmlspecialchars($device['approved_at_label'], ENT_QUOTES, 'UTF-8'); ?><?= !empty($device['approved_by']) ? ' por ' . htmlspecialchars($device['approved_by'], ENT_QUOTES, 'UTF-8') : ''; ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['user_agent'])): ?>
                                                <div style="font-size:0.75rem;color:var(--muted);">Agente de usuário: <?= htmlspecialchars($device['user_agent'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <form method="post" action="<?= url('admin/admins/' . (int)$admin['id'] . '/device-policy'); ?>" style="padding:16px;border-radius:14px;background:rgba(15,23,42,0.55);border:1px solid rgba(148,163,184,0.25);display:grid;gap:10px;">
                            <?= csrf_field(); ?>
                            <label style="display:flex;align-items:center;gap:10px;font-size:0.85rem;color:var(--muted);">
                                <input type="checkbox" name="require_known_device" value="1" <?= !empty($admin['require_known_device']) ? 'checked' : ''; ?> style="accent-color:var(--accent);width:18px;height:18px;">
                                Exigir que o administrador acesse somente por equipamentos aprovados
                            </label>
                            <p style="margin:0;font-size:0.75rem;color:var(--muted);">
                                Com a restrição ativa, novos dispositivos entram como pendentes até que você aprove aqui na tela de liberações.
                            </p>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                                <button type="submit" class="primary" data-loading-label="Salvando política..." style="padding:10px 16px;border-radius:999px;">Salvar política de dispositivo</button>
                                <?php if (!empty($admin['require_known_device'])): ?>
                                    <span style="font-size:0.75rem;color:#facc15;">Novos logins deste administrador precisarão de liberação manual.</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="login" class="panel liberacao-section" data-liberacao-section="login" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.2rem;">Solicitações de login (senha + TOTP)</h2>
        <span style="font-size:0.9rem;color:var(--muted);">Total: <?= count($loginPending); ?></span>
    </div>

    <?php if (empty($loginPending)): ?>
        <p style="margin-top:18px;color:var(--muted);">Nenhum pedido de login aguardando aprovação.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;margin-top:18px;">
            <?php foreach ($loginPending as $item):
                $createdAt = isset($item['created_at']) ? format_datetime((int)$item['created_at'], 'd/m/Y H:i') : '-';
                $selectedPermissions = isset($item['permissions']) && is_array($item['permissions']) ? $item['permissions'] : $allPermissionKeys;
            ?>
                <details style="border:1px solid var(--border);border-radius:16px;background:rgba(15,23,42,0.65);">
                    <summary style="padding:18px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;cursor:pointer;list-style:none;">
                        <div>
                            <strong style="display:block;font-size:1.05rem;color:var(--text);">
                                <?= htmlspecialchars((string)($item['name'] ?? 'Colaborador'), ENT_QUOTES, 'UTF-8'); ?>
                            </strong>
                            <span style="display:block;margin-top:6px;color:var(--muted);font-size:0.85rem;">
                                E-mail: <strong><?= htmlspecialchars((string)($item['email'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.8rem;">
                                Solicitado em <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <span style="padding:6px 12px;border-radius:999px;font-size:0.78rem;letter-spacing:0.04em;background:rgba(250,204,21,0.12);color:#facc15;border:1px solid rgba(250,204,21,0.45);">
                            Aguardando aprovação
                        </span>
                    </summary>
                    <div style="padding:0 18px 18px 18px;">
                        <div style="display:grid;gap:12px;margin-top:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                            <form method="post" action="<?= url('admin/users/' . (int)$item['id'] . '/approve'); ?>" style="display:flex;flex-direction:column;gap:12px;">
                                <?= csrf_field(); ?>
                                <div>
                                    <span style="display:block;font-size:0.85rem;color:var(--muted);margin-bottom:10px;">Selecione os módulos liberados</span>
                                    <?php $matrixId = $nextPermissionPanelId(); ?>
                                    <div class="permission-summary-wrapper">
                                        <div style="display:flex;flex-direction:column;gap:6px;flex:1;min-width:220px;">
                                            <span style="font-size:0.78rem;color:var(--muted);">Resumo das liberações sugeridas</span>
                                            <?php $renderPermissionSummary($selectedPermissions); ?>
                                        </div>
                                        <button type="button"
                                            class="permission-toggle"
                                            data-permission-matrix-toggle="<?= $matrixId; ?>"
                                            data-collapsed-label="Editar módulos"
                                            data-expanded-label="Ocultar módulos"
                                            aria-controls="<?= $matrixId; ?>"
                                            aria-expanded="false">
                                            Editar módulos
                                        </button>
                                    </div>
                                    <div class="permission-matrix" id="<?= $matrixId; ?>" data-permission-matrix="<?= $matrixId; ?>" hidden="hidden">
                                        <?php $renderPermissionMatrix($selectedPermissions); ?>
                                    </div>
                                </div>
                                <button type="submit" class="primary" data-loading-label="Liberando..." style="padding:12px;border-radius:999px;width:100%;">
                                    Liberar acesso
                                </button>
                            </form>
                            <form method="post" action="<?= url('admin/users/' . (int)$item['id'] . '/deny'); ?>">
                                <?= csrf_field(); ?>
                                <button type="submit" data-loading-label="Enviando recusa..." style="padding:12px;border-radius:999px;width:100%;background:rgba(248,113,113,0.15);border:1px solid rgba(248,113,113,0.45);color:#f87171;font-weight:600;">
                                    Recusar solicitação
                                </button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="certificates" class="panel liberacao-section" data-liberacao-section="certificates" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.2rem;">Pendentes</h2>
        <span style="font-size:0.9rem;color:var(--muted);">Total: <?= count($pending); ?></span>
    </div>

    <?php if (empty($pending)): ?>
        <p style="margin-top:18px;color:var(--muted);">Nenhuma solicitação aguardando aprovação.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;margin-top:18px;">
            <?php foreach ($pending as $item):
                $cpfDigits = isset($item['cpf']) ? digits_only((string)$item['cpf']) : '';
                $cpfLabel = $cpfDigits !== '' ? format_document($cpfDigits) : 'CPF não informado';
                $validUntil = isset($item['certificate_valid_to']) && $item['certificate_valid_to'] ? format_datetime((int)$item['certificate_valid_to'], 'd/m/Y H:i') : 'Sem informação';
                $createdAt = isset($item['created_at']) ? format_datetime((int)$item['created_at'], 'd/m/Y H:i') : '-';
                $selectedPermissions = $allPermissionKeys;
            ?>
                <details style="border:1px solid var(--border);border-radius:16px;background:rgba(15,23,42,0.65);">
                    <summary style="padding:18px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;cursor:pointer;list-style:none;">
                        <div>
                            <strong style="display:block;font-size:1.05rem;color:var(--text);">
                                <?= htmlspecialchars((string)($item['name'] ?? 'Usuário desconhecido'), ENT_QUOTES, 'UTF-8'); ?>
                            </strong>
                            <span style="display:block;margin-top:6px;color:var(--muted);font-size:0.85rem;">
                                CPF: <?= htmlspecialchars($cpfLabel, ENT_QUOTES, 'UTF-8'); ?> | Protocolo: <?= htmlspecialchars((string)($item['certificate_serial'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.8rem;">
                                Solicitado em <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <code style="font-size:0.75rem;color:var(--muted);padding:6px 10px;border-radius:999px;background:rgba(148,163,184,0.12);border:1px solid rgba(148,163,184,0.25);max-width:240px;">
                            <?= htmlspecialchars((string)$item['certificate_fingerprint'], ENT_QUOTES, 'UTF-8'); ?>
                        </code>
                    </summary>
                    <div style="padding:0 18px 18px 18px;">
                        <p style="margin:0 0 16px;color:var(--muted);font-size:0.8rem;">
                            Validade do certificado: <?= htmlspecialchars($validUntil, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
                            <form method="post" action="<?= url('admin/access-requests/' . (int)$item['id'] . '/approve'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                <?= csrf_field(); ?>
                                <label style="font-size:0.85rem;color:var(--muted);">
                                    Observação (opcional)
                                    <textarea name="reason" rows="2" style="margin-top:6px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);"></textarea>
                                </label>
                                <div>
                                    <span style="display:block;font-size:0.85rem;color:var(--muted);margin-bottom:10px;">Permissões liberadas</span>
                                    <?php $certificateMatrixId = $nextPermissionPanelId(); ?>
                                    <div class="permission-summary-wrapper">
                                        <div style="display:flex;flex-direction:column;gap:6px;flex:1;min-width:220px;">
                                            <span style="font-size:0.78rem;color:var(--muted);">Resumo atual do usuário</span>
                                            <?php $renderPermissionSummary($selectedPermissions); ?>
                                        </div>
                                        <button type="button"
                                            class="permission-toggle"
                                            data-permission-matrix-toggle="<?= $certificateMatrixId; ?>"
                                            data-collapsed-label="Ajustar módulos"
                                            data-expanded-label="Ocultar ajustes"
                                            aria-controls="<?= $certificateMatrixId; ?>"
                                            aria-expanded="false">
                                            Ajustar módulos
                                        </button>
                                    </div>
                                    <div class="permission-matrix" id="<?= $certificateMatrixId; ?>" data-permission-matrix="<?= $certificateMatrixId; ?>" hidden="hidden">
                                        <?php $renderPermissionMatrix($selectedPermissions); ?>
                                    </div>
                                </div>
                                <button type="submit" class="primary" data-loading-label="Liberando..." style="padding:12px;border-radius:999px;">Liberar acesso</button>
                            </form>
                            <form method="post" action="<?= url('admin/access-requests/' . (int)$item['id'] . '/deny'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                <?= csrf_field(); ?>
                                <label style="font-size:0.85rem;color:var(--muted);">
                                    Motivo da recusa
                                    <textarea name="reason" rows="2" style="margin-top:6px;padding:10px;border-radius:10px;border:1px solid rgba(248,113,113,0.45);background:rgba(127,29,29,0.35);color:#fecaca;"></textarea>
                                </label>
                                <button type="submit" data-loading-label="Enviando recusa..." style="padding:12px;border-radius:999px;background:rgba(248,113,113,0.15);border:1px solid rgba(248,113,113,0.45);color:#f87171;font-weight:600;">Recusar acesso</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="active" class="panel liberacao-section" data-liberacao-section="active" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.2rem;">Permissões de colaboradores ativos</h2>
        <span style="font-size:0.9rem;color:var(--muted);">Total: <?= count($activeUsers); ?></span>
    </div>

    <?php if (empty($activeUsers)): ?>
        <p style="margin-top:18px;color:var(--muted);">Nenhum colaborador ativo aguardando ajustes de permissão.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;margin-top:18px;">
            <?php foreach ($activeUsers as $user):
                $selectedPermissions = isset($user['permissions']) && is_array($user['permissions']) ? array_values(array_intersect($user['permissions'], $allPermissionKeys)) : [];
                $email = isset($user['email']) ? (string)$user['email'] : '—';
                $lastLogin = isset($user['last_login_at']) && $user['last_login_at'] ? format_datetime((int)$user['last_login_at'], 'd/m/Y H:i') : 'Nunca';
                $lastSeen = isset($user['last_seen_at']) && $user['last_seen_at'] ? format_datetime((int)$user['last_seen_at'], 'd/m/Y H:i') : 'Sem atividade recente';
                $isOnline = !empty($user['is_online']);
                $statusLabel = $isOnline ? 'Online' : 'Offline';
                $statusColor = $isOnline ? '#22c55e' : '#94a3b8';
                $forcedAt = isset($user['session_forced_at']) && $user['session_forced_at'] ? format_datetime((int)$user['session_forced_at'], 'd/m/Y H:i') : null;
                $lockedUntil = isset($user['locked_until']) && $user['locked_until'] ? (int)$user['locked_until'] : null;
                $lockActive = $lockedUntil !== null && $lockedUntil > now();
                $lockLabel = $lockActive ? format_datetime($lockedUntil, 'd/m/Y H:i') : null;
                $attempts = isset($user['failed_login_attempts']) ? (int)$user['failed_login_attempts'] : 0;
                $customWindow = ($user['access_allowed_from'] ?? null) !== null || ($user['access_allowed_until'] ?? null) !== null;
                $clientScope = $user['client_access_scope'] ?? 'all';
                $avpFilters = isset($user['avp_filters']) && is_array($user['avp_filters']) ? $user['avp_filters'] : [];
                $avpFiltersCount = isset($user['avp_filters_count']) ? (int)$user['avp_filters_count'] : count($avpFilters);
                $allowOnlineMode = !empty($user['allow_online_clients']);
                $allowInternalChat = !empty($user['allow_internal_chat']);
                $allowExternalChat = !empty($user['allow_external_chat']);
                $chatIdentifier = trim((string)($user['chat_identifier'] ?? ''));
                $chatDisplayName = trim((string)($user['chat_display_name'] ?? ''));
                $isAvpProfile = !empty($user['is_avp']);
                $avpIdentityLabel = trim((string)($user['avp_identity_label'] ?? ''));
                $avpIdentityCpf = trim((string)($user['avp_identity_cpf'] ?? ''));
                $cpfDigits = isset($user['cpf']) ? digits_only((string)$user['cpf']) : '';
                $cpfLabel = $cpfDigits !== '' ? format_document($cpfDigits) : 'Não informado';
                $permissionsPanelKey = 'user-' . (int)($user['id'] ?? 0);
            ?>
                <details style="border:1px solid var(--border);border-radius:16px;background:rgba(15,23,42,0.65);">
                    <summary style="padding:18px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;cursor:pointer;list-style:none;">
                        <div>
                            <strong style="display:block;font-size:1.05rem;color:var(--text);">
                                <?= htmlspecialchars((string)($user['name'] ?? 'Colaborador'), ENT_QUOTES, 'UTF-8'); ?>
                            </strong>
                            <span style="display:block;margin-top:6px;color:var(--muted);font-size:0.85rem;">
                                E-mail: <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.8rem;">
                                Último acesso: <?= htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?> | Último visto: <?= htmlspecialchars($lastSeen, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.78rem;">
                                Janela aplicada: <?= htmlspecialchars((string)($user['access_window_label'] ?? 'Segue horário padrão'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="display:block;margin-top:4px;color:var(--muted);font-size:0.78rem;">
                                CPF cadastrado: <?= htmlspecialchars($cpfLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px;max-width:560px;">
                                <span style="font-size:0.76rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;">Permissões liberadas</span>
                                <?php $renderPermissionSummary($selectedPermissions); ?>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:140px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid <?= $statusColor; ?>;color:<?= $statusColor; ?>;font-weight:600;font-size:0.8rem;">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $statusColor; ?>;"></span>
                                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($forcedAt !== null): ?>
                                <span style="font-size:0.75rem;color:#facc15;">Sessão forçada às <?= htmlspecialchars($forcedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($lockActive && $lockLabel !== null): ?>
                                <span style="font-size:0.75rem;color:#f87171;">Bloqueado até <?= htmlspecialchars($lockLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php elseif ($attempts > 0): ?>
                                <span style="font-size:0.75rem;color:#facc15;">Tentativas recentes: <?= (int)$attempts; ?></span>
                            <?php endif; ?>
                        </div>
                    </summary>
                    <div style="padding:0 18px 18px 18px;display:flex;flex-direction:column;gap:16px;">
                        <?php if (!empty($user['session_ip']) || !empty($user['session_location']) || !empty($user['session_user_agent'])):
                            $sessionSummaryParts = [];
                            if (!empty($user['session_started_label'])) {
                                $sessionSummaryParts[] = 'Desde ' . (string)$user['session_started_label'];
                            }
                            if (!empty($user['session_ip'])) {
                                $sessionSummaryParts[] = 'IP ' . (string)$user['session_ip'];
                            }
                            if (!empty($user['session_location'])) {
                                $sessionSummaryParts[] = (string)$user['session_location'];
                            }
                            $sessionSummaryText = $sessionSummaryParts === []
                                ? 'Clique para ver a sessão ativa'
                                : implode(' · ', $sessionSummaryParts);
                        ?>
                            <details class="user-collapsible" data-user-section="session">
                                <summary>
                                    <div class="user-collapsible-meta">
                                        <strong>Sessão atual</strong>
                                        <small><?= htmlspecialchars($sessionSummaryText, ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                    <span>Ver sessão</span>
                                </summary>
                                <div class="user-collapsible-body">
                                    <div style="display:grid;gap:8px;">
                                        <?php if (!empty($user['session_ip'])): ?>
                                            <span style="font-size:0.85rem;color:var(--muted);">IP: <code style="font-size:0.8rem;"><?= htmlspecialchars((string)$user['session_ip'], ENT_QUOTES, 'UTF-8'); ?></code></span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['session_location'])): ?>
                                            <span style="font-size:0.85rem;color:var(--muted);">Localidade aproximada: <?= htmlspecialchars((string)$user['session_location'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['session_started_label'])): ?>
                                            <span style="font-size:0.85rem;color:var(--muted);">Conectado desde: <?= htmlspecialchars((string)$user['session_started_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['session_user_agent'])): ?>
                                            <span style="font-size:0.8rem;color:var(--muted);">Navegador: <?= htmlspecialchars((string)$user['session_user_agent'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['current_device'])): ?>
                                            <span style="font-size:0.85rem;color:var(--muted);display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                                Equipamento atual:
                                                <span style="display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(94,234,212,0.35);background:rgba(13,148,136,0.15);color:#5eead4;font-size:0.8rem;">
                                                    <span><?= htmlspecialchars($user['current_device']['status_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <code style="font-size:0.75rem;"><?= htmlspecialchars($user['current_device']['fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code>
                                                </span>
                                            </span>
                                            <?php if (!empty($user['current_device']['last_seen_at_label'])): ?>
                                                <span style="font-size:0.78rem;color:var(--muted);">Último sinal: <?= htmlspecialchars($user['current_device']['last_seen_at_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($user['devices'])): ?>
                            <details class="device-list">
                                <summary style="cursor:pointer;font-size:0.85rem;color:var(--muted);display:flex;align-items:center;gap:8px;">
                                    Equipamentos registrados (<?= count($user['devices']); ?>)
                                </summary>
                                <div style="margin-top:12px;display:grid;gap:10px;">
                                    <?php foreach ($user['devices'] as $device): ?>
                                        <div style="padding:12px;border-radius:12px;background:rgba(15,23,42,0.45);border:1px solid rgba(148,163,184,0.25);display:grid;gap:6px;">
                                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                                <strong style="font-size:0.85rem;color:var(--text);">
                                                    <?= htmlspecialchars($device['label'] ?? 'Equipamento sem nome', ENT_QUOTES, 'UTF-8'); ?>
                                                </strong>
                                                <span style="padding:2px 8px;border-radius:999px;font-size:0.7rem;letter-spacing:0.04em;color:<?= $device['is_approved'] ? '#22c55e' : '#facc15'; ?>;border:1px solid <?= $device['is_approved'] ? 'rgba(34,197,94,0.45)' : 'rgba(250,204,21,0.45)'; ?>;background:<?= $device['is_approved'] ? 'rgba(34,197,94,0.12)' : 'rgba(250,204,21,0.12)'; ?>;">
                                                    <?= htmlspecialchars($device['status_label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </div>
                                            <div style="font-size:0.8rem;color:var(--muted);">Impressão digital: <code style="font-size:0.75rem;word-break:break-all;"><?= htmlspecialchars($device['fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                                            <?php if (!empty($device['last_ip']) || !empty($device['last_location'])): ?>
                                                <div style="font-size:0.8rem;color:var(--muted);">
                                                    Último IP conhecido: <?= htmlspecialchars($device['last_ip'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($device['last_location'])): ?>
                                                        <span>• <?= htmlspecialchars($device['last_location'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['last_seen_at_label'])): ?>
                                                <div style="font-size:0.8rem;color:var(--muted);">Último uso: <?= htmlspecialchars($device['last_seen_at_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['approved_at_label'])): ?>
                                                <div style="font-size:0.75rem;color:var(--muted);">Aprovado em <?= htmlspecialchars($device['approved_at_label'], ENT_QUOTES, 'UTF-8'); ?><?= !empty($device['approved_by']) ? ' por ' . htmlspecialchars($device['approved_by'], ENT_QUOTES, 'UTF-8') : ''; ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($device['user_agent'])): ?>
                                                <div style="font-size:0.75rem;color:var(--muted);">Agente de usuário: <?= htmlspecialchars($device['user_agent'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <details class="user-collapsible" data-user-section="access-window">
                            <summary>
                                <div class="user-collapsible-meta">
                                    <strong>Janela de acesso individual</strong>
                                    <small><?= htmlspecialchars((string)($user['access_window_label'] ?? 'Segue horário padrão'), ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <span style="padding:4px 10px;border-radius:999px;border:1px solid rgba(148,163,184,0.35);background:rgba(148,163,184,0.12);font-size:0.74rem;color:<?= $customWindow ? '#38bdf8' : '#94a3b8'; ?>;">
                                    <?= $customWindow ? 'Personalizada' : 'Padrão'; ?>
                                </span>
                            </summary>
                            <div class="user-collapsible-body">
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/access-window'); ?>" style="display:flex;flex-direction:column;gap:12px;">
                                    <?= csrf_field(); ?>
                                    <span style="font-size:0.78rem;color:var(--muted);">Deixe vazio para herdar o horário global configurado na política padrão.</span>
                                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                            Início liberado
                                            <input type="time" name="access_start" step="60" value="<?= htmlspecialchars((string)($user['access_window_start'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);min-width:130px;">
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                            Fim liberado
                                            <input type="time" name="access_end" step="60" value="<?= htmlspecialchars((string)($user['access_window_end'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);min-width:130px;">
                                        </label>
                                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);margin-bottom:6px;">
                                            <input type="checkbox" name="require_known_device" value="1" <?= !empty($user['require_known_device']) ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                            Exigir equipamento reconhecido para logar
                                        </label>
                                    </div>
                                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                                        <button type="submit" class="primary" data-loading-label="Salvando janela..." style="padding:10px 16px;border-radius:999px;">Salvar janela individual</button>
                                        <?php if ($customWindow): ?>
                                            <button type="submit" name="clear_window" value="1" formnovalidate data-loading-label="Removendo personalização..." style="padding:10px 16px;border-radius:999px;background:rgba(148,163,184,0.12);border:1px solid rgba(148,163,184,0.35);color:#94a3b8;">
                                                Remover personalização
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </details>

                        <?php
                            $identityLookup = $avpIdentityLookups[(int)($user['id'] ?? 0)] ?? null;
                            $lookupResults = isset($identityLookup['results']) && is_array($identityLookup['results']) ? $identityLookup['results'] : [];
                            $lookupQuery = isset($identityLookup['query']) ? (string)$identityLookup['query'] : '';
                            $lookupExpanded = !$isAvpProfile || $cpfDigits === '' || $identityLookup !== null;
                            $avpSummaryParts = [];
                            $avpSummaryParts[] = $isAvpProfile ? 'Agenda própria' : 'Sem agenda própria';
                            if ($avpIdentityLabel !== '') {
                                $avpSummaryParts[] = $avpIdentityLabel;
                            }
                            if ($avpIdentityCpf !== '') {
                                $summaryCpfDigits = digits_only($avpIdentityCpf);
                                if ($summaryCpfDigits !== '') {
                                    $avpSummaryParts[] = format_document($summaryCpfDigits);
                                }
                            }
                            if ($cpfDigits === '') {
                                $avpSummaryParts[] = 'CPF pendente';
                            }
                            $avpSummaryText = trim(implode(' · ', array_filter($avpSummaryParts)));
                            if ($avpSummaryText === '') {
                                $avpSummaryText = 'Configure como este colaborador aparece nas agendas';
                            }
                            $avpSectionOpen = $identityLookup !== null;
                        ?>
                        <details class="user-collapsible" data-user-section="avp-profile"<?= $avpSectionOpen ? ' open' : ''; ?>>
                            <summary>
                                <div class="user-collapsible-meta">
                                    <strong>Agenda e perfil de AVP</strong>
                                    <small><?= htmlspecialchars($avpSummaryText, ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <span style="padding:4px 10px;border-radius:999px;border:1px solid rgba(56,189,248,0.35);background:rgba(56,189,248,0.12);color:#38bdf8;font-size:0.74rem;">
                                    <?= $isAvpProfile ? 'AVP ativo' : 'Somente agendamentos'; ?>
                                </span>
                            </summary>
                            <div class="user-collapsible-body">
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <div>
                                        <strong style="font-size:0.9rem;color:var(--text);">Agenda e perfil de AVP</strong>
                                        <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">
                                            Busque este colaborador na carteira, importe o CPF com um clique e defina como o nome aparecerá para os clientes.
                                        </p>
                                    </div>
                                    <details<?= $lookupExpanded ? ' open' : ''; ?> style="border:1px solid rgba(148,163,184,0.25);border-radius:12px;padding:12px;background:rgba(15,23,42,0.45);">
                                <summary style="cursor:pointer;font-size:0.82rem;color:var(--text);font-weight:600;display:flex;align-items:center;gap:8px;list-style:none;">
                                    <span style="display:inline-flex;width:20px;height:20px;border-radius:50%;align-items:center;justify-content:center;background:rgba(56,189,248,0.12);border:1px solid rgba(56,189,248,0.35);color:#38bdf8;">
                                        <svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" aria-hidden="true">
                                            <circle cx="11" cy="11" r="7"></circle>
                                            <line x1="16" y1="16" x2="21" y2="21"></line>
                                        </svg>
                                    </span>
                                    Buscar CPF/nome na carteira
                                </summary>
                                <div style="margin-top:12px;display:flex;flex-direction:column;gap:12px;">
                                    <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/identity/lookup'); ?>" style="display:flex;flex-direction:column;gap:6px;">
                                        <?= csrf_field(); ?>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                            Termo de busca
                                            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                                <input type="text" name="lookup_query" value="<?= htmlspecialchars($lookupQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome completo, CPF ou protocolo" style="flex:1;min-width:220px;padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                                <button type="submit" class="primary" data-loading-label="Buscando..." style="padding:10px 16px;border-radius:999px;font-size:0.8rem;display:inline-flex;align-items:center;gap:6px;">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" aria-hidden="true">
                                                        <circle cx="11" cy="11" r="7"></circle>
                                                        <line x1="16" y1="16" x2="21" y2="21"></line>
                                                    </svg>
                                                    Buscar
                                                </button>
                                            </div>
                                        </label>
                                        <span style="font-size:0.72rem;color:var(--muted);">Ao vincular um cliente, importamos o CPF e marcamos este usuário como AVP automaticamente.</span>
                                    </form>
                                    <?php if ($identityLookup !== null): ?>
                                        <?php if ($lookupResults === []): ?>
                                            <p style="margin:0;color:#facc15;font-size:0.78rem;">Nenhum cadastro foi encontrado para "<?= htmlspecialchars($lookupQuery, ENT_QUOTES, 'UTF-8'); ?>". Ajuste o nome ou CPF e tente novamente.</p>
                                        <?php else: ?>
                                            <div style="display:flex;flex-direction:column;gap:10px;">
                                                <?php foreach ($lookupResults as $candidate):
                                                    $candidateId = (int)($candidate['id'] ?? 0);
                                                    if ($candidateId <= 0) {
                                                        continue;
                                                    }
                                                    $candidateName = (string)($candidate['name'] ?? 'Cliente sem nome');
                                                    $candidateDocument = isset($candidate['document']) && $candidate['document'] !== null && $candidate['document'] !== ''
                                                        ? format_document((string)$candidate['document'])
                                                        : 'Documento não informado';
                                                    $candidateStatus = isset($candidate['status']) ? trim((string)$candidate['status']) : '';
                                                ?>
                                                    <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/identity/link'); ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;border:1px solid rgba(148,163,184,0.25);border-radius:12px;padding:10px 12px;background:rgba(15,23,42,0.35);">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="client_id" value="<?= $candidateId; ?>">
                                                        <div style="flex:1;min-width:220px;display:flex;flex-direction:column;gap:2px;">
                                                            <strong style="color:var(--text);font-size:0.85rem;"><?= htmlspecialchars($candidateName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                            <span style="font-size:0.78rem;color:var(--muted);">CPF/CNPJ: <?= htmlspecialchars($candidateDocument, ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php if ($candidateStatus !== ''): ?>
                                                                <span style="font-size:0.72rem;color:var(--muted);">Status atual: <?= htmlspecialchars($candidateStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <button type="submit" class="primary" data-loading-label="Vinculando..." style="padding:8px 14px;border-radius:999px;font-size:0.78rem;">Vincular CPF e nome</button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/identity'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                <?= csrf_field(); ?>
                                <div>
                                    <strong style="font-size:0.9rem;color:var(--text);">Ajuste manual do CPF</strong>
                                    <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">Use apenas se precisar corrigir o CPF importado automaticamente. Deixe em branco para remover.</p>
                                </div>
                                <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                    CPF do colaborador
                                    <input type="text" name="cpf" value="<?= htmlspecialchars($cpfDigits !== '' ? format_document($cpfDigits) : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Somente números" maxlength="14" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                </label>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                    <button type="submit" class="primary" data-loading-label="Salvando CPF..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">Salvar CPF</button>
                                    <?php if ($cpfDigits === ''): ?>
                                        <span style="font-size:0.75rem;color:#facc15;">Sem CPF cadastrado, alguns atendimentos podem não ser identificados automaticamente.</span>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem;color:var(--muted);">Atual: <?= htmlspecialchars($cpfLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">
                                Com o CPF vinculado, este colaborador pode ter agenda própria ou apenas agendar para outros AVPs.
                            </p>
                            <?php if (($user['role'] ?? '') === 'admin'): ?>
                                <div style="padding:12px;border-radius:12px;background:rgba(56,189,248,0.12);border:1px solid rgba(56,189,248,0.35);color:#38bdf8;font-size:0.8rem;">
                                    Administradores visualizam todas as agendas automaticamente e não possuem agenda própria.
                                </div>
                            <?php else: ?>
                                <?php $avpProfileListId = 'avp-profile-options-' . (int)$user['id']; ?>
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/avp-profile'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                    <?= csrf_field(); ?>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                                        <input type="checkbox" name="is_avp" value="1" <?= $isAvpProfile ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                        Este usuário possui agenda própria (é um AVP)
                                    </label>
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                        Nome do AVP exibido para clientes
                                        <input type="text" name="avp_identity_label" list="<?= $avpProfileListId; ?>" value="<?= htmlspecialchars($avpIdentityLabel, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: ANDREIA COSTA" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                    </label>
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                        CPF exibido no perfil
                                        <input type="text" name="avp_identity_cpf" value="<?= htmlspecialchars($avpIdentityCpf !== '' ? format_document($avpIdentityCpf) : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Somente números" maxlength="14" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                    </label>
                                    <?php if (!empty($availableAvps)): ?>
                                        <datalist id="<?= $avpProfileListId; ?>">
                                            <?php foreach ($availableAvps as $option): ?>
                                                <option value="<?= htmlspecialchars((string)$option, ENT_QUOTES, 'UTF-8'); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    <?php endif; ?>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                        <button type="submit" class="primary" data-loading-label="Salvando perfil..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">Salvar perfil de AVP</button>
                                        <span style="font-size:0.72rem;color:var(--muted);">Sem vínculo, o usuário pode agendar para outros AVPs mas não terá agenda própria.</span>
                                    </div>
                                </form>
                            <?php endif; ?>
                                </div>
                            </div>
                        </details>

                        <?php
                            $clientScopeLabel = $clientScope === 'all' ? 'Carteira completa' : 'Carteira sob demanda';
                            $clientSummaryParts = [$clientScopeLabel, 'AVPs ' . (int)$avpFiltersCount];
                            if ($clientScope === 'custom' && $allowOnlineMode) {
                                $clientSummaryParts[] = 'Modo on-line ativo';
                            }
                            $clientSummaryText = implode(' · ', array_filter($clientSummaryParts));
                        ?>
                        <details class="user-collapsible" data-user-section="client-access">
                            <summary>
                                <div class="user-collapsible-meta">
                                    <strong>Liberação de carteira e agendas</strong>
                                    <small><?= htmlspecialchars($clientSummaryText, ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <span style="padding:4px 10px;border-radius:999px;border:1px solid rgba(148,163,184,0.35);background:rgba(148,163,184,0.12);font-size:0.74rem;color:#94a3b8;">
                                    <?= $clientScope === 'all' ? 'Tudo liberado' : 'Seleção manual'; ?>
                                </span>
                            </summary>
                            <div class="user-collapsible-body">
                                <div style="padding:16px;border-radius:14px;background:rgba(15,23,42,0.55);border:1px solid rgba(148,163,184,0.25);display:flex;flex-direction:column;gap:12px;">
                            <div>
                                <strong style="font-size:0.9rem;color:var(--text);">Liberação de carteira e agendas</strong>
                                <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">
                                    Escolha se este colaborador enxerga toda a carteira ou somente os clientes e agendas dos AVPs liberados abaixo. As autorizações valem tanto para consultar quanto para criar compromissos em nome desses AVPs.
                                </p>
                            </div>
                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/client-access/scope'); ?>" style="display:flex;flex-direction:column;gap:8px;">
                                <?= csrf_field(); ?>
                                <label style="display:flex;align-items:center;gap:10px;font-size:0.82rem;color:var(--muted);">
                                    <input type="radio" name="scope" value="all" <?= $clientScope === 'all' ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                    Liberação total (todos os clientes)
                                </label>
                                <label style="display:flex;align-items:center;gap:10px;font-size:0.82rem;color:var(--muted);">
                                    <input type="radio" name="scope" value="custom" <?= $clientScope === 'custom' ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                    Somente clientes com AVPs liberados manualmente
                                </label>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                    <button type="submit" class="primary" data-loading-label="Aplicando políticas..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">Aplicar liberação</button>
                                    <span style="font-size:0.75rem;color:var(--muted);">AVPs liberados: <?= (int)$avpFiltersCount; ?></span>
                                </div>
                            </form>

                            <?php if ($clientScope === 'custom'): ?>
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/client-access/sync'); ?>" style="display:flex;flex-direction:column;gap:8px;">
                                    <?= csrf_field(); ?>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                        <button type="submit" class="primary" data-loading-label="Sincronizando..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">
                                            Liberar AVPs atendidos
                                        </button>
                                        <span style="font-size:0.75rem;color:var(--muted);">Sincroniza automaticamente usando o CPF/nome cadastrado e o histórico importado.</span>
                                    </div>
                                    <?php if (empty($user['cpf'] ?? null)): ?>
                                        <span style="font-size:0.72rem;color:#facc15;">Cadastre o CPF deste colaborador para melhorar a identificação automática.</span>
                                    <?php endif; ?>
                                </form>
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/client-access/allow-online'); ?>" style="display:flex;flex-direction:column;gap:8px;">
                                    <?= csrf_field(); ?>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                                            <input type="checkbox" name="allow_online_clients" value="1" <?= $allowOnlineMode ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                            Liberar clientes sem AVP (modo on-line)
                                        </label>
                                        <button type="submit" class="primary" data-loading-label="Salvando preferência..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">
                                            Salvar preferencia
                                        </button>
                                    </div>
                                    <span style="font-size:0.72rem;color:var(--muted);">
                                        Quando ativo, clientes sem historico de AVP ficam visiveis neste modo <?= $allowOnlineMode ? 'atualmente habilitado' : 'atualmente desativado'; ?>.
                                    </span>
                                </form>
                                <?php
                                    $userAvpOptions = [];
                                    foreach ($availableAvps as $optionValue) {
                                        $optionValue = trim((string)$optionValue);
                                        if ($optionValue !== '') {
                                            $userAvpOptions[] = $optionValue;
                                        }
                                    }
                                    foreach ($avpFilters as $filterOption) {
                                        $label = isset($filterOption['label']) ? trim((string)$filterOption['label']) : '';
                                        if ($label !== '') {
                                            $userAvpOptions[] = $label;
                                        }
                                    }
                                    $userAvpOptions = array_values(array_unique($userAvpOptions));
                                ?>
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/client-access/add'); ?>" style="display:flex;flex-direction:column;gap:8px;">
                                    <?= csrf_field(); ?>
                                        <div style="display:flex;flex-direction:column;gap:4px;font-size:0.8rem;color:var(--muted);">
                                            <strong style="font-size:0.85rem;color:var(--text);">Adicionar AVPs manualmente</strong>
                                            <span>Marque os AVPs que este colaborador poderá visualizar e agendar. Os clientes desses AVPs ficam disponíveis na mesma hora.</span>
                                    </div>
                                    <?php if ($userAvpOptions === []): ?>
                                        <p style="margin:0;font-size:0.78rem;color:#facc15;">Ainda não encontramos AVPs na base. Utilize o campo "Outro AVP" abaixo para cadastrar manualmente.</p>
                                    <?php else: ?>
                                        <div class="avp-multiselect-grid">
                                            <?php foreach ($userAvpOptions as $optionIndex => $optionLabel):
                                                $checkboxId = 'avp-select-' . (int)$user['id'] . '-' . $optionIndex;
                                            ?>
                                                <label for="<?= htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="checkbox" id="<?= htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>" name="avp_labels[]" value="<?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <span><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                        Outro AVP (opcional)
                                        <input type="text" name="custom_avp_label" placeholder="Nome do AVP" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                    </label>
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                        CPF do AVP (apenas para o campo acima)
                                        <input type="text" name="custom_avp_cpf" placeholder="Somente números" maxlength="14" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.45);color:var(--text);">
                                    </label>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                        <button type="submit" class="primary" data-loading-label="Salvando seleção..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">Salvar seleção</button>
                                        <span style="font-size:0.75rem;color:var(--muted);">Clientes e agendas cujo último AVP corresponda aos selecionados ficam liberados imediatamente.</span>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if ($avpFiltersCount > 0): ?>
                                <div style="overflow-x:auto;">
                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                        <thead>
                                            <tr style="background:rgba(30,41,59,0.45);">
                                                <th style="text-align:left;padding:8px;color:var(--muted);">AVP liberado</th>
                                                <th style="text-align:left;padding:8px;color:var(--muted);">CPF relacionado</th>
                                                <?php if ($clientScope === 'custom'): ?>
                                                    <th style="width:120px;padding:8px;"></th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($avpFilters as $filter): ?>
                                                <tr style="border-bottom:1px solid rgba(148,163,184,0.12);">
                                                    <td style="padding:8px;color:var(--text);font-weight:600;">
                                                        <?= htmlspecialchars((string)($filter['label'] ?? 'AVP'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <td style="padding:8px;color:var(--muted);">
                                                        <?php $cpfValue = isset($filter['avp_cpf']) && $filter['avp_cpf'] !== null ? format_document((string)$filter['avp_cpf']) : '—'; ?>
                                                        <?= htmlspecialchars($cpfValue, ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <?php if ($clientScope === 'custom'): ?>
                                                        <td style="padding:8px;text-align:right;">
                                                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/client-access/' . (int)($filter['id'] ?? 0) . '/remove'); ?>" onsubmit="return confirm('Remover este AVP das liberações?');">
                                                                <?= csrf_field(); ?>
                                                                <button type="submit" data-loading-label="Removendo..." style="padding:6px 10px;border-radius:999px;background:rgba(248,113,113,0.15);border:1px solid rgba(248,113,113,0.35);color:#f87171;font-size:0.75rem;">
                                                                    Remover
                                                                </button>
                                                            </form>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($clientScope === 'custom'): ?>
                                <p style="margin:0;font-size:0.78rem;color:var(--muted);">Nenhum AVP liberado ainda. Utilize o campo acima para liberar a carteira e o acesso às agendas.</p>
                            <?php endif; ?>
                                </div>
                            </div>
                        </details>

                        <?php
                            $chatSummaryParts = [
                                'Interno: ' . ($allowInternalChat ? 'liberado' : 'bloqueado'),
                                'Externo: ' . ($allowExternalChat ? 'liberado' : 'bloqueado'),
                            ];
                        ?>
                        <details class="user-collapsible" data-user-section="chat">
                            <summary>
                                <div class="user-collapsible-meta">
                                    <strong>Liberação do chat</strong>
                                    <small><?= htmlspecialchars(implode(' · ', $chatSummaryParts), ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <span style="padding:4px 10px;border-radius:999px;border:1px solid rgba(59,130,246,0.35);background:rgba(59,130,246,0.12);color:#60a5fa;font-size:0.74rem;">
                                    Ajustar chat
                                </span>
                            </summary>
                            <div class="user-collapsible-body">
                                <div style="padding:16px;border-radius:14px;background:rgba(15,23,42,0.55);border:1px solid rgba(148,163,184,0.25);display:flex;flex-direction:column;gap:12px;">
                                    <div>
                                        <strong style="font-size:0.9rem;color:var(--text);">Liberação do chat</strong>
                                        <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">
                                            Defina se este colaborador pode iniciar conversas internas ou atender leads externos do site.
                                        </p>
                                    </div>
                                    <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/chat-permissions'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                        <?= csrf_field(); ?>
                                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                                            <input type="checkbox" name="allow_internal_chat" value="1" <?= $allowInternalChat ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                            Liberar uso interno (entre colaboradores)
                                        </label>
                                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                                            <input type="checkbox" name="allow_external_chat" value="1" <?= $allowExternalChat ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                                            Liberar atendimento externo (leads do chat do site)
                                        </label>
                                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                            <button type="submit" class="primary" data-loading-label="Salvando acesso ao chat..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">Salvar liberação do chat</button>
                                            <span style="font-size:0.75rem;color:var(--muted);">
                                                Status: <?= $allowInternalChat ? 'uso interno liberado' : 'uso interno bloqueado'; ?> · <?= $allowExternalChat ? 'atendimento externo liberado' : 'atendimento externo bloqueado'; ?>
                                            </span>
                                        </div>
                                    </form>
                                    <?php if (!$allowInternalChat && !$allowExternalChat): ?>
                                        <div style="padding:10px 12px;border-radius:10px;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.35);color:#f87171;font-size:0.78rem;">
                                            Este colaborador não consegue usar o chat até que ao menos uma das opções acima seja liberada.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>

                        <details class="user-collapsible" data-user-section="chat-identifier">
                            <summary>
                                <div class="user-collapsible-meta">
                                    <strong>Identificador nas conversas</strong>
                                    <small>
                                        <?= $chatIdentifier !== ''
                                            ? htmlspecialchars($chatIdentifier . ' - ' . (string)$user['name'], ENT_QUOTES, 'UTF-8')
                                            : 'Sem identificador configurado'; ?>
                                    </small>
                                </div>
                                <span style="padding:4px 10px;border-radius:999px;border:1px solid rgba(14,165,233,0.4);background:rgba(14,165,233,0.12);color:#38bdf8;font-size:0.74rem;">
                                    Atualizar identificador
                                </span>
                            </summary>
                            <div class="user-collapsible-body">
                                <div style="padding:16px;border-radius:14px;background:rgba(15,23,42,0.55);border:1px solid rgba(148,163,184,0.25);display:flex;flex-direction:column;gap:12px;">
                                    <div>
                                        <strong style="font-size:0.9rem;color:var(--text);">Prefixo exibido no WhatsApp</strong>
                                        <p style="margin:4px 0 0;color:var(--muted);font-size:0.78rem;">
                                            Este identificador aparece antes das mensagens enviadas pelo colaborador (ex.: <code>AGR</code> gera &ldquo;AGR - Nome do agente&rdquo;).
                                        </p>
                                    </div>
                                    <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/chat-identifier'); ?>" style="display:flex;flex-direction:column;gap:10px;">
                                        <?= csrf_field(); ?>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                            <span>Identificador (até 32 caracteres)</span>
                                            <input type="text"
                                                name="chat_identifier"
                                                maxlength="32"
                                                value="<?= htmlspecialchars($chatIdentifier, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="AGR"
                                                style="border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.35);color:var(--text);padding:10px;">
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                                            <span>Nome exibido para o cliente (opcional)</span>
                                            <input type="text"
                                                name="chat_display_name"
                                                maxlength="80"
                                                value="<?= htmlspecialchars($chatDisplayName, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="Equipe Suporte"
                                                style="border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.35);color:var(--text);padding:10px;">
                                            <small>Deixe em branco para usar o nome completo cadastrado.</small>
                                        </label>
                                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                            <button type="submit" class="primary" data-loading-label="Salvando identificador..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">
                                                Salvar identificador
                                            </button>
                                            <?php if ($chatIdentifier !== ''): ?>
                                                <button type="submit" name="clear_identifier" value="1" class="ghost" data-loading-label="Removendo identificador..." style="padding:8px 14px;border-radius:999px;font-size:0.8rem;">
                                                    Remover identificador
                                                </button>
                                            <?php endif; ?>
                                            <span style="font-size:0.75rem;color:var(--muted);">
                                                Configurado: <?= $chatIdentifier !== '' ? htmlspecialchars($chatIdentifier, ENT_QUOTES, 'UTF-8') : 'nenhum'; ?>
                                            </span>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </details>

                        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                            <button type="button"
                                class="permission-toggle"
                                data-permissions-toggle="<?= htmlspecialchars($permissionsPanelKey, ENT_QUOTES, 'UTF-8'); ?>"
                                aria-expanded="false"
                                aria-controls="<?= htmlspecialchars($permissionsPanelKey, ENT_QUOTES, 'UTF-8'); ?>">
                                Ajustar permissões
                            </button>
                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/deactivate'); ?>">
                                <?= csrf_field(); ?>
                                <button type="submit" data-loading-label="Desativando..." style="padding:10px 16px;border-radius:999px;background:rgba(148,163,184,0.12);border:1px solid rgba(148,163,184,0.35);color:#94a3b8;font-weight:600;">
                                    Desativar acesso
                                </button>
                            </form>
                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/delete'); ?>" onsubmit="return confirm('Tem certeza que deseja excluir este colaborador? Esta ação não pode ser desfeita.');">
                                <?= csrf_field(); ?>
                                <button type="submit" data-loading-label="Excluindo..." style="padding:10px 16px;border-radius:999px;background:rgba(248,113,113,0.18);border:1px solid rgba(248,113,113,0.45);color:#f87171;font-weight:600;">
                                    Excluir definitivamente
                                </button>
                            </form>
                        </div>
                        <div id="<?= htmlspecialchars($permissionsPanelKey, ENT_QUOTES, 'UTF-8'); ?>" data-permissions-panel="<?= htmlspecialchars($permissionsPanelKey, ENT_QUOTES, 'UTF-8'); ?>" hidden="hidden" style="display:flex;flex-direction:column;gap:14px;">
                            <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/permissions'); ?>" style="display:flex;flex-direction:column;gap:12px;">
                                <?= csrf_field(); ?>
                                <div>
                                    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">
                                        <span style="display:block;font-size:0.85rem;color:var(--muted);">Selecione os módulos que continuarão liberados</span>
                                        <?php $renderPermissionSummary($selectedPermissions); ?>
                                    </div>
                                    <?php $renderPermissionMatrix($selectedPermissions); ?>
                                </div>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                                    <button type="submit" class="primary" data-loading-label="Salvando permissões..." style="padding:12px 18px;border-radius:999px;">Salvar permissões</button>
                                    <span style="font-size:0.8rem;color:var(--muted);">As mudanças têm efeito imediato para o colaborador.</span>
                                </div>
                            </form>
                            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                                <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/force-off'); ?>">
                                    <?= csrf_field(); ?>
                                    <button type="submit" title="Encerra a sessão atual e libera novas tentativas de login" data-loading-label="Forçando logout..." style="padding:10px 16px;border-radius:999px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;font-weight:600;">
                                        Forçar logout
                                    </button>
                                </form>
                                <button type="button" data-password-reset-trigger="<?= (int)$user['id']; ?>" style="padding:10px 16px;border-radius:999px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.35);color:#60a5fa;font-weight:600;">
                                    Redefinir senha
                                </button>
                            </div>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="disabled" class="panel liberacao-section" data-liberacao-section="disabled" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.2rem;">Colaboradores desativados</h2>
        <span style="font-size:0.9rem;color:var(--muted);">Total: <?= count($disabledUsers); ?></span>
    </div>

    <?php if (empty($disabledUsers)): ?>
        <p style="margin-top:18px;color:var(--muted);">Nenhum colaborador está desativado no momento.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;margin-top:18px;">
            <?php foreach ($disabledUsers as $user):
                $email = isset($user['email']) ? (string)$user['email'] : '—';
                $lastLogin = isset($user['last_login_at']) && $user['last_login_at'] ? format_datetime((int)$user['last_login_at'], 'd/m/Y H:i') : 'Nunca';
                $selectedPermissions = isset($user['permissions']) && is_array($user['permissions']) ? array_values(array_intersect($user['permissions'], $allPermissionKeys)) : [];
                $permissionNames = array_map(static function (string $key) use ($permissionLabels): string {
                    return $permissionLabels[$key] ?? $key;
                }, $selectedPermissions);
                $lockedUntil = isset($user['locked_until']) && $user['locked_until'] ? (int)$user['locked_until'] : null;
                $lockActive = $lockedUntil !== null && $lockedUntil > now();
                $lockLabel = $lockActive ? format_datetime($lockedUntil, 'd/m/Y H:i') : null;
            ?>
                <article style="border:1px solid var(--border);border-radius:16px;padding:18px;background:rgba(15,23,42,0.55);">
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <h3 style="margin:0;font-size:1.05rem;">
                            <?= htmlspecialchars((string)($user['name'] ?? 'Colaborador'), ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;">
                            E-mail: <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </p>
                        <p style="margin:0;color:var(--muted);font-size:0.85rem;">Último acesso registrado: <?= htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p style="margin:0;color:var(--muted);font-size:0.8rem;">Status atual: <strong style="color:#facc15;">Desativado</strong></p>
                        <p style="margin:6px 0 0;color:var(--muted);font-size:0.8rem;">
                            Permissões mantidas: <?= $permissionNames === [] ? 'Nenhuma' : htmlspecialchars(implode(', ', $permissionNames), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <?php if ($lockActive && $lockLabel !== null): ?>
                            <p style="margin:0;color:#f87171;font-size:0.8rem;">Bloqueado por tentativas até <?= htmlspecialchars($lockLabel, ENT_QUOTES, 'UTF-8'); ?>.</p>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">
                        <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/activate'); ?>">
                            <?= csrf_field(); ?>
                            <button type="submit" data-loading-label="Reativando..." style="padding:10px 16px;border-radius:999px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.45);color:#4ade80;font-weight:600;">
                                Reativar acesso
                            </button>
                        </form>
                        <form method="post" action="<?= url('admin/users/' . (int)$user['id'] . '/delete'); ?>" onsubmit="return confirm('Tem certeza que deseja excluir este colaborador desativado?');">
                            <?= csrf_field(); ?>
                            <button type="submit" data-loading-label="Excluindo..." style="padding:10px 16px;border-radius:999px;background:rgba(248,113,113,0.18);border:1px solid rgba(248,113,113,0.45);color:#f87171;font-weight:600;">
                                Excluir definitivamente
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="history" class="panel liberacao-section" data-liberacao-section="history">
    <h2 style="margin:0 0 16px;font-size:1.2rem;">Histórico recente</h2>
    <?php if (empty($recent)): ?>
        <p style="margin:0;color:var(--muted);">Nenhuma decisão recente registrada.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:640px;">
                <thead>
                    <tr style="background:rgba(30,41,59,0.65);">
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">Nome</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">CPF</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">Impressão digital</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">Status</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">Decidido em</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.25);">Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $item):
                        $cpfDigits = isset($item['cpf']) ? digits_only((string)$item['cpf']) : '';
                        $cpfLabel = $cpfDigits !== '' ? format_document($cpfDigits) : '—';
                        $status = strtoupper((string)$item['status']);
                        $decidedAt = isset($item['decided_at']) ? format_datetime((int)$item['decided_at'], 'd/m/Y H:i') : '-';
                        $badgeColor = $status === 'APPROVED' ? '#22c55e' : ($status === 'DENIED' ? '#f87171' : '#facc15');
                    ?>
                        <tr>
                            <td style="padding:12px;color:var(--text);font-weight:600;"><?= htmlspecialchars((string)($item['name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:12px;color:var(--muted);"><?= htmlspecialchars($cpfLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:12px;color:var(--muted);font-size:0.85rem;">
                                <code><?= htmlspecialchars((string)$item['certificate_fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code>
                            </td>
                            <td style="padding:12px;">
                                <span style="padding:6px 12px;border-radius:999px;background:rgba(255,255,255,0.05);border:1px solid <?= $badgeColor; ?>;color:<?= $badgeColor; ?>;font-size:0.75rem;font-weight:600;">
                                    <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding:12px;color:var(--muted);"><?= htmlspecialchars($decidedAt, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:12px;color:var(--muted);font-size:0.9rem;"><?= htmlspecialchars((string)($item['reason'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

    </div>
</div>

<dialog data-password-reset-modal style="padding:0;border:none;border-radius:18px;max-width:420px;width:100%;box-shadow:0 40px 90px -20px rgba(15,23,42,0.55);">
    <form method="post" data-password-reset-form style="padding:24px;display:flex;flex-direction:column;gap:16px;">
        <?= csrf_field(); ?>
        <h2 style="margin:0;font-size:1.3rem;">Redefinir senha</h2>
        <p style="margin:0;color:var(--muted);font-size:0.9rem;">Informe uma nova senha forte para o colaborador selecionado. Ele terá que entrar novamente usando essa senha.</p>
        <input type="hidden" name="reset_user_id" value="">
        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
            Nova senha
            <input type="password" name="password" minlength="8" required style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.55);color:var(--text);">
        </label>
        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
            Confirmar senha
            <input type="password" name="password_confirmation" minlength="8" required style="padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.55);color:var(--text);">
        </label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="primary" data-loading-label="Atualizando senha..." data-loading-allow-prevented="true" style="flex:1;padding:12px;border-radius:999px;">Salvar nova senha</button>
            <button type="button" data-password-reset-close style="padding:12px;border-radius:999px;background:rgba(148,163,184,0.12);border:1px solid rgba(148,163,184,0.35);color:var(--muted);">Cancelar</button>
        </div>
    </form>
</dialog>

    <script>
        (function () {
            var modal = document.querySelector('[data-password-reset-modal]');
            if (!modal) {
                return;
            }

            var form = modal.querySelector('[data-password-reset-form]');
            var closeBtn = modal.querySelector('[data-password-reset-close]');
            var hiddenId = form.querySelector('input[name="reset_user_id"]');

            function closeModal() {
                if (typeof modal.close === 'function') {
                    try { modal.close(); } catch (error) { /* ignore */ }
                } else {
                    modal.setAttribute('hidden', 'hidden');
                }
                form.reset();
            }

            document.querySelectorAll('[data-password-reset-trigger]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var userId = button.getAttribute('data-password-reset-trigger');
                    hiddenId.value = userId || '';

                    if (typeof modal.showModal === 'function') {
                        try { modal.showModal(); } catch (error) { modal.removeAttribute('hidden'); }
                    } else {
                        modal.removeAttribute('hidden');
                    }
                });
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    closeModal();
                });
            }

            modal.addEventListener('cancel', function (event) {
                event.preventDefault();
                closeModal();
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var userId = hiddenId.value;
                if (!userId) {
                    closeModal();
                    return;
                }

                var password = form.querySelector('input[name="password"]').value || '';
                var confirmation = form.querySelector('input[name="password_confirmation"]').value || '';

                if (password.length < 8) {
                    alert('A senha deve ter pelo menos 8 caracteres.');
                    return;
                }

                if (password !== confirmation) {
                    alert('As senhas informadas não conferem.');
                    return;
                }

                var action = '<?= url('admin/users'); ?>/' + encodeURIComponent(userId) + '/reset-password';
                var payload = new FormData();
                payload.append('password', password);
                payload.append('password_confirmation', confirmation);
                if (window.CSRF_TOKEN) {
                    payload.append('_token', window.CSRF_TOKEN);
                }

                fetch(action, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin'
                }).then(function () {
                    window.location.reload();
                }).catch(function () {
                    alert('Não foi possível redefinir a senha agora. Tente novamente.');
                });
            });

            document.querySelectorAll('[data-permissions-toggle]').forEach(function (button) {
                var key = button.getAttribute('data-permissions-toggle');
                if (!key) {
                    return;
                }

                var panel = document.querySelector('[data-permissions-panel="' + key + '"]');
                if (!panel) {
                    return;
                }

                button.addEventListener('click', function () {
                    var isHidden = panel.hasAttribute('hidden');
                    if (isHidden) {
                        panel.removeAttribute('hidden');
                        button.setAttribute('aria-expanded', 'true');
                    } else {
                        panel.setAttribute('hidden', 'hidden');
                        button.setAttribute('aria-expanded', 'false');
                    }
                });
            });

            document.querySelectorAll('[data-permission-matrix-toggle]').forEach(function (button) {
                var key = button.getAttribute('data-permission-matrix-toggle');
                if (!key) {
                    return;
                }

                var panel = document.querySelector('[data-permission-matrix="' + key + '"]');
                if (!panel) {
                    return;
                }

                var collapsedLabel = button.getAttribute('data-collapsed-label') || button.textContent.trim() || 'Ver módulos';
                var expandedLabel = button.getAttribute('data-expanded-label') || collapsedLabel;

                var syncState = function (expanded) {
                    if (expanded) {
                        panel.removeAttribute('hidden');
                        button.setAttribute('aria-expanded', 'true');
                        button.textContent = expandedLabel;
                    } else {
                        panel.setAttribute('hidden', 'hidden');
                        button.setAttribute('aria-expanded', 'false');
                        button.textContent = collapsedLabel;
                    }
                };

                button.addEventListener('click', function () {
                    var nextExpanded = panel.hasAttribute('hidden');
                    syncState(nextExpanded);
                });

                syncState(!panel.hasAttribute('hidden'));
            });
        }());
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var layout = document.querySelector('[data-liberacao-layout]');
            if (!layout) {
                return;
            }

            layout.classList.add('liberacao-js-ready');

            var buttons = Array.from(layout.querySelectorAll('[data-liberacao-target]'));
            var sections = Array.from(layout.querySelectorAll('[data-liberacao-section]'));

            var activate = function (key, updateHash) {
                if (updateHash === void 0) {
                    updateHash = true;
                }

                var found = false;

                sections.forEach(function (section) {
                    var isMatch = section.dataset.liberacaoSection === key;
                    section.classList.toggle('is-active', isMatch);
                    if (isMatch) {
                        found = true;
                    }
                });

                buttons.forEach(function (button) {
                    button.classList.toggle('is-active', button.dataset.liberacaoTarget === key);
                });

                if (found && updateHash) {
                    var hash = '#' + key;
                    if (history.replaceState) {
                        history.replaceState(null, '', hash);
                    } else {
                        window.location.hash = hash;
                    }
                }
            };

            var initialHash = window.location.hash ? window.location.hash.substring(1) : '';
            var defaultKey = (buttons[0] && buttons[0].dataset.liberacaoTarget) || (sections[0] && sections[0].dataset.liberacaoSection) || '';
            var startKey = sections.some(function (section) {
                return section.dataset.liberacaoSection === initialHash;
            }) ? initialHash : defaultKey;

            if (startKey) {
                activate(startKey, false);
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var key = button.dataset.liberacaoTarget;
                    if (key) {
                        activate(key);
                    }
                });
            });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var lastClickedSubmit = null;

            document.addEventListener('click', function (event) {
                var button = event.target.closest('button[type="submit"][data-loading-label]');
                if (button) {
                    lastClickedSubmit = button;
                }
            }, true);

            document.querySelectorAll('form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var submitter = event.submitter || lastClickedSubmit;
                    if (!submitter || submitter.form !== form) {
                        return;
                    }

                    lastClickedSubmit = null;

                    var allowPrevented = submitter.hasAttribute('data-loading-allow-prevented');
                    if (event.defaultPrevented && !allowPrevented) {
                        return;
                    }

                    if (submitter.dataset.loadingActive === 'true') {
                        return;
                    }

                    var label = submitter.getAttribute('data-loading-label');
                    if (!label) {
                        return;
                    }

                    submitter.dataset.loadingActive = 'true';
                    submitter.dataset.originalLabel = submitter.innerHTML;
                    submitter.disabled = true;

                    submitter.innerHTML = '<span class="action-button-loading"><span class="action-button-spinner" aria-hidden="true"></span><span>' + label + '</span></span>';
                    submitter.classList.add('action-button-busy');
                });
            });
        });
    </script>
