<?php
$segment = $segment ?? [];
$errors = $errors ?? [];
$mode = $mode ?? 'create';
$action = $action ?? '';
$title = $title ?? 'Segmento';
$lists = $lists ?? [];
$refreshOptions = [
    'dynamic' => 'Atualização automática',
    'manual' => 'Atualização manual',
];
$statusOptions = [
    'draft' => 'Rascunho',
    'active' => 'Ativo',
    'paused' => 'Pausado',
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
        <p style="color:var(--muted);max-width:760px;">Descreva os critérios do recorte (tags, atributos, eventos). O campo de definição aceita JSON ou expressões simples; em versões futuras incluiremos um construtor visual.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('marketing/segments'); ?>">&larr; Voltar</a>
    </div>
</header>

<div class="panel" style="margin-top:18px;">
    <form action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
            <label class="form-field">
                <span>Nome *</span>
                <input type="text" name="name" value="<?= htmlspecialchars($segment['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                <?php if (isset($errors['name'])): ?><small class="error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
            <label class="form-field">
                <span>Slug *</span>
                <input type="text" name="slug" value="<?= htmlspecialchars($segment['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="segmento-premium" required>
                <?php if (isset($errors['slug'])): ?><small class="error"><?= htmlspecialchars($errors['slug'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
            <label class="form-field">
                <span>Lista base</span>
                <select name="list_id">
                    <option value="">Todas as listas</option>
                    <?php foreach ($lists as $listOption): ?>
                        <?php $listId = (int)($listOption['id'] ?? 0); ?>
                        <option value="<?= $listId; ?>" <?= ((string)($segment['list_id'] ?? '') === (string)$listId) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($listOption['name'] ?? 'Lista', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['list_id'])): ?><small class="error"><?= htmlspecialchars($errors['list_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
        </div>

        <label class="form-field">
            <span>Descrição</span>
            <textarea name="description" rows="3" placeholder="Objetivo do segmento"><?= htmlspecialchars($segment['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label class="form-field">
            <span>Definição (JSON, SQL-like ou DSL)</span>
            <textarea name="definition" rows="6" placeholder='{"any":[{"tag":"premium"}]}' style="font-family:'JetBrains Mono',Consolas,monospace;"><?= htmlspecialchars($segment['definition'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label class="form-field">
                <span>Modo de atualização</span>
                <select name="refresh_mode">
                    <?php foreach ($refreshOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= (($segment['refresh_mode'] ?? 'dynamic') === $value) ? 'selected' : ''; ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-field">
                <span>Intervalo (minutos)</span>
                <input type="number" name="refresh_interval" min="5" step="5" placeholder="15" value="<?= htmlspecialchars($segment['refresh_interval'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <small style="color:var(--muted);">Usado apenas em modo automático.</small>
            </label>
            <label class="form-field">
                <span>Status</span>
                <select name="status">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= (($segment['status'] ?? 'draft') === $value) ? 'selected' : ''; ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="form-field">
            <span>Metadados (JSON)</span>
            <textarea name="metadata" rows="3" placeholder='{"owner":"growth"}'><?= htmlspecialchars($segment['metadata'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <footer style="display:flex;justify-content:flex-end;gap:12px;">
            <a class="ghost" href="<?= url('marketing/segments'); ?>">Cancelar</a>
            <button class="primary" type="submit">Salvar segmento</button>
        </footer>
    </form>
</div>
