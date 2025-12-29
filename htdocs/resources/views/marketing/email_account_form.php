<?php
$account = $account ?? [];
$errors = $errors ?? [];
$mode = $mode ?? 'create';
$action = $action ?? '';
$title = $title ?? 'Conta de envio';
$providers = $providers ?? [];
$encryptionOptions = $encryptionOptions ?? ['none', 'starttls', 'tls'];
$authModes = $authModes ?? ['login', 'plain'];
$statusOptions = $statusOptions ?? ['active'];
$warmupOptions = $warmupOptions ?? ['ready'];
$templateEngines = $templateEngines ?? [];
$dnsStatusOptions = $dnsStatusOptions ?? [];
$bouncePolicies = $bouncePolicies ?? [];
$apiProviders = $apiProviders ?? [];
$listCleaningOptions = $listCleaningOptions ?? [];

$isEditMode = $mode === 'edit';
$hasTokenFields = trim((string)($account['oauth_token'] ?? '')) !== '' || trim((string)($account['api_key'] ?? '')) !== '';
$hasAdvancedConfigs = trim((string)($account['headers'] ?? '')) !== '' || trim((string)($account['settings'] ?? '')) !== '';
$webmailProviders = ['gmail', 'google_workspace', 'workspace', 'outlook', 'hotmail', 'live', 'office365', 'yahoo', 'icloud', 'zoho'];
$currentProvider = (string)($account['provider'] ?? 'custom');
$initialFlow = in_array($currentProvider, $webmailProviders, true) ? 'webmail' : 'domain';
if ($hasTokenFields) {
    $initialFlow = 'advanced';
}
$autoSyncDefault = $initialFlow === 'webmail' && (
    trim((string)($account['username'] ?? '')) === '' ||
    ($account['username'] ?? '') === ($account['from_email'] ?? '')
);
$imapSyncDefault = isset($account['imap_sync_enabled']) ? (int)$account['imap_sync_enabled'] === 1 : true;
if (!$isEditMode && $currentProvider === 'mailgrid') {
    $imapSyncDefault = false; // MailGrid será apenas para envio; recebimento via Napoleon.
}
$flowDescriptionMap = [
    'domain' => 'Use SMTP do seu domínio, com presets locais e checklist de DNS.',
    'webmail' => 'Configure Gmail, Outlook e similares com presets prontos e auto-sync do usuário SMTP.',
    'advanced' => 'Acesse tokens, limites e integrações especiais quando precisar de recursos extras.'
];
$currentFlowDescription = $flowDescriptionMap[$initialFlow] ?? $flowDescriptionMap['domain'];
?>

