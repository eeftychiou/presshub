/**
 * PressHub AI — classic editor (post.php / post-new.php) admin JS.
 *
 * Drives the metabox buttons (Generate Initial Draft, Run AI Editorial Review)
 * and the Settings → PressHub AI "Test Connection" buttons. Strings are
 * translatable via the wp.i18n runtime that the PHP enqueue wires in.
 *
 * UI review batch (2026-08-16 Antigravity UI review):
 *   - no alert() dialogs — all feedback is inline WP .notice markup
 *     (ME-1 / F-01);
 *   - every AJAX call has a .fail() path that re-enables the UI
 *     (QW-1 / F-11);
 *   - client-side button validation before expensive AI calls
 *     (ME-2 / F-03);
 *   - ✓/✗ prefixed test-connection results (QW-5 / F-25);
 *   - color-coded scorecard with score badge (ME-4 / F-23);
 *   - explicit post-status notice after review (ME-8 / F-29);
 *   - draft preview/diff modal with Accept/Reject before insertion
 *     (LF-1 / F-02 + F-27).
 */
/* global wp, jQuery, presshubAI, tinymce */

jQuery(document).ready(function($) {
    'use strict';
    // NOTE: __ is scoped INSIDE this callback (function scope) on purpose.
    // A top-level `const { __ } = wp.i18n` in a classic script would create
    // a global binding that collides with any other script declaring __
    // ("Identifier '__' has already been declared" — SyntaxError kills the
    // whole file). Function scope is immune to that class of bug.
    const { __ } = wp.i18n;

    // ------------------------------------------------------------------
    // Small helpers.
    // ------------------------------------------------------------------

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

    // Current editor content (block editor or classic tinymce), as HTML.
    function getEditorContent() {
        if (wp.data && wp.data.select('core/editor')) {
            return wp.data.select('core/editor').getEditedPostAttribute('content') || '';
        }
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            return tinymce.activeEditor.getContent() || '';
        }
        return '';
    }

    // True when an HTML string contains any visible text.
    function hasVisibleText(html) {
        return String(html || '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/&nbsp;/gi, ' ')
            .trim() !== '';
    }

    // Strip HTML to plain text for diffing/previewing (keeps token
    // separation between block boundaries).
    function plainText(html) {
        return String(html || '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/&nbsp;/gi, ' ')
            .replace(/&amp;/gi, '&')
            .replace(/&lt;/gi, '<')
            .replace(/&gt;/gi, '>')
            .replace(/&quot;/gi, '"')
            .replace(/&#0?39;/gi, "'")
            .replace(/\s+/g, ' ')
            .trim();
    }

    // ------------------------------------------------------------------
    // Inline notices (ME-1 / F-01): a WP-styled .notice injected near the
    // originating control. No blocking dialogs anywhere.
    // ------------------------------------------------------------------

    // The notice zone for a given button: its enclosing metabox container,
    // or the settings test-result area on the Settings page.
    function noticeZone($btn) {
        var $container = $btn.closest('.presshub-ai-container');
        if ($container.length) {
            return $container;
        }
        var $result = $('#presshub-ai-test-result');
        if ($result.length) {
            return $result;
        }
        return $btn.parent();
    }

    function showNotice(message, type, $btn) {
        var $zone = noticeZone($btn);
        // A fresh notice replaces any older one in the same zone.
        $zone.find('.presshub-ai-notice').remove();

        var cssClass = 'notice-info';
        if (type === 'error') {
            cssClass = 'notice-error';
        } else if (type === 'success') {
            cssClass = 'notice-success';
        }

        var $notice = $(
            '<div class="notice ' + cssClass + ' presshub-ai-notice is-dismissible">' +
            '<p></p>' +
            '</div>'
        );
        $notice.find('p').text(message); // .text() — never raw HTML.
        var $dismiss = $(
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">' +
            presshubEsc(__('Dismiss this notice.', 'presshub-ai-editor')) +
            '</span></button>'
        );
        $dismiss.on('click', function(e) {
            e.preventDefault();
            $notice.remove();
        });
        $notice.append($dismiss);
        $zone.prepend($notice);
        return $notice;
    }

    // ------------------------------------------------------------------
    // Button validation (ME-2 / F-03). Server-side validation stays the
    // authority; these guards just stop obviously-empty requests from
    // burning an AI call.
    // ------------------------------------------------------------------

    function updateGenerateButton() {
        var $btn = $('#presshub-ai-generate-draft');
        var sources = $('#presshub-ai-sources').val() || '';
        var instructions = $('#presshub-ai-instructions').val() || '';
        var fileInput = document.getElementById('presshub-ai-files');
        var hasFiles = !!(fileInput && fileInput.files && fileInput.files.length > 0);
        $btn.prop('disabled', !(sources.trim() || instructions.trim() || hasFiles));
    }

    function updateReviewButton() {
        var $btn = $('#presshub-ai-run-review');
        // Review needs something to review: editor content or a scorecard
        // that already exists (server-rendered on page load).
        var hasContent = hasVisibleText(getEditorContent());
        var hasScorecard = $('#presshub-ai-scorecard-results .scorecard-box').length > 0;
        $btn.prop('disabled', !(hasContent || hasScorecard));
    }

    // ------------------------------------------------------------------
    // Draft preview/diff modal (LF-1 / F-02 + F-27). On generate success
    // the draft is NOT inserted; the journalist reviews a word-level diff
    // (LCS, no dependencies) against the current editor content and then
    // Accepts or Rejects. Keyboard accessible: Esc closes, focus is
    // trapped in the dialog, focus returns to the Generate button.
    // ------------------------------------------------------------------

    var presshubPendingDraft = null; // { html: original HTML to insert }
    var presshubModalReturnFocus = null;
    var presshubModalOpen = false;

    function tokenizeWords(text) {
        return text.match(/\S+\s*/g) || [];
    }

    // Lightweight LCS word diff. Returns HTML with <ins>/<del> spans.
    // Falls back to a plain preview when either side is too large to
    // diff client-side without freezing the editor tab.
    function wordDiffHtml(oldText, newText) {
        var MAX_TOKENS = 1500;
        var oldWords = tokenizeWords(oldText);
        var newWords = tokenizeWords(newText);

        if (oldWords.length === 0) {
            // Nothing to diff against — caller handles this, but be safe.
            return '';
        }
        if (oldWords.length > MAX_TOKENS || newWords.length > MAX_TOKENS) {
            return ''; // signal "too large" — caller shows plain preview.
        }

        var n = oldWords.length;
        var m = newWords.length;
        var lcs = [];
        var i, j;
        for (i = 0; i <= n; i++) {
            var row = [];
            for (j = 0; j <= m; j++) {
                row.push(0);
            }
            lcs.push(row);
        }
        for (i = n - 1; i >= 0; i--) {
            for (j = m - 1; j >= 0; j--) {
                if (oldWords[i] === newWords[j]) {
                    lcs[i][j] = lcs[i + 1][j + 1] + 1;
                } else {
                    lcs[i][j] = lcs[i + 1][j] > lcs[i][j + 1] ? lcs[i + 1][j] : lcs[i][j + 1];
                }
            }
        }

        var html = '';
        i = 0;
        j = 0;
        while (i < n && j < m) {
            if (oldWords[i] === newWords[j]) {
                html += presshubEsc(oldWords[i]);
                i++;
                j++;
            } else if (lcs[i + 1][j] >= lcs[i][j + 1]) {
                html += '<del>' + presshubEsc(oldWords[i]) + '</del>';
                i++;
            } else {
                html += '<ins>' + presshubEsc(newWords[j]) + '</ins>';
                j++;
            }
        }
        while (i < n) {
            html += '<del>' + presshubEsc(oldWords[i]) + '</del>';
            i++;
        }
        while (j < m) {
            html += '<ins>' + presshubEsc(newWords[j]) + '</ins>';
            j++;
        }
        return html;
    }

    function ensureDraftModal() {
        var $modal = $('#presshub-draft-modal');
        if ($modal.length) {
            return $modal;
        }

        $modal = $(
            '<div class="presshub-modal-backdrop" id="presshub-draft-modal" ' +
            'role="dialog" aria-modal="true" aria-labelledby="presshub-draft-modal-title" ' +
            'aria-describedby="presshub-draft-modal-desc" tabindex="-1">' +
            '<div class="presshub-modal">' +
            '<h2 id="presshub-draft-modal-title">' +
            presshubEsc(__('Preview AI draft', 'presshub-ai-editor')) +
            '</h2>' +
            '<p class="description presshub-modal-desc" id="presshub-draft-modal-desc">' +
            presshubEsc(__('Review what the AI wrote before inserting it. Green text is added, red strikethrough text would be replaced. Nothing is inserted until you choose Insert draft.', 'presshub-ai-editor')) +
            '</p>' +
            '<div class="presshub-diff" tabindex="0"></div>' +
            '<div class="presshub-modal-actions">' +
            '<button type="button" class="button button-primary" id="presshub-draft-accept">' +
            presshubEsc(__('Insert draft', 'presshub-ai-editor')) +
            '</button>' +
            '<button type="button" class="button" id="presshub-draft-reject">' +
            presshubEsc(__('Discard draft', 'presshub-ai-editor')) +
            '</button>' +
            '<button type="button" class="button button-link presshub-modal-cancel">' +
            presshubEsc(__('Cancel', 'presshub-ai-editor')) +
            '</button>' +
            '</div>' +
            '</div>' +
            '</div>'
        );

        $modal.on('click', function(e) {
            // Click on the backdrop (outside the dialog) closes it.
            if (e.target === $modal[0]) {
                closeDraftModal(false);
            }
        });
        $modal.find('#presshub-draft-accept').on('click', function() {
            acceptDraft();
        });
        $modal.find('#presshub-draft-reject').on('click', function() {
            closeDraftModal(true);
        });
        $modal.find('.presshub-modal-cancel').on('click', function() {
            closeDraftModal(false);
        });
        // Focus trap: Tab/Shift+Tab cycle inside the dialog.
        $modal.on('keydown', function(e) {
            if (e.key !== 'Tab') {
                return;
            }
            var $focusables = $modal.find(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            ).filter(':visible');
            if (!$focusables.length) {
                return;
            }
            var first = $focusables[0];
            var last = $focusables[$focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });

        $('body').append($modal);
        return $modal;
    }

    function openDraftModal(draftHtml, currentHtml, $returnFocus) {
        var $modal = ensureDraftModal();
        presshubPendingDraft = { html: draftHtml };
        presshubModalReturnFocus = $returnFocus || $('#presshub-ai-generate-draft');

        var oldPlain = plainText(currentHtml);
        var newPlain = plainText(draftHtml);
        var $diff = $modal.find('.presshub-diff');

        if (newPlain === '') {
            $diff.html('<p>' + presshubEsc(__('The AI returned an empty draft.', 'presshub-ai-editor')) + '</p>');
        } else if (oldPlain === '') {
            // No existing content — a plain preview is clearer than a
            // diff that is entirely <ins>.
            $diff.html('<p>' + presshubEsc(newPlain) + '</p>');
        } else {
            var diffHtml = wordDiffHtml(oldPlain, newPlain);
            if (diffHtml === '') {
                $diff.html('<p>' + presshubEsc(__('The draft is too large to diff in the browser — previewing the raw text instead.', 'presshub-ai-editor')) + '</p><p>' + presshubEsc(newPlain) + '</p>');
            } else {
                $diff.html(diffHtml);
            }
        }

        $modal.addClass('is-open');
        $('body').addClass('presshub-modal-open');
        presshubModalOpen = true;
        // Move focus into the dialog (first focusable = Insert draft).
        $modal.find('#presshub-draft-accept').trigger('focus');
    }

    function closeDraftModal(discarded) {
        if (!presshubModalOpen) {
            return;
        }
        var $modal = $('#presshub-draft-modal');
        var $returnFocus = presshubModalReturnFocus;
        presshubModalOpen = false;
        presshubPendingDraft = null;
        presshubModalReturnFocus = null;
        $modal.removeClass('is-open');
        $('body').removeClass('presshub-modal-open');
        if ($returnFocus && $returnFocus.length) {
            $returnFocus.trigger('focus');
        }
        if (discarded) {
            showNotice(
                __('Draft discarded — nothing was inserted.', 'presshub-ai-editor'),
                'info',
                $returnFocus
            );
        }
    }

    function insertDraft(draftHtml) {
        if (wp.data && wp.data.dispatch('core/editor')) {
            var currentBlocks = wp.data.select('core/editor').getBlocks();
            var newBlock = wp.blocks.createBlock('core/paragraph', { content: draftHtml });
            wp.data.dispatch('core/editor').insertBlocks([newBlock], currentBlocks.length);
        } else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.execCommand('mceInsertContent', false, draftHtml);
        }
    }

    function acceptDraft() {
        if (!presshubPendingDraft) {
            return;
        }
        var draftHtml = presshubPendingDraft.html;
        var $generate = presshubModalReturnFocus || $('#presshub-ai-generate-draft');
        insertDraft(draftHtml);
        presshubModalOpen = false;
        presshubPendingDraft = null;
        presshubModalReturnFocus = null;
        $('#presshub-draft-modal').removeClass('is-open');
        $('body').removeClass('presshub-modal-open');
        if ($generate.length) {
            $generate.trigger('focus');
        }
        showNotice(
            __('Draft inserted into the editor.', 'presshub-ai-editor'),
            'success',
            $generate
        );
        updateReviewButton();
    }

    // Esc closes the modal without inserting (LF-1 keyboard support).
    $(document).on('keydown', function(e) {
        if (presshubModalOpen && e.key === 'Escape') {
            e.preventDefault();
            closeDraftModal(false);
        }
    });

    // ------------------------------------------------------------------
    // Scorecard rendering (ME-4 / F-23): color-coded border + score
    // badge; the "Score: X/100" text stays the primary (accessible)
    // indicator.
    // ------------------------------------------------------------------

    function scorecardTone(score) {
        var s = parseInt(score, 10);
        if (isNaN(s)) {
            return '';
        }
        if (s < 50) {
            return 'presshub-score-low';
        }
        if (s < 80) {
            return 'presshub-score-mid';
        }
        return 'presshub-score-high';
    }

    function renderScorecard(score, feedback, $btn) {
        var scoreText = __('Score: %s/100', 'presshub-ai-editor')
            .replace('%s', presshubEsc(score));
        var tone = scorecardTone(score);
        var html = '<div class="scorecard-box ' + tone + '">' +
            '<span class="presshub-score-badge" aria-hidden="true">' +
            presshubEsc(score) + '/100</span>' +
            '<strong>' + scoreText + '</strong>' +
            '<p>' + presshubEsc(feedback) + '</p>' +
            '</div>';
        $('#presshub-ai-scorecard-results').html(html);
        // A fresh scorecard means there is something to review again.
        updateReviewButton();
        if ($btn) {
            showNotice(
                __('Review completed.', 'presshub-ai-editor'),
                'success',
                $btn
            );
        }
    }

    // ------------------------------------------------------------------
    // Test Connection buttons (Settings page).
    // ------------------------------------------------------------------

    $('.presshub-ai-test-api').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $('#presshub-ai-test-spinner').addClass('is-active');
        $('#presshub-ai-test-spinner .screen-reader-text')
            .text(__('Testing connection…', 'presshub-ai-editor'));
        $('#presshub-ai-test-result').text('').removeClass('presshub-test-ok presshub-test-fail');
        $btn.prop('disabled', true);

        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_test_api',
            nonce: presshubAI.nonce,
            provider: $btn.data('provider')
        }, function(response) {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $('#presshub-ai-test-spinner .screen-reader-text').text('');
            $btn.prop('disabled', false);
            var $result = $('#presshub-ai-test-result');
            if (response && response.success) {
                $result
                    .addClass('presshub-test-ok')
                    .text('\u2713 ' + __('Success! API is working.', 'presshub-ai-editor'));
            } else {
                $result
                    .addClass('presshub-test-fail')
                    .text('\u2717 ' + __('Error: ', 'presshub-ai-editor') + (response && response.data ? response.data : ''));
            }
        }).fail(function() {
            $('#presshub-ai-test-spinner').removeClass('is-active');
            $('#presshub-ai-test-spinner .screen-reader-text').text('');
            $btn.prop('disabled', false);
            $('#presshub-ai-test-result')
                .addClass('presshub-test-fail')
                .text('\u2717 ' + __('Request failed — check your connection.', 'presshub-ai-editor'));
        });
    });

    // ------------------------------------------------------------------
    // Generate Initial Draft.
    // ------------------------------------------------------------------

    $('#presshub-ai-generate-draft').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return; // Guard: validation is the first line of defence.
        }
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
        $('#presshub-ai-draft-spinner .screen-reader-text')
            .text(__('Generating draft…', 'presshub-ai-editor'));
        $btn.prop('disabled', true);

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $('#presshub-ai-draft-spinner').removeClass('is-active');
                $('#presshub-ai-draft-spinner .screen-reader-text').text('');
                $btn.prop('disabled', false);
                if (response && response.success) {
                    // LF-1: preview the draft in the diff modal instead of
                    // inserting it silently.
                    openDraftModal(
                        response.data.draft,
                        getEditorContent(),
                        $btn
                    );
                } else {
                    showNotice(
                        __('Error: ', 'presshub-ai-editor') + (response && response.data ? response.data : ''),
                        'error',
                        $btn
                    );
                }
            },
            error: function() {
                $('#presshub-ai-draft-spinner').removeClass('is-active');
                $('#presshub-ai-draft-spinner .screen-reader-text').text('');
                $btn.prop('disabled', false);
                showNotice(
                    __('Request failed — check your connection.', 'presshub-ai-editor'),
                    'error',
                    $btn
                );
            }
        });
    });

    // ------------------------------------------------------------------
    // Run AI Editorial Review.
    // ------------------------------------------------------------------

    $('#presshub-ai-run-review').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var postId = $btn.data('post-id');

        var content = getEditorContent();

        $('#presshub-ai-review-spinner').addClass('is-active');
        $('#presshub-ai-review-spinner .screen-reader-text')
            .text(__('Running review…', 'presshub-ai-editor'));
        $btn.prop('disabled', true);

        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_run_review',
            nonce: presshubAI.nonce,
            post_id: postId,
            content: content
        }, function(response) {
            $('#presshub-ai-review-spinner').removeClass('is-active');
            $('#presshub-ai-review-spinner .screen-reader-text').text('');
            $btn.prop('disabled', false);
            if (response && response.success) {
                renderScorecard(response.data.score, response.data.feedback, $btn);

                // ME-8 / F-29: tell the journalist what happened to the
                // post status, derived from the server response.
                var statusAfter = response.data.status_after;
                if (statusAfter === 'pending') {
                    showNotice(
                        __('Post moved to Pending review (score: %s/100).', 'presshub-ai-editor')
                            .replace('%s', presshubEsc(response.data.score)),
                        'info',
                        $btn
                    );
                } else if (statusAfter === 'publish') {
                    showNotice(
                        __('Post remains published.', 'presshub-ai-editor'),
                        'info',
                        $btn
                    );
                }
            } else {
                showNotice(
                    __('Error: ', 'presshub-ai-editor') + (response && response.data ? response.data : ''),
                    'error',
                    $btn
                );
            }
        }).fail(function() {
            $('#presshub-ai-review-spinner').removeClass('is-active');
            $('#presshub-ai-review-spinner .screen-reader-text').text('');
            $btn.prop('disabled', false);
            showNotice(
                __('Request failed — check your connection.', 'presshub-ai-editor'),
                'error',
                $btn
            );
        });
    });

    // ------------------------------------------------------------------
    // Wire up validation + initial button state.
    // ------------------------------------------------------------------

    $('#presshub-ai-sources, #presshub-ai-instructions').on('input', updateGenerateButton);
    $('#presshub-ai-files').on('change', updateGenerateButton);

    // Re-evaluate the Review button when editor content changes.
    if (wp.data && wp.data.subscribe) {
        wp.data.subscribe(updateReviewButton);
    } else if (typeof tinymce !== 'undefined') {
        if (tinymce.activeEditor) {
            tinymce.activeEditor.on('keyup', updateReviewButton);
        }
        tinymce.on('AddEditor', function(e) {
            e.editor.on('keyup', updateReviewButton);
        });
    }

    updateGenerateButton();
    updateReviewButton();

    // ------------------------------------------------------------------
    // Reset / Load Prompt Template (Settings Page).
    // ------------------------------------------------------------------
    $(document).on('click', '.presshub-reset-prompt', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        if (targetId) {
            $('#' + targetId).val('');
        }
    });

    $(document).on('click', '.presshub-show-default-prompt', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var defaultPrompt = $(this).data('default');
        if (targetId && defaultPrompt) {
            $('#' + targetId).val(defaultPrompt);
        }
    });

    // ------------------------------------------------------------------
    // Settings Page Tabs Organization.
    // ------------------------------------------------------------------
    function initSettingsTabs() {
        var $tabs = $('#presshub-ai-settings-tabs');
        if (!$tabs.length) {
            return;
        }

        var $form = $('#presshub-ai-settings-form');

        function switchTab(tabKey) {
            $tabs.find('.nav-tab').removeClass('nav-tab-active');
            $tabs.find('.nav-tab[data-tab="' + tabKey + '"]').addClass('nav-tab-active');

            $('.presshub-tab-pane').hide();
            $('#presshub-tab-pane-' + tabKey).show();

            if (tabKey === 'diagnostics') {
                $form.find('.presshub-settings-submit-wrap').hide();
                loadDiagnosticLogs();
            } else {
                $form.find('.presshub-settings-submit-wrap').show();
            }

            if (window.location.hash !== '#' + tabKey) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#' + tabKey);
                }
            }
        }

        $tabs.on('click', '.nav-tab', function(e) {
            e.preventDefault();
            var tabKey = $(this).data('tab');
            switchTab(tabKey);
        });

        var initialHash = (window.location.hash || '').replace('#', '');
        if (initialHash && $tabs.find('.nav-tab[data-tab="' + initialHash + '"]').length) {
            switchTab(initialHash);
        }
    }

    initSettingsTabs();

    // ------------------------------------------------------------------
    // Settings Page AJAX Save Handler.
    // ------------------------------------------------------------------
    $('#presshub-ai-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('#submit');
        var $spinner = $('#presshub-ai-save-spinner');

        $('.presshub-settings-notice').remove();
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        var settingsPayload = {};
        $form.find('input, select, textarea').each(function() {
            var $el = $(this);
            var name = $el.attr('name');
            if (!name || name === 'action' || name === 'option_page' || name === '_wp_http_referer' || name === '_wpnonce') {
                return;
            }
            if ($el.is(':checkbox')) {
                if ($el.is(':checked')) {
                    settingsPayload[name] = $el.val() || '1';
                }
            } else if ($el.is(':radio')) {
                if ($el.is(':checked')) {
                    settingsPayload[name] = $el.val();
                }
            } else {
                settingsPayload[name] = $el.val();
            }
        });

        var jsonString = JSON.stringify(settingsPayload);
        var base64Payload = '';
        try {
            if (typeof TextEncoder !== 'undefined') {
                var u8 = new TextEncoder().encode(jsonString);
                var binStr = '';
                for (var b = 0; b < u8.length; b++) {
                    binStr += String.fromCharCode(u8[b]);
                }
                base64Payload = window.btoa(binStr);
            } else {
                base64Payload = window.btoa(unescape(encodeURIComponent(jsonString)));
            }
        } catch (e) {
            base64Payload = '';
        }

        var ajaxData = {
            action: 'presshub_ai_save_settings',
            nonce: presshubAI.nonce
        };

        if (base64Payload) {
            ajaxData.payload_b64 = base64Payload;
        } else {
            ajaxData.payload = jsonString;
        }

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: ajaxData
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (res && res.success) {
                var successMsg = (res.data && res.data.message) ? res.data.message : 'Settings saved successfully.';
                var noticeHtml = '<div class="notice notice-success is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc(successMsg) + '</p></div>';
                $('#presshub-ai-settings-tabs').before(noticeHtml);

                if (res.data && res.data.masks) {
                    if (res.data.masks.api_key) {
                        $('#presshub_ai_api_key').attr('placeholder', res.data.masks.api_key);
                    }
                    if (res.data.masks.google_cloud_key) {
                        $('#presshub_ai_google_cloud_api_key').attr('placeholder', res.data.masks.google_cloud_key);
                    }
                    if (res.data.masks.github_token) {
                        $('#presshub_ai_github_token').attr('placeholder', res.data.masks.github_token);
                    }
                }
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to save settings.';
                var errHtml = '<div class="notice notice-error is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc(err) + '</p></div>';
                $('#presshub-ai-settings-tabs').before(errHtml);
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            var errorDetail = '';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorDetail = xhr.responseJSON.data.message;
            } else if (xhr.responseText) {
                var stripped = xhr.responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                errorDetail = stripped.substring(0, 400);
            } else {
                errorDetail = error || status || 'Unknown error';
            }
            var errHtml = '<div class="notice notice-error is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc('Error while saving (' + (xhr.status || 0) + '): ' + errorDetail) + '</p></div>';
            $('#presshub-ai-settings-tabs').before(errHtml);
        });
    });

    // ------------------------------------------------------------------
    // Diagnostic Log Viewer Handlers.
    // ------------------------------------------------------------------
    function loadDiagnosticLogs() {
        var $viewer  = $('#presshub-ai-log-viewer');
        var $spinner = $('#presshub-ai-log-spinner');
        var $status  = $('#presshub-ai-log-status');

        if (!$viewer.length) {
            return;
        }

        $spinner.addClass('is-active');
        $status.text('Loading logs...');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_get_logs',
                nonce: presshubAI.nonce
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success && res.data) {
                var content = res.data.logs || '(No log entries recorded yet)';
                $viewer.val(content);
                $viewer.scrollTop($viewer[0].scrollHeight);
                var sizeKb = (res.data.size_bytes / 1024).toFixed(1);
                $status.text('Level: ' + res.data.level + ' | File: ' + sizeKb + ' KB | Loaded: ' + new Date().toLocaleTimeString());
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to retrieve logs.';
                $status.text('Error: ' + err);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $status.text('Error loading logs (' + (xhr.status || 0) + ')');
        });
    }

    $(document).on('click', '#presshub-ai-refresh-logs', function(e) {
        e.preventDefault();
        loadDiagnosticLogs();
    });

    $(document).on('click', '#presshub-ai-clear-logs', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to clear the diagnostic log file?')) {
            return;
        }

        var $spinner = $('#presshub-ai-log-spinner');
        var $status  = $('#presshub-ai-log-status');
        var $viewer  = $('#presshub-ai-log-viewer');

        $spinner.addClass('is-active');
        $status.text('Clearing logs...');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_clear_logs',
                nonce: presshubAI.nonce
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success) {
                $viewer.val('');
                $status.text('Log file cleared.');
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to clear logs.';
                $status.text('Error: ' + err);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $status.text('Error clearing logs (' + (xhr.status || 0) + ')');
        });
    });
});

