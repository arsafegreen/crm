<?php
$lists = $lists ?? [];
$segmentsPreview = $segmentsPreview ?? [];
$segmentsTotal = (int)($segmentsTotal ?? 0);
$feedback = $feedback ?? null;
$deliveryStats = $deliveryStats ?? ['sent' => 0, 'soft_bounce' => 0, 'hard_bounce' => 0];
$sentRecent = $sentRecent ?? [];
$errorRecent = $errorRecent ?? [];
$sweepStatus = $sweepStatus ?? 'stopped';
$autoMaintenanceSlugs = $autoMaintenanceSlugs ?? ['todos', 'parceiros', 'rfb-contab'];
$scheduledEmails = $scheduledEmails ?? [];

if (!isset($listTotals) || !is_array($listTotals)) {
    $computedTotal = 0;
    $computedSubscribed = 0;
    foreach ($lists as $list) {
        $computedTotal += (int)($list['contacts_total'] ?? 0);
        $computedSubscribed += (int)($list['contacts_subscribed'] ?? 0);
    }
    $listTotals = [
        'lists' => count($lists),
        'contacts' => [
            'total' => $computedTotal,
            'subscribed' => $computedSubscribed,
        ],
        'segments' => $segmentsTotal,
    ];
}

$listTotals['contacts']['total'] = (int)($listTotals['contacts']['total'] ?? 0);
$listTotals['contacts']['subscribed'] = (int)($listTotals['contacts']['subscribed'] ?? 0);
$listTotals['lists'] = (int)($listTotals['lists'] ?? count($lists));
$listTotals['segments'] = (int)($listTotals['segments'] ?? $segmentsTotal);

function format_int(int $value): string {
    return number_format($value, 0, ',', '.');
}

