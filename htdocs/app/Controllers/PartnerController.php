<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CertificateRepository;
use App\Repositories\ClientRepository;
use App\Repositories\PartnerIndicationRepository;
use App\Repositories\PartnerRepository;
use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PartnerController
{
    private const PARTNER_SEARCH_LIMIT = 50;
    private const PARTNER_LIST_LIMIT = 200;
    private const PARTNER_LIST_PAGE_SIZE = 100;
    private const PARTNER_AUDIENCE_SLUG = 'parceiros';
    private const PARTNER_AUDIENCE_NAME = 'Parceiros';

    public function index(Request $request): Response
    {
        $feedback = $_SESSION['partners_feedback'] ?? null;
        unset($_SESSION['partners_feedback']);

        $errors = $_SESSION['partners_errors'] ?? [];
        $old = $_SESSION['partners_old'] ?? null;
        unset($_SESSION['partners_errors'], $_SESSION['partners_old']);

        $documentInput = trim((string)$request->query->get('document', $old['document'] ?? ''));
        $documentDigits = digits_only($documentInput);
        $partnerIdQuery = (int)$request->query->get('partner_id', $old['partner_id'] ?? 0);
        $partnerNameQuery = trim((string)$request->query->get('partner_name', ''));

        $lookupPage = (int)$request->query->get('lookup_page', 1);
        if ($lookupPage < 1) {
            $lookupPage = 1;
        }

        $indicatorGapOptions = $this->indicatorGapOptions();
        $indicatorGapKey = trim((string)$request->query->get('indicator_gap', ''));
        if (!array_key_exists($indicatorGapKey, $indicatorGapOptions)) {
            $indicatorGapKey = '';
        }
        $indicatorGapRange = $this->indicatorGapRange($indicatorGapKey);

        $indicationMonth = (int)$request->query->get('indication_month', 0);
        if ($indicationMonth < 1 || $indicationMonth > 12) {
            $indicationMonth = 0;
        }

        $indicationYearOptions = $this->indicationYearOptions();
        $indicationYear = (int)$request->query->get('indication_year', 0);
        if (!in_array($indicationYear, $indicationYearOptions, true)) {
            $indicationYear = 0;
        }

        if ($indicationMonth === 0 || $indicationYear === 0) {
            $indicationMonth = 0;
            $indicationYear = 0;
        }

        $messages = [];
        if ($feedback !== null) {
            $messages[] = $feedback;
        }

        $partner = null;
        $partnerMatches = [];
        $partnerMatchesLimited = false;
        $partnerMatchesPerPage = $partnerNameQuery === '' ? self::PARTNER_LIST_PAGE_SIZE : self::PARTNER_SEARCH_LIMIT;
        $partnerMatchesLimit = $partnerMatchesPerPage;
        $partnerMatchesCount = 0;
        $partnerMatchesTotal = null;
        $lookupTotalPages = null;
        $monthlySeries = [];
        $lookupFiltersSubmitted = $this->lookupFiltersSubmitted($request);
        $partnerLookupRequested = $lookupFiltersSubmitted && $this->partnerLookupRequested($request);
        $searchPerformed = $lookupFiltersSubmitted
            || $documentInput !== ''
            || $partnerIdQuery > 0
            || $partnerNameQuery !== ''
            || $indicatorGapKey !== ''
            || $indicationMonth > 0;
        $clientQuery = trim((string)$request->query->get('client_query', ''));
        $clientResults = [];
        $clientSearchMode = $clientQuery === '' ? 'available' : 'query';

        $partnerRepo = new PartnerRepository();
        $audienceRepo = new AudienceListRepository();
        $certificateRepo = new CertificateRepository();
        $clientRepo = new ClientRepository();
        $partnerAudienceList = $this->syncPartnerAudienceList($audienceRepo, $partnerRepo);

        if ($partnerIdQuery > 0) {
            $partner = $partnerRepo->find($partnerIdQuery);
            if ($partner === null) {
                $messages[] = [
                    'type' => 'error',
                    'message' => 'Parceiro selecionado não foi encontrado. Ele pode ter sido removido.',
                ];
            }
        } elseif ($documentInput !== '') {
            if ($documentDigits === '' || !in_array(strlen($documentDigits), [11, 14], true)) {
                $messages[] = [
                    'type' => 'error',
                    'message' => 'Informe um CPF ou CNPJ válido para pesquisar.',
                ];
            } else {
                $partner = $partnerRepo->findByDocument($documentDigits);
                if ($partner === null && $old === null) {
                    $messages[] = [
                        'type' => 'info',
                        'message' => 'Nenhum parceiro cadastrado com este documento. Preencha o formulário para criar.',
                    ];
                }
            }
        }

        if ($partner === null && $lookupFiltersSubmitted && ($partnerNameQuery !== '' || $partnerLookupRequested)) {
            $isListingAll = $partnerNameQuery === '';
            $partnerMatchesLimit = $isListingAll ? self::PARTNER_LIST_PAGE_SIZE : self::PARTNER_SEARCH_LIMIT;
            $partnerMatchesPerPage = $partnerMatchesLimit;

            $matchesPayload = $this->resolvePartnerMatches(
                $partnerNameQuery,
                $partnerRepo,
                $clientRepo,
                $certificateRepo,
                $partnerMatchesPerPage,
                $isListingAll,
                $indicatorGapRange,
                $indicationMonth > 0 ? $indicationMonth : null,
                $indicationYear > 0 ? $indicationYear : null,
                $lookupPage
            );

            $partnerMatches = $matchesPayload['items'];
            $partnerMatchesLimited = $matchesPayload['has_more'];
            $partnerMatchesCount = count($partnerMatches);
            $rawTotalMatches = $matchesPayload['total'] ?? null;
            if ($rawTotalMatches !== null) {
                $partnerMatchesTotal = max(0, (int)$rawTotalMatches);
                $lookupTotalPages = $partnerMatchesTotal > 0
                    ? (int)ceil($partnerMatchesTotal / $partnerMatchesPerPage)
                    : 0;
            }

            if ($partnerMatches === []) {
                $messages[] = [
                    'type' => 'info',
                    'message' => $partnerNameQuery !== ''
                        ? 'Nenhum parceiro encontrado com o nome informado. Ajuste a busca e tente novamente.'
                        : 'Ainda não existem parceiros cadastrados para listar.',
                ];
            }
        }

        if ($partner !== null) {
            $monthlySeries = $certificateRepo->partnerMonthlyActivity($partner['name'] ?? '', 12, now());
        }

        $activeTab = $this->resolveActiveTab($request, $partner);

        $clientResultLimit = $clientQuery === '' ? 80 : 40;
        $clientResults = $clientRepo->searchForPartner($clientQuery, $clientResultLimit, [
            'list_when_empty' => true,
            'only_without_partner' => true,
            'document_type' => 'cpf',
        ]);

        $clientResults = $this->filterClientsByPendingPartnerNames($clientResults, $partnerRepo, $clientQuery);

        $clientTabPartnerMatches = [];
        if ($activeTab === 'client-search') {
            $clientTabPartnerMatches = $this->partnersWithPendingClients(
                $clientQuery,
                $partnerRepo,
                $clientRepo,
                $certificateRepo
            );
        }

        if ($clientQuery !== '' && $clientResults === []) {
            $messages[] = [
                'type' => 'info',
                'message' => 'Nenhum parceiro pendente corresponde ao nome informado. Ajuste a busca e tente novamente.',
            ];
        }

        $formData = [
            'partner_id' => $partner['id'] ?? ($old['partner_id'] ?? null),
            'document' => $partner['document'] ?? ($old['document'] ?? $documentDigits),
            'name' => $partner['name'] ?? ($old['name'] ?? ''),
            'email' => $partner['email'] ?? ($old['email'] ?? ''),
            'phone' => $partner['phone'] ?? ($old['phone'] ?? ''),
            'notes' => $partner['notes'] ?? ($old['notes'] ?? ''),
            'type' => $partner['type'] ?? ($old['type'] ?? 'contador'),
        ];

        // moved earlier
        $reportFilters = $this->resolveReportFilters($request, $partner);
        [$reportMonthSelect, $reportYearSelect] = $this->resolveReportMonthSelection($request, $reportFilters);
        $reportRows = [];
        $reportTotals = $this->emptyReportTotals();

        if ($partner !== null) {
            $indicationRepo = new PartnerIndicationRepository();
            $reportPayload = $indicationRepo->reportForPartner($partner, $reportFilters);
            $reportRows = $reportPayload['rows'];
            $reportTotals = $reportPayload['totals'];
            $reportFilters = $reportPayload['filters'];
        }

        $indicationFilterActive = $indicationMonth > 0 && $indicationYear > 0;
        $indicationPeriodLabel = $this->indicationPeriodLabel(
            $indicationFilterActive ? $indicationMonth : null,
            $indicationFilterActive ? $indicationYear : null
        );
        $audienceLists = $audienceRepo->all();

        return view('crm/partners/index', [
            'documentInput' => $documentInput,
            'partner' => $partner,
            'partnerMatches' => $partnerMatches,
            'partnerMatchesLimited' => $partnerMatchesLimited,
            'partnerMatchesLimit' => $partnerMatchesLimit,
            'partnerMatchesCount' => $partnerMatchesCount,
            'partnerMatchesTotal' => $partnerMatchesTotal,
            'partnerMatchesPerPage' => $partnerMatchesPerPage,
            'lookupPage' => $lookupPage,
            'lookupTotalPages' => $lookupTotalPages,
            'partnerNameQuery' => $partnerNameQuery,
            'monthlySeries' => $monthlySeries,
            'searchPerformed' => $searchPerformed,
            'messages' => $messages,
            'errors' => $errors,
            'formData' => $formData,
            'clientQuery' => $clientQuery,
            'clientResults' => $clientResults,
            'clientTabPartnerMatches' => $clientTabPartnerMatches,
            'clientSearchMode' => $clientSearchMode,
            'activeTab' => $activeTab,
            'reportFilters' => $reportFilters,
            'reportRows' => $reportRows,
            'reportTotals' => $reportTotals,
            'reportPeriodOptions' => $this->reportPeriodOptions(),
            'indicatorGapKey' => $indicatorGapKey,
            'indicatorGapOptions' => $indicatorGapOptions,
            'indicationMonth' => $indicationMonth,
            'indicationYear' => $indicationYear,
            'indicationYearOptions' => $indicationYearOptions,
            'indicationFilterActive' => $indicationFilterActive,
            'indicationPeriodLabel' => $indicationPeriodLabel,
            'lookupQueryParams' => $this->buildLookupQueryParams(
                $documentInput,
                $partnerNameQuery,
                $indicatorGapKey,
                $indicationMonth,
                $indicationYear,
                $partnerIdQuery
            ),
            'reportMonthSelect' => $reportMonthSelect,
            'reportYearSelect' => $reportYearSelect,
            'reportYearOptions' => $indicationYearOptions,
            'audienceLists' => $audienceLists,
            'partnerAudienceList' => $partnerAudienceList,
        ]);
    }

    public function store(Request $request): Response
    {
        $partnerId = (int)$request->request->get('partner_id', 0);
        $documentInput = (string)$request->request->get('document', '');
        $document = digits_only($documentInput);
        $name = trim((string)$request->request->get('name', ''));
        $email = trim((string)$request->request->get('email', ''));
        $phone = digits_only($request->request->get('phone', ''));
        $notes = trim((string)$request->request->get('notes', ''));
        $type = trim((string)$request->request->get('type', 'contador'));
        $type = $type !== '' ? $type : 'contador';

        $errors = [];
        if ($document === '' || !in_array(strlen($document), [11, 14], true)) {
            $errors['document'] = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos).';
        }
        if ($name === '') {
            $errors['name'] = 'Informe o nome do parceiro/contador.';
        }

        if ($errors !== []) {
            $_SESSION['partners_errors'] = $errors;
            $_SESSION['partners_old'] = [
                'partner_id' => $partnerId > 0 ? $partnerId : null,
                'document' => $document,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'notes' => $notes,
                'type' => $type,
            ];
            $this->flash('error', 'Não foi possível salvar. Corrija os campos destacados.');
            return new RedirectResponse($this->redirectUrl($document));
        }

        $payload = [
            'name' => $name,
            'document' => $document,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'type' => $type,
        ];

        $repo = new PartnerRepository();
        $partner = null;

        if ($partnerId > 0) {
            $partner = $repo->find($partnerId);
        }
        if ($partner === null && $document !== '') {
            $partner = $repo->findByDocument($document);
        }
        if ($partner === null && $name !== '') {
            $partner = $repo->findByNormalizedName($name);
        }

        if ($partner !== null) {
            $repo->update((int)$partner['id'], $payload);
            $partner = $repo->find((int)$partner['id']);
            $this->flash('success', 'Cadastro do parceiro atualizado.');
        } else {
            $partner = $repo->create($payload);
            $this->flash('success', 'Parceiro cadastrado com sucesso.');
        }

        $this->syncPartnerAudienceList(new AudienceListRepository(), $repo);

        return new RedirectResponse($this->redirectUrl(
            $partner['document'] ?? $document,
            ['partner_id' => $partner['id'] ?? null]
        ));
    }

    public function autocomplete(Request $request): Response
    {
        $query = trim((string)$request->query->get('q', ''));
        $limit = (int)$request->query->get('limit', 8);
        $limit = max(1, min(25, $limit));

        if ($query === '') {
            return $this->jsonResponse(['items' => [], 'has_more' => false]);
        }

        $partnerRepo = new PartnerRepository();
        $results = $partnerRepo->searchByName($query, $limit, 0);
        $items = [];

        foreach ($results['items'] ?? [] as $row) {
            $documentDigits = digits_only($row['document'] ?? '');
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'document' => $documentDigits,
                'document_formatted' => format_document($documentDigits),
                'email' => $row['email'] ?? null,
                'phone_formatted' => format_phone($row['phone'] ?? ''),
            ];
        }

        return $this->jsonResponse([
            'items' => $items,
            'has_more' => (bool)($results['has_more'] ?? false),
        ]);
    }

    public function saveReport(Request $request): Response
    {
        $partnerId = (int)$request->request->get('partner_id', 0);
        if ($partnerId <= 0) {
            $this->flash('error', 'Parceiro inválido para salvar o relatório.');
            return new RedirectResponse(url('crm/partners'));
        }

        $partnerRepo = new PartnerRepository();
        $partner = $partnerRepo->find($partnerId);
        if ($partner === null) {
            $this->flash('error', 'Parceiro não encontrado para salvar o relatório.');
            return new RedirectResponse(url('crm/partners'));
        }

        $billingMode = $this->normalizeBillingMode(
            (string)$request->request->get('billing_mode', $partner['billing_mode'] ?? 'custo')
        );

        $entriesInput = $request->request->all('entries');
        if (!is_array($entriesInput)) {
            $entriesInput = [];
        }

        $entries = [];
        foreach ($entriesInput as $certificateId => $payload) {
            $entries[] = [
                'certificate_id' => (int)$certificateId,
                'protocol' => $payload['protocol'] ?? null,
                'cost_value' => money_to_cents($payload['cost'] ?? null),
                'sale_value' => money_to_cents($payload['sale'] ?? null),
            ];
        }

        $indicationRepo = new PartnerIndicationRepository();
        $indicationRepo->saveEntries($partnerId, $billingMode, $entries);
        $partnerRepo->update($partnerId, ['billing_mode' => $billingMode]);

        $this->flash('success', 'Relatório salvo com sucesso.');

        $redirect = $this->redirectUrl(
            $partner['document'] ?? null,
            array_merge(
                ['partner_id' => $partner['id'] ?? null],
                $this->buildReportRedirectQuery($request, $billingMode)
            )
        );

        return new RedirectResponse($redirect);
    }

    public function linkClient(Request $request): Response
    {
        $clientId = (int)$request->request->get('client_id', 0);
        $stayOnClientSearch = filter_var($request->request->get('stay_on_client_search', false), FILTER_VALIDATE_BOOLEAN);
        $redirectTab = $this->sanitizeLinkTab((string)$request->request->get('redirect_tab', ''));

        if ($clientId < 1) {
            $this->flash('error', 'Cliente inválido para vincular.');
            return $this->linkClientRedirect($request, $stayOnClientSearch, $redirectTab);
        }

        $clientRepo = new ClientRepository();
        $client = $clientRepo->find($clientId);
        if ($client === null) {
            $this->flash('error', 'Cliente não encontrado na base.');
            return $this->linkClientRedirect($request, $stayOnClientSearch, $redirectTab);
        }

        $partnerRepo = new PartnerRepository();

        try {
            $partner = $partnerRepo->syncFromClient($client);
        } catch (\InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            return $this->linkClientRedirect($request, $stayOnClientSearch, $redirectTab);
        }

        $this->syncPartnerAudienceList(new AudienceListRepository(), $partnerRepo);

        $name = $partner['name'] ?? $client['name'];
        $this->flash('success', sprintf('Parceiro "%s" vinculado com sucesso.', $name));

        if ($stayOnClientSearch) {
            $redirect = $this->clientSearchRedirectUrl($request, $redirectTab);
        } else {
            $document = digits_only((string)($partner['document'] ?? $client['document'] ?? ''));
            $redirect = $this->redirectUrl(
                $document !== '' ? $document : null,
                ['partner_id' => $partner['id'] ?? null]
            );
        }

        return new RedirectResponse($redirect);
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['partners_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function redirectUrl(?string $document, array $query = []): string
    {
        $params = [];
        $queryDocument = $document !== null ? digits_only($document) : '';
        if ($queryDocument !== '') {
            $params['document'] = $queryDocument;
        }

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $params[$key] = $trimmed;
                continue;
            }

            $params[$key] = $value;
        }

        $url = url('crm/partners');
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    private function resolveActiveTab(Request $request, ?array $partner): string
    {
        $allowed = ['lookup', 'client-search', 'form', 'insights', 'report'];
        $default = $partner !== null ? 'insights' : 'lookup';
        $tab = (string)$request->query->get('tab', $default);
        if (!in_array($tab, $allowed, true)) {
            $tab = $default;
        }

        return $tab;
    }

    private function reportPeriodOptions(): array
    {
        return [
            'day' => 'Dia (hoje)',
            'month' => 'Mês atual',
            'year' => 'Ano (últimos 12 meses)',
            'custom' => 'Período personalizado',
        ];
    }

    private function emptyReportTotals(): array
    {
        return [
            'count' => 0,
            'cost' => 0,
            'sale' => 0,
            'result' => 0,
        ];
    }

    private function resolveReportFilters(Request $request, ?array $partner): array
    {
        $period = $this->sanitizeReportPeriod((string)$request->query->get('report_period', 'month'));
        $modeParam = $request->query->get('report_mode');
        $mode = $this->normalizeBillingMode($modeParam !== null ? (string)$modeParam : ($partner['billing_mode'] ?? 'custo'));

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $now = new DateTimeImmutable('now', $timezone);

        $startInput = trim((string)$request->query->get('report_start', ''));
        $endInput = trim((string)$request->query->get('report_end', ''));
        $selectedMonth = (int)$request->query->get('report_month_select', 0);
        $selectedYear = (int)$request->query->get('report_year_select', 0);
        $hasSpecificMonth = $selectedMonth >= 1 && $selectedMonth <= 12 && $selectedYear >= 2000 && $selectedYear <= 2100;

        if ($hasSpecificMonth) {
            $startDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth), $timezone))
                ->setTime(0, 0, 0);
            $endDate = $startDate->modify('last day of this month')->setTime(23, 59, 59);
            $startInput = $startDate->format('Y-m-d');
            $endInput = $endDate->format('Y-m-d');
            $period = 'custom';
        } else {
            switch ($period) {
                case 'day':
                    $startDate = $now->setTime(0, 0, 0);
                    $endDate = $now->setTime(23, 59, 59);
                    break;
                case 'month':
                    $startDate = $now->modify('first day of this month')->setTime(0, 0, 0);
                    $endDate = $now->modify('last day of this month')->setTime(23, 59, 59);
                    break;
                case 'year':
                    $endDate = $now->setTime(23, 59, 59);
                    $startDate = $now->modify('-11 months')->modify('first day of this month')->setTime(0, 0, 0);
                    break;
                default:
                    $startDate = $this->parseDateInput($startInput, $timezone, false) ?? $now->setTime(0, 0, 0);
                    $endDate = $this->parseDateInput($endInput, $timezone, true);
                    if ($endDate === null || $endDate < $startDate) {
                        $endDate = $startDate->setTime(23, 59, 59);
                    }
                    $period = 'custom';
                    break;
            }
        }

        $range = [
            'start' => $startDate->getTimestamp(),
            'end' => $endDate->getTimestamp(),
        ];

        $filters = [
            'period' => $period,
            'start_input' => $startDate->format('Y-m-d'),
            'end_input' => $endDate->format('Y-m-d'),
            'range' => $range,
            'mode' => $mode,
            'range_label' => sprintf('%s até %s', format_date($range['start']), format_date($range['end'])),
        ];

        if ($period === 'custom') {
            if ($startInput !== '') {
                $filters['start_input'] = $startInput;
            }
            if ($endInput !== '') {
                $filters['end_input'] = $endInput;
            }
        }

        if ($hasSpecificMonth) {
            $filters['selected_month'] = $selectedMonth;
            $filters['selected_year'] = $selectedYear;
        }

        return $filters;
    }

    private function resolveReportMonthSelection(Request $request, array $reportFilters): array
    {
        $rangeStart = $reportFilters['range']['start'] ?? now();
        $defaultMonth = (int)date('n', $rangeStart);
        $defaultYear = (int)date('Y', $rangeStart);

        $month = (int)$request->query->get('report_month_select', $defaultMonth);
        $year = (int)$request->query->get('report_year_select', $defaultYear);

        if ($month < 1 || $month > 12) {
            $month = $defaultMonth;
        }
        if ($year < 2000 || $year > 2100) {
            $year = $defaultYear;
        }

        return [$month, $year];
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    private function sanitizeReportPeriod(string $period): string
    {
        $normalized = strtolower(trim($period));
        return in_array($normalized, ['day', 'month', 'year', 'custom'], true) ? $normalized : 'month';
    }

    private function parseDateInput(?string $value, DateTimeZone $timezone, bool $endOfDay = false): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $timezone);
        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
    }

    private function normalizeBillingMode(mixed $value): string
    {
        $mode = strtolower(trim((string)$value));
        return in_array($mode, ['custo', 'comissao'], true) ? $mode : 'custo';
    }

    private function partnersWithPendingClients(
        string $query,
        PartnerRepository $partnerRepo,
        ClientRepository $clientRepo,
        CertificateRepository $certificateRepo
    ): array {
        $search = trim($query);
        $isListingAll = $search === '';
        $limit = $isListingAll ? self::PARTNER_LIST_LIMIT : self::PARTNER_SEARCH_LIMIT;

        $payload = $this->resolvePartnerMatches(
            $search,
            $partnerRepo,
            $clientRepo,
            $certificateRepo,
            $limit,
            $isListingAll,
            null,
            null,
            null,
            1
        );

        $items = $payload['items'] ?? [];

        return array_values(array_filter($items, static function (array $row): bool {
            $missingDocument = !isset($row['document']) || trim((string)$row['document']) === '';
            return $missingDocument && !empty($row['clients']);
        }));
    }

    private function filterClientsByPendingPartnerNames(
        array $clients,
        PartnerRepository $partnerRepo,
        string $clientQuery
    ): array {
        if ($clients === []) {
            return [];
        }

        $pendingPartners = $this->pendingPartnersNeedingUpdate($partnerRepo, $clientQuery);
        if ($pendingPartners === []) {
            return [];
        }

        $nameMap = [];
        foreach ($pendingPartners as $partner) {
            $key = $this->normalizedNameKey($partner['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $nameMap[$key] = true;
        }

        if ($nameMap === []) {
            return [];
        }

        $filtered = array_filter($clients, function (array $client) use ($nameMap): bool {
            $key = $this->normalizedNameKey($client['name'] ?? '');
            return $key !== '' && isset($nameMap[$key]);
        });

        return array_values($filtered);
    }

    private function pendingPartnersNeedingUpdate(PartnerRepository $partnerRepo, string $query): array
    {
        $needle = trim($query);
        $limit = $needle === '' ? 300 : 150;
        return $partnerRepo->listWithoutDocument($needle !== '' ? $needle : null, $limit);
    }

    private function buildReportRedirectQuery(Request $request, string $mode): array
    {
        $period = $this->sanitizeReportPeriod((string)$request->request->get('report_period', 'month'));
        $query = [
            'tab' => 'report',
            'report_period' => $period,
            'report_mode' => $mode,
        ];

        if ($period === 'custom') {
            $start = trim((string)$request->request->get('report_start', ''));
            $end = trim((string)$request->request->get('report_end', ''));
            if ($start !== '') {
                $query['report_start'] = $start;
            }
            if ($end !== '') {
                $query['report_end'] = $end;
            }
        }

        return $query;
    }

    private function buildLookupQueryParams(
        string $documentInput,
        string $partnerNameQuery,
        string $indicatorGapKey,
        int $indicationMonth,
        int $indicationYear,
        int $partnerIdQuery
    ): array {
        $params = [
            'tab' => 'lookup',
            'lookup_apply' => '1',
            'document' => $documentInput,
            'partner_name' => $partnerNameQuery,
        ];

        if ($partnerIdQuery > 0) {
            $params['partner_id'] = $partnerIdQuery;
        }
        if ($indicatorGapKey !== '') {
            $params['indicator_gap'] = $indicatorGapKey;
        }
        if ($indicationMonth > 0 && $indicationYear > 0) {
            $params['indication_month'] = $indicationMonth;
            $params['indication_year'] = $indicationYear;
        }

        return $params;
    }

    private function lookupFiltersSubmitted(Request $request): bool
    {
        $value = $request->query->get('lookup_apply', null);
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }

    private function partnerLookupRequested(Request $request): bool
    {
        $tab = (string)$request->query->get('tab', '');
        if ($tab === 'client-search') {
            return false;
        }

        return $request->query->has('partner_name')
            || $request->query->has('document')
            || $request->query->has('partner_id')
            || (trim((string)$request->query->get('indicator_gap', '')) !== '')
            || ($this->requestedIndicationPeriod($request));
    }

    private function requestedIndicationPeriod(Request $request): bool
    {
        $month = (int)$request->query->get('indication_month', 0);
        $year = (int)$request->query->get('indication_year', 0);
        return $month >= 1 && $month <= 12 && $year >= 2020;
    }

    private function resolvePartnerMatches(
        string $partnerNameQuery,
        PartnerRepository $partnerRepo,
        ClientRepository $clientRepo,
        CertificateRepository $certificateRepo,
        int $limit,
        bool $listAllMode = false,
        ?array $indicatorGapRange = null,
        ?int $indicationMonth = null,
        ?int $indicationYear = null,
        int $page = 1
    ): array {
        $perPage = max(1, $limit);
        $pageNumber = max(1, $page);
        $offset = ($pageNumber - 1) * $perPage;
        $hasAdditionalFilters = $indicatorGapRange !== null
            || (!$listAllMode && $indicationMonth !== null && $indicationYear !== null);

        if ($listAllMode && $indicationMonth !== null && $indicationYear !== null) {
            $payload = $this->partnersWithIndicationsForPeriod(
                $partnerRepo,
                $certificateRepo,
                $indicationMonth,
                $indicationYear,
                $perPage,
                $offset
            );

            if (($payload['items'] ?? []) === []) {
                $payload = $partnerRepo->listAllPaginated($perPage, $offset);
            }
        } else {
            $payload = $listAllMode
                ? $partnerRepo->listAllPaginated($perPage, $offset)
                : $partnerRepo->searchByName($partnerNameQuery, $perPage, $offset);
        }

        $rawTotal = isset($payload['total']) ? max(0, (int)$payload['total']) : null;
        $totalForResponse = $hasAdditionalFilters ? null : $rawTotal;

        $partners = $payload['items'] ?? [];

        if ($partners === []) {
            return ['items' => [], 'has_more' => false, 'total' => $totalForResponse];
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $currentDate = (new DateTimeImmutable('@' . now()))->setTimezone($timezone);
        $currentMonth = (int)$currentDate->format('n');
        $currentYear = (int)$currentDate->format('Y');

        $matches = [];
        $filterMonth = $indicationMonth;
        $filterYear = $indicationYear;
        foreach ($partners as $partner) {
            $name = trim((string)($partner['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $clients = $this->clientsMatchingPartnerName($clientRepo, $name);
            $lastIndicationAt = $certificateRepo->lastIndicationTimestampForPartner(
                $name,
                $partner['document'] ?? null
            );
            $indicator = $this->buildPartnerIndicator($lastIndicationAt);
            $indicationsTotal = $certificateRepo->partnerValidIndicationCount(
                $name,
                $partner['document'] ?? null
            );
            $indicationsCurrentMonth = $certificateRepo->partnerValidIndicationCountForMonth(
                $name,
                $currentMonth,
                $currentYear,
                $partner['document'] ?? null
            );
            $indicationsFilterPeriod = null;
            if ($filterMonth !== null && $filterYear !== null) {
                $indicationsFilterPeriod = $certificateRepo->partnerValidIndicationCountForMonth(
                    $name,
                    $filterMonth,
                    $filterYear,
                    $partner['document'] ?? null
                );
            }

            $matches[] = [
                'id' => (int)($partner['id'] ?? 0),
                'name' => $name,
                'document' => $partner['document'] ?? null,
                'document_formatted' => format_document($partner['document'] ?? ''),
                'email' => $partner['email'] ?? null,
                'phone' => $partner['phone'] ?? null,
                'phone_formatted' => format_phone($partner['phone'] ?? ''),
                'clients' => $clients,
                'indicator' => $indicator,
                'indications_total' => $indicationsTotal,
                'indications_month' => $indicationsCurrentMonth,
                'indications_period' => $indicationsFilterPeriod,
            ];
        }

        if ($indicatorGapRange !== null) {
            $matches = array_values(array_filter($matches, function (array $row) use ($indicatorGapRange): bool {
                $indicator = $row['indicator'] ?? [];
                $diffDays = $indicator['diff_days'] ?? null;
                $months = $this->indicatorGapMonthsFromDays(
                    is_int($diffDays) ? $diffDays : null
                );
                return $this->matchesIndicatorGap($months, $indicatorGapRange);
            }));
        }

        if ($indicationMonth !== null && $indicationYear !== null) {
            $matches = array_values(array_filter($matches, static function (array $row): bool {
                return (int)($row['indications_period'] ?? 0) > 0;
            }));
        }

        $primarySortKey = ($indicationMonth !== null && $indicationYear !== null)
            ? 'indications_period'
            : 'indications_month';

        usort($matches, static function (array $left, array $right) use ($primarySortKey): int {
            $leftMonth = (int)($left[$primarySortKey] ?? 0);
            $rightMonth = (int)($right[$primarySortKey] ?? 0);
            if ($leftMonth !== $rightMonth) {
                return $rightMonth <=> $leftMonth;
            }

            $leftTotal = (int)($left['indications_total'] ?? 0);
            $rightTotal = (int)($right['indications_total'] ?? 0);
            if ($leftTotal !== $rightTotal) {
                return $rightTotal <=> $leftTotal;
            }

            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return [
            'items' => $matches,
            'has_more' => (bool)($payload['has_more'] ?? false),
            'total' => $totalForResponse,
        ];
    }

    private function partnersWithIndicationsForPeriod(
        PartnerRepository $partnerRepo,
        CertificateRepository $certificateRepo,
        int $month,
        int $year,
        int $limit,
        int $offset
    ): array {
        $namesPayload = $certificateRepo->partnerNamesWithIndicationInMonth($month, $year, $limit, $offset);
        $namesWithCounts = $namesPayload['items'] ?? [];
        if ($namesWithCounts === []) {
            return ['items' => [], 'has_more' => false];
        }

        $orderedNames = array_values(array_filter(array_map(static function (array $row): string {
            return trim((string)($row['name'] ?? ''));
        }, $namesWithCounts), static function (string $name): bool {
            return $name !== '';
        }));

        if ($orderedNames === []) {
            return ['items' => [], 'has_more' => false];
        }

        $partnersMap = $partnerRepo->findByNames($orderedNames);
        if ($partnersMap === []) {
            return ['items' => [], 'has_more' => false];
        }

        $items = [];
        foreach ($orderedNames as $name) {
            $key = $this->normalizedNameKey($name);
            if ($key === '' || !isset($partnersMap[$key])) {
                continue;
            }
            $items[] = $partnersMap[$key];
        }

        return [
            'items' => $items,
            'has_more' => (bool)($namesPayload['has_more'] ?? false),
            'total' => $namesPayload['total'] ?? null,
        ];
    }

    private function clientsMatchingPartnerName(ClientRepository $clientRepo, string $partnerName): array
    {
        $partnerName = trim($partnerName);
        if ($partnerName === '') {
            return [];
        }

        $needle = $this->normalizedNameKey($partnerName);
        if ($needle === '') {
            return [];
        }

        $results = $clientRepo->searchForPartner($partnerName, 40, [
            'list_when_empty' => false,
            'only_without_partner' => true,
            'document_type' => 'cpf',
        ]);

        $filtered = array_filter($results, function (array $row) use ($needle): bool {
            if (!empty($row['has_partner'])) {
                return false;
            }

            return $this->normalizedNameKey($row['name'] ?? '') === $needle;
        });

        return array_values(array_map(static function (array $row): array {
            $partnerTag = trim((string)($row['partner_accountant_plus'] ?? $row['partner_accountant'] ?? ''));
            $partnerTag = $partnerTag !== '' ? $partnerTag : null;

            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'document' => (string)($row['document'] ?? ''),
                'document_formatted' => (string)($row['document_formatted'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'has_partner' => (bool)($row['has_partner'] ?? false),
                'partner_id' => isset($row['partner_id']) && $row['partner_id'] !== null ? (int)$row['partner_id'] : null,
                'partner_tag' => $partnerTag,
            ];
        }, $filtered));
    }

    private function buildPartnerIndicator(?int $lastIndicationAt): array
    {
        if ($lastIndicationAt === null || $lastIndicationAt <= 0) {
            return [
                'color' => '#f87171',
                'status' => 'Sem indicações válidas',
                'description' => 'Nenhuma indicação registrada (CPF do próprio parceiro foi desconsiderado).',
                'relative' => null,
                'last_at' => null,
                'diff_days' => null,
            ];
        }

        $now = now();
        $diffSeconds = max(0, $now - $lastIndicationAt);
        $diffDays = (int)floor($diffSeconds / 86400);

        if ($diffDays <= 30) {
            $color = '#22c55e';
            $status = 'Indicação recente (até 30 dias)';
        } elseif ($diffDays <= 366) {
            $color = '#facc15';
            $status = 'Sem indicações há mais de 30 dias';
        } else {
            $color = '#f87171';
            $status = 'Sem indicações há mais de 366 dias';
        }

        $relative = $this->describeRelativeDays($diffDays);

        return [
            'color' => $color,
            'status' => $status,
            'description' => sprintf('Última indicação em %s (%s)', format_date($lastIndicationAt), $relative),
            'relative' => $relative,
            'last_at' => $lastIndicationAt,
            'diff_days' => $diffDays,
        ];
    }

    private function describeRelativeDays(int $diffDays): string
    {
        if ($diffDays <= 0) {
            return 'hoje';
        }

        if ($diffDays === 1) {
            return 'há 1 dia';
        }

        return sprintf('há %d dias', $diffDays);
    }

    private function normalizedNameKey(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return mb_strtolower($value, 'UTF-8');
    }

    private function linkClientRedirect(Request $request, bool $stayOnClientSearch, string $tab): RedirectResponse
    {
        if ($stayOnClientSearch) {
            return new RedirectResponse($this->clientSearchRedirectUrl($request, $tab));
        }

        return new RedirectResponse(url('crm/partners'));
    }

    private function sanitizeLinkTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['lookup', 'client-search'];
        return in_array($tab, $allowed, true) ? $tab : 'client-search';
    }

    private function clientSearchRedirectUrl(Request $request, string $tab): string
    {
        $tab = $this->sanitizeLinkTab($tab);
        $document = digits_only((string)$request->request->get('document', ''));
        $partnerName = trim((string)$request->request->get('partner_name', ''));
        $clientQuery = trim((string)$request->request->get('client_query', ''));

        $params = array_filter([
            'document' => $document !== '' ? $document : null,
            'partner_name' => $partnerName !== '' ? $partnerName : null,
            'client_query' => $clientQuery !== '' ? $clientQuery : null,
            'tab' => $tab !== '' ? $tab : 'client-search',
        ], static fn($value) => $value !== null);

        if (!isset($params['tab'])) {
            $params['tab'] = 'client-search';
        }

        $url = url('crm/partners');
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    private function indicatorGapOptions(): array
    {
        $options = ['' => 'Qualquer período'];

        for ($month = 1; $month <= 12; $month++) {
            $label = $month === 1
                ? '1 mês sem indicar'
                : sprintf('%d meses sem indicar', $month);
            $options['gap_' . $month] = $label;
        }

        $options['gap_12_plus'] = 'Não indica há mais de 12 meses';

        return $options;
    }

    private function indicatorGapRange(string $key): ?array
    {
        if ($key === 'gap_12_plus') {
            return ['min' => 12, 'max' => null, 'exclusive_min' => true];
        }

        if (preg_match('/^gap_(\d{1,2})$/', $key, $matches)) {
            $value = (int)$matches[1];
            if ($value >= 1 && $value <= 12) {
                return ['min' => $value, 'max' => $value + 1, 'exclusive_min' => false];
            }
        }

        return null;
    }

    private function indicatorGapMonthsFromDays(?int $diffDays): ?float
    {
        if ($diffDays === null) {
            return null;
        }

        return $diffDays / 30;
    }

    private function matchesIndicatorGap(?float $months, array $range): bool
    {
        $value = $months ?? 999;

        if (isset($range['min'])) {
            $exclusive = (bool)($range['exclusive_min'] ?? false);
            if ($exclusive) {
                if (!($value > $range['min'])) {
                    return false;
                }
            } else {
                if ($value < $range['min']) {
                    return false;
                }
            }
        }

        if (isset($range['max']) && $range['max'] !== null && $value >= $range['max']) {
            return false;
        }

        return true;
    }

    private function indicationPeriodLabel(?int $month, ?int $year): ?string
    {
        if ($month === null || $year === null || $month < 1 || $month > 12 || $year < 2000) {
            return null;
        }

        $months = [
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'março',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
        ];

        $label = $months[$month] ?? null;
        if ($label === null) {
            return null;
        }

        return sprintf('%s de %d', $label, $year);
    }

    private function indicationYearOptions(): array
    {
        return range(2021, 2040);
    }

    private function syncPartnerAudienceList(
        AudienceListRepository $audienceRepo,
        PartnerRepository $partnerRepo
    ): ?array {
        try {
            $listId = $audienceRepo->upsert([
                'name' => self::PARTNER_AUDIENCE_NAME,
                'slug' => self::PARTNER_AUDIENCE_SLUG,
                'description' => 'Contatos de todos os parceiros/indicadores cadastrados.',
                'origin' => 'crm_partners',
                'purpose' => 'Comunicação com parceiros e indicadores.',
                'status' => 'active',
            ]);

            $partners = $partnerRepo->listAll();

            $existing = $audienceRepo->contacts($listId, null, 5000, 0);
            $existingByCpf = [];
            $existingUnsubByCpf = [];
            $existingContactIdsByCpf = [];
            foreach ($existing as $row) {
                $meta = $this->decodeMetadata($row['metadata'] ?? null);
                $cpf = digits_only($meta['cpf'] ?? '');
                if (strlen($cpf) !== 11) {
                    continue;
                }
                $existingByCpf[$cpf] = $row;
                $existingContactIdsByCpf[$cpf] = (int)($row['contact_id'] ?? $row['id'] ?? 0);
                $status = strtolower((string)($row['subscription_status'] ?? 'subscribed'));
                if ($status === 'unsubscribed') {
                    $existingUnsubByCpf[$cpf] = true;
                }
            }

            $contactRepo = new MarketingContactRepository();
            $seenCpf = [];
            $toAttach = [];

            foreach ($partners as $partner) {
                $name = trim((string)($partner['name'] ?? ''));
                $cpf = digits_only($partner['document'] ?? '');
                $email = $this->normalizeAudienceEmail($partner['email'] ?? null);
                $phone = trim((string)($partner['phone'] ?? ''));

                if ($name === '' || strlen($cpf) !== 11 || $email === null) {
                    continue; // exige nome, CPF válido e e-mail válido
                }
                if (isset($seenCpf[$cpf])) {
                    continue; // evita duplicar CPF
                }
                $seenCpf[$cpf] = true;

                if (isset($existingUnsubByCpf[$cpf])) {
                    continue; // respeita opt-out manual
                }

                $contact = $contactRepo->findByEmail($email);
                if ($contact === null) {
                    $contactId = $contactRepo->create([
                        'email' => $email,
                        'first_name' => $name,
                        'last_name' => null,
                        'status' => 'active',
                        'consent_status' => 'pending',
                    ]);
                    $contact = ['id' => $contactId];
                } else {
                    $contactId = (int)$contact['id'];
                    $contactRepo->update($contactId, [
                        'first_name' => $name,
                    ]);
                }

                $emailDuplicate = false;
                foreach ($existing as $row) {
                    $existingEmail = strtolower(trim((string)($row['email'] ?? '')));
                    $meta = $this->decodeMetadata($row['metadata'] ?? null);
                    $existingCpf = digits_only($meta['cpf'] ?? '');
                    if ($existingEmail === $email && $existingCpf !== $cpf) {
                        $emailDuplicate = true;
                        break;
                    }
                }

                $metadata = json_encode([
                    'cpf' => $cpf,
                    'titular_nome' => $name,
                    'telefone' => $phone,
                    'email_duplicado' => $emailDuplicate,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $toAttach[] = [
                    'contact_id' => $contact['id'],
                    'payload' => [
                        'subscription_status' => 'subscribed',
                        'source' => 'partners_auto',
                        'subscribed_at' => now(),
                        'consent_token' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'metadata' => $metadata,
                    ],
                    'cpf' => $cpf,
                ];
            }

            foreach ($toAttach as $row) {
                $audienceRepo->attachContact($listId, (int)$row['contact_id'], $row['payload']);
            }

            $allowedCpfs = array_column($toAttach, 'cpf');
            $allowedCpfs = array_values(array_filter($allowedCpfs, static fn(string $cpf): bool => $cpf !== ''));

            $toDetach = [];
            foreach ($existingByCpf as $cpf => $row) {
                if (!in_array($cpf, $allowedCpfs, true)) {
                    $contactId = $existingContactIdsByCpf[$cpf] ?? null;
                    if ($contactId !== null && $contactId > 0) {
                        $toDetach[] = (int)$contactId;
                    }
                }
            }

            if ($toDetach !== []) {
                $audienceRepo->detachContacts($listId, $toDetach);
            }

            return $audienceRepo->find($listId);
        } catch (\Throwable $exception) {
            @error_log('Falha ao sincronizar lista de parceiros: ' . $exception->getMessage());
            return null;
        }
    }

    private function normalizeAudienceEmail(mixed $email): ?string
    {
        $normalized = strtolower(trim((string)$email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

	private function decodeMetadata(?string $value): array
	{
		if ($value === null || trim($value) === '') {
			return [];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : [];
	}
}
