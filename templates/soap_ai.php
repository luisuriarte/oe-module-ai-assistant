<?php

/**
 * SOAP-AI editor template.
 *
 * Rendered by SoapAiFormController::render(). Variables in scope:
 *   $pid, $encounter, $formId           int
 *   $subjective, $objective, $assessment, $plan   string (existing note values)
 *   $webRoot, $publicEndpoint, $siteId  string
 *   $csrf                               string
 *   $chatEnabled                        bool
 *   $nativeViewUrl                      string
 *   $assets                             array{sharedCss,editorCss,editorJs}
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */

use OpenEMR\Core\Header;

$config = [
    'pid'            => $pid,
    'encounter'     => $encounter,
    'formId'        => $formId,
    'csrf'          => $csrf,
    'webRoot'       => $webRoot,
    'publicEndpoint' => $publicEndpoint,
    'siteId'        => $siteId,
    'nativeViewUrl' => $nativeViewUrl,
    'chatEnabled'   => $chatEnabled,
    'strings'       => [
        'saving'            => xlt('Saving...'),
        'saved'             => xlt('Saved.'),
        'saveFailed'        => xlt('Could not save the SOAP note. Please try again.'),
        'verifySaveConfirm' => xlt('This note still contains [VERIFY: ...] markers. Save anyway?'),
        'unsavedConfirm'    => xlt('You have unsaved changes. Leave without saving?'),
        'newNote'           => xlt('New note'),
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('SOAP with AI'); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="<?php echo attr($assets['sharedCss']); ?>">
    <link rel="stylesheet" href="<?php echo attr($assets['editorCss']); ?>">
    <script>
        window.OE_AI_SOAP_AI = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
</head>
<body class="oe-ai-editor-body">
<div class="oe-ai-editor">

    <!-- ===================== Header ===================== -->
    <header class="oe-ai-editor-header">
        <div class="oe-ai-editor-heading">
            <span class="oe-ai-editor-icon" aria-hidden="true"><i class="fa fa-robot"></i></span>
            <div>
                <h1 class="oe-ai-editor-title"><?php echo xlt('SOAP with AI'); ?></h1>
                <p class="oe-ai-editor-meta">
                    <?php echo xlt('Patient'); ?> #<?php echo text((string) $pid); ?>
                    &middot; <?php echo xlt('Encounter'); ?> #<?php echo text((string) $encounter); ?>
                    &middot; <?php echo xlt('Note'); ?>
                    <?php echo $formId > 0 ? '#' . text((string) $formId) : text(xlt('New note')); ?>
                </p>
            </div>
        </div>
        <div class="oe-ai-editor-actions">
            <span id="oe-ai-save-status" class="oe-ai-editor-save-status" role="status" aria-live="polite"></span>
            <button type="button" id="oe-ai-back" class="oe-ai-btn oe-ai-btn-discard">
                <i class="fa fa-arrow-left" aria-hidden="true"></i> <?php echo xlt('Back to native SOAP'); ?>
            </button>
            <button type="button" id="oe-ai-save" class="oe-ai-btn oe-ai-btn-generate">
                <i class="fa fa-save" aria-hidden="true"></i> <?php echo xlt('Save'); ?>
            </button>
        </div>
    </header>

    <div class="oe-ai-editor-grid<?php echo $chatEnabled ? '' : ' oe-ai-editor-grid-no-chat'; ?>">

        <!-- ===================== Main column ===================== -->
        <main class="oe-ai-editor-main">

            <!-- Dictation toolbar (reuses ai-assistant.css classes) -->
            <div class="oe-ai-dictation-container" id="oe-ai-dictation-toolbar">
                <div class="oe-ai-toolbar">
                    <div class="oe-ai-left-group">
                        <span class="oe-ai-title">
                            <i class="fa fa-microphone" aria-hidden="true"></i>
                            <span data-i18n="title"><?php echo xlt('Clinical AI Dictation'); ?></span>
                        </span>
                        <span class="oe-ai-badge">Whisper + LLM</span>
                        <button type="button" class="oe-ai-btn oe-ai-btn-record" id="oe-ai-btn-record">
                            <i class="fa fa-circle" aria-hidden="true"></i>
                            <span data-i18n="record"><?php echo xlt('Record'); ?></span>
                        </button>
                        <button type="button" class="oe-ai-btn oe-ai-btn-stop" id="oe-ai-btn-stop" style="display: none;">
                            <i class="fa fa-stop" aria-hidden="true"></i>
                            <span data-i18n="stop"><?php echo xlt('Stop'); ?></span>
                        </button>
                        <button type="button" class="oe-ai-btn oe-ai-btn-pause" id="oe-ai-btn-pause" style="display: none;">
                            <i class="fa fa-pause" aria-hidden="true"></i>
                            <span data-i18n="pause"><?php echo xlt('Pause'); ?></span>
                        </button>
                        <button type="button" class="oe-ai-btn oe-ai-btn-resume" id="oe-ai-btn-resume" style="display: none;">
                            <i class="fa fa-play" aria-hidden="true"></i>
                            <span data-i18n="resume"><?php echo xlt('Resume'); ?></span>
                        </button>
                        <button type="button" class="oe-ai-btn oe-ai-btn-discard" id="oe-ai-btn-discard" style="display: none;">
                            <i class="fa fa-trash" aria-hidden="true"></i>
                            <span data-i18n="discard"><?php echo xlt('Discard'); ?></span>
                        </button>
                        <span class="oe-ai-timer" id="oe-ai-timer" style="display: none;">00:00 / 03:00</span>
                        <span class="oe-ai-status-text" id="oe-ai-status" data-i18n="ready"><?php echo xlt('Ready to dictate'); ?></span>
                    </div>
                    <div class="oe-ai-right-group">
                        <button type="button" class="oe-ai-btn oe-ai-btn-generate" id="oe-ai-btn-generate" style="display: none;">
                            <i class="fa fa-magic" aria-hidden="true"></i>
                            <span data-i18n="generate_soap"><?php echo xlt('Generate SOAP Note'); ?></span>
                        </button>
                    </div>
                </div>

                <div class="oe-ai-transcript-panel" id="oe-ai-transcript-panel" style="display: none;">
                    <label for="oe-ai-transcript-text">
                        <span data-i18n="transcript_label"><?php echo xlt('Dictation transcript (you can edit it before generating the draft):'); ?></span>
                        <span id="oe-ai-transcript-status" class="text-muted small"></span>
                    </label>
                    <textarea class="oe-ai-transcript-textarea" id="oe-ai-transcript-text" rows="4"
                              data-i18n-placeholder="transcript_placeholder"
                              placeholder="<?php echo xla('The consultation transcript will appear here...'); ?>"></textarea>
                </div>
            </div>

            <div id="oe-ai-draft-banner-host"></div>

            <form id="oe-ai-note-form" autocomplete="off" onsubmit="return false;">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                <input type="hidden" name="id" value="<?php echo attr((string) $formId); ?>">
                <input type="hidden" name="pid" value="<?php echo attr((string) $pid); ?>">
                <input type="hidden" name="encounter" value="<?php echo attr((string) $encounter); ?>">

                <section class="oe-ai-note">
                    <div class="oe-ai-field-card">
                        <label class="oe-ai-field-label" for="oe-ai-subjective">
                            <span class="oe-ai-field-letter" aria-hidden="true">S</span>
                            <?php echo xlt('Subjective'); ?>
                        </label>
                        <textarea id="oe-ai-subjective" name="subjective" class="oe-ai-soap-textarea"
                                  rows="6" placeholder="<?php echo xla('Subjective findings...'); ?>"><?php echo text($subjective); ?></textarea>
                    </div>

                    <div class="oe-ai-field-card">
                        <label class="oe-ai-field-label" for="oe-ai-objective">
                            <span class="oe-ai-field-letter" aria-hidden="true">O</span>
                            <?php echo xlt('Objective'); ?>
                        </label>
                        <textarea id="oe-ai-objective" name="objective" class="oe-ai-soap-textarea"
                                  rows="6" placeholder="<?php echo xla('Objective findings...'); ?>"><?php echo text($objective); ?></textarea>
                    </div>

                    <div class="oe-ai-field-card">
                        <label class="oe-ai-field-label" for="oe-ai-assessment">
                            <span class="oe-ai-field-letter" aria-hidden="true">A</span>
                            <?php echo xlt('Assessment'); ?>
                        </label>
                        <textarea id="oe-ai-assessment" name="assessment" class="oe-ai-soap-textarea"
                                  rows="6" placeholder="<?php echo xla('Assessment / diagnosis...'); ?>"><?php echo text($assessment); ?></textarea>
                    </div>

                    <div class="oe-ai-field-card">
                        <label class="oe-ai-field-label" for="oe-ai-plan">
                            <span class="oe-ai-field-letter" aria-hidden="true">P</span>
                            <?php echo xlt('Plan'); ?>
                        </label>
                        <textarea id="oe-ai-plan" name="plan" class="oe-ai-soap-textarea"
                                  rows="6" placeholder="<?php echo xla('Plan / follow-up...'); ?>"><?php echo text($plan); ?></textarea>
                    </div>
                </section>
            </form>
        </main>

        <!-- ===================== Chat column (Layer 2, optional) ===================== -->
        <?php if ($chatEnabled) { ?>
        <aside class="oe-ai-editor-side">
            <section class="oe-ai-editor-chat" id="oe-ai-chat" aria-label="<?php echo xla('Clinical AI Chat'); ?>">
                <header class="oe-ai-chat-header">
                    <span class="oe-ai-chat-title" data-i18n="title"><?php echo xlt('Clinical AI Chat'); ?></span>
                    <span class="oe-ai-chat-actions">
                        <button type="button" class="oe-ai-chat-link" id="oe-ai-chat-clear" data-i18n="clear"><?php echo xlt('Clear'); ?></button>
                    </span>
                </header>
                <div class="oe-ai-chat-messages" id="oe-ai-chat-messages" role="log" aria-live="polite"></div>
                <div class="oe-ai-chat-notice" id="oe-ai-chat-notice" hidden></div>
                <footer class="oe-ai-chat-composer">
                    <textarea id="oe-ai-chat-input" rows="2" class="oe-ai-chat-input"
                              data-i18n-placeholder="placeholder"
                              placeholder="<?php echo xla('Ask a question about this patient...'); ?>"></textarea>
                    <button type="button" class="oe-ai-chat-send" id="oe-ai-chat-send" data-i18n="send"><?php echo xlt('Send'); ?></button>
                </footer>
            </section>
            <p class="oe-ai-editor-side-hint">
                <?php echo xlt('Answers come only from this patient\'s chart and cite their source section.'); ?>
            </p>
        </aside>
        <?php } ?>

    </div>
</div>

<script src="<?php echo attr($assets['editorJs']); ?>"></script>
</body>
</html>
