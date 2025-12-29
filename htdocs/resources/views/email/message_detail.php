<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $message */
/** @var array<string, mixed>|null $thread */
/** @var array<string, mixed>|null $account */
/** @var array<string, string> $emailRoutes */
/** @var array<string, array<string, mixed>> $replyPresets */
/** @var string|null $errorMessage */

$escape = static fn(?string $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$formatBytes = static function (?int $bytes): string {
    $bytes = (int)($bytes ?? 0);
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = min((int)floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $index);
    return number_format($value, $index === 0 ? 0 : 1) . ' ' . $units[$index];
};
$formatDate = static function (?int $timestamp): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '—';
    }
    return date('d/m/Y H:i', $timestamp);
};
$messageData = is_array($message) ? $message : [];
$messageFolder = is_array($messageData['folder'] ?? null) ? $messageData['folder'] : [];
$threadData = is_array($thread) ? $thread : [];
$threadFolder = is_array($threadData['folder'] ?? null) ? $threadData['folder'] : [];
$participants = is_array($messageData['participants'] ?? null) ? $messageData['participants'] : [];
$filterParticipants = static function (array $rows, string $role): array {
    return array_values(array_filter($rows, static function (array $participant) use ($role): bool {
        return strtolower((string)($participant['role'] ?? '')) === strtolower($role);
    }));
};
$formatAddresses = static function (array $rows) use ($escape): string {
    if ($rows === []) {
        return '—';
    }
    $rendered = array_map(static function (array $participant): string {
        $email = trim((string)($participant['email'] ?? ''));
        $name = trim((string)($participant['name'] ?? ''));
        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        return $email !== '' ? $email : $name;
    }, $rows);

    return htmlspecialchars(implode(', ', array_filter($rendered, static fn(string $value): bool => $value !== '')), ENT_QUOTES, 'UTF-8');
};

$fromList = $filterParticipants($participants, 'from');
$toList = $filterParticipants($participants, 'to');
$ccList = $filterParticipants($participants, 'cc');
$bccList = $filterParticipants($participants, 'bcc');
$hasHtml = !empty($messageData['body_html'] ?? null);
$hasText = !empty($messageData['body_text'] ?? null);
$attachments = is_array($messageData['attachments'] ?? null) ? $messageData['attachments'] : [];
$folderLabel = $messageFolder['display_name']
    ?? $messageFolder['remote_name']
    ?? ($threadFolder['display_name'] ?? $threadFolder['remote_name'] ?? 'Pasta');
$threadLink = ($threadData['id'] ?? null)
    ? url('email/inbox') . '?thread_id=' . (int)$threadData['id'] . '&account_id=' . (int)($threadData['account_id'] ?? $messageData['account_id'] ?? 0)
    : url('email/inbox');
$configPayload = [
    'presets' => $replyPresets,
    'routes' => $emailRoutes,
    'csrf' => csrf_token(),
];
$configJsonRaw = json_encode(
    $configPayload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
);
$configJson = htmlspecialchars($configJsonRaw === false ? '{}' : $configJsonRaw, ENT_QUOTES, 'UTF-8');
$subject = $messageData['subject'] ?? '(sem assunto)';
$sentLabel = $formatDate($messageData['sent_at'] ?? null);
$receivedLabel = $formatDate($messageData['received_at'] ?? null);
$accountName = $account['name'] ?? 'Conta de e-mail';
$rootClasses = ['message-view-shell'];
if ($errorMessage !== null) {
    $rootClasses[] = 'is-error';
}
?>

