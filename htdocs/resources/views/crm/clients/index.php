<?php
/** @var array $clients */
/** @var array $pagination */
/** @var array $filters */
/** @var array $statusLabels */
/** @var int $perPage */
/** @var array $yearOptions */
/** @var string|null $viewMode */
/** @var string|null $offScope */
/** @var array $partnerIndicators */
/** @var array $meta */
/** @var array<int, array> $pipelineStages */
/** @var array<string, array{label:string,color:string}> $clientMarkTypes */
/** @var int $clientMarkTtlHours */

$currentPage = (int)($pagination['page'] ?? 1);
$totalPages = (int)($pagination['pages'] ?? 1);

$viewMode = $viewMode ?? 'active';
$isOffView = $viewMode === 'off';
$offScope = $offScope ?? ($filters['off_scope'] ?? ($isOffView ? 'off' : 'active'));
$baseRoute = $isOffView ? 'crm/clients/off' : 'crm/clients';
$baseUrl = url($baseRoute);

$filtersApplied = (int)($filters['applied'] ?? 0) === 1;
$filtersBlocked = (int)($filters['blocked'] ?? 0) === 1;

$buildQuery = static function (array $overrides = []) use ($filters, $perPage, $filtersApplied): string {
    $params = array_merge(
        [
            'status' => $filters['status'] ?? '',
            'query' => $filters['query'] ?? '',
            'partner' => $filters['partner'] ?? '',
            'pipeline_stage_id' => $filters['pipeline_stage_id'] ?? '',
            'per_page' => $perPage,
            'expiration_window' => $filters['expiration_window'] ?? '',
            'expiration_month' => $filters['expiration_month'] ?? '',
            'expiration_scope' => ($filters['expiration_scope'] ?? '') === 'all' ? 'all' : '',
            'expiration_year' => (int)($filters['expiration_month'] ?? 0) > 0 ? ($filters['expiration_year'] ?? '') : '',
            'expiration_date' => $filters['expiration_date'] ?? '',
            'document_type' => $filters['document_type'] ?? '',
            'birthday_month' => (int)($filters['birthday_month'] ?? 0) > 0 ? ($filters['birthday_month'] ?? '') : '',
            'birthday_day' => (int)($filters['birthday_day'] ?? 0) > 0 ? ($filters['birthday_day'] ?? '') : '',
            'off_scope' => $filters['off_scope'] ?? '',
            'last_avp_name' => $filters['last_avp_name'] ?? '',
            'applied' => $filtersApplied ? '1' : '',
        ],
        $overrides
    );

    $params = array_filter($params, static function ($value): bool {
        return !($value === null || $value === '');
    });

    return http_build_query($params);
};

$statusBadges = [
    'active' => 'background:rgba(34,197,94,0.12);color:#4ade80;border:1px solid rgba(34,197,94,0.3);',
    'recent_expired' => 'background:rgba(249,115,22,0.12);color:#fb923c;border:1px solid rgba(249,115,22,0.3);',
    'inactive' => 'background:rgba(148,163,184,0.12);color:#cbd5f5;border:1px solid rgba(148,163,184,0.28);',
    'lost' => 'background:rgba(248,113,113,0.14);color:#f87171;border:1px solid rgba(248,113,113,0.28);',
    'prospect' => 'background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.28);',
];

$documentStatusLabels = [
    'active' => 'Documento ativo',
    'dropped' => 'Documento baixado',
];

$documentStatusStyles = [
    'active' => 'background:rgba(34,197,94,0.12);color:#4ade80;border:1px solid rgba(34,197,94,0.28);',
    'dropped' => 'background:rgba(248,113,113,0.14);color:#f87171;border:1px solid rgba(248,113,113,0.28);',
];

$partnerIndicators = $partnerIndicators ?? [];
$hasPartnerIndicators = $partnerIndicators !== [];
$pipelineStages = $pipelineStages ?? [];
$selectedPipelineStageId = (int)($filters['pipeline_stage_id'] ?? 0);
$clientMarkTypes = $clientMarkTypes ?? [];
$clientMarkTtlHours = isset($clientMarkTtlHours) ? max(1, (int)$clientMarkTtlHours) : 48;
$clientMarkTtlSeconds = $clientMarkTtlHours * 3600;
$clientMarkCacheKey = 'crm_client_mark_cache';
$nowTimestamp = time();

