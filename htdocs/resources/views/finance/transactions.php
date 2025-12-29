<?php
$feedback = $feedback ?? null;
$costCenterMap = $costCenterMap ?? [];
$accountMap = $accountMap ?? [];
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1>Lançamentos manuais</h1>
            <p>Registre ajustes rápidos, antecipações ou despesas extraordinárias. Cada lançamento recalcula automaticamente o saldo das contas vinculadas.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance'); ?>">&larr; Voltar</a>
            <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Contas</a>
            <a class="ghost" href="<?= url('finance/cost-centers'); ?>">Centros de custo</a>
            <a class="primary" href="<?= url('finance/transactions/create'); ?>">+ Novo lançamento</a>
        </div>
    </header>

    <?php if ($feedback !== null): ?>
        <div class="finance-feedback <?= ($feedback['type'] ?? '') === 'success' ? 'success' : 'warning'; ?>">
            <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    <?php endif; ?>

    <div class="finance-panel">
        <?php if ($transactions === []): ?>
            <p class="finance-empty">Nenhum lançamento manual foi registrado. Utilize o botão acima para começar.</p>
        <?php else: ?>
            <div class="finance-table-wrapper">
                <table class="finance-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Conta</th>
                    <th>Centro</th>
                    <th>Tipo</th>
                    <th>Valor</th>
                    <th class="actions">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <?php
                        $type = $transaction['transaction_type'] ?? 'debit';
                        $costCenterId = (int)($transaction['cost_center_id'] ?? 0);
                        $accountName = $transaction['account_name'] ?? ($accountMap[(int)($transaction['account_id'] ?? 0)]['display_name'] ?? 'Conta');
                    ?>
                    <tr>
                        <td><?= format_datetime((int)($transaction['occurred_at'] ?? 0)); ?></td>
                        <td>
                            <strong><?= htmlspecialchars($transaction['description'] ?? 'Lançamento', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                            <small class="finance-muted">Ref: <?= htmlspecialchars($transaction['reference'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> · Categoria: <?= htmlspecialchars($transaction['category'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td><?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= $costCenterId > 0 && isset($costCenterMap[$costCenterId]) ? htmlspecialchars($costCenterMap[$costCenterId]['name'] ?? 'Centro', ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <span class="finance-pill <?= $type === 'credit' ? 'success' : 'negative'; ?>">
                                <?= $type === 'credit' ? 'Crédito' : 'Débito'; ?>
                            </span>
                        </td>
                        <td class="<?= $type === 'credit' ? 'finance-amount-positive' : 'finance-amount-negative'; ?>">
                            <?= $type === 'credit' ? '+' : '-'; ?><?= format_money((int)($transaction['amount_cents'] ?? 0)); ?>
                        </td>
                        <td class="actions">
                            <div class="finance-inline-actions">
                                <a class="ghost" href="<?= url('finance/transactions/' . (int)($transaction['id'] ?? 0) . '/edit'); ?>">Editar</a>
                                <form method="post" action="<?= url('finance/transactions/' . (int)($transaction['id'] ?? 0) . '/delete'); ?>" onsubmit="return confirm('Excluir este lançamento? O saldo será recalculado.');">
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
