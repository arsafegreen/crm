<?php
/** @var array $emailSettings */
/** @var array $templates */
/** @var array $accounts */
/** @var array|null $feedback */
/** @var array|null $emailFeedback */
/** @var array|null $socialFeedback */
/** @var array $themeOptions */
/** @var string $currentTheme */
/** @var bool $showMaintenance */
/** @var array $securitySettings */
/** @var array|null $securityFeedback */
/** @var array|null $rfbUploadFeedback */
/** @var array|null $releaseFeedback */
/** @var array $releases */
/** @var array $importSettings */
/** @var array $importLogs */
/** @var array $whatsappTemplates */
/** @var array|null $whatsappTemplateFeedback */
/** @var array $alertEntries */

use App\Support\WhatsappTemplatePresets;

$emailErrors = $emailFeedback['errors'] ?? [];
$emailOld = $emailFeedback['old'] ?? [];
$fields = array_merge($emailSettings, $emailOld);

$securityFeedback = $securityFeedback ?? null;
$securityErrors = is_array($securityFeedback['errors'] ?? null) ? $securityFeedback['errors'] : [];
$securityOld = is_array($securityFeedback['old'] ?? null) ? $securityFeedback['old'] : [];
$securityValues = [
    'access_start' => (string)($securityOld['access_start'] ?? ($securitySettings['access_start'] ?? '')),
    'access_end' => (string)($securityOld['access_end'] ?? ($securitySettings['access_end'] ?? '')),
    'require_known_device' => (string)($securityOld['require_known_device'] ?? (($securitySettings['require_known_device'] ?? false) ? '1' : '0')),
];

$rfbUploadFeedback = $rfbUploadFeedback ?? null;
$feedback = $feedback ?? null;
$releaseFeedback = $releaseFeedback ?? null;
$releases = is_array($releases ?? null) ? $releases : [];
$importLogs = is_array($importLogs ?? null) ? $importLogs : [];
$whatsappTemplateFeedback = $whatsappTemplateFeedback ?? null;
$whatsappTemplateErrors = is_array($whatsappTemplateFeedback['errors'] ?? null) ? $whatsappTemplateFeedback['errors'] : [];
$whatsappTemplateOld = is_array($whatsappTemplateFeedback['old'] ?? null) ? $whatsappTemplateFeedback['old'] : [];
$whatsappTemplateValues = array_merge(
    is_array($whatsappTemplates ?? null) ? $whatsappTemplates : [],
    $whatsappTemplateOld
);
$whatsappPlaceholderHints = WhatsappTemplatePresets::placeholderHints();

$encryptionOptions = [
    'none' => 'Sem criptografia',
    'ssl' => 'SSL',
    'tls' => 'TLS',
];

$encode = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$currentThemeValue = isset($currentTheme) ? (string)$currentTheme : 'safegreen-blue';

$configMenuItems = [
    [
        'key' => 'security',
        'label' => 'Políticas de acesso',
        'description' => 'Horários e dispositivos',
    ],
    [
        'key' => 'rfb-base',
        'label' => 'Base RFB',
        'description' => 'Upload e status',
    ],
    [
        'key' => 'theme',
        'label' => 'Tema visual',
        'description' => 'Cores e aparência',
    ],
    [
        'key' => 'email',
        'label' => 'Conta de e-mail',
        'description' => 'SMTP e remetentes',
    ],
    [
        'key' => 'templates',
        'label' => 'Templates',
        'description' => 'Modelos de campanha',
    ],
    [
        'key' => 'whatsapp',
        'label' => 'WhatsApp rápido',
        'description' => 'Mensagens prontas',
    ],
];

if (!empty($showMaintenance)) {
    $configMenuItems[] = [
        'key' => 'maintenance',
        'label' => 'Backup e manutenção',
        'description' => 'Exportações e reset',
    ];

    $configMenuItems[] = [
        'key' => 'releases',
        'label' => 'Releases do sistema',
        'description' => 'Importar e aplicar',
    ];
}

$configMenuItems[] = [
    'key' => 'social',
    'label' => 'Canais sociais',
    'description' => 'Tokens e integrações',
];

$configMenuItems[] = [
    'key' => 'alerts',
    'label' => 'Alertas',
    'description' => 'Logs de inconsistências',
];
?>

<header>
    <div>
        <h1>Configurações da suíte</h1>
        <p>Centralize os ajustes pontuais: segurança, importações, comunicação e integrações.</p>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
        <a href="<?= url('config/manual'); ?>" class="primary" style="padding:10px 22px;text-decoration:none;display:inline-flex;gap:8px;align-items:center;">
            <span style="font-weight:600;">Manual técnico (PDF)</span>
        </a>
        <a href="<?= url(); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar ao dashboard</a>
    </div>
</header>