<style>
    :root {
        --reader-bg: linear-gradient(135deg, #f6f8fb 0%, #eef2f7 100%);
        --reader-card: #ffffff;
        --reader-ink: #0f172a;
        --reader-muted: #5f6b83;
        --reader-accent: #0f8b8d;
        --reader-accent-dark: #0b6365;
        --reader-border: rgba(15, 23, 42, 0.12);
        --reader-radius: 22px;
    }

    .message-view-shell {
        font-family: "Space Grotesk", "Segoe UI", sans-serif;
        background: var(--reader-bg);
        min-height: 100vh;
        padding: 2.5rem;
        color: var(--reader-ink);
    }

    .message-view-shell.is-error {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .message-view-grid {
        max-width: 1180px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.75rem;
    }

    .message-card {
        background: var(--reader-card);
        border-radius: var(--reader-radius);
        border: 1px solid var(--reader-border);
        box-shadow: 0 30px 70px rgba(15, 23, 42, 0.08);
        padding: 2rem;
    }

    .message-view-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 1.25rem;
    }

    .message-view-header h1 {
        margin: 0;
        font-size: 2rem;
        letter-spacing: -0.02em;
    }

    .message-view-header span {
        color: var(--reader-muted);
    }

    .header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
    }

    .chip-link,
    .reply-chip {
        border-radius: 999px;
        padding: 0.55rem 1.25rem;
        font-size: 0.92rem;
        border: 1px solid var(--reader-border);
        background: #fff;
        color: var(--reader-ink);
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .chip-link:hover,
    .reply-chip:hover {
        border-color: var(--reader-accent);
        color: var(--reader-accent);
    }

    .reply-chip.is-primary {
        background: var(--reader-accent);
        border-color: var(--reader-accent);
        color: #fff;
        box-shadow: 0 12px 25px rgba(15, 139, 141, 0.35);
    }

    .message-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1.2rem;
    }

    .meta-block span {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: var(--reader-muted);
        margin-bottom: 0.35rem;
    }

    .meta-block p {
        margin: 0;
        font-size: 0.98rem;
        line-height: 1.4;
        word-break: break-word;
    }

    .body-tabs {
        display: inline-flex;
        gap: 0.4rem;
        margin-bottom: 0.75rem;
    }

    .body-tabs button {
        border-radius: 12px;
        border: 1px solid var(--reader-border);
        background: transparent;
        padding: 0.4rem 1rem;
        cursor: pointer;
        color: var(--reader-muted);
        transition: all 0.2s ease;
    }

    .body-tabs button.is-active {
        border-color: var(--reader-accent);
        color: var(--reader-accent);
        background: rgba(15, 139, 141, 0.08);
    }

    .message-body-pane {
        display: none;
        border: 1px solid var(--reader-border);
        border-radius: calc(var(--reader-radius) - 6px);
        min-height: 320px;
        padding: 1rem;
        background: #fff;
        overflow: hidden;
    }

    .message-body-pane.is-active {
        display: block;
    }

    .message-body-pane iframe {
        width: 100%;
        height: 70vh;
        border: none;
    }

    .message-body-pane pre {
        margin: 0;
        font-family: "IBM Plex Mono", Consolas, monospace;
        white-space: pre-wrap;
        line-height: 1.5;
    }

    .attachment-grid {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .attachment-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        border: 1px solid var(--reader-border);
        border-radius: calc(var(--reader-radius) - 8px);
        background: #fafbff;
    }

    .attachment-row a {
        text-decoration: none;
        color: var(--reader-accent-dark);
        font-weight: 600;
    }

    .reply-panel {
        background: #fff;
        border-radius: var(--reader-radius);
        border: 1px solid var(--reader-border);
        padding: 1.5rem;
        display: none;
        flex-direction: column;
        gap: 1rem;
    }

    .reply-panel.is-visible {
        display: flex;
    }

    .reply-panel header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }

    .reply-panel header strong {
        font-size: 1.1rem;
    }

    .reply-panel header button {
        border: none;
        background: transparent;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--reader-muted);
    }

    .composer-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .composer-form label {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--reader-muted);
    }

    .composer-field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .composer-field input,
    .composer-field textarea {
        border-radius: 12px;
        border: 1px solid var(--reader-border);
        padding: 0.65rem 0.85rem;
        font-size: 1rem;
        font-family: inherit;
    }

    .composer-field textarea {
        min-height: 200px;
        resize: vertical;
    }

    .composer-attachments small {
        color: var(--reader-muted);
    }

    .composer-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .composer-actions button {
        border-radius: 14px;
        border: none;
        padding: 0.7rem 1.5rem;
        font-size: 0.95rem;
        cursor: pointer;
    }

    .composer-actions button[data-compose-send] {
        background: var(--reader-accent);
        color: #fff;
        box-shadow: 0 12px 25px rgba(15, 139, 141, 0.3);
    }

    .composer-actions button[data-compose-draft] {
        background: rgba(15, 139, 141, 0.12);
        color: var(--reader-accent-dark);
    }

    .composer-status {
        font-size: 0.9rem;
        color: var(--reader-muted);
    }

    .composer-status[data-state="error"] {
        color: #b42318;
    }

    .composer-status[data-state="success"] {
        color: #15803d;
    }

    .empty-state {
        max-width: 480px;
        text-align: center;
        background: var(--reader-card);
        border-radius: var(--reader-radius);
        padding: 2rem;
        border: 1px solid var(--reader-border);
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.1);
    }

    .empty-state h2 {
        margin-top: 0;
    }

    .forward-attachment-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        border: 1px solid var(--reader-border);
        font-size: 0.82rem;
        gap: 0.4rem;
    }

    @media (max-width: 768px) {
        .message-view-shell {
            padding: 1.5rem 1rem 3rem;
        }

        .message-card {
            padding: 1.5rem;
        }

        .header-actions {
            width: 100%;
        }
    }
