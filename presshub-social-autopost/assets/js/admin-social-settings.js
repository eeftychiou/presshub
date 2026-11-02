/**
 * Settings Screen Script for PressHub Social Auto-Post
 */
(function ($) {
    'use strict';

    $(function () {
        var nonce = (window.presshubSocial && window.presshubSocial.nonce) || $('[name="presshub_social_nonce"]').val();

        // Handle Test Connection Buttons
        $(document).on('click', '.presshub-btn-test-connection, [data-test-platform]', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var platform = $btn.data('test-platform') || $btn.data('platform');

            if (!platform) {
                return;
            }

            var $container = $btn.closest('td, .form-table, .presshub-test-container');
            var $result = $btn.siblings('.presshub-test-result');
            if (!$result.length) {
                $result = $('<span class="presshub-test-result"></span>').insertAfter($btn);
            }

            $btn.prop('disabled', true);
            $result.removeClass('success error').addClass('loading').html('<span class="spinner is-active" style="float:none; margin:0 4px 0 0;"></span> Testing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_test_connection',
                    platform: platform,
                    nonce: nonce
                }
            }).done(function (res) {
                $result.removeClass('loading');
                if (res && res.success) {
                    $result.addClass('success').text('✓ ' + (res.data.message || 'Connection successful.'));
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : 'Connection failed.';
                    $result.addClass('error').text('✕ ' + err);
                }
            }).fail(function (xhr) {
                $result.removeClass('loading');
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $result.addClass('error').text('✕ ' + msg);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
