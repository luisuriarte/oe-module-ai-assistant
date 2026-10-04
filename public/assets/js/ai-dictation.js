/**
 * ai-dictation.js — AI Dictation and SOAP Note Draft generator for OpenEMR.
 *
 * Scoped to Milestone 5 (Layer 1 UI):
 *   1. Renders dictation toolbar on native SOAP forms (load_form.php and view_form.php).
 *   2. Microphone capture with best supported MIME type (including Safari mp4).
 *   3. Live timer with automatic cutoff at max duration.
 *   4. Clean release of MediaStream tracks on stop or discard.
 *   5. Upload to M2 transcription endpoint, polling with 429 backoff, cancel support.
 *   6. Editable transcription textarea.
 *   7. Draft generation via M5 soap_draft endpoint with patient context.
 *   8. Textarea population using value/textContent only (never innerHTML).
 *   9. Replace vs. append clinician prompt when destination fields have prior content.
 *  10. "AI-generated draft — review before saving" banner.
 *  11. Soft confirmation on native save if [VERIFY: ...] markers remain.
 *  12. Zero persistence: no localStorage/sessionStorage/IndexedDB.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */

(function () {
    'use strict';

    if (window.__OE_AI_ASSISTANT_LOADED__) {
        return;
    }
    window.__OE_AI_ASSISTANT_LOADED__ = true;

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAiDictation);
    } else {
        initAiDictation();
    }

    function initAiDictation() {
        // 1. Identify native SOAP textareas
        const subjArea = document.querySelector('textarea[name="subjective"]');
        const objArea  = document.querySelector('textarea[name="objective"]');
        const assArea  = document.querySelector('textarea[name="assessment"]');
        const planArea = document.querySelector('textarea[name="plan"]');

        if (!subjArea || !objArea || !assArea || !planArea) {
            return; // Not on the active SOAP form or textareas missing
        }

        // 2. Resolve Environment & IDs
        const urlParams = new URLSearchParams(window.location.search);
        const siteId = urlParams.get('site') || 'default';

        // Extract PID
        let pid = parseInt(
            (document.querySelector('input[name="pid"]') ? document.querySelector('input[name="pid"]').value : '')
            || urlParams.get('pid')
            || (window.top && window.top.pid ? window.top.pid : 0),
            10
        );
        if (isNaN(pid) || pid <= 0) {
            pid = 0;
        }

        // Extract Encounter ID
        let encounterId = parseInt(
            (document.querySelector('input[name="encounter"]') ? document.querySelector('input[name="encounter"]').value : '')
            || urlParams.get('encounter')
            || (window.top && window.top.encounter ? window.top.encounter : 0),
            10
        );
        if (isNaN(encounterId) || encounterId <= 0) {
            encounterId = 0;
        }

        // Public API endpoint
        let webRoot = '';
        if (window.top && window.top.webroot) {
            webRoot = window.top.webroot;
        } else {
            const loc = window.location.pathname;
            const idx = loc.indexOf('/interface/');
            webRoot = (idx !== -1) ? loc.substring(0, idx) : '';
        }
        const publicEndpoint = webRoot + '/interface/modules/custom_modules/oe-module-ai-assistant/public/index.php';

        // Maximum audio duration in seconds (default 180s = 3 minutes)
        const maxAudioDurationSec = 180;

        // 3. UI State
        let mediaRecorder = null;
        let mediaStream = null;
        let recordedChunks = [];
        let timerInterval = null;
        let elapsedSeconds = 0;
        let activePollingJobId = null;
        let pollingTimer = null;
        let lastDraftPayload = null;

        // 4. Render Dictation Bar in DOM
        const formContainer = subjArea.closest('form') || subjArea.parentElement;
        const dictationContainer = document.createElement('div');
        dictationContainer.className = 'oe-ai-dictation-container';
        dictationContainer.id = 'oe-ai-dictation-toolbar';

        dictationContainer.innerHTML = `
            <div class="oe-ai-toolbar">
                <div class="oe-ai-left-group">
                    <span class="oe-ai-title">
                        <i class="fa fa-microphone"></i> Dictado Clínico IA
                    </span>
                    <span class="oe-ai-badge">Whisper + LLM</span>
                    <button type="button" class="oe-ai-btn oe-ai-btn-record" id="oe-ai-btn-record">
                        <i class="fa fa-circle"></i> Grabar
                    </button>
                    <button type="button" class="oe-ai-btn oe-ai-btn-stop" id="oe-ai-btn-stop" style="display: none;">
                        <i class="fa fa-stop"></i> Detener
                    </button>
                    <button type="button" class="oe-ai-btn oe-ai-btn-discard" id="oe-ai-btn-discard" style="display: none;">
                        <i class="fa fa-trash"></i> Descartar
                    </button>
                    <span class="oe-ai-timer" id="oe-ai-timer" style="display: none;">00:00 / 03:00</span>
                    <span class="oe-ai-status-text" id="oe-ai-status">Listo para dictar</span>
                </div>
                <div class="oe-ai-right-group">
                    <button type="button" class="oe-ai-btn oe-ai-btn-generate" id="oe-ai-btn-generate" style="display: none;">
                        <i class="fa fa-magic"></i> Generar Nota SOAP
                    </button>
                </div>
            </div>

            <!-- Transcript Drawer (editable) -->
            <div class="oe-ai-transcript-panel" id="oe-ai-transcript-panel" style="display: none;">
                <label for="oe-ai-transcript-text">
                    <span>Transcripción del dictado (podés editarla antes de generar el borrador):</span>
                    <span id="oe-ai-transcript-status" class="text-muted small"></span>
                </label>
                <textarea class="oe-ai-transcript-textarea" id="oe-ai-transcript-text" rows="3" placeholder="La transcripción de la consulta aparecerá acá..."></textarea>
            </div>
        `;

        // Insert toolbar before the subjective field group or at the top of the form
        const targetInsertPoint = subjArea.closest('.form-group') || subjArea.parentElement;
        targetInsertPoint.parentNode.insertBefore(dictationContainer, targetInsertPoint);

        // UI Element references
        const btnRecord     = document.getElementById('oe-ai-btn-record');
        const btnStop       = document.getElementById('oe-ai-btn-stop');
        const btnDiscard    = document.getElementById('oe-ai-btn-discard');
        const btnGenerate   = document.getElementById('oe-ai-btn-generate');
        const timerDisplay  = document.getElementById('oe-ai-timer');
        const statusText    = document.getElementById('oe-ai-status');
        const transcriptBox = document.getElementById('oe-ai-transcript-panel');
        const transcriptText= document.getElementById('oe-ai-transcript-text');

        // 5. Helper: Retrieve CSRF token from current DOM
        function getCsrfToken() {
            const tokenInput = document.querySelector('input[name="csrf_token_form"]')
                || document.querySelector('input[name="csrf_token"]');
            return tokenInput ? tokenInput.value : '';
        }

        // 6. Helper: Detect supported audio MIME type
        function getSupportedMimeType() {
            const types = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4', // Safari iOS and macOS
                'audio/aac',
                'audio/wav'
            ];
            if (window.MediaRecorder) {
                for (let i = 0; i < types.length; i++) {
                    if (MediaRecorder.isTypeSupported(types[i])) {
                        return types[i];
                    }
                }
            }
            return '';
        }

        // 7. Timer update
        function formatSeconds(sec) {
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }

        function updateTimer() {
            elapsedSeconds++;
            const maxFormatted = formatSeconds(maxAudioDurationSec);
            timerDisplay.textContent = formatSeconds(elapsedSeconds) + ' / ' + maxFormatted;

            if (elapsedSeconds >= maxAudioDurationSec) {
                stopRecording();
                statusText.textContent = 'Límite de audio alcanzado (3 min). Procesando...';
            }
        }

        // 8. Microphone track release
        function releaseMicrophone() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(function (track) {
                    try {
                        track.stop();
                    } catch (e) {
                        // ignore
                    }
                });
                mediaStream = null;
            }
        }

        // 9. Start Recording
        async function startRecording() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('La grabación de audio no está soportada en este navegador.');
                return;
            }

            recordedChunks = [];
            elapsedSeconds = 0;

            try {
                mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (err) {
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    alert('Permiso de micrófono denegado. Por favor permití el acceso al micrófono en el navegador para dictar.');
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    alert('No se encontró ningún micrófono conectado en este dispositivo.');
                } else {
                    alert('Error al acceder al micrófono: ' + err.message);
                }
                return;
            }

            const mimeType = getSupportedMimeType();
            const options = mimeType ? { mimeType: mimeType } : {};

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
                    const blobType = mediaRecorder.mimeType || 'audio/webm';
                    const audioBlob = new Blob(recordedChunks, { type: blobType });
                    uploadAudio(audioBlob);
                }
            };

            mediaRecorder.start(250); // Collect slice every 250ms

            // UI updates
            dictationContainer.classList.add('recording');
            btnRecord.style.display = 'none';
            btnStop.style.display = 'inline-flex';
            btnDiscard.style.display = 'inline-flex';
            timerDisplay.style.display = 'inline-block';
            timerDisplay.textContent = '00:00 / ' + formatSeconds(maxAudioDurationSec);
            statusText.innerHTML = '<span class="oe-ai-pulsing-dot mr-1"></span> Grabando consulta...';

            timerInterval = setInterval(updateTimer, 1000);
        }

        // 10. Stop Recording
        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            dictationContainer.classList.remove('recording');
            btnStop.style.display = 'none';
            btnDiscard.style.display = 'none';
            timerDisplay.style.display = 'none';
            btnRecord.style.display = 'inline-flex';
            statusText.textContent = 'Audio grabado. Subiendo...';
        }

        // 11. Discard Recording
        function discardRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.onstop = null; // Do not trigger upload
                mediaRecorder.stop();
            }
            releaseMicrophone();
            clearInterval(timerInterval);
            recordedChunks = [];

            dictationContainer.classList.remove('recording');
            btnStop.style.display = 'none';
            btnDiscard.style.display = 'none';
            timerDisplay.style.display = 'none';
            btnRecord.style.display = 'inline-flex';
            statusText.textContent = 'Grabación descartada.';
        }

        // 12. Upload to M2 and Poll for Transcription
        function uploadAudio(audioBlob) {
            statusText.textContent = 'Enviando audio al servidor Whisper...';

            const csrf = getCsrfToken();
            const formData = new FormData();
            formData.append('audio', audioBlob, 'dictation.webm');
            formData.append('csrf_token_form', csrf);
            formData.append('pid', pid);
            formData.append('encounter', encounterId);

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
                    statusText.textContent = 'Servidor ocupado. Reintentando subida en 3 s...';
                    setTimeout(function () { uploadAudio(audioBlob); }, 3000);
                    return;
                }
                const data = resObj.data;
                if (data.ok && data.job_id) {
                    activePollingJobId = data.job_id;
                    statusText.textContent = 'Transcribiendo audio (Whisper)...';
                    pollTranscription(data.job_id);
                } else {
                    statusText.textContent = 'Error al subir audio: ' + (data.error || 'Respuesta inválida');
                }
            })
            .catch(function (err) {
                statusText.textContent = 'Error de red al subir audio: ' + err.message;
            });
        }

        function pollTranscription(jobId) {
            pollingTimer = setTimeout(function () {
                fetch(publicEndpoint + '?action=transcribe_status&site=' + encodeURIComponent(siteId) + '&job_id=' + encodeURIComponent(jobId), {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    return res.json().then(function (data) { return { status: res.status, data: data }; });
                })
                .then(function (resObj) {
                    if (resObj.status === 429) {
                        statusText.textContent = 'Servidor de transcripción ocupado. Esperando turno...';
                        pollTranscription(jobId);
                        return;
                    }
                    const data = resObj.data;
                    if (data.status === 'processing' || data.status === 'pending') {
                        statusText.textContent = 'Procesando transcripción...';
                        pollTranscription(jobId);
                    } else if (data.status === 'completed') {
                        statusText.textContent = 'Transcripción completada.';
                        transcriptText.value = data.transcript || '';
                        transcriptBox.style.display = 'block';
                        btnGenerate.style.display = 'inline-flex';
                    } else {
                        statusText.textContent = 'Error en transcripción: ' + (data.error || 'Fallo desconocido');
                    }
                })
                .catch(function (err) {
                    statusText.textContent = 'Error al consultar estado: ' + err.message;
                });
            }, 1000);
        }

        // 13. Generate SOAP Draft via M5 Endpoint
        function generateSoapDraft() {
            const transcript = transcriptText.value.trim();
            if (transcript === '') {
                alert('No hay transcripción disponible para generar el borrador.');
                return;
            }

            btnGenerate.disabled = true;
            statusText.textContent = 'Generando borrador SOAP con IA...';

            const csrf = getCsrfToken();
            const formData = new FormData();
            formData.append('csrf_token_form', csrf);
            formData.append('pid', pid);
            formData.append('encounter', encounterId);
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
                const data = resObj.data;

                if (data.ok && data.draft) {
                    statusText.textContent = 'Borrador generado con éxito (' + data.meta.provider + ' - ' + data.meta.total_tokens + ' tokens).';
                    lastDraftPayload = data.draft;
                    applyDraftToForm(data.draft, data.has_verify_markers);
                } else {
                    statusText.textContent = 'Error al generar borrador: ' + (data.error || 'Respuesta inválida');
                    alert('No se pudo generar el borrador: ' + (data.error || 'Error del proveedor'));
                }
            })
            .catch(function (err) {
                btnGenerate.disabled = false;
                statusText.textContent = 'Error de conexión: ' + err.message;
                alert('Error al conectar con el servidor: ' + err.message);
            });
        }

        // 14. Apply Draft to Form (Replace vs. Append dialog)
        function applyDraftToForm(draft, hasVerify) {
            const hasPriorContent = (subjArea.value.trim() !== '')
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

        // Populates the 4 textareas using value ONLY (strictly text, never innerHTML)
        function populateTextareas(draft, isAppend) {
            const mapping = [
                { el: subjArea, val: draft.subjective || '' },
                { el: objArea,  val: draft.objective || '' },
                { el: assArea,  val: draft.assessment || '' },
                { el: planArea, val: draft.plan || '' }
            ];

            mapping.forEach(function (m) {
                if (isAppend) {
                    if (m.el.value.trim() !== '') {
                        m.el.value = m.el.value.trimEnd() + '\n\n--- Borrador IA ---\n' + m.val;
                    } else {
                        m.el.value = m.val;
                    }
                } else {
                    m.el.value = m.val;
                }
                // Trigger change/input events for OpenEMR listeners
                m.el.dispatchEvent(new Event('input', { bubbles: true }));
                m.el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        // 15. Review Banner & Verify Marker Alert
        function showReviewBanner(hasVerify) {
            let banner = document.getElementById('oe-ai-draft-review-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'oe-ai-review-banner';
                banner.id = 'oe-ai-draft-review-banner';
                dictationContainer.parentNode.insertBefore(banner, dictationContainer.nextSibling);
            }

            let verifyNotice = '';
            if (hasVerify) {
                verifyNotice = '<span class="oe-ai-verify-badge ml-2"><i class="fa fa-exclamation-triangle"></i> Contiene elementos a verificar [VERIFY]</span>';
            }

            banner.innerHTML = `
                <div>
                    <i class="fa fa-shield-alt mr-1"></i>
                    <strong>Borrador generado por IA:</strong> revisá y editá el contenido antes de guardar la consulta.
                    ${verifyNotice}
                </div>
                <button type="button" class="btn btn-sm btn-outline-dark" id="oe-ai-btn-dismiss-banner" style="padding: 2px 8px; font-size: 0.8rem;">
                    Entendido
                </button>
            `;

            const btnDismiss = document.getElementById('oe-ai-btn-dismiss-banner');
            if (btnDismiss) {
                btnDismiss.addEventListener('click', function () {
                    banner.style.display = 'none';
                });
            }
        }

        // 16. Modal Dialog for Replace vs. Append
        function showReplaceAppendModal(callback) {
            const overlay = document.createElement('div');
            overlay.className = 'oe-ai-modal-overlay';
            overlay.id = 'oe-ai-confirm-modal';

            overlay.innerHTML = `
                <div class="oe-ai-modal-card">
                    <div class="oe-ai-modal-header">
                        <i class="fa fa-question-circle mr-1 text-primary"></i> Contenido previo detectado
                    </div>
                    <div class="oe-ai-modal-body">
                        Los campos de la nota SOAP ya contienen texto. ¿Cómo deseás incorporar el nuevo borrador generado por la IA?
                    </div>
                    <div class="oe-ai-modal-footer">
                        <button type="button" class="oe-ai-btn oe-ai-btn-discard" id="oe-ai-modal-cancel">Cancelar</button>
                        <button type="button" class="oe-ai-btn btn-secondary" id="oe-ai-modal-append">Anexar al final</button>
                        <button type="button" class="oe-ai-btn oe-ai-btn-record" id="oe-ai-modal-replace">Reemplazar todo</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            document.getElementById('oe-ai-modal-cancel').addEventListener('click', function () {
                document.body.removeChild(overlay);
                callback('cancel');
            });
            document.getElementById('oe-ai-modal-append').addEventListener('click', function () {
                document.body.removeChild(overlay);
                callback('append');
            });
            document.getElementById('oe-ai-modal-replace').addEventListener('click', function () {
                document.body.removeChild(overlay);
                callback('replace');
            });
        }

        // 17. Soft Confirmation on Native Save if [VERIFY] Remains
        function attachSaveInterceptor() {
            const form = subjArea.closest('form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                const combinedText = (subjArea.value || '') + ' ' +
                                     (objArea.value || '') + ' ' +
                                     (assArea.value || '') + ' ' +
                                     (planArea.value || '');

                if (combinedText.includes('[VERIFY')) {
                    const proceed = window.confirm(
                        'Atención: La nota SOAP aún contiene marcadores [VERIFY: ...] pendientes de confirmación clínica.\n\n¿Deseás guardar de todos modos?'
                    );
                    if (!proceed) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }
            }, true);
        }

        attachSaveInterceptor();

        // 18. Attach Event Listeners
        btnRecord.addEventListener('click', startRecording);
        btnStop.addEventListener('click', stopRecording);
        btnDiscard.addEventListener('click', discardRecording);
        btnGenerate.addEventListener('click', generateSoapDraft);
    }
}());