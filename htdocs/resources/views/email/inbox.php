<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $accounts */
/** @var array<int, array<string, mixed>> $folders */
/** @var array<int, array<string, mixed>> $threads */
/** @var array<int, array<string, mixed>> $messages */

$escape = static fn(?string $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$formatBytes = static function (?int $bytes): string {
    $bytes = (int)($bytes ?? 0);
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $index = (int)floor(log($bytes, 1024));
    $index = min($index, count($units) - 1);

    return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1) . ' ' . $units[$index];
};

$activeAccountId = $activeAccountId ?? null;
$activeThreadId = $activeThreadId ?? null;

$activeAccountName = 'Inbox';
foreach ($accounts as $accountRow) {
    if ((int)($accountRow['id'] ?? 0) === (int)$activeAccountId) {
        $activeAccountName = $accountRow['name'] ?? $activeAccountName;
        break;
    }
}

$accountSummaries = array_map(static function (array $accountRow): array {
    $imapEnabled = (int)($accountRow['imap_sync_enabled'] ?? 0) === 1;

    $credentialsRaw = $accountRow['credentials'] ?? null;
    $credentials = [];
    if (is_string($credentialsRaw) && trim($credentialsRaw) !== '') {
        $decoded = json_decode($credentialsRaw, true);
        if (is_array($decoded)) {
            $credentials = $decoded;
        }
    }

    $hostSource = $accountRow['imap_host'] ?? $accountRow['smtp_host'] ?? null;
    $usernameSource = $accountRow['imap_username'] ?? ($credentials['username'] ?? null);
    $passwordSource = $accountRow['imap_password'] ?? ($credentials['password'] ?? null);

    $hasHost = trim((string)($hostSource ?? '')) !== '';
    $hasUsername = trim((string)($usernameSource ?? '')) !== '';
    $hasPassword = trim((string)($passwordSource ?? '')) !== '';
    $hasCredentials = $hasHost && $hasUsername && $hasPassword;
    $canSync = $imapEnabled && $hasCredentials;

    $reason = null;
    if (!$imapEnabled) {
        $reason = 'Sincronização IMAP está desativada para esta conta.';
    } elseif (!$hasCredentials) {
        $reason = 'Complete host, usuário e senha IMAP para usar Enviar e receber.';
    }

    return [
        'id' => (int)($accountRow['id'] ?? 0),
        'name' => $accountRow['name'] ?? ('Mailbox #' . ($accountRow['id'] ?? '')),
        'from_name' => $accountRow['from_name'] ?? null,
        'from_email' => $accountRow['from_email'] ?? null,
        'sync' => [
            'canSync' => $canSync,
            'imapEnabled' => $imapEnabled,
            'hasCredentials' => $hasCredentials,
            'reason' => $reason,
        ],
    ];
}, $accounts);

$totalUnread = 0;
$threadWithUnread = 0;
foreach ($threads as $threadRow) {
    $unread = (int)($threadRow['unread_count'] ?? 0);
    $totalUnread += $unread;
    if ($unread > 0) {
        $threadWithUnread++;
    }
}

$activeFolderId = $filters['folder_id'] ?? null;

$normalizeFolderLabel = static function (?string $value): string {
    $label = strtolower(trim((string)($value ?? '')));
    if ($label === '') {
        return '';
    }
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);
    if (is_string($transliterated) && $transliterated !== '') {
        $label = strtolower($transliterated);
    }
    $label = preg_replace('/[^a-z0-9 ]+/i', ' ', $label) ?? $label;
    return trim(preg_replace('/\s+/', ' ', $label) ?? $label);
};

$inferFolderType = static function (array $folderRow) use ($normalizeFolderLabel): string {
    $rawType = strtolower((string)($folderRow['type'] ?? ''));
    if ($rawType !== '' && $rawType !== 'custom') {
        return match ($rawType) {
            'deleted', 'bin' => 'trash',
            default => $rawType,
        };
    }

    $label = $normalizeFolderLabel($folderRow['display_name'] ?? $folderRow['remote_name'] ?? '');
    if ($label === '') {
        return $rawType ?: 'custom';
    }

    $keywordMap = [
        'inbox' => ['inbox', 'entrada'],
        'sent' => ['sent', 'enviad'],
        'drafts' => ['draft', 'rascunho'],
        'spam' => ['spam', 'junk', 'lixo'],
        'archive' => ['archive', 'arquiv', 'all mail'],
        'trash' => ['trash', 'lixeira', 'excluid', 'deleted', 'bin'],
    ];

    foreach ($keywordMap as $type => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($label, $keyword)) {
                return $type;
            }
        }
    }

    return $rawType ?: 'custom';
};

$folderTypeLabels = [
    'inbox' => 'Caixa de entrada',
    'sent' => 'Enviados',
    'drafts' => 'Rascunhos',
    'spam' => 'Spam',
    'archive' => 'Arquivados',
    'trash' => 'Lixeira',
    'important' => 'Importante',
    'custom' => 'Pasta personalizada',
];

$folderLabelMap = [
    'all mail' => 'Todos os e-mails',
    'todos os e mails' => 'Todos os e-mails',
    'sent mail' => 'Enviados',
    'sent' => 'Enviados',
    'drafts' => 'Rascunhos',
    'spam' => 'Spam',
    'junk' => 'Spam',
    'trash' => 'Lixeira',
    'bin' => 'Lixeira',
    'important' => 'Importante',
    'starred' => 'Com estrela',
    'marcados' => 'Com estrela',
    'archive' => 'Arquivados',
    'arquivados' => 'Arquivados',
];

$folderTypeLabel = static function (?string $type) use ($folderTypeLabels): string {
    $key = strtolower((string)($type ?? 'custom'));
    if ($key === '') {
        $key = 'custom';
    }
    return $folderTypeLabels[$key] ?? $folderTypeLabels['custom'];
};

$translateFolderLabel = static function (?string $value, string $folderType = '') use ($normalizeFolderLabel, $folderLabelMap, $folderTypeLabel): string {
    $raw = trim((string)($value ?? ''));
    if ($raw !== '') {
        $raw = preg_replace('/^\s*\[[^\]]+\]\s*\/?/i', '', $raw) ?? $raw;
        $raw = preg_replace('#/{2,}#', '/', $raw) ?? $raw;
        $raw = trim($raw, " \/\t\n\r");
    }

    $normalized = $normalizeFolderLabel($raw);
    if ($normalized !== '' && isset($folderLabelMap[$normalized])) {
        return $folderLabelMap[$normalized];
    }

    if ($raw !== '') {
        return $raw;
    }

    return $folderTypeLabel($folderType);
};

$formatFolderDisplayName = static function (array $folderRow) use ($inferFolderType, $translateFolderLabel): string {
    $type = $inferFolderType($folderRow);
    $rawLabel = $folderRow['display_name'] ?? ($folderRow['remote_name'] ?? '');
    return $translateFolderLabel($rawLabel, $type);
};

$activeFolderName = 'Todas as pastas';
foreach ($folders as $folderRow) {
    if ((int)$folderRow['id'] === (int)$activeFolderId) {
        $activeFolderName = $formatFolderDisplayName($folderRow);
        break;
    }
}

$folderSummaries = array_map(static function (array $folderRow) use ($inferFolderType, $formatFolderDisplayName): array {
    return [
        'id' => (int)($folderRow['id'] ?? 0),
        'display_name' => $formatFolderDisplayName($folderRow),
        'type' => $inferFolderType($folderRow),
        'remote_name' => $folderRow['remote_name'] ?? null,
        'unread_count' => (int)($folderRow['unread_count'] ?? 0),
        'total_count' => (int)($folderRow['total_count'] ?? 0),
    ];
}, $folders);

$archiveFolderId = null;
foreach ($folderSummaries as $summary) {
    if ($summary['type'] === 'archive') {
        $archiveFolderId = $summary['id'];
        break;
    }
}

$trashFolderId = null;
foreach ($folderSummaries as $summary) {
    if ($summary['type'] === 'trash') {
        $trashFolderId = $summary['id'];
        break;
    }
}

$isStandalone = isset($_GET['standalone']);
$standaloneQuery = $isStandalone ? '&standalone=1' : '';
$composeWindowUrl = $emailRoutes['composeWindow'] ?? url('email/inbox') . '?compose=novo' . $standaloneQuery;
$shouldAutoCompose = !empty($autoCompose ?? false);

$inboxConfig = [
    'accountId' => $activeAccountId,
    'folderId' => $activeFolderId,
    'threadId' => $activeThreadId,
    'threadLimit' => 30,
    'routes' => $emailRoutes,
    'folders' => $folderSummaries,
    'archiveFolderId' => $archiveFolderId,
    'trashFolderId' => $trashFolderId,
    'accounts' => $accountSummaries,
    'autoCompose' => $shouldAutoCompose,
    'composeWindowUrl' => $composeWindowUrl,
];

$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
?>

