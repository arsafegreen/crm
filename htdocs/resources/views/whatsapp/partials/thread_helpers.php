<?php

declare(strict_types=1);

if (!function_exists('wa_format_timestamp')) {
    function wa_format_timestamp(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '-';
        }

        $date = (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->format('d/m/y H:i');
    }
}

if (!function_exists('wa_format_datetime_local')) {
    function wa_format_datetime_local(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '';
        }

        return date('Y-m-d\TH:i', $timestamp);
    }
}

if (!function_exists('wa_digits_only')) {
    function wa_digits_only($value): string
    {
        $clean = preg_replace('/\D+/', '', (string)$value);
        return $clean !== null ? $clean : '';
    }
}

if (!function_exists('wa_build_search_index')) {
    /**
     * @param array<int,string|int|float|null> $parts
     */
    function wa_build_search_index(array $parts): string
    {
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
    }
}

if (!function_exists('wa_is_compact_panel')) {
    function wa_is_compact_panel(?string $panelKey): bool
    {
        if ($panelKey === null) {
            return false;
        }

        return in_array($panelKey, ['entrada', 'atendimento', 'parceiros', 'lembrete', 'agendamento', 'concluidos'], true);
    }
}

if (!function_exists('wa_resolve_thread_phone')) {
    /**
     * @param array<string,mixed> $thread
     */
    function wa_resolve_thread_phone(array $thread): string
    {
        $isGroup = !empty($thread['is_group']) || (($thread['chat_type'] ?? '') === 'group');
        if ($isGroup) {
            return '';
        }

        $candidates = [];

        $clientSummary = isset($thread['contact_client']) && is_array($thread['contact_client'])
            ? $thread['contact_client']
            : null;

        // Sempre prioriza o telefone do cliente vinculado, se existir.
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

        // Para canal alternativo, prioriza o telefone que veio do channel_thread_id (somente se não já retornamos cliente).
        $channelId = (string)($thread['channel_thread_id'] ?? '');
        if (str_starts_with($channelId, 'alt:')) {
            $payload = substr($channelId, 4);
            $parts = explode(':', $payload, 2);
            $altPhoneRaw = $parts[1] ?? '';
            if ($altPhoneRaw !== '') {
                $altPhoneClean = preg_replace('/@.*/', '', $altPhoneRaw) ?? $altPhoneRaw;
                $altDigits = wa_digits_only($altPhoneClean);
                if ($altDigits !== '' && !str_starts_with($altDigits, '55') && strlen($altDigits) >= 10 && strlen($altDigits) <= 13) {
                    $altDigits = '55' . $altDigits;
                }
                $formattedAlt = format_phone($altDigits !== '' ? $altDigits : $altPhoneClean);
                if ($formattedAlt !== '') {
                    $candidates[] = $formattedAlt;
                }
            }
        }

        // Depois usa o telefone que veio na conversa.
        $contactPhoneFormatted = trim((string)($thread['contact_phone_formatted'] ?? ''));
        if ($contactPhoneFormatted !== '') {
            $candidates[] = $contactPhoneFormatted;
        }

        $rawPhone = trim((string)($thread['contact_phone'] ?? ''));
        if ($rawPhone !== '') {
            $rawDigits = wa_digits_only($rawPhone);
            if ($rawDigits !== '' && !str_starts_with($rawDigits, '55') && strlen($rawDigits) >= 10 && strlen($rawDigits) <= 13) {
                $rawDigits = '55' . $rawDigits;
            }
            $formattedRaw = format_phone($rawDigits !== '' ? $rawDigits : $rawPhone);
            if ($formattedRaw !== '') {
                $candidates[] = $formattedRaw;
            }
            if ($rawDigits !== '') {
                $candidates[] = $rawDigits;
            }
        }

        $displaySecondary = trim((string)($thread['contact_display_secondary'] ?? ''));
        if ($displaySecondary !== '') {
            $candidates[] = $displaySecondary;
        }

        foreach ($candidates as $value) {
            $clean = trim((string)$value);
            if ($clean !== '' && stripos($clean, 'grupo whatsapp') === false) {
                return $clean;
            }
        }

        return '';
    }
}

if (!function_exists('wa_normalize_phone_key')) {
    function wa_normalize_phone_key($value, ?int $fallbackId = null): string
    {
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
        if ($digits === '' || $digits === null) {
            return 'thread-' . ($fallbackId !== null ? $fallbackId : uniqid('thread_', true));
        }

        return $digits;
    }
}

