<?php
/**
 * Settings page template.
 *
 * Variables injected by SettingsController::renderForm():
 *   $csrf          string   CSRF token
 *   $current       array    Current setting values (encrypted values redacted)
 *   $keyStatus     array    ['openai_api_key'=>bool, ...]  — true if a key is saved
 *   $consentGiven  bool
 *   $message       string   Status message after POST
 *   $success       bool
 *   $webRoot       string
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('AI Assistant Settings'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .ai-settings-section {
            margin-bottom: 1.5rem;
        }
        .key-saved-badge {
            display: inline-block;
            padding: 0.1rem 0.5rem;
            background: #d4edda;
            color: #155724;
            border-radius: 3px;
            font-size: 0.8rem;
        }
        .consent-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
<div class="container-fluid mt-3">

    <h2><?php echo xlt('AI Assistant — Settings'); ?></h2>

    <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible">
        <?php echo text($message); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Consent notice (must be acknowledged before first use) -->
    <?php if (!$consentGiven): ?>
    <div class="consent-box">
        <strong><?php echo xlt('Required: Data Consent Acknowledgement'); ?></strong>
        <p><?php echo xlt('By enabling the AI Assistant, you confirm that your clinic has:'); ?></p>
        <ul>
            <li><?php echo xlt('A legal basis for processing patient clinical data with a third-party AI provider.'); ?></li>
            <li><?php echo xlt('An appropriate patient consent process in place.'); ?></li>
        </ul>
        <p><?php echo xlt('Data categories sent to the provider: problem list, medications, allergies, SOAP note summaries, vitals summary, lab results (if enabled). No names, addresses, national IDs or insurance numbers are sent.'); ?></p>
        <p><em><?php echo xlt('Tick the checkbox in the form below to acknowledge.'); ?></em></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo attr($webRoot); ?>/interface/modules/custom_modules/oe-module-ai-assistant/moduleConfig.php">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">

        <!-- ==================== CONSENT ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('Legal Consent'); ?></div>
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="consent_acknowledged"
                           id="consent_acknowledged" value="1"
                           <?php echo $consentGiven ? 'checked disabled' : ''; ?>>
                    <label class="form-check-label" for="consent_acknowledged">
                        <?php echo xlt('I confirm the clinic has a legal basis and patient consent process for AI-assisted processing.'); ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- ==================== WHISPER SERVER ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('Whisper Transcription Server'); ?></div>
            <div class="card-body row">
                <div class="col-md-5 form-group">
                    <label for="whisper_url"><?php echo xlt('Server URL'); ?></label>
                    <input type="url" class="form-control" id="whisper_url" name="whisper_url"
                           value="<?php echo attr($current['whisper_url'] ?? 'http://127.0.0.1:8178'); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="whisper_timeout"><?php echo xlt('Timeout (s)'); ?></label>
                    <input type="number" class="form-control" id="whisper_timeout" name="whisper_timeout"
                           min="10" max="300"
                           value="<?php echo attr($current['whisper_timeout'] ?? '60'); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="whisper_max_audio_sec"><?php echo xlt('Max audio length (s)'); ?></label>
                    <input type="number" class="form-control" id="whisper_max_audio_sec" name="whisper_max_audio_sec"
                           min="30" max="600"
                           value="<?php echo attr($current['whisper_max_audio_sec'] ?? '180'); ?>">
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-test-whisper">
                        <?php echo xlt('Test Whisper Connection'); ?>
                    </button>
                    <span id="whisper-test-result" class="ml-2 font-weight-bold"></span>
                </div>
            </div>
        </div>

        <!-- ==================== AI PROVIDER ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('AI Provider'); ?></div>
            <div class="card-body">
                <div class="form-group">
                    <label for="active_provider"><?php echo xlt('Active Provider'); ?></label>
                    <select class="form-control w-auto" id="active_provider" name="active_provider">
                        <?php foreach (['openai' => 'OpenAI-compatible', 'anthropic' => 'Anthropic', 'gemini' => 'Google Gemini'] as $val => $label): ?>
                        <option value="<?php echo attr($val); ?>"
                            <?php echo ($current['active_provider'] ?? 'openai') === $val ? 'selected' : ''; ?>>
                            <?php echo text($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- OpenAI-compatible -->
                <fieldset class="border p-2 mb-3 provider-fields" id="fields-openai">
                    <legend class="w-auto px-2"><?php echo xlt('OpenAI-compatible'); ?></legend>
                    <div class="row">
                        <div class="col-md-5 form-group">
                            <label><?php echo xlt('Base URL'); ?></label>
                            <input type="url" class="form-control" name="openai_base_url"
                                   value="<?php echo attr($current['openai_base_url'] ?? 'https://api.openai.com/v1'); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label><?php echo xlt('Model'); ?></label>
                            <input type="text" class="form-control" name="openai_model"
                                   value="<?php echo attr($current['openai_model'] ?? 'gpt-4o'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Temperature'); ?></label>
                            <input type="number" step="0.1" min="0" max="2" class="form-control" name="openai_temperature"
                                   value="<?php echo attr($current['openai_temperature'] ?? '0.2'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Max tokens'); ?></label>
                            <input type="number" min="256" max="16384" class="form-control" name="openai_max_tokens"
                                   value="<?php echo attr($current['openai_max_tokens'] ?? '2048'); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>
                                <?php echo xlt('API Key'); ?>
                                <?php if ($keyStatus['openai_api_key']): ?>
                                <span class="key-saved-badge"><?php echo xlt('Key saved'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="password" class="form-control" name="openai_api_key"
                                   placeholder="<?php echo attr($keyStatus['openai_api_key'] ? xlt('Leave blank to keep existing key') : xlt('Enter API key')); ?>"
                                   autocomplete="new-password">
                            <small class="form-text text-muted"><?php echo xlt('Never stored in logs or sent to the browser.'); ?></small>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-test-provider" data-provider="openai">
                        <?php echo xlt('Test OpenAI Connection'); ?>
                    </button>
                    <span class="provider-test-result ml-2" data-provider="openai"></span>
                </fieldset>

                <!-- Anthropic -->
                <fieldset class="border p-2 mb-3 provider-fields" id="fields-anthropic">
                    <legend class="w-auto px-2"><?php echo xlt('Anthropic'); ?></legend>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label><?php echo xlt('Model'); ?></label>
                            <input type="text" class="form-control" name="anthropic_model"
                                   value="<?php echo attr($current['anthropic_model'] ?? 'claude-opus-4-5'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Temperature'); ?></label>
                            <input type="number" step="0.1" min="0" max="1" class="form-control" name="anthropic_temperature"
                                   value="<?php echo attr($current['anthropic_temperature'] ?? '0.2'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Max tokens'); ?></label>
                            <input type="number" min="256" max="16384" class="form-control" name="anthropic_max_tokens"
                                   value="<?php echo attr($current['anthropic_max_tokens'] ?? '2048'); ?>">
                        </div>
                        <div class="col-md-5 form-group">
                            <label>
                                <?php echo xlt('API Key'); ?>
                                <?php if ($keyStatus['anthropic_api_key']): ?>
                                <span class="key-saved-badge"><?php echo xlt('Key saved'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="password" class="form-control" name="anthropic_api_key"
                                   placeholder="<?php echo attr($keyStatus['anthropic_api_key'] ? xlt('Leave blank to keep existing key') : xlt('Enter API key')); ?>"
                                   autocomplete="new-password">
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-test-provider" data-provider="anthropic">
                        <?php echo xlt('Test Anthropic Connection'); ?>
                    </button>
                    <span class="provider-test-result ml-2" data-provider="anthropic"></span>
                </fieldset>

                <!-- Gemini -->
                <fieldset class="border p-2 mb-3 provider-fields" id="fields-gemini">
                    <legend class="w-auto px-2"><?php echo xlt('Google Gemini'); ?></legend>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label><?php echo xlt('Model'); ?></label>
                            <input type="text" class="form-control" name="gemini_model"
                                   value="<?php echo attr($current['gemini_model'] ?? 'gemini-2.0-flash'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Temperature'); ?></label>
                            <input type="number" step="0.1" min="0" max="1" class="form-control" name="gemini_temperature"
                                   value="<?php echo attr($current['gemini_temperature'] ?? '0.2'); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label><?php echo xlt('Max tokens'); ?></label>
                            <input type="number" min="256" max="16384" class="form-control" name="gemini_max_tokens"
                                   value="<?php echo attr($current['gemini_max_tokens'] ?? '2048'); ?>">
                        </div>
                        <div class="col-md-5 form-group">
                            <label>
                                <?php echo xlt('API Key'); ?>
                                <?php if ($keyStatus['gemini_api_key']): ?>
                                <span class="key-saved-badge"><?php echo xlt('Key saved'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="password" class="form-control" name="gemini_api_key"
                                   placeholder="<?php echo attr($keyStatus['gemini_api_key'] ? xlt('Leave blank to keep existing key') : xlt('Enter API key')); ?>"
                                   autocomplete="new-password">
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-test-provider" data-provider="gemini">
                        <?php echo xlt('Test Gemini Connection'); ?>
                    </button>
                    <span class="provider-test-result ml-2" data-provider="gemini"></span>
                </fieldset>
            </div>
        </div>

        <!-- ==================== CONTEXT OPTIONS ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('Patient Context Options'); ?></div>
            <div class="card-body row">
                <div class="col-md-3 form-group">
                    <label for="context_num_encounters"><?php echo xlt('Past encounters to include'); ?></label>
                    <input type="number" class="form-control" id="context_num_encounters"
                           name="context_num_encounters" min="1" max="20"
                           value="<?php echo attr($current['context_num_encounters'] ?? '5'); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="context_token_budget"><?php echo xlt('Context token budget'); ?></label>
                    <input type="number" class="form-control" id="context_token_budget"
                           name="context_token_budget" min="1000" max="32000" step="500"
                           value="<?php echo attr($current['context_token_budget'] ?? '4000'); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="output_language"><?php echo xlt('Output language'); ?></label>
                    <select class="form-control" id="output_language" name="output_language">
                        <option value="es" <?php echo ($current['output_language'] ?? 'es') === 'es' ? 'selected' : ''; ?>>Español</option>
                        <option value="en" <?php echo ($current['output_language'] ?? 'es') === 'en' ? 'selected' : ''; ?>>English</option>
                    </select>
                </div>
                <div class="col-md-3 form-group d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="context_include_labs"
                               name="context_include_labs" value="1"
                               <?php echo ($current['context_include_labs'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="context_include_labs">
                            <?php echo xlt('Include recent lab results'); ?>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== FEATURES ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('Features'); ?></div>
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="chat_enabled"
                           name="chat_enabled" value="1"
                           <?php echo ($current['chat_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="chat_enabled">
                        <?php echo xlt('Enable patient chat panel (Layer 2)'); ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- ==================== AUDIT & DEBUG ==================== -->
        <div class="ai-settings-section card">
            <div class="card-header"><?php echo xlt('Audit &amp; Debug'); ?></div>
            <div class="card-body row">
                <div class="col-md-3 form-group">
                    <label for="audit_retention_days"><?php echo xlt('Audit log retention (days)'); ?></label>
                    <input type="number" class="form-control" id="audit_retention_days"
                           name="audit_retention_days" min="7" max="3650"
                           value="<?php echo attr($current['audit_retention_days'] ?? '90'); ?>">
                </div>
                <div class="col-md-6 form-group d-flex align-items-end">
                    <div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="debug_log_content"
                                   name="debug_log_content" value="1"
                                   <?php echo ($current['debug_log_content'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label text-danger" for="debug_log_content">
                                <strong><?php echo xlt('Debug: log prompts and responses (SENSITIVE — disable in production)'); ?></strong>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save -->
        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><?php echo xlt('Save Settings'); ?></button>
        </div>

    </form>

    <!-- ==================== ADMIN TEST BENCH (WHISPER) ==================== -->
    <div class="ai-settings-section card border-info mb-4">
        <div class="card-header bg-light text-dark font-weight-bold d-flex justify-content-between align-items-center">
            <span><?php echo xlt('Admin Test Bench — Whisper Audio Transcription'); ?></span>
            <span class="badge badge-info"><?php echo xlt('Upload-only (Test mode)'); ?></span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                <?php echo xlt('Upload an audio file to test end-to-end Whisper transcription through the production validation pipeline, host-shared flock lock, and polling worker. Audited with patient_id = 0, encounter_id = 0 and flagged as test.'); ?>
            </p>
            <div class="row align-items-center mb-3">
                <div class="col-md-6 form-group mb-md-0">
                    <input type="file" class="form-control-file border p-1 rounded w-100" id="test_audio_file"
                           accept="audio/*,.wav,.mp3,.m4a,.ogg,.webm">
                </div>
                <div class="col-md-6">
                    <button type="button" class="btn btn-sm btn-info" id="btn-run-test-transcribe">
                        <?php echo xlt('Transcribe Test Audio'); ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ml-2 d-none" id="btn-cancel-test-transcribe">
                        <?php echo xlt('Cancel'); ?>
                    </button>
                </div>
            </div>

            <div id="test-transcribe-feedback" class="mb-3 d-none">
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm text-info mr-2 d-none" id="test-transcribe-spinner" role="status">
                        <span class="sr-only"><?php echo xlt('Processing...'); ?></span>
                    </div>
                    <span id="test-transcribe-status-text" class="font-weight-bold"></span>
                </div>
            </div>

            <div class="form-group mb-0">
                <label for="test_transcribe_output"><strong><?php echo xlt('Transcript Output:'); ?></strong></label>
                <textarea class="form-control" id="test_transcribe_output" rows="4" readonly
                          placeholder="<?php echo attr(xlt('Transcript will appear here once processing completes...')); ?>"></textarea>
                <small id="test_transcribe_meta" class="form-text text-muted mt-1"></small>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->

<script>
(function () {
    // 1. Show/hide provider fieldsets based on active provider selection
    const select = document.getElementById('active_provider');
    function toggle() {
        ['openai', 'anthropic', 'gemini'].forEach(function (p) {
            const el = document.getElementById('fields-' + p);
            if (el) el.style.display = (select.value === p) ? '' : 'none';
        });
    }
    if (select) {
        select.addEventListener('change', toggle);
        toggle();
    }

    const webRoot = <?php echo json_encode($webRoot); ?>;
    const csrfToken = <?php echo json_encode($csrf); ?>;
    const publicEndpoint = webRoot + '/interface/modules/custom_modules/oe-module-ai-assistant/public/index.php';

    // 2. Test Whisper Connection
    const btnTestWhisper = document.getElementById('btn-test-whisper');
    const whisperResult = document.getElementById('whisper-test-result');
    if (btnTestWhisper && whisperResult) {
        btnTestWhisper.addEventListener('click', function () {
            const whisperUrl = (document.getElementById('whisper_url') ? document.getElementById('whisper_url').value : '').trim();
            whisperResult.className = 'ml-2 text-muted';
            whisperResult.textContent = '<?php echo xlt('Testing connection...'); ?>';
            btnTestWhisper.disabled = true;

            const formData = new FormData();
            formData.append('csrf_token_form', csrfToken);
            formData.append('whisper_url', whisperUrl);

            fetch(publicEndpoint + '?action=test_whisper', {
                method: 'POST',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                btnTestWhisper.disabled = false;
                const data = resObj.data;
                if (data.ok) {
                    whisperResult.className = 'ml-2 text-success font-weight-bold';
                    whisperResult.textContent = '<?php echo xlt('Connection successful'); ?> (' + data.latency_ms + 'ms, HTTP ' + data.status_code + ')';
                } else {
                    whisperResult.className = 'ml-2 text-danger font-weight-bold';
                    whisperResult.textContent = '<?php echo xlt('Connection failed:'); ?> ' + (data.error || ('HTTP ' + resObj.status));
                }
            })
            .catch(function (err) {
                btnTestWhisper.disabled = false;
                whisperResult.className = 'ml-2 text-danger font-weight-bold';
                whisperResult.textContent = '<?php echo xlt('Request error:'); ?> ' + err.message;
            });
        });
    }

    // 3. Admin Test Bench (Upload-only test audio transcription)
    const btnRunTest = document.getElementById('btn-run-test-transcribe');
    const btnCancelTest = document.getElementById('btn-cancel-test-transcribe');
    const fileInput = document.getElementById('test_audio_file');
    const feedbackDiv = document.getElementById('test-transcribe-feedback');
    const spinner = document.getElementById('test-transcribe-spinner');
    const statusText = document.getElementById('test-transcribe-status-text');
    const outputArea = document.getElementById('test_transcribe_output');
    const metaText = document.getElementById('test_transcribe_meta');

    let pollTimer = null;
    let pollStart = 0;

    function stopTestBenchPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (spinner) spinner.classList.add('d-none');
        if (btnCancelTest) btnCancelTest.classList.add('d-none');
        if (btnRunTest) btnRunTest.disabled = false;
    }

    if (btnCancelTest) {
        btnCancelTest.addEventListener('click', function () {
            stopTestBenchPolling();
            if (statusText) {
                statusText.className = 'text-warning font-weight-bold';
                statusText.textContent = '<?php echo xlt('Polling cancelled by user.'); ?>';
            }
        });
    }

    if (btnRunTest && fileInput) {
        btnRunTest.addEventListener('click', function () {
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('<?php echo xlt('Please select an audio file first.'); ?>');
                return;
            }

            const file = fileInput.files[0];
            outputArea.value = '';
            metaText.textContent = '';
            feedbackDiv.classList.remove('d-none');
            spinner.classList.remove('d-none');
            btnCancelTest.classList.remove('d-none');
            statusText.className = 'text-info font-weight-bold';
            statusText.textContent = '<?php echo xlt('Submitting test audio...'); ?>';
            btnRunTest.disabled = true;

            const formData = new FormData();
            formData.append('csrf_token_form', csrfToken);
            formData.append('test_mode', '1');
            formData.append('audio', file);

            fetch(publicEndpoint + '?action=transcribe_submit', {
                method: 'POST',
                body: formData
            })
            .then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            })
            .then(function (resObj) {
                const status = resObj.status;
                const data = resObj.data;

                if (status === 429) {
                    stopTestBenchPolling();
                    statusText.className = 'text-danger font-weight-bold';
                    statusText.textContent = '<?php echo xlt('Server busy (HTTP 429): transcription lock currently held by another worker.'); ?>';
                    return;
                }

                if (status !== 200 && status !== 202) {
                    stopTestBenchPolling();
                    statusText.className = 'text-danger font-weight-bold';
                    statusText.textContent = '<?php echo xlt('Error:'); ?> ' + (data.error || ('HTTP ' + status));
                    return;
                }

                const jobId = data.job_id;
                if (!jobId) {
                    stopTestBenchPolling();
                    statusText.className = 'text-danger font-weight-bold';
                    statusText.textContent = '<?php echo xlt('Invalid response: missing job_id'); ?>';
                    return;
                }

                // If already completed synchronously
                if (data.status === 'completed' && data.text !== undefined) {
                    stopTestBenchPolling();
                    statusText.className = 'text-success font-weight-bold';
                    statusText.textContent = '<?php echo xlt('Transcription completed!'); ?>';
                    outputArea.value = data.text;
                    metaText.textContent = 'Latency: ' + (data.duration_ms || 0) + ' ms | Job: ' + jobId;
                    return;
                }

                // Begin Polling
                pollStart = Date.now();
                statusText.className = 'text-info font-weight-bold';
                statusText.textContent = '<?php echo xlt('Processing audio in background...'); ?> (0s)';

                pollTimer = setInterval(function () {
                    const elapsedSec = Math.round((Date.now() - pollStart) / 1000);
                    statusText.textContent = '<?php echo xlt('Processing audio in background...'); ?> (' + elapsedSec + 's)';

                    fetch(publicEndpoint + '?action=transcribe_status&job_id=' + encodeURIComponent(jobId))
                    .then(function (pRes) {
                        return pRes.json().then(function (pData) { return { status: pRes.status, data: pData }; });
                    })
                    .then(function (pResObj) {
                        const pStatus = pResObj.status;
                        const pData = pResObj.data;

                        if (pStatus === 404) {
                            stopTestBenchPolling();
                            statusText.className = 'text-danger font-weight-bold';
                            statusText.textContent = '<?php echo xlt('Job expired or not found.'); ?>';
                            return;
                        }

                        if (pData.status === 'completed') {
                            stopTestBenchPolling();
                            statusText.className = 'text-success font-weight-bold';
                            statusText.textContent = '<?php echo xlt('Transcription completed!'); ?>';
                            outputArea.value = pData.text || '';
                            metaText.textContent = 'Duration: ' + (pData.duration_ms || 0) + ' ms | Job: ' + jobId;
                        } else if (pData.status === 'error') {
                            stopTestBenchPolling();
                            statusText.className = 'text-danger font-weight-bold';
                            statusText.textContent = '<?php echo xlt('Transcription failed:'); ?> ' + (pData.error_code || 'unknown error');
                        }
                    })
                    .catch(function (pollErr) {
                        if (elapsedSec > 180) {
                            stopTestBenchPolling();
                            statusText.className = 'text-danger font-weight-bold';
                            statusText.textContent = '<?php echo xlt('Polling timed out.'); ?>';
                        }
                    });
                }, 1000);
            })
            .catch(function (err) {
                stopTestBenchPolling();
                statusText.className = 'text-danger font-weight-bold';
                statusText.textContent = '<?php echo xlt('Submission error:'); ?> ' + err.message;
            });
        });
    }

    // 4. Provider test buttons (placeholder — AJAX endpoint added in M3)
    document.querySelectorAll('.btn-test-provider').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const p = btn.dataset.provider;
            const resEl = document.querySelector('.provider-test-result[data-provider="' + p + '"]');
            if (resEl) {
                resEl.textContent = '<?php echo xlt('(available in M3)'); ?>';
            }
        });
    });
}());
</script>
</body>
</html>