<style>
    :root {
        --inbox-canvas: #ecf0f6;
        --inbox-shell: #ffffff;
        --inbox-ink: #0f172a;
        --inbox-muted: #64748b;
        --inbox-divider: rgba(15, 23, 42, 0.08);
        --inbox-accent: #2563eb;
        --inbox-accent-soft: rgba(37, 99, 235, 0.12);
        --inbox-highlight: #f79009;
        --inbox-surface: #f8fafc;
        --inbox-pill: rgba(15, 23, 42, 0.04);
        --inbox-elevated: rgba(15, 23, 42, 0.05);
        --inbox-radius: 18px;
    }

    .inbox-shell {
        font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
        background: var(--inbox-canvas);
        padding: 1.75rem;
        min-height: 100vh;
        color: var(--inbox-ink);
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .inbox-toolbar {
        background: var(--inbox-shell);
        border-radius: var(--inbox-radius);
        border: 1px solid var(--inbox-divider);
        padding: 1.25rem 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        box-shadow: 0 25px 60px rgba(15, 23, 42, 0.08);
    }

    .toolbar-primary {
        display: flex;
        align-items: flex-start;
        gap: 1.75rem;
        flex-wrap: wrap;
        justify-content: space-between;
    }

    .toolbar-filters {
        display: grid;
        grid-template-columns: minmax(220px, 280px) minmax(280px, 420px);
        gap: 1rem;
        align-items: end;
        width: 100%;
        max-width: 720px;
    }

    .filter-block label {
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--inbox-muted);
        display: block;
        margin-bottom: 0.35rem;
    }

    .filter-field {
        display: flex;
        align-items: center;
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        padding: 0.55rem 0.85rem;
        background: var(--inbox-surface);
        gap: 0.5rem;
    }

    .filter-field select,
    .filter-field input {
        border: none;
        background: transparent;
        width: 100%;
        font-size: 0.95rem;
        color: var(--inbox-ink);
        outline: none;
    }

    .toolbar-metrics {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .toolbar-metric {
        background: var(--inbox-surface);
        border-radius: calc(var(--inbox-radius) - 6px);
        padding: 0.85rem 1.1rem;
        min-width: 150px;
        border: 1px solid var(--inbox-divider);
    }

    .toolbar-metric span {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--inbox-muted);
    }

    .toolbar-metric strong {
        display: block;
        font-size: 1.3rem;
        margin-top: 0.15rem;
    }

    .toolbar-metric small {
        display: block;
        margin-top: 0.2rem;
        color: var(--inbox-muted);
        font-size: 0.78rem;
    }

    .toolbar-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.65rem;
        position: relative;
    }

    .toolbar-actions button,
    .toolbar-actions select {
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        padding: 0.5rem 1.1rem;
        font-size: 0.88rem;
        background: var(--inbox-shell);
        color: var(--inbox-ink);
        cursor: pointer;
        transition: background 0.2s ease, border-color 0.2s ease;
    }

    .toolbar-actions button.is-primary {
        background: var(--inbox-accent);
        border-color: var(--inbox-accent);
        color: #fff;
        box-shadow: 0 12px 30px rgba(37, 99, 235, 0.35);
    }

    .toolbar-actions button[data-compose-trigger] {
        background: #fcbf49;
        border-color: #f59f00;
        color: #4a3203;
        box-shadow: 0 10px 25px rgba(252, 191, 73, 0.35);
    }

    .toolbar-actions button[data-compose-window-trigger] {
        background: #2563eb;
        border-color: #1d4ed8;
        color: #fff;
        box-shadow: 0 12px 30px rgba(37, 99, 235, 0.4);
    }

    .toolbar-actions button[data-sync-trigger] {
        background: #16a34a;
        border-color: #15803d;
        color: #fff;
        box-shadow: 0 12px 30px rgba(22, 163, 74, 0.35);
    }

    .toolbar-actions button[data-sync-trigger][aria-busy="true"] {
        background: #b42318;
        border-color: #7a271a;
        color: #fff;
        box-shadow: 0 12px 28px rgba(180, 35, 24, 0.35);
        opacity: 1;
    }

    .toolbar-actions button:disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .toolbar-sync-group {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
    }

    .toolbar-sync-status {
        font-size: 0.82rem;
        color: var(--inbox-muted);
    }

    .toolbar-sync-status[data-state="loading"] {
        color: var(--inbox-highlight);
    }

    .toolbar-sync-status[data-state="success"] {
        color: #027a48;
    }

    .toolbar-sync-status[data-state="error"] {
        color: #d92d20;
    }

    .toolbar-sync-status[data-state="warning"] {
        color: #b45309;
    }

    .command-move-group {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .command-move-group select {
        min-width: 200px;
    }

    .command-selection {
        display: none !important;
    }

    .command-selection button {
        border: none;
        background: transparent;
        color: var(--inbox-accent);
        text-decoration: underline;
        cursor: pointer;
        font-weight: 600;
    }

    .inbox-layout {
        display: grid;
        grid-template-columns: 260px minmax(320px, 420px) 1fr;
        gap: 1.25rem;
        align-items: stretch;
    }

    .inbox-sidebar {
        background: var(--inbox-shell);
        border-radius: var(--inbox-radius);
        border: 1px solid var(--inbox-divider);
        padding: 1.25rem 1rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .sidebar-heading h3 {
        margin: 0;
        font-size: 1.1rem;
    }

    .sidebar-heading p,
    .sidebar-heading small {
        margin: 0;
        color: var(--inbox-muted);
        font-size: 0.85rem;
    }

    .sidebar-section h4 {
        margin: 0 0 0.6rem;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--inbox-muted);
    }

    .folder-list {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .folder-chip {
        border: 1px solid transparent;
        border-radius: calc(var(--inbox-radius) - 8px);
        padding: 0.65rem 0.85rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        color: var(--inbox-muted);
        background: transparent;
        transition: border 0.2s ease, background 0.2s ease, color 0.2s ease;
    }

    .folder-chip:hover {
        border-color: var(--inbox-divider);
        background: var(--inbox-pill);
        color: var(--inbox-ink);
    }

    .folder-chip.is-active {
        background: var(--inbox-accent-soft);
        color: var(--inbox-ink);
        border-color: rgba(37, 99, 235, 0.3);
    }

    .folder-chip strong {
        display: block;
        font-size: 0.95rem;
    }

    .folder-chip small {
        font-size: 0.74rem;
        color: var(--inbox-muted);
    }

    .inbox-badge {
        background: var(--inbox-ink);
        color: #fff;
        font-size: 0.75rem;
        padding: 0.1rem 0.55rem;
        border-radius: 999px;
        min-width: 30px;
        text-align: center;
    }

    .inbox-badge.is-hidden {
        display: none;
    }

    .thread-pane,
    .inbox-reader {
        background: var(--inbox-shell);
        border-radius: var(--inbox-radius);
        border: 1px solid var(--inbox-divider);
        display: flex;
        flex-direction: column;
        min-height: 70vh;
    }

    .thread-pane {
        padding: 1rem 1.1rem;
        gap: 0.75rem;
    }

    .thread-pane-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
    }

    .thread-pane-header h2 {
        margin: 0;
        font-size: 1.1rem;
    }

    .thread-pane-header p {
        margin: 0;
        color: var(--inbox-muted);
        font-size: 0.85rem;
    }

    .thread-search {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        background: linear-gradient(120deg, rgba(37, 99, 235, 0.07), rgba(236, 254, 255, 0.5));
        border-radius: calc(var(--inbox-radius) - 8px);
        padding: 0.85rem 0.95rem;
        border: 1px solid rgba(37, 99, 235, 0.16);
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.07);
        overflow: hidden;
    }

    .thread-search::after {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 55%);
        mix-blend-mode: screen;
    }

    .thread-search > * {
        position: relative;
        z-index: 1;
    }

    .thread-search-primary {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
        align-items: stretch;
    }

    .thread-search-field {
        flex: 1;
        min-width: 220px;
        display: flex;
        align-items: center;
        background: #fff;
        border-radius: 0.9rem;
        padding: 0.45rem 0.9rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.02);
        gap: 0.35rem;
    }

    .thread-search-icon {
        color: var(--inbox-accent);
        font-size: 0.95rem;
    }

    .thread-search-field input {
        border: none;
        background: transparent;
        width: 100%;
        font-size: 0.95rem;
        color: var(--inbox-ink);
        outline: none;
    }

    .thread-search-clear {
        border: none;
        border-radius: 0.85rem;
        background: rgba(15, 23, 42, 0.07);
        color: var(--inbox-ink);
        padding: 0.45rem 1rem;
        font-size: 0.8rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: background 0.2s ease, color 0.2s ease;
    }

    .thread-search-clear:not(:disabled):hover {
        background: rgba(239, 68, 68, 0.15);
        color: #b91c1c;
    }

    .thread-search-clear:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .thread-quick-filters {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .quick-filter-chip {
        border: none;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.9);
        padding: 0.35rem 0.95rem;
        font-size: 0.78rem;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        cursor: pointer;
        color: var(--inbox-muted);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.07);
        transition: transform 0.2s ease, box-shadow 0.2s ease, color 0.2s ease, background 0.2s ease;
    }

    .quick-filter-chip .chip-icon {
        display: inline-flex;
        font-size: 0.95rem;
    }

    .quick-filter-chip.is-active,
    .quick-filter-chip[aria-pressed="true"] {
        background: var(--inbox-accent);
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 14px 30px rgba(37, 99, 235, 0.35);
    }

    .thread-search-filters {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.55rem;
        max-height: 230px;
        overflow-y: auto;
        padding-right: 0.35rem;
    }

    .thread-search-filters::-webkit-scrollbar {
        width: 6px;
    }

    .thread-search-filters::-webkit-scrollbar-track {
        background: transparent;
    }

    .thread-search-filters::-webkit-scrollbar-thumb {
        background: rgba(37, 99, 235, 0.35);
        border-radius: 999px;
    }

    .thread-search-filters {
        scrollbar-width: thin;
        scrollbar-color: rgba(37, 99, 235, 0.4) transparent;
    }

    .filter-field {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        padding: 0.45rem 0.8rem;
        background: #fff;
        border-radius: 0.85rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        min-height: 72px;
        box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.015);
    }

    .filter-field input {
        border: none;
        background: transparent;
        font-size: 0.84rem;
        color: var(--inbox-ink);
        outline: none;
    }

    .filter-date input[type="date"] {
        border-radius: 0.65rem;
        border: 1px solid rgba(15, 23, 42, 0.12);
        padding: 0.45rem 0.6rem;
        background: rgba(248, 250, 255, 0.9);
        width: 100%;
    }

    .filter-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--inbox-muted);
    }

    .filter-hint {
        font-size: 0.68rem;
        color: var(--inbox-muted);
    }

    .filter-pill {
        border-radius: 0.85rem;
        border: 1px solid rgba(37, 99, 235, 0.22);
        padding: 0.4rem 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: rgba(255, 255, 255, 0.92);
        font-size: 0.78rem;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.04);
        cursor: pointer;
    }

    .filter-pill input {
        accent-color: var(--inbox-accent);
    }

    .thread-search-status {
        font-size: 0.78rem;
        color: var(--inbox-muted);
    }

    .thread-search-status.is-active {
        color: var(--inbox-accent);
    }

    .thread-list {
        flex: 1 1 auto;
        min-height: 0;
        max-height: calc(100vh - 260px);
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
        padding-right: 0.35rem;
        scrollbar-width: thin;
        scrollbar-color: var(--inbox-accent-soft) transparent;
    }

    .thread-list::-webkit-scrollbar {
        width: 6px;
    }

    .thread-list::-webkit-scrollbar-thumb {
        background: var(--inbox-accent-soft);
        border-radius: 999px;
    }

    .thread-group {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .thread-group + .thread-group {
        border-top: 1px solid var(--inbox-divider);
        padding-top: 0.65rem;
        margin-top: 0.35rem;
    }

    .thread-group-title {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--inbox-muted);
    }

    .thread-card {
        border: 1px solid var(--inbox-divider);
        border-radius: calc(var(--inbox-radius) - 8px);
        padding: 0.85rem;
        cursor: pointer;
        transition: border 0.2s ease, background 0.2s ease, transform 0.2s ease;
        background: #fff;
    }

    .thread-card:hover {
        border-color: rgba(37, 99, 235, 0.4);
        transform: translateY(-1px);
    }

    .thread-card.is-active {
        border-color: var(--inbox-accent);
        background: var(--inbox-accent-soft);
    }

    .thread-card.is-selected {
        border-color: var(--inbox-accent);
        box-shadow: 0 0 0 1px var(--inbox-accent) inset;
    }

    .thread-card-head {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .thread-head-content {
        min-width: 0;
    }

    .thread-head-content h3,
    .thread-head-content p {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .thread-select {
        display: inline-flex;
        width: 1.6rem;
        height: 1.6rem;
        border-radius: 0.45rem;
        border: 1px solid var(--inbox-divider);
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .thread-select input {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }

    .thread-select span {
        width: 100%;
        height: 100%;
        border-radius: 0.35rem;
        background: transparent;
        display: inline-block;
    }

    .thread-select input:checked + span {
        background: var(--inbox-accent);
    }

    .thread-row-actions {
        display: inline-flex;
        gap: 0.35rem;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .thread-card:hover .thread-row-actions {
        opacity: 1;
    }

    .thread-mini-action {
        border: none;
        border-radius: 0.85rem;
        width: 38px;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        cursor: pointer;
        color: #fff;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .thread-mini-action:hover,
    .thread-mini-action:focus-visible {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.25);
        outline: none;
    }

    .thread-mini-action[data-thread-row-star] {
        background: linear-gradient(135deg, #fef3c7, #facc15);
        color: #854d0e;
    }

    .thread-mini-action[data-thread-row-window] {
        background: linear-gradient(135deg, #bfdbfe, #3b82f6);
        color: #0f172a;
    }

    .thread-mini-action[data-thread-row-trash] {
        background: linear-gradient(135deg, #fecaca, #dc2626);
        color: #7f1d1d;
    }

    .thread-mini-icon {
        pointer-events: none;
        font-size: 1.05rem;
        line-height: 1;
    }

    .thread-card h3 {
        margin: 0;
        font-size: 1rem;
        color: var(--inbox-ink);
    }

    .thread-card p {
        margin: 0;
        font-size: 0.83rem;
        color: var(--inbox-muted);
    }

    .thread-meta {
        display: flex;
        justify-content: space-between;
        margin-top: 0.4rem;
        font-size: 0.78rem;
        color: var(--inbox-muted);
    }

    .thread-meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .trash-preview-trigger {
        border: 1px solid var(--inbox-divider);
        border-radius: 999px;
        padding: 0.35rem 0.85rem;
        background: #fff;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.82rem;
        cursor: pointer;
    }

    .trash-preview-trigger[data-disabled="true"] {
        opacity: 0.4;
        pointer-events: none;
    }

    .trash-preview-trigger-count {
        border-radius: 999px;
        background: var(--inbox-accent-soft);
        padding: 0.1rem 0.5rem;
        font-size: 0.72rem;
    }

    .trash-preview-popover {
        position: absolute;
        right: 0;
        top: calc(100% + 0.5rem);
        width: min(420px, 90vw);
        border-radius: var(--inbox-radius);
        border: 1px solid var(--inbox-divider);
        background: #fff;
        box-shadow: 0 25px 60px rgba(15, 23, 42, 0.18);
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        padding: 1rem 1.25rem;
        z-index: 30;
    }

    .trash-preview-popover[hidden] {
        display: none;
    }

    .trash-preview-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
    }

    .trash-preview-header strong {
        font-size: 0.92rem;
    }

    .trash-preview-header small {
        display: block;
        color: var(--inbox-muted);
        margin-top: 0.15rem;
    }

    .trash-preview-meta {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.78rem;
        color: var(--inbox-muted);
    }

    .trash-preview-refresh,
    .trash-preview-close {
        border: 1px solid var(--inbox-divider);
        border-radius: 999px;
        padding: 0.35rem 0.65rem;
        background: #fff;
        cursor: pointer;
        font-size: 0.75rem;
    }

    .trash-preview-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        max-height: 320px;
        overflow-y: auto;
    }

    .trash-preview-footer {
        border-top: 1px solid var(--inbox-divider);
        padding-top: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .trash-preview-empty {
        border: none;
        border-radius: 999px;
        padding: 0.4rem 1rem;
        background: #dc2626;
        color: #fff;
        font-size: 0.8rem;
        letter-spacing: 0.01em;
        cursor: pointer;
        transition: background 0.2s ease;
        align-self: flex-start;
    }

    .trash-preview-empty:not([disabled]):hover {
        background: #b91c1c;
    }

    .trash-preview-empty[disabled] {
        background: rgba(220, 38, 38, 0.4);
        cursor: not-allowed;
    }

    .trash-preview-warning {
        font-size: 0.76rem;
        color: var(--inbox-muted);
        margin: 0;
    }

    .trash-preview-warning[data-state="loading"] {
        color: #b45309;
    }

    .trash-preview-warning[data-state="success"] {
        color: #15803d;
    }

    .trash-preview-warning[data-state="error"] {
        color: #b91c1c;
    }

    .trash-preview-item {
        border: 1px solid var(--inbox-divider);
        border-radius: 0.75rem;
        padding: 0.5rem 0.85rem;
        background: var(--inbox-surface);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        cursor: pointer;
    }

    .trash-preview-item:hover {
        border-color: rgba(37, 99, 235, 0.4);
    }

    .trash-preview-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        text-align: left;
    }

    .trash-preview-info strong {
        font-size: 0.9rem;
        color: var(--inbox-ink);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .trash-preview-info span {
        font-size: 0.78rem;
        color: var(--inbox-muted);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .trash-preview-date {
        font-size: 0.75rem;
        color: var(--inbox-muted);
        white-space: nowrap;
    }

    .thread-meta .dot {
        width: 6px;
        height: 6px;
        border-radius: 999px;
        background: var(--inbox-highlight);
    }

    .message-panel {
        background: var(--inbox-shell);
        border-radius: var(--inbox-radius);
        border: 1px solid var(--inbox-divider);
        overflow: hidden;
    }

    .message-panel-header {
        position: relative;
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid var(--inbox-divider);
        background: var(--inbox-shell);
        color: var(--inbox-ink);
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
    }

    .message-panel-header::after {
        content: none;
    }

    .message-panel-header > * {
        position: relative;
        z-index: 1;
    }

    .message-panel-title {
        display: flex;
        justify-content: space-between;
        gap: 1.2rem;
        flex-wrap: wrap;
        align-items: flex-start;
    }

    .message-panel-heading {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }

    .message-panel-heading h2 {
        margin: 0;
        font-size: 1.35rem;
        color: var(--inbox-ink);
    }

    .message-panel-heading span {
        color: var(--inbox-muted);
        font-size: 0.9rem;
    }

    .message-panel-summary {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
        padding-top: 0.4rem;
        border-top: 1px solid var(--inbox-divider);
        margin-top: 0.35rem;
    }

    .message-chip {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        padding: 0.4rem 0.75rem;
        border-radius: 0.85rem;
        border: 1px solid var(--inbox-divider);
        background: var(--inbox-surface);
        min-width: 120px;
        color: var(--inbox-ink);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
    }

    .message-chip strong {
        font-size: 0.9rem;
    }

    .message-chip-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        opacity: 0.7;
    }

    .message-chip-alert {
        background: rgba(248, 113, 113, 0.12);
        border-color: rgba(248, 113, 113, 0.4);
    }

    .thread-header-controls {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.65rem;
        padding: 0.9rem;
        border-radius: calc(var(--inbox-radius) - 8px);
        background: var(--inbox-surface);
        border: 1px solid var(--inbox-divider);
        box-shadow: none;
    }

    .thread-actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .thread-reply-group {
        display: inline-flex;
        align-items: stretch;
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        background: var(--inbox-shell);
        overflow: hidden;
        box-shadow: none;
    }

    .thread-reply-button {
        border: none;
        background: transparent;
        padding: 0.45rem 1rem;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        color: var(--inbox-ink);
        transition: background 0.2s ease;
    }

    .thread-reply-button:hover:not([disabled]) {
        background: var(--inbox-pill);
    }

    .thread-reply-caret {
        border-left: 1px solid var(--inbox-divider);
        padding: 0 0.75rem;
        display: grid;
        place-items: center;
        font-size: 0.75rem;
        color: var(--inbox-muted);
    }

    .thread-reply-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 0.35rem);
        min-width: 200px;
        background: var(--inbox-shell);
        border: 1px solid var(--inbox-divider);
        border-radius: calc(var(--inbox-radius) - 10px);
        padding: 0.35rem;
        display: none;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
    }

    .thread-reply-menu.is-visible {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .thread-reply-menu button {
        border: none;
        background: transparent;
        padding: 0.5rem 0.6rem;
        border-radius: calc(var(--inbox-radius) - 12px);
        text-align: left;
        font-size: 0.82rem;
        cursor: pointer;
    }

    .thread-reply-menu button:hover {
        background: var(--inbox-pill);
    }

    .thread-action-button,
    .thread-move-control select,
    .inbox-mark-read {
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        background: #fff;
        font-size: 0.8rem;
        padding: 0.35rem 0.85rem;
        cursor: pointer;
        transition: border-color 0.2s ease, color 0.2s ease, background 0.2s ease, transform 0.2s ease;
    }

    .thread-header-controls .thread-action-button,
    .thread-header-controls .inbox-mark-read,
    .thread-header-controls .thread-move-control select {
        border: 1px solid var(--inbox-divider);
        background: var(--inbox-shell);
        color: var(--inbox-ink);
        box-shadow: none;
    }

    .thread-header-controls .thread-action-button:hover,
    .thread-header-controls .inbox-mark-read:hover {
        background: var(--inbox-surface);
        border-color: var(--inbox-accent);
        color: var(--inbox-accent);
        transform: translateY(-1px);
    }

    .thread-header-controls .thread-action-button[data-thread-star] {
        background: rgba(251, 191, 36, 0.15);
        border-color: rgba(251, 191, 36, 0.5);
        color: #8b5e00;
    }

    .thread-header-controls .thread-action-button[data-thread-star]:hover {
        background: rgba(251, 191, 36, 0.2);
        border-color: rgba(251, 191, 36, 0.6);
        color: #8b5e00;
    }

    .thread-header-controls .thread-action-button[data-thread-archive] {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(59, 130, 246, 0.45);
        color: #1d4ed8;
    }

    .thread-header-controls .thread-action-button[data-thread-archive]:hover {
        background: rgba(59, 130, 246, 0.18);
        border-color: rgba(59, 130, 246, 0.6);
        color: #1d4ed8;
    }

    .thread-header-controls .thread-action-button[data-thread-trash] {
        background: rgba(248, 113, 113, 0.15);
        border-color: rgba(248, 113, 113, 0.45);
        color: #b91c1c;
    }

    .thread-header-controls .thread-action-button[data-thread-trash]:hover {
        background: rgba(248, 113, 113, 0.2);
        border-color: rgba(248, 113, 113, 0.6);
        color: #b91c1c;
    }

    .thread-header-controls .thread-action-button[data-thread-move] {
        background: rgba(139, 92, 246, 0.12);
        border-color: rgba(139, 92, 246, 0.45);
        color: #5b21b6;
    }

    .thread-header-controls .thread-action-button[data-thread-move]:hover {
        background: rgba(139, 92, 246, 0.18);
        border-color: rgba(139, 92, 246, 0.6);
        color: #5b21b6;
    }

    .thread-header-controls .thread-action-button.is-active {
        box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.12) inset;
    }

    .thread-action-button:disabled,
    .inbox-mark-read:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .thread-move-control {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .thread-move-control select {
        padding-right: 2rem;
    }

    .thread-header-controls .thread-move-control select {
        background: rgba(37, 99, 235, 0.08);
        color: var(--inbox-ink);
        font-weight: 600;
    }

    .message-scroll {
        padding: 1.25rem 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        max-height: calc(70vh - 150px);
        overflow-y: auto;
        background: #fff;
    }

    .message-bubble {
        border-radius: calc(var(--inbox-radius) - 6px);
        padding: 0.95rem 1rem;
        max-width: 80%;
        background: var(--inbox-pill);
        border: 1px solid var(--inbox-divider);
        box-shadow: none;
        color: var(--inbox-ink);
    }

    .message-bubble.from-me {
        align-self: flex-end;
        background: rgba(37, 99, 235, 0.12);
        border-color: rgba(37, 99, 235, 0.35);
    }

    .message-bubble header {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        margin-bottom: 0.35rem;
        color: var(--inbox-muted);
    }

    .message-bubble.is-active,
    .message-bubble:focus-visible {
        outline: 2px solid var(--inbox-highlight);
        outline-offset: 3px;
    }

    .message-quick-actions {
        margin-top: 0.65rem;
        display: inline-flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        align-items: flex-start;
        position: relative;
    }

    .message-action-list {
        display: inline-flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .message-action-button,
    .message-action-overflow,
    .message-detail-action-button,
    .message-detail-nav button {
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        background: #fff;
        font-size: 0.75rem;
        padding: 0.28rem 0.75rem;
        cursor: pointer;
        transition: border-color 0.2s ease;
    }

    .message-action-button:hover,
    .message-action-overflow:hover,
    .message-detail-action-button:hover,
    .message-detail-nav button:hover:not([disabled]) {
        border-color: var(--inbox-accent);
    }

    .message-detail-open-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.78rem;
        color: var(--inbox-accent);
        padding: 0.2rem 0.75rem;
        border-radius: 999px;
        border: 1px dashed rgba(37, 99, 235, 0.4);
        background: transparent;
        cursor: pointer;
    }

    .message-detail-open-link:hover,
    .message-detail-open-link:focus-visible {
        border-style: solid;
        background: rgba(37, 99, 235, 0.08);
    }

    .message-action-overflow {
        display: none;
    }

    .message-quick-actions.is-condensed .message-action-list {
        display: none;
    }

    .message-quick-actions.is-condensed .message-action-overflow {
        display: inline-flex;
    }

    .message-quick-actions.is-condensed .message-action-list {
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 0.4rem;
        flex-direction: column;
        background: var(--inbox-shell);
        border: 1px solid var(--inbox-divider);
        border-radius: calc(var(--inbox-radius) - 10px);
        padding: 0.5rem;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.15);
    }

    .message-action-hint {
        display: none;
        font-size: 0.7rem;
        color: var(--inbox-muted);
    }

    .message-quick-actions.is-condensed .message-action-hint {
        display: block;
        width: 100%;
    }

    .message-attachments span {
        font-size: 0.75rem;
        background: var(--inbox-surface);
        border-radius: 999px;
        padding: 0.25rem 0.6rem;
        border: 1px solid var(--inbox-divider);
        display: inline-flex;
        gap: 0.25rem;
    }

    .message-detail {
        background: var(--inbox-surface);
        border-top: 1px solid var(--inbox-divider);
        padding: 0;
        min-height: 220px;
    }

    .message-detail-shell {
        padding: 1.35rem 1.5rem 1.6rem;
        display: flex;
        flex-direction: column;
        gap: 1.1rem;
    }

    .message-detail-empty {
        text-align: center;
        color: var(--inbox-muted);
        padding: 2rem 1rem;
    }

    .message-detail-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .message-detail-author {
        display: flex;
        align-items: center;
        gap: 0.85rem;
    }

    .message-detail-author-block {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .message-detail-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: hsl(var(--avatar-hue, 220), 65%, 48%);
        color: #fff;
        font-weight: 600;
        font-size: 1rem;
        display: grid;
        place-items: center;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
    }

    .message-detail-author span {
        display: block;
        color: var(--inbox-muted);
        font-size: 0.82rem;
    }

    .message-detail-headline {
        margin-top: 0.45rem;
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .message-detail-headline strong {
        font-size: 1.1rem;
    }

    .message-detail-headline small {
        color: var(--inbox-muted);
        font-size: 0.8rem;
    }

    .message-detail-toolbar {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        align-items: flex-end;
    }

    .message-detail-nav {
        display: inline-flex;
        gap: 0.35rem;
    }

    .message-detail-nav button {
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        background: #fff;
        font-size: 0.78rem;
        padding: 0.3rem 0.85rem;
        cursor: pointer;
    }

    .message-detail-nav button[disabled] {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .message-detail-action-group {
        display: inline-flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .message-detail-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.75rem;
        padding: 0.9rem 1rem;
        border: 1px solid var(--inbox-divider);
        border-radius: calc(var(--inbox-radius) - 12px);
        background: #fff;
    }

    .message-detail-meta-item span {
        display: block;
        text-transform: uppercase;
        font-size: 0.65rem;
        letter-spacing: 0.08em;
        color: var(--inbox-muted);
        margin-bottom: 0.25rem;
    }

    .message-detail-meta-item p {
        margin: 0;
        font-size: 0.9rem;
    }

    .message-detail-tabs {
        display: inline-flex;
        gap: 0.45rem;
        margin: 0.2rem 0 0.6rem;
    }

    .message-detail-tab {
        border-radius: 999px;
        border: 1px solid var(--inbox-divider);
        background: #fff;
        padding: 0.3rem 0.9rem;
        font-size: 0.8rem;
        cursor: pointer;
    }

    .message-detail-tab.is-active {
        border-color: var(--inbox-accent);
        color: var(--inbox-accent);
    }

    .message-detail-body {
        background: var(--inbox-shell);
        border: 1px solid var(--inbox-divider);
        border-radius: calc(var(--inbox-radius) - 10px);
        padding: 0.9rem;
        min-height: 180px;
    }

    .message-detail-pane {
        display: none;
    }

    .message-detail-pane.is-active {
        display: block;
    }

    .message-detail-pane pre {
        margin: 0;
        font-family: "IBM Plex Mono", Consolas, monospace;
        white-space: pre-wrap;
        font-size: 0.85rem;
        line-height: 1.5;
    }

    .message-detail-pane iframe {
        width: 100%;
        min-height: 320px;
        border: none;
        border-radius: calc(var(--inbox-radius) - 12px);
        background: #fff;
    }

    .message-detail-attachments {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .message-detail-attachment {
        border-radius: 0.7rem;
        border: 1px solid var(--inbox-divider);
        padding: 0.45rem 0.85rem;
        text-decoration: none;
        color: var(--inbox-ink);
        background: #fff;
        font-size: 0.8rem;
    }

    .message-detail-attachment:hover {
        border-color: var(--inbox-accent);
    }

    .composer-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(3px);
        z-index: 80;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }

    .composer-backdrop.is-visible {
        opacity: 1;
        pointer-events: all;
    }

    .composer-panel {
        position: fixed;
        bottom: 1.5rem;
        right: 1.75rem;
        width: min(520px, 100% - 2rem);
        background: var(--inbox-shell);
        border-radius: 1.25rem;
        box-shadow: 0 35px 70px rgba(15, 23, 42, 0.25);
        transform: translateY(110%);
        transition: transform 0.3s ease;
        z-index: 90;
        color: var(--inbox-ink);
        border: 1px solid var(--inbox-divider);
        padding: 1.25rem;
    }

    .composer-panel.is-visible {
        transform: translateY(0);
    }

    .composer-panel header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .composer-close {
        border: none;
        background: var(--inbox-pill);
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
        cursor: pointer;
    }

    .composer-form {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .composer-field label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--inbox-muted);
    }

    .composer-field input,
    .composer-field select,
    .composer-field textarea {
        border-radius: calc(var(--inbox-radius) - 10px);
        border: 1px solid var(--inbox-divider);
        padding: 0.6rem 0.8rem;
        font-size: 0.9rem;
        background: var(--inbox-surface);
        color: var(--inbox-ink);
    }

    .composer-attachment-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        font-size: 0.78rem;
        color: var(--inbox-muted);
    }

    .composer-attachment-list .forward-attachment-pill {
        background: rgba(37, 99, 235, 0.08);
        border: 1px solid rgba(37, 99, 235, 0.25);
        border-radius: 999px;
        padding: 0.35rem 0.75rem;
        color: var(--inbox-accent);
    }

    .composer-actions {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .composer-actions button {
        border-radius: 999px;
        border: 1px solid transparent;
        padding: 0.55rem 1.2rem;
        font-size: 0.9rem;
        cursor: pointer;
    }

    .composer-send {
        background: var(--inbox-accent);
        color: #fff;
        box-shadow: 0 15px 30px rgba(37, 99, 235, 0.3);
    }

    .composer-draft {
        border-color: var(--inbox-divider);
        background: #fff;
    }

    .composer-discard {
        border-color: var(--inbox-divider);
        background: transparent;
        color: var(--inbox-muted);
    }

    .composer-status {
        font-size: 0.82rem;
        min-height: 1rem;
    }

    .composer-status[data-status="error"] {
        color: #d92d20;
    }

    .composer-status[data-status="success"] {
        color: #039855;
    }

    .inbox-empty {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--inbox-shell);
        border-radius: var(--inbox-radius);
        border: 1px dashed var(--inbox-divider);
    }

    .muted-text {
        color: var(--inbox-muted);
        font-size: 0.95rem;
    }

    .inbox-alert {
        margin-bottom: 0.75rem;
        padding: 0.85rem 1rem;
        border-radius: 12px;
        border: 1px solid var(--inbox-divider);
        background: rgba(37, 99, 235, 0.12);
        color: var(--inbox-ink);
    }

    .inbox-alert[data-state="error"] {
        background: rgba(220, 38, 38, 0.14);
        border-color: rgba(220, 38, 38, 0.35);
    }

    .inbox-alert[data-state="success"] {
        background: rgba(34, 197, 94, 0.14);
        border-color: rgba(34, 197, 94, 0.35);
    }

    .thread-load-more {
        margin: 0.75rem 0 0;
        width: 100%;
        border-radius: 12px;
        border: 1px solid var(--inbox-divider);
        background: var(--inbox-shell);
        padding: 0.75rem 1rem;
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .thread-load-more[disabled] {
        opacity: 0.55;
        cursor: not-allowed;
    }

    @media (max-width: 1200px) {
        .inbox-layout {
            grid-template-columns: 1fr;
        }

        .thread-pane,
        .inbox-reader {
            min-height: auto;
        }

        .thread-list {
            max-height: none;
            flex: 1;
        }

        .message-scroll {
            max-height: none;
        }
    }

    @media (max-width: 620px) {
        .inbox-shell {
            padding: 1rem;
        }

        .toolbar-filters {
            grid-template-columns: 1fr;
        }

        .toolbar-metrics {
            flex-direction: column;
        }

        .thread-search-primary {
            flex-direction: column;
        }
    }
</style>
<div
    class="inbox-shell"
    data-inbox-root
    data-csrf="<?=$csrfToken;?>"
    data-inbox-config="<?=htmlspecialchars(json_encode($inboxConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');?>"
>
    <div class="inbox-alert" data-inbox-alert hidden></div>
    <header class="inbox-toolbar">
        <div class="toolbar-primary">
            <form class="toolbar-filters" method="GET" action="<?=url('email/inbox');?>">
                <div class="filter-block">
                    <label for="command-account">Conta conectada</label>
                    <div class="filter-field">
                        <select id="command-account" name="account_id" onchange="this.form.submit()">
                            <?php foreach ($accounts as $account): ?>
                                <?php $id = (int)($account['id'] ?? 0); ?>
                                <option value="<?=$id;?>" <?=$id === (int)$activeAccountId ? 'selected' : '';?>><?=$escape($account['name'] ?? 'Mailbox #'.$id);?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-block">
                    <label for="command-search">Pesquisar</label>
                    <div class="filter-field">
                        <span aria-hidden="true">&#128269;</span>
                        <input
                            id="command-search"
                            type="search"
                            name="q"
                            placeholder="Assunto, contato ou conteúdo"
                            value="<?=$escape($searchQuery ?? '');?>"
                        >
                    </div>
                </div>
                <input type="hidden" name="folder_id" value="<?=$activeFolderId !== null ? (int)$activeFolderId : '';?>">
                <?php if ($isStandalone): ?>
                    <input type="hidden" name="standalone" value="1">
                <?php endif; ?>
            </form>
            <div class="toolbar-metrics">
                <article class="toolbar-metric">
                    <span>Não lidas</span>
                    <strong><?=$totalUnread;?></strong>
                    <small>Total na conta</small>
                </article>
                <article class="toolbar-metric">
                    <span>Threads com alerta</span>
                    <strong><?=$threadWithUnread;?></strong>
                    <small>Novas respostas</small>
                </article>
                <article class="toolbar-metric">
                    <span>Pasta ativa</span>
                    <strong><?=$escape($activeFolderName);?></strong>
                    <small><?=$escape($activeAccountName);?></small>
                </article>
            </div>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="is-primary" data-compose-trigger>Nova mensagem</button>
            <button type="button" data-compose-window-trigger>Novo email</button>
            <div class="toolbar-sync-group">
                <button type="button" data-sync-trigger>Enviar e receber</button>
                <span class="toolbar-sync-status" data-sync-status data-state="idle" hidden></span>
            </div>
            <button type="button" data-selection-select-all>Selecionar tudo</button>
            <button type="button" data-selection-trash disabled>Enviar para lixeira</button>
            <button type="button" data-selection-archive disabled>Arquivar selecionadas</button>
            <button type="button" data-selection-star disabled>Favoritar selecionadas</button>
            <button type="button" data-selection-mark-read disabled>Marcar como lidas</button>
            <button type="button" data-selection-local disabled>Caixa local</button>
            <div class="command-move-group">
                <select data-selection-move-select>
                    <option value="">Mover selecionadas...</option>
                    <?php foreach ($folderSummaries as $folder): ?>
                        <option value="<?=$folder['id'];?>"><?=$escape($folder['display_name']);?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" data-selection-move disabled>Mover</button>
            </div>
            <button
                type="button"
                class="trash-preview-trigger"
                data-trash-preview-toggle
                data-disabled="<?=$trashFolderId === null ? 'true' : 'false';?>"
            >
                Caixinha "Apagar"
                <span class="trash-preview-trigger-count" data-trash-preview-count>0</span>
            </button>
            <div
                class="trash-preview-popover"
                data-trash-preview-popover
                hidden
            >
                <div class="trash-preview-header">
                    <div>
                        <strong>Caixinha "Apagar"</strong>
                        <small>Essas conversas já estão na lixeira e serão removidas em definitivo.</small>
                    </div>
                    <div class="trash-preview-meta">
                        <button type="button" class="trash-preview-refresh" data-trash-preview-refresh>Atualizar</button>
                        <button type="button" class="trash-preview-close" data-trash-preview-close>Fechar</button>
                    </div>
                </div>
                <div class="trash-preview-list" data-trash-preview-list>
                    <p class="muted-text">Nenhuma conversa na lixeira.</p>
                </div>
                <div class="trash-preview-footer">
                    <button
                        type="button"
                        class="trash-preview-empty"
                        data-trash-empty
                        <?=$trashFolderId === null ? 'disabled' : '';?>
                    >Limpar lixeira</button>
                    <p class="trash-preview-warning" data-trash-empty-status>
                        Essa ação remove todas as conversas definitivamente.
                    </p>
                </div>
            </div>
        </div>
        <div class="command-selection" data-selection-indicator hidden>
            <span><strong data-selection-count>0</strong> conversas selecionadas</span>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <button type="button" data-selection-trash>Enviar para lixeira</button>
                <button type="button" data-selection-archive>Arquivar</button>
                <button type="button" data-selection-mark-read>Marcar como lidas</button>
                <button type="button" data-selection-local>Caixa local</button>
                <button type="button" data-selection-clear>Limpar seleção</button>
            </div>
        </div>
    </header>

    <?php if ($accounts === []): ?>
        <div class="inbox-empty">
            <h2>No email accounts connected</h2>
            <p class="muted-text">Add an IMAP mailbox in the marketing area to unlock the inbox experience.</p>
        </div>
    <?php else: ?>
        <div class="inbox-layout">
            <aside class="inbox-sidebar">
                <div class="sidebar-heading">
                    <p>Conta ativa</p>
                    <h3><?=$escape($activeAccountName);?></h3>
                    <small><?=$totalUnread;?> mensagens não lidas</small>
                </div>
                <div class="sidebar-section">
                    <h4>Pastas</h4>
                    <div class="folder-list">
                        <button
                            class="folder-chip <?=$activeFolderId === null ? 'is-active' : '';?>"
                            data-folder-trigger
                            data-folder-id=""
                            type="button"
                        >
                            <div>
                                <strong>Todas as pastas</strong>
                                <small>Conta: <?=$escape($activeAccountName);?></small>
                            </div>
                        </button>
                        <button
                            class="folder-chip"
                            data-folder-trigger
                            data-folder-id="local"
                            type="button"
                        >
                            <div>
                                <strong>Caixa local</strong>
                                <small>Guardadas no navegador</small>
                            </div>
                            <span class="inbox-badge is-hidden" data-folder-badge="local">0</span>
                        </button>
                        <?php foreach ($folders as $folder): ?>
                            <?php $folderId = (int)($folder['id'] ?? 0); ?>
                            <?php $folderUnread = (int)($folder['unread_count'] ?? 0); ?>
                            <?php $folderDisplay = $formatFolderDisplayName($folder); ?>
                            <?php $folderTypeName = $folderTypeLabel($inferFolderType($folder)); ?>
                            <button
                                class="folder-chip <?=$folderId === (int)$activeFolderId ? 'is-active' : '';?>"
                                data-folder-trigger
                                data-folder-id="<?=$folderId;?>"
                                type="button"
                            >
                                <div>
                                    <strong><?=$escape($folderDisplay);?></strong>
                                    <small><?=$escape($folderTypeName);?></small>
                                </div>
                                <span
                                    class="inbox-badge <?=$folderUnread === 0 ? 'is-hidden' : '';?>"
                                    data-folder-badge="<?=$folderId;?>"
                                ><?=$folderUnread;?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>

            <section class="thread-pane">
                <div class="thread-pane-header">
                    <div>
                        <p>Fila de mensagens</p>
                        <h2><?=$escape($activeFolderName);?></h2>
                    </div>
                    <p><?=$threadWithUnread;?> threads com novas respostas</p>
                </div>
                <div class="thread-search" data-search-panel>
                    <div class="thread-search-primary">
                        <label class="thread-search-field">
                            <span class="thread-search-icon" aria-hidden="true">&#128269;</span>
                            <input
                                type="search"
                                placeholder="Buscar por assunto, conteúdo ou contato"
                                data-search-query
                                value="<?=$escape($searchQuery ?? '');?>"
                            >
                        </label>
                        <button type="button" class="thread-search-clear" data-search-clear disabled>
                            <span aria-hidden="true">&#10005;</span>
                            Limpar filtros
                        </button>
                    </div>
                    <div class="thread-quick-filters" data-quick-filters>
                        <button type="button" class="quick-filter-chip" data-quick-filter="unread" aria-pressed="false">
                            <span class="chip-icon" aria-hidden="true">&#128386;</span>
                            <span>Não lidas</span>
                        </button>
                        <button type="button" class="quick-filter-chip" data-quick-filter="attachments" aria-pressed="false">
                            <span class="chip-icon" aria-hidden="true">&#128206;</span>
                            <span>Com anexos</span>
                        </button>
                        <button type="button" class="quick-filter-chip" data-quick-filter="mentions" aria-pressed="false">
                            <span class="chip-icon" aria-hidden="true">&#128172;</span>
                            <span>Mencionou você</span>
                        </button>
                    </div>
                    <div class="thread-search-filters">
                        <label class="filter-field">
                            <span class="filter-label">Participante</span>
                            <input type="text" placeholder="nome ou e-mail" data-search-participant>
                        </label>
                        <label class="filter-pill">
                            <input type="checkbox" data-search-unread>
                            <span>Somente não lidas</span>
                        </label>
                        <label class="filter-pill">
                            <input type="checkbox" data-search-attachments>
                            <span>Com anexos</span>
                        </label>
                        <label class="filter-pill">
                            <input type="checkbox" data-search-mentions>
                            <span>Mencionou você</span>
                        </label>
                        <label class="filter-field filter-date">
                            <span class="filter-label">De</span>
                            <input type="date" placeholder="dd/mm/aaaa" data-search-date-from>
                            <small class="filter-hint">dd/mm/aaaa</small>
                        </label>
                        <label class="filter-field filter-date">
                            <span class="filter-label">Até</span>
                            <input type="date" placeholder="dd/mm/aaaa" data-search-date-to>
                            <small class="filter-hint">dd/mm/aaaa</small>
                        </label>
                    </div>
                    <p class="thread-search-status" data-search-status>Visualizando por pasta</p>
                </div>
                <div class="thread-list" data-thread-list>
                    <?php if ($threads === []): ?>
                        <p class="muted-text">No threads yet for this view.</p>
                    <?php else: ?>
                        <?php foreach ($threads as $thread): ?>
                            <?php $threadId = (int)($thread['id'] ?? 0); ?>
                            <?php $threadFlags = $thread['flags'] ?? []; ?>
                            <?php $isStarred = in_array('flagged', $threadFlags, true) || in_array('starred', $threadFlags, true); ?>
                            <article
                                class="thread-card <?=$threadId === (int)$activeThreadId ? 'is-active' : '';?>"
                                data-thread-id="<?=$threadId;?>"
                            >
                                <div class="thread-card-head">
                                    <label class="thread-select">
                                        <input type="checkbox" data-thread-select value="<?=$threadId;?>">
                                        <span></span>
                                    </label>
                                    <div class="thread-head-content">
                                        <h3>
                                            <?php if ($isStarred): ?>
                                                <span class="thread-star">&#9733;</span>
                                            <?php endif; ?>
                                            <?=$escape($thread['subject'] ?? '(no subject)');?>
                                        </h3>
                                        <p><?=$escape($thread['snippet'] ?? 'No preview captured yet.');?></p>
                                    </div>
                                    <div class="thread-row-actions">
                                        <button
                                            type="button"
                                            class="thread-mini-action"
                                            data-thread-row-star
                                            data-thread-action-id="<?=$threadId;?>"
                                            data-starred="<?=$isStarred ? '1' : '0';?>"
                                            title="Alternar estrela"
                                        ><span class="thread-mini-icon" aria-hidden="true"><?=$isStarred ? '&#9733;' : '&#9734;';?></span></button>
                                        <button
                                            type="button"
                                            class="thread-mini-action"
                                            data-thread-row-window
                                            data-thread-action-id="<?=$threadId;?>"
                                            title="Abrir em nova janela"
                                        ><span class="thread-mini-icon" aria-hidden="true">&#8599;</span></button>
                                        <button
                                            type="button"
                                            class="thread-mini-action"
                                            data-thread-row-trash
                                            data-thread-action-id="<?=$threadId;?>"
                                            title="Enviar para lixeira"
                                        ><span class="thread-mini-icon" aria-hidden="true">&#128465;</span></button>
                                    </div>
                                </div>
                                <div class="thread-meta">
                                    <?php $threadFolderLabel = $formatFolderDisplayName($thread['folder'] ?? []); ?>
                                    <span>
                                        <span class="dot"></span>
                                        <?=isset($thread['last_message_at']) && $thread['last_message_at'] ? date('d M H:i', (int)$thread['last_message_at']) : 'pending';?>
                                    </span>
                                    <span>
                                        <?=$escape($threadFolderLabel);?>
                                        <?php if ((int)($thread['unread_count'] ?? 0) > 0): ?>
                                            <strong><?=$thread['unread_count'];?> novas</strong>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="thread-load-more" data-thread-load-more>Carregar mais</button>
            </section>

            <?php
            $threadMoveOptions = '<option value="">Mover para...</option>';
            foreach ($folders as $folderOption) {
                $optionId = (int)($folderOption['id'] ?? 0);
                $optionLabel = $formatFolderDisplayName($folderOption);
                $threadMoveOptions .= '<option value="' . $optionId . '">' . $escape($optionLabel) . '</option>';
            }
            ?>

            <section class="inbox-reader message-panel">
                <div class="message-panel-header" data-thread-header>
                    <?php if ($activeThread !== null): ?>
                        <?php $activeFlags = $activeThread['flags'] ?? []; ?>
                        <?php $activeStarred = in_array('flagged', $activeFlags, true) || in_array('starred', $activeFlags, true); ?>
                        <?php
                            $threadLastActivity = isset($activeThread['last_message_at']) && $activeThread['last_message_at']
                                ? date('d M H:i', (int)$activeThread['last_message_at'])
                                : null;
                            $threadUnreadCount = (int)($activeThread['unread_count'] ?? 0);
                            $threadFolderLabel = $formatFolderDisplayName($activeThread['folder'] ?? []);
                        ?>
                        <div class="message-panel-title">
                            <div class="message-panel-heading">
                                <h2><?=$escape($activeThread['subject'] ?? '(no subject)');?></h2>
                                <span>Conversa #<?=$activeThread['id'];?> · <?=$escape($threadFolderLabel);?></span>
                                <div class="message-panel-summary">
                                    <span class="message-chip">
                                        <span class="message-chip-label">Pasta</span>
                                        <strong><?=$escape($threadFolderLabel);?></strong>
                                    </span>
                                    <?php if ($threadUnreadCount > 0): ?>
                                        <span class="message-chip message-chip-alert">
                                            <span class="message-chip-label">Não lidas</span>
                                            <strong><?=$threadUnreadCount;?></strong>
                                        </span>
                                    <?php endif; ?>
                                    <span class="message-chip">
                                        <span class="message-chip-label">Atualizado</span>
                                        <strong><?=$threadLastActivity ? $escape($threadLastActivity) : 'Sem data';?></strong>
                                    </span>
                                </div>
                            </div>
                            <div class="thread-header-controls">
                                <div class="thread-reply-group" data-thread-reply-group>
                                    <button
                                        type="button"
                                        class="thread-reply-button"
                                        data-thread-reply
                                        data-reply-mode="reply"
                                    >Responder</button>
                                    <button
                                        type="button"
                                        class="thread-reply-caret"
                                        data-thread-reply-menu-toggle
                                        aria-haspopup="true"
                                        aria-expanded="false"
                                        title="Mais opções de resposta"
                                    >&#9662;</button>
                                    <div class="thread-reply-menu" data-thread-reply-menu hidden>
                                        <button type="button" data-reply-mode="reply">Responder</button>
                                        <button type="button" data-reply-mode="reply_all">Responder a todos</button>
                                        <button type="button" data-reply-mode="forward">Encaminhar</button>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inbox-mark-read"
                                    data-mark-read
                                    <?=((int)($activeThread['unread_count'] ?? 0)) === 0 ? 'disabled' : '';?>
                                >Marcar como lida</button>
                                <div class="thread-actions" data-thread-actions>
                                    <button
                                        type="button"
                                        class="thread-action-button <?=$activeStarred ? 'is-active' : '';?>"
                                        data-thread-star
                                        data-starred="<?=$activeStarred ? '1' : '0';?>"
                                    ><?=$activeStarred ? 'Remover estrela' : 'Favoritar';?></button>
                                    <button
                                        type="button"
                                        class="thread-action-button"
                                        data-thread-archive
                                    >Arquivar</button>
                                    <button
                                        type="button"
                                        class="thread-action-button"
                                        data-thread-trash
                                    >Lixeira</button>
                                    <div class="thread-move-control">
                                        <select data-thread-move-select>
                                            <?=$threadMoveOptions;?>
                                        </select>
                                        <button type="button" class="thread-action-button" data-thread-move disabled>Mover</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="message-panel-title">
                            <div class="message-panel-heading">
                                <h2>Selecione uma conversa</h2>
                                <span>As mensagens aparecem aqui.</span>
                            </div>
                            <div class="thread-header-controls">
                                <button type="button" class="thread-reply-button" data-thread-reply disabled>Responder</button>
                                <button type="button" class="inbox-mark-read" data-mark-read disabled>Marcar como lida</button>
                                <div class="thread-actions">
                                    <button type="button" class="thread-action-button" disabled>Favoritar</button>
                                    <button type="button" class="thread-action-button" disabled>Arquivar</button>
                                    <button type="button" class="thread-action-button" disabled>Lixeira</button>
                                    <div class="thread-move-control">
                                        <select disabled>
                                            <option>Mover para...</option>
                                        </select>
                                        <button type="button" class="thread-action-button" disabled>Mover</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="message-scroll" data-message-stack>
                    <?php if ($messages === []): ?>
                        <p class="muted-text">No messages to display.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <?php $isOutbound = ($message['direction'] ?? 'inbound') === 'outbound'; ?>
                            <article class="message-bubble <?=$isOutbound ? 'from-me' : 'from-them';?>">
                                <header>
                                    <strong><?=$escape($message['subject'] ?? '(no subject)');?></strong>
                                    <span><?=isset($message['sent_at']) && $message['sent_at'] ? date('d M H:i', (int)$message['sent_at']) : 'unscheduled';?></span>
                                </header>
                                <p><?=$escape($message['body_preview'] ?? $message['snippet'] ?? 'No preview available.');?></p>
                                <?php if (!empty($message['attachments'])): ?>
                                    <div class="message-attachments">
                                        <?php foreach ($message['attachments'] as $attachment): ?>
                                            <span><?=$escape($attachment['filename']);?> · <?=$formatBytes((int)($attachment['size_bytes'] ?? 0));?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="message-detail" data-message-detail>
                    <div class="message-detail-empty">
                        <p>Selecione uma mensagem para visualizar o conteúdo completo.</p>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <div class="composer-backdrop" data-composer-backdrop hidden></div>
    <section class="composer-panel" data-composer-panel aria-hidden="true">
        <header>
            <h3>Compor mensagem</h3>
            <button type="button" class="composer-close" data-composer-close>&times;</button>
        </header>
        <form class="composer-form" data-composer-form autocomplete="off">
            <input type="hidden" data-composer-draft-id>
            <input type="hidden" data-composer-forward-attachments>
            <div class="composer-field">
                <label for="composer-account">Conta</label>
                <select id="composer-account" data-composer-account>
                    <?php foreach ($accounts as $account): ?>
                        <?php $id = (int)($account['id'] ?? 0); ?>
                        <option value="<?=$id;?>" <?=$id === (int)$activeAccountId ? 'selected' : '';?>><?=$escape($account['name'] ?? 'Mailbox #'.$id);?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="composer-field">
                <div class="composer-recipient-label">
                    <label for="composer-to">Para</label>
                    <span class="composer-recipient-toggles">
                        <button type="button" class="composer-recipient-toggle" data-composer-toggle-cc aria-pressed="false">Cc</button>
                        <button type="button" class="composer-recipient-toggle" data-composer-toggle-bcc aria-pressed="false">Bcc</button>
                    </span>
                </div>
                <input id="composer-to" type="text" placeholder="nome@empresa.com" data-composer-to>
            </div>
            <div class="composer-field composer-recipient-row" data-composer-cc-row hidden>
                <label for="composer-cc">Cc</label>
                <input id="composer-cc" type="text" placeholder="colega@empresa.com" data-composer-cc>
            </div>
            <div class="composer-field composer-recipient-row" data-composer-bcc-row hidden>
                <label for="composer-bcc">Bcc</label>
                <input id="composer-bcc" type="text" placeholder="diretoria@empresa.com" data-composer-bcc>
            </div>
            <div class="composer-field">
                <label for="composer-subject">Assunto</label>
                <input id="composer-subject" type="text" placeholder="Linha do assunto" data-composer-subject>
            </div>
            <div class="composer-field">
                <label>Mensagem</label>
                <textarea id="composer-body-editor" placeholder="Digite sua mensagem" data-composer-body></textarea>
            </div>
            <div class="composer-field">
                <label for="composer-attachments">Anexos</label>
                <input id="composer-attachments" type="file" multiple data-composer-attachments>
                <div class="composer-attachment-list" data-composer-attachment-list>
                    <span>Nenhum anexo selecionado.</span>
                </div>
            </div>
            <p class="composer-status" data-composer-status></p>
            <div class="composer-actions">
                <div style="display:flex; gap:0.55rem; flex-wrap:wrap;">
                    <button type="button" class="composer-send" data-composer-send>Enviar</button>
                    <button type="button" class="composer-draft" data-composer-draft>Salvar rascunho</button>
                </div>
                <button type="button" class="composer-discard" data-composer-discard>Descartar</button>
            </div>
        </form>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    const root = document.querySelector('[data-inbox-root]');
    if (!root) {
        return;
    }

    // Captura qualquer erro de script ou promessa não tratada e exibe na página para debug rápido.
    const renderFatal = (label, errorObj) => {
        const message = errorObj && errorObj.message ? errorObj.message : String(errorObj || 'Erro desconhecido');
        const stack = errorObj && errorObj.stack ? String(errorObj.stack) : '';
        const banner = document.createElement('div');
        banner.style.background = '#fee2e2';
        banner.style.color = '#b91c1c';
        banner.style.padding = '12px';
        banner.style.margin = '12px';
        banner.style.border = '1px solid #fecdd3';
        banner.style.borderRadius = '8px';
        banner.style.fontFamily = 'sans-serif';
        banner.style.whiteSpace = 'pre-wrap';
        banner.textContent = `${label}: ${message}${stack ? '\n' + stack : ''}`;
        document.body.prepend(banner);
    };

    window.addEventListener('error', (event) => {
        renderFatal('Erro de script', event.error || event.message || event);
    });

    window.addEventListener('unhandledrejection', (event) => {
        renderFatal('Erro em promessa', event.reason || event);
    });

    try {

    const safeRun = (label, fn) => {
        try {
            fn();
        } catch (error) {
            console.error(`[Inbox] erro em ${label}`, error);
            if (typeof showInboxAlert === 'function') {
                showInboxAlert(`Erro ao inicializar (${label})`, 'error', 8000);
            }
        }
    };


    const configValue = root.getAttribute('data-inbox-config') || '{}';
    let config;
    try {
        config = JSON.parse(configValue);
    } catch (error) {
        config = {};
    }

    // Normalize endpoints when base path (e.g., /public) changes or config is empty.
    const inferBase = () => {
        const path = window.location.pathname || '';
        const anchor = '/email/inbox';
        const index = path.indexOf(anchor);
        return index >= 0 ? path.slice(0, index) : '';
    };

    const basePrefix = inferBase();
    const ensureRoute = (value, suffix) => {
        const hasValue = typeof value === 'string' && value.trim() !== '';
        if (hasValue) {
            const trimmed = value.trim();
            if (basePrefix && trimmed.startsWith('/') && !trimmed.startsWith(basePrefix + '/')) {
                return `${basePrefix}${trimmed}`;
            }
            return trimmed;
        }
        return `${basePrefix}/email/inbox${suffix}`;
    };

    const r = config && config.routes ? config.routes : {};
    const normalizedRoutes = {
        threads: ensureRoute(r.threads, '/threads'),
        threadMessagesBase: ensureRoute(r.threadMessagesBase, '/threads'),
        markReadBase: ensureRoute(r.markReadBase, '/threads'),
        threadActionsBase: ensureRoute(r.threadActionsBase, '/threads'),
        threadStarBase: ensureRoute(r.threadStarBase, '/threads'),
        threadArchiveBase: ensureRoute(r.threadArchiveBase, '/threads'),
        threadMoveBase: ensureRoute(r.threadMoveBase, '/threads'),
        threadBulkActions: ensureRoute(r.threadBulkActions, '/threads/bulk-actions'),
        messageDetailBase: ensureRoute(r.messageDetailBase, '/messages'),
        messageStandaloneBase: ensureRoute(r.messageStandaloneBase, '/messages'),
        attachmentDownloadBase: ensureRoute(r.attachmentDownloadBase, '/attachments'),
        searchThreads: ensureRoute(r.searchThreads, '/search'),
        composeSend: ensureRoute(r.composeSend, '/compose'),
        composeDraft: ensureRoute(r.composeDraft, '/compose/draft'),
        composeDrafts: ensureRoute(r.composeDrafts, '/compose/drafts'),
        composeWindow: ensureRoute(r.composeWindow, '/compose/window'),
        accountSyncBase: ensureRoute(r.accountSyncBase, '/accounts'),
        emptyTrash: ensureRoute(r.emptyTrash, '/trash/empty'),
    };

    config.routes = normalizedRoutes;

    const csrfToken = root.getAttribute('data-csrf') || '';

    const FOLDER_TYPE_LABELS = {
        inbox: 'Caixa de entrada',
        sent: 'Enviados',
        drafts: 'Rascunhos',
        spam: 'Spam',
        trash: 'Lixeira',
        archive: 'Arquivados',
        important: 'Importante',
        custom: 'Pasta',
    };

    const FOLDER_NAME_TRANSLATIONS = {
        'all mail': 'Todos os e-mails',
        'todos os e mails': 'Todos os e-mails',
        'sent mail': 'Enviados',
        sent: 'Enviados',
        drafts: 'Rascunhos',
        spam: 'Spam',
        junk: 'Spam',
        trash: 'Lixeira',
        bin: 'Lixeira',
        important: 'Importante',
        starred: 'Com estrela',
        marcados: 'Com estrela',
        archive: 'Arquivados',
        arquivados: 'Arquivados',
    };

    const normalizeFolderKey = (value) => {
        if (!value) {
            return '';
        }
        let normalized = String(value).trim();
        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return normalized
            .toLowerCase()
            .replace(/[^a-z0-9 ]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const stripProviderPrefix = (label) => {
        if (!label) {
            return '';
        }
        return String(label)
            .replace(/^\s*\[[^\]]+\]\s*\/?/i, '')
            .replace(/^\s*inbox\s*\/?/i, '')
            .trim();
    };

    const translateFolderLabel = (label, type = '') => {
        const cleaned = stripProviderPrefix(label);
        const key = normalizeFolderKey(cleaned);
        if (key && FOLDER_NAME_TRANSLATIONS[key]) {
            return FOLDER_NAME_TRANSLATIONS[key];
        }
        const typeKey = normalizeFolderKey(type) || String(type || '').toLowerCase();
        if (typeKey && FOLDER_TYPE_LABELS[typeKey]) {
            return FOLDER_TYPE_LABELS[typeKey];
        }
        if (cleaned) {
            return cleaned;
        }
        return FOLDER_TYPE_LABELS.custom;
    };

    const formatFolderLabelFromPayload = (folder) => {
        if (!folder || typeof folder !== 'object') {
            return FOLDER_TYPE_LABELS.custom;
        }
        const type = folder.type || '';
        const source = typeof folder.display_name === 'string'
            ? folder.display_name
            : (typeof folder.remote_name === 'string' ? folder.remote_name : '');
        return translateFolderLabel(source, type);
    };

    const toNumberOrNull = (value) => {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };

    const defaultSearchFilters = {
        query: '',
        participant: '',
        unreadOnly: false,
        hasAttachments: false,
        mentionsOnly: false,
        dateFrom: '',
        dateTo: '',
    };

    const TRASH_EMPTY_DEFAULT_MESSAGE = 'Essa ação remove todas as conversas definitivamente.';
    const TRASH_EMPTY_BUTTON_IDLE_LABEL = 'Limpar lixeira';
    const TRASH_EMPTY_BUTTON_BUSY_LABEL = 'Limpando...';
    const LOCAL_FOLDER_TOKEN = 'local';
    const LOCAL_FOLDER_LABEL = 'Caixa local';
    const LOCAL_STORAGE_KEY = 'inbox_local_threads';
    const MAX_LOCAL_THREADS = 200;
    const MAX_COMPOSER_ATTACHMENTS = 20;

    const initialFolderId = toNumberOrNull(config.folderId);
    const normalizedFolderId = Number.isFinite(initialFolderId) && initialFolderId > 0 ? initialFolderId : null;

    const THREAD_PAGE_SIZE = Number(config.threadLimit || 30);

    const state = {
        accountId: toNumberOrNull(config.accountId),
        folderId: normalizedFolderId,
        threadId: toNumberOrNull(config.threadId),
        selectedMessageId: toNumberOrNull(config.selectedMessageId),
        threadLimit: THREAD_PAGE_SIZE,
        routes: config.routes || {},
        folders: Array.isArray(config.folders) ? config.folders.map((folder) => {
            const normalized = {
                ...folder,
                id: toNumberOrNull(folder.id) ?? folder.id,
            };
            normalized.display_name = formatFolderLabelFromPayload(folder);
            return normalized;
        }) : [],
        archiveFolderId: toNumberOrNull(config.archiveFolderId),
        trashFolderId: toNumberOrNull(config.trashFolderId),
        autoCompose: !!config.autoCompose,
        accounts: Array.isArray(config.accounts) ? config.accounts.map((account) => {
            const normalized = {
                ...account,
                id: toNumberOrNull(account.id) ?? account.id,
            };
            const syncMeta = account && typeof account === 'object' ? account.sync : null;
            normalized.sync = {
                canSync: !!(syncMeta && syncMeta.canSync),
                imapEnabled: !!(syncMeta && syncMeta.imapEnabled),
                hasCredentials: !!(syncMeta && syncMeta.hasCredentials),
                reason: syncMeta && typeof syncMeta.reason === 'string' ? syncMeta.reason : null,
            };
            return normalized;
        }) : [],
        csrf: csrfToken,
        searchFilters: { ...defaultSearchFilters },
        searchMode: false,
        hasMoreThreads: false,
        currentThread: config.currentThread || null,
        currentThreadMessages: [],
        selectedThreads: new Set(),
        visibleThreadIds: [],
        visibleThreadsMap: new Map(),
        trashPreviewThreads: [],
        localFolderActive: false,
        localThreads: [],
    };

    state.localThreads = loadLocalThreadsFromStorage();
    updateLocalFolderBadge();

    const debounce = (fn, wait = 250) => {
        let timeoutId;
        return (...args) => {
            clearTimeout(timeoutId);
            timeoutId = window.setTimeout(() => fn(...args), wait);
        };
    };

    const folderButtons = root.querySelectorAll('[data-folder-trigger]');
    const threadList = root.querySelector('[data-thread-list]');
    const loadMoreButton = root.querySelector('[data-thread-load-more]');
    const inboxAlert = root.querySelector('[data-inbox-alert]');

    // Debug/diagnostic helpers to surface silent failures.
    console.info('[Inbox] routes', config.routes);

    window.addEventListener('error', (event) => {
        const msg = event && event.message ? event.message : 'Erro de script desconhecido';
        console.error('[Inbox] window error', event.error || msg, event);
    });

    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
        try {
            const response = await originalFetch(...args);
            if (!response.ok) {
                const target = typeof args[0] === 'string'
                    ? args[0]
                    : (args[0] && args[0].url) ? args[0].url : 'desconhecido';
                const message = `Falha ao chamar ${target} (HTTP ${response.status})`;
                if (typeof showInboxAlert === 'function') {
                    showInboxAlert(message, 'error', 6000);
                }
                console.error('[Inbox] fetch error', message);
            }
            return response;
        } catch (error) {
            const target = typeof args[0] === 'string'
                ? args[0]
                : (args[0] && args[0].url) ? args[0].url : 'desconhecido';
            const message = `Erro de rede em ${target}`;
            if (typeof showInboxAlert === 'function') {
                showInboxAlert(message, 'error', 6000);
            }
            console.error('[Inbox] network failure', error);
            throw error;
        }
    };
    const messageStack = root.querySelector('[data-message-stack]');
    const messageDetail = root.querySelector('[data-message-detail]');
    const threadHeader = root.querySelector('[data-thread-header]');
    let messageStackDoubleClickBound = false;
    let markReadButton = null;
    let replyMenuState = { menu: null, toggle: null };
    const quickActionState = {
        observer: null,
        openContainer: null,
        listenersBound: false,
        lastToggle: null,
        manualCondensed: new Map(),
    };
    const QUICK_ACTION_CONDENSE_WIDTH = 260;

    refreshLoadMoreVisibility(threadList ? threadList.querySelectorAll('[data-thread-id]').length : 0);

    let alertTimer = null;
    let trashPreviewOpen = false;
    let trashEmptyBusy = false;

    function canUseTrashPreview() {
        if (isLocalViewActive()) {
            return false;
        }
        return Number.isFinite(state.trashFolderId);
    }

    function syncTrashPreviewTriggerState() {
        if (!trashPreviewElements.trigger) {
            return;
        }
        const available = canUseTrashPreview();
        trashPreviewElements.trigger.dataset.disabled = available ? 'false' : 'true';
        trashPreviewElements.trigger.setAttribute('aria-disabled', available ? 'false' : 'true');
        trashPreviewElements.trigger.hidden = !available;
        if (!available) {
            closeTrashPreview();
        }
        syncTrashEmptyButtonState();
    }

    function setTrashPreviewOpen(nextOpen) {
        trashPreviewOpen = nextOpen && canUseTrashPreview();
        if (!trashPreviewElements.popover || !trashPreviewElements.trigger) {
            return;
        }
        trashPreviewElements.popover.hidden = !trashPreviewOpen;
        trashPreviewElements.trigger.setAttribute('aria-expanded', trashPreviewOpen ? 'true' : 'false');
        trashPreviewElements.trigger.classList.toggle('is-active', trashPreviewOpen);
        syncTrashEmptyButtonState();
    }

    function updateTrashEmptyStatus(message = TRASH_EMPTY_DEFAULT_MESSAGE, tone = 'muted') {
        if (!trashPreviewElements.status) {
            return;
        }
        const text = typeof message === 'string' && message.trim() !== ''
            ? message.trim()
            : TRASH_EMPTY_DEFAULT_MESSAGE;
        trashPreviewElements.status.textContent = text;
        trashPreviewElements.status.dataset.state = tone;
    }

    function syncTrashEmptyButtonState() {
        if (!trashPreviewElements.emptyButton) {
            return;
        }
        const available = canUseTrashPreview() && Boolean(state.routes.emptyTrash);
        const disabled = !available || trashEmptyBusy;
        trashPreviewElements.emptyButton.disabled = disabled;
        trashPreviewElements.emptyButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        trashPreviewElements.emptyButton.textContent = trashEmptyBusy
            ? TRASH_EMPTY_BUTTON_BUSY_LABEL
            : TRASH_EMPTY_BUTTON_IDLE_LABEL;
    }

    function setTrashEmptyBusy(nextBusy) {
        trashEmptyBusy = !!nextBusy;
        if (trashPreviewElements.emptyButton) {
            if (trashEmptyBusy) {
                trashPreviewElements.emptyButton.setAttribute('aria-busy', 'true');
            } else {
                trashPreviewElements.emptyButton.removeAttribute('aria-busy');
            }
        }
        syncTrashEmptyButtonState();
    }

    async function openTrashPreview() {
        if (!canUseTrashPreview()) {
            return;
        }
        setTrashPreviewOpen(true);
        await loadTrashPreview();
    }

    function closeTrashPreview(options = {}) {
        const { restoreFocus = false } = options;
        if (!trashPreviewOpen) {
            return;
        }
        setTrashPreviewOpen(false);
        if (restoreFocus && trashPreviewElements.trigger && document.contains(trashPreviewElements.trigger)) {
            trashPreviewElements.trigger.focus();
        }
    }

    function updateActiveFolderChip(targetFolderId) {
        if (!folderButtons || folderButtons.length === 0) {
            return;
        }
        folderButtons.forEach((button) => {
            const raw = button.getAttribute('data-folder-id');
            const buttonId = raw === null || raw === ''
                ? null
                : (raw === LOCAL_FOLDER_TOKEN ? LOCAL_FOLDER_TOKEN : toNumberOrNull(raw));
            const isActive = targetFolderId === null
                ? (raw === '' || buttonId === null)
                : (targetFolderId === LOCAL_FOLDER_TOKEN
                    ? buttonId === LOCAL_FOLDER_TOKEN
                    : (buttonId !== null && buttonId !== LOCAL_FOLDER_TOKEN && Number(buttonId) === Number(targetFolderId)));
            button.classList.toggle('is-active', !!isActive);
        });
    }

    const selectionElements = {
        indicator: root.querySelector('[data-selection-indicator]'),
        count: root.querySelector('[data-selection-count]'),
        clearButtons: root.querySelectorAll('[data-selection-clear]'),
        archiveButtons: root.querySelectorAll('[data-selection-archive]'),
        starButtons: root.querySelectorAll('[data-selection-star]'),
        markReadButtons: root.querySelectorAll('[data-selection-mark-read]'),
        trashButtons: root.querySelectorAll('[data-selection-trash]'),
        localButtons: root.querySelectorAll('[data-selection-local]'),
        selectAllButtons: root.querySelectorAll('[data-selection-select-all]'),
        moveSelect: root.querySelector('[data-selection-move-select]'),
        moveButton: root.querySelector('[data-selection-move]'),
    };
    const selectionState = {
        anchorThreadId: null,
    };

    const trashPreviewElements = {
        trigger: root.querySelector('[data-trash-preview-toggle]'),
        popover: root.querySelector('[data-trash-preview-popover]'),
        list: root.querySelector('[data-trash-preview-list]'),
        count: root.querySelector('[data-trash-preview-count]'),
        refresh: root.querySelector('[data-trash-preview-refresh]'),
        close: root.querySelector('[data-trash-preview-close]'),
        emptyButton: root.querySelector('[data-trash-empty]'),
        status: root.querySelector('[data-trash-empty-status]'),
    };

    updateActiveFolderChip(state.folderId);
    syncTrashPreviewTriggerState();
    setTrashPreviewOpen(false);
    updateTrashEmptyStatus();

    const syncControls = {
        button: root.querySelector('[data-sync-trigger]'),
        status: root.querySelector('[data-sync-status]'),
    };
    const syncState = {
        busy: false,
    };
    let syncStatusResetTimer = null;

    const composerElements = {
        panel: root.querySelector('[data-composer-panel]'),
        backdrop: root.querySelector('[data-composer-backdrop]'),
        form: root.querySelector('[data-composer-form]'),
        account: root.querySelector('[data-composer-account]'),
        to: root.querySelector('[data-composer-to]'),
        cc: root.querySelector('[data-composer-cc]'),
        bcc: root.querySelector('[data-composer-bcc]'),
        ccRow: root.querySelector('[data-composer-cc-row]'),
        bccRow: root.querySelector('[data-composer-bcc-row]'),
        toggleCc: root.querySelector('[data-composer-toggle-cc]'),
        toggleBcc: root.querySelector('[data-composer-toggle-bcc]'),
        subject: root.querySelector('[data-composer-subject]'),
        body: root.querySelector('[data-composer-body]'),
        attachments: root.querySelector('[data-composer-attachments]'),
        attachmentList: root.querySelector('[data-composer-attachment-list]'),
        sendButton: root.querySelector('[data-composer-send]'),
        draftButton: root.querySelector('[data-composer-draft]'),
        discardButton: root.querySelector('[data-composer-discard]'),
        closeButton: root.querySelector('[data-composer-close]'),
        status: root.querySelector('[data-composer-status]'),
        draftId: root.querySelector('[data-composer-draft-id]'),
        forwardAttachmentsInput: root.querySelector('[data-composer-forward-attachments]'),
        inlineTriggers: root.querySelectorAll('[data-compose-trigger]'),
        windowTriggers: root.querySelectorAll('[data-compose-window-trigger]'),
    };

    const composerState = {
        isOpen: false,
        draftId: null,
        threadId: null,
        accountId: state.accountId || null,
        dirty: false,
        autosaveTimer: null,
        autosaveBusy: false,
        manualBusy: false,
        lastAutosaveAt: null,
        forwardAttachmentIds: [],
        forwardAttachmentMeta: [],
        forwardAttachmentsPending: false,
        editorId: null,
        editorReady: false,
        editorInitializing: false,
        pendingBodyHtml: '',
        silencingBodyUpdate: false,
    };

    const searchElements = {
        panel: root.querySelector('[data-search-panel]'),
        query: root.querySelector('[data-search-query]'),
        participant: root.querySelector('[data-search-participant]'),
        unread: root.querySelector('[data-search-unread]'),
        attachments: root.querySelector('[data-search-attachments]'),
        mentions: root.querySelector('[data-search-mentions]'),
        dateFrom: root.querySelector('[data-search-date-from]'),
        dateTo: root.querySelector('[data-search-date-to]'),
        clear: root.querySelector('[data-search-clear]'),
        status: root.querySelector('[data-search-status]'),
    };
    const quickFilterButtons = root.querySelectorAll('[data-quick-filter]');
    const DAY_IN_MS = 24 * 60 * 60 * 1000;
    const THREAD_BUCKET_SEQUENCE = ['Hoje', 'Ontem', 'Esta semana', 'Este mês', 'Mais antigas', 'Sem data'];

    const debouncedSearch = debounce(() => {
        if (state.searchMode) {
            runSearch();
        }
    }, 350);

    renderMessageDetailPlaceholder();
    initializeSearchPanel();
    initQuickFilters();
    initializeComposer();
    if (state.autoCompose) {
        window.requestAnimationFrame(() => {
            openComposer();
            state.autoCompose = false;
        });
    }
    bindSelectionControls();
    updateSelectionUI();
    bindMessageStackDoubleClick();

    const escapeHtml = (input) => {
        return String(input || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const convertPlainTextToHtml = (value) => {
        if (!value) {
            return '';
        }
        const safe = escapeHtml(value);
        return `<p>${safe.replace(/\r?\n/g, '<br>')}</p>`;
    };

    const htmlToPlainText = (html) => {
        if (!html) {
            return '';
        }
        const container = document.createElement('div');
        container.innerHTML = html;
        return (container.textContent || container.innerText || '').trim();
    };

    const getSearchHighlightTokens = () => {
        const query = state.searchFilters && typeof state.searchFilters.query === 'string'
            ? state.searchFilters.query.trim()
            : '';
        if (!query) {
            return [];
        }
        return query
            .split(/\s+/)
            .map((token) => token.trim())
            .filter((token) => token.length >= 2)
            .slice(0, 5);
    };

    const highlightSearchText = (input) => {
        const tokens = getSearchHighlightTokens();
        const safe = escapeHtml(input || '');
        if (tokens.length === 0) {
            return safe;
        }
        const pattern = tokens
            .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join('|');
        if (!pattern) {
            return safe;
        }
        try {
            const regex = new RegExp(`(${pattern})`, 'gi');
            return safe.replace(regex, '<mark class="search-hit">$1</mark>');
        } catch (error) {
            return safe;
        }
    };

    const formatDate = (timestamp) => {
        if (!timestamp) {
            return 'pending';
        }
        const date = new Date(timestamp * 1000);
        return date.toLocaleString(undefined, {
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const formatBytes = (bytes) => {
        const thresholds = ['B', 'KB', 'MB', 'GB'];
        let size = bytes || 0;
        if (size <= 0) {
            return '0 B';
        }
        let index = Math.floor(Math.log(size) / Math.log(1024));
        index = Math.min(index, thresholds.length - 1);
        const value = size / Math.pow(1024, index);
        return `${value.toFixed(index === 0 ? 0 : 1)} ${thresholds[index]}`;
    };

    function loadLocalThreadsFromStorage() {
        if (typeof window === 'undefined' || !window.localStorage) {
            return [];
        }

        try {
            const raw = window.localStorage.getItem(LOCAL_STORAGE_KEY);
            if (!raw) {
                return [];
            }
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed
                .map((entry) => normalizeLocalThread(entry))
                .filter((entry) => entry !== null);
        } catch (error) {
            console.warn('Local inbox cache read failed', error);
            return [];
        }
    }

    function persistLocalThreads(threads) {
        if (typeof window === 'undefined' || !window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify((threads || []).slice(0, MAX_LOCAL_THREADS)));
        } catch (error) {
            console.warn('Local inbox cache write failed', error);
        }
    }

    function normalizeLocalThread(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        const id = toNumberOrNull(raw.id);
        if (!Number.isFinite(id)) {
            return null;
        }
        return {
            id,
            subject: raw.subject || '(sem assunto)',
            snippet: raw.snippet || '',
            folder: raw.folder || { id: LOCAL_FOLDER_TOKEN, type: 'local', display_name: LOCAL_FOLDER_LABEL },
            unread_count: Number(raw.unread_count || 0),
            last_message_at: raw.last_message_at || null,
            flags: Array.isArray(raw.flags) ? raw.flags : [],
            has_attachments: !!raw.has_attachments,
            attachments: Array.isArray(raw.attachments) ? raw.attachments : [],
            cached_at: raw.cached_at || Math.floor(Date.now() / 1000),
        };
    }

    function updateLocalFolderBadge() {
        const badge = root.querySelector('[data-folder-badge="local"]');
        if (!badge) {
            return;
        }
        const count = Array.isArray(state.localThreads) ? state.localThreads.length : 0;
        badge.textContent = String(count);
        badge.classList.toggle('is-hidden', count === 0);
    }

    function isLocalViewActive() {
        return !!state.localFolderActive;
    }

    function findThreadDataInDom(threadId) {
        if (!threadList) {
            return null;
        }
        const card = threadList.querySelector(`[data-thread-id="${threadId}"]`);
        if (!card) {
            return null;
        }
        const subjectEl = card.querySelector('h3');
        const snippetEl = card.querySelector('p');
        const subject = subjectEl ? subjectEl.textContent.trim() : '(sem assunto)';
        const snippet = snippetEl ? snippetEl.textContent.trim() : '';
        return normalizeLocalThread({
            id: threadId,
            subject,
            snippet,
            folder: { id: LOCAL_FOLDER_TOKEN, type: 'local', display_name: LOCAL_FOLDER_LABEL },
        });
    }

    function getThreadDataById(threadId) {
        const numericId = Number(threadId);
        if (!Number.isFinite(numericId)) {
            return null;
        }

        if (state.visibleThreadsMap && state.visibleThreadsMap.has(numericId)) {
            return state.visibleThreadsMap.get(numericId);
        }

        if (Array.isArray(state.localThreads)) {
            const localMatch = state.localThreads.find((item) => Number(item.id) === numericId);
            if (localMatch) {
                return localMatch;
            }
        }

        return findThreadDataInDom(numericId);
    }

    function storeThreadsLocally(threadIds) {
        const ids = Array.isArray(threadIds) ? threadIds : [];
        if (ids.length === 0) {
            return;
        }

        const existing = Array.isArray(state.localThreads) ? [...state.localThreads] : [];
        const merged = new Map(existing.map((item) => [Number(item.id), item]));

        ids.forEach((rawId) => {
            const numericId = Number(rawId);
            if (!Number.isFinite(numericId)) {
                return;
            }
            const thread = getThreadDataById(numericId);
            if (!thread) {
                return;
            }
            merged.set(numericId, normalizeLocalThread({
                ...thread,
                id: numericId,
                folder: { id: LOCAL_FOLDER_TOKEN, type: 'local', display_name: LOCAL_FOLDER_LABEL },
            }));
        });

        state.localThreads = Array.from(merged.values()).slice(0, MAX_LOCAL_THREADS);
        persistLocalThreads(state.localThreads);
        updateLocalFolderBadge();
    }

    function removeThreadsFromLocal(threadIds) {
        const ids = Array.isArray(threadIds) ? threadIds.map((value) => Number(value)).filter((value) => Number.isFinite(value)) : [];
        if (ids.length === 0 || !Array.isArray(state.localThreads)) {
            return;
        }

        const next = state.localThreads.filter((entry) => !ids.includes(Number(entry.id)));
        state.localThreads = next;
        persistLocalThreads(state.localThreads);
        updateLocalFolderBadge();
    }

    function renderLocalThreads(customThreads = null) {
        state.localFolderActive = true;
        state.folderId = null;
        state.searchMode = false;
        state.threadLimit = THREAD_PAGE_SIZE;
        clearThreadSelection();
        state.threadId = null;
        state.currentThread = null;
        state.currentThreadMessages = [];
        updateThreadHeader(null);
        renderMessageDetailPlaceholder();
        const collection = Array.isArray(customThreads) ? customThreads : state.localThreads;
        state.hasMoreThreads = false;
        renderThreads(collection || []);
        updateSearchStatus(collection ? collection.length : 0);
        updateActiveFolderChip(LOCAL_FOLDER_TOKEN);
        syncTrashPreviewTriggerState();
    }

    const deriveAvatarInitials = (input) => {
        const safe = String(input || '').trim();
        if (!safe) {
            return '??';
        }
        const parts = safe.split(/\s+/).slice(0, 2);
        const letters = parts
            .map((part) => part.charAt(0))
            .join('');
        if (letters) {
            return letters.toUpperCase();
        }
        return safe.slice(0, 2).toUpperCase();
    };

    const deriveAvatarHue = (input) => {
        const seed = String(input || 'inbox').trim() || 'inbox';
        let hash = 0;
        for (let i = 0; i < seed.length; i += 1) {
            hash = seed.charCodeAt(i) + ((hash << 5) - hash);
        }
        return Math.abs(hash) % 360;
    };

    function renderMessageDetailPlaceholder() {
        if (!messageDetail) {
            return;
        }
        messageDetail.innerHTML = `
            <div class="message-detail-shell">
                <div class="message-detail-empty">
                    <p>Selecione uma mensagem para visualizar o conteúdo completo.</p>
                </div>
            </div>
        `;
    }

    function setActiveBodyTab(type) {
        if (!messageDetail || !type) {
            return;
        }
        const panes = messageDetail.querySelectorAll('[data-body-pane]');
        panes.forEach((pane) => {
            const paneType = pane.getAttribute('data-body-pane');
            pane.classList.toggle('is-active', paneType === type);
        });

        const tabs = messageDetail.querySelectorAll('[data-body-tab]');
        tabs.forEach((tab) => {
            const tabType = tab.getAttribute('data-body-tab');
            tab.classList.toggle('is-active', tabType === type);
        });
    }

    function bindMessageDetailTabs() {
        if (!messageDetail) {
            return;
        }
        const tabs = messageDetail.querySelectorAll('[data-body-tab]');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const nextType = tab.getAttribute('data-body-tab');
                if (nextType) {
                    setActiveBodyTab(nextType);
                }
            });
        });
    }

    function bindMessageDetailActions(message) {
        if (!messageDetail || !message) {
            return;
        }
        const buttons = messageDetail.querySelectorAll('[data-message-detail-action]');
        buttons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const mode = button.getAttribute('data-reply-mode') || 'reply';
                handleReplyTrigger(event, mode, message);
            });
        });
    }

    function bindMessageDetailNav() {
        if (!messageDetail) {
            return;
        }
        const buttons = messageDetail.querySelectorAll('[data-message-detail-nav]');
        buttons.forEach((button) => {
            button.addEventListener('click', (event) => {
                if (button.hasAttribute('disabled')) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const direction = button.getAttribute('data-message-detail-nav');
                navigateMessageDetail(direction);
            });
        });
    }

    const MESSAGE_WINDOW_FEATURES = 'noopener,noreferrer,width=1280,height=900,resizable=yes,scrollbars=yes';

    function openMessageInWindow(messageId, urlOverride = null) {
        const numericId = Number(messageId);
        const hasId = Number.isFinite(numericId);
        const baseRoute = state.routes && state.routes.messageStandaloneBase
            ? String(state.routes.messageStandaloneBase)
            : '';
        const targetUrl = urlOverride
            || (hasId && baseRoute ? `${baseRoute}/${numericId}/view` : '');
        if (!targetUrl) {
            return;
        }
        const windowName = hasId ? `message_${numericId}` : `message_${Date.now()}`;
        const popup = window.open(targetUrl, windowName, MESSAGE_WINDOW_FEATURES);
        if (popup && typeof popup.focus === 'function') {
            popup.focus();
        }
    }

    function bindMessageWindowLauncher(message) {
        if (!messageDetail) {
            return;
        }
        const button = messageDetail.querySelector('[data-message-open-window]');
        if (!button) {
            return;
        }
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const url = button.getAttribute('data-window-url');
            openMessageInWindow(message && message.id ? message.id : null, url);
        });
    }

    function renderMessageDetail(payload) {
        if (!messageDetail) {
            return;
        }

        const message = payload && payload.message ? payload.message : null;
        if (!message) {
            renderMessageDetailPlaceholder();
            return;
        }

        const participants = Array.isArray(message.participants) ? message.participants : [];
        const fromParticipant = participants.find((participant) => participant.role === 'from') || {};
        const fromNameRaw = fromParticipant.name || fromParticipant.email || 'Remetente desconhecido';
        const fromEmailRaw = fromParticipant.email || '';
        const fromLabel = highlightSearchText(fromNameRaw);
        const fromEmail = fromEmailRaw ? escapeHtml(fromEmailRaw) : '—';
        const avatarSeed = fromParticipant.email || fromParticipant.name || fromNameRaw;
        const avatarInitials = deriveAvatarInitials(avatarSeed);
        const avatarHue = deriveAvatarHue(avatarSeed);

        const toLabelRaw = participants
            .filter((participant) => participant.role === 'to')
            .map((participant) => participant.name || participant.email || '')
            .filter(Boolean)
            .join(', ');
        const toLabel = toLabelRaw ? highlightSearchText(toLabelRaw) : '—';
        const ccLabelRaw = participants
            .filter((participant) => participant.role === 'cc')
            .map((participant) => participant.name || participant.email || '')
            .filter(Boolean)
            .join(', ');
        const ccLabel = ccLabelRaw ? highlightSearchText(ccLabelRaw) : '';

        const attachments = Array.isArray(message.attachments) ? message.attachments : [];
        const hasHtml = Boolean(message.body_html);
        const hasText = Boolean(message.body_text);
        const defaultTab = hasHtml ? 'html' : (hasText ? 'text' : null);

        const attachmentLinks = attachments.length > 0
            ? attachments.map((attachment) => {
                const base = state.routes.attachmentDownloadBase || '';
                const href = base ? `${base}/${attachment.id}/download` : '#';
                return `
                    <a class="message-detail-attachment" href="${href}" target="_blank" rel="noopener">
                        <span>${escapeHtml(attachment.filename || 'sem-nome')}</span>
                        <span>${formatBytes(attachment.size_bytes || 0)}</span>
                    </a>
                `;
            }).join('')
            : '<p class="muted-text">Nenhum anexo disponível.</p>';

        const subjectHtml = highlightSearchText(message.subject || '(sem assunto)');
        const bodyTextHtml = hasText ? highlightSearchText(message.body_text || '') : '';
        const folderLabel = formatFolderLabelFromPayload(message.folder);
        const folderDisplay = escapeHtml(folderLabel);
        const sentLabel = message.sent_at ? escapeHtml(formatDate(message.sent_at)) : '—';

        const metaRows = [
            { label: 'Para', value: toLabel },
            ccLabel ? { label: 'Cc', value: ccLabel } : null,
            { label: 'Pasta', value: folderDisplay },
            { label: 'Enviado', value: sentLabel },
        ].filter(Boolean);

        const metaMarkup = metaRows.map((row) => `
            <div class="message-detail-meta-item">
                <span>${row.label}</span>
                <p>${row.value}</p>
            </div>
        `).join('');

        const bodyPanes = `
            ${hasHtml ? '<div class="message-detail-pane" data-body-pane="html"><iframe data-body-html sandbox=""></iframe></div>' : ''}
            ${hasText ? `<div class="message-detail-pane" data-body-pane="text"><pre>${bodyTextHtml}</pre></div>` : ''}
            ${(!hasHtml && !hasText) ? '<p class="muted-text">Nenhum conteúdo capturado.</p>' : ''}
        `;

        const tabsMarkup = (hasHtml || hasText)
            ? `
                <div class="message-detail-tabs">
                    ${hasHtml ? '<button type="button" class="message-detail-tab" data-body-tab="html">HTML</button>' : ''}
                    ${hasText ? '<button type="button" class="message-detail-tab" data-body-tab="text">Texto</button>' : ''}
                </div>
            `
            : '';

        const prevMessageId = getAdjacentMessageId(message.id, -1);
        const nextMessageId = getAdjacentMessageId(message.id, 1);
        const detailNav = `
            <div class="message-detail-nav" role="group" aria-label="Navegar mensagens">
                <button
                    type="button"
                    data-message-detail-nav="prev"
                    ${prevMessageId ? '' : 'disabled'}
                    aria-label="Mensagem anterior"
                >Anterior</button>
                <button
                    type="button"
                    data-message-detail-nav="next"
                    ${nextMessageId ? '' : 'disabled'}
                    aria-label="Próxima mensagem"
                >Próxima</button>
            </div>
        `;

        const detailActions = `
            <div class="message-detail-action-group" role="group" aria-label="Atalhos desta mensagem">
                <button
                    type="button"
                    class="message-detail-action-button"
                    data-message-detail-action
                    data-reply-mode="reply"
                    aria-label="Responder esta mensagem"
                >Responder</button>
                <button
                    type="button"
                    class="message-detail-action-button"
                    data-message-detail-action
                    data-reply-mode="reply_all"
                    aria-label="Responder a todos desta mensagem"
                >Responder a todos</button>
                <button
                    type="button"
                    class="message-detail-action-button"
                    data-message-detail-action
                    data-reply-mode="forward"
                    aria-label="Encaminhar esta mensagem"
                >Encaminhar</button>
            </div>
        `;

        const openInNewTab = state.routes.messageStandaloneBase
            ? `
                <button
                    type="button"
                    class="message-detail-open-link"
                    data-message-open-window
                    data-window-url="${state.routes.messageStandaloneBase}/${message.id}/view"
                    aria-label="Abrir mensagem em nova janela"
                >Abrir em nova janela &#8599;</button>
            `
            : '';

        messageDetail.innerHTML = `
            <div class="message-detail-shell">
                <header class="message-detail-header">
                    <div class="message-detail-author-block">
                        <div class="message-detail-author">
                            <span class="message-detail-avatar" style="--avatar-hue:${avatarHue};">${avatarInitials}</span>
                            <div>
                                <strong>${fromLabel}</strong>
                                <span>${fromEmail}</span>
                            </div>
                        </div>
                        <div class="message-detail-headline">
                            <strong>${subjectHtml}</strong>
                            <small>${sentLabel} · ${folderDisplay}</small>
                        </div>
                    </div>
                    <div class="message-detail-toolbar">
                        ${detailNav}
                        ${detailActions}
                        ${openInNewTab}
                    </div>
                </header>
                <div class="message-detail-meta-grid">
                    ${metaMarkup}
                </div>
                ${tabsMarkup}
                <div class="message-detail-body">
                    ${bodyPanes}
                </div>
                <div class="message-detail-attachments">
                    ${attachmentLinks}
                </div>
            </div>
        `;

        const iframe = messageDetail.querySelector('[data-body-html]');
        if (iframe && hasHtml) {
            iframe.srcdoc = message.body_html;
        }

        bindMessageDetailTabs();
        bindMessageDetailActions(message);
        bindMessageDetailNav();
        bindMessageWindowLauncher(message);
        if (defaultTab) {
            setActiveBodyTab(defaultTab);
        } else {
            const panes = messageDetail.querySelectorAll('[data-body-pane]');
            panes.forEach((pane) => pane.classList.add('is-active'));
        }
    }

    function initializeSearchPanel() {
        if (!searchElements.panel) {
            return;
        }

        state.searchFilters = collectSearchFilters();
        state.searchMode = hasActiveSearchFilters(state.searchFilters);
        toggleClearButton(state.searchMode);
        updateSearchStatus();
        syncQuickFilterButtons();

        if (searchElements.query) {
            searchElements.query.addEventListener('input', () => handleSearchInputChange(true));
        }

        if (searchElements.participant) {
            searchElements.participant.addEventListener('input', () => handleSearchInputChange(true));
        }

        if (searchElements.unread) {
            searchElements.unread.addEventListener('change', () => handleSearchInputChange());
        }

        if (searchElements.attachments) {
            searchElements.attachments.addEventListener('change', () => handleSearchInputChange());
        }

        if (searchElements.mentions) {
            searchElements.mentions.addEventListener('change', () => handleSearchInputChange());
        }

        if (searchElements.dateFrom) {
            searchElements.dateFrom.addEventListener('change', () => handleSearchInputChange());
        }

        if (searchElements.dateTo) {
            searchElements.dateTo.addEventListener('change', () => handleSearchInputChange());
        }

        if (searchElements.clear) {
            searchElements.clear.addEventListener('click', () => {
                clearSearchInputs();
                state.searchFilters = { ...defaultSearchFilters };
                state.searchMode = false;
                toggleClearButton(false);
                updateSearchStatus();
                syncQuickFilterButtons();
                refreshThreads();
            });
        }

        if (state.searchMode) {
            runSearch();
        }
    }

    function collectSearchFilters() {
        return {
            query: searchElements.query ? searchElements.query.value.trim() : '',
            participant: searchElements.participant ? searchElements.participant.value.trim() : '',
            unreadOnly: searchElements.unread ? searchElements.unread.checked : false,
            hasAttachments: searchElements.attachments ? searchElements.attachments.checked : false,
            mentionsOnly: searchElements.mentions ? searchElements.mentions.checked : false,
            dateFrom: searchElements.dateFrom ? searchElements.dateFrom.value : '',
            dateTo: searchElements.dateTo ? searchElements.dateTo.value : '',
        };
    }

    function hasActiveSearchFilters(filters) {
        if (!filters) {
            return false;
        }

        return Boolean(
            filters.query
            || filters.participant
            || filters.unreadOnly
            || filters.hasAttachments
            || filters.mentionsOnly
            || filters.dateFrom
            || filters.dateTo
        );
    }

    function handleSearchInputChange(useDebounce = false) {
        state.searchFilters = collectSearchFilters();
        state.threadLimit = THREAD_PAGE_SIZE;
        const active = hasActiveSearchFilters(state.searchFilters);
        state.searchMode = active;
        toggleClearButton(active);
        updateSearchStatus();
        syncQuickFilterButtons();

        if (active) {
            if (useDebounce) {
                debouncedSearch();
            } else {
                runSearch();
            }
        } else {
            refreshThreads();
        }
    }

    function clearSearchInputs() {
        if (searchElements.query) {
            searchElements.query.value = '';
        }
        if (searchElements.participant) {
            searchElements.participant.value = '';
        }
        if (searchElements.unread) {
            searchElements.unread.checked = false;
        }
        if (searchElements.attachments) {
            searchElements.attachments.checked = false;
        }
        if (searchElements.mentions) {
            searchElements.mentions.checked = false;
        }
        if (searchElements.dateFrom) {
            searchElements.dateFrom.value = '';
        }
        if (searchElements.dateTo) {
            searchElements.dateTo.value = '';
        }
        syncQuickFilterButtons();
    }

    function toggleClearButton(active) {
        if (!searchElements.clear) {
            return;
        }
        searchElements.clear.disabled = !active;
    }

    function initQuickFilters() {
        if (!quickFilterButtons || quickFilterButtons.length === 0) {
            return;
        }

        quickFilterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.getAttribute('data-quick-filter');
                if (!key) {
                    return;
                }
                const nextState = button.getAttribute('aria-pressed') !== 'true';
                applyQuickFilter(key, nextState);
            });
        });

        syncQuickFilterButtons();
    }

    function syncQuickFilterButtons() {
        if (!quickFilterButtons) {
            return;
        }
        const filters = state.searchFilters || defaultSearchFilters;
        quickFilterButtons.forEach((button) => {
            const key = button.getAttribute('data-quick-filter');
            const active = isQuickFilterActive(key, filters);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.classList.toggle('is-active', active);
        });
    }

    function isQuickFilterActive(key, filters) {
        if (!key) {
            return false;
        }
        const current = filters || state.searchFilters || defaultSearchFilters;
        switch (key) {
            case 'unread':
                return Boolean(current.unreadOnly);
            case 'attachments':
                return Boolean(current.hasAttachments);
            case 'mentions':
                return Boolean(current.mentionsOnly);
            default:
                return false;
        }
    }

    function applyQuickFilter(key, enabled) {
        if (!key) {
            return;
        }

        if (key === 'unread' && searchElements.unread) {
            searchElements.unread.checked = enabled;
        }
        if (key === 'attachments' && searchElements.attachments) {
            searchElements.attachments.checked = enabled;
        }
        if (key === 'mentions' && searchElements.mentions) {
            searchElements.mentions.checked = enabled;
        }

        handleSearchInputChange();
    }

    function buildSearchSummary() {
        const parts = [];
        if (state.searchFilters.query) {
            parts.push(`&ldquo;${escapeHtml(state.searchFilters.query)}&rdquo;`);
        }
        if (state.searchFilters.participant) {
            parts.push(`Contato: ${escapeHtml(state.searchFilters.participant)}`);
        }
        if (state.searchFilters.unreadOnly) {
            parts.push('Não lidas');
        }
        if (state.searchFilters.hasAttachments) {
            parts.push('Com anexos');
        }
        if (state.searchFilters.mentionsOnly) {
            parts.push('Mencionou você');
        }
        if (state.searchFilters.dateFrom || state.searchFilters.dateTo) {
            const from = state.searchFilters.dateFrom ? escapeHtml(state.searchFilters.dateFrom) : 'sempre';
            const to = state.searchFilters.dateTo ? escapeHtml(state.searchFilters.dateTo) : 'hoje';
            parts.push(`De ${from} até ${to}`);
        }

        return parts.join(' · ');
    }

    function updateSearchStatus(total = null) {
        if (!searchElements.status) {
            return;
        }

        if (!state.searchMode) {
            searchElements.status.textContent = 'Visualizando por pasta';
            searchElements.status.classList.remove('is-active');
            return;
        }

        const countLabel = total === null ? '...' : String(total);
        const plural = countLabel === '1' ? '' : 's';
        const summary = buildSearchSummary();
        let text = `Filtrando ${countLabel} thread${plural}`;
        if (summary) {
            text += ` · ${summary}`;
        }
        searchElements.status.innerHTML = text;
        searchElements.status.classList.add('is-active');
    }

    async function refreshThreads() {
        if (isLocalViewActive()) {
            if (state.searchMode) {
                runLocalSearch();
            } else {
                renderLocalThreads();
            }
            return;
        }

        if (state.searchMode) {
            await runSearch();
            return;
        }

        await loadThreads();
    }

    async function runSearch() {
        if (!state.routes.searchThreads || !threadList) {
            return;
        }

        if (isLocalViewActive()) {
            runLocalSearch();
            return;
        }

        const filters = state.searchFilters || defaultSearchFilters;
        const params = new URLSearchParams();
        if (state.accountId) {
            params.set('account_id', state.accountId);
        }
        if (state.folderId !== null && state.folderId !== undefined) {
            params.set('folder_id', state.folderId);
        }

        params.set('limit', String(state.threadLimit));

        if (filters.query) {
            params.set('q', filters.query);
        }
        if (filters.participant) {
            params.set('participant', filters.participant);
        }
        if (filters.unreadOnly) {
            params.set('unread', '1');
        }
        if (filters.hasAttachments) {
            params.set('has_attachments', '1');
        }
        if (filters.mentionsOnly) {
            params.set('mentions', '1');
        }
        if (filters.dateFrom) {
            params.set('date_from', filters.dateFrom);
        }
        if (filters.dateTo) {
            params.set('date_to', filters.dateTo);
        }

        threadList.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(`${state.routes.searchThreads}?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch search results');
            }

            const payload = await response.json();
            renderThreads(payload.threads || []);
            state.hasMoreThreads = Array.isArray(payload.threads)
                ? (payload.threads.length >= state.threadLimit)
                : false;
            const total = payload.meta && typeof payload.meta.total === 'number'
                ? payload.meta.total
                : (payload.threads ? payload.threads.length : 0);
            updateSearchStatus(total);
        } catch (error) {
            console.error('Inbox search request failed', error);
            threadList.innerHTML = '<p class="muted-text">Erro ao buscar threads.</p>';
        } finally {
            threadList.removeAttribute('aria-busy');
        }
    }

    function runLocalSearch() {
        const filters = state.searchFilters || defaultSearchFilters;
        const collection = Array.isArray(state.localThreads) ? state.localThreads : [];
        const query = (filters.query || '').toLowerCase();

        const filtered = collection.filter((thread) => {
            const subject = (thread.subject || '').toLowerCase();
            const snippet = (thread.snippet || '').toLowerCase();
            const matchesQuery = !query || subject.includes(query) || snippet.includes(query);
            const unreadMatch = !filters.unreadOnly || Number(thread.unread_count || 0) > 0;
            const attachmentMatch = !filters.hasAttachments || !!thread.has_attachments;
            return matchesQuery && unreadMatch && attachmentMatch;
        });

        renderThreads(filtered);
        state.hasMoreThreads = false;
        updateSearchStatus(filtered.length);
    }

    function bindMarkReadButton() {
        if (markReadButton) {
            markReadButton.removeEventListener('click', handleMarkReadClick);
        }

        markReadButton = root.querySelector('[data-mark-read]');
        if (markReadButton) {
            markReadButton.addEventListener('click', handleMarkReadClick);
        }
    }

    function bindThreadActions() {
        closeReplyMenu();
        if (!threadHeader) {
            return;
        }

        const actionsRoot = threadHeader.querySelector('[data-thread-actions]');
        if (!actionsRoot) {
            return;
        }

        const starButton = actionsRoot.querySelector('[data-thread-star]');
        const archiveButton = actionsRoot.querySelector('[data-thread-archive]');
        const trashButton = actionsRoot.querySelector('[data-thread-trash]');
        const { moveSelect, moveButton } = getMoveControls();
        const replyButton = threadHeader.querySelector('[data-thread-reply]');
        const replyToggle = threadHeader.querySelector('[data-thread-reply-menu-toggle]');
        const replyMenu = threadHeader.querySelector('[data-thread-reply-menu]');
        const replyOptions = replyMenu ? replyMenu.querySelectorAll('[data-reply-mode]') : [];

        if (starButton) {
            starButton.addEventListener('click', handleStarToggle);
        }
        if (archiveButton) {
            archiveButton.addEventListener('click', handleArchive);
        }
        if (trashButton) {
            trashButton.addEventListener('click', handleTrash);
        }
        if (moveSelect && moveButton) {
            moveButton.disabled = moveSelect.value === '';
            moveSelect.addEventListener('change', () => {
                moveButton.disabled = moveSelect.value === '';
            });
            moveButton.addEventListener('click', () => handleMove(moveSelect.value));
        }
        if (replyButton) {
            replyButton.addEventListener('click', (event) => handleReplyTrigger(event));
        }
        if (replyToggle && replyMenu) {
            replyToggle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (replyToggle.hasAttribute('disabled')) {
                    return;
                }
                toggleReplyMenu(replyToggle, replyMenu);
            });
        }
        replyOptions.forEach((option) => {
            option.addEventListener('click', (event) => {
                const mode = option.getAttribute('data-reply-mode') || 'reply';
                handleReplyTrigger(event, mode);
            });
        });
    }

    function toggleReplyMenu(toggle, menu) {
        if (replyMenuState.menu === menu) {
            closeReplyMenu();
        } else {
            openReplyMenu(toggle, menu);
        }
    }

    function openReplyMenu(toggle, menu) {
        closeReplyMenu();
        replyMenuState = { menu, toggle };
        menu.hidden = false;
        menu.classList.add('is-visible');
        toggle.setAttribute('aria-expanded', 'true');
        document.addEventListener('click', handleReplyMenuDismiss, true);
        document.addEventListener('keydown', handleReplyMenuKeydown);
    }

    function closeReplyMenu() {
        if (replyMenuState.menu) {
            replyMenuState.menu.classList.remove('is-visible');
            replyMenuState.menu.hidden = true;
        }
        if (replyMenuState.toggle) {
            replyMenuState.toggle.setAttribute('aria-expanded', 'false');
        }
        replyMenuState = { menu: null, toggle: null };
        document.removeEventListener('click', handleReplyMenuDismiss, true);
        document.removeEventListener('keydown', handleReplyMenuKeydown);
    }

    function handleReplyMenuDismiss(event) {
        if (!replyMenuState.menu) {
            return;
        }
        const target = event.target;
        if (replyMenuState.menu.contains(target) || (replyMenuState.toggle && replyMenuState.toggle.contains(target))) {
            return;
        }
        closeReplyMenu();
    }

    function handleReplyMenuKeydown(event) {
        if (event.key === 'Escape') {
            closeReplyMenu();
        }
    }

    function buildFolderOptions() {
        if (!Array.isArray(state.folders) || state.folders.length === 0) {
            return '<option value="">Mover para...</option>';
        }

        const currentFolderId = state.currentThread && state.currentThread.folder && state.currentThread.folder.id
            ? Number(state.currentThread.folder.id)
            : null;

        const options = state.folders.map((folder) => {
            const folderId = toNumberOrNull(folder.id);
            const isSelected = currentFolderId !== null && folderId !== null && currentFolderId === folderId;
            const value = folderId !== null ? String(folderId) : '';
            const label = escapeHtml(formatFolderLabelFromPayload(folder));
            return `<option value="${value}" ${isSelected ? 'selected' : ''}>${label}</option>`;
        }).join('');

        return `<option value="">Mover para...</option>${options}`;
    }

    function getMoveControls() {
        if (!threadHeader) {
            return { moveSelect: null, moveButton: null };
        }
        return {
            moveSelect: threadHeader.querySelector('[data-thread-move-select]'),
            moveButton: threadHeader.querySelector('[data-thread-move]'),
        };
    }

    function normalizeFolderPayload(folder) {
        if (!folder || typeof folder !== 'object') {
            return null;
        }
        const id = toNumberOrNull(folder.id);
        if (id === null) {
            return null;
        }

        return {
            id,
            display_name: formatFolderLabelFromPayload(folder),
            remote_name: folder.remote_name || null,
            type: folder.type || 'custom',
            unread_count: Number(folder.unread_count || 0),
            total_count: Number.isFinite(Number(folder.total_count)) ? Number(folder.total_count) : Number(folder.unread_count || 0),
        };
    }

    function upsertFolderSummary(folder) {
        if (!folder) {
            return;
        }
        const index = state.folders.findIndex((item) => Number(item.id) === folder.id);
        if (index >= 0) {
            state.folders[index] = { ...state.folders[index], ...folder };
        } else {
            state.folders.push(folder);
        }
    }

    function syncFolderBadgesFromResponse(payload) {
        if (!payload) {
            return;
        }
        ['folder', 'source_folder', 'target_folder'].forEach((key) => {
            if (payload[key]) {
                updateFolderBadge(payload[key]);
            }
        });
    }

    function setButtonBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = !!busy;
        if (busy) {
            button.setAttribute('aria-busy', 'true');
        } else {
            button.removeAttribute('aria-busy');
        }
    }

    function setSyncButtonBusy(busy) {
        if (!syncControls.button) {
            return;
        }
        syncState.busy = !!busy;
        const capability = resolveSyncCapability();
        const shouldDisable = syncState.busy || !capability.canSync;
        syncControls.button.disabled = shouldDisable;
        syncControls.button.setAttribute('aria-disabled', shouldDisable ? 'true' : 'false');
        if (syncState.busy) {
            syncControls.button.setAttribute('aria-busy', 'true');
        } else {
            syncControls.button.removeAttribute('aria-busy');
        }
    }

    function setSyncStatus(message, stateValue = 'idle') {
        if (!syncControls.status) {
            return;
        }
        const normalized = typeof message === 'string' ? message.trim() : '';
        syncControls.status.textContent = normalized;
        syncControls.status.dataset.state = stateValue;
        syncControls.status.hidden = normalized === '';
    }

    function scheduleSyncStatusReset(delay = 2500) {
        if (syncStatusResetTimer) {
            window.clearTimeout(syncStatusResetTimer);
        }
        syncStatusResetTimer = window.setTimeout(() => {
            syncStatusResetTimer = null;
            if (syncState.busy) {
                return;
            }
            refreshSyncControlsAvailability();
        }, delay);
    }

    function buildSyncEndpoint() {
        if (!state.routes.accountSyncBase || !state.accountId) {
            return null;
        }
        return `${state.routes.accountSyncBase}/${state.accountId}/sync`;
    }

    function resolveActiveRemoteFolderName() {
        if (!Array.isArray(state.folders) || state.folders.length === 0) {
            return null;
        }
        if (state.folderId === null || state.folderId === undefined || state.folderId === LOCAL_FOLDER_TOKEN) {
            return null;
        }
        const folderId = Number(state.folderId);
        if (!Number.isFinite(folderId)) {
            return null;
        }
        const folder = state.folders.find((entry) => Number(entry.id) === folderId);
        if (!folder) {
            return null;
        }
        const remoteName = typeof folder.remote_name === 'string' ? folder.remote_name.trim() : '';
        return remoteName === '' ? null : remoteName;
    }

    function resolveSyncCapability() {
        const account = getAccountById(state.accountId);
        if (!account) {
            return {
                canSync: false,
                message: 'Nenhuma conta ativa selecionada para sincronizar.',
            };
        }

        const syncMeta = account.sync || {};
        if (syncMeta.canSync) {
            return {
                canSync: true,
                message: 'Atualizado automaticamente',
            };
        }

        if (typeof syncMeta.reason === 'string' && syncMeta.reason.trim() !== '') {
            return {
                canSync: false,
                message: syncMeta.reason,
            };
        }

        if (!syncMeta.imapEnabled) {
            return {
                canSync: false,
                message: 'Sincronização IMAP está desativada para esta conta.',
            };
        }

        if (!syncMeta.hasCredentials) {
            return {
                canSync: false,
                message: 'Complete host, usuário e senha IMAP para usar Enviar e receber.',
            };
        }

        return {
            canSync: false,
            message: 'Sincronização manual indisponível para esta conta.',
        };
    }

    function refreshSyncControlsAvailability() {
        const capability = resolveSyncCapability();

        if (syncControls.button && !syncState.busy) {
            syncControls.button.disabled = !capability.canSync;
            syncControls.button.setAttribute('aria-disabled', capability.canSync ? 'false' : 'true');
            syncControls.button.title = capability.canSync
                ? 'Sincronizar agora'
                : (capability.message || 'Sincronização indisponível');
        }

        if (syncControls.status && !syncState.busy) {
            if (capability.canSync) {
                setSyncStatus('', 'idle');
            } else if (capability.message) {
                setSyncStatus(capability.message, 'warning');
            }
        }
    }

    async function handleManualSync() {
        const capability = resolveSyncCapability();
        if (!capability.canSync) {
            if (capability.message) {
                setSyncStatus(capability.message, 'warning');
            }
            refreshSyncControlsAvailability();
            return;
        }

        setSyncButtonBusy(true);
        setSyncStatus('Sincronizando...', 'loading');

        const endpoint = buildSyncEndpoint();
        if (!endpoint) {
            await refreshThreads();
            setSyncStatus('Atualizado agora', 'success');
            scheduleSyncStatusReset();
            setSyncButtonBusy(false);
            return;
        }

        try {
            const form = new FormData();
            if (state.csrf) {
                form.append('_token', state.csrf);
            }
            form.append('mode', 'async');
            const activeRemoteFolder = resolveActiveRemoteFolderName();
            const syncLimit = activeRemoteFolder ? 150 : 200;
            const lookbackDays = activeRemoteFolder ? 120 : 365;
            form.append('limit', String(syncLimit));
            form.append('lookback_days', String(lookbackDays));
            if (activeRemoteFolder) {
                form.append('folders', activeRemoteFolder);
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': state.csrf || '',
                },
                body: form,
            });

            let payload = null;
            try {
                payload = await response.clone().json();
            } catch (parseError) {
                payload = null;
            }

            if (!response.ok) {
                let errorMessage = 'Falha na sincronização manual.';
                if (payload && typeof payload.error === 'string' && payload.error.trim() !== '') {
                    errorMessage = payload.error.trim();
                } else {
                    try {
                        const fallbackText = await response.text();
                        if (fallbackText && fallbackText.trim() !== '') {
                            errorMessage = fallbackText.trim();
                        }
                    } catch (textError) {
                        console.warn('Não foi possível ler a resposta de erro da sincronização.', textError);
                    }
                }
                throw new Error(errorMessage);
            }

            if (payload === null) {
                payload = await response.json();
            }

            if (payload && payload.status === 'queued') {
                const queuedMessage = typeof payload.message === 'string' && payload.message.trim() !== ''
                    ? payload.message.trim()
                    : 'Sincronização em segundo plano iniciada.';
                setSyncStatus(queuedMessage, 'success');
                scheduleSyncStatusReset(6000);
                window.setTimeout(() => {
                    refreshThreads();
                }, 4000);
                return;
            }

            if (Array.isArray(payload.folders)) {
                payload.folders.forEach((folder) => updateFolderBadge(folder));
            }

            await refreshThreads();
            setSyncStatus('Atualizado agora', 'success');
            scheduleSyncStatusReset();
        } catch (error) {
            console.error('Manual mailbox sync failed', error);
            const message = error instanceof Error && error.message ? error.message : 'Falha ao sincronizar';
            setSyncStatus(message, 'error');
        } finally {
            setSyncButtonBusy(false);
            if (!resolveSyncCapability().canSync) {
                refreshSyncControlsAvailability();
            }
        }
    }

    function bindSelectionControls() {
        selectionElements.clearButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                clearThreadSelection();
            });
        });

        selectionElements.archiveButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                handleBulkArchive();
            });
        });

        selectionElements.starButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                handleBulkStar();
            });
        });

        selectionElements.markReadButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                handleBulkMarkRead();
            });
        });

        selectionElements.trashButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                handleBulkTrash();
            });
        });

        selectionElements.selectAllButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                selectAllVisibleThreads();
            });
        });

        selectionElements.localButtons.forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                await handleBulkLocalAction();
            });
        });

        if (selectionElements.moveSelect) {
            selectionElements.moveSelect.addEventListener('change', () => {
                updateSelectionUI();
            });
        }

        if (selectionElements.moveButton) {
            selectionElements.moveButton.addEventListener('click', (event) => {
                event.preventDefault();
                handleBulkMove();
            });
        }
    }

    function toggleThreadSelection(threadId, selected) {
        if (!Number.isFinite(threadId)) {
            return;
        }

        if (selected) {
            state.selectedThreads.add(threadId);
        } else {
            state.selectedThreads.delete(threadId);
        }

        syncCardSelection(threadId, selected);
        updateSelectionUI();
    }

    function getVisibleThreadIndex(threadId) {
        const ids = Array.isArray(state.visibleThreadIds) ? state.visibleThreadIds : [];
        const numericId = Number(threadId);
        if (!Number.isFinite(numericId)) {
            return -1;
        }
        return ids.findIndex((id) => Number(id) === numericId);
    }

    function applyThreadRangeSelection(anchorId, targetId, selected) {
        if (!Number.isFinite(Number(anchorId)) || !Number.isFinite(Number(targetId))) {
            return;
        }
        const ids = Array.isArray(state.visibleThreadIds) ? state.visibleThreadIds : [];
        if (!ids.length) {
            return;
        }

        const anchorIndex = getVisibleThreadIndex(anchorId);
        const targetIndex = getVisibleThreadIndex(targetId);
        if (anchorIndex === -1 || targetIndex === -1) {
            return;
        }

        const start = Math.min(anchorIndex, targetIndex);
        const end = Math.max(anchorIndex, targetIndex);
        for (let i = start; i <= end; i += 1) {
            const id = Number(ids[i]);
            if (!Number.isFinite(id)) {
                continue;
            }
            if (selected) {
                state.selectedThreads.add(id);
            } else {
                state.selectedThreads.delete(id);
            }
            syncCardSelection(id, selected);
        }

        updateSelectionUI();
        selectionState.anchorThreadId = Number(targetId);
    }

    function selectAllVisibleThreads() {
        const ids = Array.isArray(state.visibleThreadIds) ? state.visibleThreadIds : [];
        if (!ids.length) {
            return;
        }

        ids.forEach((value) => {
            const threadId = Number(value);
            if (!Number.isFinite(threadId)) {
                return;
            }
            state.selectedThreads.add(threadId);
            syncCardSelection(threadId, true);
        });

        const lastId = Number(ids[ids.length - 1]);
        selectionState.anchorThreadId = Number.isFinite(lastId) ? lastId : selectionState.anchorThreadId;
        updateSelectionUI();
    }

    function syncCardSelection(threadId, selected) {
        if (!threadList) {
            return;
        }
        const card = threadList.querySelector(`[data-thread-id="${threadId}"]`);
        if (!card) {
            return;
        }
        card.classList.toggle('is-selected', selected);
        const checkbox = card.querySelector('[data-thread-select]');
        if (checkbox) {
            checkbox.checked = selected;
        }
    }

    function clearThreadSelection() {
        state.selectedThreads.clear();
        selectionState.anchorThreadId = null;
        if (threadList) {
            threadList.querySelectorAll('[data-thread-select]').forEach((checkbox) => {
                checkbox.checked = false;
            });
            threadList.querySelectorAll('[data-thread-id]').forEach((card) => {
                card.classList.remove('is-selected');
            });
        }
        updateSelectionUI();
    }

    function updateSelectionUI() {
        const count = state.selectedThreads.size;
        if (selectionElements.count) {
            selectionElements.count.textContent = String(count);
        }

        if (selectionElements.indicator) {
            selectionElements.indicator.hidden = count === 0;
        }

        const disableBulk = count === 0;
        selectionElements.archiveButtons.forEach((button) => {
            button.disabled = disableBulk;
        });
        selectionElements.starButtons.forEach((button) => {
            button.disabled = disableBulk;
        });
        selectionElements.markReadButtons.forEach((button) => {
            button.disabled = disableBulk;
        });
        selectionElements.trashButtons.forEach((button) => {
            button.disabled = disableBulk;
        });

        selectionElements.localButtons.forEach((button) => {
            if (!button) {
                return;
            }
            button.disabled = disableBulk;
            button.textContent = isLocalViewActive()
                ? `Remover da ${LOCAL_FOLDER_LABEL}`
                : `Salvar na ${LOCAL_FOLDER_LABEL}`;
        });

        const moveTargetSelected = selectionElements.moveSelect
            ? Number(selectionElements.moveSelect.value) > 0
            : false;
        if (selectionElements.moveSelect) {
            selectionElements.moveSelect.disabled = disableBulk;
        }
        if (selectionElements.moveButton) {
            selectionElements.moveButton.disabled = disableBulk || !moveTargetSelected;
        }
    }

    function setBulkButtonsBusy(busy) {
        const applyState = (collection, extraDisabled = false) => {
            if (!collection) {
                return;
            }
            collection.forEach((button) => {
                if (!button) {
                    return;
                }
                if (busy) {
                    button.setAttribute('aria-busy', 'true');
                    button.disabled = true;
                } else {
                    button.removeAttribute('aria-busy');
                    button.disabled = extraDisabled || state.selectedThreads.size === 0;
                }
            });
        };

        applyState(selectionElements.archiveButtons);
        applyState(selectionElements.starButtons);
        applyState(selectionElements.markReadButtons);
        applyState(selectionElements.trashButtons);
        applyState(selectionElements.localButtons);

        if (selectionElements.moveButton) {
            selectionElements.moveButton.disabled = true;
            if (busy) {
                selectionElements.moveButton.setAttribute('aria-busy', 'true');
            } else {
                selectionElements.moveButton.removeAttribute('aria-busy');
                updateSelectionUI();
            }
        }

        if (selectionElements.moveSelect) {
            selectionElements.moveSelect.disabled = true;
        }
    }

    function handleThreadListChange(event) {
        const checkbox = event.target.closest('[data-thread-select]');
        if (!checkbox) {
            return;
        }
        event.stopPropagation();
        const threadId = Number(checkbox.value || checkbox.getAttribute('data-thread-id'));
        if (!Number.isFinite(threadId)) {
            return;
        }
        const shouldSelect = checkbox.checked;
        if (event.shiftKey && Number.isFinite(selectionState.anchorThreadId) && selectionState.anchorThreadId !== threadId) {
            applyThreadRangeSelection(selectionState.anchorThreadId, threadId, shouldSelect);
            return;
        }

        toggleThreadSelection(threadId, shouldSelect);
        selectionState.anchorThreadId = threadId;
    }

    async function handleThreadListClick(event) {
        const inlineStar = event.target.closest('[data-thread-row-star]');
        if (inlineStar) {
            event.preventDefault();
            event.stopPropagation();
            handleInlineStar(inlineStar);
            return;
        }

        const inlineWindow = event.target.closest('[data-thread-row-window]');
        if (inlineWindow) {
            event.preventDefault();
            event.stopPropagation();
            await handleThreadRowWindow(inlineWindow);
            return;
        }

        const inlineTrash = event.target.closest('[data-thread-row-trash]');
        if (inlineTrash) {
            event.preventDefault();
            event.stopPropagation();
            handleInlineTrash(inlineTrash);
            return;
        }

        if (event.target.closest('.thread-select')) {
            return;
        }

        const card = event.target.closest('[data-thread-id]');
        if (!card) {
            return;
        }

        const threadId = parseInt(card.getAttribute('data-thread-id'), 10);
        if (!Number.isFinite(threadId)) {
            return;
        }

        if (event.metaKey || event.ctrlKey) {
            event.preventDefault();
            const nextSelected = !state.selectedThreads.has(threadId);
            toggleThreadSelection(threadId, nextSelected);
            selectionState.anchorThreadId = threadId;
            return;
        }

        if (event.shiftKey && Number.isFinite(selectionState.anchorThreadId)) {
            event.preventDefault();
            applyThreadRangeSelection(selectionState.anchorThreadId, threadId, true);
            return;
        }

        state.threadId = threadId;
        threadList.querySelectorAll('[data-thread-id]').forEach((cardElement) => {
            cardElement.classList.toggle('is-active', cardElement === card);
        });

        loadThreadMessages(threadId);
    }

    function getAccountById(accountId) {
        if (!Number.isFinite(Number(accountId))) {
            return null;
        }
        return state.accounts.find((account) => Number(account.id) === Number(accountId)) || null;
    }

    function formatReplySubject(baseSubject) {
        const subject = (baseSubject || '').trim();
        if (subject.toLowerCase().startsWith('re:')) {
            return subject;
        }
        if (subject === '') {
            return 'Re: (sem assunto)';
        }
        return `Re: ${subject}`;
    }

    function formatForwardSubject(baseSubject) {
        const subject = (baseSubject || '').trim();
        if (subject.toLowerCase().startsWith('enc:')) {
            return subject;
        }
        if (subject === '') {
            return 'Enc: (sem assunto)';
        }
        return `Enc: ${subject}`;
    }

    function partitionParticipants(participants) {
        const result = { from: [], to: [], cc: [], bcc: [] };
        participants.forEach((participant) => {
            if (!participant || !participant.email) {
                return;
            }
            const email = String(participant.email).trim();
            if (email === '') {
                return;
            }
            const role = (participant.role || 'to').toLowerCase();
            if (!result[role]) {
                result[role] = [];
            }
            result[role].push(email);
        });
        return result;
    }

    function buildQuotedBody(message) {
        if (!message) {
            return '';
        }
        const participants = Array.isArray(message.participants) ? message.participants : [];
        const author = participants.find((participant) => (participant.role || 'from') === 'from');
        const authorLabel = author ? (author.name || author.email || 'remetente') : 'remetente';
        const sentAt = message.sent_at ? formatDate(message.sent_at) : 'data desconhecida';
        const preview = message.body_preview || message.snippet || '';
        return `\n\n--- ${authorLabel} em ${sentAt} ---\n${preview}`;
    }

    function buildForwardBody(message) {
        if (!message) {
            return '';
        }
        const participants = Array.isArray(message.participants) ? message.participants : [];
        const partitioned = partitionParticipants(participants);
        const fromLabel = participants.find((participant) => (participant.role || 'from') === 'from');
        const sentAt = message.sent_at ? formatDate(message.sent_at) : 'data desconhecida';
        const toLine = partitioned.to.length ? `Para: ${partitioned.to.join(', ')}` : null;
        const ccLine = partitioned.cc.length ? `Cc: ${partitioned.cc.join(', ')}` : null;
        const headerLines = [
            '---------- Mensagem encaminhada ----------',
            `De: ${(fromLabel && (fromLabel.name || fromLabel.email)) || 'remetente'}`,
            `Enviado: ${sentAt}`,
            toLine,
            ccLine,
            `Assunto: ${message.subject || '(sem assunto)'}`,
            '',
        ].filter(Boolean);

        const preview = message.body_preview || message.snippet || '';
        return `\n\n${headerLines.join('\n')}${preview}`;
    }

    function deriveReplyPreset(mode = 'reply', messageOverride = null) {
        if (!state.currentThread || !state.threadId) {
            return null;
        }

        const messages = Array.isArray(state.currentThreadMessages) ? state.currentThreadMessages : [];
        if (messages.length === 0 && !messageOverride) {
            return {
                threadId: state.threadId,
                subject: formatReplySubject(state.currentThread.subject || ''),
            };
        }

        const accountId = state.currentThread.account_id || state.accountId;
        const threadSubject = state.currentThread.subject || '';
        const account = getAccountById(accountId) || {};
        const accountEmail = account.from_email ? String(account.from_email).toLowerCase() : null;

        let targetMessage = messageOverride;
        if (!targetMessage) {
            targetMessage = [...messages].reverse().find((message) => (message.direction || 'inbound') !== 'outbound')
                || messages[messages.length - 1]
                || null;
        }

        if (!targetMessage) {
            return null;
        }

        if (mode === 'forward') {
            const attachments = Array.isArray(targetMessage.attachments) ? targetMessage.attachments : [];
            const attachmentIds = attachments
                .map((attachment) => Number(attachment.id))
                .filter((id) => Number.isFinite(id) && id > 0);

            return {
                threadId: state.threadId,
                accountId,
                subject: formatForwardSubject(targetMessage.subject || threadSubject),
                body: buildForwardBody(targetMessage),
                forwardAttachmentIds: attachmentIds,
                forwardAttachments: attachments.map((attachment) => ({
                    id: attachment.id,
                    filename: attachment.filename || 'anexo',
                    size_bytes: Number(attachment.size_bytes || attachment.sizeBytes || 0) || 0,
                })),
            };
        }

        const replySubject = targetMessage.subject || threadSubject;
        const participants = Array.isArray(targetMessage.participants) ? targetMessage.participants : [];
        const partitioned = partitionParticipants(participants);
        const seenRecipients = new Set();
        const exclusions = new Set(accountEmail ? [accountEmail] : []);

        const appendRecipients = (source, target) => {
            if (!Array.isArray(source)) {
                return;
            }
            source.forEach((email) => {
                const trimmed = String(email).trim();
                if (trimmed === '') {
                    return;
                }
                const normalized = trimmed.toLowerCase();
                if (exclusions.has(normalized) || seenRecipients.has(normalized)) {
                    return;
                }
                seenRecipients.add(normalized);
                target.push(trimmed);
            });
        };

        const toRecipients = [];
        const ccRecipients = [];
        if (mode === 'reply_all') {
            appendRecipients(partitioned.from, toRecipients);
            appendRecipients(partitioned.to, toRecipients);
            appendRecipients(partitioned.cc, ccRecipients);
        } else {
            const desiredRole = (targetMessage.direction || 'inbound') === 'outbound' ? 'to' : 'from';
            appendRecipients(partitioned[desiredRole] || [], toRecipients);
            if (toRecipients.length === 0 && desiredRole === 'from') {
                appendRecipients(partitioned.to, toRecipients);
            }
        }

        return {
            threadId: state.threadId,
            accountId,
            to: toRecipients.join(', '),
            cc: ccRecipients.length ? ccRecipients.join(', ') : undefined,
            subject: formatReplySubject(replySubject),
            body: buildQuotedBody(targetMessage),
        };
    }

    function getMessageById(messageId) {
        const numericId = Number(messageId);
        if (!Number.isFinite(numericId)) {
            return null;
        }

        const messages = Array.isArray(state.currentThreadMessages) ? state.currentThreadMessages : [];
        return messages.find((message) => Number(message.id) === numericId) || null;
    }

    function getMessageContextFromElement(element) {
        if (!element || typeof element.closest !== 'function') {
            return null;
        }
        const bubble = element.closest('[data-message-id]');
        if (!bubble) {
            return null;
        }
        const messageId = bubble.getAttribute('data-message-id');
        return getMessageById(messageId);
    }

    async function performThreadAction(action, extraPayload = {}, threadIdOverride = null) {
        const targetThreadId = Number.isFinite(Number(threadIdOverride))
            ? Number(threadIdOverride)
            : state.threadId;

        if (!targetThreadId) {
            throw new Error('Thread alvo inválida.');
        }

        const dedicatedMap = {
            star: { key: 'threadStarBase', suffix: 'star' },
            archive: { key: 'threadArchiveBase', suffix: 'archive' },
            move: { key: 'threadMoveBase', suffix: 'move' },
        };

        let endpoint = null;
        let includeActionField = true;
        if (dedicatedMap[action]) {
            const base = state.routes[dedicatedMap[action].key];
            if (base) {
                endpoint = `${base}/${targetThreadId}/${dedicatedMap[action].suffix}`;
                includeActionField = false;
            }
        }

        if (!endpoint) {
            if (!state.routes.threadActionsBase) {
                throw new Error('Thread action endpoint indisponível.');
            }
            endpoint = `${state.routes.threadActionsBase}/${targetThreadId}/actions`;
        }

        const payload = {
            ...extraPayload,
        };
        if (includeActionField) {
            payload.action = action;
        }
        if (state.csrf) {
            payload._token = state.csrf;
        }

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': state.csrf || '',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            let message = 'Não foi possível processar a ação.';
            try {
                const errorPayload = await response.json();
                if (errorPayload && errorPayload.error) {
                    message = errorPayload.error;
                }
            } catch (parseError) {
                // silent
            }
            throw new Error(message);
        }

        return response.json();
    }

    async function handleStarToggle(event) {
        event.preventDefault();
        if (!state.threadId) {
            return;
        }

        const button = event.currentTarget;
        if (!button) {
            return;
        }

        const isStarred = button.getAttribute('data-starred') === '1';
        setButtonBusy(button, true);
        try {
            const payload = await performThreadAction('star', { starred: !isStarred });
            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }
            await refreshThreads();
        } catch (error) {
            console.error('Inbox star action failed', error);
            showInboxAlert(error?.message || 'Falha ao favoritar.', 'error');
        } finally {
            setButtonBusy(button, false);
        }
    }

    async function handleArchive(event) {
        event.preventDefault();
        if (!state.threadId) {
            return;
        }

        const button = event.currentTarget;
        setButtonBusy(button, true);
        try {
            const payload = await performThreadAction('archive');
            syncFolderBadgesFromResponse(payload);
            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }
            await refreshThreads();
        } catch (error) {
            console.error('Inbox archive action failed', error);
            showInboxAlert(error?.message || 'Falha ao arquivar.', 'error');
        } finally {
            setButtonBusy(button, false);
        }
    }

    async function handleTrash(event) {
        event.preventDefault();
        if (!state.threadId) {
            return;
        }

        const button = event.currentTarget;
        setButtonBusy(button, true);
        try {
            const payload = await performThreadAction('trash');
            syncFolderBadgesFromResponse(payload);
            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }
            await refreshThreads();
            await loadTrashPreview({ silent: true });
        } catch (error) {
            console.error('Inbox trash action failed', error);
            showInboxAlert(error?.message || 'Falha ao enviar para lixeira.', 'error');
        } finally {
            setButtonBusy(button, false);
        }
    }

    async function handleMove(targetFolderId) {
        if (!state.threadId) {
            return;
        }

        const { moveSelect, moveButton } = getMoveControls();
        const value = targetFolderId !== undefined ? targetFolderId : (moveSelect ? moveSelect.value : '');
        const folderId = Number(value);
        if (!Number.isFinite(folderId) || folderId <= 0) {
            return;
        }

        const currentFolderId = state.currentThread && state.currentThread.folder && state.currentThread.folder.id
            ? Number(state.currentThread.folder.id)
            : null;
        if (currentFolderId !== null && currentFolderId === folderId) {
            if (moveButton) {
                moveButton.disabled = true;
            }
            return;
        }

        setButtonBusy(moveButton, true);
        if (moveSelect) {
            moveSelect.disabled = true;
        }

        try {
            const payload = await performThreadAction('move', { folder_id: folderId });
            syncFolderBadgesFromResponse(payload);
            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }
            await refreshThreads();
            await loadTrashPreview({ silent: true });
        } catch (error) {
            console.error('Inbox move action failed', error);
            showInboxAlert(error?.message || 'Falha ao mover.', 'error');
        } finally {
            if (moveSelect) {
                moveSelect.disabled = false;
                moveSelect.value = '';
            }
            if (moveButton) {
                moveButton.disabled = true;
                moveButton.removeAttribute('aria-busy');
            }
        }
    }

    function handleReplyTrigger(event, modeOverride = null, messageOverride = null) {
        if (event) {
            event.preventDefault();
        }
        if (!state.threadId) {
            return;
        }

        closeReplyMenu();
        const fallbackSubject = messageOverride && messageOverride.subject
            ? messageOverride.subject
            : (state.currentThread ? state.currentThread.subject : '');
        const mode = modeOverride
            || (event && event.currentTarget && event.currentTarget.getAttribute('data-reply-mode'))
            || 'reply';

        const preset = deriveReplyPreset(mode, messageOverride) || {
            threadId: state.threadId,
            subject: mode === 'forward'
                ? formatForwardSubject(fallbackSubject)
                : formatReplySubject(fallbackSubject),
        };

        openComposer(preset);
    }

    async function handleBulkArchive() {
        await runBulkThreadAction('archive');
    }

    async function handleBulkTrash() {
        await runBulkThreadAction('trash');
        await loadTrashPreview({ silent: true });
    }

    async function handleBulkStar() {
        await runBulkThreadAction('star', { starred: true });
    }

    async function handleBulkMarkRead() {
        await runBulkThreadAction('mark_read');
    }

    async function handleBulkMove() {
        const folderId = selectionElements.moveSelect
            ? Number(selectionElements.moveSelect.value)
            : NaN;
        if (!Number.isFinite(folderId) || folderId <= 0) {
            return;
        }
        await runBulkThreadAction('move', { folder_id: folderId });
        await loadTrashPreview({ silent: true });
        if (selectionElements.moveSelect) {
            selectionElements.moveSelect.value = '';
        }
        updateSelectionUI();
    }

    async function handleBulkLocalAction() {
        const threadIds = Array.from(state.selectedThreads);
        if (threadIds.length === 0) {
            return;
        }

        if (isLocalViewActive()) {
            removeThreadsFromLocal(threadIds);
            clearThreadSelection();
            renderLocalThreads();
            showInboxAlert(`${LOCAL_FOLDER_LABEL} atualizada.`, 'success');
            return;
        }

        storeThreadsLocally(threadIds);
        showInboxAlert(`Salvo na ${LOCAL_FOLDER_LABEL}.`, 'success');

        const canPurgeServer = state.routes.threadBulkActions || state.routes.threadActionsBase;
        if (!canPurgeServer) {
            return;
        }

        const confirmed = window.confirm('Deseja mover as conversas salvas para a lixeira do servidor?');
        if (!confirmed) {
            return;
        }

        try {
            await runBulkThreadAction('trash');
            await loadTrashPreview({ silent: true });
            clearThreadSelection();
            showInboxAlert('Conversas enviadas para a lixeira do servidor.', 'success');
        } catch (error) {
            console.error('Local move purge failed', error);
            showInboxAlert('Não foi possível mover para a lixeira do servidor.', 'error');
        }
    }

    async function runBulkThreadAction(action, payload = {}) {
        const threadIds = Array.from(state.selectedThreads);
        if (threadIds.length === 0) {
            return;
        }

        setBulkButtonsBusy(true);
        try {
            if (state.routes.threadBulkActions) {
                const requestPayload = {
                    action,
                    thread_ids: threadIds,
                    payload,
                };
                if (state.csrf) {
                    requestPayload._token = state.csrf;
                }

                const response = await fetch(state.routes.threadBulkActions, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': state.csrf || '',
                    },
                    body: JSON.stringify(requestPayload),
                });

                if (!response.ok) {
                    throw new Error('Não foi possível processar a ação em lote.');
                }

                const payloadResponse = await response.json();
                (payloadResponse.folders || []).forEach((folder) => updateFolderBadge(folder));
                (payloadResponse.threads || []).forEach((thread) => updateHeaderIfCurrent(thread));
            } else {
                for (const threadId of threadIds) {
                    const singlePayload = await performThreadAction(action, payload, threadId);
                    syncFolderBadgesFromResponse(singlePayload);
                    updateHeaderIfCurrent(singlePayload.thread);
                }
            }
            await refreshThreads();
            clearThreadSelection();
        } catch (error) {
            console.error('Bulk action failed', error);
            showInboxAlert(error?.message || 'Falha ao processar ações em lote.', 'error');
        } finally {
            setBulkButtonsBusy(false);
        }
    }

    async function handleInlineStar(button) {
        const threadId = Number(button.getAttribute('data-thread-action-id'));
        if (!Number.isFinite(threadId)) {
            return;
        }
        const isStarred = button.getAttribute('data-starred') === '1';
        setButtonBusy(button, true);
        try {
            const payload = await performThreadAction('star', { starred: !isStarred }, threadId);
            syncFolderBadgesFromResponse(payload);
            updateHeaderIfCurrent(payload.thread);
            await refreshThreads();
        } catch (error) {
            console.error('Inline star failed', error);
        } finally {
            setButtonBusy(button, false);
        }
    }

    async function handleThreadRowWindow(button) {
        const threadId = Number(button.getAttribute('data-thread-action-id'));
        if (!Number.isFinite(threadId)) {
            return;
        }

        const card = button.closest('[data-thread-id]');
        if (threadList && card) {
            threadList.querySelectorAll('[data-thread-id]').forEach((element) => {
                element.classList.toggle('is-active', element === card);
            });
        }

        const previousThreadId = Number(state.threadId);
        state.threadId = threadId;

        const needsLoad = previousThreadId !== threadId
            || !Array.isArray(state.currentThreadMessages)
            || state.currentThreadMessages.length === 0
            || !Number.isFinite(Number(state.selectedMessageId));

        if (needsLoad) {
            await loadThreadMessages(threadId);
        }

        const messageId = (() => {
            if (Number.isFinite(Number(state.selectedMessageId))) {
                return Number(state.selectedMessageId);
            }
            const messages = Array.isArray(state.currentThreadMessages) ? state.currentThreadMessages : [];
            if (messages.length === 0) {
                return null;
            }
            const lastMessage = messages[messages.length - 1];
            if (!lastMessage || !Number.isFinite(Number(lastMessage.id))) {
                return null;
            }
            return Number(lastMessage.id);
        })();

        if (Number.isFinite(messageId)) {
            openMessageInWindow(messageId);
        } else {
            console.warn('Thread window launch skipped: no messages available.');
        }
    }

    async function handleInlineTrash(button) {
        const threadId = Number(button.getAttribute('data-thread-action-id'));
        if (!Number.isFinite(threadId)) {
            return;
        }
        setButtonBusy(button, true);
        try {
            const payload = await performThreadAction('trash', {}, threadId);
            syncFolderBadgesFromResponse(payload);
            updateHeaderIfCurrent(payload.thread);
            await refreshThreads();
            await loadTrashPreview({ silent: true });
        } catch (error) {
            console.error('Inline trash failed', error);
        } finally {
            setButtonBusy(button, false);
        }
    }

    function updateHeaderIfCurrent(thread) {
        if (!thread) {
            return;
        }
        if (!state.threadId || Number(state.threadId) !== Number(thread.id)) {
            return;
        }
        updateThreadHeader(thread);
    }

    function launchStandaloneComposerWindow() {
        const routes = state.routes || {};
        const configuredTarget = typeof routes.composeWindow === 'string' ? routes.composeWindow : '';
        const fallbackTarget = typeof config.composeWindowUrl === 'string' ? config.composeWindowUrl : '';
        const target = (configuredTarget || fallbackTarget || '').trim();
        if (!target) {
            return false;
        }

        const windowName = `composer_${Date.now()}`;
        const features = 'noopener,noreferrer,width=1280,height=900,resizable=yes,scrollbars=yes';
        const popup = window.open(target, windowName, features);
        if (popup && typeof popup.focus === 'function') {
            popup.focus();
            return true;
        }

        return popup !== null;
    }

    function initializeComposer() {
        if (!composerElements.panel) {
            return;
        }

        composerElements.inlineTriggers.forEach((trigger) => {
            if (trigger.dataset.bound === 'compose-inline') {
                return;
            }
            trigger.dataset.bound = 'compose-inline';
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                openComposer();
            });
        });

        composerElements.windowTriggers.forEach((trigger) => {
            if (trigger.dataset.bound === 'compose-window') {
                return;
            }
            trigger.dataset.bound = 'compose-window';
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (composerState.isOpen) {
                    closeComposer();
                }
                launchStandaloneComposerWindow();
            });
        });

        if (composerElements.closeButton) {
            composerElements.closeButton.addEventListener('click', closeComposer);
        }

        if (composerElements.backdrop) {
            composerElements.backdrop.addEventListener('click', closeComposer);
        }

        if (composerElements.discardButton) {
            composerElements.discardButton.addEventListener('click', () => {
                resetComposerForm();
                closeComposer();
            });
        }

        if (composerElements.sendButton) {
            composerElements.sendButton.addEventListener('click', () => handleComposerSubmit('send'));
        }

        if (composerElements.draftButton) {
            composerElements.draftButton.addEventListener('click', () => handleComposerSubmit('draft'));
        }

        if (composerElements.attachments) {
            composerElements.attachments.addEventListener('change', (event) => {
                handleAttachmentChange(event);
                markComposerDirty();
            });
        }

        if (composerElements.account) {
            composerElements.account.addEventListener('change', () => {
                composerState.accountId = toNumberOrNull(composerElements.account.value) || null;
                markComposerDirty();
            });
        }

        document.addEventListener('keydown', handleGlobalKeydown);

        syncComposerDefaultAccount();
        updateAttachmentListDisplay();
        registerComposerFieldListeners();
        initializeComposerEditor();
        bindRecipientToggles();
        resetRecipientRows();
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Escape' && composerState.isOpen) {
            closeComposer();
            return;
        }

        if (!state.threadId || composerState.isOpen) {
            return;
        }

        if (event.altKey || event.metaKey || event.ctrlKey) {
            return;
        }

        if (isTypingContext(event.target)) {
            return;
        }

        const key = typeof event.key === 'string' ? event.key.toLowerCase() : '';
        const messageContext = getMessageContextFromElement(event.target)
            || getMessageContextFromElement(document.activeElement)
            || null;

        if (key === 'j' || key === 'k') {
            event.preventDefault();
            navigateMessageDetail(key === 'j' ? 'next' : 'prev');
            return;
        }

        if (key === 'r') {
            event.preventDefault();
            handleReplyTrigger(null, event.shiftKey ? 'reply_all' : 'reply', messageContext);
        } else if (key === 'f' && !event.shiftKey) {
            event.preventDefault();
            handleReplyTrigger(null, 'forward', messageContext);
        }
    }

    function isTypingContext(target) {
        if (!target) {
            return false;
        }
        const tagName = target.tagName ? target.tagName.toLowerCase() : '';
        return target.isContentEditable
            || tagName === 'input'
            || tagName === 'textarea'
            || tagName === 'select';
    }

    function bindRecipientToggles() {
        if (composerElements.toggleCc) {
            composerElements.toggleCc.addEventListener('click', () => {
                toggleRecipientRow('cc');
            });
        }

        if (composerElements.toggleBcc) {
            composerElements.toggleBcc.addEventListener('click', () => {
                toggleRecipientRow('bcc');
            });
        }
    }

    function toggleRecipientRow(type) {
        const row = type === 'cc' ? composerElements.ccRow : composerElements.bccRow;
        if (!row) {
            return;
        }
        const nextVisible = row.hidden;
        setRecipientRowVisibility(type, nextVisible);
        if (nextVisible) {
            const field = type === 'cc' ? composerElements.cc : composerElements.bcc;
            if (field) {
                field.focus();
            }
        }
    }

    function setRecipientRowVisibility(type, visible) {
        const row = type === 'cc' ? composerElements.ccRow : composerElements.bccRow;
        const toggle = type === 'cc' ? composerElements.toggleCc : composerElements.toggleBcc;
        if (!row) {
            return;
        }
        row.hidden = !visible;
        if (toggle) {
            toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        }
    }

    function resetRecipientRows() {
        setRecipientRowVisibility('cc', false);
        setRecipientRowVisibility('bcc', false);
        if (composerElements.cc) {
            composerElements.cc.value = '';
        }
        if (composerElements.bcc) {
            composerElements.bcc.value = '';
        }
    }

    function registerComposerFieldListeners() {
        const inputs = [composerElements.to, composerElements.cc, composerElements.bcc, composerElements.subject];
        inputs.forEach((input) => {
            if (!input) {
                return;
            }
            input.addEventListener('input', markComposerDirty);
        });

        if (composerElements.body) {
            composerElements.body.addEventListener('input', () => {
                if (composerState.silencingBodyUpdate) {
                    return;
                }
                markComposerDirty();
            });
        }
    }

    const INLINE_COMPOSER_EDITOR_ID = 'composer-body-editor';

    function ensureComposerBodyId() {
        if (!composerElements.body) {
            return '';
        }
        if (!composerElements.body.id) {
            composerElements.body.id = INLINE_COMPOSER_EDITOR_ID;
        }
        return composerElements.body.id;
    }

    function getComposerEditorInstance() {
        if (!window.tinymce || !composerState.editorId) {
            return null;
        }
        return window.tinymce.get(composerState.editorId) || null;
    }

    function initializeComposerEditor() {
        if (!composerElements.body || typeof window.tinymce === 'undefined' || composerState.editorInitializing) {
            return;
        }
        composerState.editorInitializing = true;
        const textareaId = ensureComposerBodyId();
        window.tinymce.init({
            selector: `#${textareaId}`,
            menubar: false,
            branding: false,
            height: 360,
            statusbar: false,
            plugins: 'lists link table code wordcount autolink image imagetools media quickbars paste',
            toolbar: 'undo redo | blocks fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | removeformat code',
            quickbars_selection_toolbar: 'bold italic underline | forecolor backcolor | link',
            quickbars_insert_toolbar: 'quickimage quicktable media',
            paste_data_images: true,
            automatic_uploads: false,
            image_advtab: true,
            images_upload_handler(blobInfo) {
                return new Promise((resolve) => {
                    resolve(`data:${blobInfo.blob().type};base64,${blobInfo.base64()}`);
                });
            },
            content_style: 'body { font-family: "Inter", "Segoe UI", sans-serif; font-size: 15px; } img { max-width: 100%; height: auto; }',
            setup(editor) {
                editor.on('init', () => {
                    composerState.editorId = editor.id;
                    composerState.editorReady = true;
                    if (composerState.pendingBodyHtml) {
                        editor.setContent(composerState.pendingBodyHtml);
                        composerState.pendingBodyHtml = '';
                    }
                });
                editor.on('change input undo redo keyup', () => {
                    if (composerState.silencingBodyUpdate) {
                        return;
                    }
                    markComposerDirty();
                });
            },
        })
            .then((editors) => {
                const [editor] = editors;
                if (editor) {
                    composerState.editorId = editor.id;
                    composerState.editorReady = true;
                    if (composerState.pendingBodyHtml) {
                        editor.setContent(composerState.pendingBodyHtml);
                        composerState.pendingBodyHtml = '';
                    }
                }
            })
            .catch((error) => {
                console.error('Rich-text composer init failed', error);
            })
            .finally(() => {
                composerState.editorInitializing = false;
            });
    }

    function setComposerBodyContent(html, plainTextOverride = null) {
        const plainText = typeof plainTextOverride === 'string'
            ? plainTextOverride
            : htmlToPlainText(html || '');
        composerState.silencingBodyUpdate = true;
        const editor = getComposerEditorInstance();
        if (editor) {
            editor.setContent(html || '');
            composerState.pendingBodyHtml = '';
        } else {
            composerState.pendingBodyHtml = html || '';
        }
        if (composerElements.body) {
            composerElements.body.value = plainText || '';
        }
        window.setTimeout(() => {
            composerState.silencingBodyUpdate = false;
        }, 0);
    }

    function setComposerBodyFromPlainText(value = '') {
        const safeValue = value || '';
        const html = convertPlainTextToHtml(safeValue);
        setComposerBodyContent(html, safeValue);
    }

    function resetComposerBody() {
        setComposerBodyContent('', '');
    }

    function getComposerBodyContent() {
        const editor = getComposerEditorInstance();
        if (editor) {
            const textContent = editor.getContent({ format: 'text' }).trim();
            const htmlContent = textContent ? editor.getContent({ format: 'html' }).trim() : '';
            return {
                text: textContent,
                html: htmlContent,
            };
        }
        const fallback = composerElements.body ? (composerElements.body.value || '').trim() : '';
        return {
            text: fallback,
            html: fallback ? convertPlainTextToHtml(fallback) : '',
        };
    }

    function syncComposerDefaultAccount() {
        if (composerState.accountId) {
            return;
        }
        if (state.accountId) {
            composerState.accountId = state.accountId;
        } else if (state.accounts.length > 0) {
            composerState.accountId = toNumberOrNull(state.accounts[0].id) || null;
        }
        if (composerElements.account && composerState.accountId) {
            composerElements.account.value = String(composerState.accountId);
        }
    }

    function openComposer(preset = {}) {
        if (!composerElements.panel) {
            return;
        }

        resetComposerForm();

        composerState.isOpen = true;
        composerState.threadId = preset.threadId ? Number(preset.threadId) : null;
        composerState.draftId = preset.draftId ? Number(preset.draftId) : null;
        if (preset.accountId) {
            composerState.accountId = Number(preset.accountId);
        }
        updateComposerDraftIdInput();

        if (composerElements.account && composerState.accountId) {
            composerElements.account.value = String(composerState.accountId);
        }

        if (composerElements.panel) {
            composerElements.panel.classList.add('is-visible');
            composerElements.panel.setAttribute('aria-hidden', 'false');
        }
        if (composerElements.backdrop) {
            composerElements.backdrop.hidden = false;
            composerElements.backdrop.classList.add('is-visible');
        }

        if (composerElements.to && preset.to) {
            composerElements.to.value = preset.to;
        }
        if (composerElements.cc && preset.cc) {
            setRecipientRowVisibility('cc', true);
            composerElements.cc.value = preset.cc;
        }
        if (composerElements.bcc && preset.bcc) {
            setRecipientRowVisibility('bcc', true);
            composerElements.bcc.value = preset.bcc;
        }
        if (composerElements.subject && preset.subject) {
            composerElements.subject.value = preset.subject;
        }
        if (typeof preset.bodyHtml === 'string' && preset.bodyHtml.trim() !== '') {
            setComposerBodyContent(preset.bodyHtml);
        } else if (typeof preset.body === 'string' && preset.body.trim() !== '') {
            setComposerBodyFromPlainText(preset.body);
        }

        showComposerStatus('');
        applyForwardAttachmentPreset(preset);
        if (composerElements.to) {
            composerElements.to.focus();
        }
    }

    function closeComposer() {
        if (!composerState.isOpen) {
            return;
        }

        composerState.isOpen = false;
        if (composerElements.panel) {
            composerElements.panel.classList.remove('is-visible');
            composerElements.panel.setAttribute('aria-hidden', 'true');
        }
        if (composerElements.backdrop) {
            composerElements.backdrop.classList.remove('is-visible');
            composerElements.backdrop.hidden = true;
        }
        resetComposerForm();
    }

    function resetComposerForm() {
        if (composerElements.form) {
            composerElements.form.reset();
        }
        resetComposerBody();
        resetRecipientRows();
        composerState.dirty = false;
        clearComposerAutosaveTimer();
        composerState.draftId = null;
        composerState.threadId = null;
        composerState.accountId = state.accountId
            || (state.accounts[0] ? toNumberOrNull(state.accounts[0].id) : composerState.accountId)
            || null;
        if (composerElements.account && composerState.accountId) {
            composerElements.account.value = String(composerState.accountId);
        }
        updateComposerDraftIdInput();
        showComposerStatus('');
        resetForwardAttachments();
        updateAttachmentListDisplay();
    }

    function resetForwardAttachments() {
        composerState.forwardAttachmentIds = [];
        composerState.forwardAttachmentMeta = [];
        composerState.forwardAttachmentsPending = false;
        syncForwardAttachmentInput();
    }

    function syncForwardAttachmentInput() {
        if (!composerElements.forwardAttachmentsInput) {
            return;
        }
        composerElements.forwardAttachmentsInput.value =
            composerState.forwardAttachmentsPending && composerState.forwardAttachmentIds.length > 0
                ? composerState.forwardAttachmentIds.join(',')
                : '';
    }

    function updateComposerDraftIdInput() {
        if (composerElements.draftId) {
            composerElements.draftId.value = composerState.draftId ? String(composerState.draftId) : '';
        }
    }

    function markComposerDirty() {
        if (!composerState.isOpen) {
            return;
        }
        composerState.dirty = true;
        if (!composerState.manualBusy) {
            showComposerStatus('Alterações não salvas.');
        }
        scheduleComposerAutosave();
    }

    function applyForwardAttachmentPreset(preset = {}) {
        const ids = Array.isArray(preset.forwardAttachmentIds)
            ? preset.forwardAttachmentIds
                .map((value) => Number(value))
                .filter((id) => Number.isFinite(id) && id > 0)
            : [];
        const meta = Array.isArray(preset.forwardAttachments)
            ? preset.forwardAttachments.map((attachment) => ({
                id: attachment.id,
                filename: attachment.filename || 'anexo',
                size_bytes: Number(attachment.size_bytes ?? attachment.sizeBytes ?? 0) || 0,
            }))
            : [];

        if (ids.length === 0 && meta.length === 0) {
            return;
        }

        if (ids.length > MAX_COMPOSER_ATTACHMENTS) {
            ids = ids.slice(0, MAX_COMPOSER_ATTACHMENTS);
        }

        if (meta.length > MAX_COMPOSER_ATTACHMENTS) {
            meta = meta.slice(0, MAX_COMPOSER_ATTACHMENTS);
        }

        composerState.forwardAttachmentIds = ids;
        composerState.forwardAttachmentMeta = meta;
        composerState.forwardAttachmentsPending = ids.length > 0;
        syncForwardAttachmentInput();
        updateAttachmentListDisplay();
        if (composerState.forwardAttachmentsPending) {
            markComposerDirty();
        }
    }

    function clearComposerAutosaveTimer() {
        if (composerState.autosaveTimer) {
            window.clearTimeout(composerState.autosaveTimer);
            composerState.autosaveTimer = null;
        }
    }

    function scheduleComposerAutosave() {
        if (!composerState.isOpen || composerState.manualBusy || !state.routes.composeDraft) {
            return;
        }
        clearComposerAutosaveTimer();
        composerState.autosaveTimer = window.setTimeout(runComposerAutosave, 2500);
    }

    function showComposerStatus(message, type = '') {
        if (!composerElements.status) {
            return;
        }
        composerElements.status.textContent = message || '';
        if (type) {
            composerElements.status.dataset.status = type;
        } else {
            delete composerElements.status.dataset.status;
        }
    }

    function updateAttachmentListDisplay(files = null) {
        if (!composerElements.attachmentList) {
            return;
        }

        let manualFiles = [];
        if (files) {
            manualFiles = Array.from(files);
        } else if (composerElements.attachments && composerElements.attachments.files) {
            manualFiles = Array.from(composerElements.attachments.files);
        }

        const inherited = Array.isArray(composerState.forwardAttachmentMeta)
            ? composerState.forwardAttachmentMeta
            : [];

        if (manualFiles.length === 0 && inherited.length === 0) {
            composerElements.attachmentList.innerHTML = '<span>Nenhum anexo selecionado.</span>';
            return;
        }

        const inheritedItems = inherited.map((attachment) => {
            const sizeLabel = attachment.size_bytes ? ` · ${formatBytes(attachment.size_bytes)}` : '';
            return `<span class="forward-attachment-pill">Original · ${escapeHtml(attachment.filename || 'anexo')}${sizeLabel}</span>`;
        });

        const manualItems = manualFiles.map((file) => `<span>${escapeHtml(file.name)} · ${formatBytes(file.size)}</span>`);

        composerElements.attachmentList.innerHTML = [...inheritedItems, ...manualItems].join('');
    }

    function handleAttachmentChange(event) {
        const target = event.currentTarget;
        const files = target && target.files ? target.files : null;
        const forwardCount = Array.isArray(composerState.forwardAttachmentMeta)
            ? composerState.forwardAttachmentMeta.length
            : 0;
        const manualCount = files ? files.length : (composerElements.attachments && composerElements.attachments.files ? composerElements.attachments.files.length : 0);
        const totalCount = manualCount + forwardCount;

        if (totalCount > MAX_COMPOSER_ATTACHMENTS) {
            showComposerStatus(`Limite de ${MAX_COMPOSER_ATTACHMENTS} anexos. Você selecionou ${totalCount}.`, 'error');
            if (target) {
                target.value = '';
            }
            updateAttachmentListDisplay([]);
            return;
        }

        updateAttachmentListDisplay(files);
    }

    function collectComposerFields() {
        const selectedAccount = composerElements.account ? toNumberOrNull(composerElements.account.value) : null;
        if (selectedAccount) {
            composerState.accountId = selectedAccount;
        }

        const bodyContent = getComposerBodyContent();

        return {
            accountId: composerState.accountId || state.accountId || (state.accounts[0] ? toNumberOrNull(state.accounts[0].id) : null),
            to: composerElements.to ? composerElements.to.value.trim() : '',
            cc: composerElements.cc ? composerElements.cc.value.trim() : '',
            bcc: composerElements.bcc ? composerElements.bcc.value.trim() : '',
            subject: composerElements.subject ? composerElements.subject.value.trim() : '',
            bodyText: bodyContent.text,
            bodyHtml: bodyContent.html,
        };
    }

    function getComposerAttachmentFiles() {
        if (!composerElements.attachments || !composerElements.attachments.files) {
            return [];
        }
        return Array.from(composerElements.attachments.files);
    }

    function composerHasContent(fields, attachments) {
        return Boolean(
            (fields.to && fields.to !== '')
            || (fields.cc && fields.cc !== '')
            || (fields.bcc && fields.bcc !== '')
            || (fields.subject && fields.subject !== '')
            || (fields.bodyText && fields.bodyText !== '')
            || (fields.bodyHtml && fields.bodyHtml !== '')
            || attachments.length > 0
            || composerState.forwardAttachmentIds.length > 0
        );
    }

    function buildComposerFormData(fields, options = {}) {
        const { includeFiles = true } = options;
        const formData = new FormData();

        if (state.csrf) {
            formData.append('_token', state.csrf);
        }

        if (fields.accountId) {
            formData.append('account_id', String(fields.accountId));
        }

        if (composerState.threadId) {
            formData.append('thread_id', String(composerState.threadId));
        }

        if (composerState.draftId) {
            formData.append('draft_id', String(composerState.draftId));
        }

        formData.append('to', fields.to || '');
        formData.append('cc', fields.cc || '');
        formData.append('bcc', fields.bcc || '');
        formData.append('subject', fields.subject || '');

        const hasBodyContent = Boolean((fields.bodyText && fields.bodyText !== '') || (fields.bodyHtml && fields.bodyHtml !== ''));
        if (hasBodyContent) {
            const htmlPayload = fields.bodyHtml || convertPlainTextToHtml(fields.bodyText || '');
            formData.append('body_text', fields.bodyText || '');
            if (htmlPayload) {
                formData.append('body_html', htmlPayload);
            }
        }

        if (includeFiles && composerElements.attachments && composerElements.attachments.files) {
            Array.from(composerElements.attachments.files).forEach((file) => {
                formData.append('attachments[]', file);
            });
        }

        if (composerState.forwardAttachmentsPending && composerState.forwardAttachmentIds.length > 0) {
            formData.append('inherit_attachment_ids', composerState.forwardAttachmentIds.join(','));
        }

        return formData;
    }

    function setComposerBusyState(busy) {
        [composerElements.sendButton, composerElements.draftButton, composerElements.discardButton].forEach((button) => {
            if (!button) {
                return;
            }
            button.disabled = !!busy;
            if (busy) {
                button.setAttribute('aria-busy', 'true');
            } else {
                button.removeAttribute('aria-busy');
            }
        });
    }

    async function handleComposerSubmit(mode) {
        const endpoint = mode === 'draft' ? state.routes.composeDraft : state.routes.composeSend;
        if (!endpoint) {
            showComposerStatus('Endpoint de composição indisponível.', 'error');
            return;
        }

        const fields = collectComposerFields();
        if (!fields.accountId) {
            showComposerStatus('Selecione uma conta para enviar.', 'error');
            return;
        }

        const hasRecipients = Boolean((fields.to || '') || (fields.cc || '') || (fields.bcc || ''));
        const hasBodyContent = Boolean((fields.bodyText && fields.bodyText !== '') || (fields.bodyHtml && fields.bodyHtml !== ''));

        if (mode === 'send' && !hasRecipients) {
            showComposerStatus('Informe pelo menos um destinatário.', 'error');
            return;
        }

        if (mode === 'send' && !hasBodyContent) {
            showComposerStatus('Inclua o corpo da mensagem.', 'error');
            return;
        }

        const formData = buildComposerFormData(fields);
        composerState.manualBusy = true;
        clearComposerAutosaveTimer();
        setComposerBusyState(true);
        showComposerStatus(mode === 'draft' ? 'Salvando rascunho...' : 'Enviando mensagem...');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (!response.ok) {
                let message = 'Não foi possível processar a mensagem.';
                try {
                    const errorPayload = await response.json();
                    if (errorPayload && errorPayload.error) {
                        message = errorPayload.error;
                    }
                } catch (parseError) {
                    // ignore json parse errors
                }
                throw new Error(message);
            }

            const payload = await response.json();
            composerState.dirty = false;
            composerState.lastAutosaveAt = new Date();
            showComposerStatus(mode === 'draft' ? 'Rascunho salvo.' : 'Mensagem enviada!', 'success');

            if (composerState.forwardAttachmentsPending) {
                composerState.forwardAttachmentsPending = false;
                syncForwardAttachmentInput();
            }

            composerState.draftId = mode === 'draft' && payload.message ? Number(payload.message.id) : null;
            updateComposerDraftIdInput();

            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }

            await refreshThreads();

            if (payload.thread && payload.thread.id && mode === 'send') {
                state.threadId = Number(payload.thread.id);
                loadThreadMessages(Number(payload.thread.id));
            }

            if (mode === 'send') {
                window.setTimeout(() => closeComposer(), 900);
            } else if (mode === 'draft') {
                if (composerElements.attachments) {
                    composerElements.attachments.value = '';
                }
                updateAttachmentListDisplay();
            }
        } catch (error) {
            console.error('Composer request failed', error);
            showComposerStatus(error.message || 'Erro ao processar a mensagem.', 'error');
        } finally {
            composerState.manualBusy = false;
            setComposerBusyState(false);
            if (composerState.dirty) {
                scheduleComposerAutosave();
            }
        }
    }

    async function runComposerAutosave() {
        clearComposerAutosaveTimer();
        if (!composerState.isOpen || composerState.manualBusy || composerState.autosaveBusy || !state.routes.composeDraft) {
            return;
        }

        const fields = collectComposerFields();
        const attachments = getComposerAttachmentFiles();
        if (!composerState.dirty || !composerHasContent(fields, attachments)) {
            return;
        }

        const formData = buildComposerFormData(fields, { includeFiles: false });
        composerState.autosaveBusy = true;
        showComposerStatus('Salvando automaticamente...');

        try {
            const response = await fetch(state.routes.composeDraft, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (!response.ok) {
                throw new Error('Falha ao salvar rascunho automático.');
            }

            const payload = await response.json();
            composerState.dirty = false;
            composerState.lastAutosaveAt = new Date();
            composerState.draftId = payload.message ? Number(payload.message.id) : composerState.draftId;
            updateComposerDraftIdInput();

            if (composerState.forwardAttachmentsPending) {
                composerState.forwardAttachmentsPending = false;
                syncForwardAttachmentInput();
            }

            const timestamp = composerState.lastAutosaveAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            showComposerStatus(`Rascunho salvo automaticamente às ${timestamp}.`, 'success');
        } catch (error) {
            console.error('Autosave draft failed', error);
            showComposerStatus(error.message || 'Erro ao salvar automaticamente.', 'error');
        } finally {
            composerState.autosaveBusy = false;
        }
    }

    async function handleMarkReadClick(event) {
        event.preventDefault();
        if (!state.routes.markReadBase || !state.threadId) {
            return;
        }

        const button = event.currentTarget;
        if (button) {
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
        }

        const form = new FormData();
        if (state.csrf) {
            form.append('_token', state.csrf);
        }

        try {
            const response = await fetch(`${state.routes.markReadBase}/${state.threadId}/read`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': state.csrf || ''
                },
                body: form,
            });

            if (!response.ok) {
                throw new Error('Não foi possível atualizar a thread.');
            }

            const payload = await response.json();
            syncFolderBadgesFromResponse(payload);
            if (payload.thread) {
                updateThreadHeader(payload.thread);
            }
            await refreshThreads();
        } catch (error) {
            console.error('Inbox mark-read request failed', error);
        } finally {
            if (button) {
                button.removeAttribute('aria-busy');
                button.disabled = false;
            }
        }
    }

    function updateFolderBadge(folder) {
        const normalized = normalizeFolderPayload(folder);
        if (!normalized) {
            return;
        }

        upsertFolderSummary(normalized);

        const badge = root.querySelector(`[data-folder-badge="${normalized.id}"]`);
        if (!badge) {
            return;
        }

        const count = Number.isFinite(normalized.total_count) ? normalized.total_count : normalized.unread_count;
        badge.textContent = count;
        if (count === 0) {
            badge.classList.add('is-hidden');
        } else {
            badge.classList.remove('is-hidden');
        }
    }

    function updateThreadHeader(thread) {
        if (!threadHeader) {
            return;
        }

        state.currentThread = thread || null;

        if (!thread) {
            state.currentThreadMessages = [];
            threadHeader.innerHTML = `
                <div class="message-panel-title">
                    <div>
                        <h2>Selecione uma conversa</h2>
                        <span>As mensagens aparecem aqui.</span>
                    </div>
                    <div class="thread-header-controls">
                        <button type="button" class="thread-reply-button" data-thread-reply disabled>Responder</button>
                        <button type="button" class="inbox-mark-read" data-mark-read disabled>Marcar como lida</button>
                        <div class="thread-actions">
                            <button type="button" class="thread-action-button" disabled>Favoritar</button>
                            <button type="button" class="thread-action-button" disabled>Arquivar</button>
                            <button type="button" class="thread-action-button" disabled>Lixeira</button>
                            <div class="thread-move-control">
                                <select disabled>
                                    <option>Mover para...</option>
                                </select>
                                <button type="button" class="thread-action-button" disabled>Mover</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            bindMarkReadButton();
            bindThreadActions();
            return;
        }

        const folderLabel = formatFolderLabelFromPayload(thread.folder);
        const hasUnread = Number(thread.unread_count || 0) > 0;
        const folderDisplay = escapeHtml(folderLabel);
        const unreadCount = Number(thread.unread_count || 0);
        const lastActivityLabel = thread.last_message_at
            ? escapeHtml(formatDate(thread.last_message_at))
            : 'Sem data';
        const disabledAttr = hasUnread ? '' : 'disabled';
        const flags = Array.isArray(thread.flags) ? thread.flags : [];
        const starred = flags.includes('flagged') || flags.includes('starred');
        const moveOptions = buildFolderOptions();
        threadHeader.innerHTML = `
            <div class="message-panel-title">
                <div class="message-panel-heading">
                    <h2>${escapeHtml(thread.subject || '(no subject)')}</h2>
                    <span>Conversa #${thread.id} · ${folderDisplay}</span>
                    <div class="message-panel-summary">
                        <span class="message-chip">
                            <span class="message-chip-label">Pasta</span>
                            <strong>${folderDisplay}</strong>
                        </span>
                        ${hasUnread ? `
                            <span class="message-chip message-chip-alert">
                                <span class="message-chip-label">Não lidas</span>
                                <strong>${unreadCount}</strong>
                            </span>
                        ` : ''}
                        <span class="message-chip">
                            <span class="message-chip-label">Atualizado</span>
                            <strong>${lastActivityLabel}</strong>
                        </span>
                    </div>
                </div>
                <div class="thread-header-controls">
                    <div class="thread-reply-group" data-thread-reply-group>
                        <button type="button" class="thread-reply-button" data-thread-reply data-reply-mode="reply">Responder</button>
                        <button
                            type="button"
                            class="thread-reply-caret"
                            data-thread-reply-menu-toggle
                            aria-haspopup="true"
                            aria-expanded="false"
                            title="Mais opções de resposta"
                        >&#9662;</button>
                        <div class="thread-reply-menu" data-thread-reply-menu hidden>
                            <button type="button" data-reply-mode="reply">Responder</button>
                            <button type="button" data-reply-mode="reply_all">Responder a todos</button>
                            <button type="button" data-reply-mode="forward">Encaminhar</button>
                        </div>
                    </div>
                    <button type="button" class="inbox-mark-read" data-mark-read ${disabledAttr}>Marcar como lida</button>
                    <div class="thread-actions" data-thread-actions>
                        <button type="button" class="thread-action-button ${starred ? 'is-active' : ''}" data-thread-star data-starred="${starred ? '1' : '0'}">
                            ${starred ? 'Remover estrela' : 'Favoritar'}
                        </button>
                        <button type="button" class="thread-action-button" data-thread-archive>Arquivar</button>
                        <button type="button" class="thread-action-button" data-thread-trash>Lixeira</button>
                        <div class="thread-move-control">
                            <select data-thread-move-select>
                                ${moveOptions}
                            </select>
                            <button type="button" class="thread-action-button" data-thread-move disabled>Mover</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        bindMarkReadButton();
        bindThreadActions();
    }

    folderButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const raw = button.getAttribute('data-folder-id');
            if (raw === LOCAL_FOLDER_TOKEN) {
                state.localFolderActive = true;
                state.folderId = null;
                state.searchMode = false;
                state.threadLimit = THREAD_PAGE_SIZE;
                updateActiveFolderChip(LOCAL_FOLDER_TOKEN);
                syncTrashPreviewTriggerState();
                closeTrashPreview();
                renderLocalThreads();
                return;
            }

            const parsed = raw === null || raw === '' ? null : toNumberOrNull(raw);
            state.localFolderActive = false;
            state.folderId = Number.isFinite(parsed) && parsed > 0 ? parsed : null;
            state.searchMode = false;
            state.threadLimit = THREAD_PAGE_SIZE;
            updateActiveFolderChip(state.folderId);
            syncTrashPreviewTriggerState();
            closeTrashPreview();
            refreshThreads();
        });
    });

    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', async () => {
            loadMoreButton.disabled = true;
            state.threadLimit += THREAD_PAGE_SIZE;
            if (isLocalViewActive()) {
                renderLocalThreads();
                return;
            }
            if (state.searchMode) {
                await runSearch();
            } else {
                await loadThreads();
            }
        });
    }

    if (trashPreviewElements.refresh) {
        trashPreviewElements.refresh.addEventListener('click', (event) => {
            event.preventDefault();
            loadTrashPreview();
        });
    }

    if (trashPreviewElements.list) {
        trashPreviewElements.list.addEventListener('click', handleTrashPreviewListClick);
    }

    if (trashPreviewElements.trigger) {
        trashPreviewElements.trigger.addEventListener('click', handleTrashPreviewToggle);
    }

    if (trashPreviewElements.close) {
        trashPreviewElements.close.addEventListener('click', (event) => {
            event.preventDefault();
            closeTrashPreview({ restoreFocus: true });
        });
    }

    if (trashPreviewElements.emptyButton) {
        trashPreviewElements.emptyButton.addEventListener('click', handleTrashEmpty);
    }

    if (trashPreviewElements.popover) {
        document.addEventListener('click', handleTrashPreviewOutside, true);
        document.addEventListener('keydown', handleTrashPreviewKeydown, true);
    }

    if (syncControls.button) {
        syncControls.button.addEventListener('click', handleManualSync);
    }

    refreshSyncControlsAvailability();

    bindMarkReadButton();
    bindThreadActions();
    if (canUseTrashPreview()) {
        loadTrashPreview({ silent: true });
    }

    if (threadList) {
        threadList.addEventListener('click', handleThreadListClick);
        threadList.addEventListener('change', handleThreadListChange);
    }

    async function loadThreads(options = {}) {
        const { resetLimit = false } = options;
        if (resetLimit) {
            state.threadLimit = Number(config.threadLimit || 30);
        }
        if (isLocalViewActive()) {
            renderLocalThreads();
            return;
        }
        if (!state.routes.threads || !threadList) {
            return;
        }

        threadList.setAttribute('aria-busy', 'true');
        const params = new URLSearchParams();
        if (state.accountId) {
            params.set('account_id', state.accountId);
        }
        if (state.folderId !== null && state.folderId !== undefined) {
            params.set('folder_id', state.folderId);
        }
        params.set('limit', String(state.threadLimit));
        params.set('include_folders', '1');

        try {
            const response = await fetch(`${state.routes.threads}?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Failed to load threads');
            }

            const payload = await response.json();
            if (Array.isArray(payload.folders)) {
                payload.folders.forEach((folder) => updateFolderBadge(folder));
            }
            renderThreads(payload.threads || []);
            state.hasMoreThreads = Array.isArray(payload.threads)
                ? (payload.threads.length >= state.threadLimit)
                : false;
            updateSearchStatus();
        } catch (error) {
            console.error('Inbox thread fetch failed', error);
        } finally {
            threadList.removeAttribute('aria-busy');
        }
    }

    function getThreadBucketLabel(timestamp) {
        const numericTimestamp = Number(timestamp);
        if (!Number.isFinite(numericTimestamp) || numericTimestamp <= 0) {
            return 'Sem data';
        }

        const messageTime = numericTimestamp * 1000;
        const now = new Date();
        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const startOfTodayMs = startOfToday.getTime();
        const startOfYesterdayMs = startOfTodayMs - DAY_IN_MS;
        const weekdayIndex = (startOfToday.getDay() + 6) % 7;
        const startOfWeekMs = startOfTodayMs - (weekdayIndex * DAY_IN_MS);
        const startOfMonthMs = new Date(startOfToday.getFullYear(), startOfToday.getMonth(), 1).getTime();

        if (messageTime >= startOfTodayMs) {
            return 'Hoje';
        }
        if (messageTime >= startOfYesterdayMs) {
            return 'Ontem';
        }
        if (messageTime >= startOfWeekMs) {
            return 'Esta semana';
        }
        if (messageTime >= startOfMonthMs) {
            return 'Este mês';
        }
        return 'Mais antigas';
    }

    function groupThreadsByDate(threads) {
        const bucketMap = new Map();
        threads.forEach((thread) => {
            const label = getThreadBucketLabel(thread.last_message_at);
            if (!bucketMap.has(label)) {
                bucketMap.set(label, []);
            }
            bucketMap.get(label).push(thread);
        });

        const orderedGroups = THREAD_BUCKET_SEQUENCE
            .filter((label) => bucketMap.has(label))
            .map((label) => ({ label, threads: bucketMap.get(label) }));

        bucketMap.forEach((value, label) => {
            if (!THREAD_BUCKET_SEQUENCE.includes(label)) {
                orderedGroups.push({ label, threads: value });
            }
        });

        return orderedGroups;
    }

    const TRASH_FOLDER_TYPES = new Set(['trash', 'deleted', 'spam']);

    function isTrashLikeFolder(folder) {
        if (!folder || typeof folder !== 'object') {
            return false;
        }
        const type = (folder.type || '').toString().toLowerCase();
        return TRASH_FOLDER_TYPES.has(type);
    }

    function buildThreadCard(thread) {
        const threadId = Number(thread.id);
        const unreadCount = Number(thread.unread_count || 0);
        const flags = Array.isArray(thread.flags) ? thread.flags : [];
        const starred = flags.includes('flagged') || flags.includes('starred');
        const folderLabel = formatFolderLabelFromPayload(thread.folder);
        const isActive = Number(state.threadId) === threadId;
        const isSelected = state.selectedThreads.has(threadId);
        const unreadLabel = unreadCount > 0 ? `<strong>${unreadCount} novas</strong>` : '';
        const starGlyph = starred ? '&#9733;' : '&#9734;';
        const subjectValue = thread.subject || '(no subject)';
        const snippetValue = thread.snippet || 'No preview captured yet.';
        const subjectHtml = state.searchMode ? highlightSearchText(subjectValue) : escapeHtml(subjectValue);
        const snippetHtml = state.searchMode ? highlightSearchText(snippetValue) : escapeHtml(snippetValue);
        const safeThreadId = Number.isFinite(threadId) ? threadId : '';
        const trashQuickAction = `<button type="button" class="thread-mini-action" data-thread-row-trash data-thread-action-id="${threadId}" title="Enviar para lixeira"><span class="thread-mini-icon" aria-hidden="true">&#128465;</span></button>`;

        return `
            <article class="thread-card ${isActive ? 'is-active' : ''} ${isSelected ? 'is-selected' : ''}" data-thread-id="${safeThreadId}">
                <div class="thread-card-head">
                    <label class="thread-select">
                        <input type="checkbox" data-thread-select value="${safeThreadId}" ${isSelected ? 'checked' : ''}>
                        <span></span>
                    </label>
                    <div class="thread-head-content">
                        <h3>${starred ? '<span class="thread-star">&#9733;</span>' : ''}${subjectHtml}</h3>
                        <p>${snippetHtml}</p>
                    </div>
                    <div class="thread-row-actions">
                        <button type="button" class="thread-mini-action" data-thread-row-star data-thread-action-id="${threadId}" data-starred="${starred ? '1' : '0'}" title="Alternar estrela"><span class="thread-mini-icon" aria-hidden="true">${starGlyph}</span></button>
                        <button type="button" class="thread-mini-action" data-thread-row-window data-thread-action-id="${threadId}" title="Abrir em nova janela"><span class="thread-mini-icon" aria-hidden="true">&#8599;</span></button>
                        ${trashQuickAction}
                    </div>
                </div>
                <div class="thread-meta">
                    <span><span class="dot"></span> ${formatDate(thread.last_message_at)}</span>
                    <span>${escapeHtml(folderLabel)} ${unreadLabel}</span>
                </div>
            </article>
        `;
    }

    function renderThreads(threads) {
        if (!threadList) {
            return;
        }

        const collection = Array.isArray(threads) ? threads : [];
        const viewingAllFolders = state.folderId === null;
        // Always honor the selected folder client-side in case the backend ignores folder_id.
        let filteredThreads = collection;
        if (!state.searchMode && !viewingAllFolders) {
            filteredThreads = collection.filter((thread) => Number(thread?.folder?.id) === Number(state.folderId));
        } else if (!state.searchMode && viewingAllFolders) {
            filteredThreads = collection.filter((thread) => !isTrashLikeFolder(thread.folder));
        }

        state.visibleThreadsMap = new Map();
        filteredThreads.forEach((thread) => {
            const threadId = Number(thread.id);
            if (Number.isFinite(threadId)) {
                state.visibleThreadsMap.set(threadId, thread);
            }
        });

        if (filteredThreads.length === 0) {
            const emptyMessage = state.searchMode
                ? 'Nenhuma conversa corresponde aos filtros aplicados.'
                : 'No threads for this folder.';
            threadList.innerHTML = `<p class="muted-text">${emptyMessage}</p>`;
            state.selectedThreads.clear();
            state.visibleThreadIds = [];
            selectionState.anchorThreadId = null;
            updateSelectionUI();
            return;
        }

        const visibleIds = new Set();
        const orderedIds = [];
        const groups = groupThreadsByDate(filteredThreads);
        const groupMarkup = (groups.length > 0 ? groups : [{ label: '', threads }])
            .map((group) => {
                const cards = group.threads.map((thread) => {
                    const threadId = Number(thread.id);
                    if (Number.isFinite(threadId)) {
                        visibleIds.add(threadId);
                        orderedIds.push(threadId);
                    }
                    return buildThreadCard(thread);
                }).join('');
                if (!group.label) {
                    return cards;
                }
                const safeLabel = escapeHtml(group.label);
                return `
                    <div class="thread-group">
                        <p class="thread-group-title">${safeLabel}</p>
                        ${cards}
                    </div>
                `;
            })
            .join('');

        Array.from(state.selectedThreads).forEach((selectedId) => {
            if (!visibleIds.has(selectedId)) {
                state.selectedThreads.delete(selectedId);
            }
        });

        threadList.innerHTML = groupMarkup;
        state.visibleThreadIds = orderedIds;
        updateSelectionUI();
        bindThreadRowQuickActions();
        refreshLoadMoreVisibility(filteredThreads.length);
    }

    function refreshLoadMoreVisibility(lastCount = null) {
        if (!loadMoreButton) {
            return;
        }
        const hasThreads = state.visibleThreadIds.length > 0;
        const inferredHasMore = state.hasMoreThreads || (lastCount !== null && lastCount >= state.threadLimit);
        loadMoreButton.hidden = !hasThreads;
        loadMoreButton.disabled = !inferredHasMore;
        if (!inferredHasMore) {
            loadMoreButton.textContent = 'Nada mais para carregar';
        } else {
            loadMoreButton.textContent = 'Carregar mais';
        }
    }

    function showInboxAlert(message, type = 'info', durationMs = 3800) {
        if (!inboxAlert || !message) {
            return;
        }
        inboxAlert.textContent = message;
        inboxAlert.dataset.state = type;
        inboxAlert.hidden = false;
        if (alertTimer) {
            window.clearTimeout(alertTimer);
        }
        alertTimer = window.setTimeout(() => {
            inboxAlert.hidden = true;
        }, durationMs);
    }

    function bindThreadRowQuickActions() {
        if (!threadList) {
            return;
        }

        threadList.querySelectorAll('[data-thread-row-star]').forEach((button) => {
            if (button.dataset.quickActionBound === 'star') {
                return;
            }
            button.dataset.quickActionBound = 'star';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                handleInlineStar(button);
            });
        });

        threadList.querySelectorAll('[data-thread-row-window]').forEach((button) => {
            if (button.dataset.quickActionBound === 'window') {
                return;
            }
            button.dataset.quickActionBound = 'window';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                handleThreadRowWindow(button);
            });
        });

        threadList.querySelectorAll('[data-thread-row-trash]').forEach((button) => {
            if (button.dataset.quickActionBound === 'trash') {
                return;
            }
            button.dataset.quickActionBound = 'trash';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                handleInlineTrash(button);
            });
        });
    }

    function renderTrashPreview(threads) {
        if (!trashPreviewElements.list) {
            return;
        }

        state.trashPreviewThreads = Array.isArray(threads) ? threads : [];
        const hasItems = state.trashPreviewThreads.length > 0;

        if (trashPreviewElements.count) {
            trashPreviewElements.count.textContent = hasItems
                ? String(state.trashPreviewThreads.length)
                : '0';
        }

        if (!hasItems) {
            trashPreviewElements.list.innerHTML = '<p class="muted-text">Nenhuma conversa na lixeira.</p>';
            return;
        }

        const markup = state.trashPreviewThreads.map((thread) => {
            const threadId = Number(thread.id) || '';
            const subject = escapeHtml(thread.subject || '(sem assunto)');
            const snippet = escapeHtml(thread.snippet || 'Sem prévia capturada.');
            const dateLabel = thread.last_message_at ? formatDate(thread.last_message_at) : 'Sem data';
            return `
                <button type="button" class="trash-preview-item" data-trash-thread-id="${threadId}">
                    <div class="trash-preview-info">
                        <strong>${subject}</strong>
                        <span>${snippet}</span>
                    </div>
                    <span class="trash-preview-date">${dateLabel}</span>
                </button>
            `;
        }).join('');

        trashPreviewElements.list.innerHTML = markup;
    }

    async function loadTrashPreview(options = {}) {
        const { silent = false } = options;
        if (!state.routes.threads || !canUseTrashPreview()) {
            renderTrashPreview([]);
            return;
        }

        if (trashPreviewElements.popover && !silent) {
            trashPreviewElements.popover.setAttribute('aria-busy', 'true');
        }

        try {
            const params = new URLSearchParams();
            if (state.accountId) {
                params.set('account_id', state.accountId);
            }
            params.set('folder_id', state.trashFolderId);
            params.set('limit', '6');
            params.set('include_folders', '1');

            const response = await fetch(`${state.routes.threads}?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Failed to load trash preview');
            }

            const payload = await response.json();
            const threads = Array.isArray(payload.threads) ? payload.threads : [];
            renderTrashPreview(threads);
        } catch (error) {
            console.error('Trash preview fetch failed', error);
            renderTrashPreview([]);
        } finally {
            if (trashPreviewElements.popover && !silent) {
                trashPreviewElements.popover.removeAttribute('aria-busy');
            }
        }
    }

    function handleTrashPreviewListClick(event) {
        const row = event.target.closest('[data-trash-thread-id]');
        if (!row) {
            return;
        }
        const threadId = Number(row.getAttribute('data-trash-thread-id'));
        if (!Number.isFinite(threadId)) {
            return;
        }
        closeTrashPreview();
        focusThreadFromTrash(threadId);
    }

    async function focusThreadFromTrash(threadId) {
        if (!Number.isFinite(threadId) || !state.trashFolderId) {
            return;
        }
        state.threadId = threadId;
        state.searchMode = false;
        state.localFolderActive = false;
        state.folderId = state.trashFolderId;
        updateActiveFolderChip(state.folderId);
        syncTrashPreviewTriggerState();
        await refreshThreads();
        loadThreadMessages(threadId);
    }

    function handleTrashPreviewToggle(event) {
        event.preventDefault();
        if (!canUseTrashPreview()) {
            return;
        }
        if (trashPreviewOpen) {
            closeTrashPreview();
        } else {
            openTrashPreview();
        }
    }

    function handleTrashPreviewOutside(event) {
        if (!trashPreviewOpen) {
            return;
        }
        const target = event.target;
        if (trashPreviewElements.popover && trashPreviewElements.popover.contains(target)) {
            return;
        }
        if (trashPreviewElements.trigger && trashPreviewElements.trigger.contains(target)) {
            return;
        }
        closeTrashPreview();
    }

    function handleTrashPreviewKeydown(event) {
        if (!trashPreviewOpen) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeTrashPreview({ restoreFocus: true });
        }
    }

    async function handleTrashEmpty(event) {
        event.preventDefault();
        if (!canUseTrashPreview() || !state.routes.emptyTrash || trashEmptyBusy) {
            return;
        }

        const firstConfirm = window.confirm('Tem certeza de que deseja limpar a lixeira?');
        if (!firstConfirm) {
            updateTrashEmptyStatus('Limpeza cancelada.', 'muted');
            return;
        }

        const secondConfirm = window.confirm('Essa ação não pode ser desfeita. Confirmar exclusão permanente?');
        if (!secondConfirm) {
            updateTrashEmptyStatus('Limpeza cancelada.', 'muted');
            return;
        }

        setTrashEmptyBusy(true);
        updateTrashEmptyStatus('Removendo conversas da lixeira...', 'loading');

        try {
            const body = {};
            if (state.csrf) {
                body._token = state.csrf;
            }
            if (state.accountId) {
                body.account_id = state.accountId;
            }

            const response = await fetch(state.routes.emptyTrash, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': state.csrf || '',
                },
                body: JSON.stringify(body),
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch (parseError) {
                payload = null;
            }

            if (!response.ok) {
                const fallback = payload && typeof payload.error === 'string' && payload.error.trim() !== ''
                    ? payload.error.trim()
                    : 'Falha ao limpar a lixeira.';
                throw new Error(fallback);
            }

            if (!payload) {
                throw new Error('Não recebemos confirmação do servidor.');
            }

            const deletedCount = Number(payload.deleted_threads || 0);
            const label = deletedCount === 1 ? 'conversa' : 'conversas';
            const successMessage = deletedCount > 0
                ? `Lixeira vazia (${deletedCount} ${label} removida${deletedCount === 1 ? '' : 's'}).`
                : 'Nenhuma conversa estava na lixeira.';
            updateTrashEmptyStatus(successMessage, 'success');

            syncFolderBadgesFromResponse(payload);
            await loadTrashPreview({ silent: true });

            if (Number(state.folderId) === Number(state.trashFolderId)) {
                state.threadId = null;
                state.currentThread = null;
                state.currentThreadMessages = [];
                updateThreadHeader(null);
                renderMessageDetailPlaceholder();
                await refreshThreads();
            }
        } catch (error) {
            const message = error instanceof Error && error.message ? error.message : 'Falha ao limpar a lixeira.';
            updateTrashEmptyStatus(message, 'error');
            console.error('Inbox trash empty failed', error);
        } finally {
            setTrashEmptyBusy(false);
        }
    }

    async function loadThreadMessages(threadId) {
        if (!state.routes.threadMessagesBase || !messageStack) {
            return;
        }

        state.selectedMessageId = null;
        renderMessageDetailPlaceholder();
        messageStack.setAttribute('aria-busy', 'true');
        try {
            const endpoint = `${state.routes.threadMessagesBase}/${threadId}/messages`;
            const response = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
            if (!response.ok) {
                throw new Error('Failed to load messages');
            }

            const payload = await response.json();
            renderMessages(payload);
        } catch (error) {
            console.error('Inbox messages fetch failed', error);
        } finally {
            messageStack.removeAttribute('aria-busy');
        }
    }

    function renderMessages(payload) {
        const messages = payload.messages || [];
        const thread = payload.thread || null;

        state.currentThreadMessages = messages;
        teardownMessageQuickActions();
        quickActionState.manualCondensed.clear();

        updateThreadHeader(thread);

        if (messages.length === 0) {
            messageStack.innerHTML = '<p class="muted-text">No messages to display.</p>';
            state.selectedMessageId = null;
            renderMessageDetailPlaceholder();
            return;
        }

        const html = messages.map((message) => {
            const outbound = (message.direction || 'inbound') === 'outbound';
            const attachments = (message.attachments || []).map((attachment) => `
                <span>${escapeHtml(attachment.filename)} · ${formatBytes(attachment.size_bytes || 0)}</span>
            `).join('');

            const summaryRaw = (message.participants || [])
                .filter((participant) => participant.role === 'from')
                .map((participant) => participant.email || '')
                .join(', ');
            const summary = summaryRaw ? highlightSearchText(summaryRaw) : '';

            const actionLabel = outbound ? 'Responder ou encaminhar (mensagem enviada)' : 'Responder ou encaminhar (mensagem recebida)';

            const actionListId = `message-action-list-${message.id}`;
            const standaloneUrl = state.routes && state.routes.messageStandaloneBase
                ? `${state.routes.messageStandaloneBase}/${message.id}/view`
                : '';
            const windowUrlAttr = standaloneUrl ? ` data-message-window-url="${escapeHtml(standaloneUrl)}"` : '';
            const quickActions = `
                <div class="message-quick-actions" data-message-actions>
                    <div
                        class="message-action-list"
                        id="${actionListId}"
                        role="group"
                        aria-label="${actionLabel}"
                        data-message-action-list
                    >
                        <button
                            type="button"
                            class="message-action-button"
                            title="Responder"
                            aria-label="Responder esta mensagem"
                            data-message-action
                            data-message-reply-mode="reply"
                            data-message-id="${message.id}"
                        >Responder</button>
                        <button
                            type="button"
                            class="message-action-button"
                            title="Responder a todos"
                            aria-label="Responder a todos desta mensagem"
                            data-message-action
                            data-message-reply-mode="reply_all"
                            data-message-id="${message.id}"
                        >Responder a todos</button>
                        <button
                            type="button"
                            class="message-action-button"
                            title="Encaminhar"
                            aria-label="Encaminhar esta mensagem"
                            data-message-action
                            data-message-reply-mode="forward"
                            data-message-id="${message.id}"
                        >Encaminhar</button>
                    </div>
                    <div class="message-action-hint" aria-hidden="true">
                        Responder, responder a todos ou encaminhar
                    </div>
                    <button
                        type="button"
                        class="message-action-overflow"
                        title="Mais ações"
                        aria-label="Abrir ações desta mensagem"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="${actionListId}"
                        data-message-action-overflow
                        data-message-id="${message.id}"
                    >Ações rápidas</button>
                </div>`;

            const subjectHtml = highlightSearchText(message.subject || '(no subject)');
            const previewText = message.body_preview || message.snippet || 'No preview available.';
            const previewHtml = highlightSearchText(previewText);

            return `
                <article class="message-bubble ${outbound ? 'from-me' : 'from-them'}" data-message-id="${message.id}"${windowUrlAttr} tabindex="0">
                    <header>
                        <strong>${subjectHtml}</strong>
                        <span>${formatDate(message.sent_at)}</span>
                    </header>
                    <p>${previewHtml}</p>
                    ${summary ? `<small style="display:block; margin-top:0.4rem; opacity:0.75;">From ${summary}</small>` : ''}
                    ${attachments ? `<div class="message-attachments">${attachments}</div>` : ''}
                    ${quickActions}
                </article>
            `;
        }).join('');

        messageStack.innerHTML = html;
        messageStack.scrollTop = messageStack.scrollHeight;
        bindMessageClickHandlers();
        setupMessageQuickActions();

        const validIds = messages
            .map((message) => Number(message.id))
            .filter((id) => Number.isFinite(id));

        if (validIds.length === 0) {
            state.selectedMessageId = null;
            renderMessageDetailPlaceholder();
            return;
        }

        if (!Number.isFinite(state.selectedMessageId) || !validIds.includes(Number(state.selectedMessageId))) {
            state.selectedMessageId = validIds[validIds.length - 1];
        }

        highlightSelectedMessage();
        loadMessageDetail(state.selectedMessageId);
    }

    function bindMessageClickHandlers() {
        if (!messageStack) {
            return;
        }

        const bubbles = messageStack.querySelectorAll('[data-message-id]');
        bubbles.forEach((bubble) => {
            bubble.addEventListener('click', () => {
                const messageId = parseInt(bubble.getAttribute('data-message-id') || '', 10);
                selectMessageById(messageId);
                closeMessageActionMenu({ restoreFocus: false });
            });

            bubble.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                const messageId = parseInt(bubble.getAttribute('data-message-id') || '', 10);
                selectMessageById(messageId);
                closeMessageActionMenu({ restoreFocus: false });
            });

            const quickActionButtons = bubble.querySelectorAll('[data-message-action]');
            quickActionButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    closeMessageActionMenu({ restoreFocus: false });

                    const mode = button.getAttribute('data-message-reply-mode') || 'reply';
                    const messageId = button.getAttribute('data-message-id');
                    const targetMessage = getMessageById(messageId);

                    handleReplyTrigger(event, mode, targetMessage);
                });
            });

            const overflowToggle = bubble.querySelector('[data-message-action-overflow]');
            if (overflowToggle) {
                overflowToggle.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const container = overflowToggle.closest('[data-message-actions]');
                    toggleMessageActionMenu(container);
                });
            }
        });
    }

    function handleMessageStackDoubleClick(event) {
        if (!messageStack) {
            return;
        }
        const target = event.target;
        if (!target) {
            return;
        }
        if (target.closest('button, a, input, textarea, select, [role="button"]')) {
            return;
        }
        const quickActionsArea = target.closest('[data-message-actions]');
        if (quickActionsArea) {
            return;
        }
        const bubble = target.closest('[data-message-id]');
        if (!bubble || !messageStack.contains(bubble)) {
            return;
        }
        const messageId = parseInt(bubble.getAttribute('data-message-id') || '', 10);
        const windowUrl = bubble.getAttribute('data-message-window-url');
        if (!Number.isFinite(messageId) && !windowUrl) {
            return;
        }
        event.preventDefault();
        closeMessageActionMenu({ restoreFocus: false });
        openMessageInWindow(Number.isFinite(messageId) ? messageId : null, windowUrl || undefined);
    }

    function bindMessageStackDoubleClick() {
        if (messageStackDoubleClickBound || !messageStack) {
            return;
        }
        messageStack.addEventListener('dblclick', handleMessageStackDoubleClick);
        messageStackDoubleClickBound = true;
    }

    function selectMessageById(messageId) {
        if (!Number.isFinite(messageId)) {
            return;
        }
        state.selectedMessageId = messageId;
        highlightSelectedMessage();
        loadMessageDetail(messageId);
    }

    function getMessageIndexById(messageId) {
        const numericId = Number(messageId);
        if (!Number.isFinite(numericId)) {
            return -1;
        }
        const messages = Array.isArray(state.currentThreadMessages) ? state.currentThreadMessages : [];
        return messages.findIndex((message) => Number(message.id) === numericId);
    }

    function getAdjacentMessageId(currentId, offset) {
        if (!Number.isFinite(Number(offset))) {
            return null;
        }
        const index = getMessageIndexById(currentId);
        if (index === -1) {
            return null;
        }
        const messages = Array.isArray(state.currentThreadMessages) ? state.currentThreadMessages : [];
        const target = messages[index + offset];
        return target && Number.isFinite(Number(target.id)) ? Number(target.id) : null;
    }

    function ensureMessageBubbleVisible(messageId) {
        if (!messageStack) {
            return;
        }
        const bubble = messageStack.querySelector(`[data-message-id="${messageId}"]`);
        if (bubble && typeof bubble.scrollIntoView === 'function') {
            bubble.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function navigateMessageDetail(direction) {
        if (!direction) {
            return;
        }
        const delta = direction === 'next' ? 1 : -1;
        const targetId = getAdjacentMessageId(state.selectedMessageId, delta);
        if (!Number.isFinite(targetId)) {
            return;
        }
        closeMessageActionMenu({ restoreFocus: false });
        selectMessageById(targetId);
        ensureMessageBubbleVisible(targetId);
    }

    function highlightSelectedMessage() {
        if (!messageStack) {
            return;
        }
        const bubbles = messageStack.querySelectorAll('[data-message-id]');
        bubbles.forEach((bubble) => {
            const messageId = parseInt(bubble.getAttribute('data-message-id') || '', 10);
            bubble.classList.toggle('is-active', Number.isFinite(messageId) && messageId === Number(state.selectedMessageId));
        });
    }

    function setupMessageQuickActions() {
        if (!messageStack || typeof ResizeObserver === 'undefined') {
            return;
        }

        const containers = messageStack.querySelectorAll('[data-message-actions]');
        if (containers.length === 0) {
            return;
        }

        if (!quickActionState.observer) {
            quickActionState.observer = new ResizeObserver((entries) => {
                entries.forEach((entry) => {
                    const container = entry.target;
                    const width = entry.contentRect.width;
                    const messageId = container.closest('[data-message-id]')
                        ? container.closest('[data-message-id]').getAttribute('data-message-id')
                        : null;
                    const manualValue = messageId ? quickActionState.manualCondensed.get(messageId) : null;
                    const shouldCondense = manualValue !== null
                        ? Boolean(manualValue)
                        : width < QUICK_ACTION_CONDENSE_WIDTH;
                    container.classList.toggle('is-condensed', shouldCondense);
                    if (!shouldCondense && quickActionState.openContainer === container) {
                        closeMessageActionMenu({ restoreFocus: false });
                    }
                });
            });
        }

        containers.forEach((container) => {
            quickActionState.observer.observe(container);
        });

        if (!quickActionState.listenersBound) {
            document.addEventListener('click', handleGlobalQuickActionDismiss, true);
            document.addEventListener('keydown', handleQuickActionKeydown, true);
            document.addEventListener('focusin', handleQuickActionFocusChange, true);
            quickActionState.listenersBound = true;
        }
    }

    function teardownMessageQuickActions() {
        if (quickActionState.observer && messageStack) {
            const containers = messageStack.querySelectorAll('[data-message-actions]');
            containers.forEach((container) => quickActionState.observer.unobserve(container));
        }
        closeMessageActionMenu({ restoreFocus: false });
    }

    function getMessageActionButtons(container) {
        if (!container) {
            return [];
        }
        return Array.from(container.querySelectorAll('[data-message-action]'));
    }

    function focusFirstMessageActionButton(container) {
        const buttons = getMessageActionButtons(container);
        if (buttons.length === 0) {
            return;
        }
        window.requestAnimationFrame(() => {
            if (document.contains(buttons[0])) {
                buttons[0].focus();
            }
        });
    }

    function toggleMessageActionMenu(container) {
        if (!container) {
            return;
        }

        const nextOpen = quickActionState.openContainer !== container;
        const messageId = container.closest('[data-message-id]')
            ? container.closest('[data-message-id]').getAttribute('data-message-id')
            : null;
        closeMessageActionMenu({ restoreFocus: false });
        if (nextOpen) {
            container.classList.add('is-open');
            const toggle = container.querySelector('[data-message-action-overflow]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
            quickActionState.openContainer = container;
            quickActionState.lastToggle = toggle || null;
            if (messageId) {
                quickActionState.manualCondensed.set(messageId, true);
            }
            focusFirstMessageActionButton(container);
        }
    }

    function closeMessageActionMenu(options = {}) {
        const { restoreFocus = false } = options;
        const container = quickActionState.openContainer;
        const toggle = quickActionState.lastToggle || (container ? container.querySelector('[data-message-action-overflow]') : null);

        if (container) {
            container.classList.remove('is-open');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }

        quickActionState.openContainer = null;

        if (restoreFocus && toggle && document.contains(toggle)) {
            window.requestAnimationFrame(() => {
                if (document.contains(toggle)) {
                    toggle.focus();
                }
            });
        }

        quickActionState.lastToggle = null;
    }

    function handleGlobalQuickActionDismiss(event) {
        if (!quickActionState.openContainer) {
            return;
        }
        const target = event.target;
        if (quickActionState.openContainer.contains(target)) {
            return;
        }
        closeMessageActionMenu({ restoreFocus: false });
    }

    function handleQuickActionFocusChange(event) {
        if (!quickActionState.openContainer) {
            return;
        }
        const target = event.target;
        if (quickActionState.openContainer.contains(target)) {
            return;
        }
        if (quickActionState.lastToggle && quickActionState.lastToggle === target) {
            closeMessageActionMenu({ restoreFocus: false });
            return;
        }
        closeMessageActionMenu({ restoreFocus: false });
    }

    function handleQuickActionKeydown(event) {
        if (!quickActionState.openContainer) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMessageActionMenu({ restoreFocus: true });
            return;
        }

        if (!quickActionState.openContainer.contains(event.target)) {
            return;
        }

        const buttons = getMessageActionButtons(quickActionState.openContainer);
        if (buttons.length === 0) {
            return;
        }

        const currentIndex = buttons.indexOf(event.target);
        if (currentIndex === -1) {
            return;
        }

        let nextIndex = null;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            nextIndex = (currentIndex + 1) % buttons.length;
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            nextIndex = (currentIndex - 1 + buttons.length) % buttons.length;
        } else if (event.key === 'Home') {
            event.preventDefault();
            nextIndex = 0;
        } else if (event.key === 'End') {
            event.preventDefault();
            nextIndex = buttons.length - 1;
        }

        if (nextIndex !== null && buttons[nextIndex] && document.contains(buttons[nextIndex])) {
            buttons[nextIndex].focus();
        }
    }

    async function loadMessageDetail(messageId) {
        if (!messageId || !state.routes.messageDetailBase || !messageDetail) {
            return;
        }

        messageDetail.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(`${state.routes.messageDetailBase}/${messageId}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Failed to load message detail');
            }

            const payload = await response.json();
            renderMessageDetail(payload);
        } catch (error) {
            console.error('Inbox message detail fetch failed', error);
            messageDetail.innerHTML = '<p class="muted-text">Não foi possível carregar a mensagem selecionada.</p>';
        } finally {
            messageDetail.removeAttribute('aria-busy');
        }
    }

    window.requestAnimationFrame(() => {
        const hasThread = Number.isFinite(Number(state.threadId)) && Number(state.threadId) > 0;
        const hasMessages = Array.isArray(state.currentThreadMessages) && state.currentThreadMessages.length > 0;
        if (hasThread && !hasMessages) {
            loadThreadMessages(state.threadId);
        }
    });

    // Fallback binding in caso algum listener não tenha sido conectado na carga inicial.
    function bindCriticalButtonsFallback() {
        const inline = root.querySelectorAll('[data-compose-trigger]');
        inline.forEach((btn) => {
            if (btn.dataset.bound === 'compose-inline') {
                return;
            }
            btn.dataset.bound = 'compose-inline';
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                openComposer();
            });
        });

        const windowBtns = root.querySelectorAll('[data-compose-window-trigger]');
        windowBtns.forEach((btn) => {
            if (btn.dataset.bound === 'compose-window') {
                return;
            }
            btn.dataset.bound = 'compose-window';
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                launchStandaloneComposerWindow();
            });
        });

        const syncBtn = root.querySelector('[data-sync-trigger]');
        if (syncBtn && syncBtn.dataset.bound !== 'sync') {
            syncBtn.dataset.bound = 'sync';
            syncBtn.addEventListener('click', (event) => {
                event.preventDefault();
                handleManualSync();
            });
        }
    }

    bindCriticalButtonsFallback();
    window.setTimeout(bindCriticalButtonsFallback, 300);
    window.setTimeout(bindCriticalButtonsFallback, 1200);

    // Diagnóstico: loga quando os botões críticos recebem binding.
    function logButtonState() {
        const composeInline = root.querySelectorAll('[data-compose-trigger]').length;
        const composeWindow = root.querySelectorAll('[data-compose-window-trigger]').length;
        const syncBtn = root.querySelector('[data-sync-trigger]');
        console.info('[Inbox] binds', {
            composeInline,
            composeWindow,
            syncBound: syncBtn ? syncBtn.dataset.bound : 'no-sync-btn',
            routes: config.routes,
        });
    }
    logButtonState();
    } catch (error) {
        console.error('[Inbox] init failed', error);
        const message = error && error.message ? error.message : 'Erro desconhecido na inicialização.';
        if (typeof alert === 'function') {
            alert('Falha ao carregar a inbox. ' + message);
        }
        try {
            const banner = document.createElement('div');
            banner.style.background = '#fee2e2';
            banner.style.color = '#b91c1c';
            banner.style.padding = '12px';
            banner.style.margin = '12px';
            banner.style.border = '1px solid #fecdd3';
            banner.style.borderRadius = '8px';
            banner.style.fontFamily = 'sans-serif';
            banner.textContent = 'Falha ao carregar a inbox: ' + message;
            document.body.prepend(banner);
        } catch (innerErr) {
            console.error('[Inbox] failed to render error banner', innerErr);
        }
    }
})();
</script>
