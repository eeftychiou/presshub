/**
 * Settings → Writing & QA → Featured images: the Featured Image Studio prompt
 * templates editor. A structured store with its own save endpoints: every
 * change is saved at once through admin-ajax, never with the section's Save.
 */
(function ($) {
    'use strict';

    var $root = $('#presshub-image-templates-manager');
    if (!$root.length) {
        return;
    }

    var cfg = window.presshubImageTemplates || {};
    var t = cfg.strings || {};
    var ajaxUrl = cfg.ajax_url || (window.presshubAI && window.presshubAI.ajax_url) || '';
    var nonce = cfg.nonce || (window.presshubAI && window.presshubAI.nonce) || '';

    var templates = [];
    try {
        templates = JSON.parse($root.attr('data-templates') || '[]');
    } catch (err) {
        templates = [];
    }

    var $rows = $('#presshub-image-templates-rows');
    var $editor = $('#presshub-image-templates-editor');
    var $title = $('#presshub-image-templates-editor-title');
    var $name = $('#presshub-image-templates-name');
    var $text = $('#presshub-image-templates-text');
    var $save = $('#presshub-image-templates-save');
    var $copy = $('#presshub-image-templates-copy');
    var $status = $('#presshub-image-templates-status');

    // mode: 'add' | 'edit' | 'view'; id: template being edited or viewed.
    var current = { mode: '', id: '', opener: null };

    // Fill %s without String.replace's special "$" patterns.
    function fill(tpl, value) {
        return String(tpl).replace('%s', function () { return value; });
    }

    function find(id) {
        for (var i = 0; i < templates.length; i++) {
            if (templates[i].id === id) {
                return templates[i];
            }
        }
        return null;
    }

    function setStatus(msg, isError) {
        $status.text(msg || '').toggleClass('is-error', !!isError);
    }

    function actionButton(label, action, tpl, extraClass) {
        return $('<button type="button" />')
            .addClass(extraClass || 'button-link')
            .attr('data-action', action)
            .attr('data-template-id', tpl.id)
            .attr('aria-label', label + ': ' + tpl.name)
            .text(label);
    }

    function kindLabel(tpl) {
        if (tpl.builtin) {
            return t.builtin || 'Built-in';
        }
        return tpl.author ? fill(t.saved_by || 'Saved by %s', tpl.author) : (t.saved || 'Saved');
    }

    function render() {
        $rows.empty();
        templates.forEach(function (tpl) {
            var $actions = $('<td class="presshub-image-templates-actions" />');
            if (tpl.builtin && tpl.id === cfg.default_id) {
                $actions.append(
                    $('<a />').attr('href', '#presshub_ai_image_analysis_prompt').text(t.edit_above || 'Edit in the field above')
                );
                $actions.append(actionButton(t.copy || 'Copy', 'copy', tpl));
            } else if (tpl.builtin) {
                $actions.append(actionButton(t.view || 'View', 'view', tpl));
                $actions.append(actionButton(t.copy || 'Copy', 'copy', tpl));
            } else {
                if (tpl.deletable) {
                    $actions.append(actionButton(t.edit || 'Edit', 'edit', tpl));
                } else {
                    $actions.append(actionButton(t.view || 'View', 'view', tpl));
                }
                $actions.append(actionButton(t.copy || 'Copy', 'copy', tpl));
                if (tpl.deletable) {
                    $actions.append(actionButton(t['delete'] || 'Delete', 'delete', tpl, 'button-link button-link-delete'));
                }
            }
            var $name = $('<th scope="row" class="presshub-image-templates-name" />').append($('<strong />').text(tpl.name));
            $rows.append(
                $('<tr />').attr('data-template-id', tpl.id)
                    .append($name)
                    .append($('<td class="presshub-image-templates-kind" />').text(kindLabel(tpl)))
                    .append($actions)
            );
        });
    }

    // The Automatic images selects above list the saved templates too: keep
    // their "Saved templates" group in step with this store, without a reload.
    function syncSelects() {
        var saved = templates.filter(function (x) { return !x.builtin; });
        $('select.presshub-auto-images-select').each(function () {
            var $sel = $(this);
            var value = $sel.val();
            $sel.find('optgroup[data-group="saved"]').remove();
            if (saved.length) {
                var $group = $('<optgroup data-group="saved" />').attr('label', t.saved_group || 'Saved templates');
                saved.forEach(function (tpl) {
                    $group.append($('<option />').val(tpl.id).text(tpl.name));
                });
                $sel.append($group);
            }
            if (value && $sel.find('option').filter(function () { return this.value === value; }).length) {
                $sel.val(value);
            }
        });
    }

    function openEditor(mode, tpl, opener) {
        current = { mode: mode, id: tpl ? tpl.id : '', opener: opener || null };
        var readOnly = mode === 'view';
        var heading;
        if (mode === 'add') {
            heading = t.heading_add || 'New template';
        } else if (mode === 'edit') {
            heading = fill(t.heading_edit || 'Edit template: %s', tpl.name);
        } else {
            heading = fill(t.heading_view || 'Template: %s', tpl.name);
        }
        $title.text(heading);
        $name.val(mode === 'add' ? (tpl ? fill(t.copy_name || '%s (copy)', tpl.name) : '') : tpl.name)
            .prop('readOnly', readOnly);
        $text.val(tpl ? tpl.template : '').prop('readOnly', readOnly).scrollTop(0);
        $save.prop('hidden', readOnly).prop('disabled', false);
        $copy.prop('hidden', !readOnly);
        $editor.prop('hidden', false);
        setStatus('');
        if (readOnly) {
            $title.trigger('focus');
        } else {
            $name.trigger('focus');
        }
    }

    function closeEditor() {
        var opener = current.opener;
        $editor.prop('hidden', true);
        current = { mode: '', id: '', opener: null };
        if (opener && document.body.contains(opener)) {
            $(opener).trigger('focus');
        } else {
            $('#presshub-image-templates-add').trigger('focus');
        }
    }

    function post(data) {
        data.nonce = nonce;
        return $.post(ajaxUrl, data);
    }

    function errorText(res, xhr, fallback) {
        if (res && typeof res.data === 'string' && res.data) {
            return res.data;
        }
        if (xhr && xhr.responseJSON && typeof xhr.responseJSON.data === 'string') {
            return xhr.responseJSON.data;
        }
        return fallback || t.error || 'Could not save the template. Try again.';
    }

    function save() {
        var name = $.trim($name.val() || '');
        var text = $.trim($text.val() || '');
        if (!name) {
            setStatus(t.need_name || 'Give the template a name.', true);
            $name.trigger('focus');
            return;
        }
        if (!text) {
            setStatus(t.need_text || 'The template text is empty.', true);
            $text.trigger('focus');
            return;
        }
        var editing = current.mode === 'edit';
        $save.prop('disabled', true);
        setStatus(t.saving || 'Saving...');
        var data = editing
            ? { action: 'presshub_ai_update_image_prompt_template', template_id: current.id, name: name, template: text }
            : { action: 'presshub_ai_save_image_prompt_template', name: name, template: text };
        post(data).done(function (res) {
            if (!res || !res.success) {
                setStatus(errorText(res), true);
                return;
            }
            var saved = res.data.template;
            var row = {
                id: saved.id,
                name: saved.name,
                template: saved.template,
                builtin: false,
                deletable: true,
                author: editing && find(saved.id) ? find(saved.id).author : (cfg.current_user || '')
            };
            if (editing) {
                for (var i = 0; i < templates.length; i++) {
                    if (templates[i].id === saved.id) {
                        templates[i] = row;
                    }
                }
            } else {
                templates.push(row);
            }
            render();
            syncSelects();
            var opener = $rows.find('button[data-action="edit"][data-template-id="' + saved.id + '"]').get(0);
            current.opener = opener || null;
            closeEditor();
            setStatus(fill(t.saved_msg || 'Template "%s" saved.', saved.name));
        }).fail(function (xhr) {
            setStatus(errorText(null, xhr), true);
        }).always(function () {
            $save.prop('disabled', false);
        });
    }

    function remove(tpl, btn) {
        if (!window.confirm(fill(t.confirm_delete || 'Delete the template "%s"? Editors can no longer pick it.', tpl.name))) {
            return;
        }
        $(btn).prop('disabled', true);
        setStatus(t.deleting || 'Deleting...');
        post({ action: 'presshub_ai_delete_image_prompt_template', template_id: tpl.id }).done(function (res) {
            if (!res || !res.success) {
                $(btn).prop('disabled', false);
                setStatus(errorText(res), true);
                return;
            }
            templates = templates.filter(function (x) { return x.id !== tpl.id; });
            syncSelects();
            if (current.id === tpl.id) {
                $editor.prop('hidden', true);
                current = { mode: '', id: '', opener: null };
            }
            render();
            $('#presshub-image-templates-add').trigger('focus');
            setStatus(fill(t.deleted_msg || 'Template "%s" deleted.', tpl.name));
        }).fail(function (xhr) {
            $(btn).prop('disabled', false);
            setStatus(errorText(null, xhr, t.delete_error || 'Could not delete the template. Try again.'), true);
        });
    }

    $rows.on('click', 'button[data-action]', function (e) {
        e.preventDefault();
        var tpl = find($(this).attr('data-template-id'));
        if (!tpl) {
            return;
        }
        var action = $(this).attr('data-action');
        if (action === 'delete') {
            remove(tpl, this);
        } else if (action === 'copy') {
            openEditor('add', tpl, this);
        } else {
            openEditor(action, tpl, this);
        }
    });

    $('#presshub-image-templates-add').on('click', function (e) {
        e.preventDefault();
        openEditor('add', null, this);
    });

    $copy.on('click', function (e) {
        e.preventDefault();
        var tpl = find(current.id);
        if (tpl) {
            openEditor('add', tpl, current.opener);
        }
    });

    $save.on('click', function (e) {
        e.preventDefault();
        save();
    });

    $('#presshub-image-templates-cancel').on('click', function (e) {
        e.preventDefault();
        closeEditor();
    });

    // Enter in the name field saves the template instead of submitting the
    // section form; Escape closes the editor.
    $name.on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (current.mode !== 'view') {
                save();
            }
        }
    });
    $editor.on('keydown', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeEditor();
        }
    });

    render();
})(jQuery);
