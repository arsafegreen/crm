<?php
/** @var array $client */
/** @var array $certificates */
/** @var array $certificateScope */
/** @var array $pipelineStages */
/** @var array $statusLabels */
/** @var array $stageHistory */
/** @var array $protocols */
/** @var array|null $protocolFeedback */
/** @var array $protocolForm */
/** @var array|null $feedback */
/** @var array $whatsappTemplates */
/** @var int $renewalWindowBeforeDays */
/** @var int $renewalWindowAfterDays */
/** @var array<int, array{type:string,created_by:int,created_at:int,expires_at:int}> $clientActionMarks */
/** @var array<string, array{label:string,color:string}> $clientMarkTypes */
/** @var int $clientMarkTtlHours */

use App\Support\WhatsappTemplatePresets;
$protocolFeedback = $protocolFeedback ?? null;
$protocolForm = $protocolForm ?? [
    'protocol_number' => '',
    'description' => '',
    'document' => '',
    'starts_at' => '',
    'expires_at' => '',
];

$protocolFeedbackTarget = (int)($protocolFeedback['target'] ?? 0);
$protocolCreateErrors = $protocolFeedbackTarget === 0 ? ($protocolFeedback['errors'] ?? []) : [];
$protocolEditErrors = $protocolFeedbackTarget !== 0 ? ($protocolFeedback['errors'] ?? []) : [];
$protocolMessageTone = $protocolFeedback['type'] ?? '';

$protocolDocumentValue = (string)($protocolForm['document'] ?? '');
$protocolDocumentDigits = digits_only($protocolDocumentValue);
$protocolDocumentFormatted = $protocolDocumentDigits !== '' ? format_document($protocolDocumentDigits) : $protocolDocumentValue;

$nowTimestamp = time();

$configuredRenewalBeforeDays = isset($renewalWindowBeforeDays) ? (int)$renewalWindowBeforeDays : 60;
$configuredRenewalAfterDays = isset($renewalWindowAfterDays) ? (int)$renewalWindowAfterDays : 60;
$renewalBeforeDays = max(0, $configuredRenewalBeforeDays);
$renewalAfterDays = max(0, $configuredRenewalAfterDays);

$documentRaw = (string)($client['document'] ?? '');
$documentDigits = digits_only($documentRaw);
$documentFormatted = $documentDigits !== '' ? format_document($documentDigits) : $documentRaw;
$isCompanyDocument = strlen($documentDigits) === 14;

$titularName = trim((string)($client['titular_name'] ?? ''));
$titularDocument = (string)($client['titular_document'] ?? '');
$titularDocumentDigits = digits_only($titularDocument);
$titularDocumentFormatted = $titularDocumentDigits !== '' ? format_document($titularDocumentDigits) : '';
$titularDisplayName = $titularName !== '' ? $titularName : ($isCompanyDocument ? '' : (trim((string)($client['name'] ?? ''))));
$titularDisplayDocument = $titularDocumentFormatted !== '' ? $titularDocumentFormatted : (!$isCompanyDocument ? $documentFormatted : '');
$cpfDigits = '';
if (!$isCompanyDocument && strlen($documentDigits) === 11) {
    $cpfDigits = $documentDigits;
} elseif (strlen($titularDocumentDigits) === 11) {
    $cpfDigits = $titularDocumentDigits;
}

$birthdateTimestamp = isset($client['titular_birthdate']) && $client['titular_birthdate'] !== null
    ? (int)$client['titular_birthdate']
    : null;
$birthdateLabel = $birthdateTimestamp !== null ? format_date($birthdateTimestamp) : null;

$partnerName = $client['partner_accountant_plus'] ?? $client['partner_accountant'] ?? '';

$statusOptions = $statusLabels;
unset($statusOptions['']);
$currentStatus = (string)($client['status'] ?? '');
$statusLabel = $statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus));
$statusBadges = [
    'active' => 'background:rgba(34,197,94,0.12);color:#4ade80;border:1px solid rgba(34,197,94,0.28);',
    'recent_expired' => 'background:rgba(249,115,22,0.12);color:#fb923c;border:1px solid rgba(249,115,22,0.28);',
    'inactive' => 'background:rgba(148,163,184,0.12);color:#cbd5f5;border:1px solid rgba(148,163,184,0.24);',
    'lost' => 'background:rgba(248,113,113,0.14);color:#f87171;border:1px solid rgba(248,113,113,0.28);',
    'prospect' => 'background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.28);',
];
$statusBadge = $statusBadges[$currentStatus] ?? 'background:rgba(148,163,184,0.12);color:#e2e8f0;border:1px solid rgba(148,163,184,0.24);';

$currentStageId = isset($client['pipeline_stage_id']) && $client['pipeline_stage_id'] !== null
    ? (int)$client['pipeline_stage_id']
    : null;
$currentStageLabel = 'Sem etapa';
if ($currentStageId !== null) {
    foreach ($pipelineStages as $stage) {
        if ((int)($stage['id'] ?? 0) === $currentStageId) {
            $label = trim((string)($stage['name'] ?? ''));
            if ($label !== '') {
                $currentStageLabel = $label;
            } else {
                $currentStageLabel = 'Etapa #' . $currentStageId;
            }
            break;
        }
    }
    if ($currentStageLabel === 'Sem etapa') {
        $currentStageLabel = 'Etapa #' . $currentStageId;
    }
}

$nextFollowUpTimestamp = isset($client['next_follow_up_at']) && $client['next_follow_up_at'] !== null
    ? (int)$client['next_follow_up_at']
    : null;
$nextFollowUpValue = '';
if ($nextFollowUpTimestamp !== null && $nextFollowUpTimestamp > 0) {
    $nextFollowUpValue = date('Y-m-d\TH:i', $nextFollowUpTimestamp);
}

$whatsappTemplateDefaults = WhatsappTemplatePresets::defaults();
$activeWhatsappTemplates = [];
foreach (WhatsappTemplatePresets::TEMPLATE_KEYS as $templateKey) {
    $activeWhatsappTemplates[$templateKey] = trim((string)($whatsappTemplates[$templateKey] ?? ($whatsappTemplateDefaults[$templateKey] ?? '')));
}

$clientMarkTtlSeconds = max(1, $clientMarkTtlHours) * 3600;
$clientMarkTtlSecondsDefault = isset($clientMarkTtlSecondsDefault) ? (int)$clientMarkTtlSecondsDefault : $clientMarkTtlSeconds;
$clientMarkTtlSecondsByType = isset($clientMarkTtlSecondsByType) && is_array($clientMarkTtlSecondsByType) ? $clientMarkTtlSecondsByType : [];
$markTtlLabels = [];
if (!empty($clientMarkTtlSecondsByType['renewal'])) {
    $markTtlLabels[] = 'renovação/resgate ' . max(1, intdiv((int)$clientMarkTtlSecondsByType['renewal'], 3600)) . 'h';
}
if (!empty($clientMarkTtlSecondsByType['birthday'])) {
    $markTtlLabels[] = 'felicitações ' . max(1, intdiv((int)$clientMarkTtlSecondsByType['birthday'], 86400)) . ' dias';
}
$markTtlLabel = $markTtlLabels !== [] ? implode(' • ', $markTtlLabels) : '';
$clientMarkCacheKey = 'crm_client_mark_cache';

$lastCertificateTimestamp = isset($client['last_certificate_expires_at']) && $client['last_certificate_expires_at'] !== null
    ? (int)$client['last_certificate_expires_at']
    : null;
$addressStreet = trim((string)($client['address_line'] ?? $client['address_street'] ?? $client['street'] ?? ''));
$addressNumber = trim((string)($client['address_number'] ?? $client['number'] ?? ''));
$addressComplement = trim((string)($client['address_complement'] ?? $client['complement'] ?? ''));
$addressNeighborhood = trim((string)($client['address_neighborhood'] ?? $client['neighborhood'] ?? ''));
$addressCity = trim((string)($client['address_city'] ?? $client['city'] ?? ''));
$addressState = strtoupper(trim((string)($client['address_state'] ?? $client['state'] ?? '')));
$addressZipDigits = digits_only((string)($client['address_zip'] ?? $client['zip'] ?? $client['cep'] ?? ''));
$addressZipFormatted = '';
if ($addressZipDigits !== '') {
    $zipDigits = substr($addressZipDigits, 0, 8);
    if (strlen($zipDigits) === 8) {
        $addressZipFormatted = substr($zipDigits, 0, 5) . '-' . substr($zipDigits, 5);
    } else {
        $addressZipFormatted = $addressZipDigits;
    }
}
$addressZipLabel = $addressZipFormatted !== '' ? 'CEP ' . $addressZipFormatted : '';
$addressSegments = [];
if ($addressStreet !== '') {
    $line = $addressStreet;
    if ($addressNumber !== '') {
        $line .= ', ' . $addressNumber;
    }
    $addressSegments[] = $line;
}
if ($addressComplement !== '') {
    $addressSegments[] = $addressComplement;
}
if ($addressNeighborhood !== '') {
    $addressSegments[] = $addressNeighborhood;
}
$cityState = trim($addressCity . ($addressState !== '' ? ' - ' . $addressState : ''));
if ($cityState !== '') {
    $addressSegments[] = $cityState;
}
if ($addressZipLabel !== '') {
    $addressSegments[] = $addressZipLabel;
}
$addressLabel = $addressSegments !== [] ? implode(' • ', $addressSegments) : null;
$emailValue = $client['email'] ?? '';
$phoneDigitsValue = digits_only($client['phone'] ?? '');
$whatsappDigitsValue = digits_only($client['whatsapp'] ?? '');
$whatsappThreadUrlTemplate = url('whatsapp') . '?thread=__THREAD__&panel=entrada&standalone=1&conversation_only=1&channel=alt_wpp';
$whatsappPrimaryGateway = 'wpp01';
$whatsappFallbackGateway = 'wpp02';
$whatsappChannel = 'alt_wpp';
$manualStartMessage = 'Olá! Podemos falar pelo WhatsApp?';
$extraPhonesValue = [];
$rawExtraPhones = $client['extra_phones'] ?? [];
if (is_string($rawExtraPhones)) {
    $decoded = json_decode($rawExtraPhones, true);
    if (is_array($decoded)) {
        $rawExtraPhones = $decoded;
    } else {
        $rawExtraPhones = [$rawExtraPhones];
    }
}
if (!is_array($rawExtraPhones)) {
    $rawExtraPhones = [];
}
foreach ($rawExtraPhones as $entry) {
    $digits = digits_only((string)$entry);
    if ($digits !== '') {
        $extraPhonesValue[] = $digits;
    }
}
$extraPhonesValue = array_values(array_unique($extraPhonesValue));
$whatsappContactPhone = $whatsappDigitsValue !== '' ? '+55' . $whatsappDigitsValue : '';
$whatsappContactName = $titularDisplayName !== ''
    ? $titularDisplayName
    : (trim((string)($client['name'] ?? 'Cliente')) ?: 'Cliente');
