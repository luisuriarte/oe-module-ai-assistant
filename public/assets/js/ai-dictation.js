/**
 * ai-dictation.js - AI Dictation control for the SOAP form.
 *
 * M1 SMOKE TEST: confirms the script is loaded and the DOM is accessible.
 * - Logs a prefixed message to the browser console.
 * - Inserts a visible placeholder banner above the first SOAP fieldset.
 *
 * No other functionality. Full dictation UI implemented in M5.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */
(function () {
    'use strict';

    var PREFIX = '[AiAssistant]';

    /* ------------------------------------------------------------------ *
     * Step 1 — Log to console immediately (script is executing)           *
     * ------------------------------------------------------------------ */
    console.log(PREFIX + ' ai-dictation.js loaded and executing.');

    /* ------------------------------------------------------------------ *
     * Step 2 — Wait for DOM ready, then insert the placeholder banner     *
     * ------------------------------------------------------------------ */
    function onDOMReady() {
        console.log(PREFIX + ' DOMContentLoaded fired; inserting smoke-test banner.');

        /* The SOAP form has <form name="soap"> containing <fieldset> elements.
         * The page may be inside an OpenEMR iframe, so we search the whole
         * document. We target the first <fieldset> inside form[name="soap"]. */
        var form = document.querySelector('form[name="soap"]');
        if (!form) {
            console.warn(PREFIX + ' form[name="soap"] not found — banner not inserted.');
            return;
        }

        var firstFieldset = form.querySelector('fieldset');
        if (!firstFieldset) {
            console.warn(PREFIX + ' No <fieldset> found inside form[name="soap"] — banner not inserted.');
            return;
        }

        /* Build the banner */
        var banner = document.createElement('div');
        banner.id = 'oe-ai-assistant-smoke-banner';
        banner.style.cssText = [
            'background:#d1ecf1',
            'border:1px solid #bee5eb',
            'border-radius:4px',
            'color:#0c5460',
            'font-family:sans-serif',
            'font-size:13px',
            'margin:0 0 12px 0',
            'padding:8px 14px',
            'display:flex',
            'align-items:center',
            'gap:8px'
        ].join(';');

        var icon = document.createElement('span');
        icon.textContent = '\uD83E\uDD16'; /* 🤖 */
        icon.setAttribute('aria-hidden', 'true');

        var text = document.createElement('span');
        text.textContent = 'AI Assistant loaded (M1 smoke test \u2014 placeholder).';

        banner.appendChild(icon);
        banner.appendChild(text);

        /* Insert BEFORE the first fieldset */
        firstFieldset.parentNode.insertBefore(banner, firstFieldset);

        console.log(PREFIX + ' Smoke-test banner inserted before first fieldset.');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onDOMReady);
    } else {
        /* DOM already ready (script tag was defer or placed after body) */
        onDOMReady();
    }

}());