function fmt_datetime(?int $ts): string {
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

$statusLabels = $statusLabels ?? [
    'active' => 'Ativa',
    'paused' => 'Pausada',
    'archived' => 'Arquivada',
];

$marketingListsCss = asset('css/marketing-lists.css');
$marketingListsJs = asset('js/marketing-lists.js');
$listsScriptConfig = [
    'csrf' => csrf_token(),
    'initialSweepStatus' => in_array($sweepStatus, ['running', 'paused'], true) ? $sweepStatus : 'stopped',
];
?>

<link rel="stylesheet" href="<?= $marketingListsCss; ?>">
<script>
    window.MarketingListsConfig = <?= json_encode($listsScriptConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?= $marketingListsJs; ?>" defer></script>

<header>
    <div>
        <h1 style="margin-bottom:8px;">Listas de audiência</h1>
        <p style="color:var(--muted);max-width:760px;">Consolide contatos e pontos de coleta em listas versionadas. Use estes cadastros para segmentar campanhas, fluxos e relatórios de consentimento LGPD.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
        <a class="ghost" href="<?= url('marketing/segments'); ?>">Segmentos</a>
        <a class="primary" href="<?= url('marketing/lists/create'); ?>">+ Nova lista</a>
    </div>
</header>

<section class="panel" style="margin-top:18px;">
    <div class="marketing-grid">
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Listas ativas</span>
            <div class="stat-value"><?= format_int($listTotals['lists']); ?></div>
        </div>
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Contatos cadastrados</span>
            <div class="stat-value"><?= format_int($listTotals['contacts']['total']); ?></div>
            <small style="color:var(--muted);"><?= format_int($listTotals['contacts']['subscribed']); ?> com opt-in confirmado</small>
        </div>
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Segmentos configurados</span>
            <div class="stat-value"><?= $segmentsTotal; ?></div>
            <small style="color:var(--muted);">Prévia de até 4 segmentos abaixo</small>
        </div>
    </div>
    <section class="card mb-4" style="border:1px dashed rgba(255,255,255,0.12);">
        <div class="card-body d-flex align-items-center justify-content-between" style="gap:12px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div id="bounce-status-dot" style="width:14px;height:14px;border-radius:50%;background:<?= $sweepStatus === 'running' ? '#22c55e' : '#ef4444'; ?>;border:2px solid #222;"></div>
                <div>
                    <div style="font-weight:600;">Verificação de bounces</div>
                    <small style="color:var(--muted);">Status: <b id="bounce-status-label"><?= $sweepStatus === 'running' ? 'Em execução' : 'Parada'; ?></b></small>
                    <br>
                    <small style="color:var(--muted);">Progresso: <span id="bounce-progress">0</span> / <span id="bounce-total">?</span> verificados</small>
                    <br>
                    <small style="color:var(--muted);">Bounces: <span id="bounce-found">0</span></small>
                    <br>
                    <small id="bounce-message" style="color:var(--muted);"></small>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                <label for="bounce-list-select" style="font-weight:600;">Grupo</label>
                <select id="bounce-list-select" style="min-width:220px;">
                    <option value="">Todos os grupos</option>
                    <?php foreach ($lists as $list): ?>
                        <option value="<?= (int)($list['id'] ?? 0); ?>"><?= htmlspecialchars((string)($list['name'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" id="bounce-external" style="margin:0;">
                    <span style="color:var(--muted);">Validar MX (externo)</span>
                </label>
                <button type="button" id="bounce-start-btn" class="btn btn-success" style="min-width:160px;">Iniciar / retomar</button>
                <button type="button" id="bounce-stop-btn" class="btn btn-danger" style="min-width:120px;">Pausar</button>
            </div>
        </div>
    </section>
    <script>
    (function() {
        const csrf = '<?= csrf_token(); ?>';
        const statusDot = document.getElementById('bounce-status-dot');
        const statusLabel = document.getElementById('bounce-status-label');
        const progressEl = document.getElementById('bounce-progress');
        const totalEl = document.getElementById('bounce-total');
        const foundEl = document.getElementById('bounce-found');
        const selectEl = document.getElementById('bounce-list-select');
        const startBtn = document.getElementById('bounce-start-btn');
        const stopBtn = document.getElementById('bounce-stop-btn');
        const externalEl = document.getElementById('bounce-external');
        const messageEl = document.getElementById('bounce-message');

        let processing = false;
        let processTimer = null;
        let lastStatus = null;

        const ensureProcessLoop = () => {
            if (processTimer !== null) return;
            processTimer = setInterval(() => {
                if (statusLabel.dataset.state !== 'running') return;
                triggerProcess();
            }, 1200);
        };

        const stopProcessLoop = () => {
            if (processTimer !== null) {
                clearInterval(processTimer);
                processTimer = null;
            }
        };

        const triggerProcess = async () => {
            if (processing) return;
            processing = true;
            const body = new URLSearchParams();
            body.append('_token', csrf);
            await fetch('/marketing/lists/sweep/process', { method: 'POST', body });
            processing = false;
        };

        const setStatusUi = (status) => {
            const isRunning = status === 'running';
            const isPaused = status === 'paused';
            statusDot.style.background = isRunning ? '#22c55e' : (isPaused ? '#facc15' : '#ef4444');
            statusLabel.textContent = isRunning ? 'Em execução' : (isPaused ? 'Pausada' : 'Parada');
            statusLabel.dataset.state = status;
            startBtn.disabled = isRunning;
            stopBtn.disabled = !isRunning;
            if (isRunning) {
                ensureProcessLoop();
            } else {
                stopProcessLoop();
            }
        };

        const updateStatus = async () => {
            try {
                const res = await fetch('/marketing/lists/sweep/status');
                if (!res.ok) return;
                const data = await res.json();
                lastStatus = data;
                setStatusUi(data.status || 'stopped');
                progressEl.textContent = data.checked ?? data.progress ?? 0;
                totalEl.textContent = data.total ?? 0;
                foundEl.textContent = data.bounces ?? 0;
                messageEl.textContent = data.message ?? '';
            } catch (e) {
                // falha silenciosa
            }
        };

        const startSweep = async () => {
            const body = new URLSearchParams();
            body.append('_token', csrf);
            body.append('list_id', selectEl.value || '');
            if (externalEl.checked) {
                body.append('external_validation', '1');
            }
            if (lastStatus && lastStatus.status === 'paused') {
                body.append('resume', '1');
            }
            await fetch('/marketing/lists/sweep/start', { method: 'POST', body });
            ensureProcessLoop();
            await triggerProcess();
            updateStatus();
        };

        const stopSweep = async () => {
            const body = new URLSearchParams();
            body.append('_token', csrf);
            await fetch('/marketing/lists/sweep/stop', { method: 'POST', body });
            stopProcessLoop();
            updateStatus();
        };

        startBtn.addEventListener('click', startSweep);
        stopBtn.addEventListener('click', stopSweep);
        setStatusUi(<?= $sweepStatus === 'running' ? "'running'" : "'stopped'"; ?>);
        updateStatus();
        setInterval(updateStatus, 5000);
    })();
    </script>

    <section id="suppress-card" class="card" data-collapsed="true" style="margin-top:12px; border:1px dashed rgba(255,255,255,0.12);">
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
            <div id="suppress-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:space-between;cursor:pointer;">
                <div>
                    <div style="font-weight:600;">Lista negativa (bounces/supressoes)</div>
                    <small style="color:var(--muted);">Busca e paginacao basicas</small>
                </div>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <span style="color:var(--muted);">Total: <b id="suppress-total-basic">0</b></span>
                    <span style="color:var(--muted);">Pagina: <b id="suppress-page-basic">1</b></span>
                    <button type="button" id="suppress-toggle" class="ghost" style="padding:4px 12px;">Mostrar detalhes</button>
                </div>
            </div>
            <div id="suppress-content">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:space-between;">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input id="suppress-search" type="text" placeholder="Buscar email/dominio" style="min-width:220px;">
                        <button type="button" id="suppress-search-btn" class="ghost">Buscar</button>
                        <span style="color:var(--muted);">Total: <b id="suppress-total">0</b></span>
                        <span style="color:var(--muted);">Pagina: <b id="suppress-page">1</b></span>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button type="button" id="suppress-prev" class="ghost" style="padding:4px 10px;">&lsaquo;</button>
                        <button type="button" id="suppress-next" class="ghost" style="padding:4px 10px;">&rsaquo;</button>
                    </div>
                </div>
                <div style="overflow:auto;">
                    <table class="panel-table" id="suppress-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Motivo</th>
                                <th>Atualizado em</th>
                                <th>Acao</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="4" style="color:var(--muted);">Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                    <form id="suppress-import-form" method="post" action="/marketing/lists/suppressions/import" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="_token" value="<?= csrf_token(); ?>">
                        <input type="file" name="file" accept=".txt,.csv" required>
                        <button type="submit" class="ghost">Importar supressao</button>
                    </form>
                    <a class="ghost" href="/marketing/lists/suppressions/export">Exportar CSV</a>
                </div>
            </div>
        </div>
    </section>
    <script>
    (function() {
        const card = document.getElementById('suppress-card');
        const header = document.getElementById('suppress-header');
        const content = document.getElementById('suppress-content');
        const toggleBtn = document.getElementById('suppress-toggle');
        const tableBody = document.querySelector('#suppress-table tbody');
        const totalEl = document.getElementById('suppress-total');
        const searchEl = document.getElementById('suppress-search');
        const searchBtn = document.getElementById('suppress-search-btn');
        const pageEl = document.getElementById('suppress-page');
        const prevBtn = document.getElementById('suppress-prev');
        const nextBtn = document.getElementById('suppress-next');
        const totalCompactEl = document.getElementById('suppress-total-basic');
        const pageCompactEl = document.getElementById('suppress-page-basic');

        let currentPage = 1;
        const pageSize = 20;

        const renderRows = (items) => {
            if (!Array.isArray(items) || items.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);">Nenhum registro.</td></tr>';
                return;
            }
            const rows = items.map((item) => {
                const email = item.email || '';
                const reason = item.suppression_reason || '—';
                const updated = item.updated_at ? new Date(item.updated_at * 1000).toLocaleString('pt-BR') : '—';
                const id = item.id || 0;
                return `<tr>
                    <td>${email}</td>
                    <td>${reason}</td>
                    <td>${updated}</td>
                    <td><button data-id="${id}" class="ghost suppress-restore" style="padding:4px 8px;">Restaurar</button></td>
                </tr>`;
            }).join('');
            tableBody.innerHTML = rows;
        };

        const loadSuppressed = async () => {
            const params = new URLSearchParams();
            const q = searchEl.value.trim();
            if (q !== '') params.append('q', q);
            params.append('page', String(currentPage));
            params.append('limit', String(pageSize));
            const res = await fetch('/marketing/lists/suppressions?' + params.toString());
            if (!res.ok) return;
            const data = await res.json();
            const total = data.total ?? 0;
            if (totalEl) totalEl.textContent = total;
            if (totalCompactEl) totalCompactEl.textContent = total;
            renderRows(data.items ?? []);
            if (pageEl) pageEl.textContent = String(currentPage);
            if (pageCompactEl) pageCompactEl.textContent = String(currentPage);
            const totalPages = Math.max(1, Math.ceil(total / pageSize));
            prevBtn.disabled = currentPage <= 1;
            nextBtn.disabled = currentPage >= totalPages;
        };

        searchBtn.addEventListener('click', loadSuppressed);
        searchEl.addEventListener('keypress', (ev) => {
            if (ev.key === 'Enter') loadSuppressed();
        });

        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage -= 1;
                loadSuppressed();
            }
        });

        nextBtn.addEventListener('click', () => {
            currentPage += 1;
            loadSuppressed();
        });

        tableBody.addEventListener('click', async (ev) => {
            const target = ev.target;
            if (!(target instanceof HTMLElement)) return;
            if (!target.classList.contains('suppress-restore')) return;
            const id = target.getAttribute('data-id');
            if (!id) return;
            const body = new URLSearchParams();
            body.append('_token', '<?= csrf_token(); ?>');
            body.append('id', id);
            const res = await fetch('/marketing/lists/suppressions/unsuppress', { method: 'POST', body });
            if (res.ok) {
                loadSuppressed();
            }
        });

        const importForm = document.getElementById('suppress-import-form');
        importForm.addEventListener('submit', (ev) => {
            // allow default submission; page will reload with result
        });

        let isCollapsed = true;
        const setCollapsed = (value) => {
            if (!content || !toggleBtn || !card) return;
            isCollapsed = value;
            content.style.display = value ? 'none' : 'flex';
            toggleBtn.textContent = value ? 'Mostrar detalhes' : 'Ocultar detalhes';
            card.dataset.collapsed = value ? 'true' : 'false';
        };
        const toggleCollapsed = () => setCollapsed(!isCollapsed);

        if (header) {
            header.addEventListener('click', (ev) => {
                if (toggleBtn && toggleBtn.contains(ev.target)) return;
                toggleCollapsed();
            });
        }
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                toggleCollapsed();
            });
        }

        setCollapsed(true);
        loadSuppressed();
    })();
    </script>

    <section class="card" style="margin-top:12px; border:1px dashed rgba(255,255,255,0.12);">
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div>
                    <div style="font-weight:600;">Histórico de varreduras</div>
                    <small style="color:var(--muted);">Últimas 100 execuções</small>
                </div>
                <button type="button" id="history-reload" class="ghost">Atualizar</button>
            </div>
            <div style="overflow:auto;">
                <table class="panel-table" id="history-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Lista</th>
                            <th>Verificados</th>
                            <th>Bounces</th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="4" style="color:var(--muted);">Carregando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>
    <script>
    (function() {
        const tableBody = document.querySelector('#history-table tbody');
        const btn = document.getElementById('history-reload');

        const render = (items) => {
            if (!Array.isArray(items) || items.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);">Nenhum registro.</td></tr>';
                return;
            }
            const rows = items.map((item) => {
                const ts = item.ts ? new Date(item.ts * 1000).toLocaleString('pt-BR') : '—';
                const list = item.list_id ? ('Lista #' + item.list_id) : 'Todas';
                const checked = item.checked ?? 0;
                const bounces = item.bounces ?? 0;
                return `<tr><td>${ts}</td><td>${list}</td><td>${checked}</td><td>${bounces}</td></tr>`;
            }).join('');
            tableBody.innerHTML = rows;
        };

        const loadHistory = async () => {
            const res = await fetch('/marketing/lists/sweep/history');
            if (!res.ok) return;
            const data = await res.json();
            render(data);
        };

        btn.addEventListener('click', loadHistory);
        loadHistory();
    })();
    </script>
    <div class="marketing-grid" style="margin-top:12px;">
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Enviados com sucesso</span>
            <div class="stat-value" style="color:#22c55e;">
                <?= format_int((int)$deliveryStats['sent']); ?>
            </div>
            <small style="color:var(--muted);">Inclui disparos aceitos pelo servidor</small>
        </div>
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Soft bounce (bloqueados)</span>
            <div class="stat-value" style="color:#facc15;">
                <?= format_int((int)$deliveryStats['soft_bounce']); ?>
            </div>
            <small style="color:var(--muted);">Contatos rejeitados temporariamente ou na pré-checagem</small>
        </div>
        <div class="marketing-card" style="box-shadow:none;">
            <span style="color:var(--muted);font-size:0.8rem;">Hard bounce (bloqueados)</span>
            <div class="stat-value" style="color:#f87171;">
                <?= format_int((int)$deliveryStats['hard_bounce']); ?>
            </div>
            <small style="color:var(--muted);">Inclui domínios sem MX e contatos suprimidos</small>
        </div>
    </div>
    <div class="panel" style="margin-top:12px;">
        <form method="post" action="<?= url('marketing/lists/suppress-upload'); ?>" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <?= csrf_field(); ?>
            <div style="display:flex;flex-direction:column;gap:6px;min-width:240px;">
                <label style="font-weight:600;">Importar lista negativa (TXT/CSV)</label>
                <input type="file" name="suppress_file" accept=".txt,.csv" required>
                <small style="color:var(--muted);">Um e-mail por linha; também aceita separado por vírgula/ponto e vírgula.</small>
            </div>
            <button type="submit" class="primary">Aplicar supressão</button>
        </form>
    </div>
