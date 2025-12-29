(function () {
    var chatRoot = document.querySelector('[data-chat-app]');
    if (!chatRoot) {
        return;
    }

    var threadList = chatRoot.querySelector('[data-chat-thread-list]');
    var messagesContainer = chatRoot.querySelector('[data-chat-messages]');
    var composeForm = chatRoot.querySelector('[data-chat-form]');
    var composerSubmit = composeForm ? composeForm.querySelector('button[type="submit"]') : null;
    var refreshButton = chatRoot.querySelector('[data-chat-refresh]');
    var modals = document.querySelectorAll('[data-chat-modal]');
    var openModalButtons = document.querySelectorAll('[data-chat-open-modal]');
    var modalCloseButtons = document.querySelectorAll('[data-chat-close-modal]');
    var newChatForm = document.querySelector('[data-new-chat-form]');
    var userPickerButtons = document.querySelectorAll('[data-user-picker] [data-user-id]');
    var userField = document.querySelector('[data-user-field]');
    var groupChatForm = document.querySelector('[data-group-chat-form]');
    var presenceButtons = chatRoot.querySelectorAll('[data-start-chat]');
    var messageInput = chatRoot.querySelector('[data-chat-input]');
    var emojiToggle = chatRoot.querySelector('[data-emoji-toggle]');
    var emojiPanel = chatRoot.querySelector('[data-emoji-panel]');
    var emojiButtons = document.querySelectorAll('[data-emoji-button]');
    var toastContainer = document.querySelector('[data-chat-toast-container]');
    var externalStatusWrapper = chatRoot.querySelector('[data-thread-external-wrapper]');
    var externalStatusLabelEl = chatRoot.querySelector('[data-thread-external-label]');
    var externalStatusAgentEl = chatRoot.querySelector('[data-thread-external-agent]');
    var closeThreadButtons = chatRoot.querySelectorAll('[data-close-thread]');
    var closeThreadHint = chatRoot.querySelector('[data-close-hint]');
    var headerClosedBadge = chatRoot.querySelector('[data-thread-closed-badge]');
    var closedBanner = chatRoot.querySelector('[data-chat-closed-banner]');
    var closeThreadDefaultLabel = closeThreadButtons.length > 0 ? closeThreadButtons[0].textContent : 'Finalizar atendimento';
    var closeThreadConfirmLabel = closeThreadButtons.length > 0 ? (closeThreadButtons[0].getAttribute('data-confirm-label') || 'Finalizar agora') : 'Finalizar agora';
    var closeThreadConfirmTimer = null;

    var threadLastMap = {};

    function forEachCloseButton(callback) {
        if (!closeThreadButtons || closeThreadButtons.length === 0 || typeof callback !== 'function') {
            return;
        }
        closeThreadButtons.forEach(function (button) {
            callback(button);
        });
    }

    var endpoints = {
        threads: chatRoot.getAttribute('data-threads-endpoint') || '',
        base: chatRoot.getAttribute('data-thread-base') || '',
        markRead: chatRoot.getAttribute('data-mark-read-base') || '',
        create: chatRoot.getAttribute('data-create-thread') || '',
        createGroup: chatRoot.getAttribute('data-create-group') || '',
        claimExternal: chatRoot.getAttribute('data-claim-external-base') || '',
        externalStatus: chatRoot.getAttribute('data-external-status-base') || ''
    };

    var closeThreadBase = chatRoot.getAttribute('data-close-thread-base') || '';
    var csrf = chatRoot.getAttribute('data-csrf') || '';
    var currentUser = parseInt(chatRoot.getAttribute('data-current-user') || '0', 10) || 0;
    var currentUserName = chatRoot.getAttribute('data-current-user-name') || '';
    var currentThreadId = parseInt(chatRoot.getAttribute('data-active-thread') || '0', 10) || 0;
    var lastMessageId = parseInt(chatRoot.getAttribute('data-last-message-id') || '0', 10) || 0;
    var isThreadClosed = chatRoot.getAttribute('data-thread-closed') === '1';
    var currentThreadType = (chatRoot.getAttribute('data-active-thread-type') || 'direct').toLowerCase();
    var pollingTimer = null;

    var parentOrigin = (function () {
        try {
            if (window.location && window.location.origin) {
                return window.location.origin;
            }
            return window.location.protocol + '//' + window.location.host;
        } catch (error) {
            return '*';
        }
    })();

    function isPendingExternalThread(thread) {
        if (!thread || (thread.type || 'direct').toLowerCase() !== 'external') {
            return false;
        }
        var lead = thread.external_lead || null;
        if (!lead) {
            return false;
        }
        var status = String(lead.status || '').toLowerCase();
        return status === 'pending';
    }

    function summarizeUnreadCounters(list) {
        var counters = { external: 0, internal: 0 };
        if (!Array.isArray(list)) {
            return counters;
        }
        list.forEach(function (thread) {
            if (!thread) {
                return;
            }
            var unread = parseInt(thread.unread_count || 0, 10) || 0;
            var type = (thread.type || 'direct').toLowerCase();
            if (unread > 0) {
                if (type === 'external') {
                    counters.external += unread;
                } else {
                    counters.internal += unread;
                }
                return;
            }
            if (type === 'external' && isPendingExternalThread(thread)) {
                counters.external += 1;
            }
        });
        return counters;
    }

    function broadcastUnreadSummary(list) {
        var summary = summarizeUnreadCounters(list);
        if (window.parent && window.parent !== window) {
            try {
                window.parent.postMessage({
                    type: 'chat:thread-unread-summary',
                    payload: summary
                }, parentOrigin || '*');
            } catch (error) {
                window.parent.postMessage({
                    type: 'chat:thread-unread-summary',
                    payload: summary
                }, '*');
            }
        }
        if (typeof window.CustomEvent === 'function' && typeof window.dispatchEvent === 'function') {
            window.dispatchEvent(new CustomEvent('chat:thread-unread-summary', { detail: summary }));
        }
    }

    function api(path, options) {
        var headers = options && options.headers ? options.headers : {};
        headers['X-Requested-With'] = 'XMLHttpRequest';
        headers['X-CSRF-TOKEN'] = csrf;
        options = options || {};
        options.headers = headers;
        return fetch(path, options).then(function (response) {
            if (!response.ok) {
                return response.json().catch(function () {
                    return Promise.reject(new Error('Erro inesperado.'));
                }).then(function (payload) {
                    return Promise.reject(payload);
                });
            }
            return response.json();
        });
    }

    function renderThreads(list) {
        if (!threadList) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            threadList.innerHTML = '<p class="chat-empty">Nenhuma conversa encontrada.</p>';
            return;
        }

        threadList.innerHTML = list.map(function (thread) {
            var threadId = parseInt(thread.id, 10) || 0;
            var unread = parseInt(thread.unread_count || 0, 10) || 0;
            var preview = thread.last_message_preview ? thread.last_message_preview : '';
            var lastAt = thread.last_message_created_at ? parseInt(thread.last_message_created_at, 10) : null;
            var lastLabel = lastAt ? new Date(lastAt * 1000).toLocaleString('pt-BR', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '';
            var type = (thread.type || 'direct').toLowerCase();
            var status = String(thread.status || 'open').toLowerCase();
            var badges = '';
            if (type === 'group') {
                badges += '<span class="chat-thread-badge">Grupo</span>';
            }
            if (type === 'external') {
                badges += '<span class="chat-thread-badge is-external">Externo</span>';
            }
            var previewBlock = preview ? '<p>' + escapeHtml(preview) + '</p>' : '';
            var lastMessageId = parseInt(thread.last_message_id || 0, 10) || 0;
            var lastAuthorId = parseInt(thread.last_message_author_id || 0, 10) || 0;
            var statusBlock = '';
            if (type === 'external') {
                var lead = thread.external_lead || null;
                var statusClass = 'chat-thread-status is-warning';
                var label = 'Aguardando agente';
                if (lead && lead.status && String(lead.status).toLowerCase() !== 'pending') {
                    statusClass = 'chat-thread-status is-success';
                    var agent = '';
                    if (lead.claimed_by_name) {
                        agent = String(lead.claimed_by_name).trim();
                    }
                    label = agent ? 'Com ' + agent : 'Agente atribuído';
                }
                statusBlock = '<div class="chat-thread-status-row"><span class="' + statusClass + '">' + escapeHtml(label) + '</span></div>';
            }
            return '<button type="button" class="chat-thread' + (threadId === currentThreadId ? ' is-active' : '') + '" data-thread-id="' + threadId + '" data-thread-type="' + escapeHtml(type) + '" data-last-message-id="' + lastMessageId + '" data-last-author-id="' + lastAuthorId + '" data-thread-status="' + escapeHtml(status) + '">' +
                '<div class="chat-thread-text"><div class="chat-thread-headline"><strong>' + escapeHtml(thread.display_name || 'Chat') + '</strong>' + badges + '</div>' +
                previewBlock + statusBlock + '</div>' +
                '<div class="chat-thread-meta">' +
                (lastLabel ? '<span>' + escapeHtml(lastLabel) + '</span>' : '') +
                (unread > 0 ? '<span class="chat-unread">' + unread + '</span>' : '') +
                '</div>' +
                '</button>';
        }).join('');

        threadList.querySelectorAll('[data-thread-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                var threadId = parseInt(button.getAttribute('data-thread-id') || '0', 10) || 0;
                var status = (button.getAttribute('data-thread-status') || 'open').toLowerCase();
                var typeAttr = (button.getAttribute('data-thread-type') || 'direct').toLowerCase();
                if (threadId > 0) {
                    switchThread(threadId, status, typeAttr);
                }
            });
        });

        syncExternalStatusFromThreads(list);
    }

    function renderMessages(messages, append) {
        if (!messagesContainer) {
            return;
        }

        var fragment = messages.map(function (message) {
            var authorId = parseInt(message.author_id, 10) || 0;
            var visitorName = message.external_author || '';
            var hasExternalAuthor = Boolean(visitorName);
            var isSystem = !hasExternalAuthor && (authorId === 0 || String(message.is_system) === '1');
            var mine = !hasExternalAuthor && authorId === currentUser;
            var author;
            if (hasExternalAuthor) {
                author = visitorName;
            } else if (isSystem) {
                author = 'Sistema';
            } else if (mine) {
                author = 'Você';
            } else {
                author = message.author_name || 'Colaborador';
            }
            var body = escapeHtml(message.body || '');
            var timestamp = formatTime(message.created_at);
            var classes = 'chat-message';
            if (mine) {
                classes += ' is-mine';
            }
            if (isSystem) {
                classes += ' is-system';
            }
            if (hasExternalAuthor) {
                classes += ' is-visitor';
            }
            return '<article class="' + classes + '" data-message-id="' + message.id + '"><header><strong>' + escapeHtml(author) + '</strong><span>' + escapeHtml(timestamp) + '</span></header><p>' + body.replace(/\n/g, '<br>') + '</p></article>';
        });

        if (append) {
            messagesContainer.insertAdjacentHTML('beforeend', fragment.join(''));
        } else {
            messagesContainer.innerHTML = fragment.join('');
        }

        if (messages.length > 0) {
            lastMessageId = parseInt(messages[messages.length - 1].id, 10);
            if (currentThreadId > 0) {
                threadLastMap[currentThreadId] = lastMessageId;
            }
            scrollMessagesToBottom();
            markAsRead();
        } else if (!append) {
            lastMessageId = 0;
        }
    }

    function updateExternalStatusUI(status, agentName) {
        if (!externalStatusWrapper || !externalStatusLabelEl) {
            return;
        }
        externalStatusLabelEl.classList.remove('is-warning', 'is-success', 'is-muted');
        if (status === 'pending') {
            externalStatusLabelEl.classList.add('is-warning');
            externalStatusLabelEl.textContent = 'Aguardando agente';
        } else if (status === 'closed') {
            externalStatusLabelEl.classList.add('is-muted');
            externalStatusLabelEl.textContent = 'Atendimento finalizado';
        } else {
            externalStatusLabelEl.classList.add('is-success');
            var label = agentName ? 'Atendimento com ' + agentName : 'Agente atribuído';
            externalStatusLabelEl.textContent = label;
        }
        if (externalStatusAgentEl) {
            if (status === 'pending' || !agentName) {
                externalStatusAgentEl.hidden = true;
            } else {
                externalStatusAgentEl.hidden = false;
                externalStatusAgentEl.textContent = 'Especialista: ' + agentName;
            }
        }
        document.querySelectorAll('[data-claim-external]').forEach(function (button) {
            button.disabled = true;
            button.textContent = status === 'closed' ? 'Atendimento encerrado' : 'Atendimento assumido';
        });
    }

    function syncCloseButtonVisibility() {
        var allowClose = currentThreadType === 'external' && !isThreadClosed;
        forEachCloseButton(function (button) {
            button.hidden = !allowClose;
            button.disabled = !allowClose;
        });
        if (!allowClose) {
            resetCloseButtonState();
        }
        if (closeThreadHint) {
            closeThreadHint.hidden = true;
        }
    }

    function setThreadClosedState(closed) {
        isThreadClosed = Boolean(closed);
        chatRoot.setAttribute('data-thread-closed', isThreadClosed ? '1' : '0');
        if (composeForm) {
            composeForm.classList.toggle('is-disabled', isThreadClosed);
        }
        if (messageInput) {
            messageInput.disabled = isThreadClosed;
        }
        if (composerSubmit) {
            composerSubmit.disabled = isThreadClosed;
        }
        syncCloseButtonVisibility();
        if (headerClosedBadge) {
            headerClosedBadge.hidden = !isThreadClosed;
        }
        if (closedBanner) {
            closedBanner.hidden = !isThreadClosed;
        }
        if (isThreadClosed) {
            closeEmojiPanel();
        }
    }

    function resetCloseButtonState() {
        forEachCloseButton(function (button) {
            button.removeAttribute('data-confirming');
            button.classList.remove('is-armed');
            button.textContent = closeThreadDefaultLabel;
        });
        if (closeThreadConfirmTimer) {
            clearTimeout(closeThreadConfirmTimer);
            closeThreadConfirmTimer = null;
        }
        if (closeThreadHint) {
            closeThreadHint.hidden = true;
        }
    }

    function syncExternalStatusFromThreads(list) {
        if (!externalStatusWrapper || !Array.isArray(list) || currentThreadId <= 0) {
            return;
        }
        var current = null;
        for (var i = 0; i < list.length; i += 1) {
            var thread = list[i];
            if (parseInt(thread.id, 10) === currentThreadId) {
                current = thread;
                break;
            }
        }
        if (!current || String(current.type || '').toLowerCase() !== 'external') {
            return;
        }
        var lead = current.external_lead || null;
        if (!lead) {
            return;
        }
        var status = String(lead.status || 'pending').toLowerCase();
        var agentName = '';
        if (lead.claimed_by_name) {
            agentName = String(lead.claimed_by_name).trim();
        } else if (lead.agent_name) {
            agentName = String(lead.agent_name).trim();
        }
        updateExternalStatusUI(status, agentName);
    }

    function claimExternalThread(threadId, button) {
        if (!endpoints.claimExternal || threadId <= 0) {
            return;
        }
        var url = endpoints.claimExternal + '/' + threadId + '/claim';
        var formData = new FormData();
        formData.append('_token', csrf);
        if (button) {
            button.disabled = true;
        }
        api(url, {
            method: 'POST',
            body: formData
        }).then(function () {
            updateExternalStatusUI('assigned', currentUserName || 'você');
            fetchThreads();
        }).catch(function (error) {
            var message = error && error.errors ? Object.values(error.errors)[0] : 'Não foi possível assumir este atendimento.';
            alert(message);
            if (button) {
                button.disabled = false;
            }
        });
    }

    function closeActiveThread() {
        if (!closeThreadBase || currentThreadId <= 0) {
            return;
        }
        if (currentThreadType !== 'external') {
            alert('Somente atendimentos externos podem ser finalizados.');
            return;
        }
        var url = closeThreadBase + '/' + currentThreadId + '/close';
        var formData = new FormData();
        formData.append('_token', csrf);
        forEachCloseButton(function (button) { button.disabled = true; });
        api(url, {
            method: 'POST',
            body: formData
        }).then(function () {
            setThreadClosedState(true);
            resetCloseButtonState();
            showToast('Chat finalizado', 'Este atendimento foi encerrado.');
            fetchMessages(currentThreadId, { after: null });
            fetchThreads();
            if (externalStatusWrapper) {
                updateExternalStatusUI('closed');
            }
        }).catch(function (error) {
            var message = error && error.errors ? Object.values(error.errors)[0] : 'Não foi possível finalizar esta conversa.';
            alert(message);
            forEachCloseButton(function (button) { button.disabled = false; });
            resetCloseButtonState();
        });
    }

    function fetchThreads() {
        if (!endpoints.threads) {
            return;
        }
        api(endpoints.threads).then(function (payload) {
            if (payload && Array.isArray(payload.threads)) {
                renderThreads(payload.threads);
                handleThreadNotifications(payload.threads);
                broadcastUnreadSummary(payload.threads);
            }
        }).catch(function () {});
    }

    function fetchMessages(threadId, options) {
        options = options || {};
        if (!endpoints.base || threadId <= 0) {
            return;
        }
        var url = endpoints.base + '/' + threadId + '/messages';
        if (options.after) {
            url += '?after=' + options.after;
        }
        api(url).then(function (payload) {
            if (payload && Array.isArray(payload.messages)) {
                renderMessages(payload.messages, Boolean(options.after));
            }
        }).catch(function () {});
    }

    function updateComposerAction(threadId) {
        if (!composeForm || !endpoints.base || threadId <= 0) {
            return;
        }
        composeForm.setAttribute('action', endpoints.base + '/' + threadId + '/messages');
    }

    function switchThread(threadId, status, type) {
        currentThreadId = threadId;
        currentThreadType = typeof type === 'string' ? type.toLowerCase() : 'direct';
        chatRoot.setAttribute('data-active-thread-type', currentThreadType);
        var normalizedStatus = typeof status === 'string' ? status.toLowerCase() : null;
        setThreadClosedState(normalizedStatus === 'closed');
        updateComposerAction(threadId);
        fetchMessages(threadId, { after: null });
        fetchThreads();
    }

    function submitMessage(event) {
        event.preventDefault();
        if (!composeForm || currentThreadId <= 0) {
            return;
        }
        if (isThreadClosed) {
            alert('Esta conversa foi finalizada.');
            return;
        }
        var formData = new FormData(composeForm);
        var url = composeForm.getAttribute('action');
        api(url, {
            method: 'POST',
            body: formData
        }).then(function (payload) {
            composeForm.reset();
            closeEmojiPanel();
            if (payload && payload.message) {
                var after = lastMessageId > 0 ? lastMessageId : null;
                fetchMessages(currentThreadId, { after: after });
                fetchThreads();
            }
        }).catch(function (error) {
            var message = extractErrorMessage(error);
            if (message) {
                alert(message);
                if (/finalizad/i.test(message)) {
                    setThreadClosedState(true);
                }
            }
        });
    }

    function handleThreadResponse(payload) {
        if (payload && payload.thread && payload.thread.id) {
            var threadId = parseInt(payload.thread.id, 10) || 0;
            if (threadId > 0) {
                fetchThreads();
                var threadType = String(payload.thread.type || 'direct');
                switchThread(threadId, 'open', threadType);
            }
        }
    }

    function bindClaimButtons() {
        document.querySelectorAll('[data-claim-external]').forEach(function (button) {
            if (button.getAttribute('data-claim-bound') === 'true') {
                return;
            }
            button.setAttribute('data-claim-bound', 'true');
            button.addEventListener('click', function () {
                var threadId = parseInt(button.getAttribute('data-thread-id') || '0', 10) || 0;
                if (threadId > 0) {
                    claimExternalThread(threadId, button);
                }
            });
        });
    }

    function submitNewChat(event) {
        event.preventDefault();
        if (!newChatForm) {
            return;
        }
        var selectedUserId = userField ? parseInt(userField.value || '0', 10) || 0 : 0;
        if (selectedUserId <= 0) {
            alert('Selecione um colaborador para iniciar o chat.');
            return;
        }

        var formData = new FormData(newChatForm);
        var action = newChatForm.getAttribute('action') || endpoints.create;
        api(action, {
            method: 'POST',
            body: formData
        }).then(function (payload) {
            closeModal('direct');
            handleThreadResponse(payload);
        }).catch(function (error) {
            var message = error && error.errors ? Object.values(error.errors)[0] : 'Não foi possível criar o chat.';
            alert(message);
        });
    }

    function submitGroupChat(event) {
        event.preventDefault();
        if (!groupChatForm) {
            return;
        }
        var action = groupChatForm.getAttribute('action') || endpoints.createGroup;
        if (!action) {
            return;
        }
        var formData = new FormData(groupChatForm);
        api(action, {
            method: 'POST',
            body: formData
        }).then(function (payload) {
            groupChatForm.reset();
            closeModal('group');
            handleThreadResponse(payload);
        }).catch(function (error) {
            var message = error && error.errors ? Object.values(error.errors)[0] : 'Não foi possível criar o grupo.';
            alert(message);
        });
    }

    function startChatFromPresence(event) {
        var button = event.currentTarget;
        var userId = parseInt(button.getAttribute('data-start-chat') || '0', 10) || 0;
        if (userId <= 0 || !endpoints.create) {
            return;
        }
        var formData = new FormData();
        formData.append('user_id', String(userId));
        formData.append('_token', csrf);
        api(endpoints.create, {
            method: 'POST',
            body: formData
        }).then(function (payload) {
            handleThreadResponse(payload);
        }).catch(function (error) {
            var message = error && error.errors ? Object.values(error.errors)[0] : 'Não foi possível iniciar o chat.';
            alert(message);
        });
    }

    function setSelectedUser(userId, autoSubmit) {
        if (!userField) {
            return;
        }

        userField.value = userId > 0 ? String(userId) : '';

        userPickerButtons.forEach(function (button) {
            var buttonId = parseInt(button.getAttribute('data-user-id') || '0', 10) || 0;
            if (buttonId === userId) {
                button.classList.add('is-selected');
            } else {
                button.classList.remove('is-selected');
            }
        });

        if (autoSubmit && newChatForm && userId > 0) {
            newChatForm.requestSubmit();
        }
    }

    function toggleModal(target, show) {
        if (!modals || modals.length === 0) {
            return;
        }
        modals.forEach(function (modalEl) {
            if (target && modalEl.getAttribute('data-chat-modal') !== target) {
                return;
            }
            if (show) {
                modalEl.setAttribute('data-open', 'true');
            } else {
                modalEl.removeAttribute('data-open');
            }
        });
    }

    function openModal(target) {
        if (!target) {
            return;
        }
        toggleModal(target, true);
    }

    function closeModal(target) {
        if (target) {
            toggleModal(target, false);
        } else {
            toggleModal(null, false);
        }
    }

    function openEmojiPanel() {
        if (!emojiPanel) {
            return;
        }
        emojiPanel.setAttribute('data-open', 'true');
        if (emojiToggle) {
            emojiToggle.setAttribute('aria-expanded', 'true');
        }
    }

    function closeEmojiPanel() {
        if (!emojiPanel) {
            return;
        }
        emojiPanel.removeAttribute('data-open');
        if (emojiToggle) {
            emojiToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function toggleEmojiPanel() {
        if (!emojiPanel) {
            return;
        }
        var isOpen = emojiPanel.getAttribute('data-open') === 'true';
        if (isOpen) {
            closeEmojiPanel();
        } else {
            openEmojiPanel();
        }
    }

    function insertEmoji(symbol) {
        if (!messageInput || typeof symbol !== 'string') {
            return;
        }
        var start = messageInput.selectionStart || 0;
        var end = messageInput.selectionEnd || 0;
        var current = messageInput.value || '';
        messageInput.value = current.slice(0, start) + symbol + current.slice(end);
        var caret = start + symbol.length;
        messageInput.focus();
        if (typeof messageInput.setSelectionRange === 'function') {
            messageInput.setSelectionRange(caret, caret);
        }
        closeEmojiPanel();
    }

    function extractErrorMessage(payload) {
        if (!payload) {
            return null;
        }
        if (payload.errors && typeof payload.errors === 'object') {
            if (typeof payload.errors.body === 'string') {
                return payload.errors.body;
            }
            var keys = Object.keys(payload.errors);
            if (keys.length > 0) {
                var value = payload.errors[keys[0]];
                if (typeof value === 'string') {
                    return value;
                }
            }
        }
        if (typeof payload.error === 'string') {
            return payload.error;
        }
        if (typeof payload.message === 'string') {
            return payload.message;
        }
        if (payload instanceof Error && payload.message) {
            return payload.message;
        }
        return null;
    }

    function showToast(title, body) {
        if (!toastContainer) {
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'chat-toast';
        toast.innerHTML = '<strong>' + escapeHtml(title || 'Chat') + '</strong><p>' + escapeHtml(body || 'Nova mensagem recebida.') + '</p>';
        toastContainer.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px) scale(0.98)';
        }, 4000);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 4600);
    }

    function bootstrapThreadState() {
        if (!threadList) {
            return;
        }

        threadList.querySelectorAll('[data-thread-id]').forEach(function (button) {
            var threadId = parseInt(button.getAttribute('data-thread-id') || '0', 10) || 0;
            if (threadId <= 0) {
                return;
            }
            var lastId = parseInt(button.getAttribute('data-last-message-id') || '0', 10) || 0;
            threadLastMap[threadId] = lastId;
        });
    }

    function handleThreadNotifications(list) {
        if (!Array.isArray(list)) {
            return;
        }

        list.forEach(function (thread) {
            var threadId = parseInt(thread.id, 10) || 0;
            if (threadId <= 0) {
                return;
            }

            var lastId = parseInt(thread.last_message_id || 0, 10) || 0;
            var lastAuthor = parseInt(thread.last_message_author_id || 0, 10) || 0;
            if (lastId <= 0) {
                threadLastMap[threadId] = 0;
                return;
            }

            var previous = threadLastMap[threadId];
            if (typeof previous === 'undefined') {
                threadLastMap[threadId] = lastId;
                return;
            }

            if (lastId !== previous) {
                threadLastMap[threadId] = lastId;
                if (threadId !== currentThreadId && lastAuthor !== currentUser) {
                    var title = thread.display_name || 'Chat interno';
                    var preview = thread.last_message_preview || 'Nova mensagem recebida.';
                    showToast(title, preview);
                }
            }
        });
    }

    function scrollMessagesToBottom() {
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    function markAsRead() {
        if (!endpoints.markRead || currentThreadId <= 0 || lastMessageId <= 0) {
            return;
        }
        var url = endpoints.markRead + '/' + currentThreadId + '/read';
        var formData = new FormData();
        formData.append('message_id', String(lastMessageId));
        formData.append('_token', csrf);
        api(url, { method: 'POST', body: formData }).catch(function () {});
    }

    function schedulePolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
        }
        pollingTimer = setInterval(function () {
            if (currentThreadId > 0) {
                var after = lastMessageId > 0 ? lastMessageId : null;
                fetchMessages(currentThreadId, { after: after });
            }
            fetchThreads();
        }, 20000);
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                default: return match;
            }
        });
    }

    function formatTime(timestamp) {
        if (!timestamp) {
            return '';
        }
        var date = new Date(timestamp * 1000);
        return date.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    updateComposerAction(currentThreadId);

    if (composeForm) {
        composeForm.addEventListener('submit', submitMessage);
    }

    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            if (currentThreadId > 0) {
                fetchMessages(currentThreadId, { after: null });
            }
            fetchThreads();
        });
    }

    if (threadList) {
        threadList.querySelectorAll('[data-thread-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                var threadId = parseInt(button.getAttribute('data-thread-id') || '0', 10) || 0;
                var status = (button.getAttribute('data-thread-status') || 'open').toLowerCase();
                var typeAttr = (button.getAttribute('data-thread-type') || 'direct').toLowerCase();
                if (threadId > 0) {
                    switchThread(threadId, status, typeAttr);
                }
            });
        });
    }

    openModalButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-chat-open-modal');
            openModal(target);
        });
    });

    modalCloseButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var parent = btn.closest('[data-chat-modal]');
            if (parent) {
                parent.removeAttribute('data-open');
            } else {
                closeModal();
            }
        });
    });

    if (modals.length > 0) {
        modals.forEach(function (modalEl) {
            modalEl.addEventListener('click', function (event) {
                if (event.target === modalEl) {
                    modalEl.removeAttribute('data-open');
                }
            });
        });
    }

    if (newChatForm) {
        newChatForm.addEventListener('submit', submitNewChat);
    }

    if (groupChatForm) {
        groupChatForm.addEventListener('submit', submitGroupChat);
    }

    forEachCloseButton(function (button) {
        button.addEventListener('click', function () {
            if (button.getAttribute('data-confirming') === '1') {
                resetCloseButtonState();
                closeActiveThread();
                return;
            }

            if (closeThreadConfirmTimer) {
                clearTimeout(closeThreadConfirmTimer);
            }

            button.setAttribute('data-confirming', '1');
            button.classList.add('is-armed');
            button.textContent = closeThreadConfirmLabel;
            if (closeThreadHint) {
                closeThreadHint.hidden = false;
                closeThreadHint.textContent = 'Confirme clicando em "' + closeThreadConfirmLabel + '".';
            }
            closeThreadConfirmTimer = setTimeout(function () {
                resetCloseButtonState();
            }, 4000);
        });
    });

    if (emojiToggle) {
        emojiToggle.addEventListener('click', function (event) {
            event.preventDefault();
            toggleEmojiPanel();
        });
    }

    emojiButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            var symbol = button.getAttribute('data-emoji-button');
            insertEmoji(symbol);
        });
    });

    document.addEventListener('click', function (event) {
        if (!emojiPanel || emojiPanel.getAttribute('data-open') !== 'true') {
            return;
        }
        var target = event.target;
        if (emojiPanel.contains(target)) {
            return;
        }
        if (emojiToggle && (emojiToggle === target || emojiToggle.contains(target))) {
            return;
        }
        closeEmojiPanel();
    });

    presenceButtons.forEach(function (button) {
        button.addEventListener('click', startChatFromPresence);
    });

    userPickerButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var userId = parseInt(button.getAttribute('data-user-id') || '0', 10) || 0;
            if (userId > 0) {
                setSelectedUser(userId, true);
            }
        });
    });

    if (userField) {
        setSelectedUser(parseInt(userField.value || '0', 10) || 0, false);
    }

    setThreadClosedState(isThreadClosed);
    bootstrapThreadState();
    scrollMessagesToBottom();
    markAsRead();
    schedulePolling();
    bindClaimButtons();
    fetchThreads();
})();
