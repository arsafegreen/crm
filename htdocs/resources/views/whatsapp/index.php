<?php

$status = array_merge([

    'connected' => false,

    'missing' => [],

    'webhook_ready' => false,

    'copilot_ready' => false,

    'lines_total' => 0,

    'open_threads' => 0,

    'waiting_threads' => 0,

], $status ?? []);

$arrivalThreads = $arrivalThreads ?? [];

$scheduledThreads = $scheduledThreads ?? [];

$partnerThreads = $partnerThreads ?? [];

$myThreads = $myThreads ?? [];

$groupThreads = $groupThreads ?? [];

$reminderThreads = $reminderThreads ?? [];

$completedThreads = $completedThreads ?? [];

$filterOutGroups = static fn(array $threads): array => array_values(array_filter($threads, static fn(array $t): bool => ($t['chat_type'] ?? '') !== 'group'));

// Grupos só aparecem na aba Grupos; removemos dos demais painéis.
$groupThreads = array_values(array_filter($groupThreads, static fn(array $t): bool => ($t['chat_type'] ?? '') === 'group'));
$arrivalThreads = $filterOutGroups($arrivalThreads);
$scheduledThreads = $filterOutGroups($scheduledThreads);
$partnerThreads = $filterOutGroups($partnerThreads);
$myThreads = $filterOutGroups($myThreads);
$reminderThreads = $filterOutGroups($reminderThreads);
$completedThreads = $filterOutGroups($completedThreads);

$lines = $lines ?? [];

$options = $options ?? [];

$copilotProfiles = $copilotProfiles ?? [];

$defaultCopilotProfileId = null;

foreach ($copilotProfiles as $copilotProfile) {

    if (!empty($copilotProfile['is_default'])) {

        $defaultCopilotProfileId = (int)$copilotProfile['id'];

        break;

    }

}

if ($defaultCopilotProfileId === null && $copilotProfiles !== []) {

    $defaultCopilotProfileId = (int)$copilotProfiles[0]['id'];

}

$allowedUserIds = array_map('intval', $allowedUserIds ?? []);

$agentsDirectory = $agentsDirectory ?? [];
$resolveAgentDisplayLabel = static function (array $agent): string {
    $alias = trim((string)($agent['chat_display_name'] ?? ''));
    if ($alias !== '') {
        return $alias;
    }
    $fallback = trim((string)($agent['name'] ?? 'Usuário'));
    return $fallback !== '' ? $fallback : 'Usuário';
};
$agentsById = [];
foreach ($agentsDirectory as $index => $agent) {
    if (!isset($agent['id'])) {
        continue;
    }
    $agentsDirectory[$index]['display_label'] = $resolveAgentDisplayLabel($agent);
    $agentsById[(int)$agent['id']] = $agentsDirectory[$index];
}

$partnersDirectory = $partnersDirectory ?? [];

$actor = $actor ?? null;

$canManage = (bool)($canManage ?? false);

$standaloneView = isset($_GET['standalone']) && $_GET['standalone'] !== '0';
$conversationOnly = isset($_GET['conversation_only']) && $_GET['conversation_only'] !== '0';

if (!$standaloneView) {

    $queryParams = $_GET;

    $queryParams['standalone'] = '1';

    $redirectUrl = url('whatsapp');

    if ($queryParams !== []) {

        $redirectUrl .= '?' . http_build_query($queryParams);

    }

    header('Location: ' . $redirectUrl);

    exit;

}

$collapseContext = !$canManage;
$sandboxLines = $sandboxLines ?? [];
$altGateways = $altGateways ?? [];
$deferPanels = !empty($deferPanels);
$altGatewayLookup = [];
foreach ($altGateways as $altGateway) {
    $slug = strtolower(trim((string)($altGateway['slug'] ?? '')));
    if ($slug === '') {
        continue;
    }
    $altGatewayLookup[$slug] = $altGateway;
}

$defaultGatewayInstance = null;
if ($selectedChannel === 'alt_wpp') {
    foreach ($altGatewayLookup as $slug => $gateway) {
        if (str_starts_with($slug, 'wpp')) {
            $defaultGatewayInstance = $slug;
            break;
        }
    }
} elseif ($selectedChannel === 'alt_lab') {
    foreach ($altGatewayLookup as $slug => $gateway) {
        if (str_starts_with($slug, 'lab')) {
            $defaultGatewayInstance = $slug;
            break;
        }
    }
}

$queueSummary = array_merge([
    'arrival' => 0,
    'scheduled' => 0,
    'partner' => 0,
    'reminder' => 0,
], $queueSummary ?? ($status['queues'] ?? []));

$channelOptions = [
    'meta' => [
        'label' => 'Canal oficial Meta',
        'description' => 'API oficial (Meta/Facebook) com webhooks e templates.',
    ],
    'alt_lab' => [
        'label' => 'WhatsApp Lab (Alt 1)',
        'description' => 'Gateways laboratório (slugs lab*) isolados do oficial e do WPP.',
    ],
    'alt_wpp' => [
        'label' => 'WhatsApp WPP (Alt 2)',
        'description' => 'Gateway QR (slugs wpp*) com payload completo e fotos.',
    ],
    'alt' => [
        'label' => 'Alternativos (todos)',
        'description' => 'Visão agregada de todos os gateways alternativos.',
    ],
];
$selectedChannel = isset($selectedChannel) && $selectedChannel !== '' ? $selectedChannel : null;

$buildWhatsappUrl = static function (array $params = []) use ($selectedChannel, $standaloneView, $conversationOnly): string {
    $query = $params;
    if ($standaloneView) {
        $query['standalone'] = '1';
    }
    if ($conversationOnly && !array_key_exists('conversation_only', $query)) {
        $query['conversation_only'] = '1';
    }
    if ($selectedChannel !== null) {
        $query['channel'] = $selectedChannel;
    }
    $queryString = http_build_query($query);
    return url('whatsapp') . ($queryString !== '' ? '?' . $queryString : '');
};

