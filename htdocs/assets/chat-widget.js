(function () {
    const widget = document.querySelector('[data-floating-chat]');
    if (!widget) {
        return;
    }

    const trigger = widget.querySelector('.floating-trigger');
    const triggerLabel = trigger ? trigger.querySelector('span') : null;
    const panel = widget.querySelector('.floating-panel');
    const closeBtn = widget.querySelector('.floating-close');
    const intro = widget.querySelector('[data-floating-intro]');
    const form = widget.querySelector('[data-floating-chat-form]');
    const feedback = widget.querySelector('[data-floating-feedback]');
    const statusBox = widget.querySelector('[data-floating-status]');
    const statusLabel = widget.querySelector('[data-floating-status-label]');
    const statusAgent = widget.querySelector('[data-floating-status-agent]');
    const session = widget.querySelector('[data-floating-session]');
    const messageList = widget.querySelector('[data-floating-messages]');
    const replyForm = widget.querySelector('[data-floating-reply]');
    const replyInput = widget.querySelector('[data-floating-reply-input]');
    const storageKey = widget.getAttribute('data-storage-key') || 'seloid-floating-lead';
    const TRIGGER_CONFIRM_TIMEOUT = 5000;
    const MESSAGE_POLL_BASE_DELAY = 3000;
    const MESSAGE_POLL_MAX_DELAY = 8000;
    const MESSAGE_POLL_IDLE_STEP = 1000;
    const baseEndpoint = form ? form.action.replace(/\/(external-thread)\/?$/, '/$1') : '';
    const standaloneMode = widget.getAttribute('data-chat-standalone') === 'true';
    const autoOpenAttr = widget.getAttribute('data-auto-open') === 'true';
    const bodyAutoOpen = document.body && document.body.getAttribute('data-chat-auto-open') === 'true';

    let statusTimer = null;
    let messageTimer = null;
    let messagePollDelay = MESSAGE_POLL_BASE_DELAY;
    let messagePollInFlight = false;
    let messagePollAbortController = null;
    let lastMessageId = 0;
    let activeLeadToken = null;
    let triggerConfirming = false;
    let triggerConfirmTimer = null;

    const updateTriggerLabel = () => {
        if (!triggerLabel) {
            return;
        }
        if (activeLeadToken) {
            triggerLabel.textContent = triggerConfirming ? 'Finalizar agora' : 'Finalizar conversa';
            return;
        }
        triggerLabel.textContent = 'Iniciar conversa';
    };

    const resetTriggerConfirmation = () => {
        triggerConfirming = false;
        if (triggerConfirmTimer) {
            clearTimeout(triggerConfirmTimer);
            triggerConfirmTimer = null;
        }
        updateTriggerLabel();
    };

    const requestTriggerConfirmation = () => {
        triggerConfirming = true;
        updateTriggerLabel();
        if (triggerConfirmTimer) {
            clearTimeout(triggerConfirmTimer);
        }
        triggerConfirmTimer = setTimeout(() => {
            triggerConfirming = false;
            triggerConfirmTimer = null;
            updateTriggerLabel();
        }, TRIGGER_CONFIRM_TIMEOUT);
    };

    const syncTriggerVisibility = () => {
        if (!trigger) {
            return;
        }
        if (standaloneMode) {
            trigger.hidden = true;
            return;
        }
        const panelVisible = panel && !panel.hasAttribute('hidden');
        const introVisible = intro && !intro.hasAttribute('hidden');
        trigger.hidden = Boolean(panelVisible && introVisible);
    };

    const togglePanel = (force) => {
        if (!panel) {
            return;
        }
        const shouldOpen = typeof force === 'boolean' ? force : panel.hasAttribute('hidden');
        if (shouldOpen) {
            panel.removeAttribute('hidden');
            trigger?.setAttribute('aria-expanded', 'true');
        } else {
            panel.setAttribute('hidden', '');
            trigger?.setAttribute('aria-expanded', 'false');
        }
        syncTriggerVisibility();
        resetTriggerConfirmation();
    };

    const buildStatusUrl = (token) => {
        if (!baseEndpoint || !token) {
            return null;
        }
        return `${baseEndpoint}/${token}/status`;
    };

    const buildMessagesUrl = (token) => {
        if (!baseEndpoint || !token) {
            return null;
        }
        return `${baseEndpoint}/${token}/messages`;
    };

    const persistLead = (payload) => {
        if (!payload || !payload.lead_token) {
            return;
        }
        try {
            const data = {
                token: payload.lead_token,
                thread: payload.thread_id || null
            };
            localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (error) {
            // ignore storage errors
        }
    };

    const loadStoredLead = () => {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) {
                return null;
            }
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    };

    const clearStoredLead = () => {
        try {
            localStorage.removeItem(storageKey);
        } catch (error) {
            // ignore
        }
    };

    const escapeHtml = (value) => String(value || '').replace(/[&<>"]/g, (match) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
    })[match]);

    const showStatus = (state = {}) => {
        if (!statusBox || !statusLabel) {
            return;
        }
        statusBox.hidden = false;
        const status = String(state.status || 'pending');
        if (status === 'assigned') {
            const agent = state.agent_name ? String(state.agent_name) : '';
            statusLabel.textContent = agent
                ? `Agente: ${agent} está atendendo.`
                : 'Agente: atendimento em andamento.';
        } else {
            statusLabel.textContent = 'Conversa aberta. Aguardando um agente disponível...';
        }
        if (statusAgent) {
            statusAgent.hidden = true;
            statusAgent.textContent = '';
        }
    };

    const renderMessage = (message) => {
        if (!message) {
            return '';
        }
        const direction = message.direction || 'agent';
        const classes = ['floating-message'];
        if (direction === 'visitor') {
            classes.push('visitor');
        } else if (direction === 'system') {
            classes.push('system');
        } else {
            classes.push('agent');
        }
        const timestamp = new Date((message.created_at || Date.now() / 1000) * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        return `
            <article class="${classes.join(' ')}" data-message-id="${message.id}">
                <header>${escapeHtml(message.author || 'Equipe')} · ${escapeHtml(timestamp)}</header>
                <p>${escapeHtml(message.body || '').replace(/\n/g, '<br>')}</p>
            </article>
        `;
    };

    const resetMessages = () => {
        lastMessageId = 0;
        if (messageList) {
            messageList.innerHTML = '';
        }
    };

    const appendMessages = (messages) => {
        if (!messageList || !Array.isArray(messages) || messages.length === 0) {
            return 0;
        }
        const fragments = [];
        messages.forEach((message) => {
            if (message.id && message.id > lastMessageId) {
                lastMessageId = message.id;
            }
            if (message.direction === 'system') {
                return;
            }
            fragments.push(renderMessage(message));
        });
        if (fragments.length === 0) {
            return 0;
        }
        messageList.insertAdjacentHTML('beforeend', fragments.join(''));
        messageList.scrollTop = messageList.scrollHeight;
        return fragments.length;
    };

    const stopStatusPolling = () => {
        if (statusTimer) {
            clearInterval(statusTimer);
            statusTimer = null;
        }
    };

    const pollStatusOnce = async (token) => {
        const url = buildStatusUrl(token);
        if (!url) {
            return;
        }
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                if (response.status === 404) {
                    clearStoredLead();
                    exitChatSession();
                }
                return;
            }
            const payload = await response.json();
            showStatus(payload);
            if (payload.status === 'assigned') {
                stopStatusPolling();
            }
        } catch (error) {
            // ignore polling errors
        }
    };

    const startStatusPolling = (token) => {
        if (!token) {
            return;
        }
        stopStatusPolling();
        pollStatusOnce(token);
        statusTimer = setInterval(() => pollStatusOnce(token), 6000);
    };

    const scheduleMessagePolling = (token, delay) => {
        if (messageTimer) {
            clearTimeout(messageTimer);
            messageTimer = null;
        }
        messageTimer = setTimeout(() => pollMessagesOnce(token), delay);
    };

    const pollMessagesOnce = async (token) => {
        if (!token || messagePollInFlight) {
            return;
        }
        const baseUrl = buildMessagesUrl(token);
        if (!baseUrl) {
            return;
        }
        let url = baseUrl;
        if (lastMessageId > 0) {
            url += `?after=${lastMessageId}`;
        }

        let shouldContinue = true;
        messagePollInFlight = true;
        messagePollAbortController = new AbortController();

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: messagePollAbortController.signal
            });

            if (!response.ok) {
                if (response.status === 404) {
                    clearStoredLead();
                    exitChatSession();
                    shouldContinue = false;
                }
                return;
            }

            const payload = await response.json();
            if (payload.lead) {
                showStatus(payload.lead);
            }
            const added = appendMessages(payload.messages || []);
            messagePollDelay = added > 0
                ? MESSAGE_POLL_BASE_DELAY
                : Math.min(MESSAGE_POLL_MAX_DELAY, messagePollDelay + MESSAGE_POLL_IDLE_STEP);
        } catch (error) {
            messagePollDelay = Math.min(MESSAGE_POLL_MAX_DELAY, messagePollDelay + MESSAGE_POLL_IDLE_STEP);
        } finally {
            messagePollInFlight = false;
            if (messagePollAbortController) {
                messagePollAbortController = null;
            }
            if (shouldContinue) {
                scheduleMessagePolling(token, messagePollDelay);
            }
        }
    };

    const stopMessagePolling = () => {
        if (messageTimer) {
            clearTimeout(messageTimer);
            messageTimer = null;
        }
        if (messagePollAbortController) {
            messagePollAbortController.abort();
            messagePollAbortController = null;
        }
        messagePollInFlight = false;
    };

    const startMessagePolling = (token) => {
        if (!token) {
            return;
        }
        stopMessagePolling();
        messagePollDelay = MESSAGE_POLL_BASE_DELAY;
        pollMessagesOnce(token);
    };

    const handleVisibilityChange = () => {
        if (document.hidden) {
            stopMessagePolling();
            stopStatusPolling();
            return;
        }
        if (activeLeadToken) {
            startStatusPolling(activeLeadToken);
            startMessagePolling(activeLeadToken);
        }
    };

    const enterChatSession = (token) => {
        if (!token || !session) {
            return;
        }
        activeLeadToken = token;
        if (intro) {
            intro.setAttribute('hidden', '');
        }
        session.hidden = false;
        resetMessages();
        showStatus({ status: 'pending' });
        startStatusPolling(token);
        startMessagePolling(token);
        resetTriggerConfirmation();
        syncTriggerVisibility();
    };

    function exitChatSession() {
        stopMessagePolling();
        stopStatusPolling();
        activeLeadToken = null;
        if (session) {
            session.hidden = true;
        }
        if (intro) {
            intro.removeAttribute('hidden');
        }
        if (statusBox) {
            statusBox.hidden = true;
        }
        resetMessages();
        if (replyInput) {
            replyInput.value = '';
        }
        resetTriggerConfirmation();
        syncTriggerVisibility();
    }

    const shouldAutoOpenChat = () => {
        if (standaloneMode || autoOpenAttr || bodyAutoOpen) {
            return true;
        }
        try {
            const params = new URLSearchParams(window.location.search);
            const queryFlag = params.get('chat') || params.get('chat_widget') || params.get('open_chat');
            if (queryFlag) {
                const normalized = queryFlag.toString().toLowerCase();
                if (['1', 'true', 'abrir', 'open'].includes(normalized)) {
                    return true;
                }
            }
            if (window.location.hash && window.location.hash.toLowerCase() === '#chat') {
                return true;
            }
        } catch (error) {
            // ignore URL parse errors
        }
        return false;
    };

    const autoOpenChatPanel = () => {
        if (!shouldAutoOpenChat()) {
            return;
        }
        togglePanel(true);
        if (!activeLeadToken && form && typeof form.scrollIntoView === 'function') {
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    trigger?.addEventListener('click', () => {
        if (activeLeadToken) {
            const isPanelHidden = panel?.hasAttribute('hidden');
            if (isPanelHidden) {
                togglePanel(true);
                return;
            }
            if (!triggerConfirming) {
                requestTriggerConfirmation();
                return;
            }
            clearStoredLead();
            exitChatSession();
            togglePanel(false);
            return;
        }
        togglePanel();
    });

    closeBtn?.addEventListener('click', () => togglePanel(false));

    document.addEventListener('visibilitychange', handleVisibilityChange);

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (feedback) {
            feedback.textContent = 'Criando conversa...';
        }
        const formData = new FormData(form);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch (jsonError) {
                // ignore parse errors
            }

            if (!response.ok) {
                const message = extractErrorMessage(payload) || 'Não foi possível abrir a conversa agora. Verifique os dados e tente novamente.';
                if (feedback) {
                    feedback.textContent = message;
                }
                return;
            }

            if (feedback) {
                feedback.textContent = 'Conversa criada! Um especialista assumirá em instantes.';
            }
            form.reset();
            persistLead(payload);
            if (payload && payload.lead_token) {
                enterChatSession(payload.lead_token);
            }
        } catch (error) {
            if (feedback) {
                feedback.textContent = 'Não foi possível abrir a conversa agora. Tente novamente.';
            }
        }
    });

    replyForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeLeadToken || !replyInput) {
            return;
        }
        const messageText = replyInput.value.trim();
        if (messageText.length === 0) {
            return;
        }
        const url = buildMessagesUrl(activeLeadToken);
        if (!url) {
            return;
        }
        const submitButton = replyForm.querySelector('button');
        submitButton?.setAttribute('disabled', 'disabled');
        try {
            const formData = new FormData();
            formData.append('body', messageText);
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok) {
                const message = extractErrorMessage(payload) || 'Não foi possível enviar a mensagem.';
                alert(message);
                return;
            }
            replyInput.value = '';
            if (payload && payload.message) {
                appendMessages([payload.message]);
            }
        } catch (error) {
            alert('Não foi possível enviar a mensagem agora.');
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });

    const stored = loadStoredLead();
    if (stored && stored.token) {
        enterChatSession(stored.token);
    }
    updateTriggerLabel();
    syncTriggerVisibility();
    autoOpenChatPanel();
})();

function extractErrorMessage(payload) {
    if (!payload) {
        return null;
    }
    if (typeof payload.message === 'string') {
        return payload.message;
    }

    if (payload.errors && typeof payload.errors === 'object') {
        const values = Object.values(payload.errors)
            .flat()
            .filter(Boolean)
            .map(String);
        if (values.length > 0) {
            return values[0];
        }
    }

    return null;
}
