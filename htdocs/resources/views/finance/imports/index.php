<?php
$batches = $batches ?? [];
$summary = $summary ?? [];
$statusFilter = $statusFilter ?? '';
$feedback = $feedback ?? null;
$pagination = $pagination ?? ['page' => 1, 'perPage' => 20, 'hasMore' => false];
$statusLabels = [
    'pending' => 'Pendente',
    'ready' => 'Pronto',
    'processing' => 'Processando',
    'importing' => 'Importando',
    'completed' => 'Concluído',
    'failed' => 'Falhou',
    'canceled' => 'Cancelado',
];
?>

<header>
    <div>
        <h1 style="margin-bottom:8px;">Importações de extrato</h1>
        <p style="color:var(--muted);max-width:720px;">Controle todos os lotes enviados, acompanhe o progresso do parser e identifique falhas rapidamente. Os lotes permanecem disponíveis para auditoria e reprocessamento.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('finance'); ?>">Voltar para a home financeira</a>
        <a class="primary" href="<?= url('finance/imports/create'); ?>">Nova importação</a>
    </div>
</header>
    $statusPills = [
        'pending' => 'warning',
        'processing' => 'warning',
        'importing' => 'default',
        'ready' => 'default',
        'completed' => 'success',
        'failed' => 'negative',
        'canceled' => 'neutral',
    ];
    $summaryKeys = ['pending', 'ready', 'processing', 'failed', 'completed'];


    <div class="finance-shell finance-page">
        <header class="finance-header">
            <div>
                <h1>Importações de extrato</h1>
                <p>Controle todos os lotes enviados, acompanhe o parser e identifique falhas rapidamente. Tudo fica disponível para auditoria e reprocessamento.</p>
            </div>
            <div class="finance-actions">
                <a class="ghost" href="<?= url('finance'); ?>">Voltar</a>
                <a class="primary" href="<?= url('finance/imports/create'); ?>">Nova importação</a>
            </div>
        </header>

        <?php if ($feedback !== null): ?>
            <div class="finance-feedback <?= $feedback['type'] === 'success' ? 'success' : ($feedback['type'] === 'warning' ? 'warning' : 'error'); ?>">
                <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        <?php endif; ?>

        <div class="finance-grid">
            <?php foreach ($summaryKeys as $key): ?>
                <div class="finance-card flat">
                    <span><?= htmlspecialchars($statusLabels[$key] ?? ucfirst($key), ENT_QUOTES, 'UTF-8'); ?></span>
                    <strong><?= (int)($summary[$key] ?? 0); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="finance-panel">
            <div class="finance-panel-header">
                <div>
                    <p class="finance-meta">Histórico</p>
                    <h2>Lotes processados</h2>
                </div>
                <p class="finance-muted">Filtre por status para localizar rapidamente falhas ou lotes prontos.</p>
            </div>

            <form method="get" class="finance-form">
                <div class="finance-form-row">
                    <label class="finance-field">
                        <span>Filtrar por status</span>
                        <select name="status">
                            <option value="">Todos</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= $statusFilter === $value ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="finance-inline-actions">
                    <button class="ghost" type="submit">Aplicar</button>
                    <?php if ($statusFilter !== ''): ?>
                        <a class="ghost" href="<?= url('finance/imports'); ?>">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($batches === []): ?>
                <div class="finance-empty spaced">
                    <p>Ainda não há importações registradas.</p>
                    <p class="finance-muted">Faça upload de um extrato para iniciar o histórico.</p>
                </div>
            <?php else: ?>
                <div class="finance-table-wrapper spaced">
                    <table class="finance-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <th>Conta</th>
                                <th>Status</th>
                                <th>Linhas</th>
                                <th>Atualizado</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $batch): ?>
                                <?php $status = $batch['status'] ?? 'pending'; ?>
                                <tr>
                                    <td><?= htmlspecialchars($batch['filename'] ?? 'upload', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($batch['account_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="finance-pill <?= $statusPills[$status] ?? 'neutral'; ?>">
                                            <?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?= (int)($batch['processed_rows'] ?? 0); ?>/<?= (int)($batch['total_rows'] ?? 0); ?></td>
                                    <td><?= !empty($batch['updated_at']) ? format_datetime((int)$batch['updated_at']) : '—'; ?></td>
                                    <td class="text-right">
                                        <a href="<?= url('finance/imports/' . (int)$batch['id']); ?>" class="ghost">Detalhes</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="finance-table-footer">
                    <span class="finance-muted">Página <?= (int)$pagination['page']; ?></span>
                    <div class="finance-inline-actions">
                        <?php if (($pagination['page'] ?? 1) > 1): ?>
                            <a class="ghost" href="<?= url('finance/imports') . '?page=' . ((int)$pagination['page'] - 1) . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''); ?>">Anterior</a>
                        <?php endif; ?>
                        <?php if (!empty($pagination['hasMore'])): ?>
                            <a class="ghost" href="<?= url('finance/imports') . '?page=' . ((int)$pagination['page'] + 1) . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''); ?>">Próxima</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
