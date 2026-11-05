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

        // ------------------------------------------------------------------
        // Regenerate AI Copy from Table Action Column
        // ------------------------------------------------------------------
        $(document).on('click', '.btn-regenerate-job-copy', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $btn.data('job-id');

            if (!jobId) {
                return;
            }

            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px; vertical-align:middle; animation: presshub-spin 1s infinite linear;"></span> Generating...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_regenerate_job_copy',
                    job_id: jobId,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success && res.data) {
                    var newCopy = res.data.new_copy || '';
                    var previewText = res.data.preview || newCopy;

                    // Update the preview cell
                    var $preview = $('#copy-preview-' + jobId);
                    if ($preview.length) {
                        $preview.text(previewText).attr('title', newCopy).addClass('flash-update');
                        setTimeout(function () {
                            $preview.removeClass('flash-update');
                        }, 2000);
                    }

                    // Update data attributes on edit buttons in this row
                    $('#job-row-' + jobId).find('.btn-open-edit-modal').data('full-copy', newCopy).attr('data-full-copy', newCopy);

                    $btn.html('<span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; vertical-align:middle; color:#00a32a;"></span> Done!');
                    setTimeout(function () {
                        $btn.prop('disabled', false).html(originalHtml);
                    }, 1800);
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Failed to regenerate AI copy.');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            }).fail(function (xhr) {
                var msg = 'Failed to regenerate AI copy.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                alert(msg);
                $btn.prop('disabled', false).html(originalHtml);
            });
        });

        // ------------------------------------------------------------------
        // Edit & Re-post Modal Dialog Handling
        // ------------------------------------------------------------------
        var $modal = $('#presshub-edit-copy-modal');
        var $modalCopyInput = $('#modal-post-copy-input');
        var $modalCharCounter = $('#modal-char-counter');
        var $modalNotice = $('#modal-feedback-notice');

        function updateCharCount() {
            var len = ($modalCopyInput.val() || '').length;
            $modalCharCounter.text(len);
        }

        $modalCopyInput.on('input keyup', updateCharCount);

        // Open Modal
        $(document).on('click', '.btn-open-edit-modal', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $btn.data('job-id');
            var postId = $btn.data('post-id');
            var platform = $btn.data('platform') || '';
            var engine = $btn.data('engine') || '';
            var status = $btn.data('status') || '';
            var postTitle = $btn.data('post-title') || '';
            var fullCopy = $btn.attr('data-full-copy') || $btn.data('full-copy') || '';

            $modal.data('job-id', jobId)
                  .data('post-id', postId)
                  .data('platform', platform)
                  .data('engine', engine)
                  .data('status', status);

            $('#modal-job-id-text').text(jobId);
            $('#modal-platform-text').text(platform.toUpperCase());
            $('#modal-status-text').text(status.toUpperCase());
            $('#modal-title-text').text(postTitle);

            $modalCopyInput.val(fullCopy);
            updateCharCount();
            $modalNotice.hide().empty().removeClass('notice-success notice-error');

            $modal.fadeIn(150);
            $modalCopyInput.focus();
        });

        // Close Modal
        function closeModal() {
            $modal.fadeOut(150);
        }

        $modal.on('click', '.presshub-modal-close-btn', function (e) {
            e.preventDefault();
            closeModal();
        });

        $modal.on('click', function (e) {
            if ($(e.target).is($modal)) {
                closeModal();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $modal.is(':visible')) {
                closeModal();
            }
        });

        // Regenerate inside Modal
        $('#modal-btn-regen-ai').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $modal.data('job-id');

            if (!jobId) {
                return;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px; vertical-align:middle; animation: presshub-spin 1s infinite linear;"></span> Generating...');
            $modalNotice.hide().empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_regenerate_job_copy',
                    job_id: jobId,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success && res.data) {
                    var newCopy = res.data.new_copy || '';
                    $modalCopyInput.val(newCopy);
                    updateCharCount();

                    // Update table preview in background
                    var $preview = $('#copy-preview-' + jobId);
                    if ($preview.length) {
                        $preview.text(res.data.preview || newCopy).attr('title', newCopy).addClass('flash-update');
                    }
                    $('#job-row-' + jobId).find('.btn-open-edit-modal').data('full-copy', newCopy).attr('data-full-copy', newCopy);

                    $modalNotice.removeClass('notice-error').addClass('notice-success')
                                .text('AI copy regenerated successfully. You can further edit or post again.')
                                .fadeIn();
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Generation failed.';
                    $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
                }
            }).fail(function (xhr) {
                var msg = 'Request failed.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
            }).always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
        });

        // Save to Existing Job
        $('#modal-btn-save-existing').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var jobId = $modal.data('job-id');
            var updatedCopy = $.trim($modalCopyInput.val());

            if (!jobId) {
                return;
            }
            if (!updatedCopy) {
                alert('Post copy cannot be empty.');
                return;
            }

            var origText = $btn.text();
            $btn.prop('disabled', true).text('Saving...');
            $modalNotice.hide().empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_update_job_copy',
                    job_id: jobId,
                    post_copy: updatedCopy,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    // Update table preview
                    var $preview = $('#copy-preview-' + jobId);
                    if ($preview.length) {
                        $preview.text(res.data.preview || updatedCopy).attr('title', updatedCopy).addClass('flash-update');
                    }
                    $('#job-row-' + jobId).find('.btn-open-edit-modal').data('full-copy', updatedCopy).attr('data-full-copy', updatedCopy);

                    $modalNotice.removeClass('notice-error').addClass('notice-success')
                                .text('Job copy updated successfully.')
                                .fadeIn();

                    setTimeout(function () {
                        closeModal();
                    }, 1200);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Save failed.';
                    $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
                }
            }).fail(function (xhr) {
                var msg = 'Failed to save copy.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
            }).always(function () {
                $btn.prop('disabled', false).text(origText);
            });
        });

        // Post Again as New Job
        $('#modal-btn-repost-new').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var sourceJobId = $modal.data('job-id');
            var updatedCopy = $.trim($modalCopyInput.val());

            if (!sourceJobId) {
                return;
            }
            if (!updatedCopy) {
                alert('Post copy cannot be empty.');
                return;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px; vertical-align:middle; animation: presshub-spin 1s infinite linear;"></span> Posting...');
            $modalNotice.hide().empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'presshub_social_repost_job',
                    job_id: sourceJobId,
                    post_copy: updatedCopy,
                    dispatch_now: 1,
                    nonce: nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    $modalNotice.removeClass('notice-error').addClass('notice-success')
                                .text((res.data && res.data.message) ? res.data.message : 'New post created and queued for dispatch!')
                                .fadeIn();

                    setTimeout(function () {
                        window.location.reload();
                    }, 1400);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not create new post.';
                    $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
                    $btn.prop('disabled', false).html(origHtml);
                }
            }).fail(function (xhr) {
                var msg = 'Failed to create new post.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $modalNotice.removeClass('notice-success').addClass('notice-error').text(msg).fadeIn();
                $btn.prop('disabled', false).html(origHtml);
            });
        });
    });
})(jQuery);