</style>

<div
    class="<?=implode(' ', $rootClasses);?>"
    data-message-view-root
    data-config="<?=$configJson;?>"
>
    <?php if ($errorMessage !== null || $message === null): ?>
        <div class="empty-state">
            <h2>Não foi possível exibir esta mensagem</h2>
            <p><?=$escape($errorMessage ?? 'Mensagem indisponível.');?></p>
            <a class="chip-link" href="<?=url('email/inbox');?>">Voltar para a caixa de entrada</a>
        </div>
    <?php else: ?>
        <div class="message-view-grid">
            <section class="message-card">
                <header class="message-view-header">
                    <div>
                        <p style="margin:0; text-transform:uppercase; letter-spacing:0.08em; color:var(--reader-muted);">Conta · <?=$escape($accountName);?></p>
                        <h1><?=$escape($subject);?></h1>
                        <span><?=$escape($sentLabel);?> · <?=$escape($folderLabel);?></span>
                    </div>
                    <div class="header-actions">
                        <a class="chip-link" href="<?=url('email/inbox');?>">Voltar</a>
                        <a class="chip-link" href="<?=$escape($threadLink);?>">Ver thread</a>
                        <button type="button" class="reply-chip is-primary" data-reply-trigger data-mode="reply">Responder</button>
                        <button type="button" class="reply-chip" data-reply-trigger data-mode="reply_all">Responder a todos</button>
                        <button type="button" class="reply-chip" data-reply-trigger data-mode="forward">Encaminhar</button>
                    </div>
                </header>
            </section>

            <section class="message-card">
                <div class="message-meta">
                    <div class="meta-block">
                        <span>De</span>
                        <p><?=$formatAddresses($fromList);?></p>
                    </div>
                    <div class="meta-block">
                        <span>Para</span>
                        <p><?=$formatAddresses($toList);?></p>
                    </div>
                    <div class="meta-block">
                        <span>Cc</span>
                        <p><?=$formatAddresses($ccList);?></p>
                    </div>
                    <div class="meta-block">
                        <span>Bcc</span>
                        <p><?=$formatAddresses($bccList);?></p>
                    </div>
                    <div class="meta-block">
                        <span>Recebido</span>
                        <p><?=$escape($receivedLabel);?></p>
                    </div>
                    <div class="meta-block">
                        <span>ID da mensagem</span>
                        <p><?=$escape((string)($messageData['internet_message_id'] ?? '—'));?></p>
                    </div>
                </div>
            </section>

            <section class="message-card">
                <?php if ($hasHtml || $hasText): ?>
                    <div class="body-tabs">
                        <?php if ($hasHtml): ?>
                            <button type="button" class="is-active" data-body-tab="html">HTML</button>
                        <?php endif; ?>
                        <?php if ($hasText): ?>
                            <button type="button" class="<?=$hasHtml ? '' : 'is-active';?>" data-body-tab="text">Texto</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($hasHtml): ?>
                    <div class="message-body-pane is-active" data-body-pane="html">
                        <iframe sandbox="" srcdoc="<?=htmlspecialchars((string)($messageData['body_html'] ?? ''), ENT_QUOTES, 'UTF-8');?>"></iframe>
                    </div>
                <?php endif; ?>
                <?php if ($hasText): ?>
                    <div class="message-body-pane <?=$hasHtml ? '' : 'is-active';?>" data-body-pane="text">
                        <pre><?=$escape((string)($messageData['body_text'] ?? ''));?></pre>
                    </div>
                <?php endif; ?>
                <?php if (!$hasHtml && !$hasText): ?>
                    <p style="color:var(--reader-muted);">Nenhum conteúdo disponível para esta mensagem.</p>
                <?php endif; ?>
            </section>

            <section class="message-card">
                <h3 style="margin-top:0;">Anexos</h3>
                <?php if ($attachments === []): ?>
                    <p style="color:var(--reader-muted);">Nenhum anexo disponível.</p>
                <?php else: ?>
                    <div class="attachment-grid">
                        <?php foreach ($attachments as $attachment): ?>
                            <?php $downloadUrl = $emailRoutes['attachmentDownloadBase'] ?? null;
                            $downloadHref = $downloadUrl ? $downloadUrl . '/' . (int)$attachment['id'] . '/download' : '#'; ?>
                            <div class="attachment-row">
                                <a href="<?=$escape($downloadHref);?>" target="_blank" rel="noopener">
                                    <?=$escape($attachment['filename'] ?? 'anexo');?>
                                </a>
                                <span><?=$formatBytes((int)($attachment['size_bytes'] ?? 0));?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="reply-panel" data-composer-panel>
                <header>
                    <div>
                        <p style="margin:0; text-transform:uppercase; letter-spacing:0.08em; color:var(--reader-muted);">Responder como <?=$escape($accountName);?></p>
                        <strong data-composer-mode-label>Responder</strong>
                    </div>
                    <button type="button" data-compose-cancel aria-label="Fechar">&times;</button>
                </header>
                <form class="composer-form" data-compose-form autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?=csrf_token();?>">
                    <input type="hidden" name="thread_id" value="<?=$escape((string)($messageData['thread_id'] ?? ''));?>" data-compose-thread>
                    <input type="hidden" name="account_id" value="<?=$escape((string)($messageData['account_id'] ?? ''));?>" data-compose-account>
                    <div data-inherit-attachments></div>

                    <div class="composer-field">
                        <label for="compose-to">Para</label>
                        <input id="compose-to" type="text" name="to" placeholder="destinatario@empresa.com" data-compose-to>
                    </div>
                    <div class="composer-field">
                        <label for="compose-cc">Cc</label>
                        <input id="compose-cc" type="text" name="cc" placeholder="cc@empresa.com" data-compose-cc>
                    </div>
                    <div class="composer-field">
                        <label for="compose-bcc">Bcc</label>
                        <input id="compose-bcc" type="text" name="bcc" placeholder="bcc@empresa.com" data-compose-bcc>
                    </div>
                    <div class="composer-field">
                        <label for="compose-subject">Assunto</label>
                        <input id="compose-subject" type="text" name="subject" data-compose-subject>
                    </div>
                    <div class="composer-field">
                        <label for="compose-body">Mensagem</label>
                        <textarea id="compose-body" name="body_text" data-compose-body></textarea>
                    </div>
                    <div class="composer-field composer-attachments">
                        <label for="compose-attachments">Anexos adicionais</label>
                        <input id="compose-attachments" type="file" name="attachments[]" multiple data-compose-attachments>
                        <small data-attachment-summary>Nenhum arquivo selecionado.</small>
                    </div>
                    <div class="composer-field" data-forward-attachment-wrapper hidden>
                        <label>Anexos herdados</label>
                        <div class="attachment-grid" data-forward-attachment-list>
                            <span style="color:var(--reader-muted);">Nenhum anexo herdado.</span>
                        </div>
                    </div>
                    <p class="composer-status" data-compose-status data-state="idle">Selecione uma ação para começar.</p>
                    <div class="composer-actions">
                        <button type="button" data-compose-send>Enviar</button>
                        <button type="button" data-compose-draft>Salvar rascunho</button>
                    </div>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const root = document.querySelector('[data-message-view-root]');
    if (!root) {
        return;
    }

    const configRaw = root.getAttribute('data-config') || '{}';
    let config = {};
    try {
        config = JSON.parse(configRaw);
    } catch (error) {
        config = {};
    }

    const presets = config.presets || {};
    const routes = config.routes || {};
    const csrfToken = config.csrf || '';

    const composer = {
        panel: root.querySelector('[data-composer-panel]'),
        form: root.querySelector('[data-compose-form]'),
        to: root.querySelector('[data-compose-to]'),
        cc: root.querySelector('[data-compose-cc]'),
        bcc: root.querySelector('[data-compose-bcc]'),
        subject: root.querySelector('[data-compose-subject]'),
        body: root.querySelector('[data-compose-body]'),
        attachments: root.querySelector('[data-compose-attachments]'),
        status: root.querySelector('[data-compose-status]'),
        send: root.querySelector('[data-compose-send]'),
        draft: root.querySelector('[data-compose-draft]'),
        cancel: root.querySelector('[data-compose-cancel]'),
        modeLabel: root.querySelector('[data-composer-mode-label]'),
        inheritContainer: root.querySelector('[data-inherit-attachments]'),
        forwardWrapper: root.querySelector('[data-forward-attachment-wrapper]'),
        forwardList: root.querySelector('[data-forward-attachment-list]'),
        account: root.querySelector('[data-compose-account]'),
        thread: root.querySelector('[data-compose-thread]'),
        attachmentSummary: root.querySelector('[data-attachment-summary]'),
    };

    const replyButtons = root.querySelectorAll('[data-reply-trigger]');
    replyButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.getAttribute('data-mode');
            applyPreset(mode);
        });
    });

    if (composer.cancel) {
        composer.cancel.addEventListener('click', () => {
            closeComposer();
        });
    }

    if (composer.send) {
        composer.send.addEventListener('click', (event) => {
            event.preventDefault();
            submitComposer('send');
        });
    }

    if (composer.draft) {
        composer.draft.addEventListener('click', (event) => {
            event.preventDefault();
            submitComposer('draft');
        });
    }

    if (composer.attachments && composer.attachmentSummary) {
        composer.attachments.addEventListener('change', () => {
            const files = composer.attachments.files;
            if (!files || files.length === 0) {
                composer.attachmentSummary.textContent = 'Nenhum arquivo selecionado.';
                return;
            }
            if (files.length === 1) {
                composer.attachmentSummary.textContent = `${files[0].name} (${formatBytes(files[0].size)})`;
                return;
            }
            const total = Array.from(files).reduce((sum, file) => sum + file.size, 0);
            composer.attachmentSummary.textContent = `${files.length} arquivos (${formatBytes(total)})`;
        });
    }

    const bodyTabs = root.querySelectorAll('[data-body-tab]');
    const bodyPanes = root.querySelectorAll('[data-body-pane]');
    bodyTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-body-tab');
            if (!target) {
                return;
            }
            bodyTabs.forEach((button) => button.classList.toggle('is-active', button === tab));
            bodyPanes.forEach((pane) => {
                pane.classList.toggle('is-active', pane.getAttribute('data-body-pane') === target);
            });
        });
    });

    function applyPreset(mode) {
        if (!mode || !presets[mode]) {
            return;
        }
        const preset = presets[mode];
        openComposer();

        if (composer.modeLabel) {
            const labelMap = {
                'reply': 'Responder',
                'reply_all': 'Responder a todos',
                'forward': 'Encaminhar',
            };
            composer.modeLabel.textContent = labelMap[mode] || 'Responder';
        }

        if (composer.to) composer.to.value = preset.to || '';
        if (composer.cc) composer.cc.value = preset.cc || '';
        if (composer.bcc) composer.bcc.value = '';
        if (composer.subject) composer.subject.value = preset.subject || '';
        if (composer.body) composer.body.value = preset.body_text || '';
        if (composer.account) composer.account.value = preset.account_id || '';
        if (composer.thread) composer.thread.value = preset.thread_id || '';

        syncInheritedAttachments(preset.inherit_attachment_ids || []);
        renderForwardAttachments(preset.forward_attachments || []);

        if (composer.status) {
            composer.status.dataset.state = 'idle';
            composer.status.textContent = 'Pronto para enviar.';
        }
    }

    function syncInheritedAttachments(ids) {
        if (!composer.inheritContainer) {
            return;
        }
        composer.inheritContainer.innerHTML = '';
        ids.forEach((value) => {
            const numeric = Number(value);
            if (!Number.isFinite(numeric) || numeric <= 0) {
                return;
            }
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'inherit_attachment_ids[]';
            input.value = String(numeric);
            composer.inheritContainer.appendChild(input);
        });
    }

    function renderForwardAttachments(list) {
        if (!composer.forwardWrapper || !composer.forwardList) {
            return;
        }
        composer.forwardList.innerHTML = '';
        if (!Array.isArray(list) || list.length === 0) {
            composer.forwardWrapper.hidden = true;
            composer.forwardList.innerHTML = '<span style="color:var(--reader-muted);">Nenhum anexo herdado.</span>';
            return;
        }
        composer.forwardWrapper.hidden = false;
        list.forEach((attachment) => {
            const badge = document.createElement('span');
            badge.className = 'forward-attachment-badge';
            const name = attachment.filename || 'anexo';
            const size = formatBytes(Number(attachment.size_bytes) || 0);
            badge.textContent = `${name} · ${size}`;
            composer.forwardList.appendChild(badge);
        });
    }

    function openComposer() {
        if (!composer.panel) {
            return;
        }
        composer.panel.classList.add('is-visible');
        composer.panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeComposer() {
        if (!composer.panel || !composer.form) {
            return;
        }
        composer.panel.classList.remove('is-visible');
        composer.form.reset();
        if (composer.status) {
            composer.status.dataset.state = 'idle';
            composer.status.textContent = 'Selecione uma ação para começar.';
        }
        renderForwardAttachments([]);
        syncInheritedAttachments([]);
        if (composer.attachmentSummary) {
            composer.attachmentSummary.textContent = 'Nenhum arquivo selecionado.';
        }
    }

    async function submitComposer(mode) {
        if (!composer.form) {
            return;
        }
        const endpoint = mode === 'draft' ? routes.composeDraft : routes.composeSend;
        if (!endpoint) {
            return;
        }

        const formData = new FormData(composer.form);
        if (csrfToken && !formData.has('_token')) {
            formData.append('_token', csrfToken);
        }

        setComposerBusy(true, mode);
        updateStatus('Enviando...', 'pending');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await safeJson(response);
            if (!response.ok || (payload && payload.error)) {
                const message = payload && payload.error ? payload.error : 'Não foi possível enviar a mensagem.';
                updateStatus(message, 'error');
                return;
            }
            updateStatus(mode === 'draft' ? 'Rascunho salvo com sucesso.' : 'Mensagem enviada com sucesso.', 'success');
            if (mode !== 'draft') {
                composer.form.reset();
                syncInheritedAttachments([]);
                renderForwardAttachments([]);
            }
        } catch (error) {
            updateStatus('Erro inesperado ao enviar a mensagem.', 'error');
        } finally {
            setComposerBusy(false, mode);
        }
    }

    function updateStatus(text, state) {
        if (!composer.status) {
            return;
        }
        composer.status.textContent = text;
        composer.status.dataset.state = state;
    }

    function setComposerBusy(isBusy, mode) {
        const target = mode === 'draft' ? composer.draft : composer.send;
        if (target) {
            target.disabled = isBusy;
            target.setAttribute('aria-busy', String(isBusy));
        }
    }

    async function safeJson(response) {
        try {
            return await response.json();
        } catch (error) {
            return null;
        }
    }

    function formatBytes(value) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let bytes = Number(value) || 0;
        if (bytes <= 0) {
            return '0 B';
        }
        let index = Math.floor(Math.log(bytes) / Math.log(1024));
        index = Math.min(index, units.length - 1);
        const amount = bytes / Math.pow(1024, index);
        return `${amount.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
    }
})();
</script>
