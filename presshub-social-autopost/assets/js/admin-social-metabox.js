/**
 * Post Edit Metabox Script for PressHub Social Auto-Post
 */
(function ($) {
    'use strict';

    $(function () {
        var $wrapper = $('.presshub-social-metabox-wrapper');
        if (!$wrapper.length) {
            return;
        }

        var nonce = (window.presshubSocial && window.presshubSocial.nonce) ||
                    $('[name="presshub_social_metabox_nonce"]').val() ||
                    $('[name="presshub_social_nonce"]').val();

        // Update live character counters on typing
        $(document).on('input', '.presshub-copy-textarea', function () {
            var $textarea = $(this);
            var len = $textarea.val().length;
            $textarea.closest('.presshub-social-platform-card').find('.current-chars').text(len);
        });

        // Generate Single Copy
        $(document).on('click', '.presshub-generate-single-copy', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var platform = $btn.data('platform');
            var postId = $btn.data('post-id');
            var $spinner = $btn.siblings('.single-spinner-' + platform);
            var $textarea = $('#presshub_social_copy_' + platform);

            if (!platform || !postId) {
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_generate_copy',
                    post_id: postId,
                    platform: platform,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success && res.data && res.data.copy) {
                    $textarea.val(res.data.copy).trigger('input');
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : 'Generation failed.';
                    alert('AI Generation error: ' + err);
                }
            }).fail(function (xhr) {
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert('AI Generation failed: ' + msg);
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Generate All Copy with AI
        $('#presshub-generate-all-social').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $spinner = $('#presshub-social-global-spinner');

            if (!postId) {
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_generate_copy',
                    post_id: postId,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success && res.data && res.data.copy) {
                    var copies = res.data.copy;
                    $.each(copies, function (plat, copyText) {
                        var $t = $('#presshub_social_copy_' + plat);
                        if ($t.length && copyText) {
                            $t.val(copyText).trigger('input');
                        }
                    });
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : 'Batch generation failed.';
                    alert('AI Generation error: ' + err);
                }
            }).fail(function (xhr) {
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert('AI Generation failed: ' + msg);
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Manually Enqueue Social Posts Now from Metabox
        $('#presshub-enqueue-social-now').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $spinner = $('#presshub-social-global-spinner');

            if (!postId) {
                return;
            }

            var platforms = [];
            $('input[name="presshub_social_platforms[]"]:checked').each(function () {
                platforms.push($(this).val());
            });

            if (!platforms.length) {
                alert('Please select at least one social media platform first.');
                return;
            }

            var copies = {};
            $('.presshub-copy-textarea').each(function () {
                var id = $(this).attr('id') || '';
                var plat = id.replace('presshub_social_copy_', '');
                if (plat) {
                    copies[plat] = $(this).val();
                }
            });

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_enqueue_post',
                    post_id: postId,
                    platforms: platforms,
                    copies: copies,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    alert(res.data && res.data.message ? res.data.message : 'Social posts queued successfully!');
                    window.location.reload();
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to queue social posts.';
                    alert('Queue error: ' + err);
                }
            }).fail(function (xhr) {
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert('Queue failed: ' + msg);
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });
    });
})(jQuery);
