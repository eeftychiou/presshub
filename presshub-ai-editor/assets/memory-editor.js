/**
 * PressHub AI - Memory editor (Issue #137).
 *
 * The timeline is rendered server-side (escaped). The entry editor is built here
 * from JSON returned by admin-ajax. Story text is model/editor content, so every
 * value is inserted with .text() / .val() / .attr() - never as HTML.
 */
(function ($) {
    'use strict';

    var cfg = window.presshubMemory || {};
    var i18n = cfg.i18n || {};
    var STATUSES = cfg.story_statuses || ['new', 'developing', 'resolved'];
    var COLLAPSE_AFTER = 3;

    function el(tag, cls, text) {
        var $n = $(document.createElement(tag));
        if (cls) { $n.addClass(cls); }
        if (typeof text !== 'undefined' && text !== null) { $n.text(String(text)); }
        return $n;
    }

    function safeUrl(url) {
        return /^(https?:\/\/|\/)/i.test(String(url || '')) ? url : '';
    }

    function notice($host, type, message) {
        $host.empty().append(el('div', 'notice inline notice-' + type).append(el('p', '', message)));
    }

    function request(action, data) {
        return $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: cfg.nonce }, data)
        });
    }

    function errorMessage(xhr) {
        var r = xhr && xhr.responseJSON;
        if (r && r.data && r.data.message) { return r.data.message; }
        return i18n.request_error;
    }

    function Editor($host, entryId) {
        this.$host = $host;
        this.entryId = entryId;
        this.payload = null;
        this.$notices = null;
    }

    Editor.prototype.load = function () {
        var self = this;
        this.$host.empty().append(el('p', 'presshub-memory-loading', i18n.loading));
        request(cfg.actions.get, { entry_id: this.entryId }).done(function (res) {
            if (res && res.success) {
                self.render(res.data);
            } else {
                notice(self.$host, 'error', i18n.load_error);
            }
        }).fail(function (xhr) {
            notice(self.$host, 'error', errorMessage(xhr) || i18n.load_error);
        });
    };

    Editor.prototype.storyRow = function (story, total) {
        var self = this;
        var $d = el('details', 'presshub-memory-story');
        $d.data('key', story.key || '');
        if (total <= COLLAPSE_AFTER) { $d.attr('open', 'open'); }

        var $sum = el('summary');
        var $sumTitle = el('span', 'presshub-memory-story-title', story.title || i18n.new_story);
        var $sumStatus = el('span', 'presshub-memory-story-status', story.status || '');
        $sum.append($sumTitle).append(' ').append($sumStatus);
        $d.append($sum);

        var $body = el('div', 'presshub-memory-story-body');
        var $title = $('<input type="text" class="large-text presshub-memory-f-title" />').val(story.title || '');
        var $dev = $('<textarea rows="3" class="large-text presshub-memory-f-development"></textarea>').val(story.development || '');
        var $status = $('<select class="presshub-memory-f-status"></select>');
        var known = false;
        $.each(STATUSES, function (_, s) {
            if (s === story.status) { known = true; }
            $status.append($('<option></option>').val(s).text(s));
        });
        if (!known && story.status) { $status.append($('<option></option>').val(story.status).text(story.status)); }
        $status.val(story.status || STATUSES[0]);
        var $entities = $('<input type="text" class="large-text presshub-memory-f-entities" />').val((story.entities || []).join(', '));
        var $include = $('<input type="checkbox" class="presshub-memory-f-include" />').prop('checked', story.include !== false);
        var $del = $('<button type="button" class="button-link button-link-delete presshub-memory-delete-story"></button>').text(i18n.delete_story);

        $title.on('input', function () { $sumTitle.text($title.val() || i18n.new_story); });
        $status.on('change', function () { $sumStatus.text($status.val()); });
        $del.on('click', function () { $d.remove(); self.updateEmpty(); });

        $body.append(el('p').append(el('label', '', i18n.title)).append(el('br')).append($title));
        $body.append(el('p').append(el('label', '', i18n.development)).append(el('br')).append($dev));
        $body.append(el('p').append(el('label', '', i18n.status + ' ')).append($status));
        $body.append(el('p').append(el('label', '', i18n.entities)).append(el('br')).append($entities));
        $body.append(el('p').append($('<label></label>').append($include).append(' ' + i18n.include)).append(' ').append($del));
        $d.append($body);
        return $d;
    };

    Editor.prototype.updateEmpty = function () {
        var has = this.$stories.children('.presshub-memory-story').length > 0;
        this.$empty.toggle(!has);
    };

    Editor.prototype.collectStories = function () {
        var stories = [];
        this.$stories.children('.presshub-memory-story').each(function () {
            var $s = $(this);
            var entities = $.grep($.map(String($s.find('.presshub-memory-f-entities').val() || '').split(','), $.trim), function (e) { return e !== ''; });
            stories.push({
                key: $s.data('key') || '',
                title: $s.find('.presshub-memory-f-title').val(),
                development: $s.find('.presshub-memory-f-development').val(),
                status: $s.find('.presshub-memory-f-status').val(),
                entities: entities,
                include: $s.find('.presshub-memory-f-include').is(':checked')
            });
        });
        return stories;
    };

    Editor.prototype.renderDiff = function (diff) {
        var $box = el('details', 'presshub-memory-diff');
        $box.append(el('summary', '', i18n.diff_title + ' (' + diff.length + ')'));
        if (!diff.length) {
            $box.append(el('p', 'presshub-memory-empty', i18n.diff_none));
            return $box;
        }
        $.each(diff, function (_, item) {
            var label = i18n['diff_' + item.type] || item.type;
            var $item = el('div', 'presshub-memory-diff-item presshub-memory-diff-' + item.type);
            $item.append(el('strong', '', label + (item.title ? ': ' + item.title : '')));
            $.each(item.changes || [], function (__, c) {
                var $row = el('div', 'presshub-memory-diff-change');
                $row.append(el('em', '', c.field === 'narrative' ? i18n.diff_narrative : c.field));
                $row.append(el('div', 'presshub-memory-diff-before', i18n.diff_before + ': ' + c.before));
                $row.append(el('div', 'presshub-memory-diff-after', i18n.diff_after + ': ' + c.after));
                $item.append($row);
            });
            $box.append($item);
        });
        return $box;
    };

    Editor.prototype.renderStale = function (payload) {
        var self = this;
        var rollups = payload.stale_rollups || [];
        this.$stale.empty();
        if (!rollups.length) { return; }
        var $n = el('div', 'notice notice-warning inline presshub-memory-stale');
        var $p = el('p', '', i18n.stale_notice);
        $.each(rollups, function (_, r) {
            var $line = el('span', 'presshub-memory-stale-item', ' ' + r.label + ' ');
            if (cfg.rollup_available) {
                var $b = $('<button type="button" class="button button-small"></button>').text(i18n.rebuild);
                $b.on('click', function () { self.rebuildRollup(r.id, $b); });
                $line.append($b);
            } else {
                $line.append(el('em', '', '(' + i18n.stale_no_rebuild + ')'));
            }
            $p.append($line);
        });
        this.$stale.append($n.append($p));
    };

    Editor.prototype.rebuildRollup = function (rollupId, $btn) {
        var self = this;
        if (!window.confirm(i18n.rebuild_confirm)) { return; }
        $btn.prop('disabled', true);
        request(cfg.actions.action, { op: 'rebuild', entry_id: rollupId }).done(function (res) {
            if (res && res.success) {
                notice(self.$notices, 'success', i18n.rebuild_done);
                updateRow(res.data.entry);
                self.load();
            }
        }).fail(function (xhr) {
            notice(self.$notices, 'error', i18n.error_prefix + ' ' + errorMessage(xhr));
        }).always(function () { $btn.prop('disabled', false); });
    };

    Editor.prototype.render = function (payload) {
        var self = this;
        var scroll = $(window).scrollTop();
        this.payload = payload;
        var entry = payload.entry;
        var can = payload.can;
        this.$host.empty();

        this.$notices = el('div', 'presshub-memory-editor-notices');
        this.$stale = el('div', 'presshub-memory-stale-wrap');
        this.$host.append(this.$notices).append(this.$stale);

        var $head = el('h3', 'presshub-memory-editor-title', entry.label);
        $.each(entry.badges, function (_, b) {
            $head.append(' ').append(badge(b));
        });
        this.$host.append($head);
        if (entry.error_message) {
            this.$host.append(el('p', 'presshub-memory-error-detail', entry.error_message));
        }
        this.$host.append(this.renderDiff(payload.diff));

        this.$stories = el('div', 'presshub-memory-stories');
        this.$empty = el('p', 'presshub-memory-empty', i18n.no_stories);
        var total = payload.content.stories.length;
        $.each(payload.content.stories, function (_, s) { self.$stories.append(self.storyRow(s, total)); });
        this.$host.append(this.$stories).append(this.$empty);
        this.updateEmpty();

        var $add = $('<button type="button" class="button"></button>').text(i18n.add_story);
        $add.on('click', function () {
            var $row = self.storyRow({ key: '', title: '', development: '', status: STATUSES[0], entities: [], include: true }, 0);
            self.$stories.append($row);
            self.updateEmpty();
            $row.find('.presshub-memory-f-title').trigger('focus');
        });
        this.$host.append(el('p').append($add));

        this.$narrative = null;
        if (can.narrative) {
            this.$narrative = $('<textarea rows="6" class="large-text presshub-memory-f-narrative"></textarea>').val(payload.content.narrative || '');
            this.$host.append(el('p').append(el('label', '', i18n.narrative)).append(el('br')).append(this.$narrative));
        }

        var $bar = el('p', 'presshub-memory-actions');
        var $save = $('<button type="button" class="button button-primary"></button>').text(i18n.save);
        $save.on('click', function () { self.save($save); });
        $bar.append($save);

        function actionButton(op, label, confirmText, doneText) {
            var $b = $('<button type="button" class="button"></button>').text(label);
            $b.on('click', function () { self.act(op, confirmText, doneText, $b); });
            $bar.append(' ').append($b);
        }
        if (can.reset) { actionButton('reset', i18n.reset, i18n.reset_confirm, i18n.reset_done); }
        if (can.exclude) { actionButton('exclude', i18n.exclude, '', i18n.excluded_done); }
        if (can.include) { actionButton('include', i18n.include_entry, '', i18n.included_done); }
        if (can.regenerate) {
            actionButton('regenerate', i18n.regenerate, entry.origin === 'edited' ? i18n.regenerate_edited : i18n.regenerate_confirm, i18n.regenerate_done);
        }
        if (can.rebuild) { actionButton('rebuild', i18n.rebuild, i18n.rebuild_confirm, i18n.rebuild_done); }
        var url = safeUrl(payload.record_url);
        if (url) {
            $bar.append(' ').append($('<a class="button"></a>').attr('href', url).text(i18n.view_record));
        }
        this.$host.append($bar);
        this.renderStale(payload);
        $(window).scrollTop(scroll);
    };

    Editor.prototype.done = function (res, doneText) {
        var data = res.data;
        updateRow(data.entry);
        this.render(data);
        var msg = doneText;
        if (data.dependents_stale > 0) { msg += ' ' + i18n.stale_notice; }
        notice(this.$notices, 'success', msg);
    };

    Editor.prototype.save = function ($btn) {
        var self = this;
        var data = { entry_id: this.entryId, stories: JSON.stringify(this.collectStories()) };
        if (this.$narrative) { data.narrative = this.$narrative.val(); }
        $btn.prop('disabled', true).text(i18n.saving);
        request(cfg.actions.save, data).done(function (res) {
            if (res && res.success) { self.done(res, i18n.saved); }
        }).fail(function (xhr) {
            notice(self.$notices, 'error', i18n.error_prefix + ' ' + errorMessage(xhr));
            $btn.prop('disabled', false).text(i18n.save);
        });
    };

    Editor.prototype.act = function (op, confirmText, doneText, $btn) {
        var self = this;
        if (confirmText && !window.confirm(confirmText)) { return; }
        $btn.prop('disabled', true);
        request(cfg.actions.action, { op: op, entry_id: this.entryId }).done(function (res) {
            if (res && res.success) { self.done(res, doneText); }
        }).fail(function (xhr) {
            notice(self.$notices, 'error', i18n.error_prefix + ' ' + errorMessage(xhr));
            $btn.prop('disabled', false);
        });
    };

    function badge(name) {
        var cls = String(name).replace(/[^a-z0-9_-]/gi, '');
        return el('span', 'presshub-memory-badge presshub-memory-badge-' + cls, name);
    }

    /** Refresh badges and story count of a timeline row without reloading the page. */
    function updateRow(entry) {
        var $row = $('#presshub-memory-row-' + entry.id);
        if (!$row.length) { return; }
        var $cell = $row.find('.presshub-memory-badges').empty();
        $.each(entry.badges, function (_, b) { $cell.append(badge(b)).append(' '); });
        if (entry.error_message) { $cell.append(el('div', 'presshub-memory-error-detail', entry.error_message)); }
        $row.find('.presshub-memory-count').text(entry.included_count + ' / ' + entry.story_count);
    }

    function toggle(entryId, forceOpen) {
        var $editorRow = $('.presshub-memory-editor-row[data-entry-id="' + entryId + '"]');
        if (!$editorRow.length) { return; }
        var open = !$editorRow.prop('hidden');
        if (open && !forceOpen) {
            $editorRow.prop('hidden', true);
            return;
        }
        $editorRow.prop('hidden', false);
        var $host = $editorRow.find('.presshub-memory-editor');
        if (!$host.data('editor')) {
            var editor = new Editor($host, entryId);
            $host.data('editor', editor);
            editor.load();
        }
        var row = document.getElementById('presshub-memory-row-' + entryId);
        if (row && row.scrollIntoView) { row.scrollIntoView({ block: 'nearest' }); }
    }

    $(function () {
        $(document).on('click', '.presshub-memory-edit', function () {
            toggle(parseInt($(this).attr('data-entry-id'), 10), false);
        });
        var open = parseInt(cfg.open_entry, 10);
        if (open > 0) { toggle(open, true); }
    });
}(jQuery));