$formatFileSize = static function (?int $bytes): ?string {
    if ($bytes === null || $bytes <= 0) {
        return null;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unitIndex = 0;
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    if ($unitIndex === 0) {
        return $bytes . ' ' . $units[0];
    }
    $decimals = $unitIndex >= 2 ? 2 : 1;
    return number_format($size, $decimals, ',', '.') . ' ' . $units[$unitIndex];
};

if ($selectedChannel === null) {
    ?>
    <style>
        .wa-channel-gate { min-height:100vh; display:flex; flex-direction:column; justify-content:center; align-items:center; background:#020617; color:#f8fafc; padding:40px 20px; }
        .wa-channel-gate h1 { font-size:2rem; margin-bottom:10px; }
        .wa-channel-gate p { margin:0 0 30px; color:#cbd5f5; text-align:center; max-width:520px; }
        .wa-channel-grid { display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); width:min(960px,100%); }
        .wa-channel-card { border:1px solid rgba(148,163,184,0.35); border-radius:18px; padding:20px; background:rgba(15,23,42,0.85); display:flex; flex-direction:column; gap:12px; box-shadow:0 20px 45px rgba(2,6,23,0.45); }
        .wa-channel-card h3 { margin:0; font-size:1.1rem; }
        .wa-channel-card p { margin:0; color:#94a3b8; }
        .wa-channel-card a { display:inline-flex; justify-content:center; align-items:center; padding:10px 16px; border-radius:12px; text-decoration:none; background:#22c55e; color:#04101d; font-weight:600; transition:background 0.2s ease; }
        .wa-channel-card a:hover { background:#16a34a; color:#f8fafc; }
    </style>
    <section class="wa-channel-gate">
        <h1>Escolha um canal de atendimento</h1>
        <p>Selecione por qual infraestrutura você quer carregar o painel. Voce pode voltar aqui a qualquer momento para trocar de fluxo.</p>
        <div class="wa-channel-grid">
            <?php foreach ($channelOptions as $channelKey => $channelData): ?>
                <?php
                    $link = url('whatsapp') . '?' . http_build_query([
                        'standalone' => '1',
                        'channel' => $channelKey,
                    ]);
                ?>
                <article class="wa-channel-card">
                    <div>
                        <h3><?= htmlspecialchars($channelData['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?= htmlspecialchars($channelData['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>">Entrar pelo <?= htmlspecialchars($channelKey, ENT_QUOTES, 'UTF-8'); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return;
}

$currentChannelLabel = $channelOptions[$selectedChannel]['label'] ?? 'Canal selecionado';

$threadData = $thread ?? null;
$activeThread = $threadData['thread'] ?? null;

$activeContact = $threadData['contact'] ?? null;

$messages = $threadData['messages'] ?? [];
$lastMessageId = 0;
$firstMessageId = 0;
foreach ($messages as $candidateMessage) {
    $candidateId = (int)($candidateMessage['id'] ?? 0);
    if ($candidateId > $lastMessageId) {
        $lastMessageId = $candidateId;
    }
    if ($firstMessageId === 0 || ($candidateId > 0 && $candidateId < $firstMessageId)) {
        $firstMessageId = $candidateId;
    }
}

$activeThreadId = (int)($activeThread['id'] ?? 0);

$canTransferThread = $activeThread !== null && $agentsDirectory !== [];

$contactTagsRaw = (string)($activeContact['tags'] ?? '');

$contactTags = $contactTagsRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $contactTagsRaw)))) : [];

$contactMetadata = [];

if ($activeContact !== null) {

    $rawMetadata = $activeContact['metadata'] ?? null;

    if (is_string($rawMetadata) && trim($rawMetadata) !== '') {

        $decoded = json_decode($rawMetadata, true);

        if (is_array($decoded)) {

            $contactMetadata = $decoded;

        }

    }

}



$activeClientSummary = null;

if ($activeThread !== null && isset($activeThread['contact_client']) && is_array($activeThread['contact_client'])) {

    $activeClientSummary = $activeThread['contact_client'];

}



$activeContactName = 'Contato WhatsApp';

if ($activeClientSummary !== null && !empty($activeClientSummary['name'])) {

    $activeContactName = trim((string)$activeClientSummary['name']);

} elseif ($activeContact !== null && !empty($activeContact['name'])) {

    $activeContactName = trim((string)$activeContact['name']);

} elseif ($activeThread !== null && !empty($activeThread['contact_display'])) {

    $activeContactName = trim((string)$activeThread['contact_display']);

}



$activeContactPhone = '';

if ($activeThread !== null && !empty($activeThread['contact_display_secondary'])) {

    $activeContactPhone = trim((string)$activeThread['contact_display_secondary']);

}

if ($activeContactPhone === '' && $activeContact !== null) {

    $activeContactPhone = format_phone((string)($activeContact['phone'] ?? ''));

}

$channelThreadId = (string)($activeThread['channel_thread_id'] ?? '');
$isAltThread = str_starts_with($channelThreadId, 'alt:');
$activePhoneLower = mb_strtolower($activeContactPhone, 'UTF-8');
$phoneLooksUnknown = $activeContactPhone === '' || stripos($activePhoneLower, 'não identificado') !== false;
$altPhoneSuggestion = '';
if ($isAltThread) {
    $payload = substr($channelThreadId, 4);
    $parts = explode(':', $payload, 2);
    $rawAlt = preg_replace('/@.*/', '', (string)($parts[1] ?? '')) ?? '';
    $altPhoneSuggestion = preg_replace('/\D+/', '', $rawAlt) ?? '';
}
if ($altPhoneSuggestion === '' && $activeContactPhone !== '') {
    $altPhoneSuggestion = preg_replace('/\D+/', '', $activeContactPhone) ?? '';
}
$registerContactEnabled = $isAltThread && $phoneLooksUnknown && $activeThreadId > 0 && (int)($activeContact['id'] ?? 0) > 0;
$registerContactCpf = (string)($contactMetadata['cpf'] ?? '');

$activeUnreadCount = max(0, (int)($activeThread['unread_count'] ?? 0));
$activeClientId = (int)($activeClientSummary['id'] ?? ($activeContact['client_id'] ?? 0));
$activeClientUrl = $activeClientId > 0 ? url('crm/clients/' . $activeClientId) : null;

$contactProfileName = (string)($contactMetadata['profile'] ?? $activeContactName);
$contactProfilePhoto = null;
if (!empty($contactMetadata['profile_photo']) && is_string($contactMetadata['profile_photo'])) {
    $candidatePhoto = (string)$contactMetadata['profile_photo'];
    if (str_starts_with($candidatePhoto, 'http')) {
        $contactProfilePhoto = $candidatePhoto;
    }
}
$contactRawFrom = (string)($contactMetadata['raw_from'] ?? '');
$contactNormalizedFrom = (string)($contactMetadata['normalized_from'] ?? '');
$contactGatewaySnapshot = isset($contactMetadata['gateway_snapshot']) && is_array($contactMetadata['gateway_snapshot'])
    ? $contactMetadata['gateway_snapshot']
    : null;
$contactGatewaySource = is_array($contactGatewaySnapshot) ? (string)($contactGatewaySnapshot['source'] ?? '') : '';
$contactGatewayCapturedAt = is_array($contactGatewaySnapshot) && isset($contactGatewaySnapshot['captured_at'])
    ? (int)$contactGatewaySnapshot['captured_at']
    : 0;



$latestMessageText = '';

if ($messages !== []) {

    $lastKey = array_key_last($messages);

    $latestMessageText = (string)($messages[$lastKey]['content'] ?? '');

}



$formatTimestamp = static function (?int $timestamp): string {

    if ($timestamp === null || $timestamp <= 0) {

        return '-';

    }



    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));

    return $date->format('d/m/y H:i');

};



$formatDatetimeLocal = static function (?int $timestamp): string {

    if ($timestamp === null || $timestamp <= 0) {

        return '';

    }



    return date('Y-m-d\TH:i', $timestamp);

};

$buildSearchIndex = static function (array $parts): string {
    $buffer = [];
    foreach ($parts as $part) {
        if (is_scalar($part)) {
            $value = trim((string)$part);
            if ($value !== '') {
                $buffer[] = $value;
            }
        }
    }
    if ($buffer === []) {
        return '';
    }
    $text = mb_strtolower(implode(' ', $buffer), 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    } elseif (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }
    return trim((string)$text);
};

$digitsOnly = static function ($value): string {
    $clean = preg_replace('/\D+/', '', (string)$value);
    return $clean !== null ? $clean : '';
};

$blockedNumbers = array_map('strval', is_array($options['blocked_numbers'] ?? null) ? $options['blocked_numbers'] : []);
$activeContactDigits = $digitsOnly($activeContactPhone !== '' ? $activeContactPhone : ($activeContact['phone'] ?? ''));
$isContactBlocked = $activeContactDigits !== '' && in_array($activeContactDigits, $blockedNumbers, true);



$lineById = [];
$lineByAltSlug = [];

foreach ($lines as $line) {

    $lineById[(int)($line['id'] ?? 0)] = $line;
    $altSlug = strtolower(trim((string)($line['alt_gateway_instance'] ?? '')));
    if ($altSlug !== '') {
        $lineByAltSlug[$altSlug] = $line;
    }

}

$mediaTemplates = $mediaTemplates ?? ['stickers' => [], 'documents' => []];
$threadSupportsTemplates = false;
if ($activeThread !== null) {
    $channelRef = (string)($activeThread['channel_thread_id'] ?? '');
    if ($channelRef !== '' && str_starts_with($channelRef, 'alt:')) {
        $threadSupportsTemplates = true;
    } else {
        $lineRef = $lineById[(int)($activeThread['line_id'] ?? 0)] ?? null;
        if ($lineRef !== null && !empty($lineRef['alt_gateway_instance'])) {
            $threadSupportsTemplates = true;
        }
    }
}
$stickersAvailable = !empty($mediaTemplates['stickers'] ?? []);

$describeThreadOrigin = static function (?array $thread) use ($lineById, $altGatewayLookup, $lineByAltSlug): array {
    $result = [
        'label' => '',
        'phone' => '',
        'type' => 'meta',
        'slug' => null,
    ];

    if ($thread === null) {
        return $result;
    }

    $lineId = (int)($thread['line_id'] ?? 0);
    if ($lineId > 0 && isset($lineById[$lineId])) {
        $line = $lineById[$lineId];
        $result['label'] = trim((string)($line['label'] ?? ''));
        $result['phone'] = trim((string)($line['display_phone'] ?? ''));
        return $result;
    }

    $label = trim((string)($thread['line_label'] ?? ''));
    $phone = trim((string)($thread['line_display_phone'] ?? ''));
    if ($label !== '' || $phone !== '') {
        $result['label'] = $label;
        $result['phone'] = $phone;
        return $result;
    }

    $channelId = (string)($thread['channel_thread_id'] ?? '');
    if (!str_starts_with($channelId, 'alt:')) {
        return $result;
    }

    $payload = substr($channelId, 4);
    $parts = explode(':', $payload, 2);
    $slug = strtolower(trim($parts[0] ?? ''));
    $rawPhone = $parts[1] ?? '';

    $result['type'] = 'alt';
    $result['slug'] = $slug !== '' ? $slug : null;
    $result['phone'] = $rawPhone !== '' ? format_phone($rawPhone) : '';

    if ($slug !== '' && isset($lineByAltSlug[$slug])) {
        $line = $lineByAltSlug[$slug];
        $result['label'] = trim((string)($line['label'] ?? ''));
        $result['phone'] = trim((string)($line['display_phone'] ?? $result['phone']));
        return $result;
    }

    if ($slug !== '' && isset($altGatewayLookup[$slug])) {
        $result['label'] = (string)$altGatewayLookup[$slug]['label'];
    } elseif ($slug !== '') {
        $result['label'] = 'Gateway ' . strtoupper($slug);
    } else {
        $result['label'] = 'Gateway alternativo';
    }

    return $result;
};

$activeLine = null;

if ($activeThread !== null) {

    $lineId = (int)($activeThread['line_id'] ?? 0);

    $activeLine = $lineById[$lineId] ?? null;

}
$activeOrigin = $describeThreadOrigin($activeThread);
$activeLineChip = '';
if ($activeOrigin['label'] !== '') {
    $activeLineChip = (string)$activeOrigin['label'];
}
if ($activeOrigin['phone'] !== '') {
    $activeLineChip = $activeLineChip !== '' ? $activeLineChip . ' · ' . $activeOrigin['phone'] : (string)$activeOrigin['phone'];
}



$queueLabels = [
    'arrival' => 'Fila de chegada',
    'scheduled' => 'Agendamentos',
    'partner' => 'Parceiros / Indicadores',
    'reminder' => 'Lembretes',
];
$broadcastQueueLabels = $queueLabels + ['groups' => 'Grupos'];

$currentQueueKey = (string)($activeThread['queue'] ?? 'arrival');

$currentQueueLabel = $queueLabels[$currentQueueKey] ?? $queueLabels['arrival'];

$scheduledLocalValue = $formatDatetimeLocal(isset($activeThread['scheduled_for']) ? (int)$activeThread['scheduled_for'] : null);



$resolveThreadPhone = static function (array $thread): string {
    if (!empty($thread['is_group'])) {
        return '';
    }

    $candidates = [];

    $displaySecondary = trim((string)($thread['contact_display_secondary'] ?? ''));
    if ($displaySecondary !== '') {
        $candidates[] = $displaySecondary;
    }

    $clientSummary = isset($thread['contact_client']) && is_array($thread['contact_client'])
        ? $thread['contact_client']
        : null;
    if ($clientSummary !== null) {
        $clientPhones = [
            $clientSummary['whatsapp'] ?? null,
            $clientSummary['phone'] ?? null,
        ];
        foreach ($clientPhones as $clientPhone) {
            $formatted = format_phone($clientPhone !== null ? (string)$clientPhone : '');
            if ($formatted !== '') {
                $candidates[] = $formatted;
                break;
            }
        }
    }

    $formattedContact = trim((string)($thread['contact_phone_formatted'] ?? ''));
    if ($formattedContact !== '') {
        $candidates[] = $formattedContact;
    }

    $rawPhone = trim((string)($thread['contact_phone'] ?? ''));
    if ($rawPhone !== '') {
        $candidates[] = format_phone($rawPhone);
    }

    $channelId = (string)($thread['channel_thread_id'] ?? '');
    if (str_starts_with($channelId, 'alt:')) {
        $payload = substr($channelId, 4);
        $parts = explode(':', $payload, 2);
        $altPhone = $parts[1] ?? '';
        if ($altPhone !== '') {
            $candidates[] = format_phone($altPhone);
        }
    }

    foreach ($candidates as $value) {
        $clean = trim((string)$value);
        if ($clean !== '' && stripos($clean, 'grupo whatsapp') === false) {
            return $clean;
        }
    }

    return '';
};

$compactPanels = ['entrada', 'atendimento', 'parceiros', 'lembrete', 'agendamento'];

$renderThreadCard = static function (array $thread, array $options = []) use ($activeThreadId, $lineById, $queueLabels, $formatTimestamp, $agentsById, $buildWhatsappUrl, $describeThreadOrigin, $buildSearchIndex, $digitsOnly, $resolveThreadPhone, $compactPanels): string {
    $threadId = (int)($thread['id'] ?? 0);

    $name = trim((string)($thread['contact_name'] ?? 'Contato'));
    $phoneDisplay = '';

    $preview = trim((string)($thread['last_message_preview'] ?? ''));

    $queue = (string)($thread['queue'] ?? 'arrival');

    $origin = $describeThreadOrigin($thread);
    $lineLabel = trim((string)$origin['label']);
    $lineDisplayPhone = trim((string)$origin['phone']);
    $lineChipText = trim($lineLabel . ($lineDisplayPhone !== '' ? ' · ' . $lineDisplayPhone : ''));

    $scheduledFor = isset($thread['scheduled_for']) ? (int)$thread['scheduled_for'] : null;

    $partnerName = (string)($thread['partner_name'] ?? '');

    $responsibleId = (int)($thread['responsible_user_id'] ?? 0);
    if ($responsibleId > 0 && isset($agentsById[$responsibleId])) {
        $responsibleName = (string)($agentsById[$responsibleId]['display_label'] ?? $agentsById[$responsibleId]['name']);
    } else {
        $responsibleName = (string)($thread['responsible_name'] ?? '');
    }

    $assignedId = (int)($thread['assigned_user_id'] ?? 0);

    $assignedName = '';
    if ($assignedId > 0 && isset($agentsById[$assignedId])) {
        $assignedName = (string)($agentsById[$assignedId]['display_label'] ?? $agentsById[$assignedId]['name']);
    }

    $unread = (int)($thread['unread_count'] ?? 0);

    $status = strtoupper((string)($thread['status'] ?? 'open'));
    $hasClient = (int)($thread['contact_client_id'] ?? 0) > 0;

    $isGroupThread = !empty($thread['is_group']);
    $displayName = trim((string)($thread['contact_display'] ?? ''));
    if ($displayName !== '') {
        $name = $displayName;
    }
    $phoneDisplay = !$isGroupThread ? $resolveThreadPhone($thread) : '';

    $isActive = $threadId === $activeThreadId;

    $showClaim = !empty($options['allow_claim'] ?? $options['claim']);
    $showSchedule = !empty($options['show_schedule']);
    $showStatus = !empty($options['show_status']);
    $showPreview = !empty($options['show_preview']);
    $showQueue = !empty($options['show_queue']);
    $showLine = !empty($options['show_line']);
    $showPartner = !empty($options['show_partner']);
    $showResponsible = !empty($options['show_responsible']);
    $showAgent = !empty($options['show_agent']);

    $panelKey = (string)($options['panel'] ?? ($thread['queue'] ?? ''));
    $isCompactPanel = in_array($panelKey, $compactPanels, true);

    if ($isCompactPanel) {
        $showPreview = false;
        $showQueue = false;
        $showStatus = false;
        $showPartner = false;
        $showResponsible = false;
        $showSchedule = false;
        $showLine = true;
        $showAgent = true;
        if ($panelKey === 'atendimento') {
            $showClaim = false;
        }
    }
    $searchParts = [
        $name,
        (string)($thread['contact_display'] ?? ''),
        (string)($thread['contact_display_secondary'] ?? ''),
        (string)($thread['contact_phone'] ?? ''),
        $phoneDisplay,
        $digitsOnly($thread['contact_phone'] ?? ''),
        $preview,
        $queueLabels[$queue] ?? $queue,
        $status,
        $partnerName,
        $responsibleName,
        $assignedName,
        $lineChipText,
        $lineLabel,
        $lineDisplayPhone,
        $threadId,
        (string)($thread['channel_thread_id'] ?? ''),
    ];
    $phoneDigits = $digitsOnly($thread['contact_phone'] ?? '');
    if ($phoneDigits !== '' && !str_starts_with($phoneDigits, '55')) {
        $searchParts[] = '55' . $phoneDigits;
    }
    if ($isGroupThread) {
        $searchParts[] = 'grupo';
    }
    if ($hasClient) {
        $searchParts[] = 'cliente';
    }
    $searchIndex = $buildSearchIndex($searchParts);



    ob_start(); ?>

    <article class="wa-thread-card <?= $isActive ? 'is-active' : ''; ?>" data-thread-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES, 'UTF-8'); ?>" data-thread-panel="<?= htmlspecialchars($panelKey, ENT_QUOTES, 'UTF-8'); ?>" data-thread-id="<?= $threadId; ?>">

        <a class="wa-thread-link" href="<?= htmlspecialchars($buildWhatsappUrl(['thread' => $threadId]), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="wa-thread-heading">

                <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($hasClient): ?>
                    <span class="wa-client-icon" title="Cliente do CRM" aria-label="Cliente do CRM">
                        <svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-3.33 0-6 1.34-6 3v1h12v-1c0-1.66-2.67-3-6-3z" />
                        </svg>
                    </span>
                <?php endif; ?>

                <?php if ($isGroupThread): ?>

                    <span class="wa-chip">Grupo</span>

                <?php endif; ?>

                <?php if ($unread > 0): ?>

                    <span class="wa-unread"><?= $unread; ?></span>

                <?php endif; ?>

            </div>

            <?php if ($phoneDisplay !== ''): ?>

                <small><?= htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8'); ?></small>

            <?php endif; ?>

        </a>

        <?php if ($showLine && $lineChipText !== ''): ?>

            <?php if ($isCompactPanel): ?>
                <p class="wa-mini-line"><?= htmlspecialchars($lineChipText, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <span class="wa-chip"><?= htmlspecialchars($lineChipText, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($showPreview): ?>

            <p><?= htmlspecialchars($preview !== '' ? '"' . mb_substr($preview, 0, 120) . '"' : 'Sem prévia de mensagem.', ENT_QUOTES, 'UTF-8'); ?></p>

        <?php endif; ?>

        <?php
        $metaChips = [];
        if ($showSchedule && $scheduledFor) {
            $metaChips[] = 'Agendado: ' . $formatTimestamp($scheduledFor);
        }
        if ($showPartner && $partnerName !== '') {
            $metaChips[] = 'Parceiro: ' . $partnerName;
        }
        if ($showResponsible && $responsibleName !== '') {
            $metaChips[] = 'Responsável: ' . $responsibleName;
        }
        if ($showQueue) {
            $metaChips[] = 'Fila: ' . ($queueLabels[$queue] ?? ucfirst($queue));
        }
        if ($showAgent && $assignedName !== '' && !$isCompactPanel) {
            $metaChips[] = 'Atendente: ' . $assignedName;
        }
        ?>

        <?php if ($metaChips !== [] || $showStatus): ?>

            <footer>

                <?php foreach ($metaChips as $chip): ?>

                    <span class="wa-chip"><?= htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>

                <?php endforeach; ?>

                <?php if ($showStatus): ?>

                    <span class="wa-chip"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>

                <?php endif; ?>

            </footer>

        <?php endif; ?>

        <?php if ($showClaim): ?>
            <!-- Claim button removido a pedido do cliente -->
        <?php endif; ?>

    </article>

    <?php

    return trim((string)ob_get_clean());

};

$normalizePhoneKey = static function ($value, $fallbackId = null): string {

    $raw = (string)$value;

    if ($raw === '') {

        return 'thread-' . ($fallbackId !== null ? $fallbackId : uniqid('thread_', true));

    }

    if (str_starts_with($raw, 'group:')) {

        $suffix = preg_replace('/[^a-zA-Z0-9]+/', '', substr($raw, 6)) ?: '';

        if ($suffix === '') {

            $suffix = (string)($fallbackId !== null ? $fallbackId : uniqid('group_', true));

        }

        return 'group-' . $suffix;

    }

    $digits = preg_replace('/\D+/', '', $raw);

    if ($digits === '') {

        return 'thread-' . ($fallbackId !== null ? $fallbackId : uniqid('thread_', true));

    }

    return $digits;

};



$normalizeGroupKey = static function (array $thread) use ($normalizePhoneKey): string {
    $channelId = trim((string)($thread['channel_thread_id'] ?? ''));
    if ($channelId !== '') {
        $slug = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($channelId, 'UTF-8'));
        if ($slug !== '') {
            return 'group-channel-' . $slug;
        }
    }

    $subject = trim((string)($thread['group_subject'] ?? ''));
    if ($subject !== '') {
        $subjectSlug = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($subject, 'UTF-8'));
        if ($subjectSlug !== '') {
            return 'group-subject-' . $subjectSlug;
        }
    }

    $contactPhone = (string)($thread['contact_phone'] ?? '');
    if ($contactPhone === '') {
        $contactPhone = 'group:' . ($thread['id'] ?? uniqid('group_', true));
    } elseif (!str_starts_with($contactPhone, 'group:')) {
        $contactPhone = 'group:' . $contactPhone;
    }

    return $normalizePhoneKey($contactPhone, $thread['id'] ?? null);
};


$groupThreadsByContact = static function (array $threads) use ($normalizePhoneKey, $normalizeGroupKey): array {

    $grouped = [];

    foreach ($threads as $thread) {

        $key = ($thread['chat_type'] ?? '') === 'group'
            ? $normalizeGroupKey($thread)
            : $normalizePhoneKey($thread['contact_phone'] ?? '', $thread['id'] ?? null);

        if (!isset($grouped[$key])) {

            $grouped[$key] = [];

        }

        $grouped[$key][] = $thread;

    }



    $result = [];

    foreach ($grouped as $items) {

        usort($items, static function (array $a, array $b): int {

            $timeA = (int)($a['last_message_at'] ?? $a['updated_at'] ?? 0);

            $timeB = (int)($b['last_message_at'] ?? $b['updated_at'] ?? 0);

            if ($timeA === $timeB) {

                return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));

            }

            return $timeB <=> $timeA;

        });



        $primary = $items[0];

        $primary['_group_threads'] = $items;

        $primary['_group_count'] = count($items);

        $result[] = $primary;

    }



    return $result;

};



