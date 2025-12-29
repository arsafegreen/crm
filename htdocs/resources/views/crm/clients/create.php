<?php
/** @var array $formData */
/** @var array $errors */
/** @var array $statusOptions */
/** @var array $pipelineStages */
/** @var array|null $feedback */

$formatDocument = format_document($formData['document'] ?? '');
$phoneDigits = digits_only($formData['phone'] ?? '') ?: '';
$extraPhonesValue = [];
$rawExtraPhones = $formData['extra_phones'] ?? [];
if (is_string($rawExtraPhones)) {
    $decoded = json_decode($rawExtraPhones, true);
    if (is_array($decoded)) {
        $rawExtraPhones = $decoded;
    } else {
        $rawExtraPhones = [$rawExtraPhones];
    }
}
if (!is_array($rawExtraPhones)) {
    $rawExtraPhones = [];
}
foreach ($rawExtraPhones as $entry) {
    $digits = digits_only((string)$entry);
    if ($digits !== '') {
        $extraPhonesValue[] = $digits;
    }
}
$extraPhonesValue = array_values(array_unique($extraPhonesValue));
$nextFollowUpValue = $formData['next_follow_up_at'] ?? '';
$birthdateValue = $formData['titular_birthdate'] ?? '';
$statusValue = $formData['status'] ?? 'prospect';
$stageValue = $formData['pipeline_stage_id'] ?? '';
$titularDocumentFormatted = format_document($formData['titular_document'] ?? '');
$hasTitularData = trim((string)($formData['titular_name'] ?? '')) !== ''
    || trim((string)($formData['titular_document'] ?? '')) !== ''
    || trim((string)$birthdateValue) !== '';

$feedbackMeta = $feedback['meta'] ?? [];
$existingClientId = isset($feedbackMeta['client_id']) ? (int)$feedbackMeta['client_id'] : null;
?>

<header>
    <div>
        <h1>Novo cliente</h1>
        <p>Cadastre clientes manualmente para iniciar o acompanhamento antes do próximo upload.</p>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
        <a href="<?= url('crm/clients'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar para carteira</a>
    </div>
</header>

