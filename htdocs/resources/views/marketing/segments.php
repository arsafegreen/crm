<?php
$segments = $segments ?? [];
$lists = $lists ?? [];
$feedback = $feedback ?? null;

$statusLabels = [
    'draft' => 'Rascunho',
    'active' => 'Ativo',
    'paused' => 'Pausado',
];
?>

<style>
    .segments-table { width:100%; border-collapse:collapse; }
    .segments-table th,.segments-table td { padding:10px 12px; border-bottom:1px solid rgba(148,163,184,0.2); text-align:left; }
    .segment-pill { padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; }
    .segment-pill.draft { background:rgba(148,163,184,0.18); color:var(--muted); }
    .segment-pill.active { background:rgba(34,197,94,0.15); color:#86efac; }
    .segment-pill.paused { background:rgba(250,204,21,0.2); color:#facc15; }
</style>

<header>
    <div>
        <h1 style="margin-bottom:8px;">Segmentos dinâmicos</h1>
        <p style="color:var(--muted);max-width:760px;">Combine atributos, listas e eventos para formar recortes reutilizáveis em campanhas, fluxos e notificações. Cada segmento mantém um contador sincronizado com a base de contatos.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('marketing/lists'); ?>">&larr; Voltar para listas</a>
        <a class="primary" href="<?= url('marketing/segments/create'); ?>">+ Novo segmento</a>
    </div>
</header>

<?php if ($feedback !== null): ?>
    <div class="panel" style="margin-top:18px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#f87171'; ?>;">
        <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
<?php endif; ?>

<section class="panel" style="margin-top:18px;display:grid;gap:24px;">
    <?php if ($segments === []): ?>
        <p style="color:var(--muted);">Nenhum segmento criado ainda. Defina os critérios do primeiro segmento clicando em "Novo segmento".</p>
    <?php else: ?>
        <table class="segments-table">
            <thead>
                <tr>
                    <th>Segmento</th>
                    <th>Lista de referência</th>
                    <th>Status</th>
                    <th>Contatos</th>
                    <th>Atualizado</th>
                    <th style="text-align:right;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segments as $segment): ?>
                    <?php $status = strtolower((string)($segment['status'] ?? 'draft')); ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($segment['name'] ?? 'Segmento', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                            <small style="color:var(--muted);">Slug: <?= htmlspecialchars($segment['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td><?= isset($segment['list_name']) ? htmlspecialchars($segment['list_name'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td><span class="segment-pill <?= $status; ?>"><?= $statusLabels[$status] ?? ucfirst($status); ?></span></td>
                        <td><?= number_format((int)($segment['contacts_total'] ?? 0), 0, ',', '.'); ?></td>
                        <td><?= !empty($segment['updated_at']) ? format_datetime((int)$segment['updated_at']) : '—'; ?></td>
                        <td style="text-align:right;">
                            <div style="display:flex;gap:8px;justify-content:flex-end;">
                                <a class="ghost" href="<?= url('marketing/segments/' . (int)($segment['id'] ?? 0) . '/edit'); ?>">Editar</a>
                                <form method="post" action="<?= url('marketing/segments/' . (int)($segment['id'] ?? 0) . '/delete'); ?>" onsubmit="return confirm('Remover este segmento? Esta ação não exclui os contatos.');" style="margin:0;">
                                    <?= csrf_field(); ?>
                                    <button type="submit" class="ghost" style="color:#f87171;">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:18px;">
    <h2 style="margin-top:0;font-size:1rem;">Listas disponíveis</h2>
    <?php if ($lists === []): ?>
        <p style="color:var(--muted);">Nenhuma lista cadastrada. Crie uma lista antes de segmentar contatos.</p>
    <?php else: ?>
        <ul style="margin:12px 0 0; padding-left:18px;">
            <?php foreach ($lists as $list): ?>
                <li>
                    <strong><?= htmlspecialchars($list['name'] ?? 'Lista', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small style="color:var(--muted);">(<?= htmlspecialchars($list['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
