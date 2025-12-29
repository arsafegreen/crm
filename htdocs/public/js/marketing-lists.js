(function () {
    const config = window.MarketingListsConfig || {};
    document.addEventListener('DOMContentLoaded', function () {
        initBounceSweep(config);
        initSuppressionCard(config);
        initSweepHistory();
        initListAccordion();
    });

    function initBounceSweep(cfg) {
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

        if (!statusDot || !statusLabel || !startBtn || !stopBtn) {
            return;
        }

        const csrf = cfg.csrf || getCsrfToken();
        let processing = false;
        let processTimer = null;
        let lastStatus = null;

        const ensureProcessLoop = () => {
            if (processTimer !== null) {
                return;
            }
            processTimer = setInterval(() => {
                if (statusLabel.dataset.state !== 'running') {
                    return;
                }
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
            if (processing) {
                return;
            }
            processing = true;
            try {
                const body = new URLSearchParams();
                body.append('_token', csrf);
                await fetch('/marketing/lists/sweep/process', { method: 'POST', body });
            } finally {
                processing = false;
            }
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
                if (progressEl) progressEl.textContent = data.checked ?? data.progress ?? 0;
                if (totalEl) totalEl.textContent = data.total ?? 0;
                if (foundEl) foundEl.textContent = data.bounces ?? 0;
                if (messageEl) messageEl.textContent = data.message ?? '';
            } catch (error) {
                console.error('Falha ao ler status da varredura', error);
            }
        };

        const startSweep = async () => {
            const body = new URLSearchParams();
            body.append('_token', csrf);
            body.append('list_id', selectEl && selectEl.value ? selectEl.value : '');
            if (externalEl && externalEl.checked) {
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
        setStatusUi(cfg.initialSweepStatus || 'stopped');
        updateStatus();
        setInterval(updateStatus, 5000);
    }

    function initSuppressionCard(cfg) {
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

        if (!card || !tableBody || !searchEl || !searchBtn || !pageEl || !prevBtn || !nextBtn) {
            return;
        }

        const csrf = cfg.csrf || getCsrfToken();
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
                return `
                    <tr>
                        <td>${email}</td>
                        <td>${reason}</td>
                        <td>${updated}</td>
                        <td><button data-id="${id}" class="ghost suppress-restore" style="padding:4px 8px;">Restaurar</button></td>
                    </tr>
                `;
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
            body.append('_token', csrf);
            body.append('id', id);
            const res = await fetch('/marketing/lists/suppressions/unsuppress', { method: 'POST', body });
            if (res.ok) {
                loadSuppressed();
            }
        });

        const setCollapsed = (value) => {
            if (!content || !toggleBtn) return;
            card.dataset.collapsed = value ? 'true' : 'false';
            toggleBtn.textContent = value ? 'Mostrar detalhes' : 'Ocultar detalhes';
        };

        const toggleCollapsed = () => {
            const collapsed = card.dataset.collapsed !== 'false';
            setCollapsed(!collapsed);
        };

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

        card.dataset.collapsed = 'true';
        setCollapsed(true);
        loadSuppressed();
    }

    function initSweepHistory() {
        const tableBody = document.querySelector('#history-table tbody');
        const reloadBtn = document.getElementById('history-reload');
        if (!tableBody || !reloadBtn) {
            return;
        }

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

        reloadBtn.addEventListener('click', loadHistory);
        loadHistory();
    }

    function initListAccordion() {
        const rows = document.querySelectorAll('.list-accordion .summary-row');
        if (!rows.length) {
            return;
        }

        rows.forEach((row) => {
            row.addEventListener('click', () => {
                const targetId = row.getAttribute('data-target');
                const detail = targetId ? document.getElementById(targetId) : null;
                const chevron = row.querySelector('.chevron');
                if (!detail) {
                    return;
                }
                const isOpen = detail.getAttribute('data-open') === 'true';
                detail.style.display = isOpen ? 'none' : 'table-row';
                detail.setAttribute('data-open', isOpen ? 'false' : 'true');
                if (chevron) {
                    chevron.classList.toggle('open', !isOpen);
                }
            });
        });
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name=\"csrf-token\"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }
})();