$partnerPickerStyles = <<<CSS
.partner-picker {position:relative;display:flex;flex-direction:column;gap:10px;}
.partner-picker__controls {display:flex;align-items:center;gap:10px;}
.partner-picker__controls input {flex:1;}
.partner-picker__toggle {padding:12px 16px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(56,189,248,0.15);color:var(--accent);font-weight:600;cursor:pointer;transition:background 0.2s ease,border-color 0.2s ease;}
.partner-picker__toggle:hover {background:rgba(56,189,248,0.28);border-color:rgba(56,189,248,0.55);}
.partner-picker__toggle .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
.partner-picker__dropdown {position:absolute;z-index:20;top:100%;left:0;right:0;margin-top:8px;border-radius:14px;border:1px solid rgba(148,163,184,0.3);background:rgba(15,23,42,0.95);backdrop-filter:blur(18px);box-shadow:0 20px 40px rgba(15,23,42,0.55);padding:16px;display:none;}
.partner-picker--open .partner-picker__dropdown {display:block;}
.partner-picker__search {margin-bottom:12px;}
.partner-picker__search input {width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,0.3);background:rgba(15,23,42,0.7);color:var(--text);}
.partner-picker__list {max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;scrollbar-color:rgba(56,189,248,0.6) rgba(15,23,42,0.6);}
.partner-picker__list::-webkit-scrollbar {width:8px;}
.partner-picker__list::-webkit-scrollbar-thumb {background:rgba(56,189,248,0.6);border-radius:999px;}
.partner-picker__option {display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-radius:10px;border:1px solid rgba(56,189,248,0.3);background:rgba(56,189,248,0.12);color:var(--text);font-size:0.9rem;font-weight:600;text-align:left;cursor:pointer;transition:background 0.2s ease,border-color 0.2s ease,transform 0.18s ease;}
.partner-picker__option:hover {background:rgba(56,189,248,0.24);border-color:rgba(56,189,248,0.55);transform:translateX(2px);}
.partner-picker__option span {font-size:0.75rem;color:var(--muted);font-weight:500;margin-left:12px;}
.partner-picker__empty {padding:18px 12px;border-radius:10px;border:1px dashed rgba(148,163,184,0.3);color:var(--muted);text-align:center;font-size:0.85rem;}
CSS;

if ($hasPartnerIndicators):
    echo '<style>' . $partnerPickerStyles . '</style>';
endif;

$expirationWindowOptions = [
    '' => 'Qualquer vencimento',
    'next_30' => 'Vence em 30 dias',
    'next_20' => 'Vence em 20 dias',
    'next_10' => 'Vence em 10 dias',
    'today' => 'Vence hoje',
    'past_10' => 'Venceu há 10 dias',
    'past_20' => 'Venceu há 20 dias',
    'past_30' => 'Venceu há 30 dias',
];

$monthOptions = [
    1 => 'Janeiro',
    2 => 'Fevereiro',
    3 => 'Março',
    4 => 'Abril',
    5 => 'Maio',
    6 => 'Junho',
    7 => 'Julho',
    8 => 'Agosto',
    9 => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro',
];

$dayOptions = range(1, 31);

$selectedBirthdayMonth = (int)($filters['birthday_month'] ?? 0);
if ($selectedBirthdayMonth < 1 || $selectedBirthdayMonth > 12) {
    $selectedBirthdayMonth = 0;
}

$selectedBirthdayDay = (int)($filters['birthday_day'] ?? 0);
if ($selectedBirthdayDay < 1 || $selectedBirthdayDay > 31) {
    $selectedBirthdayDay = 0;
}

if ($selectedBirthdayMonth === 0) {
    $selectedBirthdayDay = 0;
}

$selectedExpirationWindow = (string)($filters['expiration_window'] ?? '');
$selectedExpirationMonth = (int)($filters['expiration_month'] ?? 0);
$selectedExpirationScope = (string)($filters['expiration_scope'] ?? 'current');
if (!in_array($selectedExpirationScope, ['current', 'all'], true)) {
    $selectedExpirationScope = 'current';
}
$selectedExpirationYear = (int)($filters['expiration_year'] ?? (int)date('Y'));
if (!in_array($selectedExpirationYear, array_map('intval', $yearOptions), true)) {
    $yearOptions[] = $selectedExpirationYear;
    sort($yearOptions);
}
$selectedDocumentType = (string)($filters['document_type'] ?? '');
if (!in_array($selectedDocumentType, ['', 'cpf', 'cnpj'], true)) {
    $selectedDocumentType = '';
}
?>

