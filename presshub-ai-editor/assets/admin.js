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
            $('#' + targetId).val('').trigger('input');
        }
    });

    $(document).on('click', '.presshub-show-default-prompt', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var defaultPrompt = $(this).data('default');
        if (targetId && defaultPrompt) {
            $('#' + targetId).val(defaultPrompt).trigger('input');
        }
    });

    // ------------------------------------------------------------------
    // Dirty-State Tracking & Unsaved-Changes Warning Engine (Issue #17)
    // ------------------------------------------------------------------
    var tabBaselines = {};
    var providerModalBaseline = null;

    /**
     * Compute a deterministic signature/snapshot of all form fields in a tab pane.
     *
     * @param {string} tabKey
     * @return {string}
     */
    function getTabFormSnapshot(tabKey) {
        var $tabPane = $('#presshub-tab-pane-' + tabKey);
        if (!$tabPane.length) {
            return '';
        }
        var fields = [];
        $tabPane.find('input, select, textarea').each(function() {
            var $el = $(this);
            var id = $el.attr('id') || '';
            var name = $el.attr('name') || id;

            // Exclude nonces, action markers, and read-only diagnostic log viewer
            if (!name || name === 'action' || name === 'option_page' || name === '_wp_http_referer' || name === '_wpnonce') {
                return;
            }
            if (id === 'presshub-ai-log-viewer' || $el.hasClass('presshub-no-dirty')) {
                return;
            }

            var type = $el.attr('type');
            if (type === 'checkbox') {
                fields.push(name + '=' + ($el.is(':checked') ? ($el.val() || '1') : '__UNCHECKED__'));
            } else if (type === 'radio') {
                if ($el.is(':checked')) {
                    fields.push(name + '=' + ($el.val() || ''));
                }
            } else {
                fields.push(name + '=' + ($el.val() || ''));
            }
        });
        return fields.join('&');
    }

    /**
     * Initialize baseline snapshots for all savable tab panes.
     */
    function initTabBaselines() {
        var savableTabs = ['coauthor', 'briefing', 'copilot', 'advanced'];
        savableTabs.forEach(function(key) {
            tabBaselines[key] = getTabFormSnapshot(key);
        });
        $('.presshub-tab-pane').each(function() {
            var paneId = $(this).attr('id') || '';
            if (paneId.indexOf('presshub-tab-pane-') === 0) {
                var tabKey = paneId.replace('presshub-tab-pane-', '');
                if (typeof tabBaselines[tabKey] === 'undefined') {
                    tabBaselines[tabKey] = getTabFormSnapshot(tabKey);
                }
            }
        });
    }

    /**
     * Check if a specific tab has unsaved changes.
     *
     * @param {string} tabKey
     * @return {boolean}
     */
    function isTabDirty(tabKey) {
        if (!tabKey || typeof tabBaselines[tabKey] === 'undefined') {
            return false;
        }
        return getTabFormSnapshot(tabKey) !== tabBaselines[tabKey];
    }

    /**
     * Check if any tab has unsaved changes.
     *
     * @return {boolean}
     */
    function isAnyTabDirty() {
        var keys = Object.keys(tabBaselines);
        for (var i = 0; i < keys.length; i++) {
            if (isTabDirty(keys[i])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Update visual dirty indicators across desktop nav tabs, mobile tab select, and sticky save bar.
     *
     * @param {string} tabKey
     */
    function updateTabDirtyState(tabKey) {
        if (!tabKey) {
            return;
        }
        var dirty = isTabDirty(tabKey);

        // 1. Desktop / Tablet Nav Tab
        var $navTab = $('#presshub-ai-settings-tabs .nav-tab[data-tab="' + tabKey + '"]');
        if ($navTab.length) {
            if (dirty) {
                $navTab.addClass('is-dirty');
            } else {
                $navTab.removeClass('is-dirty');
            }
        }

        // 2. Mobile Tab Select option
        var $mobileOption = $('#presshub-mobile-tab-select option[value="' + tabKey + '"]');
        if ($mobileOption.length) {
            var baseLabel = $mobileOption.data('base-label');
            if (!baseLabel) {
                baseLabel = $mobileOption.text().replace(/\s*•\s*\(unsaved\)$/i, '').replace(/\s*\*$/i, '').trim();
                $mobileOption.data('base-label', baseLabel);
            }
            if (dirty) {
                $mobileOption.text(baseLabel + ' • (' + __('unsaved', 'presshub-ai-editor') + ')').addClass('is-dirty');
            } else {
                $mobileOption.text(baseLabel).removeClass('is-dirty');
            }
        }

        // 3. Mobile Select element dirty indicator
        var $mobileSelect = $('#presshub-mobile-tab-select');
        if ($mobileSelect.length) {
            if (isAnyTabDirty()) {
                $mobileSelect.addClass('is-dirty');
            } else {
                $mobileSelect.removeClass('is-dirty');
            }
        }

        // 4. Update sticky save bar
        updateStickySaveBarDirtyState();
    }

    /**
     * Update sticky save bar dirty indicators based on the currently active tab.
     */
    function updateStickySaveBarDirtyState() {
        var $stickyBar = $('#presshub-sticky-save-bar');
        if (!$stickyBar.length) {
            return;
        }
        var activeTab = $stickyBar.attr('data-active-tab') ||
                        $('#presshub-ai-settings-tabs .nav-tab-active').data('tab') ||
                        $('#presshub-mobile-tab-select').val() ||
                        '';

        var activeDirty = isTabDirty(activeTab);
        var $unsavedBadge = $('#presshub-sticky-unsaved-badge');

        if (activeDirty) {
            $stickyBar.addClass('has-unsaved');
            if ($unsavedBadge.length) {
                $unsavedBadge.show();
            }
        } else {
            $stickyBar.removeClass('has-unsaved');
            if ($unsavedBadge.length) {
                $unsavedBadge.hide();
            }
        }
    }

    /**
     * Compute a deterministic signature of all form fields in the provider modal.
     *
     * @return {string}
     */
    function getProviderModalSnapshot() {
        var $form = $('#presshub-provider-form');
        if (!$form.length) {
            return '';
        }
        var fields = [];
        $form.find('input, select, textarea').each(function() {
            var $el = $(this);
            var name = $el.attr('name') || $el.attr('id') || '';
            if (!name) {
                return;
            }
            var type = $el.attr('type');
            if (type === 'checkbox') {
                fields.push(name + '=' + ($el.is(':checked') ? '1' : '0'));
            } else if (type === 'radio') {
                if ($el.is(':checked')) {
                    fields.push(name + '=' + ($el.val() || ''));
                }
            } else {
                fields.push(name + '=' + ($el.val() || ''));
            }
        });
        return fields.join('&');
    }

    /**
     * Check if provider modal has unsaved changes.
     *
     * @return {boolean}
     */
    function isProviderModalDirty() {
        if (!$('#presshub-provider-modal').is(':visible') || providerModalBaseline === null) {
            return false;
        }
        return getProviderModalSnapshot() !== providerModalBaseline;
    }

    /**
     * Attempt to close provider modal, displaying an inline discard confirmation notice if dirty.
     *
     * @param {boolean} force
     * @return {boolean}
     */
    function tryCloseProviderModal(force) {
        if (!force && isProviderModalDirty()) {
            var $notice = $('#presshub-provider-discard-notice');
            if ($notice.length) {
                $notice.slideDown(150);
            }
            return false;
        }
        $('#presshub-provider-discard-notice').hide();
        providerModalBaseline = null;
        closeProviderModal();
        return true;
    }

    // ------------------------------------------------------------------
    // Settings Page Tabs Organization.
    // ------------------------------------------------------------------
    // Settings Tabs Navigation with Modular Mapping & Dynamic Dashboards
    // ------------------------------------------------------------------
    function initSettingsTabs() {
        var $tabs = $('#presshub-ai-settings-tabs');
        var $mobileSelect = $('#presshub-mobile-tab-select');
        if (!$tabs.length && !$mobileSelect.length) {
            return;
        }

        var $form = $('#presshub-ai-settings-form');
        var tabAliases = {
            'general': 'coauthor',
            'media': 'advanced',
            'rate_limits': 'advanced',
            'diagnostics': 'advanced'
        };

        function switchTab(tabKey) {
            if (tabAliases[tabKey]) {
                tabKey = tabAliases[tabKey];
            }

            // Sync desktop/tablet tabs & accessibility states
            $tabs.find('.nav-tab').removeClass('nav-tab-active').attr('aria-selected', 'false');
            var $activeTab = $tabs.find('.nav-tab[data-tab="' + tabKey + '"]');
            $activeTab.addClass('nav-tab-active').attr('aria-selected', 'true');

            // Sync mobile select dropdown
            if ($mobileSelect.length && $mobileSelect.val() !== tabKey) {
                $mobileSelect.val(tabKey);
            }

            // Toggle tab pane visibility
            $('.presshub-tab-pane').hide();
            $('#presshub-tab-pane-' + tabKey).show();

            // Form Submit Button visibility (per-tab AJAX save buttons used in JS)
            $('#presshub-settings-submit-wrap').hide();

            if (tabKey === 'token_logs') {
                loadTokenLogs(1);
                loadAuditLogs(1);
            } else if (tabKey === 'advanced') {
                loadDiagnosticLogs();
            }

            // Scroll active tab into view in horizontal scrolling container
            if ($activeTab.length && typeof $activeTab[0].scrollIntoView === 'function') {
                try {
                    $activeTab[0].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
                } catch (e) {
                    // Fallback
                }
            }

            updateStickySaveBar(tabKey);
            updateStickySaveBarDirtyState();

            if (window.location.hash !== '#' + tabKey) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#' + tabKey);
                }
            }
        }

        function updateStickySaveBar(tabKey) {
            var $stickyBar = $('#presshub-sticky-save-bar');
            if (!$stickyBar.length) {
                return;
            }
            var savableTabs = {
                'coauthor': __('AI Co-Author & Review', 'presshub-ai-editor'),
                'briefing': __('Daily Briefing Hub', 'presshub-ai-editor'),
                'copilot': __('AI Copilot & Assistant', 'presshub-ai-editor'),
                'advanced': __('Advanced & System', 'presshub-ai-editor')
            };

            if (savableTabs[tabKey]) {
                var $activeNavTab = $tabs.find('.nav-tab[data-tab="' + tabKey + '"]');
                var tabLabel = ($activeNavTab.length && $activeNavTab.text()) ? $activeNavTab.text().trim() : savableTabs[tabKey];
                $('#presshub-sticky-section-badge').text(tabLabel);
                $stickyBar.attr('data-active-tab', tabKey);
                $('#presshub-sticky-status-msg').text('').removeClass('is-success is-error');
                $stickyBar.fadeIn(150);
            } else {
                $stickyBar.fadeOut(150);
            }
            updateStickySaveBarDirtyState();
        }

        $tabs.on('click', '.nav-tab', function(e) {
            e.preventDefault();
            var tabKey = $(this).data('tab');
            switchTab(tabKey);
        });

        $(document).on('change', '#presshub-mobile-tab-select', function(e) {
            var tabKey = $(this).val();
            if (tabKey) {
                switchTab(tabKey);
            }
        });

        $(window).on('hashchange', function() {
            var hash = (window.location.hash || '').replace('#', '');
            if (hash) {
                var targetTab = tabAliases[hash] || hash;
                if ($tabs.find('.nav-tab[data-tab="' + targetTab + '"]').length || $mobileSelect.find('option[value="' + targetTab + '"]').length) {
                    switchTab(targetTab);
                }
            }
        });

        var initialHash = (window.location.hash || '').replace('#', '');
        if (initialHash) {
            var targetTab = tabAliases[initialHash] || initialHash;
            if ($tabs.find('.nav-tab[data-tab="' + targetTab + '"]').length || $mobileSelect.find('option[value="' + targetTab + '"]').length) {
                switchTab(targetTab);
            }
        } else {
            // Ensure default active tab is in sync
            var defaultTab = $tabs.find('.nav-tab-active').data('tab') || 'providers';
            if ($mobileSelect.length) {
                $mobileSelect.val(defaultTab);
            }
            updateStickySaveBar(defaultTab);
        }

        // Initialize baseline snapshots and bind live input/change/keyup listeners
        initTabBaselines();
        updateStickySaveBarDirtyState();

        $(document).on('input change keyup', '#presshub-ai-settings-form input, #presshub-ai-settings-form select, #presshub-ai-settings-form textarea', function() {
            var $pane = $(this).closest('.presshub-tab-pane');
            if ($pane.length && $pane.attr('id')) {
                var tabKey = $pane.attr('id').replace('presshub-tab-pane-', '');
                updateTabDirtyState(tabKey);
            }
        });
    }

    initSettingsTabs();

    // Register beforeunload handler for unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (isAnyTabDirty() || isProviderModalDirty()) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    // ------------------------------------------------------------------
    // Dynamic AI Providers Manager Logic
    // ------------------------------------------------------------------
    var providerTemplates = (typeof presshubAI !== 'undefined' && presshubAI.provider_templates) ? presshubAI.provider_templates : {};
    var configuredProviders = (typeof presshubAI !== 'undefined' && presshubAI.configured_providers) ? presshubAI.configured_providers : [];

    // Mask an API key string for safe display and preview
    function maskApiKey(key) {
        if (!key || typeof key !== 'string') {
            return '';
        }
        key = key.trim();
        var len = key.length;
        if (len === 0) {
            return '';
        }
        if (len >= 10) {
            var prefix = '';
            var knownPrefixes = [
                'sk-proj-',
                'sk-admin-',
                'sk-ant-api03-',
                'sk-ant-',
                'github_pat_',
                'ghp_',
                'gsk_',
                'AIzaSy',
                'AIza',
                'nvapi-',
                'xai-',
                'ms-',
                'sk-'
            ];
            for (var i = 0; i < knownPrefixes.length; i++) {
                var pfx = knownPrefixes[i];
                if (key.indexOf(pfx) === 0 && (len - pfx.length >= 4)) {
                    prefix = pfx;
                    break;
                }
            }
            if (!prefix) {
                var match = key.match(/^([a-zA-Z0-9_\.]{2,16}[-_])/);
                if (match && (len - match[1].length >= 4)) {
                    prefix = match[1];
                } else if (/^AQ\.[a-zA-Z0-9]{2}/.test(key)) {
                    prefix = key.substring(0, 5);
                } else {
                    prefix = key.substring(0, 4);
                }
            }
            return prefix + '••••••••' + key.substring(len - 4);
        } else if (len >= 6) {
            return key.substring(0, 2) + '••••' + key.substring(len - 2);
        } else {
            return '••••';
        }
    }

    function populateModelDropdown(modelsList, selectedModel) {
        var $select = $('#provider-form-default-model');
        $select.empty();

        var models = [];
        if (Array.isArray(modelsList)) {
            models = modelsList.slice();
        } else if (typeof modelsList === 'string' && modelsList.trim()) {
            // Split on commas, newlines (\n or \r), and runs of whitespace between
            // entries. Using `new RegExp(...)` instead of a `/.../` literal so the
            // pattern can be authored on a single line; a `/[` literal whose body
            // contains a real newline is a SyntaxError (Issue #14).
            models = modelsList.split(new RegExp('\\s*,\\s*|[\\r\\n]+\\s*', 'g'))
                .map(function(m) { return m.trim(); })
                .filter(Boolean);
        }

        if (selectedModel && models.indexOf(selectedModel) === -1) {
            models.unshift(selectedModel);
        }

        if (models.length === 0) {
            models = selectedModel ? [selectedModel] : ['default'];
        }

        var uniqueModels = [];
        models.forEach(function(m) {
            if (m && uniqueModels.indexOf(m) === -1) {
                uniqueModels.push(m);
            }
        });

        uniqueModels.forEach(function(m) {
            var $opt = $('<option></option>').attr('value', m).text(m);
            if (m === selectedModel) {
                $opt.prop('selected', true);
            }
            $select.append($opt);
        });

        if (selectedModel) {
            $select.val(selectedModel);
        } else if (uniqueModels.length > 0) {
            $select.val(uniqueModels[0]);
        }

        $('#provider-form-available-models').val(uniqueModels.join(', '));
        $('#provider-form-manual-model').val($select.val() || selectedModel || '');
    }

    function openProviderModal(providerData) {
        var $modal = $('#presshub-provider-modal');
        var $title = $('#presshub-provider-modal-title');
        var $notice = $('#presshub-provider-form-notice');
        $notice.empty();

        if (providerData) {
            $title.text(__('Edit AI Provider: ', 'presshub-ai-editor') + (providerData.name || providerData.id));
            $('#provider-form-id').val(providerData.id || '');
            $('#provider-form-name').val(providerData.name || '');
            $('#provider-form-type').val(providerData.type || 'openai');
            $('#provider-form-base-url').val(providerData.base_url || '');

            var defaultModel = providerData.default_model || '';
            var availModels = providerData.available_models || (providerTemplates[providerData.type] ? providerTemplates[providerData.type].available_models : [defaultModel]);
            populateModelDropdown(availModels, defaultModel);

            $('#provider-form-toggle-manual').prop('checked', false);
            $('#provider-model-select-wrap').show();
            $('#provider-model-manual-wrap').hide();

            $('#provider-form-temperature').val(providerData.temperature !== undefined ? providerData.temperature : 0.7);
            $('#provider-form-max-tokens').val(providerData.max_tokens !== undefined ? providerData.max_tokens : 16384);
            $('#provider-form-timeout').val(providerData.timeout !== undefined ? providerData.timeout : 300);
            var headers = providerData.headers ? (typeof providerData.headers === 'object' ? JSON.stringify(providerData.headers) : providerData.headers) : '';
            $('#provider-form-headers').val(headers);
            $('#provider-form-enabled').prop('checked', !!providerData.enabled);

            var maskedKey = providerData.masked_key || providerData.masked_api_key || (providerData.api_key ? (providerData.api_key.indexOf('•') !== -1 ? providerData.api_key : maskApiKey(providerData.api_key)) : '');
            if (providerData.type === 'ollama_local') {
                $('#provider-form-api-key').val('').attr('placeholder', __('Not required for local Ollama', 'presshub-ai-editor'));
            } else if (maskedKey) {
                $('#provider-form-api-key').val('').attr('placeholder', maskedKey);
            } else {
                $('#provider-form-api-key').val('').attr('placeholder', __('Enter API Key', 'presshub-ai-editor'));
            }
            $('#provider-form-template').val('');
        } else {
            $title.text(__('Add New AI Provider', 'presshub-ai-editor'));
            $('#presshub-provider-form')[0].reset();
            $('#provider-form-id').val('');
            $('#provider-form-enabled').prop('checked', true);
            $('#provider-form-temperature').val('0.7');
            $('#provider-form-max-tokens').val('16384');
            $('#provider-form-timeout').val('300');
            $('#provider-form-api-key').attr('placeholder', __('Enter API Key', 'presshub-ai-editor'));

            var firstTmplKey = Object.keys(providerTemplates)[0] || 'openai';
            var tmpl = providerTemplates[firstTmplKey] || {};
            populateModelDropdown(tmpl.available_models || [], tmpl.default_model || '');
            $('#provider-form-toggle-manual').prop('checked', false);
            $('#provider-model-select-wrap').show();
            $('#provider-model-manual-wrap').hide();
        }

        $modal.show().addClass('is-open');
        $('body').addClass('presshub-modal-open');
        $('#presshub-provider-discard-notice').hide();
        providerModalBaseline = getProviderModalSnapshot();
    }

    function closeProviderModal() {
        var $modal = $('#presshub-provider-modal');
        $modal.hide().removeClass('is-open');
        $('body').removeClass('presshub-modal-open');
    }

    function closeLogDetailsModal() {
        var $modal = $('#presshub-log-details-modal');
        $modal.hide().removeClass('is-open');
        $('body').removeClass('presshub-modal-open');
    }

    // Modal Trigger Buttons
    $(document).on('click', '#presshub-add-provider-btn', function(e) {
        e.preventDefault();
        openProviderModal(null);
    });

    $(document).on('click', '.presshub-modal-close, .presshub-modal-cancel', function(e) {
        e.preventDefault();
        if ($(this).closest('#presshub-provider-modal').length) {
            tryCloseProviderModal(false);
        } else {
            closeLogDetailsModal();
        }
    });

    $(document).on('click', '#presshub-provider-modal, #presshub-log-details-modal', function(e) {
        if ($(e.target).is('#presshub-provider-modal')) {
            tryCloseProviderModal(false);
        }
        if ($(e.target).is('#presshub-log-details-modal')) {
            closeLogDetailsModal();
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            if ($('#presshub-provider-modal').is(':visible')) {
                tryCloseProviderModal(false);
            }
            if ($('#presshub-log-details-modal').is(':visible')) {
                closeLogDetailsModal();
            }
        }
    });

    $(document).on('click', '.presshub-provider-discard-confirm-btn', function(e) {
        e.preventDefault();
        tryCloseProviderModal(true);
    });

    $(document).on('click', '.presshub-provider-discard-cancel-btn', function(e) {
        e.preventDefault();
        $('#presshub-provider-discard-notice').slideUp(150);
    });

    // Preset Template Selection Auto-fill
    $(document).on('change', '#provider-form-template', function() {
        var tmplKey = $(this).val();
        if (!tmplKey || !providerTemplates[tmplKey]) {
            return;
        }
        var tmpl = providerTemplates[tmplKey];
        var currentName = $('#provider-form-name').val().trim();
        if (!currentName || Object.values(providerTemplates).some(function(t) { return t.name === currentName; })) {
            $('#provider-form-name').val(tmpl.name);
        }
        $('#provider-form-type').val(tmpl.type || tmplKey);
        $('#provider-form-base-url').val(tmpl.base_url || '');

        populateModelDropdown(tmpl.available_models || [], tmpl.default_model || '');
        $('#provider-form-toggle-manual').prop('checked', false);
        $('#provider-model-select-wrap').show();
        $('#provider-model-manual-wrap').hide();

        $('#provider-form-temperature').val(tmpl.temperature !== undefined ? tmpl.temperature : 0.7);
        $('#provider-form-max-tokens').val(tmpl.max_tokens !== undefined ? tmpl.max_tokens : 16384);
        $('#provider-form-timeout').val(tmpl.timeout !== undefined ? tmpl.timeout : 300);
    });

    // Provider Type Change Handler
    $(document).on('change', '#provider-form-type', function() {
        var provType = $(this).val();
        if (providerTemplates[provType]) {
            var tmpl = providerTemplates[provType];
            if (!$('#provider-form-base-url').val()) {
                $('#provider-form-base-url').val(tmpl.base_url || '');
            }
            var curModel = $('#provider-form-default-model').val();
            populateModelDropdown(tmpl.available_models || [], curModel || tmpl.default_model || '');
        }
    });

    // Manual Model Toggle Handler
    $(document).on('change', '#provider-form-toggle-manual', function() {
        var isManual = $(this).is(':checked');
        if (isManual) {
            var currentSelectVal = $('#provider-form-default-model').val() || '';
            $('#provider-form-manual-model').val(currentSelectVal);
            $('#provider-model-select-wrap').hide();
            $('#provider-model-manual-wrap').show();
            $('#provider-form-manual-model').trigger('focus');
        } else {
            var manualVal = $('#provider-form-manual-model').val().trim();
            if (manualVal) {
                populateModelDropdown($('#provider-form-available-models').val(), manualVal);
            }
            $('#provider-model-manual-wrap').hide();
            $('#provider-model-select-wrap').show();
        }
    });

    // Model Dropdown Change Sync
    $(document).on('change', '#provider-form-default-model', function() {
        $('#provider-form-manual-model').val($(this).val());
    });

    // Dynamic Fetch Models from API Handler
    $(document).on('click', '#provider-form-fetch-models', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#provider-form-fetch-spinner');
        var $notice = $('#presshub-provider-form-notice');

        var providerData = {
            id: $('#provider-form-id').val().trim(),
            name: $('#provider-form-name').val().trim(),
            type: $('#provider-form-type').val(),
            base_url: $('#provider-form-base-url').val().trim(),
            api_key: $('#provider-form-api-key').val().trim(),
            headers: $('#provider-form-headers').val().trim()
        };

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $notice.empty();

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_fetch_provider_models',
                nonce: presshubAI.nonce,
                provider_data: JSON.stringify(providerData)
            }
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (res && res.success && res.data && Array.isArray(res.data.models)) {
                var currentModel = $('#provider-form-default-model').val() || $('#provider-form-manual-model').val();
                populateModelDropdown(res.data.models, currentModel);

                $('#provider-form-toggle-manual').prop('checked', false);
                $('#provider-model-select-wrap').show();
                $('#provider-model-manual-wrap').hide();

                $notice.html('<div class="notice notice-success"><p>✓ ' + presshubEsc(sprintf(__('Successfully fetched %d models from provider API.', 'presshub-ai-editor'), res.data.models.length)) + '</p></div>');
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : __('Failed to fetch models from provider API.', 'presshub-ai-editor');
                $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(msg) + '</p></div>');
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(__('Error fetching models: ', 'presshub-ai-editor') + (error || status)) + '</p></div>');
        });
    });

    // Edit Provider Card Click
    $(document).on('click', '.presshub-edit-provider-btn', function(e) {
        e.preventDefault();
        var providerId = $(this).data('provider-id');
        var providerRecord = configuredProviders.find(function(p) { return p.id === providerId; });
        var $card = $(this).closest('.presshub-provider-card');
        if (!providerRecord) {
            // Read from DOM card attributes if not found in memory
            providerRecord = {
                id: providerId,
                name: $card.find('.presshub-card-name').text().trim(),
                type: $card.data('provider-type') || 'openai',
                enabled: $card.hasClass('is-enabled'),
                masked_key: $card.data('provider-masked-key') || ''
            };
        } else if (!providerRecord.masked_key && $card.length && $card.data('provider-masked-key')) {
            providerRecord.masked_key = $card.data('provider-masked-key');
        }
        openProviderModal(providerRecord);
    });

    // Save Provider AJAX Handler
    $(document).on('click', '#presshub-provider-form-save', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#presshub-provider-form-spinner');
        var $notice = $('#presshub-provider-form-notice');

        var name = $('#provider-form-name').val().trim();
        if (!name) {
            $notice.html('<div class="notice notice-error"><p>' + presshubEsc(__('Provider name is required.', 'presshub-ai-editor')) + '</p></div>');
            return;
        }

        var isManual = $('#provider-form-toggle-manual').is(':checked');
        var defaultModel = isManual ? $('#provider-form-manual-model').val().trim() : $('#provider-form-default-model').val();
        if (!defaultModel) {
            $notice.html('<div class="notice notice-error"><p>' + presshubEsc(__('Model is required.', 'presshub-ai-editor')) + '</p></div>');
            return;
        }

        var availableModels = [];
        $('#provider-form-default-model option').each(function() {
            var val = $(this).val();
            if (val && availableModels.indexOf(val) === -1) {
                availableModels.push(val);
            }
        });
        if (availableModels.indexOf(defaultModel) === -1) {
            availableModels.unshift(defaultModel);
        }

        var providerData = {
            id: $('#provider-form-id').val().trim(),
            name: name,
            type: $('#provider-form-type').val(),
            base_url: $('#provider-form-base-url').val().trim(),
            api_key: $('#provider-form-api-key').val().trim(),
            default_model: defaultModel,
            available_models: availableModels,
            temperature: parseFloat($('#provider-form-temperature').val()) || 0.7,
            max_tokens: parseInt($('#provider-form-max-tokens').val(), 10) || 16384,
            timeout: parseInt($('#provider-form-timeout').val(), 10) || 300,
            headers: $('#provider-form-headers').val().trim(),
            enabled: $('#provider-form-enabled').is(':checked') ? 1 : 0
        };

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $notice.empty();

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_save_provider',
                nonce: presshubAI.nonce,
                provider_data: JSON.stringify(providerData)
            }
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (res && res.success) {
                providerModalBaseline = null;
                $notice.html('<div class="notice notice-success"><p>' + presshubEsc(res.data.message || __('Provider saved.', 'presshub-ai-editor')) + '</p></div>');
                setTimeout(function() {
                    closeProviderModal();
                    window.location.reload();
                }, 750);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : __('Failed to save provider.', 'presshub-ai-editor');
                $notice.html('<div class="notice notice-error"><p>' + presshubEsc(msg) + '</p></div>');
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $notice.html('<div class="notice notice-error"><p>' + presshubEsc(__('Error saving provider: ', 'presshub-ai-editor') + (error || status)) + '</p></div>');
        });
    });

    // Delete Provider AJAX Handler
    $(document).on('click', '.presshub-delete-provider-btn', function(e) {
        e.preventDefault();
        var providerId = $(this).data('provider-id');
        var providerName = $(this).data('provider-name') || providerId;

        if (!confirm(__('Are you sure you want to delete the provider: ', 'presshub-ai-editor') + providerName + '?')) {
            return;
        }

        var $card = $(this).closest('.presshub-provider-card');
        var $spinner = $card.find('.presshub-card-spinner');
        $spinner.addClass('is-active');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_delete_provider',
                nonce: presshubAI.nonce,
                provider_id: providerId
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success) {
                $card.fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : __('Failed to delete provider.', 'presshub-ai-editor');
                showNotice(msg, 'error', $(this));
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            showNotice(__('Error deleting provider: ', 'presshub-ai-editor') + (error || status), 'error', $(this));
        });
    });

    // Test Connection Button in Card & Modal
    $(document).on('click', '.presshub-test-provider-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var providerId = $btn.data('provider-id');
        var $card = $btn.closest('.presshub-provider-card');
        var $spinner = $card.find('.presshub-card-spinner');
        var $result = $card.find('.presshub-card-test-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.hide().empty();

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_test_provider',
                nonce: presshubAI.nonce,
                provider_id: providerId
            }
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            if (res && res.success) {
                var latency = res.data.latency_ms ? ' (' + res.data.latency_ms + ' ms)' : '';
                $result.html('<span style="color: #00a32a; font-weight: 600;">✓ ' + presshubEsc(res.data.message || 'Connected') + latency + '</span>').fadeIn();
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Connection failed';
                $result.html('<span style="color: #d63638; font-weight: 600;">✗ ' + presshubEsc(msg) + '</span>').fadeIn();
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $result.html('<span style="color: #d63638; font-weight: 600;">✗ ' + presshubEsc(error || status || 'Error') + '</span>').fadeIn();
        });
    });

    $(document).on('click', '#presshub-provider-form-test', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#presshub-provider-form-spinner');
        var $notice = $('#presshub-provider-form-notice');

        var isManual = $('#provider-form-toggle-manual').is(':checked');
        var defaultModel = isManual ? $('#provider-form-manual-model').val().trim() : $('#provider-form-default-model').val();

        var providerData = {
            id: $('#provider-form-id').val().trim(),
            name: $('#provider-form-name').val().trim(),
            type: $('#provider-form-type').val(),
            base_url: $('#provider-form-base-url').val().trim(),
            api_key: $('#provider-form-api-key').val().trim(),
            default_model: defaultModel,
            timeout: parseInt($('#provider-form-timeout').val(), 10) || 300,
            headers: $('#provider-form-headers').val().trim()
        };

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $notice.empty();

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_test_provider',
                nonce: presshubAI.nonce,
                provider_data: JSON.stringify(providerData)
            }
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            if (res && res.success) {
                var latency = res.data.latency_ms ? ' (' + res.data.latency_ms + ' ms)' : '';
                $notice.html('<div class="notice notice-success"><p>✓ ' + presshubEsc(res.data.message || 'Connected') + latency + '</p></div>');
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Connection failed';
                $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(msg) + '</p></div>');
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(error || status || 'Error') + '</p></div>');
        });
    });

    // ------------------------------------------------------------------
    // News Source Manager (Issue #6)
    // ------------------------------------------------------------------
    var presshubSourcesState = [];

    function initSourcesState() {
        var raw = $('#presshub_ai_briefing_sources').val();
        if (!raw) {
            presshubSourcesState = [];
            return;
        }
        try {
            var parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                presshubSourcesState = parsed;
            } else if (typeof parsed === 'object' && parsed !== null) {
                presshubSourcesState = Object.values(parsed);
            }
        } catch (e) {
            var lines = raw.split(/\r?\n/).map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
            presshubSourcesState = lines.map(function(line) {
                var host = '';
                try {
                    var u = new URL(line);
                    host = u.hostname.replace(/^www\./i, '');
                } catch(err) {
                    host = line;
                }
                return {
                    id: 'src_' + Math.random().toString(36).substr(2, 9),
                    name: host ? (host.charAt(0).toUpperCase() + host.slice(1)) : line,
                    url: line,
                    type: 'text_news',
                    enabled: true,
                    category: 'General',
                    max_articles: 5,
                    notes: ''
                };
            });
        }
    }

    initSourcesState();

    function syncSourcesInput() {
        $('#presshub_ai_briefing_sources').val(JSON.stringify(presshubSourcesState)).trigger('input');
    }

    function renderSourcesTable() {
        var $tbody = $('#presshub-sources-tbody');
        if (!$tbody.length) {
            return;
        }
        $tbody.empty();

        var totalCount = presshubSourcesState.length;
        var activeCount = 0;

        if (totalCount === 0) {
            $tbody.append('<tr class="presshub-sources-empty-row"><td colspan="6" style="text-align: center; color: #646970; padding: 20px;">No news sources configured yet. Click "+ Add News Source" to add one.</td></tr>');
            $('#presshub-sources-count-badge').text('0 Sources (0 Active)');
            return;
        }

        var mediaTypesMeta = {
            'text_news': { label: 'News Website (HTML)', badgeClass: 'badge-active', icon: 'dashicons-admin-site-alt3' },
            'rss_feed': { label: 'RSS / Atom Feed', badgeClass: 'badge-active', icon: 'dashicons-rss' },
            'youtube': { label: 'YouTube Channel / Playlist', badgeClass: 'badge-planned', icon: 'dashicons-video-alt3' },
            'vlog': { label: 'Video / Vlog Feed', badgeClass: 'badge-planned', icon: 'dashicons-video-alt' },
            'podcast_audio': { label: 'Audio Podcast / RSS', badgeClass: 'badge-planned', icon: 'dashicons-format-audio' }
        };

        presshubSourcesState.forEach(function(src) {
            var isEnabled = (src.enabled !== false && src.enabled !== 0 && src.enabled !== '0' && src.enabled !== 'false');
            if (isEnabled) {
                activeCount++;
            }
            var typeKey = src.type || 'text_news';
            var meta = mediaTypesMeta[typeKey] || mediaTypesMeta['text_news'];
            var statusPill = isEnabled ? 'pill-active' : 'pill-inactive';
            var statusText = isEnabled ? 'Active' : 'Disabled';
            var toggleTitle = isEnabled ? 'Click to disable source' : 'Click to enable source';
            var maxArticles = src.max_articles ? Math.max(1, Math.min(30, parseInt(src.max_articles, 10))) : 5;

            var rowHtml = '<tr class="presshub-source-row ' + (isEnabled ? '' : 'is-disabled') + '" data-id="' + presshubEsc(src.id) + '">' +
                '<td style="text-align: center; vertical-align: middle;">' +
                    '<button type="button" class="presshub-source-toggle-status presshub-status-pill ' + statusPill + '" title="' + presshubEsc(toggleTitle) + '" data-id="' + presshubEsc(src.id) + '">' +
                        presshubEsc(statusText) +
                    '</button>' +
                '</td>' +
                '<td style="vertical-align: middle;">' +
                    '<strong class="presshub-source-name">' + presshubEsc(src.name || src.url) + '</strong>' +
                    (src.notes ? '<div class="presshub-source-subnote" style="color: #646970; font-size: 11px; margin-top: 2px;">' + presshubEsc(src.notes) + '</div>' : '') +
                '</td>' +
                '<td style="vertical-align: middle;">' +
                    '<a href="' + presshubEsc(src.url) + '" target="_blank" rel="noopener noreferrer" class="presshub-source-url code" style="word-break: break-all;">' + presshubEsc(src.url) + ' <span class="dashicons dashicons-external" style="font-size: 12px; width: 12px; height: 12px; text-decoration: none; vertical-align: middle;"></span></a>' +
                '</td>' +
                '<td style="vertical-align: middle;">' +
                    '<span class="presshub-media-badge ' + meta.badgeClass + '">' +
                        '<span class="dashicons ' + meta.icon + '" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px; vertical-align: middle;"></span>' +
                        presshubEsc(meta.label) +
                    '</span>' +
                '</td>' +
                '<td style="vertical-align: middle;">' +
                    '<span class="presshub-category-pill">' + presshubEsc(src.category || 'General') + '</span>' +
                    '<div style="margin-top: 4px;"><span class="presshub-source-quota" style="font-size: 11px; color: #50575e; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; display: inline-block;">Quota: ' + maxArticles + ' articles</span></div>' +
                '</td>' +
                '<td style="text-align: right; vertical-align: middle;">' +
                    '<div class="presshub-source-actions" style="display: flex; gap: 6px; justify-content: flex-end;">' +
                        '<button type="button" class="button button-small presshub-source-test-btn" data-url="' + presshubEsc(src.url) + '" data-type="' + presshubEsc(typeKey) + '" title="Test connectivity">' +
                            '<span class="dashicons dashicons-networking" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span> Test' +
                        '</button>' +
                        '<button type="button" class="button button-small presshub-source-edit-btn" data-id="' + presshubEsc(src.id) + '" title="Edit source">' +
                            '<span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span> Edit' +
                        '</button>' +
                        '<button type="button" class="button button-small presshub-source-delete-btn" data-id="' + presshubEsc(src.id) + '" title="Delete source" style="color: #b32d2e;">' +
                            '<span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';

            $tbody.append(rowHtml);
        });

        $('#presshub-sources-count-badge').text(totalCount + ' Sources (' + activeCount + ' Active)');
    }

    $(document).on('click', '#presshub-add-source-btn', function(e) {
        e.preventDefault();
        var $modal = $('#presshub-source-modal');
        var $form  = $('#presshub-source-form');
        $form[0].reset();
        $('#source-form-id').val('');
        $('#source-form-type').val('text_news');
        $('#source-form-category').val('General');
        $('#source-form-max-articles').val('5');
        $('#source-form-enabled').prop('checked', true);
        $('#presshub-source-modal-title').text('Add News Source');
        $('#presshub-source-form-notice').hide().empty();
        $('body').addClass('presshub-modal-open');
        $modal.fadeIn(150);
        $('#source-form-name').focus();
    });

    $(document).on('click', '.presshub-source-edit-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var src = presshubSourcesState.find(function(s) { return String(s.id) === String(id); });
        if (!src) {
            return;
        }

        var $modal = $('#presshub-source-modal');
        $('#source-form-id').val(src.id);
        $('#source-form-name').val(src.name || '');
        $('#source-form-url').val(src.url || '');
        $('#source-form-type').val(src.type || 'text_news');
        $('#source-form-category').val(src.category || 'General');
        $('#source-form-max-articles').val(src.max_articles ? Math.max(1, Math.min(30, parseInt(src.max_articles, 10))) : 5);
        $('#source-form-notes').val(src.notes || '');
        var isEnabled = (src.enabled !== false && src.enabled !== 0 && src.enabled !== '0' && src.enabled !== 'false');
        $('#source-form-enabled').prop('checked', isEnabled);

        $('#presshub-source-modal-title').text('Edit News Source: ' + (src.name || src.url));
        $('#presshub-source-form-notice').hide().empty();
        $('body').addClass('presshub-modal-open');
        $modal.fadeIn(150);
        $('#source-form-name').focus();
    });

    $(document).on('click', '.presshub-source-delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var src = presshubSourcesState.find(function(s) { return String(s.id) === String(id); });
        var name = src ? (src.name || src.url) : 'this source';
        if (!confirm('Are you sure you want to delete ' + name + '?')) {
            return;
        }
        presshubSourcesState = presshubSourcesState.filter(function(s) { return String(s.id) !== String(id); });
        syncSourcesInput();
        renderSourcesTable();
    });

    $(document).on('click', '.presshub-source-toggle-status', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var src = presshubSourcesState.find(function(s) { return String(s.id) === String(id); });
        if (!src) {
            return;
        }
        var curEnabled = (src.enabled !== false && src.enabled !== 0 && src.enabled !== '0' && src.enabled !== 'false');
        src.enabled = !curEnabled;
        syncSourcesInput();
        renderSourcesTable();
    });

    $(document).on('click', '#presshub-source-form-save', function(e) {
        e.preventDefault();
        var id          = $('#source-form-id').val();
        var name        = $('#source-form-name').val().trim();
        var url         = $('#source-form-url').val().trim();
        var type        = $('#source-form-type').val();
        var category    = $('#source-form-category').val().trim() || 'General';
        var maxArticles = parseInt($('#source-form-max-articles').val(), 10) || 5;
        if (maxArticles < 1) {
            maxArticles = 1;
        } else if (maxArticles > 30) {
            maxArticles = 30;
        }
        var notes       = $('#source-form-notes').val().trim();
        var enabled     = $('#source-form-enabled').is(':checked');

        var $notice = $('#presshub-source-form-notice');

        if (!name) {
            $notice.html('<div class="notice notice-error"><p>Please enter a source name.</p></div>').show();
            $('#source-form-name').focus();
            return;
        }

        if (!url || !/^https?:\/\//i.test(url)) {
            $notice.html('<div class="notice notice-error"><p>Please enter a valid HTTP or HTTPS URL.</p></div>').show();
            $('#source-form-url').focus();
            return;
        }

        if (id) {
            var existing = presshubSourcesState.find(function(s) { return String(s.id) === String(id); });
            if (existing) {
                existing.name         = name;
                existing.url          = url;
                existing.type         = type;
                existing.category     = category;
                existing.max_articles = maxArticles;
                existing.notes        = notes;
                existing.enabled      = enabled;
            }
        } else {
            var newId = 'src_' + Math.random().toString(36).substr(2, 9);
            presshubSourcesState.push({
                id: newId,
                name: name,
                url: url,
                type: type,
                category: category,
                max_articles: maxArticles,
                notes: notes,
                enabled: enabled
            });
        }

        syncSourcesInput();
        renderSourcesTable();

        $('body').removeClass('presshub-modal-open');
        $('#presshub-source-modal').fadeOut(150);
    });

    $(document).on('click', '#presshub-source-form-test, .presshub-source-test-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var isModal = $btn.is('#presshub-source-form-test');

        var testUrl  = isModal ? $('#source-form-url').val().trim() : $btn.data('url');
        var testType = isModal ? $('#source-form-type').val() : ($btn.data('type') || 'text_news');
        var $spinner = isModal ? $('#presshub-source-form-spinner') : null;
        var $notice  = isModal ? $('#presshub-source-form-notice') : null;
        var $rowNotice = isModal ? null : $btn.siblings('.presshub-source-inline-notice');
        if (!isModal && (!$rowNotice || !$rowNotice.length)) {
            $rowNotice = $('<span class="presshub-source-inline-notice" style="font-size: 11px; margin-right: 6px;"></span>').insertBefore($btn);
        }

        if (!testUrl || !/^https?:\/\//i.test(testUrl)) {
            if (isModal) {
                $notice.html('<div class="notice notice-error"><p>Please enter a valid URL first.</p></div>').show();
            } else if ($rowNotice) {
                $rowNotice.html('<span style="color: #d63638;">✗ Invalid URL</span>').show();
                setTimeout(function() { $rowNotice.fadeOut(); }, 4000);
            }
            return;
        }

        $btn.prop('disabled', true);
        if ($spinner) { $spinner.addClass('is-active'); }
        if ($notice) { $notice.hide().empty(); }
        if ($rowNotice) { $rowNotice.html('<span style="color: #646970;">Testing...</span>').show(); }

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_test_source',
                nonce: presshubAI.nonce,
                url: testUrl,
                type: testType
            }
        }).done(function(res) {
            $btn.prop('disabled', false);
            if ($spinner) { $spinner.removeClass('is-active'); }

            if (res && res.success && res.data) {
                var successMsg = res.data.message || 'Connected successfully.';
                if (isModal) {
                    $notice.html('<div class="notice notice-success"><p>✓ ' + presshubEsc(successMsg) + '</p></div>').show();
                } else if ($rowNotice) {
                    $rowNotice.html('<span style="color: #00a32a;">✓ Connected (' + (res.data.links_found || 0) + ' links)</span>').show();
                    setTimeout(function() { $rowNotice.fadeOut(); }, 4000);
                }
            } else {
                var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Connection test failed.';
                if (isModal) {
                    $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(errMsg) + '</p></div>').show();
                } else if ($rowNotice) {
                    $rowNotice.html('<span style="color: #d63638;">✗ ' + presshubEsc(errMsg) + '</span>').show();
                    setTimeout(function() { $rowNotice.fadeOut(); }, 4000);
                }
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            if ($spinner) { $spinner.removeClass('is-active'); }
            var failMsg = error || status || 'Connection error';
            if (isModal) {
                $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(failMsg) + '</p></div>').show();
            } else if ($rowNotice) {
                $rowNotice.html('<span style="color: #d63638;">✗ ' + presshubEsc(failMsg) + '</span>').show();
                setTimeout(function() { $rowNotice.fadeOut(); }, 4000);
            }
        });
    });

    $(document).on('click', '#presshub-source-modal .presshub-modal-close, #presshub-source-modal .presshub-modal-cancel', function(e) {
        e.preventDefault();
        $('body').removeClass('presshub-modal-open');
        $('#presshub-source-modal').fadeOut(150);
    });

    $(document).on('click', '#presshub-source-modal', function(e) {
        if ($(e.target).is('#presshub-source-modal')) {
            $('body').removeClass('presshub-modal-open');
            $('#presshub-source-modal').fadeOut(150);
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            if ($('#presshub-source-modal').is(':visible')) {
                $('body').removeClass('presshub-modal-open');
                $('#presshub-source-modal').fadeOut(150);
            } else if ($('#presshub-bulk-import-modal').is(':visible')) {
                $('body').removeClass('presshub-modal-open');
                $('#presshub-bulk-import-modal').fadeOut(150);
            }
        }
    });

    // ------------------------------------------------------------------
    // Bulk Import Sources (Issue #41)
    // ------------------------------------------------------------------
    $(document).on('click', '#presshub-bulk-import-sources-btn', function(e) {
        e.preventDefault();
        var $modal = $('#presshub-bulk-import-modal');
        if (!$modal.length) {
            return;
        }
        var $form = $('#presshub-bulk-import-form');
        if ($form.length) {
            $form[0].reset();
        }
        $('#presshub-bulk-import-type').val('text_news');
        $('#presshub-bulk-import-category').val('General');
        $('#presshub-bulk-import-max-articles').val('5');
        $('#presshub-bulk-import-enabled').prop('checked', true);
        $('#presshub-bulk-import-notice').hide().empty();
        $('#presshub-bulk-import-spinner').removeClass('is-active');
        $('#presshub-bulk-import-submit').prop('disabled', false);
        $('body').addClass('presshub-modal-open');
        $modal.fadeIn(150);
        $('#presshub-bulk-import-urls').focus();
    });

    $(document).on('click', '#presshub-bulk-import-modal .presshub-modal-close, #presshub-bulk-import-modal .presshub-modal-cancel', function(e) {
        e.preventDefault();
        $('body').removeClass('presshub-modal-open');
        $('#presshub-bulk-import-modal').fadeOut(150);
    });

    $(document).on('click', '#presshub-bulk-import-modal', function(e) {
        if ($(e.target).is('#presshub-bulk-import-modal')) {
            $('body').removeClass('presshub-modal-open');
            $('#presshub-bulk-import-modal').fadeOut(150);
        }
    });

    $(document).on('click', '#presshub-bulk-import-submit', function(e) {
        e.preventDefault();

        var raw = $('#presshub-bulk-import-urls').val() || '';
        if (!raw.trim()) {
            $('#presshub-bulk-import-notice')
                .html('<div class="notice notice-error"><p>Please paste at least one URL.</p></div>')
                .show();
            $('#presshub-bulk-import-urls').focus();
            return;
        }

        var defaults = {
            type:         $('#presshub-bulk-import-type').val() || 'text_news',
            category:     ($('#presshub-bulk-import-category').val() || 'General').trim(),
            max_articles: parseInt($('#presshub-bulk-import-max-articles').val(), 10) || 5,
            enabled:      $('#presshub-bulk-import-enabled').is(':checked')
        };

        var $btn     = $(this);
        var $spinner = $('#presshub-bulk-import-spinner');
        var $notice  = $('#presshub-bulk-import-notice');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $notice.hide().empty();

        $.ajax({
            url:    presshubAI.ajax_url,
            type:   'POST',
            data: {
                action:   'presshub_ai_bulk_import_sources',
                nonce:    presshubAI.nonce,
                urls:     raw,
                defaults: JSON.stringify(defaults)
            },
            dataType: 'json'
        }).done(function(res) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (res && res.success) {
                var d = res.data || {};
                var html = '<div class="notice notice-success"><p>✓ ' + presshubEsc(d.message || 'Imported') + '</p></div>';
                if (d.truncated) {
                    html += '<div class="notice notice-warning"><p>' + presshubEsc('Import truncated at 100 lines. Submit remaining URLs in another batch.') + '</p></div>';
                }
                $notice.html(html).show();

                if (Array.isArray(d.sources)) {
                    presshubSourcesState = d.sources;
                    syncSourcesInput();
                    renderSourcesTable();
                }

                setTimeout(function() {
                    $('body').removeClass('presshub-modal-open');
                    $('#presshub-bulk-import-modal').fadeOut(150);
                }, 900);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Bulk import failed.';
                $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(msg) + '</p></div>').show();
            }
        }).fail(function(xhr, status, error) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $notice.html('<div class="notice notice-error"><p>✗ ' + presshubEsc(error || status || 'Error') + '</p></div>').show();
        });
    });

    // ------------------------------------------------------------------
    // Token & Activity Usage Analytics Dashboard Logic
    // ------------------------------------------------------------------
    var currentTokenPage = 1;

    function formatNumber(num) {
        return (num || 0).toLocaleString();
    }

    function loadTokenLogs(page) {
        currentTokenPage = page || 1;
        var $tbody   = $('#presshub-token-logs-tbody');
        var $spinner = $('#token-logs-spinner');

        var range     = $('#token-filter-range').val();
        var action    = $('#token-filter-action').val();
        var provider  = $('#token-filter-provider').val();
        var status    = $('#token-filter-status').val();
        var search    = $('#token-filter-search').val();

        $spinner.addClass('is-active');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_fetch_token_logs',
                nonce: presshubAI.nonce,
                page: currentTokenPage,
                per_page: 20,
                date_range: range,
                action_filter: action,
                provider: provider,
                status: status,
                search: search
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success && res.data) {
                var summary = res.data.summary || {};
                var logsData = res.data.logs || {};

                // Update KPIs
                $('#kpi-total-requests').text(formatNumber(summary.total_requests));
                $('#kpi-total-tokens').text(formatNumber(summary.total_tokens));
                $('#kpi-tts-chars').text(formatNumber(summary.tts_chars));
                $('#kpi-scraped-articles').text(formatNumber(summary.scraped_articles));
                $('#kpi-success-rate').text((summary.success_rate !== undefined ? summary.success_rate : 100) + '%');

                // Render Table Rows
                var items = logsData.items || [];
                window.currentLogsItems = items;
                if (items.length === 0) {
                    $tbody.html('<tr><td colspan="8" style="text-align:center; padding: 25px; color: #666;">' + presshubEsc(__('No token activity logs found matching the selected filters.', 'presshub-ai-editor')) + '</td></tr>');
                } else {
                    var rowsHtml = '';
                    items.forEach(function(item, idx) {
                        var isSuccess   = (item.status === 'success');
                        var statusBadge = isSuccess
                            ? '<span class="presshub-status-pill pill-active">' + presshubEsc(__('Success', 'presshub-ai-editor')) + '</span>'
                            : '<span class="presshub-status-pill pill-inactive">' + presshubEsc(__('Error', 'presshub-ai-editor')) + '</span>';

                        var metricDisplay = '';
                        if (item.total_tokens > 0) {
                            metricDisplay = '<strong>' + formatNumber(item.total_tokens) + '</strong> tokens <span style="color:#666; font-size:11px;">(P: ' + formatNumber(item.prompt_tokens) + ' / C: ' + formatNumber(item.completion_tokens) + ')</span>';
                        } else if (item.metric_units > 0) {
                            metricDisplay = '<strong>' + formatNumber(item.metric_units) + '</strong> units';
                        } else {
                            metricDisplay = '-';
                        }

                        var duration   = item.duration_ms ? formatNumber(item.duration_ms) + ' ms' : '-';

                        var detailsBtn = isSuccess
                            ? '<button type="button" class="button button-small presshub-view-log-btn" data-index="' + idx + '" style="display:inline-flex; align-items:center; gap:4px; color:#00a32a;"><span class="dashicons dashicons-yes"></span> ' + presshubEsc(__('Details', 'presshub-ai-editor')) + '</button>'
                            : '<button type="button" class="button button-small presshub-view-log-btn" data-index="' + idx + '" style="display:inline-flex; align-items:center; gap:4px; color:#d63638; border-color:#d63638;"><span class="dashicons dashicons-warning" style="vertical-align:middle;"></span> ' + presshubEsc(__('Error Details', 'presshub-ai-editor')) + '</button>';

                        rowsHtml += '<tr>' +
                            '<td>' + presshubEsc(item.created_at || '') + '</td>' +
                            '<td><span class="presshub-badge">' + presshubEsc(item.action_trigger || '') + '</span></td>' +
                            '<td><strong>' + presshubEsc(item.provider || '') + '</strong></td>' +
                            '<td><code style="font-size:11px;">' + presshubEsc(item.model || '') + '</code></td>' +
                            '<td>' + metricDisplay + '</td>' +
                            '<td>' + presshubEsc(duration) + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td>' + detailsBtn + '</td>' +
                            '</tr>';
                    });
                    $tbody.html(rowsHtml);
                }

                // Update Pagination Controls
                var total = logsData.total || 0;
                var pages = Math.max(1, logsData.pages || 1);
                var pageNum = logsData.page || 1;
                var perPage = logsData.per_page || 20;
                var from = total > 0 ? ((pageNum - 1) * perPage) + 1 : 0;
                var to = Math.min(total, pageNum * perPage);

                $('#token-pagination-info').text(sprintf(__('Showing %d - %d of %d entries', 'presshub-ai-editor'), from, to, total));
                $('#token-page-current').text(pageNum + ' / ' + pages);
                $('#token-page-prev').prop('disabled', pageNum <= 1);
                $('#token-page-next').prop('disabled', pageNum >= pages);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $tbody.html('<tr><td colspan="8" style="text-align:center; color:#d63638; padding:20px;">' + presshubEsc(__('Error fetching token logs: ', 'presshub-ai-editor') + (error || status)) + '</td></tr>');
        });
    }

    // Token Filters & Pagination Events
    $(document).on('change', '#token-filter-range, #token-filter-action, #token-filter-provider, #token-filter-status', function() {
        loadTokenLogs(1);
    });

    $(document).on('click', '#token-filter-refresh', function(e) {
        e.preventDefault();
        loadTokenLogs(1);
    });

    $(document).on('keyup', '#token-filter-search', function(e) {
        if (e.key === 'Enter') {
            loadTokenLogs(1);
        }
    });

    $(document).on('click', '#token-page-prev', function(e) {
        e.preventDefault();
        if (currentTokenPage > 1) {
            loadTokenLogs(currentTokenPage - 1);
        }
    });

    $(document).on('click', '#token-page-next', function(e) {
        e.preventDefault();
        loadTokenLogs(currentTokenPage + 1);
    });

    // Export CSV Trigger
    $(document).on('click', '#token-export-csv', function(e) {
        e.preventDefault();
        var range     = $('#token-filter-range').val();
        var action    = $('#token-filter-action').val();
        var provider  = $('#token-filter-provider').val();
        var status    = $('#token-filter-status').val();
        var search    = $('#token-filter-search').val();

        var params = $.param({
            action: 'presshub_ai_export_token_csv',
            nonce: presshubAI.nonce,
            date_range: range,
            action_filter: action,
            provider: provider,
            status: status,
            search: search
        });

        window.location.href = presshubAI.ajax_url + '?' + params;
    });

    // Clear Logs Trigger
    $(document).on('click', '#token-clear-logs', function(e) {
        e.preventDefault();
        if (!confirm(__('Are you sure you want to delete ALL token usage and activity logs? This action cannot be undone.', 'presshub-ai-editor'))) {
            return;
        }

        var $spinner = $('#token-logs-spinner');
        $spinner.addClass('is-active');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_clear_token_logs',
                nonce: presshubAI.nonce
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success) {
                loadTokenLogs(1);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : __('Failed to clear logs.', 'presshub-ai-editor');
                showNotice(msg, 'error', $(this));
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            showNotice(__('Error clearing logs: ', 'presshub-ai-editor') + (error || status), 'error', $(this));
        });
    });

    // Log Details Modal & Error Inspection Handlers
    function openLogDetailsModal(item) {
        if (!item) {
            return;
        }
        var $modal = $('#presshub-log-details-modal');

        $('#log-detail-timestamp').text(item.created_at || '-');

        var isSuccess = (item.status === 'success');
        var statusBadge = isSuccess
            ? '<span class="presshub-status-pill pill-active">' + presshubEsc(__('Success', 'presshub-ai-editor')) + '</span>'
            : '<span class="presshub-status-pill pill-inactive">' + presshubEsc(__('Error', 'presshub-ai-editor')) + '</span>';
        $('#log-detail-status').html(statusBadge);

        $('#log-detail-action').html('<span class="presshub-badge">' + presshubEsc(item.action_trigger || '-') + '</span>');
        $('#log-detail-provider').text(item.provider || '-');
        $('#log-detail-model').html('<code style="font-size:12px;">' + presshubEsc(item.model || '-') + '</code>');
        $('#log-detail-duration').text(item.duration_ms ? formatNumber(item.duration_ms) + ' ms' : '-');

        var metricsText = '-';
        if (item.total_tokens > 0) {
            metricsText = '<strong>' + formatNumber(item.total_tokens) + '</strong> tokens <span style="color:#666; font-size:11px;">(Prompt: ' + formatNumber(item.prompt_tokens) + ' / Completion: ' + formatNumber(item.completion_tokens) + ')</span>';
        } else if (item.metric_units > 0) {
            metricsText = '<strong>' + formatNumber(item.metric_units) + '</strong> units';
        }
        $('#log-detail-metrics').html(metricsText);

        // Error message container
        if (item.error_message) {
            $('#log-detail-error').text(item.error_message);
            $('#presshub-log-error-container').show();
        } else {
            $('#log-detail-error').text('');
            $('#presshub-log-error-container').hide();
        }

        // Additional metadata container
        var metaContent = '';
        if (item.metadata) {
            if (typeof item.metadata === 'object') {
                metaContent = JSON.stringify(item.metadata, null, 2);
            } else if (typeof item.metadata === 'string' && item.metadata.trim() !== '') {
                try {
                    var parsed = JSON.parse(item.metadata);
                    metaContent = JSON.stringify(parsed, null, 2);
                } catch(e) {
                    metaContent = item.metadata;
                }
            }
        }

        if (metaContent) {
            $('#log-detail-metadata').text(metaContent);
            $('#presshub-log-metadata-container').show();
        } else {
            $('#log-detail-metadata').text('');
            $('#presshub-log-metadata-container').hide();
        }

        $modal.show().addClass('is-open');
        $('body').addClass('presshub-modal-open');
    }

    $(document).on('click', '.presshub-view-log-btn', function(e) {
        e.preventDefault();
        var idx = parseInt($(this).data('index'), 10);
        if (window.currentLogsItems && window.currentLogsItems[idx]) {
            openLogDetailsModal(window.currentLogsItems[idx]);
        }
    });

    $(document).on('click', '#presshub-copy-log-error', function(e) {
        e.preventDefault();
        var errorText = $('#log-detail-error').text();
        if (!errorText) {
            return;
        }

        var $btn = $(this);
        var $btnText = $btn.find('.copy-btn-text');
        var originalText = $btnText.length ? $btnText.text() : $btn.text();

        var copySuccess = function() {
            if ($btnText.length) {
                $btnText.text(__('✓ Copied!', 'presshub-ai-editor'));
            } else {
                $btn.text(__('✓ Copied!', 'presshub-ai-editor'));
            }
            setTimeout(function() {
                if ($btnText.length) {
                    $btnText.text(originalText);
                } else {
                    $btn.text(originalText);
                }
            }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(errorText).then(copySuccess).catch(function() {
                fallbackCopyText(errorText, copySuccess);
            });
        } else {
            fallbackCopyText(errorText, copySuccess);
        }
    });

    function fallbackCopyText(text, cb) {
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        try {
            document.execCommand('copy');
            if (typeof cb === 'function') {
                cb();
            }
        } catch(err) {
            // ignore
        }
        $temp.remove();
    }

    // ------------------------------------------------------------------
    // Configuration Audit Trail Handlers.
    // ------------------------------------------------------------------
    var currentAuditPage = 1;

    function loadAuditLogs(page) {
        if (page !== undefined) {
            currentAuditPage = page;
        }

        var $tbody   = $('#presshub-audit-logs-tbody');
        var $spinner = $('#audit-logs-spinner');

        if (!$tbody.length) {
            return;
        }

        var eventType  = $('#audit-filter-event').val() || '';
        var entityType = $('#audit-filter-entity').val() || '';
        var search     = $('#audit-filter-search').val() || '';

        $spinner.addClass('is-active');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_fetch_audit_logs',
                nonce: presshubAI.nonce,
                page: currentAuditPage,
                per_page: 20,
                event_type: eventType,
                entity_type: entityType,
                search: search
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success && res.data) {
                var logsData = res.data.logs || {};
                var items = logsData.items || [];

                if (items.length === 0) {
                    $tbody.html('<tr><td colspan="7" style="text-align:center; padding: 25px; color: #666;">' + presshubEsc(__('No configuration audit logs found matching the selected filters.', 'presshub-ai-editor')) + '</td></tr>');
                } else {
                    var rowsHtml = '';
                    items.forEach(function(item) {
                        var detailsText = '-';
                        if (item.details) {
                            try {
                                var parsed = JSON.parse(item.details);
                                if (typeof parsed === 'object' && parsed !== null) {
                                    var parts = [];
                                    for (var k in parsed) {
                                        if (Object.prototype.hasOwnProperty.call(parsed, k)) {
                                            var v = parsed[k];
                                            if (typeof v === 'boolean') {
                                                v = v ? 'true' : 'false';
                                            } else if (typeof v === 'object' && v !== null) {
                                                v = JSON.stringify(v);
                                            }
                                            parts.push('<strong>' + presshubEsc(k) + ':</strong> ' + presshubEsc(String(v)));
                                        }
                                    }
                                    detailsText = parts.join(' &bull; ');
                                } else {
                                    detailsText = presshubEsc(String(parsed));
                                }
                            } catch (e) {
                                detailsText = presshubEsc(item.details);
                            }
                        }

                        var eventBadgeClass = 'presshub-badge';
                        if (item.event_type && item.event_type.indexOf('deleted') !== -1) {
                            eventBadgeClass = 'presshub-status-pill pill-inactive';
                        } else if (item.event_type && item.event_type.indexOf('added') !== -1) {
                            eventBadgeClass = 'presshub-status-pill pill-active';
                        }

                        rowsHtml += '<tr>' +
                            '<td>' + presshubEsc(item.created_at || '') + '</td>' +
                            '<td><strong>' + presshubEsc(item.user_login || 'system') + '</strong></td>' +
                            '<td><span class="' + eventBadgeClass + '">' + presshubEsc(item.event_type || '') + '</span></td>' +
                            '<td><code>' + presshubEsc(item.entity_type || '') + '</code></td>' +
                            '<td>' + presshubEsc(item.entity_id || '-') + '</td>' +
                            '<td style="font-size:12px;">' + detailsText + '</td>' +
                            '<td><code style="font-size:11px;">' + presshubEsc(item.ip_address || '') + '</code></td>' +
                            '</tr>';
                    });
                    $tbody.html(rowsHtml);
                }

                // Update Pagination Controls
                var total = logsData.total || 0;
                var pages = Math.max(1, logsData.pages || 1);
                var pageNum = logsData.page || 1;
                var perPage = logsData.per_page || 20;
                var from = total > 0 ? ((pageNum - 1) * perPage) + 1 : 0;
                var to = Math.min(total, pageNum * perPage);

                $('#audit-pagination-info').text(sprintf(__('Showing %d - %d of %d entries', 'presshub-ai-editor'), from, to, total));
                $('#audit-page-current').text(pageNum + ' / ' + pages);
                $('#audit-page-prev').prop('disabled', pageNum <= 1);
                $('#audit-page-next').prop('disabled', pageNum >= pages);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $tbody.html('<tr><td colspan="7" style="text-align:center; color:#d63638; padding:20px;">' + presshubEsc(__('Error fetching audit logs: ', 'presshub-ai-editor') + (error || status)) + '</td></tr>');
        });
    }

    // Audit Filters & Pagination Events
    $(document).on('change', '#audit-filter-event, #audit-filter-entity', function() {
        loadAuditLogs(1);
    });

    $(document).on('click', '#audit-filter-refresh', function(e) {
        e.preventDefault();
        loadAuditLogs(1);
    });

    $(document).on('keyup', '#audit-filter-search', function(e) {
        if (e.key === 'Enter') {
            loadAuditLogs(1);
        }
    });

    $(document).on('click', '#audit-page-prev', function(e) {
        e.preventDefault();
        if (currentAuditPage > 1) {
            loadAuditLogs(currentAuditPage - 1);
        }
    });

    $(document).on('click', '#audit-page-next', function(e) {
        e.preventDefault();
        loadAuditLogs(currentAuditPage + 1);
    });

    // Export Audit CSV Trigger
    $(document).on('click', '#audit-export-csv', function(e) {
        e.preventDefault();
        var eventType  = $('#audit-filter-event').val() || '';
        var entityType = $('#audit-filter-entity').val() || '';
        var search     = $('#audit-filter-search').val() || '';

        var params = $.param({
            action: 'presshub_ai_export_audit_csv',
            nonce: presshubAI.nonce,
            event_type: eventType,
            entity_type: entityType,
            search: search
        });

        window.location.href = presshubAI.ajax_url + '?' + params;
    });

    // Clear Audit Logs Trigger
    $(document).on('click', '#audit-clear-logs', function(e) {
        e.preventDefault();
        if (!confirm(__('Are you sure you want to delete ALL configuration and provider audit logs? This action cannot be undone.', 'presshub-ai-editor'))) {
            return;
        }

        var $spinner = $('#audit-logs-spinner');
        $spinner.addClass('is-active');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_clear_audit_logs',
                nonce: presshubAI.nonce
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success) {
                loadAuditLogs(1);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : __('Failed to clear audit logs.', 'presshub-ai-editor');
                showNotice(msg, 'error', $('#presshub-audit-dashboard'));
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            showNotice(__('Error clearing audit logs: ', 'presshub-ai-editor') + (error || status), 'error', $('#presshub-audit-dashboard'));
        });
    });

    // ------------------------------------------------------------------
    // Per-Tab AJAX Settings Save Handler & Sticky Save Bar Integration.
    // ------------------------------------------------------------------
    function saveSettingsTab(tabKey) {
        if (!tabKey) {
            return;
        }

        var $tabPane = $('#presshub-tab-pane-' + tabKey);
        if (!$tabPane.length) {
            return;
        }

        var $tabBtn = $tabPane.find('.presshub-tab-save-btn');
        var $tabSpinner = $tabPane.find('.presshub-tab-save-spinner, .spinner');
        var $stickyBar = $('#presshub-sticky-save-bar');
        var $stickyBtn = $('#presshub-sticky-save-btn, .presshub-sticky-save-btn');
        var $stickySpinner = $('#presshub-sticky-save-spinner, .presshub-sticky-save-spinner');
        var $stickyStatus = $('#presshub-sticky-status-msg');

        $tabPane.find('.presshub-settings-notice').remove();
        $('.presshub-settings-notice').remove();

        if ($tabBtn.length) {
            $tabBtn.prop('disabled', true);
        }
        if ($tabSpinner.length) {
            $tabSpinner.addClass('is-active');
        }
        if ($stickyBtn.length) {
            $stickyBtn.prop('disabled', true);
        }
        if ($stickySpinner.length) {
            $stickySpinner.addClass('is-active');
        }
        if ($stickyStatus.length) {
            $stickyStatus.removeClass('is-success is-error').text(__('Saving...', 'presshub-ai-editor')).show();
        }

        var settingsPayload = {};
        $tabPane.find('input, select, textarea').each(function() {
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
        } catch (err) {
            base64Payload = '';
        }

        var ajaxData = {
            action: 'presshub_ai_save_settings_section',
            tab: tabKey,
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
            if ($tabBtn.length) {
                $tabBtn.prop('disabled', false);
            }
            if ($tabSpinner.length) {
                $tabSpinner.removeClass('is-active');
            }
            if ($stickyBtn.length) {
                $stickyBtn.prop('disabled', false);
            }
            if ($stickySpinner.length) {
                $stickySpinner.removeClass('is-active');
            }

            if (res && res.success) {
                tabBaselines[tabKey] = getTabFormSnapshot(tabKey);
                updateTabDirtyState(tabKey);

                var successMsg = (res.data && res.data.message) ? res.data.message : __('Settings saved successfully.', 'presshub-ai-editor');
                if (res.data && res.data.sources !== undefined) {
                    if (Array.isArray(res.data.sources)) {
                        presshubSourcesState = res.data.sources;
                    } else if (typeof res.data.sources === 'string') {
                        try {
                            presshubSourcesState = JSON.parse(res.data.sources);
                        } catch (e) {
                            initSourcesState();
                        }
                    }
                    syncSourcesInput();
                    renderSourcesTable();
                    if (presshubSourcesState.length > 0) {
                        successMsg += ' [Sources: ' + presshubSourcesState.length + ' configured]';
                    }
                }
                var noticeHtml = '<div class="notice notice-success is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc(successMsg) + '</p></div>';
                $tabPane.prepend(noticeHtml);

                if ($stickyStatus.length) {
                    $stickyStatus.addClass('is-success').removeClass('is-error').text(__('Saved!', 'presshub-ai-editor'));
                    setTimeout(function() {
                        if ($stickyStatus.hasClass('is-success')) {
                            $stickyStatus.fadeOut(400, function() {
                                $(this).text('').removeClass('is-success').show();
                            });
                        }
                    }, 3000);
                }

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
                    if (res.data.masks.briefing_tts_key) {
                        $('#presshub_ai_briefing_tts_api_key').attr('placeholder', res.data.masks.briefing_tts_key);
                    }
                }
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : __('Failed to save settings.', 'presshub-ai-editor');
                var errHtml = '<div class="notice notice-error is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc(err) + '</p></div>';
                $tabPane.prepend(errHtml);
                if ($stickyStatus.length) {
                    $stickyStatus.addClass('is-error').removeClass('is-success').text(__('Save failed', 'presshub-ai-editor'));
                }
            }
        }).fail(function(xhr, status, error) {
            if ($tabBtn.length) {
                $tabBtn.prop('disabled', false);
            }
            if ($tabSpinner.length) {
                $tabSpinner.removeClass('is-active');
            }
            if ($stickyBtn.length) {
                $stickyBtn.prop('disabled', false);
            }
            if ($stickySpinner.length) {
                $stickySpinner.removeClass('is-active');
            }

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
            $tabPane.prepend(errHtml);
            if ($stickyStatus.length) {
                $stickyStatus.addClass('is-error').removeClass('is-success').text(__('Save error', 'presshub-ai-editor'));
            }
        });
    }

    $(document).on('click', '.presshub-tab-save-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var tabKey = $btn.data('tab') || '';
        if (!tabKey) {
            var $tabPane = $btn.closest('.presshub-tab-pane');
            if ($tabPane.attr('id')) {
                tabKey = $tabPane.attr('id').replace('presshub-tab-pane-', '');
            }
        }
        if (tabKey) {
            saveSettingsTab(tabKey);
        }
    });

    $(document).on('click', '#presshub-sticky-save-btn, .presshub-sticky-save-btn', function(e) {
        e.preventDefault();
        var tabKey = $('#presshub-sticky-save-bar').attr('data-active-tab') ||
                     $('#presshub-ai-settings-tabs .nav-tab-active').data('tab') ||
                     $('#presshub-mobile-tab-select').val() ||
                     '';
        if (tabKey) {
            saveSettingsTab(tabKey);
        }
    });

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
                initTabBaselines();
                Object.keys(tabBaselines).forEach(function(k) {
                    updateTabDirtyState(k);
                });

                var successMsg = (res.data && res.data.message) ? res.data.message : 'Settings saved successfully.';
                if (res.data && res.data.sources !== undefined) {
                    if (Array.isArray(res.data.sources)) {
                        presshubSourcesState = res.data.sources;
                    } else if (typeof res.data.sources === 'string') {
                        try {
                            presshubSourcesState = JSON.parse(res.data.sources);
                        } catch (e) {
                            initSourcesState();
                        }
                    }
                    syncSourcesInput();
                    renderSourcesTable();
                    if (presshubSourcesState.length > 0) {
                        successMsg += ' [Sources: ' + presshubSourcesState.length + ' configured]';
                    }
                }
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
                    if (res.data.masks.briefing_tts_key) {
                        $('#presshub_ai_briefing_tts_api_key').attr('placeholder', res.data.masks.briefing_tts_key);
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

    $(document).on('change', '#presshub_ai_briefing_tts_style', function() {
        if ($(this).val() === 'custom') {
            $('#presshub-custom-tts-style-row').show();
        } else {
            $('#presshub-custom-tts-style-row').hide();
        }
    });

    function updateBriefingHostRows() {
        var count = parseInt($('#presshub_ai_briefing_host_count').val(), 10) || 2;
        if (count >= 2) {
            $('#presshub-voice-male-row').show();
        } else {
            $('#presshub-voice-male-row').hide();
        }
        if (count >= 3) {
            $('#presshub-voice-tertiary-row').show();
        } else {
            $('#presshub-voice-tertiary-row').hide();
        }
    }
    $(document).on('change', '#presshub_ai_briefing_host_count', updateBriefingHostRows);
    updateBriefingHostRows();

    // ------------------------------------------------------------------
    // Prompt Studio Interactive Tab Switching & Warning Engine (Issue #108)
    // ------------------------------------------------------------------
    $(document).on('click', '.presshub-style-tab', function(e) {
        e.preventDefault();
        var $tab = $(this);
        var styleKey = $tab.data('style');
        
        $('.presshub-style-tab').removeClass('nav-tab-active');
        $tab.addClass('nav-tab-active');
        
        $('.presshub-style-pane').hide();
        $('#presshub-style-' + styleKey).show();
    });

    $(document).on('click', '.presshub-host-tab', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var targetId = $btn.data('target');
        var $stylePane = $btn.closest('.presshub-style-pane');

        $stylePane.find('.presshub-host-tab').removeClass('button-primary').addClass('button-secondary');
        $btn.removeClass('button-secondary').addClass('button-primary');

        $stylePane.find('.presshub-host-pane').hide();
        $('#' + targetId).show();
    });

    function checkEmptyPromptWarning($textarea) {
        var val = $textarea.val() || '';
        var $pane = $textarea.closest('.presshub-host-pane');
        var $warning = $pane.find('.presshub-empty-prompt-warning');
        if ($.trim(val) === '') {
            $warning.show();
        } else {
            $warning.hide();
        }
    }

    $(document).on('input change', '.presshub-prompt-textarea', function() {
        checkEmptyPromptWarning($(this));
    });

    // SFX Preview Player
    var currentAudio = null;
    $(document).on('click', '.presshub-sfx-preview-btn', function() {
        var $btn = $(this);
        var $select = $btn.siblings('.presshub-sfx-select');
        var filename = $select.val();
        var baseUrl = $select.data('audio-url');
        
        if (!filename || filename === 'silence') {
            return;
        }

        if (currentAudio) {
            currentAudio.pause();
            currentAudio.currentTime = 0;
            $('.presshub-sfx-preview-btn').text('▶ Preview');
        }

        currentAudio = new Audio(baseUrl + filename);
        $btn.text('⏸ Playing...');
        currentAudio.play();

        currentAudio.onended = function() {
            $btn.text('▶ Preview');
        };
    });

    // ------------------------------------------------------------------
    // Diagnostic Inspector Handlers (Issue #100 / Rationalization).
    // ------------------------------------------------------------------
    function escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function copyTextToClipboard(text, $btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showCopyFeedback($btn);
            });
        } else {
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
            showCopyFeedback($btn);
        }
    }

    function showCopyFeedback($btn) {
        var origText = $btn.text();
        $btn.text('Copied!').prop('disabled', true);
        setTimeout(function() {
            $btn.text(origText).prop('disabled', false);
        }, 1500);
    }

    function renderPromptCards(entries) {
        if (!entries || !entries.length) {
            return '<div style="padding: 24px; text-align: center; color: #646970;">(No prompt log entries found matching criteria)</div>';
        }
        var html = '';
        var reversed = entries.slice().reverse();
        for (var i = 0; i < reversed.length; i++) {
            var e = reversed[i];
            if (e.raw && !e.endpoint) {
                html += '<div class="presshub-log-card"><div class="presshub-log-section-content">' + escapeHtml(e.raw) + '</div></div>';
                continue;
            }
            var rawJson = JSON.stringify(e, null, 2);
            var traceBadge = e.trace_id ? '<span class="presshub-log-badge presshub-badge-trace presshub-trace-filter" data-trace="' + escapeHtml(e.trace_id) + '" title="Click to filter by this trace ID">#' + escapeHtml(e.trace_id) + '</span>' : '';
            var endpointBadge = e.endpoint ? '<span class="presshub-log-badge presshub-badge-endpoint">[' + escapeHtml(e.endpoint.toUpperCase()) + ']</span>' : '';
            var timeBadge = e.timestamp ? '<span class="presshub-log-badge presshub-badge-timestamp">' + escapeHtml(e.timestamp) + '</span>' : '';
            var charsBadge = (e.response_chars !== undefined && e.response_chars !== null) ? '<span class="presshub-log-badge presshub-badge-chars">' + escapeHtml(e.response_chars) + ' chars</span>' : '';

            html += '<div class="presshub-log-card" data-raw="' + escapeHtml(rawJson) + '">';
            html += '<div class="presshub-log-card-header">';
            html += '<div class="presshub-log-card-badges">' + endpointBadge + ' ' + timeBadge + ' ' + traceBadge + ' ' + charsBadge + '</div>';
            html += '<div style="display: flex; gap: 4px;">';
            if (e.user_prompt || e.system_prompt) {
                html += '<button type="button" class="button button-small presshub-copy-prompt-btn">Copy Prompt</button>';
            }
            html += '<button type="button" class="button button-small presshub-copy-json-btn">Copy Raw JSON</button>';
            html += '</div>';
            html += '</div>';

            html += '<div class="presshub-log-card-body">';
            if (e.config) {
                html += '<div style="margin-bottom: 6px; font-size: 11px; color: #50575e;"><strong>Config:</strong> <code>' + escapeHtml(e.config) + '</code></div>';
            }
            if (e.system_prompt) {
                html += '<div class="presshub-log-section"><div class="presshub-log-section-header"><span>System Prompt</span> <span class="dashicons dashicons-arrow-down-alt2"></span></div><div class="presshub-log-section-content">' + escapeHtml(e.system_prompt) + '</div></div>';
            }
            if (e.user_prompt) {
                html += '<div class="presshub-log-section"><div class="presshub-log-section-header"><span>User Prompt</span> <span class="dashicons dashicons-arrow-down-alt2"></span></div><div class="presshub-log-section-content">' + escapeHtml(e.user_prompt) + '</div></div>';
            }
            if (e.response !== null && e.response !== undefined) {
                html += '<div class="presshub-log-section"><div class="presshub-log-section-header"><span>Response</span> <span class="dashicons dashicons-arrow-down-alt2"></span></div><div class="presshub-log-section-content">' + escapeHtml(e.response) + '</div></div>';
            }
            html += '</div>';
            html += '</div>';
        }
        return html;
    }

    function renderTtsCards(entries) {
        if (!entries || !entries.length) {
            return '<div style="padding: 24px; text-align: center; color: #646970;">(No TTS payload log entries found matching criteria)</div>';
        }
        var html = '';
        var reversed = entries.slice().reverse();
        for (var i = 0; i < reversed.length; i++) {
            var e = reversed[i];
            var rawJson = JSON.stringify(e, null, 2);
            var traceBadge = e.trace_id ? '<span class="presshub-log-badge presshub-badge-trace presshub-trace-filter" data-trace="' + escapeHtml(e.trace_id) + '" title="Click to filter by this trace ID">#' + escapeHtml(e.trace_id) + '</span>' : '';
            var phaseBadge = e.phase ? '<span class="presshub-log-badge presshub-badge-phase">[' + escapeHtml(e.phase.toUpperCase()) + ']</span>' : '';
            var timeBadge = e.timestamp ? '<span class="presshub-log-badge presshub-badge-timestamp">' + escapeHtml(e.timestamp) + '</span>' : '';
            var model = (e.data && e.data.model) ? e.data.model : '';
            var modelBadge = model ? '<span class="presshub-log-badge presshub-badge-model">' + escapeHtml(model) + '</span>' : '';

            html += '<div class="presshub-log-card" data-raw="' + escapeHtml(rawJson) + '">';
            html += '<div class="presshub-log-card-header">';
            html += '<div class="presshub-log-card-badges">' + phaseBadge + ' ' + timeBadge + ' ' + traceBadge + ' ' + modelBadge + '</div>';
            html += '<div>';
            html += '<button type="button" class="button button-small presshub-copy-json-btn">Copy Raw JSON</button>';
            html += '</div>';
            html += '</div>';

            html += '<div class="presshub-log-card-body">';
            if (e.data) {
                if (e.data.endpoint_masked || e.data.endpoint) {
                    html += '<div style="margin-bottom: 4px; font-size: 11px; word-break: break-all;"><strong>Endpoint:</strong> <code>' + escapeHtml(e.data.endpoint_masked || e.data.endpoint) + '</code></div>';
                }
                if (e.data.prompt_text) {
                    html += '<div class="presshub-log-section"><div class="presshub-log-section-header"><span>Prompt Text</span> <span class="dashicons dashicons-arrow-down-alt2"></span></div><div class="presshub-log-section-content">' + escapeHtml(e.data.prompt_text) + '</div></div>';
                }
                if (e.data.speaker_mapping) {
                    html += '<div class="presshub-log-section"><div class="presshub-log-section-header"><span>Speaker Mapping</span> <span class="dashicons dashicons-arrow-down-alt2"></span></div><div class="presshub-log-section-content">' + escapeHtml(JSON.stringify(e.data.speaker_mapping, null, 2)) + '</div></div>';
                }
                if (e.data.latency_ms !== undefined || e.data.bytes !== undefined || e.data.http_code !== undefined) {
                    html += '<div style="margin-top: 6px; font-size: 11px; color: #50575e;">';
                    if (e.data.http_code !== undefined) {
                        html += '<strong>HTTP:</strong> ' + escapeHtml(e.data.http_code) + ' &bull; ';
                    }
                    if (e.data.latency_ms !== undefined) {
                        html += '<strong>Latency:</strong> ' + escapeHtml(e.data.latency_ms) + 'ms &bull; ';
                    }
                    if (e.data.bytes !== undefined) {
                        html += '<strong>Bytes:</strong> ' + escapeHtml(e.data.bytes) + ' &bull; ';
                    }
                    if (e.data.mime_type !== undefined) {
                        html += '<strong>MIME:</strong> ' + escapeHtml(e.data.mime_type);
                    }
                    html += '</div>';
                }
            }
            html += '</div>';
            html += '</div>';
        }
        return html;
    }

    var logSearchDebounce = null;

    function loadDiagnosticLogs() {
        var $viewer  = $('#presshub-ai-log-viewer');
        var $cards   = $('#presshub-ai-log-cards');
        var $spinner = $('#presshub-ai-log-spinner');
        var $status  = $('#presshub-ai-log-status');
        var $info    = $('#presshub-ai-log-file-info');

        if (!$viewer.length && !$cards.length) {
            return;
        }

        var target  = $('#presshub-ai-log-target').val() || 'app';
        var search  = $.trim($('#presshub-ai-log-search').val() || '');
        var lines   = parseInt($('#presshub-ai-log-lines').val() || '50', 10);

        $spinner.addClass('is-active');
        $status.text('Loading logs...');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_get_logs',
                nonce: presshubAI.nonce,
                target: target,
                search: search,
                lines: lines
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success && res.data) {
                var sizeKb = (res.data.size_bytes / 1024).toFixed(1);
                var loadedTime = new Date().toLocaleTimeString();
                var metaText = 'File: ' + sizeKb + ' KB | Loaded: ' + loadedTime;
                if (res.data.level) {
                    metaText = 'Level: ' + res.data.level + ' | ' + metaText;
                }
                $info.text(metaText);

                if (target === 'app') {
                    $cards.hide().empty();
                    $viewer.show();
                    var content = res.data.logs || '(No log entries recorded yet)';
                    $viewer.val(content).text(content);
                    if ($viewer[0]) {
                        $viewer.scrollTop($viewer[0].scrollHeight);
                    }
                    $status.text('Showing ' + (res.data.entries ? res.data.entries.length : 0) + ' log lines.');
                } else if (target === 'prompts') {
                    $viewer.hide();
                    $cards.show().html(renderPromptCards(res.data.entries));
                    $status.text('Showing ' + (res.data.entries ? res.data.entries.length : 0) + ' prompt entries.');
                } else if (target === 'tts') {
                    $viewer.hide();
                    $cards.show().html(renderTtsCards(res.data.entries));
                    $status.text('Showing ' + (res.data.entries ? res.data.entries.length : 0) + ' TTS payload entries.');
                }
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to retrieve logs.';
                $status.text('Error: ' + err);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $status.text('Error loading logs (' + (xhr.status || 0) + ')');
        });
    }

    $(document).on('change', '#presshub-ai-log-target', function() {
        loadDiagnosticLogs();
    });

    $(document).on('change', '#presshub-ai-log-lines', function() {
        loadDiagnosticLogs();
    });

    $(document).on('input', '#presshub-ai-log-search', function() {
        clearTimeout(logSearchDebounce);
        logSearchDebounce = setTimeout(function() {
            loadDiagnosticLogs();
        }, 350);
    });

    $(document).on('click', '#presshub-ai-refresh-logs', function(e) {
        e.preventDefault();
        loadDiagnosticLogs();
    });

    $(document).on('click', '#presshub-ai-clear-logs', function(e) {
        e.preventDefault();
        var clearChoice = $('#presshub-ai-clear-target').val() || 'active';
        var activeTarget = $('#presshub-ai-log-target').val() || 'app';
        var finalTarget = ('all' === clearChoice) ? 'all' : activeTarget;

        var confirmMsg = ('all' === finalTarget)
            ? 'Are you sure you want to clear ALL 3 log files?'
            : 'Are you sure you want to clear the ' + activeTarget + ' log file?';

        if (!confirm(confirmMsg)) {
            return;
        }

        var $spinner = $('#presshub-ai-log-spinner');
        var $status  = $('#presshub-ai-log-status');
        var $viewer  = $('#presshub-ai-log-viewer');
        var $cards   = $('#presshub-ai-log-cards');

        $spinner.addClass('is-active');
        $status.text('Clearing logs...');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_clear_logs',
                nonce: presshubAI.nonce,
                target: finalTarget
            }
        }).done(function(res) {
            $spinner.removeClass('is-active');
            if (res && res.success) {
                $viewer.val('');
                $cards.empty();
                $status.text(res.data.message || 'Log file cleared.');
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to clear logs.';
                $status.text('Error: ' + err);
            }
        }).fail(function(xhr, status, error) {
            $spinner.removeClass('is-active');
            $status.text('Error clearing logs (' + (xhr.status || 0) + ')');
        });
    });

    $(document).on('click', '#presshub-ai-download-log', function(e) {
        e.preventDefault();
        var downloadChoice = $('#presshub-ai-download-target').val() || 'active';
        var activeTarget = $('#presshub-ai-log-target').val() || 'app';
        var finalTarget = ('active' === downloadChoice) ? activeTarget : downloadChoice;

        var downloadUrl = presshubAI.ajax_url + (presshubAI.ajax_url.indexOf('?') >= 0 ? '&' : '?') +
            'action=presshub_ai_download_log' +
            '&target=' + encodeURIComponent(finalTarget) +
            '&nonce=' + encodeURIComponent(presshubAI.nonce);

        window.location.href = downloadUrl;
    });

    // Trace badge click: filter by trace ID
    $(document).on('click', '.presshub-trace-filter', function(e) {
        e.preventDefault();
        var traceId = $(this).data('trace');
        if (traceId) {
            $('#presshub-ai-log-search').val(traceId);
            loadDiagnosticLogs();
        }
    });

    // Card section accordion toggle
    $(document).on('click', '.presshub-log-section-header', function() {
        var $content = $(this).next('.presshub-log-section-content');
        var $icon = $(this).find('.dashicons');
        $content.slideToggle(150, function() {
            if ($content.is(':visible')) {
                $icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
            } else {
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
            }
        });
    });

    // Copy prompt text button
    $(document).on('click', '.presshub-copy-prompt-btn', function(e) {
        e.preventDefault();
        var $card = $(this).closest('.presshub-log-card');
        var rawData = $card.attr('data-raw');
        var textToCopy = '';
        try {
            var parsed = JSON.parse(rawData);
            textToCopy = (parsed.system_prompt ? 'SYSTEM:\n' + parsed.system_prompt + '\n\n' : '') +
                         (parsed.user_prompt ? 'USER:\n' + parsed.user_prompt : '');
        } catch (err) {
            textToCopy = rawData;
        }
        copyTextToClipboard(textToCopy, $(this));
    });

    // Copy raw JSON button
    $(document).on('click', '.presshub-copy-json-btn', function(e) {
        e.preventDefault();
        var $card = $(this).closest('.presshub-log-card');
        var rawData = $card.attr('data-raw') || '';
        copyTextToClipboard(rawData, $(this));
    });
});

