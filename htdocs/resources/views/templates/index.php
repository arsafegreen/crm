<header>
    <div>
        <h1>Modelos de Campanha</h1>
        <p>Organize seus templates de e-mail para reutilizar mensagens em novas ações.</p>
    </div>
    <div style="display:flex;gap:12px;">
        <a href="<?= url('campaigns/email'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar às campanhas</a>
        <a href="<?= url('templates/create'); ?>" style="text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px;background:rgba(56,189,248,0.18);border:1px solid rgba(56,189,248,0.28);color:var(--accent);">Novo modelo</a>
    </div>
</header>

<?php if (!empty($feedback)): ?>
    <div class="panel" style="border-left:4px solid <?= ($feedback['type'] ?? 'info') === 'success' ? '#22c55e' : '#38bdf8'; ?>;margin-bottom:28px;">
        <strong style="display:block;margin-bottom:6px;">Modelos</strong>
        <p style="margin:0;"><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
<?php endif; ?>

<div class="panel">
    <h2 style="margin-top:0;">Galeria de modelos</h2>
    <?php if (empty($templates)): ?>
        <p style="margin:0;color:var(--muted);">Nenhum modelo cadastrado ainda. Crie um novo para começar.</p>
    <?php else: ?>
        <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
            <?php foreach ($templates as $template): ?>
                <div style="border:1px solid var(--border);border-radius:18px;padding:20px;background:rgba(15,23,42,0.68);position:relative;overflow:hidden;">
                    <div style="position:absolute;top:-80px;right:-80px;width:160px;height:160px;background:rgba(56,189,248,0.12);border-radius:50%;"></div>
                    <div style="position:relative;">
                        <h3 style="margin:0;font-size:1.1rem;color:var(--text);"><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p style="margin:6px 0 10px;color:var(--muted);font-size:0.9rem;">
                            Assunto padrão: <strong><?= htmlspecialchars($template['subject'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </p>
                        <p style="margin:0 0 18px;color:rgba(148,163,184,0.75);font-size:0.8rem;">
                            Atualizado em <?= format_datetime($template['updated_at'] ?? null); ?>
                        </p>
                        <div style="display:flex;gap:10px;">
                            <a href="<?= url('templates/' . (int)$template['id'] . '/edit'); ?>" style="flex:1;text-align:center;padding:10px 0;border-radius:10px;text-decoration:none;background:rgba(56,189,248,0.2);border:1px solid rgba(56,189,248,0.35);color:var(--accent);font-weight:600;font-size:0.85rem;">Editar</a>
                            <form method="post" action="<?= url('templates/' . (int)$template['id'] . '/delete'); ?>" style="margin:0;">
                                <?= csrf_field(); ?>
                                <button type="submit" style="border:none;padding:10px 14px;border-radius:10px;background:rgba(248,113,113,0.18);color:#f87171;font-weight:600;font-size:0.85rem;cursor:pointer;">Excluir</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
