<?php
$importOverview = $importOverview ?? ['ongoing' => 0, 'ready' => 0, 'failed' => 0, 'completed' => 0];
$importWatchlist = $importWatchlist ?? [];
$importSummary = $importSummary ?? [];
$cashflow = is_array($cashflow ?? null) ? $cashflow : ['series' => []];
$dreSummary = is_array($dreSummary ?? null) ? $dreSummary : ['periods' => [], 'totals' => ['revenue' => 0, 'expense' => 0, 'net' => 0], 'months' => 0];
$cashflowSeries = $cashflow['series'] ?? [];
$cashflowToday = (int)($cashflow['today'] ?? time());
$cashflowFuture = array_values(array_filter($cashflowSeries, static fn(array $row): bool => (int)($row['timestamp'] ?? 0) >= $cashflowToday));
$cashflowPreview = array_slice($cashflowFuture, 0, 8);
$cashflowEnding = (int)($cashflow['ending_balance'] ?? ($cashflow['starting_balance'] ?? 0));
$drePeriods = $dreSummary['periods'] ?? [];
?>
<div class="finance-shell finance-page">
    <header class="finance-header finance-hero">
        <div>
            <h1>Home Financeira</h1>
            <p>Primeiro panorama da operação: saldos consolidados, importações recentes e obrigações que vencem nos próximos dias. À medida que alimentarmos o módulo, os cards abaixo ganham gráficos e alertas automáticos.</p>
        </div>
        <div class="finance-actions">
            <a class="primary" href="<?= url('finance/accounts'); ?>">Ver contas</a>
            <a class="ghost" href="<?= url('finance/imports'); ?>">Importar extrato</a>
            <a class="ghost" href="<?= url('finance/calendar'); ?>">Calendário fiscal</a>
            <a class="ghost" href="<?= url('finance/accounts/manage'); ?>">Gerenciar cadastros</a>
        </div>
    </header>

    <section class="finance-section">
        <div class="finance-grid">
            <div class="finance-card">
                <span>Saldo atual consolidado</span>
                <strong><?= format_money($totals['current_balance'] ?? 0); ?></strong>
                <small><?= (int)($totals['accounts'] ?? 0); ?> contas rastreadas</small>
            </div>
            <div class="finance-card">
                <span>Saldo disponível</span>
                <strong><?= format_money($totals['available_balance'] ?? 0); ?></strong>
                <small>Considera bloqueios e provisões</small>
            </div>
            <div class="finance-card">
                <span>Importações em andamento</span>
                <strong><?= (int)($importOverview['ongoing'] ?? 0); ?></strong>
                <small><?= (int)($importOverview['ready'] ?? 0); ?> prontas para revisão</small>
            </div>
            <div class="finance-card">
                <span>Obrigações próximas</span>
                <strong><?= count($upcomingObligations); ?></strong>
                <small>Próximos 6 lembretes</small>
            </div>
        </div>
    </section>

    <section class="finance-section finance-panel">
        <h2>Contas monitoradas</h2>
        <?php if ($accounts === []): ?>
            <p class="finance-empty">Cadastre contas financeiras para acompanhar saldos e conciliações.</p>
        <?php else: ?>
            <div class="finance-grid">
                <?php foreach ($accounts as $account): ?>
                    <div class="finance-card flat">
                        <span><?= htmlspecialchars($account['display_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?></span>
                        <strong><?= format_money((int)($account['current_balance'] ?? 0)); ?></strong>
                        <small>Disponível: <?= format_money((int)($account['available_balance'] ?? 0)); ?></small>
                        <?php if (!empty($account['institution'])): ?>
                            <small>Instituição: <?= htmlspecialchars($account['institution'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($cashflowSeries !== []): ?>
        <section class="finance-section finance-panel">
            <div class="finance-panel-header">
                <div>
                    <h2>Projeção de fluxo de caixa</h2>
                    <p class="finance-muted">Considera lançamentos confirmados e faturas previstas para os próximos <?= (int)($cashflow['days_ahead'] ?? 21); ?> dias.</p>
                </div>
            </div>

            <div class="finance-grid">
                <div class="finance-card">
                    <span>Saldo atual</span>
                    <strong><?= format_money((int)($cashflow['starting_balance'] ?? 0)); ?></strong>
                    <small>Baseado em <?= (int)count($accounts); ?> contas</small>
                </div>
                <div class="finance-card">
                    <span>Saldo projetado (<?= (int)($cashflow['days_ahead'] ?? 21); ?> dias)</span>
                    <strong style="color:<?= $cashflowEnding < 0 ? '#f87171' : 'var(--text)'; ?>;">
                        <?= format_money($cashflowEnding); ?>
                    </strong>
                    <small>Recebimentos previstos menos pagamentos planejados</small>
                </div>
            </div>

            <?php if ($cashflowPreview === []): ?>
                <p class="finance-empty">Nenhum dado futuro disponível ainda. Cadastre contas a receber/pagar para alimentar a projeção.</p>
            <?php else: ?>
                <div class="finance-table-wrapper">
                    <table class="finance-table">
                    <thead>
                        <tr>
                            <th style="min-width:90px;">Dia</th>
                            <th>Entradas confirmadas</th>
                            <th>Saídas confirmadas</th>
                            <th>Recebimentos previstos</th>
                            <th>Pagamentos previstos</th>
                            <th>Saldo projetado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashflowPreview as $entry): ?>
                            <?php $projected = $entry['projected_balance'] ?? null; ?>
                            <tr>
                                <td><?= format_date((int)($entry['timestamp'] ?? 0), 'd/m'); ?></td>
                                <td><?= format_money((int)($entry['actual_inflow'] ?? 0)); ?></td>
                                <td><?= format_money((int)($entry['actual_outflow'] ?? 0)); ?></td>
                                <td><?= format_money((int)($entry['expected_receivables'] ?? 0)); ?></td>
                                <td><?= format_money((int)($entry['expected_payables'] ?? 0)); ?></td>
                                <td style="color:<?= ($projected ?? 0) < 0 ? '#f87171' : 'var(--text)'; ?>;">
                                    <?= $projected !== null ? format_money((int)$projected) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="finance-section finance-panel">
        <div class="finance-panel-header">
            <h2>Próximas obrigações</h2>
            <a class="ghost" href="<?= url('finance/calendar'); ?>">Abrir calendário &rarr;</a>
        </div>
        <?php if ($upcomingObligations === []): ?>
            <p class="finance-empty">Nenhuma obrigação pendente. Configure lembretes em breve.</p>
        <?php else: ?>
            <div class="finance-table-wrapper">
                <table class="finance-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Vencimento</th>
                    <th>Valor estimado</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcomingObligations as $obligation): ?>
                    <tr>
                        <td><?= htmlspecialchars($obligation['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if (!empty($obligation['due_date'])): ?>
                                <?= format_date((int)$obligation['due_date']); ?>
                            <?php elseif (!empty($obligation['due_day'])): ?>
                                Dia <?= str_pad((string)$obligation['due_day'], 2, '0', STR_PAD_LEFT); ?> de cada mês
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= $obligation['amount_estimate'] !== null ? format_money((int)$obligation['amount_estimate']) : '—'; ?></td>
                        <td>
                            <span class="finance-pill <?= ($obligation['status'] ?? '') === 'pending' ? 'warning' : ''; ?>">
                                <?= htmlspecialchars((string)($obligation['status'] ?? 'pendente'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="finance-section">
        <div class="finance-grid-wide">
            <div class="finance-panel">
                <div class="finance-panel-header">
                    <h2>Lançamentos recentes</h2>
                    <a class="ghost" href="<?= url('finance/accounts'); ?>">Ver todos</a>
                </div>
                <?php if ($recentTransactions === []): ?>
                    <p class="finance-empty">Ainda não há lançamentos registrados.</p>
                <?php else: ?>
                    <ul class="finance-list spaced">
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <li>
                                <div style="font-weight:600;"><?= htmlspecialchars($transaction['description'] ?? 'Lançamento', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="finance-meta-line finance-muted">
                                    <span><?= htmlspecialchars($transaction['account_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?= format_date((int)($transaction['occurred_at'] ?? 0)); ?></span>
                                </div>
                                <div style="margin-top:6px;font-weight:600;color:<?= (($transaction['transaction_type'] ?? 'debit') === 'credit') ? '#22c55e' : '#f87171'; ?>;">
                                    <?= ($transaction['transaction_type'] ?? 'debit') === 'credit' ? '+' : '-'; ?><?= format_money((int)($transaction['amount_cents'] ?? 0)); ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="finance-panel">
                <div class="finance-panel-header">
                    <h2>Importações de extrato</h2>
                </div>
                <div class="finance-grid">
                    <div class="finance-card flat">
                <span>Em andamento</span>
                <strong><?= (int)($importOverview['ongoing'] ?? 0); ?></strong>
                <small>Somatório de pendentes + processamento</small>
            </div>
                    <div class="finance-card flat">
                <span>Prontas para importar</span>
                <strong><?= (int)($importOverview['ready'] ?? 0); ?></strong>
                <small>Use a fila para revisar e lançar</small>
            </div>
                    <div class="finance-card flat">
                <span>Falhas detectadas</span>
                <strong style="color:<?= (int)($importOverview['failed'] ?? 0) > 0 ? '#f87171' : 'var(--text)'; ?>;">
                    <?= (int)($importOverview['failed'] ?? 0); ?>
                </strong>
                <small><?= (int)($importSummary['completed'] ?? 0); ?> lotes concluídos neste mês</small>
            </div>
        </div>

        <?php if ($importWatchlist !== []): ?>
                    <div class="finance-feedback warning spaced">
                        <strong>Atenção com estes lotes:</strong>
                        <ul class="finance-list no-margin">
                            <?php foreach (array_slice($importWatchlist, 0, 3) as $batch): ?>
                                <li class="finance-list__row">
                                    <span>
                                        <?= htmlspecialchars($batch['filename'] ?? 'upload', ENT_QUOTES, 'UTF-8'); ?>
                                        &middot;
                                        <?= htmlspecialchars($batch['account_name'] ?? 'Conta', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <span class="finance-pill <?= ($batch['status'] ?? '') === 'failed' ? 'negative' : 'warning'; ?>">
                                        <?= htmlspecialchars((string)($batch['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <a class="ghost" href="<?= url('finance/imports/' . (int)($batch['id'] ?? 0)); ?>">Abrir lote</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
        <?php endif; ?>

        <?php if ($recentImports === []): ?>
                    <p class="finance-empty spaced">Nenhuma importação registrada. <a href="<?= url('finance/imports/create'); ?>">Envie o primeiro extrato</a> para alimentar os lançamentos.</p>
        <?php else: ?>
                        <div class="finance-table-wrapper spaced">
                            <table class="finance-table">
                <thead>
                    <tr>
                        <th>Arquivo</th>
                        <th>Conta</th>
                        <th>Status</th>
                        <th>Processado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentImports as $batch): ?>
                        <tr>
                            <td><?= htmlspecialchars($batch['filename'] ?? 'upload.csv', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($batch['account_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="finance-pill <?= ($batch['status'] ?? '') === 'failed' ? 'negative' : ((($batch['status'] ?? '') === 'processing') ? 'warning' : ''); ?>">
                                    <?= htmlspecialchars((string)($batch['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?= (int)($batch['processed_rows'] ?? 0); ?>/<?= (int)($batch['total_rows'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
        <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($drePeriods !== []): ?>
        <section class="finance-section finance-panel">
            <div class="finance-panel-header">
                <div>
                    <h2>DRE resumida</h2>
                    <p class="finance-muted">Resumo mensal das receitas x despesas dos últimos <?= (int)($dreSummary['months'] ?? count($drePeriods)); ?> meses.</p>
                </div>
            </div>

            <div class="finance-table-wrapper">
                <table class="finance-table" style="min-width:520px;">
                <thead>
                    <tr>
                        <th>Mês</th>
                        <th>Receitas</th>
                        <th>Despesas</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drePeriods as $period): ?>
                        <?php $net = (int)($period['net'] ?? 0); ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($period['label'] ?? 'Mês'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= format_money((int)($period['revenue'] ?? 0)); ?></td>
                            <td><?= format_money((int)($period['expense'] ?? 0)); ?></td>
                            <td style="color:<?= $net < 0 ? '#f87171' : '#22c55e'; ?>;font-weight:600;">
                                <?= format_money($net); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php $dreTotals = $dreSummary['totals'] ?? ['revenue' => 0, 'expense' => 0, 'net' => 0]; ?>
                    <tr>
                        <th>Total</th>
                        <th><?= format_money((int)($dreTotals['revenue'] ?? 0)); ?></th>
                        <th><?= format_money((int)($dreTotals['expense'] ?? 0)); ?></th>
                        <?php $netTotal = (int)($dreTotals['net'] ?? 0); ?>
                        <th style="color:<?= $netTotal < 0 ? '#f87171' : '#22c55e'; ?>;">
                            <?= format_money($netTotal); ?>
                        </th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    <?php endif; ?>

</div>
