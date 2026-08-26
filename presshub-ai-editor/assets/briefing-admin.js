/**
 * PressHub AI — Daily News Briefing & AI Podcast Admin Script
 *
 * Handles AJAX pipeline actions: scraping, text story curation, podcast dialogue script
 * generation & saving, multi-voice audio synthesis, and Cloudflare blocked source manual uploads.
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var config = window.presshubBriefingAdmin || {};
        var ajaxUrl = config.ajax_url || window.ajaxurl;
        var nonce = config.nonce || '';
        var i18n = config.i18n || {};
        var currentDate = $('#presshub-briefing-hub-wrap').data('date') || config.date || new Date().toISOString().split('T')[0];

        // -------------------------------------------------------------------------
        // Notice Display System
        // -------------------------------------------------------------------------
        function showNotice(type, message) {
            var noticeClass = 'notice notice-' + type + ' is-dismissible presshub-admin-notice';
            var icon = type === 'success' ? '✅' : (type === 'error' ? '❌' : 'ℹ️');
            var html = '<div class="' + noticeClass + '"><p><strong>' + icon + ' ' + message + '</strong></p></div>';
            
            var $container = $('#presshub-briefing-notices');
            $container.empty().append(html);

            // Auto-dismiss after 6 seconds for success
            if (type === 'success') {
                setTimeout(function() {
                    $container.find('.notice-success').fadeOut(400, function() {
                        $(this).remove();
                    });
                }, 6000);
            }
        }

        // -------------------------------------------------------------------------
        // Button Loading State Helper
        // -------------------------------------------------------------------------
        function setButtonLoading($btn, isLoading, originalText, loadingText) {
            if (isLoading) {
                $btn.data('orig-text', originalText || $btn.text());
                $btn.prop('disabled', true).addClass('updating-message');
                if (loadingText) {
                    $btn.text(loadingText);
                }
            } else {
                $btn.prop('disabled', false).removeClass('updating-message');
                var orig = $btn.data('orig-text');
                if (orig) {
                    $btn.text(orig);
                }
            }
        }

        // -------------------------------------------------------------------------
        // UI Refresh from Status Response
        // -------------------------------------------------------------------------
        function updateUIFromStatus(status) {
            if (!status) return;

            // 1. Harvesting Status
            if (status.harvested) {
                $('#milestone-harvest').removeClass('card-pending').addClass('card-complete');
                $('#status-badge-harvest').removeClass('badge-secondary').addClass('badge-success').text('Harvested');
                $('#btn-run-scrape').text('🔄 Re-scrape Sources');
            } else {
                $('#milestone-harvest').removeClass('card-complete').addClass('card-pending');
                $('#status-badge-harvest').removeClass('badge-success').addClass('badge-secondary').text('Pending Scrape');
                $('#btn-run-scrape').text('🔄 Run Scrape Now');
            }
            $('#count-harvested-articles').text(status.article_count || 0);

            // Blocked sources banner
            if (status.blocked_sources && status.blocked_sources.length > 0) {
                var badges = '';
                $.each(status.blocked_sources, function(idx, src) {
                    badges += '<span class="presshub-blocked-badge">' + $('<div>').text(src).html() + '</span> ';
                });
                $('#blocked-sources-list').html(badges);
                $('#presshub-blocked-sources-alert').fadeIn(300);
            } else {
                $('#presshub-blocked-sources-alert').fadeOut(300);
            }

            // 2. Text Curation Status
            if (status.text_created) {
                $('#milestone-curation').removeClass('card-pending').addClass('card-complete');
                $('#status-badge-curation').removeClass('badge-secondary').addClass('badge-success').text('Story Created');
                $('#btn-run-curation').text('✍️ Re-generate Story');
            } else {
                $('#milestone-curation').removeClass('card-complete').addClass('card-pending');
                $('#status-badge-curation').removeClass('badge-success').addClass('badge-secondary').text('Pending Generation');
                $('#btn-run-curation').text('✍️ Generate Text Story');
            }

            // 3. Podcast Script Status
            if (status.script_created) {
                $('#milestone-script').removeClass('card-pending').addClass('card-complete');
                $('#status-badge-script').removeClass('badge-secondary').addClass('badge-success').text('Script Ready');
                $('#btn-run-script').text('🎙️ Re-generate Script');
                if (status.script_text && !$('#presshub-briefing-script-editor').val()) {
                    $('#presshub-briefing-script-editor').val(status.script_text);
                }
            } else {
                $('#milestone-script').removeClass('card-complete').addClass('card-pending');
                $('#status-badge-script').removeClass('badge-success').addClass('badge-secondary').text('Pending Script');
                $('#btn-run-script').text('🎙️ Generate Script');
            }
            $('#script-turns-count').text(status.script_turns_count || 0);
            $('#script-words-count').text(status.script_word_count || 0);

            // 4. Audio Podcast Status
            if (status.audio_created && status.audio_url) {
                $('#milestone-audio').removeClass('card-pending').addClass('card-complete');
                $('#status-badge-audio').removeClass('badge-secondary').addClass('badge-success').text('Synthesized');
                $('#btn-synthesize-audio').text('🔊 Re-synthesize Audio');
                if ($('#briefing-audio-player').length) {
                    $('#briefing-audio-player').attr('src', status.audio_url);
                }
            } else {
                $('#milestone-audio').removeClass('card-complete').addClass('card-pending');
                $('#status-badge-audio').removeClass('badge-success').addClass('badge-secondary').text('Pending Audio');
                $('#btn-synthesize-audio').text('🔊 Synthesize Audio Podcast');
            }
        }

        // -------------------------------------------------------------------------
        // Fetch Pipeline Status
        // -------------------------------------------------------------------------
        function fetchStatus(date, callback) {
            var targetDate = date || currentDate;
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_get_status',
                    nonce: nonce,
                    date: targetDate
                },
                success: function(response) {
                    if (response.success && response.data) {
                        updateUIFromStatus(response.data);
                        if (typeof callback === 'function') callback(null, response.data);
                    } else {
                        var err = (response.data && response.data.message) || response.data || 'Failed to fetch status';
                        if (typeof callback === 'function') callback(err, null);
                    }
                },
                error: function(xhr, status, error) {
                    if (typeof callback === 'function') callback(error || 'Network error', null);
                }
            });
        }

        // -------------------------------------------------------------------------
        // Event: Date Picker Change
        // -------------------------------------------------------------------------
        $('#presshub-date-picker').on('change', function() {
            var newDate = $(this).val();
            if (newDate) {
                currentDate = newDate;
                $('#presshub-briefing-hub-wrap').data('date', newDate);
                fetchStatus(newDate, function(err) {
                    if (err) {
                        showNotice('error', 'Error loading briefing status for ' + newDate + ': ' + err);
                    } else {
                        showNotice('info', 'Loaded briefing data for date: ' + newDate);
                    }
                });
            }
        });

        // -------------------------------------------------------------------------
        // Event: Run Scrape Now / Re-scrape
        // -------------------------------------------------------------------------
        $('#btn-run-scrape').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            setButtonLoading($btn, true, null, i18n.harvesting || 'Harvesting news...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_run_harvest',
                    nonce: nonce,
                    date: currentDate
                },
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        var count = response.data && response.data.articles ? response.data.articles.length : 0;
                        showNotice('success', 'Scraped successfully! ' + count + ' articles saved to snapshot.');
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Harvest failed.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Scrape network error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Run Text Story Curation
        // -------------------------------------------------------------------------
        $('#btn-run-curation').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            setButtonLoading($btn, true, null, i18n.curating || 'Curating story...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_run_curation',
                    nonce: nonce,
                    date: currentDate
                },
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        showNotice('success', 'Text briefing story generated successfully! Post #' + (response.data.post_id || ''));
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Text curation failed.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Curation network error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Run Podcast Script Generation
        // -------------------------------------------------------------------------
        $('#btn-run-script').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            setButtonLoading($btn, true, null, i18n.generating_script || 'Generating script...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_run_script',
                    nonce: nonce,
                    date: currentDate
                },
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        showNotice('success', 'Podcast dialogue script generated! (' + (response.data.turns_count || 0) + ' turns)');
                        if (response.data.raw_script) {
                            $('#presshub-briefing-script-editor').val(response.data.raw_script);
                        }
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Script generation failed.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Script generation error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Save Script Changes
        // -------------------------------------------------------------------------
        $('#btn-save-script').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var scriptText = $('#presshub-briefing-script-editor').val();

            if (!$.trim(scriptText)) {
                showNotice('error', 'Script editor cannot be empty.');
                return;
            }

            setButtonLoading($btn, true, null, i18n.saving_script || 'Saving script...');
            $('#script-save-status').text('');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_save_script',
                    nonce: nonce,
                    date: currentDate,
                    script: scriptText
                },
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        $('#script-save-status').text('Saved! (' + (response.data.turns_count || 0) + ' turns parsed)').css('color', '#46b450');
                        showNotice('success', 'Podcast dialogue script saved successfully.');
                        fetchStatus(currentDate);
                    } else {
                        $('#script-save-status').text('Save error').css('color', '#dc3232');
                        showNotice('error', response.data || 'Failed to save script.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Save script network error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Synthesize Audio Podcast
        // -------------------------------------------------------------------------
        $('#btn-synthesize-audio').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var scriptText = $('#presshub-briefing-script-editor').val();

            setButtonLoading($btn, true, null, i18n.synthesizing_audio || 'Synthesizing audio...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_generate_audio',
                    nonce: nonce,
                    date: currentDate,
                    script: scriptText
                },
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        showNotice('success', 'Multi-voice podcast audio synthesized and published!');
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Audio synthesis failed.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Audio synthesis error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Manual Upload for Blocked Sources Form Submission
        // -------------------------------------------------------------------------
        $('#btn-process-upload').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var form = $('#presshub-manual-upload-form')[0];
            var formData = new FormData(form);

            formData.append('action', 'presshub_ai_briefing_upload');
            formData.append('nonce', nonce);
            formData.append('date', currentDate);

            var source = $('#upload-source-outlet').val();
            var title = $('#upload-article-title').val();
            var content = $('#upload-article-content').val();
            var filesInput = $('#upload-files-input')[0];
            var hasFiles = filesInput && filesInput.files && filesInput.files.length > 0;

            if (!$.trim(content) && !$.trim(title) && !hasFiles) {
                showNotice('error', 'Please enter text notes or attach files to upload.');
                return;
            }

            setButtonLoading($btn, true, null, i18n.uploading || 'Processing upload...');
            $('#upload-spinner').addClass('is-active');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    setButtonLoading($btn, false);
                    $('#upload-spinner').removeClass('is-active');
                    if (response.success) {
                        showNotice('success', 'Manual documents merged into today\'s briefing article pool!');
                        form.reset();
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Upload processing failed.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    $('#upload-spinner').removeClass('is-active');
                    showNotice('error', 'Upload error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Run Full Generation (Sequence)
        // -------------------------------------------------------------------------
        $('#btn-run-all').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            setButtonLoading($btn, true, null, 'Running full pipeline...');
            showNotice('info', 'Starting end-to-end briefing pipeline execution...');

            // Step 1: Harvest
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: { action: 'presshub_ai_briefing_run_harvest', nonce: nonce, date: currentDate },
                success: function(res1) {
                    if (!res1.success) {
                        setButtonLoading($btn, false);
                        showNotice('error', 'Step 1 (Scrape) failed: ' + res1.data);
                        return;
                    }
                    showNotice('info', 'Step 1 Complete. Curating text story...');

                    // Step 2: Curation
                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: { action: 'presshub_ai_briefing_run_curation', nonce: nonce, date: currentDate },
                        success: function(res2) {
                            if (!res2.success) {
                                setButtonLoading($btn, false);
                                showNotice('error', 'Step 2 (Curation) failed: ' + res2.data);
                                return;
                            }
                            showNotice('info', 'Step 2 Complete. Generating podcast script...');

                            // Step 3: Script
                            $.ajax({
                                url: ajaxUrl,
                                type: 'POST',
                                data: { action: 'presshub_ai_briefing_run_script', nonce: nonce, date: currentDate },
                                success: function(res3) {
                                    if (!res3.success) {
                                        setButtonLoading($btn, false);
                                        showNotice('error', 'Step 3 (Script) failed: ' + res3.data);
                                        return;
                                    }
                                    if (res3.data && res3.data.raw_script) {
                                        $('#presshub-briefing-script-editor').val(res3.data.raw_script);
                                    }
                                    showNotice('info', 'Step 3 Complete. Synthesizing multi-voice audio...');

                                    // Step 4: Audio Synthesis
                                    $.ajax({
                                        url: ajaxUrl,
                                        type: 'POST',
                                        data: { action: 'presshub_ai_briefing_generate_audio', nonce: nonce, date: currentDate, script: res3.data.raw_script || '' },
                                        success: function(res4) {
                                            setButtonLoading($btn, false);
                                            if (!res4.success) {
                                                showNotice('error', 'Step 4 (Audio) failed: ' + res4.data);
                                                return;
                                            }
                                            showNotice('success', '🎉 Full Briefing & AI Podcast Pipeline completed successfully!');
                                            fetchStatus(currentDate);
                                        },
                                        error: function() {
                                            setButtonLoading($btn, false);
                                            showNotice('error', 'Step 4 network error.');
                                        }
                                    });
                                },
                                error: function() {
                                    setButtonLoading($btn, false);
                                    showNotice('error', 'Step 3 network error.');
                                }
                            });
                        },
                        error: function() {
                            setButtonLoading($btn, false);
                            showNotice('error', 'Step 2 network error.');
                        }
                    });
                },
                error: function() {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Step 1 network error.');
                }
            });
        });

    });
})(jQuery);
