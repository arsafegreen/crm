<?php
$template = $template ?? [];
$errors = $errors ?? [];
$versions = $versions ?? [];

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
            <h1>Editar modelo</h1>
            <p>Ajuste o conteúdo e reutilize este template nas próximas campanhas.</p>
        </div>
        <div>
            <a href="<?= url('templates'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar para modelos</a>
        </div>
    </header>

    <div class="panel">
        <form method="post" action="<?= url('templates/' . (int)($template['id'] ?? 0) . '/update'); ?>" style="display:grid;gap:18px;">
            <?= csrf_field(); ?>
            <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0); ?>">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Nome do modelo
                <input type="text" name="name" value="<?= htmlspecialchars($template['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <?php if (!empty($errors['name'])): ?>
                    <span style="color:#f87171;font-size:0.8rem;">
                        <?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </label>

            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Assunto padrão
                <input type="text" name="subject" value="<?= htmlspecialchars($template['subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <?php if (!empty($errors['subject'])): ?>
                    <span style="color:#f87171;font-size:0.8rem;">
                        <?= htmlspecialchars($errors['subject'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
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
                    <input type="text" name="label" value="<?= htmlspecialchars($labelValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: Copy pós-campanha" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
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
                <button class="primary" type="submit">Atualizar modelo</button>
            </div>
        </form>
    </div>

    <?php if (!empty($versions)): ?>
        <div class="panel" style="margin-top:24px;">
            <h2 style="margin:0 0 12px;">Histórico de versões</h2>
            <p style="margin:0 0 16px;color:var(--muted);font-size:0.85rem;">Cada alteração gera uma nova versão publicada para auditoria. Você pode visualizar e aplicar qualquer versão no formulário para editar e salvar.</p>
            <div style="display:grid;gap:12px;">
                <?php foreach ($versions as $version): ?>
                    <?php
                        $versionId = (int)($version['id'] ?? 0);
                        $versionNumber = (int)($version['version'] ?? 0);
                        $vSubject = htmlspecialchars($version['subject'] ?? '', ENT_QUOTES, 'UTF-8');
                        $vPreview = htmlspecialchars($version['preview_text'] ?? '', ENT_QUOTES, 'UTF-8');
                        $vBodyHtml = $version['body_html'] ?? '';
                        $vBodyText = htmlspecialchars($version['body_text'] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <div style="padding:12px 14px;border:1px solid var(--border);border-radius:12px;display:flex;flex-direction:column;gap:8px;">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                            <div>
                                <strong>v<?= $versionNumber; ?></strong>
                                <span style="margin-left:8px;color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.08em;">
                                    <?= htmlspecialchars($version['status'] ?? 'draft', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <div style="font-size:0.8rem;color:var(--muted);margin-top:4px;">
                                    <?= format_datetime($version['published_at'] ?? $version['updated_at'] ?? null); ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <button type="button"
                                    class="ghost"
                                    data-action="apply-version"
                                    data-version="<?= $versionNumber; ?>"
                                    data-subject="<?= $vSubject; ?>"
                                    data-preview="<?= $vPreview; ?>"
                                    data-body-html="<?= htmlspecialchars($vBodyHtml, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-body-text="<?= $vBodyText; ?>"
                                    style="padding:8px 12px;">
                                    Aplicar no formulário
                                </button>
                                <details class="ghost" style="padding:8px 12px;">
                                    <summary style="cursor:pointer;">Ver conteúdo</summary>
                                    <div style="margin-top:8px;display:flex;flex-direction:column;gap:8px;">
                                        <div style="font-size:0.85rem;color:var(--muted);"><strong>Assunto:</strong> <?= $vSubject; ?></div>
                                        <?php if ($vPreview !== ''): ?><div style="font-size:0.85rem;color:var(--muted);"><strong>Preview:</strong> <?= $vPreview; ?></div><?php endif; ?>
                                        <?php if ($vBodyText !== ''): ?>
                                            <div>
                                                <strong style="font-size:0.85rem;">Texto:</strong>
                                                <pre style="white-space:pre-wrap;background:rgba(15,23,42,0.3);padding:10px;border-radius:10px;border:1px solid var(--border);color:var(--text);margin:6px 0 0;"><?= $vBodyText; ?></pre>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim($vBodyHtml) !== ''): ?>
                                            <div>
                                                <strong style="font-size:0.85rem;">HTML:</strong>
                                                <textarea readonly style="width:100%;min-height:160px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.25);color:var(--text);resize:vertical;"><?= htmlspecialchars($vBodyHtml, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </div>
                        </div>
                        <?php if (!empty($version['label'])): ?>
                            <span style="font-size:0.8rem;color:var(--text);">
                                <?= htmlspecialchars($version['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        (function() {
            const applyButtons = document.querySelectorAll('[data-action="apply-version"]');
            if (!applyButtons.length) return;

            const nameInput = document.querySelector('input[name="name"]');
            const subjectInput = document.querySelector('input[name="subject"]');
            const previewInput = document.querySelector('input[name="preview_text"]');
            const bodyHtmlTextarea = document.querySelector('textarea[name="body_html"]');
            const bodyTextTextarea = document.querySelector('textarea[name="body_text"]');

            // Garantir que o nome do template aparece mesmo se algum estado anterior tiver limpado o campo
            const templateName = <?= json_encode(($template['name'] ?? '') !== '' ? $template['name'] : ('Modelo #' . (int)($template['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            // Segurança extra: se o form veio sem ID, tenta preencher
            const hiddenId = document.querySelector('input[name="template_id"]');
            const fallbackId = <?= (int)($template['id'] ?? 0); ?> || <?= (int)($versions[0]['template_id'] ?? 0); ?>;
            if (hiddenId && (!hiddenId.value || hiddenId.value === '0') && fallbackId) {
                hiddenId.value = fallbackId.toString();
            }
            if (nameInput && templateName) {
                nameInput.value = templateName;
            }

            // Se o formulário chegou vazio e há versões, preenche com a primeira versão disponível
            if (applyButtons.length && subjectInput && subjectInput.value.trim() === '') {
                const first = applyButtons[0];
                subjectInput.value = first.getAttribute('data-subject') || '';
                if (previewInput) previewInput.value = first.getAttribute('data-preview') || '';
                if (bodyHtmlTextarea) bodyHtmlTextarea.value = first.getAttribute('data-body-html') || '';
                if (bodyTextTextarea) bodyTextTextarea.value = first.getAttribute('data-body-text') || '';
            }

            applyButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const subj = btn.getAttribute('data-subject') || '';
                    const prev = btn.getAttribute('data-preview') || '';
                    const html = btn.getAttribute('data-body-html') || '';
                    const text = btn.getAttribute('data-body-text') || '';

                    if (nameInput && templateName) {
                        nameInput.value = templateName;
                    }
                    if (subjectInput) subjectInput.value = subj;
                    if (previewInput) previewInput.value = prev;
                    if (bodyHtmlTextarea) bodyHtmlTextarea.value = html;
                    if (bodyTextTextarea) bodyTextTextarea.value = text;

                    btn.textContent = 'Aplicado (edite e salve)';
                    setTimeout(() => { btn.textContent = 'Aplicar no formulário'; }, 1800);
                });
            });
        })();
    </script>
