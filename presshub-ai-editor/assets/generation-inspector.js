/**
 * PressHub AI - Generation Inspector (Issue #133).
 *
 * Read-only viewer for generation records. Tabs load on demand through
 * admin-ajax: structured tabs return server-escaped HTML; the large text fields
 * (prompts, response) return JSON data and are inserted with textContent only,
 * never as HTML, because they contain scraped third-party text.
 */
(function ($) {
    'use strict';

    var cfg = window.presshubInspector || {};
    var i18n = cfg.i18n || {};
    var TEXT_TABS = { system_prompt: true, user_prompt: true, response_text: true };
    // Record kinds that have a TTS tab (keep in sync with PressHub_AI_Generation_Inspector::TTS_KINDS).
    var TTS_KINDS = ['podcast_script', 'tts_test'];

    function format(template, value) {
        return String(template || '%s').replace('%s', value);
    }

    function copyText(text, $btn) {
        var done = function (label) {
            var original = $btn.data('label');
            $btn.text(label);
            window.setTimeout(function () { $btn.text(original); }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(
                function () { done(i18n.copied); },
                function () { done(i18n.copy_failed); }
            );
            return;
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            done(document.execCommand('copy') ? i18n.copied : i18n.copy_failed);
        } catch (e) {
            done(i18n.copy_failed);
        }
        document.body.removeChild(ta);
    }

    function Inspector($root) {
        this.$root = $root;
        this.$select = $root.find('.presshub-inspector-select');
        this.$tabs = $root.find('.presshub-inspector-tab');
        this.$panel = $root.find('.presshub-inspector-panel');
        this.cache = {};
        this.tab = 'overview';
        this.token = 0;
        this.bind();
        this.open('overview');
    }

    Inspector.prototype.recordId = function () {
        return parseInt(this.$select.val() || this.$root.data('record-id'), 10) || 0;
    };

    Inspector.prototype.bind = function () {
        var self = this;
        this.$tabs.on('click', function () {
            self.open($(this).data('tab'));
        });
        this.$select.on('change', function () {
            var kind = self.$select.find('option:selected').data('kind');
            var $tts = self.$tabs.filter('[data-tab="tts"]');
            var hasTts = TTS_KINDS.indexOf(kind) !== -1;
            $tts.prop('hidden', !hasTts);
            if (!hasTts && self.tab === 'tts') {
                self.tab = 'overview';
            }
            self.open(self.tab);
        });
    };

    Inspector.prototype.open = function (tab) {
        this.tab = tab;
        this.$tabs.removeClass('button-primary').attr('aria-selected', 'false');
        this.$tabs.filter('[data-tab="' + tab + '"]').addClass('button-primary').attr('aria-selected', 'true');

        var id = this.recordId();
        var key = id + ':' + tab;
        var token = ++this.token;
        var self = this;

        if (tab === 'raw') {
            return this.renderRaw();
        }
        if (this.cache[key]) {
            return this.render(tab, this.cache[key]);
        }
        this.$panel.empty().append($('<p class="presshub-inspector-loading"/>').text(i18n.loading));

        var isText = !!TEXT_TABS[tab];
        var data = { action: isText ? cfg.actions.field : cfg.actions.tab, nonce: cfg.nonce, record_id: id };
        data[isText ? 'field' : 'tab'] = tab;

        $.post(cfg.ajax_url, data).done(function (resp) {
            if (token !== self.token) { return; }
            if (!resp || !resp.success) {
                return self.fail();
            }
            self.cache[key] = resp.data;
            self.render(tab, resp.data);
        }).fail(function () {
            if (token === self.token) { self.fail(); }
        });
    };

    Inspector.prototype.fail = function () {
        this.$panel.empty().append($('<p class="presshub-inspector-error"/>').text(i18n.error));
    };

    Inspector.prototype.render = function (tab, data) {
        if (TEXT_TABS[tab]) {
            return this.renderText(data);
        }
        // Server-escaped HTML fragment (all record values escaped in PHP).
        this.$panel.html(data.html);
    };

    Inspector.prototype.renderText = function (data) {
        var value = String(data.value || '');
        var $bar = $('<div class="presshub-inspector-textbar"/>');
        var $count = $('<span class="presshub-inspector-count"/>').text(format(i18n.characters, String(data.chars)));
        var $btn = $('<button type="button" class="button"/>').text(i18n.copy).data('label', i18n.copy);
        $btn.on('click', function () { copyText(value, $btn); });
        $bar.append($count, ' ', $btn);
        // textContent only: never interpret record text as HTML.
        var $pre = $('<pre class="presshub-inspector-text" tabindex="0"/>').text(value === '' ? i18n.empty : value);
        this.$panel.empty().append($bar, $pre);
    };

    Inspector.prototype.renderRaw = function () {
        var url = this.$select.find('option:selected').attr('data-raw-url') || '';
        var $link = $('<a class="button button-primary"/>').attr('href', url).text(i18n.raw_download);
        this.$panel.empty().append($('<p/>').text(i18n.raw_intro), $('<p/>').append($link));
    };

    $(function () {
        $('.presshub-inspector').each(function () {
            new Inspector($(this));
        });
    });
}(jQuery));
