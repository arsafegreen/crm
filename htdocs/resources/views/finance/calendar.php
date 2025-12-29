<?php
$grouped = [];
foreach ($obligations as $obligation) {
    $key = 'Sem data definida';
    if (!empty($obligation['due_date'])) {
        $key = date('F Y', (int)$obligation['due_date']);
    } elseif (!empty($obligation['due_day'])) {
        $key = 'Todo dia ' . str_pad((string)$obligation['due_day'], 2, '0', STR_PAD_LEFT);
    }
    $grouped[$key] = $grouped[$key] ?? [];
    $grouped[$key][] = $obligation;
}
ksort($grouped);
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1>Calendário fiscal</h1>
            <p>Filtramos obrigações com status pendente ou agendado e agrupamos por período. Ao cadastrar novos CNAEs e estados, a timeline é preenchida automaticamente.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance'); ?>">&larr; Voltar à home financeira</a>
        </div>
    </header>

    <div class="finance-grid">
        <div class="finance-card flat">
            <span>Pendências</span>
            <strong><?= (int)($statusTotals['pending'] ?? 0); ?></strong>
        </div>
        <div class="finance-card flat">
            <span>Agendadas</span>
            <strong><?= (int)($statusTotals['scheduled'] ?? 0); ?></strong>
        </div>
        <div class="finance-card flat">
            <span>Pagas (últimos 90 dias)</span>
            <strong><?= (int)($statusTotals['paid'] ?? 0); ?></strong>
        </div>
    </div>

    <div class="finance-panel">
        <div class="finance-panel-header">
            <div>
                <p class="finance-meta">Timeline</p>
                <h2>Obrigações por período</h2>
            </div>
            <p class="finance-muted">Monitoramos vencimentos para você antecipar provisionamentos e manter tributos em dia.</p>
        </div>

        <?php if ($grouped === []): ?>
            <div class="finance-empty">
                <p>Ainda não há obrigações cadastradas.</p>
                <p class="finance-muted">Assim que criarmos regras por CNAE, o calendário será preenchido automaticamente.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $period => $items): ?>
                <section class="finance-section">
                    <div class="finance-panel-header">
                        <div>
                            <p class="finance-meta">Período</p>
                            <h3><?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?></h3>
                        </div>
                        <p class="finance-muted">Exibindo <?= count($items); ?> obrigação(ões)</p>
                    </div>
                    <div class="finance-table-wrapper">
                        <table class="finance-table">
                            <thead>
                                <tr>
                                    <th>Obrigação</th>
                                    <th>Tipo</th>
                                    <th>Vencimento</th>
                                    <th>Valor estimado</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php $status = strtolower((string)($item['status'] ?? 'pending')); ?>
                                    <?php
                                        $statusClass = 'warning';
                                        if ($status === 'scheduled') {
                                            $statusClass = 'default';
                                        } elseif ($status === 'paid') {
                                            $statusClass = 'success';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars($item['periodicity'] ?? 'mensal', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if (!empty($item['due_date'])): ?>
                                                <?= format_date((int)$item['due_date']); ?>
                                            <?php elseif (!empty($item['due_day'])): ?>
                                                Dia <?= str_pad((string)$item['due_day'], 2, '0', STR_PAD_LEFT); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $item['amount_estimate'] !== null ? format_money((int)$item['amount_estimate']) : '—'; ?></td>
                                        <td>
                                            <span class="finance-pill <?= $statusClass; ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