$whatsappCrmEndpoint = url('whatsapp/manual-thread');
$whatsappOverview = $whatsappOverview ?? ['contacts' => [], 'threads' => []];
$partnerAccountantValue = $client['partner_accountant'] ?? '';
$partnerAccountantPlusValue = $client['partner_accountant_plus'] ?? '';
$isTitularDocumentLocked = $titularDocumentDigits !== '';
$isOff = (int)($client['is_off'] ?? 0) === 1;

$birthdayGreetingDisabled = true;
$birthdayButtonTooltip = 'Disponível apenas no mês do aniversário.';
$birthdayMonthNumber = $birthdateTimestamp !== null ? (int)date('n', $birthdateTimestamp) : null;
$birthdayMonthLabel = null;
$monthNames = [
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

if ($birthdayMonthNumber !== null && isset($monthNames[$birthdayMonthNumber])) {
    $birthdayMonthLabel = $monthNames[$birthdayMonthNumber];
}

$lastCertificateLabel = $lastCertificateTimestamp !== null ? format_date($lastCertificateTimestamp) : '';

$whatsappContext = [
    'nome' => $titularDisplayName !== '' ? $titularDisplayName : (trim((string)($client['name'] ?? '')) ?: 'cliente'),
    'empresa' => trim((string)($client['name'] ?? '')),
    'documento' => $documentFormatted,
    'cpf' => !$isCompanyDocument ? $documentFormatted : $titularDocumentFormatted,
    'cnpj' => $isCompanyDocument ? $documentFormatted : '',
    'titular_documento' => $titularDocumentFormatted,
    'data_nascimento' => $birthdateLabel ?? '',
    'vencimento' => $lastCertificateLabel,
    'status' => $statusLabel,
];

$renderWhatsappTemplate = static function (string $template, array $context, string $fallback): string {
    $text = trim($template) !== '' ? $template : $fallback;
    $replacements = [];
    foreach ($context as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string)$value;
    }

    return strtr($text, $replacements);
};

$whatsappMessageTexts = [
    'birthday' => $renderWhatsappTemplate($activeWhatsappTemplates['birthday'] ?? '', $whatsappContext, $whatsappTemplateDefaults['birthday'] ?? ''),
    'renewal' => $renderWhatsappTemplate($activeWhatsappTemplates['renewal'] ?? '', $whatsappContext, $whatsappTemplateDefaults['renewal'] ?? ''),
    'rescue' => $renderWhatsappTemplate($activeWhatsappTemplates['rescue'] ?? '', $whatsappContext, $whatsappTemplateDefaults['rescue'] ?? ''),
];

$hasWhatsappNumber = $whatsappDigitsValue !== '';
$birthdayMessageText = '';
$renewalMessageText = '';
$rescueMessageText = '';

if ($hasWhatsappNumber) {
    $renewalMessageText = $whatsappMessageTexts['renewal'];
    $rescueMessageText = $whatsappMessageTexts['rescue'];

    if ($birthdateTimestamp !== null) {
        $birthdayMessageText = $whatsappMessageTexts['birthday'];
        $birthdayGreetingDisabled = ((int)date('n')) !== $birthdayMonthNumber;
        $birthdayButtonTooltip = $birthdayGreetingDisabled && $birthdayMonthLabel !== null
            ? 'Disponível somente em ' . $birthdayMonthLabel
            : 'Enviar felicitações';
    }
}

$agendaPrefillName = $titularDisplayName !== '' ? $titularDisplayName : trim((string)($client['name'] ?? ''));
$agendaPrefillDocument = $titularDisplayDocument !== '' ? $titularDisplayDocument : $documentFormatted;
$agendaPrefillParams = ['schedule' => 1];
if ($agendaPrefillName !== '') {
    $agendaPrefillParams['prefill_client_name'] = $agendaPrefillName;
}
if ($agendaPrefillDocument !== '') {
    $agendaPrefillParams['prefill_client_document'] = $agendaPrefillDocument;
}
$agendaScheduleUrl = url('agenda') . '?' . http_build_query($agendaPrefillParams, '', '&', PHP_QUERY_RFC3986);

$renewalWindowBeforeSeconds = $renewalBeforeDays * 86400;
$renewalWindowAfterSeconds = $renewalAfterDays * 86400;
$renewalButtonTooltip = sprintf(
    'Disponível de %d dias antes até %d dias após o vencimento mais recente.',
    $renewalBeforeDays,
    $renewalAfterDays
);
$canSendRenewalNow = false;
if ($lastCertificateTimestamp !== null) {
    $windowStart = $lastCertificateTimestamp - $renewalWindowBeforeSeconds;
    $windowEnd = $lastCertificateTimestamp + $renewalWindowAfterSeconds;
    $canSendRenewalNow = $nowTimestamp >= $windowStart && $nowTimestamp <= $windowEnd;
} else {
    $renewalButtonTooltip = 'Registre um vencimento para habilitar a mensagem de renovação.';
}

$certificateScopeData = $certificateScope ?? [
    'mode' => 'client',
    'client_ids' => [$client['id'] ?? 0],
    'client_count' => 1,
    'titular_document_formatted' => $titularDocumentFormatted,
    'owners' => [
        (int)($client['id'] ?? 0) => [
            'id' => (int)($client['id'] ?? 0),
            'name' => $client['name'] ?? '',
            'document' => $client['document'] ?? '',
        ],
    ],
];

$certificateOwners = $certificateScopeData['owners'] ?? [];
$certificateOwnerIds = $certificateScopeData['client_ids'] ?? [];
if (!is_array($certificateOwnerIds)) {
    $certificateOwnerIds = [];
}

$certificateOwnerCount = count(array_filter(array_map('intval', $certificateOwnerIds), static fn(int $id): bool => $id > 0));
$hasCertificateAggregation = ($certificateScopeData['mode'] ?? 'client') === 'titular' && $certificateOwnerCount > 1;
$certificateScopeDocument = $certificateScopeData['titular_document_formatted'] ?? ($titularDocumentFormatted ?? '');

$certificateOwnerLabels = [];
foreach ($certificateOwners as $owner) {
    $ownerDocument = (string)($owner['document'] ?? '');
    $ownerName = trim((string)($owner['name'] ?? ''));

    $parts = [];
    if ($ownerDocument !== '') {
        $parts[] = format_document($ownerDocument);
    }
    if ($ownerName !== '') {
        $parts[] = $ownerName;
    }

    if ($parts !== []) {
        $certificateOwnerLabels[] = implode(' • ', $parts);
    }
}

$certificateOwnerLabels = array_values(array_unique(array_filter($certificateOwnerLabels)));

$renderStatusBadge = static function (string $label, string $color, string $background, string $dotColor): string {
    $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return sprintf(
        '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:%s;color:%s;font-weight:600;font-size:0.78rem;"><span aria-hidden="true" style="display:inline-block;width:8px;height:8px;border-radius:999px;background:%s;"></span>%s</span>',
        $background,
        $color,
        $dotColor,
        $labelHtml
    );
};

$clientMarkEndpoint = url('crm/clients/' . (int)($client['id'] ?? 0) . '/marks');
?>

<header>
    <div>
        <h1><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Gerencie status, próxima ação, notas e histórico do cliente.</p>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <a href="<?= $isOff ? url('crm/clients/off') : url('crm/clients'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar para a carteira</a>
    </div>
</header>

<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;align-items:center;">
    <?php if ($isOff): ?>
        <span style="padding:8px 14px;border-radius:999px;background:rgba(148,163,184,0.16);border:1px solid rgba(148,163,184,0.35);color:var(--muted);font-size:0.85rem;">
            Este cliente está na carteira off.
        </span>
        <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/restore'); ?>">
            <?= csrf_field(); ?>
            <button type="submit" class="primary" style="padding:10px 20px;font-size:0.85rem;">Retornar para carteira principal</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/off'); ?>">
            <?= csrf_field(); ?>
            <button type="submit" class="primary" style="padding:10px 20px;font-size:0.85rem;background:linear-gradient(135deg,#f97316,#fb7185);color:#0f172a;">Mover para carteira off</button>
        </form>
    <?php endif; ?>
