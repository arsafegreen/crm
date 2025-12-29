<?php
$moduleAccess = $moduleAccess ?? [];
$hasAnyModule = in_array(true, array_diff_key($moduleAccess, ['overview' => true]), true) || ($moduleAccess['overview'] ?? false);
?>

<?php if (!$hasAnyModule): ?>
    <div class="panel" style="margin-bottom:24px;">
        <p style="margin:0;color:var(--muted);">
            Nenhum bloco do painel CRM foi liberado para o seu usuário. Solicite ao administrador as seções necessárias.
        </p>
    </div>
<?php else: ?>
    <?php if (($moduleAccess['import'] ?? false) && !empty($feedback)): ?>
        <div class="panel" style="border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#f87171'; ?>;">
            <strong style="display:block;">Importação</strong>
            <p style="margin:6px 0;"><?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (!empty($feedback['meta'])): ?>
                <ul style="list-style:none;padding:0;margin:12px 0 0;font-size:0.9rem;color:var(--muted);display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
                    <li>Processados: <strong><?= (int)$feedback['meta']['processed']; ?></strong></li>
                    <li>Ignorados: <strong><?= (int)$feedback['meta']['skipped']; ?></strong></li>
                    <li>Clientes novos: <strong><?= (int)$feedback['meta']['created_clients']; ?></strong></li>
                    <li>Clientes atualizados: <strong><?= (int)$feedback['meta']['updated_clients']; ?></strong></li>
                    <li>Certificados novos: <strong><?= (int)$feedback['meta']['created_certificates']; ?></strong></li>
                    <li>Certificados atualizados: <strong><?= (int)$feedback['meta']['updated_certificates']; ?></strong></li>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($moduleAccess['metrics'] ?? false): ?>
        <?php
            $summaryProtocols = number_format($metrics['total_protocols'] ?? 0, 0, ',', '.');
            $summaryActive = number_format($metrics['active_valid'] ?? 0, 0, ',', '.');
        ?>
        <div class="module" data-module data-open="false">
            <button class="module-toggle" type="button" aria-expanded="false">
                <div class="module-toggle-text">
                    <h2 class="module-title">Visão geral de clientes</h2>
                    <p class="module-subtitle">Consolide totais por status, documento e janela de prospecção.</p>
                </div>
                <div class="module-toggle-right">
                    <div class="module-tags">
                        <span class="module-tag">Protocolos <?= $summaryProtocols; ?></span>
                        <span class="module-tag">Ativos <?= $summaryActive; ?></span>
                    </div>
                    <span class="module-toggle-icon" aria-hidden="true"></span>
                </div>
            </button>
            <div class="module-body">
                <div class="metric-grid">
                    <div class="metric"><span>Protocolos emitidos</span><strong><?= $summaryProtocols; ?></strong></div>
                    <div class="metric"><span>Ativos válidos</span><strong><?= $summaryActive; ?></strong></div>
                    <div class="metric"><span>A vencer &le; 10 dias</span><strong><?= number_format($metrics['expiring_0_10'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>Inativos (vencidos)</span><strong><?= number_format($metrics['inactive_expired'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>Perdidos (carteira off)</span><strong><?= number_format($metrics['lost_off'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>Prospecção (40d/20d)</span><strong><?= number_format($metrics['prospection_window'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CNPJ ativos</span><strong><?= number_format($metrics['active_cnpj'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF ativos</span><strong><?= number_format($metrics['active_cpf'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CNPJ (40d/-20d)</span><strong><?= number_format($metrics['window_cnpj'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF (40d/-20d)</span><strong><?= number_format($metrics['window_cpf'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CNPJ total</span><strong><?= number_format($metrics['cnpj_total'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CNPJ revogados</span><strong><?= number_format($metrics['cnpj_revoked'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CNPJ vencidos</span><strong><?= number_format($metrics['cnpj_expired'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF total</span><strong><?= number_format($metrics['cpf_total'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF revogados</span><strong><?= number_format($metrics['cpf_revoked'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF vencidos</span><strong><?= number_format($metrics['cpf_expired'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>CPF com CNPJ relacionado</span><strong><?= number_format($metrics['cpf_with_cnpj_protocols'] ?? 0, 0, ',', '.'); ?></strong></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($moduleAccess['alerts'] ?? false): ?>
        <?php
            $alertSoon = number_format($metrics['expiring_0_10'] ?? 0, 0, ',', '.');
            $alertExpired = number_format($metrics['expired_30'] ?? 0, 0, ',', '.');
        ?>
        <div class="module" data-module data-open="false">
            <button class="module-toggle" type="button" aria-expanded="false">
                <div class="module-toggle-text">
                    <h2 class="module-title">Alertas de renovação</h2>
                    <p class="module-subtitle">Acompanhe vencimentos e priorize contatos na janela crítica.</p>
                </div>
                <div class="module-toggle-right">
                    <div class="module-tags">
                        <span class="module-tag">Vencendo 10d <?= $alertSoon; ?></span>
                        <span class="module-tag">Vencidos 30d <?= $alertExpired; ?></span>
                    </div>
                    <span class="module-toggle-icon" aria-hidden="true"></span>
                </div>
            </button>
            <div class="module-body">
                <p class="module-lead">Clientes com certificado emitido e status ativo.</p>
                <div class="metric-grid">
                    <div class="metric"><span>Vencendo &le; 10 dias</span><strong><?= $alertSoon; ?></strong></div>
                    <div class="metric"><span>Vencendo 11-20 dias</span><strong><?= number_format($metrics['expiring_11_20'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>Vencendo 21-30 dias</span><strong><?= number_format($metrics['expiring_21_30'] ?? 0, 0, ',', '.'); ?></strong></div>
                    <div class="metric"><span>Vencidos até 30 dias</span><strong><?= $alertExpired; ?></strong></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($moduleAccess['performance'] ?? false): ?>
        <?php
            $latestGrowth = !empty($monthlyGrowth) ? end($monthlyGrowth) : null;
            if (!empty($monthlyGrowth)) {
                reset($monthlyGrowth);
            }
            $growthCount = is_array($monthlyGrowth ?? null) ? count($monthlyGrowth) : 0;
            $growthTag = $latestGrowth !== null ? htmlspecialchars((string)($latestGrowth['label'] ?? ''), ENT_QUOTES, 'UTF-8') : 'Sem histórico';
            $growthChange = (int)($latestGrowth['difference'] ?? 0);
            $growthChangeText = $growthChange > 0 ? '+' . number_format($growthChange, 0, ',', '.') : ($growthChange < 0 ? number_format($growthChange, 0, ',', '.') : '0');
            $growthPercentTagRaw = $latestGrowth['difference_percent'] ?? null;
            $growthPercentTag = $growthPercentTagRaw !== null
                ? (($growthChange > 0 ? '+' : ($growthChange < 0 ? '-' : '±')) . number_format(abs((float)$growthPercentTagRaw), 1, ',', '.') . '%')
                : '—';
            $growthMood = $growthChange > 0 ? 'Crescimento' : ($growthChange < 0 ? 'Queda' : 'Estável');
        ?>
        <div class="module" data-module data-open="false">
            <button class="module-toggle" type="button" aria-expanded="false">
                <div class="module-toggle-text">
                    <h2 class="module-title">Performance de emissões</h2>
                    <p class="module-subtitle">Comparativo mensal por protocolo único versus o mesmo período anterior.</p>
                </div>
                <div class="module-toggle-right">
                    <div class="module-tags">
                        <span class="module-tag">Último <?= $growthTag; ?></span>
                        <span class="module-tag">Mood <?= htmlspecialchars($growthMood, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="module-tag">∆ <?= htmlspecialchars($growthChangeText, ENT_QUOTES, 'UTF-8'); ?> / <?= htmlspecialchars($growthPercentTag, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <span class="module-toggle-icon" aria-hidden="true"></span>
                </div>
            </button>
            <div class="module-body">
                <?php if (!empty($monthlyGrowth)): ?>
                    <?php
                        $latestDiff = (int)($latestGrowth['difference'] ?? 0);
                        $latestLabel = htmlspecialchars((string)($latestGrowth['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $latestPrevYear = (int)($latestGrowth['previous_year'] ?? 0);
                        $latestPrevTotal = number_format((int)($latestGrowth['previous_total'] ?? 0), 0, ',', '.');
                        $latestCurrentTotal = number_format((int)($latestGrowth['current_total'] ?? 0), 0, ',', '.');
                        $latestDiffFormatted = number_format(abs($latestDiff), 0, ',', '.');
                        $latestPercent = $latestGrowth['difference_percent'] ?? null;
                        $summaryColor = $latestDiff > 0 ? '#22c55e' : ($latestDiff < 0 ? '#ef4444' : '#facc15');
                        $summaryArrow = $latestDiff > 0 ? '&uarr;' : ($latestDiff < 0 ? '&darr;' : '&rarr;');
                        $summaryLabel = $latestDiff === 0 ? 'Estável' : ($latestDiff > 0 ? 'Crescimento' : 'Queda');
                        $percentText = $latestPercent !== null ? number_format($latestPercent, 1, ',', '.') . '%' : '—';
                        $changeText = $latestDiff === 0 ? '0' : ($latestDiff > 0 ? '+' : '-') . $latestDiffFormatted;
                    ?>
                    <div class="module-highlight" style="--highlight-color: <?= $summaryColor; ?>;">
                        <span class="module-highlight-icon"><?= $summaryArrow; ?></span>
                        <div>
                            <strong><?= $summaryLabel; ?> <?= $changeText !== '0' ? 'de ' . $changeText . ' certificados' : 'sem variação'; ?></strong>
                            <small><?= $latestLabel; ?> vs <?= $latestPrevYear; ?> &bull; Atual <?= $latestCurrentTotal; ?> / <?= $latestPrevYear; ?> <?= $latestPrevTotal; ?> (<?= $percentText; ?>)</small>
                        </div>
                    </div>
                    <p class="module-lead">Comparativo mensal de emissões (protocolos únicos) versus o mesmo mês do ano anterior.</p>
                    <div class="module-table-wrap" style="max-height:360px;overflow:auto;">
                        <table class="module-table">
                            <thead>
                                <tr>
                                    <th>Mês</th>
                                    <th>Atual</th>
                                    <th>Ano anterior</th>
                                    <th>Variação</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 0; ?>
                                <?php foreach ($monthlyGrowth as $point):
                                    $isLatest = $rowIndex === $growthCount - 1;
                                    $rowIndex++;
                                    $rowBg = $isLatest ? 'row-latest' : '';
                                    $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $currentYear = (int)($point['current_year'] ?? 0);
                                    $currentTotal = number_format((int)($point['current_total'] ?? 0), 0, ',', '.');
                                    $previousYear = (int)($point['previous_year'] ?? 0);
                                    $previousTotal = number_format((int)($point['previous_total'] ?? 0), 0, ',', '.');

                                    $differenceRaw = (int)($point['difference'] ?? 0);
                                    $differenceAbs = number_format(abs($differenceRaw), 0, ',', '.');
                                    $differencePrefix = $differenceRaw > 0 ? '+' : ($differenceRaw < 0 ? '-' : '±');
                                    $differenceColor = $differenceRaw > 0 ? 'positive' : ($differenceRaw < 0 ? 'negative' : 'neutral');
                                    $differenceIcon = $differenceRaw > 0 ? '&uarr;' : ($differenceRaw < 0 ? '&darr;' : '&rarr;');
                                    $differenceText = $differenceRaw === 0 ? '0' : $differenceAbs;

                                    $percentRaw = $point['difference_percent'] ?? null;
                                    $percentTextPoint = $percentRaw !== null
                                        ? ($differenceRaw > 0 ? '+' : ($differenceRaw < 0 ? '-' : '±')) . number_format(abs((float)$percentRaw), 1, ',', '.') . '%'
                                        : '—';
                                ?>
                                    <tr class="<?= $rowBg; ?>">
                                        <td data-label="Mês">
                                            <strong><?= $label; ?></strong>
                                            <span class="module-table-note">Ano <?= $currentYear; ?></span>
                                        </td>
                                        <td data-label="Atual">
                                            <strong><?= $currentTotal; ?></strong>
                                        </td>
                                        <td data-label="Ano anterior">
                                            <strong><?= $previousTotal; ?></strong>
                                            <span class="module-table-note">Ano <?= $previousYear; ?></span>
                                        </td>
                                        <td data-label="Variação" class="text-<?= $differenceColor; ?>">
                                            <span class="module-table-diff">
                                                <span><?= $differenceIcon; ?></span>
                                                <span><?= $differencePrefix === '±' ? '' : $differencePrefix; ?><?= $differenceText; ?></span>
                                            </span>
                                        </td>
                                        <td data-label="%" class="text-<?= $differenceColor; ?>">
                                            <?= $percentTextPoint; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="module-lead">Importe os relatórios históricos para visualizar o comparativo mensal de emissões.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($moduleAccess['import'] ?? false): ?>
        <div class="module" data-module data-open="false">
            <button class="module-toggle" type="button" aria-expanded="false">
                <div class="module-toggle-text">
                    <h2 class="module-title">Importar planilha do sistema</h2>
                    <p class="module-subtitle">Atualize base de certificados com novos protocolos e renovações.</p>
                </div>
                <div class="module-toggle-right">
                    <div class="module-tags">
                        <span class="module-tag">Formatos XLS/XLSX/CSV</span>
                    </div>
                    <span class="module-toggle-icon" aria-hidden="true"></span>
                </div>
            </button>
            <div class="module-body">
                <p class="module-lead">Faça upload do relatório ICP-Brasil completo. Protocolos existentes serão atualizados automaticamente.</p>
                <form method="post" action="<?= url('crm/import'); ?>" enctype="multipart/form-data" class="module-form">
                    <?= csrf_field(); ?>
                    <input class="module-input" type="file" name="spreadsheet" accept=".xls,.xlsx,.csv" required>
                    <div class="module-form-actions">
                        <button class="primary" type="submit">Processar planilha</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($moduleAccess['partners'] ?? false): ?>
        <?php
            $topPartner = $partnerStats['rolling'][0] ?? null;
            $topPartnerName = $topPartner !== null ? htmlspecialchars((string)$topPartner['partner_name'], ENT_QUOTES, 'UTF-8') : 'Sem indicações';
            $topPartnerTotal = $topPartner !== null ? number_format((int)($topPartner['total'] ?? 0), 0, ',', '.') : '0';
            $currentSortField = $partnerStats['sort'] ?? 'latest';
            $currentSortDirection = $partnerStats['direction'] ?? 'desc';
            $buildSortUrl = static function (string $field) use ($currentSortField, $currentSortDirection): string {
                $nextDirection = ($currentSortField === $field && $currentSortDirection === 'asc') ? 'desc' : 'asc';
                return url('crm') . '?sort=' . rawurlencode($field) . '&direction=' . rawurlencode($nextDirection);
            };
            $sortIndicator = static function (string $field) use ($currentSortField, $currentSortDirection): string {
                if ($currentSortField !== $field) {
                    return '';
                }
                return $currentSortDirection === 'asc' ? '&uarr;' : '&darr;';
            };
        ?>
        <div class="module" data-module data-open="false">
            <button class="module-toggle" type="button" aria-expanded="false">
                <div class="module-toggle-text">
                    <h2 class="module-title">Parceiro / Contador</h2>
                    <p class="module-subtitle">Integra parceiros e contadores em um único leaderboard dinâmico.</p>
                </div>
                <div class="module-toggle-right">
                    <div class="module-tags">
                        <span class="module-tag">Top <?= $topPartnerName; ?></span>
                        <span class="module-tag">Total <?= $topPartnerTotal; ?></span>
                    </div>
                    <span class="module-toggle-icon" aria-hidden="true"></span>
                </div>
            </button>
            <div class="module-body">
                <?php if (!empty($partnerStats['rolling'])): ?>
                    <p class="module-lead">Todos os parceiros com indicações emitidas nos últimos 12 meses. Clique nos cabeçalhos para reordenar.</p>
                    <div class="module-table-wrap" style="max-height:360px;overflow:auto;">
                        <table class="module-table">
                            <thead>
                                <tr>
                                    <th>
                                        <?php $indicator = $sortIndicator('partner'); ?>
                                        <a href="<?= $buildSortUrl('partner'); ?>" class="module-sort-link">
                                            Parceiro / Contador
                                            <?php if ($indicator !== ''): ?><span class="module-sort-indicator"><?= $indicator; ?></span><?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <?php $indicator = $sortIndicator('latest'); ?>
                                        <a href="<?= $buildSortUrl('latest'); ?>" class="module-sort-link">
                                            Última indicação
                                            <?php if ($indicator !== ''): ?><span class="module-sort-indicator"><?= $indicator; ?></span><?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <?php $indicator = $sortIndicator('total'); ?>
                                        <a href="<?= $buildSortUrl('total'); ?>" class="module-sort-link">
                                            Indicações (12 meses)
                                            <?php if ($indicator !== ''): ?><span class="module-sort-indicator"><?= $indicator; ?></span><?php endif; ?>
                                        </a>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rankPosition = 1; ?>
                                <?php foreach ($partnerStats['rolling'] as $partner):
                                    $partnerName = htmlspecialchars($partner['partner_name'], ENT_QUOTES, 'UTF-8');
                                    $totalIndications = number_format((int)($partner['total'] ?? 0), 0, ',', '.');
                                    $lastProtocol = trim((string)($partner['last_protocol'] ?? ''));
                                    $lastStartTimestamp = isset($partner['last_start_at']) ? (int)$partner['last_start_at'] : 0;
                                    $lastStartAt = $lastStartTimestamp > 0
                                        ? date('d/m/Y', $lastStartTimestamp)
                                        : 'Sem início';
                                ?>
                                    <tr>
                                        <td data-label="Parceiro / Contador">
                                            <div class="module-rank-name">
                                                <span class="module-rank-badge">#<?= $rankPosition; ?></span>
                                                <span><?= $partnerName; ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Última indicação">
                                            <div class="module-rank-latest">
                                                <small>Protocolo: <?= $lastProtocol !== '' ? htmlspecialchars($lastProtocol, ENT_QUOTES, 'UTF-8') : '—'; ?></small>
                                                <small>Início: <?= $lastStartAt; ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Indicações (12 meses)">
                                            <strong><?= $totalIndications; ?></strong>
                                        </td>
                                    </tr>
                                    <?php $rankPosition++; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="module-lead">Importe os relatórios para mapear as indicações dos parceiros nos últimos 12 meses.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>
