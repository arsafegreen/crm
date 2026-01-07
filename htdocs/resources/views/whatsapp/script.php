<script>
(() => {
    const globalState = window.SafeGreenWhatsApp = window.SafeGreenWhatsApp || {};
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';
    const threadId = <?= $activeThreadId; ?>;
    const whatsappThreadUrlTemplate = <?= json_encode($whatsappThreadUrlTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const whatsappMediaBaseUrl = <?= json_encode(rtrim(url('whatsapp/media'), '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const threadPollEndpoint = <?= json_encode(url('whatsapp/thread-poll'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const panelRefreshEndpoint = <?= json_encode(url('whatsapp/panel-refresh'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const THREAD_POLL_INTERVAL = 5000;
    const THREAD_POLL_IDLE_INTERVAL = 30000;
    const THREAD_POLL_BACKGROUND_INTERVAL = 30000;
    const THREAD_PREFETCH_LIMIT = 4;
    const THREAD_HISTORY_PAGE_SIZE = 40;
    const PANEL_REFRESH_INTERVAL = 12000;
    const PANEL_REFRESH_BACKGROUND_INTERVAL = 30000;
    const PANEL_REFRESH_IDLE_INTERVAL = 20000;
    const INITIAL_MESSAGE_RENDER_LIMIT = 120;
    const LAZY_MEDIA_PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
    const IDLE_THRESHOLD_MS = 2 * 60 * 1000;
    const selectedChannel = <?= json_encode($selectedChannel ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const deferPanels = <?= !empty($deferPanels) ? 'true' : 'false'; ?>;
    const activeThreadContext = {
        id: <?= (int)$activeThreadId; ?>,
        name: <?= json_encode($activeContactName ?? 'Contato WhatsApp', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        phone: <?= json_encode($activeContactPhone ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        line: <?= json_encode($activeLineChip ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        clientId: <?= (int)($activeClientId ?? 0); ?>,
        isGroup: <?= $activeThread !== null && (($activeThread['chat_type'] ?? '') === 'group') ? 'true' : 'false'; ?>,
        queue: <?= json_encode($currentQueueKey ?? 'arrival', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    };
    const NOTIFY_SOUND_KEY = 'wa_notify_sound';
    const NOTIFY_POPUP_KEY = 'wa_notify_popup';
    const NOTIFY_COOLDOWN_KEY = 'wa_notify_cooldown';
    const NOTIFY_SOUND_STYLE_KEY = 'wa_notify_sound_style';
    const NOTIFY_SOUND_CUSTOM_DATA_KEY = 'wa_notify_sound_custom_data';
    const NOTIFY_SOUND_CUSTOM_NAME_KEY = 'wa_notify_sound_custom_name';
    const MAX_CUSTOM_SOUND_BYTES = 1024 * 1024; // 1MB
    const pageLoadedAt = Date.now();
    let panelRefreshTimer = null;
    let isPanelRefreshing = false;
    let panelRefreshController = null;
    let threadNavigationLocked = false;

    const mediaTemplates = <?= json_encode($mediaTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const composerCapabilities = {
        stickersAllowed: <?= $threadSupportsTemplates ? 'true' : 'false'; ?>,
    };

    const broadcastQueueLabels = <?= json_encode($broadcastQueueLabels ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const initialBroadcastHistory = <?= json_encode($recentBroadcasts ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const messageForm = document.getElementById('wa-message-form');
    const messageFeedback = document.getElementById('wa-message-feedback');
    const messageMediaInput = messageForm ? messageForm.querySelector('[data-message-media]') : null;
    const messageTextarea = messageForm ? messageForm.querySelector('textarea[name="message"]') : null;
    const messageMediaPreview = messageForm ? messageForm.querySelector('[data-media-preview]') : null;
    const messageMediaName = messageMediaPreview ? messageMediaPreview.querySelector('[data-media-name]') : null;
    const messageMediaSize = messageMediaPreview ? messageMediaPreview.querySelector('[data-media-size]') : null;
    const messageMediaClear = messageForm ? messageForm.querySelector('[data-media-clear]') : null;
    const templateKindField = messageForm ? messageForm.querySelector('[data-template-kind]') : null;
    const templateKeyField = messageForm ? messageForm.querySelector('[data-template-key]') : null;
    const templatePreview = messageForm ? messageForm.querySelector('[data-template-preview]') : null;
    const templatePreviewLabel = templatePreview ? templatePreview.querySelector('[data-template-preview-label]') : null;
    const templatePreviewDescription = templatePreview ? templatePreview.querySelector('[data-template-preview-description]') : null;
    const templatePreviewImage = templatePreview ? templatePreview.querySelector('[data-template-preview-image]') : null;
    const templateClearButton = messageForm ? messageForm.querySelector('[data-template-clear]') : null;
    const audioRecordButton = messageForm ? messageForm.querySelector('[data-audio-record]') : null;
    const audioRecordCancel = messageForm ? messageForm.querySelector('[data-audio-cancel]') : null;
    const audioRecordStatus = messageForm ? messageForm.querySelector('[data-audio-status]') : null;
    const templateButtons = document.querySelectorAll('[data-template-open]');
    const templateItemButtons = document.querySelectorAll('[data-template-item]');
    const templateCloseButtons = document.querySelectorAll('[data-template-close]');
    const aiFillButton = document.getElementById('wa-ai-fill');
    const aiOutput = document.getElementById('wa-ai-output');
    const aiGoalButtons = document.querySelectorAll('.wa-ai-actions button[data-goal]');
    const assignSelfButtons = document.querySelectorAll('[data-assign-self]');
    const releaseButtons = document.querySelectorAll('[data-release-thread]');
    const transferButtons = document.querySelectorAll('[data-open-transfer]');
    const statusButtons = document.querySelectorAll('[data-status]');
    const assignUserForm = document.getElementById('wa-assign-user');
    const permissionForm = document.getElementById('wa-permission-form');
    const permissionFeedback = document.getElementById('wa-permission-feedback');
    const copilotForm = document.getElementById('wa-copilot-form');
    const copilotFeedback = document.getElementById('wa-copilot-feedback');
    const copilotProfileForm = document.getElementById('wa-copilot-profile-form');
    const copilotProfileFeedback = document.getElementById('wa-copilot-profile-feedback');
    const copilotProfileReset = document.getElementById('wa-copilot-profile-reset');
    const copilotProfileEditButtons = document.querySelectorAll('[data-copilot-profile-edit]');
    const copilotProfileDeleteButtons = document.querySelectorAll('[data-copilot-profile-delete]');
    const notifySoundToggle = document.querySelector('[data-notify-sound-toggle]');
    const notifyPopupToggle = document.querySelector('[data-notify-popup-toggle]');
    const notifySoundStyleSelect = document.querySelector('[data-notify-sound-style]');
    const notifyDelaySelect = document.querySelector('[data-notify-delay]');
    const toastStack = document.querySelector('[data-toast-stack]');
    const backupRestoreForm = document.getElementById('wa-backup-restore-form');
    const backupRestoreFeedback = document.getElementById('wa-backup-restore-feedback');
    const notifyPanel = document.querySelector('[data-notify-panel]');
    const notifyPanelToggle = document.querySelector('[data-notify-panel-toggle]');
    const notifySummary = document.querySelector('[data-notify-summary]');
    const notifySoundUpload = document.querySelector('[data-notify-sound-upload]');
    const notifySoundFileInput = document.querySelector('[data-notify-sound-file]');
    const notifySoundFilename = document.querySelector('[data-notify-sound-filename]');
    const notifySoundFeedback = document.querySelector('[data-notify-sound-feedback]');
    const notifySoundClearButton = document.querySelector('[data-notify-clear-sound]');
    const notifyTestSoundButton = document.querySelector('[data-notify-test-sound]');

    // Monitor de disponibilidade dos gateways (toast único e elegante)
    const gatewayFleetStatus = new Map();
    const lineForm = document.getElementById('wa-line-form');
    const lineReset = document.getElementById('wa-line-reset');
    const lineFeedback = document.getElementById('wa-line-feedback');
    const lineEditButtons = document.querySelectorAll('[data-line-edit]');
    const lineDeleteButtons = document.querySelectorAll('[data-line-delete]');
    const lineModeField = lineForm ? lineForm.querySelector('[data-line-mode]') : null;
    const linePickerField = lineForm ? lineForm.querySelector('[data-line-picker]') : null;
    const lineModeSections = lineForm ? Array.from(lineForm.querySelectorAll('[data-line-mode-section]')) : [];
    const lineModeVisibilityNodes = lineForm ? Array.from(lineForm.querySelectorAll('[data-line-mode-visibility]')) : [];
    const lineIdField = lineForm ? lineForm.querySelector('input[name="line_id"]') : null;
    const lineLabelField = lineForm ? lineForm.querySelector('input[name="label"]') : null;
    const lineDisplayPhoneField = lineForm ? lineForm.querySelector('input[name="display_phone"]') : null;
    const linePhoneNumberField = lineForm ? lineForm.querySelector('input[name="phone_number_id"]') : null;
    const lineProviderField = lineForm ? lineForm.querySelector('select[name="provider"]') : null;
    const lineBaseField = lineForm ? lineForm.querySelector('input[name="api_base_url"]') : null;
    const lineTemplateField = lineForm ? lineForm.querySelector('[data-line-template]') : null;
    const lineAltInline = lineForm ? lineForm.querySelector('[data-line-alt-inline]') : null;
    const lineAltSelect = lineForm ? lineForm.querySelector('[data-alt-gateway-select]') : null;
    const webLockField = lineForm ? lineForm.querySelector('[data-web-lock-field]') : null;
    const webLockHint = lineForm ? lineForm.querySelector('[data-web-lock-hint]') : null;
    const webLockInput = lineForm ? lineForm.querySelector('input[name="web_edit_code"]') : null;
    const lineAltDefault = lineAltSelect ? (lineAltSelect.getAttribute('data-default-alt') || '') : '';
    const templateBlocks = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-block]')) : [];
    const templateHints = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-hint]')) : [];
    const templateHideNodes = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-hide]')) : [];
    const templateShowNodes = lineForm ? Array.from(lineForm.querySelectorAll('[data-template-show]')) : [];
    const lineBusinessAccountField = lineForm ? lineForm.querySelector('input[name="business_account_id"]') : null;
    const lineAccessField = lineForm ? lineForm.querySelector('input[name="access_token"]') : null;
    const lineVerifyTokenField = lineForm ? lineForm.querySelector('input[name="verify_token"]') : null;
    const lineStatusField = lineForm ? lineForm.querySelector('select[name="status"]') : null;
    const lineDefaultField = lineForm ? lineForm.querySelector('input[name="is_default"]') : null;
    const lineLimitEnabledField = lineForm ? lineForm.querySelector('input[name="rate_limit_enabled"]') : null;
    const lineLimitWindowField = lineForm ? lineForm.querySelector('input[name="rate_limit_window_seconds"]') : null;
    const lineLimitMaxField = lineForm ? lineForm.querySelector('input[name="rate_limit_max_messages"]') : null;
    const rateLimitPresetField = lineForm ? lineForm.querySelector('[data-rate-limit-preset]') : null;
    const rateLimitHint = lineForm ? lineForm.querySelector('[data-rate-limit-hint]') : null;

    if (lineForm && !lineForm.dataset.webLockOrigin) {
        lineForm.dataset.webLockOrigin = '0';
    }
    const broadcastForm = document.getElementById('wa-broadcast-form');
    const broadcastFeedback = document.getElementById('wa-broadcast-feedback');
    const broadcastKindField = broadcastForm ? broadcastForm.querySelector('[data-broadcast-template-kind]') : null;
    const broadcastKeyField = broadcastForm ? broadcastForm.querySelector('[data-broadcast-template-key]') : null;
    const broadcastTemplatePreview = document.getElementById('wa-broadcast-template-preview');
    const broadcastTemplatePreviewLabel = broadcastTemplatePreview ? broadcastTemplatePreview.querySelector('[data-broadcast-template-label]') : null;
    const broadcastTemplatePreviewDescription = broadcastTemplatePreview ? broadcastTemplatePreview.querySelector('[data-broadcast-template-description]') : null;
    const broadcastTemplatePreviewImage = broadcastTemplatePreview ? broadcastTemplatePreview.querySelector('[data-broadcast-template-image]') : null;
    const broadcastTemplateClear = broadcastForm ? broadcastForm.querySelector('[data-broadcast-template-clear]') : null;
    const broadcastHistoryWrapper = document.getElementById('wa-broadcast-history');
    const broadcastRefreshButton = document.getElementById('wa-broadcast-refresh');
    let rateLimitPresets = {};
    if (rateLimitPresetField) {
        const parsedPresets = parseJsonText(rateLimitPresetField.getAttribute('data-presets') || '{}', {});
        rateLimitPresets = parsedPresets || {};
    }
    const queueForm = document.getElementById('wa-queue-form');
    const queueFeedback = document.getElementById('wa-queue-feedback');
    const queueSelect = queueForm ? queueForm.querySelector('[data-queue-select]') : null;
    const queueExtras = queueForm ? queueForm.querySelectorAll('[data-queue-extra]') : [];
    const queueQuickButtons = queueForm ? queueForm.querySelectorAll('[data-queue-quick]') : [];
    const moveQueueForm = document.getElementById('wa-move-queue-form');
    const moveQueueSelect = moveQueueForm ? moveQueueForm.querySelector('[data-move-queue]') : null;
    const moveQueueFeedback = moveQueueForm ? moveQueueForm.querySelector('[data-move-queue-feedback]') : null;
    const moveQueueSubmit = moveQueueForm ? moveQueueForm.querySelector('[data-move-queue-submit]') : null;
    const intakeSummaryField = document.getElementById('wa-intake-summary');
    const tagsForm = document.getElementById('wa-tags-form');
    const tagsFeedback = document.getElementById('wa-tags-feedback');
    const editContactButton = document.querySelector('[data-edit-contact-phone]');
    const blockContactButton = document.querySelector('[data-block-contact]');
    const blockedBadge = document.querySelector('[data-blocked-badge]');
    const blockedBanner = document.querySelector('[data-blocked-banner]');
    const contactPhoneDisplay = document.querySelector('[data-contact-phone-display]');
    const contactAvatar = document.querySelector('.wa-contact-avatar');
    const contactNameHeading = document.querySelector('.wa-contact-identity h3');
    const noteForm = document.getElementById('wa-internal-form');
    const noteFeedback = document.getElementById('wa-note-feedback');
    const messagesContainer = document.getElementById('wa-messages');
    const loadMoreButton = messagesContainer ? messagesContainer.querySelector('[data-load-more]') : null;
    let lastMessageId = messagesContainer ? Number(messagesContainer.getAttribute('data-last-message-id')) || 0 : 0;
    let firstMessageId = messagesContainer ? Number(messagesContainer.getAttribute('data-first-message-id')) || 0 : 0;
    let threadPollTimer = null;
    let isThreadPolling = false;
    let threadPollController = null;
    let threadPollPaused = false;
    let lazyMediaObserver = null;
    let initialMessageTrimmed = false;
    let isLoadingOlderMessages = false;
    let lastUserActivity = Date.now();
    let activityReschedulePending = false;
    const preTriageButton = document.getElementById('wa-pretriage');
    const gatewayToggleButton = document.querySelector('[data-alt-gateway-toggle]');
    const gatewayContainer = document.querySelector('[data-alt-gateway-container]');
    const gatewayCards = Array.from(document.querySelectorAll('[data-alt-gateway-card]'))
        .map((card) => {
            const slug = card.getAttribute('data-gateway-instance');
            if (!slug) {
                return null;
            }
            const labelNode = card.querySelector('h4');
            const qrPlaceholder = card.querySelector('[data-alt-gateway-qr-placeholder]');
            return {
                kind: 'card',
                slug,
                label: labelNode ? (labelNode.textContent || slug) : slug,
                isOnline: false,
                card,
                elements: {
                    status: card.querySelector('[data-alt-gateway-status]'),
                    refresh: card.querySelector('[data-alt-gateway-refresh]'),
                    qrButton: card.querySelector('[data-alt-gateway-qr]'),
                    qrOpen: card.querySelector('[data-alt-gateway-qr-popup]'),
                    clean: card.querySelector('[data-alt-gateway-clean]') || card.querySelector('[data-alt-gateway-reset]'),
                    start: card.querySelector('[data-alt-gateway-start]'),
                    feedback: card.querySelector('[data-alt-gateway-feedback]'),
                    qrPanel: card.querySelector('[data-alt-gateway-qr-panel]'),
                    qrPlaceholder,
                    qrImage: card.querySelector('[data-alt-gateway-qr-image]'),
                    session: card.querySelector('[data-alt-gateway-session]'),
                    incoming: card.querySelector('[data-alt-gateway-incoming]'),
                    heartbeat: card.querySelector('[data-alt-gateway-heartbeat]'),
                    history: card.querySelector('[data-alt-gateway-history]'),
                    qrMeta: card.querySelector('[data-alt-gateway-qr-meta]'),
                    historyForm: card.querySelector('[data-alt-history-form]'),
                    historyFeedback: card.querySelector('[data-alt-history-feedback]'),
                },
                qrVisible: false,
                qrTimer: null,
                lastQrData: '',
                qrResetAttempted: false,
                qrAutoResetCount: 0,
                lastResetAt: 0,
                qrPlaceholderDefault: qrPlaceholder ? qrPlaceholder.textContent : '',
            };
        })
        .filter(Boolean);
    let blockedNumbersList = <?= json_encode($options['blocked_numbers'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let blockedNumbersSet = new Set((blockedNumbersList || []).map((entry) => String(entry)));
        const contactProfileToggle = document.getElementById('wa-contact-profile-toggle');
        const contactProfileCard = document.getElementById('wa-contact-profile-card');
    let activeContactDigits = normalizePhone(activeThreadContext.phone || '');
    const lineGatewayStates = Array.from(document.querySelectorAll('[data-line-gateway]'))
        .map((wrapper) => {
            const slug = wrapper.getAttribute('data-gateway-instance');
            if (!slug) {
                return null;
            }
            return {
                kind: 'line',
                slug,
                label: wrapper.getAttribute('data-line-label') || slug,
                isOnline: false,
                wrapper,
                label: wrapper.getAttribute('data-line-label') || '',
                phone: wrapper.getAttribute('data-line-phone') || '',
                elements: {
                    status: wrapper.querySelector('[data-line-gateway-status]'),
                    start: wrapper.querySelector('[data-line-gateway-start]'),
                    stop: wrapper.querySelector('[data-line-gateway-stop]'),
                    qrButton: wrapper.querySelector('[data-line-gateway-qr]'),
                    qrHistoryButton: wrapper.querySelector('[data-line-gateway-qr-history]'),
                },
            };
        })
        .filter(Boolean);
    const fullHistoryRequests = Object.create(null);
    let gatewayPanelVisible = gatewayContainer ? !gatewayContainer.hasAttribute('hidden') : false;
    let recordedAudioFallback = null;
    let audioRecorder = null;
    let templateSelection = null;
    let audioChunks = [];
    let audioStream = null;
    let audioTimer = null;
    let audioSeconds = 0;
    let audioMimeType = 'audio/ogg;codecs=opus';
    let audioExtension = 'ogg';
    let audioDiscardOnStop = false;
    const gatewayEndpoints = {
        status: '<?= url('whatsapp/alt/gateway-status'); ?>',
        qr: '<?= url('whatsapp/alt/qr'); ?>',
        reset: '<?= url('whatsapp/alt/gateway-reset'); ?>',
        start: '<?= url('whatsapp/alt/gateway-start'); ?>',
        stop: '<?= url('whatsapp/alt/gateway-stop'); ?>',
        history: '<?= url('whatsapp/alt/history-sync'); ?>',
    };
    function getFullHistoryRequest(slug) {
        if (!slug) {
            return null;
        }
        return fullHistoryRequests[slug] || null;
    }

    function setFullHistoryRequest(slug, patch, replace = false) {
        if (!slug) {
            return null;
        }
        if (replace || !fullHistoryRequests[slug]) {
            fullHistoryRequests[slug] = {};
        }
        if (patch) {
            Object.assign(fullHistoryRequests[slug], patch);
        }
        return fullHistoryRequests[slug];
    }

    function clearFullHistoryRequest(slug) {
        if (!slug) {
            return;
        }
        delete fullHistoryRequests[slug];
    }

    function describeFullHistoryStatus(request, fallbackStatus, isReady) {
        if (!request) {
            return fallbackStatus;
        }
        switch (request.status) {
            case 'resetting':
                return 'Reiniciando sessão para importação completa...';
            case 'awaiting_ready':
                return isReady
                    ? 'Preparando importação completa...'
                    : 'Escaneie o QR histórico e mantenha o aplicativo aberto.';
            case 'importing':
                return request.progress || 'Importando histórico completo...';
            case 'completed':
                return request.summary || 'Histórico importado com sucesso.';
            case 'failed':
                return request.error || 'Falha ao importar histórico. Gere o QR histórico novamente.';
            default:
                return fallbackStatus;
        }
    }

    async function startFullHistoryImport(state) {
        const request = getFullHistoryRequest(state.slug);
        if (!request || request.running) {
            return;
        }
        request.status = 'importing';
        request.running = true;
        request.progress = 'Importando histórico completo...';
        if (state.elements.status) {
            state.elements.status.textContent = request.progress;
        }
        try {
            const data = await postGatewayAction(state, gatewayEndpoints.history);
            const stats = data.stats || {};
            const summary = `Importação concluída · Mensagens: ${stats.messages_forwarded || 0} | Conversas: ${stats.chats_with_messages || 0}`;
            request.summary = summary;
            request.status = 'completed';
            if (state.elements.status) {
                state.elements.status.textContent = summary;
            }
            if (request.button) {
                request.button.disabled = false;
            }
            setTimeout(() => {
                clearFullHistoryRequest(state.slug);
                refreshGatewayStatus(state);
            }, 20000);
        } catch (error) {
            request.error = error.message || 'Falha ao importar histórico. Gere o QR histórico novamente.';
            request.status = 'failed';
            if (state.elements.status) {
                state.elements.status.textContent = request.error;
            }
            if (request.button) {
                request.button.disabled = false;
            }
        } finally {
            request.running = false;
        }
    }
    const sandboxForm = document.getElementById('wa-sandbox-form');
    const sandboxFeedback = document.getElementById('wa-sandbox-feedback');
    const manualForm = document.getElementById('wa-manual-form');
    const manualFeedback = document.getElementById('wa-manual-feedback');
    const manualDeleteButtons = document.querySelectorAll('[data-manual-delete]');
    const contextPane = document.querySelector('.wa-pane--context');
    const contextToggle = document.querySelector('[data-context-toggle]');
    const contextClose = document.querySelector('[data-context-close]');
    const contextOverlay = document.querySelector('[data-context-overlay]');
    const contextDetails = document.querySelector('[data-context-details]');
    const contextDetailsToggle = document.querySelector('[data-context-details-toggle]');
    let contextDetailsVisible = contextDetails ? !contextDetails.hasAttribute('hidden') : false;
    const tabLinks = document.querySelectorAll('.wa-tab[data-tab-target]');
    const panelSections = document.querySelectorAll('.wa-panel[data-panel]');
    const copilotProfileSelect = document.getElementById('wa-copilot-profile');
    const clientPopover = document.querySelector('[data-client-popover]');
    const clientNameField = clientPopover ? clientPopover.querySelector('[data-client-name]') : null;
    const clientPhoneField = clientPopover ? clientPopover.querySelector('[data-client-phone]') : null;
    const clientLinkField = clientPopover ? clientPopover.querySelector('[data-client-link]') : null;
    const clientDocumentRow = clientPopover ? clientPopover.querySelector('[data-client-document-row]') : null;
    const clientDocumentField = clientPopover ? clientPopover.querySelector('[data-client-document]') : null;
    const clientStatusRow = clientPopover ? clientPopover.querySelector('[data-client-status-row]') : null;
    const clientStatusField = clientPopover ? clientPopover.querySelector('[data-client-status]') : null;
    const clientPartnerRow = clientPopover ? clientPopover.querySelector('[data-client-partner-row]') : null;
    const clientPartnerField = clientPopover ? clientPopover.querySelector('[data-client-partner]') : null;
    const notificationState = {
        soundEnabled: readBooleanPreference(NOTIFY_SOUND_KEY, true),
        popupEnabled: readBooleanPreference(NOTIFY_POPUP_KEY, true),
        cooldownMinutes: readNumberPreference(NOTIFY_COOLDOWN_KEY, 2),
        soundStyle: readStringPreference(NOTIFY_SOUND_STYLE_KEY, 'voice'),
        customSoundData: readStringPreference(NOTIFY_SOUND_CUSTOM_DATA_KEY, ''),
        customSoundName: readStringPreference(NOTIFY_SOUND_CUSTOM_NAME_KEY, ''),
        audioCtx: null,
    };
    let activeCustomAudio = null;
    const threadSnapshots = new Map();
    const prefetchedThreads = new Map();
    const threadMessageCache = new Map();
    const MESSAGE_CACHE_PREFIX = 'wa_thread_cache_v1';
    const MESSAGE_CACHE_INDEX_KEY = `${MESSAGE_CACHE_PREFIX}_index`;
    const MESSAGE_CACHE_LIMIT = 20;
    const MESSAGE_CACHE_TTL_MS = 5 * 60 * 1000;
    const MESSAGE_CACHE_MAX_MESSAGES = 120;
    const PANEL_CACHE_PREFIX = 'wa_panel_cache_v5';
    const PANEL_CACHE_TTL_MS = 60 * 1000;
    let panelCacheHydrated = false;
    const toastTimers = new Map();
    const lastAgentResponseAt = Object.create(null);
    let notificationsPrimed = false;
    if (threadId > 0) {
        markAgentResponse(threadId);
    }
    const clientCloseButtons = document.querySelectorAll('[data-client-popover-close]');
    let clientBodyOverflow = '';
    let newThreadBodyOverflow = '';
    const newThreadToggle = document.querySelectorAll('[data-new-thread-toggle]');
    const newThreadModal = document.querySelector('[data-new-thread-modal]');
    const newThreadCloseButtons = document.querySelectorAll('[data-new-thread-close]');
    const newThreadForm = document.querySelector('[data-new-thread-form]');
    const newThreadFeedback = document.querySelector('[data-new-thread-feedback]');
    let newThreadModalVisible = false;
    const registerContactModal = document.querySelector('[data-register-contact-modal]');
    const registerContactCloseButtons = registerContactModal ? registerContactModal.querySelectorAll('[data-register-contact-close]') : [];
    const registerContactForm = registerContactModal ? registerContactModal.querySelector('[data-register-contact-form]') : null;
    const registerContactFeedback = registerContactModal ? registerContactModal.querySelector('[data-register-contact-feedback]') : null;
    const registerContactThreadId = registerContactForm ? registerContactForm.querySelector('input[name="thread_id"]') : null;
    const registerContactContactId = registerContactForm ? registerContactForm.querySelector('input[name="contact_id"]') : null;
    const registerContactName = registerContactForm ? registerContactForm.querySelector('input[name="contact_name"]') : null;
    const registerContactPhone = registerContactForm ? registerContactForm.querySelector('input[name="contact_phone"]') : null;
    const registerContactCpf = registerContactForm ? registerContactForm.querySelector('input[name="contact_cpf"]') : null;
    const registerContactBirthdate = registerContactForm ? registerContactForm.querySelector('input[name="contact_birthdate"]') : null;
    const registerContactEmail = registerContactForm ? registerContactForm.querySelector('input[name="contact_email"]') : null;
    const registerContactAddress = registerContactForm ? registerContactForm.querySelector('textarea[name="contact_address"]') : null;
    const registerContactClientId = registerContactForm ? registerContactForm.querySelector('input[name="client_id"]') : null;
    const registerClientSearchInput = registerContactModal ? registerContactModal.querySelector('[data-register-client-search]') : null;
    const registerClientSearchButton = registerContactModal ? registerContactModal.querySelector('[data-register-client-search-btn]') : null;
    const registerClientSearchResults = registerContactModal ? registerContactModal.querySelector('[data-register-client-search-results]') : null;
    const registerClientSearchFeedback = registerContactModal ? registerContactModal.querySelector('[data-register-client-search-feedback]') : null;
    let registerContactBodyOverflow = '';
    let registerContactTrigger = null;
    const threadSearchInput = document.querySelector('[data-thread-search-input]');
    const threadSearchClear = document.querySelector('[data-thread-search-clear]');
    const threadSearchApply = document.querySelector('[data-thread-search-apply]');
    const threadSearchFeedback = document.querySelector('[data-thread-search-feedback]');
    let threadSearchRefreshTimer = null;
    const lineQrModal = document.querySelector('[data-line-qr-modal]');
    const lineQrModeLabel = lineQrModal ? lineQrModal.querySelector('[data-line-qr-mode]') : null;
    const lineQrTitle = lineQrModal ? lineQrModal.querySelector('[data-line-qr-title]') : null;
    const lineQrDescription = lineQrModal ? lineQrModal.querySelector('[data-line-qr-description]') : null;
    const lineQrHelper = lineQrModal ? lineQrModal.querySelector('[data-line-qr-helper]') : null;
    const lineQrPlaceholder = lineQrModal ? lineQrModal.querySelector('[data-line-qr-placeholder]') : null;
    const lineQrImage = lineQrModal ? lineQrModal.querySelector('[data-line-qr-image]') : null;
    const lineQrMeta = lineQrModal ? lineQrModal.querySelector('[data-line-qr-meta]') : null;
    const lineQrRefreshButton = lineQrModal ? lineQrModal.querySelector('[data-line-qr-refresh]') : null;
    const lineQrCloseButtons = lineQrModal ? lineQrModal.querySelectorAll('[data-line-qr-close]') : [];
    let lineQrBodyOverflow = '';
    const lineQrModalState = {
        visible: false,
        slug: null,
        mode: 'standard',
        timer: null,
    };

    if (copilotProfileSelect) {
        const profileStorageKey = 'wa_copilot_profile';
        const savedProfile = localStorage.getItem(profileStorageKey);
        const defaultProfile = copilotProfileSelect.getAttribute('data-default-profile');
        if (savedProfile && copilotProfileSelect.querySelector(`option[value="${savedProfile}"]`)) {
            copilotProfileSelect.value = savedProfile;
        } else if (defaultProfile && copilotProfileSelect.querySelector(`option[value="${defaultProfile}"]`)) {
            copilotProfileSelect.value = defaultProfile;
        }
        copilotProfileSelect.addEventListener('change', () => {
            localStorage.setItem(profileStorageKey, copilotProfileSelect.value || '');
        });
    }

    function toParams(payload) {
        const body = new URLSearchParams();
        Object.entries(payload || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((entry) => body.append(key, entry));
            } else if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        return body;
    }

    function readSessionItem(key) {
        try {
            return sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeSessionItem(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch (error) {
            // ignore storage quota failures
        }
    }

    function removeSessionItem(key) {
        try {
            sessionStorage.removeItem(key);
        } catch (error) {
            // ignore
        }
    }

    function loadCacheIndex() {
        const raw = readSessionItem(MESSAGE_CACHE_INDEX_KEY);
        if (!raw) {
            return [];
        }
        const parsed = parseJsonText(raw, []);
        return Array.isArray(parsed) ? parsed : [];
    }

    function persistCacheIndex(entries) {
        writeSessionItem(MESSAGE_CACHE_INDEX_KEY, JSON.stringify(entries || []));
    }

    function trimCacheIndex(entries) {
        const now = Date.now();
        const filtered = (entries || []).filter((item) => item && item.id && (now - Number(item.fetchedAt || 0)) < MESSAGE_CACHE_TTL_MS);
        filtered.sort((a, b) => Number(b.fetchedAt || 0) - Number(a.fetchedAt || 0));
        return filtered.slice(0, MESSAGE_CACHE_LIMIT);
    }

    function getThreadCache(targetThreadId) {
        if (!targetThreadId) {
            return null;
        }
        const cached = threadMessageCache.get(targetThreadId);
        if (cached && (Date.now() - cached.fetchedAt) < MESSAGE_CACHE_TTL_MS) {
            return cached;
        }
        const storageKey = `${MESSAGE_CACHE_PREFIX}_${targetThreadId}`;
        const raw = readSessionItem(storageKey);
        if (!raw) {
            return null;
        }
        const parsed = parseJsonText(raw, null);
        if (!parsed || typeof parsed !== 'object') {
            removeSessionItem(storageKey);
            return null;
        }
        const fetchedAt = Number(parsed.fetchedAt || 0);
        if ((Date.now() - fetchedAt) >= MESSAGE_CACHE_TTL_MS) {
            removeSessionItem(storageKey);
            return null;
        }
        const normalized = {
            messages: Array.isArray(parsed.messages) ? parsed.messages : [],
            lastMessageId: Number(parsed.lastMessageId || 0),
            fetchedAt,
        };
        threadMessageCache.set(targetThreadId, normalized);
        return normalized;
    }

    function buildPanelCacheKey() {
        const channel = selectedChannel || 'default';
        return `${PANEL_CACHE_PREFIX}_${channel}`;
    }

    function loadPanelCache() {
        const raw = readSessionItem(buildPanelCacheKey());
        if (!raw) {
            return null;
        }
        const parsed = parseJsonText(raw, null);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        const ts = Number(parsed.ts || 0);
        if (!ts || (Date.now() - ts) > PANEL_CACHE_TTL_MS) {
            return null;
        }
        return parsed.panels || null;
    }

    function persistPanelCache(panels) {
        if (!panels || typeof panels !== 'object') {
            return;
        }
        const payload = {
            ts: Date.now(),
            panels,
        };
        writeSessionItem(buildPanelCacheKey(), JSON.stringify(payload));
    }

    function persistThreadCache(targetThreadId, entry) {
        if (!targetThreadId || !entry) {
            return;
        }
        const snapshot = {
            messages: Array.isArray(entry.messages) ? entry.messages.slice(-MESSAGE_CACHE_MAX_MESSAGES) : [],
            lastMessageId: Number(entry.lastMessageId || 0),
            fetchedAt: Number(entry.fetchedAt || Date.now()),
        };
        threadMessageCache.set(targetThreadId, snapshot);
        const storageKey = `${MESSAGE_CACHE_PREFIX}_${targetThreadId}`;
        writeSessionItem(storageKey, JSON.stringify(snapshot));

        const trimmed = trimCacheIndex(loadCacheIndex().filter((item) => Number(item.id) !== Number(targetThreadId)));
        trimmed.unshift({ id: targetThreadId, fetchedAt: snapshot.fetchedAt });
        const finalIndex = trimCacheIndex(trimmed);
        persistCacheIndex(finalIndex);

        const keepIds = new Set(finalIndex.map((item) => Number(item.id)));
        threadMessageCache.forEach((_, key) => {
            if (!keepIds.has(Number(key))) {
                threadMessageCache.delete(key);
            }
        });
    }

    function updateThreadCacheFromMessages(targetThreadId, newMessages, newLastMessageId) {
        if (!targetThreadId) {
            return;
        }
        const existing = getThreadCache(targetThreadId);
        const combined = [];
        if (existing && Array.isArray(existing.messages)) {
            combined.push(...existing.messages);
        }
        if (Array.isArray(newMessages) && newMessages.length) {
            combined.push(...newMessages);
        }
        const lastId = Math.max(Number(newLastMessageId || 0), existing ? Number(existing.lastMessageId || 0) : 0);
        const pruned = combined.slice(-MESSAGE_CACHE_MAX_MESSAGES);
        persistThreadCache(targetThreadId, {
            messages: pruned,
            lastMessageId: lastId,
            fetchedAt: Date.now(),
        });
    }

    function hydrateThreadFromCache() {
        if (!threadId || !messagesContainer) {
            return;
        }
        const cached = getThreadCache(threadId);
        if (!cached) {
            return;
        }
        const cachedLastId = Number(cached.lastMessageId || 0);
        if (cachedLastId > (lastMessageId || 0)) {
            lastMessageId = cachedLastId;
            messagesContainer.setAttribute('data-last-message-id', String(lastMessageId));
        }
        if (!messagesContainer.childElementCount && Array.isArray(cached.messages) && cached.messages.length) {
            cached.messages.forEach((message) => appendMessageBubble(message));
            scrollMessagesToBottom();
        }
    }

    function normalizeSearchTerm(value) {
        return (value || '')
            .toString()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function normalizePhone(phone) {
        return (phone || '').replace(/\D+/g, '');
    }

    function formatPhone(phone) {
        if (!phone) {
            return '';
        }
        const digits = normalizePhone(phone);
        if (digits.length === 13 && digits.startsWith('55')) {
            return `+${digits.substr(0, 2)} (${digits.substr(2, 2)}) ${digits.substr(4, 5)}-${digits.substr(9)}`;
        }
        if (digits.length === 12 && digits.startsWith('55')) {
            return `+${digits.substr(0, 2)} (${digits.substr(2, 2)}) ${digits.substr(4, 4)}-${digits.substr(8)}`;
        }
        if (digits.length === 11) {
            return `(${digits.substr(0, 2)}) ${digits.substr(2, 5)}-${digits.substr(7)}`;
        }
        if (digits.length === 10) {
            return `(${digits.substr(0, 2)}) ${digits.substr(2, 4)}-${digits.substr(6)}`;
        }
        return phone;
    }

    function deriveInitials(name) {
        const safe = (name || '').trim();
        if (safe === '') {
            return 'C';
        }
        const parts = safe.split(/\s+/).filter(Boolean);
        const initials = parts.slice(0, 2).map((part) => part.charAt(0).toUpperCase());
        const joined = initials.join('').slice(0, 2);
        return joined !== '' ? joined : 'C';
    }

    function resolveContactPhotoUrl(contact, metadata) {
        const safeMeta = metadata || {};
        const snapshot = (safeMeta.gateway_snapshot && typeof safeMeta.gateway_snapshot === 'object')
            ? safeMeta.gateway_snapshot
            : {};
        const candidates = [
            safeMeta.profile_photo,
            snapshot.profile_photo,
            contact && contact.profile_photo,
        ].map((value) => (typeof value === 'string' ? value.trim() : ''));

        for (const candidate of candidates) {
            if (candidate && (/^https?:\/\//i.test(candidate) || candidate.startsWith('data:') || candidate.startsWith('blob:'))) {
                return candidate;
            }
        }

        return '';
    }

    function renderContactProfileCard(contact, metadata) {
        if (!contactProfileCard) {
            return;
        }
        const safeMetadata = normalizeMetadata(metadata);
        const gatewaySnapshot = (safeMetadata.gateway_snapshot && typeof safeMetadata.gateway_snapshot === 'object')
            ? safeMetadata.gateway_snapshot
            : {};
        const photoUrl = resolveContactPhotoUrl(contact, safeMetadata);
        const name = (safeMetadata.profile && String(safeMetadata.profile).trim())
            || contact.name
            || activeThreadContext.name
            || 'Contato WhatsApp';
        const rawPhone = contact.phone || activeThreadContext.phone || '';
        const phoneDisplay = rawPhone ? formatPhone(rawPhone) : '';
        const rawFrom = (safeMetadata.raw_from || '').toString();
        const normalizedFromDigits = normalizePhone(safeMetadata.normalized_from || '');
        const phoneDigits = normalizePhone(rawPhone);
        const normalizedFromDisplay = normalizedFromDigits ? formatPhone(normalizedFromDigits) : '';
        const gatewaySource = (gatewaySnapshot.source || '').toString();
        const capturedAtRaw = Number(gatewaySnapshot.captured_at || 0);
        const capturedAtMs = capturedAtRaw > 0 && capturedAtRaw < 2000000000 ? capturedAtRaw * 1000 : capturedAtRaw;
        const capturedLabel = capturedAtMs > 0 ? formatMessageTimestamp(capturedAtMs) : '';
        const email = (safeMetadata.email || '').toString();
        const cpf = (safeMetadata.cpf || '').toString();
        const tagsRaw = Array.isArray(safeMetadata.tags)
            ? safeMetadata.tags
            : (typeof safeMetadata.tags === 'string' ? safeMetadata.tags.split(',') : []);
        const tags = tagsRaw.map((tag) => (tag || '').toString().trim()).filter(Boolean);
        const metaMessageId = (gatewaySnapshot.meta_message_id || '').toString();
        const presence = (gatewaySnapshot.presence || '').toString();
        const about = (gatewaySnapshot.about || '').toString();
        const businessName = (gatewaySnapshot.business_name || gatewaySnapshot.verified_name || '').toString();
        const businessCategory = (gatewaySnapshot.business_category || '').toString();
        const verifiedLevel = (gatewaySnapshot.verified_level || '').toString();
        const lastSeenRaw = Number(gatewaySnapshot.last_seen_at || 0);
        const lastSeenMs = lastSeenRaw > 0 && lastSeenRaw < 2000000000 ? lastSeenRaw * 1000 : lastSeenRaw;
        const lastSeenLabel = lastSeenMs > 0 ? formatMessageTimestamp(lastSeenMs) : '';

        const avatarHtml = photoUrl
            ? `<img src="${escapeHtml(photoUrl)}" alt="Foto do contato" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:1px solid rgba(148,163,184,0.25);" />`
            : `<div style="width:54px;height:54px;border-radius:50%;background:rgba(148,163,184,0.2);display:flex;align-items:center;justify-content:center;color:#e2e8f0;font-weight:700;font-size:1.1rem;">${escapeHtml(deriveInitials(name).slice(0, 1))}</div>`;

        const identityParts = [
            phoneDisplay ? `<span style="color:var(--muted);font-size:0.92rem;">Telefone: ${escapeHtml(phoneDisplay)}</span>` : '',
            rawFrom ? `<span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Origem: ${escapeHtml(rawFrom)}</span>` : '',
            normalizedFromDisplay && normalizedFromDigits !== phoneDigits
                ? `<span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Normalizado: ${escapeHtml(normalizedFromDisplay)}</span>`
                : '',
            gatewaySource ? `<span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Gateway: ${escapeHtml(gatewaySource)}</span>` : '',
            capturedLabel ? `<span style="color:rgba(148,163,184,0.8);font-size:0.86rem;">Capturado: ${escapeHtml(capturedLabel)}</span>` : '',
        ].filter(Boolean);

        const detailBlocks = [];
        if (email) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">E-mail</strong><div>${escapeHtml(email)}</div></div>`);
        }
        if (cpf) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Documento</strong><div>${escapeHtml(cpf)}</div></div>`);
        }
        if (tags.length) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Tags</strong><div>${escapeHtml(tags.join(', '))}</div></div>`);
        }
        if (metaMessageId) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Ult. meta_message_id</strong><div>${escapeHtml(metaMessageId)}</div></div>`);
        }
        if (presence) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Presença</strong><div>${escapeHtml(presence)}</div></div>`);
        }
        if (about) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Sobre</strong><div>${escapeHtml(about)}</div></div>`);
        }
        if (businessName) {
            const suffix = verifiedLevel ? ` (${escapeHtml(verifiedLevel)})` : '';
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Business</strong><div>${escapeHtml(businessName)}${suffix}</div></div>`);
        }
        if (businessCategory) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Categoria</strong><div>${escapeHtml(businessCategory)}</div></div>`);
        }
        if (lastSeenLabel) {
            detailBlocks.push(`<div><strong style="color:var(--muted);font-size:0.82rem;">Visto por último</strong><div>${escapeHtml(lastSeenLabel)}</div></div>`);
        }

        const detailsGrid = detailBlocks.length
            ? `<div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">${detailBlocks.join('')}</div>`
            : '';

        contactProfileCard.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;">
                ${avatarHtml}
                <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
                    <strong style="color:var(--text);font-size:1rem;">${escapeHtml(name)}</strong>
                    ${identityParts.join('')}
                </div>
            </div>
            ${detailsGrid}
        `;
    }

    function applyContactPayload(contact) {
        if (!contact || typeof contact !== 'object') {
            return;
        }
        const metadata = normalizeMetadata(contact.metadata);
        const name = (metadata.profile && String(metadata.profile).trim())
            || contact.name
            || activeThreadContext.name
            || 'Contato WhatsApp';
        const rawPhone = contact.phone || activeThreadContext.phone || '';
        const phoneDisplay = rawPhone ? formatPhone(rawPhone) : '';
        const photoUrl = resolveContactPhotoUrl(contact, metadata);

        activeThreadContext.name = name;
        activeThreadContext.phone = rawPhone || activeThreadContext.phone || '';
        activeContactDigits = normalizePhone(activeThreadContext.phone || '');

        if (contactNameHeading) {
            contactNameHeading.textContent = name;
        }

        const phoneRow = document.querySelector('.wa-contact-identity .wa-contact-row');
        let phoneNode = contactPhoneDisplay || document.querySelector('.wa-contact-identity [data-contact-phone-display]');
        if (!phoneNode && phoneRow) {
            phoneNode = document.createElement('span');
            phoneNode.className = 'wa-contact-phone';
            phoneNode.dataset.contactPhoneDisplay = '';
            phoneRow.appendChild(phoneNode);
        }
        if (phoneNode) {
            phoneNode.textContent = phoneDisplay;
            phoneNode.hidden = phoneDisplay === '';
        }

        if (editContactButton) {
            editContactButton.dataset.contactName = name;
            editContactButton.dataset.contactPhone = rawPhone || phoneDisplay;
        }

        if (contactAvatar) {
            const initials = deriveInitials(name);
            let img = contactAvatar.querySelector('img');
            let initialsNode = contactAvatar.querySelector('span');
            if (photoUrl) {
                if (!img) {
                    img = document.createElement('img');
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    contactAvatar.prepend(img);
                }
                img.src = photoUrl;
                img.alt = 'Foto do contato';
                contactAvatar.classList.add('has-photo');
                if (initialsNode) {
                    initialsNode.textContent = initials;
                    initialsNode.style.display = 'none';
                }
            } else {
                if (img) {
                    img.remove();
                }
                if (!initialsNode) {
                    initialsNode = document.createElement('span');
                    contactAvatar.appendChild(initialsNode);
                }
                initialsNode.textContent = initials;
                initialsNode.style.display = '';
                contactAvatar.classList.remove('has-photo');
            }
        }

        if (contactProfileToggle) {
            contactProfileToggle.dataset.contactProfileName = name;
            contactProfileToggle.dataset.contactPhone = phoneDisplay || rawPhone;
            contactProfileToggle.dataset.contactPhoto = photoUrl || '';
        }

        renderContactProfileCard({ name, phone: rawPhone, profile_photo: photoUrl }, metadata);
        setBlockedState(activeContactDigits !== '' && blockedNumbersSet.has(activeContactDigits));
    }

    function setBlockedState(isBlocked, updatedList = null) {
        if (Array.isArray(updatedList)) {
            blockedNumbersList = updatedList.map((entry) => String(entry));
            blockedNumbersSet = new Set(blockedNumbersList);
        }
        if (blockContactButton) {
            blockContactButton.dataset.blocked = isBlocked ? '1' : '0';
            blockContactButton.textContent = isBlocked ? 'Desbloquear contato' : 'Bloquear contato';
            blockContactButton.disabled = false;
        }
        if (blockedBadge) {
            blockedBadge.hidden = !isBlocked;
        }
        if (blockedBanner) {
            blockedBanner.hidden = !isBlocked;
        }
    }

        if (contactProfileToggle && contactProfileCard) {
            contactProfileToggle.addEventListener('click', () => {
                const isOpen = contactProfileCard.style.display !== 'none';
                contactProfileCard.style.display = isOpen ? 'none' : 'block';
                contactProfileToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        }

    function describeLineGateway(state) {
        const label = (state && state.label) ? state.label : 'Linha';
        const phone = (state && state.phone) ? state.phone : '';
        return {
            label,
            phone,
            display: phone ? `${label} · ${phone}` : label,
        };
    }

    function buildLineQrModalCopy(state, mode = 'standard') {
        const context = describeLineGateway(state);
        const isHistory = mode === 'history';
        const baseTitle = isHistory ? 'QR histórico' : 'Novo QR';
        const helper = isHistory
            ? 'Escaneie com o celular desta linha para importar todas as mensagens. Deixe o WhatsApp aberto até concluir.'
            : 'Escaneie com o celular desta linha para reconectar o WhatsApp Web imediatamente.';
        return {
            title: `${baseTitle} — ${context.label}`,
            description: context.display,
            helper,
            modeLabel: isHistory ? 'Importar histórico' : 'Reconexão rápida',
        };
    }

    function setLineQrPlaceholder(text, slugCheck = null) {
        if (!lineQrModal || !lineQrModalState.visible) {
            return;
        }
        if (slugCheck && lineQrModalState.slug !== slugCheck) {
            return;
        }
        if (lineQrPlaceholder) {
            lineQrPlaceholder.hidden = false;
            lineQrPlaceholder.textContent = text || '';
        }
        if (lineQrImage) {
            lineQrImage.hidden = true;
            lineQrImage.removeAttribute('src');
        }
    }

    function setLineQrMeta(text, slugCheck = null) {
        if (!lineQrModal || !lineQrModalState.visible) {
            return;
        }
        if (slugCheck && lineQrModalState.slug !== slugCheck) {
            return;
        }
        if (lineQrMeta) {
            lineQrMeta.textContent = text || '';
        }
    }

    function openLineQrModal(slug, options = {}) {
        if (!lineQrModal) {
            return;
        }
        lineQrModalState.slug = slug;
        lineQrModalState.mode = options.mode || 'standard';
        if (!lineQrBodyOverflow) {
            lineQrBodyOverflow = document.body.style.overflow || '';
        }
        document.body.style.overflow = 'hidden';
        lineQrModal.removeAttribute('hidden');
        lineQrModalState.visible = true;
        if (lineQrModeLabel) {
            lineQrModeLabel.textContent = options.modeLabel || (lineQrModalState.mode === 'history' ? 'Importar histórico' : 'Reconexão rápida');
        }
        if (lineQrTitle) {
            lineQrTitle.textContent = options.title || 'QR do gateway';
        }
        if (lineQrDescription) {
            lineQrDescription.textContent = options.description || '';
        }
        if (lineQrHelper) {
            const helperText = options.helper || '';
            lineQrHelper.textContent = helperText;
            lineQrHelper.hidden = helperText === '';
        }
        setLineQrPlaceholder(options.statusText || 'Gerando QR...', slug);
        setLineQrMeta('', slug);
        stopLineQrModalLoop();
        if (options.autoFetch === false) {
            return;
        }
        refreshLineQrModalQr(true);
    }

    function closeLineQrModal() {
        if (!lineQrModal || !lineQrModalState.visible) {
            return;
        }
        stopLineQrModalLoop();
        lineQrModalState.visible = false;
        lineQrModalState.slug = null;
        lineQrModal.setAttribute('hidden', 'hidden');
        if (lineQrPlaceholder) {
            lineQrPlaceholder.hidden = false;
            lineQrPlaceholder.textContent = '';
        }
        if (lineQrImage) {
            lineQrImage.hidden = true;
            lineQrImage.removeAttribute('src');
        }
        if (lineQrMeta) {
            lineQrMeta.textContent = '';
        }
        document.body.style.overflow = lineQrBodyOverflow || '';
        lineQrBodyOverflow = '';
    }

    function stopLineQrModalLoop() {
        if (lineQrModalState.timer) {
            clearInterval(lineQrModalState.timer);
            lineQrModalState.timer = null;
        }
    }

    function refreshLineQrModalQr(immediate = false) {
        if (!lineQrModalState.visible || !lineQrModalState.slug) {
            return;
        }
        stopLineQrModalLoop();
        if (immediate) {
            fetchLineQrImage(lineQrModalState.slug, { silent: false });
        }
        lineQrModalState.timer = setInterval(() => {
            if (!lineQrModalState.visible || !lineQrModalState.slug) {
                stopLineQrModalLoop();
                return;
            }
            fetchLineQrImage(lineQrModalState.slug, { silent: true });
        }, 20000);
    }

    async function fetchLineQrImage(slug, options = {}) {
        if (!lineQrModal || !lineQrModalState.visible || lineQrModalState.slug !== slug) {
            return;
        }
        const silent = !!options.silent;
        if (!silent) {
            setLineQrPlaceholder('Carregando QR...', slug);
            setLineQrMeta('', slug);
        }
        try {
            const response = await fetch(buildGatewayUrl(gatewayEndpoints.qr, slug));
            if (lineQrModalState.slug !== slug) {
                return;
            }
            if (response.status === 204) {
                setLineQrPlaceholder('Sessão conectada (QR não necessário).', slug);
                setLineQrMeta('', slug);
                return;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.error || 'QR não disponível');
            }
            if (data.qr && lineQrImage) {
                lineQrImage.src = data.qr;
                lineQrImage.alt = 'QR Code WhatsApp Web';
                lineQrImage.hidden = false;
                if (lineQrPlaceholder) {
                    lineQrPlaceholder.hidden = true;
                }
                const metaParts = [];
                if (data.generated_at) {
                    metaParts.push('Gerado ' + formatRelativeTime(data.generated_at));
                }
                if (data.expires_at) {
                    metaParts.push('Expira em ' + formatExpiryCountdown(data.expires_at));
                }
                setLineQrMeta(metaParts.join(' | '), slug);
            } else {
                setLineQrPlaceholder('QR indisponível no momento.', slug);
                setLineQrMeta('', slug);
            }
        } catch (error) {
            if (lineQrModalState.slug !== slug) {
                return;
            }
            setLineQrPlaceholder(error.message || 'Falha ao carregar QR.', slug);
            setLineQrMeta('', slug);
        }
    }

    lineQrCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeLineQrModal();
        });
    });

    if (lineQrRefreshButton) {
        lineQrRefreshButton.addEventListener('click', () => {
            refreshLineQrModalQr(true);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && lineQrModalState.visible) {
            closeLineQrModal();
        }
    });

    function applyThreadSearchFilter() {
        if (!threadSearchInput) {
            return;
        }
        const query = normalizeSearchTerm(threadSearchInput.value);
        const nodes = document.querySelectorAll('.wa-panel [data-thread-search]');
        let matches = 0;
        nodes.forEach((node) => {
            const haystack = node.getAttribute('data-thread-search') || '';
            const isMatch = query === '' || haystack.includes(query);
            node.hidden = !isMatch;
            node.setAttribute('aria-hidden', isMatch ? 'false' : 'true');
            if (isMatch) {
                matches += 1;
            }
        });
        const panels = document.querySelectorAll('.wa-panel[data-panel]');
        panels.forEach((panel) => {
            const hasVisible = !!panel.querySelector('[data-thread-search]:not([hidden])');
            const searchEmptyState = panel.querySelector('[data-panel-search-empty]');
            if (query !== '' && !hasVisible) {
                panel.setAttribute('data-search-empty', 'true');
                if (searchEmptyState) {
                    searchEmptyState.hidden = false;
                }
            } else {
                panel.removeAttribute('data-search-empty');
                if (searchEmptyState) {
                    searchEmptyState.hidden = true;
                }
            }
        });
        if (threadSearchFeedback) {
            if (query === '') {
                threadSearchFeedback.hidden = true;
                threadSearchFeedback.textContent = '';
            } else {
                threadSearchFeedback.hidden = false;
                threadSearchFeedback.textContent = matches === 1
                    ? '1 conversa encontrada.'
                    : `${matches} conversas encontradas.`;
            }
        }
        if (threadSearchClear) {
            threadSearchClear.hidden = query === '';
        }
    }

    function scheduleThreadSearchRefresh(delay = 120) {
        if (threadSearchRefreshTimer) {
            clearTimeout(threadSearchRefreshTimer);
        }
        threadSearchRefreshTimer = setTimeout(() => {
            refreshPanels({ skipNotifications: true });
        }, Math.max(50, delay));
    }

    async function postForm(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: toParams(payload),
        });
        const data = await response.json().catch(() => ({ error: 'invalid_json' }));
        if (!response.ok) {
            throw new Error(data.error || 'Falha na solicitação');
        }
        return data;
    }

    async function getJson(url) {
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        });
        const data = await response.json().catch(() => ({ error: 'invalid_json' }));
        if (!response.ok) {
            throw new Error(data.error || 'Falha na solicitação');
        }
        return data;
    }

    async function postMultipart(url, formElement, overrideFormData = null) {
        const payload = overrideFormData instanceof FormData ? overrideFormData : new FormData(formElement);
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
            body: payload,
        });
        let data;
        let rawFallback = '';
        try {
            data = await response.json();
        } catch (error) {
            try {
                rawFallback = await response.text();
            } catch (textError) {
                rawFallback = '';
            }
            data = { error: 'invalid_json', raw: rawFallback ? rawFallback.slice(0, 2000) : undefined };
        }
        if (!response.ok) {
            console.error('Enviar mídia falhou', { status: response.status, data, rawFallback });
            throw new Error(data.error || rawFallback || 'Falha no envio');
        }
        return data;
    }

    function parseJsonText(rawText, fallback = null) {
        if (!rawText) {
            return fallback;
        }
        const normalized = rawText
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
        try {
            return JSON.parse(normalized);
        } catch (error) {
            console.warn('Falha ao interpretar JSON de atributo:', error);
            return fallback;
        }
    }

    function templateRequiresWebLock(template) {
        return (template || '').toLowerCase() === 'alt';
    }

    function isWebLinePayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return false;
        }
        const altInstance = (payload.alt_gateway_instance || '').toString().trim();
        if (altInstance !== '') {
            return true;
        }
        const template = (payload.api_template || '').toString().toLowerCase();
        if (template === 'alt') {
            return true;
        }
        const provider = (payload.provider || '').toString().toLowerCase();
        return provider === 'sandbox';
    }

    function syncWebLockVisibility(template) {
        const templateLock = templateRequiresWebLock(template);
        const originLock = lineForm && lineForm.dataset.webLockOrigin === '1';
        const shouldShow = templateLock || originLock;
        if (webLockField) {
            webLockField.hidden = !shouldShow;
        }
        if (webLockHint) {
            webLockHint.hidden = !shouldShow;
        }
        if (!shouldShow && webLockInput) {
            webLockInput.value = '';
        }
    }

    function setupHistoryForm(formElement) {
        if (!formElement) {
            return;
        }
        const modeRadios = formElement.querySelectorAll('input[name="history_mode"]');
        const rangeBlock = formElement.querySelector('[data-history-range]');
        const hoursBlock = formElement.querySelector('[data-history-hours]');
        function syncHistoryMode() {
            const selected = formElement.querySelector('input[name="history_mode"]:checked');
            const mode = selected ? selected.value : 'all';
            if (rangeBlock) {
                if (mode === 'range') {
                    rangeBlock.removeAttribute('hidden');
                } else {
                    rangeBlock.setAttribute('hidden', 'hidden');
                }
            }
            if (hoursBlock) {
                if (mode === 'hours') {
                    hoursBlock.removeAttribute('hidden');
                } else {
                    hoursBlock.setAttribute('hidden', 'hidden');
                }
            }
        }
        modeRadios.forEach((radio) => {
            radio.addEventListener('change', syncHistoryMode);
        });
        syncHistoryMode();
    }

    function syncLineRequirements() {
        const template = lineTemplateField ? (lineTemplateField.value || 'meta') : 'meta';
        const isAlt = template === 'alt';
        const isSandbox = template === 'sandbox';
        const requireBusinessId = template === 'meta';
        const requireAccessToken = !(isAlt || isSandbox);

        if (lineBusinessAccountField) {
            lineBusinessAccountField.required = requireBusinessId;
        }
        if (lineAccessField) {
            lineAccessField.required = requireAccessToken;
        }
    }

    function syncLineAltInline() {
        if (!lineAltInline) {
            return;
        }
        const template = lineTemplateField ? lineTemplateField.value : 'meta';
        const hasSelection = lineAltSelect && lineAltSelect.value !== '';
        const allowPanel = getLineMode() !== 'edit';
        const shouldShow = allowPanel && (template === 'alt' || hasSelection);
        if (shouldShow) {
            lineAltInline.removeAttribute('hidden');
            lineAltInline.setAttribute('aria-hidden', 'false');
        } else {
            lineAltInline.setAttribute('hidden', 'hidden');
            lineAltInline.setAttribute('aria-hidden', 'true');
            if (gatewayPanelVisible) {
                setGatewayPanelVisibility(false);
            }
        }
    }

    function syncTemplateVisibility(template) {
        const normalized = (template || 'meta').toString();
        templateBlocks.forEach((block) => {
            if (!(block instanceof HTMLElement)) {
                return;
            }
            const targets = (block.dataset.templateBlock || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const visible = targets.length === 0 || targets.includes(normalized);
            block.classList.toggle('is-visible', visible);
            block.style.display = visible ? '' : 'none';
            const inputs = block.querySelectorAll('input, select, textarea');
            inputs.forEach((control) => {
                const hadFlag = control.dataset.templateRequired === '1';
                if (!visible) {
                    if (control.required) {
                        control.dataset.templateRequired = '1';
                    } else {
                        control.dataset.templateRequired = control.dataset.templateRequired || '0';
                    }
                    control.required = false;
                } else if (hadFlag) {
                    control.required = true;
                    control.dataset.templateRequired = '0';
                }
            });
        });
        templateHints.forEach((hint) => {
            if (!(hint instanceof HTMLElement)) {
                return;
            }
            const targets = (hint.dataset.templateHint || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const visible = targets.includes(normalized);
            hint.classList.toggle('is-visible', visible);
            hint.style.display = visible ? '' : 'none';
        });
        templateHideNodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const targets = (node.dataset.templateHide || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const shouldHide = targets.includes(normalized);
            if (shouldHide) {
                node.setAttribute('hidden', 'hidden');
                node.style.display = 'none';
            } else {
                node.removeAttribute('hidden');
                node.style.display = '';
            }
        });
        templateShowNodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const targets = (node.dataset.templateShow || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const shouldShow = targets.length === 0 || targets.includes(normalized);
            if (shouldShow) {
                node.removeAttribute('hidden');
                node.style.display = '';
            } else {
                node.setAttribute('hidden', 'hidden');
                node.style.display = 'none';
            }
        });
    }

    function getLineMode() {
        return lineModeField ? (lineModeField.value || 'new') : 'new';
    }

    function toggleLineModeNode(node, visible) {
        if (!(node instanceof HTMLElement)) {
            return;
        }
        if (visible) {
            node.removeAttribute('hidden');
            node.style.display = '';
        } else {
            node.setAttribute('hidden', 'hidden');
            node.style.display = 'none';
        }
        const controls = node.querySelectorAll('input, select, textarea');
        controls.forEach((control) => {
            const hadFlag = control.dataset.lineModeRequired === '1';
            if (!visible) {
                if (control.required) {
                    control.dataset.lineModeRequired = '1';
                } else if (!control.dataset.lineModeRequired) {
                    control.dataset.lineModeRequired = '0';
                }
                control.required = false;
            } else if (hadFlag) {
                control.required = true;
                control.dataset.lineModeRequired = '0';
            }
        });
    }

    function syncLineModeUI() {
        const mode = getLineMode();
        lineModeSections.forEach((section) => {
            if (!(section instanceof HTMLElement)) {
                return;
            }
            const targets = (section.dataset.lineModeSection || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            toggleLineModeNode(section, targets.includes(mode));
        });
        lineModeVisibilityNodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const targets = (node.dataset.lineModeVisibility || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const shouldShow = targets.length === 0 || targets.includes(mode);
            toggleLineModeNode(node, shouldShow);
        });
        if (mode === 'new') {
            if (linePickerField) {
                linePickerField.value = '';
            }
            if (lineIdField) {
                lineIdField.value = '';
            }
        }
    }

    function getLineOptionPayload(lineId) {
        if (!linePickerField) {
            return null;
        }
        const normalizedId = (lineId || '').toString();
        if (normalizedId === '') {
            return null;
        }
        const option = Array.from(linePickerField.options).find((entry) => entry.value === normalizedId);
        if (!option) {
            return null;
        }
        const payload = parseJsonText(option.getAttribute('data-line-config') || '{}', null);
        if (payload && !payload.id) {
            payload.id = Number(normalizedId);
        }
        return payload;
    }

    function populateLineForm(payload, options = {}) {
        if (!lineForm || !payload) {
            return;
        }
        const syncPicker = options.syncPicker !== false;
        const targetId = payload.id ?? payload.line_id ?? '';
        if (lineIdField) {
            lineIdField.value = targetId;
        }
        if (lineLabelField) {
            lineLabelField.value = payload.label || '';
        }
        if (lineDisplayPhoneField) {
            lineDisplayPhoneField.value = payload.display_phone || '';
        }
        const template = payload.api_template || deriveTemplateFromPayload(payload);
        if (lineForm) {
            lineForm.dataset.webLockOrigin = isWebLinePayload(payload) ? '1' : '0';
        }
        if (lineTemplateField) {
            lineTemplateField.value = template;
        }
        if (lineProviderField) {
            lineProviderField.value = payload.provider || lineProviderField.value || 'meta';
        }
        if (lineBaseField) {
            lineBaseField.value = payload.api_base_url || '';
        }
        if (linePhoneNumberField) {
            linePhoneNumberField.value = payload.phone_number_id || '';
        }
        if (lineBusinessAccountField) {
            lineBusinessAccountField.value = payload.business_account_id || '';
        }
        if (lineAccessField) {
            lineAccessField.value = payload.access_token || '';
        }
        if (lineVerifyTokenField) {
            lineVerifyTokenField.value = payload.verify_token || '';
        }
        if (lineAltSelect) {
            lineAltSelect.value = payload.alt_gateway_instance || '';
        }
        if (lineStatusField) {
            lineStatusField.value = payload.status || 'active';
        }
        if (lineDefaultField) {
            lineDefaultField.checked = Number(payload.is_default || 0) === 1;
        }
        if (lineLimitEnabledField) {
            lineLimitEnabledField.checked = Number(payload.rate_limit_enabled || 0) === 1;
        }
        if (lineLimitWindowField) {
            lineLimitWindowField.value = payload.rate_limit_window_seconds
                ? String(payload.rate_limit_window_seconds)
                : '3600';
        }
        if (lineLimitMaxField) {
            lineLimitMaxField.value = payload.rate_limit_max_messages
                ? String(payload.rate_limit_max_messages)
                : '500';
        }
        if (rateLimitPresetField) {
            rateLimitPresetField.value = payload.rate_limit_preset || '';
            setRateLimitHint(rateLimitPresetField.value || '');
        }
        if (syncPicker && linePickerField && targetId) {
            const optionExists = Array.from(linePickerField.options).some((option) => option.value === String(targetId));
            if (optionExists) {
                linePickerField.value = String(targetId);
            }
        }
        applyApiTemplate({ userInitiated: false });
        syncRateLimitPresetFromValues();
        syncLineModeUI();
    }

    function setRateLimitHint(presetKey) {
        if (!rateLimitHint) {
            return;
        }
        if (presetKey && rateLimitPresets[presetKey]) {
            const preset = rateLimitPresets[presetKey];
            rateLimitHint.textContent = preset.description || preset.label || 'Nível Meta selecionado.';
        } else {
            rateLimitHint.textContent = 'Selecione um nível Meta para preencher os campos automaticamente.';
        }
    }

    function applyRateLimitPresetSelection(presetKey, options = {}) {
        if (!rateLimitPresetField) {
            return;
        }
        const preset = rateLimitPresets[presetKey];
        if (!preset) {
            setRateLimitHint('');
            return;
        }
        if (lineLimitWindowField) {
            lineLimitWindowField.value = preset.window_seconds;
        }
        if (lineLimitMaxField) {
            lineLimitMaxField.value = preset.max_messages;
        }
        if (!options.silent) {
            rateLimitPresetField.value = presetKey;
        }
        setRateLimitHint(presetKey);
    }

    function syncRateLimitPresetFromValues() {
        if (!rateLimitPresetField) {
            return;
        }
        const windowValue = Number(lineLimitWindowField ? lineLimitWindowField.value : 0);
        const maxValue = Number(lineLimitMaxField ? lineLimitMaxField.value : 0);
        const matched = Object.entries(rateLimitPresets).find(([, preset]) => {
            return Number(preset.window_seconds) === windowValue && Number(preset.max_messages) === maxValue;
        });
        if (matched) {
            const [key] = matched;
            rateLimitPresetField.value = key;
            setRateLimitHint(key);
        } else {
            rateLimitPresetField.value = '';
            setRateLimitHint('');
        }
    }

    function applyApiTemplate(options = {}) {
        if (!lineTemplateField) {
            syncLineRequirements();
            syncLineAltInline();
            return;
        }
        const template = lineTemplateField.value || 'meta';
        const userInitiated = Boolean(options.userInitiated);
        const presets = {
            meta: 'https://graph.facebook.com/v19.0',
            commercial: 'https://graph.facebook.com/v19.0',
            dialog360: 'https://waba.360dialog.io',
        };
        if (lineProviderField) {
            if (template === 'meta') {
                lineProviderField.value = 'meta';
            } else if (template === 'commercial') {
                lineProviderField.value = 'commercial';
            } else if (template === 'dialog360') {
                lineProviderField.value = 'dialog360';
            } else if (template === 'sandbox' || template === 'alt') {
                lineProviderField.value = 'sandbox';
            }
        }
        if (lineBaseField && userInitiated) {
            if (template === 'meta') {
                lineBaseField.value = presets.meta;
            } else if (template === 'commercial') {
                lineBaseField.value = presets.commercial;
            } else if (template === 'dialog360') {
                lineBaseField.value = presets.dialog360;
            } else if (template === 'sandbox' || template === 'alt') {
                lineBaseField.value = '';
            }
        }
        if (template === 'alt' && lineAltSelect && lineAltSelect.value === '') {
            if (lineAltDefault && Array.from(lineAltSelect.options).some((option) => option.value === lineAltDefault)) {
                lineAltSelect.value = lineAltDefault;
            } else if (lineAltSelect.options.length > 1) {
                lineAltSelect.selectedIndex = 1;
            }
        }
        if (template !== 'alt' && lineAltSelect) {
            lineAltSelect.value = '';
        }
        syncLineRequirements();
        syncLineAltInline();
        syncTemplateVisibility(template);
        syncWebLockVisibility(template);
    }

    function deriveTemplateFromPayload(payload) {
        if (!payload) {
            return 'meta';
        }
        if (payload.alt_gateway_instance) {
            return 'alt';
        }
        if (payload.provider === 'commercial') {
            return 'commercial';
        }
        if (payload.provider === 'dialog360') {
            return 'dialog360';
        }
        if (payload.provider === 'sandbox' && !payload.alt_gateway_instance) {
            return 'sandbox';
        }
        return 'meta';
    }

    function formatLocalDateTimeInput(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function toggleQueueExtras(queueValue) {
        if (!queueExtras) {
            return;
        }
        queueExtras.forEach((field) => {
            if (!(field instanceof HTMLElement)) {
                return;
            }
            const shouldShow = field.dataset.queueExtra === queueValue;
            field.classList.toggle('is-visible', shouldShow);
            field.style.display = shouldShow ? 'block' : 'none';
        });
    }

    function submitQueueForm() {
        if (!queueForm) {
            return;
        }
        if (typeof queueForm.requestSubmit === 'function') {
            queueForm.requestSubmit();
            return;
        }
        queueForm.dispatchEvent(new Event('submit', { cancelable: true }));
    }

    function activatePanel(panelKey) {
        if (!panelKey) {
            return;
        }
        tabLinks.forEach((tab) => {
            const isActive = tab.dataset.tabTarget === panelKey;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        panelSections.forEach((panel) => {
            const isActive = panel.dataset.panel === panelKey;
            panel.classList.toggle('is-active', isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            if (isActive) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', 'hidden');
            }
        });
    }

    function openClientPopover(payload) {
        if (!clientPopover) {
            return;
        }
        if (clientNameField) {
            clientNameField.textContent = payload.name || 'Cliente';
        }
        if (clientPhoneField) {
            clientPhoneField.textContent = payload.phone || '--';
        }
        if (clientLinkField) {
            if (payload.link) {
                clientLinkField.href = payload.link;
                clientLinkField.hidden = false;
            } else {
                clientLinkField.hidden = true;
            }
        }
        if (clientDocumentRow && clientDocumentField) {
            if (payload.document) {
                clientDocumentField.textContent = payload.document;
                clientDocumentRow.hidden = false;
            } else {
                clientDocumentRow.hidden = true;
            }
        }
        if (clientStatusRow && clientStatusField) {
            if (payload.status) {
                clientStatusField.textContent = payload.status;
                clientStatusRow.hidden = false;
            } else {
                clientStatusRow.hidden = true;
            }
        }
        if (clientPartnerRow && clientPartnerField) {
            if (payload.partner) {
                clientPartnerField.textContent = payload.partner;
                clientPartnerRow.hidden = false;
            } else {
                clientPartnerRow.hidden = true;
            }
        }
        clientBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        clientPopover.classList.add('is-visible');
        clientPopover.setAttribute('aria-hidden', 'false');
    }

    function closeClientPopover() {
        if (!clientPopover) {
            return;
        }
        clientPopover.classList.remove('is-visible');
        clientPopover.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = clientBodyOverflow;
    }

    function parseClientButtonPayload(element) {
        if (!element) {
            return null;
        }
        const raw = element.getAttribute('data-client-preview');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            console.error('NÆo foi poss¡vel abrir os dados do cliente:', error);
            return null;
        }
    }

    function resolveClientVariant(payload) {
        if (!payload) {
            return { variant: 'create', label: 'Cadastro' };
        }
        const statusRaw = (payload.status || '').toString().trim().toLowerCase();
        const activeStatuses = ['ativo', 'active', 'regular', 'regularizado'];
        const inactiveStatuses = ['inativo', 'inactive', 'cancelado', 'cancelada', 'revogado', 'revogada', 'bloqueado', 'bloqueada'];
        if (activeStatuses.includes(statusRaw)) {
            return { variant: 'active', label: 'Cliente' };
        }
        if (inactiveStatuses.includes(statusRaw)) {
            return { variant: 'inactive', label: 'Cliente' };
        }
        // Prospeccao/lead/sem status => trata como sem protocolo.
        return { variant: 'no-protocol', label: 'Cliente' };
    }

    function openNewThreadModal() {
        if (!newThreadModal) {
            return;
        }
        newThreadModal.classList.add('is-visible');
        newThreadModal.setAttribute('aria-hidden', 'false');
        newThreadModalVisible = true;
        newThreadBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        if (newThreadForm) {
            const firstInput = newThreadForm.querySelector('input[name=\"contact_name\"]');
            if (firstInput) {
                firstInput.focus();
            }
        }
    }

    function closeNewThreadModal() {
        if (!newThreadModal) {
            return;
        }
        newThreadModal.classList.remove('is-visible');
        newThreadModal.setAttribute('aria-hidden', 'true');
        newThreadModalVisible = false;
        document.body.style.overflow = newThreadBodyOverflow;
    }

    function openRegisterContactModal() {
        if (!registerContactModal) {
            return;
        }
        registerContactModal.classList.add('is-visible');
        registerContactModal.setAttribute('aria-hidden', 'false');
        registerContactBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }

    function closeRegisterContactModal() {
        if (!registerContactModal) {
            return;
        }
        registerContactModal.classList.remove('is-visible');
        registerContactModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = registerContactBodyOverflow;
        registerContactTrigger = null;
    }

    function translateGatewayStatus(rawStatus) {
        const normalized = String(rawStatus || '').toLowerCase();
        if (normalized.includes('qr')) {
            return 'Aguardando leitura do QR';
        }
        if (normalized.includes('resetting')) {
            return 'Reiniciando sessao';
        }
        if (normalized.includes('starting') || normalized.includes('syncing')) {
            return 'Inicializando gateway';
        }
        if (normalized.includes('connected') || normalized.includes('inchat') || normalized.includes('islogged')) {
            return 'Sessao conectada';
        }
        if (normalized.includes('browser')) {
            return 'Aguardando navegador';
        }
        if (normalized.includes('disconnected') || normalized.includes('offline')) {
            return 'Sessao desconectada';
        }
        return rawStatus || 'Gateway operacional';
    }

    function formatRelativeTime(timestampSeconds) {
        const numeric = Number(timestampSeconds);
        if (!numeric || Number.isNaN(numeric)) {
            return '—';
        }
        const nowSeconds = Math.floor(Date.now() / 1000);
        const delta = Math.max(0, nowSeconds - numeric);
        if (delta < 60) {
            return `${delta}s atrás`;
        }
        if (delta < 3600) {
            return `${Math.floor(delta / 60)}min atrás`;
        }
        if (delta < 86400) {
            return `${Math.floor(delta / 3600)}h atrás`;
        }
        const date = new Date(numeric * 1000);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }

    function formatBytes(value) {
        const numeric = Number(value);
        if (!numeric || Number.isNaN(numeric) || numeric <= 0) {
            return '';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = numeric;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }
        if (unitIndex === 0) {
            return `${Math.round(size)} ${units[unitIndex]}`;
        }
        const decimals = unitIndex >= 2 ? 2 : 1;
        return `${size.toFixed(decimals)} ${units[unitIndex]}`;
    }

    function formatDuration(seconds) {
        const total = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(total / 60);
        const secs = Math.floor(total % 60);
        return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    function describeHistoryStatus(history, metrics = {}) {
        if (!history || !history.enabled) {
            return 'Desligado';
        }
        if (history.running) {
            return 'Sincronizando...';
        }
        if (history.synced) {
            const suffix = metrics.historySyncedAt ? ` · ${formatRelativeTime(metrics.historySyncedAt)}` : '';
            return 'Sincronizado' + suffix;
        }
        return 'Aguardando';
    }

    function formatExpiryCountdown(expiresAt) {
        const numeric = Number(expiresAt);
        if (!numeric || Number.isNaN(numeric)) {
            return '';
        }
        const nowSeconds = Math.floor(Date.now() / 1000);
        const remaining = Math.max(0, numeric - nowSeconds);
        if (remaining === 0) {
            return 'expirado';
        }
        if (remaining < 60) {
            return `${remaining}s`;
        }
        return `${Math.ceil(remaining / 60)}min`;
    }

    function renderNotificationControls() {
        if (notifySoundToggle) {
            notifySoundToggle.classList.toggle('is-active', notificationState.soundEnabled);
            notifySoundToggle.setAttribute('aria-pressed', notificationState.soundEnabled ? 'true' : 'false');
            notifySoundToggle.textContent = notificationState.soundEnabled ? 'Som: Ativo' : 'Som: Mudo';
        }
        if (notifyPopupToggle) {
            notifyPopupToggle.classList.toggle('is-active', notificationState.popupEnabled);
            notifyPopupToggle.setAttribute('aria-pressed', notificationState.popupEnabled ? 'true' : 'false');
            notifyPopupToggle.textContent = notificationState.popupEnabled ? 'Popup: Ativo' : 'Popup: Oculto';
        }
        if (notifySoundStyleSelect) {
            const value = notificationState.soundStyle || 'voice';
            if (notifySoundStyleSelect.value !== value) {
                notifySoundStyleSelect.value = value;
            }
        }
        if (notifyDelaySelect) {
            const value = String(notificationState.cooldownMinutes ?? 0);
            if (notifyDelaySelect.value !== value) {
                notifyDelaySelect.value = value;
            }
        }
        updateNotifySummary();
        updateCustomSoundControls();
    }

    function updateNotifySummary() {
        if (!notifySummary) {
            return;
        }
        const parts = [
            notificationState.soundEnabled ? 'Som ativo' : 'Som desligado',
            notificationState.popupEnabled ? 'Popup ativo' : 'Popup desligado',
        ];
        notifySummary.textContent = parts.join(' · ');
    }

    function updateCustomSoundControls() {
        const usesCustom = (notificationState.soundStyle || 'voice') === 'custom';
        if (notifySoundUpload) {
            notifySoundUpload.hidden = !usesCustom;
        }
        if (notifySoundFilename) {
            notifySoundFilename.textContent = notificationState.customSoundName
                ? `Selecionado: ${notificationState.customSoundName}`
                : 'Nenhum arquivo selecionado.';
        }
        if (notifySoundFeedback) {
            if (!usesCustom) {
                setSoundFeedback('Ative o modo "Arquivo personalizado" para escolher um áudio.');
            } else if (!notificationState.customSoundName) {
                setSoundFeedback('Selecione um áudio curto (até 1MB).');
            }
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setSoundFeedback(message, isError = false) {
        if (!notifySoundFeedback) {
            return;
        }
        notifySoundFeedback.textContent = message;
        notifySoundFeedback.style.color = isError ? '#f87171' : 'var(--muted)';
    }

    function setBackupRestoreFeedback(message, isError = false) {
        if (!backupRestoreFeedback) {
            return;
        }
        backupRestoreFeedback.textContent = message;
        backupRestoreFeedback.style.color = isError ? '#f87171' : 'var(--muted)';
    }

    function setNotifyPanelVisibility(visible) {
        if (!notifyPanel || !notifyPanelToggle) {
            return;
        }
        if (visible) {
            notifyPanel.removeAttribute('hidden');
        } else {
            notifyPanel.setAttribute('hidden', '');
        }
        notifyPanelToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
    }

    function formatMessageTimestamp(sentAt) {
        const date = new Date(sentAt);
        if (Number.isNaN(date.getTime())) {
            return '-';
        }
        return date.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }) + ' ' + date.toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatBytes(value) {
        const numeric = Number(value);
        if (!numeric || Number.isNaN(numeric) || numeric <= 0) {
            return '';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = numeric;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }
        if (unitIndex === 0) {
            return `${Math.round(size)} ${units[unitIndex]}`;
        }
        const decimals = unitIndex >= 2 ? 2 : 1;
        return `${size.toFixed(decimals)} ${units[unitIndex]}`;
    }

    function normalizeMetadata(rawMetadata) {
        if (!rawMetadata) {
            return {};
        }
        if (typeof rawMetadata === 'object') {
            return rawMetadata;
        }
        if (typeof rawMetadata === 'string') {
            try {
                const parsed = JSON.parse(rawMetadata);
                if (parsed && typeof parsed === 'object') {
                    return parsed;
                }
            } catch (error) {
                console.warn('Falha ao interpretar metadados da mensagem', error);
            }
        }
        return {};
    }

    function resolveBroadcastQueueLabel(queue) {
        const key = (queue || '').toString();
        return broadcastQueueLabels[key] || key || 'Fila não definida';
    }

    function describeBroadcastQueues(queues) {
        if (!Array.isArray(queues) || queues.length === 0) {
            return 'Filas padrão';
        }
        return queues.map((queue) => resolveBroadcastQueueLabel(queue)).join(', ');
    }

    function describeBroadcastTemplate(entry) {
        if (!entry) {
            return null;
        }
        const criteria = entry.criteria && typeof entry.criteria === 'object' ? entry.criteria : {};
        const directSelection = entry.template_kind && entry.template_key ? { kind: entry.template_kind, key: entry.template_key } : null;
        const templateMeta = directSelection || (criteria.template && typeof criteria.template === 'object' ? criteria.template : null);
        if (!templateMeta || !templateMeta.kind || !templateMeta.key) {
            return null;
        }
        const template = findTemplate(templateMeta.kind, templateMeta.key);
        if (!template) {
            return `${templateMeta.kind} :: ${templateMeta.key}`;
        }
        return template.label || template.id || templateMeta.key;
    }

    function formatBroadcastStatus(status) {
        const normalized = (status || '').toLowerCase();
        const map = {
            pending: { label: 'Pendente', className: 'is-warning' },
            running: { label: 'Em andamento', className: 'is-warning' },
            completed: { label: 'Concluído', className: 'is-success' },
            completed_with_errors: { label: 'Com alerta', className: 'is-warning' },
            failed: { label: 'Falhou', className: 'is-error' },
        };
        return map[normalized] || { label: normalized || 'Indefinido', className: '' };
    }

    function buildBroadcastRow(entry) {
        const safeEntry = entry || {};
        const criteria = safeEntry.criteria && typeof safeEntry.criteria === 'object' ? safeEntry.criteria : {};
        const queues = Array.isArray(criteria.queues) ? criteria.queues : [];
        const statusInfo = formatBroadcastStatus(safeEntry.status);
        const title = escapeHtml(safeEntry.title || 'Comunicado');
        const createdAt = safeEntry.created_at ? formatRelativeTime(Number(safeEntry.created_at)) : '';
        const statsTotal = Number(safeEntry.stats_total || 0);
        const statsSent = Number(safeEntry.stats_sent || 0);
        const statsFailed = Number(safeEntry.stats_failed || 0);
        const failBadge = statsFailed > 0 ? ` &middot; <span style="color:#f87171;">${statsFailed} falha(s)</span>` : '';
        const templateLabel = describeBroadcastTemplate(safeEntry);
        const templateBadge = templateLabel ? `<span class="wa-chip" style="margin-top:4px;">Modelo: ${escapeHtml(templateLabel)}</span>` : '';
        const message = (safeEntry.message || '').trim();
        const messageBlock = message !== '' ? `<p class="wa-empty" style="margin:4px 0 0;">${escapeHtml(message)}</p>` : '';
        const createdLabel = createdAt !== '' ? `Criado ${createdAt}` : '';
        const extraBlock = messageBlock || templateBadge ? (messageBlock + templateBadge) : '';
        return `
            <div class="wa-broadcast-row">
                <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <strong>${title}</strong>
                    <span class="wa-status-badge ${statusInfo.className}">${statusInfo.label}</span>
                </div>
                <small>${describeBroadcastQueues(queues)} &middot; ${statsSent}/${statsTotal} entregues${failBadge}</small>
                ${extraBlock}
                <small>${createdLabel}</small>
            </div>
        `;
    }

    function renderBroadcastHistory(entries) {
        if (!broadcastHistoryWrapper) {
            return;
        }
        const list = Array.isArray(entries) ? entries : [];
        if (list.length === 0) {
            broadcastHistoryWrapper.innerHTML = '<p class="wa-empty wa-broadcast-empty">Nenhum comunicado enviado.</p>';
            return;
        }
        broadcastHistoryWrapper.innerHTML = list.map(buildBroadcastRow).join('');
    }

    function getLazyMediaObserver() {
        if (lazyMediaObserver !== null) {
            return lazyMediaObserver;
        }
        if (!('IntersectionObserver' in window)) {
            lazyMediaObserver = false;
            return lazyMediaObserver;
        }
        lazyMediaObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }
                const target = entry.target;
                const src = target.getAttribute('data-src');
                if (src) {
                    target.setAttribute('src', src);
                    target.removeAttribute('data-src');
                    if (target.tagName === 'VIDEO') {
                        target.load();
                    }
                }
                lazyMediaObserver.unobserve(target);
            });
        }, {
            rootMargin: '160px',
            threshold: 0.01,
        });
        return lazyMediaObserver;
    }

    function registerLazyMedia(node, src) {
        if (!node || !src) {
            return;
        }
        node.setAttribute('data-src', src);
        const observer = getLazyMediaObserver();
        if (observer && observer.observe) {
            observer.observe(node);
            return;
        }
        node.setAttribute('src', src);
        if (node.tagName === 'VIDEO') {
            node.load();
        }
    }

    function buildMediaBlock(media, mediaUrl) {
        const wrapper = document.createElement('div');
        wrapper.classList.add('wa-media-block');
        const rawType = media && media.type ? media.type : 'document';
        const type = String(rawType).toLowerCase();
        if (type === 'image') {
            const link = document.createElement('a');
            link.href = mediaUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            const img = document.createElement('img');
            img.src = (media && media.preview_url) ? media.preview_url : LAZY_MEDIA_PLACEHOLDER;
            img.alt = media && media.original_name ? media.original_name : 'Arquivo de mídia';
            img.loading = 'lazy';
            img.decoding = 'async';
            registerLazyMedia(img, mediaUrl);
            link.appendChild(img);
            wrapper.appendChild(link);
            return wrapper;
        }
        if (type === 'audio') {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = mediaUrl;
            wrapper.appendChild(audio);
            return wrapper;
        }
        if (type === 'video') {
            const video = document.createElement('video');
            video.controls = true;
            video.preload = 'metadata';
            registerLazyMedia(video, mediaUrl);
            wrapper.appendChild(video);
            return wrapper;
        }
        const anchor = document.createElement('a');
        anchor.classList.add('wa-media-download');
        anchor.href = mediaUrl;
        anchor.target = '_blank';
        anchor.rel = 'noopener';
        const nameSpan = document.createElement('span');
        nameSpan.textContent = media && media.original_name ? media.original_name : 'Arquivo';
        anchor.appendChild(nameSpan);
        if (media && media.size) {
            const sizeLabel = document.createElement('small');
            sizeLabel.textContent = formatBytes(media.size);
            anchor.appendChild(sizeLabel);
        }
        wrapper.appendChild(anchor);
        return wrapper;
    }

    function buildMediaActions(media, mediaUrl) {
        const downloadUrl = media && media.download_url
            ? media.download_url
            : (mediaUrl ? `${mediaUrl}?download=1` : null);
        if (!downloadUrl) {
            return null;
        }
        const actions = document.createElement('div');
        actions.classList.add('wa-media-actions');
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Baixar arquivo';
        actions.appendChild(link);
        return actions;
    }

    function resolveStatusMeta(status, direction) {
        const normalized = (status || '').toLowerCase();
        const map = {
            queued: { className: 'is-pending', label: 'Na fila para envio' },
            saving: { className: 'is-pending', label: 'Registrando' },
            saved: { className: 'is-pending', label: 'Registrando' },
            sent: { className: 'is-sent', label: 'Enviado' },
            delivered: { className: 'is-delivered', label: 'Recebido' },
            imported: { className: 'is-delivered', label: 'Registrado' },
            read: { className: 'is-read', label: 'Lido' },
            error: { className: 'is-error', label: 'Falha no envio' },
            failed: { className: 'is-error', label: 'Falha no envio' },
        };
        if (normalized && map[normalized]) {
            return map[normalized];
        }
        if (direction === 'incoming') {
            return map.delivered;
        }
        return { className: 'is-pending', label: 'Processando' };
    }

    function removeEmptyState() {
        if (!messagesContainer) {
            return;
        }
        const emptyState = messagesContainer.querySelector('.wa-empty');
        if (emptyState) {
            emptyState.remove();
        }
    }

    function trimRenderedMessages(limit = INITIAL_MESSAGE_RENDER_LIMIT, options = {}) {
        if (!messagesContainer || !limit || limit <= 0) {
            return;
        }
        const initialOnly = Boolean(options.initialOnly);
        if (initialOnly && initialMessageTrimmed) {
            return;
        }
        const bubbles = Array.from(messagesContainer.querySelectorAll('.wa-bubble'));
        if (bubbles.length <= limit) {
            return;
        }
        const excess = bubbles.length - limit;
        const removable = bubbles.slice(0, excess);
        removable.forEach((bubble) => bubble.remove());

        const remainingFirst = messagesContainer.querySelector('.wa-bubble');
        if (remainingFirst) {
            const candidateId = Number(remainingFirst.getAttribute('data-message-id') || 0);
            if (candidateId > 0) {
                firstMessageId = candidateId;
                messagesContainer.setAttribute('data-first-message-id', String(candidateId));
                setLoadMoreVisibility(true);
            }
        }
        if (initialOnly) {
            initialMessageTrimmed = true;
        }
    }

    function buildMessageBubbleElement(message) {
        if (!messagesContainer || !message) {
            return null;
        }
        const numericMessageId = Number(message.id || message.message_id || 0);
        if (numericMessageId && messagesContainer.querySelector(`[data-message-id="${numericMessageId}"]`)) {
            return null;
        }
        const bubble = document.createElement('div');
        bubble.classList.add('wa-bubble');
        if (numericMessageId) {
            bubble.dataset.messageId = String(numericMessageId);
        }
        const direction = message.direction || 'outgoing';
        if (direction === 'outgoing') {
            bubble.classList.add('is-outgoing');
        } else if (direction === 'internal') {
            bubble.classList.add('is-internal');
        } else {
            bubble.classList.add('is-incoming');
        }

        const metadata = normalizeMetadata(message.metadata);
        const mediaMeta = metadata.media && typeof metadata.media === 'object' ? metadata.media : null;
        const resolvedMessageId = message.id || message.message_id || null;
        const mediaUrl = mediaMeta && mediaMeta.url
            ? mediaMeta.url
            : (mediaMeta && resolvedMessageId ? `${whatsappMediaBaseUrl}/${resolvedMessageId}` : null);
        const contentText = String(message.content || '');
        const hideContent = Boolean(mediaMeta && /^\[[^\]]+\]$/u.test(contentText.trim()));

        const statusMeta = resolveStatusMeta(message.status, direction);
        const statusIndicator = document.createElement('span');
        statusIndicator.classList.add('wa-status-indicator', statusMeta.className);
        statusIndicator.textContent = '★';
        statusIndicator.title = statusMeta.label;
        bubble.appendChild(statusIndicator);

        let actorLabel = 'Cliente';
        if (direction === 'outgoing') {
            if (metadata.actor_identifier && metadata.actor_name) {
                actorLabel = `${metadata.actor_identifier} - ${metadata.actor_name}`;
            } else {
                actorLabel = metadata.actor_name || metadata.actor_identifier || 'Você';
            }
        } else if (direction === 'internal') {
            actorLabel = metadata.actor_name || 'Nota interna';
        }

        if (direction === 'outgoing') {
            const author = document.createElement('div');
            author.className = 'wa-message-author';
            author.innerHTML = `<strong><em>${escapeHtml(actorLabel)}</em></strong>`;
            bubble.appendChild(author);
        }

        if (mediaMeta && mediaUrl) {
            bubble.appendChild(buildMediaBlock(mediaMeta, mediaUrl));
            const mediaActions = buildMediaActions(mediaMeta, mediaUrl);
            if (mediaActions) {
                bubble.appendChild(mediaActions);
            }
        }

        if (!hideContent && contentText !== '') {
            const body = document.createElement('div');
            body.className = 'wa-message-body';
            body.innerHTML = escapeHtml(contentText).replace(/\n/g, '<br>');
            bubble.appendChild(body);
        }

        const footer = document.createElement('footer');
        const sentAt = Number(message.sentAt ?? message.sent_at ?? Date.now());
        if (direction === 'incoming') {
            footer.textContent = `Cliente • ${formatMessageTimestamp(sentAt)}`;
        } else if (direction === 'internal') {
            footer.textContent = `${actorLabel} • ${formatMessageTimestamp(sentAt)}`;
        } else {
            footer.textContent = formatMessageTimestamp(sentAt);
        }
        bubble.appendChild(footer);

        return { bubble, numericMessageId, direction };
    }

    function prependMessageBubble(message) {
        const built = buildMessageBubbleElement(message);
        if (!built) {
            return;
        }
        removeEmptyState();
        const { bubble, numericMessageId } = built;
        const anchor = messagesContainer.querySelector('.wa-bubble, .wa-empty');
        messagesContainer.insertBefore(bubble, anchor || null);
        if (numericMessageId && (firstMessageId === 0 || numericMessageId < firstMessageId)) {
            firstMessageId = numericMessageId;
            messagesContainer.setAttribute('data-first-message-id', String(firstMessageId));
        }
    }

    function appendMessageBubble(message) {
        const built = buildMessageBubbleElement(message);
        if (!built) {
            return;
        }
        removeEmptyState();
        const { bubble, numericMessageId, direction } = built;
        messagesContainer.appendChild(bubble);
        if (numericMessageId && numericMessageId > (lastMessageId || 0)) {
            lastMessageId = numericMessageId;
            messagesContainer.setAttribute('data-last-message-id', String(lastMessageId));
        }
        if (numericMessageId && (firstMessageId === 0 || numericMessageId < firstMessageId)) {
            firstMessageId = numericMessageId;
            messagesContainer.setAttribute('data-first-message-id', String(firstMessageId));
        }
        if (direction === 'outgoing') {
            markAgentResponse(threadId);
        } else if (direction === 'incoming') {
            handleActiveThreadIncoming(message);
        }
    }

    function updateComposerMediaPreview() {
        if (!messageMediaPreview || !messageMediaName || !messageMediaSize) {
            return;
        }
        let file = null;
        if (messageMediaInput && messageMediaInput.files && messageMediaInput.files.length > 0) {
            file = messageMediaInput.files[0];
        } else if (recordedAudioFallback) {
            file = recordedAudioFallback;
        }
        if (!file) {
            messageMediaPreview.setAttribute('hidden', 'hidden');
            messageMediaName.textContent = '';
            messageMediaSize.textContent = '';
            return;
        }
        messageMediaName.textContent = file.name;
        messageMediaSize.textContent = file.size ? formatBytes(file.size) : '';
        messageMediaPreview.removeAttribute('hidden');
    }

    function findTemplate(kind, key) {
        const catalog = mediaTemplates[kind] || [];
        return catalog.find((item) => item.id === key) || null;
    }

    function refreshTemplatePreview() {
        if (!templatePreview) {
            return;
        }
        if (!templateSelection || !templateSelection.template) {
            templatePreview.setAttribute('hidden', 'hidden');
            if (templatePreviewLabel) {
                templatePreviewLabel.textContent = '';
            }
            if (templatePreviewDescription) {
                templatePreviewDescription.textContent = '';
            }
            if (templatePreviewImage) {
                templatePreviewImage.setAttribute('hidden', 'hidden');
                templatePreviewImage.removeAttribute('src');
            }
            return;
        }
        const template = templateSelection.template;
        if (templatePreviewLabel) {
            templatePreviewLabel.textContent = template.label || template.id;
        }
        if (templatePreviewDescription) {
            templatePreviewDescription.textContent = template.description || 'Figurinha corporativa selecionada.';
        }
        if (templatePreviewImage) {
            if (template.preview_url) {
                templatePreviewImage.src = template.preview_url;
                templatePreviewImage.removeAttribute('hidden');
            } else {
                templatePreviewImage.setAttribute('hidden', 'hidden');
                templatePreviewImage.removeAttribute('src');
            }
        }
        templatePreview.removeAttribute('hidden');
    }

    function clearTemplateSelection() {
        templateSelection = null;
        if (templateKindField) {
            templateKindField.value = '';
        }
        if (templateKeyField) {
            templateKeyField.value = '';
        }
        refreshTemplatePreview();
    }

    function populateBroadcastTemplates() {
        if (!broadcastKeyField) {
            return;
        }
        const kind = broadcastKindField ? broadcastKindField.value : '';
        broadcastKeyField.innerHTML = '<option value="">Selecione o modelo</option>';
        broadcastKeyField.disabled = true;
        const catalog = kind && mediaTemplates[kind] ? mediaTemplates[kind] : null;
        if (!Array.isArray(catalog) || catalog.length === 0) {
            return;
        }
        const options = ['<option value="">Selecione o modelo</option>'];
        catalog.forEach((template) => {
            const label = template.label || template.id;
            options.push(`<option value="${escapeHtml(template.id)}">${escapeHtml(label)}</option>`);
        });
        broadcastKeyField.innerHTML = options.join('');
        broadcastKeyField.disabled = false;
    }

    function updateBroadcastTemplatePreviewUi() {
        if (!broadcastTemplatePreview) {
            return;
        }
        const kind = broadcastKindField ? broadcastKindField.value : '';
        const key = broadcastKeyField ? broadcastKeyField.value : '';
        const template = (kind && key) ? findTemplate(kind, key) : null;
        if (!template) {
            broadcastTemplatePreview.setAttribute('hidden', 'hidden');
            if (broadcastTemplatePreviewLabel) {
                broadcastTemplatePreviewLabel.textContent = '';
            }
            if (broadcastTemplatePreviewDescription) {
                broadcastTemplatePreviewDescription.textContent = '';
            }
            if (broadcastTemplatePreviewImage) {
                broadcastTemplatePreviewImage.setAttribute('hidden', 'hidden');
                broadcastTemplatePreviewImage.removeAttribute('src');
            }
            if (broadcastTemplateClear) {
                broadcastTemplateClear.setAttribute('hidden', 'hidden');
            }
            return;
        }
        if (broadcastTemplatePreviewLabel) {
            broadcastTemplatePreviewLabel.textContent = template.label || template.id;
        }
        if (broadcastTemplatePreviewDescription) {
            const caption = template.description || template.caption || '';
            broadcastTemplatePreviewDescription.textContent = caption;
        }
        if (broadcastTemplatePreviewImage) {
            if (template.preview_url) {
                broadcastTemplatePreviewImage.src = template.preview_url;
                broadcastTemplatePreviewImage.removeAttribute('hidden');
            } else {
                broadcastTemplatePreviewImage.setAttribute('hidden', 'hidden');
                broadcastTemplatePreviewImage.removeAttribute('src');
            }
        }
        broadcastTemplatePreview.removeAttribute('hidden');
        if (broadcastTemplateClear) {
            broadcastTemplateClear.removeAttribute('hidden');
        }
    }

    function clearBroadcastTemplateSelectionUi() {
        if (broadcastKindField) {
            broadcastKindField.value = '';
        }
        if (broadcastKeyField) {
            broadcastKeyField.value = '';
            broadcastKeyField.disabled = true;
            broadcastKeyField.innerHTML = '<option value="">Selecione o modelo</option>';
        }
        updateBroadcastTemplatePreviewUi();
    }

    function applyTemplateSelection(kind, key) {
        const template = findTemplate(kind, key);
        if (!template) {
            return;
        }
        templateSelection = { kind, key, template };
        if (templateKindField) {
            templateKindField.value = kind;
        }
        if (templateKeyField) {
            templateKeyField.value = key;
        }
        if (messageMediaInput) {
            messageMediaInput.value = '';
        }
        recordedAudioFallback = null;
        refreshTemplatePreview();
        updateComposerMediaPreview();
    }

    function openTemplateModal(kind) {
        const modal = document.querySelector(`[data-template-modal="${kind}"]`);
        if (!modal) {
            return;
        }
        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeTemplateModal(modal) {
        if (!modal) {
            return;
        }
        modal.setAttribute('hidden', 'hidden');
        document.body.style.overflow = '';
    }

    function templateSelectionAllowed(kind) {
        if (kind === 'stickers') {
            return composerCapabilities.stickersAllowed;
        }
        return true;
    }

    function updateAudioStatus(message, isError = false) {
        if (!audioRecordStatus) {
            return;
        }
        audioRecordStatus.textContent = message || '';
        audioRecordStatus.classList.toggle('is-error', Boolean(isError));
    }

    function resolveSupportedAudioMime() {
        const candidates = [
            'audio/ogg;codecs=opus',
            'audio/ogg',
            'audio/webm;codecs=opus',
            'audio/webm',
        ];
        if (typeof window.MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
            return '';
        }
        return candidates.find((mime) => {
            try {
                return MediaRecorder.isTypeSupported(mime);
            } catch (error) {
                return false;
            }
        }) || '';
    }

    function extensionFromMime(mime) {
        if (!mime) {
            return 'ogg';
        }
        const lower = String(mime).toLowerCase();
        if (lower.includes('ogg')) {
            return 'ogg';
        }
        if (lower.includes('webm')) {
            return 'webm';
        }
        if (lower.includes('mp3')) {
            return 'mp3';
        }
        return 'ogg';
    }

    function resetAudioRecorderControls(label = 'Gravar áudio') {
        if (audioRecordButton) {
            audioRecordButton.disabled = false;
            audioRecordButton.textContent = label;
            audioRecordButton.dataset.recording = '0';
        }
        if (audioRecordCancel) {
            audioRecordCancel.setAttribute('hidden', 'hidden');
        }
    }

    function releaseAudioResources() {
        if (audioTimer) {
            clearInterval(audioTimer);
            audioTimer = null;
        }
        if (audioRecorder) {
            audioRecorder.ondataavailable = null;
            audioRecorder.onstop = null;
            if (audioRecorder.state !== 'inactive') {
                try {
                    audioRecorder.stop();
                } catch (error) {
                    // ignore
                }
            }
        }
        if (audioStream) {
            audioStream.getTracks().forEach((track) => track.stop());
        }
        audioRecorder = null;
        audioStream = null;
        audioChunks = [];
        audioSeconds = 0;
        audioExtension = 'ogg';
    }

    function attachFileToComposer(file) {
        if (!file) {
            return;
        }
        if (messageMediaInput && window.DataTransfer) {
            const transfer = new DataTransfer();
            transfer.items.add(file);
            messageMediaInput.files = transfer.files;
            recordedAudioFallback = null;
        } else {
            recordedAudioFallback = file;
        }
        updateComposerMediaPreview();
    }

    async function startAudioRecording() {
        if (audioRecorder) {
            return;
        }
        if (typeof window.MediaRecorder !== 'function' || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            updateAudioStatus('Seu navegador não permite gravar áudio.', true);
            return;
        }
        if (messageMediaInput) {
            messageMediaInput.value = '';
        }
        recordedAudioFallback = null;
        updateComposerMediaPreview();
        try {
            audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (error) {
            updateAudioStatus('Permita o acesso ao microfone para gravar áudio.', true);
            return;
        }
        audioChunks = [];
        audioSeconds = 0;
        audioDiscardOnStop = false;
        try {
            const preferredMime = resolveSupportedAudioMime() || 'audio/ogg;codecs=opus';
            const recorderOptions = preferredMime ? { mimeType: preferredMime } : undefined;
            audioRecorder = new MediaRecorder(audioStream, recorderOptions);
            audioMimeType = audioRecorder.mimeType || preferredMime || 'audio/ogg;codecs=opus';
            audioExtension = extensionFromMime(audioMimeType);
        } catch (error) {
            updateAudioStatus('Falha ao iniciar o gravador de áudio.', true);
            releaseAudioResources();
            return;
        }
        audioRecorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                audioChunks.push(event.data);
            }
        });
        audioRecorder.addEventListener('stop', () => {
            handleRecorderStop();
        });
        audioRecorder.start();
        if (audioRecordButton) {
            audioRecordButton.textContent = 'Parar gravação';
            audioRecordButton.dataset.recording = '1';
            audioRecordButton.disabled = false;
        }
        if (audioRecordCancel) {
            audioRecordCancel.removeAttribute('hidden');
        }
        updateAudioStatus('Gravando... 00:00');
        audioTimer = setInterval(() => {
            audioSeconds += 1;
            updateAudioStatus(`Gravando... ${formatDuration(audioSeconds)}`);
        }, 1000);
    }

    function stopAudioRecording(discard = false) {
        if (!audioRecorder) {
            return;
        }
        audioDiscardOnStop = discard;
        if (audioTimer) {
            clearInterval(audioTimer);
            audioTimer = null;
        }
        try {
            audioRecorder.stop();
        } catch (error) {
            handleRecorderStop();
        }
        if (audioRecordButton) {
            audioRecordButton.disabled = true;
            audioRecordButton.textContent = discard ? 'Cancelando...' : 'Processando áudio...';
        }
        updateAudioStatus(discard ? 'Cancelando gravação...' : 'Processando áudio...');
    }

    function handleRecorderStop() {
        const chunks = audioChunks.slice();
        const duration = audioSeconds;
        const mimeType = audioMimeType;
        const discarded = audioDiscardOnStop;
        releaseAudioResources();
        if (discarded || chunks.length === 0) {
            resetAudioRecorderControls();
            updateAudioStatus(discarded ? 'Gravação cancelada.' : 'Nenhum áudio capturado.');
            return;
        }
        const fileName = `audio-whatsapp-${new Date().toISOString().replace(/[:.]/g, '-')}.${audioExtension || 'ogg'}`;
        const blob = new Blob(chunks, { type: mimeType });
        const file = new File([blob], fileName, { type: mimeType });
        attachFileToComposer(file);
        resetAudioRecorderControls('Gravar novo áudio');
        updateAudioStatus(`Áudio gravado (${formatDuration(duration)}).`);
    }

    function scrollMessagesToBottom() {
        if (!messagesContainer) {
            return;
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function clearActiveThreadUnread(unreadValue = 0) {
        const contactUnread = document.querySelector('.wa-contact-unread');
        if (contactUnread) {
            contactUnread.remove();
        }
        const threadBadge = document.querySelector(`.wa-mini-thread[data-thread-id="${threadId}"] .wa-unread`);
        if (threadBadge) {
            threadBadge.remove();
        }
        const snapshot = threadSnapshots.get(threadId);
        if (snapshot) {
            snapshot.unread = Number(unreadValue) || 0;
            threadSnapshots.set(threadId, snapshot);
        }
    }

    async function pollThreadMessages() {
        if (!threadId || !threadPollEndpoint || !messagesContainer || isThreadPolling) {
            return;
        }
        isThreadPolling = true;
        if (threadPollController) {
            try {
                threadPollController.abort();
            } catch (error) {
                // ignore abort errors
            }
        }
        threadPollController = new AbortController();
        const params = new URLSearchParams({
            thread_id: String(threadId),
            last_message_id: String(lastMessageId || 0),
        });
        try {
            const response = await fetch(`${threadPollEndpoint}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: threadPollController.signal,
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const incomingMessages = (payload && Array.isArray(payload.messages)) ? payload.messages : [];
            if (incomingMessages.length) {
                incomingMessages.forEach((message) => appendMessageBubble(message));
                scrollMessagesToBottom();
            }
            if (payload && payload.contact) {
                applyContactPayload(payload.contact);
            }
            if (payload) {
                const unreadFromServer = Number(payload.thread_unread ?? payload.thread?.unread_count ?? 0);
                clearActiveThreadUnread(unreadFromServer);
            }
            if (payload && typeof payload.last_message_id === 'number' && payload.last_message_id > (lastMessageId || 0)) {
                lastMessageId = payload.last_message_id;
                messagesContainer.setAttribute('data-last-message-id', String(lastMessageId));
            }
            if (incomingMessages.length || (payload && payload.last_message_id)) {
                updateThreadCacheFromMessages(threadId, incomingMessages, payload.last_message_id);
            }
        } catch (error) {
            console.error('Erro ao atualizar mensagens do thread:', error);
        } finally {
            isThreadPolling = false;
            threadPollController = null;
        }
    }

    function setLoadMoreVisibility(visible) {
        if (!loadMoreButton) {
            return;
        }
        loadMoreButton.hidden = !visible;
    }

    async function loadOlderThreadMessages() {
        if (!threadId || !threadPollEndpoint || !messagesContainer || !loadMoreButton) {
            return;
        }
        if (isLoadingOlderMessages) {
            return;
        }
        const cursor = Number(firstMessageId || 0);
        if (!cursor) {
            setLoadMoreVisibility(false);
            return;
        }
        isLoadingOlderMessages = true;
        loadMoreButton.disabled = true;
        loadMoreButton.textContent = 'Carregando...';

        const params = new URLSearchParams({
            thread_id: String(threadId),
            before_id: String(cursor),
            limit: String(THREAD_HISTORY_PAGE_SIZE),
        });

        const previousHeight = messagesContainer.scrollHeight;
        const previousScrollTop = messagesContainer.scrollTop;

        try {
            const response = await fetch(`${threadPollEndpoint}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('Falha ao carregar mensagens antigas.');
            }
            const payload = await response.json();
            const olderMessages = (payload && Array.isArray(payload.messages)) ? payload.messages : [];
            if (olderMessages.length) {
                olderMessages.forEach((message) => prependMessageBubble(message));
                const heightDelta = messagesContainer.scrollHeight - previousHeight;
                if (heightDelta !== 0) {
                    messagesContainer.scrollTop = previousScrollTop + heightDelta;
                }
            }
            const nextCursor = Number(payload && payload.before_id_next ? payload.before_id_next : 0);
            if (nextCursor > 0 && (firstMessageId === 0 || nextCursor < firstMessageId)) {
                firstMessageId = nextCursor;
                messagesContainer.setAttribute('data-first-message-id', String(firstMessageId));
            }
            const hasMore = Boolean(payload && payload.has_more);
            if (!hasMore || olderMessages.length === 0) {
                setLoadMoreVisibility(false);
            } else {
                loadMoreButton.hidden = false;
                loadMoreButton.disabled = false;
                loadMoreButton.textContent = 'Carregar mais';
            }
        } catch (error) {
            console.error('Erro ao carregar mensagens antigas:', error);
            loadMoreButton.disabled = false;
            loadMoreButton.textContent = 'Carregar mais';
        } finally {
            isLoadingOlderMessages = false;
            if (loadMoreButton) {
                loadMoreButton.textContent = 'Carregar mais';
                loadMoreButton.disabled = loadMoreButton.hidden;
            }
        }
    }

    function scheduleThreadPolling() {
        if (!threadId || !messagesContainer || !threadPollEndpoint) {
            return;
        }
        if (threadPollTimer) {
            clearInterval(threadPollTimer);
        }
        const interval = getThreadPollInterval();
        threadPollTimer = setInterval(() => {
            if (!threadPollPaused) {
                pollThreadMessages();
            }
        }, interval);
        if (!threadPollPaused) {
            pollThreadMessages();
        }
    }

    function pauseThreadPolling() {
        threadPollPaused = true;
        if (threadPollTimer) {
            clearInterval(threadPollTimer);
            threadPollTimer = null;
        }
        if (threadPollController) {
            try {
                threadPollController.abort();
            } catch (error) {
                // ignore abort errors
            }
            threadPollController = null;
        }
    }

    function resumeThreadPolling() {
        threadPollPaused = false;
        scheduleThreadPolling();
    }

    function buildPanelRefreshUrl() {
        if (!panelRefreshEndpoint) {
            return null;
        }
        const params = new URLSearchParams();
        params.set('standalone', '1');
        params.set('compact', '1');
        if (selectedChannel) {
            params.set('channel', selectedChannel);
        }
        if (threadId) {
            params.set('thread', String(threadId));
        }
        if (threadSearchInput && threadSearchInput.value) {
            params.set('search', threadSearchInput.value);
        }
        return `${panelRefreshEndpoint}?${params.toString()}`;
    }

    async function refreshPanels(options = {}) {
        if (!panelRefreshEndpoint || isPanelRefreshing) {
            return;
        }
        const refreshUrl = buildPanelRefreshUrl();
        if (!refreshUrl) {
            return;
        }

        const skipNotifications = Boolean(options.skipNotifications || document.hidden);

        if (!panelCacheHydrated) {
            const cachedPanels = loadPanelCache();
            if (cachedPanels) {
                applyPanelSnapshot(cachedPanels, { skipNotifications: true, fromCache: true });
                panelCacheHydrated = true;
            }
        }
        isPanelRefreshing = true;
        if (panelRefreshController) {
            try {
                panelRefreshController.abort();
            } catch (error) {
                // ignore abort errors
            }
        }
        panelRefreshController = new AbortController();
        try {
            const response = await fetch(refreshUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: panelRefreshController.signal,
            });
            if (!response.ok) {
                return;
            }
            const data = await response.json().catch(() => null);
            if (data && data.panels) {
                applyPanelSnapshot(data.panels, { skipNotifications });
                persistPanelCache(data.panels);
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                // Solicitação cancelada por nova busca; ignora sem exibir erro.
            } else {
                console.error('Erro ao atualizar filas:', error);
            }
        } finally {
            isPanelRefreshing = false;
            panelRefreshController = null;
        }
    }

    function applyPanelSnapshot(panels, options = {}) {
        if (!panels || typeof panels !== 'object') {
            return;
        }
        const skipNotifications = Boolean(options.skipNotifications) || !notificationsPrimed;
        Object.entries(panels).forEach(([panelKey, panelData]) => {
            updatePanelTab(panelKey, panelData);
            updatePanelList(panelKey, panelData);
            if (panelData && Array.isArray(panelData.meta)) {
                registerPanelMeta(panelKey, panelData.meta, { skipNotifications });
            }
        });
        notificationsPrimed = true;
        applyThreadSearchFilter();
        if (!document.hidden) {
            prefetchVisibleThreads();
            setupPrefetchObserver();
        }
    }

    let isPrefetchingThreads = false;

    async function prefetchThreadMessages(targetThreadId) {
        if (!threadPollEndpoint || !targetThreadId) {
            return;
        }
        const params = new URLSearchParams({
            thread_id: String(targetThreadId),
            last_message_id: '0',
            prefetch: '1',
        });
        try {
            const response = await fetch(`${threadPollEndpoint}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json().catch(() => null);
            if (payload && Array.isArray(payload.messages)) {
                const lastId = Number(payload.last_message_id || 0);
                prefetchedThreads.set(targetThreadId, {
                    messages: payload.messages,
                    lastMessageId: lastId,
                    fetchedAt: Date.now(),
                });
                updateThreadCacheFromMessages(targetThreadId, payload.messages, lastId);
                trimPrefetchCache();
            }
        } catch (error) {
            console.warn('Falha ao pré-carregar mensagens do thread', error);
        }
    }

    function trimPrefetchCache(maxEntries = THREAD_PREFETCH_LIMIT * 2) {
        if (prefetchedThreads.size <= maxEntries) {
            return;
        }
        const ordered = Array.from(prefetchedThreads.entries()).sort((a, b) => {
            const aTime = a[1]?.fetchedAt || 0;
            const bTime = b[1]?.fetchedAt || 0;
            return aTime - bTime;
        });
        while (ordered.length > maxEntries) {
            const [oldestKey] = ordered.shift();
            prefetchedThreads.delete(oldestKey);
        }
    }

    async function prefetchVisibleThreads(limit = THREAD_PREFETCH_LIMIT) {
        if (!threadPollEndpoint || isPrefetchingThreads || limit <= 0) {
            return;
        }
        const candidates = Array.from(document.querySelectorAll('.wa-panel.is-active .wa-mini-thread[data-thread-id]'))
            .slice(0, limit)
            .map((el) => Number(el.getAttribute('data-thread-id') || 0))
            .filter((id) => id > 0 && id !== threadId && !prefetchedThreads.has(id));

        if (candidates.length === 0) {
            return;
        }

        isPrefetchingThreads = true;
        try {
            for (const candidate of candidates) {
                await prefetchThreadMessages(candidate);
            }
        } finally {
            isPrefetchingThreads = false;
        }
    }

    let prefetchObserver = null;

    function disconnectPrefetchObserver() {
        if (prefetchObserver) {
            try {
                prefetchObserver.disconnect();
            } catch (error) {
                // ignore
            }
            prefetchObserver = null;
        }
    }

    function setupPrefetchObserver() {
        disconnectPrefetchObserver();
        if (!('IntersectionObserver' in window)) {
            return;
        }
        const activePanel = document.querySelector('.wa-panel.is-active');
        if (!activePanel) {
            return;
        }
        prefetchObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }
                const id = Number(entry.target.getAttribute('data-thread-id') || 0);
                if (id > 0 && id !== threadId && !prefetchedThreads.has(id)) {
                    prefetchThreadMessages(id);
                }
            });
        }, {
            root: activePanel.querySelector('[data-panel-list]') || activePanel,
            rootMargin: '48px',
            threshold: 0.1,
        });

        const items = activePanel.querySelectorAll('.wa-mini-thread[data-thread-id]');
        items.forEach((item) => prefetchObserver.observe(item));
    }

    function updatePanelTab(panelKey, panelData) {
        const tab = document.getElementById(`wa-tab-${panelKey}`);
        if (!tab) {
            return;
        }
        const countEl = tab.querySelector('.wa-tab-count');
        if (countEl && typeof panelData.count !== 'undefined') {
            countEl.textContent = String(panelData.count);
        }
        let unreadEl = tab.querySelector('.wa-tab-unread');
        const unreadValue = Number(panelData.unread || 0);
        if (unreadValue > 0) {
            if (!unreadEl) {
                unreadEl = document.createElement('span');
                unreadEl.className = 'wa-tab-unread';
                tab.appendChild(unreadEl);
            }
            unreadEl.textContent = `+${unreadValue}`;
        } else if (unreadEl) {
            unreadEl.remove();
        }
    }

    function renderPanelItems(list, items, options = {}) {
        if (!list || !Array.isArray(items)) {
            return;
        }
        const activeId = threadId;
        const frag = document.createDocumentFragment();
        items.forEach((item) => {
            if (!item || !item.id) {
                return;
            }
            const el = document.createElement('div');
            el.className = 'wa-mini-thread';
            el.setAttribute('data-thread-id', String(item.id));
            if (item.queue) {
                el.setAttribute('data-queue', item.queue);
            }
            const title = document.createElement('div');
            title.className = 'wa-mini-thread__title';
            title.textContent = item.contact_name || item.contact_phone || 'Contato';
            const subtitle = document.createElement('div');
            subtitle.className = 'wa-mini-thread__meta';
            const parts = [];
            if (item.line_label) {
                parts.push(item.line_label);
            }
            if (item.last_message_preview) {
                parts.push(item.last_message_preview);
            }
            subtitle.textContent = parts.join(' · ');

            const unread = document.createElement('div');
            unread.className = 'wa-mini-thread__unread';
            const unreadCount = Number(item.unread || 0);
            if (unreadCount > 0) {
                unread.textContent = `+${unreadCount}`;
            } else {
                unread.classList.add('is-zero');
            }

            el.appendChild(title);
            el.appendChild(subtitle);
            el.appendChild(unread);

            if (activeId && item.id === activeId) {
                el.classList.add('is-active');
            }

            el.addEventListener('click', () => {
                if (threadNavigationLocked) {
                    return;
                }
                threadNavigationLocked = true;
                setTimeout(() => {
                    threadNavigationLocked = false;
                }, 800);
                stopPanelRefreshLoop();
                pauseThreadPolling();

                const href = document.querySelector(`[data-thread-link="${item.id}"]`);
                if (href && href.getAttribute('href')) {
                    window.location.href = href.getAttribute('href');
                    return;
                }
                if (typeof options.onClick === 'function') {
                    options.onClick(item);
                }
            });

            frag.appendChild(el);
        });

        list.innerHTML = '';
        list.appendChild(frag);
        list.hidden = items.length === 0;
    }

    function updatePanelList(panelKey, panelData) {
        const list = document.querySelector(`[data-panel-list="${panelKey}"]`);
        const emptyState = document.querySelector(`[data-panel-empty="${panelKey}"]`);
        const countChip = document.querySelector(`[data-panel-count="${panelKey}"]`);
        const items = Array.isArray(panelData.items) ? panelData.items : null;

        if (list) {
            if (panelData.html) {
                renderPanelHtmlChunked(list, panelData.html, { batchSize: 4 });
            } else if (items && items.length > 0) {
                renderPanelItems(list, items, { batchSize: 1 });
            } else {
                list.innerHTML = '';
                list.hidden = true;
            }
        }

        if (emptyState) {
            const hasContent = items ? items.length > 0 : Boolean(panelData.html && panelData.html !== '');
            emptyState.hidden = hasContent;
            if (panelData.empty) {
                emptyState.textContent = panelData.empty;
            }
        }
        if (countChip && typeof panelData.count !== 'undefined') {
            countChip.textContent = `${panelData.count} contatos`;
        }
    }

    function renderPanelHtmlChunked(list, html, options = {}) {
        const batchSize = Number(options.batchSize || 8);
        const container = document.createElement('div');
        container.innerHTML = html;
        const nodes = Array.from(container.children);
        list.innerHTML = '';
        list.hidden = nodes.length === 0;

        let index = 0;
        const total = nodes.length;

        const step = () => {
            const frag = document.createDocumentFragment();
            for (let i = 0; i < batchSize && index < total; i += 1, index += 1) {
                frag.appendChild(nodes[index]);
            }
            list.appendChild(frag);
            if (index < total) {
                requestAnimationFrame(step);
            }
        };

        requestAnimationFrame(step);
    }

    function registerPanelMeta(panelKey, entries, options = {}) {
        if (!Array.isArray(entries)) {
            return;
        }
        const skipNotifications = Boolean(options.skipNotifications);
        entries.forEach((entry) => {
            if (!entry || typeof entry.id === 'undefined') {
                return;
            }
            const normalized = Object.assign({ panel: panelKey }, entry);
            const previous = threadSnapshots.get(normalized.id);
            threadSnapshots.set(normalized.id, normalized);
            if (!skipNotifications) {
                evaluatePanelNotification(normalized, previous);
            }
        });
    }

    function evaluatePanelNotification(meta, previous) {
        if (!meta || (threadId && meta.id === threadId) || (!notificationState.soundEnabled && !notificationState.popupEnabled)) {
            return;
        }
        if (meta.panel === 'concluidos' || meta.panel === 'grupos') {
            return;
        }
        const unreadNow = Number(meta.unread || 0);
        const unreadBefore = previous ? Number(previous.unread || 0) : 0;
        const hasNewUnread = unreadNow > unreadBefore;
        const isFirstSnapshot = !previous && unreadNow > 0;
        if (!hasNewUnread && !isFirstSnapshot) {
            return;
        }
        triggerNotification(meta, { source: 'panel' });
    }

    function startPanelRefreshLoop() {
        if (!panelRefreshEndpoint) {
            return;
        }
        stopPanelRefreshLoop();
        const interval = getPanelRefreshInterval();
        panelRefreshTimer = setInterval(() => {
            const hidden = document.hidden;
            refreshPanels({ skipNotifications: hidden });
        }, interval);
        refreshPanels({ skipNotifications: document.hidden });
    }

    function stopPanelRefreshLoop() {
        if (panelRefreshTimer) {
            clearInterval(panelRefreshTimer);
            panelRefreshTimer = null;
        }
        if (panelRefreshController) {
            try {
                panelRefreshController.abort();
            } catch (error) {
                // ignore abort errors
            }
            panelRefreshController = null;
        }
    }

    if (deferPanels) {
        refreshPanels({ skipNotifications: true });
    }

    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', () => loadOlderThreadMessages());
        setLoadMoreVisibility(Boolean(firstMessageId));
    }
    const runIdle = (fn) => {
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(fn, { timeout: 150 });
        } else {
            setTimeout(fn, 0);
        }
    };

    runIdle(() => {
        scrollMessagesToBottom();
        trimRenderedMessages(INITIAL_MESSAGE_RENDER_LIMIT, { initialOnly: true });
        if (threadId > 0) {
            hydrateThreadFromCache();
            clearActiveThreadUnread(0);
            scheduleThreadPolling();
        }
        startPanelRefreshLoop();
        prefetchVisibleThreads();
        runIdle(() => setupPrefetchObserver());
    });

    document.addEventListener('visibilitychange', () => {
        if (threadPollController) {
            try {
                threadPollController.abort();
            } catch (error) {
                // ignore abort errors
            }
            threadPollController = null;
        }
        if (panelRefreshController) {
            try {
                panelRefreshController.abort();
            } catch (error) {
                // ignore abort errors
            }
            panelRefreshController = null;
        }
        scheduleThreadPolling();
        startPanelRefreshLoop();
        if (!document.hidden) {
            runIdle(() => setupPrefetchObserver());
        }
    });

    function markUserActivity() {
        lastUserActivity = Date.now();
        if (activityReschedulePending) {
            return;
        }
        activityReschedulePending = true;
        setTimeout(() => {
            activityReschedulePending = false;
            scheduleThreadPolling();
            startPanelRefreshLoop();
        }, 300);
    }

    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((eventName) => {
        window.addEventListener(eventName, markUserActivity, { passive: true });
    });
    window.addEventListener('scroll', markUserActivity, { passive: true });
    window.addEventListener('focus', markUserActivity, true);

    function handleActiveThreadIncoming(message) {
        if (!threadId || (!notificationState.soundEnabled && !notificationState.popupEnabled)) {
            return;
        }
        if (!shouldNotifyActiveThread(threadId)) {
            return;
        }
        const meta = {
            id: threadId,
            panel: 'atendimento',
            name: activeThreadContext.name || 'Contato WhatsApp',
            phone: activeThreadContext.phone || '',
            line_label: activeThreadContext.line || '',
            line_chip: activeThreadContext.line || '',
            queue: activeThreadContext.queue || '',
            client_id: activeThreadContext.clientId || 0,
            is_group: activeThreadContext.isGroup,
            preview: extractMessagePreview(message),
            updated_at: Date.now(),
        };
        triggerNotification(meta, { source: 'active', isActiveThread: true });
    }

    function extractMessagePreview(message) {
        if (!message) {
            return 'Nova mensagem recebida.';
        }
        const body = typeof message.content === 'string' ? message.content.trim() : '';
        if (body !== '') {
            return body;
        }
        const metadata = normalizeMetadata(message.metadata);
        const mediaMeta = metadata.media || {};
        if (mediaMeta.caption) {
            return String(mediaMeta.caption);
        }
        if (mediaMeta.original_name) {
            return String(mediaMeta.original_name);
        }
        if (mediaMeta.type) {
            switch (mediaMeta.type) {
                case 'audio':
                    return 'Áudio recebido.';
                case 'image':
                    return 'Imagem recebida.';
                case 'video':
                    return 'Vídeo recebido.';
                default:
                    return 'Arquivo recebido.';
            }
        }
        return 'Nova mensagem recebida.';
    }

    function markAgentResponse(threadKey) {
        if (!threadKey) {
            return;
        }
        lastAgentResponseAt[threadKey] = Date.now();
    }

    function shouldNotifyActiveThread(threadKey) {
        const cooldownMinutes = Math.max(0, Number(notificationState.cooldownMinutes) || 0);
        if (cooldownMinutes === 0) {
            return true;
        }
        const cooldownMs = cooldownMinutes * 60000;
        const lastResponse = lastAgentResponseAt[threadKey] || pageLoadedAt;
        return Date.now() - lastResponse >= cooldownMs;
    }

    function renderPanelItems(list, items, options = {}) {
        if (!list || !Array.isArray(items)) {
            return;
        }
        const activeId = threadId;
        const batchSize = Number(options.batchSize || 1);
        list.innerHTML = '';
        list.hidden = items.length === 0;

        let index = 0;
        const total = items.length;

        const step = () => {
            const frag = document.createDocumentFragment();
            for (let i = 0; i < batchSize && index < total; i += 1, index += 1) {
                const item = items[index];
                if (!item || !item.id) {
                    continue;
                }
                const el = document.createElement('div');
                el.className = 'wa-mini-thread';
                el.setAttribute('data-thread-id', String(item.id));
                if (item.queue) {
                    el.setAttribute('data-queue', item.queue);
                }
                const title = document.createElement('div');
                title.className = 'wa-mini-thread__title';
                title.textContent = item.contact_name || item.contact_phone || 'Contato';
                const subtitle = document.createElement('div');
                subtitle.className = 'wa-mini-thread__meta';
                const parts = [];
                if (item.line_label) {
                    parts.push(item.line_label);
                }
                if (item.last_message_preview) {
                    parts.push(item.last_message_preview);
                }
                subtitle.textContent = parts.join(' · ');

                const unread = document.createElement('div');
                unread.className = 'wa-mini-thread__unread';
                const unreadCount = Number(item.unread || 0);
                if (unreadCount > 0) {
                    unread.textContent = `+${unreadCount}`;
                } else {
                    unread.classList.add('is-zero');
                }

                el.appendChild(title);
                el.appendChild(subtitle);
                el.appendChild(unread);

                if (activeId && item.id === activeId) {
                    el.classList.add('is-active');
                }

                el.addEventListener('click', () => {
                    if (threadNavigationLocked) {
                        return;
                    }
                    threadNavigationLocked = true;
                    setTimeout(() => {
                        threadNavigationLocked = false;
                    }, 800);
                    stopPanelRefreshLoop();
                    pauseThreadPolling();

                    const href = document.querySelector(`[data-thread-link="${item.id}"]`);
                    if (href && href.getAttribute('href')) {
                        window.location.href = href.getAttribute('href');
                    }
                });

                frag.appendChild(el);
            }

            list.appendChild(frag);
            if (index < total) {
                requestAnimationFrame(step);
            }
        };

        requestAnimationFrame(step);
    }
        if (!notificationState.audioCtx) {
            notificationState.audioCtx = new AudioContextCtor();
        }
        const ctx = notificationState.audioCtx;
        const oscillator = ctx.createOscillator();
        const gainNode = ctx.createGain();
        oscillator.type = 'triangle';
        oscillator.frequency.value = 880;
        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.9);
        oscillator.stop(ctx.currentTime + 0.9);
    }

    function tryPlayCustomAudio(dataUrl) {
        if (!dataUrl) {
            return;
        }
        if (activeCustomAudio) {
            activeCustomAudio.pause();
            activeCustomAudio.currentTime = 0;
        }
        const audio = new Audio(dataUrl);
        activeCustomAudio = audio;
        audio.currentTime = 0;
        const playPromise = audio.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {
                playFallbackTone();
            });
        }
    }

    function showNotificationToast(meta) {
        if (!toastStack || !meta) {
            return;
        }
        toastStack.hidden = false;
        const existing = toastStack.querySelector(`[data-toast-thread=\"${meta.id}\"]`);
        const toast = existing || document.createElement('article');
        toast.className = 'wa-toast';
        toast.dataset.toastThread = meta.id;
        toast.innerHTML = `
            <div class=\"wa-toast__header\">
                <div>
                    <small class=\"wa-toast__line\">${escapeHtml(meta.line_label || meta.line_chip || 'WhatsApp')}</small>
                    <strong class=\"wa-toast__title\">${escapeHtml(meta.name || 'Contato WhatsApp')}</strong>
                    <small class=\"wa-toast__line\">${escapeHtml(meta.phone || '')}</small>
                </div>
                <button type=\"button\" class=\"wa-toast__close\" aria-label=\"Fechar\" data-toast-close>&times;</button>
            </div>
            <p class=\"wa-toast__preview\">${escapeHtml((meta.preview || 'Nova mensagem recebida.').slice(0, 160))}</p>
        `;
        toast.querySelector('[data-toast-close]').addEventListener('click', (event) => {
            event.stopPropagation();
            closeToast(toast);
        });
        toast.addEventListener('click', () => {
            closeToast(toast);
            navigateToThread(meta.id);
        });
        toastStack.prepend(toast);
        if (toastTimers.has(toast)) {
            clearTimeout(toastTimers.get(toast));
        }
        const timer = setTimeout(() => closeToast(toast), 9000);
        toastTimers.set(toast, timer);
    }

    function renderGatewayOfflineToast() {
        if (!toastStack) {
            return;
        }
        const offline = Array.from(gatewayFleetStatus.values()).filter((entry) => entry.online === false);
        const existing = toastStack.querySelector('[data-toast-gateway]');
        if (offline.length === 0) {
            if (existing) {
                existing.remove();
            }
            if (!toastStack.children.length) {
                toastStack.hidden = true;
            }
            return;
        }

        const labels = offline.map((entry) => entry.label || entry.slug || 'Gateway').join(', ');
        const toast = existing || document.createElement('article');
        toast.className = 'wa-toast wa-toast--alert';
        toast.dataset.toastGateway = 'offline';
        toast.innerHTML = `
            <div class="wa-toast__header">
                <div>
                    <small class="wa-toast__line">Gateways</small>
                    <strong class="wa-toast__title">Gateway offline</strong>
                    <small class="wa-toast__line">${escapeHtml(labels)}</small>
                </div>
                <button type="button" class="wa-toast__close" aria-label="Fechar" data-toast-close>&times;</button>
            </div>
            <p class="wa-toast__preview">Verifique ou reinicie o(s) gateway(s) listado(s).</p>
        `;
        toast.querySelector('[data-toast-close]').addEventListener('click', (event) => {
            event.stopPropagation();
            toast.remove();
            if (!toastStack.children.length) {
                toastStack.hidden = true;
            }
        });
        if (!existing) {
            toastStack.prepend(toast);
        }
        toastStack.hidden = false;
    }

    function closeToast(toast) {
        if (!toastStack || !toast) {
            return;
        }
        if (toastTimers.has(toast)) {
            clearTimeout(toastTimers.get(toast));
            toastTimers.delete(toast);
        }
        toast.remove();
        if (!toastStack.children.length) {
            toastStack.hidden = true;
        }
    }

    function readBooleanPreference(key, fallback) {
        try {
            const stored = localStorage.getItem(key);
            if (stored === null) {
                return fallback;
            }
            return stored === '1';
        } catch (error) {
            return fallback;
        }
    }

    function readNumberPreference(key, fallback) {
        try {
            const stored = localStorage.getItem(key);
            if (stored === null || stored === '') {
                return fallback;
            }
            const parsed = parseFloat(stored);
            return Number.isFinite(parsed) ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function persistPreference(key, value) {
        try {
            localStorage.setItem(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
        } catch (error) {
            // ignore storage errors (private mode, etc.)
        }
    }

    function readStringPreference(key, fallback) {
        try {
            const stored = localStorage.getItem(key);
            if (stored === null || stored === '') {
                return fallback;
            }
            return stored;
        } catch (error) {
            return fallback;
        }
    }

    function navigateToThread(targetThreadId, panel) {
        if (!targetThreadId) {
            return;
        }
        let targetUrl = whatsappThreadUrlTemplate.replace('__THREAD__', encodeURIComponent(targetThreadId));
        if (panel) {
            const separator = targetUrl.includes('?') ? '&' : '?';
            targetUrl += `${separator}panel=${encodeURIComponent(panel)}`;
        }
        window.location.href = targetUrl;
    }

    if (queueSelect) {
        toggleQueueExtras(queueSelect.value || 'arrival');
        queueSelect.addEventListener('change', () => toggleQueueExtras(queueSelect.value || 'arrival'));
    } else {
        toggleQueueExtras('arrival');
    }

    tabLinks.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }
            event.preventDefault();
            const targetPanel = tab.dataset.tabTarget;
            activatePanel(targetPanel);
            const href = tab.getAttribute('href');
            if (href && window.history && window.history.replaceState) {
                try {
                    const url = new URL(href, window.location.origin);
                    window.history.replaceState({}, '', url);
                } catch (error) {
                    window.history.replaceState({}, '', href);
                }
            }
        });
    });

    templateButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const kind = button.getAttribute('data-template-open');
            if (!templateSelectionAllowed(kind)) {
                if (messageFeedback) {
                    messageFeedback.textContent = 'Disponível apenas nas linhas conectadas ao gateway alternativo.';
                }
                return;
            }
            openTemplateModal(kind);
        });
    });

    templateItemButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const kind = button.getAttribute('data-template-kind');
            const key = button.getAttribute('data-template-key');
            applyTemplateSelection(kind, key);
            const modal = button.closest('[data-template-modal]');
            if (modal) {
                closeTemplateModal(modal);
            }
        });
    });

    templateCloseButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const modal = button.closest('[data-template-modal]');
            if (modal) {
                closeTemplateModal(modal);
            }
        });
    });

    if (templateClearButton) {
        templateClearButton.addEventListener('click', (event) => {
            event.preventDefault();
            clearTemplateSelection();
        });
    }

    if (broadcastKindField) {
        broadcastKindField.addEventListener('change', () => {
            populateBroadcastTemplates();
            updateBroadcastTemplatePreviewUi();
        });
    }

    if (broadcastKeyField) {
        broadcastKeyField.addEventListener('change', () => {
            updateBroadcastTemplatePreviewUi();
        });
    }

    if (broadcastTemplateClear) {
        broadcastTemplateClear.addEventListener('click', (event) => {
            event.preventDefault();
            clearBroadcastTemplateSelectionUi();
        });
    }

    if (messageForm) {
        if (messageTextarea) {
            messageTextarea.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    messageForm.requestSubmit();
                }
            });
        }

        messageForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(messageForm);
            const messageText = String(formData.get('message') || '').trim();
            const hasInputMedia = messageMediaInput && messageMediaInput.files && messageMediaInput.files.length > 0;
            const hasRecordedMedia = Boolean(recordedAudioFallback);
            const hasTemplate = Boolean(templateKindField && templateKindField.value && templateKeyField && templateKeyField.value);
            if (!hasInputMedia && hasRecordedMedia) {
                formData.delete('media');
                formData.append('media', recordedAudioFallback, recordedAudioFallback.name);
            }
            const hasMedia = hasInputMedia || hasRecordedMedia;
            if (!hasTemplate) {
                formData.delete('template_kind');
                formData.delete('template_key');
            }
            if (!hasMedia && !hasTemplate && messageText === '') {
                if (messageFeedback) {
                    messageFeedback.textContent = 'Escreva uma mensagem ou selecione um arquivo antes de enviar.';
                }
                return;
            }
            if (messageFeedback) {
                messageFeedback.textContent = 'Enviando...';
            }
            try {
                const data = await postMultipart('<?= url('whatsapp/send-message'); ?>', messageForm, formData);
                if (messageFeedback) {
                    messageFeedback.textContent = data.status
                        ? 'Mensagem enviada (' + data.status + ').'
                        : 'Mensagem enviada.';
                }
                if (data.message) {
                    appendMessageBubble(data.message);
                } else {
                    appendMessageBubble({
                        direction: 'outgoing',
                        content: messageText,
                        sentAt: Date.now(),
                        status: data.status || 'sent',
                        metadata: {
                            actor_name: 'Você',
                        },
                    });
                }
                messageForm.reset();
                clearTemplateSelection();
                recordedAudioFallback = null;
                resetAudioRecorderControls();
                updateAudioStatus('');
                updateComposerMediaPreview();
                scrollMessagesToBottom();
                markAgentResponse(threadId);
            } catch (error) {
                if (messageFeedback) {
                    messageFeedback.textContent = error.message;
                }
            }
        });

        if (messageMediaInput) {
            messageMediaInput.addEventListener('change', () => {
                recordedAudioFallback = null;
                clearTemplateSelection();
                if (audioRecorder) {
                    stopAudioRecording(true);
                }
                resetAudioRecorderControls();
                updateAudioStatus(messageMediaInput.files && messageMediaInput.files.length > 0 ? 'Anexo selecionado.' : '');
                updateComposerMediaPreview();
            });
        }
        if (messageMediaClear) {
            messageMediaClear.addEventListener('click', () => {
                if (messageMediaInput) {
                    messageMediaInput.value = '';
                }
                recordedAudioFallback = null;
                updateComposerMediaPreview();
                releaseAudioResources();
                resetAudioRecorderControls();
                updateAudioStatus('');
            });
        }
        if (audioRecordButton) {
            audioRecordButton.addEventListener('click', async (event) => {
                event.preventDefault();
                if (audioRecorder) {
                    stopAudioRecording(false);
                } else {
                    await startAudioRecording();
                }
            });
        }
        if (audioRecordCancel) {
            audioRecordCancel.addEventListener('click', (event) => {
                event.preventDefault();
                if (audioRecorder) {
                    stopAudioRecording(true);
                    return;
                }
                if (messageMediaInput) {
                    messageMediaInput.value = '';
                }
                recordedAudioFallback = null;
                updateComposerMediaPreview();
                resetAudioRecorderControls();
                updateAudioStatus('Áudio removido.');
            });
        }
        updateComposerMediaPreview();
    }

    async function requestAiSuggestion(options = {}) {
        if (!aiOutput) {
            return;
        }
        aiOutput.textContent = 'Gerando sugestão...';
        try {
            const payload = {
                contact_name: <?= json_encode((string)($activeContact['name'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
                last_message: <?= json_encode($latestMessageText, JSON_UNESCAPED_UNICODE); ?>,
                tone: options.tone || 'consultivo',
                goal: options.goal || 'avancar',
                thread_id: <?= (int)$activeThreadId; ?>,
                profile_id: copilotProfileSelect ? copilotProfileSelect.value : '',
            };
            const data = await postForm('<?= url('whatsapp/copilot-suggestion'); ?>', payload);
            aiOutput.textContent = data.suggestion + '\n\nSentimento: ' + data.sentiment + ' • Confiança: ' + Math.round((data.confidence || 0) * 100) + '%';
        } catch (error) {
            aiOutput.textContent = error.message;
        }
    }

    if (aiFillButton) {
        aiFillButton.addEventListener('click', (event) => {
            event.preventDefault();
            requestAiSuggestion();
        });
    }

    function isUserIdle() {
        return (Date.now() - lastUserActivity) > IDLE_THRESHOLD_MS;
    }

    function getThreadPollInterval() {
        if (document.hidden) {
            return THREAD_POLL_BACKGROUND_INTERVAL;
        }
        if (isUserIdle()) {
            return THREAD_POLL_IDLE_INTERVAL;
        }
        return THREAD_POLL_INTERVAL;
    }

    function getPanelRefreshInterval() {
        if (document.hidden) {
            return PANEL_REFRESH_BACKGROUND_INTERVAL;
        }
        if (isUserIdle()) {
            return PANEL_REFRESH_IDLE_INTERVAL;
        }
        return PANEL_REFRESH_INTERVAL;
    }

    aiGoalButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            requestAiSuggestion({ tone: button.dataset.tone, goal: button.dataset.goal });
        });
    });

    function openContextPanel() {
        if (!contextPane) {
            return;
        }
        contextPane.classList.add('is-visible');
        if (contextOverlay) {
            contextOverlay.classList.add('is-visible');
        }
    }

    function closeContextPanel() {
        if (!contextPane) {
            return;
        }
        contextPane.classList.remove('is-visible');
        if (contextOverlay) {
            contextOverlay.classList.remove('is-visible');
        }
    }

    function setContextDetailsVisibility(visible) {
        if (!contextDetails) {
            return;
        }
        contextDetailsVisible = Boolean(visible);
        if (contextDetailsVisible) {
            contextDetails.removeAttribute('hidden');
            if (contextPane && contextPane.dataset.collapsed === 'true') {
                openContextPanel();
            }
        } else {
            contextDetails.setAttribute('hidden', 'hidden');
        }
        if (contextDetailsToggle) {
            contextDetailsToggle.textContent = contextDetailsVisible ? 'Menos' : 'Mais';
        }
    }

    function setContextDetailsVisibility(visible) {
        if (!contextDetails) {
            return;
        }
        contextDetailsVisible = Boolean(visible);
        if (contextDetailsVisible) {
            contextDetails.removeAttribute('hidden');
            if (contextPane && contextPane.dataset.collapsed === 'true') {
                openContextPanel();
            }
        } else {
            contextDetails.setAttribute('hidden', 'hidden');
        }
        if (contextDetailsToggle) {
            contextDetailsToggle.textContent = contextDetailsVisible ? 'Menos' : 'Mais';
        }
    }

    if (contextToggle) {
        contextToggle.addEventListener('click', (event) => {
            event.preventDefault();
            openContextPanel();
        });
    }

    if (contextClose) {
        contextClose.addEventListener('click', (event) => {
            event.preventDefault();
            closeContextPanel();
        });
    }

    if (contextOverlay) {
        contextOverlay.addEventListener('click', closeContextPanel);
    }

    if (contextDetailsToggle && contextDetails) {
        contextDetailsToggle.addEventListener('click', (event) => {
            event.preventDefault();
            setContextDetailsVisibility(!contextDetailsVisible);
        });
    }

    newThreadToggle.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            openNewThreadModal();
        });
    });

    newThreadCloseButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            closeNewThreadModal();
        });
    });

    if (contextDetailsToggle && contextDetails) {
        contextDetailsToggle.addEventListener('click', (event) => {
            event.preventDefault();
            setContextDetailsVisibility(!contextDetailsVisible);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeContextPanel();
            closeClientPopover();
            closeNewThreadModal();
        }
    });

    async function assignThread(userId) {
        if (!threadId) {
            return;
        }
        await postForm('<?= url('whatsapp/assign-thread'); ?>', {
            thread_id: threadId,
            user_id: userId,
        });
        window.location.reload();
    }

    async function updateStatus(status) {
        if (!threadId) {
            return;
        }
        await postForm('<?= url('whatsapp/thread-status'); ?>', {
            thread_id: threadId,
            status,
        });
        window.location.reload();
    }

    assignSelfButtons.forEach((button) => {
        button.addEventListener('click', () => assignThread('self'));
    });

    releaseButtons.forEach((button) => {
        button.addEventListener('click', () => assignThread(''));
    });

    transferButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            openContextPanel();
            const assignForm = document.getElementById('wa-assign-user');
            if (assignForm) {
                const select = assignForm.querySelector('select[name="user_id"]');
                if (select) {
                    select.focus();
                }
            }
        });
    });

    document.addEventListener('click', async (event) => {
        const claimTarget = event.target.closest('[data-claim-thread]');
        if (claimTarget) {
            event.preventDefault();
            event.stopPropagation();
            const threadTarget = claimTarget.getAttribute('data-claim-thread');
            if (!threadTarget) {
                return;
            }
            claimTarget.disabled = true;
            try {
                await postForm('<?= url('whatsapp/assign-thread'); ?>', { thread_id: threadTarget, user_id: 'self' });
                await postForm('<?= url('whatsapp/thread-status'); ?>', { thread_id: threadTarget, status: 'open' });
                navigateToThread(threadTarget);
            } catch (error) {
                alert(error.message || 'Não foi possível assumir a conversa.');
            } finally {
                claimTarget.disabled = false;
            }
            return;
        }

        const reopenTarget = event.target.closest('[data-reopen-thread]');
        if (reopenTarget) {
            event.preventDefault();
            event.stopPropagation();
            const threadTarget = reopenTarget.getAttribute('data-reopen-thread');
            if (!threadTarget) {
                return;
            }
            reopenTarget.disabled = true;
            try {
                await postForm('<?= url('whatsapp/thread-status'); ?>', {
                    thread_id: threadTarget,
                    status: 'open',
                });
                await postForm('<?= url('whatsapp/assign-thread'); ?>', {
                    thread_id: threadTarget,
                    user_id: 'self',
                });
                await postForm('<?= url('whatsapp/thread-queue'); ?>', {
                    thread_id: threadTarget,
                    queue: 'arrival',
                });
                navigateToThread(threadTarget, 'atendimento');
            } catch (error) {
                alert(error.message || 'Não foi possível reabrir a conversa.');
            } finally {
                reopenTarget.disabled = false;
            }
        }
    });

    statusButtons.forEach((button) => {
        button.addEventListener('click', () => updateStatus(button.dataset.status));
    });

    if (assignUserForm) {
        assignUserForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const select = assignUserForm.querySelector('select[name="user_id"]');
            await assignThread(select.value);
        });
    }

    if (queueForm) {
        queueForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (queueFeedback) {
                queueFeedback.textContent = 'Salvando...';
            }
            const formData = new FormData(queueForm);
            try {
                await postForm('<?= url('whatsapp/thread-queue'); ?>', Object.fromEntries(formData.entries()));
                if (queueFeedback) {
                    queueFeedback.textContent = 'Fila atualizada.';
                }
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                if (queueFeedback) {
                    queueFeedback.textContent = error.message;
                }
            }
        });
    }

    if (moveQueueForm && moveQueueSelect) {
        moveQueueForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const queueValue = moveQueueSelect.value;
            if (!queueValue) {
                if (moveQueueFeedback) {
                    moveQueueFeedback.textContent = 'Escolha uma fila.';
                }
                return;
            }
            if (moveQueueFeedback) {
                moveQueueFeedback.textContent = 'Movendo...';
            }
            if (moveQueueSubmit) {
                moveQueueSubmit.disabled = true;
            }
            const formData = new FormData(moveQueueForm);
            try {
                await postForm('<?= url('whatsapp/thread-queue'); ?>', Object.fromEntries(formData.entries()));
                if (moveQueueFeedback) {
                    moveQueueFeedback.textContent = 'Fila atualizada.';
                }
                const targetOption = moveQueueSelect.options[moveQueueSelect.selectedIndex];
                const panelHint = targetOption ? (targetOption.getAttribute('data-panel') || '') : '';
                setTimeout(() => {
                    const url = new URL(window.location.href);
                    if (panelHint) {
                        url.searchParams.set('panel', panelHint);
                    }
                    const threadParam = url.searchParams.get('thread') || (threadId ? String(threadId) : '');
                    if (threadParam === '' && threadId) {
                        url.searchParams.set('thread', String(threadId));
                    }
                    window.location.href = url.toString();
                }, 300);
            } catch (error) {
                if (moveQueueFeedback) {
                    moveQueueFeedback.textContent = error.message || 'Erro ao mover.';
                }
            } finally {
                if (moveQueueSubmit) {
                    moveQueueSubmit.disabled = false;
                }
            }
        });
    }

    if (queueQuickButtons.length > 0 && queueSelect) {
        queueQuickButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const targetQueue = button.getAttribute('data-queue-quick');
                if (!targetQueue || !queueSelect) {
                    return;
                }
                queueSelect.value = targetQueue;
                toggleQueueExtras(targetQueue);
                if (targetQueue === 'scheduled') {
                    const scheduledInput = queueForm ? queueForm.querySelector('input[name="scheduled_for"]') : null;
                    if (scheduledInput && !scheduledInput.value) {
                        const defaultDate = new Date(Date.now() + 3600 * 1000);
                        scheduledInput.value = formatLocalDateTimeInput(defaultDate);
                    }
                    submitQueueForm();
                    return;
                }
                submitQueueForm();
            });
        });
    }

    if (preTriageButton) {
        preTriageButton.addEventListener('click', async () => {
            if (!threadId) {
                return;
            }
            preTriageButton.disabled = true;
            if (queueFeedback) {
                queueFeedback.textContent = 'Executando pré-triagem...';
            }
            try {
                const data = await postForm('<?= url('whatsapp/pre-triage'); ?>', { thread_id: threadId });
                if (intakeSummaryField && data.intake_summary) {
                    intakeSummaryField.value = data.intake_summary;
                }
                if (aiOutput && data.copilot && data.copilot.suggestion) {
                    aiOutput.textContent = data.copilot.suggestion + '\n\nConfiança: ' + Math.round((data.copilot.confidence || 0) * 100) + '%';
                }
                if (queueFeedback) {
                    queueFeedback.textContent = 'Pré-triagem registrada.';
                }
            } catch (error) {
                if (queueFeedback) {
                    queueFeedback.textContent = error.message;
                }
            } finally {
                preTriageButton.disabled = false;
            }
        });
    }

    let openRegisterContactFromTrigger = null;

    if (registerContactModal && registerContactForm) {
        registerContactCloseButtons.forEach((btn) => btn.addEventListener('click', closeRegisterContactModal));

        const preloadRegisterForm = (trigger) => {
            if (!registerContactForm) {
                return;
            }
            const dataset = trigger ? trigger.dataset : {};
            if (registerContactThreadId) {
                registerContactThreadId.value = dataset.threadId || String(threadId || '');
            }
            if (registerContactContactId) {
                registerContactContactId.value = dataset.contactId || registerContactContactId.value || '';
            }
            if (registerContactName) {
                registerContactName.value = dataset.contactName || activeThreadContext.name || '';
            }
            if (registerContactPhone) {
                registerContactPhone.value = dataset.suggestPhone || dataset.contactPhone || activeThreadContext.phone || '';
            }
            if (registerContactCpf) {
                registerContactCpf.value = dataset.suggestCpf || '';
            }
            if (registerContactBirthdate) {
                registerContactBirthdate.value = '';
            }
            if (registerContactEmail) {
                registerContactEmail.value = '';
            }
            if (registerContactAddress) {
                registerContactAddress.value = '';
            }
            if (registerContactClientId) {
                registerContactClientId.value = dataset.clientId || '';
            }
            if (registerContactFeedback) {
                registerContactFeedback.textContent = '';
            }
            if (registerClientSearchInput) {
                registerClientSearchInput.value = '';
            }
            if (registerClientSearchResults) {
                registerClientSearchResults.innerHTML = '';
                registerClientSearchResults.style.display = 'none';
            }
            if (registerClientSearchFeedback) {
                registerClientSearchFeedback.textContent = '';
            }
        };

        openRegisterContactFromTrigger = (trigger) => {
            registerContactTrigger = trigger || null;
            preloadRegisterForm(trigger || registerContactTrigger);
            openRegisterContactModal();
        };

        const registerClientSearchEndpoint = '<?= url('crm/clients/quick-search'); ?>';

        const applyRegisterClientSelection = (item) => {
            const phoneCandidate = normalizePhone(
                item.whatsapp
                || item.phone
                || (Array.isArray(item.extra_phones) && item.extra_phones.length ? item.extra_phones[0] : '')
                || ''
            );
            const cpfCandidate = normalizePhone(
                item.titular_document
                || (item.document && String(item.document).length === 11 ? item.document : '')
                || ''
            );

            if (registerContactName && item.name) {
                registerContactName.value = item.name;
            }
            if (registerContactPhone && phoneCandidate) {
                registerContactPhone.value = phoneCandidate;
            }
            if (registerContactCpf && cpfCandidate) {
                registerContactCpf.value = cpfCandidate;
            }
            if (registerContactClientId) {
                registerContactClientId.value = item.id ? String(item.id) : '';
            }
            if (registerContactFeedback) {
                registerContactFeedback.textContent = 'Cliente carregado do CRM.';
            }
            if (registerClientSearchResults) {
                registerClientSearchResults.style.display = 'none';
            }
        };

        const renderRegisterClientResults = (items) => {
            if (!registerClientSearchResults) {
                return;
            }
            registerClientSearchResults.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                registerClientSearchResults.style.display = 'none';
                return;
            }

            const list = document.createElement('ul');
            list.style.listStyle = 'none';
            list.style.margin = '0';
            list.style.padding = '0';

            items.forEach((item, index) => {
                const li = document.createElement('li');
                li.style.borderBottom = index === items.length - 1 ? 'none' : '1px solid var(--border)';
                li.style.padding = '6px';

                const button = document.createElement('button');
                button.type = 'button';
                button.style.width = '100%';
                button.style.textAlign = 'left';
                button.style.border = 'none';
                button.style.background = 'transparent';
                button.style.color = 'var(--text)';
                button.style.cursor = 'pointer';

                const docLabel = item.document_formatted
                    || item.document
                    || item.titular_document_formatted
                    || item.titular_document
                    || '';
                const phoneCandidate = normalizePhone(
                    item.whatsapp
                    || item.phone
                    || (Array.isArray(item.extra_phones) && item.extra_phones.length ? item.extra_phones[0] : '')
                    || ''
                );
                const phoneLabel = phoneCandidate ? formatPhone(phoneCandidate) : '';

                button.textContent = [item.name || 'Cliente', docLabel, phoneLabel].filter(Boolean).join(' • ');
                button.addEventListener('click', () => applyRegisterClientSelection(item));

                li.appendChild(button);
                list.appendChild(li);
            });

            registerClientSearchResults.appendChild(list);
            registerClientSearchResults.style.display = 'block';
        };

        const performRegisterClientSearch = async () => {
            if (!registerClientSearchInput) {
                return;
            }
            const query = registerClientSearchInput.value.trim();
            if (query.length < 2) {
                if (registerClientSearchFeedback) {
                    registerClientSearchFeedback.textContent = 'Digite pelo menos 2 caracteres para buscar.';
                }
                renderRegisterClientResults([]);
                return;
            }

            if (registerClientSearchFeedback) {
                registerClientSearchFeedback.textContent = 'Buscando...';
            }
            renderRegisterClientResults([]);

            try {
                const url = new URL(registerClientSearchEndpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '8');

                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.error || 'Não foi possível buscar clientes.');
                }
                const items = Array.isArray(data.items) ? data.items : [];
                renderRegisterClientResults(items);
                if (registerClientSearchFeedback) {
                    registerClientSearchFeedback.textContent = items.length > 0
                        ? `${items.length} resultado(s) encontrado(s).`
                        : 'Nenhum cliente encontrado.';
                }
            } catch (error) {
                if (registerClientSearchFeedback) {
                    registerClientSearchFeedback.textContent = error.message || 'Falha ao buscar clientes.';
                }
                renderRegisterClientResults([]);
            }
        };

        async function lookupClientByCpf() {
            if (!registerContactCpf) {
                return;
            }
            const cpfDigits = normalizePhone(registerContactCpf.value);
            if (cpfDigits.length < 11) {
                if (registerContactClientId) {
                    registerContactClientId.value = '';
                }
                return;
            }
            if (registerContactFeedback) {
                registerContactFeedback.textContent = 'Consultando CRM...';
            }
            try {
                const data = await postForm('<?= url('crm/clients/check'); ?>', { document: cpfDigits });
                if (registerContactFeedback) {
                    registerContactFeedback.textContent = data.message || (data.found ? 'Cliente encontrado no CRM.' : 'CPF não cadastrado.');
                }
                if (data.found && data.client) {
                    if (registerContactName && !registerContactName.value) {
                        registerContactName.value = data.client.name || registerContactName.value;
                    }
                    if (registerContactClientId) {
                        registerContactClientId.value = data.client.id || '';
                    }
                } else if (registerContactClientId) {
                    registerContactClientId.value = '';
                }
            } catch (error) {
                if (registerContactFeedback) {
                    registerContactFeedback.textContent = error.message || 'Falha ao consultar o CRM.';
                }
            }
        }

        if (registerContactCpf) {
            registerContactCpf.addEventListener('blur', lookupClientByCpf);
        }

        if (registerClientSearchButton) {
            registerClientSearchButton.addEventListener('click', performRegisterClientSearch);
        }
        if (registerClientSearchInput) {
            registerClientSearchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    performRegisterClientSearch();
                }
            });
            registerClientSearchInput.addEventListener('input', () => {
                if (registerClientSearchFeedback) {
                    registerClientSearchFeedback.textContent = '';
                }
                renderRegisterClientResults([]);
            });
        }

        registerContactForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (registerContactFeedback) {
                registerContactFeedback.textContent = 'Salvando...';
            }

            const formData = new FormData(registerContactForm);
            try {
                const data = await postForm('<?= url('whatsapp/register-contact'); ?>', Object.fromEntries(formData.entries()));
                const contact = data.contact || {};
                const updatedName = contact.name || (registerContactName ? registerContactName.value : activeThreadContext.name);
                const updatedPhone = contact.phone || (registerContactPhone ? registerContactPhone.value : activeThreadContext.phone);
                const targetThreadId = registerContactThreadId ? parseInt(registerContactThreadId.value || '0', 10) || 0 : threadId;

                if (targetThreadId === threadId) {
                    activeThreadContext.name = updatedName;
                    activeThreadContext.phone = updatedPhone;
                    activeContactDigits = normalizePhone(updatedPhone);

                    if (editContactButton) {
                        editContactButton.dataset.contactName = updatedName;
                        editContactButton.dataset.contactPhone = updatedPhone;
                    }

                    const nameNode = document.querySelector('.wa-contact-identity h3');
                    if (nameNode) {
                        nameNode.textContent = updatedName;
                    }

                    const identity = document.querySelector('.wa-contact-identity');
                    let phoneNode = contactPhoneDisplay || (identity ? identity.querySelector('[data-contact-phone-display]') : null);
                    if (!phoneNode && identity) {
                        phoneNode = document.createElement('p');
                        phoneNode.className = 'wa-contact-phone';
                        phoneNode.dataset.contactPhoneDisplay = '';
                        identity.appendChild(phoneNode);
                    }
                    if (phoneNode) {
                        phoneNode.textContent = formatPhone(updatedPhone);
                        phoneNode.hidden = false;
                    }
                }

                if (registerContactTrigger && registerContactTrigger.dataset.hideOnSuccess === '1') {
                    registerContactTrigger.hidden = true;
                }

                if (registerContactFeedback) {
                    registerContactFeedback.textContent = 'Contato atualizado.';
                }

                if (targetThreadId === threadId) {
                    setBlockedState(blockedNumbersSet.has(activeContactDigits));
                }
                await refreshPanels();
                closeRegisterContactModal();
            } catch (error) {
                if (registerContactFeedback) {
                    registerContactFeedback.textContent = error.message || 'Falha ao salvar contato.';
                }
            }
        });
    }

    if (blockContactButton) {
        setBlockedState(activeContactDigits !== '' && blockedNumbersSet.has(activeContactDigits));

        blockContactButton.addEventListener('click', async () => {
            const contactId = Number(blockContactButton.dataset.contactId || '0');
            if (!contactId) {
                return;
            }

            const currentlyBlocked = blockContactButton.dataset.blocked === '1';
            const nextBlock = !currentlyBlocked;

            blockContactButton.disabled = true;
            blockContactButton.textContent = nextBlock ? 'Bloqueando...' : 'Desbloqueando...';

            try {
                const data = await postForm('<?= url('whatsapp/block-contact'); ?>', {
                    contact_id: contactId,
                    block: nextBlock ? 1 : 0,
                });

                const updatedList = Array.isArray(data.blocked_numbers) ? data.blocked_numbers : Array.from(blockedNumbersSet);
                if (data && data.contact && data.contact.phone) {
                    activeThreadContext.phone = data.contact.phone;
                    activeContactDigits = normalizePhone(data.contact.phone);
                }
                const blockedNow = !!(data && data.blocked);
                setBlockedState(blockedNow, updatedList);
            } catch (error) {
                alert(error.message || 'Falha ao atualizar bloqueio.');
                setBlockedState(blockedNumbersSet.has(activeContactDigits));
            }
        });
    }

    if (editContactButton) {
        editContactButton.addEventListener('click', async () => {
            const contactId = Number(editContactButton.dataset.contactId || '0');
            if (!contactId) {
                return;
            }

            const currentName = editContactButton.dataset.contactName || activeThreadContext.name || '';
            const currentPhone = editContactButton.dataset.contactPhone || activeThreadContext.phone || '';

            const nextName = window.prompt('Nome do contato', currentName);
            if (nextName === null) {
                return;
            }
            const nextPhone = window.prompt('Telefone (somente números)', currentPhone);
            if (nextPhone === null) {
                return;
            }

            const normalizedPhone = normalizePhone(nextPhone);
            if (!normalizedPhone || normalizedPhone.length < 8) {
                alert('Informe um telefone válido.');
                return;
            }

            editContactButton.disabled = true;
            try {
                const data = await postForm('<?= url('whatsapp/update-contact-phone'); ?>', {
                    contact_id: contactId,
                    contact_name: nextName || '',
                    contact_phone: normalizedPhone,
                });

                const contact = data.contact || {};
                const updatedPhone = contact.phone || normalizedPhone;
                const updatedName = contact.name || nextName || 'Contato WhatsApp';

                activeContactDigits = normalizePhone(updatedPhone);

                editContactButton.dataset.contactName = updatedName;
                editContactButton.dataset.contactPhone = updatedPhone;

                activeThreadContext.phone = updatedPhone;
                activeThreadContext.name = updatedName;

                const nameNode = document.querySelector('.wa-contact-identity h3');
                if (nameNode) {
                    nameNode.textContent = updatedName;
                }

                const identity = document.querySelector('.wa-contact-identity');
                let phoneNode = contactPhoneDisplay || (identity ? identity.querySelector('[data-contact-phone-display]') : null);
                if (!phoneNode && identity) {
                    phoneNode = document.createElement('p');
                    phoneNode.className = 'wa-contact-phone';
                    phoneNode.dataset.contactPhoneDisplay = '';
                    identity.appendChild(phoneNode);
                }
                if (phoneNode) {
                    phoneNode.textContent = formatPhone(updatedPhone);
                    phoneNode.hidden = false;
                }

                setBlockedState(blockedNumbersSet.has(activeContactDigits));

                await refreshPanels();
            } catch (error) {
                alert(error.message || 'Falha ao atualizar contato.');
            } finally {
                editContactButton.disabled = false;
            }
        });
    }

    if (tagsForm) {
        tagsForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (tagsFeedback) {
                tagsFeedback.textContent = 'Salvando...';
            }
            const formData = new FormData(tagsForm);
            try {
                await postForm('<?= url('whatsapp/contact-tags'); ?>', Object.fromEntries(formData.entries()));
                if (tagsFeedback) {
                    tagsFeedback.textContent = 'Tags atualizadas.';
                }
            } catch (error) {
                if (tagsFeedback) {
                    tagsFeedback.textContent = error.message;
                }
            }
        });
    }

    if (noteForm) {
        noteForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (noteFeedback) {
                noteFeedback.textContent = 'Registrando...';
            }
            const formData = new FormData(noteForm);
            const mentionSelect = noteForm.querySelector('select[name="mentions[]"]');
            const mentions = mentionSelect ? Array.from(mentionSelect.selectedOptions).map((option) => option.value) : [];
            try {
                await postForm('<?= url('whatsapp/internal-note'); ?>', {
                    thread_id: formData.get('thread_id'),
                    note: formData.get('note'),
                    'mentions[]': mentions,
                });
                if (noteFeedback) {
                    noteFeedback.textContent = 'Nota salva nos bastidores.';
                }
                noteForm.reset();
            } catch (error) {
                if (noteFeedback) {
                    noteFeedback.textContent = error.message;
                }
            }
        });
    }


    function buildGatewayUrl(path, slug) {
        const separator = path.includes('?') ? '&' : '?';
        return `${path}${separator}instance=${encodeURIComponent(slug)}`;
    }

    function postGatewayAction(state, url, payload = {}) {
        return postForm(url, Object.assign({ instance: state.slug }, payload));
    }

    async function refreshGatewayStatus(state) {
        const { elements } = state;
        if (!elements.status) {
            return;
        }
        elements.status.textContent = 'Consultando...';
        try {
            const response = await fetch(buildGatewayUrl(gatewayEndpoints.status, state.slug));
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.error || 'Falha ao consultar gateway');
            }
            const gateway = data.gateway || {};
            const metrics = gateway.metrics || {};
            const normalized = String(gateway.status || '').toLowerCase();
            const translatedStatus = translateGatewayStatus(gateway.status || 'Gateway operacional');
            const isReady = Boolean(gateway.ready)
                || normalized.includes('connected')
                || normalized.includes('ready')
                || normalized.includes('online')
                || normalized.includes('open');
            state.isOnline = isReady;
            gatewayFleetStatus.set(state.slug, { slug: state.slug, label: state.label || state.slug, online: isReady });
            if (elements.start) {
                // Mantém o botão de start sempre como iniciar (paramos via botão dedicado).
                elements.start.textContent = 'Iniciar gateway';
                elements.start.dataset.gatewayAction = 'start';
                elements.start.classList.remove('is-stop');
                elements.start.classList.remove('is-online');
                elements.start.classList.add('is-offline');
            }
            elements.status.textContent = translatedStatus;
            if (elements.session) {
                elements.session.textContent = gateway.session || '-';
            }
            if (elements.incoming) {
                elements.incoming.textContent = metrics.lastIncomingAt
                    ? formatRelativeTime(metrics.lastIncomingAt)
                    : '-';
            }
            if (elements.heartbeat) {
                const heartbeatRef = metrics.lastDispatchAt || gateway.lastStatusAt || null;
                elements.heartbeat.textContent = heartbeatRef
                    ? formatRelativeTime(heartbeatRef)
                    : '-';
            }
            if (elements.history) {
                elements.history.textContent = describeHistoryStatus(gateway.history || {}, metrics);
            }
            if (elements.feedback) {
                if (metrics.lastError) {
                    elements.feedback.textContent = 'Ultimo erro: ' + metrics.lastError;
                } else if (metrics.reconnectReason) {
                    elements.feedback.textContent = 'Monitorando: ' + metrics.reconnectReason;
                } else {
                    elements.feedback.textContent = '';
                }
            }
            if (state.kind === 'line') {
                const historyRequest = getFullHistoryRequest(state.slug);
                if (historyRequest && historyRequest.status === 'awaiting_ready' && isReady) {
                    startFullHistoryImport(state);
                }
                if (elements.status) {
                    if (historyRequest) {
                        elements.status.textContent = describeFullHistoryStatus(historyRequest, translatedStatus, isReady);
                    } else {
                        elements.status.textContent = isReady ? 'Gateway ativo' : translatedStatus;
                    }
                }
            }
            if (state.qrVisible && normalized.indexOf('qr') === -1) {
                hideGatewayQr(state, true);
            }
            renderGatewayOfflineToast();
        } catch (error) {
            if (elements.status) {
                elements.status.textContent = error.message || 'Erro ao consultar gateway';
            }
            if (elements.feedback) {
                elements.feedback.textContent = error.message || 'Falha ao consultar gateway';
            }
            if (state.kind === 'line' && elements.start) {
                elements.start.classList.remove('is-online');
                elements.start.classList.add('is-offline');
            }
            gatewayFleetStatus.set(state.slug, { slug: state.slug, label: state.label || state.slug, online: false });
            renderGatewayOfflineToast();
        }
    }

    async function toggleGatewayProcess(state, options = {}) {
        const { elements } = state;
        if (!elements || !elements.start) {
            return;
        }
        const button = elements.start;
        const wantsStop = Boolean(state.isOnline);
        const actionUrl = wantsStop ? gatewayEndpoints.stop : gatewayEndpoints.start;
        const statusElement = options.statusElement || elements.status || elements.feedback || null;
        const showQrOnStart = Boolean(options.showQrOnStart);
        const waitingText = wantsStop ? 'Encerrando gateway...' : 'Iniciando gateway...';
        const defaultSuccess = wantsStop
            ? 'Gateway sendo encerrado... aguarde alguns segundos.'
            : 'Gateway em inicializacao...';

        const originalText = button.textContent;
        button.disabled = true;
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
        button.textContent = waitingText;
        if (statusElement) {
            statusElement.textContent = waitingText;
        }
        try {
            const data = await postGatewayAction(state, actionUrl);
            if (statusElement) {
                statusElement.textContent = (data && data.message) ? data.message : defaultSuccess;
            }
            if (!wantsStop && showQrOnStart) {
                showGatewayQr(state, false);
            }
            state.isOnline = !wantsStop;
            const delay = wantsStop ? 3500 : 1500;
            setTimeout(() => {
                refreshGatewayStatus(state);
            }, delay);
        } catch (error) {
            if (statusElement) {
                statusElement.textContent = error.message
                    || (wantsStop ? 'Falha ao encerrar gateway' : 'Falha ao iniciar gateway');
            }
        } finally {
            button.disabled = false;
            button.classList.remove('is-busy');
            button.removeAttribute('aria-busy');
            if (originalText) {
                button.textContent = originalText;
            }
        }
    }

    async function cleanGatewaySession(state, options = {}) {
        const { elements } = state;
        const button = options.button || (elements ? elements.clean : null);
        const feedbackElement = options.feedbackElement || (elements ? elements.feedback : null);
        if (!state || (!button && !feedbackElement)) {
            return;
        }
        if (!confirm('Parar, limpar sessão e gerar novo QR?')) {
            return;
        }
        const originalText = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.classList.add('is-busy');
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Limpando sessão...';
        }
        if (feedbackElement) {
            feedbackElement.textContent = 'Parando e limpando sessão...';
        }
        try {
            if (state.isOnline) {
                await postGatewayAction(state, gatewayEndpoints.stop);
            }
            await postGatewayAction(state, gatewayEndpoints.reset);
            if (feedbackElement) {
                feedbackElement.textContent = 'Sessão limpa. Aguarde o novo QR.';
            }
            showGatewayQr(state, false);
            refreshGatewayStatus(state);
        } catch (error) {
            if (feedbackElement) {
                feedbackElement.textContent = error.message || 'Falha ao limpar sessão';
            }
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('is-busy');
                button.removeAttribute('aria-busy');
                if (originalText) {
                    button.textContent = originalText;
                }
            }
        }
    }

    function stopGatewayQrTimer(state) {
        if (state.qrTimer) {
            clearInterval(state.qrTimer);
            state.qrTimer = null;
        }
    }

    function startGatewayQrTimer(state) {
        stopGatewayQrTimer(state);
        state.qrTimer = setInterval(() => {
            fetchGatewayQr(state, true);
        }, 20000);
    }

    function setGatewayQrPlaceholder(state, text) {
        if (!state || !state.elements || !state.elements.qrPlaceholder) {
            return;
        }
        const content = text != null ? text : state.qrPlaceholderDefault || '';
        state.elements.qrPlaceholder.textContent = content;
        state.elements.qrPlaceholder.hidden = content === '';
    }

    function hideGatewayQr(state, silent = false) {
        const { elements } = state;
        if (!elements.qrPanel) {
            return;
        }
        elements.qrPanel.hidden = true;
        state.qrVisible = false;
        state.lastQrData = '';
        state.qrResetAttempted = false;
        state.qrAutoResetCount = 0;
        state.lastResetAt = 0;
        if (elements.qrOpen) {
            elements.qrOpen.disabled = true;
        }
        stopGatewayQrTimer(state);
        if (elements.qrButton) {
            elements.qrButton.textContent = 'Mostrar QR';
        }
        setGatewayQrPlaceholder(state, state.qrPlaceholderDefault || '');
        if (!silent && elements.qrImage) {
            elements.qrImage.removeAttribute('src');
            elements.qrImage.alt = 'QR indisponivel';
        }
        if (!silent && elements.qrMeta) {
            elements.qrMeta.textContent = '';
        }
    }

    async function fetchGatewayQr(state, isAuto = false) {
        const { elements } = state;
        if (!elements.qrPanel || !elements.qrImage) {
            return;
        }
        if (!state.qrVisible) {
            return;
        }
        state.qrPanel.hidden = false;
        if (!isAuto) {
            setGatewayQrPlaceholder(state, 'Carregando QR...');
        }
        if (!isAuto) {
            elements.qrImage.alt = 'Carregando QR...';
        }
        if (elements.qrMeta && !isAuto) {
            elements.qrMeta.textContent = '';
        }
        if (elements.qrOpen && !isAuto) {
            elements.qrOpen.disabled = true;
        }
        try {
            const response = await fetch(buildGatewayUrl(gatewayEndpoints.qr, state.slug));
            if (response.status === 204) {
                elements.qrImage.removeAttribute('src');
                elements.qrImage.alt = 'Sessao conectada (QR nao necessario)';
                elements.qrPanel.hidden = false;
                if (elements.qrMeta) {
                    elements.qrMeta.textContent = '';
                }
                setGatewayQrPlaceholder(state, 'Sessão conectada. QR não é necessário.');
                state.lastQrData = '';
                state.qrAutoResetCount = 0;
                if (elements.qrOpen) {
                    elements.qrOpen.disabled = true;
                }
                stopGatewayQrTimer(state);
                return;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = (data && data.error) ? String(data.error) : 'QR nao disponivel';
                throw new Error(message);
            }
            if (data.qr) {
                elements.qrPanel.hidden = false;
                elements.qrImage.src = data.qr;
                elements.qrImage.alt = 'QR Code WhatsApp Web';
                state.lastQrData = data.qr;
                if (elements.qrOpen) {
                    elements.qrOpen.disabled = false;
                }
                if (elements.qrButton) {
                    elements.qrButton.textContent = 'Ocultar QR';
                }
                if (elements.qrMeta) {
                    const parts = [];
                    if (data.generated_at) {
                        parts.push('Gerado ' + formatRelativeTime(data.generated_at));
                    }
                    if (data.expires_at) {
                        parts.push('Expira em ' + formatExpiryCountdown(data.expires_at));
                    }
                    elements.qrMeta.textContent = parts.join(' | ');
                }
                state.qrResetAttempted = false;
                state.qrAutoResetCount = 0;
                setGatewayQrPlaceholder(state, '');
            } else {
                elements.qrImage.removeAttribute('src');
                elements.qrImage.alt = 'Sem QR no momento (sessao conectada)';
                state.lastQrData = '';
                if (elements.qrOpen) {
                    elements.qrOpen.disabled = true;
                }
                if (elements.qrMeta) {
                    elements.qrMeta.textContent = data.generated_at
                        ? 'Ultimo QR ' + formatRelativeTime(data.generated_at)
                        : '';
                }
                setGatewayQrPlaceholder(state, 'Sem QR disponível. Aguarde a reconexão.');

                // Recuperação automática se ficarmos sem QR por muito tempo
                const now = Date.now();
                const msSinceReset = state.lastResetAt ? (now - state.lastResetAt) : Infinity;
                if (isAuto && state.qrAutoResetCount < 2 && msSinceReset > 120000) {
                    state.qrAutoResetCount += 1;
                    state.lastResetAt = now;
                    if (elements.qrMeta) {
                        elements.qrMeta.textContent = 'Reiniciando sessão automaticamente para tentar novo QR...';
                    }
                    try {
                        await postGatewayAction(state, gatewayEndpoints.reset);
                        setTimeout(() => {
                            fetchGatewayQr(state, false);
                        }, 1500);
                        return;
                    } catch (resetError) {
                        if (elements.qrMeta) {
                            elements.qrMeta.textContent = resetError.message || 'Falha ao reiniciar sessão para QR.';
                        }
                    }
                }

                // Tentativa automática: se não há QR e ainda não resetamos, force reset para obter um QR fresco
                if (!isAuto && !state.qrResetAttempted) {
                    state.qrResetAttempted = true;
                    state.lastResetAt = Date.now();
                    try {
                        elements.qrMeta.textContent = 'Reiniciando sessão para gerar novo QR...';
                        await postGatewayAction(state, gatewayEndpoints.reset);
                        setTimeout(() => {
                            fetchGatewayQr(state, false);
                        }, 1500);
                        return;
                    } catch (resetError) {
                        elements.qrMeta.textContent = resetError.message || 'Falha ao reiniciar sessão para QR.';
                    }
                }
            }
        } catch (error) {
            elements.qrPanel.hidden = false;
            elements.qrImage.removeAttribute('src');
            elements.qrImage.alt = error.message || 'Erro ao buscar QR';
            state.lastQrData = '';
            if (elements.qrOpen) {
                elements.qrOpen.disabled = true;
            }
            if (elements.qrMeta) {
                elements.qrMeta.textContent = error.message || 'QR indisponivel (gateway offline ou sem resposta)';
            }
            setGatewayQrPlaceholder(state, error.message || 'QR indisponível no momento.');

            const now = Date.now();
            const msSinceReset = state.lastResetAt ? (now - state.lastResetAt) : Infinity;
            if (isAuto && state.qrAutoResetCount < 2 && msSinceReset > 120000) {
                state.qrAutoResetCount += 1;
                state.lastResetAt = now;
                if (elements.qrMeta) {
                    elements.qrMeta.textContent = 'Reiniciando sessão automaticamente para tentar novo QR...';
                }
                try {
                    await postGatewayAction(state, gatewayEndpoints.reset);
                    setTimeout(() => {
                        fetchGatewayQr(state, false);
                    }, 1500);
                    return;
                } catch (resetError) {
                    if (elements.qrMeta) {
                        elements.qrMeta.textContent = resetError.message || 'Falha ao reiniciar sessão para QR.';
                    }
                }
            }

            // Se deu erro e ainda não tentamos reset, tenta uma vez reiniciar para obter QR.
            if (!isAuto && !state.qrResetAttempted) {
                state.qrResetAttempted = true;
                state.lastResetAt = Date.now();
                try {
                    elements.qrMeta.textContent = 'Reiniciando sessão para gerar novo QR...';
                    await postGatewayAction(state, gatewayEndpoints.reset);
                    setTimeout(() => {
                        fetchGatewayQr(state, false);
                    }, 1500);
                    return;
                } catch (resetError) {
                    if (elements.qrMeta) {
                        elements.qrMeta.textContent = resetError.message || 'Falha ao reiniciar sessão para QR.';
                    }
                }
            }
        }
    }

    async function openGatewayQrPopup(state) {
        if (!state || !state.elements) {
            return;
        }
        if (!state.qrVisible) {
            showGatewayQr(state, false);
        }
        if (!state.lastQrData) {
            await fetchGatewayQr(state, false);
        }
        if (!state.lastQrData) {
            alert('QR ainda nao disponivel. Aguarde a imagem aparecer.');
            return;
        }
        const popup = window.open(state.lastQrData, '_blank', 'noopener');
        if (!popup) {
            const link = document.createElement('a');
            link.href = state.lastQrData;
            link.download = state.slug + '-qr.png';
            document.body.appendChild(link);
            link.click();
            link.remove();
        }
    }

    function showGatewayQr(state, isAuto = false) {
        const { elements } = state;
        if (!elements.qrPanel) {
            return;
        }
        elements.qrPanel.hidden = false;
        state.qrVisible = true;
        if (elements.qrButton) {
            elements.qrButton.textContent = 'Ocultar QR';
        }
        fetchGatewayQr(state, isAuto);
        if (!isAuto) {
            startGatewayQrTimer(state);
        }
    }

    function toggleGatewayQr(state) {
        if (state.qrVisible) {
            hideGatewayQr(state);
        } else {
            showGatewayQr(state, false);
        }
    }

    function locateGatewayCard(slug) {
        if (!slug) {
            return null;
        }
        return gatewayCards.find((state) => state.slug === slug) || null;
    }

    function ensureGatewayPanelForSlug(slug) {
        if (!slug) {
            alert('Esta linha nÆo est  vinculada a um gateway alternativo.');
            return null;
        }
        if (gatewayCards.length === 0) {
            alert('Nenhum gateway alternativo configurado nesta tela.');
            return null;
        }
        setGatewayPanelVisibility(true);
        const cardState = locateGatewayCard(slug);
        if (!cardState) {
            alert('Instƒncia nÆo encontrada. Verifique as configura‡äes do gateway.');
            return null;
        }
        return cardState;
    }

    function showQrForLineGateway(state) {
        const cardState = ensureGatewayPanelForSlug(state.slug);
        if (!cardState) {
            return;
        }
        showGatewayQr(cardState, false);
        if (cardState.card && typeof cardState.card.scrollIntoView === 'function') {
            cardState.card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function setGatewayPanelVisibility(visible) {
        if (!gatewayContainer) {
            return;
        }
        gatewayPanelVisible = visible;
        if (visible) {
            gatewayContainer.removeAttribute('hidden');
            if (gatewayToggleButton) {
                gatewayToggleButton.textContent = 'Ocultar painel do laboratório';
            }
            refreshAllGateways();
        } else {
            gatewayContainer.setAttribute('hidden', 'hidden');
            if (gatewayToggleButton) {
                gatewayToggleButton.textContent = 'Mostrar painel do laboratório';
            }
            gatewayCards.forEach((state) => hideGatewayQr(state, true));
        }
        renderGatewayOfflineToast();
    }

    function initializeGatewayCards() {
        gatewayCards.forEach((state) => {
            const { elements } = state;
            if (elements.refresh) {
                elements.refresh.addEventListener('click', () => refreshGatewayStatus(state));
            }
            if (elements.qrButton) {
                elements.qrButton.addEventListener('click', () => toggleGatewayQr(state));
            }
            if (elements.qrOpen) {
                elements.qrOpen.addEventListener('click', () => openGatewayQrPopup(state));
            }
            if (elements.clean) {
                elements.clean.addEventListener('click', async () => {
                    await cleanGatewaySession(state, { feedbackElement: elements.feedback, button: elements.clean });
                });
            }
            if (elements.start) {
                elements.start.addEventListener('click', () => {
                    toggleGatewayProcess(state, { statusElement: elements.feedback, showQrOnStart: true });
                });
            }
            if (elements.historyForm) {
                setupHistoryForm(elements.historyForm);
                elements.historyForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (elements.historyFeedback) {
                        elements.historyFeedback.textContent = 'Sincronizando historico...';
                    }
                    const formData = new FormData(elements.historyForm);
                    const mode = (formData.get('history_mode') || 'all').toString();
                    const payload = {
                        instance: state.slug,
                        history_mode: mode,
                    };
                    const maxChats = formData.get('history_max_chats');
                    if (maxChats) {
                        payload.history_max_chats = maxChats;
                    }
                    const maxMessages = formData.get('history_max_messages');
                    if (maxMessages) {
                        payload.history_max_messages = maxMessages;
                    }
                    if (mode === 'range') {
                        const from = formData.get('history_from');
                        const to = formData.get('history_to');
                        if (from) {
                            payload.history_from = from;
                        }
                        if (to) {
                            payload.history_to = to;
                        }
                    } else if (mode === 'hours') {
                        const hoursValue = Number(formData.get('history_hours') || 0);
                        if (hoursValue > 0) {
                            const minutes = Math.min(10080, Math.max(1, Math.round(hoursValue * 60)));
                            payload.history_lookback = minutes;
                        }
                    } else {
                        const lookbackMinutes = Number(formData.get('history_lookback') || 0);
                        if (lookbackMinutes > 0) {
                            payload.history_lookback = Math.min(10080, Math.max(5, lookbackMinutes));
                        }
                    }
                    try {
                        const data = await postForm(gatewayEndpoints.history, payload);
                        const stats = data.stats || {};
                        const summary = `Mensagens importadas: ${stats.messages_forwarded || 0} | Conversas: ${stats.chats_with_messages || 0}`;
                        if (elements.historyFeedback) {
                            elements.historyFeedback.textContent = summary;
                        }
                        refreshGatewayStatus(state);
                    } catch (error) {
                        if (elements.historyFeedback) {
                            elements.historyFeedback.textContent = error.message || 'Falha ao sincronizar historico';
                        }
                    }
                });
            }
        });
    }

    function refreshAllGateways() {
        gatewayCards.forEach((state) => refreshGatewayStatus(state));
        renderGatewayOfflineToast();
    }

    function refreshLineGateways(force = false) {
        if (!force && !gatewayPanelVisible) {
            return;
        }
        lineGatewayStates.forEach((state) => refreshGatewayStatus(state));
    }

    function initializeLineGateways() {
        lineGatewayStates.forEach((state) => {
            const { elements } = state;
            if (elements.start) {
                elements.start.addEventListener('click', async () => {
                    const button = elements.start;
                    const original = button.textContent;
                    button.disabled = true;
                    button.classList.add('is-busy');
                    button.textContent = 'Iniciando gateway...';
                    if (elements.status) {
                        elements.status.textContent = 'Iniciando gateway...';
                    }
                    try {
                        await postGatewayAction(state, gatewayEndpoints.start);
                        setTimeout(() => refreshGatewayStatus(state), 1200);
                    } catch (error) {
                        if (elements.status) {
                            elements.status.textContent = error.message || 'Falha ao iniciar gateway';
                        }
                    } finally {
                        button.disabled = false;
                        button.classList.remove('is-busy');
                        button.textContent = original || 'Iniciar gateway';
                    }
                });
            }
            if (elements.stop) {
                elements.stop.addEventListener('click', async () => {
                    const button = elements.stop;
                    const original = button.textContent;
                    button.disabled = true;
                    button.classList.add('is-busy');
                    button.textContent = 'Parando gateway...';
                    if (elements.status) {
                        elements.status.textContent = 'Parando gateway...';
                    }
                    try {
                        const data = await postGatewayAction(state, gatewayEndpoints.stop);
                        const message = (data && data.message)
                            ? data.message
                            : 'Comando enviado. Aguarde alguns segundos e atualize.';
                        if (elements.status) {
                            elements.status.textContent = message;
                        }
                        alert(message);
                        setTimeout(() => refreshGatewayStatus(state), 3000);
                    } catch (error) {
                        if (elements.status) {
                            elements.status.textContent = error.message || 'Falha ao encerrar gateway';
                        }
                        alert(error.message || 'Falha ao encerrar gateway');
                    } finally {
                        button.disabled = false;
                        button.classList.remove('is-busy');
                        button.textContent = original || 'Parar gateway';
                    }
                });
            }
            if (elements.qrButton) {
                elements.qrButton.addEventListener('click', async () => {
                    const copy = buildLineQrModalCopy(state, 'standard');
                    openLineQrModal(state.slug, {
                        mode: 'standard',
                        modeLabel: copy.modeLabel,
                        title: copy.title,
                        description: copy.description,
                        helper: copy.helper,
                        statusText: 'Gerando novo QR...',
                        autoFetch: false,
                    });
                    elements.qrButton.disabled = true;
                    if (elements.status) {
                        elements.status.textContent = 'Gerando novo QR...';
                    }
                    try {
                        await postGatewayAction(state, gatewayEndpoints.reset);
                        setLineQrPlaceholder('Sessão reiniciada. Aguarde o QR aparecer no celular.', state.slug);
                        refreshLineQrModalQr(true);
                        refreshGatewayStatus(state);
                    } catch (error) {
                        const message = error.message || 'Falha ao solicitar novo QR';
                        setLineQrPlaceholder(message, state.slug);
                        setLineQrMeta('', state.slug);
                        if (elements.status) {
                            elements.status.textContent = message;
                        } else {
                            alert(message);
                        }
                    } finally {
                        elements.qrButton.disabled = false;
                    }
                });
            }
            if (elements.qrHistoryButton) {
                elements.qrHistoryButton.addEventListener('click', async () => {
                    const copy = buildLineQrModalCopy(state, 'history');
                    openLineQrModal(state.slug, {
                        mode: 'history',
                        modeLabel: copy.modeLabel,
                        title: copy.title,
                        description: copy.description,
                        helper: copy.helper,
                        statusText: 'Preparando QR histórico...',
                        autoFetch: false,
                    });
                    elements.qrHistoryButton.disabled = true;
                    clearFullHistoryRequest(state.slug);
                    setFullHistoryRequest(state.slug, {
                        status: 'resetting',
                        button: elements.qrHistoryButton,
                        summary: '',
                        error: '',
                        progress: '',
                    }, true);
                    if (elements.status) {
                        elements.status.textContent = 'Reiniciando sessão para importar histórico...';
                    }
                    try {
                        await postGatewayAction(state, gatewayEndpoints.reset);
                        setFullHistoryRequest(state.slug, {
                            status: 'awaiting_ready',
                            error: '',
                            progress: '',
                        });
                        setLineQrPlaceholder('Escaneie o QR histórico e deixe o WhatsApp aberto para sincronizar tudo.', state.slug);
                        refreshLineQrModalQr(true);
                        refreshGatewayStatus(state);
                    } catch (error) {
                        const message = error.message || 'Falha ao solicitar QR histórico';
                        setFullHistoryRequest(state.slug, {
                            status: 'failed',
                            error: message,
                        });
                        if (elements.status) {
                            elements.status.textContent = message;
                        } else {
                            alert(message);
                        }
                        clearFullHistoryRequest(state.slug);
                        setLineQrPlaceholder(message, state.slug);
                        setLineQrMeta('', state.slug);
                    } finally {
                        elements.qrHistoryButton.disabled = false;
                    }
                });
            }
        });
        if (lineGatewayStates.length > 0) {
            refreshLineGateways(true);
            setInterval(() => {
                refreshLineGateways();
            }, 30000);
        }
    }

    if (gatewayToggleButton) {
        gatewayToggleButton.addEventListener('click', () => {
            setGatewayPanelVisibility(!gatewayPanelVisible);
        });
    }

    initializeGatewayCards();

    if (gatewayCards.length > 0 && gatewayPanelVisible) {
        refreshAllGateways();
    }

    if (gatewayCards.length > 0) {
        setInterval(() => {
            if (!gatewayPanelVisible) {
                return;
            }
            refreshAllGateways();
        }, 25000);
    }

    populateBroadcastTemplates();
    updateBroadcastTemplatePreviewUi();
    renderBroadcastHistory(initialBroadcastHistory);

    initializeLineGateways();
    globalState.lineGatewayCount = lineGatewayStates.length;
    globalState.lineGatewayInit = lineGatewayStates.length > 0;
    globalState.lineGatewaySlugs = lineGatewayStates.map((state) => state.slug);

    window.addEventListener('beforeunload', () => {
        gatewayCards.forEach(stopGatewayQrTimer);
    });

    if (broadcastForm) {
        broadcastForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (broadcastFeedback) {
                broadcastFeedback.textContent = 'Disparando comunicado...';
            }
            const formData = new FormData(broadcastForm);
            const queues = formData.getAll('queues[]').filter((value) => value && value !== '');
            if (queues.length === 0) {
                if (broadcastFeedback) {
                    broadcastFeedback.textContent = 'Selecione pelo menos uma fila.';
                }
                return;
            }
            const payload = {
                title: formData.get('title') || '',
                message: formData.get('message') || '',
                limit: formData.get('limit') || '',
                'queues[]': queues,
                template_kind: formData.get('template_kind') || '',
                template_key: formData.get('template_key') || '',
            };
            try {
                const data = await postForm('<?= url('whatsapp/broadcast'); ?>', payload);
                const mode = data && data.mode ? data.mode : 'immediate';
                if (broadcastFeedback) {
                    if (mode === 'queued') {
                        broadcastFeedback.textContent = 'Comunicado enviado para a fila. Ele será processado em segundo plano.';
                    } else {
                        const stats = data && data.stats ? data.stats : {};
                        const sent = typeof stats.sent === 'number' ? stats.sent : queues.length;
                        const total = typeof stats.total === 'number' ? stats.total : queues.length;
                        broadcastFeedback.textContent = `Comunicado enviado: ${sent}/${total} conversas.`;
                    }
                }
                if (data && Array.isArray(data.recent)) {
                    renderBroadcastHistory(data.recent);
                }
            } catch (error) {
                if (broadcastFeedback) {
                    broadcastFeedback.textContent = error.message;
                }
            }
        });
    }

    if (broadcastRefreshButton) {
        broadcastRefreshButton.addEventListener('click', () => window.location.reload());
    }

    if (sandboxForm) {
        sandboxForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (sandboxFeedback) {
                sandboxFeedback.textContent = 'Injetando...';
            }
            const formData = new FormData(sandboxForm);
            try {
                await postForm('<?= url('whatsapp/sandbox/inject'); ?>', Object.fromEntries(formData.entries()));
                if (sandboxFeedback) {
                    sandboxFeedback.textContent = 'Mensagem registrada no sandbox.';
                }
            } catch (error) {
                if (sandboxFeedback) {
                    sandboxFeedback.textContent = error.message;
                }
            }
        });
    }

    if (newThreadForm) {
        newThreadForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (newThreadFeedback) {
                newThreadFeedback.textContent = 'Enviando...';
            }
            const formData = new FormData(newThreadForm);
            const payload = Object.fromEntries(formData.entries());
            try {
                const data = await postForm('<?= url('whatsapp/manual-thread'); ?>', payload);
                if (newThreadFeedback) {
                    newThreadFeedback.textContent = 'Conversa criada. Redirecionando...';
                }
                setTimeout(() => {
                    if (data.thread_id) {
                        navigateToThread(data.thread_id);
                    } else {
                        window.location.reload();
                    }
                }, 500);
            } catch (error) {
                if (newThreadFeedback) {
                    newThreadFeedback.textContent = error.message;
                }
            }
        });
    }

    if (manualForm) {
        manualForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (manualFeedback) {
                manualFeedback.textContent = 'Enviando manual...';
            }
            try {
                await postMultipart('<?= url('whatsapp/copilot-manuals'); ?>', manualForm);
                if (manualFeedback) {
                    manualFeedback.textContent = 'Manual salvo com sucesso.';
                }
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                if (manualFeedback) {
                    manualFeedback.textContent = error.message;
                }
            }
        });
    }

    manualDeleteButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const manualId = button.getAttribute('data-manual-delete');
            if (!manualId) {
                return;
            }
            if (!confirm('Remover este manual da base IA?')) {
                return;
            }
            try {
                await postForm('<?= url('whatsapp/copilot-manuals'); ?>/' + manualId + '/delete', {});
                window.location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });

    if (permissionForm) {
        permissionForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (permissionFeedback) {
                permissionFeedback.textContent = 'Salvando...';
            }
            const formData = new FormData(permissionForm);
            const allowed = formData.getAll('allowed_user_ids[]');
            const payload = {
                'allowed_user_ids[]': allowed,
                block_avp: formData.get('block_avp') ? 1 : 0,
            };
            try {
                await postForm('<?= url('whatsapp/access-control'); ?>', payload);
                if (permissionFeedback) {
                    permissionFeedback.textContent = 'Permissões atualizadas.';
                }
            } catch (error) {
                if (permissionFeedback) {
                    permissionFeedback.textContent = error.message;
                }
            }
        });
    }

    const permissionCards = Array.from(document.querySelectorAll('[data-permission-card]'));

    function syncPanelUsersVisibility(card, panelKey) {
        if (!panelKey) {
            return;
        }
        const wrapper = card.querySelector(`[data-panel-users="${panelKey}"]`);
        if (!wrapper) {
            return;
        }
        const select = card.querySelector(`[data-panel-scope="${panelKey}"]`);
        wrapper.hidden = !select || select.value !== 'selected';
    }

    function syncPanelControls(card) {
        const selects = card.querySelectorAll('[data-panel-scope]');
        selects.forEach((select) => {
            const panelKey = select.getAttribute('data-panel-scope');
            syncPanelUsersVisibility(card, panelKey);
        });
    }

    function collectPanelScopes(card) {
        const scopes = {};
        card.querySelectorAll('[data-panel-scope]').forEach((select) => {
            const key = select.getAttribute('data-panel-scope');
            if (!key) {
                return;
            }
            const mode = (select.value || 'none').toLowerCase();
            let users = [];
            if (mode === 'selected') {
                const wrapper = card.querySelector(`[data-panel-users="${key}"]`);
                if (wrapper) {
                    users = Array.from(wrapper.querySelectorAll('input[type="checkbox"]'))
                        .filter((checkbox) => checkbox.checked)
                        .map((checkbox) => parseInt(checkbox.value || '', 10))
                        .filter((value) => !Number.isNaN(value) && value > 0);
                }
            }
            scopes[key] = {
                mode,
                users,
            };
        });
        return scopes;
    }

    function collectPermissionCard(card) {
        const userId = parseInt(card.getAttribute('data-user-id') || '', 10);
        if (!userId) {
            return null;
        }
        const displayInput = card.querySelector('[data-display-name]');
        const displayName = displayInput ? displayInput.value : '';
        const panelScope = collectPanelScopes(card);
        const entryScope = panelScope.entrada ? panelScope.entrada.mode : 'none';
        const inboxAccess = entryScope === 'all' ? 'all' : 'own_only';
        const atendimentoScope = panelScope.atendimento ? panelScope.atendimento.mode : 'own';
        const atendimentoUsers = panelScope.atendimento && Array.isArray(panelScope.atendimento.users)
            ? panelScope.atendimento.users
            : [];
        const normalizedViewScope = atendimentoScope === 'none' ? 'own' : atendimentoScope;
        const normalizedViewUsers = normalizedViewScope === 'selected' ? atendimentoUsers : [];
        const checkboxValue = (selector) => {
            const field = card.querySelector(selector);
            return field && field.checked ? 1 : 0;
        };
        return {
            user_id: userId,
            level: 3,
            inbox_access: inboxAccess,
            view_scope: normalizedViewScope,
            view_scope_users: normalizedViewUsers,
            display_name: displayName,
            panel_scope: panelScope,
            can_forward: checkboxValue("[data-permission-field='can_forward']"),
            can_start_thread: checkboxValue("[data-permission-field='can_start_thread']"),
            can_view_completed: checkboxValue("[data-permission-field='can_view_completed']"),
            can_grant_permissions: checkboxValue("[data-permission-field='can_grant_permissions']"),
        };
    }

    permissionCards.forEach((card) => {
        syncPanelControls(card);
        card.querySelectorAll('[data-panel-scope]').forEach((select) => {
            const panelKey = select.getAttribute('data-panel-scope');
            select.addEventListener('change', () => {
                syncPanelUsersVisibility(card, panelKey);
            });
        });
        const saveButton = card.querySelector('[data-permission-save]');
        const statusNode = card.querySelector('[data-permission-status]');
        if (!saveButton) {
            return;
        }
        saveButton.addEventListener('click', async () => {
            const payload = collectPermissionCard(card);
            if (!payload) {
                if (statusNode) {
                    statusNode.textContent = 'Usuário inválido.';
                }
                return;
            }
            if (statusNode) {
                statusNode.textContent = 'Salvando...';
            }
            saveButton.disabled = true;
            try {
                await postForm('<?= url('whatsapp/user-permissions'); ?>', { entries: JSON.stringify([payload]) });
                if (statusNode) {
                    statusNode.textContent = 'Permissões atualizadas.';
                }
            } catch (error) {
                if (statusNode) {
                    statusNode.textContent = error.message;
                }
            } finally {
                saveButton.disabled = false;
            }
        });
    });

    if (copilotForm) {
        copilotForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (copilotFeedback) {
                copilotFeedback.textContent = 'Salvando...';
            }
            const formData = new FormData(copilotForm);
            try {
                await postForm('<?= url('whatsapp/integration'); ?>', Object.fromEntries(formData.entries()));
                if (copilotFeedback) {
                    copilotFeedback.textContent = 'Chave atualizada.';
                }
            } catch (error) {
                if (copilotFeedback) {
                    copilotFeedback.textContent = error.message;
                }
            }
        });
    }

    if (copilotProfileForm) {
        copilotProfileForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (copilotProfileFeedback) {
                copilotProfileFeedback.textContent = 'Salvando perfil...';
            }
            const formData = new FormData(copilotProfileForm);
            const profileId = formData.get('profile_id');
            const url = profileId
                ? '<?= url('whatsapp/copilot-profiles'); ?>/' + profileId
                : '<?= url('whatsapp/copilot-profiles'); ?>';
            try {
                await postForm(url, Object.fromEntries(formData.entries()));
                if (copilotProfileFeedback) {
                    copilotProfileFeedback.textContent = 'Perfil salvo.';
                }
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                if (copilotProfileFeedback) {
                    copilotProfileFeedback.textContent = error.message;
                }
            }
        });
    }

    if (copilotProfileReset) {
        copilotProfileReset.addEventListener('click', (event) => {
            event.preventDefault();
            resetCopilotProfileForm();
        });
    }

    copilotProfileEditButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!copilotProfileForm) {
                return;
            }
            const payload = JSON.parse(button.getAttribute('data-copilot-profile-edit') || '{}');
            const idField = copilotProfileForm.querySelector('input[name="profile_id"]');
            if (idField) {
                idField.value = payload.id || '';
            }
            const nameField = copilotProfileForm.querySelector('input[name="name"]');
            if (nameField) {
                nameField.value = payload.name || '';
            }
            const objectiveField = copilotProfileForm.querySelector('input[name="objective"]');
            if (objectiveField) {
                objectiveField.value = payload.objective || '';
            }
            const descriptionField = copilotProfileForm.querySelector('textarea[name="description"]');
            if (descriptionField) {
                descriptionField.value = payload.description || '';
            }
            const toneField = copilotProfileForm.querySelector('select[name="tone"]');
            if (toneField) {
                toneField.value = payload.tone || 'consultivo';
            }
            const queueField = copilotProfileForm.querySelector('select[name="default_queue"]');
            if (queueField) {
                queueField.value = payload.default_queue || '';
            }
            const tempField = copilotProfileForm.querySelector('input[name="temperature"]');
            if (tempField) {
                tempField.value = payload.temperature ?? 0.5;
            }
            const instructionsField = copilotProfileForm.querySelector('textarea[name="instructions"]');
            if (instructionsField) {
                instructionsField.value = payload.instructions || '';
            }
            const defaultField = copilotProfileForm.querySelector('input[name="is_default"]');
            if (defaultField) {
                defaultField.checked = Number(payload.is_default || 0) === 1;
            }
            if (copilotProfileFeedback) {
                copilotProfileFeedback.textContent = 'Editando perfil #' + (payload.id || '');
            }
        });
    });

    copilotProfileDeleteButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const profileId = button.getAttribute('data-copilot-profile-delete');
            if (!profileId) {
                return;
            }
            if (!confirm('Remover este perfil IA?')) {
                return;
            }
            try {
                await postForm('<?= url('whatsapp/copilot-profiles'); ?>/' + profileId + '/delete', {});
                window.location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });

    if (lineProviderField) {
        lineProviderField.addEventListener('change', () => {
            syncLineRequirements();
        });
    }
    if (lineTemplateField) {
        lineTemplateField.addEventListener('change', () => {
            applyApiTemplate({ userInitiated: true });
        });
    }
    if (lineAltSelect) {
        lineAltSelect.addEventListener('change', () => {
            syncLineAltInline();
        });
    }
    if (rateLimitPresetField) {
        setRateLimitHint(rateLimitPresetField.value || '');
        rateLimitPresetField.addEventListener('change', () => {
            const key = rateLimitPresetField.value;
            if (key === '' || !rateLimitPresets[key]) {
                setRateLimitHint('');
                return;
            }
            applyRateLimitPresetSelection(key, { silent: true });
        });
    }
    [lineLimitWindowField, lineLimitMaxField].forEach((field) => {
        if (!field) {
            return;
        }
        field.addEventListener('input', () => {
            syncRateLimitPresetFromValues();
        });
    });

    if (lineModeField) {
        lineModeField.addEventListener('change', () => {
            const mode = getLineMode();
            if (mode === 'new') {
                resetLineForm();
                return;
            }
            syncLineModeUI();
            if (linePickerField && linePickerField.value) {
                const payload = getLineOptionPayload(linePickerField.value);
                if (payload) {
                    populateLineForm(payload, { syncPicker: false });
                }
                return;
            }
            if (lineFeedback) {
                lineFeedback.textContent = mode === 'migrate'
                    ? 'Selecione a linha que será migrada.'
                    : 'Selecione uma linha para editar.';
            }
        });
    }

    if (linePickerField) {
        linePickerField.addEventListener('change', () => {
            const value = linePickerField.value;
            if (!value) {
                if (lineIdField) {
                    lineIdField.value = '';
                }
                if (lineFeedback && getLineMode() !== 'new') {
                    lineFeedback.textContent = 'Escolha uma linha para continuar.';
                }
                return;
            }
            const payload = getLineOptionPayload(value);
            if (!payload) {
                if (lineFeedback) {
                    lineFeedback.textContent = 'Não foi possível carregar os dados da linha.';
                }
                return;
            }
            populateLineForm(payload, { syncPicker: false });
            if (lineFeedback) {
                lineFeedback.textContent = getLineMode() === 'migrate'
                    ? 'Linha carregada. Ajuste as credenciais e salve.'
                    : 'Linha pronta para edição.';
            }
        });
    }

    applyApiTemplate({ userInitiated: false });
    syncRateLimitPresetFromValues();
    syncLineModeUI();

    function resetCopilotProfileForm() {
        if (!copilotProfileForm) {
            return;
        }
        copilotProfileForm.reset();
        const idField = copilotProfileForm.querySelector('input[name="profile_id"]');
        if (idField) {
            idField.value = '';
        }
        if (copilotProfileFeedback) {
            copilotProfileFeedback.textContent = '';
        }
    }

    function resetLineForm() {
        if (!lineForm) {
            return;
        }
        lineForm.reset();
        if (lineModeField) {
            lineModeField.value = 'new';
        }
        if (linePickerField) {
            linePickerField.value = '';
        }
        if (lineIdField) {
            lineIdField.value = '';
        }
        if (lineLabelField) {
            lineLabelField.value = '';
        }
        if (lineDisplayPhoneField) {
            lineDisplayPhoneField.value = '';
        }
        if (lineTemplateField) {
            lineTemplateField.value = 'meta';
        }
        if (lineProviderField) {
            lineProviderField.value = 'meta';
        }
        if (lineBaseField) {
            lineBaseField.value = '';
        }
        if (linePhoneNumberField) {
            linePhoneNumberField.value = '';
        }
        if (lineBusinessAccountField) {
            lineBusinessAccountField.value = '';
        }
        if (lineAccessField) {
            lineAccessField.value = '';
        }
        if (lineVerifyTokenField) {
            lineVerifyTokenField.value = '';
        }
        if (lineAltSelect) {
            lineAltSelect.value = '';
        }
        if (lineStatusField) {
            lineStatusField.value = 'active';
        }
        if (lineDefaultField) {
            lineDefaultField.checked = false;
        }
        if (lineLimitEnabledField) {
            lineLimitEnabledField.checked = false;
        }
        if (lineLimitWindowField) {
            lineLimitWindowField.value = '3600';
        }
        if (lineLimitMaxField) {
            lineLimitMaxField.value = '500';
        }
        if (rateLimitPresetField) {
            rateLimitPresetField.value = '';
        }
        setRateLimitHint('');
        if (lineForm) {
            lineForm.dataset.webLockOrigin = '0';
        }
        applyApiTemplate({ userInitiated: true });
        syncRateLimitPresetFromValues();
        syncLineModeUI();
        if (lineFeedback) {
            lineFeedback.textContent = '';
        }
    }

    if (lineForm) {
        lineForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (lineFeedback) {
                lineFeedback.textContent = 'Salvando linha...';
            }
            const formData = new FormData(lineForm);
            const templateValue = (formData.get('api_template') || (lineTemplateField ? lineTemplateField.value : '') || '').toString().toLowerCase();
            const requiresWebCode = templateRequiresWebLock(templateValue) || (lineForm.dataset.webLockOrigin === '1');
            if (requiresWebCode) {
                const codeValue = (formData.get('web_edit_code') || '').toString().trim();
                if (codeValue === '') {
                    if (lineFeedback) {
                        lineFeedback.textContent = 'Informe o código de autorização para alterar linhas do WhatsApp Web.';
                    }
                    return;
                }
            }
            const lineId = formData.get('line_id');
            const mode = getLineMode();
            if ((mode === 'edit' || mode === 'migrate') && (!lineId || String(lineId).trim() === '')) {
                if (lineFeedback) {
                    lineFeedback.textContent = 'Selecione uma linha antes de salvar.';
                }
                return;
            }
            const url = lineId ? '<?= url('whatsapp/lines'); ?>/' + lineId : '<?= url('whatsapp/lines'); ?>';
            try {
                await postForm(url, Object.fromEntries(formData.entries()));
                if (lineFeedback) {
                    lineFeedback.textContent = 'Linha salva.';
                }
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                if (lineFeedback) {
                    lineFeedback.textContent = error.message;
                }
            }
        });
    }

    if (lineReset) {
        lineReset.addEventListener('click', (event) => {
            event.preventDefault();
            resetLineForm();
        });
    }

    lineEditButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!lineForm) {
                return;
            }
            const payload = parseJsonText(button.getAttribute('data-line-edit'), null);
            if (!payload) {
                alert('Não foi possível carregar os dados desta linha.');
                return;
            }
            if (lineModeField) {
                lineModeField.value = 'edit';
            }
            populateLineForm(payload);
            if (lineFeedback) {
                lineFeedback.textContent = 'Editando linha #' + payload.id;
            }
        });
    });

    lineDeleteButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Remover esta linha?')) {
                return;
            }
            const lineId = button.getAttribute('data-line-delete');
            const payload = parseJsonText(button.getAttribute('data-line-delete-payload') || '{}', null);
            const needsCode = isWebLinePayload(payload);
            const deletePayload = {};
            if (needsCode) {
                const codeInput = prompt('Informe o código de autorização para remover linhas do WhatsApp Web:');
                if (!codeInput) {
                    return;
                }
                deletePayload.web_edit_code = codeInput;
            }
            try {
                await postForm('<?= url('whatsapp/lines'); ?>/' + lineId + '/delete', deletePayload);
                window.location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });
    if (threadSearchInput) {
        threadSearchInput.addEventListener('input', () => {
            applyThreadSearchFilter();
        });
        threadSearchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyThreadSearchFilter();
                scheduleThreadSearchRefresh(80);
            }
        });
        applyThreadSearchFilter();
    }
    if (threadSearchApply) {
        threadSearchApply.addEventListener('click', (event) => {
            event.preventDefault();
            applyThreadSearchFilter();
            scheduleThreadSearchRefresh(80);
        });
    }
    if (threadSearchClear) {
        threadSearchClear.addEventListener('click', () => {
            if (!threadSearchInput) {
                return;
            }
            const hadQuery = threadSearchInput.value !== '';
            threadSearchInput.value = '';
            threadSearchInput.focus();
            applyThreadSearchFilter();
            if (hadQuery) {
                scheduleThreadSearchRefresh(80);
            }
        });
    }

    renderNotificationControls();
    if (notifySoundStyleSelect) {
        notifySoundStyleSelect.value = notificationState.soundStyle || 'voice';
        notifySoundStyleSelect.addEventListener('change', () => {
            const style = notifySoundStyleSelect.value || 'voice';
            notificationState.soundStyle = style;
            persistPreference(NOTIFY_SOUND_STYLE_KEY, style);
            renderNotificationControls();
        });
    }
    if (notifyDelaySelect) {
        notifyDelaySelect.value = String(notificationState.cooldownMinutes ?? 0);
        notifyDelaySelect.addEventListener('change', () => {
            const minutes = Math.max(0, parseFloat(notifyDelaySelect.value) || 0);
            notificationState.cooldownMinutes = minutes;
            persistPreference(NOTIFY_COOLDOWN_KEY, minutes);
            renderNotificationControls();
        });
    }
    if (notifyPanelToggle && notifyPanel) {
        notifyPanelToggle.addEventListener('click', (event) => {
            event.preventDefault();
            const shouldOpen = notifyPanel.hasAttribute('hidden');
            setNotifyPanelVisibility(shouldOpen);
        });
    }
    if (notifySoundFileInput) {
        notifySoundFileInput.addEventListener('change', () => {
            const file = notifySoundFileInput.files && notifySoundFileInput.files[0];
            if (!file) {
                setSoundFeedback('Selecione um arquivo de áudio para o alerta personalizado.', true);
                return;
            }
            if (file.type && !file.type.startsWith('audio/')) {
                notifySoundFileInput.value = '';
                setSoundFeedback('Use apenas arquivos de áudio (mp3, wav, ogg...).', true);
                return;
            }
            if (file.size > MAX_CUSTOM_SOUND_BYTES) {
                notifySoundFileInput.value = '';
                setSoundFeedback('O áudio precisa ter até 1MB.', true);
                return;
            }
            const reader = new FileReader();
            reader.onload = () => {
                notificationState.customSoundData = String(reader.result || '');
                notificationState.customSoundName = file.name || 'Áudio personalizado';
                persistPreference(NOTIFY_SOUND_CUSTOM_DATA_KEY, notificationState.customSoundData);
                persistPreference(NOTIFY_SOUND_CUSTOM_NAME_KEY, notificationState.customSoundName);
                setSoundFeedback('Áudio salvo. Clique em "Testar som" para ouvir.');
                renderNotificationControls();
            };
            reader.onerror = () => {
                notifySoundFileInput.value = '';
                setSoundFeedback('Não foi possível ler o arquivo selecionado.', true);
            };
            reader.readAsDataURL(file);
        });
    }
    if (notifySoundClearButton) {
        notifySoundClearButton.addEventListener('click', (event) => {
            event.preventDefault();
            notificationState.customSoundData = '';
            notificationState.customSoundName = '';
            persistPreference(NOTIFY_SOUND_CUSTOM_DATA_KEY, '');
            persistPreference(NOTIFY_SOUND_CUSTOM_NAME_KEY, '');
            if (notifySoundFileInput) {
                notifySoundFileInput.value = '';
            }
            setSoundFeedback('Áudio removido.');
            renderNotificationControls();
        });
    }
    if (notifyTestSoundButton) {
        notifyTestSoundButton.addEventListener('click', (event) => {
            event.preventDefault();
            if (!notificationState.soundEnabled) {
                setSoundFeedback('O som está em modo mudo.', true);
                return;
            }
            notificationsPrimed = true;
            playNotificationSound({
                line_label: 'Linha de teste',
                name: 'SafeGreen',
            });
        });
    }
    if (backupRestoreForm) {
        backupRestoreForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!backupRestoreForm.checkValidity()) {
                backupRestoreForm.reportValidity();
                return;
            }
            const endpoint = backupRestoreForm.getAttribute('action') || '<?= url('whatsapp/backup/import'); ?>';
            const submitButton = backupRestoreForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }
            setBackupRestoreFeedback('Enviando backup para restauração...', false);
            try {
                const data = await postMultipart(endpoint, backupRestoreForm);
                const stats = data && data.stats && typeof data.stats === 'object'
                    ? Object.entries(data.stats)
                        .map(([table, count]) => `${table.replace('whatsapp_', '')}: ${count}`)
                        .join(' · ')
                    : null;
                const successMessage = data && data.message ? data.message : 'Backup restaurado com sucesso.';
                setBackupRestoreFeedback(stats ? `${successMessage} (${stats})` : successMessage, false);
                backupRestoreForm.reset();
            } catch (error) {
                setBackupRestoreFeedback(error.message || 'Falha ao restaurar o backup.', true);
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }
    document.addEventListener('click', (event) => {
        const registerTrigger = event.target.closest('[data-register-contact-open]');
        if (registerTrigger && typeof openRegisterContactFromTrigger === 'function') {
            event.preventDefault();
            openRegisterContactFromTrigger(registerTrigger);
            return;
        }
        const soundTarget = event.target.closest('[data-notify-sound-toggle]');
        if (soundTarget) {
            notificationState.soundEnabled = !notificationState.soundEnabled;
            persistPreference(NOTIFY_SOUND_KEY, notificationState.soundEnabled);
            renderNotificationControls();
            if (!notificationState.soundEnabled) {
                if (typeof window !== 'undefined' && window.speechSynthesis) {
                    window.speechSynthesis.cancel();
                }
                if (activeCustomAudio) {
                    activeCustomAudio.pause();
                    activeCustomAudio.currentTime = 0;
                    activeCustomAudio = null;
                }
                if (notificationState.audioCtx && typeof notificationState.audioCtx.close === 'function') {
                    notificationState.audioCtx.close().catch(() => {});
                    notificationState.audioCtx = null;
                }
            }
            event.preventDefault();
            return;
        }
        const popupTarget = event.target.closest('[data-notify-popup-toggle]');
        if (popupTarget) {
            notificationState.popupEnabled = !notificationState.popupEnabled;
            persistPreference(NOTIFY_POPUP_KEY, notificationState.popupEnabled);
            renderNotificationControls();
            event.preventDefault();
            return;
        }
        const trigger = event.target.closest('.wa-client-button');
        if (!trigger) {
            return;
        }
        const payload = parseClientButtonPayload(trigger);
        const clientId = parseInt(trigger.getAttribute('data-client-id') || (payload ? payload.id : 0) || '0', 10) || 0;
        event.preventDefault();

        const applyPayload = (data) => {
            const nextPayload = Object.assign({}, payload || {}, data || {});
            const { variant, label } = resolveClientVariant(nextPayload);
            trigger.classList.remove('wa-client-button--active', 'wa-client-button--inactive', 'wa-client-button--no-protocol', 'wa-client-button--create');
            trigger.classList.add(`wa-client-button--${variant}`);
            trigger.textContent = label;
            trigger.setAttribute('data-client-preview', JSON.stringify(nextPayload));
            if (nextPayload.id) {
                trigger.setAttribute('data-client-id', String(nextPayload.id));
            }
            return nextPayload;
        };

        if (clientId > 0) {
            trigger.disabled = true;
            trigger.classList.add('is-loading');
            getJson(`<?= url('whatsapp/client-summary'); ?>?id=${clientId}`)
                .then((response) => {
                    const refreshed = response && response.client ? response.client : null;
                    const merged = applyPayload(refreshed);
                    openClientPopover(merged);
                })
                .catch((error) => {
                    console.error('Falha ao atualizar cliente', error);
                    const merged = applyPayload(payload || {});
                    openClientPopover(merged);
                })
                .finally(() => {
                    trigger.disabled = false;
                    trigger.classList.remove('is-loading');
                });
        } else if (payload) {
            const merged = applyPayload(payload);
            openClientPopover(merged);
        }
    });
    clientCloseButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            closeClientPopover();
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && clientPopover && clientPopover.classList.contains('is-visible')) {
            closeClientPopover();
        }
    });
})();
</script>
