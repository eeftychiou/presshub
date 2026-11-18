/**
 * PressHub AI — classic editor (post.php / post-new.php) admin JS.
 *
 * Drives the metabox buttons (Generate Initial Draft, Run AI Editorial Review)
 * and the PressHub → Settings "Test Connection" buttons. Strings are
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
    const { __, sprintf } = wp.i18n;

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
        } else if (type === 'warning') {
            cssClass = 'notice-warning';
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

    function openModal($modal) {
        if (!$modal || !$modal.length) {
            return;
        }
        $modal.removeAttr('hidden')
            .prop('hidden', false)
            .css('display', 'flex')
            .addClass('is-open');
        $('body').addClass('presshub-modal-open');
    }

    function closeModal($modal) {
        if (!$modal || !$modal.length) {
            return;
        }
        $modal.removeClass('is-open')
            .attr('hidden', 'hidden')
            .prop('hidden', true)
            .css('display', 'none')
            .hide();
        $('body').removeClass('presshub-modal-open');
        // Focus goes back to the button that opened the dialog.
        var opener = $modal.data('presshub-opener');
        $modal.removeData('presshub-opener');
        if (opener && document.body.contains(opener) && typeof opener.focus === 'function') {
            opener.focus();
        }
    }

    function ensureDraftModal() {
        var $modal = $('#presshub-draft-modal, #presshub-draft-diff-modal');
        if ($modal.length) {
            return $modal.first();
        }

        $modal = $(
            '<div class="presshub-modal-backdrop presshub-draft-diff-modal" id="presshub-draft-modal" ' +
            'role="dialog" aria-modal="true" aria-labelledby="presshub-draft-modal-title" ' +
            'aria-describedby="presshub-draft-modal-desc" tabindex="-1" hidden style="display:none;">' +
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
        $modal.find('#presshub-draft-accept').on('click', function(e) {
            e.preventDefault();
            acceptDraft();
        });
        $modal.find('#presshub-draft-reject').on('click', function(e) {
            e.preventDefault();
            closeDraftModal(true);
        });
        $modal.find('.presshub-modal-cancel').on('click', function(e) {
            e.preventDefault();
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

    function openDraftModal(draftHtml, currentHtml, $returnFocus, seo) {
        var $modal = ensureDraftModal();
        presshubPendingDraft = { html: draftHtml, seo: seo || null };
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

        openModal($modal);
        presshubModalOpen = true;
        // Move focus into the dialog (first focusable = Insert draft).
        $modal.find('#presshub-draft-accept').trigger('focus');
    }

    function closeDraftModal(discarded) {
        var $modal = $('#presshub-draft-modal, #presshub-draft-diff-modal');
        var $returnFocus = presshubModalReturnFocus;
        presshubModalOpen = false;
        presshubPendingDraft = null;
        presshubModalReturnFocus = null;
        closeModal($modal);
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
            closeDraftModal(false);
            return;
        }
        var draftHtml = presshubPendingDraft.html;
        var draftSeo = presshubPendingDraft.seo;
        var $generate = presshubModalReturnFocus || $('#presshub-ai-generate-draft');
        insertDraft(draftHtml);
        if (draftSeo) {
            applySeoSuggestions(draftSeo.tags || [], draftSeo.meta_description || '', false, $generate);
        }
        closeDraftModal(false);
        if ($generate && $generate.length) {
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
        if (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27) {
            var $draftModal = $('#presshub-draft-modal, #presshub-draft-diff-modal');
            if (presshubModalOpen || ($draftModal.length && $draftModal.is(':visible'))) {
                e.preventDefault();
                closeDraftModal(false);
            }
        }
    });

    $(document).on('click', '#presshub-draft-modal #presshub-draft-accept, #presshub-draft-diff-modal #presshub-draft-accept', function(e) {
        e.preventDefault();
        acceptDraft();
    });
    $(document).on('click', '#presshub-draft-modal #presshub-draft-reject, #presshub-draft-diff-modal #presshub-draft-reject', function(e) {
        e.preventDefault();
        closeDraftModal(true);
    });
    $(document).on('click', '#presshub-draft-modal .presshub-modal-cancel, #presshub-draft-diff-modal .presshub-modal-cancel, #presshub-draft-modal .presshub-modal-close, #presshub-draft-diff-modal .presshub-modal-close', function(e) {
        e.preventDefault();
        closeDraftModal(false);
    });

    // ------------------------------------------------------------------
    // SEO helpers: tags + meta description (excerpt) in the editor, and
    // the SEO review panel.
    // ------------------------------------------------------------------

    function decodeEntities(str) {
        var txt = document.createElement('textarea');
        txt.innerHTML = String(str || '');
        return txt.value;
    }

    // Resolve tag names to term IDs, creating missing tags (block editor).
    function resolveTagIds(names) {
        return Promise.all(names.map(function(name) {
            return wp.apiFetch({
                path: '/wp/v2/tags?search=' + encodeURIComponent(name) + '&per_page=20&_fields=id,name'
            }).then(function(list) {
                var wanted = String(name).toLocaleLowerCase();
                for (var i = 0; i < (list || []).length; i++) {
                    if (decodeEntities(list[i].name).toLocaleLowerCase() === wanted) {
                        return list[i].id;
                    }
                }
                return wp.apiFetch({ path: '/wp/v2/tags', method: 'POST', data: { name: name } })
                    .then(function(term) { return term.id; });
            }).catch(function(err) {
                if (err && err.code === 'term_exists' && err.data && err.data.term_id) {
                    return err.data.term_id;
                }
                return null;
            });
        })).then(function(ids) {
            return ids.filter(function(id) { return !!id; });
        });
    }

    // Add tags and (optionally) the meta description to the post being edited.
    // Each outcome shows its own notice: tags added, already present, failed,
    // excerpt updated, or no excerpt field to update.
    function applySeoSuggestions(tags, metaDescription, overwriteExcerpt, $anchor) {
        tags = (tags || []).filter(function(t) { return !!t; });
        var select = wp.data && wp.data.select && wp.data.select('core/editor');
        if (select && wp.data.dispatch && wp.apiFetch) {
            var dispatcher = wp.data.dispatch('core/editor');
            if (metaDescription) {
                if (overwriteExcerpt || !select.getEditedPostAttribute('excerpt')) {
                    dispatcher.editPost({ excerpt: metaDescription });
                    if (overwriteExcerpt) {
                        showNotice(__('Excerpt updated. Save the post to keep it.', 'presshub-ai-editor'), 'success', $anchor);
                    }
                }
            }
            if (!tags.length) {
                return;
            }
            resolveTagIds(tags).then(function(ids) {
                var current = select.getEditedPostAttribute('tags') || [];
                var merged = current.slice();
                var added = 0;
                ids.forEach(function(id) {
                    if (merged.indexOf(id) === -1) {
                        merged.push(id);
                        added++;
                    }
                });
                var skipped = tags.length - ids.length;
                if (added > 0) {
                    dispatcher.editPost({ tags: merged });
                    var msg = __('Added %s suggested tags. Save the post to keep them.', 'presshub-ai-editor').replace('%s', added);
                    if (skipped > 0) {
                        msg += ' ' + __('%s could not be added.', 'presshub-ai-editor').replace('%s', skipped);
                    }
                    showNotice(msg, skipped > 0 ? 'warning' : 'success', $anchor);
                } else if (ids.length) {
                    showNotice(__('The suggested tags are already on this post.', 'presshub-ai-editor'), 'info', $anchor);
                } else {
                    showNotice(__('Could not add the suggested tags. Check that you can create tags, then try again.', 'presshub-ai-editor'), 'error', $anchor);
                }
            });
            return;
        }
        // Classic editor fallback.
        if (metaDescription) {
            var $excerpt = $('#excerpt');
            if ($excerpt.length && (overwriteExcerpt || !$excerpt.val())) {
                $excerpt.val(metaDescription);
                if (overwriteExcerpt) {
                    showNotice(__('Excerpt updated. Save the post to keep it.', 'presshub-ai-editor'), 'success', $anchor);
                }
            } else if (!$excerpt.length && overwriteExcerpt) {
                showNotice(__('No excerpt field found. Enable "Excerpt" in Screen Options and try again.', 'presshub-ai-editor'), 'error', $anchor);
            }
        }
        if (tags.length) {
            var $newTag = $('#new-tag-post_tag');
            if ($newTag.length) {
                $newTag.val(tags.join(', '));
                $newTag.closest('.tagsdiv, .ajaxtag').find('.tagadd').trigger('click');
                showNotice(__('Suggested tags added. Save the post to keep them.', 'presshub-ai-editor'), 'success', $anchor);
            } else {
                showNotice(__('No tags box found on this screen.', 'presshub-ai-editor'), 'error', $anchor);
            }
        }
    }

    function collectSeoContext() {
        var ctx = { title: '', excerpt: '', tag_ids: [], tag_names: [], category_ids: [], featured_media: 0 };
        var select = wp.data && wp.data.select && wp.data.select('core/editor');
        if (select) {
            ctx.title = select.getEditedPostAttribute('title') || '';
            ctx.excerpt = select.getEditedPostAttribute('excerpt') || '';
            ctx.tag_ids = select.getEditedPostAttribute('tags') || [];
            ctx.category_ids = select.getEditedPostAttribute('categories') || [];
            ctx.featured_media = select.getEditedPostAttribute('featured_media') || 0;
        } else {
            ctx.title = $('#title').val() || '';
            ctx.excerpt = $('#excerpt').val() || '';
            var rawTags = $('textarea[name="tax_input[post_tag]"]').val() || '';
            ctx.tag_names = rawTags.split(',').map(function(t) { return $.trim(t); }).filter(function(t) { return !!t; });
            $('#categorychecklist input:checked').each(function() {
                ctx.category_ids.push(parseInt($(this).val(), 10));
            });
            ctx.featured_media = parseInt($('#_thumbnail_id').val(), 10) || 0;
        }
        return ctx;
    }

    function renderSeoReview(seo) {
        var $box = $('#presshub-ai-seo-results');
        if (!$box.length) {
            return;
        }
        if (!seo || seo.seo_score === undefined || seo.seo_score === null) {
            $box.empty();
            return;
        }
        var score = parseInt(seo.seo_score, 10);
        var tone = score < 50 ? 'presshub-score-low' : (score < 75 ? 'presshub-score-mid' : 'presshub-score-high');
        var html = '<div class="scorecard-box presshub-seo-box ' + tone + '">' +
            '<span class="presshub-score-badge" aria-hidden="true">' + presshubEsc(score) + '/100</span>' +
            '<strong>' + __('SEO Score: %s/100', 'presshub-ai-editor').replace('%s', presshubEsc(score)) + '</strong>';
        if (seo.focus_keyphrase) {
            html += '<p class="presshub-seo-keyphrase">' + presshubEsc(__('Focus keyphrase:', 'presshub-ai-editor')) + ' <code>' + presshubEsc(seo.focus_keyphrase) + '</code></p>';
        }
        if (seo.summary) {
            html += '<p>' + presshubEsc(seo.summary) + '</p>';
        }
        if (seo.suggestions && seo.suggestions.length) {
            html += '<strong>' + presshubEsc(__('Suggestions', 'presshub-ai-editor')) + '</strong><ul class="presshub-seo-suggestions">';
            seo.suggestions.forEach(function(s) {
                html += '<li>' + presshubEsc(s) + '</li>';
            });
            html += '</ul>';
        }
        var issues = (seo.checks || []).filter(function(c) { return c.status !== 'pass'; });
        if (issues.length) {
            html += '<details class="presshub-seo-checks"><summary>' +
                presshubEsc(__('On-page checks needing attention (%s)', 'presshub-ai-editor').replace('%s', issues.length)) +
                '</summary><ul>';
            issues.forEach(function(c) {
                html += '<li class="presshub-seo-' + presshubEsc(c.status) + '"><strong>' + presshubEsc(c.label) + ':</strong> ' + presshubEsc(c.detail) + '</li>';
            });
            html += '</ul></details>';
        }
        var actions = '';
        if (seo.suggested_tags && seo.suggested_tags.length) {
            html += '<p class="presshub-seo-tags"><strong>' + presshubEsc(__('Suggested tags:', 'presshub-ai-editor')) + '</strong> ' +
                seo.suggested_tags.map(function(t) { return '<span class="presshub-seo-tag">' + presshubEsc(t) + '</span>'; }).join(' ') + '</p>';
            actions += '<button type="button" class="button presshub-seo-apply-tags">' + presshubEsc(__('Add suggested tags', 'presshub-ai-editor')) + '</button> ';
        }
        if (seo.suggested_meta_description) {
            html += '<p class="presshub-seo-desc"><strong>' + presshubEsc(__('Suggested meta description:', 'presshub-ai-editor')) + '</strong> ' + presshubEsc(seo.suggested_meta_description) + '</p>';
            actions += '<button type="button" class="button presshub-seo-apply-desc">' + presshubEsc(__('Use as excerpt', 'presshub-ai-editor')) + '</button>';
        }
        if (actions) {
            html += '<p class="presshub-seo-actions">' + actions + '</p>';
        }
        html += '</div>';
        $box.html(html).data('seo', seo);
    }

    $(document).on('click', '#presshub-ai-seo-results .presshub-seo-apply-tags', function(e) {
        e.preventDefault();
        var seo = $('#presshub-ai-seo-results').data('seo') || {};
        applySeoSuggestions(seo.suggested_tags || [], '', false, $(this));
    });
    $(document).on('click', '#presshub-ai-seo-results .presshub-seo-apply-desc', function(e) {
        e.preventDefault();
        var seo = $('#presshub-ai-seo-results').data('seo') || {};
        if (!seo.suggested_meta_description) {
            showNotice(__('There is no suggested meta description to use.', 'presshub-ai-editor'), 'info', $(this));
            return;
        }
        applySeoSuggestions([], seo.suggested_meta_description, true, $(this));
    });

    // Render a previously saved SEO review on page load.
    (function() {
        var $box = $('#presshub-ai-seo-results');
        if ($box.length && $box.attr('data-seo')) {
            try {
                renderSeoReview(JSON.parse($box.attr('data-seo')));
            } catch (err) {
                // Ignore malformed saved data.
            }
        }
    })();

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
        var minScore = (window.presshubAI && parseInt(window.presshubAI.qa_min_score, 10)) || 80;
        if (s < 50) {
            return 'presshub-score-low';
        }
        if (s < minScore) {
            return 'presshub-score-mid';
        }
        return 'presshub-score-high';
    }

    function renderScorecard(score, feedback, $btn, libelRisk, legalConcerns) {
        var scoreText = __('Score: %s/100', 'presshub-ai-editor')
            .replace('%s', presshubEsc(score));
        var tone = scorecardTone(score);
        var libelHtml = '';
        if (libelRisk) {
            var libelClass = 'presshub-libel-' + String(libelRisk).toLowerCase();
            var libelLabel = __('Libel Risk: %s', 'presshub-ai-editor')
                .replace('%s', presshubEsc(libelRisk));
            libelHtml = ' <span class="presshub-libel-badge ' + presshubEsc(libelClass) + '">' +
                libelLabel + '</span>';
        }
        var legalHtml = '';
        if (legalConcerns && legalConcerns !== 'None identified') {
            legalHtml = '<div class="presshub-legal-concerns">' +
                '<strong>' + __('Legal Concerns:', 'presshub-ai-editor') + '</strong>' +
                '<p>' + presshubEsc(legalConcerns) + '</p>' +
                '</div>';
        }
        var html = '<div class="scorecard-box ' + tone + '">' +
            '<span class="presshub-score-badge" aria-hidden="true">' +
            presshubEsc(score) + '/100</span>' +
            '<strong>' + scoreText + '</strong>' +
            libelHtml +
            '<p>' + presshubEsc(feedback) + '</p>' +
            legalHtml +
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
        formData.append('title', collectSeoContext().title);
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
                        $btn,
                        response.data.seo || null
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

        var seoCtx = collectSeoContext();
        $.post(presshubAI.ajax_url, {
            action: 'presshub_ai_run_review',
            nonce: presshubAI.nonce,
            post_id: postId,
            content: content,
            has_seo_context: 1,
            title: seoCtx.title,
            excerpt: seoCtx.excerpt,
            tag_ids: seoCtx.tag_ids,
            tag_names: seoCtx.tag_names,
            category_ids: seoCtx.category_ids,
            featured_media: seoCtx.featured_media
        }, function(response) {
            $('#presshub-ai-review-spinner').removeClass('is-active');
            $('#presshub-ai-review-spinner .screen-reader-text').text('');
            $btn.prop('disabled', false);
            if (response && response.success) {
                renderScorecard(
                    response.data.score,
                    response.data.feedback,
                    $btn,
                    response.data.libel_risk,
                    response.data.legal_concerns
                );
                renderSeoReview(response.data.seo || null);

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
    // Podcast Dialogue Script Regeneration
    // ------------------------------------------------------------------

    $(document).on('click', '#presshub-regenerate-podcast-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var postId = $btn.data('post-id');
        var script = $('#presshub_podcast_script').val();
        var $spinner = $('#presshub-regenerate-podcast-spinner');
        var $status = $('#presshub-regenerate-podcast-status');

        if (!script || !script.trim()) {
            $status.css('color', '#dc3232').text(__('Dialogue script cannot be empty.', 'presshub-ai-editor'));
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.css('color', '#555').text(__('Regenerating audio... this may take a moment.', 'presshub-ai-editor'));

        $.ajax({
            url: (typeof presshubAI !== 'undefined' ? presshubAI.ajax_url : ajaxurl),
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'presshub_ai_regenerate_podcast_audio',
                nonce: (typeof presshubAI !== 'undefined' ? presshubAI.nonce : ''),
                post_id: postId,
                script: script
            }
        }).done(function(response) {
            if (response && response.success) {
                $status.css('color', '#46b450').text(response.data.message || __('Audio regenerated successfully!', 'presshub-ai-editor'));
                if (response.data.audio_url) {
                    var $player = $('#presshub-podcast-audio-player');
                    if ($player.length) {
                        $player.attr('src', response.data.audio_url);
                        if ($player[0] && $player[0].load) {
                            $player[0].load();
                        }
                    }
                }
            } else {
                var msg = (response && response.data && response.data.message) ? response.data.message : __('Failed to regenerate audio.', 'presshub-ai-editor');
                $status.css('color', '#dc3232').text(msg);
            }
        }).fail(function(xhr) {
            var msg = __('Failed to regenerate audio. Server error.', 'presshub-ai-editor');
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            $status.css('color', '#dc3232').text(msg);
        }).always(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // ------------------------------------------------------------------
    // AI Featured Image Studio handlers
    // ------------------------------------------------------------------

    // Prompt templates: the instructions the AI follows when it drafts the
    // image prompt. Picking one fills the editable template text; the draft
    // request sends whatever is in that box. Edits can be saved as a new
    // shared template.
    var imageTemplates = [];
    (function initImageTemplates() {
        var $wrap = $('#presshub-ai-image-templates');
        if (!$wrap.length) {
            return;
        }
        var raw = $wrap.attr('data-templates');
        try {
            imageTemplates = raw ? JSON.parse(raw) : [];
        } catch (err) {
            imageTemplates = [];
        }
        refreshImageTemplateControls();
    })();

    function findImageTemplate(id) {
        for (var i = 0; i < imageTemplates.length; i++) {
            if (imageTemplates[i].id === id) {
                return imageTemplates[i];
            }
        }
        return null;
    }

    function setImageTemplateStatus(text, isError) {
        $('#presshub-ai-image-template-status')
            .removeClass('is-error is-ok')
            .addClass(text ? (isError ? 'is-error' : 'is-ok') : '')
            .text(text || '');
    }

    function refreshImageTemplateControls() {
        var tpl = findImageTemplate($('#presshub-ai-image-template-select').val());
        var $del = $('#presshub-ai-image-template-delete');
        $del.prop('hidden', !(tpl && tpl.deletable)).removeData('armed')
            .text(__('Delete this template', 'presshub-ai-editor'));
    }

    $(document).on('change', '#presshub-ai-image-template-select', function() {
        var tpl = findImageTemplate($(this).val());
        $('#presshub-ai-image-template-text').val(tpl ? tpl.template : '').scrollTop(0);
        setImageTemplateStatus('');
        refreshImageTemplateControls();
    });

    $(document).on('click', '#presshub-ai-image-template-toggle', function(e) {
        e.preventDefault();
        var $editor = $('#presshub-ai-image-template-editor');
        var show = $editor.prop('hidden');
        $editor.prop('hidden', !show);
        $(this).attr('aria-expanded', show ? 'true' : 'false')
            .text(show ? __('Hide template', 'presshub-ai-editor') : __('View / edit template', 'presshub-ai-editor'));
    });

    $(document).on('click', '#presshub-ai-image-template-reset', function(e) {
        e.preventDefault();
        var tpl = findImageTemplate($('#presshub-ai-image-template-select').val());
        $('#presshub-ai-image-template-text').val(tpl ? tpl.template : '');
        setImageTemplateStatus(__('Template text restored.', 'presshub-ai-editor'), false);
    });

    $(document).on('click', '#presshub-ai-image-template-save', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var name = ($('#presshub-ai-image-template-name').val() || '').trim();
        var text = ($('#presshub-ai-image-template-text').val() || '').trim();
        if (!name) {
            setImageTemplateStatus(__('Give the template a name.', 'presshub-ai-editor'), true);
            $('#presshub-ai-image-template-name').trigger('focus');
            return;
        }
        if (!text) {
            setImageTemplateStatus(__('The template text is empty.', 'presshub-ai-editor'), true);
            return;
        }
        $btn.prop('disabled', true);
        setImageTemplateStatus(__('Saving…', 'presshub-ai-editor'), false);

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: {
                action: 'presshub_ai_save_image_prompt_template',
                nonce: presshubAI.nonce,
                name: name,
                template: text
            }
        }).done(function(response) {
            if (response && response.success && response.data && response.data.template) {
                var saved = response.data.template;
                imageTemplates.push({
                    id: saved.id,
                    name: saved.name,
                    template: saved.template,
                    builtin: false,
                    deletable: true
                });
                $('<option>').val(saved.id).text(saved.name)
                    .appendTo('#presshub-ai-image-template-custom-group');
                $('#presshub-ai-image-template-select').val(saved.id);
                $('#presshub-ai-image-template-text').val(saved.template).scrollTop(0);
                $('#presshub-ai-image-template-name').val('');
                refreshImageTemplateControls();
                setImageTemplateStatus(__('Saved as a new template.', 'presshub-ai-editor'), false);
            } else {
                setImageTemplateStatus((response && response.data) ? response.data : __('Could not save the template.', 'presshub-ai-editor'), true);
            }
        }).fail(function(xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : __('Could not save the template. Server error.', 'presshub-ai-editor');
            setImageTemplateStatus(msg, true);
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    // Delete asks for a second click instead of a confirm() dialog.
    $(document).on('click', '#presshub-ai-image-template-delete', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var id = $('#presshub-ai-image-template-select').val();
        if (!$btn.data('armed')) {
            $btn.data('armed', true).text(__('Click again to delete', 'presshub-ai-editor'));
            return;
        }
        $btn.prop('disabled', true);
        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: {
                action: 'presshub_ai_delete_image_prompt_template',
                nonce: presshubAI.nonce,
                template_id: id
            }
        }).done(function(response) {
            if (response && response.success) {
                imageTemplates = imageTemplates.filter(function(t) { return t.id !== id; });
                $('#presshub-ai-image-template-select option').filter(function() { return $(this).val() === id; }).remove();
                $('#presshub-ai-image-template-select').val(imageTemplates.length ? imageTemplates[0].id : '').trigger('change');
                setImageTemplateStatus(__('Template deleted.', 'presshub-ai-editor'), false);
            } else {
                refreshImageTemplateControls();
                setImageTemplateStatus((response && response.data) ? response.data : __('Could not delete the template.', 'presshub-ai-editor'), true);
            }
        }).fail(function(xhr) {
            refreshImageTemplateControls();
            var msg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : __('Could not delete the template. Server error.', 'presshub-ai-editor');
            setImageTemplateStatus(msg, true);
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '#presshub-ai-draft-image-prompt', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var postId = $btn.data('post-id') || 0;
        var content = getEditorContent();
        var title = '';
        if (wp.data && wp.data.select && wp.data.select('core/editor')) {
            title = wp.data.select('core/editor').getEditedPostAttribute('title') || '';
        } else {
            title = $('#title').val() || '';
        }

        var $spinner = $('#presshub-ai-image-spinner');
        var $status = $('#presshub-ai-image-status');

        $spinner.addClass('is-active');
        $btn.prop('disabled', true);
        $status.text('');

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: {
                action: 'presshub_ai_generate_image_prompt',
                nonce: presshubAI.nonce,
                post_id: postId,
                content: content,
                title: title,
                template_id: $('#presshub-ai-image-template-select').val() || '',
                template: $('#presshub-ai-image-template-text').val() || ''
            }
        }).done(function(response) {
            if (response && response.success && response.data && response.data.prompt) {
                $('#presshub-ai-image-prompt').val(response.data.prompt).trigger('change');
                $status.css('color', '#46b450').text(__('Prompt drafted successfully!', 'presshub-ai-editor'));
            } else {
                var msg = (response && response.data) ? response.data : __('Failed to draft prompt.', 'presshub-ai-editor');
                $status.css('color', '#dc3232').text(msg);
            }
        }).fail(function(xhr) {
            var msg = __('Failed to draft prompt. Server error.', 'presshub-ai-editor');
            if (xhr.responseJSON && xhr.responseJSON.data) {
                msg = xhr.responseJSON.data;
            }
            $status.css('color', '#dc3232').text(msg);
        }).always(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    $(document).on('click', '#presshub-ai-generate-image-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var postId = $btn.data('post-id') || 0;
        var prompt = $('#presshub-ai-image-prompt').val() || '';
        var $spinner = $('#presshub-ai-image-spinner');
        var $status = $('#presshub-ai-image-status');

        if (!prompt || !prompt.trim()) {
            $status.css('color', '#dc3232').text(__('Please draft or enter an image prompt first.', 'presshub-ai-editor'));
            return;
        }

        $spinner.addClass('is-active');
        $btn.prop('disabled', true);
        $status.css('color', '#2271b1').text(__('Synthesizing image… This may take up to 30 seconds.', 'presshub-ai-editor'));

        var imgTitle = '';
        if (wp.data && wp.data.select && wp.data.select('core/editor')) {
            imgTitle = wp.data.select('core/editor').getEditedPostAttribute('title') || '';
        } else {
            imgTitle = $('#title').val() || '';
        }

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: {
                action: 'presshub_ai_generate_featured_image',
                nonce: presshubAI.nonce,
                post_id: postId,
                prompt: prompt,
                title: imgTitle,
                content: getEditorContent()
            }
        }).done(function(response) {
            if (response && response.success && response.data && response.data.url) {
                var imgData = response.data;
                $('#presshub-ai-image-preview-img').attr('src', imgData.url);
                $('#presshub-ai-image-preview-container').show();
                var $setBtn = $('#presshub-ai-set-featured-btn');
                $setBtn.data('attachment-id', imgData.id).prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-image-rotate" style="vertical-align: middle; margin-right: 4px;"></span> ' + __('Regenerate Image', 'presshub-ai-editor'));
                $status.css('color', '#46b450').text(__('Image synthesized & saved to Media Library (ID: #%s)', 'presshub-ai-editor').replace('%s', imgData.id));
                if (imgData.alt_text) {
                    $('#presshub-ai-image-preview-img').attr('alt', imgData.alt_text);
                }
                if (imgData.title) {
                    $status.append($('<br>')).append($('<span>').css('color', '#50575e').text(__('Title: ', 'presshub-ai-editor') + imgData.title));
                }
            } else {
                var msg = (response && response.data) ? response.data : __('Image generation failed.', 'presshub-ai-editor');
                $status.css('color', '#dc3232').text(msg);
            }
        }).fail(function(xhr) {
            var msg = __('Image generation failed. Server error.', 'presshub-ai-editor');
            if (xhr.responseJSON && xhr.responseJSON.data) {
                msg = xhr.responseJSON.data;
            }
            $status.css('color', '#dc3232').text(msg);
        }).always(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    $(document).on('click', '#presshub-ai-set-featured-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var postId = $btn.data('post-id') || 0;
        var attachmentId = $btn.data('attachment-id') || 0;
        var prompt = $('#presshub-ai-image-prompt').val() || '';
        var $status = $('#presshub-ai-image-status');
        var $spinner = $('#presshub-ai-image-spinner');

        if (!postId || !attachmentId) {
            $status.css('color', '#dc3232').text(__('Missing post or attachment ID.', 'presshub-ai-editor'));
            return;
        }

        $spinner.addClass('is-active');
        $btn.prop('disabled', true);

        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            data: {
                action: 'presshub_ai_set_featured_image',
                nonce: presshubAI.nonce,
                post_id: postId,
                attachment_id: attachmentId,
                prompt: prompt
            }
        }).done(function(response) {
            if (response && response.success) {
                $status.css('color', '#46b450').text(__('Assigned as Featured Image!', 'presshub-ai-editor'));
                if (wp.data && wp.data.dispatch && wp.data.dispatch('core/editor')) {
                    try {
                        wp.data.dispatch('core/editor').editPost({ featured_media: parseInt(attachmentId, 10) });
                    } catch (err) {}
                }
            } else {
                var msg = (response && response.data && response.data.message) ? response.data.message : (response && response.data ? response.data : __('Failed to assign featured image.', 'presshub-ai-editor'));
                $status.css('color', '#dc3232').text(msg);
            }
        }).fail(function(xhr) {
            var msg = __('Failed to assign featured image.', 'presshub-ai-editor');
            if (xhr.responseJSON && xhr.responseJSON.data) {
                msg = xhr.responseJSON.data;
            }
            $status.css('color', '#dc3232').text(msg);
        }).always(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
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
    /**
     * After a successful save, show the mailbox password the way a page load
     * would: masked when one is stored, empty (and "Remove" unchecked) after
     * removing it. Keeps the plain password out of the page.
     */
    function resetPressInboxPasswordField() {
        resetSocialSecretFields();
    }

    /**
     * After a successful save, show every secret the way a page load would
     * (#247): a new value as saved (masked), a removed one as "Not set", the
     * input closed and emptied, and "Remove" unticked so a key typed later is
     * not removed again. The saved value never comes back to the page.
     */
    function resetSocialSecretFields() {
        $('#presshub-settings-page .presshub-secret').each(function() {
            var $box = $(this);
            var $input = $box.find('.presshub-secret-edit input');
            var $remove = $box.find('.presshub-secret-remove input[type="checkbox"]');
            var $current = $box.find('.presshub-secret-current');
            var $replace = $box.find('.presshub-secret-replace');
            // The server trims, so spaces alone keep the saved key.
            var typed = $.trim(String($input.val() || ''));
            if (typed !== '' && typed.indexOf('••••') === -1) {
                $current.addClass('code').text('•••••••• ' + __('(new key saved)', 'presshub-ai-editor'));
                $replace.text($replace.data('replace-label') || __('Replace key', 'presshub-ai-editor'));
                $remove.closest('label').prop('hidden', false).show();
            } else if ($remove.length && $remove.is(':checked')) {
                $current.removeClass('code').text($current.data('empty-label') || __('Not set', 'presshub-ai-editor'));
                $replace.text($replace.data('add-label') || __('Add key', 'presshub-ai-editor'));
                $remove.closest('label').hide();
            }
            $remove.prop('checked', false);
            $input.val('');
            closeSecretEdit($box, false);
        });
    }

    // #247: one "Replace key" pattern for every secret. Without JS the input
    // is simply shown; with JS it opens from the Replace key button.
    function closeSecretEdit($box, focus) {
        var $edit = $box.find('.presshub-secret-edit');
        var $replace = $box.find('.presshub-secret-replace');
        $edit.addClass('presshub-is-hidden');
        $replace.prop('hidden', false).attr('aria-expanded', 'false');
        if (focus) {
            $replace.trigger('focus');
        }
    }
    $('.presshub-secret').each(function() {
        var $box = $(this);
        $box.find('.presshub-secret-cancel').prop('hidden', false);
        closeSecretEdit($box, false);
    });
    $(document).on('click', '.presshub-secret-replace', function(e) {
        e.preventDefault();
        var $box = $(this).closest('.presshub-secret');
        $box.find('.presshub-secret-edit').removeClass('presshub-is-hidden');
        $(this).prop('hidden', true).attr('aria-expanded', 'true');
        $box.find('.presshub-secret-edit input').trigger('focus');
    });
    $(document).on('click', '.presshub-secret-cancel', function(e) {
        e.preventDefault();
        var $box = $(this).closest('.presshub-secret');
        $box.find('.presshub-secret-edit input').val('').trigger('input');
        closeSecretEdit($box, true);
    });

    /**
     * #247: replace a connection's status pill (every copy on the page) with
     * the one the test returned.
     */
    function presshubUpdateConnStatus(html) {
        if (!html) {
            return;
        }
        var $new = $($.parseHTML(String(html)));
        var check = $new.attr('data-check');
        if (!check) {
            return;
        }
        $('.presshub-conn-status').filter(function() {
            return $(this).attr('data-check') === check;
        }).each(function() {
            $(this).replaceWith($new.clone());
        });
    }
    window.PressHubUpdateConnStatus = presshubUpdateConnStatus;

    // #247: "Recheck all" runs every Test on the page, one after another,
    // so each service gets one request and the rate limits are respected.
    $(document).on('click', '.presshub-conn-recheck-all', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $note = $btn.siblings('.presshub-conn-recheck-status');
        var $tests = $('#presshub-settings-page').find('.presshub-test-provider-btn, #presshub-ai-test-press-inbox, .presshub-social-test-btn').filter(':visible');
        if (!$tests.length) {
            return;
        }
        $btn.prop('disabled', true);
        var i = 0;
        function next() {
            if (i >= $tests.length) {
                $btn.prop('disabled', false);
                $note.text(__('All connections checked. Reload the page to update the setup check.', 'presshub-ai-editor'));
                return;
            }
            var test = $tests.get(i++);
            $note.text(sprintf(__('Checking %1$d of %2$d…', 'presshub-ai-editor'), i, $tests.length));
            $(test).trigger('click');
            var waited = 0;
            var timer = setInterval(function() {
                waited += 300;
                if (!test.disabled || waited > 90000) {
                    clearInterval(timer);
                    next();
                }
            }, 300);
        }
        next();
    });

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
            // The source modal's fields only feed the sources list (its hidden input is tracked).
            if (id === 'presshub-ai-log-viewer' || $el.hasClass('presshub-no-dirty') || $el.closest('#presshub-source-modal').length) {
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
        var savableTabs = ['coauthor', 'briefing', 'social_listening', 'images', 'copilot', 'knowledge', 'advanced'];
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
                captureElementBaselines(tabKey);
            }
        });
    }

    /**
     * #234: remembers each field's saved value so a tab can be put back
     * when the editor leaves it without saving.
     */
    function captureElementBaselines(tabKey) {
        $('#presshub-tab-pane-' + tabKey).find('input, select, textarea').each(function() {
            var $el = $(this);
            var type = $el.attr('type');
            if (type === 'checkbox' || type === 'radio') {
                $el.data('phBaseline', $el.is(':checked'));
            } else if (type !== 'file' && type !== 'button' && type !== 'submit') {
                $el.data('phBaseline', $el.val());
            }
        });
        $('#presshub-tab-pane-' + tabKey).find('.presshub-image-pool-grid').each(function() {
            $(this).data('phGridBaseline', $(this).html());
        });
    }

    function revertTab(tabKey) {
        var $pane = $('#presshub-tab-pane-' + tabKey);
        var $grids = $pane.find('.presshub-image-pool-grid');
        $grids.each(function() {
            var base = $(this).data('phGridBaseline');
            if (typeof base === 'string') {
                $(this).html(base);
            }
        });
        if ($grids.length) {
            $(document).trigger('presshub:image-pools-reset');
        }
        $pane.find('input, select, textarea').each(function() {
            var $el = $(this);
            var base = $el.data('phBaseline');
            if (typeof base === 'undefined') {
                return;
            }
            var type = $el.attr('type');
            if (type === 'checkbox' || type === 'radio') {
                if ($el.is(':checked') !== base) {
                    $el.prop('checked', base).trigger('change');
                }
            } else if ($el.val() !== base) {
                $el.val(base).trigger('change');
            }
        });
        if ($pane.find('#presshub_ai_briefing_sources').length) {
            $(document).trigger('presshub:sources-reset');
        }
        updateTabDirtyState(tabKey);
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
        $(document).trigger('presshub:settings-dirty', [tabKey, dirty]);
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
    /** The "empty prompt" warning under the QA prompt follows its textarea. */
    function bindQaPromptWarnings() {
        $(document).on('input change', '.presshub-qa-prompt-textarea', function() {
            var $textarea = $(this);
            var $pane = $textarea.closest('.presshub-qa-pane');
            var $warning = $pane.find('.presshub-empty-prompt-warning');
            if ($warning.length) {
                if ($.trim($textarea.val()) === '') {
                    $warning.slideDown(150);
                } else {
                    $warning.slideUp(150);
                }
            }
        });
    }

    function initSettingsTabs() {
        // #235: Token & Usage Logs moved to PressHub → Usage & logs.
        if ($('#presshub-usage-logs-page').length) {
            loadTokenLogs(1);
            loadAuditLogs(1);
            return;
        }
        // #246: the one Settings page shows one section per page load. Track
        // its fields for the unsaved-changes guard; there are no tabs.
        if ($('#presshub-settings-page').length) {
            initTabBaselines();
            updateStickySaveBarDirtyState();
            bindQaPromptWarnings();
            $(document).on('input change keyup', '#presshub-ai-settings-form input, #presshub-ai-settings-form select, #presshub-ai-settings-form textarea', function() {
                var $pane = $(this).closest('.presshub-tab-pane');
                if ($pane.length && $pane.attr('id')) {
                    updateTabDirtyState($pane.attr('id').replace('presshub-tab-pane-', ''));
                }
            });
            return;
        }
        var $tabs = $('#presshub-ai-settings-tabs');
        var $mobileSelect = $('#presshub-mobile-tab-select');
        if (!$tabs.length && !$mobileSelect.length) {
            return;
        }
        if (/^#(token_logs|token_usage|usage)$/.test(window.location.hash || '') && typeof presshubAI !== 'undefined' && presshubAI.usage_url) {
            window.location.replace(presshubAI.usage_url);
            return;
        }

        var $form = $('#presshub-ai-settings-form');
        var tabAliases = {
            'general': 'coauthor',
            'media': 'advanced',
            'rate_limits': 'advanced',
            'diagnostics': 'advanced',
            'qa': 'coauthor',
            'scorecard': 'coauthor',
            'scorecards': 'coauthor',
            'editorial-qa': 'coauthor',
            'editorial_qa': 'coauthor',
            'coauthor-qa': 'coauthor',
            'coauthor-drafting': 'coauthor',
            'coauthor-seo': 'coauthor',
            'seo': 'coauthor',
            'social-listening': 'social_listening',
            'memory': 'knowledge'
        };

        /**
         * #234: opens the tab (and Co-Author sub-tab or <details>) that holds
         * the element with this id, then scrolls to it. Used for group links
         * such as #briefing-sources and for old bookmarks to moved fields.
         * Returns false when there is no such element.
         */
        function revealAnchor(anchorId) {
            if (!anchorId) {
                return false;
            }
            var el = document.getElementById(anchorId);
            if (!el && /^[a-z0-9_]+$/i.test(anchorId)) {
                // Checkboxes and array options (name="key[task]") have no id of their own.
                el = document.querySelector('#presshub-ai-settings-form [name="' + anchorId + '"]:not([type=hidden]), #presshub-ai-settings-form [name^="' + anchorId + '["]:not([type=hidden])');
            }
            if (!el) {
                return false;
            }
            var $el = $(el);
            var $pane = $el.closest('.presshub-tab-pane');
            if ($pane.length && $pane.attr('id')) {
                switchTab($pane.attr('id').replace('presshub-tab-pane-', ''));
            }
            var $sub = $el.closest('.presshub-coauthor-subpane');
            if ($sub.length && $sub.attr('id')) {
                switchCoauthorSubtab($sub.attr('id').replace('presshub-subpane-coauthor-', ''));
            }
            $el.parents('details').attr('open', 'open');
            // A hidden input or a field in a closed style/presenter pane cannot
            // be scrolled to: use the nearest visible container instead.
            var target = el;
            while (target && target.parentElement && (target.offsetParent === null || target.type === 'hidden') && target.tagName !== 'BODY') {
                target = target.parentElement;
            }
            if (target && typeof target.scrollIntoView === 'function') {
                try {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (err) {
                    target.scrollIntoView();
                }
                if (target !== el) {
                    $(target).addClass('presshub-search-hit');
                    setTimeout(function() { $(target).removeClass('presshub-search-hit'); }, 2500);
                }
            }
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, '#' + anchorId);
            }
            return true;
        }

        $(document).on('click', '#presshub-ai-settings-form a[href^="#"], .presshub-on-this-page a[href^="#"]', function(e) {
            var target = ($(this).attr('href') || '').replace('#', '');
            if (!target || $(this).hasClass('nav-tab') || $(this).hasClass('presshub-coauthor-subtab') || $(this).hasClass('presshub-qa-tab') || $(this).hasClass('presshub-switch-to-qa-studio')) {
                return;
            }
            var isTab = $tabs.find('.nav-tab[data-tab="' + (tabAliases[target] || target) + '"]').length > 0;
            if (isTab) {
                e.preventDefault();
                requestTabSwitch(target);
                return;
            }
            if (document.getElementById(target) && $(document.getElementById(target)).closest('#presshub-ai-settings-form').length) {
                e.preventDefault();
                var $targetPane = $(document.getElementById(target)).closest('.presshub-tab-pane');
                var $current = $('.presshub-tab-pane:visible').first();
                if ($targetPane.length && $current.length && $targetPane[0] !== $current[0] && !confirmLeaveTab($current)) {
                    return;
                }
                revealAnchor(target);
            }
        });

        /**
         * #234 unsaved-changes guard: asks before leaving a tab with unsaved
         * edits. "Leave" puts the tab back to its last saved state.
         */
        function confirmLeaveTab($pane) {
            if (!$pane || !$pane.length || !$pane.attr('id')) {
                return true;
            }
            var key = $pane.attr('id').replace('presshub-tab-pane-', '');
            if (typeof isTabDirty !== 'function' || !isTabDirty(key)) {
                return true;
            }
            var ok = window.confirm(__('This tab has unsaved changes. Leave it and discard them?', 'presshub-ai-editor'));
            if (ok) {
                revertTab(key);
            }
            return ok;
        }

        function requestTabSwitch(tabKey) {
            var target = tabAliases[tabKey] || tabKey;
            var $current = $('.presshub-tab-pane:visible').first();
            if ($current.length && $current.attr('id') !== 'presshub-tab-pane-' + target && !confirmLeaveTab($current)) {
                var currentKey = $current.attr('id').replace('presshub-tab-pane-', '');
                if ($mobileSelect.length) {
                    $mobileSelect.val(currentKey);
                }
                return false;
            }
            switchTab(tabKey);
            return true;
        }

        function switchCoauthorSubtab(subtabKey) {
            var $subtabs = $('.presshub-coauthor-subtabs');
            if (!$subtabs.length) {
                return;
            }
            $subtabs.find('.presshub-coauthor-subtab').removeClass('nav-tab-active').attr('aria-selected', 'false');
            var $activeSubtab = $subtabs.find('.presshub-coauthor-subtab[data-subtab="' + subtabKey + '"]');
            $activeSubtab.addClass('nav-tab-active').attr('aria-selected', 'true');

            $('.presshub-coauthor-subpane').hide();
            $('#presshub-subpane-coauthor-' + subtabKey).show();
        }

        function switchTab(tabKey) {
            var rawKey = tabKey;
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

            if (tabKey === 'advanced') {
                loadDiagnosticLogs();
            }

            if (rawKey === 'qa' || rawKey === 'scorecard' || rawKey === 'scorecards' || rawKey === 'editorial-qa' || rawKey === 'editorial_qa' || rawKey === 'coauthor-qa') {
                switchCoauthorSubtab('qa');
            } else if (rawKey === 'coauthor-drafting') {
                switchCoauthorSubtab('drafting');
            } else if (rawKey === 'coauthor-seo' || rawKey === 'seo') {
                switchCoauthorSubtab('seo');
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

            if (window.location.hash !== '#' + rawKey && window.location.hash !== '#' + tabKey) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#' + rawKey);
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
                'social_listening': __('Social listening', 'presshub-ai-editor'),
                'images': __('AI Featured Images', 'presshub-ai-editor'),
                'copilot': __('AI Copilot & Assistant', 'presshub-ai-editor'),
                'knowledge': __('Editorial knowledge', 'presshub-ai-editor'),
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
            requestTabSwitch(tabKey);
        });

        $(document).on('click', '.presshub-coauthor-subtab', function(e) {
            e.preventDefault();
            var subtabKey = $(this).data('subtab');
            if (subtabKey) {
                switchCoauthorSubtab(subtabKey);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#coauthor-' + subtabKey);
                }
            }
        });

        $(document).on('click', '.presshub-qa-tab', function(e) {
            e.preventDefault();
            var targetPaneId = $(this).data('qa-target');
            if (!targetPaneId) {
                return;
            }
            $('.presshub-qa-tab').removeClass('nav-tab-active').attr('aria-selected', 'false');
            $(this).addClass('nav-tab-active').attr('aria-selected', 'true');

            $('.presshub-qa-pane').hide();
            $('#' + targetPaneId).show();
        });

        $(document).on('click', '.presshub-switch-to-qa-studio', function(e) {
            e.preventDefault();
            var targetTab = $(this).data('target-tab') || 'coauthor';
            var subtab = $(this).data('subtab') || 'qa';
            var promptTarget = $(this).data('prompt-target') || 'briefing';

            if (promptTarget === 'briefing') {
                // #234: the briefing QA prompt lives in Daily Briefing -> Text story.
                revealAnchor('presshub_ai_qa_briefing_prompt');
                return;
            }
            if (!requestTabSwitch(targetTab)) {
                return;
            }
            switchCoauthorSubtab(subtab);

            var $targetPane = $('#presshub-qa-pane-article');
            if ($targetPane.length && typeof $targetPane[0].scrollIntoView === 'function') {
                try {
                    $targetPane[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                } catch (err) {}
            }
        });

        bindQaPromptWarnings();

        $(document).on('change', '#presshub-mobile-tab-select', function(e) {
            var tabKey = $(this).val();
            if (tabKey) {
                requestTabSwitch(tabKey);
            }
        });

        var lastHash = window.location.hash || '';
        $(window).on('hashchange', function() {
            var hash = (window.location.hash || '').replace('#', '');
            if (hash) {
                var targetTab = tabAliases[hash] || hash;
                var isTab = $tabs.find('.nav-tab[data-tab="' + targetTab + '"]').length || $mobileSelect.find('option[value="' + targetTab + '"]').length;
                // #234 review: hash navigation (search results, links) goes
                // through the unsaved-changes guard as well.
                var targetEl = isTab ? document.getElementById('presshub-tab-pane-' + targetTab) : document.getElementById(hash);
                if (!targetEl && !isTab && /^[a-z0-9_]+$/i.test(hash)) {
                    targetEl = document.querySelector('#presshub-ai-settings-form [name="' + hash + '"], #presshub-ai-settings-form [name^="' + hash + '["]');
                }
                var $targetPane = targetEl ? $(targetEl).closest('.presshub-tab-pane') : $();
                var $current = $('.presshub-tab-pane:visible').first();
                if ($targetPane.length && $current.length && $targetPane[0] !== $current[0] && !confirmLeaveTab($current)) {
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, null, '#' + ($current.attr('id') || '').replace('presshub-tab-pane-', ''));
                    }
                    return;
                }
                if (isTab) {
                    switchTab(hash);
                } else {
                    revealAnchor(hash);
                }
            }
            lastHash = window.location.hash || '';
        });

        var initialHash = (window.location.hash || '').replace('#', '');
        if (initialHash) {
            var targetTab = tabAliases[initialHash] || initialHash;
            if ($tabs.find('.nav-tab[data-tab="' + targetTab + '"]').length || $mobileSelect.find('option[value="' + targetTab + '"]').length) {
                switchTab(initialHash);
            } else if (!revealAnchor(initialHash)) {
                updateStickySaveBar($tabs.find('.nav-tab-active').data('tab') || 'providers');
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

    /**
     * #246: number of fields in a pane whose value differs from the saved one.
     */
    function countChangedFields(tabKey) {
        var count = 0;
        var seen = {};
        $('#presshub-tab-pane-' + tabKey).find('input, select, textarea').each(function() {
            var $el = $(this);
            var base = $el.data('phBaseline');
            if (typeof base === 'undefined' || $el.hasClass('presshub-no-dirty') || $el.closest('#presshub-source-modal').length) {
                return;
            }
            var type = $el.attr('type');
            var changed = (type === 'checkbox' || type === 'radio') ? ($el.is(':checked') !== base) : ($el.val() !== base);
            if (!changed) {
                return;
            }
            // A group of checkboxes or radios with one name counts once.
            var key = (type === 'radio' || type === 'checkbox') ? ($el.attr('name') || this.id) : (this.id || $el.attr('name'));
            if (!seen[key]) {
                seen[key] = true;
                count++;
            }
        });
        if (!count && isTabDirty(tabKey)) {
            count = 1;
        }
        return count;
    }

    // #246: read by assets/js/settings-page.js (guard on links, status line).
    window.PressHubSettingsState = {
        isDirty: function() {
            return isAnyTabDirty() || isProviderModalDirty();
        },
        changedCount: function(tabKey) {
            return countChangedFields(tabKey);
        },
        markClean: function(tabKey) {
            tabBaselines[tabKey] = getTabFormSnapshot(tabKey);
            captureElementBaselines(tabKey);
            updateTabDirtyState(tabKey);
        }
    };

    // Register beforeunload handler for unsaved changes
    window.addEventListener('beforeunload', function(e) {
        // #246: the editor already confirmed leaving on a link or the section list.
        if (window.PressHubSettingsState && window.PressHubSettingsState.leaving) {
            return;
        }
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
            $('#provider-form-max-tokens').val(providerData.max_tokens !== undefined ? providerData.max_tokens : 20000);
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
            $('#provider-form-max-tokens').val('20000');
            $('#provider-form-timeout').val('300');
            $('#provider-form-api-key').attr('placeholder', __('Enter API Key', 'presshub-ai-editor'));

            var firstTmplKey = Object.keys(providerTemplates)[0] || 'openai';
            var tmpl = providerTemplates[firstTmplKey] || {};
            populateModelDropdown(tmpl.available_models || [], tmpl.default_model || '');
            $('#provider-form-toggle-manual').prop('checked', false);
            $('#provider-model-select-wrap').show();
            $('#provider-model-manual-wrap').hide();
        }

        $modal.data('presshub-opener', document.activeElement);
        $modal.show().addClass('is-open');
        $('body').addClass('presshub-modal-open');
        $('#presshub-provider-discard-notice').hide();
        providerModalBaseline = getProviderModalSnapshot();
        // Move focus into the dialog (#285 review).
        $modal.find('input, select, textarea, button').filter(':visible:not(:disabled)').not('.presshub-modal-close').first().trigger('focus');
    }

    function closeProviderModal() {
        var $modal = $('#presshub-provider-modal');
        closeModal($modal);
    }

    function closeLogDetailsModal() {
        var $modal = $('#presshub-log-details-modal');
        closeModal($modal);
    }

    // Modal Trigger Buttons
    $(document).on('click', '#presshub-add-provider-btn', function(e) {
        e.preventDefault();
        openProviderModal(null);
    });

    $(document).on('click', '#presshub-provider-modal .presshub-modal-close, #presshub-provider-modal .presshub-modal-cancel, #presshub-log-details-modal .presshub-modal-close, #presshub-log-details-modal .presshub-modal-cancel', function(e) {
        e.preventDefault();
        if ($(this).closest('#presshub-provider-modal').length) {
            tryCloseProviderModal(false);
        } else if ($(this).closest('#presshub-log-details-modal').length) {
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
        $('#provider-form-max-tokens').val(tmpl.max_tokens !== undefined ? tmpl.max_tokens : 20000);
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
            max_tokens: parseInt($('#provider-form-max-tokens').val(), 10) || 20000,
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
            presshubUpdateConnStatus(res && res.data ? res.data.status : '');
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

    // #234: the tab guard put the hidden sources input back; redraw the table.
    $(document).on('presshub:sources-reset', function() {
        initSourcesState();
        if (typeof renderSourcesTable === 'function') {
            renderSourcesTable();
        }
    });

    function syncSourcesInput() {
        $('#presshub_ai_briefing_sources').val(JSON.stringify(presshubSourcesState)).trigger('input');
    }

    // Issue #196: source priority, 1 (lowest) to 5 (highest), 3 = normal.
    function presshubSourcePriority(v) {
        var n = parseInt(v, 10);
        return isNaN(n) ? 3 : Math.max(1, Math.min(5, n));
    }

    // Source alignment: whose side a source usually reflects ('' = not set).
    var presshubAlignmentLabels = {
        government: 'Pro-government',
        opposition: 'Opposition',
        independent: 'Independent',
        institutional: 'Institutional',
        social_partner: 'Social partner'
    };

    function presshubSourceAlignment(v) {
        v = String(v || '');
        return Object.prototype.hasOwnProperty.call(presshubAlignmentLabels, v) ? v : '';
    }

    function presshubAlignmentBadge(v) {
        var a = presshubSourceAlignment(v);
        if (!a) {
            return '';
        }
        return ' <span class="presshub-source-alignment presshub-source-alignment-' + a + '" style="font-size: 11px; color: #50575e; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; display: inline-block;">Alignment: ' + presshubEsc(presshubAlignmentLabels[a]) + '</span>';
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
            'social_x': { label: 'X (Twitter) Account', badgeClass: 'badge-active', icon: 'dashicons-twitter' },
            'social_tiktok': { label: 'TikTok Account', badgeClass: 'badge-active', icon: 'dashicons-video-alt2' },
            'social_facebook': { label: 'Facebook Page', badgeClass: 'badge-active', icon: 'dashicons-facebook' },
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
            var priority = presshubSourcePriority(src.priority);

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
                    '<div style="margin-top: 4px;"><span class="presshub-source-quota" style="font-size: 11px; color: #50575e; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; display: inline-block;">Quota: ' + maxArticles + ' articles</span> <span class="presshub-source-priority" style="font-size: 11px; color: #50575e; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; display: inline-block;">Priority: ' + priority + '/5</span>' + presshubAlignmentBadge(src.alignment) + '</div>' +
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
        $('#source-form-priority').val('3');
        $('#source-form-alignment').val('');
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
        $('#source-form-priority').val(String(presshubSourcePriority(src.priority)));
        $('#source-form-alignment').val(presshubSourceAlignment(src.alignment));
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
        var priority    = presshubSourcePriority($('#source-form-priority').val());
        var alignment   = presshubSourceAlignment($('#source-form-alignment').val());

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
                existing.priority     = priority;
                existing.alignment    = alignment;
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
                priority: priority,
                alignment: alignment,
                notes: notes,
                enabled: enabled
            });
        }

        syncSourcesInput();
        renderSourcesTable();

        $('body').removeClass('presshub-modal-open');
        $('#presshub-source-modal').fadeOut(150);
    });

    // Press Release Inbox: test the saved IMAP settings.
    $(document).on('click', '#presshub-ai-test-press-inbox', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#presshub-ai-test-press-inbox-spinner');
        var $result = $('#presshub-ai-test-press-inbox-result');
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('<span style="color: #646970;">Connecting...</span>');
        $.ajax({
            url: presshubAI.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: { action: 'presshub_ai_test_press_inbox', nonce: presshubAI.nonce }
        }).done(function(res) {
            var ok = !!(res && res.success);
            presshubUpdateConnStatus(res && res.data ? res.data.status : '');
            var msg = (res && res.data && res.data.message) ? res.data.message : (ok ? 'Connected.' : 'Connection test failed.');
            $result.html('<span style="color: ' + (ok ? '#00a32a' : '#d63638') + ';">' + (ok ? '✓ ' : '✗ ') + presshubEsc(msg) + '</span>');
        }).fail(function(xhr, status, error) {
            $result.html('<span style="color: #d63638;">✗ ' + presshubEsc(error || status || 'Connection error') + '</span>');
        }).always(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
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
    /**
     * Checks the browser validity rules (min, max, step, required, pattern)
     * of every named field inside $scope (#257). On failure it shows one
     * error notice in $noticeTarget naming each invalid field, reveals the
     * first one (switching its sub-tab if needed) and lets the browser
     * point at it. Returns true when every field is valid.
     */
    function presshubCheckFieldsValid($scope, $noticeTarget) {
        var invalid = [];
        $scope.find('input[name], select[name], textarea[name]').each(function() {
            if (typeof this.checkValidity !== 'function' || this.disabled || this.checkValidity()) {
                return;
            }
            // A value off the step grid alone is not worth blocking a save:
            // the server rounds or clamps it, and some stored defaults sit
            // off their field's step.
            var v = this.validity;
            if (v && v.stepMismatch && !v.rangeOverflow && !v.rangeUnderflow && !v.valueMissing &&
                !v.badInput && !v.patternMismatch && !v.typeMismatch && !v.tooLong && !v.tooShort && !v.customError) {
                return;
            }
            invalid.push(this);
        });
        if (!invalid.length) {
            return true;
        }
        // #246: the Settings page shows an error summary that links to each
        // field, plus the same message next to the field (settings-page.js).
        if ($('#presshub-settings-error-summary').length) {
            $(document).trigger('presshub:settings-invalid', [invalid]);
            return false;
        }

        var $list = $('<ul class="presshub-settings-notice-list"></ul>');
        $.each(invalid, function(i, el) {
            var $el = $(el);
            var label = '';
            if (el.id) {
                label = $.trim($('label[for="' + el.id + '"]').first().text());
            }
            if (!label) {
                var $wrap = $el.closest('label');
                if ($wrap.length) {
                    label = $.trim($wrap.clone().children().remove().end().text());
                }
            }
            if (!label) {
                label = $.trim($el.closest('tr').find('th').first().text()) || el.name;
            }
            $('<li></li>').text(label + ': ' + (el.validationMessage || __('Invalid value', 'presshub-ai-editor'))).appendTo($list);
        });
        var $notice = $('<div class="notice notice-error presshub-settings-notice"></div>');
        $('<p></p>').append($('<strong></strong>').text(__('Not saved. Please fix these fields first:', 'presshub-ai-editor'))).append($list).appendTo($notice);
        $noticeTarget.prepend($notice);

        // Reveal the first invalid field: its main tab, sub-pane and any
        // collapsed <details> around it.
        var $first = $(invalid[0]);
        var $pane = $first.closest('.presshub-tab-pane');
        if ($pane.length && !$pane.is(':visible') && $pane.attr('id')) {
            var paneKey = $pane.attr('id').replace('presshub-tab-pane-', '');
            $('#presshub-ai-settings-tabs .nav-tab[data-tab="' + paneKey + '"]').first().trigger('click');
        }
        $first.parents('details').attr('open', 'open');
        var $subpane = $first.closest('[id^="presshub-subpane-"]');
        if ($subpane.length && !$subpane.is(':visible')) {
            var sub = $subpane.attr('id').replace(/^presshub-subpane-[^-]+-/, '');
            $('a[data-subtab="' + sub + '"]').first().trigger('click');
        }
        if ($first.is(':visible') && typeof invalid[0].reportValidity === 'function') {
            invalid[0].reportValidity();
        } else {
            $notice[0].scrollIntoView({ block: 'center' });
        }
        return false;
    }

    /**
     * The settings fields under $container as a flat object for the AJAX save.
     * List fields (name="option[]") post every ticked or selected value as an
     * array, as a form post to options.php did (#246: Social platforms).
     */
    function collectSettingsPayload($container) {
        var payload = {};
        $container.find('input, select, textarea').each(function() {
            var $el = $(this);
            var name = $el.attr('name');
            if (!name || name === 'action' || name === 'option_page' || name === '_wp_http_referer' || name === '_wpnonce') {
                return;
            }
            if (/\[\]$/.test(name)) {
                var list = Array.isArray(payload[name]) ? payload[name] : [];
                if ($el.is(':checkbox') || $el.is(':radio')) {
                    if ($el.is(':checked')) {
                        list.push($el.val() || '1');
                    }
                } else {
                    var v = $el.val();
                    if (Array.isArray(v)) {
                        list = list.concat(v);
                    } else if (v !== null && v !== undefined) {
                        list.push(v);
                    }
                }
                if (list.length) {
                    payload[name] = list;
                }
                return;
            }
            if ($el.is(':checkbox')) {
                if ($el.is(':checked')) {
                    payload[name] = $el.val() || '1';
                }
            } else if ($el.is(':radio')) {
                if ($el.is(':checked')) {
                    payload[name] = $el.val();
                }
            } else {
                payload[name] = $el.val();
            }
        });
        return payload;
    }

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

        // #257: never send values the browser already flags as invalid
        // (e.g. temperature 5 with max 2); the server would clamp them and
        // the tab would still report success.
        if (!presshubCheckFieldsValid($tabPane, $tabPane)) {
            if ($stickyStatus.length) {
                $stickyStatus.addClass('is-error').removeClass('is-success').text(__('Fix the highlighted fields', 'presshub-ai-editor')).show();
            }
            return;
        }

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

        var settingsPayload = collectSettingsPayload($tabPane);

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
                resetPressInboxPasswordField();

                var successMsg = (res.data && res.data.message) ? res.data.message : __('Settings saved successfully.', 'presshub-ai-editor');
                if (res.data && Array.isArray(res.data.model_warnings) && res.data.model_warnings.length) {
                    successMsg += ' ' + res.data.model_warnings.join(' ');
                }
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
                // Issue #206: take the clean baseline only after the sources input
                // holds the server's normalized list, or the tab looks unsaved.
                tabBaselines[tabKey] = getTabFormSnapshot(tabKey);
                captureElementBaselines(tabKey);
                updateTabDirtyState(tabKey);
                var noticeHtml = '<div class="notice notice-success is-dismissible presshub-settings-notice"><p>' + presshubEsc(successMsg) + '</p></div>';
                $tabPane.prepend(noticeHtml);
                $(document).trigger('presshub:settings-saved', [tabKey, res]);

                var hasModelWarning = !!(res.data && Array.isArray(res.data.model_warnings) && res.data.model_warnings.length);
                if ($stickyStatus.length && hasModelWarning) {
                    // #236: an unknown model ID saved; the warning stays until the next save.
                    $stickyStatus.removeClass('is-success is-error').text(__('Saved, with a warning on a model. See its row.', 'presshub-ai-editor'));
                } else if ($stickyStatus.length) {
                    $stickyStatus.addClass('is-success').removeClass('is-error').text(__('Settings saved', 'presshub-ai-editor'));
                    setTimeout(function() {
                        if ($stickyStatus.hasClass('is-success')) {
                            $stickyStatus.fadeOut(400, function() {
                                $(this).text('').removeClass('is-success').show();
                            });
                        }
                    }, 3000);
                }

                if (res.data && res.data.masks) {
                    if (res.data.masks.google_cloud_key) {
                        $('#presshub_ai_google_cloud_api_key').attr('placeholder', res.data.masks.google_cloud_key);
                    }
                    if (res.data.masks.github_token) {
                        $('#presshub_ai_github_token').attr('placeholder', res.data.masks.github_token);
                    }
                }
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : __('Failed to save settings.', 'presshub-ai-editor');
                var errHtml = '<div class="notice notice-error is-dismissible presshub-settings-notice"><p>' + presshubEsc(err) + '</p></div>';
                $tabPane.prepend(errHtml);
                if (res && res.data && res.data.field_errors && $('#presshub-settings-error-summary').length) {
                    // #236: the server refused a field (a model of the wrong type).
                    $(document).trigger('presshub:settings-field-errors', [res.data.field_errors, err]);
                } else {
                    $(document).trigger('presshub:settings-save-failed', [tabKey, err]);
                }
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
            var errText = 'Error while saving (' + (xhr.status || 0) + '): ' + errorDetail;
            var errHtml = '<div class="notice notice-error is-dismissible presshub-settings-notice"><p>' + presshubEsc(errText) + '</p></div>';
            $tabPane.prepend(errHtml);
            $(document).trigger('presshub:settings-save-failed', [tabKey, errText]);
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
        // #246: on the Settings page a submit (Enter in a field) saves the section on screen.
        if ($('#presshub-settings-page').length) {
            saveSettingsTab($(this).data('section') || $('#presshub-sticky-save-bar').attr('data-active-tab') || '');
            return;
        }

        var $form = $(this);
        var $btn = $form.find('#submit');
        var $spinner = $('#presshub-ai-save-spinner');

        $('.presshub-settings-notice').remove();
        if (!presshubCheckFieldsValid($form, $form)) {
            return;
        }
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        var settingsPayload = collectSettingsPayload($form);

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
                resetPressInboxPasswordField();

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
                // Issue #206: baselines after the sources input is re-synced (see above).
                initTabBaselines();
                Object.keys(tabBaselines).forEach(function(k) {
                    updateTabDirtyState(k);
                });
                var noticeHtml = '<div class="notice notice-success is-dismissible presshub-settings-notice" style="margin: 15px 0;"><p>' + presshubEsc(successMsg) + '</p></div>';
                $('#presshub-ai-settings-tabs').before(noticeHtml);

                if (res.data && res.data.masks) {
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
            $('#presshub-host-male-row').show();
        } else {
            $('#presshub-voice-male-row').hide();
            $('#presshub-host-male-row').hide();
        }
        if (count >= 3) {
            $('#presshub-voice-tertiary-row').show();
            $('#presshub-host-tertiary-row').show();
        } else {
            $('#presshub-voice-tertiary-row').hide();
            $('#presshub-host-tertiary-row').hide();
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
    // Gemini Speech Settings Dynamic Inactive States & Visual Indicators
    // ------------------------------------------------------------------
    function updateTtsPayloadModeVisualStates() {
        var mode = $('#presshub_ai_briefing_tts_payload_mode').val() || 'structured';
        var $sysRow = $('#presshub-tts-system-instruction-row');
        var $speedRow = $('#presshub-voice-speed-row');
        var $pitchRow = $('#presshub-voice-pitch-row');

        if (mode === 'structured') {
            // Official Gemini 3.8 TTS REST Standard:
            // Google controls tone, speed, and pitch directly in speech_metadata.style without system instructions
            $sysRow.addClass('presshub-setting-inactive');
            $sysRow.find('textarea, button').prop('disabled', true);
            $sysRow.find('.presshub-inactive-mode-badge').show();

            // Speed and Pitch are active (mapped to speech_metadata.style)
            $speedRow.removeClass('presshub-setting-inactive');
            $speedRow.find('input').prop('disabled', false);
            $speedRow.find('.presshub-inactive-mode-badge').hide();

            $pitchRow.removeClass('presshub-setting-inactive');
            $pitchRow.find('input').prop('disabled', false);
            $pitchRow.find('.presshub-inactive-mode-badge').hide();
        } else if (mode === 'hybrid_instruction') {
            // Hybrid: All settings active
            $sysRow.removeClass('presshub-setting-inactive');
            $sysRow.find('textarea, button').prop('disabled', false);
            $sysRow.find('.presshub-inactive-mode-badge').hide();

            $speedRow.removeClass('presshub-setting-inactive');
            $speedRow.find('input').prop('disabled', false);
            $speedRow.find('.presshub-inactive-mode-badge').hide();

            $pitchRow.removeClass('presshub-setting-inactive');
            $pitchRow.find('input').prop('disabled', false);
            $pitchRow.find('.presshub-inactive-mode-badge').hide();
        } else if (mode === 'legacy') {
            // Legacy: System instruction, speed, pitch NOT used
            $sysRow.addClass('presshub-setting-inactive');
            $sysRow.find('textarea, button').prop('disabled', true);
            $sysRow.find('.presshub-inactive-mode-badge').show();

            $speedRow.addClass('presshub-setting-inactive');
            $speedRow.find('input').prop('disabled', true);
            $speedRow.find('.presshub-inactive-mode-badge').show();

            $pitchRow.addClass('presshub-setting-inactive');
            $pitchRow.find('input').prop('disabled', true);
            $pitchRow.find('.presshub-inactive-mode-badge').show();
        }
    }

    $(document).on('change', '#presshub_ai_briefing_tts_payload_mode', function() {
        updateTtsPayloadModeVisualStates();
    });

    $(document).ready(function() {
        updateTtsPayloadModeVisualStates();
    });

    // ------------------------------------------------------------------
    // Gemini Speech API JSON Payload Inspector & Live Audio Tester
    // ------------------------------------------------------------------
    function getTtsTesterParameters() {
        var nonce = (typeof presshubAI !== 'undefined' && presshubAI.nonce) ? presshubAI.nonce : ($('#presshub_ai_nonce').val() || '');
        var hostsVal = $('input[name="presshub_tts_test_hosts"]:checked').val();
        var twoHosts = (hostsVal === '2' || typeof hostsVal === 'undefined') ? 1 : 0;

        return {
            nonce: nonce,
            text: $('#presshub-tts-test-text').val() || '',
            speaker1: $('#presshub_ai_briefing_voice_female').val() || 'Kore',
            speaker2: $('#presshub_ai_briefing_voice_male').val() || 'Fenrir',
            speed: parseFloat($('#presshub_ai_briefing_voice_speed').val()) || 1.0,
            pitch: parseFloat($('#presshub_ai_briefing_voice_pitch').val()) || 0.0,
            style: $('#presshub_ai_briefing_tts_style').val() || 'formal',
            custom_style: $('#presshub_ai_briefing_tts_custom_style').val() || '',
            payload_mode: $('#presshub_ai_briefing_tts_payload_mode').val() || 'structured',
            system_instruction: $('#presshub_ai_briefing_tts_system_instruction').val() || '',
            two_hosts: twoHosts
        };
    }

    var ttsPreviewDebounceTimer = null;
    function updateTtsPayloadPreview() {
        var params = getTtsTesterParameters();
        params.action = 'presshub_ai_preview_tts_payload';

        var ajaxTarget = (typeof presshubAI !== 'undefined' && presshubAI.ajax_url) ? presshubAI.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

        $.ajax({
            url: ajaxTarget,
            type: 'POST',
            data: params,
            success: function(res) {
                if (res && res.success && res.data && res.data.json_formatted) {
                    $('#presshub-tts-payload-preview').text(res.data.json_formatted);
                } else if (res && res.data && res.data.message) {
                    $('#presshub-tts-payload-preview').text('Error: ' + res.data.message);
                }
            },
            error: function(xhr, status, error) {
                $('#presshub-tts-payload-preview').text('Error fetching JSON preview: ' + (error || status || 'Network error'));
            }
        });
    }

    function scheduleTtsPayloadPreview() {
        if ($('#presshub-tts-payload-preview').length === 0) {
            return;
        }
        if (ttsPreviewDebounceTimer) {
            clearTimeout(ttsPreviewDebounceTimer);
        }
        ttsPreviewDebounceTimer = setTimeout(updateTtsPayloadPreview, 350);
    }

    $(document).on('change', '#presshub_ai_briefing_tts_payload_mode, #presshub_ai_briefing_tts_style, #presshub_ai_briefing_voice_female, #presshub_ai_briefing_voice_male, #presshub_ai_briefing_voice_speed, #presshub_ai_briefing_voice_pitch, input[name="presshub_tts_test_hosts"]', scheduleTtsPayloadPreview);
    $(document).on('input', '#presshub-tts-test-text, #presshub_ai_briefing_tts_custom_style, #presshub_ai_briefing_tts_system_instruction', scheduleTtsPayloadPreview);

    $(document).on('click', '#presshub-preview-tts-payload-btn', function(e) {
        e.preventDefault();
        updateTtsPayloadPreview();
    });

    $(document).on('click', '#presshub-restore-default-tts-instruction', function(e) {
        e.preventDefault();
        var defaultTpl = $(this).data('default') || '';
        $('#presshub_ai_briefing_tts_system_instruction').val(defaultTpl).trigger('change');
        if (typeof markTabDirty === 'function') {
            markTabDirty('briefing');
        }
        updateTtsPayloadPreview();
    });

    $(document).on('click', '#presshub-copy-tts-json-btn', function(e) {
        e.preventDefault();
        var jsonText = $('#presshub-tts-payload-preview').text();
        var $btn = $(this);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(jsonText).then(function() {
                var orig = $btn.text();
                $btn.text('Copied!');
                setTimeout(function() { $btn.text(orig); }, 1500);
            });
        } else {
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(jsonText).select();
            document.execCommand('copy');
            $temp.remove();
            var orig = $btn.text();
            $btn.text('Copied!');
            setTimeout(function() { $btn.text(orig); }, 1500);
        }
    });

    $(document).on('click', '#presshub-test-tts-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var params = getTtsTesterParameters();
        params.action = 'presshub_ai_test_tts_synthesis';

        $btn.prop('disabled', true).addClass('updating-message');
        $('#presshub-tts-test-idle').hide();
        $('#presshub-tts-test-error').hide();
        $('#presshub-tts-test-result').hide();
        $('#presshub-tts-test-loading').show();

        var ajaxTarget = (typeof presshubAI !== 'undefined' && presshubAI.ajax_url) ? presshubAI.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

        $.ajax({
            url: ajaxTarget,
            type: 'POST',
            data: params,
            success: function(res) {
                $('#presshub-tts-test-loading').hide();
                if (res && res.success && res.data) {
                    var data = res.data;
                    var audioUrl = data.audio_data_url || data.audio_url || '';
                    var player = document.getElementById('presshub-tts-test-audio-player');
                    if (player && audioUrl) {
                        player.src = audioUrl;
                        player.load();
                        player.play().catch(function() {});
                    }

                    var dur = data.duration_ms ? Math.round(data.duration_ms) + 'ms' : '';
                    var kb = data.audio_bytes ? Math.round(data.audio_bytes / 1024) + ' KB' : '';
                    var mode = data.payload_mode ? 'Mode: ' + data.payload_mode : '';
                    var model = data.model ? 'Model: ' + data.model : '';
                    var metricsText = [mode, dur, kb, model].filter(Boolean).join(' · ');

                    $('#presshub-tts-test-metrics').text(metricsText);

                    if (audioUrl) {
                        $('#presshub-tts-download-link').attr('href', audioUrl).show();
                    } else {
                        $('#presshub-tts-download-link').hide();
                    }

                    if (data.json_payload) {
                        var formatted = JSON.stringify(data.json_payload, null, 2);
                        $('#presshub-tts-payload-preview').text(formatted);
                    }

                    $('#presshub-tts-test-result').show();
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Speech synthesis failed.';
                    $('#presshub-tts-test-error p').text(errMsg);
                    $('#presshub-tts-test-error').show();
                }
            },
            error: function(xhr, status, error) {
                $('#presshub-tts-test-loading').hide();
                var msg = 'Synthesis request failed: ' + (error || status || 'Server error');
                if (xhr.responseText) {
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.data && parsed.data.message) {
                            msg = parsed.data.message;
                        }
                    } catch (e) {}
                }
                $('#presshub-tts-test-error p').text(msg);
                $('#presshub-tts-test-error').show();
            },
            complete: function() {
                $btn.prop('disabled', false).removeClass('updating-message');
            }
        });
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

    // =========================================================================
    // Featured Image Pools Manager (Media Library Picker UI)
    // =========================================================================

    function markTabDirty(tabKey) {
        if (typeof updateTabDirtyState === 'function') {
            updateTabDirtyState(tabKey);
        }
    }

    function syncImagePoolManager($manager) {
        var ids = [];
        $manager.find('.presshub-image-pool-item').each(function() {
            var id = parseInt($(this).attr('data-id'), 10);
            if (id > 0 && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });

        var $input = $manager.find('input[type="hidden"]');
        var oldVal = $input.val();
        var newVal = ids.join(',');
        $input.val(newVal);

        var hasImages = ids.length > 0;
        var $emptyNotice = $manager.find('.presshub-image-pool-empty');
        var $fallbackNotice = $manager.find('.presshub-image-pool-fallback');
        var $clearBtn = $manager.find('.presshub-clear-pool-images-btn');

        if (hasImages) {
            $emptyNotice.hide();
            $fallbackNotice.hide();
            $clearBtn.show();
        } else {
            $emptyNotice.show();
            $fallbackNotice.show();
            $clearBtn.hide();
        }

        if (oldVal !== newVal) {
            $input.trigger('change');
            markTabDirty('briefing');
        }
    }

    // Select images from WordPress Media Library
    $(document).on('click', '.presshub-select-pool-images-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $manager = $btn.closest('.presshub-image-pool-manager');
        var $grid = $manager.find('.presshub-image-pool-grid');

        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }

        var frame = wp.media({
            title: __('Select Featured Images for Pool', 'presshub-ai-editor'),
            button: {
                text: __('Add to Pool', 'presshub-ai-editor')
            },
            multiple: true,
            library: {
                type: 'image'
            }
        });

        frame.on('select', function() {
            var selection = frame.state().get('selection');
            selection.each(function(attachment) {
                var att = attachment.toJSON();
                var id = parseInt(att.id, 10);
                if (!id || id <= 0) {
                    return;
                }

                // Check if already in grid
                if ($grid.find('.presshub-image-pool-item[data-id="' + id + '"]').length > 0) {
                    return;
                }

                var thumbUrl = att.url;
                if (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) {
                    thumbUrl = att.sizes.thumbnail.url;
                }

                var itemHtml = '<div class="presshub-image-pool-item" data-id="' + presshubEsc(id) + '">' +
                    '<img src="' + presshubEsc(thumbUrl) + '" alt="' + presshubEsc(__('Image #' + id, 'presshub-ai-editor')) + '" />' +
                    '<span class="presshub-image-pool-badge">#' + presshubEsc(id) + '</span>' +
                    '<button type="button" class="presshub-image-pool-remove" title="' + presshubEsc(__('Remove image from pool', 'presshub-ai-editor')) + '" aria-label="' + presshubEsc(__('Remove image', 'presshub-ai-editor')) + '">&times;</button>' +
                    '</div>';

                $grid.append(itemHtml);
            });

            syncImagePoolManager($manager);
        });

        frame.open();
    });

    // #234: the tab guard put a pool grid back to its saved state; resync
    // the hidden input and the empty/fallback notices from the grid.
    $(document).on('presshub:image-pools-reset', function() {
        $('.presshub-image-pool-manager').each(function() {
            syncImagePoolManager($(this));
        });
    });

    // Remove single image from pool
    $(document).on('click', '.presshub-image-pool-remove', function(e) {
        e.preventDefault();
        var $item = $(this).closest('.presshub-image-pool-item');
        var $manager = $item.closest('.presshub-image-pool-manager');
        $item.remove();
        syncImagePoolManager($manager);
    });

    // Clear all images from pool
    $(document).on('click', '.presshub-clear-pool-images-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $manager = $btn.closest('.presshub-image-pool-manager');
        $manager.find('.presshub-image-pool-grid').empty();
        syncImagePoolManager($manager);
    });
});