<style>
    .config-layout {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        margin-top: 24px;
    }
    .config-menu {
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
    .config-menu-item {
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
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease;
    }
    .config-menu-item strong {
        font-size: 1rem;
    }
    .config-menu-item span {
        color: rgba(148,163,184,0.9);
        font-size: 0.8rem;
    }
    .config-menu-item.is-active {
        border-color: var(--accent);
        background: rgba(56,189,248,0.12);
    }
    .config-panels {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 28px;
    }
    .panel {
        border: 1px solid rgba(148,163,184,0.25);
        border-radius: 18px;
        padding: 26px;
        background: rgba(15,23,42,0.65);
    }
    .config-section {
        display: none;
    }
    .config-section.is-active {
        display: block;
    }
    .section-header {
        display:flex;
        justify-content:space-between;
        gap:18px;
        flex-wrap:wrap;
        margin-bottom:16px;
    }
    .section-header h2 {
        margin:0;
    }
</style>

<div class="config-layout" data-config-layout>
    <nav class="config-menu">
        <?php foreach ($configMenuItems as $item): ?>
            <button type="button" class="config-menu-item" data-config-target="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></span>
            </button>
        <?php endforeach; ?>
    </nav>

    <div class="config-panels">
        <section id="security" class="panel config-section" data-config-section="security">
            <div class="section-header">
                <div>
                    <h2>Políticas de acesso</h2>
                    <p style="margin:0;color:var(--muted);max-width:540px;">Defina a janela de horário em que os usuários podem acessar o sistema e exija dispositivos previamente liberados.</p>
                </div>
                <?php if (!empty($securityFeedback) && ($securityFeedback['type'] ?? '') !== 'error'): ?>
                    <span style="padding:8px 14px;border-radius:12px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.28);font-size:0.85rem;">
                        <?= htmlspecialchars((string)($securityFeedback['message'] ?? 'Atualizado.'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (($securityFeedback['type'] ?? '') === 'error'): ?>
                <div style="margin-bottom:16px;padding:12px;border-radius:12px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.12);color:#fca5a5;">
                    <?= htmlspecialchars((string)($securityFeedback['message'] ?? 'Revise os campos destacados.'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('config/security'); ?>" style="display:grid;gap:18px;max-width:620px;">
                <?= csrf_field(); ?>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                    Início permitido (HH:MM)
                    <input type="time" name="access_start" value="<?= $encode($securityValues['access_start']); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($securityErrors['access_start']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php if (isset($securityErrors['access_start'])): ?>
                        <small style="color:#f87171;">
                            <?= htmlspecialchars($securityErrors['access_start'], ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                    <?php endif; ?>
                </label>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                    Fim permitido (HH:MM)
                    <input type="time" name="access_end" value="<?= $encode($securityValues['access_end']); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($securityErrors['access_end']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php if (isset($securityErrors['access_end'])): ?>
                        <small style="color:#f87171;">
                            <?= htmlspecialchars($securityErrors['access_end'], ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                    <?php endif; ?>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:0.9rem;color:var(--muted);">
                    <input type="checkbox" name="require_known_device" value="1" <?= $securityValues['require_known_device'] === '1' ? 'checked' : ''; ?> style="width:18px;height:18px;">
                    Exigir dispositivo já autorizado para logins administrativos
                </label>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="primary" style="padding:12px 30px;">Salvar políticas</button>
                </div>
            </form>
        </section>

        <section id="rfb-base" class="panel config-section" data-config-section="rfb-base">
            <div class="section-header">
                <div>
                    <h2>Base RFB e importações</h2>
                    <p style="margin:0;color:var(--muted);max-width:600px;">Envie o arquivo CSV disponibilizado pela Receita Federal e acompanhe o histórico das últimas importações.</p>
                </div>
            </div>

            <?php if (!empty($rfbUploadFeedback)): ?>
                <?php $rfbSuccess = ($rfbUploadFeedback['type'] ?? '') === 'success'; ?>
                <div style="margin-bottom:18px;padding:14px;border-radius:12px;border:1px solid <?= $rfbSuccess ? 'rgba(34,197,94,0.35)' : 'rgba(248,113,113,0.35)'; ?>;background:<?= $rfbSuccess ? 'rgba(34,197,94,0.12)' : 'rgba(248,113,113,0.12)'; ?>;color:<?= $rfbSuccess ? '#22c55e' : '#f87171'; ?>;">
                    <?= htmlspecialchars((string)($rfbUploadFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div style="display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
                <form method="post" action="<?= url('config/rfb-base-upload'); ?>" enctype="multipart/form-data" style="border:1px solid rgba(56,189,248,0.35);border-radius:16px;padding:20px;background:rgba(15,23,42,0.58);display:flex;flex-direction:column;gap:12px;">
                    <?= csrf_field(); ?>
                    <strong>Enviar CSV oficial</strong>
                    <p style="margin:0;color:var(--muted);font-size:0.85rem;">Aceita apenas arquivos CSV exportados da base RFB. O arquivo é dividido em partes automaticamente.</p>
                    <input type="file" name="rfb_spreadsheet" accept=".csv" required style="padding:10px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(10,16,28,0.65);color:var(--text);">
                    <button type="submit" class="primary" style="padding:12px 18px;">Importar agora</button>
                </form>
                <form method="post" action="<?= url('config/import-settings'); ?>" style="border:1px solid rgba(148,163,184,0.3);border-radius:16px;padding:20px;background:rgba(15,23,42,0.58);display:flex;flex-direction:column;gap:12px;">
                    <?= csrf_field(); ?>
                    <strong>Preferências de importação</strong>
                    <label style="display:flex;align-items:center;gap:10px;font-size:0.9rem;color:var(--muted);">
                        <input type="checkbox" name="reject_older_certificates" value="1" <?= !empty($importSettings['reject_older_certificates']) ? 'checked' : ''; ?> style="width:18px;height:18px;">
                        Ignorar certificados com data anterior à já cadastrada
                    </label>
                    <button type="submit" class="primary" style="padding:10px 16px;align-self:flex-start;">Salvar preferência</button>
                    <small style="color:var(--muted);font-size:0.78rem;">Essa opção evita sobrescrever clientes em casos de planilhas desatualizadas.</small>
                </form>
            </div>

            <?php if (!empty($importLogs)): ?>
                <div style="margin-top:26px;overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;min-width:720px;">
                        <thead>
                            <tr style="background:rgba(30,41,59,0.65);">
                                <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.35);">Data</th>
                                <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.35);">Arquivo</th>
                                <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.35);">Resumo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($importLogs as $log): ?>
                                <?php $stats = $log['stats'] ?? []; ?>
                                <tr style="border-bottom:1px solid rgba(148,163,184,0.25);">
                                    <td style="padding:10px;color:var(--muted);">
                                        <?= date('d/m/Y H:i', (int)($log['created_at'] ?? time())); ?>
                                    </td>
                                    <td style="padding:10px;color:var(--muted);">
                                        <?= htmlspecialchars((string)($log['label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:10px;color:var(--muted);">
                                        <?= htmlspecialchars(sprintf('%d processados / %d novos / %d atualizados', (int)($stats['processed'] ?? 0), (int)($stats['created_clients'] ?? 0), (int)($stats['updated_clients'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="margin-top:18px;color:var(--muted);">Nenhuma importação registrada ainda.</p>
            <?php endif; ?>
        </section>

        <section id="theme" class="panel config-section" data-config-section="theme">
            <div class="section-header">
                <div>
                    <h2>Tema visual</h2>
                    <p style="margin:0;color:var(--muted);max-width:520px;">Aplique um fundo padronizado para todos os usuários imediatamente.</p>
                </div>
                <?php if (!empty($feedback) && ($feedback['type'] ?? '') !== 'error'): ?>
                    <span style="padding:8px 14px;border-radius:12px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.28);font-size:0.85rem;">
                        <?= htmlspecialchars((string)($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (($feedback['type'] ?? '') === 'error'): ?>
                <div style="margin-bottom:16px;padding:12px;border-radius:12px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.12);color:#fca5a5;">
                    <?= htmlspecialchars((string)($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('config/theme'); ?>" style="display:grid;gap:20px;">
                <?= csrf_field(); ?>
                <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
                    <?php foreach ($themeOptions as $key => $option): ?>
                        <label style="border:1px solid <?= $currentThemeValue === $key ? 'var(--accent)' : 'rgba(148,163,184,0.35)'; ?>;border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:8px;background:rgba(15,23,42,0.58);cursor:pointer;">
                            <span style="font-weight:600;"><?= htmlspecialchars($option['label'] ?? $key, ENT_QUOTES, 'UTF-8'); ?></span>
                            <small style="color:var(--muted);">
                                <?= htmlspecialchars($option['description'] ?? 'Gradiente personalizado', ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                            <input type="radio" name="theme" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $currentThemeValue === $key ? 'checked' : ''; ?> style="margin-top:6px;">
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="primary" style="padding:12px 28px;">Aplicar tema</button>
                </div>
            </form>
        </section>

        <section id="email" class="panel config-section" data-config-section="email">
            <div class="section-header">
                <div>
                    <h2>Conta de envio de e-mail</h2>
                    <p style="margin:0;color:var(--muted);max-width:540px;">Defina os dados SMTP utilizados para disparar campanhas e notificações.</p>
                </div>
                <?php if (!empty($emailFeedback) && empty($emailFeedback['errors'])): ?>
                    <span style="padding:8px 14px;border-radius:12px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.28);font-size:0.85rem;">
                        <?= htmlspecialchars((string)($emailFeedback['message'] ?? 'Configurações salvas.'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($emailFeedback['errors'])): ?>
                <div style="margin-bottom:16px;padding:12px;border-radius:12px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.12);color:#fca5a5;">
                    <?= htmlspecialchars((string)($emailFeedback['message'] ?? 'Erros encontrados no formulário.'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('config/email'); ?>" style="display:grid;gap:18px;margin-top:10px;">
                <?= csrf_field(); ?>
                <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Servidor SMTP
                        <input type="text" name="host" value="<?= $encode((string)($fields['host'] ?? '')); ?>" placeholder="smtp.seudominio.com" style="padding:12px;border-radius:12px;border:1px solid <?= isset($emailErrors['host']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($emailErrors['host'])): ?>
                            <small style="color:#f87171;"><?= htmlspecialchars($emailErrors['host'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Porta
                        <input type="text" name="port" value="<?= $encode((string)($fields['port'] ?? '587')); ?>" placeholder="587" style="padding:12px;border-radius:12px;border:1px solid <?= isset($emailErrors['port']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($emailErrors['port'])): ?>
                            <small style="color:#f87171;"><?= htmlspecialchars($emailErrors['port'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Criptografia
                        <select name="encryption" style="padding:12px;border-radius:12px;border:1px solid <?= isset($emailErrors['encryption']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                            <?php foreach ($encryptionOptions as $value => $label): ?>
                                <option value="<?= $value; ?>" <?= (($fields['encryption'] ?? 'tls') === $value) ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($emailErrors['encryption'])): ?>
                            <small style="color:#f87171;"><?= htmlspecialchars($emailErrors['encryption'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Usuário
                        <input type="text" name="username" value="<?= $encode((string)($fields['username'] ?? '')); ?>" placeholder="usuario@seudominio.com" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Senha
                        <input type="password" name="password" value="<?= $encode((string)($fields['password'] ?? '')); ?>" placeholder="••••••" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                </div>
                <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Remetente (nome)
                        <input type="text" name="from_name" value="<?= $encode((string)($fields['from_name'] ?? '')); ?>" placeholder="SafeGreen Digital" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Remetente (e-mail)
                        <input type="email" name="from_email" value="<?= $encode((string)($fields['from_email'] ?? '')); ?>" placeholder="contato@seudominio.com" style="padding:12px;border-radius:12px;border:1px solid <?= isset($emailErrors['from_email']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($emailErrors['from_email'])): ?>
                            <small style="color:#f87171;"><?= htmlspecialchars($emailErrors['from_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Resposta para
                        <input type="email" name="reply_to" value="<?= $encode((string)($fields['reply_to'] ?? '')); ?>" placeholder="suporte@seudominio.com" style="padding:12px;border-radius:12px;border:1px solid <?= isset($emailErrors['reply_to']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($emailErrors['reply_to'])): ?>
                            <small style="color:#f87171;"><?= htmlspecialchars($emailErrors['reply_to'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button class="primary" type="submit" style="padding:14px 32px;">Salvar configurações</button>
                </div>
            </form>
        </section>

        <section id="templates" class="panel config-section" data-config-section="templates">
            <div class="section-header">
                <div>
                    <h2>Templates de campanha</h2>
                    <p style="margin:0;color:var(--muted);max-width:520px;">Revise rapidamente os modelos cadastrados e ative o editor completo quando necessário.</p>
                </div>
                <a href="<?= url('templates'); ?>" class="button" style="padding:10px 16px;border-radius:10px;border:1px solid rgba(56,189,248,0.4);color:var(--accent);text-decoration:none;">Gerenciar no módulo</a>
            </div>

            <?php if (empty($templates)): ?>
                <p style="color:var(--muted);">Nenhum template cadastrado ainda.</p>
            <?php else: ?>
                <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                    <?php foreach ($templates as $template): ?>
                        <div style="border:1px solid rgba(148,163,184,0.3);border-radius:14px;padding:18px;background:rgba(15,23,42,0.6);display:flex;flex-direction:column;gap:8px;">
                            <strong><?= htmlspecialchars((string)($template['name'] ?? 'Template'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span style="font-size:0.9rem;color:var(--muted);">Assunto: <?= htmlspecialchars((string)($template['subject'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <a href="<?= url('templates/' . (int)($template['id'] ?? 0) . '/edit'); ?>" style="align-self:flex-start;margin-top:6px;font-size:0.85rem;color:var(--accent);text-decoration:none;">Abrir no editor &rarr;</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="whatsapp" class="panel config-section" data-config-section="whatsapp">
            <div class="section-header">
                <div>
                    <h2>Mensagens rápidas no WhatsApp</h2>
                    <p style="margin:0;color:var(--muted);max-width:580px;">Edite os textos usados nos botões de "Enviar felicitações", "Mensagem de renovação" e "Resgate" exibidos na ficha do cliente.</p>
                </div>
                <?php if (!empty($whatsappTemplateFeedback) && ($whatsappTemplateFeedback['type'] ?? '') === 'success'): ?>
                    <span style="padding:8px 14px;border-radius:12px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.28);font-size:0.85rem;">
                        <?= htmlspecialchars((string)($whatsappTemplateFeedback['message'] ?? 'Modelos atualizados.'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (($whatsappTemplateFeedback['type'] ?? '') === 'error'): ?>
                <div style="margin-bottom:16px;padding:12px;border-radius:12px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.12);color:#fca5a5;">
                    <?= htmlspecialchars((string)($whatsappTemplateFeedback['message'] ?? 'Revise os campos destacados.'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('config/whatsapp-templates'); ?>" style="display:grid;gap:22px;">
                <?= csrf_field(); ?>
                <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
                    <?php foreach (['birthday' => '🎉 Felicitações','renewal' => '🔁 Renovação','rescue' => '🤝 Resgate de cliente'] as $templateKey => $label): ?>
                        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                            <span style="font-weight:600;color:var(--text);"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <textarea name="templates[<?= $templateKey; ?>]" rows="6" style="padding:12px;border-radius:12px;border:1px solid <?= isset($whatsappTemplateErrors[$templateKey]) ? '#f87171' : 'rgba(148,163,184,0.35)'; ?>;background:rgba(15,23,42,0.6);color:var(--text);"><?= htmlspecialchars((string)($whatsappTemplateValues[$templateKey] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php if (isset($whatsappTemplateErrors[$templateKey])): ?>
                                <small style="color:#f87171;">
                                    <?= htmlspecialchars($whatsappTemplateErrors[$templateKey], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            <?php else: ?>
                                <small style="color:var(--muted);">Use até 1.200 caracteres. Disponível para todos os atendentes.</small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.25);border-radius:16px;padding:16px;">
                    <strong style="display:block;margin-bottom:8px;">Variáveis disponíveis</strong>
                    <p style="margin:0 0 10px;color:var(--muted);font-size:0.85rem;">Substitua automaticamente pelos dados do cliente:</p>
                    <ul style="margin:0;padding-left:18px;color:var(--muted);font-size:0.85rem;column-count:2;gap:12px;">
                        <?php foreach ($whatsappPlaceholderHints as $token => $hint): ?>
                            <li><code><?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?></code> – <?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="primary" style="padding:12px 28px;">Salvar mensagens</button>
                </div>
            </form>

            <div style="margin-top:32px;padding-top:28px;border-top:1px solid rgba(148,163,184,0.3);">
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:18px;">
                    <div>
                        <h3 style="margin:0 0 6px;font-size:1.05rem;">Janela da mensagem de renovação</h3>
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;max-width:560px;">Defina o intervalo em dias que libera o botão &ldquo;Mensagem de renovação&rdquo; antes e depois do vencimento mais recente.</p>
                    </div>
                    <?php if ($renewalWindowFeedback !== null && ($renewalWindowFeedback['type'] ?? '') === 'success'): ?>
                        <span style="padding:6px 12px;border-radius:999px;font-size:0.8rem;font-weight:600;background:rgba(16,185,129,0.18);border:1px solid rgba(16,185,129,0.4);color:#22c55e;">
                            <?= htmlspecialchars((string)($renewalWindowFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php
                    $renewalWindowOld = $renewalWindowFeedback['old'] ?? [];
                    $renewalWindowErrors = $renewalWindowFeedback['errors'] ?? [];
                    $renewalBeforeValue = isset($renewalWindowOld['before_days']) ? (string)$renewalWindowOld['before_days'] : (string)($renewalWindow['before_days'] ?? 60);
                    $renewalAfterValue = isset($renewalWindowOld['after_days']) ? (string)$renewalWindowOld['after_days'] : (string)($renewalWindow['after_days'] ?? 60);
                ?>

                <?php if (($renewalWindowFeedback['type'] ?? '') === 'error'): ?>
                    <div style="margin-bottom:16px;padding:12px;border-radius:12px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.12);color:#fca5a5;">
                        <?= htmlspecialchars((string)($renewalWindowFeedback['message'] ?? 'Revise os campos destacados.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= url('config/renewal-window'); ?>" style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <?= csrf_field(); ?>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Dias antes do vencimento
                        <input type="number" min="0" max="365" name="before_days" value="<?= htmlspecialchars($renewalBeforeValue, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($renewalWindowErrors['before_days']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($renewalWindowErrors['before_days'])): ?>
                            <small style="color:#f87171;">
                                <?= htmlspecialchars((string)$renewalWindowErrors['before_days'], ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                        <?php else: ?>
                            <small style="color:var(--muted);">Permite agendar a abordagem antes do vencimento.</small>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Dias após o vencimento
                        <input type="number" min="0" max="365" name="after_days" value="<?= htmlspecialchars($renewalAfterValue, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($renewalWindowErrors['after_days']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                        <?php if (isset($renewalWindowErrors['after_days'])): ?>
                            <small style="color:#f87171;">
                                <?= htmlspecialchars((string)$renewalWindowErrors['after_days'], ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                        <?php else: ?>
                            <small style="color:var(--muted);">Mantém o botão ativo mesmo após o vencimento.</small>
                        <?php endif; ?>
                    </label>
                    <div style="grid-column:1 / -1;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:10px;">
                        <button type="submit" class="primary" style="padding:10px 26px;">Salvar janela</button>
                    </div>
                </form>
            </div>

            <div style="margin-top:32px;padding-top:28px;border-top:1px solid rgba(148,163,184,0.3);">
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:18px;">
                    <div>
                        <h3 style="margin:0 0 6px;font-size:1.05rem;">Botões da Base RFB</h3>
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;max-width:560px;">Atualize os textos exibidos nos botões "Parceria CD" e "Certificados" da Base RFB. Use os marcadores para preencher automaticamente com dados do prospecto.</p>
                    </div>
                    <?php if ($rfbWhatsappTemplateFeedback !== null): ?>
                        <span style="padding:6px 12px;border-radius:999px;font-size:0.8rem;font-weight:600;<?= ($rfbWhatsappTemplateFeedback['type'] ?? '') === 'success' ? 'background:rgba(16,185,129,0.18);border:1px solid rgba(16,185,129,0.4);color:#22c55e;' : 'background:rgba(248,113,113,0.18);border:1px solid rgba(248,113,113,0.4);color:#f87171;'; ?>">
                            <?= htmlspecialchars((string)($rfbWhatsappTemplateFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php
                    $rfbOld = $rfbWhatsappTemplateFeedback['old'] ?? [];
                    $rfbPartnershipValue = isset($rfbOld['partnership_template'])
                        ? (string)$rfbOld['partnership_template']
                        : (string)($rfbWhatsappTemplates['partnership'] ?? '');
                    $rfbGeneralValue = isset($rfbOld['general_template'])
                        ? (string)$rfbOld['general_template']
                        : (string)($rfbWhatsappTemplates['general'] ?? '');
                ?>

                <form method="post" action="<?= url('config/rfb-whatsapp-templates'); ?>" style="display:grid;gap:18px;">
                    <?= csrf_field(); ?>
                    <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
                        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                            Texto botão "Parceria CD"
                            <textarea name="partnership_template" rows="5" style="resize:vertical;padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.55);color:var(--text);font-size:0.95rem;"><?= htmlspecialchars($rfbPartnershipValue, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </label>
                        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                            Texto botão "Certificados"
                            <textarea name="general_template" rows="5" style="resize:vertical;padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,0.35);background:rgba(15,23,42,0.55);color:var(--text);font-size:0.95rem;"><?= htmlspecialchars($rfbGeneralValue, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </label>
                    </div>

                    <div style="font-size:0.82rem;color:var(--muted);display:flex;flex-wrap:wrap;gap:12px;">
                        <span>Marcadores disponíveis:</span>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{responsavel}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{responsavel_primeiro_nome}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{empresa}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{cidade}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{estado}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{cnpj}}</code>
                        <code style="background:rgba(15,23,42,0.65);padding:4px 8px;border-radius:8px;">{{cnae}}</code>
                    </div>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="primary" style="padding:10px 24px;">Salvar botões</button>
                        <a href="<?= url('rfb-base'); ?>" style="font-size:0.85rem;color:var(--muted);text-decoration:none;">Ver na Base RFB</a>
                    </div>
                </form>
            </div>
        </section>

        <?php if (!empty($showMaintenance)): ?>
        <section id="maintenance" class="panel config-section" data-config-section="maintenance">
            <div class="section-header">
                <div>
                    <h2>Backup e manutenção</h2>
                    <p style="margin:0;color:var(--muted);max-width:560px;">Exporte dados sensíveis, gere planilhas compatíveis com o CRM e restaure o ambiente para padrão de fábrica.</p>
                </div>
            </div>

            <div style="display:grid;gap:24px;margin-top:10px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
                <div style="border:1px solid var(--border);border-radius:16px;padding:20px;background:rgba(15,23,42,0.62);display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <strong style="display:block;font-size:1rem;">Exportar backup completo</strong>
                        <p style="margin:6px 0 0;color:var(--muted);font-size:0.9rem;">Gera planilha com clientes, certificados e histórico, compactada em .zip.</p>
                    </div>
                    <form method="post" action="<?= url('config/export-backup'); ?>">
                        <?= csrf_field(); ?>
                        <button type="submit" class="primary" style="width:100%;padding:12px 16px;">Baixar backup ZIP</button>
                    </form>
                    <small style="color:var(--muted);font-size:0.78rem;">O arquivo é apagado após o download.</small>
                </div>

                <div style="border:1px solid rgba(248,113,113,0.35);border-radius:16px;padding:20px;background:rgba(248,113,113,0.08);display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <strong style="display:block;font-size:1rem;color:#ef4444;">Restaurar padrão de fábrica</strong>
                        <p style="margin:6px 0 0;color:rgba(248,113,113,0.85);font-size:0.9rem;">Remove clientes, certificados e arquivos enviados. Usuários administradores permanecem.</p>
                    </div>
                    <form method="post" action="<?= url('config/factory-reset'); ?>" onsubmit="return confirm('Esta ação remove os dados de clientes, certificados e históricos. Deseja continuar?');" style="display:grid;gap:12px;">
                        <?= csrf_field(); ?>
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:rgba(248,113,113,0.9);">
                            Digite <strong style="color:#ef4444;">REDEFINIR</strong> para confirmar
                            <input type="text" name="confirmation" autocomplete="off" style="padding:10px 12px;border-radius:10px;border:1px solid rgba(248,113,113,0.5);background:rgba(15,23,42,0.75);color:var(--text);">
                        </label>
                        <button type="submit" style="padding:12px 16px;border-radius:12px;border:1px solid rgba(248,113,113,0.5);background:#ef4444;color:#0f172a;font-weight:600;">Restaurar agora</button>
                    </form>
                    <small style="color:rgba(248,113,113,0.85);font-size:0.78rem;">Recria o funil padrão e os modelos automaticamente.</small>
                </div>

                <div style="border:1px solid rgba(56,189,248,0.35);border-radius:16px;padding:20px;background:rgba(56,189,248,0.12);display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <strong style="display:block;font-size:1rem;color:var(--accent);">Planilha para reimportar</strong>
                        <p style="margin:6px 0 0;color:rgba(148,163,184,0.95);font-size:0.9rem;">Gera arquivo compatível com a tela de importação do CRM.</p>
                    </div>
                    <form method="post" action="<?= url('config/export-import-template'); ?>">
                        <?= csrf_field(); ?>
                        <button type="submit" class="primary" style="width:100%;padding:12px 16px;background:linear-gradient(135deg,rgba(56,189,248,0.85),rgba(59,130,246,0.85));color:#0f172a;">Baixar planilha</button>
                    </form>
                </div>

                <div style="border:1px solid rgba(94,234,212,0.35);border-radius:16px;padding:20px;background:rgba(16,185,129,0.08);display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <strong style="display:block;font-size:1rem;color:#34d399;">Limpar cache de rotas</strong>
                        <p style="margin:6px 0 0;color:rgba(148,163,184,0.95);font-size:0.9rem;">Remove o cache do roteador (FastRoute). Ele será recriado automaticamente na próxima requisição.</p>
                    </div>
                    <form method="post" action="<?= url('config/route-cache/refresh'); ?>" onsubmit="return confirm('Limpar o cache de rotas agora?');">
                        <?= csrf_field(); ?>
                        <button type="submit" class="primary" style="width:100%;padding:12px 16px;background:linear-gradient(135deg,rgba(16,185,129,0.9),rgba(45,212,191,0.9));color:#0f172a;">Limpar e regenerar</button>
                    </form>
                    <small style="color:rgba(148,163,184,0.95);font-size:0.78rem;">Use após adicionar novas rotas em produção para forçar recompilação da tabela.</small>
                </div>

                <div style="border:1px solid rgba(56,189,248,0.35);border-radius:16px;padding:20px;background:rgba(15,23,42,0.62);display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <strong style="display:block;font-size:1rem;color:var(--accent);">Importar planilha (admin)</strong>
                        <p style="margin:6px 0 0;color:rgba(148,163,184,0.95);font-size:0.9rem;">Atualize clientes diretamente pela configuração.</p>
                    </div>
                    <form method="post" action="<?= url('config/import-spreadsheet'); ?>" enctype="multipart/form-data" style="display:grid;gap:12px;">
                        <?= csrf_field(); ?>
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.8rem;color:var(--muted);">
                            Selecionar arquivo (XLS, XLSX ou CSV)
                            <input type="file" name="spreadsheet" accept=".xls,.xlsx,.csv" required style="padding:10px 12px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(10,16,28,0.65);color:var(--text);">
                        </label>
                        <button type="submit" class="primary" style="width:100%;padding:12px 16px;">Importar agora</button>
                    </form>
                </div>
            </div>
        </section>

        <section id="releases" class="panel config-section" data-config-section="releases" style="margin-top:0;">
            <div class="section-header">
                <div>
                    <h2>Releases do sistema</h2>
                    <p style="margin:0;color:var(--muted);max-width:640px;">Importe o pacote .zip gerado no ambiente de desenvolvimento e aplique quando estiver pronto.</p>
                </div>
            </div>

            <?php if (!empty($releaseFeedback)): ?>
                <?php $success = ($releaseFeedback['type'] ?? '') === 'success'; ?>
                <div style="margin-bottom:18px;padding:14px;border-radius:14px;border:1px solid <?= $success ? 'rgba(34,197,94,0.35)' : 'rgba(248,113,113,0.35)'; ?>;background:<?= $success ? 'rgba(34,197,94,0.12)' : 'rgba(248,113,113,0.12)'; ?>;color:<?= $success ? '#22c55e' : '#f87171'; ?>;">
                    <strong style="display:block;margin-bottom:6px;">Resultado</strong>
                    <p style="margin:0;color:var(--muted);"><?= htmlspecialchars((string)($releaseFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php $context = $releaseFeedback['context'] ?? null; ?>
                    <?php if (is_array($context)): ?>
                        <details style="margin-top:10px;">
                            <summary style="cursor:pointer;color:var(--accent);">Ver log detalhado</summary>
                            <div style="margin-top:8px;font-size:0.8rem;color:var(--muted);">
                                <?php if (!empty($context['command'])): ?>
                                    <div><strong>Comando:</strong> <code><?= htmlspecialchars((string)$context['command'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                                <?php endif; ?>
                                <?php if (isset($context['exit_code'])): ?>
                                    <div><strong>Exit code:</strong> <?= (int)$context['exit_code']; ?></div>
                                <?php endif; ?>
                                <?php if (!empty($context['stdout'])): ?>
                                    <div style="margin-top:8px;">
                                        <strong>STDOUT</strong>
                                        <pre style="background:rgba(15,23,42,0.85);color:var(--text);padding:10px;border-radius:10px;white-space:pre-wrap;max-height:220px;overflow:auto;"><?= htmlspecialchars((string)$context['stdout'], ENT_QUOTES, 'UTF-8'); ?></pre>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($context['stderr'])): ?>
                                    <div style="margin-top:8px;">
                                        <strong>STDERR</strong>
                                        <pre style="background:rgba(31,21,21,0.85);color:#f87171;padding:10px;border-radius:10px;white-space:pre-wrap;max-height:220px;overflow:auto;"><?= htmlspecialchars((string)$context['stderr'], ENT_QUOTES, 'UTF-8'); ?></pre>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="display:grid;gap:20px;margin-top:10px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
                <?php if (!empty($showMaintenance)): ?>
                <form method="post" action="<?= url('config/releases/generate'); ?>" style="border:1px solid rgba(34,197,94,0.35);border-radius:16px;padding:20px;background:rgba(15,23,42,0.55);display:flex;flex-direction:column;gap:14px;">
                    <?= csrf_field(); ?>
                    <div>
                        <strong style="display:block;font-size:1rem;color:#22c55e;">Gerar release</strong>
                        <p style="margin:6px 0 0;color:var(--muted);font-size:0.9rem;">Cria um pacote .zip com manifest e dependências para levar a outro ambiente.</p>
                    </div>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Identificador da versão
                        <input type="text" name="version" placeholder="Ex.: v0.9.0-rc1" style="padding:12px;border-radius:12px;border:1px solid rgba(34,197,94,0.45);background:rgba(10,16,28,0.65);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Notas (opcional)
                        <textarea name="notes" rows="3" placeholder="Resumo do que mudou" style="padding:12px;border-radius:12px;border:1px solid rgba(34,197,94,0.35);background:rgba(10,16,28,0.65);color:var(--text);"></textarea>
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;font-size:0.85rem;color:var(--muted);">
                        <input type="checkbox" name="skip_vendor" value="1" style="width:18px;height:18px;">
                        Pular pasta <code>vendor/</code> (usará dependências já instaladas no destino)
                    </label>
                    <button type="submit" class="primary" style="padding:12px 18px;background:linear-gradient(135deg,rgba(34,197,94,0.85),rgba(16,185,129,0.85));color:#0f172a;">Gerar pacote</button>
                    <small style="color:var(--muted);font-size:0.78rem;">O arquivo fica salvo em <code>storage/releases</code>. Baixe logo após a conclusão.</small>
                </form>
                <?php endif; ?>

                <form method="post" action="<?= url('config/releases/import'); ?>" enctype="multipart/form-data" style="border:1px solid rgba(56,189,248,0.35);border-radius:16px;padding:20px;background:rgba(15,23,42,0.62);display:flex;flex-direction:column;gap:14px;">
                    <?= csrf_field(); ?>
                    <div>
                        <strong style="display:block;font-size:1rem;color:var(--accent);">Importar pacote ZIP</strong>
                        <p style="margin:6px 0 0;color:var(--muted);font-size:0.9rem;">Envie o arquivo criado pelo script <code>package_release.php</code>.</p>
                    </div>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Selecionar arquivo (.zip)
                        <input type="file" name="release_package" accept=".zip" required style="padding:12px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(10,16,28,0.65);color:var(--text);">
                    </label>
                    <button type="submit" class="primary" style="padding:12px 18px;">Importar release</button>
                    <small style="color:var(--muted);font-size:0.78rem;">O arquivo fica salvo em <code>storage/releases</code>.</small>
                </form>
            </div>

            <?php if (empty($releases)): ?>
                <p style="margin-top:22px;color:var(--muted);">Nenhuma release importada ainda.</p>
            <?php else: ?>
                <?php
                    $formatBytes = static function (int $bytes): string {
                        if ($bytes <= 0) {
                            return '0 B';
                        }

                        $units = ['B', 'KB', 'MB', 'GB'];
                        $power = (int)floor(min(count($units) - 1, log($bytes, 1024)));
                        $value = $bytes / (1024 ** $power);
                        $decimals = $power === 0 ? 0 : 2;

                        return number_format($value, $decimals, ',', '.') . ' ' . $units[$power];
                    };
                ?>
                <div style="overflow-x:auto;margin-top:26px;">
                    <table style="width:100%;border-collapse:collapse;min-width:780px;">
                        <thead>
                            <tr style="background:rgba(30,41,59,0.65);">
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Versão</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Status</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Origem</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Tamanho</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Importada em</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Última aplicação</th>
                                <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($releases as $release):
                                $status = (string)($release['status'] ?? 'available');
                                $statusMap = [
                                    'available' => ['label' => 'Disponível', 'bg' => 'rgba(56,189,248,0.15)', 'color' => '#38bdf8'],
                                    'applied' => ['label' => 'Aplicada', 'bg' => 'rgba(34,197,94,0.18)', 'color' => '#22c55e'],
                                    'failed' => ['label' => 'Falhou', 'bg' => 'rgba(248,113,113,0.15)', 'color' => '#f87171'],
                                ];
                                $statusBadge = $statusMap[$status] ?? ['label' => ucfirst($status), 'bg' => 'rgba(148,163,184,0.2)', 'color' => 'var(--muted)'];
                                $createdAt = !empty($release['created_at']) ? date('d/m/Y H:i', (int)$release['created_at']) : '—';
                                $appliedAt = !empty($release['applied_at']) ? date('d/m/Y H:i', (int)$release['applied_at']) : 'Ainda não aplicado';
                                $sizeLabel = $formatBytes((int)($release['file_size'] ?? 0));
                                $notes = trim((string)($release['notes'] ?? ''));
                                $canApply = in_array($status, ['available', 'failed'], true);
                            ?>
                                <tr style="border-bottom:1px solid rgba(148,163,184,0.2);">
                                    <td style="padding:12px;">
                                        <strong style="display:block;">
                                            <?= htmlspecialchars((string)$release['version'], ENT_QUOTES, 'UTF-8'); ?>
                                        </strong>
                                        <?php if ($notes !== ''): ?>
                                            <small style="display:block;margin-top:4px;color:var(--muted);">Notas: <?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px;">
                                        <span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:0.78rem;background:<?= htmlspecialchars($statusBadge['bg'], ENT_QUOTES, 'UTF-8'); ?>;color:<?= htmlspecialchars($statusBadge['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                                            <?= htmlspecialchars($statusBadge['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="padding:12px;color:var(--muted);text-transform:capitalize;">
                                        <?= htmlspecialchars((string)($release['origin'] ?? 'local'), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:12px;color:var(--muted);">
                                        <?= htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:12px;color:var(--muted);">
                                        <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:12px;color:var(--muted);">
                                        <?= htmlspecialchars($appliedAt, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($release['applied_exit_code'])): ?>
                                            <small style="display:block;color:<?= (int)$release['applied_exit_code'] === 0 ? '#22c55e' : '#f87171'; ?>;">exit <?= (int)$release['applied_exit_code']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px;">
                                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                                            <a href="<?= url('config/releases/' . (int)$release['id'] . '/download'); ?>" class="button" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(56,189,248,0.4);color:var(--accent);text-decoration:none;font-size:0.82rem;">Baixar</a>
                                            <?php if ($canApply): ?>
                                                <form method="post" action="<?= url('config/releases/' . (int)$release['id'] . '/apply'); ?>" onsubmit="return confirm('Aplicar a release <?= htmlspecialchars((string)$release['version'], ENT_QUOTES, 'UTF-8'); ?> agora?');" style="margin:0;">
                                                    <?= csrf_field(); ?>
                                                    <button type="submit" class="primary" style="padding:8px 16px;">Aplicar</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size:0.78rem;color:var(--muted);">Aplicada em <?= htmlspecialchars($appliedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div style="margin-top:24px;border:1px solid var(--border);border-radius:16px;padding:18px;background:rgba(15,23,42,0.55);">
                <strong style="font-size:0.95rem;display:block;margin-bottom:6px;">Checklist antes de aplicar</strong>
                <ul style="margin:0;padding-left:20px;color:var(--muted);font-size:0.85rem;display:flex;flex-direction:column;gap:6px;">
                    <li>Confirme backup recente do banco e da pasta <code>storage/</code>.</li>
                    <li>Garanta que não há usuários ativos durante a aplicação.</li>
                    <li>Após o sucesso, valide login, CRM e importações.</li>
                </ul>
                <span style="margin-top:10px;display:block;color:rgba(248,250,252,0.75);font-size:0.78rem;">Importe várias versões e aplique quando quiser.</span>
            </div>
        </section>
        <?php endif; ?>

        <section id="social" class="panel config-section" data-config-section="social">
            <div class="section-header">
                <div>
                    <h2>Canais sociais conectados</h2>
                    <p style="margin:0;color:var(--muted);max-width:540px;">Armazene tokens e IDs para usar nas automações do Instagram, Facebook, WhatsApp e LinkedIn.</p>
                </div>
                <?php if (!empty($socialFeedback)): ?>
                    <span style="padding:8px 14px;border-radius:12px;background:<?= ($socialFeedback['type'] ?? '') === 'error' ? 'rgba(248,113,113,0.14)' : 'rgba(34,197,94,0.12)'; ?>;color:<?= ($socialFeedback['type'] ?? '') === 'error' ? '#f87171' : '#22c55e'; ?>;border:1px solid <?= ($socialFeedback['type'] ?? '') === 'error' ? 'rgba(248,113,113,0.35)' : 'rgba(34,197,94,0.28)'; ?>;font-size:0.85rem;">
                        <?= htmlspecialchars((string)($socialFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= url('config/social-accounts'); ?>" style="display:grid;gap:16px;margin-top:20px;">
                <?= csrf_field(); ?>
                <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Plataforma
                        <select name="platform" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                            <option value="">Selecione</option>
                            <option value="facebook">Facebook</option>
                            <option value="instagram">Instagram</option>
                            <option value="whatsapp">WhatsApp Business</option>
                            <option value="linkedin">LinkedIn</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Apelido interno
                        <input type="text" name="label" required placeholder="Ex.: Fanpage principal" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        ID externo
                        <input type="text" name="external_id" placeholder="Opcional" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        Expira em
                        <input type="datetime-local" name="expires_at" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    </label>
                </div>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                    Token de acesso
                    <textarea name="token" required placeholder="Cole o token gerado na plataforma" rows="4" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);"></textarea>
                </label>
                <div style="display:flex;justify-content:flex-end;">
                    <button class="primary" type="submit" style="padding:14px 30px;">Salvar canal</button>
                </div>
            </form>

            <div style="margin-top:28px;">
                <?php if (empty($accounts)): ?>
                    <p style="color:var(--muted);">Nenhum canal cadastrado. Adicione acima para habilitar integrações.</p>
                <?php else: ?>
                    <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                        <?php foreach ($accounts as $account):
                            $expiresAt = $account['expires_at'] ?? null;
                            $expiresLabel = $expiresAt ? date('d/m/Y H:i', (int)$expiresAt) : 'Sem expiração';
                            $expiresColor = ($expiresAt !== null && (int)$expiresAt < time()) ? '#f87171' : '#22c55e';
                        ?>
                            <div style="border:1px solid var(--border);border-radius:14px;padding:18px;background:rgba(15,23,42,0.68);">
                                <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);">
                                    <?= htmlspecialchars((string)$account['platform'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size:1.1rem;font-weight:600;margin:8px 0 4px;">
                                    <?= htmlspecialchars((string)$account['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if (!empty($account['external_id'])): ?>
                                    <div style="font-size:0.85rem;color:var(--muted);">ID: <?= htmlspecialchars((string)$account['external_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <div style="font-size:0.8rem;color:var(--muted);margin-top:8px;">
                                    Criado em <?= date('d/m/Y H:i', (int)$account['created_at']); ?>
                                </div>
                                <div style="font-size:0.85rem;margin-top:6px;color:<?= htmlspecialchars($expiresColor, ENT_QUOTES, 'UTF-8'); ?>;">
                                    <?= htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="alerts" class="panel config-section" data-config-section="alerts">
            <div class="section-header">
                <div>
                    <h2>Alertas recentes</h2>
                    <p style="margin:0;color:var(--muted);max-width:540px;">Eventos registrados pelo sistema (placeholders faltando, validações, etc.).</p>
                </div>
            </div>

            <?php if (empty($alertEntries)): ?>
                <p style="color:var(--muted);">Nenhum alerta registrado.</p>
            <?php else: ?>
                <div style="overflow:auto;max-height:460px;border:1px solid var(--border);border-radius:14px;">
                    <table style="width:100%;border-collapse:collapse;min-width:640px;">
                        <thead>
                            <tr style="background:rgba(15,23,42,0.6);color:var(--muted);">
                                <th style="text-align:left;padding:10px 12px;font-size:0.85rem;">Quando</th>
                                <th style="text-align:left;padding:10px 12px;font-size:0.85rem;">Origem</th>
                                <th style="text-align:left;padding:10px 12px;font-size:0.85rem;">Mensagem</th>
                                <th style="text-align:left;padding:10px 12px;font-size:0.85rem;">Meta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($alertEntries) as $entry): ?>
                                <tr style="border-top:1px solid var(--border);">
                                    <td style="padding:10px 12px;font-size:0.9rem;white-space:nowrap;">
                                        <?= htmlspecialchars(date('d/m/Y H:i:s', (int)($entry['ts'] ?? time())), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:10px 12px;font-size:0.9rem;white-space:nowrap;">
                                        <?= htmlspecialchars((string)($entry['source'] ?? 'n/d'), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:10px 12px;font-size:0.95rem;">
                                        <?= htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding:10px 12px;font-size:0.85rem;color:var(--muted);">
                                        <?php $meta = $entry['meta'] ?? []; ?>
                                        <?php if (!is_array($meta) || $meta === []): ?>
                                            —
                                        <?php else: ?>
                                            <code style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:0.8rem;"><?= htmlspecialchars(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></code>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const layout = document.querySelector('[data-config-layout]');
    if (!layout) {
        return;
    }

    const buttons = Array.from(layout.querySelectorAll('[data-config-target]'));
    const sections = Array.from(layout.querySelectorAll('[data-config-section]'));

    const activate = (key, updateHash = true) => {
        let found = false;

        sections.forEach((section) => {
            const isMatch = section.dataset.configSection === key;
            section.classList.toggle('is-active', isMatch);
            if (isMatch) {
                found = true;
            }
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
    const defaultKey = buttons[0]?.dataset.configTarget || sections[0]?.dataset.configSection || '';
    const startKey = sections.some((section) => section.dataset.configSection === initialHash)
        ? initialHash
        : defaultKey;

    if (startKey) {
        activate(startKey, false);
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.configTarget;
            if (key) {
                activate(key);
            }
        });
    });
});
</script>