if (!function_exists('wa_normalize_group_key')) {
    /**
     * @param array<string,mixed> $thread
     * @return array{chat_id:string,subject:string,participant_name:string,participant_phone:string}
     */
    function wa_extract_group_metadata(array $thread): array
    {
        $rawMeta = $thread['group_metadata'] ?? [];
        if (is_string($rawMeta) && trim($rawMeta) !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                $rawMeta = $decoded;
            }
        }
        $meta = is_array($rawMeta) ? $rawMeta : [];

        $subject = trim((string)($thread['group_subject'] ?? ($meta['subject'] ?? '')));
        $chatId = trim((string)($meta['chat_id'] ?? ''));

        $participant = isset($meta['participant']) && is_array($meta['participant']) ? $meta['participant'] : [];
        $participantName = trim((string)($participant['name'] ?? ''));
        $participantPhone = trim((string)($participant['phone'] ?? ''));

        return [
            'chat_id' => $chatId,
            'subject' => $subject,
            'participant_name' => $participantName,
            'participant_phone' => $participantPhone,
        ];
    }
}

if (!function_exists('wa_normalize_group_key')) {
    /**
     * @param array<string,mixed> $thread
     */
    function wa_normalize_group_key(array $thread): string
    {
        $groupMeta = wa_extract_group_metadata($thread);
        $chatId = $groupMeta['chat_id'];
        if ($chatId !== '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($chatId, 'UTF-8'));
            if ($slug !== '') {
                return 'group-chat-' . $slug;
            }
        }

        $channelId = trim((string)($thread['channel_thread_id'] ?? ''));
        if ($channelId !== '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($channelId, 'UTF-8'));
            if ($slug !== '') {
                return 'group-channel-' . $slug;
            }
        }

        $subject = $groupMeta['subject'];
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

        return wa_normalize_phone_key($contactPhone, $thread['id'] ?? null);
    }
}

