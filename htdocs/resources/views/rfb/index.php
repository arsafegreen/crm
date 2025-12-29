<?php
use App\Support\RfbWhatsappTemplates;
/** @var array<int, array> $items */
/** @var array $pagination */
/** @var array $filters */
/** @var array|null $stats */
/** @var array $latest */
/** @var array|null $feedback */
/** @var int $perPage */

$feedback = $feedback ?? null;
$filters = $filters ?? ['query' => '', 'state' => '', 'city' => '', 'cnae' => '', 'has_email' => '', 'has_whatsapp' => '', 'status' => 'active'];
$stats = $stats ?? null;
$pagination = $pagination ?? ['page' => 1, 'pages' => 1, 'total' => 0];
$latest = $latest ?? [];
$filtersApplied = (int)($filters['applied'] ?? 0) === 1;
$filtersBlocked = (int)($filters['blocked'] ?? 0) === 1;

$buildQuery = static function (array $overrides = []) use ($filters, $perPage, $filtersApplied): string {
    $params = array_merge(
        [
            'query' => $filters['query'] ?? '',
            'state' => $filters['state'] ?? '',
            'city' => $filters['city'] ?? '',
            'cnae' => $filters['cnae'] ?? '',
            'has_email' => $filters['has_email'] ?? '',
            'has_whatsapp' => $filters['has_whatsapp'] ?? '',
            'status' => $filters['status'] ?? 'active',
            'per_page' => $perPage,
            'applied' => $filtersApplied ? '1' : '',
        ],
        $overrides
    );

    $params = array_filter($params, static fn($value) => !($value === null || $value === ''));

    return http_build_query($params);
};

$currentQuery = $buildQuery(['page' => $pagination['page'] ?? 1]);
$statusReasons = [
    'email_error' => 'E-mail com erro',
    'complaint' => 'Reclamação',
    'missing_contact' => 'Sem contato',
    'manual' => 'Definido manualmente',
];

$formatPhone = static function ($ddd, $phone): string {
    $digits = digits_only((string)($ddd ?? '') . (string)($phone ?? ''));
    if ($digits === '') {
        return '-';
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }

    return $digits;
};
$whatsappTemplates = $whatsappTemplates ?? ['partnership' => '', 'general' => ''];

$formatBirthdateLabelValue = static function (?int $timestamp): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '';
    }

    return date('d/m/Y', $timestamp);
};

$formatBirthdateInputValue = static function (?int $timestamp): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '';
    }

    return gmdate('Y-m-d', $timestamp);
};

$rfbApplyWhatsappTemplate = static function (array $record, string $template): string {
    return RfbWhatsappTemplates::render($record, $template);
};

$rfbBuildWhatsappLink = static function (?string $digits, string $message, bool $allowed = true): ?string {
    if (!$allowed) {
        return null;
    }

    $cleanDigits = digits_only((string)$digits);
    if (strlen($cleanDigits) < 10) {
        return null;
    }

    $text = trim($message);
    $encoded = $text === '' ? '' : '?text=' . rawurlencode($text);

    return 'https://wa.me/55' . $cleanDigits . $encoded;
};

$rfbPartnershipEligible = static function (?string $cnae): bool {
    $code = digits_only((string)$cnae);
    return in_array($code, ['6920601', '6920602'], true);
};
?>

