<?php
/** @var array $snapshots */
/** @var array $chains */
/** @var array|null $feedback */

$baseOptions = $snapshots;
$targetOptions = $snapshots;
?>
<div class="panel" style="display:flex;flex-direction:column;gap:12px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <div>
            <h2 style="margin:0;font-size:1.2rem;">Gerenciador de Backups</h2>
            <p style="margin:4px 0 0;color:var(--muted);">Crie snapshots completos, incrementais e restaure cadeias automaticamente.</p>
        </div>
        <a class="config-button" href="<?= url('backup-manager'); ?>">Atualizar lista</a>
    </div>

    <?php if ($feedback): ?>
        <div class="alert <?= htmlspecialchars($feedback['type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;">
        <form method="post" action="<?= url('backup-manager/full'); ?>" class="card" style="padding:14px;border:1px solid var(--border);border-radius:14px;background:rgba(15,23,42,0.45);box-shadow:var(--shadow);">
            <?= csrf_field(); ?>
            <h3 style="margin:0 0 8px;">Backup completo</h3>
            <p style="margin:0 0 10px;color:var(--muted);">Inclui código + banco SQLite. Opcionalmente inclui mídias.</p>
            <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                <input type="checkbox" name="with_media" value="1">
                <span>Incluir mídias (storage/whatsapp-media, whatsapp-web)</span>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;margin:8px 0;">
                <span>Nota (opcional)</span>
                <input type="text" name="note" placeholder="Ex: antes de atualização">            
            </label>
            <button type="submit" class="primary" style="margin-top:8px;">Gerar completo</button>
        </form>

        <form method="post" action="<?= url('backup-manager/incremental'); ?>" class="card" style="padding:14px;border:1px solid var(--border);border-radius:14px;background:rgba(15,23,42,0.45);box-shadow:var(--shadow);">
            <?= csrf_field(); ?>
            <h3 style="margin:0 0 8px;">Backup incremental</h3>
            <p style="margin:0 0 10px;color:var(--muted);">Armazena apenas arquivos alterados/novos desde o snapshot base.</p>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span>Snapshot base</span>
                <select name="base_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($baseOptions as $snapshot): ?>
                        <?php $id = htmlspecialchars($snapshot['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($id === '') { continue; } ?>
                        <option value="<?= $id; ?>"><?= $id; ?> (<?= htmlspecialchars($snapshot['type'] ?? 'n/d', ENT_QUOTES, 'UTF-8'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;">
                <input type="checkbox" name="with_media" value="1">
                <span>Forçar incluir mídias (senão herda do base)</span>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;margin:8px 0;">
                <span>Nota (opcional)</span>
                <input type="text" name="note" placeholder="Ex: após hotfix" />
            </label>
            <button type="submit" class="primary" style="margin-top:8px;">Gerar incremental</button>
        </form>

        <form method="post" action="<?= url('backup-manager/restore'); ?>" class="card" style="padding:14px;border:1px solid var(--border);border-radius:14px;background:rgba(15,23,42,0.45);box-shadow:var(--shadow);">
            <?= csrf_field(); ?>
            <h3 style="margin:0 0 8px;">Restaurar cadeia</h3>
            <p style="margin:0 0 10px;color:var(--muted);">Aplica automaticamente o full e todos incrementais necessários.</p>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span>Snapshot alvo</span>
                <select name="target_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($targetOptions as $snapshot): ?>
                        <?php $id = htmlspecialchars($snapshot['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($id === '') { continue; } ?>
                        <option value="<?= $id; ?>"><?= $id; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;margin:8px 0;">
                <span>Destino (pasta). Deixe em branco para storage/backups/restores/&lt;id&gt;.</span>
                <input type="text" name="destination" placeholder="C:\\caminho\\restauracao ou /var/www/restore" />
            </label>
            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;">
                <input type="checkbox" name="force" value="1">
                <span>Forçar (limpa o destino se não estiver vazio)</span>
            </label>
            <button type="submit" class="primary" style="margin-top:8px;">Restaurar</button>
        </form>

        <form method="post" action="<?= url('backup-manager/prune'); ?>" class="card" style="padding:14px;border:1px solid var(--border);border-radius:14px;background:rgba(15,23,42,0.45);box-shadow:var(--shadow);">
            <?= csrf_field(); ?>
            <h3 style="margin:0 0 8px;">Retenção / limpeza</h3>
            <p style="margin:0 0 10px;color:var(--muted);">Mantém os últimos full e suas cadeias; remove mais antigos e, opcionalmente, respeita limite de espaço.</p>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span>Manter últimos full</span>
                <input type="number" name="keep_full" min="1" value="2" />
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;margin:8px 0;">
                <span>Limite de espaço (GB, opcional)</span>
                <input type="number" step="1" min="1" name="max_gb" placeholder="ex: 50" />
            </label>
            <button type="submit" class="primary" style="margin-top:8px;">Aplicar retenção</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <h3 style="margin-top:0;">Snapshots</h3>
    <div class="table" style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;min-width:720px;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--border);">
                    <th style="padding:8px 6px;">ID</th>
                    <th style="padding:8px 6px;">Tipo</th>
                    <th style="padding:8px 6px;">Base</th>
                    <th style="padding:8px 6px;">Criado</th>
                    <th style="padding:8px 6px;">Arquivos</th>
                    <th style="padding:8px 6px;">Mídia</th>
                    <th style="padding:8px 6px;">Notas</th>
                    <th style="padding:8px 6px;">Cadeia</th>
                    <th style="padding:8px 6px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($snapshots === []): ?>
                    <tr><td colspan="9" style="padding:10px;color:var(--muted);">Nenhum snapshot gerado ainda.</td></tr>
                <?php else: ?>
                    <?php foreach ($snapshots as $snapshot): ?>
                        <?php $id = htmlspecialchars($snapshot['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                            <td style="padding:8px 6px;font-family:monospace;"><?= $id; ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars($snapshot['type'] ?? 'n/d', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:8px 6px;font-family:monospace;"><?= htmlspecialchars($snapshot['base_id'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars($snapshot['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:8px 6px;"><?= count($snapshot['files'] ?? []); ?></td>
                            <td style="padding:8px 6px;"><?= !empty($snapshot['with_media']) ? 'Sim' : 'Não'; ?></td>
                            <td style="padding:8px 6px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($snapshot['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($snapshot['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:8px 6px;">
                                <?php $chain = $chains[$snapshot['id']] ?? null; ?>
                                <?php if (is_array($chain) && isset($chain['error'])): ?>
                                    <span style="color:#f87171;">Erro: <?= htmlspecialchars($chain['error'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php elseif (is_array($chain)): ?>
                                    <span title="<?= htmlspecialchars(implode(' -> ', array_map(static fn($c) => $c['id'] ?? '?', $chain)), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?= count($chain); ?> passos
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px 6px;display:flex;gap:8px;align-items:center;">
                                <a class="config-button" style="padding:6px 10px;font-size:0.85rem;" href="<?= url('backup-manager/' . $id . '/download'); ?>">Baixar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <h3 style="margin-top:0;">Checklist pós-restauração</h3>
    <ol style="margin:8px 0 0 18px;line-height:1.5;color:var(--muted);">
        <li>Descompactar a cadeia (full + incrementais) no destino informado.</li>
        <li>Restaurar .env (ajuste caminhos/URLs/chaves se mudou o host).</li>
        <li>Na raiz, executar <code>composer install</code> para recriar vendor/.</li>
        <li>Em services/whatsapp-web-gateway, rodar <code>npm install</code> e apontar o .env local.</li>
        <li>Garantir permissão de escrita em storage/ (database.sqlite, logs, whatsapp-media).</li>
        <li>Copiar certs/keys externos e ajustar caminhos no .env, se aplicável.</li>
        <li>Subir o servidor web/PHP apontando para public/index.php.</li>
    </ol>
</div>
