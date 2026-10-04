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
                           value="<?php echo attr($current['whisper_max_audio_sec'] ?? '300'); ?>">
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-test-whisper">
                        <?php echo xlt('Test Whisper Connection'); ?>
                    </button>
                    <span id="whisper-test-result" class="ml-2"></span>
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
</div><!-- /container-fluid -->

<script>
// Show/hide provider fieldsets based on active provider selection
(function () {
    const select = document.getElementById('active_provider');
    function toggle() {
        ['openai', 'anthropic', 'gemini'].forEach(function (p) {
            const el = document.getElementById('fields-' + p);
            if (el) el.style.display = (select.value === p) ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();

    // Whisper test button (placeholder — AJAX endpoint added in M2)
    document.getElementById('btn-test-whisper').addEventListener('click', function () {
        document.getElementById('whisper-test-result').textContent = '<?php echo xlt('(available in M2)'); ?>';
    });

    // Provider test buttons (placeholder — AJAX endpoint added in M3)
    document.querySelectorAll('.btn-test-provider').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const p = btn.dataset.provider;
            document.querySelector('.provider-test-result[data-provider="' + p + '"]').textContent =
                '<?php echo xlt('(available in M3)'); ?>';
        });
    });
}());
</script>
</body>
</html>
