<?php
/** @var string $documentInput */
/** @var array|null $partner */
/** @var array $monthlySeries */
/** @var bool $searchPerformed */
/** @var array $messages */
/** @var array $errors */
/** @var array $formData */
/** @var string $clientQuery */
/** @var array $clientResults */
/** @var string $clientSearchMode */
/** @var string $partnerNameQuery */
/** @var array $partnerMatches */
/** @var bool $partnerMatchesLimited */
/** @var int $partnerMatchesLimit */
/** @var int $partnerMatchesCount */
/** @var int|null $partnerMatchesTotal */
/** @var int $partnerMatchesPerPage */
/** @var int $lookupPage */
/** @var int|null $lookupTotalPages */
/** @var array $lookupQueryParams */
/** @var string $activeTab */
/** @var array $reportFilters */
/** @var array $reportRows */
/** @var array $reportTotals */
/** @var array $reportPeriodOptions */
/** @var bool $indicationFilterActive */
/** @var string|null $indicationPeriodLabel */
/** @var int $reportMonthSelect */
/** @var int $reportYearSelect */
/** @var array $reportYearOptions */

$partnerIdValue = (int)($partner['id'] ?? 0);

$documentFormatted = format_document($formData['document'] ?? '');
$documentDigitsValue = htmlspecialchars(digits_only($documentInput), ENT_QUOTES, 'UTF-8');
$phoneDigits = digits_only($formData['phone'] ?? '');
$phoneDisplay = $phoneDigits !== '' ? $phoneDigits : '';
$totalIndications = array_sum(array_map(static fn(array $row): int => (int)($row['total'] ?? 0), $monthlySeries));
$buttonLabel = $partner !== null ? 'Salvar alterações' : 'Cadastrar parceiro';
$initialTab = htmlspecialchars($activeTab ?? 'lookup', ENT_QUOTES, 'UTF-8');
$reportMode = $reportFilters['mode'] ?? 'custo';
$reportPeriod = $reportFilters['period'] ?? 'month';
$reportRangeLabel = $reportFilters['range_label'] ?? '';
$partnerNameValue = htmlspecialchars($partnerNameQuery ?? '', ENT_QUOTES, 'UTF-8');
$clientQueryValue = htmlspecialchars($clientQuery ?? '', ENT_QUOTES, 'UTF-8');
$indicatorGapKeyValue = htmlspecialchars($indicatorGapKey ?? '', ENT_QUOTES, 'UTF-8');
$indicationMonthValue = (int)($indicationMonth ?? 0);
$indicationYearValue = (int)($indicationYear ?? 0);
$indicationFilterEnabled = !empty($indicationFilterActive);
$indicationPeriodLabelRaw = $indicationFilterEnabled && !empty($indicationPeriodLabel)
    ? (string)$indicationPeriodLabel
    : null;
$lookupPageValue = max(1, (int)($lookupPage ?? 1));
$partnerMatchesPerPageValue = max(1, (int)($partnerMatchesPerPage ?? 1));
$partnerMatchesCountValue = max(
    0,
    (int)($partnerMatchesCount ?? (is_array($partnerMatches) ? count($partnerMatches) : 0))
);
$partnerMatchesTotalValue = isset($partnerMatchesTotal) && $partnerMatchesTotal !== null
    ? max(0, (int)$partnerMatchesTotal)
    : null;
$lookupTotalPagesValue = isset($lookupTotalPages) ? max(0, (int)$lookupTotalPages) : null;
$pageDisplayValue = ($lookupTotalPagesValue !== null && $lookupTotalPagesValue > 0)
    ? min($lookupPageValue, max(1, $lookupTotalPagesValue))
    : $lookupPageValue;
$totalPartnersDisplay = $partnerMatchesTotalValue ?? $partnerMatchesCountValue;
$lookupQueryBase = $lookupQueryParams ?? [];
foreach ($lookupQueryBase as $key => $value) {
    if ($value === null) {
        unset($lookupQueryBase[$key]);
    }
}
$currentMonthNumber = (int)date('n');
$currentYearNumber = (int)date('Y');
$indicationFilterIsCurrentMonth = $indicationFilterEnabled
    && $indicationMonthValue === $currentMonthNumber
    && $indicationYearValue === $currentYearNumber;
$monthOptions = [
    0 => 'Qualquer mês',
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
$reportMonthSelectValue = max(1, min(12, (int)($reportMonthSelect ?? (int)date('n'))));
$reportYearSelectValue = (int)($reportYearSelect ?? (int)date('Y'));
?>

<header>
    <div>
        <h1>Parceiros &amp; Contadores</h1>
        <p>Organize indicadores, cadastre contadores e acompanhe as indicações com o mesmo fluxo do módulo de configurações.</p>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
        <a href="<?= url('crm'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar ao CRM</a>
        <a href="<?= url('crm/clients'); ?>" style="text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px;background:rgba(56,189,248,0.18);border:1px solid rgba(56,189,248,0.28);color:var(--accent);">Abrir carteira de clientes</a>
    </div>
</header>

<style>
    .partners-layout {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        margin-top: 24px;
    }
    .partners-menu {
        flex: 0 0 250px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        position: sticky;
        top: 90px;
        max-height: calc(100vh - 120px);
        overflow-y: auto;
        padding-right: 4px;
    }
    .partners-menu button {
        border: 1px solid rgba(148,163,184,0.3);
        border-radius: 14px;
        background: rgba(15,23,42,0.55);
        color: var(--text);
        text-align: left;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 0.9rem;
        cursor: pointer;
        transition: border 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }
    .partners-menu button span {
        color: var(--muted);
        font-size: 0.78rem;
    }
    .partners-menu button.is-active {
        border-color: rgba(56,189,248,0.6);
        background: rgba(56,189,248,0.08);
        box-shadow: 0 18px 40px -30px rgba(56,189,248,0.8);
    }
    .partners-content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    .partners-section {
        margin: 0;
    }
    .partners-layout[data-ready="true"] .partners-section {
        display: none;
    }
    .partners-layout[data-ready="true"] .partners-section.is-active {
        display: block;
    }
    @media (max-width: 1024px) {
        .partners-layout {
            flex-direction: column;
        }
        .partners-menu {
            position: static;
            flex-direction: row;
            flex-wrap: wrap;
            max-height: none;
        }
        .partners-menu button {
            flex: 1 1 200px;
        }
    }
    @media (max-width: 640px) {
        .partners-menu button {
            flex: 1 1 100%;
        }
    }
    .partners-report-controls {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 18px;
    }
    .partners-report-periods {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .partners-report-periods label {
        border: 1px solid rgba(148,163,184,0.35);
        border-radius: 999px;
        padding: 8px 14px;
        font-size: 0.85rem;
        color: var(--muted);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(15,23,42,0.5);
    }
    .partners-report-periods label input {
        display: none;
    }
    .partners-report-periods label input:checked + span {
        color: var(--accent);
        font-weight: 600;
    }
    .partners-report-dates {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .partners-report-dates label {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.85rem;
        color: var(--muted);
    }
    .partners-report-dates input[type="date"] {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: rgba(15,23,42,0.55);
        color: var(--text);
    }
    [data-report-custom-range][data-active="false"] {
        display: none;
    }
    .report-filter-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        justify-content: space-between;
    }
    .report-filter-actions button {
        padding: 10px 18px;
        border-radius: 10px;
        border: 1px solid rgba(56,189,248,0.35);
        background: rgba(56,189,248,0.16);
        color: var(--accent);
        font-weight: 600;
    }
    .report-range-label {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .report-billing-toggle {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        padding: 12px;
        border: 1px solid rgba(148,163,184,0.35);
        border-radius: 12px;
        background: rgba(15,23,42,0.5);
        margin-bottom: 12px;
    }
    .report-billing-toggle label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: var(--muted);
        cursor: pointer;
    }
    .report-billing-toggle input[type="radio"] {
        accent-color: #38bdf8;
    }
    .report-money-alert {
        font-size: 0.85rem;
        margin: 6px 0 14px;
        padding: 8px 12px;
        border-radius: 10px;
        background: rgba(56,189,248,0.08);
        color: var(--muted);
    }
    .report-money-alert--commission {
        background: rgba(34,197,94,0.12);
        color: #4ade80;
    }
    .report-money-alert--cost {
        background: rgba(148,163,184,0.12);
    }
    [data-report-form][data-report-mode="custo"] .report-money-column,
    [data-report-form][data-report-mode="custo"] .report-summary,
    [data-report-form][data-report-mode="custo"] .report-money-alert--commission {
        display: none;
    }
    [data-report-form][data-report-mode="comissao"] .report-money-alert--cost {
        display: none;
    }
    .partners-report-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 620px;
    }
    .partners-report-table th,
    .partners-report-table td {
        padding: 12px;
        border-bottom: 1px solid rgba(148,163,184,0.25);
        text-align: left;
        font-size: 0.9rem;
    }
    .partners-report-table tbody tr:nth-child(even) {
        background: rgba(15,23,42,0.35);
    }
    .report-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-top: 16px;
        padding: 12px;
        border: 1px dashed rgba(148,163,184,0.4);
        border-radius: 12px;
    }
    .report-summary div {
        flex: 1 1 180px;
        font-size: 0.9rem;
        color: var(--muted);
    }
    .report-summary strong {
        display: block;
        margin-top: 6px;
        font-size: 1rem;
        color: var(--text);
    }
    .report-empty {
        padding: 16px;
        border-radius: 12px;
        border: 1px dashed rgba(148,163,184,0.4);
        color: var(--muted);
        margin: 12px 0 18px;
    }
    .report-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 18px;
    }
    .report-actions button {
        padding: 12px 26px;
        border-radius: 12px;
        border: none;
        font-weight: 600;
        background: #38bdf8;
        color: #041421;
        cursor: pointer;
    }
    .partner-autocomplete {
        position: relative;
    }
    .partner-autocomplete-panel {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        background: rgba(15,23,42,0.95);
        border: 1px solid rgba(56,189,248,0.35);
        border-radius: 12px;
        box-shadow: 0 20px 45px -25px rgba(15,23,42,0.9);
        max-height: 260px;
        overflow-y: auto;
        z-index: 20;
        display: none;
    }
    .partner-autocomplete-panel[data-open="true"] {
        display: block;
    }
    .partner-autocomplete-option {
        padding: 10px 14px;
        border-bottom: 1px solid rgba(148,163,184,0.2);
        display: flex;
        flex-direction: column;
        gap: 4px;
        text-align: left;
        background: transparent;
        color: var(--text);
        width: 100%;
        cursor: pointer;
    }
    .partner-autocomplete-option:last-child {
        border-bottom: none;
    }
    .partner-autocomplete-option:hover,
    .partner-autocomplete-option:focus {
        background: rgba(56,189,248,0.08);
        outline: none;
    }
    .partner-autocomplete-option small {
        color: var(--muted);
        font-size: 0.75rem;
    }
