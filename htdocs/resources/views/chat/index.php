<?php
$chatJsPath = function_exists('public_path') ? public_path('js/chat.js') : __DIR__ . '/../../public/js/chat.js';
$chatJsVersion = file_exists($chatJsPath) ? filemtime($chatJsPath) : time();
?>
<script src="<?= url('js/chat.js') . '?v=' . $chatJsVersion; ?>" defer></script>
<style>


.chat-toast-stack {
        position: fixed;
        bottom: 24px;
        right: 24px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        z-index: 120;
        pointer-events: none;
    }
    .chat-toast {
        min-width: 260px;
        max-width: 360px;
        background: rgba(15, 23, 42, 0.95);
        border: 1px solid rgba(59, 130, 246, 0.4);
        border-radius: 16px;
        padding: 14px 16px;
        box-shadow: 0 18px 35px rgba(0, 0, 0, 0.35);
        color: var(--text);
        pointer-events: auto;
        animation: chat-toast-in 0.25s ease forwards;
    }
    .chat-toast strong {
        display: block;
        font-size: 0.95rem;
        margin-bottom: 4px;
    }
    .chat-toast p {
        margin: 0;
        font-size: 0.85rem;
        color: var(--muted);
    }
    @keyframes chat-toast-in {
        from {
            opacity: 0;
            transform: translateY(12px) scale(0.98);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    .chat-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.7);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 90;
    }
    .chat-modal[data-open="true"] {
        display: flex;
    }
    .chat-modal-panel {
        background: rgba(15, 23, 42, 0.95);
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 20px;
        padding: 24px;
        width: 360px;
        max-width: 90vw;
    }
    .chat-modal-panel header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }
    .chat-modal-panel form label {
        display: flex;
        flex-direction: column;
        gap: 8px;
        font-size: 0.9rem;
        margin-bottom: 16px;
    }
    .chat-modal-panel form select {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: rgba(15, 23, 42, 0.6);
        color: var(--text);
    }
    .chat-user-picker {
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-height: 260px;
        overflow-y: auto;
        margin: 12px 0;
        padding-right: 6px;
    }
    .chat-user-picker-item {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(148, 163, 184, 0.3);
        background: rgba(15, 23, 42, 0.65);
        color: var(--text);
        cursor: pointer;
        transition: border-color 0.15s ease, transform 0.15s ease;
    }
    .chat-user-picker-item:hover {
        border-color: var(--accent);
        transform: translateX(2px);
    }
    .chat-user-picker-item.is-selected {
        border-color: rgba(59, 130, 246, 0.7);
        background: rgba(59, 130, 246, 0.18);
    }
    .chat-user-status-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 6px rgba(0, 0, 0, 0.3);
    }
    .chat-user-status-dot.is-online {
        background: #22c55e;
        box-shadow: 0 0 6px rgba(34, 197, 94, 0.7);
    }
    .chat-user-status-dot.is-offline {
        background: #f87171;
        box-shadow: 0 0 6px rgba(248, 113, 113, 0.5);
    }
    .chat-user-picker-item strong {
        display: block;
    }
    .chat-user-picker-item small {
        display: block;
        color: var(--muted);
        font-size: 0.75rem;
    }
    .chat-user-picker-cta {
        margin-left: auto;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--muted);
    }
    .chat-modal-panel form input[type="text"] {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: rgba(15, 23, 42, 0.6);
        color: var(--text);
    }
    .chat-modal-panel form select[multiple] {
        min-height: 180px;
    }
    .chat-field-hint {
        font-size: 0.75rem;
        color: var(--muted);
    }
    .chat-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    @media (max-width: 960px) {
        .chat-wrapper {
            grid-template-columns: 1fr;
        }
        .chat-sidebar {
            border-right: none;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }
    }
        body.is-chat-widget .chat-panel-embedded {
            margin: 0;
            border: none;
            background: transparent;
            box-shadow: none;
            padding: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            width: 100%;
        }
        body.is-chat-widget .chat-wrapper {
            min-height: 0;
            height: 100%;
            max-height: none;
            border-radius: 18px;
            overflow: hidden;
            flex: 1 1 auto;
        }
        body.is-chat-widget .chat-sidebar {
            background: rgba(15, 23, 42, 0.9);
        }
        body.is-chat-widget .chat-content {
            background: rgba(3, 7, 18, 0.72);
            min-height: 0;
            flex: 1;
        }

</style>