<?php if (!empty($feedback)): ?>
    <?php
        $feedbackType = $feedback['type'] ?? 'info';
        $feedbackColor = '#38bdf8';
        if ($feedbackType === 'success') {
            $feedbackColor = '#22c55e';
        } elseif ($feedbackType === 'error') {
            $feedbackColor = '#f87171';
        }
    ?>
    <div class="panel" style="border-left:4px solid <?= $feedbackColor; ?>;margin-bottom:24px;">
        <strong style="display:block;margin-bottom:6px;">Aviso</strong>
        <p style="margin:0;color:var(--muted);">
            <?= htmlspecialchars((string)($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($existingClientId !== null): ?>
                <br><a href="<?= url('crm/clients/' . $existingClientId); ?>" style="color:var(--accent);font-weight:600;">Abrir cadastro existente</a>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="panel">
    <h2 style="margin-top:0;">Dados principais</h2>
    <p style="margin:0 0 18px;color:var(--muted);">Preencha o mínimo necessário e, quando o relatório mensal for importado, o sistema complementa com histórico de certificados.</p>

    <form method="post" action="<?= url('crm/clients'); ?>" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                CPF / CNPJ
                <div style="display:flex;align-items:center;gap:10px;">
                    <input data-crm-document-input type="text" name="document" value="<?= htmlspecialchars($formatDocument, ENT_QUOTES, 'UTF-8'); ?>" placeholder="000.000.000-00" style="flex:1;padding:12px;border-radius:12px;border:1px solid <?= isset($errors['document']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                    <button type="button" data-action="crm-search-document" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;border:1px solid rgba(56,189,248,0.32);background:rgba(56,189,248,0.18);color:var(--accent);font-size:1rem;">
                        <span aria-hidden="true" style="line-height:1;">🔍</span>
                        <span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Verificar cadastro</span>
                    </button>
                </div>
                <small data-crm-document-feedback style="display:none;color:var(--muted);"></small>
                <?php if (isset($errors['document'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['document'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Nome do cliente
                <input type="text" name="name" value="<?= htmlspecialchars($formData['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Razão social / Nome completo" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['name']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);text-transform:uppercase;">
                <?php if (isset($errors['name'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                E-mail
                <input type="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="contato@cliente.com" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['email']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                <?php if (isset($errors['email'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Telefone / WhatsApp
                <input type="text" name="phone" value="<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8'); ?>" placeholder="82999998888" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Telefones adicionais
                <div data-extra-phones-root style="display:grid;gap:10px;">
                    <div data-extra-phones-list style="display:grid;gap:10px;">
                        <?php foreach ($extraPhonesValue as $extraPhone): ?>
                            <div data-extra-phone-row style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <input type="text" name="extra_phones[]" value="<?= htmlspecialchars($extraPhone, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DDD + número" style="flex:1;min-width:180px;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                                <button type="button" data-remove-extra-phone style="padding:10px 12px;border-radius:10px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fca5a5;font-weight:600;cursor:pointer;">Remover</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="button" data-add-extra-phone style="padding:10px 14px;border-radius:10px;border:1px solid rgba(56,189,248,0.45);background:rgba(56,189,248,0.18);color:var(--accent);font-weight:600;cursor:pointer;">Adicionar telefone</button>
                        <span data-extra-phone-empty style="<?= $extraPhonesValue === [] ? '' : 'display:none;'; ?>color:var(--muted);font-size:0.85rem;">Nenhum telefone adicional.</span>
                    </div>
                    <template data-extra-phone-template>
                        <div data-extra-phone-row style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="text" name="extra_phones[]" placeholder="DDD + número" style="flex:1;min-width:180px;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                            <button type="button" data-remove-extra-phone style="padding:10px 12px;border-radius:10px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fca5a5;font-weight:600;cursor:pointer;">Remover</button>
                        </div>
                    </template>
                    <?php if (isset($errors['extra_phones'])): ?>
                        <small style="color:#f87171;"><?= htmlspecialchars($errors['extra_phones'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php else: ?>
                        <small style="color:var(--muted);">Apenas números, mínimo 10 dígitos.</small>
                    <?php endif; ?>
                </div>
            </label>
        </div>

        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Status
                <select name="status" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?>" <?= ($statusValue === (string)$value) ? 'selected' : ''; ?>><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Etapa no pipeline
                <select name="pipeline_stage_id" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="">Sem etapa definida</option>
                    <?php foreach ($pipelineStages as $stage):
                        $id = (int)($stage['id'] ?? 0);
                        $label = $stage['name'] ?? ('Etapa #' . $id);
                    ?>
                        <option value="<?= $id; ?>" <?= ((string)$stageValue === (string)$id) ? 'selected' : ''; ?>><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Próximo follow-up
                <input type="datetime-local" name="next_follow_up_at" value="<?= htmlspecialchars($nextFollowUpValue, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['next_follow_up_at']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                <?php if (isset($errors['next_follow_up_at'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['next_follow_up_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
        </div>

    <div data-crm-titular-section data-visible="<?= $hasTitularData ? '1' : '0'; ?>" data-initial="<?= $hasTitularData ? '1' : '0'; ?>" style="<?= $hasTitularData ? '' : 'display:none;'; ?>">
            <h3 style="margin:12px 0 0;font-size:1.1rem;">Dados do titular (opcional)</h3>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Nome do titular
                <input type="text" name="titular_name" value="<?= htmlspecialchars($formData['titular_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome completo" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                CPF do titular
                <input data-crm-titular-input type="text" name="titular_document" value="<?= htmlspecialchars($titularDocumentFormatted, ENT_QUOTES, 'UTF-8'); ?>" placeholder="000.000.000-00" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['titular_document']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                <small data-crm-titular-feedback style="display:none;color:var(--muted);"></small>
                <div data-crm-titular-results style="display:none;border:1px solid rgba(129,140,248,0.35);border-radius:14px;padding:12px;margin-top:8px;background:rgba(15,23,42,0.58);">
                    <strong data-crm-titular-summary style="display:block;margin-bottom:8px;color:var(--text);"></strong>
                    <ul data-crm-titular-list style="list-style:none;padding:0;margin:0;display:grid;gap:8px;"></ul>
                </div>
                <?php if (isset($errors['titular_document'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['titular_document'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Data de nascimento
                <input type="date" name="titular_birthdate" value="<?= htmlspecialchars($birthdateValue, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['titular_birthdate']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                <?php if (isset($errors['titular_birthdate'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['titular_birthdate'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
        </div>
            </div>

        <h3 style="margin:12px 0 0;font-size:1.1rem;">Relacionamento</h3>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Parceiro / contador
                <input type="text" name="partner_accountant" value="<?= htmlspecialchars($formData['partner_accountant'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do parceiro" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Parceiro adicional
                <input type="text" name="partner_accountant_plus" value="<?= htmlspecialchars($formData['partner_accountant_plus'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Parceiro complementar" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
        </div>

        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
            Observações
            <textarea name="notes" rows="4" placeholder="Contexto da negociação, histórico ou próximos passos" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);resize:vertical;"><?= htmlspecialchars($formData['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <a href="<?= url('crm/clients'); ?>" style="display:inline-flex;align-items:center;padding:12px 22px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.45);color:var(--muted);text-decoration:none;">Cancelar</a>
            <button class="primary" type="submit" style="padding:14px 32px;">Salvar cliente</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const setupExtraPhones = function (root) {
        if (!root) {
            return;
        }

        const list = root.querySelector('[data-extra-phones-list]');
        const template = root.querySelector('[data-extra-phone-template]');
        const emptyState = root.querySelector('[data-extra-phone-empty]');
        const addButton = root.querySelector('[data-add-extra-phone]');

        const updateEmpty = function () {
            if (!emptyState) {
                return;
            }
            const hasRows = list && list.querySelector('[data-extra-phone-row]');
            emptyState.style.display = hasRows ? 'none' : '';
        };

        const addRow = function (value) {
            if (!list || !template) {
                return;
            }
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-extra-phone-row]');
            const input = fragment.querySelector('input[name="extra_phones[]"]');
            if (input && typeof value === 'string' && value !== '') {
                input.value = value;
            }
            list.appendChild(fragment);
            updateEmpty();
            if (row) {
                const focusInput = row.querySelector('input[name="extra_phones[]"]');
                if (focusInput) {
                    focusInput.focus();
                }
            }
        };

        if (addButton) {
            addButton.addEventListener('click', function () {
                addRow('');
            });
        }

        root.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target.closest('[data-remove-extra-phone]') : null;
            if (!target) {
                return;
            }
            event.preventDefault();
            const row = target.closest('[data-extra-phone-row]');
            if (row && list) {
                row.remove();
                updateEmpty();
            }
        });

        updateEmpty();
    };

    document.querySelectorAll('[data-extra-phones-root]').forEach(function (root) {
        setupExtraPhones(root);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const documentInput = document.querySelector('[data-crm-document-input]');
    const searchButton = document.querySelector('[data-action="crm-search-document"]');
    const feedbackNode = document.querySelector('[data-crm-document-feedback]');
    const titularSection = document.querySelector('[data-crm-titular-section]');
    const titularInput = document.querySelector('[data-crm-titular-input]');
    let requestTitularLookup = null;

    const showTitularSection = function () {
        if (!titularSection) {
            return;
        }

        titularSection.style.display = 'block';
        titularSection.setAttribute('data-visible', '1');
        titularSection.dataset.unlocked = '1';
        if (typeof requestTitularLookup === 'function') {
            requestTitularLookup();
        }
    };

    const hideTitularSection = function () {
        if (!titularSection) {
            return;
        }

        if (titularSection.dataset.initial === '1' || titularSection.dataset.unlocked === '1') {
            return;
        }

        titularSection.style.display = 'none';
        titularSection.setAttribute('data-visible', '0');
    };

    if (documentInput && searchButton && feedbackNode) {
        const originalMarkup = searchButton.innerHTML;

        const setMessage = function (message, tone) {
            feedbackNode.textContent = message;

            if (message === '') {
                feedbackNode.style.display = 'none';
                return;
            }

            feedbackNode.style.display = 'block';

            let color = 'var(--muted)';
            if (tone === 'error') {
                color = '#f87171';
            } else if (tone === 'success') {
                color = '#4ade80';
            }

            feedbackNode.style.color = color;
        };

        const performSearch = async function () {
            const digits = (documentInput.value || '').replace(/\D+/g, '');

            if (![11, 14].includes(digits.length)) {
                setMessage('Informe um CPF ou CNPJ válido com 11 ou 14 dígitos.', 'error');
                documentInput.focus();
                return;
            }

            setMessage('Pesquisando cadastro...', 'info');
            searchButton.disabled = true;
            searchButton.innerHTML = '<span aria-hidden="true" style="line-height:1;">⏳</span><span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Verificando cadastro</span>';

            try {
                const response = await fetch('<?= url('crm/clients/check'); ?>', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': window.CSRF_TOKEN
                    },
                    body: new URLSearchParams({ document: digits }).toString()
                });

                const data = await response.json();

                if (response.status >= 400 || data.error) {
                    setMessage(data.error ?? 'Não foi possível verificar o documento agora.', 'error');
                    return;
                }

                if (data.found && data.redirect) {
                    setMessage('Cliente já cadastrado. Abrindo ficha existente...', 'success');
                    window.location.href = data.redirect;
                    return;
                }

                setMessage('Nenhum cliente encontrado. Você pode continuar o cadastro.', 'info');
                showTitularSection();
                if (titularInput) {
                    titularInput.focus();
                }
            } catch (error) {
                setMessage('Não foi possível verificar o documento agora. Tente novamente.', 'error');
            } finally {
                searchButton.disabled = false;
                searchButton.innerHTML = originalMarkup;
            }
        };

        searchButton.addEventListener('click', function () {
            performSearch();
        });

        documentInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performSearch();
            }
        });

        documentInput.addEventListener('input', function () {
            setMessage('', 'info');
            if (titularSection && titularSection.dataset.initial !== '1') {
                titularSection.dataset.unlocked = '0';
                hideTitularSection();
            }
        });
    }

    if (titularSection && titularSection.dataset.initial === '1') {
        titularSection.dataset.unlocked = '1';
    }

    const titularFeedback = document.querySelector('[data-crm-titular-feedback]');
    const titularResults = document.querySelector('[data-crm-titular-results]');
    const titularSummary = titularResults ? titularResults.querySelector('[data-crm-titular-summary]') : null;
    const titularList = titularResults ? titularResults.querySelector('[data-crm-titular-list]') : null;

    if (titularInput && titularFeedback && titularResults && titularSummary && titularList) {
        const setTitularMessage = function (message, tone) {
            titularFeedback.textContent = message;

            if (message === '') {
                titularFeedback.style.display = 'none';
                return;
            }

            titularFeedback.style.display = 'block';

            let color = 'var(--muted)';
            if (tone === 'error') {
                color = '#f87171';
            } else if (tone === 'success') {
                color = '#4ade80';
            }

            titularFeedback.style.color = color;
        };

        const renderTitularResults = function (items, formattedDocument) {
            if (!Array.isArray(items) || items.length === 0) {
                titularResults.style.display = 'none';
                titularList.innerHTML = '';
                return;
            }

            titularResults.style.display = 'block';
            titularSummary.textContent = `${items.length} CNPJ(s) vinculados ao CPF ${formattedDocument}`;
            titularList.innerHTML = '';

            items.forEach(function (item) {
                const li = document.createElement('li');
                li.style.padding = '10px';
                li.style.borderRadius = '10px';
                li.style.background = 'rgba(15,23,42,0.45)';
                li.style.border = '1px solid rgba(129,140,248,0.25)';

                const link = document.createElement('a');
                link.href = item.url;
                link.textContent = `${item.document_formatted || item.document} • ${item.name}`;
                link.style.color = 'var(--accent)';
                link.style.fontWeight = '600';
                link.style.textDecoration = 'none';
                li.appendChild(link);

                const status = document.createElement('span');
                status.textContent = `Status: ${item.status_label || item.status || '-'}`;
                status.style.display = 'block';
                status.style.marginTop = '4px';
                status.style.fontSize = '0.78rem';
                status.style.color = 'var(--muted)';
                li.appendChild(status);

                titularList.appendChild(li);
            });
        };

        const clearTitularResults = function () {
            titularResults.style.display = 'none';
            titularList.innerHTML = '';
        };

        const performTitularSearch = async function () {
            if (!titularSection || titularSection.getAttribute('data-visible') !== '1') {
                return;
            }

            const digits = (titularInput.value || '').replace(/\D+/g, '');

            if (digits === '') {
                setTitularMessage('', 'info');
                clearTitularResults();
                return;
            }

            if (digits.length !== 11) {
                setTitularMessage('Informe um CPF com 11 dígitos.', 'error');
                clearTitularResults();
                return;
            }

            setTitularMessage('Buscando CNPJ vinculados...', 'info');
            clearTitularResults();

            try {
                const response = await fetch('<?= url('crm/clients/lookup-titular'); ?>', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': window.CSRF_TOKEN
                    },
                    body: new URLSearchParams({ titular_document: digits }).toString()
                });

                const data = await response.json();

                if (response.status >= 400 || data.error) {
                    setTitularMessage(data.error ?? 'Não foi possível consultar o CPF agora.', 'error');
                    return;
                }

                if (!data.found || !Array.isArray(data.clients) || data.clients.length === 0) {
                    setTitularMessage(data.message ?? 'Nenhum CNPJ vinculado encontrado. Você pode continuar o cadastro.', 'info');
                    return;
                }

                setTitularMessage(`${data.count} CNPJ(s) encontrados para este CPF.`, 'success');
                renderTitularResults(data.clients, data.titular_document_formatted ?? titularInput.value);
            } catch (error) {
                setTitularMessage('Não foi possível consultar o CPF agora. Tente novamente.', 'error');
            }
        };

        const scheduleTitularSearch = function () {
            if (!titularSection || titularSection.getAttribute('data-visible') !== '1') {
                return;
            }
            performTitularSearch();
        };

        requestTitularLookup = scheduleTitularSearch;

        titularInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                scheduleTitularSearch();
            }
        });

        titularInput.addEventListener('blur', function () {
            scheduleTitularSearch();
        });

        titularInput.addEventListener('input', function () {
            setTitularMessage('', 'info');
            clearTitularResults();
        });

        if (titularSection && titularSection.getAttribute('data-visible') === '1') {
            scheduleTitularSearch();
        }
    }
});
</script>
