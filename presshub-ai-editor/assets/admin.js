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
    // Settings Tabs Navigation with Modular Mapping & Dynamic Dashboards
    // ------------------------------------------------------------------
    function initSettingsTabs() {
        var $tabs = $('#presshub-ai-settings-tabs');
        if (!$tabs.length) {
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

            $tabs.find('.nav-tab').removeClass('nav-tab-active');
            $tabs.find('.nav-tab[data-tab="' + tabKey + '"]').addClass('nav-tab-active');

            $('.presshub-tab-pane').hide();
            $('#presshub-tab-pane-' + tabKey).show();

            // Form Submit Button visibility on different tabs
            if (tabKey === 'providers' || tabKey === 'token_logs') {
                $('#presshub-settings-submit-wrap').hide();
            } else {
                $('#presshub-settings-submit-wrap').show();
            }

            if (tabKey === 'token_logs') {
                loadTokenLogs(1);
            } else if (tabKey === 'advanced') {
                loadDiagnosticLogs();
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
        if (initialHash) {
            var targetTab = tabAliases[initialHash] || initialHash;
            if ($tabs.find('.nav-tab[data-tab="' + targetTab + '"]').length) {
                switchTab(targetTab);
            }
        }
    }

    initSettingsTabs();

    // ------------------------------------------------------------------
    // Dynamic AI Providers Manager Logic
    // ------------------------------------------------------------------
    var providerTemplates = (typeof presshubAI !== 'undefined' && presshubAI.provider_templates) ? presshubAI.provider_templates : {};
    var configuredProviders = (typeof presshubAI !== 'undefined' && presshubAI.configured_providers) ? presshubAI.configured_providers : [];

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
            $('#provider-form-default-model').val(providerData.default_model || '');
            var avail = Array.isArray(providerData.available_models) ? providerData.available_models.join(', ') : (providerData.available_models || '');
            $('#provider-form-available-models').val(avail);
            $('#provider-form-temperature').val(providerData.temperature !== undefined ? providerData.temperature : 0.7);
            $('#provider-form-max-tokens').val(providerData.max_tokens !== undefined ? providerData.max_tokens : 10000);
            $('#provider-form-timeout').val(providerData.timeout !== undefined ? providerData.timeout : 300);
            var headers = providerData.headers ? (typeof providerData.headers === 'object' ? JSON.stringify(providerData.headers) : providerData.headers) : '';
            $('#provider-form-headers').val(headers);
            $('#provider-form-enabled').prop('checked', !!providerData.enabled);
            $('#provider-form-api-key').val('').attr('placeholder', providerData.api_key ? '••••••••' : __('Enter API Key', 'presshub-ai-editor'));
            $('#provider-form-template').val('');
        } else {
            $title.text(__('Add New AI Provider', 'presshub-ai-editor'));
            $('#presshub-provider-form')[0].reset();
            $('#provider-form-id').val('');
            $('#provider-form-enabled').prop('checked', true);
            $('#provider-form-temperature').val('0.7');
            $('#provider-form-max-tokens').val('10000');
            $('#provider-form-timeout').val('300');
            $('#provider-form-api-key').attr('placeholder', __('Enter API Key', 'presshub-ai-editor'));
        }

        $modal.show().addClass('is-open');
        $('body').addClass('presshub-modal-open');
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
        closeProviderModal();
        closeLogDetailsModal();
    });

    $(document).on('click', '#presshub-provider-modal, #presshub-log-details-modal', function(e) {
        if ($(e.target).is('#presshub-provider-modal')) {
            closeProviderModal();
        }
        if ($(e.target).is('#presshub-log-details-modal')) {
            closeLogDetailsModal();
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            if ($('#presshub-provider-modal').is(':visible')) {
                closeProviderModal();
            }
            if ($('#presshub-log-details-modal').is(':visible')) {
                closeLogDetailsModal();
            }
        }
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
        $('#provider-form-default-model').val(tmpl.default_model || '');
        var avail = Array.isArray(tmpl.available_models) ? tmpl.available_models.join(', ') : '';
        $('#provider-form-available-models').val(avail);
        $('#provider-form-temperature').val(tmpl.temperature !== undefined ? tmpl.temperature : 0.7);
        $('#provider-form-max-tokens').val(tmpl.max_tokens !== undefined ? tmpl.max_tokens : 10000);
        $('#provider-form-timeout').val(tmpl.timeout !== undefined ? tmpl.timeout : 300);
    });

    // Edit Provider Card Click
    $(document).on('click', '.presshub-edit-provider-btn', function(e) {
        e.preventDefault();
        var providerId = $(this).data('provider-id');
        var providerRecord = configuredProviders.find(function(p) { return p.id === providerId; });
        if (!providerRecord) {
            // Read from DOM card attributes if not found in memory
            var $card = $(this).closest('.presshub-provider-card');
            providerRecord = {
                id: providerId,
                name: $card.find('.presshub-card-name').text().trim(),
                type: $card.data('provider-type') || 'openai',
                enabled: $card.hasClass('is-enabled')
            };
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

        var providerData = {
            id: $('#provider-form-id').val().trim(),
            name: name,
            type: $('#provider-form-type').val(),
            base_url: $('#provider-form-base-url').val().trim(),
            api_key: $('#provider-form-api-key').val().trim(),
            default_model: $('#provider-form-default-model').val().trim(),
            available_models: $('#provider-form-available-models').val().trim(),
            temperature: parseFloat($('#provider-form-temperature').val()) || 0.7,
            max_tokens: parseInt($('#provider-form-max-tokens').val(), 10) || 10000,
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

        var providerData = {
            id: $('#provider-form-id').val().trim(),
            name: $('#provider-form-name').val().trim(),
            type: $('#provider-form-type').val(),
            base_url: $('#provider-form-base-url').val().trim(),
            api_key: $('#provider-form-api-key').val().trim(),
            default_model: $('#provider-form-default-model').val().trim(),
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
                if (res.data && res.data.sources !== undefined) {
                    $('#presshub_ai_briefing_sources').val(res.data.sources);
                    if (res.data.sources.length > 0) {
                        successMsg += ' [Sources: ' + res.data.sources.split('\n').length + ' URLs]';
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
                $viewer.val(content).text(content);
                if ($viewer[0]) {
                    $viewer.scrollTop($viewer[0].scrollHeight);
                }
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

