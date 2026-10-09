/**
 * ai-launch.js — injects the "IA" button into the native SOAP form.
 *
 * The native SOAP form (load_form.php / view_form.php) is no longer given the full
 * dictation toolbar. Instead, this lightweight script adds a single button next to
 * the native Save/Cancel group. Clicking it navigates the encounter tab to the
 * SOAP-AI editor (public/form.php), which reads and writes the same form_soap row.
 *
 * The editor is deliberately reachable ONLY from here: it is not registered in the
 * encounter "Add Form" menu, so there is always exactly one SOAP form per encounter.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 */

(function () {
    'use strict';

    if (window.__OE_AI_LAUNCH_LOADED__) {
        return;
    }
    window.__OE_AI_LAUNCH_LOADED__ = true;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLaunch);
    } else {
        initLaunch();
    }

    function initLaunch() {
        // Only on the native SOAP form.
        var subjArea = document.querySelector('textarea[name="subjective"]');
        if (!subjArea || document.getElementById('oe-ai-launch-btn')) {
            return;
        }

        var urlParams = new URLSearchParams(window.location.search);
        var siteId = urlParams.get('site') || 'default';

        function inputValue(name) {
            var el = document.querySelector('input[name="' + name + '"]');
            return el ? el.value : '';
        }

        var pid = parseInt(
            inputValue('pid') || urlParams.get('pid') || (window.top && window.top.pid ? window.top.pid : 0),
            10
        );
        if (isNaN(pid) || pid <= 0) { pid = 0; }

        var encounter = parseInt(
            urlParams.get('encounter') || (window.top && window.top.encounter ? window.top.encounter : 0),
            10
        );
        if (isNaN(encounter) || encounter <= 0) { encounter = 0; }

        var formId = parseInt(inputValue('id') || urlParams.get('id') || 0, 10);
        if (isNaN(formId) || formId <= 0) { formId = 0; }

        var webRoot = '';
        if (window.top && window.top.webroot) {
            webRoot = window.top.webroot;
        } else {
            var loc = window.location.pathname;
            var idx = loc.indexOf('/interface/');
            webRoot = (idx !== -1) ? loc.substring(0, idx) : '';
        }

        var editorUrl = webRoot
            + '/interface/modules/custom_modules/oe-module-ai-assistant/public/form.php'
            + '?pid=' + encodeURIComponent(pid)
            + '&encounter=' + encodeURIComponent(encounter)
            + '&id=' + encodeURIComponent(formId)
            + '&site=' + encodeURIComponent(siteId);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'oe-ai-launch-btn';
        btn.className = 'btn oe-ai-launch-btn';
        btn.title = 'SOAP con IA';
        btn.innerHTML = '<i class="fa fa-robot" aria-hidden="true"></i> '
            + '<span class="oe-ai-launch-btn-label">IA</span>';

        btn.addEventListener('click', function () {
            if (window.top && typeof window.top.restoreSession === 'function') {
                window.top.restoreSession();
            }
            window.location.href = editorUrl;
        });

        var group = document.querySelector('form[name="soap"] .btn-group')
            || document.querySelector('.btn-group');
        if (group) {
            group.insertBefore(btn, group.firstChild);
        } else {
            btn.classList.add('oe-ai-launch-btn-floating');
            document.body.appendChild(btn);
        }
    }
}());
