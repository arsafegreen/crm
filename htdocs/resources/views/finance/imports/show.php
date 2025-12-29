<?php
$batch = $batch ?? [];
$rows = $rows ?? [];
$events = $events ?? [];
$feedback = $feedback ?? null;
$canRetry = $canRetry ?? false;
$canCancel = $canCancel ?? false;
$statusLabels = [
    'pending' => 'Pendente',
    'ready' => 'Pronto para importar',
    'processing' => 'Processando',
    'importing' => 'Importando',
    'completed' => 'Concluído',
    'failed' => 'Falhou',
    'canceled' => 'Cancelado',
];
$statusPills = [
    'failed' => 'negative',
    'processing' => 'warning',
    'pending' => 'warning',
];
$metadata = [];
if (!empty($batch['metadata'])) {
    $decoded = json_decode((string)$batch['metadata'], true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}
$rowCounters = [
    'pending' => max(0, (int)($batch['total_rows'] ?? 0) - (int)($batch['processed_rows'] ?? 0)),
    'valid' => (int)($batch['valid_rows'] ?? 0),
    'invalid' => (int)($batch['invalid_rows'] ?? 0),
    'imported' => (int)($batch['imported_rows'] ?? 0),
];
$rowCounterLabels = [
    'pending' => 'Aguardando parser',
    'valid' => 'Validadas',
    'invalid' => 'Com erro',
    'imported' => 'Inseridas',
];
?>

<div class="finance-shell finance-page">
    <header class="finance-header">
        <div>
            <h1>Lote #<?= (int)($batch['id'] ?? 0); ?></h1>
            <p>Arquivo <?= htmlspecialchars($batch['filename'] ?? 'upload', ENT_QUOTES, 'UTF-8'); ?> enviado para a conta <?= htmlspecialchars($batch['account_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>
        <div class="finance-actions">
            <a class="ghost" href="<?= url('finance/imports'); ?>">Voltar</a>
            <?php if ($canCancel): ?>
                <form action="<?= url('finance/imports/' . (int)$batch['id'] . '/cancel'); ?>" method="post" onsubmit="return confirm('Cancelar o processamento deste lote?');">
                    <?= csrf_field(); ?>
                    <button class="ghost" type="submit">Cancelar lote</button>
                </form>
            <?php endif; ?>
            <?php if ($canRetry): ?>
                <form action="<?= url('finance/imports/' . (int)$batch['id'] . '/retry'); ?>" method="post" onsubmit="return confirm('Reabrir o lote para nova tentativa?');">
                    <?= csrf_field(); ?>
                    <button class="primary" type="submit">Reprocessar</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($feedback !== null): ?>
        <div class="finance-feedback <?= $feedback['type'] === 'success' ? 'success' : ($feedback['type'] === 'warning' ? 'warning' : 'error'); ?>">
            <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    <?php endif; ?>

    <div class="finance-grid">
        <div class="finance-card flat">
            <span>Status atual</span>
            <div class="finance-badge-group">
                <span class="finance-pill <?= $statusPills[$batch['status'] ?? ''] ?? 'default'; ?>">
                    <?= htmlspecialchars($statusLabels[$batch['status'] ?? 'pending'] ?? (string)($batch['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
        <div class="finance-card flat">
            <span>Linhas processadas</span>
            <strong><?= (int)($batch['processed_rows'] ?? 0); ?>/<?= (int)($batch['total_rows'] ?? 0); ?></strong>
        </div>
        <div class="finance-card flat">
            <span>Criado em</span>
            <strong><?= !empty($batch['created_at']) ? format_datetime((int)$batch['created_at']) : '—'; ?></strong>
        </div>
        <div class="finance-card flat">
            <span>Atualizado em</span>
            <strong><?= !empty($batch['updated_at']) ? format_datetime((int)$batch['updated_at']) : '—'; ?></strong>
        </div>
    </div>

    <div class="finance-panel">
        <div class="finance-panel-header">
            <div>
                <p class="finance-meta">Contexto</p>
                <h2>Configuração e progresso</h2>
            </div>
            <p class="finance-muted">Detalhamos os parâmetros do upload e a contagem de linhas por status.</p>
        </div>

        <div class="finance-grid-wide">
            <div>
                <p class="finance-meta">Parâmetros do upload</p>
                <div class="finance-summary-grid">
                    <div class="finance-summary__item">
                        <p class="finance-muted">Formato</p>
                        <strong><?= htmlspecialchars($metadata['file_type'] ?? 'ofx', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="finance-summary__item">
                        <p class="finance-muted">Timezone</p>
                        <strong><?= htmlspecialchars($metadata['timezone'] ?? 'America/Sao_Paulo', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="finance-summary__item">
                        <p class="finance-muted">Duplicados</p>
                        <strong><?= htmlspecialchars($metadata['duplicate_strategy'] ?? 'ignore', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="finance-summary__item">
                        <p class="finance-muted">Centro padrão</p>
                        <strong><?= htmlspecialchars((string)($metadata['default_cost_center_id'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>
            </div>
            <div>
                <p class="finance-meta">Resumo das linhas</p>
                <div class="finance-summary-grid">
                    <?php foreach ($rowCounters as $key => $value): ?>
                        <div class="finance-summary__item">
                            <p class="finance-muted"><?= htmlspecialchars($rowCounterLabels[$key] ?? ucfirst($key), ENT_QUOTES, 'UTF-8'); ?></p>
                            <strong><?= (int)$value; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php
    $rowSections = [
        'pending' => 'Aguardando parser',
        'valid' => 'Prontas para importar',
        'invalid' => 'Com erros',
        'imported' => 'Já inseridas',
    ];
    ?>

    <section class="finance-section">
        <?php foreach ($rowSections as $key => $title): ?>
            <?php $items = $rows[$key] ?? []; ?>
            <div class="finance-panel">
                <div class="finance-panel-header">
                    <div>
                        <p class="finance-meta">Linhas</p>
                        <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
                    </div>
                    <?php if ($key === 'valid'): ?>
                        <form action="<?= url('finance/imports/' . (int)$batch['id'] . '/rows/import'); ?>" method="post" class="finance-inline-actions">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="mode" value="all">
                            <label class="finance-field inline">
                                <input type="checkbox" name="override_duplicates" value="1">
                                Ignorar duplicados
                            </label>
                            <button class="primary" type="submit" <?= ($rowCounters['valid'] ?? 0) === 0 ? 'disabled' : ''; ?>>Importar todas</button>
                        </form>
                    <?php else: ?>
                        <p class="finance-muted">Exibindo até 25 registros recentes</p>
                    <?php endif; ?>
                </div>

                <?php if ($items === []): ?>
                    <div class="finance-empty">
                        <p>Nenhuma linha com este status.</p>
                    </div>
                <?php else: ?>
                    <?php if ($key === 'valid'): ?>
                        <form id="import-selected-form" action="<?= url('finance/imports/' . (int)$batch['id'] . '/rows/import'); ?>" method="post" class="finance-bulk-bar">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="mode" value="selected">
                            <strong>Importar selecionadas</strong>
                            <label class="finance-field inline">
                                <input type="checkbox" name="override_duplicates" value="1">
                                Ignorar duplicados
                            </label>
                            <button class="primary" type="submit">Importar</button>
                        </form>
                    <?php endif; ?>

                    <div class="finance-table-wrapper spaced">
                        <table class="finance-table">
                            <thead>
                                <tr>
                                    <?php if ($key === 'valid'): ?>
                                        <th style="width:32px;"><input type="checkbox" data-batch-select="toggle"></th>
                                    <?php endif; ?>
                                    <th>#</th>
                                    <th>Descrição</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Ocorrido em</th>
                                    <th>Erro</th>
                                    <?php if ($key === 'valid'): ?>
                                        <th class="text-right">Ações</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $row): ?>
                                    <?php
                                    $duplicateHint = false;
                                    if ($key === 'valid' && !empty($row['normalized_payload'])) {
                                        $decoded = json_decode((string)$row['normalized_payload'], true);
                                        if (is_array($decoded) && !empty($decoded['duplicate_hint'])) {
                                            $duplicateHint = true;
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <?php if ($key === 'valid'): ?>
                                            <td>
                                                <input type="checkbox" name="row_ids[]" value="<?= (int)$row['id']; ?>" form="import-selected-form">
                                            </td>
                                        <?php endif; ?>
                                        <td><?= (int)($row['row_number'] ?? 0); ?></td>
                                        <td>
                                            <div class="finance-summary">
                                                <span><?= htmlspecialchars($row['description'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                                <div class="finance-badge-group">
                                                    <?php if (!empty($row['reference'])): ?>
                                                        <span class="finance-pill neutral" title="Referência">Ref <?= htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['checksum'])): ?>
                                                        <span class="finance-pill default" title="Checksum completo: <?= htmlspecialchars($row['checksum'], ENT_QUOTES, 'UTF-8'); ?>">Hash <?= htmlspecialchars(substr((string)$row['checksum'], 0, 8), ENT_QUOTES, 'UTF-8'); ?>…</span>
                                                    <?php endif; ?>
                                                    <?php if ($duplicateHint): ?>
                                                        <span class="finance-pill negative">Possível duplicado</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($row['transaction_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?= $row['amount_cents'] !== null ? format_money((int)$row['amount_cents']) : '—'; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['occurred_at']) && is_numeric($row['occurred_at'])): ?>
                                                <?= format_date((int)$row['occurred_at']); ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($row['occurred_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['error_message'])): ?>
                                                <span class="finance-error"><?= htmlspecialchars($row['error_message'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($key === 'valid'): ?>
                                            <td class="text-right">
                                            <form action="<?= url('finance/imports/' . (int)$batch['id'] . '/rows/' . (int)$row['id'] . '/skip'); ?>" method="post" onsubmit="return confirm('Deseja ignorar esta linha?');">
                                                <?= csrf_field(); ?>
                                                <button class="ghost" type="submit">Pular</button>
                                            </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('[data-batch-select="toggle"]');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('change', function (event) {
            var checked = event.target.checked;
            document.querySelectorAll('#import-selected-form input[type="checkbox"][name="row_ids[]"]').forEach(function (input) {
                input.checked = checked;
            });
        });
    });
    </script>

    <div class="finance-panel">
        <div class="finance-panel-header">
            <div>
                <p class="finance-meta">Auditoria</p>
                <h2>Linha do tempo</h2>
            </div>
            <p class="finance-muted">Eventos registrados durante o processamento e importação.</p>
        </div>

        <?php if ($events === []): ?>
            <div class="finance-empty">
                <p>Nenhum evento registrado ainda.</p>
            </div>
        <?php else: ?>
            <ul class="finance-timeline">
                <?php foreach ($events as $event): ?>
                    <?php
                    $context = [];
                    if (!empty($event['context'])) {
                        $decoded = json_decode((string)$event['context'], true);
                        if (is_array($decoded)) {
                            $context = $decoded;
                        }
                    }
                    ?>
                    <li class="finance-timeline__item">
                        <div class="finance-meta-line">
                            <strong><?= htmlspecialchars(ucfirst($event['level'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="finance-muted">
                                <?= !empty($event['created_at']) ? format_datetime((int)$event['created_at']) : '—'; ?>
                            </span>
                        </div>
                        <div><?= htmlspecialchars($event['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($context !== []): ?>
                            <pre class="finance-code"><?= htmlspecialchars(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
