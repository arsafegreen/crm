<header>
    <div>
        <h1>Conexões de Canais</h1>
        <p>Cadastre tokens de Instagram, Facebook, WhatsApp Business e LinkedIn para acionar campanhas diretas.</p>
        <p style="color:var(--muted);font-size:0.9rem;max-width:720px;">
            Gere tokens no painel das plataformas (Meta, LinkedIn, etc.) e cole aqui. Os dados ficam na base local para uso exclusivo desta suíte.
        </p>
    </div>
    <div>
        <a href="<?= url(); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;">&larr; Voltar ao painel</a>
    </div>
</header>

<div class="panel">
    <h2 style="margin-top:0;">Novo canal</h2>
    <?php if (!empty($feedback)): ?>
        <div style="padding:12px;border:1px solid rgba(148,163,184,0.3);border-radius:12px;margin-bottom:18px;background:rgba(15,23,42,0.6);color:<?= $feedback['type'] === 'error' ? '#f87171' : '#22c55e'; ?>;">
            <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form id="socialAccountForm" method="post" action="<?= url('social-accounts'); ?>" style="display:grid;gap:16px;">
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Plataforma</label>
            <select name="platform" required style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <option value="">Selecione</option>
                <option value="facebook">Facebook</option>
                <option value="instagram">Instagram</option>
                <option value="whatsapp">WhatsApp Business</option>
                <option value="linkedin">LinkedIn</option>
            </select>
        </div>

        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Apelido interno</label>
            <input type="text" name="label" required placeholder="Ex.: Fanpage principal" style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
        </div>

        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">ID externo (Page ID, Instagram ID, Número)</label>
            <input type="text" name="external_id" placeholder="Opcional" style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
        </div>

        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Token de acesso</label>
            <textarea name="token" required placeholder="Cole o token gerado na plataforma" rows="4" style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);"></textarea>
        </div>

        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Expira em</label>
            <input type="datetime-local" name="expires_at" style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            <p style="color:var(--muted);font-size:0.8rem;margin-top:4px;">Defina a data de expiração do token para lembrarmos de renovar.</p>
        </div>

        <div style="display:flex;justify-content:flex-end;">
            <button class="primary" type="submit">Salvar canal</button>
        </div>
    </form>
</div>

<div class="panel" style="margin-top:28px;">
    <h2 style="margin-top:0;">Canais conectados</h2>
    <?php if (empty($accounts)): ?>
        <p style="color:var(--muted);">Ainda não há conexões. Cadastre acima os tokens para ativar automações.</p>
    <?php else: ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));">
            <?php foreach ($accounts as $account): ?>
                <div style="border:1px solid var(--border);border-radius:14px;padding:18px;background:rgba(15,23,42,0.68);">
                    <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);">
                        <?= htmlspecialchars($account['platform'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size:1.2rem;font-weight:600;margin:8px 0 4px;">
                        <?= htmlspecialchars($account['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if (!empty($account['external_id'])): ?>
                        <div style="font-size:0.85rem;color:var(--muted);">ID externo: <?= htmlspecialchars((string)$account['external_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div style="font-size:0.85rem;color:var(--muted);margin-top:8px;">
                        Criado em <?= date('d/m/Y H:i', (int)$account['created_at']); ?>
                    </div>
                    <?php if (!empty($account['expires_at'])): ?>
                        <div style="font-size:0.85rem;color:<?= (int)$account['expires_at'] < time() ? '#f87171' : '#22c55e'; ?>;margin-top:4px;">
                            Expira em <?= date('d/m/Y H:i', (int)$account['expires_at']); ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:0.85rem;color:var(--muted);margin-top:4px;">Sem expiração definida.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
