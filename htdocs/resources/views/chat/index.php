<?php
$chatJsPath = function_exists('public_path') ? public_path('js/chat.js') : __DIR__ . '/../../public/js/chat.js';
$chatJsVersion = file_exists($chatJsPath) ? filemtime($chatJsPath) : time();
?>
<script src="<?= url('js/chat.js') . '?v=' . $chatJsVersion; ?>" defer></script>
<style>
    :root {
        --chat-surface: rgba(15, 23, 42, 0.9);
        --chat-panel: rgba(7, 12, 26, 0.92);
        --chat-border: rgba(148, 163, 184, 0.25);
        --chat-border-strong: rgba(148, 163, 184, 0.4);
        --chat-muted: var(--muted, #94a3b8);
        --chat-accent: var(--accent, #38bdf8);
        --chat-success: var(--success, #22c55e);
        --chat-danger: #ef4444;
    }

    .chat-panel-embedded {
        background: linear-gradient(135deg, rgba(13, 23, 45, 0.95), rgba(9, 18, 36, 0.9));
        border: 1px solid var(--chat-border);
        <?php
        $chatJsPath = function_exists('public_path') ? public_path('js/chat.js') : __DIR__ . '/../../public/js/chat.js';
        $chatJsVersion = file_exists($chatJsPath) ? filemtime($chatJsPath) : time();

        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

        $formatTime = static function (?int $timestamp): string {
            if ($timestamp === null || $timestamp <= 0) {
                return '';
            }
            return date('d/m H:i', $timestamp);
        };

        $formatBytes = static function (?int $bytes): ?string {
            if ($bytes === null || $bytes <= 0) {
                return null;
            }
            $units = ['B', 'KB', 'MB', 'GB'];
            $size = (float)$bytes;
            $unitIndex = 0;
            while ($size >= 1024 && $unitIndex < count($units) - 1) {
                $size /= 1024;
                $unitIndex++;
            }
            $decimals = $unitIndex === 0 ? 0 : ($unitIndex >= 2 ? 2 : 1);
            return number_format($size, $decimals, ',', '.') . ' ' . $units[$unitIndex];
        };

        $threads = $threads ?? [];
        $activeThread = $activeThread ?? null;
        $activeThreadId = (int)($activeThreadId ?? 0);
        $messages = $messages ?? [];
        $currentUser = $currentUser ?? null;
        $isThreadClosed = (($activeThread['status'] ?? 'open') === 'closed');
        $currentThreadType = strtolower((string)($activeThread['type'] ?? 'direct'));
        $lastMessageId = !empty($messages) ? (int)($messages[array_key_last($messages)]['id'] ?? 0) : 0;
        $activeSessions = $activeSessions ?? [];
        $userOptions = $userOptions ?? [];
        $chatRoutes = $chatRoutes ?? [];
        ?>

        <script src="<?= url('js/chat.js') . '?v=' . $chatJsVersion; ?>" defer></script>
        <style>
            :root {
                --chat-surface: rgba(15, 23, 42, 0.9);
                --chat-panel: rgba(7, 12, 26, 0.92);
                --chat-border: rgba(148, 163, 184, 0.25);
                --chat-border-strong: rgba(148, 163, 184, 0.4);
                --chat-muted: var(--muted, #94a3b8);
                --chat-accent: var(--accent, #38bdf8);
                --chat-success: var(--success, #22c55e);
                --chat-danger: #ef4444;
            }

            .chat-panel-embedded {
                background: linear-gradient(135deg, rgba(13, 23, 45, 0.95), rgba(9, 18, 36, 0.9));
                border: 1px solid var(--chat-border);
                border-radius: 18px;
                box-shadow: 0 26px 80px -42px rgba(0, 0, 0, 0.65);
                display: flex;
                flex-direction: column;
                min-height: 0;
                padding: 16px;
            }

            body.is-chat-widget .chat-panel-embedded {
                background: transparent;
                border: none;
                box-shadow: none;
                padding: 0;
            }

            .chat-wrapper {
                display: grid;
                grid-template-columns: 320px 1fr;
                gap: 0;
                min-height: 0;
                flex: 1;
                border-radius: 18px;
                overflow: hidden;
                background: var(--chat-surface);
                border: 1px solid var(--chat-border);
            }

            .chat-sidebar {
                background: rgba(15, 23, 42, 0.9);
                border-right: 1px solid var(--chat-border);
                display: flex;
                flex-direction: column;
                min-height: 0;
            }

            .chat-sidebar header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 16px;
                border-bottom: 1px solid var(--chat-border);
                font-weight: 600;
            }

            .chat-sidebar header button,
            .chat-sidebar .chat-sidebar-action {
                background: rgba(148, 163, 184, 0.15);
                border: 1px solid var(--chat-border);
                color: var(--text);
                border-radius: 10px;
                padding: 8px 12px;
                cursor: pointer;
            }

            [data-chat-thread-list] {
                overflow-y: auto;
                padding: 12px;
                display: flex;
                flex-direction: column;
                gap: 10px;
                min-height: 0;
            }

            .chat-thread {
                width: 100%;
                text-align: left;
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid var(--chat-border);
                border-radius: 12px;
                padding: 10px 12px;
                color: var(--text);
                cursor: pointer;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 8px;
                align-items: center;
                transition: border-color 0.15s ease, background 0.15s ease;
            }

            .chat-thread:hover { border-color: var(--chat-border-strong); background: rgba(255, 255, 255, 0.04); }
            .chat-thread.is-active { border-color: var(--chat-accent); box-shadow: 0 10px 30px -18px rgba(56, 189, 248, 0.4); }
            .chat-thread-headline { display: flex; gap: 6px; align-items: center; font-weight: 600; }
            .chat-thread p { margin: 4px 0; color: var(--chat-muted); font-size: 0.9rem; }
            .chat-thread-meta { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; font-size: 0.78rem; color: var(--chat-muted); }
            .chat-unread { background: var(--chat-accent); color: #0b1224; border-radius: 999px; padding: 2px 8px; font-weight: 700; font-size: 0.75rem; }
            .chat-thread-badge { background: rgba(59, 130, 246, 0.16); border: 1px solid rgba(59, 130, 246, 0.35); border-radius: 8px; padding: 2px 6px; font-size: 0.7rem; color: var(--text); }
            .chat-thread-badge.is-external { background: rgba(234, 179, 8, 0.18); border-color: rgba(234, 179, 8, 0.45); }

            .chat-presence { padding: 10px 12px; border-top: 1px solid var(--chat-border); display: grid; gap: 8px; }
            .chat-presence-title { margin: 0; font-size: 0.85rem; color: var(--chat-muted); letter-spacing: 0.02em; }
            .chat-presence-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
            .chat-presence-item { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: var(--text); }
            .chat-presence-button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px 8px; background: transparent; border: none; color: inherit; text-align: left; border-radius: 8px; cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease; }
            .chat-presence-button:hover { background: rgba(255, 255, 255, 0.04); border: 1px solid var(--chat-border); }
            .chat-status-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--chat-muted); box-shadow: 0 0 6px rgba(0, 0, 0, 0.3); }
            .chat-status-dot.is-online { background: #22c55e; box-shadow: 0 0 6px rgba(34, 197, 94, 0.7); }
            .chat-status-dot.is-offline { background: #f87171; box-shadow: 0 0 6px rgba(248, 113, 113, 0.6); opacity: 0.85; }
            .chat-thread-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 8px; font-size: 0.78rem; border: 1px solid var(--chat-border); color: var(--chat-muted); }
            .chat-thread-status.is-warning { border-color: rgba(234, 179, 8, 0.45); color: #fbbf24; }
            .chat-thread-status.is-success { border-color: rgba(34, 197, 94, 0.45); color: var(--chat-success); }

            .chat-content { background: var(--chat-panel); display: flex; flex-direction: column; min-height: 0; }
            .chat-content-header { padding: 14px 18px; border-bottom: 1px solid var(--chat-border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .chat-content-header span { color: var(--chat-muted); font-size: 0.85rem; }

            [data-thread-closed-badge] { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 8px; background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.35); color: #fca5a5; font-size: 0.8rem; }
            [data-thread-closed-badge][hidden] { display: none; }

            .chat-messages { flex: 1; overflow-y: auto; padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; }
            .chat-message { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--chat-border); border-radius: 12px; padding: 10px 12px; max-width: 640px; }
            .chat-message header { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: var(--chat-muted); margin-bottom: 6px; }
            .chat-message.is-mine { background: rgba(56, 189, 248, 0.12); border-color: rgba(56, 189, 248, 0.35); align-self: flex-end; }
            .chat-message.is-system { background: rgba(148, 163, 184, 0.12); border-color: rgba(148, 163, 184, 0.35); text-align: center; }
            .chat-message.is-visitor { background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.35); }
            .chat-message p { margin: 0; line-height: 1.45; color: var(--text); }

            .chat-attachment { margin-top: 8px; border: 1px dashed var(--chat-border); border-radius: 10px; padding: 10px; background: rgba(255, 255, 255, 0.02); display: grid; gap: 6px; }
            .chat-attachment--image img { max-width: 100%; border-radius: 8px; display: block; }
            .chat-attachment--audio audio { width: 100%; }
            .chat-attachment a { color: var(--chat-accent); font-weight: 600; }
            .chat-attachment small { color: var(--chat-muted); }
            .chat-attachment-meta { display: flex; gap: 6px; align-items: center; font-size: 0.85rem; color: var(--chat-muted); }

            .chat-compose { border-top: 1px solid var(--chat-border); padding: 12px 14px; background: rgba(255, 255, 255, 0.02); display: flex; flex-direction: column; gap: 10px; }
            .chat-compose.is-disabled { opacity: 0.6; pointer-events: none; }
            .chat-compose form { display: grid; grid-template-columns: 1fr auto; grid-template-rows: auto 1fr; gap: 10px; align-items: center; }
            .chat-attachment-bar { grid-column: 1 / span 2; display: flex; gap: 8px; align-items: center; }
            .chat-attach-button { background: rgba(148, 163, 184, 0.15); border: 1px dashed var(--chat-border); color: var(--text); border-radius: 10px; padding: 8px 12px; cursor: pointer; }
            .chat-attachment-chip { background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.35); color: var(--text); border-radius: 999px; padding: 6px 10px; display: inline-flex; gap: 6px; align-items: center; }
            .chat-attachment-clear { background: transparent; color: var(--chat-muted); border: 1px solid var(--chat-border); border-radius: 50%; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
            [data-chat-input] { width: 100%; min-height: 48px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--chat-border); background: rgba(255, 255, 255, 0.03); color: var(--text); resize: vertical; grid-row: 2; grid-column: 1; }
            .chat-compose-actions { display: flex; align-items: center; gap: 8px; }
            .chat-compose-actions button { background: linear-gradient(120deg, var(--chat-accent), var(--accent-hover, #0ea5e9)); color: #0b1224; border: none; border-radius: 12px; padding: 10px 14px; cursor: pointer; font-weight: 700; box-shadow: 0 16px 32px -20px rgba(56, 189, 248, 0.7); }
            .chat-emoji-toggle { background: rgba(148, 163, 184, 0.15); color: var(--text); border: 1px solid var(--chat-border); border-radius: 10px; padding: 10px 12px; cursor: pointer; }

            .chat-emoji-panel { grid-column: 1 / span 2; display: none; gap: 6px; flex-wrap: wrap; }
            .chat-emoji-panel[data-open="true"] { display: flex; }
            .chat-emoji-panel button { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--chat-border); border-radius: 10px; padding: 6px 8px; cursor: pointer; font-size: 1rem; }

            .chat-closed-banner { padding: 10px 12px; border-radius: 10px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.35); color: #fecdd3; font-size: 0.9rem; }
            .chat-closed-banner[hidden], [data-close-thread][hidden] { display: none; }
            .chat-close-thread { align-self: flex-end; background: linear-gradient(120deg, rgba(239, 68, 68, 0.9), rgba(248, 113, 113, 0.9)); color: #fff; border: none; border-radius: 12px; padding: 10px 14px; cursor: pointer; font-weight: 700; }
            .chat-close-hint { font-size: 0.85rem; color: var(--chat-muted); }

            @media (max-width: 960px) {
                .chat-wrapper { grid-template-columns: 1fr; height: 100%; }
                .chat-sidebar { max-height: 360px; }
            }

            .chat-toast-stack { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; gap: 12px; z-index: 120; pointer-events: none; }
            .chat-toast { min-width: 260px; max-width: 360px; background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(59, 130, 246, 0.4); border-radius: 16px; padding: 14px 16px; box-shadow: 0 18px 35px rgba(0, 0, 0, 0.35); color: var(--text); font-weight: 600; display: flex; align-items: center; justify-content: space-between; pointer-events: auto; }
            .chat-toast small { display: block; color: var(--chat-muted); font-size: 0.85rem; }
            .chat-toast-stack [data-chat-toast-dismiss] { background: transparent; border: none; color: var(--text); cursor: pointer; font-size: 1rem; }

            .chat-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.65); display: none; align-items: center; justify-content: center; z-index: 110; padding: 16px; }
            .chat-modal[data-open="true"] { display: flex; }
            .chat-modal-content { background: #0f172a; border: 1px solid var(--chat-border); border-radius: 14px; padding: 18px; width: min(480px, 100%); color: var(--text); box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45); }
            .chat-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
            .chat-modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
            .chat-user-picker { display: grid; gap: 10px; max-height: 300px; overflow-y: auto; padding: 8px; border: 1px solid var(--chat-border); border-radius: 10px; }
            .chat-user-picker-item { border: 1px solid var(--chat-border); border-radius: 10px; padding: 10px; display: flex; gap: 10px; align-items: center; cursor: pointer; transition: border-color 0.15s ease, transform 0.15s ease; }
            .chat-user-picker-item:hover { border-color: var(--chat-accent); transform: translateX(2px); }
            .chat-user-picker-item.is-selected { border-color: rgba(59, 130, 246, 0.7); background: rgba(59, 130, 246, 0.18); }
            .chat-user-status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; box-shadow: 0 0 6px rgba(0, 0, 0, 0.3); }
            .chat-user-status-dot.is-online { background: #22c55e; box-shadow: 0 0 6px rgba(34, 197, 94, 0.7); }
            .chat-user-status-dot.is-offline { background: #f87171; box-shadow: 0 0 6px rgba(248, 113, 113, 0.5); }
            .chat-field-hint { font-size: 0.75rem; color: var(--chat-muted); }
        </style>

        <div
            class="chat-panel-embedded"
            data-chat-app
            data-threads-endpoint="<?= $escape($chatRoutes['threads'] ?? ''); ?>"
            data-thread-base="<?= $escape($chatRoutes['messagesBase'] ?? ''); ?>"
            data-mark-read-base="<?= $escape($chatRoutes['markReadBase'] ?? ''); ?>"
            data-create-thread="<?= $escape($chatRoutes['createThread'] ?? ''); ?>"
            data-create-group="<?= $escape($chatRoutes['createGroup'] ?? ''); ?>"
            data-claim-external-base="<?= $escape($chatRoutes['claimExternalBase'] ?? ''); ?>"
            data-external-status-base="<?= $escape($chatRoutes['externalStatusBase'] ?? ''); ?>"
            data-close-thread-base="<?= $escape($chatRoutes['closeThreadBase'] ?? ''); ?>"
            data-csrf="<?= $escape(csrf_token()); ?>"
            data-current-user="<?= (int)($currentUser->id ?? 0); ?>"
            data-current-user-name="<?= $escape($currentUser->name ?? ''); ?>"
            data-active-thread="<?= $activeThreadId; ?>"
            data-last-message-id="<?= $lastMessageId; ?>"
            data-thread-closed="<?= $isThreadClosed ? '1' : '0'; ?>"
            data-active-thread-type="<?= $escape($currentThreadType); ?>"
        >
            <div class="chat-wrapper">
                <aside class="chat-sidebar">
                    <header>
                        <span>Chat interno</span>
                        <button type="button" data-chat-refresh>Atualizar</button>
                    </header>

                    <div data-chat-thread-list>
                        <?php if ($threads === []): ?>
                            <p class="chat-empty">Nenhuma conversa encontrada.</p>
                        <?php else: ?>
                            <?php foreach ($threads as $thread): ?>
                                <?php
                                $threadId = (int)($thread['id'] ?? 0);
                                $unread = (int)($thread['unread_count'] ?? 0);
                                $preview = $thread['last_message_preview'] ?? '';
                                $lastAt = isset($thread['last_message_created_at']) ? (int)$thread['last_message_created_at'] : null;
                                $lastLabel = $lastAt ? $formatTime($lastAt) : '';
                                $type = strtolower((string)($thread['type'] ?? 'direct'));
                                $status = strtolower((string)($thread['status'] ?? 'open'));
                                $badges = '';
                                if ($type === 'group') {
                                    $badges .= '<span class="chat-thread-badge">Grupo</span>';
                                }
                                if ($type === 'external') {
                                    $badges .= '<span class="chat-thread-badge is-external">Externo</span>';
                                }
                                $statusBlock = '';
                                if ($type === 'external') {
                                    $statusBlock = '<div class="chat-thread-status-row"><span class="chat-thread-status is-warning">Aguardando agente</span></div>';
                                }
                                ?>
                                <button type="button" class="chat-thread<?= $threadId === $activeThreadId ? ' is-active' : ''; ?>" data-thread-id="<?= $threadId; ?>" data-thread-type="<?= $escape($type); ?>" data-thread-status="<?= $escape($status); ?>" data-last-message-id="<?= (int)($thread['last_message_id'] ?? 0); ?>">
                                    <div class="chat-thread-text">
                                        <div class="chat-thread-headline"><strong><?= $escape($thread['display_name'] ?? 'Chat'); ?></strong><?= $badges; ?></div>
                                        <?php if ($preview): ?><p><?= $escape($preview); ?></p><?php endif; ?>
                                        <?= $statusBlock; ?>
                                    </div>
                                    <div class="chat-thread-meta">
                                        <?php if ($lastLabel): ?><span><?= $escape($lastLabel); ?></span><?php endif; ?>
                                        <?php if ($unread > 0): ?><span class="chat-unread"><?= $unread; ?></span><?php endif; ?>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="chat-sidebar-actions" style="padding: 12px; display: grid; gap: 8px;">
                        <button type="button" class="chat-sidebar-action" data-chat-open-modal="direct">Novo chat</button>
                        <button type="button" class="chat-sidebar-action" data-chat-open-modal="group">Novo grupo</button>
                    </div>

                    <div class="chat-presence">
                        <p class="chat-presence-title">Online agora</p>
                        <ul class="chat-presence-list" data-user-picker>
                            <?php if ($activeSessions === []): ?>
                                <li class="chat-presence-item">Nenhum colega online.</li>
                            <?php else: ?>
                                <?php foreach ($activeSessions as $session): ?>
                                    <?php $userId = (int)($session['id'] ?? 0); ?>
                                    <li class="chat-presence-item">
                                        <button type="button" class="chat-presence-button" data-start-chat="<?= $userId; ?>">
                                            <span class="chat-status-dot is-online"></span>
                                            <span><?= $escape($session['name'] ?? 'Colaborador'); ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </aside>

                <section class="chat-content">
                    <header class="chat-content-header">
                        <div>
                            <strong><?= isset($activeThread['display_name']) ? $escape($activeThread['display_name']) : 'Chat interno'; ?></strong>
                            <span data-thread-closed-badge<?= $isThreadClosed ? '' : ' hidden'; ?>>Encerrado</span>
                        </div>
                        <div data-thread-external-wrapper hidden>
                            <span data-thread-external-label class="chat-thread-status is-warning">Aguardando agente</span>
                            <span data-thread-external-agent hidden></span>
                        </div>
                    </header>

                    <div class="chat-messages" data-chat-messages>
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $message): ?>
                                <?php
                                $authorId = (int)($message['author_id'] ?? 0);
                                $visitorName = $message['external_author'] ?? '';
                                $hasExternal = $visitorName !== '';
                                $isSystem = !$hasExternal && ($authorId === 0 || (($message['is_system'] ?? '0') === '1'));
                                $mine = !$hasExternal && $authorId === (int)($currentUser->id ?? 0);
                                $classes = 'chat-message';
                                if ($mine) { $classes .= ' is-mine'; }
                                if ($isSystem) { $classes .= ' is-system'; }
                                if ($hasExternal) { $classes .= ' is-visitor'; }
                                $authorLabel = $hasExternal ? $visitorName : ($isSystem ? 'Sistema' : ($mine ? 'Você' : ($message['author_name'] ?? 'Colaborador')));
                                $timestamp = isset($message['created_at']) ? $formatTime((int)$message['created_at']) : '';
                                $type = strtolower((string)($message['type'] ?? 'text'));
                                $attachmentUrl = $message['attachment_url'] ?? null;
                                $attachmentName = $message['attachment_name'] ?? null;
                                $attachmentSize = isset($message['attachment_size']) ? (int)$message['attachment_size'] : null;
                                ?>
                                <article class="<?= $escape($classes); ?>" data-message-id="<?= (int)($message['id'] ?? 0); ?>">
                                    <header><strong><?= $escape($authorLabel); ?></strong><span><?= $escape($timestamp); ?></span></header>
                                    <p><?= nl2br($escape($message['body'] ?? '')); ?></p>
                                    <?php if ($attachmentUrl): ?>
                                        <div class="chat-attachment chat-attachment--<?= $escape($type); ?>">
                                            <?php if ($type === 'image'): ?>
                                                <img src="<?= $escape($attachmentUrl); ?>" alt="<?= $escape($attachmentName ?? 'Imagem'); ?>">
                                            <?php elseif ($type === 'audio'): ?>
                                                <audio controls src="<?= $escape($attachmentUrl); ?>" preload="none"></audio>
                                            <?php else: ?>
                                                <a href="<?= $escape($attachmentUrl); ?>" target="_blank" rel="noopener"><?= $escape($attachmentName ?? 'Arquivo'); ?></a>
                                            <?php endif; ?>
                                            <div class="chat-attachment-meta">
                                                <?php if ($attachmentName): ?><span><?= $escape($attachmentName); ?></span><?php endif; ?>
                                                <?php if ($formatBytes($attachmentSize)): ?><small><?= $escape($formatBytes($attachmentSize)); ?></small><?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="chat-compose<?= $isThreadClosed ? ' is-disabled' : ''; ?>">
                        <form data-chat-form action="<?= $escape(($chatRoutes['messagesBase'] ?? '') . '/' . $activeThreadId . '/messages'); ?>" method="post" enctype="multipart/form-data">
                            <div class="chat-attachment-bar">
                                <button type="button" class="chat-attach-button" data-attach-trigger>Anexar</button>
                                <div class="chat-attachment-chip" data-attachment-chip hidden>
                                    <span data-attachment-name></span>
                                    <small data-attachment-size></small>
                                    <button type="button" class="chat-attachment-clear" data-clear-attachment aria-label="Remover anexo">&times;</button>
                                </div>
                                <input type="file" name="attachment" data-chat-attachment-input hidden accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                            </div>
                            <textarea name="body" data-chat-input rows="2" placeholder="Digite sua mensagem"<?= $isThreadClosed ? ' disabled' : ''; ?>></textarea>
                            <div class="chat-compose-actions">
                                <button type="button" class="chat-emoji-toggle" data-emoji-toggle aria-expanded="false">😊</button>
                                <button type="submit"<?= $isThreadClosed ? ' disabled' : ''; ?>>Enviar</button>
                            </div>
                            <div class="chat-emoji-panel" data-emoji-panel>
                                <?php foreach (["😀","😂","😊","👍","🙏","🎉"] as $emoji): ?>
                                    <button type="button" data-emoji-button="<?= $emoji; ?>"><?= $emoji; ?></button>
                                <?php endforeach; ?>
                            </div>
                        </form>
                        <div data-chat-closed-banner class="chat-closed-banner"<?= $isThreadClosed ? '' : ' hidden'; ?>>Atendimento encerrado.</div>
                        <div data-close-hint class="chat-close-hint" hidden>Finalize após atender o lead.</div>
                        <button type="button" data-close-thread class="chat-close-thread"<?= $currentThreadType === 'external' && !$isThreadClosed ? '' : ' hidden'; ?>>Encerrar atendimento</button>
                    </div>
                </section>
            </div>
        </div>

        <div class="chat-toast-stack" data-chat-toast-container></div>

        <div class="chat-modal" data-chat-modal="direct">
            <div class="chat-modal-content">
                <div class="chat-modal-header">
                    <strong>Iniciar novo chat</strong>
                    <button type="button" data-chat-close-modal aria-label="Fechar">&times;</button>
                </div>
                <form data-new-chat-form action="<?= $escape($chatRoutes['createThread'] ?? ''); ?>" method="post">
                    <div class="chat-user-picker" data-user-picker>
                        <?php if ($userOptions === []): ?>
                            <div class="chat-user-picker-item">Nenhum usuário disponível.</div>
                        <?php else: ?>
                            <?php foreach ($userOptions as $user): ?>
                                <?php $uid = (int)($user['id'] ?? 0); ?>
                                <button type="button" class="chat-user-picker-item" data-user-id="<?= $uid; ?>">
                                    <span class="chat-user-status-dot is-online"></span>
                                    <div>
                                        <strong><?= $escape($user['name'] ?? 'Usuário'); ?></strong>
                                        <small><?= $escape($user['email'] ?? ''); ?></small>
                                    </div>
                                    <span class="chat-user-picker-cta">Selecionar</span>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="user_id" data-user-field>
                    <input type="hidden" name="_token" value="<?= $escape(csrf_token()); ?>">
                    <div class="chat-modal-actions">
                        <button type="button" data-chat-close-modal>Cancelar</button>
                        <button type="submit">Iniciar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="chat-modal" data-chat-modal="group">
            <div class="chat-modal-content">
                <div class="chat-modal-header">
                    <strong>Novo grupo</strong>
                    <button type="button" data-chat-close-modal aria-label="Fechar">&times;</button>
                </div>
                <form data-group-chat-form action="<?= $escape($chatRoutes['createGroup'] ?? ''); ?>" method="post">
                    <label>
                        Assunto do grupo
                        <input type="text" name="subject" required>
                    </label>
                    <div class="chat-field-hint">Selecione os participantes</div>
                    <div class="chat-user-picker" style="grid-template-columns: 1fr;">
                        <?php foreach ($userOptions as $user): ?>
                            <?php $uid = (int)($user['id'] ?? 0); ?>
                            <label class="chat-user-picker-item">
                                <input type="checkbox" name="participants[]" value="<?= $uid; ?>">
                                <span class="chat-user-status-dot is-online"></span>
                                <div>
                                    <strong><?= $escape($user['name'] ?? 'Usuário'); ?></strong>
                                    <small><?= $escape($user['email'] ?? ''); ?></small>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="_token" value="<?= $escape(csrf_token()); ?>">
                    <div class="chat-modal-actions">
                        <button type="button" data-chat-close-modal>Cancelar</button>
                        <button type="submit">Criar grupo</button>
                    </div>
                </form>
            </div>
        </div>