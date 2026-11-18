/**
 * PressHub Settings: search in the section list and per-field defaults with
 * Reset (#234, moved into the section list by #246).
 *
 * Loaded on PressHub → Settings with window.presshubSettingsIndex =
 * { section, section_urls, section_labels, entries: [...], legacy_hashes, i18n }.
 * Each entry: { key, page, tab, section, label, help, aliases[], default?, checkbox,
 * depends_on?, depends_label? }.
 */
(function ($) {
    'use strict';

    var data = window.presshubSettingsIndex;
    if (!data || !Array.isArray(data.entries) || !document.getElementById('presshub-settings-page')) {
        return;
    }
    var t = data.i18n || {};
    var currentSection = data.section || '';
    var urls = data.section_urls || {};
    var labels = data.section_labels || {};

    function text(s) {
        return document.createTextNode(s == null ? '' : String(s));
    }

    /** The visible control for an option in this section, or null. */
    function fieldFor(key) {
        var form = document.getElementById('presshub-ai-settings-form');
        if (!form) {
            return null;
        }
        // AI models: the list shown in place of the model ID field (#236).
        if (typeof window.PressHubModelControl === 'function') {
            var model = window.PressHubModelControl(key);
            if (model) {
                return model;
            }
        }
        var el = document.getElementById(key);
        if (el && form.contains(el) && /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && el.type !== 'hidden') {
            return el;
        }
        var els = form.querySelectorAll('[name="' + key + '"]');
        for (var i = 0; i < els.length; i++) {
            if (els[i].type !== 'hidden') {
                return els[i];
            }
        }
        // Array options: name="key[]" or name="key[task]".
        var arr = form.querySelectorAll('[name^="' + key + '["]');
        for (var j = 0; j < arr.length; j++) {
            if (arr[j].type !== 'hidden') {
                return arr[j];
            }
        }
        // Widgets that keep the value in a hidden input (sources, image
        // pools): point at the widget instead.
        var hidden = els.length ? els[0] : (el && form.contains(el) ? el : null);
        if (hidden && hidden.type === 'hidden' && hidden.parentElement) {
            return hidden.parentElement;
        }
        return null;
    }

    /** Opens any collapsed "Advanced" block around el, scrolls to it and focuses it. */
    function reveal(el) {
        if (!el) {
            return false;
        }
        $(el).parents('details').attr('open', 'open');
        // Podcast prompts sit in style and presenter-count panes: open the pane's tab.
        var stylePane = $(el).closest('.presshub-style-pane')[0];
        if (stylePane && stylePane.id) {
            $('.presshub-style-tab[data-style="' + stylePane.id.replace(/^presshub-style-/, '') + '"]').trigger('click');
        }
        var hostPane = $(el).closest('.presshub-host-pane')[0];
        if (hostPane && hostPane.id) {
            $('.presshub-host-tab[data-target="' + hostPane.id + '"]').trigger('click');
        }
        if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'center' });
        }
        $(el).addClass('presshub-search-hit');
        setTimeout(function () { $(el).removeClass('presshub-search-hit'); }, 2500);
        try {
            if (!/^(INPUT|SELECT|TEXTAREA|BUTTON|A)$/.test(el.tagName) && !el.hasAttribute('tabindex')) {
                el.setAttribute('tabindex', '-1');
            }
            el.focus({ preventScroll: true });
        } catch (e) {}
        return true;
    }

    function entryFor(key) {
        for (var i = 0; i < data.entries.length; i++) {
            if (data.entries[i].key === key) {
                return data.entries[i];
            }
        }
        return null;
    }

    function goTo(entry) {
        if (entry.section !== currentSection) {
            if (urls[entry.section]) {
                // Unsaved changes: the same "Leave this page?" question as the section links.
                if (typeof window.PressHubSettingsConfirmLeave === 'function' && !window.PressHubSettingsConfirmLeave()) {
                    return;
                }
                window.location.href = urls[entry.section] + '#' + encodeURIComponent(entry.key);
            }
            return;
        }
        reveal(fieldFor(entry.key));
    }

    // ------------------------------------------------------------------
    // Old links: #tab hashes of the former AI page, and #field anchors of a
    // field that now lives in another section.
    // ------------------------------------------------------------------
    function followHash() {
        var hash = '';
        try {
            hash = decodeURIComponent((window.location.hash || '').replace(/^#/, ''));
        } catch (e) {
            return; // A malformed %-escape: nothing to follow.
        }
        if (!hash) {
            return;
        }
        var hasSection = /[?&]section=/.test(window.location.search);
        if (!hasSection && data.legacy_hashes && data.legacy_hashes[hash] && !document.getElementById(hash)) {
            window.location.replace(data.legacy_hashes[hash]);
            return;
        }
        var target = document.getElementById(hash);
        // A hidden input (sources, image pools) is found through fieldFor() below.
        if (target && target.type !== 'hidden' && document.getElementById('presshub-settings-main').contains(target)) {
            $(target).parents('details').attr('open', 'open');
            if (/^(INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) {
                reveal(target);
            }
            return;
        }
        if (/^[a-z0-9_]+$/i.test(hash)) {
            var el = fieldFor(hash);
            if (el) {
                reveal(el);
                return;
            }
            var entry = entryFor(hash);
            if (entry && entry.section && entry.section !== currentSection && urls[entry.section]) {
                window.location.replace(urls[entry.section] + '#' + encodeURIComponent(hash));
            }
        }
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------
    function score(entry, q) {
        var label = (entry.label || '').toLowerCase();
        if (label.indexOf(q) === 0) { return 0; }
        if (label.indexOf(q) !== -1) { return 1; }
        var aliases = (entry.aliases || []).join(' ').toLowerCase();
        if (aliases.indexOf(q) !== -1) { return 2; }
        if (entry.key.toLowerCase().indexOf(q.replace(/\s+/g, '_')) !== -1) { return 3; }
        if ((entry.help || '').toLowerCase().indexOf(q) !== -1) { return 4; }
        return -1;
    }

    function buildSearch() {
        var $input = $('#presshub-settings-search-input');
        var $list = $('#presshub-settings-search-results');
        var $status = $('#presshub-settings-search-status');
        if (!$input.length || !$list.length) {
            return;
        }
        var results = [];
        var active = -1;

        function close() {
            $list.prop('hidden', true);
            $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        }

        function render() {
            $list.empty();
            if (!results.length) {
                if ($.trim($input.val()).length >= 2) {
                    $list.append($('<li class="presshub-search-empty" role="option" aria-disabled="true"></li>').text(t.no_results || 'No matching settings'));
                    $list.prop('hidden', false);
                    $input.attr('aria-expanded', 'true');
                    $status.text(t.no_results || 'No matching settings');
                } else {
                    close();
                    $status.text('');
                }
                return;
            }
            $.each(results, function (i, e) {
                var where = labels[e.section] || e.section;
                var $li = $('<li role="option"></li>').attr('id', 'presshub-search-opt-' + i).attr('aria-selected', i === active ? 'true' : 'false');
                $li.append($('<strong></strong>').text(e.label));
                $li.append(' ', $('<span class="presshub-search-meta"></span>').text(where + (e.section !== currentSection ? ' ↗' : '')));
                if (i === active) {
                    $li.addClass('is-active');
                }
                $li.on('mousedown', function (ev) {
                    ev.preventDefault();
                    choose(i);
                });
                $list.append($li);
            });
            $list.prop('hidden', false);
            $input.attr('aria-expanded', 'true');
            if (active >= 0) {
                $input.attr('aria-activedescendant', 'presshub-search-opt-' + active);
            } else {
                $input.removeAttr('aria-activedescendant');
            }
            $status.text((t.results_count || '%d settings found').replace('%d', String(results.length)));
        }

        function search() {
            var q = $.trim($input.val()).toLowerCase();
            active = -1;
            if (q.length < 2) {
                results = [];
                render();
                return;
            }
            var scored = [];
            $.each(data.entries, function (_, e) {
                var s = score(e, q);
                if (s >= 0) {
                    // Fields in the section on screen come first.
                    scored.push({ s: s + (e.section === currentSection ? 0 : 0.5), e: e });
                }
            });
            scored.sort(function (a, b) { return a.s - b.s || a.e.label.localeCompare(b.e.label); });
            results = scored.slice(0, 12).map(function (x) { return x.e; });
            render();
        }

        function choose(i) {
            var e = results[i];
            if (!e) {
                return;
            }
            close();
            goTo(e);
        }

        $input.on('input', search);
        $input.on('keydown', function (ev) {
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                active = Math.min(results.length - 1, active + 1);
                render();
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                active = Math.max(0, active - 1);
                render();
            } else if (ev.key === 'Enter') {
                ev.preventDefault();
                choose(active >= 0 ? active : 0);
            } else if (ev.key === 'Escape') {
                close();
            }
        });
        $input.on('blur', function () {
            setTimeout(close, 150);
        });
    }

    // ------------------------------------------------------------------
    // Defaults with Reset (scalar defaults only; prompts keep their own
    // "Load default" buttons).
    // ------------------------------------------------------------------
    function displayDefault(e, el) {
        if (e.checkbox) {
            return e.default ? (t.on || 'On') : (t.off || 'Off');
        }
        if (el && el.tagName === 'SELECT') {
            for (var i = 0; i < el.options.length; i++) {
                if (String(el.options[i].value) === String(e.default)) {
                    return el.options[i].text;
                }
            }
        }
        if (e.default === '') {
            return t.empty_default || '(empty: uses the provider or built-in default)';
        }
        return String(e.default);
    }

    function currentValue(e, el) {
        if (e.checkbox) {
            return el.checked ? 1 : 0;
        }
        return el.value;
    }

    function isDefault(e, el) {
        var cur = currentValue(e, el);
        if (e.checkbox) {
            return cur === (e.default ? 1 : 0);
        }
        // A blank numeric field shows a stored 0 ("use the provider default").
        if (typeof e.default === 'number' && cur === '' && e.default === 0) {
            return true;
        }
        if (typeof e.default === 'number' && cur !== '' && !isNaN(Number(cur))) {
            return Number(cur) === e.default;
        }
        return String(cur) === String(e.default);
    }

    function buildDefaults() {
        $.each(data.entries, function (_, e) {
            if (e.section !== currentSection || !('default' in e) || e.default === null || typeof e.default === 'object') {
                return;
            }
            var el = fieldFor(e.key);
            if (!el || !/^(INPUT|SELECT)$/.test(el.tagName) || el.type === 'password' || el.type === 'radio' || el.type === 'hidden') {
                return;
            }
            if (e.checkbox && el.type !== 'checkbox') {
                return;
            }
            var $el = $(el);
            var $span = $('<span class="presshub-field-default"></span>');
            $span.append(text((t.default_label || 'Default:') + ' ' + displayDefault(e, el) + ' '));
            var $btn = $('<button type="button" class="button-link presshub-field-reset"></button>').text(t.reset || 'Reset to default')
                .attr('aria-label', (t.reset_aria || 'Reset %s to its default').replace('%s', e.label));
            $span.append($btn);
            var $anchor = e.checkbox && $el.closest('label').length ? $el.closest('label') : $el;
            var $unit = $anchor.next('span:not(.presshub-field-default)');
            ($unit.length ? $unit : $anchor).after($span);

            function sync() {
                // Read-only and disabled fields (they do not apply now) get no Reset.
                $btn.prop('hidden', el.readOnly || el.disabled || isDefault(e, el));
            }
            $btn.on('click', function () {
                if (el.readOnly || el.disabled) {
                    return;
                }
                // Changes this field only; Save writes it with the rest of the section.
                if (e.checkbox) {
                    $el.prop('checked', !!e.default);
                } else {
                    $el.val(String(e.default));
                }
                $el.trigger('input').trigger('change');
                sync();
                try { el.focus(); } catch (err) {}
            });
            $el.on('input change presshub:applies', sync);
            $(document).on('change', '#presshub_social_worker_mode', function () { setTimeout(sync, 0); });
            sync();
        });
    }

    $(function () {
        buildSearch();
        buildDefaults();
        followHash();
        $(window).on('hashchange', followHash);
    });
})(jQuery);
