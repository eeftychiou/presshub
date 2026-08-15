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

    $('#presshub-ai-test-api').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $('#presshub-ai-test-spinner').addClass('is-active');
        $('#presshub-ai-test-result').text('');
        $btn.prop('disabled', true);

        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_test_api',
            nonce: presshubAI.nonce
        }, function(response) {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $btn.prop('disabled', false);
            if (response.success) {
                $('#presshub-ai-test-result').css('color', 'green').text('Success! API is working.');
            } else {
                $('#presshub-ai-test-result').css('color', 'red').text('Error: ' + response.data);
            }
        }).fail(function() {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $btn.prop('disabled', false);
            $('#presshub-ai-test-result').css('color', 'red').text('Server error occurred.');
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
                    alert('Draft generated successfully!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                $('#presshub-ai-draft-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                alert('Server connection error.');
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
                var html = '<div class="scorecard-box"><strong>Score: ' + presshubEsc(response.data.score) + '/100</strong><p>' + presshubEsc(response.data.feedback) + '</p></div>';
                $('#presshub-ai-scorecard-results').html(html);
                alert('Review completed. Status updated.');
            } else {
                alert('Error: ' + response.data);
            }
        });
    });
});
