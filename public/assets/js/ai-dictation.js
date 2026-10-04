/**
 * ai-dictation.js - AI Dictation control for the SOAP form.
 *
 * M1 SMOKE TEST: confirms the script is loaded and the DOM is accessible.
 * - Guarded against multiple executions (idempotent per document).
 * - Fixed banner ID check avoids inserting duplicates into the DOM.
 * - Executes immediately if document.readyState is past 'loading'.
 *
 * No other functionality. Full dictation UI implemented in M5.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */
(function () {
    'use strict';

    var PREFIX = '[AiAssistant]';
    var BANNER_ID = 'oe-ai-assistant-smoke-banner';

    /* ------------------------------------------------------------------ *
     * Guard 1 — Global flag: run script logic at most once per document   *
     * ------------------------------------------------------------------ */
    if (window.__OE_AI_ASSISTANT_LOADED__) {
        console.warn(PREFIX + ' ai-dictation.js already executed on this window/document. Skipping duplicate execution.');
        return;
    }
    window.__OE_AI_ASSISTANT_LOADED__ = true;

    console.log(PREFIX + ' ai-dictation.js loaded and executing (first run).');

    /* ------------------------------------------------------------------ *
     * Banner insertion logic (idempotent)                                *
     * ------------------------------------------------------------------ */
    function insertBanner() {
        /* Guard 2 — Skip insertion if element with this fixed ID already exists */
        if (document.getElementById(BANNER_ID)) {
            console.warn(PREFIX + ' Banner #' + BANNER_ID + ' already exists in DOM. Skipping insertion.');
            return;
        }

        /* The SOAP form has <form name="soap"> containing <fieldset> elements.
         * The page may be inside an OpenEMR iframe, so we search the current document.
         * We target the first <fieldset> inside form[name="soap"]. */
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
        banner.id = BANNER_ID;
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

    /* ------------------------------------------------------------------ *
     * Execution trigger: run immediately if past 'loading', else wait    *
     * ------------------------------------------------------------------ */
    if (document.readyState === 'loading') {
        console.log(PREFIX + ' document.readyState is "loading"; waiting for DOMContentLoaded.');
        document.addEventListener('DOMContentLoaded', insertBanner, { once: true });
    } else {
        console.log(PREFIX + ' document.readyState is "' + document.readyState + '" (past loading); inserting immediately.');
        insertBanner();
    }

}());