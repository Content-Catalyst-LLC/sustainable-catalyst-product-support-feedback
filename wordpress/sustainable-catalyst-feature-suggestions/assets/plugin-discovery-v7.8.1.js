(function () {
    'use strict';

    var config = window.SCFSPluginDiscovery || null;

    function fragmentNode(selector, html) {
        var template = document.createElement('template');
        template.innerHTML = String(html || '').trim();
        return template.content.querySelector(selector);
    }

    function replaceFragment(selector, html) {
        var current = document.querySelector(selector);
        var replacement = fragmentNode(selector, html);
        if (current && replacement) {
            current.replaceWith(replacement);
        }
    }

    function showNotice(message, isError) {
        var notice = document.querySelector('[data-scfs-discovery-notice]');
        if (!notice) { return; }
        notice.hidden = false;
        notice.classList.toggle('notice-success', !isError);
        notice.classList.toggle('notice-error', !!isError);
        var paragraph = notice.querySelector('p');
        if (paragraph) { paragraph.textContent = message; }
        notice.setAttribute('tabindex', '-1');
        notice.focus();
    }

    function setBusy(form, busy) {
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        Array.prototype.forEach.call(form.querySelectorAll('button, select, input'), function (control) {
            control.disabled = busy;
        });
        var spinner = form.querySelector('.spinner');
        if (spinner) { spinner.classList.toggle('is-active', busy); }
    }

    function refreshFragments(fragments) {
        replaceFragment('[data-scfs-discovery-summary]', fragments.summary);
        replaceFragment('[data-scfs-discovery-matches]', fragments.matches);
        replaceFragment('[data-scfs-discovery-review]', fragments.review);
        replaceFragment('[data-scfs-discovery-ignored]', fragments.ignored);
        replaceFragment('[data-scfs-plugin-inventory]', fragments.inventory);
        bindInventoryFilters();
    }

    function bindInventoryFilters() {
        var search = document.querySelector('[data-scfs-inventory-search]');
        var filter = document.querySelector('[data-scfs-inventory-filter]');
        var rows = document.querySelectorAll('[data-scfs-inventory-row]');
        var empty = document.querySelector('[data-scfs-inventory-empty]');
        if (!search || !filter || !rows.length) { return; }
        var update = function () {
            var query = search.value.trim().toLowerCase();
            var classification = filter.value;
            var visible = 0;
            Array.prototype.forEach.call(rows, function (row) {
                var matchesText = !query || String(row.getAttribute('data-search') || '').indexOf(query) !== -1;
                var matchesClass = classification === 'all' || row.getAttribute('data-scope') === classification || row.getAttribute('data-type') === classification;
                row.hidden = !(matchesText && matchesClass);
                if (!row.hidden) { visible += 1; }
            });
            if (empty) { empty.hidden = visible !== 0; }
        };
        search.addEventListener('input', update);
        filter.addEventListener('change', update);
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-scfs-select-all]')) { return; }
        var table = event.target.closest('table');
        if (!table) { return; }
        Array.prototype.forEach.call(table.querySelectorAll('tbody input[type="checkbox"][name="plugin_files[]"]'), function (checkbox) {
            checkbox.checked = event.target.checked;
        });
    });

    if (config && config.restUrl && window.fetch) {
        document.addEventListener('submit', function (event) {
            var form = event.target.closest('[data-scfs-discovery-decision]');
            if (!form) { return; }
            event.preventDefault();
            var formData = new FormData(form);
            var pluginFile = formData.get('plugin_file');
            var decision = formData.get('decision');
            if (!pluginFile || !decision) { showNotice(config.error, true); return; }
            setBusy(form, true);
            showNotice(config.saving, false);
            window.fetch(config.restUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce},
                body: JSON.stringify({plugin_file: pluginFile, decision: decision})
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.ok) { throw new Error(payload.message || config.error); }
                    return payload;
                });
            }).then(function (payload) {
                refreshFragments(payload.fragments || {});
                showNotice(payload.message || 'Plugin discovery decision saved.', false);
            }).catch(function (error) {
                setBusy(form, false);
                showNotice(error.message || config.error, true);
            });
        });
    }

    bindInventoryFilters();
}());
