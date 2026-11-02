/**
 * Queue Dashboard Admin Script for PressHub Social Auto-Post
 */
(function ($) {
    'use strict';

    $(function () {
        var $wrap = $('.presshub-social-admin-wrap');
        if (!$wrap.length) {
            return;
        }

        var nonce = $wrap.data('nonce') || (window.presshubSocial && window.presshubSocial.nonce);

        // Process Due Jobs Now
        $('#btn-process-due-jobs').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $spinner = $('#process-due-spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_dispatch_now',
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    alert(res.data.message || 'Due jobs processed successfully.');
                    window.location.reload();
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Error processing due jobs.');
                }
            }).fail(function (xhr) {
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Dispatch Single Job Now
        $(document).on('click', '.btn-dispatch-now', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $btn.data('job-id');

            if (!jobId) {
                return;
            }

            $btn.prop('disabled', true).text('Dispatching...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_dispatch_now',
                    job_id: jobId,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Dispatch failed.');
                    $btn.prop('disabled', false).text('Dispatch');
                }
            }).fail(function (xhr) {
                var msg = 'Dispatch error.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
                $btn.prop('disabled', false).text('Dispatch');
            });
        });

        // Retry Job
        $(document).on('click', '.btn-retry-job', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $btn.data('job-id');

            if (!jobId) {
                return;
            }

            $btn.prop('disabled', true).text('Retrying...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_retry_job',
                    job_id: jobId,
                    dispatch_now: 1,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Retry failed.');
                    $btn.prop('disabled', false).text('Retry');
                }
            }).fail(function (xhr) {
                var msg = 'Retry failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
                $btn.prop('disabled', false).text('Retry');
            });
        });

        // Cancel Job
        $(document).on('click', '.btn-cancel-job', function (e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to cancel this scheduled social post?')) {
                return;
            }

            var $btn = $(this);
            var jobId = $btn.data('job-id');

            if (!jobId) {
                return;
            }

            $btn.prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_cancel_job',
                    job_id: jobId,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Cancel failed.');
                    $btn.prop('disabled', false);
                }
            }).fail(function (xhr) {
                var msg = 'Cancel error.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