<header>
    <div>
        <h1><?= $isOffView ? 'Carteira de clientes (off)' : 'Carteira de clientes'; ?></h1>
        <p><?= $isOffView
            ? 'Clientes fora da carteira principal. Reative quando houver nova oportunidade.'
            : 'Liste, filtre e abra os detalhes dos clientes para agir em renovações e prospecções.'; ?></p>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:flex-end;">
        <a href="<?= url('crm'); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;">&larr; Visão geral</a>
        <a href="<?= url('crm/clients/create'); ?>" style="text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px;background:rgba(34,197,94,0.18);border:1px solid rgba(34,197,94,0.28);color:#4ade80;">Novo cliente</a>
        <a href="<?= $isOffView ? url('crm/clients') : url('crm/clients/off'); ?>" style="text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px;background:rgba(56,189,248,0.18);border:1px solid rgba(56,189,248,0.28);color:var(--accent);">
            <?= $isOffView ? 'Voltar para carteira principal' : 'Abrir carteira off'; ?>
        </a>
    </div>
</header>

<?php if ($isOffView): ?>
    <div class="panel" style="margin-bottom:24px;border-left:4px solid rgba(148,163,184,0.45);">
        <strong style="display:block;margin-bottom:6px;">Carteira off</strong>
        <p style="margin:0;color:var(--muted);font-size:0.9rem;">
            Clientes arquivados manualmente ficam fora das métricas e da carteira principal. Ao importar um certificado novo, eles retornam automaticamente.
        </p>
    </div>
<?php endif; ?>

<?php if ($clientMarkTypes !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cacheKey = '<?= $clientMarkCacheKey; ?>';
    const markMeta = <?= json_encode($clientMarkTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultTtlSeconds = <?= (int)$clientMarkTtlSeconds; ?>;

    const readCache = function () {
        try {
            const raw = localStorage.getItem(cacheKey);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const writeCache = function (cache) {
        try {
            if (Object.keys(cache).length === 0) {
                localStorage.removeItem(cacheKey);
            } else {
                localStorage.setItem(cacheKey, JSON.stringify(cache));
            }
        } catch (error) {
            // ignore storage failures
        }
    };

    const formatExpiryLabel = function (expiresAt) {
        if (!expiresAt) {
            return '';
        }
        const date = new Date(expiresAt * 1000);
        const pad = function (value) {
            return String(value).padStart(2, '0');
        };
        return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    };

    const formatCountdown = function (expiresAt) {
        if (!expiresAt) {
            return '--';
        }
        const diff = expiresAt - Math.floor(Date.now() / 1000);
        if (diff <= 0) {
            return 'exp.';
        }
        const hours = Math.ceil(diff / 3600);
        if (hours >= 24) {
            const days = Math.floor(hours / 24);
            const remainder = hours % 24;
            return remainder > 0 ? days + 'd ' + remainder + 'h' : days + 'd';
        }
        return hours + 'h';
    };

    const renderBadge = function (container, type, expiresAt, isLocal) {
        const meta = markMeta[type];
        if (!meta || !container) {
            return;
        }

        if (!container.style.display || container.style.display === 'none') {
            container.style.display = 'flex';
        }

        const badge = document.createElement('span');
        badge.setAttribute('data-mark-type', type);
        if (expiresAt) {
            badge.setAttribute('data-expires-at', String(expiresAt));
        }
        if (isLocal) {
            badge.setAttribute('data-local-mark', '1');
        }
        badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;border:1px solid rgba(148,163,184,0.28);background:rgba(15,23,42,0.45);color:var(--muted);font-size:0.72rem;font-weight:600;';

        const dot = document.createElement('span');
        dot.setAttribute('aria-hidden', 'true');
        dot.style.cssText = 'width:10px;height:10px;border-radius:999px;background:' + meta.color + ';';

        const label = document.createElement('span');
        label.style.cssText = 'color:var(--text);font-size:0.7rem;letter-spacing:0.02em;';
        const countdownLabel = formatCountdown(expiresAt);
        const expiryLabel = formatExpiryLabel(expiresAt);
        label.textContent = countdownLabel !== '--' ? countdownLabel : (expiryLabel || '--');

        const titleParts = [meta.label];
        if (expiryLabel) {
            titleParts.push('expira ' + expiryLabel);
        }
        badge.title = titleParts.join(' • ');

        badge.appendChild(dot);
        badge.appendChild(label);
        container.appendChild(badge);
    };

    const hydrateBadges = function () {
        let cache = readCache();
        const nowSeconds = Math.floor(Date.now() / 1000);
        let cacheDirty = false;

        const pruneExpired = function () {
            Object.keys(cache).forEach(function (clientId) {
                const marks = cache[clientId];
                Object.keys(marks).forEach(function (type) {
                    const expiresAt = parseInt(marks[type]?.expires_at ?? 0, 10);
                    if (!expiresAt || expiresAt <= nowSeconds) {
                        delete marks[type];
                        cacheDirty = true;
                    }
                });
                if (Object.keys(marks).length === 0) {
                    delete cache[clientId];
                    cacheDirty = true;
                }
            });
        };

        pruneExpired();

        const containers = document.querySelectorAll('[data-client-mark-badges]');
        containers.forEach(function (container) {
            const clientId = container.getAttribute('data-client-id');
            if (!clientId) {
                return;
            }

            container.querySelectorAll('[data-local-mark="1"]').forEach(function (badge) {
                badge.remove();
            });

            const pending = cache[clientId];
            const existingTypes = new Set();

            container.querySelectorAll('[data-mark-type]').forEach(function (badge) {
                const type = badge.getAttribute('data-mark-type');
                if (type) {
                    existingTypes.add(type);
                    if (pending && pending[type]) {
                        delete pending[type];
                        cacheDirty = true;
                    }
                }
            });

            if (!pending) {
                if (container.children.length === 0) {
                    container.style.display = 'none';
                }
                return;
            }

            Object.keys(pending).forEach(function (type) {
                if (existingTypes.has(type)) {
                    delete pending[type];
                    cacheDirty = true;
                    return;
                }

                const expiresAt = parseInt(pending[type]?.expires_at ?? 0, 10) || (Math.floor(Date.now() / 1000) + defaultTtlSeconds);
                renderBadge(container, type, expiresAt, true);
                delete pending[type];
                cacheDirty = true;
            });

            if (pending && Object.keys(pending).length === 0) {
                delete cache[clientId];
            }
        });

        if (cacheDirty) {
            writeCache(cache);
        }
    };

    hydrateBadges();

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            hydrateBadges();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            hydrateBadges();
        }
    });
});
</script>
<?php endif; ?>

