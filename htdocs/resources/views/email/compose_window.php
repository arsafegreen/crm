<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $accounts */
/** @var int|null $activeAccountId */
/** @var array<string, string> $emailRoutes */
/** @var array<int, array<string, mixed>> $templates */
/** @var array<int, array<string, mixed>> $audienceLists */

$escape = static fn(?string $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

$accountOptions = array_map(static function (array $accountRow): array {
    return [
        'id' => (int)($accountRow['id'] ?? 0),
        'label' => trim((string)($accountRow['name'] ?? $accountRow['from_email'] ?? 'Conta sem nome')),
        'from_email' => $accountRow['from_email'] ?? null,
    ];
}, $accounts);

$templateOptions = array_map(static function (array $templateRow): array {
    $id = (string)($templateRow['id'] ?? '');
    return [
        'id' => $id,
        'label' => trim((string)($templateRow['label'] ?? $templateRow['name'] ?? 'Modelo')), 
        'subject' => (string)($templateRow['subject'] ?? ''),
        'html' => (string)($templateRow['html'] ?? ''),
        'text' => (string)($templateRow['text'] ?? ''),
    ];
}, is_array($templates ?? null) ? $templates : []);

$config = [
    'accountId' => $activeAccountId,
    'routes' => $emailRoutes,
    'templates' => $templateOptions,
    'audiences' => array_map(static function (array $row): array {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => trim((string)($row['name'] ?? 'Grupo')),
            'contacts' => (int)($row['contacts_subscribed'] ?? $row['contacts_total'] ?? 0),
        ];
    }, is_array($audienceLists ?? null) ? $audienceLists : []),
];
$configJson = htmlspecialchars(json_encode($config, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$hasAccounts = $accountOptions !== [];
?>

<style>
    :root {
        --compose-bg: linear-gradient(135deg, #f5f7fb 0%, #e9ecf2 100%);
        --compose-card: #ffffff;
        --compose-ink: #0f172a;
        --compose-muted: #5f6c86;
        --compose-accent: #2563eb;
        --compose-border: rgba(15, 23, 42, 0.12);
        --compose-radius: 22px;
    }

    body {
        margin: 0;
        background: var(--compose-bg);
        min-height: 100vh;
        font-family: "Space Grotesk", "Segoe UI", sans-serif;
        color: var(--compose-ink);
    }

    .compose-window-shell {
        max-width: 1100px;
        margin: 0 auto;
        padding: 2.5rem 1.5rem 3rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .compose-card {
        background: var(--compose-card);
        border-radius: var(--compose-radius);
        border: 1px solid var(--compose-border);
        box-shadow: 0 30px 70px rgba(15, 23, 42, 0.12);
        padding: 2rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .composer-field.has-suggestions {
        position: relative;
    }

    .compose-suggestions {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        right: 0;
        background: #fff;
        border-radius: 16px;
        border: 1px solid var(--compose-border);
        box-shadow: 0 22px 45px rgba(15, 23, 42, 0.12);
        padding: 0.35rem;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        max-height: 260px;
        overflow-y: auto;
        z-index: 20;
    }

    .compose-group-row {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .compose-group-row select {
        flex: 1;
    }

    .compose-group-row button {
        padding: 0.75rem 1.15rem;
        border-radius: 12px;
        border: 1px solid var(--compose-border);
        background: var(--compose-ink);
        color: #fff;
        cursor: pointer;
        font-weight: 600;
        transition: transform 0.1s ease, box-shadow 0.15s ease;
    }

    .compose-group-row button:active {
        transform: translateY(1px);
    }

    .composer-hint[data-group-status] {
        color: var(--compose-muted);
    }

    .compose-suggestions[hidden] {
        display: none;
    }

    .compose-suggestion-option {
        border: none;
        background: transparent;
        text-align: left;
        padding: 0.5rem 0.65rem;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        font-size: 0.95rem;
        font-family: inherit;
        color: var(--compose-ink);
        transition: background 0.15s ease;
    }

    .compose-suggestion-option strong {
        font-size: 0.95rem;
        color: var(--compose-ink);
    }

    .compose-suggestion-option span {
        font-size: 0.82rem;
        color: var(--compose-muted);
    }

    .compose-suggestion-option.is-active,
    .compose-suggestion-option:hover {
        background: rgba(37, 99, 235, 0.08);
    }

    .compose-suggestion-option.is-active span {
        color: var(--compose-ink);
    }

    .compose-window-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .compose-window-header h1 {
        margin: 0;
        font-size: 2.25rem;
        letter-spacing: -0.02em;
    }

    .compose-window-header span {
        color: var(--compose-muted);
    }

    .composer-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .composer-field {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .composer-hint {
        color: var(--compose-muted);
        font-size: 0.9rem;
        margin-top: -0.15rem;
    }

    .composer-field label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: var(--compose-muted);
    }

    .composer-field input,
    .composer-field select,
    .composer-field textarea {
        border-radius: 14px;
        border: 1px solid var(--compose-border);
        padding: 0.75rem 1rem;
        font-size: 1rem;
        font-family: inherit;
        background: #fff;
    }

    .composer-field textarea {
        min-height: 320px;
        resize: vertical;
    }

    .composer-row-inline {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .composer-toggle {
        border: none;
        background: rgba(15, 23, 42, 0.05);
        color: var(--compose-ink);
        border-radius: 999px;
        padding: 0.35rem 0.9rem;
        cursor: pointer;
        font-size: 0.9rem;
    }

    .composer-toggle[aria-pressed="true"] {
        background: rgba(37, 99, 235, 0.15);
        color: var(--compose-accent);
    }

    .attachments-drop {
        border: 1px dashed var(--compose-border);
        border-radius: 18px;
        padding: 1rem;
        text-align: center;
        color: var(--compose-muted);
    }

    .attachment-list {
        margin: 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .attachment-list li {
        display: flex;
        justify-content: space-between;
        border-radius: 12px;
        border: 1px solid var(--compose-border);
        padding: 0.5rem 0.9rem;
        font-size: 0.95rem;
    }

    .composer-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .composer-actions button {
        border-radius: 16px;
        border: none;
        padding: 0.85rem 1.6rem;
        font-size: 1rem;
        cursor: pointer;
        font-weight: 600;
    }

    .composer-actions button[data-role="send"] {
        background: var(--compose-accent);
        color: #fff;
        box-shadow: 0 14px 30px rgba(37, 99, 235, 0.35);
    }

    .composer-actions button[data-role="draft"] {
        background: rgba(37, 99, 235, 0.12);
        color: var(--compose-accent);
    }

    .composer-actions button[data-role="discard"] {
        background: rgba(15, 23, 42, 0.06);
        color: var(--compose-ink);
    }

    .composer-status {
        font-size: 0.95rem;
        color: var(--compose-muted);
    }

    .composer-status[data-state="error"] {
        color: #b42318;
    }

    .composer-status[data-state="success"] {
        color: #15803d;
    }

    .empty-alert {
        padding: 2rem;
        border-radius: var(--compose-radius);
        border: 1px solid var(--compose-border);
        text-align: center;
        background: #fff;
    }
</style>

<div
    class="compose-window-shell"
    data-compose-window-root
    data-config="<?=$configJson;?>"
    data-csrf="<?=$csrfToken;?>"
    data-contacts-endpoint="<?=url('crm/clients/contact-search');?>"
>
    <div class="compose-window-header">
        <div>
            <h1>Novo email</h1>
            <span>Escreva uma nova mensagem usando o mesmo formato de leitura.</span>
        </div>
        <div>
            <a href="<?=url('email/inbox');?>" style="text-decoration:none;color:var(--compose-muted);font-size:0.95rem;">Voltar à caixa de entrada</a>
        </div>
    </div>

    <?php if (!$hasAccounts): ?>
        <div class="empty-alert">
            <h2>Nenhuma conta configurada</h2>
            <p>Adicione uma conta de e-mail antes de enviar novas mensagens.</p>
        </div>
    <?php else: ?>
        <section class="compose-card">
            <form class="composer-form" data-compose-form>
                <div class="composer-field">
                    <label for="compose-account">Conta de envio</label>
                    <select id="compose-account" name="account_id" data-compose-account>
                        <?php foreach ($accountOptions as $account): ?>
                            <option value="<?=$account['id'];?>" <?=$account['id'] === (int)$activeAccountId ? 'selected' : '';?>>
                                <?=$escape($account['label']);?><?php if ($account['from_email'] ?? null): ?> (<?=$escape($account['from_email']);?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="composer-field has-suggestions">
                    <label for="compose-to">Para</label>
                    <input type="text" id="compose-to" name="to" placeholder="destinatario@empresa.com" data-compose-to autocomplete="off">
                    <div class="compose-suggestions" data-contact-suggestions hidden></div>
                </div>

                <div class="composer-row-inline">
                    <button type="button" class="composer-toggle" data-toggle-cc aria-pressed="false">Cc</button>
                    <button type="button" class="composer-toggle" data-toggle-bcc aria-pressed="false">Bcc</button>
                </div>

                <div class="composer-field" data-cc-row hidden>
                    <label for="compose-cc">Cc</label>
                    <input type="text" id="compose-cc" name="cc" placeholder="copias@empresa.com" data-compose-cc>
                </div>

                <div class="composer-field" data-bcc-row hidden>
                    <label for="compose-bcc">Bcc</label>
                    <input type="text" id="compose-bcc" name="bcc" placeholder="ocultos@empresa.com" data-compose-bcc>
                </div>

                <div class="composer-field">
                    <label for="compose-audience">Usar grupos</label>
                    <div class="compose-group-row">
                        <select id="compose-audience" data-compose-audience>
                            <option value="">Selecione um grupo...</option>
                            <?php foreach (is_array($audienceLists ?? null) ? $audienceLists : [] as $list): ?>
                                <?php $contactsCount = (int)($list['contacts_subscribed'] ?? $list['contacts_total'] ?? 0); ?>
                                <option value="<?=$list['id'];?>"><?=$escape($list['name'] ?? 'Grupo');?><?php if ($contactsCount > 0): ?> (<?=$contactsCount;?>)<?php endif; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" data-compose-audience-apply>OK</button>
                    </div>
                    <small class="composer-hint" data-group-status></small>
                </div>

                <div class="composer-field">
                    <label for="compose-template">Modelos rápidos</label>
                    <select id="compose-template" data-compose-template>
                        <option value="">Selecionar modelo...</option>
                        <?php foreach ($templateOptions as $template): ?>
                            <?php if (($template['id'] ?? '') === '') { continue; } ?>
                            <option value="<?=$escape($template['id']);?>"><?=$escape($template['label']);?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="composer-hint">Personalize os campos entre {{ }} antes de enviar.</small>
                    <small class="composer-hint">Atributos disponíveis: {{nome}}, {{empresa}}/{{razao_social}}, {{email}}, {{documento}}, {{cpf}}, {{cnpj}}, {{titular_nome}}, {{titular_documento}}, {{data_nascimento}}.</small>
                </div>

                <div class="composer-field">
                    <label for="compose-subject">Assunto</label>
                    <input type="text" id="compose-subject" name="subject" placeholder="Digite o assunto" data-compose-subject>
                </div>

                <div class="composer-field">
                    <label for="compose-body">Mensagem</label>
                    <textarea id="compose-body" name="body" placeholder="Escreva sua mensagem" data-compose-body></textarea>
                </div>

                <div class="composer-field">
                    <label>Anexos</label>
                    <div class="attachments-drop">
                        <input type="file" name="attachments[]" data-compose-attachments multiple style="margin-bottom:0.5rem;">
                        <small>Arraste e solte ou clique para selecionar arquivos.</small>
                    </div>
                    <ul class="attachment-list" data-attachment-list></ul>
                </div>

                <input type="hidden" name="draft_id" value="" data-compose-draft-id>

                <div class="composer-actions">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button type="button" data-role="send" data-compose-send>Enviar</button>
                        <button type="button" class="composer-toggle" data-compose-schedule-toggle aria-pressed="false">Programar envio</button>
                    </div>
                    <div class="composer-field" data-compose-schedule-row hidden>
                        <label for="compose-scheduled-for">Agendar para</label>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="datetime-local" id="compose-scheduled-for" name="scheduled_for" data-compose-schedule-input style="max-width:240px;">
                            <button type="button" class="composer-toggle" data-compose-schedule-clear>Limpar</button>
                        </div>
                        <small class="composer-hint">Se preencher, enviaremos no horário escolhido (hora local).</small>
                    </div>
                    <button type="button" data-role="draft" data-compose-draft>Salvar rascunho</button>
                    <button type="button" data-role="discard" data-compose-discard>Descartar</button>
                </div>

                <p class="composer-status" data-compose-status>Pronto para enviar.</p>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php if ($hasAccounts): ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    const root = document.querySelector('[data-compose-window-root]');
    if (!root) {
        return;
    }

    const config = (() => {
        try {
            return JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (error) {
            return {};
        }
    })();

    const TEMPLATES = (Array.isArray(config.templates) ? config.templates : []).map((tpl) => {
        const html = tpl.html && tpl.html.trim() !== ''
            ? tpl.html
            : (tpl.text ? wrapPlainTextAsHtml(tpl.text) : '');
        return {
            id: String(tpl.id || ''),
            label: tpl.label || 'Modelo',
            subject: tpl.subject || '',
            html,
        };
    }).filter((tpl) => tpl.id !== '');

    const escapeHtml = (value) => {
        return String(value ?? '').replace(/[&<>"']/g, (match) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        })[match]);
    };

    const wrapPlainTextAsHtml = (value) => {
        if (!value) {
            return '';
        }
        const safe = escapeHtml(value);
        return `<p>${safe.replace(/\r?\n/g, '<br>')}</p>`;
    };

    const htmlToPlainText = (html) => {
        if (!html) {
            return '';
        }
        const container = document.createElement('div');
        container.innerHTML = html;
        return (container.textContent || container.innerText || '').trim();
    };

    const debounce = (fn, wait = 250) => {
        let timeoutId;
        return (...args) => {
            window.clearTimeout(timeoutId);
            timeoutId = window.setTimeout(() => fn(...args), wait);
        };
    };

    const elements = {
        form: root.querySelector('[data-compose-form]'),
        account: root.querySelector('[data-compose-account]'),
        to: root.querySelector('[data-compose-to]'),
        cc: root.querySelector('[data-compose-cc]'),
        bcc: root.querySelector('[data-compose-bcc]'),
        ccRow: root.querySelector('[data-cc-row]'),
        bccRow: root.querySelector('[data-bcc-row]'),
        toggleCc: root.querySelector('[data-toggle-cc]'),
        toggleBcc: root.querySelector('[data-toggle-bcc]'),
        audienceSelect: root.querySelector('[data-compose-audience]'),
        audienceApply: root.querySelector('[data-compose-audience-apply]'),
        audienceStatus: root.querySelector('[data-group-status]'),
        templatePicker: root.querySelector('[data-compose-template]'),
        subject: root.querySelector('[data-compose-subject]'),
        body: root.querySelector('[data-compose-body]'),
        attachments: root.querySelector('[data-compose-attachments]'),
        attachmentList: root.querySelector('[data-attachment-list]'),
        sendButton: root.querySelector('[data-compose-send]'),
        scheduleToggle: root.querySelector('[data-compose-schedule-toggle]'),
        scheduleRow: root.querySelector('[data-compose-schedule-row]'),
        scheduleInput: root.querySelector('[data-compose-schedule-input]'),
        scheduleClear: root.querySelector('[data-compose-schedule-clear]'),
        draftButton: root.querySelector('[data-compose-draft]'),
        discardButton: root.querySelector('[data-compose-discard]'),
        status: root.querySelector('[data-compose-status]'),
        draftId: root.querySelector('[data-compose-draft-id]'),
        suggestions: root.querySelector('[data-contact-suggestions]'),
    };

    const state = {
        routes: config.routes || {},
        accountId: config.accountId || null,
        csrf: root.getAttribute('data-csrf') || '',
        busy: false,
        dirty: false,
        audiences: Array.isArray(config.audiences) ? config.audiences : [],
        scheduling: false,
    };

    const contactLookup = {
        endpoint: root.getAttribute('data-contacts-endpoint') || '',
        items: [],
        activeIndex: -1,
        controller: null,
    };
    const MIN_LOOKUP_CHARS = 2;

    const richTextState = {
        editorId: null,
        initializing: false,
        pendingHtml: '',
        silencing: false,
    };

    const ensureComposeBodyId = () => {
        if (!elements.body) {
            return '';
        }
        if (!elements.body.id) {
            elements.body.id = 'compose-body';
        }
        return elements.body.id;
    };

    const getComposeEditor = () => {
        if (!window.tinymce || !richTextState.editorId) {
            return null;
        }
        return window.tinymce.get(richTextState.editorId) || null;
    };

    const initializeRichTextEditor = () => {
        if (!elements.body || typeof window.tinymce === 'undefined' || richTextState.initializing) {
            return;
        }
        richTextState.initializing = true;
        const textareaId = ensureComposeBodyId();
        window.tinymce.init({
            selector: `#${textareaId}`,
            menubar: false,
            branding: false,
            height: 420,
            statusbar: false,
            plugins: 'lists link table code wordcount autolink',
            toolbar: 'undo redo | formatselect | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link table | removeformat code',
            content_style: 'body { font-family: "Space Grotesk", "Segoe UI", sans-serif; font-size: 16px; }',
            setup(editor) {
                editor.on('init', () => {
                    richTextState.editorId = editor.id;
                    if (richTextState.pendingHtml) {
                        editor.setContent(richTextState.pendingHtml);
                        richTextState.pendingHtml = '';
                    }
                });
                editor.on('change input undo redo keyup', () => {
                    if (richTextState.silencing) {
                        return;
                    }
                    state.dirty = true;
                });
            },
        })
            .then((editors) => {
                const [editor] = editors;
                if (editor) {
                    richTextState.editorId = editor.id;
                    if (richTextState.pendingHtml) {
                        editor.setContent(richTextState.pendingHtml);
                        richTextState.pendingHtml = '';
                    }
                }
            })
            .catch((error) => {
                console.error('Falha ao iniciar o editor de rich-text', error);
            })
            .finally(() => {
                richTextState.initializing = false;
            });
    };

    const setBodyContent = (html, plainTextOverride = null) => {
        const plainText = typeof plainTextOverride === 'string'
            ? plainTextOverride
            : htmlToPlainText(html || '');
        richTextState.silencing = true;
        const editor = getComposeEditor();
        if (editor) {
            editor.setContent(html || '');
            richTextState.pendingHtml = '';
        } else {
            richTextState.pendingHtml = html || '';
        }
        if (elements.body) {
            elements.body.value = plainText || '';
        }
        window.setTimeout(() => {
            richTextState.silencing = false;
        }, 0);
    };

    const getBodyContent = () => {
        const editor = getComposeEditor();
        if (editor) {
            const text = editor.getContent({ format: 'text' }).trim();
            const html = text ? editor.getContent({ format: 'html' }).trim() : '';
            return { text, html };
        }
        const fallback = elements.body ? (elements.body.value || '').trim() : '';
        return {
            text: fallback,
            html: fallback ? wrapPlainTextAsHtml(fallback) : '',
        };
    };

    const resetBodyContent = () => {
        setBodyContent('', '');
    };

    const applyTemplateById = (templateId) => {
        if (!templateId) {
            return;
        }
        const template = TEMPLATES.find((item) => item.id === templateId);
        if (!template) {
            return;
        }

        if (elements.subject) {
            elements.subject.value = template.subject;
        }

        const plain = htmlToPlainText(template.html);
        setBodyContent(template.html, plain);
        state.dirty = true;
        showStatus(`Modelo "${template.label}" aplicado.`, 'success');
    };

    const showStatus = (message, type = 'idle') => {
        if (!elements.status) {
            return;
        }
        elements.status.textContent = message;
        elements.status.dataset.state = type;
    };

    const setBusy = (busy) => {
        state.busy = busy;
        [elements.sendButton, elements.draftButton, elements.discardButton].forEach((button) => {
            if (button) {
                button.disabled = busy;
            }
        });
    };

    const buildContactLookupUrl = (term) => {
        const endpoint = contactLookup.endpoint;
        if (!endpoint) {
            return null;
        }
        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('q', term);
            url.searchParams.set('limit', '8');
            return url.toString();
        } catch (error) {
            const encoded = encodeURIComponent(term);
            const separator = endpoint.includes('?') ? '&' : '?';
            return `${endpoint}${separator}q=${encoded}&limit=8`;
        }
    };

    const hideContactSuggestions = () => {
        contactLookup.items = [];
        contactLookup.activeIndex = -1;
        if (elements.suggestions) {
            elements.suggestions.innerHTML = '';
            elements.suggestions.hidden = true;
        }
    };

    const setActiveSuggestion = (index) => {
        if (!elements.suggestions) {
            return;
        }
        const options = elements.suggestions.querySelectorAll('[data-contact-option]');
        options.forEach((option, optionIndex) => {
            option.classList.toggle('is-active', optionIndex === index);
        });
        contactLookup.activeIndex = index;
        if (index >= 0 && options[index]) {
            const option = options[index];
            const optionTop = option.offsetTop;
            const optionBottom = optionTop + option.offsetHeight;
            const viewTop = elements.suggestions.scrollTop;
            const viewBottom = viewTop + elements.suggestions.clientHeight;
            if (optionTop < viewTop) {
                elements.suggestions.scrollTop = optionTop;
            } else if (optionBottom > viewBottom) {
                elements.suggestions.scrollTop = optionBottom - elements.suggestions.clientHeight;
            }
        }
    };

    const renderContactSuggestions = (items) => {
        if (!elements.suggestions) {
            return;
        }
        contactLookup.items = items;
        contactLookup.activeIndex = -1;
        if (items.length === 0) {
            elements.suggestions.innerHTML = '';
            elements.suggestions.hidden = true;
            return;
        }

        const html = items.map((item, index) => {
            const label = item.name ? escapeHtml(item.name) : escapeHtml(item.email);
            const metaParts = [];
            if (item.name) {
                metaParts.push(escapeHtml(item.email));
            }
            if (item.document_formatted) {
                metaParts.push(escapeHtml(item.document_formatted));
            }
            const meta = metaParts.join(' · ');
            const metaHtml = meta !== '' ? `<span>${meta}</span>` : '';
            return `
                <button type="button" class="compose-suggestion-option" data-contact-option data-index="${index}">
                    <strong>${label}</strong>
                    ${metaHtml}
                </button>
            `;
        }).join('');

        elements.suggestions.innerHTML = html;
        elements.suggestions.hidden = false;
        elements.suggestions.scrollTop = 0;
    };

    const moveActiveSuggestion = (direction) => {
        if (!contactLookup.items.length) {
            return;
        }
        let next = contactLookup.activeIndex + direction;
        if (next < 0) {
            next = contactLookup.items.length - 1;
        } else if (next >= contactLookup.items.length) {
            next = 0;
        }
        setActiveSuggestion(next);
    };

    const applyContactSuggestion = (item) => {
        if (!elements.to || !item) {
            return;
        }
        const value = elements.to.value || '';
        const lastSeparatorIndex = Math.max(value.lastIndexOf(','), value.lastIndexOf(';'));
        const prefix = lastSeparatorIndex >= 0 ? value.slice(0, lastSeparatorIndex + 1) : '';
        const withSpace = prefix === '' ? '' : `${prefix} `;
        elements.to.value = `${withSpace}${item.email}, `;
        elements.to.focus();
        const caret = elements.to.value.length;
        if (typeof elements.to.setSelectionRange === 'function') {
            elements.to.setSelectionRange(caret, caret);
        }
        state.dirty = true;
        hideContactSuggestions();
    };

    const selectSuggestionByIndex = (index) => {
        if (index < 0 || index >= contactLookup.items.length) {
            return;
        }
        applyContactSuggestion(contactLookup.items[index]);
    };

    const currentRecipientToken = () => {
        if (!elements.to) {
            return '';
        }
        const value = elements.to.value || '';
        const lastSeparatorIndex = Math.max(value.lastIndexOf(','), value.lastIndexOf(';'));
        return value.slice(lastSeparatorIndex + 1).trim();
    };

    const fetchContactSuggestions = async (term) => {
        const url = buildContactLookupUrl(term);
        if (!url) {
            return;
        }
        if (contactLookup.controller) {
            contactLookup.controller.abort();
        }
        const controller = new AbortController();
        contactLookup.controller = controller;
        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload.error || 'Falha ao carregar contatos.');
            }
            const items = Array.isArray(payload.items) ? payload.items : [];
            renderContactSuggestions(items);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.warn('Falha ao buscar contatos', error);
                hideContactSuggestions();
            }
        } finally {
            if (contactLookup.controller === controller) {
                contactLookup.controller = null;
            }
        }
    };

    const scheduleContactLookup = debounce((term) => {
        fetchContactSuggestions(term);
    }, 220);

    const updateAttachmentList = () => {
        if (!elements.attachments || !elements.attachmentList) {
            return;
        }
        const files = Array.from(elements.attachments.files || []);
        elements.attachmentList.innerHTML = '';
        files.forEach((file) => {
            const item = document.createElement('li');
            item.innerHTML = `<span>${file.name}</span><small>${(file.size / 1024).toFixed(1)} KB</small>`;
            elements.attachmentList.appendChild(item);
        });
    };

    const gatherFormData = (bodyContentOverride = null) => {
        const formData = new FormData(elements.form);
        if (state.csrf) {
            formData.append('_token', state.csrf);
        }

        const bodyContent = bodyContentOverride || getBodyContent();
        const assignField = (field, value) => {
            if (typeof formData.set === 'function') {
                formData.set(field, value);
                return;
            }
            if (formData.has(field)) {
                formData.delete(field);
            }
            formData.append(field, value);
        };

        if (bodyContent.text || bodyContent.html) {
            assignField('body_text', bodyContent.text);
            assignField('body_html', bodyContent.html || wrapPlainTextAsHtml(bodyContent.text));
        } else {
            assignField('body_text', '');
            assignField('body_html', '');
        }

        return formData;
    };

    const setAudienceStatus = (message, tone = 'muted') => {
        if (!elements.audienceStatus) {
            return;
        }
        elements.audienceStatus.textContent = message || '';
        if (tone === 'error') {
            elements.audienceStatus.style.color = '#b42318';
        } else if (tone === 'success') {
            elements.audienceStatus.style.color = '#15803d';
        } else {
            elements.audienceStatus.style.color = 'var(--compose-muted)';
        }
    };

    const normalizeEmail = (value) => {
        const email = String(value || '').trim().toLowerCase();
        if (email === '') {
            return '';
        }
        const basicPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return basicPattern.test(email) ? email : '';
    };

    const appendBccRecipients = (emails) => {
        if (!elements.bcc) {
            return 0;
        }
        const existingTokens = (elements.bcc.value || '').split(/[,;]+/).map(normalizeEmail).filter(Boolean);
        const currentSet = new Set(existingTokens);
        let added = 0;
        emails.forEach((raw) => {
            const email = normalizeEmail(raw);
            if (!email || currentSet.has(email)) {
                return;
            }
            currentSet.add(email);
            added += 1;
        });
        elements.bcc.value = Array.from(currentSet).join(', ');
        if (elements.bccRow) {
            elements.bccRow.hidden = false;
        }
        if (elements.toggleBcc) {
            elements.toggleBcc.setAttribute('aria-pressed', 'true');
        }
        if (added > 0) {
            state.dirty = true;
        }
        return added;
    };

    const fetchAudienceRecipients = async (listId) => {
        if (!state.routes.composeAudienceRecipients) {
            throw new Error('Endpoint de grupos não configurado.');
        }
        const url = `${state.routes.composeAudienceRecipients}?list_id=${encodeURIComponent(listId)}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Falha ao carregar os contatos do grupo.');
        }
        const emails = Array.isArray(payload.emails) ? payload.emails : [];
        return emails;
    };

    const applyAudienceSelection = async () => {
        if (!elements.audienceSelect) {
            return;
        }
        const selectedId = Number(elements.audienceSelect.value);
        if (!selectedId) {
            setAudienceStatus('Selecione um grupo para adicionar no Bcc.', 'error');
            return;
        }
        setAudienceStatus('Carregando contatos do grupo...', 'muted');
        try {
            const emails = await fetchAudienceRecipients(selectedId);
            if (!emails.length) {
                setAudienceStatus('O grupo não possui contatos com e-mail.', 'error');
                return;
            }
            const added = appendBccRecipients(emails);
            if (added === 0) {
                setAudienceStatus('Nenhum e-mail novo adicionado (todos já estavam no Bcc).', 'muted');
                return;
            }
            setAudienceStatus(`Adicionamos ${added} destinatário(s) em Bcc.`, 'success');
        } catch (error) {
            setAudienceStatus(error.message || 'Falha ao adicionar grupo.', 'error');
        }
    };

    const requestEndpoint = (mode) => {
        if (mode === 'draft') {
            return state.routes.composeDraft || '';
        }
        return state.routes.composeSend || '';
    };

    const handleSubmit = async (mode) => {
        const endpoint = requestEndpoint(mode);
        if (!endpoint) {
            showStatus('Endpoint de composição não configurado.', 'error');
            return;
        }
        const bodyContent = getBodyContent();
        if (mode === 'send' && !bodyContent.text && !bodyContent.html) {
            showStatus('Inclua o corpo da mensagem.', 'error');
            return;
        }
        setBusy(true);
        showStatus(mode === 'draft' ? 'Salvando rascunho...' : 'Enviando mensagem...', 'loading');
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: gatherFormData(bodyContent),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Falha ao processar a solicitação.');
            }
            if (mode === 'draft' && payload.draft && payload.draft.id && elements.draftId) {
                elements.draftId.value = String(payload.draft.id);
            }
            if (mode === 'send' && elements.scheduleInput && elements.scheduleInput.value) {
                showStatus(`Envio agendado para ${elements.scheduleInput.value.replace('T', ' ')}.`, 'success');
            } else {
                showStatus(mode === 'draft' ? 'Rascunho salvo com sucesso.' : 'Mensagem enviada.', 'success');
            }
            state.dirty = false;
            if (mode === 'send') {
                elements.form.reset();
                resetBodyContent();
                updateAttachmentList();
                if (elements.ccRow) {
                    elements.ccRow.hidden = true;
                }
                if (elements.bccRow) {
                    elements.bccRow.hidden = true;
                }
                if (elements.scheduleRow) {
                    elements.scheduleRow.hidden = true;
                }
                if (elements.scheduleToggle) {
                    elements.scheduleToggle.setAttribute('aria-pressed', 'false');
                }
                if (elements.scheduleInput) {
                    elements.scheduleInput.value = '';
                }
            }
        } catch (error) {
            showStatus(error.message || 'Erro inesperado.', 'error');
        } finally {
            setBusy(false);
        }
    };

    const toggleRow = (type) => {
        const row = type === 'cc' ? elements.ccRow : elements.bccRow;
        const toggle = type === 'cc' ? elements.toggleCc : elements.toggleBcc;
        if (!row || !toggle) {
            return;
        }
        const nextState = row.hidden;
        row.hidden = !nextState;
        toggle.setAttribute('aria-pressed', nextState ? 'true' : 'false');
        if (nextState) {
            (type === 'cc' ? elements.cc : elements.bcc)?.focus();
        }
    };

    const bindEvents = () => {
        if (elements.attachments) {
            elements.attachments.addEventListener('change', updateAttachmentList);
        }
        if (elements.templatePicker) {
            elements.templatePicker.addEventListener('change', () => {
                applyTemplateById(elements.templatePicker.value);
            });
        }
        if (elements.body) {
            elements.body.addEventListener('input', () => {
                if (richTextState.silencing) {
                    return;
                }
                state.dirty = true;
            });
        }
        if (elements.toggleCc) {
            elements.toggleCc.addEventListener('click', () => toggleRow('cc'));
        }
        if (elements.toggleBcc) {
            elements.toggleBcc.addEventListener('click', () => toggleRow('bcc'));
        }
        if (elements.scheduleToggle) {
            elements.scheduleToggle.addEventListener('click', () => {
                state.scheduling = !state.scheduling;
                if (elements.scheduleRow) {
                    elements.scheduleRow.hidden = !state.scheduling;
                }
                elements.scheduleToggle.setAttribute('aria-pressed', state.scheduling ? 'true' : 'false');
                if (state.scheduling && elements.scheduleInput) {
                    elements.scheduleInput.focus();
                }
            });
        }
        if (elements.scheduleClear && elements.scheduleInput) {
            elements.scheduleClear.addEventListener('click', () => {
                elements.scheduleInput.value = '';
            });
        }
        if (elements.sendButton) {
            elements.sendButton.addEventListener('click', () => handleSubmit('send'));
        }
        if (elements.draftButton) {
            elements.draftButton.addEventListener('click', () => handleSubmit('draft'));
        }
        if (elements.audienceApply) {
            elements.audienceApply.addEventListener('click', applyAudienceSelection);
        }
        if (elements.discardButton) {
            elements.discardButton.addEventListener('click', () => {
                if (elements.form) {
                    elements.form.reset();
                    resetBodyContent();
                    updateAttachmentList();
                    if (elements.ccRow) {
                        elements.ccRow.hidden = true;
                        if (elements.toggleCc) {
                            elements.toggleCc.setAttribute('aria-pressed', 'false');
                        }
                    }
                    if (elements.bccRow) {
                        elements.bccRow.hidden = true;
                        if (elements.toggleBcc) {
                            elements.toggleBcc.setAttribute('aria-pressed', 'false');
                        }
                    }
                    showStatus('Rascunho descartado.');
                }
            });
        }

        if (elements.to) {
            elements.to.addEventListener('input', () => {
                state.dirty = true;
                const token = currentRecipientToken();
                if (token.length >= MIN_LOOKUP_CHARS && contactLookup.endpoint) {
                    scheduleContactLookup(token);
                } else {
                    hideContactSuggestions();
                }
            });

            elements.to.addEventListener('keydown', (event) => {
                if (!contactLookup.items.length || !elements.suggestions || elements.suggestions.hidden) {
                    if (event.key === 'Escape') {
                        hideContactSuggestions();
                    }
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    moveActiveSuggestion(1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    moveActiveSuggestion(-1);
                } else if (event.key === 'Enter' || event.key === 'Tab') {
                    if (contactLookup.activeIndex >= 0) {
                        event.preventDefault();
                        selectSuggestionByIndex(contactLookup.activeIndex);
                    }
                } else if (event.key === 'Escape') {
                    hideContactSuggestions();
                }
            });

            elements.to.addEventListener('blur', () => {
                window.setTimeout(() => {
                    hideContactSuggestions();
                }, 120);
            });
        }

        if (elements.suggestions) {
            elements.suggestions.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });
            elements.suggestions.addEventListener('click', (event) => {
                const option = event.target.closest('[data-contact-option]');
                if (!option) {
                    return;
                }
                const index = Number(option.getAttribute('data-index'));
                if (!Number.isNaN(index)) {
                    selectSuggestionByIndex(index);
                }
            });
        }
    };

    initializeRichTextEditor();
    bindEvents();
    updateAttachmentList();
    if (elements.to) {
        elements.to.focus();
    }
})();
</script>
<?php endif; ?>
