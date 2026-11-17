/**
 * PressHub settings search, per-field defaults with Reset, and the
 * "What's new in settings" panel (#234, settings overhaul phase 2).
 *
 * Loaded on the PressHub AI and PressHub Social settings pages with
 * window.presshubSettingsIndex = { page, pages: {ai, social}, entries: [...], i18n }.
 * Each entry: { key, page, tab, group, label, help, aliases[], default?, checkbox, type }.
 */
(function ($) {
    'use strict';

    var data = window.presshubSettingsIndex;
    if (!data || !Array.isArray(data.entries) || !document.querySelector('#presshub-ai-settings-form, #presshub-social-settings-form')) {
        return;
    }
    var t = data.i18n || {};
    var currentPage = data.page;

    function text(s) {
        return document.createTextNode(s == null ? '' : String(s));
    }

    /** The visible control for an option on this page, or null. */
    function fieldFor(key) {
        var el = document.getElementById(key);
        if (el && /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && el.type !== 'hidden') {
            return el;
        }
        var els = document.getElementsByName(key);
        for (var i = 0; i < els.length; i++) {
            if (els[i].type !== 'hidden') {
                return els[i];
            }
        }
        return el || null;
    }

    function anchorIdFor(key) {
        var el = fieldFor(key);
        if (!el) {
            return '';
        }
        if (!el.id) {
            el.id = 'presshub-field-' + key;
        }
        return el.id;
    }

    function goTo(entry) {
        if (entry.page !== currentPage) {
            var url = (data.pages && data.pages[entry.page]) || '';
            if (url) {
                window.location.href = url + (entry.page === 'social' && entry.tab ? '&tab=' + encodeURIComponent(entry.tab) : '') + '#' + encodeURIComponent(entry.key);
            }
            return;
        }
        var id = anchorIdFor(entry.key);
        if (!id) {
            return;
        }
        if (window.location.hash === '#' + id) {
            $(window).trigger('hashchange');
        } else {
            window.location.hash = id;
        }
        var el = document.getElementById(id);
        if (el) {
            $(el).addClass('presshub-search-hit');
            setTimeout(function () { $(el).removeClass('presshub-search-hit'); }, 2500);
            setTimeout(function () { try { el.focus({ preventScroll: true }); } catch (e) {} }, 400);
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
        var $h1 = $('.wrap h1').first();
        if (!$h1.length) {
            return;
        }
        var $box = $('<div class="presshub-settings-search" role="search"></div>');
        var $label = $('<label class="screen-reader-text" for="presshub-settings-search-input"></label>').text(t.search_label || 'Search settings');
        var $input = $('<input type="search" id="presshub-settings-search-input" class="regular-text presshub-no-dirty" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="presshub-settings-search-results" aria-autocomplete="list">')
            .attr('placeholder', t.search_placeholder || 'Search settings (name, help or old name)');
        var $list = $('<ul id="presshub-settings-search-results" class="presshub-settings-search-results" role="listbox" hidden></ul>');
        $box.append($label, $input, $list);
        $h1.after($box);

        var results = [];
        var active = -1;

        function render() {
            $list.empty();
            if (!results.length) {
                if ($.trim($input.val()).length >= 2) {
                    $list.append($('<li class="presshub-search-empty" role="option" aria-disabled="true"></li>').text(t.no_results || 'No matching settings'));
                    $list.prop('hidden', false);
                    $input.attr('aria-expanded', 'true');
                } else {
                    $list.prop('hidden', true);
                    $input.attr('aria-expanded', 'false');
                }
                return;
            }
            $.each(results, function (i, e) {
                var where = (data.tab_labels && data.tab_labels[e.page] && data.tab_labels[e.page][e.tab]) || e.tab;
                var $li = $('<li role="option"></li>').attr('id', 'presshub-search-opt-' + i).attr('aria-selected', i === active ? 'true' : 'false');
                $li.append($('<strong></strong>').text(e.label));
                var meta = where + (e.page !== currentPage ? ' ↗ ' + (e.page === 'social' ? 'PressHub Social' : 'PressHub AI') : '');
                $li.append(' ', $('<span class="presshub-search-meta"></span>').text(meta));
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
                    scored.push({ s: s + (e.page === currentPage ? 0 : 0.5), e: e });
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
            $list.prop('hidden', true);
            $input.attr('aria-expanded', 'false');
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
                $list.prop('hidden', true);
                $input.attr('aria-expanded', 'false');
            }
        });
        $input.on('blur', function () {
            setTimeout(function () { $list.prop('hidden', true); $input.attr('aria-expanded', 'false'); }, 150);
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
        if (typeof e.default === 'number' && cur !== '' && !isNaN(Number(cur))) {
            return Number(cur) === e.default;
        }
        return String(cur) === String(e.default);
    }

    function buildDefaults() {
        $.each(data.entries, function (_, e) {
            if (e.page !== currentPage || !('default' in e) || e.default === null || typeof e.default === 'object') {
                return;
            }
            var el = fieldFor(e.key);
            if (!el || el.tagName === 'TEXTAREA' || el.type === 'password' || el.type === 'radio' || el.readOnly) {
                return;
            }
            if (e.checkbox && el.type !== 'checkbox') {
                return;
            }
            var $el = $(el);
            var $span = $('<span class="presshub-field-default"></span>');
            $span.append(text((t.default_label || 'Default:') + ' ' + displayDefault(e, el) + ' '));
            var $btn = $('<button type="button" class="button-link presshub-field-reset"></button>').text(t.reset || 'Reset')
                .attr('aria-label', (t.reset_aria || 'Reset %s to its default').replace('%s', e.label));
            $span.append($btn);
            var $anchor = e.checkbox && $el.closest('label').length ? $el.closest('label') : $el;
            var $unit = $anchor.next('span:not(.presshub-field-default)');
            ($unit.length ? $unit : $anchor).after($span);

            function sync() {
                $btn.prop('hidden', isDefault(e, el));
            }
            $btn.on('click', function () {
                if (e.checkbox) {
                    $el.prop('checked', !!e.default);
                } else {
                    $el.val(String(e.default));
                }
                $el.trigger('input').trigger('change');
                sync();
            });
            $el.on('input change', sync);
            sync();
        });
    }

    // ------------------------------------------------------------------
    // What's new (per viewer; remembered in this browser only).
    // ------------------------------------------------------------------
    function buildWhatsNew() {
        var key = 'presshub_settings_whats_new_234_' + currentPage;
        try {
            if (window.localStorage && window.localStorage.getItem(key) === '1') {
                return;
            }
        } catch (err) {}
        var items = (data.whats_new && data.whats_new[currentPage]) || [];
        if (!items.length) {
            return;
        }
        var $n = $('<div class="notice notice-info is-dismissible presshub-whats-new"></div>');
        $n.append($('<p></p>').append($('<strong></strong>').text(t.whats_new_title || "What's new in settings")));
        var $ul = $('<ul style="list-style: disc; margin-left: 20px;"></ul>');
        $.each(items, function (_, item) { $ul.append($('<li></li>').text(item)); });
        $n.append($ul);
        var $close = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>');
        $close.find('span').text(t.dismiss || 'Dismiss this notice.');
        $close.on('click', function () {
            try { window.localStorage.setItem(key, '1'); } catch (err) {}
            $n.remove();
        });
        $n.append($close);
        $('.presshub-settings-search').after($n);
    }

    $(function () {
        buildSearch();
        buildDefaults();
        buildWhatsNew();
    });
})(jQuery);
