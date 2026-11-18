/**
 * PressHub → Settings, one section per page load (#246):
 *
 *  - asks before leaving a section with unsaved changes (section links, the
 *    narrow-screen section <select>, other admin links; closing the tab is
 *    covered by the beforeunload guard in admin.js),
 *  - says how many changes are unsaved, in a role="status" line,
 *  - shows validation and save errors as a summary that links to each field,
 *    with the same message next to the field, and prefixes the title "Error:",
 *  - keeps the focused field clear of the sticky save bar (WCAG 2.2 SC 2.4.11),
 *  - disables, with a reason, fields whose switch is off,
 *  - remembers a dismissed "What's new in settings" panel.
 *
 * Needs admin.js (window.PressHubSettingsState, the save handler and its
 * presshub:settings-* events) and window.presshubSettingsIndex.
 */
(function ($) {
    'use strict';

    var page = document.getElementById('presshub-settings-page');
    if (!page) {
        return;
    }
    var data = window.presshubSettingsIndex || {};
    var t = data.i18n || {};
    var section = page.getAttribute('data-section') || '';
    var sectionLabel = (data.section_labels && data.section_labels[section]) || $.trim($('#presshub-settings-section-title').text());
    var baseTitle = document.title;

    function state() {
        return window.PressHubSettingsState || null;
    }

    function changedCount() {
        var s = state();
        return s ? s.changedCount(section) : 0;
    }

    function isDirty() {
        var s = state();
        return !!(s && s.isDirty());
    }

    // ------------------------------------------------------------------
    // Unsaved-changes guard on links and the section <select>.
    // ------------------------------------------------------------------
    function confirmLeave() {
        if (!isDirty()) {
            return true;
        }
        var n = changedCount();
        var msg = n === 1
            ? (t.leave_confirm_one || "Leave this page? Your change in %s isn't saved.").replace('%s', sectionLabel)
            : (t.leave_confirm || "Leave this page? Your %1$d changes in %2$s aren't saved.").replace('%1$d', String(n)).replace('%2$s', sectionLabel);
        if (window.confirm(msg)) {
            markLeaving();
            return true;
        }
        return false;
    }

    /**
     * Skips the beforeunload prompt for the navigation the user just
     * confirmed. Reset shortly after, in case the link did not leave the
     * page (a download), so closing the tab later still asks.
     */
    function markLeaving() {
        var s = state();
        if (!s) {
            return;
        }
        s.leaving = true;
        setTimeout(function () {
            s.leaving = false;
        }, 2000);
    }

    // settings-search.js asks the same question before opening another section.
    window.PressHubSettingsConfirmLeave = confirmLeave;

    function leavesPage(a) {
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
            return false;
        }
        if (a.target && a.target !== '_self') {
            return false;
        }
        if (a.hasAttribute('download')) {
            return false;
        }
        // Same page, other anchor.
        try {
            var url = new URL(a.href, window.location.href);
            if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
                return false;
            }
        } catch (e) {}
        return true;
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || !leavesPage(a)) {
            return;
        }
        if (a.classList.contains('presshub-confirm-link')) {
            return; // Handled below; it asks its own question.
        }
        if (!confirmLeave()) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    $(document).on('change', '#presshub-settings-section-select', function () {
        var $sel = $(this);
        var url = $sel.val();
        if (!url) {
            return;
        }
        if (confirmLeave()) {
            window.location.href = url;
        } else {
            $sel.find('option').prop('selected', false).filter(function () {
                return this.defaultSelected;
            }).prop('selected', true);
        }
    });

    // Links that act at once (restore all prompts) ask first.
    $(document).on('click', '.presshub-confirm-link', function (e) {
        var question = $(this).attr('data-confirm') || '';
        if (question && !window.confirm(question)) {
            e.preventDefault();
            return;
        }
        markLeaving();
    });

    // ------------------------------------------------------------------
    // "N unsaved changes".
    // ------------------------------------------------------------------
    var $badge = $('#presshub-sticky-unsaved-badge');
    var lastCount = -1;
    function updateUnsaved() {
        var n = isDirty() ? changedCount() : 0;
        if (n === lastCount) {
            return;
        }
        lastCount = n;
        if (n > 0) {
            $badge.text(n === 1 ? (t.unsaved_one || '1 unsaved change') : (t.unsaved_many || '%d unsaved changes').replace('%d', String(n))).prop('hidden', false);
        } else {
            $badge.text('').prop('hidden', true);
        }
    }
    $(document).on('presshub:settings-dirty', updateUnsaved);

    // ------------------------------------------------------------------
    // Error summary + inline messages.
    // ------------------------------------------------------------------
    var $summary = $('#presshub-settings-error-summary');
    var $summaryList = $summary.find('.presshub-error-summary-list');

    function fieldLabel(el) {
        var $el = $(el);
        var label = '';
        if (el.id) {
            label = $.trim($('label[for="' + el.id + '"]').first().text());
        }
        if (!label) {
            var $wrap = $el.closest('label');
            if ($wrap.length) {
                label = $.trim($wrap.clone().children().remove().end().text());
            }
        }
        if (!label) {
            label = $.trim($el.closest('tr').find('th').first().text()) || el.name;
        }
        return label;
    }

    function clearErrors() {
        $summary.prop('hidden', true);
        $summaryList.empty();
        $('.presshub-field-error').remove();
        $('[aria-invalid="true"]').each(function () {
            var $el = $(this);
            // A stored wrong model keeps its error until it is changed (#236).
            if ($el.attr('data-stored-invalid') === '1') {
                return;
            }
            $el.removeAttr('aria-invalid');
            var describedBy = ($el.attr('aria-describedby') || '').split(/\s+/).filter(function (id) {
                return id && id.indexOf('-presshub-error') === -1;
            }).join(' ');
            if (describedBy) {
                $el.attr('aria-describedby', describedBy);
            } else {
                $el.removeAttr('aria-describedby');
            }
        });
        document.title = baseTitle;
    }

    function showSummary(items) {
        $summaryList.empty();
        $.each(items, function (_, item) {
            var $li = $('<li></li>');
            if (item.id) {
                $('<a></a>').attr('href', '#' + item.id).text(item.text).on('click', function (e) {
                    e.preventDefault();
                    var el = document.getElementById(item.id);
                    if (el) {
                        $(el).parents('details').attr('open', 'open');
                        el.focus();
                    }
                }).appendTo($li);
            } else {
                $li.text(item.text);
            }
            $summaryList.append($li);
        });
        $summary.prop('hidden', false);
        document.title = (t.error_prefix || 'Error:') + ' ' + baseTitle;
        try {
            $summary[0].scrollIntoView({ block: 'start' });
        } catch (e) {}
        $summary[0].focus();
    }

    /** Marks each { el, msg } as invalid, next to the field and in the summary. */
    function markFields(pairs) {
        clearErrors();
        var items = [];
        $.each(pairs, function (i, pair) {
            var el = pair.el;
            if (!el.id) {
                el.id = 'presshub-field-' + (el.name || 'field').replace(/[^a-z0-9_-]/gi, '_') + '-' + i;
            }
            var msg = pair.msg;
            var errId = el.id + '-presshub-error';
            var $err = $('<p class="presshub-field-error"></p>').attr('id', errId).text(msg);
            var $anchor = $(el).closest('label').length ? $(el).closest('label') : $(el);
            $anchor.after($err);
            $(el).attr('aria-invalid', 'true').attr('aria-describedby', $.trim(($(el).attr('aria-describedby') || '') + ' ' + errId));
            $(el).parents('details').attr('open', 'open');
            items.push({ id: el.id, text: fieldLabel(el) + ': ' + msg });
        });
        showSummary(items);
        $('#presshub-sticky-status-msg').removeClass('is-success').addClass('is-error').text(t.not_saved || 'Not saved. Fix the fields listed above.');
    }

    $(document).on('presshub:settings-invalid', function (_, invalid) {
        markFields($.map(invalid || [], function (el) {
            return { el: el, msg: el.validationMessage || t.invalid_value || 'Enter a valid value.' };
        }));
    });

    // #236: errors the server found (a model of the wrong type), keyed by option.
    $(document).on('presshub:settings-field-errors', function (_, errors, message) {
        var pairs = [];
        $.each(errors || {}, function (key, msg) {
            var el = modelControl(key) || document.getElementById(key);
            if (el) {
                pairs.push({ el: el, msg: String(msg) });
            }
        });
        if (pairs.length) {
            markFields(pairs);
        } else {
            clearErrors();
            showSummary([{ id: '', text: String(message || '') }]);
        }
    });

    $(document).on('presshub:settings-save-failed', function (_, tabKey, message) {
        clearErrors();
        showSummary([{ id: '', text: String(message || '') }]);
    });

    $(document).on('presshub:settings-saved', function (_, tabKey, res) {
        clearErrors();
        lastCount = -1;
        updateUnsaved();
        // #236: an unknown model ID saved; say so on its row (admin.js says so in the status line).
        var warnings = res && res.data && res.data.model_warning_fields;
        if (warnings && typeof warnings === 'object' && !Array.isArray(warnings)) {
            $.each(warnings, function (key, msg) {
                var input = document.getElementById(key);
                if (!input) {
                    return;
                }
                var noteId = key + '-model-error';
                $('#' + noteId).remove();
                var $note = $('<p class="presshub-model-note is-warning"></p>').attr('id', noteId).text((t.warning_prefix || 'Warning:') + ' ' + String(msg));
                $(input).closest('.presshub-model-other').after($note);
                var ids = ($(input).attr('aria-describedby') || '').split(/\s+/).filter(function (id) { return id && id !== noteId; });
                ids.push(noteId);
                $(input).attr('aria-describedby', ids.join(' '));
            });
        }
    });
    // A changed model loses the error or warning shown for its stored value.
    $(document).on('input change', '.presshub-model-other input', function () {
        if (this.getAttribute('data-stored-invalid') === '1') {
            this.removeAttribute('data-stored-invalid');
            this.removeAttribute('aria-invalid');
        }
        $('#' + this.id + '-model-error').remove();
    });

    // An invalid field that becomes valid loses its message.
    $(document).on('input change', '#presshub-ai-settings-form [aria-invalid="true"]', function () {
        if (typeof this.checkValidity === 'function' && this.checkValidity()) {
            $('#' + this.id + '-presshub-error').remove();
            $(this).removeAttr('aria-invalid');
        }
    });

    // ------------------------------------------------------------------
    // Keep the focused field clear of the sticky save bar.
    // ------------------------------------------------------------------
    var bar = document.getElementById('presshub-sticky-save-bar');
    document.addEventListener('focusin', function (e) {
        if (!bar || !e.target || bar.contains(e.target) || !document.getElementById('presshub-ai-settings-form').contains(e.target)) {
            return;
        }
        var barTop = bar.getBoundingClientRect().top;
        var rect = e.target.getBoundingClientRect();
        var gap = 16;
        if (rect.bottom > barTop - gap && rect.top < window.innerHeight) {
            window.scrollBy(0, rect.bottom - barTop + gap);
        }
    });

    // ------------------------------------------------------------------
    // Fields that do not apply: disabled, with a reason (registry depends_on).
    // ------------------------------------------------------------------
    function controlsFor(key) {
        var form = document.getElementById('presshub-ai-settings-form');
        if (!form) {
            return [];
        }
        var list = [];
        var byId = document.getElementById(key);
        if (byId && form.contains(byId) && /^(INPUT|SELECT|TEXTAREA)$/.test(byId.tagName) && byId.type !== 'hidden') {
            list.push(byId);
        }
        $(form).find('[name="' + key + '"], [name^="' + key + '["]').each(function () {
            if (this.type !== 'hidden' && list.indexOf(this) === -1) {
                list.push(this);
            }
        });
        return list;
    }

    function switchIsOn(el) {
        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked;
        }
        return el.value !== '' && el.value !== '0';
    }

    $.each(data.entries || [], function (_, e) {
        if (e.section !== section || !e.depends_on) {
            return;
        }
        // depends_on is one switch or a list of switches (any one on turns it on).
        var parents = [];
        $.each([].concat(e.depends_on), function (_, key) {
            var found = controlsFor(key);
            if (found.length) {
                parents.push(found[0]);
            }
        });
        var mine = controlsFor(e.key);
        if (!parents.length || !mine.length) {
            return;
        }
        var reasonId = 'presshub-applies-' + e.key;
        var $reason = $('<p class="description presshub-applies-reason"></p>').attr('id', reasonId)
            .text((t.depends_reason || 'Used only when "%s" is on.').replace('%s', e.depends_label || e.depends_on));
        var last = mine[mine.length - 1];
        var $anchor = $(last).closest('label').length ? $(last).closest('label') : $(last);
        $anchor.after($reason);

        function apply() {
            var on = parents.some(switchIsOn);
            $.each(mine, function (_, el) {
                // Disabled fields are still sent on Save (admin.js reads every field).
                el.disabled = !on;
                var ids = ($(el).attr('aria-describedby') || '').split(/\s+/).filter(function (id) { return id && id !== reasonId; });
                if (!on) {
                    ids.push(reasonId);
                }
                if (ids.length) {
                    $(el).attr('aria-describedby', ids.join(' '));
                } else {
                    $(el).removeAttr('aria-describedby');
                }
                $(el).trigger('presshub:applies');
            });
            $reason.prop('hidden', on);
        }
        $(parents).on('change input', apply);
        apply();
    });

    // ------------------------------------------------------------------
    // AI models (#236): a list of known models plus "Other model ID…",
    // kept in step with the text field that is saved. Without JavaScript
    // only the text field shows.
    // ------------------------------------------------------------------
    /** The visible control for a model option: its list, or its text field. */
    function modelControl(key) {
        var choice = document.querySelector('.presshub-model-choice[data-model-input="' + key + '"]');
        if (!choice || choice.hidden) {
            return null;
        }
        return choice.value === '__other' ? document.getElementById(key) : choice;
    }
    window.PressHubModelControl = modelControl;

    function modelPicker(choice) {
        var input = document.getElementById(choice.getAttribute('data-model-input'));
        var wrap = input ? $(input).closest('.presshub-model-other') : $();
        var providerSel = document.getElementById(choice.getAttribute('data-provider-select') || '');
        if (!input) {
            return;
        }
        function showOther(on) {
            wrap.prop('hidden', !on);
        }
        // Shows the chosen provider's models and points the list at the stored
        // value. It never changes the value: one the provider doesn't list
        // shows as "Other model ID…" (#236 review).
        function filterGroups() {
            if (providerSel) {
                var pid = providerSel.value || choice.getAttribute('data-default-provider') || '';
                $(choice).find('optgroup').each(function () {
                    var mine = this.getAttribute('data-provider') === pid;
                    this.hidden = !mine;
                    $(this).find('option').prop('disabled', !mine);
                });
            }
            var match = null;
            $(choice).find('option').each(function () {
                if (!match && !this.disabled && this.value !== '__other' && this.value === input.value) {
                    match = this;
                }
            });
            if (match) {
                match.selected = true; // this option, not a same-named one in a hidden group
            } else {
                choice.value = '__other';
            }
            showOther(choice.value === '__other');
        }
        choice.hidden = false;
        filterGroups();
        $(choice).on('change', function () {
            if (choice.value === '__other') {
                showOther(true);
                input.focus();
                return;
            }
            showOther(false);
            input.value = choice.value;
            $(input).trigger('input').trigger('change');
        });
        if (providerSel) {
            $(providerSel).on('change', filterGroups);
        }
    }
    $('.presshub-model-choice').each(function () {
        modelPicker(this);
    });

    // ------------------------------------------------------------------
    // "What's new in settings": dismissed once per user.
    // ------------------------------------------------------------------
    $(document).on('click', '#presshub-whats-new .notice-dismiss', function () {
        if (!window.presshubAI || !window.presshubAI.ajax_url) {
            return;
        }
        $.post(window.presshubAI.ajax_url, {
            action: 'presshub_dismiss_settings_whats_new',
            nonce: window.presshubAI.nonce
        });
    });
    $(document).on('click', '.presshub-whats-new-search', function (e) {
        e.preventDefault();
        $('#presshub-settings-search-input').trigger('focus');
    });
})(jQuery);