<div class="panel" style="margin-bottom:28px;">
    <form method="get" action="<?= $baseUrl; ?>" style="display:grid;gap:16px;">
    <input type="hidden" name="page" value="1">
    <input type="hidden" name="applied" value="1">
        <input type="hidden" name="off_scope" value="<?= htmlspecialchars($offScope, ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Busca rápida
                <input type="text" name="query" value="<?= htmlspecialchars($filters['query'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome, documento, protocolo ou titular" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Status
                <select name="status" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= ($filters['status'] ?? '') === (string)$value ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Funil / etapa
                <select name="pipeline_stage_id" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="0" <?= $selectedPipelineStageId === 0 ? 'selected' : ''; ?>>Todos os funis</option>
                    <?php foreach ($pipelineStages as $stage):
                        $stageId = (int)($stage['id'] ?? 0);
                        if ($stageId === 0) {
                            continue;
                        }
                        $stageLabel = $stage['name'] ?? ('Etapa #' . $stageId);
                    ?>
                        <option value="<?= $stageId; ?>" <?= $selectedPipelineStageId === $stageId ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Parceiro/contador
                <?php if ($hasPartnerIndicators): ?>
                    <div class="partner-picker" data-partner-picker>
                        <div class="partner-picker__controls">
                            <input type="text" name="partner" value="<?= htmlspecialchars($filters['partner'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do parceiro" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);" data-partner-input>
                            <button type="button" class="partner-picker__toggle" data-partner-toggle aria-haspopup="listbox" aria-expanded="false" aria-label="Selecionar indicação" title="Selecionar indicação">
                                <span aria-hidden="true">🔍</span>
                                <span class="sr-only">Selecionar indicação</span>
                            </button>
                        </div>
                        <div class="partner-picker__dropdown" data-partner-dropdown aria-hidden="true">
                            <div class="partner-picker__search">
                                <input type="search" placeholder="Buscar parceiro indicado" data-partner-search>
                            </div>
                            <div class="partner-picker__list" role="listbox">
                                <?php foreach ($partnerIndicators as $indicator): ?>
                                    <?php
                                        $indicatorName = htmlspecialchars($indicator['name'], ENT_QUOTES, 'UTF-8');
                                        $indicatorTotal = (int)$indicator['total'];
                                        $indicatorLabel = $indicatorTotal === 1 ? '1 indicação' : $indicatorTotal . ' indicações';
                                    ?>
                                    <button type="button" class="partner-picker__option" data-partner-option data-name="<?= $indicatorName; ?>">
                                        <span><?= $indicatorName; ?></span>
                                        <span><?= htmlspecialchars($indicatorLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </button>
                                <?php endforeach; ?>
                                <div class="partner-picker__empty" data-partner-empty hidden>Nenhum parceiro encontrado.</div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="text" name="partner" value="<?= htmlspecialchars($filters['partner'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do parceiro" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Último AVP
                <input type="text" name="last_avp_name" value="<?= htmlspecialchars($filters['last_avp_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do AVP" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Itens por página
                <select name="per_page" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ([10, 25, 50, 100] as $option): ?>
                        <option value="<?= $option; ?>" <?= (int)$perPage === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Tipo de documento
                <select name="document_type" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="" <?= $selectedDocumentType === '' ? 'selected' : ''; ?>>CPF e CNPJ</option>
                    <option value="cpf" <?= $selectedDocumentType === 'cpf' ? 'selected' : ''; ?>>Somente CPF</option>
                    <option value="cnpj" <?= $selectedDocumentType === 'cnpj' ? 'selected' : ''; ?>>Somente CNPJ</option>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Vencimento (janela)
                <select name="expiration_window" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ($expirationWindowOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= $selectedExpirationWindow === (string)$value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Data específica do vencimento
                <input type="date" name="expiration_date" value="<?= htmlspecialchars($filters['expiration_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                <span style="font-size:0.75rem;color:rgba(148,163,184,0.9);">Prioriza um único dia e ignora janela e mês.</span>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Vencimento no mês
                <select name="expiration_month" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="0" <?= $selectedExpirationMonth === 0 ? 'selected' : ''; ?>>Todos os meses</option>
                    <?php foreach ($monthOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= $selectedExpirationMonth === (int)$value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Referência do mês
                <select name="expiration_scope" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="current" <?= $selectedExpirationScope === 'current' ? 'selected' : ''; ?>>Somente ano selecionado</option>
                    <option value="all" <?= $selectedExpirationScope === 'all' ? 'selected' : ''; ?>>Todos os anos</option>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Ano de referência
                <select name="expiration_year" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ($yearOptions as $yearOption): ?>
                        <option value="<?= (int)$yearOption; ?>" <?= (int)$yearOption === $selectedExpirationYear ? 'selected' : ''; ?>><?= (int)$yearOption; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Mês do aniversário
                <select name="birthday_month" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="0" <?= $selectedBirthdayMonth === 0 ? 'selected' : ''; ?>>Todos os meses</option>
                    <?php foreach ($monthOptions as $value => $label): ?>
                        <option value="<?= (int)$value; ?>" <?= $selectedBirthdayMonth === (int)$value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:0.75rem;color:rgba(148,163,184,0.9);">Escolha um mês para listar aniversariantes.</span>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Dia específico (opcional)
                <select name="birthday_day" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="0" <?= $selectedBirthdayDay === 0 ? 'selected' : ''; ?>>Todos os dias</option>
                    <?php foreach ($dayOptions as $dayOption): ?>
                        <option value="<?= (int)$dayOption; ?>" <?= $selectedBirthdayDay === (int)$dayOption ? 'selected' : ''; ?>><?= str_pad((string)$dayOption, 2, '0', STR_PAD_LEFT); ?></option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:0.75rem;color:rgba(148,163,184,0.9);">Selecione um mês acima para ativar o filtro por dia.</span>
            </label>
        </div>
        <div style="display:flex;gap:12px;justify-content:flex-end;align-items:center;">
            <?php
                $hasFilters = ($filters['query'] ?? '') !== ''
                    || ($filters['status'] ?? '') !== ''
                    || ($filters['partner'] ?? '') !== ''
                    || ($filters['last_avp_name'] ?? '') !== ''
                    || (int)($filters['pipeline_stage_id'] ?? 0) > 0
                    || ($filters['expiration_window'] ?? '') !== ''
                    || ($filters['expiration_date'] ?? '') !== ''
                    || (int)($filters['expiration_month'] ?? 0) > 0
                    || ($filters['expiration_scope'] ?? 'current') === 'all'
                    || ($filters['document_type'] ?? '') !== ''
                    || (int)($filters['birthday_month'] ?? 0) > 0;
            ?>
            <?php if ($hasFilters): ?>
                <a href="<?= $baseUrl; ?>" style="color:var(--muted);text-decoration:none;">Limpar</a>
            <?php endif; ?>
            <button class="primary" type="submit">Aplicar filtros</button>
        </div>
    </form>
</div>

<?php if ($filtersApplied): ?>
    <?php
        $resultCount = (int)($meta['count'] ?? count($clients));
        $resultTotal = (int)($meta['total'] ?? $resultCount);
    ?>
    <div class="panel" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;background:rgba(15,23,42,0.6);border:1px solid rgba(56,189,248,0.24);padding:16px 20px;border-radius:14px;">
        <div style="display:flex;flex-direction:column;">
            <strong style="font-size:1rem;">Resultados filtrados</strong>
            <span style="font-size:0.85rem;color:var(--muted);">Mostrando <?= $resultCount; ?> de <?= $resultTotal; ?> clientes que correspondem aos filtros.</span>
        </div>
        <span style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:999px;background:rgba(56,189,248,0.22);color:var(--accent);font-weight:600;font-size:0.9rem;">Total atual: <?= $resultTotal; ?></span>
    </div>
        <?php if ($clientMarkTypes !== []): ?>
            <div class="panel" style="margin-top:16px;margin-bottom:16px;padding:18px 20px;border:1px dashed rgba(148,163,184,0.3);background:rgba(15,23,42,0.45);">
                <strong style="display:block;margin-bottom:8px;font-size:0.9rem;">Marcas rápidas (<?= $clientMarkTtlHours; ?>h)</strong>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:6px;">
                    <?php foreach ($clientMarkTypes as $markMeta): ?>
                        <?php
                            $markLabel = htmlspecialchars($markMeta['label'], ENT_QUOTES, 'UTF-8');
                            $markColor = htmlspecialchars($markMeta['color'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <span style="display:inline-flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--muted);">
                            <span aria-hidden="true" style="width:10px;height:10px;border-radius:999px;background:<?= $markColor; ?>;"></span>
                            <?= $markLabel; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <small style="color:var(--muted);font-size:0.78rem;">Aplicadas automaticamente ao usar os botões do cliente e expiram em <?= $clientMarkTtlHours; ?> horas.</small>
            </div>
        <?php endif; ?>
<div class="panel" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;min-width:920px;">
            <thead>
                <tr style="background:rgba(15,23,42,0.72);text-align:left;font-size:0.78rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);">
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Cliente</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Documento</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Status</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Vencimento</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Parceiro</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Próximo contato</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);">Atualizado</th>
                    <th style="padding:16px 20px;border-bottom:1px solid var(--border);text-align:right;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="8" style="padding:32px 20px;text-align:center;color:var(--muted);">Nenhum cliente encontrado com os filtros atuais.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clients as $client): ?>
                        <?php
                            $clientId = (int)($client['id'] ?? 0);
                            $statusKey = (string)($client['status'] ?? '');
                            $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('_', ' ', $statusKey));
                            $statusStyle = $statusBadges[$statusKey] ?? 'background:rgba(148,163,184,0.12);color:#e2e8f0;border:1px solid rgba(148,163,184,0.24);';
                            $expiresAt = $client['last_certificate_expires_at'] ?? null;
                            $partnerName = $client['partner_accountant_plus'] ?? $client['partner_accountant'] ?? '';
                            $documentRaw = (string)($client['document'] ?? '');
                            $documentFormatted = format_document($documentRaw);
                            $isCnpjDocument = strlen(digits_only($documentRaw)) === 14;
                            $isPartnerIndicator = ((int)($client['is_partner_indicator'] ?? 0)) === 1;
                            $rowBackground = $isPartnerIndicator ? 'background:rgba(34,197,94,0.08);' : '';
                        ?>
                        <tr data-client-row data-client-id="<?= $clientId; ?>" style="border-bottom:1px solid rgba(148,163,184,0.12);<?= $rowBackground; ?>">
                            <td style="padding:18px 20px;">
                                <strong style="display:block;font-size:0.98rem;">
                                    <a href="<?= url('crm/clients/' . $clientId); ?>" style="color:var(--text);text-decoration:none;">
                                        <?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </strong>
                                <?php if (!empty($client['email'])): ?>
                                    <span style="display:block;font-size:0.85rem;color:var(--muted);">
                                        <?= htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($isPartnerIndicator): ?>
                                    <span style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:4px 10px;border-radius:999px;background:rgba(34,197,94,0.18);color:#22c55e;font-size:0.75rem;font-weight:600;">
                                        Indicador parceiro
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:18px 20px;color:var(--muted);font-size:0.9rem;">
                                <?php if ($documentFormatted !== ''): ?>
                                    <?php $documentDisplayStyle = $isCnpjDocument ? 'font-size:0.8rem;letter-spacing:0.01em;white-space:nowrap;' : ''; ?>
                                    <span style="<?= $documentDisplayStyle; ?>"><?= htmlspecialchars($documentFormatted, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span style="color:rgba(148,163,184,0.6);">-</span>
                                <?php endif; ?>
                                <?php
                                    $documentStatus = (string)($client['document_status'] ?? 'active');
                                    $documentStatusLabel = $documentStatusLabels[$documentStatus] ?? ucfirst(str_replace('_', ' ', $documentStatus));
                                    $documentStatusStyle = $documentStatusStyles[$documentStatus] ?? 'background:rgba(148,163,184,0.12);color:#e2e8f0;border:1px solid rgba(148,163,184,0.24);';
                                ?>
                                <span style="display:inline-block;margin-top:6px;padding:4px 10px;border-radius:999px;font-size:0.72rem;font-weight:600;<?= $documentStatusStyle; ?>">
                                    <?= htmlspecialchars($documentStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php
                                    $actionMarks = [];
                                    if (isset($client['action_marks']) && is_array($client['action_marks'])) {
                                        $actionMarks = array_values(array_filter($client['action_marks'], static function ($mark) use ($clientMarkTypes): bool {
                                            return isset($mark['type']) && isset($clientMarkTypes[(string)$mark['type'] ?? '']);
                                        }));
                                    }
                                    $badgeContainerStyle = 'margin-top:8px;display:' . ($actionMarks !== [] ? 'flex' : 'none') . ';gap:8px;flex-wrap:wrap;align-items:center;';
                                ?>
                                <div data-client-mark-badges data-client-id="<?= $clientId; ?>" style="<?= $badgeContainerStyle; ?>">
                                    <?php foreach ($actionMarks as $mark): ?>
                                        <?php
                                            $markType = (string)($mark['type'] ?? '');
                                            $meta = $clientMarkTypes[$markType] ?? null;
                                            if ($meta === null) {
                                                continue;
                                            }
                                            $dotColor = htmlspecialchars($meta['color'], ENT_QUOTES, 'UTF-8');
                                            $dotLabel = $meta['label'] ?? $markType;
                                            $expiresLabel = '';
                                            $countdownLabel = '';
                                            $expiresAtTs = isset($mark['expires_at']) ? (int)$mark['expires_at'] : null;
                                            if ($expiresAtTs !== null) {
                                                $expiresLabel = format_datetime($expiresAtTs, 'd/m H:i');
                                                $secondsLeft = $expiresAtTs - $nowTimestamp;
                                                if ($secondsLeft > 0) {
                                                    $hoursLeft = (int)ceil($secondsLeft / 3600);
                                                    if ($hoursLeft >= 24) {
                                                        $days = intdiv($hoursLeft, 24);
                                                        $hoursRemainder = $hoursLeft % 24;
                                                        $countdownLabel = $days . 'd';
                                                        if ($hoursRemainder > 0) {
                                                            $countdownLabel .= ' ' . $hoursRemainder . 'h';
                                                        }
                                                    } else {
                                                        $countdownLabel = $hoursLeft . 'h';
                                                    }
                                                } else {
                                                    $countdownLabel = 'exp.';
                                                }
                                            }
                                            $titleParts = [$dotLabel];
                                            if ($expiresLabel !== '') {
                                                $titleParts[] = 'expira ' . $expiresLabel;
                                            }
                                            $title = htmlspecialchars(implode(' • ', $titleParts), ENT_QUOTES, 'UTF-8');
                                            $badgeLabel = $countdownLabel !== '' ? $countdownLabel : ($expiresLabel !== '' ? $expiresLabel : '--');
                                        ?>
                                        <span data-mark-type="<?= htmlspecialchars($markType, ENT_QUOTES, 'UTF-8'); ?>" data-expires-at="<?= $expiresAtTs ?? ''; ?>" title="<?= $title; ?>" style="display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;border:1px solid rgba(148,163,184,0.28);background:rgba(15,23,42,0.45);color:var(--muted);font-size:0.72rem;font-weight:600;">
                                            <span aria-hidden="true" style="width:10px;height:10px;border-radius:999px;background:<?= $dotColor; ?>;"></span>
                                            <span style="color:var(--text);font-size:0.7rem;letter-spacing:0.02em;">
                                                <?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td style="padding:18px 20px;">
                                <span style="font-size:0.78rem;font-weight:600;padding:6px 10px;border-radius:999px;display:inline-block;<?= $statusStyle; ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding:18px 20px;color:var(--muted);font-size:0.9rem;">
                                <?= format_date($expiresAt); ?>
                            </td>
                            <td style="padding:18px 20px;color:var(--muted);font-size:0.9rem;">
                                <?php if ($partnerName !== ''): ?>
                                    <span style="font-weight:600;color:<?= $isPartnerIndicator ? '#22c55e' : 'var(--muted)'; ?>;">
                                        <?= htmlspecialchars($partnerName, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:rgba(148,163,184,0.6);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:18px 20px;color:var(--muted);font-size:0.9rem;">
                                <?= format_datetime($client['next_follow_up_at'] ?? null); ?>
                            </td>
                            <td style="padding:18px 20px;color:var(--muted);font-size:0.9rem;">
                                <?= format_datetime($client['updated_at'] ?? null); ?>
                            </td>
                            <td style="padding:18px 20px;text-align:right;">
                                <a href="<?= url('crm/clients/' . $clientId); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;font-size:0.88rem;">Ver detalhes</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <?php
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            if ($endPage - $startPage < 4) {
                $startPage = max(1, $endPage - 4);
                $endPage = min($totalPages, $startPage + 4);
            }
        ?>
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:18px 22px;border-top:1px solid rgba(148,163,184,0.12);background:rgba(10,16,28,0.72);">
            <?php if ($currentPage > 1): ?>
                <?php $prevQuery = $buildQuery(['page' => $currentPage - 1]); ?>
                <a href="<?= $baseUrl . ($prevQuery !== '' ? '?' . $prevQuery : ''); ?>" style="color:var(--muted);text-decoration:none;font-size:0.85rem;">&larr; Anterior</a>
            <?php endif; ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                    <?php $pageQuery = $buildQuery(['page' => $page]); ?>
                    <a href="<?= $baseUrl . ($pageQuery !== '' ? '?' . $pageQuery : ''); ?>"
                       style="padding:6px 12px;border-radius:10px;text-decoration:none;font-size:0.85rem;font-weight:600;<?= $page === $currentPage ? 'background:rgba(56,189,248,0.2);color:var(--accent);border:1px solid rgba(56,189,248,0.4);' : 'color:var(--muted);border:1px solid rgba(148,163,184,0.18);'; ?>">
                        <?= $page; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php if ($currentPage < $totalPages): ?>
                <?php $nextQuery = $buildQuery(['page' => $currentPage + 1]); ?>
                <a href="<?= $baseUrl . ($nextQuery !== '' ? '?' . $nextQuery : ''); ?>" style="color:var(--muted);text-decoration:none;font-size:0.85rem;">Próxima &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="panel" style="padding:32px;text-align:center;color:var(--muted);">
    <?php if ($filtersBlocked): ?>
        Informe pelo menos um critério (busca, status, parceiro, etapa ou vencimento) antes de listar os clientes.
    <?php else: ?>
        Aplique um filtro para visualizar clientes nesta lista.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($hasPartnerIndicators): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var pickers = document.querySelectorAll('[data-partner-picker]');

    pickers.forEach(function (picker) {
        var toggle = picker.querySelector('[data-partner-toggle]');
        var dropdown = picker.querySelector('[data-partner-dropdown]');
        var input = picker.querySelector('[data-partner-input]');
        var search = picker.querySelector('[data-partner-search]');
        var emptyState = picker.querySelector('[data-partner-empty]');
        var options = Array.prototype.slice.call(picker.querySelectorAll('[data-partner-option]'));

        if (!toggle || !dropdown || !input) {
            return;
        }

        var closeDropdown = function () {
            picker.classList.remove('partner-picker--open');
            dropdown.setAttribute('aria-hidden', 'true');
            toggle.setAttribute('aria-expanded', 'false');
        };

        var openDropdown = function () {
            picker.classList.add('partner-picker--open');
            dropdown.setAttribute('aria-hidden', 'false');
            toggle.setAttribute('aria-expanded', 'true');
            if (search) {
                search.value = '';
                search.dispatchEvent(new Event('input', { bubbles: true }));
                search.focus();
            }
        };

        toggle.addEventListener('click', function () {
            if (picker.classList.contains('partner-picker--open')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                var name = option.getAttribute('data-name') || '';
                input.value = name;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closeDropdown();
                input.focus();
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                var term = search.value.trim().toLowerCase();
                var visible = 0;

                options.forEach(function (option) {
                    var candidate = (option.getAttribute('data-name') || '').toLowerCase();
                    var match = term === '' || candidate.indexOf(term) !== -1;
                    option.style.display = match ? '' : 'none';
                    if (match) {
                        visible += 1;
                    }
                });

                if (emptyState) {
                    emptyState.hidden = visible !== 0;
                }
            });
        }

        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDropdown();
            }
        });
    });
});
</script>
<?php endif; ?>
