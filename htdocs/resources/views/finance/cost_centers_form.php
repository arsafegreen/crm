<?php
$costCenter = $costCenter ?? [];
$errors = $errors ?? [];
$accounts = $accounts ?? [];
$parentOptions = $parentOptions ?? [];
$action = $action ?? '';
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1>Editar centro de custo</h1>
            <p>Atualize código, hierarquia e conta padrão. Alterações impactam os próximos lançamentos vinculados.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance/cost-centers'); ?>">Voltar</a>
        </div>
    </header>

    <div class="finance-panel">
        <form action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" class="finance-form">
            <?= csrf_field(); ?>
            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['name']) ? 'has-error' : ''; ?>">
                    <span>Nome *</span>
                    <input type="text" name="name" value="<?= htmlspecialchars($costCenter['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <?php if (isset($errors['name'])): ?><small class="finance-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($errors['code']) ? 'has-error' : ''; ?>">
                    <span>Código *</span>
                    <input class="finance-uppercase" type="text" name="code" value="<?= htmlspecialchars($costCenter['code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <?php if (isset($errors['code'])): ?><small class="finance-error"><?= htmlspecialchars($errors['code'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <label class="finance-field">
                <span>Descrição</span>
                <textarea name="description" rows="4"><?= htmlspecialchars($costCenter['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>

            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['parent_id']) ? 'has-error' : ''; ?>">
                    <span>Centro pai</span>
                    <select name="parent_id">
                        <option value="">Sem hierarquia</option>
                        <?php foreach ($parentOptions as $option): ?>
                            <?php $id = (int)($option['id'] ?? 0); ?>
                            <option value="<?= $id; ?>" <?= (($costCenter['parent_id'] ?? '') === (string)$id) ? 'selected' : ''; ?>><?= htmlspecialchars($option['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['parent_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['parent_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($errors['default_account_id']) ? 'has-error' : ''; ?>">
                    <span>Conta padrão</span>
                    <select name="default_account_id">
                        <option value="">Selecionar</option>
                        <?php foreach ($accounts as $account): ?>
                            <?php $id = (int)($account['id'] ?? 0); ?>
                            <option value="<?= $id; ?>" <?= (($costCenter['default_account_id'] ?? '') === (string)$id) ? 'selected' : ''; ?>><?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['default_account_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['default_account_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <label class="finance-field inline">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" <?= (($costCenter['is_active'] ?? '1') === '1') ? 'checked' : ''; ?>>
                Centro ativo
            </label>

            <div class="finance-inline-actions">
                <a class="ghost" href="<?= url('finance/cost-centers'); ?>">Cancelar</a>
                <button class="primary" type="submit">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>
