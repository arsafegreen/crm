<div class="finance-shell finance-page">
<header class="finance-header">
    <div>
        <h1>Gestão de contas financeiras</h1>
        <p>Mantenha o cadastro de contas, defina saldos de referência e organize quais centros de custo apontam para cada instituição. Estas informações são usadas nos lançamentos manuais, importadores e alertas de liquidez.</p>
    </div>
    <div class="finance-actions">
        <a class="ghost" href="<?= url('finance'); ?>">&larr; Voltar</a>
        <a class="ghost" href="<?= url('finance/cost-centers'); ?>">Centros de custo</a>
        <a class="ghost" href="<?= url('finance/transactions'); ?>">Lançamentos</a>
        <a class="primary" href="<?= url('finance/accounts/create'); ?>">+ Nova conta</a>
    </div>
</header>

<?php if (!empty($feedback)): ?>
    <div class="finance-feedback <?= ($feedback['type'] ?? '') === 'success' ? 'success' : 'warning'; ?>">
        <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <div class="finance-panel">
        <p class="finance-empty">Nenhuma conta foi cadastrada ainda. Utilize o botão acima para configurar o primeiro banco ou caixa.</p>
        <div class="finance-inline-actions">
            <a class="primary" href="<?= url('finance/accounts/create'); ?>">Cadastrar conta</a>
        </div>
    </div>
<?php else: ?>
    <div class="finance-panel">
        <div class="finance-table-wrapper">
            <table class="finance-table">
            <thead>
                <tr>
                    <th>Conta</th>
                    <th>Instituição</th>
                    <th>Tipo</th>
                    <th>Saldo atual</th>
                    <th>Disponível</th>
                    <th>Status</th>
                    <th class="actions">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                            <small class="finance-muted">#<?= (int)$account['id']; ?> · <?= htmlspecialchars(strtoupper((string)($account['currency'] ?? 'BRL')), ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td><?= htmlspecialchars($account['institution'] ?? '—', ENT_QUOTES, 'UTF-8'); ?><br><small class="finance-muted"><?= htmlspecialchars($account['account_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                        <td><?= htmlspecialchars($account['account_type'] ?? 'bank', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= format_money((int)($account['current_balance'] ?? 0)); ?></td>
                        <td><?= format_money((int)($account['available_balance'] ?? 0)); ?></td>
                        <td>
                            <?php $isActive = (int)($account['is_active'] ?? 1) === 1; ?>
                            <span class="finance-pill <?= $isActive ? 'success' : 'negative'; ?>">
                                <?= $isActive ? 'Ativa' : 'Inativa'; ?>
                                <?php if ((int)($account['is_primary'] ?? 0) === 1): ?>
                                    · principal
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="actions">
                            <div class="finance-inline-actions">
                                <a class="ghost" href="<?= url('finance/accounts/' . (int)$account['id'] . '/edit'); ?>">Editar</a>
                                <form method="post" action="<?= url('finance/accounts/' . (int)$account['id'] . '/delete'); ?>" onsubmit="return confirm('Remover esta conta e seus lançamentos?');">
                                    <?= csrf_field(); ?>
                                    <button type="submit" class="ghost danger">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>
