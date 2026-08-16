/**
 * PressHub AI — instruction-preset management.
 *
 * Serves two admin surfaces with one enqueued asset:
 *   - the admin library page (class-admin-presets.php, scope = 'plugin');
 *   - the author profile section (class-author-presets.php, scope = 'author').
 *
 * Configuration (ajax_url, nonce, scope, user_id, i18n) is provided by
 * wp_localize_script on window.presshubAI; the viewed user id is also
 * read from the section's data-user-id attribute. All mutations POST to
 * the presshub_ai_*_preset AJAX endpoints and reload the page on success.
 *
 * Depends on jQuery. Strings are translatable via wp.i18n; for non-
 * critical user-facing copy (alert fallback, prompts) the server-localized
 * `i18n` payload still provides a last-ditch default.
 */
/* global jQuery */
const { __ } = wp.i18n;
(function($) {
    'use strict';

    var cfg = window.presshubAI || {};
    var i18n = cfg.i18n || {};

    /** Kebab-case slug from a display name (mirrors the server-side slug regex). */
    function presshubPresetSlug(name) {
        return String(name || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 40);
    }

    /** POST a preset mutation; reload the page on success, alert on error. */
    function presshubPostPreset(data) {
        data.nonce = cfg.nonce;
        return $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: data
        }).done(function(response) {
            if (response && response.success) {
                location.reload();
            } else {
                alert(
                    (i18n.error_prefix || __('Error: ', 'presshub-ai-editor')) +
                    (response && response.data ? response.data : (i18n.unknown_error || __('Unknown error.', 'presshub-ai-editor')))
                );
            }
        }).fail(function() {
            alert(i18n.connection_error || __('Server connection error.', 'presshub-ai-editor'));
        });
    }

    $(function() {
        // ------------------------------------------------------------------
        // Admin library page (scope = plugin). Bound only when the page's
        // add-preset form exists, so the same handlers never fire on the
        // author profile screens.
        // ------------------------------------------------------------------
        var $adminForm = $('#presshub-ai-add-preset-form');
        if ($adminForm.length) {
            $adminForm.on('submit', function(e) {
                e.preventDefault();
                var name = $('#presshub-ai-new-preset-name').val();
                var text = $('#presshub-ai-new-preset-text').val();
                var slug = presshubPresetSlug(name);
                if (!slug) {
                    alert(i18n.slug_required || __('Name must contain at least one letter or number to generate a slug.', 'presshub-ai-editor'));
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: slug,
                    name: name,
                    instruction_text: text,
                    enabled: 1
                });
            });

            $('.presshub-preset-delete').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                if (!window.confirm(i18n.delete_confirm || __('Delete this preset?', 'presshub-ai-editor'))) {
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_delete_preset',
                    scope: 'plugin',
                    slug: $row.data('slug')
                });
            });

            $('.presshub-preset-enabled').on('change', function() {
                var $row = $(this).closest('.presshub-preset-row');
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: $row.data('slug'),
                    name: $row.find('.presshub-preset-name').text(),
                    instruction_text: $row.data('text') || '',
                    enabled: this.checked ? 1 : 0
                });
            });

            $('.presshub-preset-edit').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                var $editRow = $row.next('.presshub-preset-edit-row');
                $editRow.find('.presshub-edit-name').val($row.find('.presshub-preset-name').text());
                $editRow.find('.presshub-edit-text').val($row.data('text') || '');
                $row.hide();
                $editRow.show();
            });

            $('.presshub-edit-cancel').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                $editRow.hide();
                $editRow.prev('.presshub-preset-row').show();
            });

            $('.presshub-edit-save').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                var $row = $editRow.prev('.presshub-preset-row');
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: $row.data('slug'),
                    name: $editRow.find('.presshub-edit-name').val(),
                    instruction_text: $editRow.find('.presshub-edit-text').val(),
                    enabled: $row.find('.presshub-preset-enabled').is(':checked') ? 1 : 0
                });
            });
        }

        // ------------------------------------------------------------------
        // Author profile section (scope = author). The AJAX handlers only
        // ever mutate the CURRENT user's library, so mutation handlers are
        // bound only when the section is editable (own profile).
        // ------------------------------------------------------------------
        var $section = $('#presshub-ai-author-presets');
        if ($section.length) {
            var userId = $section.data('user-id');
            var canEdit = String($section.data('can-edit')) === '1';

            if (canEdit) {
                $('#presshub-ai-author-add-preset-form').on('submit', function(e) {
                    e.preventDefault();
                    var name = $('#presshub-ai-author-new-preset-name').val();
                    var text = $('#presshub-ai-author-new-preset-text').val();
                    var slug = presshubPresetSlug(name);
                    if (!slug) {
                        alert(i18n.slug_required || __('Name must contain at least one letter or number to generate a slug.', 'presshub-ai-editor'));
                        return;
                    }
                    presshubPostPreset({
                        action: 'presshub_ai_save_preset',
                        scope: 'author',
                        user_id: userId,
                        slug: slug,
                        name: name,
                        instruction_text: text,
                        enabled: 1
                    });
                });

                $section.find('.presshub-preset-delete').on('click', function() {
                    var $row = $(this).closest('.presshub-preset-row');
                    if (!window.confirm(i18n.delete_confirm || __('Delete this preset?', 'presshub-ai-editor'))) {
                        return;
                    }
                    presshubPostPreset({
                        action: 'presshub_ai_delete_preset',
                        scope: 'author',
                        user_id: userId,
                        slug: $row.data('slug')
                    });
                });

                $section.find('.presshub-preset-edit').on('click', function() {
                    var $row = $(this).closest('.presshub-preset-row');
                    var $editRow = $row.next('.presshub-preset-edit-row');
                    $editRow.find('.presshub-edit-name').val($row.find('.presshub-preset-name').text());
                    $editRow.find('.presshub-edit-text').val($row.data('text') || '');
                    $row.hide();
                    $editRow.show();
                });

                $section.find('.presshub-edit-cancel').on('click', function() {
                    var $editRow = $(this).closest('.presshub-preset-edit-row');
                    $editRow.hide();
                    $editRow.prev('.presshub-preset-row').show();
                });

                $section.find('.presshub-edit-save').on('click', function() {
                    var $editRow = $(this).closest('.presshub-preset-edit-row');
                    var $row = $editRow.prev('.presshub-preset-row');
                    presshubPostPreset({
                        action: 'presshub_ai_save_preset',
                        scope: 'author',
                        user_id: userId,
                        slug: $row.data('slug'),
                        name: $editRow.find('.presshub-edit-name').val(),
                        instruction_text: $editRow.find('.presshub-edit-text').val(),
                        enabled: 1
                    });
                });

                $('#presshub-ai-save-default').on('click', function() {
                    presshubPostPreset({
                        action: 'presshub_ai_set_default_preset',
                        user_id: userId,
                        slug: $('#presshub-ai-default-preset').val() || ''
                    });
                });

                $('.presshub-ai-copy-preset-btn').on('click', function() {
                    presshubPostPreset({
                        action: 'presshub_ai_copy_default_preset',
                        user_id: userId,
                        slug: $('#presshub-ai-copy-preset').val() || ''
                    });
                });
            }
        }
    });
})(jQuery);