<style>
    .form-field { display:flex; flex-direction:column; gap:6px; }
    .form-field span { font-size:0.85rem; color:var(--muted); }
    .form-field input,
    .form-field select,
    .form-field textarea { padding:12px; border-radius:12px; border:1px solid var(--border); background:rgba(15,23,42,0.6); color:var(--text); }
    .form-field textarea { min-height:110px; }
    .form-field .error { color:#f87171; }
    .fieldset { border:1px solid var(--border); border-radius:16px; padding:18px; background:rgba(15,23,42,0.45); display:flex; flex-direction:column; gap:14px; }
    .fieldset h3 { margin:0; font-size:1.05rem; }
    .fieldset p { margin:0; color:var(--muted); }
    .toggle-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
    .toggle-grid label { border:1px solid rgba(148,163,184,0.3); border-radius:12px; padding:10px 12px; display:flex; gap:8px; align-items:flex-start; background:rgba(15,23,42,0.4); font-size:0.9rem; }
    .toggle-grid label input { margin-top:4px; }
    .grid-auto { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
    .form-shell { display:grid; gap:24px; grid-template-columns:minmax(0,1fr) 300px; align-items:flex-start; }
    .form-main { display:flex; flex-direction:column; gap:18px; }
    .form-aside { border:1px dashed rgba(148,163,184,0.4); border-radius:18px; padding:18px; background:rgba(15,23,42,0.45); position:sticky; top:96px; display:flex; flex-direction:column; gap:16px; }
    .aside-card { border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:14px; background:rgba(15,23,42,0.6); }
    .aside-card h3 { margin:0 0 6px; font-size:1rem; }
    .aside-card p, .aside-card li { color:var(--muted); font-size:0.9rem; }
    .aside-card ol { margin:0; padding-left:18px; display:flex; flex-direction:column; gap:6px; }
    .quick-links { display:flex; flex-direction:column; gap:10px; }
    .quick-links a { text-align:center; }
    .flow-subnav { display:flex; flex-wrap:wrap; gap:10px; margin:14px 0 6px; }
    .flow-subnav button { border:1px solid rgba(148,163,184,0.35); border-radius:18px; padding:10px 16px; background:rgba(15,23,42,0.35); color:inherit; text-align:left; cursor:pointer; transition:all 0.2s; min-width:200px; flex:1; display:flex; flex-direction:column; gap:4px; }
    .flow-subnav button strong { font-size:0.82rem; letter-spacing:0.08em; text-transform:uppercase; }
    .flow-subnav button span { font-size:0.82rem; color:var(--muted); }
    .flow-subnav button[data-active="true"] { background:rgba(34,197,94,0.15); border-color:rgba(34,197,94,0.7); box-shadow:0 0 0 1px rgba(34,197,94,0.15); }
    .flow-subnav-description { margin:0 0 12px; color:var(--muted); font-size:0.9rem; padding:12px 16px; border:1px dashed rgba(148,163,184,0.35); border-radius:14px; background:rgba(15,23,42,0.3); }
    .flow-panels { display:flex; flex-direction:column; gap:18px; }
    .flow-panel { display:block; }
    [data-flow-panel][data-active="false"] { display:none !important; }
    .flow-panel.flow-panel-inline { height:100%; }
    .inline-toggle { display:flex; align-items:center; gap:10px; font-size:0.85rem; color:var(--muted); padding:10px 0; }
    .inline-toggle input[type="checkbox"] { margin:0; }
    input[readonly] { background:rgba(148,163,184,0.15); cursor:not-allowed; }
    .preset-pills { border:1px dashed rgba(148,163,184,0.35); border-radius:14px; padding:14px; background:rgba(15,23,42,0.35); display:flex; flex-direction:column; gap:10px; }
    .preset-pill-row { display:flex; flex-wrap:wrap; gap:8px; }
    .preset-pill { border:1px solid rgba(148,163,184,0.4); border-radius:999px; padding:7px 16px; font-size:0.85rem; background:transparent; color:inherit; cursor:pointer; transition:background 0.2s, border 0.2s; }
    .preset-pill[data-active="true"] { background:rgba(34,197,94,0.18); border-color:rgba(34,197,94,0.55); color:#bbf7d0; }
    .section-heading { display:flex; flex-direction:column; gap:4px; }
    .section-heading p { margin:0; color:var(--muted); font-size:0.9rem; }
    details.accordion { border:1px solid rgba(148,163,184,0.25); border-radius:14px; background:rgba(15,23,42,0.45); padding:0 18px; }
    details.accordion + details.accordion { margin-top:14px; }
    details.accordion summary { list-style:none; cursor:pointer; padding:14px 0; font-weight:600; display:flex; align-items:center; justify-content:space-between; }
    details.accordion summary::marker { display:none; }
    details.accordion[open] summary { border-bottom:1px solid rgba(148,163,184,0.2); margin-bottom:12px; }
    .accordion-body { display:flex; flex-direction:column; gap:16px; padding-bottom:16px; }
    .domain-presets { border:1px dashed rgba(148,163,184,0.35); border-radius:14px; background:rgba(15,23,42,0.25); padding:14px; display:flex; flex-direction:column; gap:12px; }
    .domain-presets small { color:var(--muted); }
    .domain-actions { display:flex; flex-wrap:wrap; gap:8px; }
    .domain-saver { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .domain-saver input { flex:1; min-width:200px; }
    .preset-hint { color:var(--muted); font-size:0.85rem; margin:0; }
    .helper-text { display:block; font-size:0.8rem; color:var(--muted); margin-top:4px; }
    .credential-body { display:flex; flex-wrap:wrap; gap:18px; }
    .credential-grid { flex:1 1 320px; }
    .credential-helper { flex:1 1 220px; border:1px dashed rgba(148,163,184,0.35); border-radius:14px; padding:14px; background:rgba(15,23,42,0.25); color:var(--muted); }
    .credential-helper strong { display:block; font-size:0.9rem; color:#e0f2fe; margin-bottom:6px; }
    .credential-helper ul { margin:0; padding-left:18px; display:flex; flex-direction:column; gap:4px; font-size:0.85rem; }
    .credential-password { border:1px solid rgba(148,163,184,0.35); border-radius:14px; padding:12px; background:rgba(15,23,42,0.35); }
    .form-actions { display:flex; flex-wrap:wrap; gap:12px; justify-content:flex-end; align-items:center; margin-top:6px; }
    @media (max-width:1100px) {
        .form-shell { grid-template-columns:1fr; }
        .form-aside { position:static; }
    }
</style>

<header>
    <div>
        <h1 style="margin-bottom:8px;<?= $isEditMode ? 'color:#c4b5fd;' : ''; ?>"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p style="color:var(--muted);max-width:780px;">Complete os blocos essenciais (identidade, SMTP e compliance). Campos avançados como tokens, JSON e integrações extras ficam recolhidos para você abrir apenas quando precisar.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('marketing/email-accounts'); ?>">&larr; Voltar</a>
    </div>
</header>

<div class="panel" style="margin-top:18px;">
    <div class="form-shell">
        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" class="form-main">
            <?= csrf_field(); ?>

            <div class="flow-subnav" role="tablist" aria-label="Selecione o tipo de conta">
                <button type="button" data-flow-tab="domain" data-flow-description="Use SMTP do seu domínio, com presets locais e checklist de DNS.">
                    <strong>Domínio próprio</strong>
                    <span>Host e porta customizados, com salvamento de presets.</span>
                </button>
                <button type="button" data-flow-tab="webmail" data-flow-description="Configure Gmail, Outlook e similares com presets prontos e auto-sync do usuário SMTP.">
                    <strong>Gmail / Hotmail</strong>
                    <span>Atalhos para Gmail, Outlook, Yahoo, Zoho e iCloud.</span>
                </button>
                <button type="button" data-flow-tab="advanced" data-flow-description="Acesse tokens, limites e integrações especiais quando precisar de recursos extras.">
                    <strong>Outros recursos</strong>
                    <span>Tokens OAuth, APIs, limites e integrações avançadas.</span>
                </button>
            </div>
            <p class="flow-subnav-description" id="flowDescription" aria-live="polite"><?= htmlspecialchars($currentFlowDescription, ENT_QUOTES, 'UTF-8'); ?></p>
            <input type="hidden" name="flow_type" id="flowTypeInput" value="<?= htmlspecialchars($initialFlow, ENT_QUOTES, 'UTF-8'); ?>">

            <section class="fieldset">
                <div class="section-heading">
                    <h3>Identidade da conta</h3>
                    <p>Nome interno aparece em relatórios. O provedor ajuda a carregar presets de saúde.</p>
                </div>
                <div class="grid-auto">
                    <label class="form-field">
                        <span>Nome interno *</span>
                        <input type="text" name="name" value="<?= htmlspecialchars($account['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php if (isset($errors['name'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="form-field">
                        <span>Provedor *</span>
                        <select name="provider" id="providerSelect">
                            <?php foreach ($providers as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= (($account['provider'] ?? 'custom') === $value) ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['provider'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['provider'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <div class="flow-panel flow-panel-inline" data-flow-panel="domain">
                        <label class="form-field">
                            <span>Domínio dedicado</span>
                            <input type="text" name="domain" id="domainInput" value="<?= htmlspecialchars($account['domain'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="emails.suaempresa.com">
                        </label>
                    </div>
                </div>
                <div class="preset-pills flow-panel" data-flow-panel="webmail" id="providerPresetBlock">
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <strong style="font-size:0.92rem;">Atalhos populares</strong>
                        <span class="preset-hint">Preenche host, porta e criptografia dos provedores comuns.</span>
                    </div>
                    <div class="preset-pill-row">
                        <button type="button" class="preset-pill" data-provider-preset="gmail">Gmail / Workspace</button>
                        <button type="button" class="preset-pill" data-provider-preset="outlook">Outlook / Hotmail</button>
                        <button type="button" class="preset-pill" data-provider-preset="yahoo">Yahoo Mail</button>
                        <button type="button" class="preset-pill" data-provider-preset="zoho">Zoho Mail</button>
                        <button type="button" class="preset-pill" data-provider-preset="icloud">iCloud</button>
                    </div>
                        <button type="button" class="preset-pill" data-provider-preset="mailgrid">Mailgrid (TLS 1.2/1.3)</button>
                    <p class="preset-hint" id="providerPresetHint">Selecione um preset rápido ou siga com o preenchimento manual.</p>
                </div>
            </section>

            <section class="fieldset">
                <div class="section-heading">
                    <h3>Remetente exibido</h3>
                    <p>Nome e e-mail vistos pelos leads. Reply-to opcional para direcionar respostas.</p>
                </div>
                <div class="grid-auto">
                    <label class="form-field">
                        <span>Nome do remetente *</span>
                        <input type="text" name="from_name" value="<?= htmlspecialchars($account['from_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php if (isset($errors['from_name'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['from_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="form-field">
                        <span>E-mail do remetente *</span>
                        <input type="email" name="from_email" id="fromEmailInput" value="<?= htmlspecialchars($account['from_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php if (isset($errors['from_email'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['from_email'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="form-field">
                        <span>Reply-to</span>
                        <input type="email" name="reply_to" value="<?= htmlspecialchars($account['reply_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Opcional">
                        <?php if (isset($errors['reply_to'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['reply_to'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                </div>
            </section>

            <div class="flow-panels">

            <div class="flow-panel flow-panel-spaced" data-flow-panel="domain,webmail">
                <section class="fieldset">
                    <div class="section-heading">
                        <h3>Infraestrutura SMTP</h3>
                        <p>Host, porta e criptografia alimentam os testes automáticos.</p>
                    </div>
                    <div class="domain-presets flow-panel" data-flow-panel="domain" id="domainPresetTools">
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <strong style="font-size:0.92rem;">Presets para domínio próprio</strong>
                        <small>Salve host, porta, criptografia e autenticação das contas do seu domínio no próprio navegador.</small>
                    </div>
                    <div class="grid-auto" style="gap:12px;">
                        <label class="form-field">
                            <span>Preset salvo</span>
                            <select id="domainPresetSelect">
                                <option value="">Selecionar...</option>
                            </select>
                        </label>
                        <label class="form-field">
                            <span>Nome do preset</span>
                            <input type="text" id="domainPresetLabel" placeholder="ex: SMTP @acme.com">
                        </label>
                    </div>
                    <div class="domain-actions">
                        <button type="button" class="ghost" id="applyDomainPreset" disabled>Aplicar preset</button>
                        <button type="button" class="ghost" id="deleteDomainPreset" disabled>Remover preset</button>
                        <button type="button" class="primary" id="saveDomainPreset">Salvar atual</button>
                    </div>
                    <p class="preset-hint" id="domainPresetFeedback" aria-live="polite">Sem presets salvos ainda.</p>
                </div>
                    <div class="grid-auto">
                    <input type="hidden" name="limit_source" id="limitSourceInput" value="<?= htmlspecialchars($account['limit_source'] ?? 'auto', ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="form-field">
                        <span>Host SMTP *</span>
                        <input type="text" name="smtp_host" id="smtpHostInput" value="<?= htmlspecialchars($account['smtp_host'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php if (isset($errors['smtp_host'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['smtp_host'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="form-field">
                        <span>Porta *</span>
                        <input type="number" min="1" name="smtp_port" id="smtpPortInput" value="<?= htmlspecialchars($account['smtp_port'] ?? '587', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php if (isset($errors['smtp_port'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['smtp_port'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    <label class="form-field">
                        <span>Criptografia *</span>
                        <select name="encryption" id="encryptionSelect">
                            <?php foreach ($encryptionOptions as $option): ?>
                                <option value="<?= $option; ?>" <?= (($account['encryption'] ?? 'tls') === $option) ? 'selected' : ''; ?>><?= strtoupper($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['encryption'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['encryption'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    </div>
                </section>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="domain,webmail">
                <section class="fieldset">
                    <div class="section-heading">
                        <h3>Credenciais</h3>
                        <p>Senha em branco mantém a credencial existente quando estiver editando.</p>
                    </div>
                    <div class="credential-body">
                        <div class="grid-auto credential-grid">
                            <div class="flow-panel flow-panel-inline" data-flow-panel="webmail">
                                <label class="inline-toggle">
                                    <input type="hidden" name="auto_sync_username" value="0">
                                    <input type="checkbox" id="autoSyncEmailToggle" name="auto_sync_username" value="1" <?= $autoSyncDefault ? 'checked' : ''; ?>>
                                    <span>Usar o e-mail do remetente como usuário SMTP (recomendado para Gmail/Hotmail).</span>
                                </label>
                            </div>
                            <label class="form-field">
                                <span>Autenticação *</span>
                                <select name="auth_mode" id="authModeSelect">
                                    <?php foreach ($authModes as $modeOption): ?>
                                        <option value="<?= $modeOption; ?>" <?= (($account['auth_mode'] ?? 'login') === $modeOption) ? 'selected' : ''; ?>><?= strtoupper($modeOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['auth_mode'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['auth_mode'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </label>
                            <label class="form-field">
                                <span>Usuário</span>
                                <input type="text" name="username" id="usernameInput" value="<?= htmlspecialchars($account['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="login@dominio.com">
                                <?php if (isset($errors['username'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['username'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </label>
                            <label class="form-field credential-password">
                                <span>Senha SMTP <?= $isEditMode ? '(deixe em branco para manter)' : '*'; ?></span>
                                <input type="password" name="password" value="" placeholder="Senha de app ou senha SMTP">
                                <small class="helper-text">Gere a senha no painel do provedor (Gmail/Outlook = senha de app). Nunca use senha pessoal.</small>
                                <?php if (!empty($account['has_password'])): ?><small class="helper-text">Uma senha já está armazenada e será mantida se este campo ficar vazio.</small><?php endif; ?>
                                <?php if (isset($errors['password'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </label>
                            <label class="inline-toggle">
                                <input type="hidden" name="imap_sync_enabled" value="0">
                                <input type="checkbox" id="imapSyncToggle" name="imap_sync_enabled" value="1" <?= $imapSyncDefault ? 'checked' : ''; ?>>
                                <span>Sincronizar caixa de entrada (IMAP). Desmarque quando o recebimento for feito em outro provedor (ex: Napoleon).</span>
                            </label>
                        </div>
                        <div class="credential-helper" role="note">
                            <strong>Onde colocar a senha?</strong>
                            <ul>
                                <li>Use a senha SMTP criada no painel do provedor ou uma senha de app.</li>
                                <li>Para domínios próprios, é a senha do usuário criado no painel de e-mail.</li>
                                <li>Em edições, deixar em branco mantém a senha atual.</li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="domain,webmail,advanced">
                <details class="accordion" <?= $hasTokenFields ? 'open' : ''; ?>>
                    <summary>Tokens e integrações alternativas</summary>
                    <div class="accordion-body">
                        <div class="grid-auto">
                            <label class="form-field">
                                <?php $oauthPlaceholder = !empty($account['has_oauth_token']) ? 'Token já cadastrado (deixe em branco para manter)' : 'Cole o token JWT ou refresh token'; ?>
                                <span>Token OAuth2</span>
                                <textarea name="oauth_token" placeholder="<?= htmlspecialchars($oauthPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($account['oauth_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <?php if (!empty($account['has_oauth_token'])): ?><small class="helper-text">Token atual permanece se nenhum novo valor for informado.</small><?php endif; ?>
                                <?php if (isset($errors['oauth_token'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['oauth_token'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </label>
                            <label class="form-field">
                                <?php $apiKeyPlaceholder = !empty($account['has_api_key']) ? 'API key já cadastrada (deixe em branco para manter)' : 'Chave para provedores tipo Sendgrid/Sparkpost'; ?>
                                <span>API key</span>
                                <textarea name="api_key" placeholder="<?= htmlspecialchars($apiKeyPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($account['api_key'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <?php if (!empty($account['has_api_key'])): ?><small class="helper-text">Chave atual será mantida se nada for preenchido.</small><?php endif; ?>
                                <?php if (isset($errors['api_key'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['api_key'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </label>
                        </div>
                    </div>
                </details>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="domain,advanced">
                <section class="fieldset">
                    <div class="section-heading">
                        <h3>Limites e status</h3>
                        <p>Defina quantos envios por minuto e por dia a conta suporta. Esses números alimentam a régua de disparo e o painel de capacidade.</p>
                        <?php if (!empty($caps) && ($account['provider'] ?? 'custom') === 'custom'): ?>
                            <?php
                                $capMin = (int)($caps['domain_cap_per_minute'] ?? 0);
                                $usedMin = (int)($caps['domain_used_per_minute'] ?? 0);
                                $remainMin = max(0, $capMin - $usedMin);
                                $perHourCap = $capMin * 60;
                                $perHourUsed = $usedMin * 60;
                                $perHourRemain = $remainMin * 60;
                            ?>
                            <p class="helper-text" style="margin-top:6px;">
                                Teto do domínio: <?= number_format($capMin, 0, ',', '.'); ?>/min (<?= number_format($perHourCap, 0, ',', '.'); ?>/h). Usado: <?= number_format($usedMin, 0, ',', '.'); ?>/min (<?= number_format($perHourUsed, 0, ',', '.'); ?>/h). Disponível: <?= number_format($remainMin, 0, ',', '.'); ?>/min (<?= number_format($perHourRemain, 0, ',', '.'); ?>/h).
                            </p>
                        <?php elseif (!empty($providerLimits)): ?>
                            <?php
                                $pMin = (int)($providerLimits['per_minute'] ?? 0);
                                $pHour = (int)($providerLimits['per_hour'] ?? 0);
                                $pDay = (int)($providerLimits['per_day'] ?? 0);
                            ?>
                            <?php if ($pMin > 0 || $pDay > 0): ?>
                                <p class="helper-text" style="margin-top:6px;">
                                    Política recomendada do provedor: <?= $pMin > 0 ? number_format($pMin, 0, ',', '.') . '/min' : '—'; ?><?= $pHour > 0 ? ' (' . number_format($pHour, 0, ',', '.') . '/h)' : ''; ?><?= $pDay > 0 ? ' · ' . number_format($pDay, 0, ',', '.') . '/dia' : ''; ?>. Ajuste com cautela para não violar limites gratuitos.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="grid-auto">
                    <label class="form-field">
                        <span>Limite por minuto</span>
                        <input type="number" min="0" name="hourly_limit" id="minuteLimitInput" value="<?= htmlspecialchars($account['hourly_limit'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="helper-text">Qtd máxima de e-mails/minuto. Use um valor maior que zero; presets já preenchem o recomendado.</small>
                    </label>
                    <label class="form-field">
                        <span>Limite por dia</span>
                        <input type="number" min="0" name="daily_limit" id="dailyLimitInput" value="<?= htmlspecialchars($account['daily_limit'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="helper-text">Qtd máxima diária total (obrigatório). Presets aplicam limites conforme o provedor.</small>
                    </label>
                    <label class="form-field">
                        <span>Burst (rajada)</span>
                        <input type="number" min="0" name="burst_limit" id="burstLimitInput" value="<?= htmlspecialchars($account['burst_limit'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                        <small class="helper-text">Reserva para picos rápidos (obrigatório). Use valor acima de zero.</small>
                    </label>
                    <label class="form-field">
                        <span>Warmup *</span>
                        <select name="warmup_status">
                            <?php foreach ($warmupOptions as $option): ?>
                                <option value="<?= $option; ?>" <?= (($account['warmup_status'] ?? 'ready') === $option) ? 'selected' : ''; ?>><?= ucfirst($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span>Status *</span>
                        <select name="status">
                            <?php foreach ($statusOptions as $option): ?>
                                <option value="<?= $option; ?>" <?= (($account['status'] ?? 'active') === $option) ? 'selected' : ''; ?>><?= ucfirst($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    </div>
                </section>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="advanced">
                <section class="fieldset">
                    <div class="section-heading">
                        <h3>Compliance e rodapé</h3>
                        <p>Esses links e textos são necessários para manter descadastro e política de privacidade em cada disparo.</p>
                    </div>
                    <div class="grid-auto">
                        <label class="form-field">
                            <span>Link de descadastro *</span>
                            <input type="url" name="footer_unsubscribe_url" value="<?= htmlspecialchars($account['footer_unsubscribe_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://suaempresa.com.br/descadastro" required>
                            <small class="helper-text">URL pública onde o lead confirma o opt-out.</small>
                            <?php if (isset($errors['footer_unsubscribe_url'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['footer_unsubscribe_url'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        </label>
                        <label class="form-field">
                            <span>Política de privacidade *</span>
                            <input type="url" name="footer_privacy_url" value="<?= htmlspecialchars($account['footer_privacy_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://suaempresa.com.br/politica" required>
                            <small class="helper-text">Página com os termos de privacidade.</small>
                            <?php if (isset($errors['footer_privacy_url'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['footer_privacy_url'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        </label>
                        <label class="form-field">
                            <span>Endereço físico *</span>
                            <textarea name="footer_company_address" placeholder="Rua Exemplo, 123 - Cidade/UF" required><?= htmlspecialchars($account['footer_company_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small class="helper-text">Usado no rodapé obrigatório pelas boas práticas CAN-SPAM/LGPD.</small>
                            <?php if (isset($errors['footer_company_address'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['footer_company_address'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        </label>
                        <label class="form-field">
                            <span>List-Unsubscribe header *</span>
                            <input type="text" name="list_unsubscribe_header" value="<?= htmlspecialchars($account['list_unsubscribe_header'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="<mailto:descadastro@suaempresa.com.br>, <https://suaempresa.com.br/descadastro>" required>
                            <small class="helper-text">Informe mailto/URL que os provedores usam para descadastro automático.</small>
                            <?php if (isset($errors['list_unsubscribe_header'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['list_unsubscribe_header'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        </label>
                    </div>
                </section>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="advanced">
                <section class="fieldset">
                    <div class="section-heading">
                        <h3>Execução no VS Code</h3>
                        <p>Marque as extensões configuradas conforme o guia operacional.</p>
                    </div>
                    <div class="toggle-grid">
                    <label>
                        <input type="hidden" name="tooling_vs_mail" value="0">
                        <input type="checkbox" name="tooling_vs_mail" value="1" <?= (($account['tooling_vs_mail'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>VSCode Mail</strong><br><small>Cliente IMAP/SMTP</small></div>
                    </label>
                    <label>
                        <input type="hidden" name="tooling_postie" value="0">
                        <input type="checkbox" name="tooling_postie" value="1" <?= (($account['tooling_postie'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>Postie</strong><br><small>Smoke tests via Nodemailer</small></div>
                    </label>
                    <label>
                        <input type="hidden" name="tooling_email_editing_tools" value="0">
                        <input type="checkbox" name="tooling_email_editing_tools" value="1" <?= (($account['tooling_email_editing_tools'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>Email Editing Tools</strong><br><small>Preview/inspeção HTML</small></div>
                    </label>
                    <label>
                        <input type="hidden" name="tooling_emaildev_utilities" value="0">
                        <input type="checkbox" name="tooling_emaildev_utilities" value="1" <?= (($account['tooling_emaildev_utilities'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>EmailDev Utilities</strong><br><small>Lint e validação</small></div>
                    </label>
                    <label>
                        <input type="hidden" name="tooling_live_server" value="0">
                        <input type="checkbox" name="tooling_live_server" value="1" <?= (($account['tooling_live_server'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>Live Server</strong><br><small>Preview em tempo real</small></div>
                    </label>
                    <label>
                        <input type="hidden" name="tooling_template_manager" value="0">
                        <input type="checkbox" name="tooling_template_manager" value="1" <?= (($account['tooling_template_manager'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <div><strong>Template Manager</strong><br><small>Biblioteca de layouts</small></div>
                    </label>
                    </div>
                    <label class="form-field">
                        <span>Observações do setup</span>
                        <textarea name="tooling_notes" placeholder="Extensões adicionais, workspaces, atalhos dedicados."><?= htmlspecialchars($account['tooling_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>
                </section>
            </div>

            <div class="flow-panel flow-panel-spaced" data-flow-panel="advanced">
                <section class="fieldset">
                    <h3>Editor e templates</h3>
                    <div class="grid-auto">
                    <label class="form-field">
                        <span>Engine principal *</span>
                        <select name="template_engine">
                            <?php foreach ($templateEngines as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= (($account['template_engine'] ?? 'mjml') === $value) ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['template_engine'])): ?><small class="error">&bull; <?= htmlspecialchars($errors['template_engine'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </label>
                    </div>
                </section>
            </div>

            </div>

            <div class="form-actions">
                <a class="ghost" href="<?= url('marketing/email-accounts'); ?>">Cancelar</a>
                <button type="submit" class="primary"><?= $isEditMode ? 'Atualizar conta' : 'Criar conta'; ?></button>
            </div>
        </form>

        <aside class="form-aside">
            <div class="aside-card">
                <h3>Checklist rápido</h3>
                <ol>
                    <li>Escolha o provedor e use um preset para Gmail/Hotmail se for conta pessoal.</li>
                    <li>Para domínios próprios, salve o host/porta no bloco de presets para reaproveitar.</li>
                    <li>Rode um teste de envio após salvar para validar credenciais.</li>
                </ol>
            </div>
            <div class="aside-card">
                <h3>Senhas de app</h3>
                <p>Gmail, Outlook e iCloud exigem senhas específicas de app para SMTP. Gere no painel do provedor e cole aqui.</p>
                <div class="quick-links">
                    <a class="ghost" target="_blank" rel="noopener" href="https://support.google.com/accounts/answer/185833">Senha de app Gmail</a>
                    <a class="ghost" target="_blank" rel="noopener" href="https://support.microsoft.com/pt-br/account-billing/create-app-passwords-3e7c8608-9a3e-43b5-8388-6b64edc41a3a">Senha de app Outlook</a>
                </div>
            </div>
            <div class="aside-card">
                <h3>DNS essencial</h3>
                <p>Garanta DKIM, SPF e MX ativos antes de aquecer. Use os presets para manter a padronização por domínio.</p>
            </div>
        </aside>
    </div>
</div>

<script>
(function(){
    const providerPresets = {
        gmail: {
            label: 'Gmail / Workspace',
            providerValue: 'gmail',
            smtp_host: 'smtp.gmail.com',
            smtp_port: '587',
            encryption: 'tls',
            auth_mode: 'login',
            hint: 'Use senha de app (2FA obrigatório) e mantenha TLS na porta 587.',
            limits: {
                hourly_limit: 30,
                daily_limit: 1800,
                burst_limit: 60,
            },
        },
        mailgrid: {
            label: 'Mailgrid (TLS 1.2/1.3)',
            providerValue: 'mailgrid',
            smtp_host: 'server18.mailgrid.com.br',
            smtp_port: '587',
            encryption: 'tls',
            auth_mode: 'login',
            hint: 'Use TLS na porta 587 (ou STARTTLS). Autenticação LOGIN/PLAIN. Limite 2000/h, máx 14/s, 20 conexões.',
            limits: {
                hourly_limit: 2000,
                daily_limit: 48000,
                burst_limit: 14,
            },
        },
        outlook: {
            label: 'Outlook / Hotmail',
            providerValue: 'outlook',
            smtp_host: 'smtp.office365.com',
            smtp_port: '587',
            encryption: 'tls',
            auth_mode: 'login',
            hint: 'Habilite SMTP autenticado no painel Microsoft e mantenha TLS + porta 587.',
            limits: {
                hourly_limit: 25,
                daily_limit: 1000,
                burst_limit: 40,
            },
        },
        yahoo: {
            label: 'Yahoo Mail',
            providerValue: 'yahoo',
            smtp_host: 'smtp.mail.yahoo.com',
            smtp_port: '465',
            encryption: 'ssl',
            auth_mode: 'login',
            hint: 'Necessário gerar senha de app Yahoo. Porta 465 com SSL.',
            limits: {
                hourly_limit: 20,
                daily_limit: 500,
                burst_limit: 25,
            },
        },
        zoho: {
            label: 'Zoho Mail',
            providerValue: 'zoho',
            smtp_host: 'smtp.zoho.com',
            smtp_port: '587',
            encryption: 'tls',
            auth_mode: 'login',
            hint: 'Zoho recomenda TLS + 587. Confirme se a conta tem SMTP liberado.',
            limits: {
                hourly_limit: 18,
                daily_limit: 700,
                burst_limit: 30,
            },
        },
        icloud: {
            label: 'iCloud',
            providerValue: 'icloud',
            smtp_host: 'smtp.mail.me.com',
            smtp_port: '587',
            encryption: 'tls',
            auth_mode: 'login',
            hint: 'Ative autenticação em 2 fatores na Apple ID e gere senha específica.',
            limits: {
                hourly_limit: 10,
                daily_limit: 200,
                burst_limit: 15,
            },
        },
    };

    const doc = document;
    const hostInput = doc.getElementById('smtpHostInput');
    const portInput = doc.getElementById('smtpPortInput');
    const encryptionSelect = doc.getElementById('encryptionSelect');
    const authModeSelect = doc.getElementById('authModeSelect');
    const providerSelect = doc.getElementById('providerSelect');
    const domainInput = doc.getElementById('domainInput');
    const fromEmailInput = doc.getElementById('fromEmailInput');
    const usernameInput = doc.getElementById('usernameInput');
    const minuteLimitInput = doc.getElementById('minuteLimitInput');
    const dailyLimitInput = doc.getElementById('dailyLimitInput');
    const burstLimitInput = doc.getElementById('burstLimitInput');
    const limitSourceInput = doc.getElementById('limitSourceInput');
    const presetHint = doc.getElementById('providerPresetHint');
    const presetButtons = doc.querySelectorAll('[data-provider-preset]');
    const autoSyncToggle = doc.getElementById('autoSyncEmailToggle');
    const imapSyncToggle = doc.getElementById('imapSyncToggle');
    const flowDescriptionEl = doc.getElementById('flowDescription');
    const initialFlow = <?= json_encode($initialFlow); ?>;
    const flowTabs = doc.querySelectorAll('[data-flow-tab]');
    const flowElements = doc.querySelectorAll('[data-flow-panel]');
    const flowInput = doc.getElementById('flowTypeInput');
    const webmailProviderSet = new Set(<?= json_encode($webmailProviders); ?>);
    let currentFlow = initialFlow || 'domain';

    function shouldLockUsername() {
        return currentFlow === 'webmail' && autoSyncToggle && autoSyncToggle.checked;
    }

    function applyUsernameLock() {
        if (!usernameInput) { return; }
        if (shouldLockUsername()) {
            if (fromEmailInput) {
                usernameInput.value = fromEmailInput.value;
            }
            usernameInput.setAttribute('readonly', 'readonly');
        } else {
            usernameInput.removeAttribute('readonly');
        }
    }

    function syncFromEmailToUsername() {
        if (!fromEmailInput || !usernameInput) { return; }
        if (shouldLockUsername()) {
            usernameInput.value = fromEmailInput.value;
        }
    }

    function parseFlowAttr(el) {
        const attr = el.getAttribute('data-flow-panel');
        if (!attr) { return []; }
        return attr.split(',').map(function(entry){ return entry.trim(); }).filter(Boolean);
    }

    function setFlow(flow) {
        currentFlow = flow;
        let activeDescription = '';
        flowTabs.forEach(function(button){
            const isActive = button.getAttribute('data-flow-tab') === flow;
            button.dataset.active = isActive ? 'true' : 'false';
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            if (isActive) {
                activeDescription = button.getAttribute('data-flow-description') || '';
            }
        });
        flowElements.forEach(function(el){
            const allowed = parseFlowAttr(el);
            if (allowed.length === 0) { return; }
            el.dataset.active = allowed.includes(flow) ? 'true' : 'false';
        });
        if (flowInput) {
            flowInput.value = flow;
        }
        if (flowDescriptionEl && activeDescription) {
            flowDescriptionEl.textContent = activeDescription;
        }
        applyUsernameLock();
    }

    setFlow(currentFlow);

    flowTabs.forEach(function(button){
        button.addEventListener('click', function(){
            const target = button.getAttribute('data-flow-tab');
            if (target) {
                setFlow(target);
            }
        });
    });

    if (autoSyncToggle) {
        autoSyncToggle.addEventListener('change', function(){
            applyUsernameLock();
            if (autoSyncToggle.checked) {
                syncFromEmailToUsername();
            }
        });
    }

    if (fromEmailInput) {
        fromEmailInput.addEventListener('input', function(){
            syncFromEmailToUsername();
        });
    }

    function optionExists(selectEl, value) {
        if (!selectEl) { return false; }
        return Array.prototype.some.call(selectEl.options, function(opt){ return opt.value === value; });
    }

    function setPresetHint(text) {
        if (presetHint) { presetHint.textContent = text; }
    }

    function setLimitSource(value) {
        if (limitSourceInput) {
            limitSourceInput.value = value;
        }
    }

    function applyFallbackLimits(provider) {
        const isCustom = provider === 'custom';
        const minuteDefault = isCustom ? 34 : 4;
        const dailyDefault = isCustom ? 48000 : 240;
        const burstDefault = isCustom ? 60 : 20;

        if (minuteLimitInput && (!minuteLimitInput.value || Number(minuteLimitInput.value) <= 0)) {
            minuteLimitInput.value = minuteDefault;
        }
        if (dailyLimitInput && (!dailyLimitInput.value || Number(dailyLimitInput.value) <= 0)) {
            dailyLimitInput.value = dailyDefault;
        }
        if (burstLimitInput && (!burstLimitInput.value || Number(burstLimitInput.value) <= 0)) {
            burstLimitInput.value = burstDefault;
        }
        setLimitSource('preset');
    }

    function applyLimitDefaults(limits) {
        if (!limits) { return; }
        if (minuteLimitInput && typeof limits.hourly_limit !== 'undefined') {
            minuteLimitInput.value = limits.hourly_limit;
        }
        if (dailyLimitInput && typeof limits.daily_limit !== 'undefined') {
            dailyLimitInput.value = limits.daily_limit;
        }
        if (burstLimitInput && typeof limits.burst_limit !== 'undefined') {
            burstLimitInput.value = limits.burst_limit;
        }
        setLimitSource('preset');
    }

    function applyProviderDefaultsIfEmpty(provider, limits) {
        if (!limits) { return; }
        const minuteDefault = limits.hourly_limit;
        const dailyDefault = limits.daily_limit;
        const burstDefault = limits.burst_limit;

        if (minuteLimitInput && (!minuteLimitInput.value || Number(minuteLimitInput.value) <= 0) && typeof minuteDefault !== 'undefined') {
            minuteLimitInput.value = minuteDefault;
        }
        if (dailyLimitInput && (!dailyLimitInput.value || Number(dailyLimitInput.value) <= 0) && typeof dailyDefault !== 'undefined') {
            dailyLimitInput.value = dailyDefault;
        }
        if (burstLimitInput && (!burstLimitInput.value || Number(burstLimitInput.value) <= 0) && typeof burstDefault !== 'undefined') {
            burstLimitInput.value = burstDefault;
        }
        setLimitSource('preset');
    }

    function formatLimitSummary(limits) {
        if (!limits) { return ''; }
        const parts = [];
        if (typeof limits.hourly_limit !== 'undefined') {
            parts.push(limits.hourly_limit + '/min');
        }
        if (typeof limits.daily_limit !== 'undefined') {
            parts.push(limits.daily_limit + '/dia');
        }
        if (typeof limits.burst_limit !== 'undefined' && Number(limits.burst_limit) > 0) {
            parts.push('burst ' + limits.burst_limit);
        }
        return parts.length ? ' Limites padrão: ' + parts.join(' • ') + '.' : '';
    }

    function buildPresetHint(preset) {
        const base = preset.hint || 'Preset aplicado.';
        return base + formatLimitSummary(preset.limits);
    }

    function applyProviderPreset(key, options) {
        const preset = providerPresets[key];
        if (!preset || !hostInput || !portInput || !encryptionSelect) { return false; }

        const autoFlow = !options || options.autoFlow !== false;
        if (autoFlow) {
            const targetFlow = webmailProviderSet.has(preset.providerValue) ? 'webmail' : 'domain';
            setFlow(targetFlow);
        }

        if (providerSelect && preset.providerValue && optionExists(providerSelect, preset.providerValue)) {
            providerSelect.value = preset.providerValue;
        }

        hostInput.value = preset.smtp_host;
        portInput.value = preset.smtp_port;
        encryptionSelect.value = preset.encryption;
        if (authModeSelect && preset.auth_mode) {
            authModeSelect.value = preset.auth_mode;
        }
        applyLimitDefaults(preset.limits);
        setPresetHint(buildPresetHint(preset));
        if (imapSyncToggle) {
            imapSyncToggle.checked = preset.providerValue === 'mailgrid' ? false : imapSyncToggle.checked;
        }
        return true;
    }

    presetButtons.forEach(function(button){
        button.addEventListener('click', function(){
            const key = button.getAttribute('data-provider-preset');
            if (!key) { return; }
            presetButtons.forEach(function(other){ other.dataset.active = 'false'; });
            button.dataset.active = 'true';
            applyProviderPreset(key, { autoFlow: true });
        });
    });

    function markManualLimits() {
        setLimitSource('manual');
    }

    [minuteLimitInput, dailyLimitInput, burstLimitInput].forEach(function(input){
        if (!input) { return; }
        input.addEventListener('input', markManualLimits);
    });

    if (providerSelect) {
        providerSelect.addEventListener('change', function(){
            const applied = applyProviderPreset(providerSelect.value, { autoFlow: true });
            if (!applied) {
                if (currentFlow === 'advanced') { return; }
                const targetFlow = webmailProviderSet.has(providerSelect.value) ? 'webmail' : 'domain';
                setFlow(targetFlow);
                applyFallbackLimits(providerSelect.value);
            }

            // Se for provedor gratuito com defaults, preenche apenas se vazio.
            const preset = providerPresets[providerSelect.value];
            if (preset && preset.limits) {
                applyProviderDefaultsIfEmpty(providerSelect.value, preset.limits);
            }
        });
    }

    // Pré-preencher em carregamento inicial se os campos estiverem vazios e houver preset do provider atual
    (function prefillOnLoad(){
        const currentProvider = providerSelect ? providerSelect.value : null;
        if (!currentProvider) { return; }
        const preset = providerPresets[currentProvider];
        if (preset && preset.limits) {
            applyProviderDefaultsIfEmpty(currentProvider, preset.limits);
        }
    })();

    [minuteLimitInput, dailyLimitInput, burstLimitInput].forEach(function(input){
        if (!input) { return; }
        input.addEventListener('blur', function(){
            const val = Number(input.value || '0');
            if (!Number.isFinite(val) || val <= 0) {
                input.value = '1';
            }
        });
    });

    const domainPresetSelect = doc.getElementById('domainPresetSelect');
    const domainPresetLabel = doc.getElementById('domainPresetLabel');
    const domainPresetFeedback = doc.getElementById('domainPresetFeedback');
    const applyDomainPreset = doc.getElementById('applyDomainPreset');
    const deleteDomainPreset = doc.getElementById('deleteDomainPreset');
    const saveDomainPreset = doc.getElementById('saveDomainPreset');
    const storageKey = 'marketingEmailDomainPresets';

    function safeStorage(fn, fallback) {
        try {
            return fn();
        } catch (err) {
            console.warn('Domain preset storage disabled', err);
            if (domainPresetFeedback) {
                domainPresetFeedback.textContent = 'Salvamento local indisponível neste navegador.';
                domainPresetFeedback.style.color = '#f87171';
            }
            if (applyDomainPreset) { applyDomainPreset.disabled = true; }
            if (deleteDomainPreset) { deleteDomainPreset.disabled = true; }
            if (saveDomainPreset) { saveDomainPreset.disabled = true; }
            return fallback;
        }
    }

    let domainPresets = safeStorage(function(){
        const stored = window.localStorage.getItem(storageKey);
        return stored ? JSON.parse(stored) : {};
    }, {});

    function persistPresets() {
        safeStorage(function(){
            window.localStorage.setItem(storageKey, JSON.stringify(domainPresets));
        });
    }

    function renderDomainOptions(selectedKey) {
        if (!domainPresetSelect) { return; }
        const fragment = doc.createDocumentFragment();
        const placeholder = doc.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Selecionar...';
        fragment.appendChild(placeholder);
        Object.keys(domainPresets).sort().forEach(function(key){
            const option = doc.createElement('option');
            option.value = key;
            option.textContent = domainPresets[key].label || key;
            fragment.appendChild(option);
        });
        domainPresetSelect.innerHTML = '';
        domainPresetSelect.appendChild(fragment);
        if (selectedKey && domainPresets[selectedKey]) {
            domainPresetSelect.value = selectedKey;
        }
        updateDomainButtons();
    }

    function updateDomainButtons() {
        if (!domainPresetSelect) { return; }
        const hasSelection = Boolean(domainPresetSelect.value && domainPresets[domainPresetSelect.value]);
        if (applyDomainPreset) { applyDomainPreset.disabled = !hasSelection; }
        if (deleteDomainPreset) { deleteDomainPreset.disabled = !hasSelection; }
        if (!domainPresetFeedback) { return; }
        if (Object.keys(domainPresets).length === 0) {
            domainPresetFeedback.textContent = 'Sem presets salvos ainda.';
        }
    }

    function setDomainFeedback(message, isError) {
        if (!domainPresetFeedback) { return; }
        domainPresetFeedback.textContent = message;
        domainPresetFeedback.style.color = isError ? '#f87171' : 'var(--muted)';
    }

    function collectPresetPayload() {
        return {
            label: (domainPresetLabel && domainPresetLabel.value.trim()) || (domainInput && domainInput.value.trim()) || '',
            domain: domainInput ? domainInput.value.trim() : '',
            smtp_host: hostInput ? hostInput.value.trim() : '',
            smtp_port: portInput ? portInput.value.trim() : '',
            encryption: encryptionSelect ? encryptionSelect.value : '',
            auth_mode: authModeSelect ? authModeSelect.value : '',
            username: usernameInput ? usernameInput.value.trim() : '',
            from_email: fromEmailInput ? fromEmailInput.value.trim() : ''
        };
    }

    if (domainPresetSelect) {
        renderDomainOptions();
        domainPresetSelect.addEventListener('change', updateDomainButtons);
    }

    if (domainInput && domainPresetLabel) {
        domainInput.addEventListener('blur', function(){
            if (!domainPresetLabel.value.trim() && domainInput.value.trim()) {
                domainPresetLabel.value = domainInput.value.trim();
            }
        });
    }

    if (saveDomainPreset) {
        saveDomainPreset.addEventListener('click', function(){
            const payload = collectPresetPayload();
            if (!payload.smtp_host || !payload.smtp_port) {
                setDomainFeedback('Preencha host e porta antes de salvar.', true);
                return;
            }
            const key = (payload.label || payload.domain || (payload.smtp_host + ':' + payload.smtp_port)).toLowerCase();
            if (!key) {
                setDomainFeedback('Use o nome do domínio ou um apelido para identificar o preset.', true);
                return;
            }
            domainPresets[key] = payload;
            persistPresets();
            renderDomainOptions(key);
            setDomainFeedback('Preset salvo para ' + (payload.label || key) + '.', false);
        });
    }

    if (applyDomainPreset) {
        applyDomainPreset.addEventListener('click', function(){
            const key = domainPresetSelect ? domainPresetSelect.value : '';
            const preset = key ? domainPresets[key] : null;
            if (!preset) { return; }
            if (hostInput) { hostInput.value = preset.smtp_host || ''; }
            if (portInput) { portInput.value = preset.smtp_port || ''; }
            if (encryptionSelect && preset.encryption) { encryptionSelect.value = preset.encryption; }
            if (authModeSelect && preset.auth_mode) { authModeSelect.value = preset.auth_mode; }
            if (usernameInput && preset.username) { usernameInput.value = preset.username; }
            if (fromEmailInput && preset.from_email) { fromEmailInput.value = preset.from_email; }
            if (domainInput && preset.domain) { domainInput.value = preset.domain; }
            setDomainFeedback('Preset aplicado: ' + (preset.label || key) + '.', false);
        });
    }

    if (deleteDomainPreset) {
        deleteDomainPreset.addEventListener('click', function(){
            const key = domainPresetSelect ? domainPresetSelect.value : '';
            if (!key || !domainPresets[key]) { return; }
            delete domainPresets[key];
            persistPresets();
            renderDomainOptions();
            setDomainFeedback('Preset removido.', false);
        });
    }
})();
</script>
