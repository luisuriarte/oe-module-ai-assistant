/**
 * ai-chat.js - Layer 2 patient chat panel widget.
 *
 * Scoped to Milestone 6 (Layer 2 UI):
 *   1. Renders a floating chat toggle + side panel on the native SOAP form.
 *   2. Stays hidden unless an admin enabled Layer 2 (chat_enabled via module_status).
 *   3. Composer stays disabled while the server-side consent gate is closed, with the
 *      reason shown. ChatController re-checks the gate, so this is presentation only.
 *   4. Conversation history lives in this closure only: no localStorage, no sessionStorage,
 *      no IndexedDB, no cookie. Reload the page and it is gone.
 *   5. History is sent with every question and re-bounded server-side; a 'system' turn is
 *      rejected server-side, so the client cannot rewrite the model instructions.
 *   6. Answers render with their chart-section citations as chips below the bubble.
 *   7. Every string comes from ?action=module_status (chat_i18n); the Spanish literals are
 *      only fallbacks so the panel never renders blank.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */

(function () {
    'use strict';

    if (window.__OE_AI_CHAT_LOADED__) {
        return;
    }
    window.__OE_AI_CHAT_LOADED__ = true;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAiChat);
    } else {
        initAiChat();
    }

    function initAiChat() {
        // 1. Only on the native SOAP form.
        const subjArea = document.querySelector('textarea[name="subjective"]');
        const objArea  = document.querySelector('textarea[name="objective"]');
        const assArea  = document.querySelector('textarea[name="assessment"]');
        const planArea = document.querySelector('textarea[name="plan"]');

        if (!subjArea || !objArea || !assArea || !planArea) {
            return;
        }

        if (document.getElementById('oe-ai-chat-toggle')) {
            return; // already rendered
        }

        // 2. Resolve environment and ids (same sources the dictation toolbar uses).
        const urlParams = new URLSearchParams(window.location.search);
        const siteId = urlParams.get('site') || 'default';

        let pid = parseInt(
            (document.querySelector('input[name="pid"]') ? document.querySelector('input[name="pid"]').value : '')
            || urlParams.get('pid')
            || (window.top && window.top.pid ? window.top.pid : 0),
            10
        );
        if (isNaN(pid) || pid <= 0) {
            pid = 0;
        }

        let encounterId = parseInt(
            (document.querySelector('input[name="encounter"]') ? document.querySelector('input[name="encounter"]').value : '')
            || urlParams.get('encounter')
            || (window.top && window.top.encounter ? window.top.encounter : 0),
            10
        );
        if (isNaN(encounterId) || encounterId <= 0) {
            encounterId = 0;
        }

        let webRoot = '';
        if (window.top && window.top.webroot) {
            webRoot = window.top.webroot;
        } else {
            const loc = window.location.pathname;
            const idx = loc.indexOf('/interface/');
            webRoot = (idx !== -1) ? loc.substring(0, idx) : '';
        }
        const publicEndpoint = webRoot + '/interface/modules/custom_modules/oe-module-ai-assistant/public/index.php';

        // 3. Server-supplied config and translations.
        let I18N = {};
        let gateAllowed = false;
        let gateMessage = '';
        let chatReady = false;
        let busy = false;

        const MAX_QUESTION_CHARS = 4000;
        const MAX_TURNS = 20;

        /** In-memory conversation. Never written to any browser storage. */
        let history = [];

        function t(key, fallback) {
            return (I18N[key] !== undefined && I18N[key] !== '') ? I18N[key] : fallback;
        }

        function applyI18n(root) {
            const scope = root || document;
            const nodes = scope.querySelectorAll('[data-i18n]');
            for (let i = 0; i < nodes.length; i++) {
                const v = I18N[nodes[i].getAttribute('data-i18n')];
                if (v) {
                    nodes[i].textContent = v;
                }
            }
        }

        function getCsrfToken() {
            const tokenInput = document.querySelector('input[name="csrf_token_form"]')
                || document.querySelector('input[name="csrf_token"]');
            return tokenInput ? tokenInput.value : '';
        }

        // 4. DOM: floating toggle + panel, appended to <body> so it survives form scrolls.
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.id = 'oe-ai-chat-toggle';
        toggle.className = 'oe-ai-chat-toggle';
        // Hidden until module_status confirms Layer 2 is on, so a disabled install never
        // shows a dead button even for the few hundred ms the status request takes.
        toggle.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', 'oe-ai-chat-panel');
        toggle.title = t('open', 'Abrir chat del paciente');
        toggle.innerHTML = '<i class="fa fa-comments" aria-hidden="true"></i><span class="oe-ai-chat-toggle-label" data-i18n="toggle"></span>';

        const panel = document.createElement('section');
        panel.id = 'oe-ai-chat-panel';
        panel.className = 'oe-ai-chat-panel';
        panel.hidden = true;
        panel.setAttribute('aria-label', t('title', 'Chat Clínico IA'));
        panel.innerHTML =
            '<header class="oe-ai-chat-header">' +
                '<span class="oe-ai-chat-title" data-i18n="title"></span>' +
                '<span class="oe-ai-chat-actions">' +
                    '<button type="button" class="oe-ai-chat-link" id="oe-ai-chat-clear" data-i18n="clear"></button>' +
                    '<button type="button" class="oe-ai-chat-link" id="oe-ai-chat-close" data-i18n="close"></button>' +
                '</span>' +
            '</header>' +
            '<div class="oe-ai-chat-messages" id="oe-ai-chat-messages" role="log" aria-live="polite"></div>' +
            '<div class="oe-ai-chat-notice" id="oe-ai-chat-notice" hidden></div>' +
            '<footer class="oe-ai-chat-composer">' +
                '<textarea id="oe-ai-chat-input" rows="2" class="oe-ai-chat-input" data-i18n-placeholder="placeholder"></textarea>' +
                '<button type="button" class="oe-ai-chat-send" id="oe-ai-chat-send" data-i18n="send"></button>' +
            '</footer>';

        document.body.appendChild(toggle);
        document.body.appendChild(panel);

        applyI18n(panel);
        // data-i18n-placeholder needs its own pass: textContent cannot set an attribute.
        const placeholderNode = panel.querySelector('[data-i18n-placeholder]');
        if (placeholderNode) {
            placeholderNode.setAttribute('placeholder', t('placeholder', 'Hacé una pregunta sobre este paciente...'));
        }
        toggle.title = t('open', 'Abrir chat del paciente');

        const messages   = document.getElementById('oe-ai-chat-messages');
        const notice     = document.getElementById('oe-ai-chat-notice');
        const input      = document.getElementById('oe-ai-chat-input');
        const sendBtn    = document.getElementById('oe-ai-chat-send');
        const clearBtn   = document.getElementById('oe-ai-chat-clear');
        const closeBtn   = document.getElementById('oe-ai-chat-close');

        // 5. Panel behaviour.
        function openPanel() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            if (!messages.children.length) {
                showNotice(t('empty_state', 'Las respuestas salen solo del contexto de la ficha y citan la sección de origen.'));
            }
            input.focus();
        }

        function closePanel() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        function showNotice(text) {
            notice.textContent = text || '';
            notice.hidden = !text;
        }

        function hideNotice() {
            notice.hidden = true;
            notice.textContent = '';
        }

        function scrollToEnd() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addBubble(role, text) {
            const bubble = document.createElement('div');
            bubble.className = 'oe-ai-chat-bubble oe-ai-chat-bubble-' + role;

            const body = document.createElement('div');
            body.className = 'oe-ai-chat-bubble-text';
            body.textContent = text;
            bubble.appendChild(body);

            messages.appendChild(bubble);
            scrollToEnd();
            return bubble;
        }

        function addCitations(bubble, citations) {
            if (!citations || !citations.length) {
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'oe-ai-chat-citations';
            wrap.setAttribute('data-i18n-label', 'sources');

            const label = document.createElement('span');
            label.className = 'oe-ai-chat-citations-label';
            label.textContent = t('sources', 'Fuentes');
            wrap.appendChild(label);

            for (let i = 0; i < citations.length; i++) {
                const chip = document.createElement('span');
                chip.className = 'oe-ai-chat-citation';
                chip.textContent = (citations[i] && citations[i].label) ? citations[i].label : '';
                if (chip.textContent) {
                    wrap.appendChild(chip);
                }
            }
            bubble.appendChild(wrap);
        }

        function setBusy(state) {
            busy = state;
            sendBtn.disabled = state || !chatReady || !gateAllowed;
            input.disabled = !chatReady || !gateAllowed;
        }

        // 6. Send.
        function sendQuestion() {
            if (busy || !chatReady) {
                return;
            }

            const question = (input.value || '').trim();
            if (question === '') {
                showNotice(t('empty_question', 'Escribí una pregunta primero.'));
                input.focus();
                return;
            }
            if (question.length > MAX_QUESTION_CHARS) {
                showNotice(t('too_long', 'La pregunta supera la longitud máxima permitida.'));
                return;
            }
            if (!gateAllowed) {
                showNotice(gateMessage || t('gate_blocked', 'El chat por IA no está habilitado en este servidor.'));
                return;
            }

            hideNotice();
            addBubble('user', question);
            input.value = '';
            setBusy(true);

            const pending = addBubble('assistant', t('thinking', 'Revisando la ficha...'));
            pending.classList.add('oe-ai-chat-bubble-pending');

            const formData = new FormData();
            formData.append('csrf_token_form', getCsrfToken());
            formData.append('pid', pid);
            formData.append('encounter', encounterId);
            formData.append('message', question);
            formData.append('history', JSON.stringify(history));

            fetch(publicEndpoint + '?action=chat&site=' + encodeURIComponent(siteId), {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                setBusy(false);
                const data = resObj.data || {};
                pending.classList.remove('oe-ai-chat-bubble-pending');

                if (data.ok && typeof data.reply === 'string') {
                    const textNode = pending.querySelector('.oe-ai-chat-bubble-text');
                    if (textNode) {
                        textNode.textContent = data.reply;
                    }
                    addCitations(pending, data.citations);
                    scrollToEnd();

                    // Only successful turns become history; failures are not replayed.
                    history.push({ role: 'user', content: question });
                    history.push({ role: 'assistant', content: data.reply });
                    if (history.length > MAX_TURNS) {
                        history = history.slice(history.length - MAX_TURNS);
                    }
                } else {
                    pending.remove();
                    showNotice(data.error || t('chat_error', 'No se pudo responder la pregunta. Intentá de nuevo.'));
                }
            })
            .catch(function () {
                setBusy(false);
                pending.remove();
                showNotice(t('network_error', 'Error de conexión. Intentá de nuevo.'));
            });
        }

        function clearConversation() {
            if (busy) {
                return;
            }
            history = [];
            messages.innerHTML = '';
            showNotice(t('cleared', 'Conversación borrada.'));
        }

        toggle.addEventListener('click', function () {
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });
        closeBtn.addEventListener('click', closePanel);
        clearBtn.addEventListener('click', clearConversation);
        sendBtn.addEventListener('click', sendQuestion);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendQuestion();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) {
                closePanel();
            }
        });

        // 7. Server state. Fail closed: the widget stays hidden until a check succeeds,
        //    and the composer stays disabled while the consent gate is closed.
        //    ChatController re-checks both server-side on every request.
        function loadStatus() {
            fetch(publicEndpoint + '?action=module_status&site=' + encodeURIComponent(siteId), {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                const data = resObj.data || {};

                if (data.chat_enabled !== true) {
                    // Layer 2 off: remove the widget entirely rather than leaving a dead button.
                    if (toggle.parentNode) { toggle.parentNode.removeChild(toggle); }
                    if (panel.parentNode) { panel.parentNode.removeChild(panel); }
                    return;
                }

                if (data.chat_i18n && typeof data.chat_i18n === 'object') {
                    I18N = data.chat_i18n;
                    applyI18n(panel);
                    if (placeholderNode) {
                        placeholderNode.setAttribute('placeholder', t('placeholder', 'Hacé una pregunta sobre este paciente...'));
                    }
                    toggle.title = t('open', 'Abrir chat del paciente');
                }

                toggle.hidden = false;

                gateAllowed = (data.allowed === true);
                gateMessage = data.message || '';

                if (gateAllowed) {
                    chatReady = true;
                    hideNotice();
                } else {
                    chatReady = true;
                    showNotice(gateMessage || t('gate_blocked', 'El chat por IA no está habilitado en este servidor.'));
                }

                setBusy(false);
            })
            .catch(function () {
                // Fail closed: no widget on an unknown state.
                if (toggle.parentNode) { toggle.parentNode.removeChild(toggle); }
                if (panel.parentNode) { panel.parentNode.removeChild(panel); }
            });
        }

        setBusy(false);
        input.disabled = true;
        sendBtn.disabled = true;
        loadStatus();
    }
}());
