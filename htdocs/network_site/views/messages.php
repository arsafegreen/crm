<?php
// Mensagens diretas. Expects $account, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Mensagens</span>
    <h1>Converse com outros participantes</h1>
    <p class="lede">Envio direto por e-mail cadastrado. Respeitamos isolamento político: esquerda/direita não se veem; neutro fala com ambos.</p>
</div>

<div class="panel">
    <form id="form-send" class="form-grid">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <label>E-mail do destinatário
            <input type="email" name="to_email" required placeholder="alvo@empresa.com">
        </label>
        <label>Mensagem
            <textarea name="body" rows="3" required placeholder="Texto curto, sem anexos."></textarea>
        </label>
        <button class="cta" type="submit">Enviar</button>
    </form>
</div>

<div class="panel">
    <div class="pill"><span class="badge-dot"></span>Últimas mensagens</div>
    <div id="msg-list">Carregando...</div>
</div>

<script>
const csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

async function loadMessages() {
    const box = document.getElementById('msg-list');
    try {
        const res = await fetch('/network/api/messages');
        const json = await res.json();
        const msgs = json.data || [];
        if (!msgs.length) { box.textContent = 'Sem mensagens ainda.'; return; }
        box.innerHTML = msgs.map(m => {
            const me = <?= (int)$account['id']; ?>;
            const dir = m.sender_id === me ? 'Enviada para ' + (m.recipient_email || 'destinatário') : 'De ' + (m.sender_email || 'remetente');
            return `<div style="border:1px solid rgba(148,163,184,0.2); border-radius:10px; padding:10px; margin-bottom:8px;">
                <div style="color:#9fb1c8;font-size:0.9rem;">${dir} · ${escapeHtml(m.created_at || '')}</div>
                <div style="margin-top:6px;">${escapeHtml(m.body || '')}</div>
            </div>`;
        }).join('');
    } catch (e) {
        box.textContent = 'Erro ao carregar mensagens.';
    }
}

const form = document.getElementById('form-send');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.set('_token', csrf);
    const res = await fetch('/network/api/messages/send', { method:'POST', body: fd });
    if (res.ok) {
        form.reset();
        loadMessages();
    } else {
        const txt = await res.text();
        alert('Erro: ' + txt);
    }
});

function escapeHtml(v) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return (v ?? '').toString().replace(/[&<>"']/g, c => map[c]);
}

loadMessages();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
