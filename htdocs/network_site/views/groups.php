<?php
// Lista grupos do usuário. Expects $account, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Grupos</span>
    <h1>Seus grupos de network</h1>
    <p class="lede">Grupos automáticos por política, região, atividade e objetivos. Admin pode mover sua posição política.</p>
</div>

<div class="panel">
    <div class="pill"><span class="badge-dot"></span>Participações</div>
    <div id="group-list">Carregando...</div>
    <div style="margin-top:10px;">
        <a class="cta alt" href="/network/profile">Editar perfil</a>
        <a class="cta alt" href="/network/messages">Ir para mensagens</a>
    </div>
</div>

<script>
async function loadGroups() {
    const box = document.getElementById('group-list');
    try {
        const res = await fetch('/network/api/groups');
        const json = await res.json();
        const groups = json.data || [];
        if (!groups.length) { box.textContent = 'Nenhum grupo associado.'; return; }
        box.innerHTML = groups.map(g => `
            <div style="border:1px solid rgba(148,163,184,0.2); border-radius:10px; padding:10px; margin-bottom:8px;">
                <div><strong>${escapeHtml(g.name || g.slug)}</strong></div>
                <div style="color:#9fb1c8;font-size:0.9rem;">${escapeHtml(g.type || '')}</div>
            </div>
        `).join('');
    } catch (e) {
        box.textContent = 'Erro ao carregar grupos.';
    }
}

function escapeHtml(v) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return (v ?? '').toString().replace(/[&<>"']/g, c => map[c]);
}

loadGroups();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
