<?php
$account = $account ?? [];
$errors = $errors ?? [];
$mode = $mode ?? 'create';
$action = $action ?? '';
$title = $title ?? 'Conta financeira';
$types = [
    'bank' => 'Banco / Corrente',
    'cash' => 'Caixa',
    'investment' => 'Investimento',
    'credit' => 'Cartão / Crédito',
];
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Preencha os campos abaixo para manter o saldo de referência e facilitar conciliações. Todas as informações podem ser ajustadas posteriormente.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Cancelar</a>
        </div>
    </header>

    <div class="finance-panel">
        <form action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" class="finance-form">
            <?= csrf_field(); ?>
            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['display_name']) ? 'has-error' : ''; ?>">
                    <span>Nome da conta *</span>
                    <input type="text" name="display_name" value="<?= htmlspecialchars($account['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <?php if (isset($errors['display_name'])): ?><small class="finance-error"><?= htmlspecialchars($errors['display_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field">
                    <span>Instituição</span>
                    <input type="text" name="institution" value="<?= htmlspecialchars($account['institution'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="finance-field">
                    <span>Número / Agência</span>
                    <input type="text" name="account_number" value="<?= htmlspecialchars($account['account_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
            </div>

            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['account_type']) ? 'has-error' : ''; ?>">
                    <span>Tipo *</span>
                    <select name="account_type">
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= (($account['account_type'] ?? 'bank') === $value) ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="finance-field">
                    <span>Moeda</span>
                    <input class="finance-uppercase" type="text" name="currency" maxlength="3" value="<?= htmlspecialchars($account['currency'] ?? 'BRL', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="finance-field <?= isset($errors['color']) ? 'has-error' : ''; ?>">
                    <span>Cor</span>
                    <input type="color" name="color" value="<?= htmlspecialchars($account['color'] ?? '#38bdf8', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['color'])): ?><small class="finance-error"><?= htmlspecialchars($errors['color'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <div class="finance-form-row">
                <label class="finance-field <?= isset($errors['initial_balance']) ? 'has-error' : ''; ?>">
                    <span>Saldo inicial</span>
                    <input type="text" name="initial_balance" value="<?= htmlspecialchars($account['initial_balance'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="0,00">
                    <?php if (isset($errors['initial_balance'])): ?><small class="finance-error"><?= htmlspecialchars($errors['initial_balance'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($errors['current_balance']) ? 'has-error' : ''; ?>">
                    <span>Saldo atual *</span>
                    <input type="text" name="current_balance" value="<?= htmlspecialchars($account['current_balance'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="0,00">
                    <?php if (isset($errors['current_balance'])): ?><small class="finance-error"><?= htmlspecialchars($errors['current_balance'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
                <label class="finance-field <?= isset($errors['available_balance']) ? 'has-error' : ''; ?>">
                    <span>Saldo disponível *</span>
                    <input type="text" name="available_balance" value="<?= htmlspecialchars($account['available_balance'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="0,00">
                    <?php if (isset($errors['available_balance'])): ?><small class="finance-error"><?= htmlspecialchars($errors['available_balance'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </label>
            </div>

            <div class="finance-form-row">
                <label class="finance-field inline">
                    <input type="hidden" name="is_primary" value="0">
                    <input type="checkbox" name="is_primary" value="1" <?= (($account['is_primary'] ?? '0') === '1') ? 'checked' : ''; ?>>
                    Marcar como conta principal
                </label>
                <label class="finance-field inline">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" <?= (($account['is_active'] ?? '1') === '1') ? 'checked' : ''; ?>>
                    Conta ativa
                </label>
            </div>

            <div class="finance-inline-actions">
                <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Cancelar</a>
                <button class="primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>
