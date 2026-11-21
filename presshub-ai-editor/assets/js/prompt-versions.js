/**
 * PressHub → Settings: prompt history (2.77.0).
 *
 * Every prompt studio textarea listed in window.presshubPromptVersions.prompts
 * gets a "History and changes" button. Its panel lists the kept versions of
 * the prompt (newest first) with who saved them, where they came from and how
 * many generations used them, and can:
 *
 *  - show a version's text and the generations (posts) that used it,
 *  - show the changes from the previous version, between any two versions, or
 *    from a version to the text in the editor (removed words struck through,
 *    added words bold),
 *  - load a version into the editor. Nothing is written until the section is
 *    saved; the save is kept as a new version marked "restored from vN".
 *
 * Read only on the server: the panel uses the three read-only AJAX actions of
 * PressHub_AI_Prompt_Versions. A ?prompt_history=<option>&prompt_version=<id>
 * link (from Generation records) opens the panel on load.
 */
(function ($) {
    'use strict';

    var data = window.presshubPromptVersions || {};
    var prompts = data.prompts || {};
    var t = data.strings || {};

    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(str || '')
            .replace(/%(\d+)\$[sd]/g, function (m, n) { return String(args[parseInt(n, 10) - 1]); })
            .replace(/%[sd]/g, function () { return String(args[i++]); });
    }

    function request(action, params, method) {
        return $.ajax({
            url: data.ajax_url,
            method: method || 'GET',
            dataType: 'json',
            data: $.extend({ action: action, nonce: data.nonce }, params)
        });
    }

    function errorText(xhr) {
        var res = xhr && xhr.responseJSON;
        return (res && res.data && res.data.message) || t.error;
    }

    /** Show the tab panes a textarea sits in (podcast style and presenter tabs, Social tabs). */
    function reveal($textarea) {
        $textarea.parents('[id]').each(function () {
            var el = this;
            if (!$(el).is(':visible')) {
                var $tab = $('[data-target="' + el.id + '"], a[href="#' + el.id + '"]').first();
                if ($tab.length) {
                    $tab.trigger('click');
                }
            }
        });
    }

    function Panel(key, $textarea) {
        this.key = key;
        this.$textarea = $textarea;
        this.versions = [];
        this.loaded = false;
        var id = 'presshub-pv-' + key;
        this.$button = $('<button type="button" class="button button-secondary presshub-pv-open"></button>')
            .text(t.button)
            .attr({ 'aria-expanded': 'false', 'aria-controls': id });
        this.$panel = $('<div class="presshub-pv-panel presshub-is-hidden" role="region"></div>')
            .attr({ id: id, 'aria-label': t.title + ': ' + prompts[key] });
        var $bar = $('<p class="presshub-pv-bar"></p>').append(this.$button);
        $textarea.after(this.$panel).after($bar);
        this.$button.on('click', $.proxy(this.toggle, this));
    }

    Panel.prototype.toggle = function () {
        if (this.$panel.hasClass('presshub-is-hidden')) {
            this.open(0);
        } else {
            this.close();
        }
    };

    Panel.prototype.close = function () {
        this.$panel.addClass('presshub-is-hidden');
        this.$button.attr('aria-expanded', 'false').trigger('focus');
    };

    Panel.prototype.open = function (versionId) {
        var self = this;
        this.$panel.removeClass('presshub-is-hidden');
        this.$button.attr('aria-expanded', 'true');
        this.$panel.empty().append($('<p class="presshub-pv-status"></p>').text(t.loading));
        return request('presshub_ai_prompt_versions', { key: this.key }).done(function (res) {
            if (!res || !res.success) {
                self.$panel.empty().append($('<p class="presshub-pv-status"></p>').text(t.error));
                return;
            }
            self.versions = res.data.versions || [];
            self.render(res.data);
            if (versionId) {
                self.view(versionId);
            }
        }).fail(function (xhr) {
            self.$panel.empty().append($('<p class="presshub-pv-status"></p>').text(errorText(xhr)));
        });
    };

    Panel.prototype.find = function (id) {
        for (var i = 0; i < this.versions.length; i++) {
            if (this.versions[i].id === id) {
                return { version: this.versions[i], index: i };
            }
        }
        return null;
    };

    Panel.prototype.render = function (payload) {
        var self = this;
        var $p = this.$panel.empty();
        var $head = $('<div class="presshub-pv-head"></div>');
        $head.append($('<h4 class="presshub-pv-title"></h4>').text(t.title + ': ' + payload.label));
        $head.append($('<button type="button" class="button-link presshub-pv-close"></button>').text(t.close).on('click', function () { self.close(); }));
        $p.append($head);

        if (!this.versions.length) {
            $p.append($('<p class="presshub-pv-status"></p>').text(t.empty));
            return;
        }

        var $table = $('<table class="widefat striped presshub-pv-table"><thead><tr></tr></thead><tbody></tbody></table>');
        $.each([t.version, t.saved, t.by, t.origin, t.used, t.actions], function (i, h) {
            $table.find('thead tr').append($('<th scope="col"></th>').text(h));
        });
        $.each(this.versions, function (i, v) {
            var $tr = $('<tr></tr>');
            var $ver = $('<td></td>').append($('<strong></strong>').text('v' + v.version));
            if (v.current) {
                $ver.append(' ').append($('<span class="presshub-pv-current"></span>').text(t.current));
            }
            $tr.append($ver);
            $tr.append($('<td></td>').text(v.created_at));
            $tr.append($('<td></td>').text(v.user));
            $tr.append($('<td></td>').text(v.source));
            $tr.append($('<td></td>').append(v.uses > 0 ? $('<a></a>').attr('href', v.records_url).text(String(v.uses)) : document.createTextNode('0')));
            var $actions = $('<td class="presshub-pv-actions"></td>');
            $actions.append(self.action(t.view, function () { self.view(v.id); }));
            if (i < self.versions.length - 1) {
                $actions.append(self.action(t.compare_prev, function () { self.diff(self.versions[i + 1].id, v.id); }));
            }
            $actions.append(self.action(t.compare_edit, function () { self.diffEditor(v.id); }));
            $actions.append(self.action(t.restore, function () { self.restore(v.id); }));
            $tr.append($actions);
            $table.find('tbody').append($tr);
        });
        $p.append($('<div class="presshub-pv-table-wrap"></div>').append($table));

        if (this.versions.length > 1) {
            var $pick = $('<p class="presshub-pv-pick"></p>');
            var fromId = 'presshub-pv-from-' + this.key;
            var toId = 'presshub-pv-to-' + this.key;
            var $from = $('<select></select>').attr('id', fromId);
            var $to = $('<select></select>').attr('id', toId);
            $.each(this.versions, function (i, v) {
                $from.append($('<option></option>').val(v.id).text('v' + v.version + ' (' + v.created_at + ')'));
                $to.append($('<option></option>').val(v.id).text('v' + v.version + ' (' + v.created_at + ')'));
            });
            $from.val(String(this.versions[1].id));
            $to.val(String(this.versions[0].id));
            $pick.append($('<strong></strong>').text(t.compare_pick + ': '));
            $pick.append($('<label></label>').attr('for', fromId).text(t.compare_from + ' ')).append($from).append(' ');
            $pick.append($('<label></label>').attr('for', toId).text(t.compare_to + ' ')).append($to).append(' ');
            $pick.append($('<button type="button" class="button button-secondary"></button>').text(t.compare_go).on('click', function () {
                self.diff(parseInt($from.val(), 10), parseInt($to.val(), 10));
            }));
            $p.append($pick);
        }

        this.$out = $('<div class="presshub-pv-output" aria-live="polite"></div>');
        $p.append(this.$out);
    };

    Panel.prototype.action = function (label, handler) {
        return $('<button type="button" class="button-link presshub-pv-action"></button>').text(label).on('click', handler);
    };

    Panel.prototype.show = function ($content) {
        if (this.$out) {
            this.$out.empty().append($content);
        }
    };

    Panel.prototype.view = function (id) {
        var self = this;
        this.show($('<p class="presshub-pv-status"></p>').text(t.loading));
        request('presshub_ai_prompt_version', { id: id }).done(function (res) {
            if (!res || !res.success) {
                self.show($('<p class="presshub-pv-status"></p>').text(t.error));
                return;
            }
            var v = res.data;
            var $box = $('<div></div>');
            $box.append($('<h5 class="presshub-pv-subtitle"></h5>').text('v' + v.version + ' · ' + v.created_at + ' · ' + v.user + ' · ' + v.source));
            $box.append($('<div class="presshub-pv-text"></div>').text(v.content));
            $box.append($('<h5 class="presshub-pv-subtitle"></h5>').text(t.uses_heading + ' (' + v.uses_count + ')'));
            if (!v.uses.length) {
                $box.append($('<p></p>').text(t.uses_none));
            } else {
                var $ul = $('<ul class="presshub-pv-uses"></ul>');
                $.each(v.uses, function (i, u) {
                    var $li = $('<li></li>');
                    $li.append(document.createTextNode(u.created_at + ' · ' + u.kind + (u.status === 'error' ? ' (error)' : '') + ' · '));
                    if (u.post_id && u.post_url) {
                        $li.append($('<a></a>').attr('href', u.post_url).text(u.post_title)).append(' · ');
                    } else if (u.post_title) {
                        $li.append(document.createTextNode(u.post_title + ' · '));
                    }
                    $li.append($('<a></a>').attr('href', u.record_url).text(t.record + ' #' + u.record_id));
                    $ul.append($li);
                });
                $box.append($ul);
                if (v.uses_count > v.uses.length) {
                    $box.append($('<p></p>').append($('<a></a>').attr('href', v.records_url).text(t.uses_all)));
                }
            }
            self.show($box);
        }).fail(function (xhr) {
            self.show($('<p class="presshub-pv-status"></p>').text(errorText(xhr)));
        });
    };

    Panel.prototype.renderDiff = function (res) {
        if (!res || !res.success) {
            this.show($('<p class="presshub-pv-status"></p>').text(t.error));
            return;
        }
        var d = res.data;
        var $box = $('<div></div>');
        $box.append($('<h5 class="presshub-pv-subtitle"></h5>').text(d.heading));
        if (d.same) {
            $box.append($('<p></p>').text(t.no_changes));
        } else {
            $box.append($('<p class="presshub-pv-legend"></p>').text(fmt(t.stats, d.added, d.removed)));
            // Built and escaped on the server (PressHub_AI_Prompt_Versions::diff_html()).
            $box.append($(d.html));
        }
        this.show($box);
    };

    Panel.prototype.diff = function (fromId, toId) {
        var self = this;
        if (fromId === toId) {
            this.show($('<p></p>').text(t.no_changes));
            return;
        }
        this.show($('<p class="presshub-pv-status"></p>').text(t.loading));
        request('presshub_ai_prompt_version_diff', { from: fromId, to: toId }).done(function (res) {
            self.renderDiff(res);
        }).fail(function (xhr) {
            self.show($('<p class="presshub-pv-status"></p>').text(errorText(xhr)));
        });
    };

    Panel.prototype.diffEditor = function (fromId) {
        var self = this;
        this.show($('<p class="presshub-pv-status"></p>').text(t.loading));
        request('presshub_ai_prompt_version_diff', { from: fromId, text: this.$textarea.val() || '' }, 'POST').done(function (res) {
            self.renderDiff(res);
        }).fail(function (xhr) {
            self.show($('<p class="presshub-pv-status"></p>').text(errorText(xhr)));
        });
    };

    Panel.prototype.restore = function (id) {
        var self = this;
        request('presshub_ai_prompt_version', { id: id }).done(function (res) {
            if (!res || !res.success) {
                self.show($('<p class="presshub-pv-status"></p>').text(t.error));
                return;
            }
            // Like "Load default template": the editor holds it, the section Save keeps it.
            self.$textarea.val(res.data.content).trigger('input').trigger('change');
            self.show($('<div class="notice notice-info inline"></div>').append($('<p></p>').text(fmt(t.restored, res.data.version))));
            self.$textarea.trigger('focus');
        }).fail(function (xhr) {
            self.show($('<p class="presshub-pv-status"></p>').text(errorText(xhr)));
        });
    };

    $(function () {
        var panels = {};
        $.each(prompts, function (key) {
            var $textarea = $('textarea[name="' + key + '"]').first();
            if ($textarea.length) {
                panels[key] = new Panel(key, $textarea);
            }
        });
        if (data.open && panels[data.open]) {
            var panel = panels[data.open];
            reveal(panel.$textarea);
            panel.open(parseInt(data.open_version, 10) || 0).always(function () {
                var el = panel.$panel.get(0);
                if (el && el.scrollIntoView) {
                    el.scrollIntoView({ block: 'start' });
                }
            });
        }
    });
}(jQuery));
