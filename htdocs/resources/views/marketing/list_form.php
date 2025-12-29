<?php
$list = $list ?? [];
$errors = $errors ?? [];
$mode = $mode ?? 'create';
$action = $action ?? '';
$title = $title ?? 'Lista';
$contacts = $contacts ?? [];
$consentOptions = [
    'single_opt_in' => 'Single opt-in',
    'double_opt_in' => 'Double opt-in',
    'custom_policy' => 'Política customizada',
];
$statusOptions = [
    'active' => 'Ativa',
    'paused' => 'Pausada',
    'archived' => 'Arquivada',
];
?>

<style>
    .form-field { display:flex; flex-direction:column; gap:6px; }
    .form-field span { font-size:0.85rem; color:var(--muted); }
    .form-field input,
    .form-field select,
    .form-field textarea { padding:12px; border-radius:12px; border:1px solid var(--border); background:rgba(15,23,42,0.6); color:var(--text); }
    .form-field textarea { min-height:120px; }
    .form-field .error { color:#f87171; }
</style>

<header>
    <div>
        <h1 style="margin-bottom:8px;">
            <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <p style="color:var(--muted);max-width:760px;">Centralize o contexto LGPD de cada ponto de coleta: origem, política de consentimento e retenção. Estes dados alimentam os relatórios de auditoria.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('marketing/lists'); ?>">&larr; Voltar</a>
    </div>
</header>

<div class="panel" style="margin-top:18px;">
    <form action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
            <label class="form-field">
                <span>Nome *</span>
                <input type="text" name="name" value="<?= htmlspecialchars($list['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                <?php if (isset($errors['name'])): ?><small class="error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
            <label class="form-field">
                <span>Slug *</span>
                <input type="text" name="slug" value="<?= htmlspecialchars($list['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="ex: base-clientes" required>
                <?php if (isset($errors['slug'])): ?><small class="error"><?= htmlspecialchars($errors['slug'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
            <label class="form-field">
                <span>Status</span>
                <select name="status">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= (($list['status'] ?? 'active') === $value) ? 'selected' : ''; ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-field" style="align-self:flex-end;">
                <span>Modo de envio</span>
                <div style="display:flex;align-items:center;gap:8px;color:var(--muted);">
                    <input type="hidden" name="per_document" value="0">
                    <input type="checkbox" name="per_document" value="1" <?= (($list['per_document'] ?? '0') === '1') ? 'checked' : ''; ?>>
                    Duplicar envios por documento (CPF/CNPJ)
                </div>
                <small style="color:var(--muted);">Ative para campanhas que precisam de um envio por documento (ex.: renovações).</small>
            </label>
        </div>

        <label class="form-field">
            <span>Descrição</span>
            <textarea name="description" rows="3" placeholder="Como esta lista será usada?"><?= htmlspecialchars($list['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label class="form-field">
                <span>Origem dos contatos</span>
                <input type="text" name="origin" value="<?= htmlspecialchars($list['origin'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Evento, website, indicação...">
            </label>
            <label class="form-field">
                <span>Objetivo</span>
                <input type="text" name="purpose" value="<?= htmlspecialchars($list['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Campanhas, suporte, onboarding...">
            </label>
        </div>

        <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
            <label class="form-field">
                <span>Consentimento *</span>
                <select name="consent_mode">
                    <?php foreach ($consentOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= (($list['consent_mode'] ?? 'single_opt_in') === $value) ? 'selected' : ''; ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-field" style="align-self:flex-end;">
                <span>Double opt-in</span>
                <div style="display:flex;align-items:center;gap:8px;color:var(--muted);">
                    <input type="hidden" name="double_opt_in" value="0">
                    <input type="checkbox" name="double_opt_in" value="1" <?= (($list['double_opt_in'] ?? '0') === '1') ? 'checked' : ''; ?>>
                    Requer confirmação por e-mail
                </div>
            </label>
        </div>

        <label class="form-field">
            <span>Declaração exibida no opt-in</span>
            <textarea name="opt_in_statement" rows="3" placeholder="Texto apresentado no formulário"><?= htmlspecialchars($list['opt_in_statement'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label class="form-field">
            <span>Política de retenção</span>
            <textarea name="retention_policy" rows="3" placeholder="Ex.: remover contatos inativos após 18 meses"><?= htmlspecialchars($list['retention_policy'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label class="form-field">
            <span>Metadados (JSON)</span>
            <textarea name="metadata" rows="3" placeholder='{"source":"landing-page"}'><?= htmlspecialchars($list['metadata'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <footer style="display:flex;justify-content:flex-end;gap:12px;">
            <a class="ghost" href="<?= url('marketing/lists'); ?>">Cancelar</a>
            <button class="primary" type="submit">Salvar</button>
        </footer>
    </form>
</div>

<?php if ($mode === 'edit'): ?>
    <section class="panel" style="margin-top:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 style="margin:0;">Adicionar contato (CPF + Nome + E-mail)</h2>
            <small style="color:var(--muted);">Formato obrigatório para novos grupos e listas.</small>
        </div>
        <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/contacts/add'); ?>" style="margin-top:12px;display:grid;gap:12px;">
            <?= csrf_field(); ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <div style="position:relative;flex:1;min-width:260px;display:flex;align-items:center;gap:6px;">
                    <button type="button" id="contact-search-btn" title="Buscar" class="ghost" style="padding:8px 10px;display:inline-flex;align-items:center;justify-content:center;">🔍</button>
                    <input list="contact-suggestions" id="contact-search" type="text" placeholder="Digite parte do nome, razão social ou e-mail" style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                </div>
                <datalist id="contact-suggestions"></datalist>
            </div>
            <div id="contact-suggestions-list" style="display:flex;flex-direction:column;gap:6px;"></div>
            <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                <label class="form-field">
                    <span>CPF *</span>
                    <input type="text" name="cpf" maxlength="14" placeholder="000.000.000-00">
                </label>
                <label class="form-field">
                    <span>Nome *</span>
                    <input type="text" name="titular_nome" placeholder="Nome completo">
                </label>
                <label class="form-field">
                    <span>E-mail *</span>
                    <input type="email" name="email" placeholder="contato@exemplo.com">
                </label>
                <label class="form-field">
                    <span>Telefone</span>
                    <input type="text" name="telefone" placeholder="(11) 99999-0000">
                </label>
            </div>
            <div id="pending-contacts" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
            <input type="hidden" name="contacts_json" id="contacts-json" value="">
            <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                <button type="button" class="ghost" id="queue-contact-btn">Adicionar à fila</button>
                <button type="submit" class="primary" id="save-queue-btn">Salvar fila</button>
            </div>
        </form>
        <script>
            (function(){
                const input = document.getElementById('contact-search');
                const datalist = document.getElementById('contact-suggestions');
                const btn = document.getElementById('contact-search-btn');
                const list = document.getElementById('contact-suggestions-list');
                const queueBtn = document.getElementById('queue-contact-btn');
                const saveBtn = document.getElementById('save-queue-btn');
                const chips = document.getElementById('pending-contacts');
                const hidden = document.getElementById('contacts-json');
                const cpfField = document.querySelector('input[name="cpf"]');
                const nomeField = document.querySelector('input[name="titular_nome"]');
                const emailField = document.querySelector('input[name="email"]');
                const phoneField = document.querySelector('input[name="telefone"]');
                const pending = [];

                function normalizeCpf(raw){
                    const digits = (raw||'').replace(/\D+/g, '');
                    return digits.length === 11 ? digits : '';
                }

                function renderQueue(){
                    chips.innerHTML = '';
                    hidden.value = JSON.stringify(pending);
                    pending.forEach((item, idx) => {
                        const span = document.createElement('span');
                        span.style.cssText='padding:6px 10px;border-radius:999px;background:rgba(34,197,94,0.12);color:#a7f3d0;border:1px solid rgba(34,197,94,0.3);display:inline-flex;align-items:center;gap:6px;';
                        span.textContent = `${item.nome} • ${item.cpf} • ${item.email}`;
                        const rm = document.createElement('button');
                        rm.type='button';
                        rm.textContent='×';
                        rm.style.cssText='border:none;background:transparent;color:#94a3b8;cursor:pointer;font-size:14px;';
                        rm.onclick=()=>{ pending.splice(idx,1); renderQueue(); };
                        span.appendChild(rm);
                        chips.appendChild(span);
                    });
                }

                function queueCurrent(){
                    const cpf = normalizeCpf(cpfField.value);
                    const nome = (nomeField.value||'').trim();
                    const email = (emailField.value||'').trim().toLowerCase();
                    const telefone = (phoneField.value||'').trim();
                    const isEmailValid = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email);
                    if (!cpf || !nome || !isEmailValid) {
                        alert('Preencha CPF válido (11 dígitos), nome e e-mail válido para adicionar à fila.');
                        return;
                    }
                    pending.push({ cpf, nome, email, telefone });
                    renderQueue();
                    cpfField.value=''; nomeField.value=''; emailField.value=''; phoneField.value='';
                }

                const basePrefix = window.location.pathname.includes('/marketing/')
                    ? window.location.pathname.split('/marketing/')[0]
                    : '';
                const routeUrl = window.location.origin + basePrefix + '/crm/clients/contact-search';

                function fillFields(item){
                    if (item.document) { cpfField.value = item.document; }
                    if (item.cpf) { cpfField.value = item.cpf; }
                    if (item.name) { nomeField.value = item.name; }
                    if (item.email) { emailField.value = item.email; }
                    if (item.phone_formatted) { phoneField.value = item.phone_formatted; }
                }

                function renderList(items, query){
                    list.innerHTML = '';
                    datalist.innerHTML = '';
                    if (!items || items.length === 0) {
                        const empty = document.createElement('div');
                        empty.textContent = 'Nenhum resultado para "' + query + '"';
                        empty.style.cssText = 'padding:8px 10px;color:#94a3b8;';
                        list.appendChild(empty);
                        return;
                    }

                    items.forEach(item => {
                        const displayLabel = item.label || item.name || item.email;
                        const opt = document.createElement('option');
                        opt.value = item.email || '';
                        opt.label = displayLabel;
                        datalist.appendChild(opt);

                        const row = document.createElement('div');
                        row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;align-items:center;padding:8px 10px;border:1px solid rgba(148,163,184,0.25);border-radius:10px;';
                        const span = document.createElement('span');
                        span.textContent = displayLabel;
                        span.style.color = '#e2e8f0';
                        const action = document.createElement('button');
                        action.type = 'button';
                        action.textContent = 'Usar';
                        action.className = 'ghost';
                        action.onclick = () => { fillFields(item); };
                        row.appendChild(span);
                        row.appendChild(action);
                        list.appendChild(row);
                    });
                }

                function fetchSuggestions(force){
                    const q = (input.value || '').trim();
                    if (!force && q.length < 2) {
                        datalist.innerHTML = '';
                        list.innerHTML = '';
                        return;
                    }

                    const endpoint = routeUrl + '?q=' + encodeURIComponent(q) + '&limit=8';
                    fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                        .then(r => r.ok ? r.json() : { items: [] })
                        .then(data => renderList(data.items || [], q))
                        .catch(() => { list.innerHTML = ''; });
                }

                input.addEventListener('input', () => fetchSuggestions(false));
                btn.addEventListener('click', () => { fetchSuggestions(true); input.focus(); });
                queueBtn.addEventListener('click', queueCurrent);
                saveBtn.addEventListener('click', (ev) => {
                    if (pending.length > 0) {
                        // permitir envio mesmo com campos vazios, pois a fila já está preenchida
                        cpfField.removeAttribute('required');
                        nomeField.removeAttribute('required');
                        emailField.removeAttribute('required');
                    } else {
                        cpfField.setAttribute('required', 'required');
                        nomeField.setAttribute('required', 'required');
                        emailField.setAttribute('required', 'required');
                    }
                });
            })();
        </script>
    </section>

    <section class="panel" style="margin-top:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 style="margin:0;">Contatos da lista</h2>
            <?php
                $contactsTotal = $contactsTotal ?? count($contacts);
                $contactsPage = max(1, (int)($contactsPage ?? 1));
                $contactsPerPage = max(1, (int)($contactsPerPage ?? 100));
                $contactsPages = max(1, (int)($contactsPages ?? ceil($contactsTotal / $contactsPerPage)));
                $start = ($contactsPage - 1) * $contactsPerPage + 1;
                $end = min($contactsTotal, $contactsPage * $contactsPerPage);
            ?>
            <small style="color:var(--muted);">Mostrando <?= number_format($start,0,',','.'); ?>–<?= number_format($end,0,',','.'); ?> de <?= number_format($contactsTotal,0,',','.'); ?></small>
        </div>
        <?php if ($contacts === []): ?>
            <p style="color:var(--muted);">Nenhum contato vinculado.</p>
        <?php else: ?>
            <?php
                $formatCpf = static function (?string $value): string {
                    $digits = preg_replace('/\D+/', '', (string)$value);
                    if ($digits === null || strlen($digits) !== 11) {
                        return '';
                    }
                    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
                };
            ?>
            <div style="margin-top:12px; overflow:auto; max-height:520px; border:1px solid rgba(148,163,184,0.15); border-radius:10px;">
            <table class="panel-table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>CPF</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th style="width:160px;">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact): ?>
                        <?php
                            $status = strtolower((string)($contact['subscription_status'] ?? 'subscribed'));
                            $isUnsub = $status === 'unsubscribed';
                            $meta = [];
                            if (isset($contact['metadata'])) {
                                $decodedMeta = json_decode((string)$contact['metadata'], true);
                                $meta = is_array($decodedMeta) ? $decodedMeta : [];
                            }
                            $name = trim((string)($meta['titular_nome'] ?? $contact['first_name'] ?? $contact['name'] ?? ''));
                            $cpf = $formatCpf($meta['cpf'] ?? $contact['document'] ?? null);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($cpf, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($contact['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="status-pill <?= $isUnsub ? 'paused' : 'active'; ?>">
                                    <?= $isUnsub ? 'Desativado' : 'Ativo'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isUnsub): ?>
                                    <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/contacts/' . (int)($contact['id'] ?? $contact['contact_id'] ?? 0) . '/resubscribe'); ?>" style="margin:0;">
                                        <?= csrf_field(); ?>
                                        <button type="submit" class="ghost">Reativar envio</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/contacts/' . (int)($contact['id'] ?? $contact['contact_id'] ?? 0) . '/unsubscribe'); ?>" style="margin:0;">
                                        <?= csrf_field(); ?>
                                        <button type="submit" class="ghost" style="color:#f97316;">Desativar envio</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($contactsPages > 1): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <a class="ghost" href="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/edit?page=' . max(1, $contactsPage - 1)); ?>" style="opacity:<?= $contactsPage > 1 ? '1' : '0.5'; ?>;pointer-events:<?= $contactsPage > 1 ? 'auto' : 'none'; ?>;">« Anterior</a>
                        <span style="color:var(--muted);">Página <?= $contactsPage; ?> de <?= $contactsPages; ?></span>
                        <a class="ghost" href="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/edit?page=' . min($contactsPages, $contactsPage + 1)); ?>" style="opacity:<?= $contactsPage < $contactsPages ? '1' : '0.5'; ?>;pointer-events:<?= $contactsPage < $contactsPages ? 'auto' : 'none'; ?>;">Próxima »</a>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php
                            $window = 5;
                            $startPage = max(1, $contactsPage - 2);
                            $endPage = min($contactsPages, $startPage + $window - 1);
                            for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a class="ghost" href="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/edit?page=' . $p); ?>" style="padding:6px 10px;border:1px solid <?= $p === $contactsPage ? '#38bdf8' : 'rgba(148,163,184,0.4)'; ?>;border-radius:8px;<?= $p === $contactsPage ? 'background:rgba(56,189,248,0.12);color:#e0f2fe;' : '' ?>;">
                                <?= $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