</section>

<section class="panel" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Últimos envios com sucesso</h2>
        <small style="color:var(--muted);">Mostrando até 50 registros</small>
    </div>
    <?php if ($sentRecent === []): ?>
        <p style="color:var(--muted);">Nenhum envio registrado.</p>
    <?php else: ?>
        <table class="panel-table" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Destinatário</th>
                    <th>Evento</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sentRecent as $row): ?>
                    <?php
                        $payload = $row['payload'] ?? [];
                        $recipient = $payload['recipient'] ?? ($payload['email'] ?? '');
                        $date = isset($row['occurred_at']) ? date('d/m/Y H:i', (int)$row['occurred_at']) : '';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($recipient ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string)($row['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Envios agendados</h2>
        <small style="color:var(--muted);">Próximos horários programados</small>
    </div>
    <?php if ($scheduledEmails === []): ?>
        <p style="color:var(--muted);">Nenhum envio agendado.</p>
    <?php else: ?>
        <table class="panel-table" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Assunto</th>
                    <th>Destinatários</th>
                    <th>Agendado para</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scheduledEmails as $row): ?>
                    <?php
                        $toList = json_decode((string)($row['to_recipients'] ?? '[]'), true) ?: [];
                        $first = $toList[0]['email'] ?? null;
                        $total = count($toList);
                        $recipientsLabel = $first ? htmlspecialchars($first, ENT_QUOTES, 'UTF-8') : '—';
                        if ($total > 1) {
                            $recipientsLabel .= ' +' . ($total - 1);
                        }
                        $whenTs = isset($row['scheduled_for']) ? (int)$row['scheduled_for'] : null;
                        $when = fmt_datetime($whenTs);
                        $id = (int)($row['id'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string)($row['subject'] ?? '(sem assunto)'), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td><?= $recipientsLabel; ?></td>
                        <td>
                            <form method="post" action="<?= url('marketing/scheduled/' . $id . '/update'); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <?= csrf_field(); ?>
                                <input type="datetime-local" name="scheduled_for" value="<?= $whenTs ? date('Y-m-d\TH:i', $whenTs) : ''; ?>" style="max-width:200px;">
                                <button type="submit" class="ghost" style="padding:6px 12px;">Salvar</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="<?= url('marketing/scheduled/' . $id . '/cancel'); ?>" onsubmit="return confirm('Cancelar este agendamento?');" style="margin:0;">
                                <?= csrf_field(); ?>
                                <button type="submit" class="ghost" style="color:#f87171;padding:6px 12px;">Cancelar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Últimos erros (soft/hard)</h2>
        <small style="color:var(--muted);">Mostrando até 50 registros</small>
    </div>
    <?php if ($errorRecent === []): ?>
        <p style="color:var(--muted);">Nenhum erro registrado.</p>
    <?php else: ?>
        <table class="panel-table" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Destinatário</th>
                    <th>Tipo</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($errorRecent as $row): ?>
                    <?php
                        $payload = $row['payload'] ?? [];
                        $recipient = $payload['recipient'] ?? ($payload['email'] ?? '');
                        $reason = $payload['reason'] ?? ($payload['error'] ?? '');
                        $date = isset($row['occurred_at']) ? date('d/m/Y H:i', (int)$row['occurred_at']) : '';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($recipient ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string)($row['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($reason ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($feedback !== null): ?>
    <div class="panel" style="margin-top:18px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#f87171'; ?>;">
        <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
<?php endif; ?>

<section class="panel" style="margin-top:18px;">
    <?php if ($lists === []): ?>
        <p style="color:var(--muted);">Nenhuma lista foi cadastrada. Comece criando uma lista acima.</p>
    <?php else: ?>
        <table class="list-accordion">
            <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th>Lista</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Subscritos</th>
                    <th>Descadastrados</th>
                    <th>Entregues</th>
                    <th>Soft</th>
                    <th>Hard</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lists as $list): ?>
                    <?php $status = strtolower((string)($list['status'] ?? 'active')); $rowId = 'list-row-' . (int)($list['id'] ?? 0); ?>
                    <tr class="summary-row" data-target="<?= $rowId; ?>">
                        <td><span class="chevron">▶</span></td>
                        <td>
                            <strong><?= htmlspecialchars($list['name'] ?? 'Lista', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                            <small style="color:var(--muted);">Slug: <?= htmlspecialchars($list['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td><span class="list-pill <?= $status; ?>"><?= $statusLabels[$status] ?? ucfirst($status); ?></span></td>
                        <td><?= format_int((int)($list['contacts_total'] ?? 0)); ?></td>
                        <td style="color:#22c55e; font-weight:600;">&nbsp;<?= format_int((int)($list['contacts_subscribed'] ?? 0)); ?></td>
                        <td style="color:#f87171; font-weight:600;">&nbsp;<?= format_int((int)($list['contacts_unsubscribed'] ?? 0)); ?></td>
                        <td style="color:#22c55e;">&nbsp;<?= format_int((int)($list['contacts_sent'] ?? 0)); ?></td>
                        <td style="color:#facc15;">&nbsp;<?= format_int((int)($list['contacts_soft_bounce'] ?? 0)); ?></td>
                        <td style="color:#f87171;">&nbsp;<?= format_int((int)($list['contacts_hard_bounce'] ?? 0)); ?></td>
                    </tr>
                    <tr id="<?= $rowId; ?>" class="detail-row" data-open="false" style="background:rgba(15,23,42,0.4);">
                        <td></td>
                        <td colspan="8" style="padding:14px 16px;">
                            <?php if (!empty($list['description'])): ?>
                                <p style="margin:0 0 10px 0;color:var(--muted);">
                                    <?= htmlspecialchars($list['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            <?php endif; ?>
                            <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                <a class="ghost" href="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/import'); ?>">Importar contatos</a>
                                <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/pdf-test'); ?>" style="margin:0;">
                                    <?= csrf_field(); ?>
                                    <button type="submit" class="ghost">Teste PDF</button>
                                </form>
                                <a class="ghost" href="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/edit'); ?>">Editar</a>
                                <?php $slug = strtolower((string)($list['slug'] ?? '')); if (in_array($slug, $autoMaintenanceSlugs, true)): ?>
                                    <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/refresh'); ?>" style="margin:0;">
                                        <?= csrf_field(); ?>
                                        <button type="submit" class="ghost" style="color:#38bdf8;">Manutenção da lista</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status !== 'archived'): ?>
                                    <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/archive'); ?>" onsubmit="return confirm('Arquivar esta lista? Contatos permanecem preservados.');" style="margin:0;">
                                        <?= csrf_field(); ?>
                                        <button type="submit" class="ghost" style="color:#f97316;">Arquivar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Segmentos em destaque</h2>
        <a class="ghost" href="<?= url('marketing/segments'); ?>">Ver todos</a>
    </div>
    <?php if ($segmentsPreview === []): ?>
        <p style="color:var(--muted);">Cadastre seu primeiro segmento para visualizar regras e estimativas.</p>
    <?php else: ?>
        <table class="panel-table" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Lista</th>
                    <th>Status</th>
                    <th>Contatos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segmentsPreview as $segment): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($segment['name'] ?? 'Segmento', ENT_QUOTES, 'UTF-8'); ?></strong><br><small style="color:var(--muted);">Slug: <?= htmlspecialchars($segment['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                        <td><?= isset($segment['list_name']) ? htmlspecialchars($segment['list_name'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td><?= htmlspecialchars(ucfirst((string)($segment['status'] ?? 'draft')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= number_format((int)($segment['contacts_total'] ?? 0), 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
