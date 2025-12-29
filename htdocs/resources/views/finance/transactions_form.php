<?php
$transaction = $transaction ?? [];
$errors = $errors ?? [];
$mode = $mode ?? 'create';
$title = $title ?? 'Lançamento manual';
$action = $action ?? '';
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Utilize este formulário para registrar ajustes que não vieram de importações. Defina valores positivos (crédito) ou negativos (débito) com precisão e mantenha a conciliação sempre em dia.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance/transactions'); ?>">&larr; Voltar</a>
            <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Contas</a>
            <a class="ghost" href="<?= url('finance/cost-centers'); ?>">Centros de custo</a>
        </div>
    </header>

    <div class="finance-panel">
        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" class="finance-form">
            <?= csrf_field(); ?>

            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['account_id']) ? 'has-error' : ''; ?>">
                    <span>Conta *</span>
                    <select name="account_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int)$account['id']; ?>" <?= ((string)$transaction['account_id'] ?? '') === (string)$account['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($account['display_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['account_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['account_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>

                <label class="finance-field <?= isset($errors['cost_center_id']) ? 'has-error' : ''; ?>">
                    <span>Centro de custo</span>
                    <select name="cost_center_id">
                        <option value="">Não vincular</option>
                        <?php foreach ($costCenters as $center): ?>
                            <option value="<?= (int)$center['id']; ?>" <?= ((string)$transaction['cost_center_id'] ?? '') === (string)$center['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($center['code'] . ' · ' . $center['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['cost_center_id'])): ?><small class="finance-error"><?= htmlspecialchars($errors['cost_center_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>

                <label class="finance-field">
                    <span>Categoria</span>
                    <input type="text" name="category" value="<?= htmlspecialchars($transaction['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: Ajuste, Tarifa, Comissão">
                </label>

                <label class="finance-field">
                    <span>Referência interna</span>
                    <input type="text" name="reference" value="<?= htmlspecialchars($transaction['reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="ID da fatura, pedido...">
                </label>
            </div>

            <label class="finance-field <?= isset($errors['description']) ? 'has-error' : ''; ?>">
                <span>Descrição *</span>
                <input type="text" name="description" required value="<?= htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Informe um resumo claro do lançamento">
                <?php if (isset($errors['description'])): ?><small class="finance-error"><?= htmlspecialchars($errors['description'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </label>

            <div class="finance-form-row">
                <div class="finance-field <?= isset($errors['transaction_type']) ? 'has-error' : ''; ?>">
                    <span>Tipo *</span>
                    <div class="finance-choice-group">
                        <?php $types = ['debit' => 'Débito (saída)', 'credit' => 'Crédito (entrada)']; ?>
                        <?php foreach ($types as $value => $label): ?>
                            <label class="finance-choice">
                                <input type="radio" name="transaction_type" value="<?= $value; ?>" <?= (($transaction['transaction_type'] ?? 'debit') === $value) ? 'checked' : ''; ?>>
                                <?= $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($errors['transaction_type'])): ?><small class="finance-error"><?= htmlspecialchars($errors['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </div>

                <label class="finance-field <?= isset($errors['amount']) ? 'has-error' : ''; ?>">
                    <span>Valor *</span>
                    <input type="text" name="amount" required inputmode="decimal" value="<?= htmlspecialchars($transaction['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="0,00">
                    <?php if (isset($errors['amount'])): ?><small class="finance-error"><?= htmlspecialchars($errors['amount'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>

                <label class="finance-field <?= isset($errors['occurred_at']) ? 'has-error' : ''; ?>">
                    <span>Data e hora *</span>
                    <input type="datetime-local" name="occurred_at" required value="<?= htmlspecialchars($transaction['occurred_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['occurred_at'])): ?><small class="finance-error"><?= htmlspecialchars($errors['occurred_at'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <div class="finance-inline-actions">
                <a class="ghost" href="<?= url('finance/transactions'); ?>">Cancelar</a>
                <button class="primary" type="submit"><?= $mode === 'edit' ? 'Atualizar lançamento' : 'Registrar lançamento'; ?></button>
            </div>
        </form>
    </div>
</div>
