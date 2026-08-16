/**
 * PressHub AI — classic editor (post.php / post-new.php) admin JS.
 *
 * Drives the metabox buttons (Generate Initial Draft, Run AI Editorial Review)
 * and the Settings → PressHub AI "Test Connection" buttons. Strings are
 * translatable via the wp.i18n runtime that the PHP enqueue wires in.
 */
/* global wp, jQuery, presshubAI, tinymce */
const { __ } = wp.i18n;

jQuery(document).ready(function($) {
    // Escape a string for safe insertion into HTML. Used for any server
    // or AI-provided content rendered into the admin UI (defense in depth
    // against a provider response containing markup).
    function presshubEsc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Per-provider Test buttons: every .presshub-ai-test-api button carries
    // data-provider ('' = the active provider) which the server uses to pick
    // the provider + per-provider credentials for the connection test.
    $('.presshub-ai-test-api').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $('#presshub-ai-test-spinner').addClass('is-active');
        $('#presshub-ai-test-result').text('');
        $btn.prop('disabled', true);

        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_test_api',
            nonce: presshubAI.nonce,
            provider: $btn.data('provider')
        }, function(response) {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $btn.prop('disabled', false);
            if (response.success) {
                $('#presshub-ai-test-result').css('color', 'green').text(__('Success! API is working.', 'presshub-ai-editor'));
            } else {
                $('#presshub-ai-test-result').css('color', 'red').text(
                    __('Error: ', 'presshub-ai-editor') + response.data
                );
            }
        }).fail(function() {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $btn.prop('disabled', false);
            $('#presshub-ai-test-result').css('color', 'red').text(__('Server error occurred.', 'presshub-ai-editor'));
        });
    });

    $('#presshub-ai-generate-draft').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var sources = $('#presshub-ai-sources').val();
        var instructions = $('#presshub-ai-instructions').val();

        var fileInput = document.getElementById('presshub-ai-files');

        var formData = new FormData();
        formData.append('action', 'presshub_ai_generate_draft');
        formData.append('nonce', presshubAI.nonce);
        formData.append('post_id', postId);
        formData.append('sources', sources);
        formData.append('instructions', instructions);
        formData.append('instruction_preset_id', $('#presshub-ai-preset').val() || '');

        if (fileInput && fileInput.files.length > 0) {
            for (var i = 0; i < fileInput.files.length; i++) {
                formData.append('files[]', fileInput.files[i]);
            }
        }

        $('#presshub-ai-draft-spinner').addClass('is-active');
        $btn.prop('disabled', true);

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $('#presshub-ai-draft-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                if(response.success) {
                    if (wp.data && wp.data.dispatch('core/editor')) {
                        var currentBlocks = wp.data.select('core/editor').getBlocks();
                        var newBlock = wp.blocks.createBlock('core/paragraph', { content: response.data.draft });
                        wp.data.dispatch('core/editor').insertBlocks([newBlock], currentBlocks.length);
                    } else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                        tinymce.activeEditor.execCommand('mceInsertContent', false, response.data.draft);
                    }
                    alert(__('Draft generated successfully!', 'presshub-ai-editor'));
                } else {
                    alert(__('Error: ', 'presshub-ai-editor') + response.data);
                }
            },
            error: function() {
                $('#presshub-ai-draft-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                alert(__('Server connection error.', 'presshub-ai-editor'));
            }
        });
    });

    $('#presshub-ai-run-review').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var postId = $btn.data('post-id');

        var content = '';
        if (wp.data && wp.data.select('core/editor')) {
            content = wp.data.select('core/editor').getEditedPostAttribute('content');
        } else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            content = tinymce.activeEditor.getContent();
        }

        $('#presshub-ai-review-spinner').addClass('is-active');
        $btn.prop('disabled', true);

        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_run_review',
            nonce: presshubAI.nonce,
            post_id: postId,
            content: content
        }, function(response) {
            $('#presshub-ai-review-spinner').removeClass('is-active');
            $btn.prop('disabled', false);
            if(response.success) {
                var scoreText = __('Score: %s/100', 'presshub-ai-editor')
                    .replace('%s', presshubEsc(response.data.score));
                var html = '<div class="scorecard-box"><strong>' + scoreText + '</strong><p>' + presshubEsc(response.data.feedback) + '</p></div>';
                $('#presshub-ai-scorecard-results').html(html);
                alert(__('Review completed. Status updated.', 'presshub-ai-editor'));
            } else {
                alert(__('Error: ', 'presshub-ai-editor') + response.data);
            }
        });
    });
});