</style>

<?php
$partnerMenu = [
    ['key' => 'lookup', 'label' => 'Localizar parceiro', 'description' => 'Buscar por CPF/CNPJ'],
    ['key' => 'client-search', 'label' => 'Parceiro/cliente', 'description' => 'Atualizar cadastro parceiro com o cadastro de clientes'],
    ['key' => 'form', 'label' => 'Cadastro / edição', 'description' => 'Atualizar dados'],
    ['key' => 'insights', 'label' => 'Painel de indicações', 'description' => 'Histórico e métricas'],
    ['key' => 'report', 'label' => 'Relatório de indicações', 'description' => 'Filtros e comissões'],
];
?>

<div class="partners-layout" data-partners-layout data-partners-active="<?= $initialTab; ?>">
    <aside class="partners-menu">
        <?php foreach ($partnerMenu as $index => $item):
            $target = htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8');
            $isActive = $item['key'] === ($activeTab ?? 'lookup');
        ?>
            <button type="button" class="<?= $isActive ? 'is-active' : ''; ?>" data-partners-target="<?= $target; ?>">
                <strong><?= $label; ?></strong>
                <span><?= $description; ?></span>
            </button>
        <?php endforeach; ?>
    </aside>
    <div class="partners-content">

<?php if (!empty($messages)): ?>
    <?php foreach ($messages as $message):
        $type = $message['type'] ?? 'info';
        $text = trim((string)($message['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        $color = '#38bdf8';
        if ($type === 'success') {
            $color = '#22c55e';
        } elseif ($type === 'error') {
            $color = '#f87171';
        }
    ?>
        <div class="panel" style="border-left:4px solid <?= $color; ?>;margin-bottom:24px;">
            <strong style="display:block;margin-bottom:6px;">Aviso</strong>
            <p style="margin:0;color:var(--muted);">
                <?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

        <section class="panel partners-section<?= '' ?>" data-partners-section="lookup" style="margin-bottom:24px;<?= '' ?>">
    <form method="get" action="<?= url('crm/partners'); ?>" style="display:grid;gap:16px;">
        <input type="hidden" name="tab" value="lookup">
        <input type="hidden" name="lookup_page" value="1">
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:end;">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                CPF / CNPJ do parceiro
                <input type="text" name="document" value="<?= htmlspecialchars($documentInput, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Digite apenas números" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Nome do parceiro
                <input type="text" name="partner_name" value="<?= $partnerNameValue; ?>" placeholder="Ex.: Escritório Silva" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Sem indicar há
                <select name="indicator_gap" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach (($indicatorGapOptions ?? []) as $key => $label):
                        $keyValue = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
                        $labelValue = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
                        $selected = $indicatorGapKeyValue === $keyValue ? 'selected' : '';
                    ?>
                        <option value="<?= $keyValue; ?>" <?= $selected; ?>><?= $labelValue; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Mês da indicação
                <select name="indication_month" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <?php foreach ($monthOptions as $value => $label):
                        $selected = $indicationMonthValue === (int)$value ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$value; ?>" <?= $selected; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Ano da indicação
                <select name="indication_year" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                    <option value="0" <?= $indicationYearValue === 0 ? 'selected' : ''; ?>>Qualquer ano</option>
                    <?php foreach (($indicationYearOptions ?? []) as $yearOption):
                        $selected = $indicationYearValue === (int)$yearOption ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$yearOption; ?>" <?= $selected; ?>><?= (int)$yearOption; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="primary" type="submit" name="lookup_apply" value="1" style="padding:12px 24px;">Mostrar parceiros</button>
                <?php if ($searchPerformed): ?>
                    <a href="<?= url('crm/partners'); ?>" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.4);color:var(--muted);text-decoration:none;">Limpar busca</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (!$searchPerformed && $partner === null): ?>
        <div style="margin-top:16px;padding:14px;border-radius:12px;border:1px dashed rgba(148,163,184,0.5);color:var(--muted);background:rgba(15,23,42,0.35);">
            Aplique algum filtro e clique em "Mostrar parceiros" para carregar a lista.
        </div>
    <?php endif; ?>

    <?php if (!empty($partnerMatches) && $partner === null): ?>
        <div style="margin-top:18px;display:grid;gap:18px;">
            <div style="display:flex;flex-direction:column;gap:8px;">
                <p style="margin:0;color:var(--muted);font-size:0.85rem;">Revise os parceiros localizados e compare com todos os clientes de mesmo nome (CPF) antes de confirmar o vínculo.</p>
                <div style="padding:10px 14px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(56,189,248,0.12);color:var(--accent);font-size:0.82rem;">
                    <?= number_format($totalPartnersDisplay, 0, ',', '.'); ?> parceiros encontrados para os filtros atuais.
                    <span style="display:block;font-size:0.78rem;color:var(--muted);margin-top:4px;">
                        Exibindo até <?= $partnerMatchesPerPageValue; ?> por página &middot;
                        <?php if ($lookupTotalPagesValue !== null && $lookupTotalPagesValue > 0): ?>
                            Página <?= $pageDisplayValue; ?> de <?= $lookupTotalPagesValue; ?>.
                        <?php else: ?>
                            Página <?= $lookupPageValue; ?>.
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($indicationFilterEnabled && $indicationPeriodLabelRaw !== null): ?>
                    <div style="padding:8px 12px;border-radius:10px;border:1px dashed rgba(148,163,184,0.5);color:var(--muted);font-size:0.8rem;">
                        Filtrando parceiros com indicações registradas em <?= htmlspecialchars($indicationPeriodLabelRaw, ENT_QUOTES, 'UTF-8'); ?>.
                    </div>
                <?php endif; ?>
                <?php $showPagination = ($lookupPageValue > 1)
                    || !empty($partnerMatchesLimited)
                    || ($lookupTotalPagesValue !== null && $lookupTotalPagesValue > 1); ?>
                <?php if ($showPagination): ?>
                    <?php
                        $hasPreciseTotals = $lookupTotalPagesValue !== null && $lookupTotalPagesValue > 0;
                        $showPrev = $lookupPageValue > 1;
                        $showNext = $hasPreciseTotals
                            ? ($lookupPageValue < $lookupTotalPagesValue)
                            : !empty($partnerMatchesLimited);
                        $maxButtons = 5;
                        $windowStart = 1;
                        $windowEnd = $hasPreciseTotals ? min($lookupTotalPagesValue, $maxButtons) : 1;
                        if ($hasPreciseTotals) {
                            $windowStart = max(1, $lookupPageValue - 2);
                            $windowEnd = min($lookupTotalPagesValue, $windowStart + $maxButtons - 1);
                            $windowStart = max(1, $windowEnd - $maxButtons + 1);
                        }
                    ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <?php if ($showPrev):
                            $prevQuery = $lookupQueryBase;
                            $prevQuery['lookup_page'] = max(1, $lookupPageValue - 1);
                        ?>
                            <a href="<?= url('crm/partners') . '?' . http_build_query($prevQuery); ?>" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(148,163,184,0.4);color:var(--muted);text-decoration:none;">&larr; Página anterior</a>
                        <?php endif; ?>

                        <?php if ($hasPreciseTotals): ?>
                            <?php if ($windowStart > 1):
                                $firstQuery = $lookupQueryBase;
                                $firstQuery['lookup_page'] = 1;
                            ?>
                                <a href="<?= url('crm/partners') . '?' . http_build_query($firstQuery); ?>" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(148,163,184,0.4);color:var(--muted);text-decoration:none;">1</a>
                                <?php if ($windowStart > 2): ?>
                                    <span style="color:var(--muted);">&hellip;</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($page = $windowStart; $page <= $windowEnd; $page++):
                                $pageQuery = $lookupQueryBase;
                                $pageQuery['lookup_page'] = $page;
                                $isActive = $page === $lookupPageValue;
                                $borderColor = $isActive ? 'rgba(56,189,248,0.6)' : 'rgba(148,163,184,0.4)';
                                $textColor = $isActive ? 'var(--accent)' : 'var(--muted)';
                                $background = $isActive ? 'rgba(56,189,248,0.12)' : 'transparent';
                            ?>
                                <a href="<?= url('crm/partners') . '?' . http_build_query($pageQuery); ?>" style="padding:8px 14px;border-radius:10px;border:1px solid <?= $borderColor; ?>;color:<?= $textColor; ?>;text-decoration:none;background:<?= $background; ?>;font-weight:<?= $isActive ? '600' : '400'; ?>;">
                                    <?= $page; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($windowEnd < $lookupTotalPagesValue):
                                if ($windowEnd < $lookupTotalPagesValue - 1): ?>
                                    <span style="color:var(--muted);">&hellip;</span>
                                <?php endif;
                                $lastQuery = $lookupQueryBase;
                                $lastQuery['lookup_page'] = $lookupTotalPagesValue;
                            ?>
                                <a href="<?= url('crm/partners') . '?' . http_build_query($lastQuery); ?>" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(148,163,184,0.4);color:var(--muted);text-decoration:none;">
                                    <?= $lookupTotalPagesValue; ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($showNext):
                            $nextQuery = $lookupQueryBase;
                            $nextQuery['lookup_page'] = $lookupPageValue + 1;
                        ?>
                            <a href="<?= url('crm/partners') . '?' . http_build_query($nextQuery); ?>" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(56,189,248,0.4);color:var(--accent);text-decoration:none;">Próxima página &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php foreach ($partnerMatches as $match):
                $matchId = (int)($match['id'] ?? 0);
                $matchName = htmlspecialchars((string)($match['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchDoc = htmlspecialchars((string)($match['document_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchDocLabel = $matchDoc !== '' ? $matchDoc : 'Documento não informado';
                $matchPhone = htmlspecialchars((string)($match['phone_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchEmail = htmlspecialchars((string)($match['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicator = $match['indicator'] ?? [];
                $indicatorColor = htmlspecialchars((string)($indicator['color'] ?? '#475569'), ENT_QUOTES, 'UTF-8');
                $indicatorStatus = htmlspecialchars((string)($indicator['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicatorDescription = htmlspecialchars((string)($indicator['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicationsTotalRaw = (int)($match['indications_total'] ?? 0);
                $indicationsTotal = number_format($indicationsTotalRaw, 0, ',', '.');
                $indicationsMonthRaw = (int)($match['indications_month'] ?? 0);
                $indicationsMonth = number_format($indicationsMonthRaw, 0, ',', '.');
                $indicationsMonthLabel = $indicationsMonthRaw === 1 ? '1 indicação' : $indicationsMonth . ' indicações';
                $indicationsPeriodRaw = (int)($match['indications_period'] ?? 0);
                $indicationsPeriod = number_format($indicationsPeriodRaw, 0, ',', '.');
                $indicationsPeriodCountLabel = $indicationsPeriodRaw === 1 ? '1 indicação' : $indicationsPeriod . ' indicações';
                $indicationsTotalLabel = $indicationsTotalRaw === 1 ? '1 indicação' : $indicationsTotal . ' indicações';
                $clients = $match['clients'] ?? [];
                $query = http_build_query(array_filter([
                    'partner_id' => $matchId,
                    'partner_name' => $partnerNameQuery,
                ]));
                $loadUrl = url('crm/partners') . ($query !== '' ? '?' . $query : '');
            ?>
                <div style="border:1px solid var(--border);border-left:5px solid <?= $indicatorColor; ?>;border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);display:flex;flex-direction:column;gap:14px;" data-partner-card>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;justify-content:space-between;">
                        <div style="flex:1;min-width:220px;">
                            <strong style="display:block;font-size:1.05rem;"><?= $matchName; ?></strong>
                            <div style="font-size:0.85rem;color:var(--muted);">CPF/CNPJ: <?= $matchDocLabel; ?></div>
                            <?php if ($matchPhone !== '' || $matchEmail !== ''): ?>
                                <div style="margin-top:6px;font-size:0.82rem;color:var(--muted);">
                                    <?php if ($matchPhone !== ''): ?>Telefone: <?= $matchPhone; ?><?php endif; ?>
                                    <?php if ($matchPhone !== '' && $matchEmail !== ''): ?> · <?php endif; ?>
                                    <?php if ($matchEmail !== ''): ?>E-mail: <?= $matchEmail; ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($indicatorDescription !== ''): ?>
                                <div style="margin-top:6px;font-size:0.82rem;color:var(--muted);">
                                    <?= $indicatorDescription; ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top:8px;font-size:0.82rem;color:var(--muted);">
                                <?php if ($indicationFilterEnabled && ($indicationFilterIsCurrentMonth || $indicationPeriodLabelRaw !== null)): ?>
                                    <?php if ($indicationFilterIsCurrentMonth): ?>
                                        No mês atual <?= $indicationsPeriodCountLabel; ?>.
                                    <?php else: ?>
                                        No período de <?= htmlspecialchars($indicationPeriodLabelRaw, ENT_QUOTES, 'UTF-8'); ?> <?= $indicationsPeriodCountLabel; ?>.
                                    <?php endif; ?>
                                    <?php if ($indicationsMonthRaw !== $indicationsPeriodRaw): ?>
                                        Neste mês <?= $indicationsMonthLabel; ?>.
                                    <?php endif; ?>
                                    Total de <?= $indicationsTotalLabel; ?> deste parceiro.
                                <?php else: ?>
                                    Neste mês <?= $indicationsMonthLabel; ?> e total de <?= $indicationsTotalLabel; ?> deste parceiro.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                            <?php if ($indicatorStatus !== ''): ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:0.78rem;font-weight:600;background:rgba(255,255,255,0.04);color:<?= $indicatorColor; ?>;border:1px solid rgba(255,255,255,0.08);">
                                    <span style="width:8px;height:8px;border-radius:999px;background:<?= $indicatorColor; ?>;display:inline-block;"></span>
                                    <?= $indicatorStatus; ?>
                                </span>
                            <?php endif; ?>
                            <a href="<?= $loadUrl; ?>" style="padding:10px 18px;border-radius:10px;background:#38bdf8;color:#052235;font-weight:600;text-decoration:none;">Carregar parceiro</a>
                        </div>
                    </div>
                    <?php if ($clients !== []): ?>
                        <div style="border-top:1px solid rgba(148,163,184,0.25);padding-top:12px;">
                            <strong style="display:block;font-size:0.9rem;margin-bottom:8px;">Clientes com o mesmo nome (CPF)</strong>
                            <div style="display:grid;gap:12px;">
                                <?php foreach ($clients as $client):
                                    $clientId = (int)($client['id'] ?? 0);
                                    $clientName = htmlspecialchars((string)($client['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $clientDoc = htmlspecialchars((string)($client['document_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $clientDocLabel = $clientDoc !== '' ? $clientDoc : 'Documento não informado';
                                    $clientStatus = htmlspecialchars((string)($client['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $clientPartnerTag = htmlspecialchars((string)($client['partner_tag'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;border:1px solid rgba(148,163,184,0.35);border-radius:12px;padding:12px 14px;background:rgba(15,23,42,0.45);" data-partner-card>
                                        <div style="min-width:220px;">
                                            <strong><?= $clientName; ?></strong>
                                            <div style="font-size:0.82rem;color:var(--muted);">CPF: <?= $clientDocLabel; ?></div>
                                            <div style="font-size:0.78rem;color:var(--muted);">Status: <?= $clientStatus !== '' ? $clientStatus : '—'; ?></div>
                                            <?php if ($clientPartnerTag !== ''): ?>
                                                <span style="display:inline-block;margin-top:6px;font-size:0.75rem;padding:4px 10px;border-radius:999px;background:rgba(34,197,94,0.12);color:#4ade80;font-weight:600;">
                                                    <?= 'Já indicado como ' . $clientPartnerTag; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                                            <form method="post" action="<?= url('crm/partners/link'); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;" data-partner-link-form>
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="client_id" value="<?= $clientId; ?>">
                                                <input type="hidden" name="document" value="<?= $documentDigitsValue; ?>">
                                                <input type="hidden" name="partner_name" value="<?= $partnerNameValue; ?>">
                                                <input type="hidden" name="client_query" value="<?= $clientQueryValue; ?>">
                                                <input type="hidden" name="redirect_tab" value="lookup">
                                                <input type="hidden" name="stay_on_client_search" value="1">
                                                <input type="hidden" name="async" value="1">
                                                <button type="submit" style="padding:10px 18px;border:none;border-radius:12px;background:#22c55e;color:#052e16;font-weight:600;cursor:pointer;">
                                                    Atualizar cadastro do parceiro
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel partners-section" data-partners-section="client-search" style="margin-bottom:24px;">
    <h2 style="margin-top:0;">Localizar na base de clientes</h2>
    <p style="margin:0 0 16px;color:var(--muted);">Busque pelo nome do parceiro (somente documentos CPF serão exibidos). Deixe o campo em branco para listar apenas parceiros pendentes de atualização.</p>
    <form method="get" action="<?= url('crm/partners'); ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;">
        <input type="hidden" name="tab" value="client-search">
        <input type="hidden" name="document" value="<?= htmlspecialchars($documentInput, ENT_QUOTES, 'UTF-8'); ?>">
        <label style="flex:1;display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);min-width:260px;">
            Nome ou documento do cliente
            <input type="text" name="client_query" value="<?= htmlspecialchars($clientQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex.: Karine Oliveira" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
        </label>
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" style="padding:12px 20px;border-radius:12px;border:1px solid rgba(56,189,248,0.35);background:rgba(56,189,248,0.18);color:var(--accent);font-weight:600;">Buscar cliente</button>
            <?php if ($clientQuery !== ''): ?>
                <a href="<?= url('crm/partners'); ?>" style="text-decoration:none;color:var(--muted);">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php $showingAvailable = ($clientSearchMode ?? 'query') === 'available'; ?>
    <?php if (!empty($clientTabPartnerMatches ?? [])): ?>
        <div style="display:grid;gap:18px;margin-bottom:20px;">
            <p style="margin:0;color:var(--muted);font-size:0.85rem;">Parceiros com clientes pendentes para atualizar o cadastro.</p>
            <?php foreach ($clientTabPartnerMatches as $match):
                $matchId = (int)($match['id'] ?? 0);
                $matchName = htmlspecialchars((string)($match['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchDoc = htmlspecialchars((string)($match['document_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchDocLabel = $matchDoc !== '' ? $matchDoc : 'Documento não informado';
                $matchPhone = htmlspecialchars((string)($match['phone_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                $matchEmail = htmlspecialchars((string)($match['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicator = $match['indicator'] ?? [];
                $indicatorColor = htmlspecialchars((string)($indicator['color'] ?? '#475569'), ENT_QUOTES, 'UTF-8');
                $indicatorStatus = htmlspecialchars((string)($indicator['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicatorDescription = htmlspecialchars((string)($indicator['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                $indicationsTotalRaw = (int)($match['indications_total'] ?? 0);
                $indicationsTotal = number_format($indicationsTotalRaw, 0, ',', '.');
                $indicationsMonthRaw = (int)($match['indications_month'] ?? 0);
                $indicationsMonth = number_format($indicationsMonthRaw, 0, ',', '.');
                $indicationsMonthLabel = $indicationsMonthRaw === 1 ? '1 indicação' : $indicationsMonth . ' indicações';
                $indicationsPeriodRaw = (int)($match['indications_period'] ?? 0);
                $indicationsPeriod = number_format($indicationsPeriodRaw, 0, ',', '.');
                $indicationsPeriodCountLabel = $indicationsPeriodRaw === 1 ? '1 indicação' : $indicationsPeriod . ' indicações';
                $indicationsTotalLabel = $indicationsTotalRaw === 1 ? '1 indicação' : $indicationsTotal . ' indicações';
                $clients = $match['clients'] ?? [];
                if ($clients === []) {
                    continue;
                }
                $query = http_build_query(array_filter([
                    'partner_id' => $matchId,
                    'partner_name' => $partnerNameQuery,
                ]));
                $loadUrl = url('crm/partners') . ($query !== '' ? '?' . $query : '');
            ?>
                <div style="border:1px solid var(--border);border-left:5px solid <?= $indicatorColor; ?>;border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);display:flex;flex-direction:column;gap:14px;" data-partner-card>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;justify-content:space-between;">
                        <div style="flex:1;min-width:220px;">
                            <strong style="display:block;font-size:1.05rem;"><?= $matchName; ?></strong>
                            <div style="font-size:0.85rem;color:var(--muted);">CPF/CNPJ: <?= $matchDocLabel; ?></div>
                            <?php if ($matchPhone !== '' || $matchEmail !== ''): ?>
                                <div style="margin-top:6px;font-size:0.82rem;color:var(--muted);">
                                    <?php if ($matchPhone !== ''): ?>Telefone: <?= $matchPhone; ?><?php endif; ?>
                                    <?php if ($matchPhone !== '' && $matchEmail !== ''): ?> · <?php endif; ?>
                                    <?php if ($matchEmail !== ''): ?>E-mail: <?= $matchEmail; ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($indicatorDescription !== ''): ?>
                                <div style="margin-top:6px;font-size:0.82rem;color:var(--muted);">
                                    <?= $indicatorDescription; ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top:8px;font-size:0.82rem;color:var(--muted);">
                                <?php if ($indicationFilterEnabled && ($indicationFilterIsCurrentMonth || $indicationPeriodLabelRaw !== null)): ?>
                                    <?php if ($indicationFilterIsCurrentMonth): ?>
                                        No mês atual <?= $indicationsPeriodCountLabel; ?>.
                                    <?php else: ?>
                                        No período de <?= htmlspecialchars($indicationPeriodLabelRaw, ENT_QUOTES, 'UTF-8'); ?> <?= $indicationsPeriodCountLabel; ?>.
                                    <?php endif; ?>
                                    <?php if ($indicationsMonthRaw !== $indicationsPeriodRaw): ?>
                                        Neste mês <?= $indicationsMonthLabel; ?>.
                                    <?php endif; ?>
                                    Total de <?= $indicationsTotalLabel; ?> deste parceiro.
                                <?php else: ?>
                                    Neste mês <?= $indicationsMonthLabel; ?> e total de <?= $indicationsTotalLabel; ?> deste parceiro.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                            <?php if ($indicatorStatus !== ''): ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:0.78rem;font-weight:600;background:rgba(255,255,255,0.04);color:<?= $indicatorColor; ?>;border:1px solid rgba(255,255,255,0.08);">
                                    <span style="width:8px;height:8px;border-radius:999px;background:<?= $indicatorColor; ?>;display:inline-block;"></span>
                                    <?= $indicatorStatus; ?>
                                </span>
                            <?php endif; ?>
                            <a href="<?= $loadUrl; ?>" style="padding:10px 18px;border-radius:10px;background:#38bdf8;color:#052235;font-weight:600;text-decoration:none;">Carregar parceiro</a>
                        </div>
                    </div>
                    <div style="border-top:1px solid rgba(148,163,184,0.25);padding-top:12px;">
                        <strong style="display:block;font-size:0.9rem;margin-bottom:8px;">Clientes com o mesmo nome (CPF)</strong>
                        <div style="display:grid;gap:12px;">
                            <?php foreach ($clients as $client):
                                $clientId = (int)($client['id'] ?? 0);
                                $clientName = htmlspecialchars((string)($client['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $clientDoc = htmlspecialchars((string)($client['document_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $clientDocLabel = $clientDoc !== '' ? $clientDoc : 'Documento não informado';
                                $clientStatus = htmlspecialchars((string)($client['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $clientPartnerTag = htmlspecialchars((string)($client['partner_tag'] ?? ''), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;border:1px solid rgba(148,163,184,0.35);border-radius:12px;padding:12px 14px;background:rgba(15,23,42,0.45);" data-partner-card>
                                    <div style="min-width:220px;">
                                    <strong><?= $clientName; ?></strong>
                                        <div style="font-size:0.82rem;color:var(--muted);">CPF: <?= $clientDocLabel; ?></div>
                                        <div style="font-size:0.78rem;color:var(--muted);">Status: <?= $clientStatus !== '' ? $clientStatus : '—'; ?></div>
                                        <?php if ($clientPartnerTag !== ''): ?>
                                            <span style="display:inline-block;margin-top:6px;font-size:0.75rem;padding:4px 10px;border-radius:999px;background:rgba(34,197,94,0.12);color:#4ade80;font-weight:600;">
                                                <?= 'Já indicado como ' . $clientPartnerTag; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                                        <form method="post" action="<?= url('crm/partners/link'); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;" data-partner-link-form>
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="client_id" value="<?= $clientId; ?>">
                                            <input type="hidden" name="document" value="<?= $documentDigitsValue; ?>">
                                            <input type="hidden" name="partner_name" value="<?= $partnerNameValue; ?>">
                                            <input type="hidden" name="client_query" value="<?= $clientQueryValue; ?>">
                                            <input type="hidden" name="redirect_tab" value="client-search">
                                            <input type="hidden" name="stay_on_client_search" value="1">
                                            <input type="hidden" name="async" value="1">
                                            <button type="submit" style="padding:10px 18px;border:none;border-radius:12px;background:#22c55e;color:#052e16;font-weight:600;cursor:pointer;">
                                                Atualizar cadastro do parceiro
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($clientResults)): ?>
        <div style="display:grid;gap:12px;">
            <?php foreach ($clientResults as $result):
                $clientId = (int)($result['id'] ?? 0);
                $document = htmlspecialchars((string)($result['document_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars((string)($result['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $status = htmlspecialchars((string)($result['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                $partnerTag = trim((string)($result['partner_accountant'] ?? $result['partner_accountant_plus'] ?? $result['partner_tag'] ?? ''));
            ?>
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;border:1px solid var(--border);border-radius:14px;padding:14px 18px;background:rgba(15,23,42,0.55);" data-partner-card>
                    <div>
                        <strong style="display:block;font-size:1rem;"><?= $name; ?></strong>
                        <span style="display:block;font-size:0.85rem;color:var(--muted);">Documento: <?= $document !== '' ? $document : 'Não informado'; ?></span>
                        <span style="display:block;font-size:0.8rem;color:var(--muted);">Status: <?= $status !== '' ? $status : '—'; ?></span>
                        <?php if ($partnerTag !== ''): ?>
                            <span style="display:inline-block;margin-top:6px;font-size:0.75rem;padding:4px 10px;border-radius:999px;background:rgba(34,197,94,0.15);color:#4ade80;font-weight:600;">
                                <?= 'Já indicado como ' . htmlspecialchars($partnerTag, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                        <form method="post" action="<?= url('crm/partners/link'); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;" data-partner-link-form>
                            <?= csrf_field(); ?>
                            <input type="hidden" name="client_id" value="<?= $clientId; ?>">
                            <input type="hidden" name="document" value="<?= $documentDigitsValue; ?>">
                            <input type="hidden" name="partner_name" value="<?= $partnerNameValue; ?>">
                            <input type="hidden" name="client_query" value="<?= $clientQueryValue; ?>">
                            <input type="hidden" name="redirect_tab" value="client-search">
                            <input type="hidden" name="stay_on_client_search" value="1">
                            <input type="hidden" name="async" value="1">
                            <button type="submit" style="padding:10px 18px;border:none;border-radius:12px;background:#22c55e;color:#052e16;font-weight:600;cursor:pointer;">
                                Atualizar cadastro do parceiro
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($clientQuery !== ''): ?>
        <div style="padding:14px;border-radius:12px;border:1px dashed rgba(148,163,184,0.35);color:var(--muted);">Nenhum parceiro pendente corresponde à busca atual.</div>
    <?php else: ?>
        <div style="padding:14px;border-radius:12px;border:1px dashed rgba(148,163,184,0.35);color:var(--muted);">Todos os parceiros pendentes já tiveram o cadastro atualizado.</div>
    <?php endif; ?>
</section>

<section class="panel partners-section" data-partners-section="form" style="margin-bottom:24px;">
    <h2 style="margin-top:0;">Cadastro do parceiro</h2>
    <p style="margin:0 0 16px;color:var(--muted);">Ao localizar um CPF/CNPJ existente o formulário carrega os dados automaticamente. Se não existir, preencha para criar o registro.</p>
    <form method="post" action="<?= url('crm/partners'); ?>" style="display:grid;gap:18px;">
        <input type="hidden" name="partner_id" value="<?= htmlspecialchars((string)($formData['partner_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($formData['type'] ?? 'contador', ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                CPF / CNPJ
                <input type="text" name="document" value="<?= htmlspecialchars($documentFormatted, ENT_QUOTES, 'UTF-8'); ?>" placeholder="000.000.000-00" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['document']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);">
                <?php if (isset($errors['document'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['document'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Nome completo / empresa
                <input type="text" name="name" value="<?= htmlspecialchars($formData['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do parceiro" style="padding:12px;border-radius:12px;border:1px solid <?= isset($errors['name']) ? '#f87171' : 'var(--border)'; ?>;background:rgba(15,23,42,0.58);color:var(--text);text-transform:uppercase;">
                <?php if (isset($errors['name'])): ?>
                    <small style="color:#f87171;"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                E-mail
                <input type="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="contato@empresa.com" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                Telefone / WhatsApp
                <input type="text" name="phone" value="<?= htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8'); ?>" placeholder="82999998888" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
            </label>
        </div>
        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
            Observações
            <textarea name="notes" rows="3" placeholder="Informações adicionais sobre o parceiro" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);resize:vertical;"><?= htmlspecialchars($formData['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <div style="display:flex;justify-content:flex-end;">
            <button class="primary" type="submit" style="padding:14px 28px;">
                <?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </div>
    </form>
</section>

<?php if ($partner !== null): ?>
    <section class="panel partners-section" data-partners-section="insights" style="margin-bottom:24px;display:grid;gap:16px;">
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);">
                <span style="display:block;font-size:0.75rem;letter-spacing:0.08em;color:var(--muted);text-transform:uppercase;">Parceiro</span>
                <strong style="display:block;margin-top:10px;font-size:1.1rem;">
                    <?= htmlspecialchars((string)($partner['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </strong>
                <span style="display:block;margin-top:6px;font-size:0.85rem;color:var(--muted);">
                    Documento: <?= $documentFormatted !== '' ? htmlspecialchars($documentFormatted, ENT_QUOTES, 'UTF-8') : '—'; ?>
                </span>
            </div>
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);">
                <span style="display:block;font-size:0.75rem;letter-spacing:0.08em;color:var(--muted);text-transform:uppercase;">Contatos</span>
                <div style="margin-top:10px;font-size:0.9rem;display:grid;gap:6px;">
                    <span>E-mail: <?= !empty($partner['email']) ? htmlspecialchars($partner['email'], ENT_QUOTES, 'UTF-8') : '—'; ?></span>
                    <span>Telefone: <?= $phoneDisplay !== '' ? htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
                </div>
            </div>
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);">
                <span style="display:block;font-size:0.75rem;letter-spacing:0.08em;color:var(--muted);text-transform:uppercase;">Indicações (12 meses)</span>
                <strong style="display:block;margin-top:10px;font-size:1.4rem;">
                    <?= number_format($totalIndications, 0, ',', '.'); ?>
                </strong>
                <span style="display:block;margin-top:6px;font-size:0.85rem;color:var(--muted);">Volume baseado nas emissões do período selecionado.</span>
            </div>
        </div>
        <?php if (!empty($partner['notes'])): ?>
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.45);color:var(--muted);">
                <strong style="display:block;margin-bottom:6px;">Observações registradas</strong>
                <p style="margin:0;white-space:pre-line;"><?= htmlspecialchars((string)$partner['notes'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel partners-section" data-partners-section="insights">
        <h2 style="margin-top:0;">Indicações por mês (últimos 12 meses)</h2>
        <p style="margin:0 0 16px;color:var(--muted);">Contagem baseada na data de atualização mais recente do certificado, considerando indicações com o nome deste parceiro.</p>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:520px;">
                <thead>
                    <tr style="background:rgba(30,41,59,0.65);">
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Mês</th>
                        <th style="text-align:left;padding:12px;border-bottom:1px solid rgba(148,163,184,0.35);">Indicações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlySeries as $point):
                        $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $total = number_format((int)($point['total'] ?? 0), 0, ',', '.');
                    ?>
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.2);">
                            <td style="padding:12px;font-weight:600;"><?= $label; ?></td>
                            <td style="padding:12px;color:var(--muted);font-weight:600;"><?= $total; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php elseif ($searchPerformed): ?>
    <section class="panel partners-section" data-partners-section="insights">
        <h2 style="margin-top:0;">Indicações por mês</h2>
        <p style="color:var(--muted);margin:0;">Cadastre o parceiro para começar a acompanhar o volume de indicações.</p>
    </section>
<?php endif; ?>

        <section class="panel partners-section" data-partners-section="report" style="margin-bottom:24px;">
            <h2 style="margin-top:0;">Relatório de indicações</h2>
            <p style="margin:0 0 16px;color:var(--muted);">Filtre, visualize e salve os lançamentos de custo/venda para este parceiro.</p>

            <div class="partners-report-controls" style="margin-bottom:18px;">
                <form method="get" action="<?= url('crm/partners'); ?>" data-report-partner-search data-partner-autocomplete-endpoint="<?= url('crm/partners/autocomplete'); ?>" style="display:grid;gap:12px;">
                    <input type="hidden" name="tab" value="report">
                    <input type="hidden" name="lookup_apply" value="1">
                    <input type="hidden" name="lookup_page" value="1">
                    <input type="hidden" name="report_period" value="custom">
                    <input type="hidden" name="report_mode" value="<?= htmlspecialchars($reportMode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="partner_id" value="<?= $partnerIdValue; ?>" data-partner-id-input>
                    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                            CPF / CNPJ do parceiro
                            <input type="text" name="document" value="<?= htmlspecialchars($documentInput, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Digite o documento" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);" data-partner-document-input data-autofill="0">
                        </label>
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                            Nome do parceiro
                            <div class="partner-autocomplete" data-partner-autocomplete>
                                <input type="text" name="partner_name" value="<?= $partnerNameValue; ?>" placeholder="Ex.: Escritório Silva" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);" autocomplete="off" data-partner-autocomplete-input>
                                <div class="partner-autocomplete-panel" data-partner-autocomplete-panel></div>
                            </div>
                        </label>
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                            Mês do relatório
                            <select name="report_month_select" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                                <?php foreach ($monthOptions as $value => $label):
                                    if ((int)$value === 0) {
                                        continue;
                                    }
                                    $selected = (int)$value === $reportMonthSelectValue ? 'selected' : '';
                                ?>
                                    <option value="<?= (int)$value; ?>" <?= $selected; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                            Ano do relatório
                            <select name="report_year_select" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.58);color:var(--text);">
                                <?php foreach ($reportYearOptions as $yearOption):
                                    $selected = (int)$yearOption === $reportYearSelectValue ? 'selected' : '';
                                ?>
                                    <option value="<?= (int)$yearOption; ?>" <?= $selected; ?>><?= (int)$yearOption; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:flex-end;">
                        <button type="submit" class="primary" style="padding:12px 22px;">Carregar relatório</button>
                        <?php if ($partner !== null): ?>
                            <a href="<?= url('crm/partners') . '?tab=report'; ?>" style="text-decoration:none;color:var(--muted);">Limpar seleção</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($partner === null): ?>
                <div style="padding:14px;border-radius:12px;border:1px dashed rgba(148,163,184,0.4);color:var(--muted);">
                    Use o formulário acima para localizar o parceiro e definir mês/ano antes de gerar o relatório.
                </div>
            <?php else: ?>
                <div class="partners-report-controls">
                    <form method="get" action="<?= url('crm/partners'); ?>" class="report-filter-form" data-report-filter>
                        <input type="hidden" name="document" value="<?= htmlspecialchars(digits_only($documentInput), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="tab" value="report">
                        <input type="hidden" name="report_mode" value="<?= htmlspecialchars($reportMode, ENT_QUOTES, 'UTF-8'); ?>" data-report-mode-input>
                        <input type="hidden" name="report_month_select" value="<?= $reportMonthSelectValue; ?>">
                        <input type="hidden" name="report_year_select" value="<?= $reportYearSelectValue; ?>">
                        <div class="partners-report-periods">
                            <?php foreach ($reportPeriodOptions as $value => $label):
                                $valueAttr = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                                $labelText = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                $checked = $reportPeriod === $value ? 'checked' : '';
                            ?>
                                <label>
                                    <input type="radio" name="report_period" value="<?= $valueAttr; ?>" <?= $checked; ?> data-report-period>
                                    <span><?= $labelText; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="partners-report-dates" data-report-custom-range data-active="<?= $reportPeriod === 'custom' ? 'true' : 'false'; ?>">
                            <label>
                                Data inicial
                                <input type="date" name="report_start" value="<?= htmlspecialchars($reportFilters['start_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label>
                                Data final
                                <input type="date" name="report_end" value="<?= htmlspecialchars($reportFilters['end_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                        </div>
                        <div class="report-filter-actions">
                            <button type="submit">Aplicar filtro</button>
                            <?php if ($reportRangeLabel !== ''): ?>
                                <span class="report-range-label">Período: <?= htmlspecialchars($reportRangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <form method="post" action="<?= url('crm/partners/report'); ?>" data-report-form data-report-mode="<?= htmlspecialchars($reportMode, ENT_QUOTES, 'UTF-8'); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="partner_id" value="<?= (int)($partner['id'] ?? 0); ?>">
                    <input type="hidden" name="report_period" value="<?= htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_start" value="<?= htmlspecialchars($reportFilters['start_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_end" value="<?= htmlspecialchars($reportFilters['end_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="report-billing-toggle">
                        <label>
                            <input type="radio" name="billing_mode" value="custo" <?= $reportMode === 'custo' ? 'checked' : ''; ?> data-report-mode-option>
                            <span>Valor de custo</span>
                        </label>
                        <label>
                            <input type="radio" name="billing_mode" value="comissao" <?= $reportMode === 'comissao' ? 'checked' : ''; ?> data-report-mode-option>
                            <span>Comissão (custo x venda)</span>
                        </label>
                    </div>
                    <p class="report-money-alert report-money-alert--cost">Modo custo selecionado. Nenhuma informação adicional é necessária para salvar.</p>
                    <p class="report-money-alert report-money-alert--commission">Informe os valores de custo e venda para cada indicação. O resultado e o total serão calculados automaticamente.</p>

                    <?php if (empty($reportRows)): ?>
                        <div class="report-empty">Nenhuma indicação encontrada no período selecionado.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="partners-report-table" data-report-table>
                                <thead>
                                    <tr>
                                        <th>Protocolo</th>
                                        <th>Status</th>
                                        <th>CNPJ</th>
                                        <th>Razão social</th>
                                        <th class="report-money-column">Valor de custo</th>
                                        <th class="report-money-column">Valor de venda</th>
                                        <th class="report-money-column">Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportRows as $row):
                                        $certificateId = (int)($row['certificate_id'] ?? 0);
                                        $protocol = htmlspecialchars((string)($row['protocol'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $status = htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $documentValue = format_document($row['document'] ?? '');
                                        $documentDisplay = $documentValue !== '' ? $documentValue : '—';
                                        $clientName = htmlspecialchars((string)($row['client_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $costValue = $row['cost_value'] ?? null;
                                        $saleValue = $row['sale_value'] ?? null;
                                        $resultValue = $row['result_value'] ?? null;
                                    ?>
                                        <tr data-report-row>
                                            <td>
                                                <strong><?= $protocol !== '' ? $protocol : '—'; ?></strong>
                                                <input type="hidden" name="entries[<?= $certificateId; ?>][protocol]" value="<?= $protocol; ?>">
                                            </td>
                                            <td><?= $status !== '' ? $status : '—'; ?></td>
                                            <td><?= htmlspecialchars($documentDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= $clientName !== '' ? $clientName : '—'; ?></td>
                                            <td class="report-money-column">
                                                <input type="text" name="entries[<?= $certificateId; ?>][cost]" value="<?= htmlspecialchars(format_money_input($costValue), ENT_QUOTES, 'UTF-8'); ?>" placeholder="0,00" inputmode="decimal" data-report-input="cost">
                                            </td>
                                            <td class="report-money-column">
                                                <input type="text" name="entries[<?= $certificateId; ?>][sale]" value="<?= htmlspecialchars(format_money_input($saleValue), ENT_QUOTES, 'UTF-8'); ?>" placeholder="0,00" inputmode="decimal" data-report-input="sale">
                                            </td>
                                            <td class="report-money-column">
                            <span data-report-result><?= $resultValue !== null ? format_money($resultValue) : '—'; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="report-summary report-money-column">
                            <div>
                                Total de custos
                                <strong data-report-total="cost"><?= format_money($reportTotals['cost'] ?? 0); ?></strong>
                            </div>
                            <div>
                                Total de vendas
                                <strong data-report-total="sale"><?= format_money($reportTotals['sale'] ?? 0); ?></strong>
                            </div>
                            <div>
                                Resultado líquido
                                <strong data-report-total="result"><?= format_money($reportTotals['result'] ?? 0); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="report-actions">
                        <button type="submit">Salvar informações</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const layout = document.querySelector('[data-partners-layout]');
    if (!layout) {
        return;
    }

    layout.setAttribute('data-ready', 'true');
    const menuButtons = layout.querySelectorAll('[data-partners-target]');
    const sections = layout.querySelectorAll('[data-partners-section]');

    function activateTab(key) {
        if (!key) {
            return;
        }

        sections.forEach(section => {
            section.classList.toggle('is-active', section.getAttribute('data-partners-section') === key);
        });

        menuButtons.forEach(button => {
            button.classList.toggle('is-active', button.getAttribute('data-partners-target') === key);
        });

        layout.setAttribute('data-partners-active', key);
    }

    menuButtons.forEach(button => {
        button.addEventListener('click', () => {
            activateTab(button.getAttribute('data-partners-target'));
        });
    });

    const firstSection = sections.length ? sections[0].getAttribute('data-partners-section') : null;
    const initialTab = layout.getAttribute('data-partners-active') || firstSection;
    if (initialTab) {
        activateTab(initialTab);
    }

    const periodRadios = Array.from(document.querySelectorAll('[data-report-period]'));
    const customRange = document.querySelector('[data-report-custom-range]');

    function updateCustomRangeVisibility() {
        if (!customRange) {
            return;
        }
        const current = periodRadios.find(radio => radio.checked);
        customRange.setAttribute('data-active', current && current.value === 'custom' ? 'true' : 'false');
    }

    periodRadios.forEach(radio => {
        radio.addEventListener('change', updateCustomRangeVisibility);
    });
    updateCustomRangeVisibility();

    const reportSearchForm = document.querySelector('[data-report-partner-search]');
    if (reportSearchForm) {
        const endpoint = reportSearchForm.getAttribute('data-partner-autocomplete-endpoint');
        const nameInput = reportSearchForm.querySelector('[data-partner-autocomplete-input]');
        const panel = reportSearchForm.querySelector('[data-partner-autocomplete-panel]');
        const hiddenIdInput = reportSearchForm.querySelector('[data-partner-id-input]');
        const documentInput = reportSearchForm.querySelector('[data-partner-document-input]');
        let debounceTimer = null;
        let abortController = null;

        const hidePanel = () => {
            if (!panel) {
                return;
            }
            panel.innerHTML = '';
            panel.dataset.open = 'false';
        };

        const clearSelection = () => {
            if (hiddenIdInput) {
                hiddenIdInput.value = '';
            }
            if (documentInput && documentInput.dataset.autofill === '1') {
                documentInput.value = '';
                documentInput.dataset.autofill = '0';
            }
        };

        const selectSuggestion = (option) => {
            if (!option) {
                return;
            }
            const partnerName = option.getAttribute('data-partner-name') || '';
            const partnerId = option.getAttribute('data-partner-id') || '';
            const partnerDocument = option.getAttribute('data-partner-document') || '';
            if (nameInput) {
                nameInput.value = partnerName;
            }
            if (hiddenIdInput) {
                hiddenIdInput.value = partnerId;
            }
            if (documentInput && partnerDocument !== '') {
                documentInput.value = partnerDocument;
                documentInput.dataset.autofill = '1';
            }
            hidePanel();
        };

        const renderSuggestions = (items) => {
            if (!panel) {
                return;
            }
            if (!items.length) {
                hidePanel();
                return;
            }
            panel.innerHTML = '';
            items.forEach(item => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'partner-autocomplete-option';
                option.setAttribute('data-partner-option', '1');
                option.setAttribute('data-partner-id', String(item.id || ''));
                option.setAttribute('data-partner-name', item.name || '');
                option.setAttribute('data-partner-document', item.document || '');
                const title = document.createElement('strong');
                title.textContent = item.name || 'Sem nome';
                const meta = document.createElement('small');
                const documentLabel = item.document_formatted || 'Documento não informado';
                const emailLabel = item.email || 'Sem e-mail cadastrado';
                meta.textContent = `${documentLabel} · ${emailLabel}`;
                option.appendChild(title);
                option.appendChild(meta);
                panel.appendChild(option);
            });
            panel.dataset.open = 'true';
        };

        const fetchSuggestions = (value) => {
            if (!endpoint || !panel) {
                return;
            }
            if (abortController) {
                abortController.abort();
            }
            abortController = new AbortController();
            fetch(`${endpoint}?q=${encodeURIComponent(value)}&limit=8`, {
                signal: abortController.signal,
                headers: { 'Accept': 'application/json' },
            })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error('Erro ao buscar parceiros'))))
                .then(data => {
                    renderSuggestions(Array.isArray(data.items) ? data.items : []);
                })
                .catch(error => {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    console.warn('[partners] Autocomplete falhou:', error);
                    hidePanel();
                });
        };

        if (nameInput && panel) {
            nameInput.addEventListener('input', () => {
                const value = nameInput.value.trim();
                clearSelection();
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }
                if (value.length < 2) {
                    hidePanel();
                    return;
                }
                debounceTimer = setTimeout(() => fetchSuggestions(value), 180);
            });

            nameInput.addEventListener('focus', () => {
                if (panel && panel.children.length > 0) {
                    panel.dataset.open = 'true';
                }
            });
        }

        if (panel) {
            panel.addEventListener('mousedown', event => {
                const option = event.target.closest('[data-partner-option]');
                if (!option) {
                    return;
                }
                event.preventDefault();
                selectSuggestion(option);
            });
        }

        document.addEventListener('click', event => {
            if (!panel || !reportSearchForm) {
                return;
            }
            if (!reportSearchForm.contains(event.target)) {
                hidePanel();
            }
        });
    }

    const reportForm = document.querySelector('[data-report-form]');
    if (!reportForm) {
        return;
    }

    const modeInputs = reportForm.querySelectorAll('[data-report-mode-option]');
    const reportModeHidden = document.querySelector('[data-report-mode-input]');

    function setReportMode(mode) {
        reportForm.setAttribute('data-report-mode', mode);
        if (reportModeHidden) {
            reportModeHidden.value = mode;
        }
    }

    modeInputs.forEach(input => {
        input.addEventListener('change', () => {
            if (input.checked) {
                setReportMode(input.value);
            }
        });
    });

    const moneyFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    function parseMoney(value) {
        if (!value) {
            return null;
        }
        const normalized = value.replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.');
        if (normalized === '') {
            return null;
        }
        const number = Number(normalized);
        return Number.isNaN(number) ? null : Math.round(number * 100);
    }

    function formatMoney(value) {
        if (value === null) {
            return '—';
        }
        return moneyFormatter.format(value / 100);
    }

    const rows = Array.from(reportForm.querySelectorAll('[data-report-row]'));

    function updateRow(row) {
        const costInput = row.querySelector('[data-report-input="cost"]');
        const saleInput = row.querySelector('[data-report-input="sale"]');
        const resultTarget = row.querySelector('[data-report-result]');

        if (!costInput || !saleInput) {
            return { cost: null, sale: null, result: null };
        }

        const cost = parseMoney(costInput.value);
        const sale = parseMoney(saleInput.value);
        const result = cost !== null && sale !== null ? sale - cost : null;

        if (resultTarget) {
            resultTarget.textContent = result !== null ? formatMoney(result) : '—';
        }

        return { cost, sale, result };
    }

    function updateTotals() {
        let totalCost = 0;
        let totalSale = 0;
        let totalResult = 0;

        rows.forEach(row => {
            const { cost, sale, result } = updateRow(row);
            if (cost !== null) {
                totalCost += cost;
            }
            if (sale !== null) {
                totalSale += sale;
            }
            if (result !== null) {
                totalResult += result;
            }
        });

        const totals = {
            cost: totalCost,
            sale: totalSale,
            result: totalResult,
        };

        Object.entries(totals).forEach(([key, value]) => {
            const target = reportForm.querySelector(`[data-report-total="${key}"]`);
            if (target) {
                target.textContent = formatMoney(value);
            }
        });
    }

    rows.forEach(row => {
        const inputs = row.querySelectorAll('[data-report-input]');
        inputs.forEach(input => {
            input.addEventListener('input', () => {
                updateRow(row);
                updateTotals();
            });
        });
    });

    updateTotals();
});
</script>
