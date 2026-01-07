<?php
// Admin panel. Expects $admin, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Painel</span>
    <h1>Olá, <?= htmlspecialchars($admin['name'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="lede">Gerencie leads e anúncios do Network. Todas as ações exigem sessão válida e token anti-CSRF.</p>
    <form method="POST" action="/network/admin/logout" style="margin-top:8px;">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="cta alt" type="submit">Sair</button>
    </form>
</div>

<div class="panel" aria-label="Leads">
    <div class="pill"><span class="badge-dot"></span> Leads recentes</div>
    <div id="leads-table" class="table-shell">Carregando...</div>
</div>

<div class="panel" aria-label="Anúncios">
    <div class="pill"><span class="badge-dot"></span> Anúncios</div>
    <form id="form-ad" class="form-grid" style="margin-bottom:16px;">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Título
            <input type="text" name="title" required>
        </label>
        <label>URL de destino
            <input type="url" name="target_url" required placeholder="https://...">
        </label>
        <label>URL da imagem (opcional)
            <input type="url" name="image_url" placeholder="https://...">
        </label>
        <label>Início (opcional)
            <input type="datetime-local" name="starts_at">
        </label>
        <label>Fim (opcional)
            <input type="datetime-local" name="ends_at">
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="is_active" value="1" checked> Ativo
        </label>
        <button class="cta" type="submit">Cadastrar anúncio</button>
    </form>

<div id="ads-table" class="table-shell">Carregando...</div>
</div>

<div class="panel" aria-label="Contas">
    <div class="pill"><span class="badge-dot"></span> Contas / Política</div>
    <div id="accounts-table" class="table-shell">Carregando...</div>
</div>

<style>
.table-shell { overflow-x: auto; border: 1px solid rgba(148,163,184,0.2); border-radius: 12px; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 10px 12px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
.table th { color: #c7d6ec; font-weight: 600; }
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 0.85rem; background: rgba(148,163,184,0.14); }
.badge.success { background: rgba(34,197,94,0.16); color: #c6f6d5; }
.badge.warn { background: rgba(252,211,77,0.14); color: #fef3c7; }
.badge.danger { background: rgba(248,113,113,0.14); color: #fecdd3; }
.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.btn { padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(148,163,184,0.3); background: rgba(255,255,255,0.03); color: inherit; cursor: pointer; }
.btn:hover { border-color: rgba(34,211,238,0.6); }
</style>

<script>
const csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

async function fetchLeads() {
    const el = document.getElementById('leads-table');
    try {
        const res = await fetch('/network/api/leads');
        const json = await res.json();
        renderLeads(json.data || []);
    } catch (e) {
        el.textContent = 'Falha ao carregar leads';
    }
}

function renderLeads(leads) {
    const el = document.getElementById('leads-table');
    if (!leads.length) { el.textContent = 'Nenhum lead ainda.'; return; }
    const rows = leads.map(l => `
        <tr>
            <td><strong>${escapeHtml(l.name)}</strong><br><small>${escapeHtml(l.email)}</small><br><small>${escapeHtml(l.phone)}</small></td>
            <td>${escapeHtml(l.region || '-')}</td>
            <td>${escapeHtml(l.area || '-')}</td>
            <td>${escapeHtml(l.objective || '-')}</td>
            <td>${statusBadge(l.status)}</td>
            <td>${(l.suggested_groups||[]).map(escapeHtml).join('<br>')}</td>
            <td>${(l.assigned_groups||[]).map(escapeHtml).join('<br>')}</td>
            <td>
                <div class="actions">
                    <button class="btn" onclick="setStatus('${l.id}','approve')">Aprovar</button>
                    <button class="btn" onclick="setStatus('${l.id}','deny')">Negar</button>
                    <button class="btn" onclick="editGroups('${l.id}', ${JSON.stringify(l.assigned_groups || [])})">Grupos</button>
                </div>
            </td>
        </tr>
    `).join('');

    el.innerHTML = `<table class="table"><thead><tr>
        <th>Contato</th><th>Região</th><th>Área</th><th>Objetivo</th><th>Status</th><th>Sugeridos</th><th>Grupos</th><th>Ações</th>
    </tr></thead><tbody>${rows}</tbody></table>`;
}

async function setStatus(id, action) {
    const note = prompt('Observação (opcional):') || '';
    const fd = new FormData();
    fd.append('_token', csrf);
    fd.append('note', note);
    const res = await fetch(`/network/api/leads/${id}/${action}`, { method:'POST', body: fd });
    if (res.ok) fetchLeads();
}

async function editGroups(id, current) {
    const val = prompt('Grupos separados por vírgula', (current || []).join(','));
    if (val === null) return;
    const fd = new FormData();
    fd.append('_token', csrf);
    fd.append('groups', val);
    const res = await fetch(`/network/api/leads/${id}/groups`, { method:'POST', body: fd });
    if (res.ok) fetchLeads();
}

function statusBadge(status) {
    const map = { approved: 'success', denied: 'danger', pending: 'warn' };
    const cls = map[status] || 'warn';
    return `<span class="badge ${cls}">${escapeHtml(status || 'pending')}</span>`;
}

function escapeHtml(v) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return (v ?? '').toString().replace(/[&<>"']/g, c => map[c]);
}

async function fetchAds() {
    const el = document.getElementById('ads-table');
    try {
        const res = await fetch('/network/api/ads');
        const json = await res.json();
        renderAds(json.data || []);
    } catch (e) { el.textContent = 'Falha ao carregar anúncios'; }
}

function renderAds(ads) {
    const el = document.getElementById('ads-table');
    if (!ads.length) { el.textContent = 'Nenhum anúncio'; return; }
    const rows = ads.map(a => `
        <tr>
            <td><strong>${escapeHtml(a.title)}</strong><br><small>${escapeHtml(a.target_url || '')}</small></td>
            <td>${a.is_active ? 'Ativo' : 'Inativo'}</td>
            <td>${escapeHtml(a.starts_at || '-')}</td>
            <td>${escapeHtml(a.ends_at || '-')}</td>
            <td>
                <div class="actions">
                    <button class="btn" onclick="toggleAd(${a.id})">Alternar</button>
                    <button class="btn" onclick="editAd(${a.id})">Editar</button>
                </div>
            </td>
        </tr>
    `).join('');
    el.innerHTML = `<table class="table"><thead><tr><th>Título</th><th>Status</th><th>Início</th><th>Fim</th><th>Ações</th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function toggleAd(id) {
    const fd = new FormData();
    fd.append('_token', csrf);
    await fetch(`/network/api/ads/${id}/toggle`, { method:'POST', body: fd });
    fetchAds();
}

async function editAd(id) {
    const title = prompt('Novo título:');
    if (title === null) return;
    const fd = new FormData();
    fd.append('_token', csrf);
    fd.append('title', title);
    await fetch(`/network/api/ads/${id}`, { method:'POST', body: fd });
    fetchAds();
}

const formAd = document.getElementById('form-ad');
formAd?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(formAd);
    fd.set('_token', csrf);
    fd.set('is_active', fd.get('is_active') ? '1' : '0');
    await fetch('/network/api/ads', { method:'POST', body: fd });
    formAd.reset();
    fetchAds();
});

async function fetchAccounts() {
    const el = document.getElementById('accounts-table');
    try {
        const res = await fetch('/network/api/admin/accounts');
        const json = await res.json();
        renderAccounts(json.data || []);
    } catch (e) {
        el.textContent = 'Falha ao carregar contas';
    }
}

function renderAccounts(list) {
    const el = document.getElementById('accounts-table');
    if (!list.length) { el.textContent = 'Nenhuma conta'; return; }
    const rows = list.map(acc => `
        <tr>
            <td><strong>${escapeHtml(acc.name || '')}</strong><br><small>${escapeHtml(acc.email || '')}</small><br><small>${escapeHtml(acc.phone || '')}</small></td>
            <td>${escapeHtml(acc.type || '')}</td>
            <td>${escapeHtml(acc.segment || '-')}</td>
            <td>${escapeHtml(acc.region || '')}/${escapeHtml(acc.state || '')}</td>
            <td>${escapeHtml(acc.political_pref || '')}${acc.locked_at ? ' <span class="badge danger">bloqueado</span>' : ''}</td>
            <td>
                <div class="actions">
                    <button class="btn" onclick="changePolitics(${acc.id})">Política</button>
                    ${acc.locked_at ? `<button class="btn" onclick="unlockAccount(${acc.id})">Desbloquear</button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
    el.innerHTML = `<table class="table"><thead><tr><th>Conta</th><th>Tipo</th><th>Segmento</th><th>Região/UF</th><th>Política</th><th>Ações</th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function changePolitics(id) {
    const pref = prompt('Informe: left, right ou neutral');
    if (!pref) return;
    const fd = new FormData();
    fd.append('_token', csrf);
    fd.append('political_pref', pref.trim());
    const res = await fetch(`/network/api/admin/accounts/${id}/politics`, { method:'POST', body: fd });
    if (res.ok) fetchAccounts(); else alert('Erro ao alterar política');
}

async function unlockAccount(id) {
    const fd = new FormData();
    fd.append('_token', csrf);
    const res = await fetch(`/network/api/admin/accounts/${id}/unlock`, { method:'POST', body: fd });
    if (res.ok) fetchAccounts(); else alert('Erro ao desbloquear');
}

fetchLeads();
fetchAds();
fetchAccounts();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
