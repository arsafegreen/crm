<?php
$formValues = $form['values'] ?? [];
$formErrors = $form['errors'] ?? [];
$accountsById = [];
foreach ($accounts as $account) {
    $accountsById[(int)($account['id'] ?? 0)] = $account;
}
$centerMap = [];
foreach ($costCenters as $center) {
    $centerMap[(int)($center['id'] ?? 0)] = $center;
}
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1>Centros de custo</h1>
            <p>Estruture projetos, squads ou departamentos para classificar lançamentos e obrigações fiscais. Estes códigos alimentam relatórios e régua de cobrança.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance'); ?>">&larr; Voltar</a>
            <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Contas</a>
            <a class="ghost" href="<?= url('finance/transactions'); ?>">Lançamentos</a>
        </div>
    </header>

    <?php if (!empty($feedback)): ?>
        <div class="finance-feedback <?= ($feedback['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
            <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    <?php endif; ?>

    <div class="finance-panel">
        <div class="finance-panel-header">
            <div>
                <p class="finance-meta">Configuração</p>
                <h2>Novo centro</h2>
            </div>
            <p class="finance-muted">Preencha nome, código e conta padrão para manter rastreabilidade em relatórios.</p>
        </div>
        <form action="<?= url('finance/cost-centers'); ?>" method="post" class="finance-form">
            <?= csrf_field(); ?>
            <div class="finance-form-row">
                <label class="finance-field <?= isset($formErrors['name']) ? 'has-error' : ''; ?>">
                    <span>Nome *</span>
                    <input type="text" name="name" value="<?= htmlspecialchars($formValues['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <?php if (isset($formErrors['name'])): ?><small class="finance-error"><?= htmlspecialchars($formErrors['name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($formErrors['code']) ? 'has-error' : ''; ?>">
                    <span>Código *</span>
                    <input class="finance-uppercase" type="text" name="code" value="<?= htmlspecialchars($formValues['code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <?php if (isset($formErrors['code'])): ?><small class="finance-error"><?= htmlspecialchars($formErrors['code'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($formErrors['default_account_id']) ? 'has-error' : ''; ?>">
                    <span>Conta padrão</span>
                    <select name="default_account_id">
                        <option value="">Selecione</option>
                        <?php foreach ($accounts as $account): ?>
                            <?php $id = (int)($account['id'] ?? 0); ?>
                            <option value="<?= $id; ?>" <?= (($formValues['default_account_id'] ?? '') === (string)$id) ? 'selected' : ''; ?>><?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['default_account_id'])): ?><small class="finance-error"><?= htmlspecialchars($formErrors['default_account_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <label class="finance-field">
                <span>Descrição</span>
                <textarea name="description" rows="3"><?= htmlspecialchars($formValues['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>

            <div class="finance-form-row">
                <label class="finance-field <?= isset($formErrors['parent_id']) ? 'has-error' : ''; ?>">
                    <span>Centro pai</span>
                    <select name="parent_id">
                        <option value="">Sem hierarquia</option>
                        <?php foreach ($costCenters as $center): ?>
                            <?php $id = (int)($center['id'] ?? 0); ?>
                            <option value="<?= $id; ?>" <?= (($formValues['parent_id'] ?? '') === (string)$id) ? 'selected' : ''; ?>><?= htmlspecialchars($center['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['parent_id'])): ?><small class="finance-error"><?= htmlspecialchars($formErrors['parent_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>

                <label class="finance-field inline">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" <?= (($formValues['is_active'] ?? '1') === '1') ? 'checked' : ''; ?>>
                    Ativo
                </label>
            </div>

            <div class="finance-inline-actions">
                <button class="primary" type="submit">Salvar centro</button>
            </div>
        </form>
    </div>

    <div class="finance-panel">
        <div class="finance-panel-header">
            <div>
                <p class="finance-meta">Catálogo</p>
                <h2>Centros cadastrados</h2>
            </div>
            <p class="finance-muted">Utilize ações à direita para ajustar hierarquias ou remover registros desnecessários.</p>
        </div>

        <?php if ($costCenters === []): ?>
            <div class="finance-empty">
                <p>Nenhum centro cadastrado ainda.</p>
                <p class="finance-muted">Crie códigos para segmentar despesas, receitas e obrigações fiscais.</p>
            </div>
        <?php else: ?>
            <div class="finance-table-wrapper">
                <table class="finance-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Código</th>
                            <th>Pai</th>
                            <th>Conta padrão</th>
                            <th>Status</th>
                            <th class="text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costCenters as $center): ?>
                            <?php $centerId = (int)($center['id'] ?? 0); ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($center['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($center['description'])): ?>
                                        <p class="finance-meta-line"><?= htmlspecialchars($center['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($center['code'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php $parentId = (int)($center['parent_id'] ?? 0); ?>
                                    <?= $parentId > 0 && isset($centerMap[$parentId]) ? htmlspecialchars($centerMap[$parentId]['name'] ?? '—', ENT_QUOTES, 'UTF-8') : '—'; ?>
                                </td>
                                <td>
                                    <?php $defaultId = (int)($center['default_account_id'] ?? 0); ?>
                                    <?= $defaultId > 0 && isset($accountsById[$defaultId]) ? htmlspecialchars($accountsById[$defaultId]['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8') : '—'; ?>
                                </td>
                                <td>
                                    <?php $isActive = ((int)($center['is_active'] ?? 1) === 1); ?>
                                    <span class="finance-pill <?= $isActive ? 'success' : 'negative'; ?>">
                                        <?= $isActive ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <div class="finance-inline-actions">
                                        <a class="ghost" href="<?= url('finance/cost-centers/' . $centerId . '/edit'); ?>">Editar</a>
                                        <form method="post" action="<?= url('finance/cost-centers/' . $centerId . '/delete'); ?>" onsubmit="return confirm('Remover este centro? Lançamentos ficarão sem referência.');">
                                            <?= csrf_field(); ?>
                                            <button class="ghost danger" type="submit">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
