/**
 * Settings Screen Script for PressHub Social Auto-Post
 */
(function ($) {
    'use strict';

    $(function () {
        var nonce = (window.presshubSocial && window.presshubSocial.nonce) || $('[name="presshub_social_nonce"]').val();

        // Handle Client-Side Tab Switching (preserves all 6 tabs inside the single form)
        $(document).on('click', '.presshub-social-tab-link', function (e) {
            var $tab = $(this);
            var tabId = $tab.data('tab');
            var $pane = $('#presshub-social-tab-pane-' + tabId);

            if (!tabId || !$pane.length) {
                return;
            }

            e.preventDefault();
            $('.presshub-social-tab-link').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');

            $('.presshub-social-tab-pane').hide();
            $pane.show();

            if (window.history && window.history.replaceState) {
                var href = $tab.attr('href');
                if (href) {
                    window.history.replaceState(null, '', href);
                }
            }
        });

        // All tabs share one form and inactive tabs are hidden. When a field in a
        // hidden tab fails the browser's validation, the browser cancels the submit
        // and shows nothing, so the save silently does nothing. Show the tab that
        // holds the first invalid field so its message is visible.
        var $form = $('#presshub-social-settings-form');
        var revealedThisSubmit = false;
        $form.find(':submit').on('click', function () {
            revealedThisSubmit = false;
        });
        if ($form.length && $form[0].addEventListener) {
            $form[0].addEventListener('invalid', function (e) {
                if (revealedThisSubmit) {
                    return;
                }
                var $pane = $(e.target).closest('.presshub-social-tab-pane');
                if (!$pane.length || $pane.is(':visible')) {
                    return;
                }
                revealedThisSubmit = true;
                var tabId = ($pane.attr('id') || '').replace('presshub-social-tab-pane-', '');
                var $link = $('.presshub-social-tab-link[data-tab="' + tabId + '"]');
                $('.presshub-social-tab-link').removeClass('nav-tab-active');
                $link.addClass('nav-tab-active');
                $('.presshub-social-tab-pane').hide();
                $pane.show();
                setTimeout(function () {
                    if (e.target.reportValidity) {
                        e.target.reportValidity();
                    }
                }, 0);
            }, true);
        }

        // Handle Test Connection Buttons
        $(document).on('click', '.presshub-social-test-btn, .presshub-btn-test-connection, [data-test-platform]', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var platform = $btn.data('test-platform') || $btn.data('platform');

            if (!platform) {
                return;
            }

            var $row = $btn.closest('.presshub-social-test-row');
            if ($row.length) {
                var $spinner = $row.find('.spinner');
                var $status = $row.find('.presshub-social-test-status');
                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                $status.css('color', '#666').text('Testing...');

                $.post(ajaxurl, {
                    action: 'presshub_social_test_connection',
                    nonce: nonce,
                    platform: platform
                }).done(function (res) {
                    if (res && res.success) {
                        $status.css('color', '#007017').text('✓ ' + (res.data && res.data.message ? res.data.message : 'Connected'));
                    } else {
                        var msg = res && res.data && res.data.message ? res.data.message : 'Connection failed';
                        $status.css('color', '#d63638').text('✕ ' + msg);
                    }
                }).fail(function (xhr) {
                    var msg = 'HTTP Request Error';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    }
                    $status.css('color', '#d63638').text('✕ ' + msg);
                }).always(function () {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
                return;
            }

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
