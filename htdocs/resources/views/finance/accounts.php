<div class="finance-shell finance-page">
    <header class="finance-header finance-hero">
        <div>
            <h1>Contas & Lançamentos</h1>
            <p>Aqui centralizamos os saldos por conta e os últimos lançamentos importados/registrados manualmente. Utilize esta visão para validar conciliações rápidas antes de aprovar pagamentos.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance'); ?>">&larr; Voltar à Home Financeira</a>
            <a class="primary" href="<?= url('finance/accounts/manage'); ?>">Gerenciar cadastros</a>
        </div>
    </header>

    <?php if ($accounts === []): ?>
        <div class="finance-panel">
            <p class="finance-empty">Nenhuma conta cadastrada ainda. Crie registros via importador ou migre dados legados para visualizar nesta tela.</p>
        </div>
    <?php else: ?>
        <div class="account-grid">
            <?php foreach ($accounts as $account): ?>
                <div class="account-card">
                    <header>
                        <div>
                            <h3>
                                <?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <div class="account-meta">
                                <?= htmlspecialchars($account['institution'] ?? $account['account_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($account['account_number'])): ?>
                                    • <?= htmlspecialchars($account['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <span class="finance-pill default" style="display:inline-flex;align-items:center;gap:6px;">
                                <?= !empty($account['currency']) ? htmlspecialchars($account['currency'], ENT_QUOTES, 'UTF-8') : 'BRL'; ?>
                            </span>
                        </div>
                    </header>

                    <div class="account-balances">
                        <div class="account-balance">
                            <span>Saldo atual</span>
                            <strong><?= format_money((int)($account['current_balance'] ?? 0)); ?></strong>
                        </div>
                        <div class="account-balance">
                            <span>Disponível</span>
                            <strong><?= format_money((int)($account['available_balance'] ?? 0)); ?></strong>
                        </div>
                    </div>

                    <?php $recent = $transactionsByAccount[(int)$account['id']] ?? []; ?>
                    <div>
                        <p class="transaction-heading">Últimos lançamentos</p>
                        <?php if ($recent === []): ?>
                            <p class="finance-empty" style="font-size:0.85rem;">Sem lançamentos cadastrados.</p>
                        <?php else: ?>
                            <ul class="transaction-list">
                                <?php foreach ($recent as $transaction): ?>
                                    <li class="transaction-item">
                                        <strong><?= htmlspecialchars($transaction['description'] ?? 'Lançamento', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-weight:600;color:<?= (($transaction['transaction_type'] ?? 'debit') === 'credit') ? '#22c55e' : '#f87171'; ?>;">
                                            <?= ($transaction['transaction_type'] ?? 'debit') === 'credit' ? '+' : '-'; ?><?= format_money((int)($transaction['amount_cents'] ?? 0)); ?>
                                        </div>
                                        <footer>
                                            <span><?= format_date((int)($transaction['occurred_at'] ?? 0)); ?></span>
                                            <?php if (!empty($transaction['category'])): ?>
                                                <span><?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </footer>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