<style>
    .rfb-page header {
        flex-wrap: wrap;
        align-items: flex-start;
    }
    .rfb-page header > div:first-child {
        flex: 1 1 320px;
    }
    .rfb-summary-grid {
        display: grid;
        gap: 14px;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    }
    .rfb-filters {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .rfb-picker {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .rfb-picker__controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .rfb-picker__controls input {
        flex: 1;
    }
    .rfb-picker__button {
        padding: 12px 16px;
        border-radius: 12px;
        border: 1px solid rgba(56,189,248,0.4);
        background: rgba(56,189,248,0.15);
        color: var(--accent);
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    .rfb-picker__button:hover {
        background: rgba(56,189,248,0.28);
        border-color: rgba(56,189,248,0.55);
    }
    .rfb-picker__dropdown {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        z-index: 30;
        border-radius: 14px;
        border: 1px solid rgba(148,163,184,0.3);
        background: rgba(15,23,42,0.95);
        backdrop-filter: blur(14px);
        box-shadow: 0 20px 40px rgba(15,23,42,0.55);
        padding: 16px;
        display: none;
    }
    .rfb-picker--open .rfb-picker__dropdown {
        display: block;
    }
    .rfb-picker__search {
        margin-bottom: 10px;
    }
    .rfb-picker__search input {
        width: 100%;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(148,163,184,0.3);
        background: rgba(15,23,42,0.7);
        color: var(--text);
    }
    .rfb-picker__list {
        max-height: 260px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 6px;
        scrollbar-color: rgba(56,189,248,0.6) rgba(15,23,42,0.6);
    }
    .rfb-picker__list::-webkit-scrollbar {
        width: 8px;
    }
    .rfb-picker__list::-webkit-scrollbar-thumb {
        background: rgba(56,189,248,0.6);
        border-radius: 999px;
    }
    .rfb-picker__option {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(56,189,248,0.3);
        background: rgba(56,189,248,0.12);
        color: var(--text);
        font-size: 0.9rem;
        font-weight: 600;
        text-align: left;
        cursor: pointer;
        transition: background 0.2s ease, border-color 0.2s ease, transform 0.18s ease;
    }
    .rfb-picker__option span {
        font-size: 0.75rem;
        color: var(--muted);
        font-weight: 500;
        margin-left: 12px;
    }
    .rfb-picker__option:hover {
        background: rgba(56,189,248,0.24);
        border-color: rgba(56,189,248,0.55);
        transform: translateX(2px);
    }
    .rfb-picker__empty {
        padding: 18px 12px;
        border-radius: 10px;
        border: 1px dashed rgba(148,163,184,0.3);
        color: var(--muted);
        text-align: center;
        font-size: 0.85rem;
    }
    .rfb-action-stack {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .rfb-contact-editor {
        border: 1px solid rgba(148,163,184,0.25);
        border-radius: 14px;
        background: rgba(15,23,42,0.45);
        padding: 0 12px 12px;
    }
    .rfb-contact-editor[open] {
        background: rgba(56,189,248,0.08);
        border-color: rgba(56,189,248,0.35);
    }
    .rfb-contact-editor summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--accent);
        padding: 12px 0;
    }
    .rfb-contact-editor summary::-webkit-details-marker {
        display: none;
    }
    .rfb-contact-editor summary::after {
        content: '\25BC';
        font-size: 0.7rem;
        transition: transform 0.2s ease;
    }
    .rfb-contact-editor[open] summary::after {
        transform: rotate(180deg);
    }
    .rfb-contact-editor form {
        display: grid;
        gap: 10px;
    }
    .rfb-contact-editor label {
        font-size: 0.78rem;
        color: var(--muted);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .rfb-contact-editor input[type="email"],
    .rfb-contact-editor input[type="text"] {
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid rgba(148,163,184,0.35);
        background: rgba(15,23,42,0.55);
        color: var(--text);
    }
    .rfb-placeholder {
        color: rgba(148,163,184,0.6);
    }
    .rfb-contact-feedback {
        margin: 4px 0 0;
        font-size: 0.72rem;
        min-height: 1em;
        color: var(--muted);
    }
    .rfb-contact-feedback[data-state="success"] {
        color: #22c55e;
    }
    .rfb-contact-feedback[data-state="error"] {
        color: #f87171;
    }
    .rfb-contact-button {
        padding: 8px 14px;
        border-radius: 10px;
        border: 1px solid rgba(56,189,248,0.45);
        background: rgba(56,189,248,0.18);
        color: var(--accent);
        font-weight: 600;
        cursor: pointer;
        transition: opacity 0.2s ease;
    }
    .rfb-contact-button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .rfb-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid transparent;
        font-size: 0.78rem;
        font-weight: 600;
        text-decoration: none;
        transition: opacity 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    }
    .rfb-chip--success {
        background: rgba(16,185,129,0.18);
        border-color: rgba(16,185,129,0.4);
        color: #22c55e;
    }
    .rfb-chip--danger {
        background: rgba(248,113,113,0.18);
        border-color: rgba(248,113,113,0.4);
        color: #f87171;
    }
    .rfb-chip--accent {
        background: rgba(56,189,248,0.15);
        border-color: rgba(56,189,248,0.45);
        color: var(--accent);
    }
    .rfb-chip--neutral {
        background: rgba(148,163,184,0.15);
        border-color: rgba(148,163,184,0.35);
        color: var(--text);
    }
    .rfb-chip--disabled {
        opacity: 0.45;
        pointer-events: none;
    }
    .rfb-responsible-summary {
        margin-top: 10px;
        display: flex;
        flex-direction: column;
        gap: 2px;
        font-size: 0.75rem;
        color: var(--muted);
    }
    .rfb-responsible-name {
        font-weight: 600;
        color: var(--text);
        font-size: 0.8rem;
    }
    .rfb-responsible-meta {
        font-size: 0.72rem;
        color: var(--muted);
    }
    .rfb-whatsapp-buttons {
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .rfb-whatsapp-button {
        display: inline-flex;
    }
    .rfb-phone-link {
        color: var(--accent);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    tr.rfb-row-highlight td {
        background-color: rgba(56,189,248,0.08) !important;
        transition: background-color 0.6s ease;
    }
    @media (max-width: 720px) {
        .rfb-contact-editor {
            padding: 0 0 12px;
        }
    }
    .rfb-table-panel {
        padding: 0;
        overflow: hidden;
    }
    .rfb-table-scroll {
        width: 100%;
        overflow-x: auto;
    }
    .rfb-table-scroll table {
        min-width: 900px;
    }
    .rfb-table-scroll-top {
        border-bottom: 1px solid rgba(148,163,184,0.12);
        scrollbar-color: rgba(226,232,240,0.6) transparent;
    }
    .rfb-table-scroll-top::-webkit-scrollbar {
        height: 10px;
    }
    .rfb-table-scroll-top::-webkit-scrollbar-thumb {
        background: rgba(226,232,240,0.4);
        border-radius: 999px;
    }
    .rfb-table-scroll-proxy {
        height: 1px;
    }
    @media (max-width: 1024px) {
        .rfb-page header {
            gap: 16px;
        }
    }
    @media (max-width: 640px) {
        .rfb-filters {
            grid-template-columns: 1fr;
        }
        .rfb-page header {
            flex-direction: column;
        }
        .rfb-page header > div:last-child {
            width: 100%;
        }
        .rfb-page header a {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="rfb-page">

<header>
    <div>
        <h1>Base RFB</h1>
        <p>Importe dados frios da Receita Federal para prospecções e elimine CNPJs já presentes na carteira quente.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <a href="<?= url('crm/clients'); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;">&larr; Voltar para carteira</a>
    </div>
</header>

<?php if ($feedback !== null): ?>
    <div class="panel" style="margin-bottom:20px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : (($feedback['type'] ?? '') === 'error' ? '#f87171' : '#38bdf8'); ?>;">
        <strong style="display:block;margin-bottom:6px;">Importação da Base RFB</strong>
        <p style="margin:0;color:var(--muted);"><?= htmlspecialchars((string)($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if (!empty($feedback['details']) && is_array($feedback['details'])): ?>
            <ul style="margin:10px 0 0 16px;color:var(--muted);font-size:0.85rem;">
                <?php foreach ($feedback['details'] as $key => $value): ?>
                    <li><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>: <?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel rfb-summary-grid" style="margin-bottom:24px;">
</div>
<div class="panel" style="margin-bottom:24px;">
    <?php if ($stats === null): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div>
                <strong style="display:block;margin-bottom:4px;">Estatísticas da Base RFB</strong>
                <span style="color:var(--muted);font-size:0.9rem;">Os números serão calculados automaticamente assim que você aplicar filtros.</span>
            </div>
        </div>
    <?php else: ?>
        <div class="rfb-summary-grid">
            <div>
                <strong style="font-size:1.4rem;display:block;">
                    <?= number_format((int)($stats['total'] ?? 0), 0, ',', '.'); ?>
                </strong>
                <span style="color:var(--muted);font-size:0.85rem;">CNPJs ativos na base fria</span>
            </div>
            <div>
                <strong style="font-size:1.4rem;display:block;">
                    <?= number_format((int)($stats['with_email'] ?? 0), 0, ',', '.'); ?>
                </strong>
                <span style="color:var(--muted);font-size:0.85rem;">Com e-mail disponível</span>
            </div>
            <div>
                <strong style="font-size:1.4rem;display:block;">
                    <?= number_format((int)($stats['unique_states'] ?? 0), 0, ',', '.'); ?>
                </strong>
                <span style="color:var(--muted);font-size:0.85rem;">Estados cobertos</span>
            </div>
            <div>
                <strong style="font-size:1.4rem;display:block;">
                    <?= number_format((int)($stats['unique_cities'] ?? 0), 0, ',', '.'); ?>
                </strong>
                <span style="color:var(--muted);font-size:0.85rem;">Cidades únicas</span>
            </div>
            <div>
                <strong style="font-size:1.4rem;display:block;">
                    <?= number_format((int)($stats['excluded_total'] ?? 0), 0, ',', '.'); ?>
                </strong>
                <span style="color:var(--muted);font-size:0.85rem;">Na lista de exclusão</span>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>

<div class="panel" style="margin-bottom:24px;">
    <form method="get" action="<?= url('rfb-base'); ?>" class="rfb-filters">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="applied" value="1">
        <label style="display:flex;flex-direction:column;color:var(--muted);font-size:0.85rem;gap:6px;">
            Busca
            <input type="text" name="query" value="<?= htmlspecialchars($filters['query'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="CNPJ, razão social, e-mail, cidade ou WhatsApp" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
        </label>
        <label style="display:flex;flex-direction:column;color:var(--muted);font-size:0.85rem;gap:6px;">
            UF
            <input type="text" name="state" maxlength="2" value="<?= htmlspecialchars($filters['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);text-transform:uppercase;">
        </label>
        <label style="display:flex;flex-direction:column;color:var(--muted);font-size:0.85rem;gap:6px;">
            Cidade
            <div class="rfb-picker" data-rfb-picker="city" data-rfb-picker-endpoint="<?= url('rfb-base/options/cities'); ?>">
                <div class="rfb-picker__controls">
                    <input type="text" name="city" value="<?= htmlspecialchars($filters['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" data-rfb-picker-input>
                    <button class="rfb-picker__button" type="button" data-rfb-picker-toggle aria-label="Listar cidades" title="Listar cidades" aria-haspopup="listbox" aria-expanded="false">
                        🔍
                    </button>
                </div>
                <div class="rfb-picker__dropdown" data-rfb-picker-dropdown aria-hidden="true">
                    <div class="rfb-picker__search">
                        <input type="search" data-rfb-picker-search placeholder="Buscar cidade">
                    </div>
                    <div class="rfb-picker__list" data-rfb-picker-list role="listbox"></div>
                    <div class="rfb-picker__empty" data-rfb-picker-empty hidden>Nenhuma cidade encontrada.</div>
                </div>
            </div>
            <span style="font-size:0.75rem;color:rgba(148,163,184,0.85);">A lista usa a UF acima, quando preenchida.</span>
        </label>
        <label style="display:flex;flex-direction:column;color:var(--muted);font-size:0.85rem;gap:6px;">
            CNAE
            <div class="rfb-picker" data-rfb-picker="cnae" data-rfb-picker-endpoint="<?= url('rfb-base/options/cnaes'); ?>">
                <div class="rfb-picker__controls">
                    <input type="text" name="cnae" value="<?= htmlspecialchars($filters['cnae'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" data-rfb-picker-input>
                    <button class="rfb-picker__button" type="button" data-rfb-picker-toggle aria-label="Listar CNAEs" title="Listar CNAEs" aria-haspopup="listbox" aria-expanded="false">
                        🔍
                    </button>
                </div>
                <div class="rfb-picker__dropdown" data-rfb-picker-dropdown aria-hidden="true">
                    <div class="rfb-picker__search">
                        <input type="search" data-rfb-picker-search placeholder="Buscar CNAE ou descrição">
                    </div>
                    <div class="rfb-picker__list" data-rfb-picker-list role="listbox"></div>
                    <div class="rfb-picker__empty" data-rfb-picker-empty hidden>Nenhuma opção encontrada.</div>
                </div>
            </div>
            <span style="font-size:0.75rem;color:rgba(148,163,184,0.85);">Selecione um código diretamente da base.</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;color:var(--muted);font-size:0.85rem;">
            <input type="checkbox" name="has_email" value="1" <?= ($filters['has_email'] ?? '') === '1' ? 'checked' : ''; ?> style="width:18px;height:18px;">
            Apenas contatos com e-mail
        </label>
        <label style="display:flex;align-items:center;gap:8px;color:var(--muted);font-size:0.85rem;">
            <input type="checkbox" name="has_whatsapp" value="1" <?= ($filters['has_whatsapp'] ?? '') === '1' ? 'checked' : ''; ?> style="width:18px;height:18px;">
            Apenas contatos com WhatsApp
        </label>
        <label style="display:flex;flex-direction:column;color:var(--muted);font-size:0.85rem;gap:6px;">
            Status
            <select name="status" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <option value="active" <?= ($filters['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Apenas ativos</option>
                <option value="excluded" <?= ($filters['status'] ?? 'active') === 'excluded' ? 'selected' : ''; ?>>Somente excluídos</option>
                <option value="all" <?= ($filters['status'] ?? 'active') === 'all' ? 'selected' : ''; ?>>Todos</option>
            </select>
        </label>
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;grid-column:1 / -1;">
            <a href="<?= url('rfb-base'); ?>" style="color:var(--muted);text-decoration:none;">Limpar</a>
            <button type="submit" class="primary">Aplicar filtros</button>
        </div>
    </form>
</div>

<?php if ($filtersApplied): ?>
<div class="panel rfb-table-panel" style="margin-top:24px;" data-rfb-scroll="1">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(148,163,184,0.12);background:rgba(10,16,28,0.65);">
        <strong style="font-size:0.95rem;">Resultados encontrados</strong>
        <span style="color:var(--muted);font-size:0.9rem;">
            <?= number_format((int)($pagination['total'] ?? 0), 0, ',', '.'); ?> registro(s)
        </span>
    </div>
    <div class="rfb-table-scroll rfb-table-scroll-top" data-scroll-role="top">
        <div class="rfb-table-scroll-proxy"></div>
    </div>
    <div class="rfb-table-scroll rfb-table-scroll-body" data-scroll-role="body">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:rgba(15,23,42,0.72);text-align:left;font-size:0.78rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);">
                    <th style="padding:14px 18px;">CNPJ</th>
                    <th style="padding:14px 18px;">Razão Social</th>
                    <th style="padding:14px 18px;">Contato</th>
                    <th style="padding:14px 18px;">Cidade/UF</th>
                    <th style="padding:14px 18px;">CNAE</th>
                    <th style="padding:14px 18px;">Início atividade</th>
                    <th style="padding:14px 18px;">Telefone</th>
                    <th style="padding:14px 18px;">Responsável</th>
                    <th style="padding:14px 18px;">Status</th>
                    <th style="padding:14px 18px;">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr>
                    <td colspan="10" style="padding:28px;text-align:center;color:var(--muted);">Nenhum registro encontrado.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <?php
                        $cnpjFormatted = format_document((string)($item['cnpj'] ?? ''));
                        $city = $item['city'] ?? '';
                        $state = $item['state'] ?? '';
                        $activity = isset($item['activity_started_at']) ? format_date($item['activity_started_at']) : '-';
                        $importedAt = isset($item['created_at']) ? format_datetime($item['created_at']) : '-';
                        $phoneDisplay = $formatPhone($item['ddd'] ?? null, $item['phone'] ?? null);
                        $phoneDigits = digits_only((string)($item['ddd'] ?? '') . (string)($item['phone'] ?? ''));
                        $whatsUrl = strlen($phoneDigits) >= 10 ? 'https://wa.me/55' . $phoneDigits : null;
                        $emailValue = trim((string)($item['email'] ?? ''));
                        $contactPhoneValue = $phoneDigits;
                        $responsibleNameValue = trim((string)($item['responsible_name'] ?? ''));
                        $responsibleBirthTimestamp = isset($item['responsible_birthdate']) ? (int)$item['responsible_birthdate'] : null;
                        $responsibleBirthLabel = $formatBirthdateLabelValue($responsibleBirthTimestamp);
                        $responsibleBirthInput = $formatBirthdateInputValue($responsibleBirthTimestamp);
                        $isExcluded = ($item['exclusion_status'] ?? '') === 'excluded';
                        $reasonKey = $item['exclusion_reason'] ?? null;
                        $reasonLabel = $reasonKey && isset($statusReasons[$reasonKey]) ? $statusReasons[$reasonKey] : null;
                        $excludedAt = isset($item['excluded_at']) ? format_datetime($item['excluded_at']) : null;
                        $isPartnershipEligible = $rfbPartnershipEligible($item['cnae_code'] ?? '');
                        $whatsappMessages = [
                            'partnership' => $rfbApplyWhatsappTemplate($item, $whatsappTemplates['partnership'] ?? ''),
                            'general' => $rfbApplyWhatsappTemplate($item, $whatsappTemplates['general'] ?? ''),
                        ];
                        $whatsappLinks = [
                            'partnership' => $rfbBuildWhatsappLink($phoneDigits, $whatsappMessages['partnership'], $isPartnershipEligible),
                            'general' => $rfbBuildWhatsappLink($phoneDigits, $whatsappMessages['general'], true),
                        ];
                    ?>
                    <tr id="rfb-row-<?= (int)($item['id'] ?? 0); ?>" style="border-bottom:1px solid rgba(148,163,184,0.12);<?= $isExcluded ? 'opacity:0.65;' : ''; ?>">
                        <td style="padding:16px 18px;font-weight:600;"><?= htmlspecialchars($cnpjFormatted, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:16px 18px;">
                            <strong style="display:block;"><?= htmlspecialchars((string)($item['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </td>
                        <td style="padding:16px 18px;color:var(--muted);">
                            <?php if (!empty($item['email'])): ?>
                                <span data-rfb-email="<?= (int)($item['id'] ?? 0); ?>"><?= htmlspecialchars((string)$item['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                                <span class="rfb-placeholder" data-rfb-email="<?= (int)($item['id'] ?? 0); ?>">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:16px 18px;">
                            <?= $city !== '' ? htmlspecialchars((string)$city, ENT_QUOTES, 'UTF-8') : '-'; ?>
                            <?php if ($state !== ''): ?>
                                <span style="color:var(--muted);">/ <?= htmlspecialchars((string)$state, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:16px 18px;">
                            <?= !empty($item['cnae_code']) ? htmlspecialchars((string)$item['cnae_code'], ENT_QUOTES, 'UTF-8') : '-'; ?>
                        </td>
                        <td style="padding:16px 18px;"><?= $activity; ?></td>
                        <td style="padding:16px 18px;">
                            <div data-rfb-phone="<?= (int)($item['id'] ?? 0); ?>">
                                <?php if ($whatsUrl !== null): ?>
                                    <a href="<?= htmlspecialchars($whatsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="rfb-phone-link">
                                        <span><?= htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php else: ?>
                                    <span class="<?= $phoneDisplay === '-' ? 'rfb-placeholder' : ''; ?>"><?= htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="padding:16px 18px;">
                            <div class="rfb-responsible-summary" data-rfb-responsible="<?= (int)($item['id'] ?? 0); ?>">
                                <?php if ($responsibleNameValue !== ''): ?>
                                    <div class="rfb-responsible-name"><?= htmlspecialchars($responsibleNameValue, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ($responsibleBirthLabel !== ''): ?>
                                        <div class="rfb-responsible-meta">Nascimento: <?= htmlspecialchars($responsibleBirthLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="rfb-placeholder">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="padding:16px 18px;">
                            <?php if ($isExcluded): ?>
                                <span class="rfb-chip rfb-chip--danger">Excluído</span>
                                <?php if ($reasonLabel !== null): ?>
                                    <div style="color:var(--muted);font-size:0.8rem;margin-top:8px;">Motivo: <?= htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($excludedAt !== null): ?>
                                    <div style="color:var(--muted);font-size:0.75rem;">Desde <?= $excludedAt; ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="rfb-chip rfb-chip--success">Ativo</span>
                            <?php endif; ?>

                            <div class="rfb-whatsapp-buttons"
                                 data-rfb-whatsapp="<?= (int)($item['id'] ?? 0); ?>"
                                 data-phone-digits="<?= htmlspecialchars($phoneDigits, ENT_QUOTES, 'UTF-8'); ?>"
                                 data-template-partnership="<?= htmlspecialchars($whatsappMessages['partnership'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-template-general="<?= htmlspecialchars($whatsappMessages['general'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-partnership-allowed="<?= $isPartnershipEligible ? '1' : '0'; ?>">
                                <?php
                                    $partnershipClasses = 'rfb-chip rfb-chip--accent rfb-whatsapp-button';
                                    if ($whatsappLinks['partnership'] === null) {
                                        $partnershipClasses .= ' rfb-chip--disabled';
                                    }
                                    $generalClasses = 'rfb-chip rfb-chip--neutral rfb-whatsapp-button';
                                    if ($whatsappLinks['general'] === null) {
                                        $generalClasses .= ' rfb-chip--disabled';
                                    }
                                ?>
                                <a class="<?= $partnershipClasses; ?>" data-rfb-wa-button="partnership"
                                    <?php if ($whatsappLinks['partnership'] !== null): ?>
                                        href="<?= htmlspecialchars($whatsappLinks['partnership'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                                    <?php else: ?>
                                        data-disabled="1"
                                    <?php endif; ?>>Parceria CD</a>
                                <a class="<?= $generalClasses; ?>" data-rfb-wa-button="general"
                                    <?php if ($whatsappLinks['general'] !== null): ?>
                                        href="<?= htmlspecialchars($whatsappLinks['general'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                                    <?php else: ?>
                                        data-disabled="1"
                                    <?php endif; ?>>Certificados</a>
                            </div>
                        </td>
                        <td style="padding:16px 18px;">
                            <div class="rfb-action-stack">
                                <details class="rfb-contact-editor">
                                    <summary>Editar contato</summary>
                                    <form method="post" action="<?= url('rfb-base/' . (int)($item['id'] ?? 0) . '/contact'); ?>" data-rfb-contact-form data-rfb-id="<?= (int)($item['id'] ?? 0); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                        <label>
                                            E-mail comercial
                                            <input type="email" name="email" value="<?= htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="contato@empresa.com">
                                        </label>
                                        <label>
                                            Telefone / WhatsApp
                                            <input type="text" name="phone" value="<?= htmlspecialchars($contactPhoneValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(11) 91234-5678">
                                        </label>
                                        <label>
                                            Nome do responsável
                                            <input type="text" name="responsible_name" value="<?= htmlspecialchars($responsibleNameValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome completo do responsável">
                                        </label>
                                        <label>
                                            Data de nascimento
                                            <input type="date" name="responsible_birthdate" value="<?= htmlspecialchars($responsibleBirthInput, ENT_QUOTES, 'UTF-8'); ?>">
                                        </label>
                                        <p style="margin:0;font-size:0.72rem;color:var(--muted);">Deixe os campos vazios para remover as informações.</p>
                                        <button type="submit" class="rfb-contact-button" data-rfb-contact-submit>Salvar contato</button>
                                        <div class="rfb-contact-feedback" data-rfb-contact-feedback="<?= (int)($item['id'] ?? 0); ?>"></div>
                                    </form>
                                </details>
                                <form method="post" action="<?= url('rfb-base/' . (int)($item['id'] ?? 0) . '/status'); ?>" style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($isExcluded): ?>
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(56,189,248,0.4);background:rgba(56,189,248,0.15);color:var(--accent);font-weight:600;cursor:pointer;">Restaurar</button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="exclude">
                                        <label style="font-size:0.78rem;color:var(--muted);display:flex;flex-direction:column;gap:4px;">
                                            Motivo
                                            <select name="reason" style="padding:6px 10px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);font-size:0.85rem;">
                                                <option value="manual">Definido manualmente</option>
                                                <option value="email_error">E-mail retornou erro</option>
                                                <option value="complaint">Reclamação do contato</option>
                                                <option value="missing_contact">Sem e-mail e telefone</option>
                                            </select>
                                        </label>
                                        <button type="submit" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(248,113,113,0.6);background:rgba(248,113,113,0.15);color:#f87171;font-weight:600;cursor:pointer;">Excluir</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pagination['pages'] ?? 1) > 1): ?>
        <?php
            $currentPage = (int)($pagination['page'] ?? 1);
            $totalPages = (int)($pagination['pages'] ?? 1);
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            if ($endPage - $startPage < 4) {
                $startPage = max(1, $endPage - 4);
                $endPage = min($totalPages, $startPage + 4);
            }
        ?>
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:16px 20px;border-top:1px solid rgba(148,163,184,0.12);background:rgba(10,16,28,0.72);">
            <?php if ($currentPage > 1): ?>
                <?php $prevQuery = $buildQuery(['page' => $currentPage - 1]); ?>
                <a href="<?= url('rfb-base') . ($prevQuery !== '' ? '?' . $prevQuery : ''); ?>" style="color:var(--muted);text-decoration:none;font-size:0.85rem;">&larr; Anterior</a>
            <?php endif; ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                    <?php $pageQuery = $buildQuery(['page' => $page]); ?>
                    <a href="<?= url('rfb-base') . ($pageQuery !== '' ? '?' . $pageQuery : ''); ?>"
                        style="padding:6px 12px;border-radius:10px;text-decoration:none;font-size:0.85rem;font-weight:600;<?= $page === $currentPage ? 'background:rgba(56,189,248,0.2);color:var(--accent);border:1px solid rgba(56,189,248,0.4);' : 'color:var(--muted);border:1px solid rgba(148,163,184,0.18);'; ?>">
                        <?= $page; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php if ($currentPage < $totalPages): ?>
                <?php $nextQuery = $buildQuery(['page' => $currentPage + 1]); ?>
                <a href="<?= url('rfb-base') . ($nextQuery !== '' ? '?' . $nextQuery : ''); ?>" style="color:var(--muted);text-decoration:none;font-size:0.85rem;">Próxima &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="panel" style="margin-top:24px;padding:32px;text-align:center;color:var(--muted);">
    <?php if ($filtersBlocked): ?>
        Informe ao menos um critério (busca, UF, cidade, CNAE, e-mail) ou selecione "Somente excluídos" para consultar a Base RFB.
    <?php else: ?>
        Use os filtros acima para consultar registros específicos na Base RFB.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($latest !== []): ?>
    <div class="panel" style="margin-top:24px;">
        <h2 style="margin-top:0;margin-bottom:12px;">Últimas inserções</h2>
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <?php foreach ($latest as $entry): ?>
                <div style="padding:14px;border-radius:14px;border:1px solid rgba(148,163,184,0.24);background:rgba(15,23,42,0.55);">
                    <strong style="display:block;font-size:0.95rem;margin-bottom:4px;"><?= htmlspecialchars(format_document((string)($entry['cnpj'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span style="display:block;color:var(--muted);font-size:0.85rem;"><?= htmlspecialchars((string)($entry['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="display:block;color:var(--muted);font-size:0.75rem;margin-top:6px;">Importado em <?= isset($entry['created_at']) ? format_datetime($entry['created_at']) : '-'; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setupRfbTableScrollSync();
    setupRfbContactForms();
    setupRfbPickers();
});

function setupRfbTableScrollSync() {
    document.querySelectorAll('[data-rfb-scroll]')
        .forEach(function (panel) {
            const top = panel.querySelector('[data-scroll-role="top"]');
            const body = panel.querySelector('[data-scroll-role="body"]');
            if (!top || !body) {
                return;
            }

            const proxy = top.querySelector('.rfb-table-scroll-proxy');

            const syncTop = () => {
                if (top.scrollLeft !== body.scrollLeft) {
                    top.scrollLeft = body.scrollLeft;
                }
            };

            const syncBody = () => {
                if (body.scrollLeft !== top.scrollLeft) {
                    body.scrollLeft = top.scrollLeft;
                }
            };

            body.addEventListener('scroll', syncTop);
            top.addEventListener('scroll', syncBody);

            const updateProxy = () => {
                if (proxy) {
                    proxy.style.width = body.scrollWidth + 'px';
                }
            };

            updateProxy();
            const resizeObserver = new ResizeObserver(updateProxy);
            resizeObserver.observe(body);
        });
}

function setupRfbContactForms() {
    const forms = document.querySelectorAll('[data-rfb-contact-form]');
    if (!forms.length) {
        return;
    }

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const submitButton = form.querySelector('[data-rfb-contact-submit]');
            const feedback = form.querySelector('[data-rfb-contact-feedback]');
            const originalText = submitButton ? submitButton.textContent : '';
            const formData = new FormData(form);

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Salvando...';
            }
            if (feedback) {
                feedback.textContent = 'Salvando contato...';
                feedback.removeAttribute('data-state');
            }

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(async function (response) {
                    let payload = null;
                    try {
                        payload = await response.json();
                    } catch (error) {
                        // ignore parsing errors, handled below
                    }

                    if (!response.ok || !payload) {
                        const errorMessage = payload && payload.message ? payload.message : 'Falha ao salvar contato.';
                        throw new Error(errorMessage);
                    }

                    const data = payload.data || {};
                    const recordId = form.dataset.rfbId;
                    updateRfbEmailCell(recordId, typeof data.email === 'string' ? data.email : '');
                    updateRfbPhoneCell(recordId, data);
                    updateRfbResponsibleSummary(recordId, data);
                    updateRfbWhatsappButtons(recordId, data);

                    const phoneInput = form.querySelector('input[name="phone"]');
                    if (phoneInput && typeof data.phone_digits === 'string') {
                        phoneInput.value = data.phone_digits;
                    }
                    const emailInput = form.querySelector('input[name="email"]');
                    if (emailInput && typeof data.email === 'string') {
                        emailInput.value = data.email;
                    }
                    const responsibleInput = form.querySelector('input[name="responsible_name"]');
                    if (responsibleInput) {
                        responsibleInput.value = typeof data.responsible_name === 'string' ? data.responsible_name : '';
                    }
                    const birthInput = form.querySelector('input[name="responsible_birthdate"]');
                    if (birthInput) {
                        birthInput.value = typeof data.responsible_birth_input === 'string' ? data.responsible_birth_input : '';
                    }

                    if (feedback) {
                        feedback.textContent = payload.message || 'Contato atualizado com sucesso.';
                        feedback.dataset.state = payload.status === 'error' ? 'error' : 'success';
                    }

                    const row = document.getElementById('rfb-row-' + recordId);
                    if (row) {
                        row.classList.add('rfb-row-highlight');
                        setTimeout(function () {
                            row.classList.remove('rfb-row-highlight');
                        }, 1600);
                    }
                })
                .catch(function (error) {
                    if (feedback) {
                        feedback.textContent = error && error.message ? error.message : 'Não foi possível salvar agora.';
                        feedback.dataset.state = 'error';
                    }
                })
                .finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = originalText;
                    }
                });
        });
    });
}

function setupRfbPickers() {
    const pickers = document.querySelectorAll('[data-rfb-picker]');
    if (!pickers.length) {
        return;
    }

    const cache = new Map();
    const stateInput = document.querySelector('input[name="state"]');

    pickers.forEach(function (picker) {
        const toggle = picker.querySelector('[data-rfb-picker-toggle]');
        const dropdown = picker.querySelector('[data-rfb-picker-dropdown]');
        const input = picker.querySelector('[data-rfb-picker-input]');
        const search = picker.querySelector('[data-rfb-picker-search]');
        const list = picker.querySelector('[data-rfb-picker-list]');
        const emptyState = picker.querySelector('[data-rfb-picker-empty]');

        if (!toggle || !dropdown || !input || !list) {
            return;
        }

        let items = [];
        let optionNodes = [];
        let currentCacheKey = null;
        let pendingRequestId = 0;

        const closeDropdown = function () {
            picker.classList.remove('rfb-picker--open');
            dropdown.setAttribute('aria-hidden', 'true');
            toggle.setAttribute('aria-expanded', 'false');
        };

        const openDropdown = function () {
            picker.classList.add('rfb-picker--open');
            dropdown.setAttribute('aria-hidden', 'false');
            toggle.setAttribute('aria-expanded', 'true');
            loadOptions();
        };

        toggle.addEventListener('click', function () {
            if (picker.classList.contains('rfb-picker--open')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

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

        let searchDebounceId = null;
        if (search) {
            search.addEventListener('input', function () {
                const term = search.value.trim();
                filterOptions(term);

                if (term === '' || term.length >= 1) {
                    if (searchDebounceId) {
                        clearTimeout(searchDebounceId);
                    }

                    searchDebounceId = setTimeout(function () {
                        loadOptions(term, true);
                    }, 250);
                }
            });
        }

        function loadOptions(forcedSearchValue = null, preserveSearch = false) {
            const type = picker.dataset.rfbPicker || '';
            const endpoint = picker.dataset.rfbPickerEndpoint || '';
            if (!endpoint || !type) {
                showEmpty('Configuração inválida.');
                return;
            }

            const stateValue = type === 'city' && stateInput ? (stateInput.value || '').trim().toUpperCase() : '';
            const typedSearch = typeof forcedSearchValue === 'string'
                ? forcedSearchValue
                : (input.value || '').trim();
            const normalizedSearch = typedSearch.toLowerCase();
            const cacheKey = [endpoint, type, stateValue, normalizedSearch].join('::');

            if (currentCacheKey === cacheKey && items.length) {
                populateList(items);
                if (!preserveSearch) {
                    focusSearch();
                } else {
                    filterOptions(search ? search.value.trim() : '');
                }
                return;
            }

            const cached = cache.get(cacheKey);
            if (cached) {
                items = cached;
                currentCacheKey = cacheKey;
                populateList(items);
                if (!preserveSearch) {
                    focusSearch();
                } else {
                    filterOptions(search ? search.value.trim() : '');
                }
                return;
            }

            optionNodes = [];
            list.innerHTML = '';
            showEmpty('Carregando...');

            const requestId = ++pendingRequestId;

            const url = new URL(endpoint, window.location.origin);
            if (type === 'city' && stateValue !== '') {
                url.searchParams.set('state', stateValue);
            }

            if (typedSearch !== '') {
                url.searchParams.set('search', typedSearch);
            }

            const pickerLimit = parseInt(picker.dataset.rfbPickerLimit || '200', 10);
            const limitValue = Number.isFinite(pickerLimit) ? Math.min(500, Math.max(25, pickerLimit)) : 200;
            url.searchParams.set('limit', String(limitValue));

            fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Erro na resposta');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (requestId !== pendingRequestId) {
                        return;
                    }
                    const fetched = payload && payload.data && Array.isArray(payload.data.items) ? payload.data.items : [];
                    items = fetched;
                    cache.set(cacheKey, fetched);
                    currentCacheKey = cacheKey;
                    populateList(fetched);
                    if (!preserveSearch) {
                        focusSearch();
                    } else {
                        filterOptions(search ? search.value.trim() : '');
                    }
                })
                .catch(function () {
                    if (requestId !== pendingRequestId) {
                        return;
                    }
                    showEmpty('Não foi possível carregar agora.');
                });
        }

        function populateList(source) {
            optionNodes = [];
            list.innerHTML = '';
            if (!source.length) {
                showEmpty('Nenhuma opção disponível.');
                return;
            }

            hideEmpty();
            const type = picker.dataset.rfbPicker || '';
            source.forEach(function (item) {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'rfb-picker__option';

                if (type === 'city') {
                    const cityName = item.city || '';
                    const stateLabel = item.state ? ' / ' + item.state : '';
                    const meta = item.total ? item.total + ' registro(s)' : '';
                    option.dataset.value = cityName;
                    option.dataset.filterValue = (cityName + ' ' + (item.state || '')).toLowerCase();
                    option.innerHTML = '<div>' + escapeHtml(cityName || '-') + stateLabel + '</div>' + (meta ? '<span>' + escapeHtml(meta) + '</span>' : '');
                } else {
                    const cnaeCode = item.cnae || '';
                    const meta = item.total ? item.total + ' ocorrência(s)' : '';
                    option.dataset.value = cnaeCode;
                    option.dataset.filterValue = cnaeCode.toLowerCase();
                    option.innerHTML = '<div>' + escapeHtml(cnaeCode || '-') + '</div>' + (meta ? '<span>' + escapeHtml(meta) + '</span>' : '');
                }

                option.addEventListener('click', function () {
                    input.value = option.dataset.value || '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    closeDropdown();
                    input.focus();
                });

                list.appendChild(option);
                optionNodes.push(option);
            });
        }

        function filterOptions(value) {
            const term = (value || '').trim().toLowerCase();
            let visible = 0;
            optionNodes.forEach(function (option) {
                const target = option.dataset.filterValue || '';
                const match = term === '' || target.indexOf(term) !== -1;
                option.style.display = match ? '' : 'none';
                if (match) {
                    visible += 1;
                }
            });

            if (visible === 0) {
                showEmpty(term === '' ? 'Nenhuma opção disponível.' : 'Sem resultados para "' + value + '".');
            } else {
                hideEmpty();
            }
        }

        function showEmpty(message) {
            if (!emptyState) {
                return;
            }
            emptyState.textContent = message;
            emptyState.hidden = false;
        }

        function hideEmpty() {
            if (emptyState) {
                emptyState.hidden = true;
            }
        }

        function focusSearch() {
            if (search) {
                search.value = '';
                filterOptions('');
                search.focus();
            }
        }
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }
}

function updateRfbEmailCell(id, email) {
    if (!id) {
        return;
    }
    const target = document.querySelector('[data-rfb-email="' + id + '"]');
    if (!target) {
        return;
    }
    const cleanEmail = (email || '').trim();
    if (cleanEmail === '') {
        target.textContent = '-';
        target.classList.add('rfb-placeholder');
    } else {
        target.textContent = cleanEmail;
        target.classList.remove('rfb-placeholder');
    }
}

function updateRfbPhoneCell(id, data) {
    if (!id) {
        return;
    }
    const container = document.querySelector('[data-rfb-phone="' + id + '"]');
    if (!container) {
        return;
    }

    const hasPhone = Boolean(data && data.has_phone && data.phone_display);
    container.innerHTML = '';

    if (!hasPhone) {
        const placeholder = document.createElement('span');
        placeholder.className = 'rfb-placeholder';
        placeholder.textContent = '-';
        container.appendChild(placeholder);
        return;
    }

    if (data.whatsapp_url) {
        const link = document.createElement('a');
        link.href = data.whatsapp_url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'rfb-phone-link';
        const number = document.createElement('span');
        number.textContent = data.phone_display;
        link.appendChild(number);
        container.appendChild(link);
        return;
    }

    const span = document.createElement('span');
    span.textContent = data.phone_display;
    container.appendChild(span);
}

function updateRfbResponsibleSummary(id, data) {
    if (!id) {
        return;
    }

    const container = document.querySelector('[data-rfb-responsible="' + id + '"]');
    if (!container) {
        return;
    }

    const name = typeof data.responsible_name === 'string' ? data.responsible_name.trim() : '';
    const birth = typeof data.responsible_birth_label === 'string' ? data.responsible_birth_label.trim() : '';

    container.innerHTML = '';

    if (!name && !birth) {
        const placeholder = document.createElement('span');
        placeholder.className = 'rfb-placeholder';
        placeholder.textContent = '-';
        container.appendChild(placeholder);
        return;
    }

    if (name) {
        const nameNode = document.createElement('div');
        nameNode.className = 'rfb-responsible-name';
        nameNode.textContent = name;
        container.appendChild(nameNode);
    }

    if (birth) {
        const birthNode = document.createElement('div');
        birthNode.className = 'rfb-responsible-meta';
        birthNode.textContent = 'Nascimento: ' + birth;
        container.appendChild(birthNode);
    }
}

function updateRfbWhatsappButtons(id, data) {
    if (!id) {
        return;
    }

    const container = document.querySelector('[data-rfb-whatsapp="' + id + '"]');
    if (!container) {
        return;
    }

    const digits = typeof data.phone_digits === 'string' ? data.phone_digits : '';
    container.dataset.phoneDigits = digits;

    if (data.whatsapp_templates && typeof data.whatsapp_templates.partnership === 'string') {
        container.dataset.templatePartnership = data.whatsapp_templates.partnership;
    }
    if (data.whatsapp_templates && typeof data.whatsapp_templates.general === 'string') {
        container.dataset.templateGeneral = data.whatsapp_templates.general;
    }

    container.querySelectorAll('[data-rfb-wa-button]').forEach(function (button) {
        const type = button.dataset.rfbWaButton || 'general';
        const allowed = type === 'partnership'
            ? container.dataset.partnershipAllowed === '1'
            : true;
        const template = type === 'partnership'
            ? (container.dataset.templatePartnership || '')
            : (container.dataset.templateGeneral || '');

        const href = buildWhatsappHref(digits, template, allowed);
        if (href) {
            button.href = href;
            button.target = '_blank';
            button.rel = 'noopener';
            button.classList.remove('rfb-chip--disabled');
            button.removeAttribute('data-disabled');
        } else {
            button.removeAttribute('href');
            button.removeAttribute('target');
            button.removeAttribute('rel');
            button.classList.add('rfb-chip--disabled');
            button.setAttribute('data-disabled', '1');
        }
    });
}

function buildWhatsappHref(digits, message, allowed) {
    if (!allowed) {
        return null;
    }

    const cleanDigits = String(digits || '').replace(/\D+/g, '');
    if (cleanDigits.length < 10) {
        return null;
    }

    const text = (message || '').trim();
    if (text === '') {
        return 'https://wa.me/55' + cleanDigits;
    }

    return 'https://wa.me/55' + cleanDigits + '?text=' + encodeURIComponent(text);
}
</script>
