<?php
$errors = $_SESSION['template_errors'] ?? [];
$old = $_SESSION['template_old'] ?? null;
unset($_SESSION['template_errors'], $_SESSION['template_old']);

$template = $template ?? [
    'name' => '',
    'subject' => '',
    'preview_text' => '',
    'category' => '',
    'tags' => [],
    'body_html' => '',
    'body_text' => '',
];

if ($old !== null) {
    $template = array_merge($template, $old);
}

$tagsValue = '';
if (isset($template['tags_string'])) {
    $tagsValue = (string)$template['tags_string'];
} elseif (isset($template['tags']) && is_array($template['tags'])) {
    $tagsValue = implode(', ', $template['tags']);
}
$placeholderCatalog = $placeholderCatalog ?? \App\Services\TemplatePlaceholderCatalog::catalog();
$statusValue = $template['status'] ?? 'published';
$labelValue = $template['label'] ?? '';
?>

<header>
    <div>
        <h1>Novo modelo de e-mail</h1>
        <p>Monte um template reutilizável para campanhas futuras.</p>
    </div>
    <div>
        <a href="<?= url('templates'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar para modelos</a>
    </div>
</header>

<div class="panel">
    <form method="post" action="<?= url('templates'); ?>" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Nome do modelo
            <input type="text" name="name" value="<?= htmlspecialchars($template['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            <?php if (!empty($errors['name'])): ?>
                <span style="color:#f87171;font-size:0.8rem;"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </label>

        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Assunto padrão
            <input type="text" name="subject" value="<?= htmlspecialchars($template['subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            <?php if (!empty($errors['subject'])): ?>
                <span style="color:#f87171;font-size:0.8rem;"><?= htmlspecialchars($errors['subject'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </label>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Texto de preview (inbox)
                <input type="text" name="preview_text" value="<?= htmlspecialchars($template['preview_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: Consultoria dedicada para sua renovação" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <span style="font-size:0.75rem;color:var(--muted);">Exibido como snippet no cliente de e-mail.</span>
            </label>

            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Categoria
                <input type="text" name="category" value="<?= htmlspecialchars($template['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="renovacao, consentimento..." style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
        </div>

        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Tags (separe por vírgula)
            <input type="text" name="tags" value="<?= htmlspecialchars($tagsValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="vip, consultivo, whatsapp" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
        </label>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Status da versão
                <select name="status" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="published" <?= $statusValue === 'published' ? 'selected' : ''; ?>>Publicar ao salvar</option>
                    <option value="draft" <?= $statusValue === 'draft' ? 'selected' : ''; ?>>Salvar como rascunho</option>
                </select>
            </label>

            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Rótulo da versão (opcional)
                <input type="text" name="label" value="<?= htmlspecialchars($labelValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: Ajuste copy black friday" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
        </div>

        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            HTML do e-mail
            <textarea name="body_html" rows="10" style="padding:14px;border-radius:14px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);resize:vertical;min-height:240px;"><?= htmlspecialchars($template['body_html'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Versão em texto (opcional)
            <textarea name="body_text" rows="6" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);resize:vertical;"><?= htmlspecialchars($template['body_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <div style="font-size:0.8rem;color:var(--muted);background:rgba(15,23,42,0.5);border:1px dashed rgba(56,189,248,0.35);border-radius:14px;padding:14px;display:grid;gap:10px;">
            <div>
                <strong style="display:block;color:var(--accent);margin-bottom:6px;">Placeholders disponíveis</strong>
                <span style="font-size:0.8rem;">Use o formato <code>{{chave}}</code>. Tokens legados serão convertidos automaticamente no salvamento.</span>
            </div>
            <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
                <?php foreach ($placeholderCatalog as $namespace => $placeholders): ?>
                    <div>
                        <strong style="color:var(--text);text-transform:uppercase;font-size:0.75rem;letter-spacing:0.06em;"><?= htmlspecialchars($namespace, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                            <?php foreach ($placeholders as $placeholder): ?>
                                <code style="background:rgba(56,189,248,0.16);border:1px solid rgba(56,189,248,0.3);padding:4px 8px;border-radius:8px;display:inline-block;">
                                    {{<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>}}
                                </code>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <a href="<?= url('templates'); ?>" style="text-decoration:none;color:var(--muted);font-weight:600;">Cancelar</a>
            <button class="primary" type="submit">Salvar modelo</button>
        </div>
    </form>
</div>
