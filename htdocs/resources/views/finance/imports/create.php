<?php
$accounts = $accounts ?? [];
$costCenters = $costCenters ?? [];
$form = $form ?? [];
$errors = $errors ?? [];
$fileTypes = [
    'ofx' => 'OFX (recomendado)',
    'csv' => 'CSV personalizado',
];
$duplicateStrategies = [
    'ignore' => 'Ignorar duplicados',
    'override' => 'Sobrescrever lançamentos existentes',
    'skip_batch' => 'Abortar lote se houver duplicado',
];
?>

<header>
    <div>
        <h1 style="margin-bottom:8px;">Nova importação</h1>
        <p style="color:var(--muted);max-width:720px;">Envie o extrato exportado pelo banco para conciliar os lançamentos. O arquivo fica guardado em armazenamento seguro e pode ser reprocessado mais tarde.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('finance/imports'); ?>">Cancelar</a>
    </div>
</header>

<div class="panel">
    <form action="<?= url('finance/imports'); ?>" method="post" enctype="multipart/form-data" style="display:grid;gap:20px;">
        <?= csrf_field(); ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span style="font-size:0.85rem;color:var(--muted);">Conta destino *</span>
                <select name="account_id" required style="padding:10px 12px;border-radius:12px;border:1px solid <?= isset($errors['account_id']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.62);color:var(--text);">
                    <option value="">Selecione</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int)$account['id']; ?>" <?= (string)($form['account_id'] ?? '') === (string)$account['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['account_id'])): ?><small style="color:#f87171;"><?= htmlspecialchars($errors['account_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span style="font-size:0.85rem;color:var(--muted);">Fuso horário</span>
                <input type="text" name="timezone" value="<?= htmlspecialchars($form['timezone'] ?? 'America/Sao_Paulo', ENT_QUOTES, 'UTF-8'); ?>" placeholder="America/Sao_Paulo" style="padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.62);color:var(--text);">
                <small style="color:var(--muted);">Usado para converter timestamps do banco.</small>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span style="font-size:0.85rem;color:var(--muted);">Centro de custo padrão</span>
                <select name="default_cost_center_id" style="padding:10px 12px;border-radius:12px;border:1px solid <?= isset($errors['default_cost_center_id']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.62);color:var(--text);">
                    <option value="">Não atribuir</option>
                    <?php foreach ($costCenters as $center): ?>
                        <option value="<?= (int)$center['id']; ?>" <?= (string)($form['default_cost_center_id'] ?? '') === (string)$center['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($center['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['default_cost_center_id'])): ?><small style="color:#f87171;"><?= htmlspecialchars($errors['default_cost_center_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>
        </div>

        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span style="font-size:0.85rem;color:var(--muted);">Formato do arquivo *</span>
                <select name="file_type" style="padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.62);color:var(--text);">
                    <?php foreach ($fileTypes as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= ($form['file_type'] ?? 'ofx') === $value ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>

                    <div class="finance-shell finance-page">
                        <header class="finance-header">
                            <div>
                                <h1>Nova importação</h1>
                                <p>Envie o extrato exportado pelo banco para conciliar lançamentos. Guardamos o arquivo para reprocessar quando necessário.</p>
                            </div>
                            <div class="finance-actions">
                                <a class="ghost" href="<?= url('finance/imports'); ?>">Cancelar</a>
                            </div>
                        </header>

                        <div class="finance-panel">
                            <form action="<?= url('finance/imports'); ?>" method="post" enctype="multipart/form-data" class="finance-form">
                                <?= csrf_field(); ?>

                                <div class="finance-form-row">
                                    <label class="finance-field <?= isset($errors['account_id']) ? 'has-error' : ''; ?>">
                                        <span>Conta destino *</span>
                                        <select name="account_id" required>
                                            <option value="">Selecione</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?= (int)$account['id']; ?>" <?= (string)($form['account_id'] ?? '') === (string)$account['id'] ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (isset($errors['account_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['account_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                                    </label>

                                    <label class="finance-field">
                                        <span>Fuso horário</span>
                                        <input type="text" name="timezone" value="<?= htmlspecialchars($form['timezone'] ?? 'America/Sao_Paulo', ENT_QUOTES, 'UTF-8'); ?>" placeholder="America/Sao_Paulo">
                                        <small class="finance-muted">Usado para converter timestamps do banco.</small>
                                    </label>

                                    <label class="finance-field <?= isset($errors['default_cost_center_id']) ? 'has-error' : ''; ?>">
                                        <span>Centro de custo padrão</span>
                                        <select name="default_cost_center_id">
                                            <option value="">Não atribuir</option>
                                            <?php foreach ($costCenters as $center): ?>
                                                <option value="<?= (int)$center['id']; ?>" <?= (string)($form['default_cost_center_id'] ?? '') === (string)$center['id'] ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($center['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (isset($errors['default_cost_center_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['default_cost_center_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                                    </label>
                                </div>

                                <div class="finance-form-row">
                                    <label class="finance-field">
                                        <span>Formato do arquivo *</span>
                                        <select name="file_type">
                                            <?php foreach ($fileTypes as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= ($form['file_type'] ?? 'ofx') === $value ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="finance-field">
                                        <span>Estratégia para duplicados</span>
                                        <select name="duplicate_strategy">
                                            <?php foreach ($duplicateStrategies as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= ($form['duplicate_strategy'] ?? 'ignore') === $value ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="finance-field">
                                        <span>Prefixo para categorias</span>
                                        <input type="text" name="category_prefix" maxlength="60" value="<?= htmlspecialchars($form['category_prefix'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <small class="finance-muted">Opcional, aplicado quando criamos centros automaticamente.</small>
                                    </label>
                                </div>

                                <label class="finance-field <?= isset($errors['import_file']) ? 'has-error' : ''; ?>">
                                    <span>Arquivo OFX ou CSV *</span>
                                    <input type="file" name="import_file" accept=".ofx,.csv,.txt" required>
                                    <?php if (isset($errors['import_file'])): ?><small class="finance-error"><?= htmlspecialchars($errors['import_file'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                                    <small class="finance-muted">Tamanho máximo 5MB. Armazenamos o arquivo original para auditoria.</small>
                                </label>

                                <div class="finance-inline-actions">
                                    <a class="ghost" href="<?= url('finance/imports'); ?>">Cancelar</a>
                                    <button class="primary" type="submit">Enviar arquivo</button>
                                </div>
                            </form>
                        </div>
                    </div>