</div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const lookupInput = document.querySelector('[data-client-titular-input]');
        const lookupButton = document.querySelector('[data-action="client-titular-lookup"]');
        const feedbackNode = document.querySelector('[data-client-titular-feedback]');
        const containerNode = document.querySelector('[data-client-titular-container]');
        const summaryNode = containerNode ? containerNode.querySelector('[data-client-titular-summary]') : null;
        const listNode = containerNode ? containerNode.querySelector('[data-client-titular-list]') : null;

        if (!lookupInput || !lookupButton || !feedbackNode || !containerNode || !summaryNode || !listNode) {
            return;
        }

        const originalLabel = lookupButton.textContent;

        const setMessage = function (message, tone) {
            feedbackNode.textContent = message;

            if (message === '') {
                feedbackNode.style.display = 'none';
                return;
            }

            feedbackNode.style.display = 'block';

            let color = 'var(--muted)';
            if (tone === 'error') {
                color = '#f87171';
            } else if (tone === 'success') {
                color = '#4ade80';
            }

            feedbackNode.style.color = color;
        };

        const renderResults = function (items, formattedDocument) {
            if (!Array.isArray(items) || items.length === 0) {
                containerNode.style.display = 'none';
                listNode.innerHTML = '';
                return;
            }

            containerNode.style.display = 'block';
            summaryNode.textContent = `${items.length} CNPJ(s) vinculados ao CPF ${formattedDocument}`;
            listNode.innerHTML = '';

            items.forEach(function (item) {
                const li = document.createElement('li');
                li.style.padding = '10px';
                li.style.borderRadius = '10px';
                li.style.background = 'rgba(15,23,42,0.45)';
                li.style.border = '1px solid rgba(129,140,248,0.25)';

                const link = document.createElement('a');
                link.href = item.url;
                link.textContent = `${item.document_formatted || item.document} • ${item.name}`;
                link.style.color = 'var(--accent)';
                link.style.fontWeight = '600';
                link.style.textDecoration = 'none';
                li.appendChild(link);

                const status = document.createElement('span');
                status.textContent = `Status: ${item.status_label || item.status || '-'}`;
                status.style.display = 'block';
                status.style.marginTop = '4px';
                status.style.fontSize = '0.78rem';
                status.style.color = 'var(--muted)';
                li.appendChild(status);

                listNode.appendChild(li);
            });
        };

        const performLookup = async function () {
            const digits = (lookupInput.value || '').replace(/\D+/g, '');

            if (digits.length !== 11) {
                setMessage('Informe um CPF com 11 dígitos para consultar.', 'error');
                containerNode.style.display = 'none';
                listNode.innerHTML = '';
                lookupInput.focus();
                return;
            }

            setMessage('Consultando empresas vinculadas...', 'info');
            containerNode.style.display = 'none';
            listNode.innerHTML = '';
            lookupButton.disabled = true;
            lookupButton.textContent = 'Consultando...';

            try {
                const response = await fetch('<?= url('crm/clients/lookup-titular'); ?>', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': window.CSRF_TOKEN
                    },
                    body: new URLSearchParams({ titular_document: digits }).toString()
                });

                const data = await response.json();

                if (response.status >= 400 || data.error) {
                    setMessage(data.error ?? 'Não foi possível consultar o CPF agora.', 'error');
                    return;
                }

                if (!data.found || !Array.isArray(data.clients) || data.clients.length === 0) {
                    setMessage(data.message ?? 'Nenhum CNPJ vinculado encontrado.', 'info');
                    return;
                }

                setMessage(`${data.count} CNPJ(s) encontrados.`, 'success');
                renderResults(data.clients, data.titular_document_formatted ?? lookupInput.value);
            } catch (error) {
                setMessage('Não foi possível consultar o CPF agora. Tente novamente.', 'error');
            } finally {
                lookupButton.disabled = false;
                lookupButton.textContent = originalLabel;
            }
        };

        lookupButton.addEventListener('click', function () {
            performLookup();
        });

        lookupInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performLookup();
            }
        });

        lookupInput.addEventListener('input', function () {
            setMessage('', 'info');
            containerNode.style.display = 'none';
            listNode.innerHTML = '';
        });
    });
    </script>

