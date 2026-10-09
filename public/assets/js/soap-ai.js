/**
 * soap-ai.js — editor logic for the SOAP-AI form (Layer 1 dictation + Layer 2 chat).
 *
 * This is the modern replacement UI that the "IA" button on the native SOAP form opens.
 * It reuses the proven flows from ai-dictation.js and ai-chat.js but renders into a
 * fixed template (templates/soap_ai.php) instead of injecting DOM into the native form.
 *
 * Contracts:
 *   1. Storage is unchanged: the Save action (index.php?action=soap_ai_save) writes
 *      form_soap + forms(formdir='soap'); after saving it redirects to the native view.
 *   2. Dictation uploads to transcribe_submit and polls transcribe_status.
 *   3. Draft generation calls soap_draft and fills the four textareas (value only).
 *   4. Chat calls chat and keeps history in this closure only (no browser storage).
 *   5. The consent gate is read from module_status; failures fail CLOSED. The server
 *      re-checks every gate on each request, so this is presentation only.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */

(function () {
    'use strict';

    var CONFIG = window.OE_AI_SOAP_AI || {};
    if (!CONFIG.publicEndpoint) {
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEditor);
    } else {
        initEditor();
    }

    function initEditor() {
        var subjArea = document.getElementById('oe-ai-subjective');
        var objArea  = document.getElementById('oe-ai-objective');
        var assArea  = document.getElementById('oe-ai-assessment');
        var planArea = document.getElementById('oe-ai-plan');
        if (!subjArea || !objArea || !assArea || !planArea) {
            return;
        }

        var siteId = CONFIG.siteId || 'default';
        var publicEndpoint = CONFIG.publicEndpoint;

        var STRINGS = CONFIG.strings || {};
        function s(key, fallback) {
            return (STRINGS[key] !== undefined && STRINGS[key] !== '') ? STRINGS[key] : (fallback || '');
        }

        function getCsrf() {
            if (CONFIG.csrf) {
                return CONFIG.csrf;
            }
            var el = document.querySelector('input[name="csrf_token_form"]')
                || document.querySelector('input[name="csrf_token"]');
            return el ? el.value : '';
        }

        function applyI18n(root, map) {
            if (!root) {
                return;
            }
            var nodes = root.querySelectorAll('[data-i18n]');
            for (var i = 0; i < nodes.length; i++) {
                var v = map[nodes[i].getAttribute('data-i18n')];
                if (v) {
                    nodes[i].textContent = v;
                }
            }
        }

        // =====================================================================
        // Dirty state + Save / Back
        // =====================================================================
        var dirty = false;
        var saveBtn = document.getElementById('oe-ai-save');
        var backBtn = document.getElementById('oe-ai-back');
        var saveStatus = document.getElementById('oe-ai-save-status');

        function markDirty() {
            dirty = true;
        }

        [subjArea, objArea, assArea, planArea].forEach(function (el) {
            el.addEventListener('input', markDirty);
        });

        function showSaveStatus(text, state) {
            if (!saveStatus) {
                return;
            }
            saveStatus.textContent = text || '';
            saveStatus.classList.toggle('is-error', state === 'error');
            saveStatus.classList.toggle('is-ok', state === 'ok');
        }

        function setSaving(state) {
            if (saveBtn) {
                saveBtn.disabled = state;
            }
            if (backBtn) {
                backBtn.disabled = state;
            }
            if (state) {
                showSaveStatus(s('saving', 'Saving...'), '');
            }
        }

        function buildPayload() {
            var fd = new FormData();
            fd.append('csrf_token_form', getCsrf());
            fd.append('pid', String(CONFIG.pid || 0));
            fd.append('encounter', String(CONFIG.encounter || 0));
            fd.append('id', String(CONFIG.formId || 0));
            fd.append('subjective', subjArea.value);
            fd.append('objective', objArea.value);
            fd.append('assessment', assArea.value);
            fd.append('plan', planArea.value);
            return fd;
        }

        function saveNote() {
            var combined = (subjArea.value || '') + ' ' + (objArea.value || '') + ' '
                + (assArea.value || '') + ' ' + (planArea.value || '');
            if (combined.indexOf('[VERIFY') !== -1) {
                if (!window.confirm(s('verifySaveConfirm', 'This note still contains [VERIFY] markers. Save anyway?'))) {
                    return;
                }
            }

            setSaving(true);
            fetch(publicEndpoint + '?action=soap_ai_save&site=' + encodeURIComponent(siteId), {
                method: 'POST',
                credentials: 'same-origin',
                body: buildPayload()
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                setSaving(false);
                var data = resObj.data || {};
                if (data.ok) {
                    dirty = false;
                    showSaveStatus(s('saved', 'Saved.'), 'ok');
                    if (data.redirect) {
                        // Keep the encounter summary in sync (native save calls
                        // parent.closeTab(..., true) which also refreshes the visit display).
                        if (window.parent && typeof window.parent.refreshVisitDisplay === 'function') {
                            try { window.parent.refreshVisitDisplay(); } catch (e) { /* ignore */ }
                        }
                        if (window.top && typeof window.top.restoreSession === 'function') {
                            window.top.restoreSession();
                        }
                        window.location.href = data.redirect;
                    }
                } else {
                    showSaveStatus(data.error || s('saveFailed', 'Could not save the note.'), 'error');
                }
            })
            .catch(function () {
                setSaving(false);
                showSaveStatus(s('saveFailed', 'Could not save the note.'), 'error');
            });
        }

        function goBack() {
            if (dirty && !window.confirm(s('unsavedConfirm', 'Leave without saving?'))) {
                return;
            }
            dirty = false;
            var url = CONFIG.nativeViewUrl;
            if (!CONFIG.formId || CONFIG.formId <= 0) {
                url = (CONFIG.webRoot || '') + '/interface/patient_file/encounter/forms.php';
            }
            if (window.top && typeof window.top.restoreSession === 'function') {
                window.top.restoreSession();
            }
            window.location.href = url;
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', saveNote);
        }
        if (backBtn) {
            backBtn.addEventListener('click', goBack);
        }
        window.addEventListener('beforeunload', function (e) {
            if (dirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // =====================================================================
        // Layer 1 — Dictation
        // =====================================================================
        var I18N = {};
        var maxAudioDurationSec = 180;
        var consentAllowed = null;
        var consentMessage = '';

        function t(key, fallback) {
            return (I18N[key] !== undefined && I18N[key] !== '') ? I18N[key] : fallback;
        }

        var toolbar = document.getElementById('oe-ai-dictation-toolbar');
        var btnRecord = document.getElementById('oe-ai-btn-record');
        var btnStop = document.getElementById('oe-ai-btn-stop');
        var btnDiscard = document.getElementById('oe-ai-btn-discard');
        var btnGenerate = document.getElementById('oe-ai-btn-generate');
        var timerDisplay = document.getElementById('oe-ai-timer');
        var statusText = document.getElementById('oe-ai-status');
        var transcriptPanel = document.getElementById('oe-ai-transcript-panel');
        var transcriptText = document.getElementById('oe-ai-transcript-text');

        function refreshTranscriptPlaceholder() {
            if (transcriptText && I18N['transcript_placeholder']) {
                transcriptText.setAttribute('placeholder', I18N['transcript_placeholder']);
            }
        }

        var mediaRecorder = null;
        var mediaStream = null;
        var recordedChunks = [];
        var timerInterval = null;
        var elapsedSeconds = 0;
        var activePollingJobId = null;
        var pollingTimer = null;
        var pollGeneration = 0;

        function formatSeconds(sec) {
            var m = Math.floor(sec / 60);
            var sec2 = sec % 60;
            return String(m).padStart(2, '0') + ':' + String(sec2).padStart(2, '0');
        }

        function updateTimer() {
            elapsedSeconds++;
            timerDisplay.textContent = formatSeconds(elapsedSeconds) + ' / ' + formatSeconds(maxAudioDurationSec);
            if (elapsedSeconds >= maxAudioDurationSec) {
                stopRecording();
                statusText.textContent = t('audio_limit_reached', 'Audio limit reached (%d min). Processing...')
                    .replace('%d', Math.max(1, Math.round(maxAudioDurationSec / 60)));
            }
        }

        function getSupportedMimeType() {
            var types = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
                'audio/aac',
                'audio/wav'
            ];
            if (window.MediaRecorder) {
                for (var i = 0; i < types.length; i++) {
                    if (MediaRecorder.isTypeSupported(types[i])) {
                        return types[i];
                    }
                }
            }
            return '';
        }

        function releaseMicrophone() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(function (track) {
                    try { track.stop(); } catch (e) { /* ignore */ }
                });
                mediaStream = null;
            }
        }

        function startRecording() {
            if (consentAllowed !== true) {
                window.alert(consentMessage || t('gate_blocked', 'AI dictation is not enabled on this server. Contact your administrator.'));
                return;
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                window.alert(t('no_recording_support', 'Audio recording is not supported in this browser.'));
                return;
            }

            recordedChunks = [];
            elapsedSeconds = 0;

            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                mediaStream = stream;
                var mimeType = getSupportedMimeType();
                var options = mimeType ? { mimeType: mimeType } : {};
                try {
                    mediaRecorder = new MediaRecorder(mediaStream, options);
                } catch (e) {
                    mediaRecorder = new MediaRecorder(mediaStream);
                }

                mediaRecorder.ondataavailable = function (e) {
                    if (e.data && e.data.size > 0) {
                        recordedChunks.push(e.data);
                    }
                };
                mediaRecorder.onstop = function () {
                    releaseMicrophone();
                    clearInterval(timerInterval);
                    if (recordedChunks.length > 0) {
                        var blobType = mediaRecorder.mimeType || 'audio/webm';
                        uploadAudio(new Blob(recordedChunks, { type: blobType }));
                    }
                };

                mediaRecorder.start(250);

                toolbar.classList.add('recording');
                btnRecord.style.display = 'none';
                btnStop.style.display = 'inline-flex';
                btnDiscard.style.display = 'inline-flex';
                timerDisplay.style.display = 'inline-block';
                timerDisplay.textContent = '00:00 / ' + formatSeconds(maxAudioDurationSec);
                statusText.innerHTML = '<span class="oe-ai-pulsing-dot mr-1"></span> ' + t('recording', 'Recording consultation...');
                timerInterval = setInterval(updateTimer, 1000);
            }).catch(function (err) {
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    window.alert(t('mic_denied', 'Microphone permission denied.'));
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    window.alert(t('mic_not_found', 'No microphone was found on this device.'));
                } else {
                    window.alert(t('mic_error', 'Error accessing the microphone: ') + err.message);
                }
            });
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            toolbar.classList.remove('recording');
            btnStop.style.display = 'none';
            btnDiscard.style.display = 'none';
            timerDisplay.style.display = 'none';
            btnRecord.style.display = 'inline-flex';
            statusText.textContent = t('audio_recorded', 'Audio recorded. Uploading...');
        }

        function discardRecording() {
            cancelPolling();
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.onstop = null;
                mediaRecorder.stop();
            }
            releaseMicrophone();
            clearInterval(timerInterval);
            recordedChunks = [];
            toolbar.classList.remove('recording');
            btnStop.style.display = 'none';
            btnDiscard.style.display = 'none';
            timerDisplay.style.display = 'none';
            btnRecord.style.display = 'inline-flex';
            statusText.textContent = t('recording_discarded', 'Recording discarded.');
        }

        function cancelPolling() {
            pollGeneration++;
            if (pollingTimer !== null) {
                clearTimeout(pollingTimer);
                pollingTimer = null;
            }
            activePollingJobId = null;
        }

        function uploadAudio(audioBlob) {
            statusText.textContent = t('sending_audio', 'Uploading audio to the Whisper server...');

            var formData = new FormData();
            formData.append('audio', audioBlob, 'dictation.webm');
            formData.append('csrf_token_form', getCsrf());
            formData.append('pid', String(CONFIG.pid || 0));
            formData.append('encounter', String(CONFIG.encounter || 0));

            fetch(publicEndpoint + '?action=transcribe_submit&site=' + encodeURIComponent(siteId), {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                if (resObj.status === 429) {
                    statusText.textContent = t('busy_retry', 'Server busy. Retrying upload in 3 s...');
                    setTimeout(function () { uploadAudio(audioBlob); }, 3000);
                    return;
                }
                var data = resObj.data;
                if (!data.job_id) {
                    statusText.textContent = t('upload_error', 'Upload error: ') + (data.error || describeTranscribeError(data.error_code));
                    return;
                }
                if (data.status === 'completed' && data.text !== undefined) {
                    activePollingJobId = null;
                    deliverTranscript(data.text);
                    return;
                }
                activePollingJobId = data.job_id;
                statusText.textContent = t('transcribing', 'Transcribing audio (Whisper)...');
                pollTranscription(data.job_id);
            })
            .catch(function (err) {
                statusText.textContent = t('upload_network_error', 'Network error uploading audio: ') + err.message;
            });
        }

        function pollTranscription(jobId) {
            var generation = pollGeneration;
            var statusUrl = publicEndpoint
                + '?action=transcribe_status&site=' + encodeURIComponent(siteId)
                + '&job_id=' + encodeURIComponent(jobId)
                + '&pid=' + encodeURIComponent(CONFIG.pid || 0)
                + '&encounter=' + encodeURIComponent(CONFIG.encounter || 0);

            pollingTimer = setTimeout(function () {
                fetch(statusUrl, { method: 'GET', credentials: 'same-origin' })
                .then(function (res) {
                    return res.json().then(function (data) { return { status: res.status, data: data }; });
                })
                .then(function (resObj) {
                    if (generation !== pollGeneration) {
                        return;
                    }
                    var httpStatus = resObj.status;
                    var data = resObj.data;
                    if (httpStatus === 429) {
                        statusText.textContent = t('busy_waiting', 'Transcription server busy. Waiting in queue...');
                        pollTranscription(jobId);
                        return;
                    }
                    if (httpStatus === 404) {
                        activePollingJobId = null;
                        statusText.textContent = t('job_expired', 'The transcription expired or does not exist. Record again.');
                        return;
                    }
                    if (httpStatus === 403) {
                        activePollingJobId = null;
                        statusText.textContent = t('poll_auth_error', 'Authorization error: ') + (data.error || '');
                        return;
                    }
                    if (data.status === 'processing' || data.status === 'pending') {
                        statusText.textContent = t('processing', 'Processing transcription...');
                        pollTranscription(jobId);
                    } else if (data.status === 'completed') {
                        activePollingJobId = null;
                        deliverTranscript(data.text || '');
                    } else {
                        activePollingJobId = null;
                        statusText.textContent = t('transcription_error', 'Transcription error: ') + describeTranscribeError(data.error_code);
                    }
                })
                .catch(function (err) {
                    statusText.textContent = t('poll_status_error', 'Error checking status: ') + err.message;
                });
            }, 1000);
        }

        function deliverTranscript(text) {
            activePollingJobId = null;
            statusText.textContent = t('transcription_done', 'Transcription complete.');
            transcriptText.value = text;
            transcriptPanel.style.display = 'block';
            btnGenerate.style.display = 'inline-flex';
        }

        function describeTranscribeError(code) {
            var messages = {
                invalid_csrf: 'Invalid CSRF token. Reload the page.',
                unauthorized: 'Unauthenticated or expired session.',
                access_denied: 'You do not have permission to use AI dictation.',
                invalid_clinical_context: 'The patient or encounter could not be validated.',
                clinical_context_mismatch: 'The job does not belong to this patient or encounter.',
                no_audio_file: 'No audio file was received.',
                invalid_upload: 'Invalid audio file.',
                file_too_large: 'The audio exceeds the maximum allowed size.',
                unsupported_audio_format: 'Unsupported audio format.',
                invalid_whisper_configuration: 'The Whisper URL is invalid or unreachable.',
                storage_error: 'Server storage error.',
                busy: 'The transcription engine is busy. Retry in a few seconds.',
                worker_timeout: 'Transcription exceeded the maximum allowed time.',
                worker_exception: 'Internal server error during transcription.',
                transcription_failed: 'Transcription failed.',
                worker_error: 'Error in the transcription process.',
                job_not_found: 'The transcription job expired or does not exist.'
            };
            if (code && I18N['err_' + code]) {
                return I18N['err_' + code];
            }
            return (code && messages[code]) ? messages[code] : t('err_unknown', 'Unknown failure');
        }

        // =====================================================================
        // Layer 1 — Draft generation and application
        // =====================================================================
        function generateSoapDraft() {
            var transcript = transcriptText.value.trim();
            if (transcript === '') {
                window.alert(t('no_transcript', 'No transcript available to generate the draft.'));
                return;
            }

            btnGenerate.disabled = true;
            statusText.textContent = t('generating_draft', 'Generating SOAP draft with AI...');

            var formData = new FormData();
            formData.append('csrf_token_form', getCsrf());
            formData.append('pid', String(CONFIG.pid || 0));
            formData.append('encounter', String(CONFIG.encounter || 0));
            formData.append('transcript', transcript);

            fetch(publicEndpoint + '?action=soap_draft&site=' + encodeURIComponent(siteId), {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                btnGenerate.disabled = false;
                var data = resObj.data;
                if (data.ok && data.draft) {
                    statusText.textContent = t('draft_ok', 'Draft generated (%s - %s tokens).')
                        .replace('%s', data.meta.provider)
                        .replace('%s', data.meta.total_tokens);
                    applyDraftToForm(data.draft, data.has_verify_markers);
                } else {
                    statusText.textContent = t('draft_error', 'Error generating draft: ') + (data.error || t('invalid_response', 'Invalid response'));
                    window.alert(t('draft_alert_failed', 'Could not generate the draft: ') + (data.error || t('provider_error', 'Provider error')));
                }
            })
            .catch(function (err) {
                btnGenerate.disabled = false;
                statusText.textContent = t('connection_error', 'Connection error: ') + err.message;
                window.alert(t('connect_failed', 'Could not connect to the server: ') + err.message);
            });
        }

        function applyDraftToForm(draft, hasVerify) {
            var hasPriorContent = (subjArea.value.trim() !== '')
                || (objArea.value.trim() !== '')
                || (assArea.value.trim() !== '')
                || (planArea.value.trim() !== '');

            if (hasPriorContent) {
                showReplaceAppendModal(function (action) {
                    if (action === 'replace') {
                        populateTextareas(draft, false);
                        showReviewBanner(hasVerify);
                    } else if (action === 'append') {
                        populateTextareas(draft, true);
                        showReviewBanner(hasVerify);
                    }
                });
            } else {
                populateTextareas(draft, false);
                showReviewBanner(hasVerify);
            }
        }

        function populateTextareas(draft, isAppend) {
            var mapping = [
                { el: subjArea, val: draft.subjective || '' },
                { el: objArea, val: draft.objective || '' },
                { el: assArea, val: draft.assessment || '' },
                { el: planArea, val: draft.plan || '' }
            ];
            mapping.forEach(function (m) {
                if (isAppend) {
                    if (m.el.value.trim() !== '') {
                        m.el.value = m.el.value.trimEnd() + '\n\n--- ' + t('ai_draft_marker', 'AI draft') + ' ---\n' + m.val;
                    } else {
                        m.el.value = m.val;
                    }
                } else {
                    m.el.value = m.val;
                }
                m.el.dispatchEvent(new Event('input', { bubbles: true }));
                m.el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        function showReviewBanner(hasVerify) {
            var host = document.getElementById('oe-ai-draft-banner-host');
            if (!host) {
                return;
            }
            host.innerHTML = '';

            var banner = document.createElement('div');
            banner.className = 'oe-ai-review-banner';

            var textWrap = document.createElement('div');
            var strong = document.createElement('strong');
            strong.textContent = t('review_banner_title', 'AI-generated draft:') + ' ';
            textWrap.appendChild(strong);
            textWrap.appendChild(document.createTextNode(t('review_banner_body', 'review and edit the content before saving.')));
            if (hasVerify) {
                var badge = document.createElement('span');
                badge.className = 'oe-ai-verify-badge ml-2';
                badge.textContent = t('verify_badge', 'Contains items to verify [VERIFY]');
                textWrap.appendChild(badge);
            }
            banner.appendChild(textWrap);

            var dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'btn btn-sm btn-outline-dark';
            dismiss.style.padding = '2px 8px';
            dismiss.style.fontSize = '0.8rem';
            dismiss.textContent = t('understood', 'Got it');
            dismiss.addEventListener('click', function () { banner.style.display = 'none'; });
            banner.appendChild(dismiss);

            host.appendChild(banner);
        }

        function showReplaceAppendModal(callback) {
            var overlay = document.createElement('div');
            overlay.className = 'oe-ai-modal-overlay';

            var card = document.createElement('div');
            card.className = 'oe-ai-modal-card';

            var header = document.createElement('div');
            header.className = 'oe-ai-modal-header';
            header.textContent = t('prior_content_title', 'Existing content detected');
            card.appendChild(header);

            var body = document.createElement('div');
            body.className = 'oe-ai-modal-body';
            body.textContent = t('prior_content_body', 'The SOAP fields already contain text. How should the new AI draft be added?');
            card.appendChild(body);

            var footer = document.createElement('div');
            footer.className = 'oe-ai-modal-footer';

            function addButton(label, cls, action) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'oe-ai-btn ' + cls;
                b.textContent = label;
                b.addEventListener('click', function () {
                    document.body.removeChild(overlay);
                    callback(action);
                });
                footer.appendChild(b);
            }
            addButton(t('cancel', 'Cancel'), 'oe-ai-btn-discard', 'cancel');
            addButton(t('append_end', 'Append at end'), 'btn-secondary', 'append');
            addButton(t('replace_all', 'Replace all'), 'oe-ai-btn-record', 'replace');

            card.appendChild(footer);
            overlay.appendChild(card);
            document.body.appendChild(overlay);
        }

        // =====================================================================
        // Layer 2 — Chat
        // =====================================================================
        var CHAT_I18N = {};
        var chatSection = document.getElementById('oe-ai-chat');
        var chatReady = false;
        var chatBusy = false;
        var chatGateAllowed = false;
        var chatGateMessage = '';
        var chatHistory = [];
        var MAX_QUESTION_CHARS = 4000;
        var MAX_TURNS = 20;

        var chatMessages = document.getElementById('oe-ai-chat-messages');
        var chatNotice = document.getElementById('oe-ai-chat-notice');
        var chatInput = document.getElementById('oe-ai-chat-input');
        var chatSend = document.getElementById('oe-ai-chat-send');
        var chatClear = document.getElementById('oe-ai-chat-clear');

        function ct(key, fallback) {
            return (CHAT_I18N[key] !== undefined && CHAT_I18N[key] !== '') ? CHAT_I18N[key] : fallback;
        }

        function showChatNotice(text) {
            if (!chatNotice) { return; }
            chatNotice.textContent = text || '';
            chatNotice.hidden = !text;
        }
        function hideChatNotice() {
            if (!chatNotice) { return; }
            chatNotice.hidden = true;
            chatNotice.textContent = '';
        }
        function scrollChatToEnd() {
            if (chatMessages) { chatMessages.scrollTop = chatMessages.scrollHeight; }
        }
        function addChatBubble(role, text) {
            var bubble = document.createElement('div');
            bubble.className = 'oe-ai-chat-bubble oe-ai-chat-bubble-' + role;
            var body = document.createElement('div');
            body.className = 'oe-ai-chat-bubble-text';
            body.textContent = text;
            bubble.appendChild(body);
            chatMessages.appendChild(bubble);
            scrollChatToEnd();
            return bubble;
        }
        function addChatCitations(bubble, citations) {
            if (!citations || !citations.length) { return; }
            var wrap = document.createElement('div');
            wrap.className = 'oe-ai-chat-citations';
            var label = document.createElement('span');
            label.className = 'oe-ai-chat-citations-label';
            label.textContent = ct('sources', 'Sources');
            wrap.appendChild(label);
            for (var i = 0; i < citations.length; i++) {
                var chip = document.createElement('span');
                chip.className = 'oe-ai-chat-citation';
                chip.textContent = (citations[i] && citations[i].label) ? citations[i].label : '';
                if (chip.textContent) { wrap.appendChild(chip); }
            }
            bubble.appendChild(wrap);
        }
        function updateChatBusy(state) {
            chatBusy = state;
            if (chatSend) { chatSend.disabled = state || !chatReady || !chatGateAllowed; }
            if (chatInput) { chatInput.disabled = !chatReady || !chatGateAllowed; }
        }

        function sendChatQuestion() {
            if (chatBusy || !chatReady || !chatSection) { return; }
            var question = (chatInput.value || '').trim();
            if (question === '') {
                showChatNotice(ct('empty_question', 'Type a question first.'));
                chatInput.focus();
                return;
            }
            if (question.length > MAX_QUESTION_CHARS) {
                showChatNotice(ct('too_long', 'The question exceeds the maximum allowed length.'));
                return;
            }
            if (!chatGateAllowed) {
                showChatNotice(chatGateMessage || ct('gate_blocked', 'AI chat is not enabled on this server.'));
                return;
            }

            hideChatNotice();
            addChatBubble('user', question);
            chatInput.value = '';
            updateChatBusy(true);

            var pending = addChatBubble('assistant', ct('thinking', 'Reviewing the chart...'));
            pending.classList.add('oe-ai-chat-bubble-pending');

            var formData = new FormData();
            formData.append('csrf_token_form', getCsrf());
            formData.append('pid', String(CONFIG.pid || 0));
            formData.append('encounter', String(CONFIG.encounter || 0));
            formData.append('message', question);
            formData.append('history', JSON.stringify(chatHistory));

            fetch(publicEndpoint + '?action=chat&site=' + encodeURIComponent(siteId), {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                updateChatBusy(false);
                var data = resObj.data || {};
                pending.classList.remove('oe-ai-chat-bubble-pending');
                if (data.ok && typeof data.reply === 'string') {
                    var textNode = pending.querySelector('.oe-ai-chat-bubble-text');
                    if (textNode) { textNode.textContent = data.reply; }
                    addChatCitations(pending, data.citations);
                    scrollChatToEnd();
                    chatHistory.push({ role: 'user', content: question });
                    chatHistory.push({ role: 'assistant', content: data.reply });
                    if (chatHistory.length > MAX_TURNS) {
                        chatHistory = chatHistory.slice(chatHistory.length - MAX_TURNS);
                    }
                } else {
                    pending.remove();
                    showChatNotice(data.error || ct('chat_error', 'Could not answer the question. Try again.'));
                }
            })
            .catch(function () {
                updateChatBusy(false);
                pending.remove();
                showChatNotice(ct('network_error', 'Connection error. Try again.'));
            });
        }

        function clearChatConversation() {
            if (chatBusy) { return; }
            chatHistory = [];
            if (chatMessages) { chatMessages.innerHTML = ''; }
            showChatNotice(ct('cleared', 'Conversation cleared.'));
        }

        if (chatSend) { chatSend.addEventListener('click', sendChatQuestion); }
        if (chatClear) { chatClear.addEventListener('click', clearChatConversation); }
        if (chatInput) {
            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendChatQuestion();
                }
            });
        }

        function configureChat(data) {
            if (!chatSection) { return; }
            if (data.chat_enabled !== true) {
                var side = chatSection.closest ? chatSection.closest('.oe-ai-editor-side') : null;
                var target = side || chatSection;
                if (target.parentNode) { target.parentNode.removeChild(target); }
                return;
            }
            if (data.chat_i18n && typeof data.chat_i18n === 'object') {
                CHAT_I18N = data.chat_i18n;
                applyI18n(chatSection, CHAT_I18N);
                if (chatInput && CHAT_I18N['placeholder']) {
                    chatInput.setAttribute('placeholder', CHAT_I18N['placeholder']);
                }
            }
            chatGateAllowed = data.allowed === true;
            chatGateMessage = data.message || '';
            chatReady = true;
            if (chatGateAllowed) {
                hideChatNotice();
            } else {
                showChatNotice(chatGateMessage || ct('gate_blocked', 'AI chat is not enabled on this server.'));
            }
            updateChatBusy(false);
        }

        updateChatBusy(false);
        if (chatInput) { chatInput.disabled = true; }
        if (chatSend) { chatSend.disabled = true; }

        // =====================================================================
        // Server state (gate + translations) — single request for both layers
        // =====================================================================
        function loadStatus() {
            fetch(publicEndpoint + '?action=module_status&site=' + encodeURIComponent(siteId), {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                var data = resObj.data || {};
                consentAllowed = (data.allowed === true);
                consentMessage = data.message || '';

                if (data.max_audio_sec && parseInt(data.max_audio_sec, 10) > 0) {
                    maxAudioDurationSec = parseInt(data.max_audio_sec, 10);
                }
                if (data.i18n && typeof data.i18n === 'object') {
                    I18N = data.i18n;
                    applyI18n(toolbar, I18N);
                    refreshTranscriptPlaceholder();
                }
                if (!consentAllowed) {
                    btnRecord.disabled = true;
                    btnRecord.title = consentMessage;
                    statusText.textContent = consentMessage
                        || t('gate_blocked_short', 'AI dictation is not enabled on this server.');
                }
                configureChat(data);
            })
            .catch(function () {
                consentAllowed = false;
                btnRecord.disabled = true;
                statusText.textContent = t('gate_unknown', 'Could not verify whether AI dictation is enabled.');
                configureChat({ chat_enabled: false });
            });
        }

        btnRecord.addEventListener('click', startRecording);
        btnStop.addEventListener('click', stopRecording);
        btnDiscard.addEventListener('click', discardRecording);
        btnGenerate.addEventListener('click', generateSoapDraft);

        loadStatus();
    }
}());