$renderCompactThread = static function (array $thread, array $options = []) use ($standaloneView, $formatTimestamp, $queueLabels, $agentsById, $activeThreadId, $buildWhatsappUrl, $describeThreadOrigin, $buildSearchIndex, $digitsOnly, $resolveThreadPhone, $compactPanels): string {
    $threadId = (int)($thread['id'] ?? 0);

    if ($threadId <= 0) {

        return '';

    }



    $name = trim((string)($thread['contact_name'] ?? 'Contato'));

    $phone = '';

    $preview = trim((string)($thread['last_message_preview'] ?? ''));

    $unread = max(0, (int)($thread['unread_count'] ?? 0));

    $clientId = (int)($thread['contact_client_id'] ?? 0);

    $isGroupThread = !empty($thread['is_group']);
    $displayName = trim((string)($thread['contact_display'] ?? ''));
    if ($displayName !== '') {
        $name = $displayName;
    }
    $phone = !$isGroupThread ? $resolveThreadPhone($thread) : '';

    $photo = null;
    $candidatePhoto = isset($thread['contact_photo']) ? (string)$thread['contact_photo'] : '';
    if ($candidatePhoto !== '' && str_starts_with($candidatePhoto, 'http')) {
        $photo = $candidatePhoto;
    }

    $initials = '';
    $words = preg_split('/\s+/', trim($name));
    if (is_array($words)) {
        foreach ($words as $word) {
            $first = mb_substr($word, 0, 1, 'UTF-8');
            if ($first !== false && $first !== '') {
                $initials .= mb_strtoupper($first, 'UTF-8');
            }
            if (mb_strlen($initials, 'UTF-8') >= 2) {
                break;
            }
        }
    }
    if ($initials === '' && $phone !== '') {
        $initials = '#';
    }

    $origin = $describeThreadOrigin($thread);
    $lineLabel = trim((string)$origin['label']);
    $lineDisplayPhone = trim((string)$origin['phone']);
    $lineChipText = trim($lineLabel . ($lineDisplayPhone !== '' ? ' · ' . $lineDisplayPhone : ''));

    $partnerName = trim((string)($thread['partner_name'] ?? ''));

    $responsibleId = (int)($thread['responsible_user_id'] ?? 0);
    if ($responsibleId > 0 && isset($agentsById[$responsibleId])) {
        $responsibleName = (string)($agentsById[$responsibleId]['display_label'] ?? $agentsById[$responsibleId]['name']);
    } else {
        $responsibleName = trim((string)($thread['responsible_name'] ?? ''));
    }

    $scheduledFor = isset($thread['scheduled_for']) ? (int)$thread['scheduled_for'] : null;

    $panelKey = (string)($options['panel'] ?? 'entrada');
    $isCompactPanel = in_array($panelKey, $compactPanels, true);

    $queueKey = (string)($thread['queue'] ?? 'arrival');

    $queueLabel = $queueLabels[$queueKey] ?? ucfirst($queueKey);

    $showSchedule = !empty($options['show_schedule']);
    $showPartner = !empty($options['show_partner']);
    $showResponsible = !empty($options['show_responsible']);
    $showQueue = !empty($options['show_queue']);
    $showPreview = !empty($options['show_preview']);
    $showLine = !empty($options['show_line']);
    $showAgent = !empty($options['show_agent']);

    if ($isCompactPanel) {
        $showSchedule = false;
        $showPartner = false;
        $showResponsible = false;
        $showQueue = false;
        $showPreview = false;
        $showLine = true;
        $showAgent = true;
    }

    $groupThreads = isset($thread['_group_threads']) && is_array($thread['_group_threads']) ? $thread['_group_threads'] : null;

    $isActiveGroup = $threadId === $activeThreadId;

    if (!$isActiveGroup && $groupThreads) {

        foreach ($groupThreads as $candidateThread) {

            if ((int)($candidateThread['id'] ?? 0) === $activeThreadId) {

                $isActiveGroup = true;

                break;

            }

        }

    }



    $buildThreadUrl = static function (int $targetId, string $panel) use ($buildWhatsappUrl): string {
        $params = ['thread' => $targetId];
        if ($panel !== '') {
            $params['panel'] = $panel;
        }
        return $buildWhatsappUrl($params);
    };


    $threadUrl = $buildThreadUrl($threadId, $panelKey);



    $metaLines = [];
    $previewLine = null;




    if ($showSchedule && $scheduledFor) {



        $metaLines[] = 'Agendado: ' . $formatTimestamp($scheduledFor);



    }



    if ($showPartner && $partnerName !== '') {



        $metaLines[] = 'Parceiro: ' . $partnerName;



    }



    if ($showResponsible && $responsibleName !== '') {



        $metaLines[] = 'Responsável: ' . $responsibleName;



    }



    if ($showQueue) {



        $metaLines[] = 'Fila: ' . $queueLabel;



    }



    if ($showPreview && $preview !== '') {



        $previewLine = '“' . mb_substr($preview, 0, 120) . '”';



    }





    $assignedNames = [];

    $lineLabels = [];

    $referenceThreads = $groupThreads ?? [$thread];

    foreach ($referenceThreads as $referenceThread) {

        $assignedId = (int)($referenceThread['assigned_user_id'] ?? 0);

        if ($assignedId > 0 && isset($agentsById[$assignedId])) {

            $assignedNames[$assignedId] = (string)($agentsById[$assignedId]['display_label'] ?? $agentsById[$assignedId]['name']);

        }

        $originRef = $describeThreadOrigin($referenceThread);
        $lineIdValue = (int)($referenceThread['line_id'] ?? 0);
        $refLabel = trim((string)$originRef['label']);
        $refPhone = trim((string)$originRef['phone']);
        $lineDisplayName = trim($refLabel . ($refPhone !== '' ? ' · ' . $refPhone : ''));

        if ($lineDisplayName === '' && $lineIdValue > 0) {

            $lineDisplayName = 'Linha #' . $lineIdValue;

        }

        if ($lineDisplayName !== '') {

            $lineLabels[$lineDisplayName] = $lineDisplayName;

        }

    }



    if ($lineLabels === [] && ($lineChipText !== '' || $lineLabel !== '')) {

        $fallback = $lineChipText !== '' ? $lineChipText : $lineLabel;
        $lineLabels[$fallback] = $fallback;

    }



    if ($showAgent && $assignedNames !== [] && !$isCompactPanel) {

        $metaLines[] = 'Atendente: ' . implode(', ', array_values($assignedNames));

    }

    if ($showLine && $lineLabels !== [] && !$isCompactPanel) {

        $metaLines[] = 'Linha: ' . implode(', ', array_values($lineLabels));

    }

    $clientPayload = null;
    $clientSummary = isset($thread['contact_client']) && is_array($thread['contact_client']) ? $thread['contact_client'] : null;

    if ($clientSummary !== null || $clientId > 0) {

        $payloadId = (int)($clientSummary['id'] ?? $clientId);

        if ($payloadId > 0) {

            $clientPayload = [

                'id' => $payloadId,

                'name' => $clientSummary['name'] ?? $name,

                'phone' => $clientSummary['whatsapp'] ?? ($clientSummary['phone'] ?? $phone),

                'link' => url('crm/clients/' . $payloadId),

            ];

            if (!empty($clientSummary['status'])) {
                $clientPayload['status'] = $clientSummary['status'];
            }
            if (!empty($clientSummary['document'])) {
                $clientPayload['document'] = $clientSummary['document'];
            }
            if (!empty($clientSummary['partner'])) {
                $clientPayload['partner'] = $clientSummary['partner'];
            }
            if (array_key_exists('has_protocol', $clientSummary)) {
                $clientPayload['has_protocol'] = (bool)$clientSummary['has_protocol'];
            }
            if (array_key_exists('protocol_state', $clientSummary)) {
                $clientPayload['protocol_state'] = $clientSummary['protocol_state'];
            }
            if (array_key_exists('protocol_number', $clientSummary)) {
                $clientPayload['protocol_number'] = $clientSummary['protocol_number'];
            }
            if (array_key_exists('protocol_expires_at', $clientSummary)) {
                $clientPayload['protocol_expires_at'] = $clientSummary['protocol_expires_at'];
            }

        }

    }



    $searchParts = array_merge(
        [
            $name,
            (string)($thread['contact_display'] ?? ''),
            (string)($thread['contact_display_secondary'] ?? ''),
            (string)($thread['contact_phone'] ?? ''),
            $digitsOnly($thread['contact_phone'] ?? ''),
            $phone,
            $preview,
            $queueLabel,
            $panelKey,
            $partnerName,
            $responsibleName,
            $threadId,
        ],
        $metaLines,
        array_values($lineLabels),
        array_values($assignedNames)
    );
    if ($isGroupThread) {
        $searchParts[] = 'grupo';
    }
    $searchIndex = $buildSearchIndex($searchParts);



    ob_start(); ?>

    <article class="wa-mini-thread<?= $isActiveGroup ? ' is-active' : ''; ?>" data-thread-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES, 'UTF-8'); ?>" data-thread-panel="<?= htmlspecialchars($panelKey, ENT_QUOTES, 'UTF-8'); ?>" data-thread-id="<?= $threadId; ?>">

        <a class="wa-mini-link" href="<?= htmlspecialchars($threadUrl, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="wa-mini-header">
                <div class="wa-mini-ident">
                    <div class="wa-mini-avatar<?= $photo ? ' has-photo' : ''; ?>">
                        <?php if ($photo): ?>
                            <img src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto do contato" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="wa-mini-title">
                        <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>

                        <?php if (!empty($clientId)): ?>
                            <span class="wa-client-icon" title="Cliente do CRM" aria-label="Cliente do CRM">
                                <svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-3.33 0-6 1.34-6 3v1h12v-1c0-1.66-2.67-3-6-3z" />
                                </svg>
                            </span>
                        <?php endif; ?>

                        <?php if ($isGroupThread): ?>

                            <span class="wa-chip">Grupo</span>

                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($unread > 0): ?>

                    <span class="wa-unread">+<?= $unread; ?></span>

                <?php endif; ?>

            </div>

            <?php if ($phone !== ''): ?>
                <p class="wa-mini-phone"><?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

        </a>
        <?php if ($showLine && $lineChipText !== ''): ?>
            <p class="wa-mini-line"><?= htmlspecialchars($lineChipText, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php
            $arrivalTimestamp = (int)($thread['last_message_at'] ?? ($thread['updated_at'] ?? ($thread['created_at'] ?? 0)));
            $arrivalLabel = $arrivalTimestamp > 0 ? $formatTimestamp($arrivalTimestamp) : '';
        ?>

        <div class="wa-mini-actions" style="gap:10px;">

            <div class="wa-mini-actions-time">
                <?= htmlspecialchars($arrivalLabel !== '' ? $arrivalLabel : '--', ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <?php
                $clientButtonVariant = 'create';
                $clientButtonLabel = 'Cadastro';

                if ($clientPayload !== null && !empty($clientPayload['id'])) {
                    $statusRaw = strtolower(trim((string)($clientPayload['status'] ?? '')));
                    $activeStatuses = ['ativo', 'active', 'regular', 'regularizado'];
                    $inactiveStatuses = ['inativo', 'inactive', 'cancelado', 'cancelada', 'revogado', 'revogada', 'bloqueado', 'bloqueada'];
                    if (in_array($statusRaw, $activeStatuses, true)) {
                        $clientButtonVariant = 'active';
                        $clientButtonLabel = 'Cliente';
                    } elseif (in_array($statusRaw, $inactiveStatuses, true)) {
                        $clientButtonVariant = 'inactive';
                        $clientButtonLabel = 'Cliente';
                    } else {
                        $clientButtonVariant = 'no-protocol';
                        $clientButtonLabel = 'Cliente';
                    }
                }
            ?>

            <?php if (!$isGroupThread && $clientPayload !== null && !empty($clientPayload['id'])): ?>

                <a class="wa-client-button wa-client-button--<?= htmlspecialchars($clientButtonVariant, ENT_QUOTES, 'UTF-8'); ?>" href="<?= htmlspecialchars(url('crm/clients/' . (int)$clientPayload['id']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                    data-client-id="<?= (int)$clientPayload['id']; ?>"
                    data-client-preview='<?= htmlspecialchars(json_encode($clientPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'><?= htmlspecialchars($clientButtonLabel, ENT_QUOTES, 'UTF-8'); ?></a>

            <?php elseif (!$isGroupThread && (int)($thread['contact_id'] ?? 0) > 0): ?>

                <button class="wa-client-button wa-client-button--create" type="button"
                    data-register-contact-open
                    data-thread-id="<?= (int)$threadId; ?>"
                    data-contact-id="<?= (int)($thread['contact_id'] ?? 0); ?>"
                    data-contact-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                    data-contact-phone="<?= htmlspecialchars($digitsOnly($thread['contact_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars($clientButtonLabel, ENT_QUOTES, 'UTF-8'); ?>
                </button>

            <?php endif; ?>

            <?php if (!empty($options['allow_claim'])): ?>

                <button type="button" class="ghost wa-mini-action" data-claim-thread="<?= $threadId; ?>">Assumir</button>

            <?php endif; ?>

            <?php if (!empty($options['allow_reopen'])): ?>

                <button type="button" class="ghost wa-mini-action" data-reopen-thread="<?= $threadId; ?>">Reabrir</button>

            <?php endif; ?>

        </div>

    </article>

    <?php

    return trim((string)ob_get_clean());

};

$countUnreadThreads = static function (array $threads): int {

    $total = 0;

    foreach ($threads as $thread) {

        $total += (int)($thread['unread_count'] ?? 0);

    }



    return $total;

};

$estimatePanelCount = static function (string $panelKey) use ($queueSummary): int {
    switch ($panelKey) {
        case 'entrada':
            return (int)($queueSummary['arrival'] ?? 0);
        case 'agendamento':
            return (int)($queueSummary['scheduled'] ?? 0);
        case 'parceiros':
            return (int)($queueSummary['partner'] ?? 0);
        case 'lembrete':
            return (int)($queueSummary['reminder'] ?? 0);
        default:
            return 0;
    }
};



$panelConfig = [

    'entrada' => [

        'label' => 'Entrada',

        'description' => 'Novas conversas aguardando triagem.',

        'empty' => 'Nenhum cliente aguardando.',

        'threads' => $arrivalThreads,

        'options' => ['panel' => 'entrada', 'allow_claim' => true, 'show_line' => true, 'show_agent' => true],

    ],

    'atendimento' => [

        'label' => 'Atendimento',

        'description' => 'Conversas em andamento com vocÃª.',

        'empty' => 'Nenhuma conversa assumida.',

        'threads' => $myThreads,

        'options' => ['panel' => 'atendimento', 'show_line' => true, 'show_agent' => true],

    ],

    'grupos' => [

        'label' => 'Grupos',

        'description' => 'Conversas em grupos via WhatsApp Web.',

        'empty' => 'Nenhum grupo com atividade.',

        'threads' => $groupThreads,

        'options' => ['panel' => 'grupos', 'show_preview' => true, 'show_line' => true, 'show_agent' => true],

    ],

    'parceiros' => [

        'label' => 'Parceiros',

        'description' => 'Leads encaminhados por parceiros.',

        'empty' => 'Nenhum parceiro aguardando.',

        'threads' => $partnerThreads,

        'options' => ['panel' => 'parceiros', 'show_line' => true, 'show_agent' => true],

    ],

    'lembrete' => [

        'label' => 'Lembrete',

        'description' => 'Conversas aguardando follow-up.',

        'empty' => 'Sem lembretes programados.',

        'threads' => $reminderThreads,

        'options' => ['panel' => 'lembrete', 'show_line' => true, 'show_agent' => true],

    ],

    'agendamento' => [

        'label' => 'Agendamento',

        'description' => 'Clientes com horÃ¡rio marcado.',

        'empty' => 'Nenhum agendamento pendente.',

        'threads' => $scheduledThreads,

        'options' => ['panel' => 'agendamento', 'show_line' => true, 'show_agent' => true],

    ],

    'concluidos' => [

        'label' => 'Concluidos',

        'description' => 'HistÃ³rico finalizado (pode reabrir).',

        'empty' => 'Nenhuma conversa encerrada.',

        'threads' => $completedThreads,

        // Mantemos layout compacto sem prévia nem fila para não mudar de formato.
        'options' => [
            'panel' => 'concluidos',
            'allow_reopen' => true,
            'show_preview' => false,
            'show_queue' => false,
            'show_line' => true,
            'show_agent' => true,
        ],

    ],

];

if (!$canManage) {
    unset($panelConfig['grupos']);
}



$requestedPanel = isset($_GET['panel']) ? strtolower(trim((string)$_GET['panel'])) : null;

$defaultPanel = $activeThreadId > 0 ? 'atendimento' : 'entrada';

if ($requestedPanel !== null && isset($panelConfig[$requestedPanel])) {

    $defaultPanel = $requestedPanel;

}



foreach ($panelConfig as $panelKey => $panelData) {

    $displayThreads = $groupThreadsByContact($panelData['threads']);

    $panelConfig[$panelKey]['display_threads'] = $displayThreads;

    $panelConfig[$panelKey]['display_count'] = $displayThreads !== [] ? count($displayThreads) : $estimatePanelCount($panelKey);

}

?>

<style>

    .wa-header { display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; align-items:flex-start; margin-bottom:18px; }

    .wa-header h1 { margin:0 0 6px; font-size:1.6rem; }

    .wa-header p { margin:0; color:var(--muted); max-width:720px; }

    .wa-header-cta { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

    .wa-header-cta form { margin:0; }

    .wa-metrics { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }

    .wa-card { border:1px solid rgba(148,163,184,0.25); border-radius:18px; background:rgba(15,23,42,0.82); padding:18px; box-shadow:var(--shadow); }

    .wa-card-header { display:flex; justify-content:space-between; align-items:center; gap:10px; }

    .wa-card-header h3 { margin:0; font-size:1rem; }

    .wa-card-header small { color:var(--muted); }

    .wa-thread-list { display:flex; flex-direction:column; gap:12px; max-height:48vh; overflow:auto; padding-top:12px; }

    .wa-thread-card { border:1px solid rgba(56,189,248,0.25); border-radius:14px; padding:12px; background:rgba(15,23,42,0.6); transition:border 0.2s ease, background 0.2s ease; }

    .wa-thread-card.is-active { border-color:#22c55e; background:rgba(34,197,94,0.08); }

    .wa-thread-link { display:flex; flex-direction:column; gap:6px; text-decoration:none; color:var(--text); }

    .wa-thread-heading { display:flex; justify-content:space-between; gap:10px; align-items:center; }
    .wa-client-icon { display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:999px; border:1px solid rgba(34,197,94,0.55); background:rgba(34,197,94,0.15); color:#22c55e; flex-shrink:0; box-shadow:0 2px 6px rgba(34,197,94,0.3); }
    .wa-client-icon svg { width:12px; height:12px; fill:currentColor; }

    .wa-thread-link p { margin:0; color:var(--muted); font-size:0.9rem; }

    .wa-thread-link footer { display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:0.8rem; color:var(--muted); }

    .wa-thread-actions { margin-top:10px; display:flex; justify-content:flex-end; }

    .wa-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; border:1px solid rgba(148,163,184,0.35); font-size:0.75rem; color:var(--muted); }

    .wa-chip--queue { border-color:rgba(56,189,248,0.5); color:#7dd3fc; }
    .wa-chip--danger { border-color:rgba(248,113,113,0.55); color:#fecdd3; background:rgba(127,29,29,0.25); }

    .wa-alert { border:1px solid rgba(248,113,113,0.35); background:rgba(127,29,29,0.18); color:#fecdd3; padding:12px 14px; border-radius:12px; margin:10px 0; }
    .wa-alert strong { color:#ffe4e6; }

    .wa-unread { background:#ef4444; color:#fff; border-radius:999px; min-width:20px; padding:0 6px; text-align:center; font-size:0.8rem; }

    .wa-empty { color:var(--muted); font-size:0.9rem; margin:0; }

    .wa-empty-state { text-align:center; color:var(--muted); padding:60px 20px; }

    .wa-panel-empty[data-panel-search-empty] { text-align:center; font-size:0.85rem; color:var(--muted); }

    .wa-layout { display:grid; gap:18px; grid-template-columns:320px minmax(420px,1fr) 320px; margin-top:24px; align-items:flex-start; }

    .wa-layout.wa-layout--compact { grid-template-columns:320px minmax(420px,1fr); }

    .wa-layout.wa-layout--solo { grid-template-columns:1fr; }
    .wa-layout.wa-layout--solo .wa-pane--conversation { grid-column:1 / -1; }

    .wa-search-wrapper { margin-bottom:18px; }
    .wa-search-actions { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .wa-notify-gear { display:inline-flex; align-items:center; gap:6px; border-radius:10px; border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.5); color:var(--text); padding:6px 14px; font-size:0.82rem; cursor:pointer; }
    .wa-notify-gear:hover { border-color:rgba(248,250,252,0.5); color:#fff; }
    .wa-notify-gear svg { width:14px; height:14px; }
    .wa-notify-summary { font-size:0.78rem; color:var(--muted); }
    .wa-notify-bar { display:flex; flex-direction:column; gap:12px; margin:8px 0 22px; padding:12px 14px; border:1px dashed rgba(148,163,184,0.35); border-radius:14px; background:rgba(2,6,23,0.35); }
    .wa-notify-bar[hidden] { display:none; }
    .wa-notify-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .wa-notify-test { border-color:rgba(56,189,248,0.5); color:#7dd3fc; }
    .wa-notify-test:hover { border-color:rgba(56,189,248,0.8); color:#e0f2fe; }
    .wa-notify-toggle { border:1px solid rgba(148,163,184,0.4); border-radius:10px; padding:6px 14px; font-size:0.82rem; background:rgba(15,23,42,0.5); color:var(--text); cursor:pointer; transition:background 0.2s ease, border 0.2s ease; }
    .wa-notify-toggle.is-active { border-color:rgba(34,197,94,0.6); background:rgba(34,197,94,0.15); color:#c3f7d6; }
    .wa-notify-selects { display:flex; flex-wrap:wrap; gap:12px; }
    .wa-notify-select { display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--muted); }
    .wa-notify-select select { border-radius:8px; border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.5); color:var(--text); padding:4px 10px; font-size:0.82rem; min-width:120px; }
    .wa-notify-sound-upload { display:flex; flex-direction:column; gap:8px; border-top:1px solid rgba(148,163,184,0.2); padding-top:10px; }
    .wa-notify-upload-meta { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; font-size:0.78rem; color:var(--muted); }
    .wa-notify-upload-meta button { min-width:0; }

    .wa-search-bar { display:flex; align-items:center; gap:10px; border:1px solid rgba(148,163,184,0.35); border-radius:999px; padding:10px 16px; background:rgba(2,6,23,0.7); box-shadow:0 12px 24px rgba(2,6,23,0.35); }

    .wa-search-icon { display:flex; align-items:center; color:var(--muted); }

    .wa-search-bar input { flex:1; border:none; background:transparent; color:var(--text); font-size:0.95rem; outline:none; }

    .wa-search-bar input::placeholder { color:rgba(148,163,184,0.7); }

    .wa-search-clear { border:none; background:transparent; color:var(--muted); cursor:pointer; font-size:1.1rem; line-height:1; }

    .wa-search-clear:hover { color:#f8fafc; }

    .wa-search-feedback { margin:-10px 0 18px; font-size:0.85rem; color:var(--muted); }

    .wa-pane--queues { display:flex; flex-direction:column; gap:14px; min-height:0; }

    .wa-top-nav { margin-top:24px; margin-bottom:10px; display:flex; gap:10px; padding:14px; background:rgba(2,6,23,0.65); border:1px solid rgba(148,163,184,0.3); border-radius:18px; box-shadow:0 12px 30px rgba(2,6,23,0.35); flex-wrap:wrap; overflow-x:auto; }

    .wa-tabs { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:8px; width:100%; }

    .wa-tab { border:1px solid rgba(148,163,184,0.35); border-radius:18px; padding:14px 16px; background:rgba(15,23,42,0.45); color:var(--muted); text-decoration:none; display:flex; flex-direction:column; align-items:flex-start; gap:4px; font-weight:600; transition:all 0.2s ease; }

    .wa-tab:hover { border-color:rgba(248,250,252,0.4); color:#fff; }

    .wa-tab.is-active { border-color:#22c55e; background:rgba(34,197,94,0.14); color:#fff; box-shadow:0 10px 30px rgba(34,197,94,0.15); }

    .wa-tab-label { font-size:0.82rem; text-transform:uppercase; letter-spacing:0.05em; color:inherit; }

    .wa-tab-count { font-size:1.6rem; font-weight:700; margin:0; color:#f8fafc; }

    .wa-tab-unread { font-size:0.85rem; font-weight:600; color:#f87171; }

    .wa-contact-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }

    .wa-contact-identity { display:flex; flex-direction:column; gap:4px; }

    .wa-contact-main { display:flex; align-items:center; gap:12px; }

    .wa-contact-avatar { width:54px; height:54px; border-radius:14px; background:rgba(148,163,184,0.18); border:1px solid rgba(148,163,184,0.28); display:inline-flex; align-items:center; justify-content:center; color:#e2e8f0; font-weight:700; box-shadow:0 6px 18px rgba(2,6,23,0.4); overflow:hidden; }
    .wa-contact-avatar span { font-size:1rem; letter-spacing:0.4px; }
    .wa-contact-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
    .wa-contact-avatar.has-photo { background:rgba(255,255,255,0.02); border-color:rgba(148,163,184,0.45); }

    .wa-contact-meta { display:flex; flex-direction:column; gap:4px; }
    .wa-contact-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .wa-contact-status { display:flex; align-items:center; gap:6px; color:var(--muted); font-size:0.82rem; }
    .wa-status-dot { width:8px; height:8px; border-radius:999px; background:#22c55e; box-shadow:0 0 0 4px rgba(34,197,94,0.14); display:inline-block; }

    .wa-contact-unread { display:block; }

    .wa-contact-phone { margin:0; color:var(--muted); font-size:0.9rem; }

    .wa-contact-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

    .wa-panels { display:flex; flex-direction:column; gap:14px; flex:1; min-height:0; }

    .wa-panel { display:none; flex-direction:column; gap:12px; border:1px solid rgba(148,163,184,0.3); border-radius:18px; padding:16px; background:rgba(15,23,42,0.55); min-height:0; }

    .wa-panel.is-active { display:flex; }

    .wa-panel-header { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; }

    .wa-panel-header h3 { margin:0; font-size:1rem; }

    .wa-panel-description { display:block; margin-top:4px; font-size:0.85rem; color:var(--muted); }

    .wa-panel-body { display:flex; flex-direction:column; gap:10px; min-height:0; flex:1; }

    .wa-panel-scroll { max-height:calc(100vh - 320px); overflow-y:auto; display:flex; flex-direction:column; gap:10px; flex:1; }

    .wa-panel-empty { margin:0; padding:30px 12px; text-align:center; color:var(--muted); font-size:0.9rem; }

    .wa-mini-thread { border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:10px 12px; background:rgba(2,6,23,0.35); display:flex; flex-direction:column; gap:8px; transition:border 0.2s ease, box-shadow 0.2s ease; }

    .wa-mini-thread.is-active { border-color:#22c55e; box-shadow:0 0 0 1px rgba(34,197,94,0.35); }

    .wa-mini-link { text-decoration:none; color:var(--text); display:flex; flex-direction:column; gap:4px; }

    .wa-mini-header { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }

    .wa-mini-ident { display:flex; align-items:center; gap:10px; min-width:0; }

    .wa-mini-avatar { width:40px; height:40px; border-radius:12px; background:rgba(148,163,184,0.15); display:inline-flex; align-items:center; justify-content:center; color:#e2e8f0; font-weight:700; flex-shrink:0; overflow:hidden; border:1px solid rgba(148,163,184,0.25); box-shadow:0 4px 12px rgba(2,6,23,0.35); }
    .wa-mini-avatar span { font-size:0.95rem; letter-spacing:0.5px; }
    .wa-mini-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
    .wa-mini-avatar.has-photo { background:rgba(255,255,255,0.02); border-color:rgba(148,163,184,0.4); }

    .wa-mini-title { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

    .wa-mini-phone { margin:2px 0 0; font-size:0.85rem; color:var(--muted); }
    .wa-mini-line { margin:0; font-size:0.8rem; color:var(--muted); }
    .wa-mini-preview { margin:4px 0 0; font-size:0.85rem; color:var(--muted); }
    .wa-mini-meta { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }

    .wa-mini-actions { display:flex; gap:6px; flex-wrap:nowrap; margin-top:4px; align-items:center; }
    .wa-mini-actions-time { font-size:0.72rem; color:var(--muted); min-width:90px; flex-shrink:0; white-space:nowrap; }

    .wa-mini-action { min-width:0; font-size:0.78rem; padding:4px 10px; }

    .wa-client-button { border:1px solid rgba(148,163,184,0.35); border-radius:10px; padding:4px 12px; font-size:0.78rem; cursor:pointer; box-shadow:0 2px 6px rgba(15,23,42,0.25); background:#e2e8f0; color:#0f172a; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .wa-client-button--active { background:#16a34a; color:#fff; border-color:rgba(34,197,94,0.35); box-shadow:0 2px 10px rgba(22,163,74,0.45); }
    .wa-client-button--active:hover { background:#15803d; }
    .wa-client-button--inactive { background:#ef4444; color:#fff; border-color:rgba(239,68,68,0.35); box-shadow:0 2px 10px rgba(239,68,68,0.35); }
    .wa-client-button--inactive:hover { background:#dc2626; }
    .wa-client-button--no-protocol { background:#f8fafc; color:#0f172a; border-color:rgba(148,163,184,0.55); box-shadow:0 2px 8px rgba(148,163,184,0.25); }
    .wa-client-button--no-protocol:hover { background:#e2e8f0; }
    .wa-client-button--create { background:#0ea5e9; color:#0b192b; border-color:rgba(14,165,233,0.4); box-shadow:0 2px 10px rgba(14,165,233,0.35); }
    .wa-client-button--create:hover { background:#0284c7; color:#e2f3ff; }

    .wa-client-context { margin-top:16px; padding:12px; border:1px solid rgba(148,163,184,0.25); border-radius:14px; background:rgba(2,6,23,0.4); display:flex; flex-direction:column; gap:10px; }

    .wa-client-context__header { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }

    .wa-client-context__header strong { font-size:1rem; }

    .wa-client-context__header a { text-decoration:none; }

    .wa-client-chips { display:flex; flex-wrap:wrap; gap:6px; }
    .wa-toast-stack { position:fixed; right:24px; bottom:24px; display:flex; flex-direction:column; gap:10px; z-index:999; max-width:320px; pointer-events:none; }
    .wa-toast { border:1px solid rgba(148,163,184,0.35); border-radius:14px; padding:14px; background:rgba(15,23,42,0.92); box-shadow:0 20px 40px rgba(2,6,23,0.6); pointer-events:auto; cursor:pointer; }
    .wa-toast__header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .wa-toast__title { display:block; font-size:0.95rem; margin-top:2px; }
    .wa-toast__line { display:block; font-size:0.75rem; color:var(--muted); }
    .wa-toast__preview { margin:8px 0 0; font-size:0.85rem; color:var(--text); }
    .wa-toast__close { border:none; background:transparent; color:var(--text); font-size:1rem; cursor:pointer; }

    .wa-definition--compact { display:flex; flex-direction:column; gap:8px; margin:0; }

    .wa-definition--compact div { display:flex; flex-direction:column; gap:2px; }

    .wa-definition--compact dt { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); }

    .wa-definition--compact dd { margin:0; font-size:0.9rem; color:var(--text); }

    .wa-pane { display:flex; flex-direction:column; gap:18px; }

    .wa-message-scroll { max-height:calc(100vh - 320px); overflow-y:auto; display:flex; flex-direction:column; gap:12px; }

    .wa-quick-actions { display:flex; flex-direction:column; gap:8px; margin-bottom:14px; padding:10px 12px; border:1px dashed rgba(148,163,184,0.35); border-radius:16px; background:rgba(2,6,23,0.35); }

    .wa-quick-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }

    .wa-quick-row label { font-size:0.75rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); }

    .wa-bubble { position:relative; padding:12px 14px; border-radius:16px; border:1px solid rgba(148,163,184,0.2); max-width:75%; background:rgba(15,23,42,0.5); }

    .wa-bubble.is-outgoing { margin-left:auto; border-color:rgba(34,197,94,0.4); background:rgba(34,197,94,0.08); }

    .wa-bubble.is-incoming { margin-right:auto; border-color:rgba(56,189,248,0.35); background:rgba(56,189,248,0.08); }

    .wa-bubble.is-internal { margin:0 auto; border-color:rgba(248,250,252,0.3); background:rgba(15,23,42,0.9); }
    .wa-message-author {
        font-size:0.82rem;
        font-weight:700;
        font-style:italic;
        color:#064e3b;
        text-transform:none;
        letter-spacing:0.02em;
        margin-bottom:6px;
        display:block;
        line-height:1.2;
    }
    .wa-message-body { display:block; margin-top:2px; }
    .wa-thread-heading strong {
        display:block;
        color:#064e3b;
        font-weight:700;
        margin-bottom:2px;
    }
    .wa-message-line { font-size:0.78rem; color:var(--muted); margin-left:6px; }
    .wa-media-block { margin-bottom:10px; border-radius:14px; overflow:hidden; border:1px solid rgba(148,163,184,0.35); background:rgba(2,6,23,0.5); }
    .wa-media-block img { width:100%; height:auto; display:block; }
    .wa-media-block video,
    .wa-media-block audio { width:100%; display:block; background:#000; }
    .wa-media-download { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 14px; border-radius:12px; border:1px dashed rgba(148,163,184,0.45); text-decoration:none; color:var(--text); background:rgba(2,6,23,0.35); }
    .wa-media-download span { font-weight:600; }
    .wa-media-download small { color:var(--muted); font-size:0.78rem; }
    .wa-media-preview { margin-bottom:10px; padding:10px 14px; border-radius:14px; border:1px dashed rgba(148,163,184,0.45); display:flex; justify-content:space-between; gap:12px; align-items:center; background:rgba(2,6,23,0.4); }
    .wa-media-preview[hidden] { display:none; }
    .wa-media-preview strong { display:block; font-size:0.92rem; }
    .wa-media-preview small { color:var(--muted); font-size:0.78rem; display:block; }
    .wa-media-actions { display:flex; justify-content:flex-start; margin-bottom:8px; }
    .wa-media-actions a { display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; padding:6px 10px; border-radius:10px; border:1px solid rgba(148,163,184,0.4); text-decoration:none; color:var(--text); background:rgba(15,23,42,0.45); }
    .wa-media-actions a:hover { border-color:#22c55e; color:#22c55e; }
    .wa-status-indicator { display:inline-flex; align-items:center; gap:2px; margin-left:6px; vertical-align:middle; }
    .wa-status-indicator .wa-check { font-size:0.85rem; line-height:1; color:transparent; -webkit-text-stroke:1px rgba(148,163,184,0.85); opacity:0; transform-origin:center; transition:color 120ms ease, -webkit-text-stroke 120ms ease, opacity 120ms ease; }
    .wa-status-indicator .check-2 { transform:translateX(-2px); }
    .wa-status-indicator.is-pending .wa-check { opacity:0.6; color:transparent; -webkit-text-stroke:1px rgba(248,250,252,0.5); }
    .wa-status-indicator.is-sent .check-1 { opacity:1; color:transparent; -webkit-text-stroke:1px rgba(248,250,252,0.9); }
    .wa-status-indicator.is-delivered .wa-check { opacity:1; color:#f8fafc; -webkit-text-stroke:0; }
    .wa-status-indicator.is-read .wa-check { opacity:1; color:#22c55e; -webkit-text-stroke:0; }
    .wa-status-indicator.is-error .wa-check { opacity:1; color:#ef4444; -webkit-text-stroke:0; }
    .wa-audio-recorder { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px; }
    .wa-audio-recorder [data-audio-status] { flex:1 1 100%; margin-top:4px; }

    .wa-bubble footer { margin-top:8px; display:flex; flex-wrap:wrap; gap:6px; font-size:0.75rem; color:var(--muted); align-items:center; }

    .wa-message-form textarea, .wa-message-form input, .wa-message-form select { width:100%; border-radius:14px; border:1px solid var(--border); background:rgba(15,23,42,0.55); color:var(--text); padding:12px; font-size:0.95rem; }

    .wa-message-form textarea { resize:vertical; }
    .wa-template-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:6px 0 10px; }
    .wa-template-preview { display:flex; align-items:center; gap:12px; border:1px dashed rgba(148,163,184,0.35); border-radius:14px; padding:10px 12px; background:rgba(15,23,42,0.35); margin:8px 0; }
    .wa-template-preview__thumb { width:56px; height:56px; border-radius:12px; border:1px solid rgba(148,163,184,0.25); background:rgba(2,6,23,0.6); display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .wa-template-preview__thumb img { max-width:100%; max-height:100%; object-fit:contain; image-rendering:pixelated; }
    .wa-template-preview__info { flex:1; min-width:0; }
    .wa-template-preview__info strong { display:block; margin-bottom:2px; }
    .wa-template-modal { position:fixed; inset:0; z-index:1200; display:flex; align-items:center; justify-content:center; padding:24px; }
    .wa-template-modal[hidden] { display:none; }
    .wa-template-modal__overlay { position:absolute; inset:0; background:rgba(2,6,23,0.82); backdrop-filter:blur(6px); }
    .wa-template-modal__card { position:relative; width:100%; max-width:560px; background:rgba(10,18,38,0.96); border:1px solid rgba(148,163,184,0.3); border-radius:20px; padding:22px; box-shadow:0 35px 90px rgba(2,6,23,0.6); display:flex; flex-direction:column; gap:14px; z-index:2; }
    .wa-template-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); }
    .wa-template-card { border:1px solid rgba(59,130,246,0.35); border-radius:14px; padding:10px; background:rgba(15,23,42,0.65); color:var(--text); display:flex; flex-direction:column; gap:6px; cursor:pointer; transition:border 0.2s ease, transform 0.2s ease; text-align:center; }
    .wa-template-card:hover { border-color:rgba(59,130,246,0.8); transform:translateY(-2px); }
    .wa-template-card img { width:72px; height:72px; margin:0 auto 4px; object-fit:contain; image-rendering:pixelated; background:#fff; border-radius:12px; padding:6px; }
    .wa-template-modal__actions { display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
    .wa-template-card small { color:var(--muted); font-size:0.8rem; }

    [data-queue-extra] { display:none; }

    [data-queue-extra].is-visible { display:block; }

    .wa-composer-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }

    .wa-feedback { color:var(--muted); font-size:0.8rem; display:block; margin-top:6px; }
    .wa-queue-quick { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
    .wa-queue-quick button { border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.6); color:var(--text); border-radius:999px; padding:6px 14px; font-size:0.85rem; cursor:pointer; transition:border 0.2s ease, background 0.2s ease; }
    .wa-queue-quick button:hover { border-color:rgba(56,189,248,0.7); background:rgba(56,189,248,0.15); }

    .wa-definition { display:grid; gap:8px; margin:0 0 16px; }

    .wa-definition div { display:flex; justify-content:space-between; gap:10px; }

    .wa-definition dt { font-weight:600; color:var(--muted); }

    .wa-definition dd { margin:0; }

    .wa-tag-chips { display:flex; flex-wrap:wrap; gap:6px; }

    .wa-inline-form { display:flex; flex-direction:column; gap:10px; }

    .wa-queue-info { display:flex; flex-wrap:wrap; gap:10px; background:rgba(15,23,42,0.45); border:1px solid rgba(148,163,184,0.3); border-radius:12px; padding:12px; font-size:0.85rem; color:var(--muted); }

    .wa-status-buttons { display:flex; flex-wrap:wrap; gap:10px; }

    .wa-admin-zone { margin-top:32px; }

    .wa-admin-grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); }

    .wa-admin-card { border:1px solid rgba(82,101,143,0.4); border-radius:18px; padding:18px; background:rgba(11,19,38,0.85); }

    .wa-alt-qr { margin-top:12px; border:1px dashed rgba(56,189,248,0.4); padding:12px; border-radius:14px; }

    .wa-alt-meta { list-style:none; margin:12px 0 0; padding:0; font-size:0.85rem; color:var(--muted); }

    .wa-alt-meta li { display:flex; justify-content:space-between; gap:8px; padding:4px 0; border-bottom:1px dashed rgba(148,163,184,0.25); }

    .wa-alt-meta li:last-child { border-bottom:none; }

    .wa-alt-meta strong { color:var(--text); font-weight:600; }

    .wa-pane--conversation .wa-card { height:100%; }

    .wa-context-toggle { display:inline-flex; align-items:center; gap:6px; }

    .wa-context-overlay { display:none; }

    .wa-context-overlay.is-visible { display:block; position:fixed; inset:0; background:rgba(2,6,23,0.65); backdrop-filter:blur(2px); z-index:55; }

    .wa-pane--context[data-collapsed="true"] { display:none; }

    .wa-pane--context[data-collapsed="true"].is-visible { display:flex; position:fixed; top:40px; right:40px; width:min(420px, calc(100% - 60px)); max-height:calc(100vh - 80px); overflow:auto; z-index:60; background:rgba(15,23,42,0.96); padding:18px; border-radius:18px; box-shadow:0 35px 60px rgba(0,0,0,0.45); }

    .wa-context-close { display:none; }

    .wa-pane--context.is-modal .wa-context-close { display:flex; justify-content:flex-end; margin-bottom:8px; }

    .wa-pane--context.is-modal .wa-context-close button { min-width:0; }

    .wa-client-popover { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:80; }

    .wa-client-popover.is-visible { display:flex; }

    .wa-client-popover__overlay { position:absolute; inset:0; background:rgba(2,6,23,0.7); backdrop-filter:blur(2px); }

    .wa-client-popover__card { position:relative; z-index:1; background:rgba(15,23,42,0.96); border-radius:18px; border:1px solid rgba(148,163,184,0.35); padding:20px; width:min(420px, calc(100% - 40px)); color:var(--text); box-shadow:0 35px 60px rgba(0,0,0,0.45); display:flex; flex-direction:column; gap:12px; }

    .wa-client-popover__card h4 { margin:0; font-size:1.15rem; }

    .wa-client-popover__details { margin:0; color:var(--muted); line-height:1.4; }

    .wa-client-popover__actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }

    .wa-client-popover__actions a { text-decoration:none; }

    .wa-nav-actions { display:flex; align-items:center; gap:10px; }

    .wa-new-thread-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:90; padding:20px; }

    .wa-new-thread-modal.is-visible { display:flex; }

    .wa-new-thread__overlay { position:absolute; inset:0; background:rgba(2,6,23,0.7); backdrop-filter:blur(2px); }

    .wa-new-thread__card { position:relative; z-index:1; background:rgba(15,23,42,0.96); border-radius:18px; border:1px solid rgba(148,163,184,0.35); padding:20px; width:min(480px, 100%); color:var(--text); box-shadow:0 35px 60px rgba(0,0,0,0.45); display:flex; flex-direction:column; gap:12px; }

    .wa-new-thread__card h4 { margin:0; font-size:1.2rem; }

    .wa-new-thread__form { display:flex; flex-direction:column; gap:12px; }

    .wa-new-thread__actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }

    @media (max-width:1280px) {

        .wa-pane--context[data-collapsed="true"].is-visible { top:30px; right:20px; left:20px; width:auto; }

    }

    @media (max-width:1280px) {

        .wa-layout { grid-template-columns:1fr; }

        .wa-thread-list { max-height:unset; }

        .wa-message-scroll { max-height:60vh; }

        .wa-panel-scroll { max-height:unset; }

        .wa-tabs { grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }

    }

    @media (max-width:768px) {

        .wa-tabs { grid-template-columns:1fr; }

        .wa-top-nav { padding:12px; }

    }

</style>

<?php if (!$conversationOnly): ?>

<nav class="wa-top-nav" aria-label="Filas de atendimento">

    <div class="wa-tabs" role="tablist">

        <?php foreach ($panelConfig as $panelKey => $panelData): ?>

            <?php

                $isActiveTab = $panelKey === $defaultPanel;

                $totalThreads = (int)($panelData['display_count'] ?? count($panelData['threads']));

                $unreadThreads = $countUnreadThreads($panelData['threads']);

                $panelParams = ['panel' => $panelKey];
                if ($activeThreadId > 0) {
                    $panelParams['thread'] = $activeThreadId;
                }
                $panelUrl = $buildWhatsappUrl($panelParams);
            ?>

            <a id="wa-tab-<?= $panelKey; ?>" class="wa-tab <?= $isActiveTab ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8'); ?>" data-tab-target="<?= $panelKey; ?>" role="tab" aria-selected="<?= $isActiveTab ? 'true' : 'false'; ?>" aria-controls="wa-panel-<?= $panelKey; ?>">
                <span class="wa-tab-label"><?= htmlspecialchars($panelData['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="wa-tab-count"><?= $totalThreads; ?></span>
                <?php if ($unreadThreads > 0): ?>
                    <span class="wa-tab-unread">+<?= $unreadThreads; ?></span>
                <?php endif; ?>
            </a>

        <?php endforeach; ?>

    </div>

    <div class="wa-nav-actions">
        <span class="wa-chip">Canal: <?= htmlspecialchars($currentChannelLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" class="ghost" data-new-thread-toggle>Nova conversa</button>
        <a class="ghost" href="<?= htmlspecialchars(url('whatsapp') . '?standalone=1', ENT_QUOTES, 'UTF-8'); ?>">Trocar canal</a>
    </div>

 </nav>

<?php endif; ?>

<?php
    $layoutClasses = 'wa-layout';
    if ($collapseContext) {
        $layoutClasses .= ' wa-layout--compact';
    }
    if ($conversationOnly) {
        $layoutClasses .= ' wa-layout--solo';
    }
?>

<div class="<?= $layoutClasses; ?>">
    <?php if (!$conversationOnly): ?>

    <aside class="wa-pane wa-pane--queues">

        <div class="wa-search-wrapper" role="search">
            <div class="wa-search-bar">
                <span class="wa-search-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7"></circle>
                        <line x1="16.5" y1="16.5" x2="21" y2="21"></line>
                    </svg>
                </span>
                <input type="search" placeholder="Buscar por nome, telefone ou mensagem" data-thread-search-input autocomplete="off" spellcheck="false">
                <button type="button" class="wa-search-clear" data-thread-search-clear hidden aria-label="Limpar busca">&times;</button>
            </div>
            <p class="wa-search-feedback" data-thread-search-feedback hidden></p>
            <div class="wa-search-actions">
                <button type="button" class="ghost" data-thread-search-apply>Aplicar filtro</button>
            </div>
            <div class="wa-search-actions">
                <button type="button" class="wa-notify-gear" data-notify-panel-toggle aria-expanded="false" aria-controls="wa-notify-panel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="2.5"></circle>
                        <path d="M19.4 13.5a1 1 0 0 0 .2-1 10.6 10.6 0 0 0-.6-1.4 1 1 0 0 0 .1-1.1l-1.1-1.9a1 1 0 0 0-1-.5 9 9 0 0 0-1.4-.6 1 1 0 0 0-.9-.8l-.3-2.1a1 1 0 0 0-1-.9h-2.2a1 1 0 0 0-1 .9l-.3 2.1a1 1 0 0 0-.9.8 9 9 0 0 0-1.4.6 1 1 0 0 0-1 .5l-1.1 1.9a1 1 0 0 0 .1 1.1A10.6 10.6 0 0 0 4.4 12a1 1 0 0 0 .2 1l.7 1.4a1 1 0 0 0 .6.5 9 9 0 0 0 .4 1.5 1 1 0 0 0 .4.7l1.7 1.3a1 1 0 0 0 1.1.1 9.6 9.6 0 0 0 1.3.6 1 1 0 0 0 .8.8l.3 2.1a1 1 0 0 0 1 .9h2.2a1 1 0 0 0 1-.9l.3-2.1a1 1 0 0 0 .8-.8 9.6 9.6 0 0 0 1.3-.6 1 1 0 0 0 1.1-.1l1.7-1.3a1 1 0 0 0 .4-.7 9 9 0 0 0 .4-1.5 1 1 0 0 0 .6-.5z"></path>
                    </svg>
                    Alertas
                </button>
                <span class="wa-notify-summary" data-notify-summary>Som ativo · Popup ativo</span>
            </div>
        </div>
        <div class="wa-notify-bar" id="wa-notify-panel" data-notify-panel role="region" aria-label="Notificações" hidden>
            <div class="wa-notify-actions">
                <button type="button" class="wa-notify-toggle" data-notify-sound-toggle aria-pressed="false">Som: Ativo</button>
                <button type="button" class="wa-notify-toggle" data-notify-popup-toggle aria-pressed="false">Popup: Ativo</button>
                <button type="button" class="ghost wa-notify-test" data-notify-test-sound>Testar som</button>
            </div>
            <div class="wa-notify-selects">
                <label class="wa-notify-select">
                    <span>Modo</span>
                    <select data-notify-sound-style>
                        <option value="voice" selected>Mensagem falada</option>
                        <option value="beep">Campainha curta</option>
                        <option value="custom">Arquivo personalizado</option>
                    </select>
                </label>
                <label class="wa-notify-select wa-notify-select--delay">
                    <span>Pausa (min)</span>
                    <select data-notify-delay>
                        <option value="0">0</option>
                        <option value="1">1</option>
                        <option value="2" selected>2</option>
                        <option value="5">5</option>
                    </select>
                </label>
            </div>
            <div class="wa-notify-sound-upload" data-notify-sound-upload hidden>
                <label class="wa-notify-select">
                    <span>Arquivo de áudio</span>
                    <input type="file" accept="audio/*" data-notify-sound-file>
                </label>
                <div class="wa-notify-upload-meta">
                    <span data-notify-sound-filename>Nenhum arquivo selecionado.</span>
                    <button type="button" class="ghost" data-notify-clear-sound>Remover</button>
                </div>
                <small class="wa-feedback" data-notify-sound-feedback>Selecione um áudio curto (até 1MB).</small>
            </div>
        </div>

        <div class="wa-panels">

            <?php foreach ($panelConfig as $panelKey => $panelData): ?>

                <?php

                    $isActivePanel = $panelKey === $defaultPanel;

                    $displayThreads = $panelData['display_threads'] ?? $panelData['threads'];

                    $displayCount = (int)($panelData['display_count'] ?? count($displayThreads));

                ?>

                <section id="wa-panel-<?= $panelKey; ?>" class="wa-panel <?= $isActivePanel ? 'is-active' : ''; ?>" data-panel="<?= $panelKey; ?>" role="tabpanel" aria-labelledby="wa-tab-<?= $panelKey; ?>" aria-hidden="<?= $isActivePanel ? 'false' : 'true'; ?>"<?= $isActivePanel ? '' : ' hidden'; ?>>

                    <div class="wa-panel-header">

                        <div>

                            <h3><?= htmlspecialchars($panelData['label'], ENT_QUOTES, 'UTF-8'); ?></h3>

                            <small class="wa-panel-description"><?= htmlspecialchars($panelData['description'], ENT_QUOTES, 'UTF-8'); ?></small>

                        </div>

                        <span class="wa-chip" data-panel-count="<?= htmlspecialchars($panelKey, ENT_QUOTES, 'UTF-8'); ?>"><?= $displayCount; ?> contatos</span>

                    </div>

                    <div class="wa-panel-body">

                        <div class="wa-panel-scroll" data-panel-list="<?= htmlspecialchars($panelKey, ENT_QUOTES, 'UTF-8'); ?>"<?= $displayThreads === [] ? ' hidden' : ''; ?>>
                            <?php if ($displayThreads !== []): ?>
                                <?php foreach ($displayThreads as $threadItem): ?>
                                    <?= $renderCompactThread($threadItem, $panelData['options']); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <p class="wa-panel-empty" data-panel-empty="<?= htmlspecialchars($panelKey, ENT_QUOTES, 'UTF-8'); ?>"<?= $displayThreads === [] ? '' : ' hidden'; ?>><?= htmlspecialchars($panelData['empty'], ENT_QUOTES, 'UTF-8'); ?></p>

                        <p class="wa-panel-empty" data-panel-search-empty hidden>Nenhum resultado para este filtro.</p>

                    </div>

                </section>

            <?php endforeach; ?>

        </div>

    </aside>

    <?php endif; ?>



    <section class="wa-pane wa-pane--conversation">

        <?php if ($activeThreadId === 0): ?>

            <article class="wa-card">

                <div class="wa-empty-state">

                    <p>Escolha uma conversa para comeÃ§ar ou puxe alguma da fila de chegada.</p>

                </div>

            </article>

        <?php else: ?>

            <article class="wa-card wa-conversation">

                <?php
                    $standaloneThreadUrl = $buildWhatsappUrl([
                        'thread' => $activeThreadId,
                        'panel' => $defaultPanel,
                        'standalone' => 1,
                        'conversation_only' => 1,
                    ]);
                ?>

                <header class="wa-card-header wa-contact-header" style="margin-bottom:16px;">

                    <?php
                        $activeContactInitials = strtoupper(mb_substr($activeContactName !== '' ? $activeContactName : 'C', 0, 2, 'UTF-8'));
                        $lastSeenTs = (int)($activeThread['last_message_at'] ?? ($activeThread['updated_at'] ?? 0));
                        $lastSeenLabel = $lastSeenTs > 0 ? $formatTimestamp($lastSeenTs) : '—';
                    ?>

                    <div class="wa-contact-identity">
                        <div class="wa-contact-main">
                            <div class="wa-contact-avatar<?= $contactProfilePhoto !== null ? ' has-photo' : ''; ?>">
                                <?php if ($contactProfilePhoto !== null): ?>
                                    <img src="<?= htmlspecialchars($contactProfilePhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto do contato" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <span><?= htmlspecialchars($activeContactInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="wa-contact-meta">
                                <h3 style="margin:0;"><?= htmlspecialchars($activeContactName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="wa-contact-row">
                                    <?php if ($activeUnreadCount > 0): ?>
                                        <span class="wa-contact-unread"><span class="wa-unread">+<?= $activeUnreadCount; ?></span></span>
                                    <?php endif; ?>
                                    <?php if ($activeContactPhone !== ''): ?>
                                        <span class="wa-contact-phone" data-contact-phone-display><?= htmlspecialchars($activeContactPhone, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="wa-contact-status" aria-label="Última atividade">
                                    <span class="wa-status-dot"></span>
                                    <small><?= htmlspecialchars('Última atividade: ' . $lastSeenLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wa-contact-actions">

                        <?php if ($isContactBlocked): ?>
                            <span class="wa-chip wa-chip--danger" data-blocked-badge>Bloqueado</span>
                        <?php else: ?>
                            <span class="wa-chip wa-chip--danger" data-blocked-badge hidden>Bloqueado</span>
                        <?php endif; ?>

                        <?php if (($activeContact['id'] ?? 0) > 0): ?>

                            <button class="ghost" type="button" data-edit-contact-phone
                                data-contact-id="<?= (int)($activeContact['id'] ?? 0); ?>"
                                data-contact-name="<?= htmlspecialchars($activeContactName, ENT_QUOTES, 'UTF-8'); ?>"
                                data-contact-phone="<?= htmlspecialchars($activeContactPhone, ENT_QUOTES, 'UTF-8'); ?>">
                                Editar contato
                            </button>

                            <?php if ($registerContactEnabled): ?>
                                <button class="ghost" type="button" data-register-contact-open
                                    data-thread-id="<?= (int)$activeThreadId; ?>"
                                    data-contact-id="<?= (int)($activeContact['id'] ?? 0); ?>"
                                    data-contact-name="<?= htmlspecialchars($activeContactName, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-suggest-phone="<?= htmlspecialchars($altPhoneSuggestion, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-suggest-cpf="<?= htmlspecialchars($registerContactCpf, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-hide-on-success="1">
                                    Cadastrar contato
                                </button>
                            <?php endif; ?>

                            <button class="ghost" type="button"
                                id="wa-contact-profile-toggle"
                                aria-expanded="false"
                                data-contact-profile-name="<?= htmlspecialchars($contactProfileName, ENT_QUOTES, 'UTF-8'); ?>"
                                data-contact-phone="<?= htmlspecialchars($activeContactPhone, ENT_QUOTES, 'UTF-8'); ?>"
                                data-contact-photo="<?= $contactProfilePhoto !== null ? htmlspecialchars($contactProfilePhoto, ENT_QUOTES, 'UTF-8') : ''; ?>">
                                Ver dados do contato
                            </button>

                            <button class="ghost danger" type="button"
                                data-block-contact
                                data-contact-id="<?= (int)($activeContact['id'] ?? 0); ?>"
                                data-blocked="<?= $isContactBlocked ? '1' : '0'; ?>">
                                <?= $isContactBlocked ? 'Desbloquear contato' : 'Bloquear contato'; ?>
                            </button>

                        <?php endif; ?>

                        <?php if ($activeClientUrl !== null): ?>

                            <a class="ghost" href="<?= htmlspecialchars($activeClientUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver no CRM</a>

                        <?php endif; ?>

                        <a class="ghost" href="<?= htmlspecialchars($standaloneThreadUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Abrir em nova aba</a>

                    </div>

                </header>

                <div id="wa-contact-profile-card" class="wa-card" style="display:none;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <?php if ($contactProfilePhoto !== null): ?>
                            <img src="<?= htmlspecialchars($contactProfilePhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto do contato" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:1px solid rgba(148,163,184,0.25);" />
                        <?php else: ?>
                            <div style="width:54px;height:54px;border-radius:50%;background:rgba(148,163,184,0.2);display:flex;align-items:center;justify-content:center;color:#e2e8f0;font-weight:700;font-size:1.1rem;">
                                <?= htmlspecialchars(strtoupper(mb_substr($contactProfileName !== '' ? $contactProfileName : 'C', 0, 1, 'UTF-8')), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
                            <strong style="color:var(--text);font-size:1rem;">
                                <?= $contactProfileName !== '' ? htmlspecialchars($contactProfileName, ENT_QUOTES, 'UTF-8') : 'Contato WhatsApp'; ?>
                            </strong>
                            <?php if ($activeContactPhone !== ''): ?>
                                <span style="color:var(--muted);font-size:0.92rem;">Telefone: <?= htmlspecialchars($activeContactPhone, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($contactRawFrom !== ''): ?>
                                <span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Origem: <?= htmlspecialchars($contactRawFrom, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($contactNormalizedFrom !== '' && $contactNormalizedFrom !== preg_replace('/\D+/', '', $activeContactPhone)): ?>
                                <span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Normalizado: <?= htmlspecialchars(format_phone($contactNormalizedFrom), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($contactGatewaySource !== ''): ?>
                                <span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Gateway: <?= htmlspecialchars($contactGatewaySource, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($contactGatewayCapturedAt > 0): ?>
                                <span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Capturado: <?= htmlspecialchars($formatTimestamp($contactGatewayCapturedAt), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
                        <?php if (!empty($contactMetadata['email'])): ?>
                            <div><strong style="color:var(--muted);font-size:0.82rem;">E-mail</strong><div><?= htmlspecialchars((string)$contactMetadata['email'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($contactMetadata['cpf'])): ?>
                            <div><strong style="color:var(--muted);font-size:0.82rem;">Documento</strong><div><?= htmlspecialchars((string)$contactMetadata['cpf'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($contactMetadata['tags']) && is_array($contactMetadata['tags'])): ?>
                            <div><strong style="color:var(--muted);font-size:0.82rem;">Tags</strong><div><?= htmlspecialchars(implode(', ', $contactMetadata['tags']), ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($contactMetadata['gateway_snapshot']['meta_message_id'])): ?>
                            <div><strong style="color:var(--muted);font-size:0.82rem;">Ult. meta_message_id</strong><div><?= htmlspecialchars((string)$contactMetadata['gateway_snapshot']['meta_message_id'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php $isClosedThread = strtolower((string)($activeThread['status'] ?? 'open')) === 'closed'; ?>

                <div class="wa-quick-actions">

                    <div class="wa-quick-row">
                        <label for="wa-move-queue-select">Mover para</label>
                        <form id="wa-move-queue-form" class="wa-inline-form" autocomplete="off">
                            <input type="hidden" name="thread_id" value="<?= (int)$activeThreadId; ?>">
                            <select id="wa-move-queue-select" name="queue" data-move-queue>
                                <option value="arrival" data-panel="entrada">Entrada</option>
                                <option value="arrival" data-panel="atendimento">Atendimento</option>
                                <option value="partner" data-panel="parceiros">Parceiros</option>
                                <option value="reminder" data-panel="lembrete">Lembrete</option>
                                <option value="scheduled" data-panel="agendamento">Agendamento</option>
                                <?php if ($canManage): ?>
                                    <option value="groups" data-panel="grupos">Grupo</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" class="ghost" data-move-queue-submit>Aplicar</button>
                            <small class="wa-feedback" data-move-queue-feedback></small>
                        </form>
                    </div>

                    <div class="wa-quick-row">
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <button type="button" class="ghost" data-status="<?= $isClosedThread ? 'open' : 'closed'; ?>" <?= $activeThreadId === 0 ? 'disabled' : ''; ?>>
                                <?= $isClosedThread ? 'Reabrir conversa' : 'Concluir e mover para Concluídos'; ?>
                            </button>
                            <small class="wa-feedback">Esta ação fecha a conversa e envia para o painel Concluídos.</small>
                        </div>
                    </div>

                </div>

                <div class="wa-alert" data-blocked-banner <?= $isContactBlocked ? '' : 'hidden'; ?>>
                    <strong>Número bloqueado.</strong> Envios são barrados e mensagens recebidas são ignoradas até desbloquear.
                </div>

                <?php
                    $conversationLineLabel = '';
                    $conversationLineParts = [];
                    if (!empty($activeOrigin['label'])) {
                        $conversationLineParts[] = trim((string)$activeOrigin['label']);
                    } elseif (!empty($activeThread['line_label'])) {
                        $conversationLineParts[] = trim((string)$activeThread['line_label']);
                    }
                    if ($conversationLineParts === [] && !empty($activeOrigin['slug'])) {
                        $conversationLineParts[] = strtoupper((string)$activeOrigin['slug']);
                    }
                    $conversationLineLabel = trim(implode(' · ', array_filter($conversationLineParts)));
                ?>

                <?php
                    if ($messages !== []) {
                        usort($messages, static function (array $a, array $b): int {
                            $aSent = (int)($a['sent_at'] ?? 0);
                            $bSent = (int)($b['sent_at'] ?? 0);

                            if ($aSent !== $bSent) {
                                return $aSent <=> $bSent; // oldest first
                            }

                            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
                        });
                    }
                ?>

                <div class="wa-message-scroll" id="wa-messages" data-last-message-id="<?= (int)$lastMessageId; ?>" data-first-message-id="<?= (int)$firstMessageId; ?>">

                    <button type="button" class="wa-load-more" data-load-more hidden>Carregar mais</button>

                    <?php if ($messages === []): ?>

                        <p class="wa-empty">Sem mensagens registradas.</p>

                    <?php else: ?>

                        <?php foreach ($messages as $message): ?>

                            <?php

                                $direction = (string)($message['direction'] ?? 'incoming');

                                $bubbleClass = 'wa-bubble';

                                if ($direction === 'outgoing') {

                                    $bubbleClass .= ' is-outgoing';

                                } elseif ($direction === 'internal') {

                                    $bubbleClass .= ' is-internal';

                                } else {

                                    $bubbleClass .= ' is-incoming';

                                }

                                $metaRaw = $message['metadata'] ?? null;

                                $meta = [];

                                if (is_array($metaRaw)) {

                                    $meta = $metaRaw;

                                } elseif (is_string($metaRaw) && trim($metaRaw) !== '') {

                                    $decoded = json_decode($metaRaw, true);

                                    if (is_array($decoded)) {

                                        $meta = $decoded;

                                    }

                                }

                                $actorId = isset($meta['actor_id']) ? (int)$meta['actor_id'] : 0;
                                $actorRecord = $actorId > 0 && isset($agentsById[$actorId]) ? $agentsById[$actorId] : null;
                                $preferredActorLabel = null;
                                if ($actorRecord !== null) {
                                    $preferredActorLabel = trim((string)($actorRecord['display_label'] ?? $actorRecord['chat_display_name'] ?? ''));
                                    if ($preferredActorLabel === '') {
                                        $preferredActorLabel = null;
                                    }
                                }
                                $rawActorName = null;
                                if ($actorRecord !== null) {
                                    $rawActorName = $actorRecord['name'] ?? null;
                                }
                                if ($rawActorName === null) {
                                    $rawActorName = $meta['actor_name'] ?? null;
                                }
                                if (!is_string($rawActorName) || trim($rawActorName) === '') {
                                    $rawActorName = 'Voce';
                                }
                                $rawActorIdentifier = $actorRecord['chat_identifier'] ?? ($meta['actor_identifier'] ?? null);
                                $rawActorIdentifier = is_string($rawActorIdentifier) ? trim($rawActorIdentifier) : '';
                                if ($preferredActorLabel !== null) {
                                    $actorLabel = $preferredActorLabel;
                                } else {
                                    $actorLabel = trim(($rawActorIdentifier !== '' ? $rawActorIdentifier . ' - ' : '') . $rawActorName);
                                }
                                if ($actorLabel === '') {
                                    $actorLabel = 'Voce';
                                }

                                $media = isset($meta['media']) && is_array($meta['media']) ? $meta['media'] : null;
                                $mediaUrl = $media !== null && !empty($message['id'])
                                    ? url('whatsapp/media/' . (int)$message['id'])
                                    : null;
                                if ($media !== null && !empty($media['url'])) {
                                    $mediaUrl = (string)$media['url'];
                                }
                                $mediaDownloadUrl = null;
                                if ($media !== null && !empty($media['download_url'])) {
                                    $mediaDownloadUrl = (string)$media['download_url'];
                                } elseif ($mediaUrl !== null) {
                                    $mediaDownloadUrl = $mediaUrl . '?download=1';
                                }
                                $mediaType = $media !== null ? strtolower((string)($media['type'] ?? 'document')) : null;
                                $mediaName = null;
                                $mediaSizeLabel = null;
                                if ($media !== null) {
                                    $mediaName = $media['original_name'] ?? null;
                                    if ($mediaName === null && !empty($media['path'])) {
                                        $mediaName = basename((string)$media['path']);
                                    }
                                    if ($mediaName === null && $mediaType !== null) {
                                        $mediaName = strtoupper($mediaType);
                                    }
                                    if (isset($media['size']) && is_numeric($media['size'])) {
                                        $mediaSizeLabel = $formatFileSize((int)$media['size']);
                                    }
                                }
                                $contentText = (string)($message['content'] ?? '');
                                $hideContent = $media !== null && preg_match('/^\[[^\]]+\]$/u', trim($contentText)) === 1;

                                $statusValue = strtolower((string)($message['status'] ?? ''));
                                if ($statusValue === '' && $direction === 'incoming') {
                                    $statusValue = 'delivered';
                                }
                                $statusTimestamp = isset($message['sent_at']) ? (int)$message['sent_at'] : 0;
                                $statusMap = [
                                    'queued' => ['class' => 'is-pending', 'label' => 'Na fila'],
                                    'pending' => ['class' => 'is-pending', 'label' => 'Processando'],
                                    'saving' => ['class' => 'is-pending', 'label' => 'Registrando'],
                                    'saved' => ['class' => 'is-pending', 'label' => 'Registrado'],
                                    'processing' => ['class' => 'is-pending', 'label' => 'Processando'],
                                    'async' => ['class' => 'is-pending', 'label' => 'Processando'],
                                    'background' => ['class' => 'is-pending', 'label' => 'Processando'],
                                    'retrying' => ['class' => 'is-pending', 'label' => 'Reenviando'],
                                    'requeued' => ['class' => 'is-pending', 'label' => 'Reenfileirado'],
                                    'sent' => ['class' => 'is-sent', 'label' => 'Enviado'],
                                    'delivered' => ['class' => 'is-delivered', 'label' => 'Recebido'],
                                    'imported' => ['class' => 'is-delivered', 'label' => 'Registrado'],
                                    'read' => ['class' => 'is-read', 'label' => 'Lido'],
                                    'error' => ['class' => 'is-error', 'label' => 'Erro ao enviar'],
                                    'failed' => ['class' => 'is-error', 'label' => 'Erro ao enviar'],
                                ];
                                $statusMeta = $statusMap[$statusValue] ?? ['class' => 'is-pending', 'label' => 'Processando'];
                                $statusTooltip = $statusMeta['label'];
                                if ($statusTimestamp > 0) {
                                    $statusTooltip .= ' • ' . $formatTimestamp($statusTimestamp);
                                }

                            ?>

                            <div class="<?= $bubbleClass; ?>" data-message-id="<?= (int)($message['id'] ?? 0); ?>">

                                <?php if ($direction === 'outgoing' && $actorLabel !== ''): ?>

                                    <div class="wa-message-author"><strong><em><?= htmlspecialchars($actorLabel, ENT_QUOTES, 'UTF-8'); ?></em></strong></div>

                                <?php endif; ?>

                                <?php if ($mediaUrl !== null): ?>

                                    <div class="wa-media-block">

                                        <?php if ($mediaType === 'image'): ?>

                                            <a href="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">

                                                <img src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($mediaName ?? 'Arquivo de mídia', ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">

                                            </a>

                                        <?php elseif ($mediaType === 'audio'): ?>

                                            <audio controls src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8'); ?>"></audio>

                                        <?php elseif ($mediaType === 'video'): ?>

                                            <video controls src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8'); ?>"></video>

                                        <?php else: ?>

                                            <a class="wa-media-download" href="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">

                                                <span><?= htmlspecialchars($mediaName ?? 'Arquivo', ENT_QUOTES, 'UTF-8'); ?></span>

                                                <?php if ($mediaSizeLabel !== null): ?>

                                                    <small><?= htmlspecialchars($mediaSizeLabel, ENT_QUOTES, 'UTF-8'); ?></small>

                                                <?php endif; ?>

                                            </a>

                                        <?php endif; ?>

                                    </div>

                                    <?php if ($mediaDownloadUrl !== null): ?>

                                        <div class="wa-media-actions">

                                            <a href="<?= htmlspecialchars($mediaDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Baixar arquivo</a>

                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>

                                <?php if (!$hideContent): ?>

                                    <div class="wa-message-body"><?= nl2br(htmlspecialchars($contentText, ENT_QUOTES, 'UTF-8')); ?></div>

                                <?php endif; ?>

                                <footer>

                                    <?php if ($direction === 'internal'): ?>

                                        <strong><?= htmlspecialchars((string)($meta['actor_name'] ?? 'Nota interna'), ENT_QUOTES, 'UTF-8'); ?></strong>

                                        <?php if (!empty($meta['mentions']) && is_array($meta['mentions'])): ?>

                                            <?php foreach ($meta['mentions'] as $mention): ?>

                                                <span class="wa-chip">@<?= htmlspecialchars((string)($mention['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <?php if ($direction === 'incoming'): ?>
                                            <?= htmlspecialchars('Cliente', ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>

                                        <?php if ($direction !== 'internal' && $conversationLineLabel !== ''): ?>
                                            <span class="wa-message-line"><?= htmlspecialchars($conversationLineLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                    <span class="wa-message-time"><?= htmlspecialchars($formatTimestamp((int)($message['sent_at'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></span>

                                    <?php if ($direction === 'outgoing'): ?>
                                        <span class="wa-status-indicator <?= $statusMeta['class']; ?>" title="<?= htmlspecialchars($statusTooltip, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="wa-check check-1">✓</span>
                                            <span class="wa-check check-2">✓</span>
                                        </span>
                                    <?php endif; ?>

                                </footer>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </article>



            <article class="wa-card">

                <form class="wa-message-form" id="wa-message-form" enctype="multipart/form-data" method="post" action="<?= htmlspecialchars(url('whatsapp/send-message'), ENT_QUOTES, 'UTF-8'); ?>">

                    <input type="hidden" name="_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="thread_id" value="<?= $activeThreadId; ?>">
                    <input type="hidden" name="template_kind" value="" data-template-kind>
                    <input type="hidden" name="template_key" value="" data-template-key>

                    <label class="wa-form-field">

                        <span>Mensagem para o cliente</span>

                        <textarea name="message" rows="4" placeholder="Escreva com o contexto do cliente"></textarea>

                    </label>

<?php if ($stickersAvailable): ?>
                    <div class="wa-template-actions">
                        <button type="button" class="ghost" data-template-open="stickers" <?= $threadSupportsTemplates ? '' : 'disabled'; ?>>Enviar figurinha</button>
                        <?php if (!$threadSupportsTemplates): ?>
                            <small class="wa-feedback">Disponível apenas nas linhas conectadas ao gateway alternativo.</small>
                        <?php endif; ?>
                    </div>
<?php endif; ?>

                    <label class="wa-form-field">

                        <span>Anexo (imagem, áudio ou vídeo)</span>

                        <input type="file" name="media" accept="image/*,video/*,audio/*,.pdf" data-message-media>

                    </label>

                    <div class="wa-audio-recorder" data-audio-controls>
                        <button type="button" class="ghost" data-audio-record>Gravar áudio</button>
                        <button type="button" class="ghost danger" data-audio-cancel hidden>Cancelar</button>
                        <small class="wa-feedback" data-audio-status></small>
                    </div>

                    <div class="wa-media-preview" data-media-preview hidden>

                        <div>

                            <strong data-media-name></strong>

                            <small data-media-size></small>

                        </div>

                        <button type="button" class="ghost" data-media-clear>Remover</button>

                    </div>

                    <div class="wa-template-preview" data-template-preview hidden>
                        <div class="wa-template-preview__thumb">
                            <img src="" alt="Prévia da figurinha corporativa" data-template-preview-image hidden>
                        </div>
                        <div class="wa-template-preview__info">
                            <strong data-template-preview-label></strong>
                            <small data-template-preview-description></small>
                        </div>
                        <button type="button" class="ghost" data-template-clear>Remover figurinha</button>
                    </div>

                    <div class="wa-composer-actions">

                        <button type="button" class="ghost" id="wa-ai-fill">Sugerir com IA</button>

                        <button type="submit" class="primary">Enviar pelo WhatsApp</button>

                    </div>

                    <small id="wa-message-feedback" class="wa-feedback"></small>

                </form>

            </article>

        <?php endif; ?>

    </section>

<?php if ($stickersAvailable): ?>
    <div class="wa-template-modal" data-template-modal="stickers" hidden>
        <div class="wa-template-modal__overlay" data-template-close></div>
        <div class="wa-template-modal__card">
            <header class="wa-card-header">
                <div>
                    <h3>Figurinhas corporativas</h3>
                    <p class="wa-empty">Selecione uma figurinha para anexar rapidamente ao atendimento.</p>
                </div>
                <button type="button" class="ghost" data-template-close>&times;</button>
            </header>
            <div class="wa-template-grid" data-template-list="stickers">
                <?php foreach (($mediaTemplates['stickers'] ?? []) as $sticker): ?>
                    <button type="button" class="wa-template-card"
                        data-template-item
                        data-template-kind="stickers"
                        data-template-key="<?= htmlspecialchars((string)$sticker['id'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-template-label="<?= htmlspecialchars((string)$sticker['label'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-template-description="<?= htmlspecialchars((string)$sticker['description'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-template-preview="<?= htmlspecialchars((string)($sticker['preview_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        <?php if (!empty($sticker['preview_url'])): ?>
                            <img src="<?= htmlspecialchars((string)$sticker['preview_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Prévia da figurinha">
                        <?php else: ?>
                            <div class="wa-template-preview__thumb" style="width:72px;height:72px;margin:0 auto 4px;">🎨</div>
                        <?php endif; ?>
                        <strong><?= htmlspecialchars((string)$sticker['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (!empty($sticker['description'])): ?>
                            <small><?= htmlspecialchars((string)$sticker['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="wa-template-modal__actions">
                <button type="button" class="ghost danger" data-template-close>Fechar</button>
            </div>
        </div>
    </div>
<?php endif; ?>



    <?php if ($collapseContext): ?>

        <div class="wa-context-overlay" data-context-overlay></div>

    <?php endif; ?>

    <aside class="wa-pane wa-pane--context<?= $collapseContext ? ' is-modal' : ''; ?>" data-collapsed="<?= $collapseContext ? 'true' : 'false'; ?>">

        <?php if ($collapseContext): ?>

            <div class="wa-context-close">

                <button type="button" class="ghost" data-context-close>Fechar</button>

            </div>

        <?php endif; ?>

        <div class="wa-context-details" data-context-details hidden>

        <article class="wa-card">

            <div class="wa-card-header">

                <div>

                    <h3>Cliente & tags</h3>

                    <small>Identifique o contato antes de responder.</small>

                </div>

            </div>

            <?php if ($activeContact === null): ?>

                <p class="wa-empty">Selecione uma conversa para editar tags ou visualizar dados.</p>

            <?php else: ?>

                <dl class="wa-definition">

                    <div><dt>Telefone</dt><dd><?= htmlspecialchars(format_phone((string)($activeContact['phone'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></dd></div>

                    <?php if (!empty($contactMetadata['profile']['name'])): ?>

                        <div><dt>Perfil</dt><dd><?= htmlspecialchars((string)$contactMetadata['profile']['name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>

                    <?php endif; ?>

                    <?php if (!empty($activeContact['client_id'])): ?>

                        <div><dt>Cliente</dt><dd>#<?= (int)$activeContact['client_id']; ?></dd></div>

                    <?php endif; ?>

                </dl>

                <?php if ($activeClientSummary !== null): ?>
                    <?php
                        $clientLink = url('crm/clients/' . (int)($activeClientSummary['id'] ?? 0));
                        $clientTitularParts = [];
                        if (!empty($activeClientSummary['titular_name'])) {
                            $clientTitularParts[] = (string)$activeClientSummary['titular_name'];
                        }
                        if (!empty($activeClientSummary['titular_document'])) {
                            $clientTitularParts[] = (string)$activeClientSummary['titular_document'];
                        }
                        $clientTitularValue = $clientTitularParts !== [] ? implode(' - ', $clientTitularParts) : null;
                        $clientEmail = trim((string)($activeClientSummary['email'] ?? ''));
                        $clientPhoneCrm = trim((string)($activeClientSummary['phone'] ?? ''));
                        $clientWhatsappCrm = trim((string)($activeClientSummary['whatsapp'] ?? ''));
                        $clientNextFollowUp = isset($activeClientSummary['next_follow_up_at']) && (int)$activeClientSummary['next_follow_up_at'] > 0
                            ? $formatTimestamp((int)$activeClientSummary['next_follow_up_at'])
                            : null;
                        $clientCreatedAt = isset($activeClientSummary['created_at']) && (int)$activeClientSummary['created_at'] > 0
                            ? $formatTimestamp((int)$activeClientSummary['created_at'])
                            : null;
                        $clientUpdatedAt = isset($activeClientSummary['updated_at']) && (int)$activeClientSummary['updated_at'] > 0
                            ? $formatTimestamp((int)$activeClientSummary['updated_at'])
                            : null;
                        $clientExpiresAt = isset($activeClientSummary['last_certificate_expires_at']) && (int)$activeClientSummary['last_certificate_expires_at'] > 0
                            ? $formatTimestamp((int)$activeClientSummary['last_certificate_expires_at'])
                            : null;
                    ?>
                    <div class="wa-client-context">
                        <div class="wa-client-context__header">
                            <strong><?= htmlspecialchars($activeClientSummary['name'] !== '' ? (string)$activeClientSummary['name'] : ('Cliente #' . (int)$activeClientSummary['id']), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if (!empty($activeClientSummary['id'])): ?>
                                <a class="ghost" href="<?= htmlspecialchars($clientLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver ficha no CRM</a>
                            <?php endif; ?>
                        </div>
                        <div class="wa-client-chips">
                            <?php if (!empty($activeClientSummary['document'])): ?>
                                <span class="wa-chip">Documento: <?= htmlspecialchars((string)$activeClientSummary['document'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($activeClientSummary['status'])): ?>
                                <span class="wa-chip">Status: <?= htmlspecialchars((string)$activeClientSummary['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($activeClientSummary['partner'])): ?>
                                <span class="wa-chip">Parceiro: <?= htmlspecialchars((string)$activeClientSummary['partner'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                        <dl class="wa-definition wa-definition--compact">
                            <?php if ($clientTitularValue !== null): ?>
                                <div><dt>Titular</dt><dd><?= htmlspecialchars($clientTitularValue, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientEmail !== ''): ?>
                                <div><dt>E-mail</dt><dd><?= htmlspecialchars($clientEmail, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientPhoneCrm !== ''): ?>
                                <div><dt>Telefone CRM</dt><dd><?= htmlspecialchars($clientPhoneCrm, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientWhatsappCrm !== ''): ?>
                                <div><dt>WhatsApp CRM</dt><dd><?= htmlspecialchars($clientWhatsappCrm, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($activeClientSummary['last_avp_name'])): ?>
                                <div><dt>Último AVP</dt><dd><?= htmlspecialchars((string)$activeClientSummary['last_avp_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientNextFollowUp !== null && $clientNextFollowUp !== '-'): ?>
                                <div><dt>Próxima ação</dt><dd><?= htmlspecialchars($clientNextFollowUp, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientExpiresAt !== null && $clientExpiresAt !== '-'): ?>
                                <div><dt>Vencimento</dt><dd><?= htmlspecialchars($clientExpiresAt, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientCreatedAt !== null && $clientCreatedAt !== '-'): ?>
                                <div><dt>Criado em</dt><dd><?= htmlspecialchars($clientCreatedAt, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($clientUpdatedAt !== null && $clientUpdatedAt !== '-'): ?>
                                <div><dt>Atualizado</dt><dd><?= htmlspecialchars($clientUpdatedAt, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>

                <div class="wa-tag-chips">

                    <?php if ($contactTags === []): ?>

                        <span class="wa-empty">Sem tags cadastradas.</span>

                    <?php else: ?>

                        <?php foreach ($contactTags as $tag): ?>

                            <span class="wa-chip"><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <form id="wa-tags-form" class="wa-inline-form" style="margin-top:12px;">

                    <input type="hidden" name="contact_id" value="<?= (int)($activeContact['id'] ?? 0); ?>">

                    <label class="wa-form-field">

                        <span>Atualizar tags (separe por vÃ­rgula)</span>

                        <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $contactTags), ENT_QUOTES, 'UTF-8'); ?>" placeholder="vip, prioridade, follow-up">

                    </label>

                    <button type="submit" class="ghost">Salvar tags</button>

                    <small id="wa-tags-feedback" class="wa-feedback"></small>

                </form>

            <?php endif; ?>

        </article>



        <article class="wa-card">

            <div class="wa-card-header">

                <div>

                    <h3>Fila & distribuiÃ§Ã£o</h3>

                    <small>Controle rÃ¡pido da conversa selecionada.</small>

                </div>

            </div>

            <?php if ($activeThreadId === 0): ?>

                <p class="wa-empty">Escolha uma conversa para habilitar os controles.</p>

            <?php else: ?>

                <div class="wa-queue-info" style="margin-bottom:12px;">

                    <span>Status: <?= htmlspecialchars((string)($activeThread['status'] ?? 'open'), ENT_QUOTES, 'UTF-8'); ?></span>

                    <span>Fila: <?= htmlspecialchars($currentQueueLabel, ENT_QUOTES, 'UTF-8'); ?></span>

                    <?php if ($scheduledLocalValue !== ''): ?>

                        <span>Agendado para <?= htmlspecialchars($formatTimestamp((int)($activeThread['scheduled_for'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></span>

                    <?php endif; ?>

                </div>

                <div class="wa-status-buttons" style="margin-bottom:12px;">
                    <?php if (!$isClosedThread): ?>
                        <button type="button" class="primary" data-status="closed">Concluir conversa</button>
                    <?php else: ?>
                        <button type="button" class="ghost" data-status="open">Reabrir conversa</button>
                    <?php endif; ?>
                </div>

                <?php if ($canTransferThread): ?>

                    <form id="wa-assign-user" class="wa-inline-form" style="margin-top:12px;">

                        <label class="wa-form-field">

                            <span>Direcionar para usuÃ¡rio</span>

                            <select name="user_id">

                                <option value="">Escolher...</option>

                                <?php foreach ($agentsDirectory as $agent): ?>

                                    <option value="<?= (int)$agent['id']; ?>" <?= (int)($activeThread['assigned_user_id'] ?? 0) === (int)$agent['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string)($agent['display_label'] ?? $agent['name']), ENT_QUOTES, 'UTF-8'); ?></option>

                                <?php endforeach; ?>

                            </select>

                        </label>

                        <button type="submit" class="primary">Direcionar</button>

                    </form>

                <?php endif; ?>

                <form id="wa-queue-form" class="wa-message-form" style="margin-top:14px;">

                    <input type="hidden" name="thread_id" value="<?= $activeThreadId; ?>">

                    <label class="wa-form-field">

                        <span>Atualizar fila</span>

                        <select name="queue" data-queue-select>

                            <option value="arrival" <?= $currentQueueKey === 'arrival' ? 'selected' : ''; ?>>Fila de chegada</option>

                            <option value="scheduled" <?= $currentQueueKey === 'scheduled' ? 'selected' : ''; ?>>Agendado</option>

                            <option value="partner" <?= $currentQueueKey === 'partner' ? 'selected' : ''; ?>>Parceiros / Indicadores</option>

                            <option value="reminder" <?= $currentQueueKey === 'reminder' ? 'selected' : ''; ?>>Lembrete</option>

                        </select>

                    </label>

                    <div class="wa-queue-quick">
                        <button type="button" class="ghost" data-queue-quick="partner">Enviar para parceiro</button>
                        <button type="button" class="ghost" data-queue-quick="reminder">Mover para lembrete</button>
                        <button type="button" class="ghost" data-queue-quick="scheduled">Agendar follow-up</button>
                    </div>

                    <div class="wa-form-field" data-queue-extra="scheduled">

                        <span>Data/hora do agendamento</span>

                        <input type="datetime-local" name="scheduled_for" value="<?= htmlspecialchars($scheduledLocalValue, ENT_QUOTES, 'UTF-8'); ?>">

                    </div>

                    <div class="wa-form-field" data-queue-extra="partner">

                        <span>Parceiro responsÃ¡vel</span>

                        <select name="partner_id">

                            <option value="">Selecionar parceiro...</option>

                            <?php foreach ($partnersDirectory as $partner): ?>

                                <option value="<?= (int)$partner['id']; ?>" <?= (int)($activeThread['partner_id'] ?? 0) === (int)$partner['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string)$partner['name'], ENT_QUOTES, 'UTF-8'); ?></option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="wa-form-field" data-queue-extra="partner">

                        <span>ResponsÃ¡vel interno</span>

                        <select name="responsible_user_id">

                            <option value="">Selecione...</option>

                            <?php foreach ($agentsDirectory as $agent): ?>

                                <option value="<?= (int)$agent['id']; ?>" <?= (int)($activeThread['responsible_user_id'] ?? 0) === (int)$agent['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string)($agent['display_label'] ?? $agent['name']), ENT_QUOTES, 'UTF-8'); ?></option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <label class="wa-form-field">

                        <span>Resumo / intake</span>

                        <textarea name="intake_summary" id="wa-intake-summary" rows="3" placeholder="Notas do agente ou prÃ©-triagem IA"><?= htmlspecialchars((string)($activeThread['intake_summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

                    </label>

                    <button type="submit" class="ghost">Salvar fila</button>

                    <small id="wa-queue-feedback" class="wa-feedback"></small>

                </form>

            <?php endif; ?>

        </article>



        <article class="wa-card">

            <div class="wa-card-header">

                <div>

                    <h3>Copilot IA</h3>

                    <small>RecomendaÃ§Ãµes em portuguÃªs.</small>

                </div>

            </div>

            <?php if ($copilotProfiles !== []): ?>

                <label class="wa-form-field" style="margin-bottom:12px;">

                    <span>Agente especializado</span>

                    <select id="wa-copilot-profile" data-default-profile="<?= (int)($defaultCopilotProfileId ?? 0); ?>">

                        <?php foreach ($copilotProfiles as $profile): ?>

                            <option value="<?= (int)$profile['id']; ?>" <?= (int)$profile['id'] === (int)($defaultCopilotProfileId ?? 0) ? 'selected' : ''; ?>>

                                <?= htmlspecialchars($profile['name'] . ($profile['objective'] ? ' Â· ' . $profile['objective'] : ''), ENT_QUOTES, 'UTF-8'); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>

            <?php endif; ?>

            <div class="wa-ai-output" id="wa-ai-output" style="min-height:120px; border:1px dashed rgba(148,163,184,0.4); border-radius:16px; padding:14px; background:rgba(15,23,42,0.4);">

                <?= $activeThreadId === 0 ? 'Escolha uma conversa para solicitar uma sugestÃ£o.' : 'Clique em âSugerir com IAâ para gerar uma resposta contextual.'; ?>

            </div>

            <div class="wa-ai-actions" style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">

                <button type="button" class="ghost" data-tone="formal" data-goal="agendar_validacao">Formal -> Agendar</button>

                <button type="button" class="ghost" data-tone="direto" data-goal="renovar_certificado">Direto -> Renovar</button>

                <button type="button" class="ghost" id="wa-pretriage" <?= $activeThreadId === 0 ? 'disabled' : ''; ?>>PrÃ©-triagem automÃ¡tica</button>

            </div>

        </article>



        <article class="wa-card">

            <div class="wa-card-header">

                <div>

                    <h3>Notas internas & convites</h3>

                    <small>Converse com outros usuÃ¡rios sem o cliente ver.</small>

                </div>

            </div>

            <?php if ($activeThreadId === 0): ?>

                <p class="wa-empty">Selecione uma conversa para registrar notas.</p>

            <?php else: ?>

                <form id="wa-internal-form" class="wa-message-form">

                    <input type="hidden" name="thread_id" value="<?= $activeThreadId; ?>">

                    <label class="wa-form-field">

                        <span>Nota interna</span>

                        <textarea name="note" rows="3" placeholder="Explique o contexto ou peÃ§a ajuda."></textarea>

                    </label>

                    <label class="wa-form-field">

                        <span>Notificar usuÃ¡rios</span>

                        <select name="mentions[]" multiple size="4">

                            <?php foreach ($agentsDirectory as $agent): ?>

                                <option value="<?= (int)$agent['id']; ?>"><?= htmlspecialchars((string)($agent['display_label'] ?? $agent['name']), ENT_QUOTES, 'UTF-8'); ?></option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <button type="submit" class="ghost">Salvar nota privada</button>

                    <small id="wa-note-feedback" class="wa-feedback"></small>

                </form>

            <?php endif; ?>

        </article>

        </div>

    </aside>

</div>

<div class="wa-new-thread-modal" data-register-contact-modal aria-hidden="true">

    <div class="wa-new-thread__overlay" data-register-contact-close></div>

    <article class="wa-new-thread__card">

        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">

            <h4>Cadastrar contato</h4>

            <button type="button" class="ghost" data-register-contact-close>Fechar</button>

        </div>

        <form class="wa-new-thread__form" data-register-contact-form>

            <input type="hidden" name="thread_id" value="<?= (int)$activeThreadId; ?>">
            <input type="hidden" name="contact_id" value="<?= (int)($activeContact['id'] ?? 0); ?>">
            <input type="hidden" name="client_id" value="" data-register-client-id>

            <div class="wa-form-field">

                <span>Localizar cliente (nome, CPF, CNPJ ou razão social)</span>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" data-register-client-search placeholder="Digite parte do nome, CPF ou CNPJ" style="flex:1;min-width:220px;">
                    <button type="button" class="ghost" data-register-client-search-btn>Buscar</button>
                </div>

                <small class="wa-feedback" data-register-client-search-feedback></small>

                <div data-register-client-search-results style="margin-top:8px;display:none;border:1px solid var(--border);border-radius:12px;overflow:hidden;"></div>

            </div>

            <label class="wa-form-field">

                <span>Nome completo *</span>

                <input type="text" name="contact_name" placeholder="Nome" required>

            </label>

            <label class="wa-form-field">

                <span>Telefone *</span>

                <input type="text" name="contact_phone" placeholder="DDD + número" value="<?= htmlspecialchars($altPhoneSuggestion, ENT_QUOTES, 'UTF-8'); ?>" required>

            </label>

            <label class="wa-form-field">

                <span>CPF *</span>

                <input type="text" name="contact_cpf" placeholder="Somente números" value="<?= htmlspecialchars($registerContactCpf, ENT_QUOTES, 'UTF-8'); ?>" required>

            </label>

            <label class="wa-form-field">

                <span>Data de nascimento (opcional)</span>

                <input type="date" name="contact_birthdate">

            </label>

            <label class="wa-form-field">

                <span>E-mail (opcional)</span>

                <input type="email" name="contact_email" placeholder="contato@email.com">

            </label>

            <label class="wa-form-field">

                <span>Endereço (opcional)</span>

                <textarea name="contact_address" rows="2" placeholder="Rua, número, complemento"></textarea>

            </label>

            <small style="color:var(--muted);">CPF tenta preencher dados do CRM se houver cliente existente.</small>

            <div class="wa-new-thread__actions">

                <button type="button" class="ghost" data-register-contact-close>Cancelar</button>

                <button type="submit" class="primary">Salvar contato</button>

            </div>

            <small class="wa-feedback" data-register-contact-feedback></small>

        </form>

    </article>

</div>


<div class="wa-new-thread-modal" data-new-thread-modal aria-hidden="true">

    <div class="wa-new-thread__overlay" data-new-thread-close></div>

    <article class="wa-new-thread__card">

        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">

            <h4>Nova conversa</h4>

            <button type="button" class="ghost" data-new-thread-close>Fechar</button>

        </div>

        <form class="wa-new-thread__form" data-new-thread-form method="post" action="<?= htmlspecialchars(url('whatsapp/manual-thread'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="redirect" value="1">
            <?php if ($defaultGatewayInstance !== null): ?>
                <input type="hidden" name="gateway_instance" value="<?= htmlspecialchars($defaultGatewayInstance, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <?php if ($selectedChannel !== null): ?>
                <input type="hidden" name="channel" value="<?= htmlspecialchars($selectedChannel, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <?php if ($standaloneView): ?>
                <input type="hidden" name="standalone" value="1">
            <?php endif; ?>

            <label class="wa-form-field">

                <span>Nome</span>

                <input type="text" name="contact_name" placeholder="Contato teste">

            </label>

            <label class="wa-form-field">

                <span>Telefone</span>

                <input type="text" name="contact_phone" placeholder="11999887766" value="+55" required>

            </label>

            <label class="wa-form-field">

                <span>Mensagem inicial</span>

                <textarea name="message" rows="3" placeholder="Mensagem que será enviada" required></textarea>

            </label>

            <div class="wa-new-thread__actions">

                <button type="button" class="ghost" data-new-thread-close>Cancelar</button>

                <button type="submit" class="primary">Enviar pelo WhatsApp</button>

            </div>

            <small class="wa-feedback" data-new-thread-feedback></small>

        </form>

    </article>

</div>



<script>
(function() {
    const overflowKey = 'data-wa-new-thread-overflow';
    const modal = document.querySelector('[data-new-thread-modal]');
    const openModal = () => {
        if (!modal) return;
        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
        document.body.setAttribute(overflowKey, document.body.style.overflow || '');
        document.body.style.overflow = 'hidden';
        const firstInput = modal.querySelector('input[name="contact_name"]');
        if (firstInput) {
            firstInput.focus();
        }
    };
    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove('is-visible');
        modal.setAttribute('aria-hidden', 'true');
        const previous = document.body.getAttribute(overflowKey) || '';
        document.body.style.overflow = previous;
    };
    window.__waFallbackNewThreadOpen = openModal;
    window.__waFallbackNewThreadClose = closeModal;
    document.addEventListener('click', (event) => {
        const openBtn = event.target.closest('[data-new-thread-toggle]');
        if (openBtn) {
            event.preventDefault();
            openModal();
            return;
        }
        const closeBtn = event.target.closest('[data-new-thread-close]');
        if (closeBtn) {
            event.preventDefault();
            closeModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
})();
</script>

<div class="wa-client-popover" data-client-popover aria-hidden="true">

    <div class="wa-client-popover__overlay" data-client-popover-close></div>

    <article class="wa-client-popover__card">

        <div>

            <h4 data-client-name>Cliente</h4>

            <p class="wa-client-popover__details">

                <strong>Telefone:</strong>

                <span data-client-phone>--</span>

            </p>

            <p class="wa-client-popover__details" data-client-document-row hidden>

                <strong>Documento:</strong>

                <span data-client-document>--</span>

            </p>

            <p class="wa-client-popover__details" data-client-status-row hidden>

                <strong>Status:</strong>

                <span data-client-status>--</span>

            </p>

            <p class="wa-client-popover__details" data-client-partner-row hidden>

                <strong>Parceiro:</strong>

                <span data-client-partner>--</span>

            </p>

        </div>

        <div class="wa-client-popover__actions">

            <a class="ghost" href="#" target="_blank" rel="noopener" data-client-link>Ver ficha no CRM</a>

            <button type="button" class="ghost" data-client-popover-close>Fechar</button>

        </div>

    </article>

</div>

<div class="wa-toast-stack" data-toast-stack hidden></div>

<?php
$whatsappThreadUrlTemplate = $buildWhatsappUrl(['thread' => '__THREAD__']);
require __DIR__ . '/script.php';
?>