<?php if (!empty($feedback)): ?>
    <div class="panel" style="margin-bottom:28px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : (($feedback['type'] ?? '') === 'error' ? '#f87171' : '#38bdf8'); ?>;">
        <strong style="display:block;margin-bottom:6px;">Atualização</strong>
        <p style="margin:0;color:var(--muted);">
            <?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:28px;">
    <div style="display:flex;flex-wrap:wrap;gap:28px;align-items:flex-start;justify-content:space-between;">
        <div style="min-width:220px;">
            <span style="display:inline-block;font-size:0.78rem;font-weight:600;padding:6px 12px;border-radius:999px;<?= $statusBadge; ?>">
                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <div style="margin-top:18px;display:grid;gap:10px;font-size:0.9rem;color:var(--muted);">
                <div data-client-mark-endpoint="<?= htmlspecialchars($clientMarkEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                    data-client-id="<?= (int)($client['id'] ?? 0); ?>"
                    data-client-mark-ttl="<?= (int)$clientMarkTtlSecondsDefault; ?>"
                    data-client-mark-ttl-map='<?= htmlspecialchars(json_encode($clientMarkTtlSecondsByType, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'
                    data-client-mark-cache-key="<?= htmlspecialchars($clientMarkCacheKey, ENT_QUOTES, 'UTF-8'); ?>"
                    hidden></div>
                <?php if ($isCompanyDocument): ?>
                    <div><strong style="color:var(--text);">CNPJ:</strong> <?= $documentFormatted !== '' ? htmlspecialchars($documentFormatted, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">-</span>'; ?></div>
                    <div><strong style="color:var(--text);">CPF:</strong> <?= $titularDocumentFormatted !== '' ? htmlspecialchars($titularDocumentFormatted, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">Não informado</span>'; ?></div>
                    <div>
                        <strong style="color:var(--text);">Razão social:</strong>
                        <?= !empty($client['name']) ? htmlspecialchars((string)$client['name'], ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">Não informado</span>'; ?>
                    </div>
                <?php else: ?>
                    <div><strong style="color:var(--text);">CPF:</strong> <?= $documentFormatted !== '' ? htmlspecialchars($documentFormatted, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">-</span>'; ?></div>
                <?php endif; ?>
                <div>
                    <strong style="color:var(--text);">Nome do titular:</strong>
                    <?= $titularDisplayName !== '' ? htmlspecialchars($titularDisplayName, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">Nome não informado</span>'; ?>
                </div>
                <div>
                    <strong style="color:var(--text);">Data de nascimento:</strong>
                    <?= $birthdateLabel !== null
                        ? htmlspecialchars($birthdateLabel, ENT_QUOTES, 'UTF-8')
                        : '<span style="color:rgba(148,163,184,0.6);">Não informado</span>';
                    ?>
                </div>
                <?php if (!empty($client['email'])): ?>
                    <div><strong style="color:var(--text);">E-mail:</strong> <?= htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (!empty($client['phone'])): ?>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <div><strong style="color:var(--text);">Telefone:</strong> <?= htmlspecialchars($client['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($phoneDigitsValue !== ''): ?>
                            <button type="button" class="chip" data-start-whatsapp data-phone="<?= htmlspecialchars($phoneDigitsValue, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?= htmlspecialchars($whatsappContactName, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid rgba(59,130,246,0.35);background:rgba(59,130,246,0.12);color:#60a5fa;font-weight:600;cursor:pointer;">Iniciar WhatsApp</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($extraPhonesValue !== []): ?>
                    <div>
                        <strong style="color:var(--text);">Telefones adicionais:</strong>
                        <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
                            <?php foreach ($extraPhonesValue as $extraPhone): ?>
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <span><?= htmlspecialchars(format_phone($extraPhone), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <button type="button" class="chip" data-start-whatsapp data-phone="<?= htmlspecialchars($extraPhone, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?= htmlspecialchars($whatsappContactName, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid rgba(59,130,246,0.35);background:rgba(59,130,246,0.12);color:#60a5fa;font-weight:600;cursor:pointer;">Iniciar WhatsApp</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($client['whatsapp'])): ?>
                    <?php
                        $whatsappLink = $whatsappDigitsValue !== '' ? 'https://wa.me/55' . $whatsappDigitsValue . '?text=' . rawurlencode('Olá tudo bem?') : '';
                    ?>
                    <div>
                        <strong style="color:var(--text);">WhatsApp:</strong>
                        <?php if ($whatsappLink !== ''): ?>
                            <a href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600;text-decoration:none;">
                                <?= htmlspecialchars($client['whatsapp'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($client['whatsapp'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if ($whatsappDigitsValue !== ''): ?>
                            <button type="button" class="chip" data-start-whatsapp data-phone="<?= htmlspecialchars($whatsappDigitsValue, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?= htmlspecialchars($whatsappContactName, ENT_QUOTES, 'UTF-8'); ?>" style="margin-left:8px;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid rgba(59,130,246,0.35);background:rgba(59,130,246,0.12);color:#60a5fa;font-weight:600;cursor:pointer;">Iniciar WhatsApp</button>
                        <?php endif; ?>
                        <button type="button" id="whatsapp-overview-toggle" style="margin-left:8px;padding:4px 10px;border-radius:8px;border:1px solid var(--border);background:rgba(59,130,246,0.12);color:#60a5fa;font-size:0.78rem;font-weight:600;cursor:pointer;">
                            Esconder perfil WhatsApp
                        </button>
                    </div>
                <?php endif; ?>
                <div><strong style="color:var(--text);">Parceiro:</strong> <?= $partnerName !== '' ? htmlspecialchars($partnerName, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">-</span>'; ?></div>
                <div>
                    <strong style="color:var(--text);">Endereço:</strong>
                    <?= $addressLabel !== null
                        ? htmlspecialchars($addressLabel, ENT_QUOTES, 'UTF-8')
                        : '<span style="color:rgba(148,163,184,0.6);">Não cadastrado</span>';
                    ?>
                </div>
                <?php if (!empty($client['last_avp_name'])): ?>
                    <div><strong style="color:var(--text);">Último AVP:</strong> <?= htmlspecialchars((string)$client['last_avp_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div><strong style="color:var(--text);">Etapa do funil:</strong> <?= htmlspecialchars($currentStageLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php
                    $visibleMarks = array_values(array_filter($clientActionMarks, static function (array $mark) use ($clientMarkTypes): bool {
                        $type = (string)($mark['type'] ?? '');
                        return $type !== '' && isset($clientMarkTypes[$type]);
                    }));
                ?>
                <?php if ($visibleMarks !== []): ?>
                    <div style="margin-top:16px;">
                        <strong style="display:block;font-size:0.75rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);">Marcas recentes (<?= htmlspecialchars($markTtlLabel, ENT_QUOTES, 'UTF-8'); ?>)</strong>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                            <?php foreach ($visibleMarks as $mark): ?>
                                <?php
                                    $typeKey = (string)($mark['type'] ?? '');
                                    $meta = $clientMarkTypes[$typeKey] ?? null;
                                    if ($meta === null) {
                                        continue;
                                    }
                                    $dotColor = htmlspecialchars($meta['color'], ENT_QUOTES, 'UTF-8');
                                    $dotLabel = htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8');
                                    $expiresAt = isset($mark['expires_at']) ? format_datetime((int)$mark['expires_at'], 'd/m H:i') : '';
                                ?>
                                <span style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(148,163,184,0.3);font-size:0.75rem;color:var(--text);">
                                    <span aria-hidden="true" style="width:8px;height:8px;border-radius:999px;background:<?= $dotColor; ?>;"></span>
                                    <?= $dotLabel; ?>
                                    <?php if ($expiresAt !== ''): ?>
                                        <small style="color:var(--muted);font-size:0.7rem;">até <?= htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div style="flex:1;display:grid;gap:10px;font-size:0.88rem;color:var(--muted);">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                <div>
                    <strong style="display:block;color:var(--text);">Última importação</strong>
                    <?= format_datetime($client['updated_at'] ?? null); ?>
                </div>
                <div>
                    <strong style="display:block;color:var(--text);">Mudança de status</strong>
                    <?= format_datetime($client['status_changed_at'] ?? null); ?>
                </div>
                <div>
                    <strong style="display:block;color:var(--text);">Próxima ação</strong>
                    <?= format_datetime($client['next_follow_up_at'] ?? null); ?>
                </div>
                <div>
                    <strong style="display:block;color:var(--text);">Criado em</strong>
                    <?= format_datetime($client['created_at'] ?? null); ?>
                </div>
            </div>
            <?php if ($lastCertificateLabel !== ''): ?>
                <div style="margin-top:12px;font-size:0.9rem;color:var(--muted);">
                    <strong style="color:var(--text);">Vencimento mais recente:</strong>
                    <?= htmlspecialchars($lastCertificateLabel, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <div style="margin-top:18px;padding:16px;border-radius:16px;border:1px solid rgba(129,140,248,0.28);background:rgba(11,18,32,0.78);">
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.82rem;color:var(--muted);">
                    Consultar CNPJ cadastrados pelo CPF do titular
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <input data-client-titular-input type="text" value="<?= htmlspecialchars($titularDocumentFormatted, ENT_QUOTES, 'UTF-8'); ?>" placeholder="000.000.000-00" style="flex:1;min-width:200px;padding:10px 12px;border-radius:12px;border:1px solid rgba(129,140,248,0.35);background:rgba(10,16,28,0.65);color:var(--text);">
                        <button type="button" data-action="client-titular-lookup" style="padding:10px 18px;border-radius:12px;border:1px solid rgba(129,140,248,0.45);background:rgba(129,140,248,0.2);color:#a5b4fc;font-weight:600;">Pesquisar CPF</button>
                    </div>
                </label>
                <div data-client-titular-feedback style="display:none;margin-top:6px;font-size:0.82rem;color:var(--muted);"></div>
                <div data-client-titular-container style="display:none;margin-top:12px;border:1px solid rgba(129,140,248,0.25);border-radius:14px;padding:12px;background:rgba(10,16,28,0.65);">
                    <strong data-client-titular-summary style="display:block;margin-bottom:8px;color:var(--text);"></strong>
                    <ul data-client-titular-list style="list-style:none;margin:0;padding:0;display:grid;gap:8px;"></ul>
                </div>
                <?php if ($hasWhatsappNumber): ?>
                    <div data-whatsapp-crm
                        data-endpoint="<?= htmlspecialchars($whatsappCrmEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                        data-contact-name="<?= htmlspecialchars($whatsappContactName, ENT_QUOTES, 'UTF-8'); ?>"
                        data-contact-phone="<?= htmlspecialchars($whatsappContactPhone, ENT_QUOTES, 'UTF-8'); ?>"
                        data-contact-cpf="<?= htmlspecialchars($cpfDigits, ENT_QUOTES, 'UTF-8'); ?>"
                        data-initial-queue="concluidos"
                        data-channel="<?= htmlspecialchars($whatsappChannel, ENT_QUOTES, 'UTF-8'); ?>"
                        data-gateway-instance="<?= htmlspecialchars($whatsappPrimaryGateway, ENT_QUOTES, 'UTF-8'); ?>"
                        data-gateway-fallback="<?= htmlspecialchars($whatsappFallbackGateway, ENT_QUOTES, 'UTF-8'); ?>"
                        hidden></div>
                    <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                        <?php if ($birthdateTimestamp !== null): ?>
                            <?php if ($birthdayMessageText === '' || $birthdayGreetingDisabled): ?>
                                <span title="<?= htmlspecialchars($birthdayButtonTooltip, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px dashed rgba(148,163,184,0.5);background:rgba(15,23,42,0.35);color:rgba(148,163,184,0.9);font-size:0.82rem;font-weight:600;cursor:not-allowed;">
                                    🎉 Enviar felicitações
                                </span>
                            <?php else: ?>
                                <button type="button" data-client-mark="birthday" data-whatsapp-crm-trigger data-whatsapp-kind="birthday" data-whatsapp-message="<?= htmlspecialchars($birthdayMessageText, ENT_QUOTES, 'UTF-8'); ?>" title="<?= htmlspecialchars($birthdayButtonTooltip, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px solid rgba(34,197,94,0.4);background:rgba(34,197,94,0.15);color:#4ade80;font-size:0.82rem;font-weight:600;text-decoration:none;cursor:pointer;">
                                    🎉 Enviar felicitações
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($renewalMessageText !== ''): ?>
                            <?php if ($canSendRenewalNow): ?>
                                <button type="button" data-client-mark="renewal" data-whatsapp-crm-trigger data-whatsapp-kind="renewal" data-whatsapp-message="<?= htmlspecialchars($renewalMessageText, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px solid rgba(56,189,248,0.4);background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.82rem;font-weight:600;text-decoration:none;cursor:pointer;">
                                    🔁 Mensagem de renovação
                                </button>
                            <?php else: ?>
                                <span title="<?= htmlspecialchars($renewalButtonTooltip, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px dashed rgba(56,189,248,0.35);background:rgba(15,23,42,0.35);color:rgba(56,189,248,0.8);font-size:0.82rem;font-weight:600;cursor:not-allowed;">
                                    🔁 Mensagem de renovação
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($rescueMessageText !== ''): ?>
                            <button type="button" data-client-mark="rescue" data-whatsapp-crm-trigger data-whatsapp-kind="rescue" data-whatsapp-message="<?= htmlspecialchars($rescueMessageText, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px solid rgba(249,115,22,0.35);background:rgba(248,113,113,0.12);color:#fb923c;font-size:0.82rem;font-weight:600;text-decoration:none;cursor:pointer;">
                                🤝 Resgate de cliente
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                        <a href="<?= htmlspecialchars($agendaScheduleUrl, ENT_QUOTES, 'UTF-8'); ?>"
                           data-client-mark="schedule"
                           style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:1px solid rgba(129,140,248,0.45);background:rgba(99,102,241,0.15);color:#a5b4fc;font-size:0.82rem;font-weight:600;text-decoration:none;">
                        🗓️ Agendar
                    </a>
                    <span style="font-size:0.78rem;color:var(--muted);">Abre a agenda com nome e documento preenchidos.</span>
                </div>
            </div>
        </div>
    </div>
</div>


<?php
    $overviewContacts = $whatsappOverview['contacts'] ?? [];
    $overviewThreads = $whatsappOverview['threads'] ?? [];
?>

<div id="whatsapp-overview-panel" class="panel" data-open="1" style="display:block;margin-bottom:28px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
        <h2 style="margin:0;">WhatsApp - Perfil e Histórico</h2>
        <span style="color:var(--muted);font-size:0.85rem;">Dados vindos do WhatsApp Web</span>
    </div>

    <?php if (empty($overviewContacts) && empty($overviewThreads)): ?>
        <p style="color:var(--muted);margin:0;">Nenhum histórico de WhatsApp encontrado para este cliente.</p>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
            <div style="border:1px solid var(--border);border-radius:14px;padding:14px;background:rgba(11,18,32,0.8);">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;">
                    <strong style="color:var(--text);">Dados do contato (WhatsApp Web)</strong>
                    <span style="color:var(--muted);font-size:0.8rem;"><?= count($overviewContacts); ?></span>
                </div>
                <?php if (empty($overviewContacts)): ?>
                    <p style="color:var(--muted);margin:0;font-size:0.9rem;">Nenhum contato encontrado para os telefones deste cliente.</p>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:10px;">
                        <?php foreach ($overviewContacts as $contact): ?>
                            <?php
                                $contactName = trim((string)($contact['profile_name'] ?? ($contact['name'] ?? '')));
                                $contactPhone = trim((string)($contact['phone'] ?? ''));
                                $contactUpdatedAt = $contact['updated_at'] ?? null;
                                $contactMeta = is_array($contact['metadata_decoded'] ?? null) ? $contact['metadata_decoded'] : [];
                                $profilePhoto = isset($contact['profile_photo']) && is_string($contact['profile_photo']) ? $contact['profile_photo'] : null;
                            ?>
                            <li style="padding:10px 12px;border:1px solid rgba(148,163,184,0.18);border-radius:12px;background:rgba(15,23,42,0.6);display:flex;justify-content:space-between;gap:12px;align-items:center;">
                                <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                                    <?php if ($profilePhoto): ?>
                                        <img src="<?= htmlspecialchars($profilePhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto do contato" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:1px solid rgba(148,163,184,0.2);" />
                                    <?php else: ?>
                                        <div style="width:42px;height:42px;border-radius:50%;background:rgba(148,163,184,0.2);display:flex;align-items:center;justify-content:center;color:var(--text);font-weight:700;">
                                            <?= htmlspecialchars(strtoupper(mb_substr($contactName !== '' ? $contactName : 'C', 0, 1, 'UTF-8')), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
                                        <span style="color:var(--text);font-weight:600;">
                                            <?= $contactName !== '' ? htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8') : 'Contato sem nome'; ?>
                                        </span>
                                        <span style="color:var(--muted);font-size:0.88rem;">
                                            <?= $contactPhone !== '' ? htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8') : 'Telefone não registrado'; ?>
                                        </span>
                                        <?php if (!empty($contactMeta['profile'])): ?>
                                            <span style="color:rgba(148,163,184,0.9);font-size:0.82rem;">Perfil: <?= htmlspecialchars((string)$contactMeta['profile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span style="color:var(--muted);font-size:0.78rem;white-space:nowrap;">
                                    <?= $contactUpdatedAt ? format_datetime($contactUpdatedAt) : 'Nunca'; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div style="border:1px solid var(--border);border-radius:14px;padding:14px;background:rgba(11,18,32,0.8);">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;">
                    <strong style="color:var(--text);">Conversas recentes</strong>
                    <span style="color:var(--muted);font-size:0.8rem;"><?= count($overviewThreads); ?></span>
                </div>
                <?php if (empty($overviewThreads)): ?>
                    <p style="color:var(--muted);margin:0;font-size:0.9rem;">Ainda não há mensagens registradas para este cliente.</p>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:10px;">
                        <?php foreach ($overviewThreads as $thread): ?>
                            <?php
                                $line = trim((string)($thread['external_line'] ?? ''));
                                $lastPreview = trim((string)($thread['last_message_preview'] ?? ''));
                                $lastAt = $thread['last_message_at'] ?? null;
                                $channel = trim((string)($thread['channel'] ?? ''));
                            ?>
                            <li style="padding:10px 12px;border:1px solid rgba(148,163,184,0.18);border-radius:12px;background:rgba(15,23,42,0.6);display:grid;gap:6px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                                    <span style="color:var(--text);font-weight:600;">
                                        <?= $line !== '' ? htmlspecialchars($line, ENT_QUOTES, 'UTF-8') : 'Linha não identificada'; ?>
                                    </span>
                                    <?php if ($channel !== ''): ?>
                                        <span style="color:rgba(148,163,184,0.8);font-size:0.78rem;border:1px solid rgba(148,163,184,0.25);padding:2px 8px;border-radius:999px;"><?= htmlspecialchars($channel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin:0;color:var(--muted);font-size:0.9rem;">
                                    <?= $lastPreview !== '' ? htmlspecialchars($lastPreview, ENT_QUOTES, 'UTF-8') : 'Sem prévia disponível'; ?>
                                </p>
                                <span style="color:var(--muted);font-size:0.8rem;">
                                    Última mensagem: <?= $lastAt ? format_datetime($lastAt) : 'Sem data'; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

        <div class="panel" id="protocolos" style="margin-bottom:28px;">
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px;">
                <h2 style="margin:0;">Protocolos do cliente</h2>
                <p style="margin:0;color:var(--muted);font-size:0.9rem;">Registre produtos associados e acompanhe vencimentos usando o CPF ou CNPJ do cliente.</p>
            </div>

            <?php if ($protocolFeedback !== null && ($protocolFeedback['message'] ?? '') !== ''): ?>
                <?php
                    $tone = $protocolMessageTone;
                    $border = 'rgba(56,189,248,0.35)';
                    $background = 'rgba(56,189,248,0.12)';
                    $color = 'var(--accent)';

                    if ($tone === 'success') {
                        $border = 'rgba(34,197,94,0.35)';
                        $background = 'rgba(34,197,94,0.14)';
                        $color = '#4ade80';
                    } elseif ($tone === 'error') {
                        $border = 'rgba(248,113,113,0.35)';
                        $background = 'rgba(248,113,113,0.14)';
                        $color = '#f87171';
                    }
                ?>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const endpointNode = document.querySelector('[data-client-mark-endpoint]');
                    const endpoint = endpointNode ? endpointNode.getAttribute('data-client-mark-endpoint') : '';
                    if (!endpoint) {
                        return;
                    }

                    const sendMark = function (type) {
                        if (!type) {
                            return;
                        }

                        const payload = new URLSearchParams();
                        payload.set('type', type);
                        if (window.CSRF_TOKEN) {
                            payload.set('_token', window.CSRF_TOKEN);
                        }

                        const body = payload.toString();
                        if (navigator.sendBeacon) {
                            navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
                            return;
                        }

                        const headers = new Headers({
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        });
                        if (window.CSRF_TOKEN) {
                            headers.set('X-CSRF-TOKEN', window.CSRF_TOKEN);
                        }

                        fetch(endpoint, {
                            method: 'POST',
                            body,
                            headers,
                            keepalive: true,
                        }).catch(() => {});
                    };

                    document.addEventListener('click', function (event) {
                        const target = event.target instanceof Element ? event.target.closest('[data-client-mark]') : null;
                        if (!target) {
                            return;
                        }

                        const type = target.getAttribute('data-client-mark');
                        if (!type) {
                            return;
                        }

                        sendMark(type);
                    });
                });
                </script>
                <div style="margin-bottom:18px;padding:14px 16px;border-radius:12px;border:1px solid <?= $border; ?>;background:<?= $background; ?>;color:<?= $color; ?>;font-size:0.9rem;">
                    <?= htmlspecialchars((string)$protocolFeedback['message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/protocols'); ?>" style="display:grid;gap:14px;margin-bottom:24px;">
                <?= csrf_field(); ?>
                <h3 style="margin:0;font-size:0.95rem;color:var(--muted);">Novo protocolo</h3>
                <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Número do protocolo
                        <input type="text" name="protocol_number" value="<?= htmlspecialchars((string)($protocolForm['protocol_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="PROTO-123" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);text-transform:uppercase;">
                        <?php if (isset($protocolCreateErrors['protocol_number'])): ?>
                            <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$protocolCreateErrors['protocol_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Descrição
                        <input type="text" name="description" value="<?= htmlspecialchars((string)($protocolForm['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Certificado A1" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Data de início
                        <input type="date" name="starts_at" value="<?= htmlspecialchars((string)($protocolForm['starts_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                        <?php if (isset($protocolCreateErrors['starts_at'])): ?>
                            <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$protocolCreateErrors['starts_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        Data de vencimento
                        <input type="date" name="expires_at" value="<?= htmlspecialchars((string)($protocolForm['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                        <?php if (isset($protocolCreateErrors['expires_at'])): ?>
                            <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$protocolCreateErrors['expires_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                        CPF/CNPJ do cliente
                        <input type="text" name="document" value="<?= htmlspecialchars($protocolDocumentFormatted, ENT_QUOTES, 'UTF-8'); ?>" placeholder="00.000.000/0000-00" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                        <?php if (isset($protocolCreateErrors['document'])): ?>
                            <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$protocolCreateErrors['document'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <span style="color:rgba(148,163,184,0.65);font-size:0.75rem;">Use o mesmo documento deste cliente para vincular na busca.</span>
                        <?php endif; ?>
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="primary" style="padding:10px 22px;">Adicionar protocolo</button>
                </div>
            </form>

            <?php if ($protocols === []): ?>
                <div style="padding:16px;border-radius:12px;border:1px dashed rgba(148,163,184,0.35);color:var(--muted);font-size:0.88rem;">
                    Nenhum protocolo cadastrado até o momento.
                </div>
            <?php else: ?>
                <div style="display:grid;gap:16px;">
                    <?php foreach ($protocols as $protocol): ?>
                        <?php
                            $protocolId = (int)($protocol['id'] ?? 0);
                            $protocolNumberRow = (string)($protocol['protocol_number'] ?? '');
                            $protocolDescriptionRow = trim((string)($protocol['description'] ?? ''));
                            $protocolDocumentRow = (string)($protocol['document'] ?? '');
                            $protocolDocumentDigitsRow = digits_only($protocolDocumentRow);
                            $protocolDocumentLabel = $protocolDocumentDigitsRow !== '' ? format_document($protocolDocumentDigitsRow) : ($protocolDocumentRow !== '' ? $protocolDocumentRow : '-');
                            $startsAtTs = isset($protocol['starts_at']) && $protocol['starts_at'] !== null ? (int)$protocol['starts_at'] : null;
                            $expiresAtTs = isset($protocol['expires_at']) && $protocol['expires_at'] !== null ? (int)$protocol['expires_at'] : null;

                            $statusLabel = 'Sem vencimento definido';
                            $statusStyles = 'background:rgba(148,163,184,0.12);color:#cbd5f5;border:1px solid rgba(148,163,184,0.28);';
                            $statusDot = 'rgba(148,163,184,0.65)';

                            if ($expiresAtTs !== null) {
                                if ($expiresAtTs < $nowTimestamp) {
                                    $statusLabel = 'Vencido em ' . format_date($expiresAtTs);
                                    $statusStyles = 'background:rgba(248,113,113,0.14);color:#f87171;border:1px solid rgba(248,113,113,0.28);';
                                    $statusDot = '#f87171';
                                } elseif ($expiresAtTs <= $nowTimestamp + (30 * 86400)) {
                                    $statusLabel = 'Vence em ' . format_date($expiresAtTs);
                                    $statusStyles = 'background:rgba(249,115,22,0.14);color:#fb923c;border:1px solid rgba(249,115,22,0.28);';
                                    $statusDot = '#fb923c';
                                } else {
                                    $statusLabel = 'Ativo até ' . format_date($expiresAtTs);
                                    $statusStyles = 'background:rgba(34,197,94,0.14);color:#4ade80;border:1px solid rgba(34,197,94,0.28);';
                                    $statusDot = '#4ade80';
                                }
                            }

                            $editForm = [
                                'protocol_number' => $protocolNumberRow,
                                'description' => $protocolDescriptionRow,
                                'document' => $protocolDocumentLabel,
                                'starts_at' => $startsAtTs !== null ? date('Y-m-d', $startsAtTs) : '',
                                'expires_at' => $expiresAtTs !== null ? date('Y-m-d', $expiresAtTs) : '',
                            ];

                            if ($protocolFeedbackTarget === $protocolId && isset($protocolFeedback['old']) && is_array($protocolFeedback['old'])) {
                                $editForm = array_merge($editForm, array_map(static fn($value) => is_scalar($value) ? (string)$value : '', $protocolFeedback['old']));
                            }

                            $editErrors = $protocolFeedbackTarget === $protocolId ? $protocolEditErrors : [];
                        ?>
                        <div style="padding:16px;border-radius:14px;border:1px solid rgba(129,140,248,0.28);background:rgba(9,14,26,0.72);display:grid;gap:16px;">
                            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <strong style="font-size:1rem;color:var(--text);"><?= htmlspecialchars($protocolNumberRow, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($protocolDescriptionRow !== ''): ?>
                                        <span style="color:var(--muted);font-size:0.88rem;"><?= htmlspecialchars($protocolDescriptionRow, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <span style="font-size:0.78rem;color:var(--muted);">Documento: <?= $protocolDocumentLabel !== '-' ? htmlspecialchars($protocolDocumentLabel, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">—</span>'; ?></span>
                                </div>
                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                                    <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:0.78rem;font-weight:600;<?= $statusStyles; ?>">
                                        <span aria-hidden="true" style="display:inline-block;width:8px;height:8px;border-radius:999px;background:<?= $statusDot; ?>;"></span>
                                        <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/protocols/' . $protocolId . '/delete'); ?>" onsubmit="return confirm('Remover este protocolo?');">
                                        <?= csrf_field(); ?>
                                        <button type="submit" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(248,113,113,0.35);background:rgba(248,113,113,0.18);color:#f87171;font-weight:600;">Remover</button>
                                    </form>
                                </div>
                            </div>
                            <div style="display:grid;gap:10px;font-size:0.85rem;color:var(--muted);grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
                                <div>
                                    <strong style="display:block;color:var(--text);">Início</strong>
                                    <?= $startsAtTs !== null ? htmlspecialchars(format_date($startsAtTs), ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.65);">—</span>'; ?>
                                </div>
                                <div>
                                    <strong style="display:block;color:var(--text);">Vencimento</strong>
                                    <?= $expiresAtTs !== null ? htmlspecialchars(format_date($expiresAtTs), ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.65);">—</span>'; ?>
                                </div>
                            </div>
                            <details<?= $protocolFeedbackTarget === $protocolId ? ' open' : ''; ?> style="border-radius:12px;border:1px solid rgba(129,140,248,0.2);background:rgba(15,23,42,0.65);padding:14px;">
                                <summary style="cursor:pointer;color:var(--accent);font-weight:600;">Editar protocolo</summary>
                                <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/protocols/' . $protocolId . '/update'); ?>" style="display:grid;gap:12px;margin-top:12px;">
                                    <?= csrf_field(); ?>
                                    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                                            Número do protocolo
                                            <input type="text" name="protocol_number" value="<?= htmlspecialchars((string)$editForm['protocol_number'], ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);text-transform:uppercase;">
                                            <?php if (isset($editErrors['protocol_number'])): ?>
                                                <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$editErrors['protocol_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                                            Descrição
                                            <input type="text" name="description" value="<?= htmlspecialchars((string)$editForm['description'], ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                                            Data de início
                                            <input type="date" name="starts_at" value="<?= htmlspecialchars((string)$editForm['starts_at'], ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                                            <?php if (isset($editErrors['starts_at'])): ?>
                                                <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$editErrors['starts_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                                            Data de vencimento
                                            <input type="date" name="expires_at" value="<?= htmlspecialchars((string)$editForm['expires_at'], ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                                            <?php if (isset($editErrors['expires_at'])): ?>
                                                <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$editErrors['expires_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--muted);">
                                            CPF/CNPJ do cliente
                                            <input type="text" name="document" value="<?= htmlspecialchars((string)$editForm['document'], ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                                            <?php if (isset($editErrors['document'])): ?>
                                                <span style="color:#f87171;font-size:0.75rem;"><?= htmlspecialchars((string)$editErrors['document'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                                        <button type="submit" class="primary" style="padding:10px 20px;">Salvar alterações</button>
                                    </div>
                                </form>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

<div class="panel" style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;">
        <h2 style="margin:0;">Atualizar informações</h2>
        <button type="button" data-client-edit-toggle aria-expanded="false" title="Editar dados do cliente" style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:14px;border:1px solid rgba(56,189,248,0.5);background:rgba(56,189,248,0.12);color:var(--accent);font-size:1.1rem;cursor:pointer;transition:background 0.2s ease,border-color 0.2s ease;">
            ✎
        </button>
    </div>
    <div data-client-edit-form data-open-default="<?= (($feedback['type'] ?? '') === 'error') ? 'true' : 'false'; ?>" style="display:none;">
    <form method="post" action="<?= url('crm/clients/' . (int)$client['id'] . '/update'); ?>" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <h3 style="margin:0;font-size:1rem;color:var(--muted);">Dados cadastrais</h3>
        <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Nome do cliente
                <input type="text" name="name" value="<?= htmlspecialchars($client['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);text-transform:uppercase;">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                E-mail
                <input type="email" name="email" value="<?= htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="contato@cliente.com" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Telefone
                <input type="text" name="phone" value="<?= htmlspecialchars($phoneDigitsValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="82999998888" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                WhatsApp
                <input type="text" name="whatsapp" value="<?= htmlspecialchars($whatsappDigitsValue !== '' ? $whatsappDigitsValue : $phoneDigitsValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="82999998888" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Telefones adicionais
                <div data-extra-phones-root style="display:grid;gap:10px;">
                    <div data-extra-phones-list style="display:grid;gap:10px;">
                        <?php foreach ($extraPhonesValue as $extraPhone): ?>
                            <div data-extra-phone-row style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <input type="text" name="extra_phones[]" value="<?= htmlspecialchars($extraPhone, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DDD + número" style="flex:1;min-width:180px;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                                <button type="button" data-remove-extra-phone style="padding:10px 12px;border-radius:10px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fca5a5;font-weight:600;cursor:pointer;">Remover</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="button" data-add-extra-phone style="padding:10px 14px;border-radius:10px;border:1px solid rgba(56,189,248,0.45);background:rgba(56,189,248,0.12);color:var(--accent);font-weight:600;cursor:pointer;">Adicionar telefone</button>
                        <span data-extra-phone-empty style="<?= $extraPhonesValue === [] ? '' : 'display:none;'; ?>color:var(--muted);font-size:0.85rem;">Nenhum telefone adicional.</span>
                    </div>
                    <template data-extra-phone-template>
                        <div data-extra-phone-row style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="text" name="extra_phones[]" placeholder="DDD + número" style="flex:1;min-width:180px;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                            <button type="button" data-remove-extra-phone style="padding:10px 12px;border-radius:10px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fca5a5;font-weight:600;cursor:pointer;">Remover</button>
                        </div>
                    </template>
                    <small style="color:var(--muted);font-size:0.82rem;">Apenas números, mínimo 10 dígitos.</small>
                </div>
            </label>
        </div>
        <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Nome do titular
                <input type="text" name="titular_name" value="<?= htmlspecialchars($titularName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome completo" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                CPF do titular
                <input type="text" name="titular_document" value="<?= htmlspecialchars($titularDocumentFormatted, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Somente números" <?= $isTitularDocumentLocked ? 'readonly' : '' ?> style="padding:12px;border-radius:12px;border:1px solid <?= $isTitularDocumentLocked ? 'rgba(148,163,184,0.35)' : 'var(--border)'; ?>;background:rgba(15,23,42,0.6);color:var(--text);<?= $isTitularDocumentLocked ? 'cursor:not-allowed;' : ''; ?>">
                <?php if ($isTitularDocumentLocked): ?>
                    <small style="color:rgba(148,163,184,0.75);font-size:0.78rem;">CPF vinculado automaticamente pelos certificados.</small>
                <?php else: ?>
                    <small style="color:rgba(148,163,184,0.75);font-size:0.78rem;">Informe o CPF para vincular outros CNPJ ao mesmo titular.</small>
                <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Parceiro / contador
                <input type="text" name="partner_accountant" value="<?= htmlspecialchars($partnerAccountantValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome do parceiro" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Parceiro adicional
                <input type="text" name="partner_accountant_plus" value="<?= htmlspecialchars($partnerAccountantPlusValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Parceiro complementar" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            </label>
        </div>
        <h3 style="margin:8px 0 0;font-size:1rem;color:var(--muted);">Status e acompanhamento</h3>
        <div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Status do cliente
                <select name="status" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?>" <?= $currentStatus === (string)$value ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Etapa no funil
                <select name="pipeline_stage_id" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="" <?= $currentStageId === null ? 'selected' : ''; ?>>Sem etapa</option>
                    <?php foreach ($pipelineStages as $stage): ?>
                        <option value="<?= (int)$stage['id']; ?>" <?= $currentStageId === (int)$stage['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($stage['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Próxima ação (data e hora)
                <input type="datetime-local" name="next_follow_up_at" value="<?= htmlspecialchars($nextFollowUpValue, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <div style="display:flex;align-items:center;gap:8px;margin-top:6px;font-size:0.8rem;color:var(--muted);">
                    <input type="checkbox" name="clear_follow_up" value="1" style="width:16px;height:16px;"> Remover próxima ação
                </div>
            </label>
        </div>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Notas gerais
            <textarea name="notes" rows="4" style="padding:12px;border-radius:14px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);resize:vertical;min-height:120px;"><?= htmlspecialchars((string)($client['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
            Observação da mudança de etapa (opcional)
            <textarea name="stage_note" rows="3" style="padding:12px;border-radius:14px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);resize:vertical;min-height:80px;"></textarea>
        </label>
        <div style="display:flex;justify-content:flex-end;">
            <button class="primary" type="submit">Salvar alterações</button>
        </div>
    </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var editToggle = document.querySelector('[data-client-edit-toggle]');
    var editWrapper = document.querySelector('[data-client-edit-form]');

    if (!editToggle || !editWrapper) {
        return;
    }

    var firstField = editWrapper.querySelector('input, select, textarea');
    var defaultOpen = editWrapper.getAttribute('data-open-default') === 'true';
    var isOpen = defaultOpen;

    var renderState = function () {
        if (isOpen) {
            editWrapper.style.display = 'block';
            editToggle.setAttribute('aria-expanded', 'true');
        } else {
            editWrapper.style.display = 'none';
            editToggle.setAttribute('aria-expanded', 'false');
        }
    };

    editToggle.addEventListener('click', function () {
        isOpen = !isOpen;
        renderState();
        if (isOpen && firstField) {
            setTimeout(function () {
                firstField.focus();
            }, 0);
        }
    });

    renderState();

    if (isOpen && firstField) {
        setTimeout(function () {
            firstField.focus();
        }, 0);
    }
});
</script>

<script>
// Verifica gateway alternativo a cada 1h e alerta se estiver indisponível.
document.addEventListener('DOMContentLoaded', function () {
    const gatewayStatusUrl = <?= json_encode(url('whatsapp/alt/gateway-status'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    let lastWarnedAt = 0;
    const warnCooldownMs = 10 * 60 * 1000; // evita spam de alertas

    const checkGateway = async function () {
        try {
            const response = await fetch(gatewayStatusUrl, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Gateway indisponível');
            }
            const data = await response.json().catch(() => ({}));
            const gateway = (data && data.gateway) ? data.gateway : {};
            const ok = Boolean(gateway.ready || gateway.ok || gateway.hasClient);
            if (!ok) {
                maybeWarn();
            }
        } catch (error) {
            maybeWarn();
        }
    };

    const maybeWarn = function () {
        const now = Date.now();
        if (now - lastWarnedAt < warnCooldownMs) {
            return;
        }
        lastWarnedAt = now;
        alert('Gateway alternativo de WhatsApp indisponível. Verifique a conexão/QR e reinicie o gateway se necessário.');
    };

    // Checa já na entrada e depois a cada 1 hora.
    checkGateway();
    setInterval(checkGateway, 60 * 60 * 1000);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const setupExtraPhones = function (root) {
        if (!root) {
            return;
        }

        const list = root.querySelector('[data-extra-phones-list]');
        const template = root.querySelector('[data-extra-phone-template]');
        const emptyState = root.querySelector('[data-extra-phone-empty]');
        const addButton = root.querySelector('[data-add-extra-phone]');

        const updateEmpty = function () {
            if (!emptyState) {
                return;
            }
            const hasRows = list && list.querySelector('[data-extra-phone-row]');
            emptyState.style.display = hasRows ? 'none' : '';
        };

        const addRow = function (value) {
            if (!list || !template) {
                return;
            }
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-extra-phone-row]');
            const input = fragment.querySelector('input[name="extra_phones[]"]');
            if (input && typeof value === 'string' && value !== '') {
                input.value = value;
            }
            list.appendChild(fragment);
            updateEmpty();
            if (row) {
                const focusInput = row.querySelector('input[name="extra_phones[]"]');
                if (focusInput) {
                    focusInput.focus();
                }
            }
        };

        if (addButton) {
            addButton.addEventListener('click', function () {
                addRow('');
            });
        }

        root.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target.closest('[data-remove-extra-phone]') : null;
            if (!target) {
                return;
            }
            event.preventDefault();
            const row = target.closest('[data-extra-phone-row]');
            if (row && list) {
                row.remove();
                updateEmpty();
            }
        });

        updateEmpty();
    };

    document.querySelectorAll('[data-extra-phones-root]').forEach(function (root) {
        setupExtraPhones(root);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const manualEndpoint = <?= json_encode($whatsappCrmEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const threadUrlTemplate = <?= json_encode($whatsappThreadUrlTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const manualMessage = <?= json_encode($manualStartMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    const startButtons = document.querySelectorAll('[data-start-whatsapp]');
    startButtons.forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            if (!manualEndpoint || !threadUrlTemplate) {
                return;
            }
            const phone = button.getAttribute('data-phone') || '';
            const name = button.getAttribute('data-name') || 'Cliente';
            if (!phone) {
                return;
            }

            const previousLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Criando conversa...';

            try {
                const payload = new URLSearchParams();
                payload.set('contact_name', name);
                payload.set('contact_phone', phone);
                payload.set('message', manualMessage || 'Olá!');
                if (window.CSRF_TOKEN) {
                    payload.set('_token', window.CSRF_TOKEN);
                }

                const response = await fetch(manualEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(window.CSRF_TOKEN ? { 'X-CSRF-TOKEN': window.CSRF_TOKEN } : {}),
                    },
                    body: payload.toString(),
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Não foi possível iniciar a conversa.');
                }

                const data = await response.json().catch(() => ({}));
                if (!data.thread_id) {
                    throw new Error('Conversa criada sem identificador.');
                }

                const targetUrl = threadUrlTemplate.replace('__THREAD__', encodeURIComponent(data.thread_id));
                window.open(targetUrl, '_blank');
            } catch (error) {
                alert(error.message || 'Falha ao iniciar conversa.');
            } finally {
                button.disabled = false;
                button.textContent = previousLabel;
            }
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const whatsappConfig = document.querySelector('[data-whatsapp-crm]');
    if (!whatsappConfig) {
        return;
    }

    const endpoint = whatsappConfig.getAttribute('data-endpoint') || '';
    const contactName = whatsappConfig.getAttribute('data-contact-name') || 'Cliente';
    const contactPhone = whatsappConfig.getAttribute('data-contact-phone') || '';
    const contactCpf = whatsappConfig.getAttribute('data-contact-cpf') || '';
    const initialQueue = whatsappConfig.getAttribute('data-initial-queue') || '';
    const gatewayInstance = whatsappConfig.getAttribute('data-gateway-instance') || '';
    const gatewayFallback = whatsappConfig.getAttribute('data-gateway-fallback') || '';
    const channel = whatsappConfig.getAttribute('data-channel') || '';

    if (contactPhone === '') {
        return;
    }

    let isSending = false;
    const lastSentByPhone = new Map();

    const buildWhatsappFallback = function (message) {
        const digits = (contactPhone || '').replace(/\D+/g, '');
        if (!digits) {
            return null;
        }
        const phoneParam = digits.startsWith('55') ? digits : '55' + digits;
        const params = new URLSearchParams();
        params.set('phone', phoneParam);
        params.set('text', message || '');
        params.set('type', 'phone_number');
        params.set('app_absent', '0');
        return 'https://api.whatsapp.com/send/?' + params.toString();
    };

    const sendWhatsappThread = async function (message, kind, preferredGateway) {
        if (!endpoint) {
            throw new Error('Canal do CRM indisponível.');
        }

        const payload = new URLSearchParams();
        payload.set('contact_name', contactName);
        payload.set('contact_phone', contactPhone);
        payload.set('message', message);
        if (initialQueue) {
            payload.set('initial_queue', initialQueue);
        }
        if (kind) {
            payload.set('campaign_kind', kind);
        }
        if (kind === 'birthday' && contactCpf) {
            payload.set('campaign_token', contactCpf);
        }
        if (channel) {
            payload.set('channel', channel);
        }
        const chosenGateway = preferredGateway || gatewayInstance;
        if (chosenGateway) {
            payload.set('gateway_instance', chosenGateway);
        }
        if (window.CSRF_TOKEN) {
            payload.set('_token', window.CSRF_TOKEN);
        }

        const headers = new Headers({
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        });
        if (window.CSRF_TOKEN) {
            headers.set('X-CSRF-TOKEN', window.CSRF_TOKEN);
        }

        const response = await fetch(endpoint, {
            method: 'POST',
            body: payload.toString(),
            headers,
            credentials: 'same-origin',
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || (data && data.error)) {
            throw new Error((data && data.error) || 'Não foi possível iniciar a conversa pelo CRM.');
        }

        return data;
    };

    document.addEventListener('click', async function (event) {
        const trigger = event.target instanceof Element ? event.target.closest('[data-whatsapp-crm-trigger]') : null;
        if (!trigger) {
            return;
        }

        const message = (trigger.getAttribute('data-whatsapp-message') || '').trim();
        if (!message) {
            return;
        }

        const now = Date.now();
        const phoneKey = (contactPhone || '').replace(/\D+/g, '') || 'default';
        const lastSentAt = Number(lastSentByPhone.get(phoneKey) || 0);
        if (now - lastSentAt < 600000) {
            const remainingMs = 600000 - (now - lastSentAt);
            const remainingMinutes = Math.max(1, Math.ceil(remainingMs / 60000));
            alert(`Já foi enviada uma mensagem para este número há menos de 10 minutos. Tente novamente em ${remainingMinutes} min.`);
            return;
        }

        event.preventDefault();
        if (isSending) {
            return;
        }

        isSending = true;
        trigger.setAttribute('aria-busy', 'true');
        lastSentByPhone.set(phoneKey, now);
        if (typeof trigger.disabled !== 'undefined') {
            trigger.disabled = true;
        }

        try {
            let lastError = null;
            try {
                await sendWhatsappThread(message, trigger.getAttribute('data-whatsapp-kind') || '', gatewayInstance);
            } catch (error) {
                lastError = error;
                if (gatewayFallback && gatewayFallback !== gatewayInstance) {
                    await sendWhatsappThread(message, trigger.getAttribute('data-whatsapp-kind') || '', gatewayFallback);
                    lastError = null;
                }
            }

            if (lastError) {
                throw lastError;
            }
        } catch (error) {
            const fallbackUrl = buildWhatsappFallback(message);
            if (fallbackUrl) {
                window.open(fallbackUrl, '_blank');
            } else {
                alert(error.message || 'Falha ao abrir a conversa.');
            }
        } finally {
            isSending = false;
            trigger.removeAttribute('aria-busy');
            if (typeof trigger.disabled !== 'undefined') {
                trigger.disabled = false;
            }
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('whatsapp-overview-toggle');
    const panel = document.getElementById('whatsapp-overview-panel');
    if (!toggle || !panel) {
        return;
    }

    toggle.addEventListener('click', function () {
        const isOpening = panel.getAttribute('data-open') !== '1';
        panel.style.display = isOpening ? 'block' : 'none';
        panel.setAttribute('data-open', isOpening ? '1' : '0');
        toggle.textContent = isOpening ? 'Esconder perfil WhatsApp' : 'Ver perfil WhatsApp';
    });
});
</script>

<div class="panel" style="margin-bottom:28px;">
    <h2 style="margin-top:0;">Certificados vinculados</h2>
    <?php if (empty($certificates)): ?>
        <p style="color:var(--muted);margin:0;">Nenhum certificado encontrado para este cliente.</p>
    <?php else: ?>
        <?php if ($hasCertificateAggregation && $certificateScopeDocument !== ''): ?>
            <div style="margin-bottom:16px;padding:12px 14px;border:1px solid rgba(148,163,184,0.25);border-radius:14px;background:rgba(15,23,42,0.6);color:var(--muted);font-size:0.85rem;">
                Mostrando certificados de <?= $certificateOwnerCount; ?> empresas vinculadas ao CPF <?= htmlspecialchars($certificateScopeDocument, ENT_QUOTES, 'UTF-8'); ?>.
                <?php if ($certificateOwnerLabels !== []): ?>
                    <span style="display:block;margin-top:6px;font-size:0.78rem;color:rgba(148,163,184,0.75);">
                        Empresas incluídas: <?= htmlspecialchars(implode('; ', $certificateOwnerLabels), ENT_QUOTES, 'UTF-8'); ?>.
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:820px;">
                <thead>
                    <tr style="background:rgba(15,23,42,0.72);color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.06em;">
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Cliente</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Protocolo</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Produto</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Início</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Final</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Situação</th>
                        <th style="padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);">Parceiro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($certificates as $certificate): ?>
                        <?php
                            $certificatePartner = $certificate['partner_accountant_plus'] ?? $certificate['partner_accountant'] ?? '';
                            $certificateClientId = (int)($certificate['client_id'] ?? 0);
                            $certificateDocument = (string)($certificate['client_document'] ?? ($certificateOwners[$certificateClientId]['document'] ?? ''));
                            $certificateDocumentFormatted = $certificateDocument !== '' ? format_document($certificateDocument) : '';
                            $certificateClientName = trim((string)($certificate['client_name'] ?? ($certificateOwners[$certificateClientId]['name'] ?? '')));
                            $isCurrentClientCertificate = $certificateClientId === (int)$client['id'];

                            $endAtTimestamp = isset($certificate['end_at']) && $certificate['end_at'] !== null ? (int)$certificate['end_at'] : null;
                            $isRevoked = (int)($certificate['is_revoked'] ?? 0) === 1;
                            $statusBadgeHtml = '';

                            if ($isRevoked) {
                                $statusBadgeHtml = $renderStatusBadge('Revogado', '#f87171', 'rgba(248,113,113,0.18)', '#f87171');
                            } elseif ($endAtTimestamp !== null && $endAtTimestamp >= $nowTimestamp) {
                                $statusBadgeHtml = $renderStatusBadge('Ativo', '#4ade80', 'rgba(34,197,94,0.18)', '#4ade80');
                            } elseif ($endAtTimestamp !== null && $endAtTimestamp < $nowTimestamp) {
                                $statusBadgeHtml = $renderStatusBadge('Vencido', '#e2e8f0', 'rgba(148,163,184,0.18)', '#f8fafc');
                            } else {
                                $fallbackStatus = trim((string)($certificate['status_raw'] ?? $certificate['status'] ?? ''));
                                $statusBadgeHtml = htmlspecialchars($fallbackStatus !== '' ? $fallbackStatus : '-', ENT_QUOTES, 'UTF-8');
                            }
                        ?>
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.12);">
                            <td style="padding:14px 16px;color:var(--muted);">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <span style="font-weight:600;color:var(--text);">
                                        <?php if ($certificateDocumentFormatted !== ''): ?>
                                            <?= htmlspecialchars($certificateDocumentFormatted, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                            <span style="color:rgba(148,163,184,0.6);">-</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($certificateClientName !== ''): ?>
                                        <span style="font-size:0.78rem;color:<?= $isCurrentClientCertificate ? 'var(--muted)' : 'rgba(148,163,184,0.75)'; ?>;">
                                            <?= htmlspecialchars($certificateClientName, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($isCurrentClientCertificate && $hasCertificateAggregation): ?>
                                        <span style="display:inline-block;padding:2px 6px;border-radius:999px;background:rgba(129,140,248,0.18);color:#a5b4fc;font-size:0.7rem;font-weight:600;width:max-content;">Cliente atual</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding:14px 16px;font-weight:600;color:var(--text);">
                                <?= htmlspecialchars($certificate['protocol'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:14px 16px;color:var(--muted);">
                                <?= htmlspecialchars($certificate['product_name'] ?? 'Certificado', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:14px 16px;color:var(--muted);">
                                <?= format_date($certificate['start_at'] ?? null); ?>
                            </td>
                            <td style="padding:14px 16px;color:var(--muted);">
                                <?= format_date($certificate['end_at'] ?? null); ?>
                            </td>
                            <td style="padding:14px 16px;color:var(--muted);">
                                <?= $statusBadgeHtml; ?>
                            </td>
                            <td style="padding:14px 16px;color:var(--muted);">
                                <?= $certificatePartner !== '' ? htmlspecialchars($certificatePartner, ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">-</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2 style="margin-top:0;">Histórico de etapas</h2>
    <?php if (empty($stageHistory)): ?>
        <p style="color:var(--muted);margin:0;">Ainda não há histórico de movimentação neste funil.</p>
    <?php else: ?>
        <ul style="list-style:none;padding:0;margin:0;display:grid;gap:16px;">
            <?php foreach ($stageHistory as $entry): ?>
                <li style="border:1px solid var(--border);border-radius:16px;padding:16px;background:rgba(11,18,32,0.8);">
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;">
                        <strong style="color:var(--text);"><?= htmlspecialchars($entry['from_stage_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?> &rarr; <?= htmlspecialchars($entry['to_stage_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span style="color:var(--muted);font-size:0.85rem;">
                            <?= format_datetime($entry['changed_at'] ?? null); ?>
                        </span>
                    </div>
                    <?php if (!empty($entry['notes'])): ?>
                        <p style="margin:12px 0 0;color:var(--muted);font-size:0.88rem;">
                            <?= nl2br(htmlspecialchars($entry['notes'], ENT_QUOTES, 'UTF-8')); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($entry['changed_by'])): ?>
                        <p style="margin:8px 0 0;color:rgba(148,163,184,0.7);font-size:0.78rem;">Por <?= htmlspecialchars($entry['changed_by'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const endpointNode = document.querySelector('[data-client-mark-endpoint]');
    const endpoint = endpointNode ? endpointNode.getAttribute('data-client-mark-endpoint') : '';
    if (!endpoint) {
        return;
    }

    const clientId = endpointNode ? parseInt(endpointNode.getAttribute('data-client-id') || '0', 10) : 0;
    const cacheKey = endpointNode ? endpointNode.getAttribute('data-client-mark-cache-key') || 'crm_client_mark_cache' : 'crm_client_mark_cache';
    const ttlSecondsDefault = endpointNode ? parseInt(endpointNode.getAttribute('data-client-mark-ttl') || '0', 10) : 0;
    let ttlSecondsByType = {};
    if (endpointNode) {
        const rawMap = endpointNode.getAttribute('data-client-mark-ttl-map') || '{}';
        try {
            const parsed = JSON.parse(rawMap);
            if (parsed && typeof parsed === 'object') {
                ttlSecondsByType = parsed;
            }
        } catch (error) {
            ttlSecondsByType = {};
        }
    }

    const pushLocalMark = function (type) {
        if (!type || !clientId || typeof window.localStorage === 'undefined') {
            return;
        }

        let cache;
        try {
            cache = JSON.parse(localStorage.getItem(cacheKey) || '{}');
        } catch (error) {
            cache = {};
        }

        if (!cache[clientId]) {
            cache[clientId] = {};
        }

        const nowSeconds = Math.floor(Date.now() / 1000);
        const ttlForType = (type && ttlSecondsByType[type]) ? parseInt(ttlSecondsByType[type], 10) : ttlSecondsDefault;
        const ttlValue = Number.isFinite(ttlForType) && ttlForType > 0 ? ttlForType : 172800;
        const expiresAt = nowSeconds + ttlValue;
        cache[clientId][type] = {
            type,
            expires_at: expiresAt,
        };

        try {
            localStorage.setItem(cacheKey, JSON.stringify(cache));
        } catch (error) {
            // ignore storage errors
        }
    };

    const sendMark = function (type) {
        if (!type) {
            return;
        }

        pushLocalMark(type);

        const payload = new URLSearchParams();
        payload.set('type', type);
        if (window.CSRF_TOKEN) {
            payload.set('_token', window.CSRF_TOKEN);
        }

        const body = payload.toString();
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
            return;
        }

        const headers = new Headers({
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        });
        if (window.CSRF_TOKEN) {
            headers.set('X-CSRF-TOKEN', window.CSRF_TOKEN);
        }

        fetch(endpoint, {
            method: 'POST',
            body,
            headers,
            keepalive: true,
        }).catch(() => {});
    };

    document.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target.closest('[data-client-mark]') : null;
        if (!target) {
            return;
        }

        const type = target.getAttribute('data-client-mark');
        if (!type) {
            return;
        }

        sendMark(type);
    });
});
</script>