if (!function_exists('wa_group_threads_by_contact')) {
    /**
     * @param array<int,array<string,mixed>> $threads
     * @return array<int,array<string,mixed>>
     */
    function wa_group_threads_by_contact(array $threads): array
    {
        $grouped = [];
        foreach ($threads as $thread) {
            if (($thread['chat_type'] ?? '') === 'group') {
                $key = wa_normalize_group_key($thread);
            } else {
                $phoneKey = wa_normalize_phone_key($thread['contact_phone'] ?? '', $thread['id'] ?? null);
                $lineId = (int)($thread['line_id'] ?? 0);
                $channelId = (string)($thread['channel_thread_id'] ?? '');
                if (str_starts_with($channelId, 'alt:')) {
                    $key = 'alt:' . substr($channelId, 4);
                } else {
                    $key = 'line:' . $lineId . ':' . $phoneKey;
                }
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $thread;
        }

        $result = [];
        foreach ($grouped as $items) {
            usort($items, static function (array $a, array $b): int {
                $aHasClient = !empty($a['contact_client_id']);
                $bHasClient = !empty($b['contact_client_id']);
                if ($aHasClient !== $bHasClient) {
                    return $aHasClient ? -1 : 1; // prioritize items already vinculados a cliente
                }

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
    }
}

if (!function_exists('wa_count_unread_threads')) {
    /**
     * @param array<int,array<string,mixed>> $threads
     */
    function wa_count_unread_threads(array $threads): int
    {
        $total = 0;
        foreach ($threads as $thread) {
            $total += (int)($thread['unread_count'] ?? 0);
        }

        return $total;
    }
}

if (!function_exists('wa_describe_thread_origin')) {
    /**
     * @param array<string,mixed>|null $thread
     * @param array<int,array<string,mixed>> $lineById
     * @param array<string,array<string,mixed>> $altGatewayLookup
     * @param array<string,array<string,mixed>> $lineByAltSlug
     * @return array{label:string,phone:string,type:string,slug: ?string}
     */
    function wa_describe_thread_origin(
        ?array $thread,
        array $lineById,
        array $altGatewayLookup,
        array $lineByAltSlug
    ): array {
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
        $rawPhone = preg_replace('/@.*/', '', (string)($parts[1] ?? '')) ?? '';

        $result['type'] = 'alt';
        $result['slug'] = $slug !== '' ? $slug : null;
        $result['phone'] = $rawPhone !== '' ? format_phone($rawPhone) : '';

        if ($slug !== '' && isset($lineByAltSlug[$slug])) {
            $line = $lineByAltSlug[$slug];
            $result['label'] = trim((string)($line['label'] ?? ''));

            // No cabeçalho da origem, preferimos mostrar o telefone da linha configurada
            // (ex.: Movel-SafeGreen) em vez do phone do channel_thread_id, que é o contato.
            $lineDisplay = trim((string)($line['display_phone'] ?? ''));
            if ($lineDisplay !== '') {
                $result['phone'] = format_phone($lineDisplay);
            }

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
    }
}


if (!function_exists('wa_prepare_thread_view')) {
    /**
     * @param array<string,mixed> $thread
     * @param array<string,mixed> $options
     * @param array{
     *     active_thread_id?: int,
     *     queue_labels?: array<string,string>,
     *     agents_by_id?: array<int,array<string,mixed>>,
     *     build_url?: callable,
     *     line_by_id?: array<int,array<string,mixed>>,
     *     line_by_alt_slug?: array<string,array<string,mixed>>,
     *     alt_gateway_lookup?: array<string,array<string,mixed>>
     * } $context
     * @return array<string,mixed>|null
     */
    function wa_prepare_thread_view(array $thread, array $options, array $context): ?array
    {
        $threadId = (int)($thread['id'] ?? 0);
        if ($threadId <= 0) {
            return null;
        }

        $queueLabels = $context['queue_labels'] ?? [];
        $agentsById = $context['agents_by_id'] ?? [];
        $activeThreadId = (int)($context['active_thread_id'] ?? 0);
        $buildUrl = $context['build_url'] ?? null;
        $lineById = $context['line_by_id'] ?? [];
        $lineByAltSlug = $context['line_by_alt_slug'] ?? [];
        $altGatewayLookup = $context['alt_gateway_lookup'] ?? [];

        $name = trim((string)($thread['contact_name'] ?? 'Contato'));
        $phone = format_phone((string)($thread['contact_phone'] ?? ''));
        $contactPhoneDigits = wa_digits_only($thread['contact_phone'] ?? '');
        $preview = trim((string)($thread['last_message_preview'] ?? ''));
        $queue = (string)($thread['queue'] ?? 'arrival');

        $displayName = trim((string)($thread['contact_display'] ?? ''));
        $isGroupThread = !empty($thread['is_group']) || (($thread['chat_type'] ?? '') === 'group');
        $groupMeta = $isGroupThread ? wa_extract_group_metadata($thread) : ['chat_id' => '', 'subject' => '', 'participant_name' => '', 'participant_phone' => ''];
        $groupSubject = trim((string)$groupMeta['subject']);
        if ($isGroupThread && $groupSubject !== '') {
            $name = $groupSubject;
        }
        if ($displayName !== '') {
            $name = $displayName;
        }
        $phone = !$isGroupThread ? wa_resolve_thread_phone($thread) : '';

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

        $origin = wa_describe_thread_origin($thread, $lineById, $altGatewayLookup, $lineByAltSlug);
        $lineLabel = trim((string)$origin['label']);
        $lineDisplayPhone = trim((string)$origin['phone']);
        $lineChipText = trim($lineLabel . ($lineDisplayPhone !== '' ? ' · ' . $lineDisplayPhone : ''));

        $scheduledFor = isset($thread['scheduled_for']) ? (int)$thread['scheduled_for'] : null;
        $partnerName = trim((string)($thread['partner_name'] ?? ''));
        $responsibleId = (int)($thread['responsible_user_id'] ?? 0);
        if ($responsibleId > 0 && isset($agentsById[$responsibleId])) {
            $responsibleName = (string)($agentsById[$responsibleId]['display_label'] ?? $agentsById[$responsibleId]['name']);
        } else {
            $responsibleName = trim((string)($thread['responsible_name'] ?? ''));
        }
        $assignedId = (int)($thread['assigned_user_id'] ?? 0);
        $assignedName = '';
        if ($assignedId > 0 && isset($agentsById[$assignedId])) {
            $assignedName = (string)($agentsById[$assignedId]['display_label'] ?? $agentsById[$assignedId]['name']);
        }
        $unread = max(0, (int)($thread['unread_count'] ?? 0));
        $status = strtoupper((string)($thread['status'] ?? 'open'));
        $clientId = isset($thread['contact_client_id']) ? (int)$thread['contact_client_id'] : 0;
        $clientSummary = isset($thread['contact_client']) && is_array($thread['contact_client'])
            ? $thread['contact_client']
            : null;

        $panelKey = (string)($options['panel'] ?? ($thread['queue'] ?? ''));
        $isCompactPanel = wa_is_compact_panel($panelKey);

        $groupThreads = isset($thread['_group_threads']) && is_array($thread['_group_threads'])
            ? $thread['_group_threads']
            : null;
        if (($clientId <= 0 || $clientSummary === null) && $groupThreads) {
            foreach ($groupThreads as $candidateThread) {
                if ($clientSummary === null && isset($candidateThread['contact_client']) && is_array($candidateThread['contact_client'])) {
                    $clientSummary = $candidateThread['contact_client'];
                }

                if ($clientId <= 0) {
                    $candidateClientId = (int)($candidateThread['contact_client_id'] ?? 0);
                    if ($candidateClientId > 0) {
                        $clientId = $candidateClientId;
                    }
                }

                if ($clientId > 0 && $clientSummary !== null) {
                    break;
                }
            }
        }
        $isActive = $threadId === $activeThreadId;
        if (!$isActive && $groupThreads) {
            foreach ($groupThreads as $candidateThread) {
                if ((int)($candidateThread['id'] ?? 0) === $activeThreadId) {
                    $isActive = true;
                    break;
                }
            }
        }

        $threadUrlParams = ['thread' => $threadId];
        if ($panelKey !== '') {
            $threadUrlParams['panel'] = $panelKey;
        }
        $threadUrl = is_callable($buildUrl) ? (string)$buildUrl($threadUrlParams) : '#';

        // Avoid undefined index notices when claim flags are absent
        $showClaim = !empty($options['allow_claim'] ?? null);
        if (!$showClaim && array_key_exists('claim', $options ?? [])) {
            $showClaim = !empty($options['claim']);
        }
        if ($panelKey === 'entrada') {
            $showClaim = true;
        }
        $showStatus = !empty($options['show_status']);
        $showPreview = !empty($options['show_preview']);
        $showQueue = !empty($options['show_queue']);
        $showSchedule = !empty($options['show_schedule']);
        $showPartner = !empty($options['show_partner']);
        $showResponsible = !empty($options['show_responsible']);
        $showAgent = !empty($options['show_agent']);
        $showLine = !empty($options['show_line']);
        $showReopen = !empty($options['allow_reopen']);

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

        $metaLines = [];
        if ($showSchedule && $scheduledFor) {
            $metaLines[] = 'Agendado: ' . wa_format_timestamp($scheduledFor);
        }
        if ($showPartner && $partnerName !== '') {
            $metaLines[] = 'Parceiro: ' . $partnerName;
        }
        if ($showResponsible && $responsibleName !== '') {
            $metaLines[] = 'Responsável: ' . $responsibleName;
        }
        if ($showQueue) {
            $metaLines[] = 'Fila: ' . ($queueLabels[$queue] ?? ucfirst($queue));
        }

        $referenceThreads = $groupThreads ?? [$thread];
        $assignedNames = [];
        $lineLabels = [];
        $participantNames = [];
        foreach ($referenceThreads as $referenceThread) {
            $assignedUserId = (int)($referenceThread['assigned_user_id'] ?? 0);
            if ($assignedUserId > 0 && isset($agentsById[$assignedUserId])) {
                $assignedNames[$assignedUserId] = (string)$agentsById[$assignedUserId]['name'];
            }
            $originRef = wa_describe_thread_origin($referenceThread, $lineById, $altGatewayLookup, $lineByAltSlug);
            $label = trim((string)$originRef['label']);
            if ($label !== '') {
                $lineLabels[$label] = $label;
            }

            $metaRef = !empty($referenceThread['is_group']) || (($referenceThread['chat_type'] ?? '') === 'group')
                ? wa_extract_group_metadata($referenceThread)
                : ['participant_name' => ''];
            $participantName = trim((string)($metaRef['participant_name'] ?? ''));
            if ($participantName !== '') {
                $participantNames[$participantName] = $participantName;
            }
        }

        if ($lineLabels === [] && ($lineChipText !== '' || $lineLabel !== '')) {
            $fallback = $lineChipText !== '' ? $lineChipText : $lineLabel;
            if ($fallback !== '') {
                $lineLabels[$fallback] = $fallback;
            }
        }
        if ($lineChipText === '' && $lineLabels !== []) {
            $lineChipText = implode(', ', array_values($lineLabels));
        }

        if ($showAgent && $assignedNames !== []) {
            $metaLines[] = 'Atendente: ' . implode(', ', array_values($assignedNames));
        }
        if ($showLine && $lineLabels !== [] && !$isCompactPanel) {
            $metaLines[] = 'Linha: ' . implode(', ', array_values($lineLabels));
        }
        if ($isGroupThread && $participantNames !== []) {
            $participantList = array_values($participantNames);
            sort($participantList, SORT_NATURAL | SORT_FLAG_CASE);
            $maxParticipants = 4;
            $visible = array_slice($participantList, 0, $maxParticipants);
            $remaining = count($participantList) - count($visible);
            $label = implode(', ', $visible);
            if ($remaining > 0) {
                $label .= ' +' . $remaining;
            }
            $metaLines[] = 'Participantes: ' . $label;
        }

        $clientPayload = null;
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
                wa_digits_only($thread['contact_phone'] ?? ''),
                $phone,
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
            ],
            $metaLines,
            array_values($lineLabels),
            array_values($assignedNames)
        );
        $phoneDigits = wa_digits_only($thread['contact_phone'] ?? '');
        if ($phoneDigits !== '' && !str_starts_with($phoneDigits, '55')) {
            $searchParts[] = '55' . $phoneDigits;
        }
        if ($isGroupThread) {
            $searchParts[] = 'grupo';
        }
        if ($clientId > 0) {
            $searchParts[] = 'cliente';
        }
        $searchIndex = wa_build_search_index($searchParts);

        $lastMessageAt = (int)($thread['last_message_at'] ?? $thread['updated_at'] ?? 0);
        $arrivalTimestamp = (int)($thread['last_message_at'] ?? ($thread['updated_at'] ?? ($thread['created_at'] ?? 0)));
        $channelThreadId = (string)($thread['channel_thread_id'] ?? '');

        return [
            'thread_id' => $threadId,
            'contact_id' => isset($thread['contact_id']) ? (int)$thread['contact_id'] : 0,
            'panel_key' => $panelKey,
            'thread_url' => $threadUrl,
            'search_index' => $searchIndex,
            'name' => $name,
            'phone' => $phone,
            'phone_digits' => $contactPhoneDigits,
            'is_group' => $isGroupThread,
            'unread' => $unread,
            'status' => $status,
            'preview' => $preview,
            'line_chip_text' => $lineChipText,
            'meta_lines' => $metaLines,
            'client_payload' => $clientPayload,
            'show_line' => $showLine,
            'show_preview' => $showPreview,
            'show_status' => $showStatus,
            'show_claim' => $showClaim,
            'show_reopen' => $showReopen,
            'is_active' => $isActive,
            'origin' => $origin,
            'queue_key' => $queue,
            'client_id' => $clientId,
            'last_message_at' => $lastMessageAt,
            'arrival_timestamp' => $arrivalTimestamp,
            'arrival_label' => $arrivalTimestamp > 0 ? wa_format_timestamp($arrivalTimestamp) : '',
            'channel_thread_id' => $channelThreadId,
            'contact_display' => $displayName,
            'photo' => $photo,
            'initials' => $initials,
        ];
    }
}

if (!function_exists('wa_render_thread_card')) {
    /**
     * @param array<string,mixed> $thread
     * @param array<string,mixed> $options
     * @param array<string,mixed> $context
     */
    function wa_render_thread_card(array $thread, array $options, array $context): string
    {
        $view = wa_prepare_thread_view($thread, $options, $context);
        if ($view === null) {
            return '';
        }

        ob_start(); ?>
<article class="wa-mini-thread<?= $view['is_active'] ? ' is-active' : ''; ?>" data-thread-search="<?= htmlspecialchars($view['search_index'], ENT_QUOTES, 'UTF-8'); ?>" data-thread-panel="<?= htmlspecialchars($view['panel_key'], ENT_QUOTES, 'UTF-8'); ?>" data-thread-id="<?= $view['thread_id']; ?>">
    <a class="wa-mini-link" href="<?= htmlspecialchars($view['thread_url'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="wa-mini-header">
            <div class="wa-mini-ident">
                <div class="wa-mini-avatar<?= $view['photo'] ? ' has-photo' : ''; ?>">
                    <?php if ($view['photo']): ?>
                        <img src="<?= htmlspecialchars($view['photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="Foto do contato" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span><?= htmlspecialchars($view['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="wa-mini-title">
                    <strong><?= htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (!empty($view['client_id'])): ?>
                        <span class="wa-client-icon" title="Cliente do CRM" aria-label="Cliente do CRM">
                            <svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-3.33 0-6 1.34-6 3v1h12v-1c0-1.66-2.67-3-6-3z" />
                            </svg>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($view['is_group'])): ?>
                        <span class="wa-chip">Grupo</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($view['unread'] > 0): ?>
                <span class="wa-unread"><?= (int)$view['unread']; ?></span>
            <?php endif; ?>
        </div>
        <?php if ($view['phone'] !== ''): ?>
            <p class="wa-mini-phone"><?= htmlspecialchars($view['phone'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </a>
    <?php if ($view['show_line'] && $view['line_chip_text'] !== ''): ?>
        <p class="wa-mini-line"><?= htmlspecialchars($view['line_chip_text'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($view['show_preview'] && $view['preview'] !== ''): ?>
        <p class="wa-mini-preview"><?= htmlspecialchars('"' . mb_substr($view['preview'], 0, 120) . '"', ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($view['meta_lines'] !== [] || $view['show_status']): ?>
        <div class="wa-mini-meta">
            <?php foreach ($view['meta_lines'] as $metaLine): ?>
                <span class="wa-chip"><?= htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endforeach; ?>
            <?php if ($view['show_status']): ?>
                <span class="wa-chip"><?= htmlspecialchars($view['status'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="wa-mini-actions">
        <div class="wa-mini-actions-time">
            <?= htmlspecialchars($view['arrival_label'] !== '' ? $view['arrival_label'] : '--', ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php
            $isGroup = !empty($view['is_group']);
            $clientPayload = $view['client_payload'] ?? null;
            $clientButtonVariant = 'create';
            $clientButtonLabel = 'Cadastro';

            if ($clientPayload !== null) {
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
        <?php if (!$isGroup && $clientPayload !== null): ?>
            <a class="wa-client-button wa-client-button--<?= htmlspecialchars($clientButtonVariant, ENT_QUOTES, 'UTF-8'); ?>" href="<?= htmlspecialchars($clientPayload['link'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                data-client-id="<?= (int)$clientPayload['id']; ?>"
                data-client-preview='<?= htmlspecialchars(json_encode($clientPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'><?= htmlspecialchars($clientButtonLabel, ENT_QUOTES, 'UTF-8'); ?></a>
        <?php elseif (!$isGroup && (int)($view['contact_id'] ?? 0) > 0): ?>
            <button class="wa-client-button wa-client-button--create" type="button"
                data-register-contact-open
                data-thread-id="<?= (int)$view['thread_id']; ?>"
                data-contact-id="<?= (int)($view['contact_id'] ?? 0); ?>"
                data-contact-name="<?= htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-contact-phone="<?= htmlspecialchars($view['phone_digits'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars($clientButtonLabel, ENT_QUOTES, 'UTF-8'); ?>
            </button>
        <?php endif; ?>
        <?php if (!empty($view['show_claim'])): ?>
            <button class="ghost wa-mini-action" type="button" data-claim-thread="<?= (int)$view['thread_id']; ?>">Assumir</button>
        <?php endif; ?>
        <?php if ($view['show_reopen']): ?>
            <button class="ghost wa-mini-action" type="button" data-reopen-thread="<?= (int)$view['thread_id']; ?>">Reabrir</button>
        <?php endif; ?>
    </div>
</article>
<?php

        return trim((string)ob_get_clean());
    }
}

if (!function_exists('wa_collect_thread_meta')) {
    /**
     * @param array<string,mixed> $thread
     * @param array<string,mixed> $options
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    function wa_collect_thread_meta(array $thread, array $options, array $context): ?array
    {
        $view = wa_prepare_thread_view($thread, $options, $context);
        if ($view === null) {
            return null;
        }

        return [
            'id' => $view['thread_id'],
            'panel' => $view['panel_key'],
            'name' => $view['name'],
            'phone' => $view['phone'],
            'line_label' => $view['origin']['label'] ?? '',
            'line_phone' => $view['origin']['phone'] ?? '',
            'line_chip' => $view['line_chip_text'],
            'line_slug' => $view['origin']['slug'] ?? '',
            'line_type' => $view['origin']['type'] ?? 'meta',
            'unread' => $view['unread'],
            'preview' => $view['preview'],
            'updated_at' => $view['last_message_at'],
            'queue' => $view['queue_key'],
            'client_id' => $view['client_id'],
            'is_group' => !empty($view['is_group']),
            'status' => $view['status'],
            'channel_thread_id' => $view['channel_thread_id'],
            'contact_display' => $view['contact_display'],
            'arrival_timestamp' => $view['arrival_timestamp'],
            'contact_photo' => $view['photo'],
            'contact_initials' => $view['initials'],
        ];
    }
}
