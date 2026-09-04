/**
 * PressHub AI — Daily News Briefing & AI Podcast Admin Script
 *
 * Handles AJAX pipeline actions: scraping, text story curation, podcast dialogue script
 * generation & saving, multi-voice audio synthesis, and Cloudflare blocked source manual uploads.
 *
 * Issue #61 (S4) — the cap-observability subtext is translatable so the JS
 * stays consistent with the server-rendered (Greek-first) wording.
 */

(function($) {
    'use strict';

    // Pull wp.i18n helpers from the global namespace. Falls back to identity
    // functions when wp.i18n is not yet available (e.g. during the brief
    // window between script enqueue and wp-i18n runtime ready).
    var __  = (window.wp && window.wp.i18n && window.wp.i18n.__)       || function (s) { return s; };
    var sprintf = (window.wp && window.wp.i18n && window.wp.i18n.sprintf) || function (fmt) {
        var args = Array.prototype.slice.call(arguments, 1);
        return fmt.replace(/%[sd]/g, function () { return args.shift(); });
    };

    $(document).ready(function() {
        var config = window.presshubBriefingAdmin || {};
        var ajaxUrl = config.ajax_url || window.ajaxurl;
        var nonce = config.nonce || '';
        var i18n = config.i18n || {};
        // Issue #83 — prefer the server-provided WP-local date stamp
        // (PHP wp_date('Y-m-d')) over the UTC ISO date so the JS
        // bootstrap matches the timezone the token-log writer uses.
        // `new Date().toISOString().split('T')[0]` is intentionally
        // avoided as a final fallback because it always returns UTC
        // and leaks yesterday's date for operators east of UTC.
        var currentDate = $('#presshub-briefing-hub-wrap').data('date') || config.date || '';

        // -------------------------------------------------------------------------
        // Notice Display System
        // -------------------------------------------------------------------------
        function showNotice(type, message) {
            var noticeClass = 'notice notice-' + type + ' is-dismissible presshub-admin-notice';
            var icon = type === 'success' ? '✅' : (type === 'error' ? '❌' : (type === 'warning' ? '⚠️' : 'ℹ️'));
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
        // Article Selection Helpers
        // -------------------------------------------------------------------------
        function getSelectedArticleIndices() {
            var selected = [];
            $('.presshub-article-checkbox:checked').each(function() {
                var val = $(this).val();
                if (val !== undefined && val !== '') {
                    selected.push(parseInt(val, 10));
                }
            });
            return selected;
        }

        function jsStripHtml(html) {
            if (!html) return '';
            // Use a detached DOM node to strip tags safely (avoids regex pitfalls).
            var div = document.createElement('div');
            div.innerHTML = String(html);
            return (div.textContent || div.innerText || '').trim();
        }

        // Mirror of PressHub_AI_Context_Estimator::utf8_word_count().
        // - Strip HTML tags first
        // - Whitespace-split tokens
        // - For CJK-heavy tokens, count each codepoint as a word
        function jsWordCount(text) {
            var plain = jsStripHtml(text);
            if (!plain) return 0;
            var rawTokens = plain.split(/\s+/).filter(function(t) { return t.length > 0; });
            var cjkPattern = /[\u4E00-\u9FFF\u3040-\u309F\u30A0-\u30FF\uAC00-\uD7AF]/;
            var count = 0;
            for (var i = 0; i < rawTokens.length; i++) {
                var tok = rawTokens[i];
                if (cjkPattern.test(tok)) {
                    // Count codepoints (handles surrogate pairs / emoji correctly).
                    count += Array.from(tok).length;
                } else {
                    count += 1;
                }
            }
            return count;
        }

        // Mirror of PressHub_AI_Context_Estimator::estimate_tokens().
        // Heuristic: max(1, intdiv(codepoint_length, 3)).
        function jsEstimateTokens(text) {
            var plain = jsStripHtml(text);
            if (!plain) return 0;
            var codepoints = Array.from(plain).length;
            if (codepoints === 0) return 0;
            return Math.max(1, Math.floor(codepoints / 3));
        }

        // Issue #61 (S1) — cap-aware mirror used by the JS card builder
        // so cards rebuilt from a status refresh expose the same
        // data-capped-tokens value the server pre-computed on first paint.
        // Falls back to jsEstimateTokens() when the cap value is missing
        // (older config object, pre-Issue-61 deployments, etc.).
        function jsEstimateCappedTokens(text) {
            var capChars = (window.presshubBriefingAdmin && presshubBriefingAdmin.cap_chars_per_article)
                ? parseInt(presshubBriefingAdmin.cap_chars_per_article, 10)
                : 800;
            if (!isFinite(capChars) || capChars < 100) capChars = 800;
            var plain = jsStripHtml(text || '');
            if (!plain) return 0;
            // Array.from(...) counts UTF-16 code units, not codepoints. We
            // approximate by truncating by character count; for Greek
            // (BMP) this matches PHP's mb_substr() closely. Codepoint-based
            // truncation would require a grapheme-aware helper that the
            // server side also approximates, so a tiny drift on CJK /
            // emoji-heavy text is acceptable.
            var truncated = Array.from(plain).slice(0, capChars).join('');
            return jsEstimateTokens(truncated);
        }

        function formatCount(n) {
            // Thousands separator matching PHP's number_format() output for editor consistency.
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function computeAggregateTotals() {
            var totalWords = 0;
            var totalTokens = 0;
            $('.presshub-article-checkbox:checked').each(function() {
                var $card = $(this).closest('.presshub-inspector-card');
                var w = parseInt($card.attr('data-words'), 10);
                var t = parseInt($card.attr('data-tokens'), 10);
                if (!isNaN(w) && w > 0) totalWords += w;
                if (!isNaN(t) && t > 0) totalTokens += t;
            });
            return { words: totalWords, tokens: totalTokens };
        }

        function writeAggregateTotals(totals) {
            var wordsLabel = 'Total: ' + formatCount(totals.words) + ' words';
            var tokensLabel = '~' + formatCount(totals.tokens) + ' tokens';
            $('#presshub-selected-words-total').text(wordsLabel);
            $('#presshub-selected-tokens-total').text(tokensLabel);
            $('#presshub-inspector-words-total').text(wordsLabel);
            $('#presshub-inspector-tokens-total').text(tokensLabel);

            // Issue #61 — pool-vs-LLM subtext mirrors the curator's cap math
            // on the client so it stays in sync with the article selection.
            writeLlmSubtext(totals);
        }

        // Issue #61 (S1) — client-side mirror of the curator's cap math.
        // Each inspector card now carries a server-computed
        // data-capped-tokens attribute (the LLM-facing token estimate for
        // that article, truncated to the curator's per-article char cap).
        // We just sum the first cap_articles selected cards' values, so
        // the JS subtext matches the server-rendered numbers exactly —
        // no heuristic, no scaling, no client/server drift.
        function computeCappedTokens(poolTokens) {
            var capArticles = (presshubBriefingAdmin && presshubBriefingAdmin.cap_articles)
                ? parseInt(presshubBriefingAdmin.cap_articles, 10)
                : 40;
            var capChars = (presshubBriefingAdmin && presshubBriefingAdmin.cap_chars_per_article)
                ? parseInt(presshubBriefingAdmin.cap_chars_per_article, 10)
                : 3000;
            if (!isFinite(capArticles) || capArticles < 1) capArticles = 40;
            if (!isFinite(capChars) || capChars < 100) capChars = 3000;

            var capped = 0;
            var considered = 0;
            $('.presshub-article-checkbox:checked').each(function() {
                if (considered >= capArticles) return false; // break out of .each
                var $card = $(this).closest('.presshub-inspector-card');
                // data-capped-tokens is computed server-side in the same loop
                // that builds the article card (PressHub_AI_Context_Estimator
                // over the truncated text). Falls back to data-tokens when
                // the attribute is missing (e.g. cards built by older JS
                // paths that haven't re-rendered yet).
                var t = parseInt($card.attr('data-capped-tokens'), 10);
                if (isNaN(t) || t < 0) {
                    t = parseInt($card.attr('data-tokens'), 10);
                }
                if (!isNaN(t) && t > 0) {
                    capped += t;
                }
                considered++;
            });
            return {
                tokens: capped,
                cap_articles: capArticles,
                cap_chars: capChars
            };
        }

        function writeLlmSubtext(poolTotals) {
            var capped = computeCappedTokens(poolTotals.tokens);
            var $nodes = $('#presshub-milestone-llm-subtext, #presshub-inspector-llm-subtext');
            if ($nodes.length === 0) return;
            // Hide subtext when there's no cap pressure (capped == pool or pool == 0).
            if (!poolTotals || !poolTotals.tokens || capped.tokens <= 0 || capped.tokens >= poolTotals.tokens) {
                $nodes.hide();
                return;
            }
            // Issue #61 (S4) — translatable so the JS-side recompute stays
            // consistent with the server-rendered (Greek-first) wording. The
            // matching server string lives in class-briefing-admin.php
            // (__('Of %1$s pool tokens...', 'presshub-ai-editor')).
            var text = sprintf(
                __('Of %1$s pool tokens, %2$s were sent to the LLM for curation (cap: %3$d articles × %4$d chars/article).', 'presshub-ai-editor'),
                formatCount(poolTotals.tokens),
                formatCount(capped.tokens),
                capped.cap_articles,
                capped.cap_chars
            );
            // .text() destroys child elements, so the server-rendered
            // "Configure cap in Settings" anchor must be re-appended after
            // the rewrite (Issue #61 — link points to the Settings page).
            $nodes.text(text).append(' <a href="' + (presshubBriefingAdmin && presshubBriefingAdmin.curation_settings_url || '#') + '" class="presshub-llm-subtext-link">' + __('Configure cap in Settings → Daily Briefing.', 'presshub-ai-editor') + '</a>').show();
        }

        function updateSelectedCountBadge() {
            var total = $('.presshub-article-checkbox').length;
            var selected = $('.presshub-article-checkbox:checked').length;
            var text = 'Selected: ' + selected + ' / ' + total;
            $('#presshub-selected-articles-count').text(text);
            $('#presshub-inspector-count-badge').text(text);

            // Live aggregate word/token totals reflect all selected articles,
            // independent of filter visibility (selection drives the briefing,
            // not what's currently rendered).
            writeAggregateTotals(computeAggregateTotals());

            if (total > 0 && selected === total) {
                $('#presshub-select-all-checkbox').prop('checked', true).prop('indeterminate', false);
            } else if (selected === 0) {
                $('#presshub-select-all-checkbox').prop('checked', false).prop('indeterminate', false);
            } else {
                $('#presshub-select-all-checkbox').prop('checked', false).prop('indeterminate', true);
            }
        }

        // -------------------------------------------------------------------------
        // UI Refresh from Status Response
        // -------------------------------------------------------------------------
        function updateUIFromStatus(status) {
            if (!status) return;

            // Issue #88 — publish the latest server status to a JS-visible
            // surface (window.presshubBriefingAdmin.lastStatus) so that:
            //   (a) regression tests can assert against the canonical
            //       status without scraping the DOM, and
            //   (b) any code path that wants to react to the current
            //       curation post (e.g. showing the new title in another
            //       widget) has a single source of truth.
            // The object is mutated in place to avoid clobbering the
            // wp_localize_script bag that lives at the same key.
            var bag = window.presshubBriefingAdmin;
            if (!bag || typeof bag !== 'object') {
                bag = {};
                window.presshubBriefingAdmin = bag;
            }
            bag.lastStatus = status;

            // 1. Harvesting Status & Articles Table
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

            // Populate / Refresh harvested articles inspector
            if (status.articles && Array.isArray(status.articles)) {
                var $inspectorList = $('#presshub-inspector-articles-list');
                var $sourceFilter = $('#presshub-article-source-filter');
                if (status.articles.length > 0) {
                    var cardsHtml = '';
                    var sourcesSet = {};

                    $.each(status.articles, function(idx, art) {
                        var title = art.title || 'Untitled';
                        var src = art.source || 'Unknown';
                        var url = art.url || '';
                        var content = art.content || '';
                        var words = jsWordCount(content);
                        var tokens = jsEstimateTokens(content);
                        var cappedTokens = jsEstimateCappedTokens(content);
                        var chars = content ? content.length : 0;
                        var capChars = (window.presshubBriefingAdmin && presshubBriefingAdmin.cap_chars_per_article)
                            ? parseInt(presshubBriefingAdmin.cap_chars_per_article, 10)
                            : 800;
                        if (!isFinite(capChars) || capChars < 100) capChars = 800;
                        sourcesSet[src] = true;

                        var escTitle = $('<div>').text(title).html();
                        var escSrc = $('<div>').text(src).html();
                        var escUrl = $('<div>').text(url).html();
                        var escContent = $('<div>').text(content).html().replace(/\n/g, '<br>');

                        cardsHtml += '<div class="presshub-inspector-card" data-index="' + idx + '" data-source="' + $('<div>').text(src.toLowerCase()).html() + '" data-title="' + $('<div>').text(title.toLowerCase()).html() + '" data-text="' + $('<div>').text(content.toLowerCase().substring(0, 500)).html() + '" data-words="' + words + '" data-tokens="' + tokens + '" data-capped-tokens="' + cappedTokens + '" data-cap-chars="' + capChars + '">' +
                            '<div class="presshub-inspector-card-header">' +
                                '<div class="inspector-card-check">' +
                                    '<input type="checkbox" class="presshub-article-checkbox" value="' + idx + '" checked="checked" id="inspector-check-' + idx + '" />' +
                                '</div>' +
                                '<div class="inspector-card-meta">' +
                                    '<span class="presshub-article-source-pill">' + escSrc + '</span>' +
                                    '<span class="presshub-article-words-pill">' + formatCount(words) + ' words</span>' +
                                    '<span class="presshub-article-tokens-pill">~' + formatCount(tokens) + ' tokens</span>' +
                                '</div>' +
                                '<div class="inspector-card-title">' +
                                    '<label for="inspector-check-' + idx + '"><strong>' + escTitle + '</strong></label>' +
                                    (url ? ' <a href="' + escUrl + '" target="_blank" rel="noopener noreferrer" class="presshub-article-external-link" title="Open original source article">↗</a>' : '') +
                                '</div>' +
                                '<div class="inspector-card-toggle">' +
                                    '<button type="button" class="button button-small presshub-toggle-text-btn" data-index="' + idx + '">' +
                                        '📖 <span class="toggle-text-label">Read Text ▼</span>' +
                                    '</button>' +
                                '</div>' +
                            '</div>' +
                            '<div class="presshub-inspector-card-drawer" id="inspector-drawer-' + idx + '" style="display:none;">' +
                                '<div class="inspector-drawer-meta-bar">' +
                                    '<div class="drawer-stats">' +
                                        '<span><strong>Length:</strong> ' + words + ' words / ' + chars + ' characters</span>' +
                                        (url ? ' <span class="drawer-url"><strong>URL:</strong> <a href="' + escUrl + '" target="_blank" rel="noopener noreferrer">' + escUrl + '</a></span>' : '') +
                                    '</div>' +
                                    '<button type="button" class="button button-small presshub-copy-article-btn" data-index="' + idx + '">📋 Copy Text</button>' +
                                '</div>' +
                                '<div class="inspector-drawer-content" id="inspector-content-' + idx + '">' +
                                    (escContent ? '<p>' + escContent + '</p>' : '<p class="description"><em>No body text extracted for this article snapshot.</em></p>') +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });

                    $inspectorList.html(cardsHtml);

                    // Rebuild source dropdown options
                    var curSrcVal = $sourceFilter.val();
                    var srcOptions = '<option value="">All News Sources</option>';
                    Object.keys(sourcesSet).sort().forEach(function(s) {
                        var selectedAttr = (s === curSrcVal) ? ' selected="selected"' : '';
                        srcOptions += '<option value="' + $('<div>').text(s).html() + '"' + selectedAttr + '>' + $('<div>').text(s).html() + '</option>';
                    });
                    $sourceFilter.html(srcOptions);

                    $('.presshub-articles-summary-box').show();
                    $('#presshub-harvest-inspector-section').show();
                } else {
                    $inspectorList.html('<p class="presshub-no-articles-msg" style="padding: 20px; text-align: center; color: #666;">No harvested articles found for today. Run news scraping above to populate the pool.</p>');
                    $('.presshub-articles-summary-box').hide();
                    $('#presshub-harvest-inspector-section').hide();
                }
                updateSelectedCountBadge();
            }

                        // 1b. Render Harvest Diagnostics & Source Health Table (Issue #5)
            var $healthTbody = $('#presshub-source-health-tbody');
            if ($healthTbody.length && status.source_health && Array.isArray(status.source_health)) {
                if (status.source_health.length > 0) {
                    var healthRowsHtml = '';
                    $.each(status.source_health, function(idx, h) {
                        var hUrl = h.url || '';
                        var hStatus = (h.status || 'ok').toLowerCase();
                        var hCode = h.http_code || '-';
                        var hMethod = h.discovery_method || 'UNKNOWN';
                        var hLat = (h.latency_ms !== undefined) ? h.latency_ms + 'ms' : '-';
                        var hYield = (h.articles_yielded !== undefined) ? h.articles_yielded : 0;
                        var hReason = h.failure_reason || (hStatus === 'ok' ? 'Healthy' : '-');

                        var badgeClass = 'badge-success';
                        if (hStatus === 'blocked') badgeClass = 'badge-danger';
                        else if (hStatus === 'warning') badgeClass = 'badge-warning';
                        else if (hStatus === 'error') badgeClass = 'badge-danger';

                        var methodClass = 'method-html';
                        if (hMethod === 'RSS_FEED' || hMethod === 'FEED_DIRECT') methodClass = 'method-rss';

                        var escUrl = $('<div>').text(hUrl).html();
                        var escReason = $('<div>').text(hReason).html();

                        healthRowsHtml += '<tr>' +
                            '<td><code title="' + escUrl + '">' + escUrl + '</code></td>' +
                            '<td><span class="presshub-status-pill ' + badgeClass + '">' + hStatus.toUpperCase() + '</span></td>' +
                            '<td><strong>' + hCode + '</strong></td>' +
                            '<td><span class="presshub-method-pill ' + methodClass + '">' + hMethod + '</span></td>' +
                            '<td>' + hLat + '</td>' +
                            '<td><strong>' + hYield + '</strong></td>' +
                            '<td><small>' + escReason + '</small></td>' +
                        '</tr>';
                    });
                    $healthTbody.html(healthRowsHtml);
                } else {
                    $healthTbody.html('<tr><td colspan="7" style="text-align: center; color: #777; padding: 15px;">No harvest diagnostics recorded yet for this date.</td></tr>');
                }
            }

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

                // Issue #65 — Bug B: refresh the "WP Status: <code>" subtitle
                // in place so operators see the post's WP publish state (pending
                // /draft/publish) without a page reload. The label uses the
                // localized template from presshubBriefingAdmin.i18n.wp_status_label.
                var $wpStatus = $('#presshub-text-post-status');
                if ($wpStatus.length) {
                    var statusLabel = (window.presshubBriefingAdmin && presshubBriefingAdmin.i18n && presshubBriefingAdmin.i18n.wp_status_label)
                        ? presshubBriefingAdmin.i18n.wp_status_label
                        : 'WP Status: %s';
                    $wpStatus.html(
                        statusLabel.replace('%s', '<code>' + (status.text_post_status || 'pending') + '</code>')
                    );
                }
            } else {
                $('#milestone-curation').removeClass('card-complete').addClass('card-pending');
                $('#status-badge-curation').removeClass('badge-success').addClass('badge-secondary').text('Pending Generation');
                $('#btn-run-curation').text('✍️ Generate Text Story');
                // Remove the WP status subtitle when there's no post yet.
                $('#presshub-text-post-status').remove();
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

            // -----------------------------------------------------------------
            // Issue #79 — Per-stage status boxes. The server renders initial
            // values; this block refreshes the chips/pills/badges on every
            // AJAX poll without a page reload. Idempotent (overwrites the
            // same DOM nodes).
            // -----------------------------------------------------------------
            renderStageStatusBoxes(status);

            // Issue #79 — Stage 2 source-context + post status pills.
            renderCurationStatusPills(status);

            // Issue #79 — Stage 3 script context-mode + source post id pill.
            renderScriptStatusPills(status);

            // Issue #79 — Stage 4 audio stats (duration, filesize, format,
            // engine, mode, voices, post status, attachment id).
            renderAudioStatusPills(status);
        }

        /**
         * Issue #79 — Render the four `.presshub-stage-status-box` containers
         * using the `status.stage_events` dictionary the server provides.
         *
         * Each box has up to three children:
         *   - timestamp row (attempted + completed + duration pill),
         *   - context row (sources / post status / engine / voice chips),
         *   - error line (when stage status === 'error').
         */
        function renderStageStatusBoxes(status) {
            if (!status || !status.stage_events) return;
            var events = status.stage_events;
            var stageKeys = ['harvest', 'curation', 'script', 'audio'];
            // Issue #83 — placeholder text used when a stage has no
            // recorded activity for the selected date. Translates via
            // the standard __() helper so the wording mirrors the PHP
            // pre-render on first paint.
            var emptyText = (window.wp && window.wp.i18n && window.wp.i18n.__)
                ? window.wp.i18n.__('Awaiting first run for this date.', 'presshub-ai-editor')
                : 'Awaiting first run for this date.';
            for (var i = 0; i < stageKeys.length; i++) {
                var key   = stageKeys[i];
                var event = events[key] || {};
                var box   = $('#stage-' + key + '-status-box');
                if (!box.length) continue;
                // Replace inner HTML idempotently — server may have added
                // new sub-pills since the last AJAX tick.
                box.empty();
                // Issue #83 — defense-in-depth: when the server returned
                // no log rows for this stage on this date, paint a small
                // placeholder line so the editor sees an explicit empty
                // state instead of a bare empty container.
                if (!event.attempted_at && !event.completed_at && (!event.duration_ms || event.duration_ms <= 0) && event.status !== 'error') {
                    box.append('<p class="description presshub-stage-empty">⏳ ' + escapeHtml(emptyText) + '</p>');
                    continue;
                }
                var row = $('<div class="presshub-status-row"></div>');
                if (event.attempted_at) {
                    var chip = $('<span class="presshub-timestamp-chip">⏱ Attempted: ' + escapeHtml(event.attempted_at) + '</span>');
                    chip.attr('title', 'Pipeline stage start timestamp');
                    row.append(chip);
                }
                if (event.completed_at) {
                    var chip2 = $('<span class="presshub-timestamp-chip">✅ Completed: ' + escapeHtml(event.completed_at) + '</span>');
                    chip2.attr('title', 'Pipeline stage completion timestamp');
                    row.append(chip2);
                }
                if (event.duration_ms && event.duration_ms > 0) {
                    row.append('<span class="presshub-stat-pill">⏳ ' + formatDuration(event.duration_ms) + '</span>');
                }
                box.append(row);

                if (event.status === 'error' && event.error_message) {
                    box.append('<p class="presshub-stage-error description">⚠️ ' + escapeHtml(event.error_message) + '</p>');
                }
            }
        }

        /**
         * Issue #79 — Render Stage 2 (curation) post-status pill + source
         * type + preset chips.
         *
         * Issue #88 — defense-in-depth: also refresh the
         * `.card-title-preview strong` text node. The card body is
         * server-rendered once at page load (class-briefing-admin.php)
         * with the previous post title; without this line the operator
         * sees the stale title after clicking "Re-generate Story" until
         * a full page reload. The pill below already refreshed the
         * status; we now refresh the title in lockstep.
         */
        function renderCurationStatusPills(status) {
            if (!status || !status.text_created) return;
            var pill = $('#presshub-curation-post-status-pill');
            if (!pill.length) return;
            var statusText = (status.text_post_status || '').replace(/^./, function (c) {
                return c.toUpperCase();
            });
            var cls = 'badge-secondary';
            if (status.text_post_status === 'publish') cls = 'badge-success';
            else if (status.text_post_status === 'pending') cls = 'badge-warning';
            else if (status.text_post_status === 'trash') cls = 'badge-danger';
            pill.removeClass('badge-success badge-warning badge-danger badge-secondary')
                .addClass(cls)
                .html('📰 Post #' + (status.text_post_id || 0) + ' · ' + escapeHtml(statusText));

            // Issue #88 — refresh the bounded-box title preview from the
            // latest status payload. Guarded by the text_post_title
            // presence so a partial payload (e.g. a harvest-only refresh)
            // never blanks the preview. Uses .text() (not .html()) so a
            // malicious or malformed server payload cannot inject markup.
            if (typeof status.text_post_title === 'string' && status.text_post_title.length > 0) {
                var $titlePreview = $('.card-title-preview strong');
                if ($titlePreview.length) {
                    $titlePreview.text(status.text_post_title);
                }
            }
        }

        /**
         * Issue #79 — Render Stage 3 (script) context-mode pill.
         */
        function renderScriptStatusPills(status) {
            if (!status || !status.script_meta) return;
            var mode = status.script_meta.context_mode;
            if (!mode) return;
            var box = $('#stage-script-status-box .presshub-status-row').eq(1);
            if (!box.length) return;
            box.empty();
            if (mode === 'curated_briefing') {
                var html = '📰 Curated Morning Briefing';
                if (status.script_meta.source_post_id) {
                    html += ' · <code>#' + status.script_meta.source_post_id + '</code>';
                }
                box.append('<span class="presshub-source-tag">' + html + '</span>');
            } else if (mode === 'harvested_articles') {
                box.append('<span class="presshub-source-tag">🌐 Direct Harvested Articles</span>');
            }
        }

        /**
         * Issue #79 — Render Stage 4 (audio) stat pills, engine/mode chips,
         * voice chips, post status pill, attachment pill.
         */
        function renderAudioStatusPills(status) {
            if (!status || !status.audio_meta) return;
            var audio = status.audio_meta;
            var box = $('#stage-audio-status-box');
            if (!box.length) return;
            // Remove any prior injected sub-rows (idempotent).
            box.find('.presshub-status-row').slice(1).remove();
            // Voice chips row
            var voiceRow = $('<div class="presshub-status-row"></div>');
            var hostCount = parseInt(audio.host_count, 10) || 2;
            var voices = [];
            if (hostCount >= 1 && audio.female_voice) {
                voices.push('🎙 ' + audio.female_voice + ' (Presenter 1)');
            }
            if (hostCount >= 2 && audio.male_voice) {
                voices.push('🎙 ' + audio.male_voice + ' (Presenter 2)');
            }
            if (hostCount >= 3 && audio.tertiary_voice) {
                voices.push('🎙 ' + audio.tertiary_voice + ' (Presenter 3)');
            }
            if (voices.length) {
                voiceRow.css('margin-top', '4px');
                for (var i = 0; i < voices.length; i++) {
                    voiceRow.append('<span class="presshub-voice-chip">' + escapeHtml(voices[i]) + '</span>');
                }
                box.append(voiceRow);
            }
            // Post status pill (refresh; preserve original styling).
            if (status.audio_created) {
                var podStatus = (status.podcast_post_status || '').replace(/^./, function (c) {
                    return c.toUpperCase();
                });
                var podCls = 'badge-secondary';
                if (status.podcast_post_status === 'publish') podCls = 'badge-success';
                else if (status.podcast_post_status === 'pending') podCls = 'badge-warning';
                else if (status.podcast_post_status === 'trash') podCls = 'badge-danger';
                var postPill = $('#presshub-podcast-post-status-pill');
                if (postPill.length) {
                    postPill.removeClass('badge-success badge-warning badge-danger badge-secondary')
                        .addClass(podCls)
                        .html('🎙 Post #' + (status.podcast_post_id || 0) + ' · ' + escapeHtml(podStatus));
                }
                // Attachment pill already server-rendered; nothing to refresh.
            }
        }

        /**
         * Issue #79 — Format ms duration as HH:MM:SS or MM:SS for compactness.
         */
        function formatDuration(ms) {
            var totalSec = Math.floor(ms / 1000);
            var h = Math.floor(totalSec / 3600);
            var m = Math.floor((totalSec % 3600) / 60);
            var s = totalSec % 60;
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            if (h > 0) return pad(h) + ':' + pad(m) + ':' + pad(s);
            return pad(m) + ':' + pad(s);
        }

        /**
         * Issue #79 — Escape HTML special characters to prevent XSS in
         * chip rendering. Falls back to a noop when the value is undefined.
         */
        function escapeHtml(value) {
            if (value === undefined || value === null) return '';
            return String(value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
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
        // Article Selection and Inspector Filter Engine
        // -------------------------------------------------------------------------
        function filterInspectorArticles() {
            var search = $.trim($('#presshub-article-search').val()).toLowerCase();
            var source = $.trim($('#presshub-article-source-filter').val()).toLowerCase();
            var selection = $('#presshub-article-selection-filter').val();

            var visibleCount = 0;
            $('.presshub-inspector-card').each(function() {
                var $card = $(this);
                var cardSource = ($card.data('source') || '').toString().toLowerCase();
                var cardTitle = ($card.data('title') || '').toString().toLowerCase();
                var cardText = ($card.data('text') || '').toString().toLowerCase();
                var isChecked = $card.find('.presshub-article-checkbox').is(':checked');

                var matchesSearch = !search || (cardTitle.indexOf(search) !== -1 || cardText.indexOf(search) !== -1 || cardSource.indexOf(search) !== -1);
                var matchesSource = !source || (cardSource === source);
                var matchesSelection = true;

                if (selection === 'selected') {
                    matchesSelection = isChecked;
                } else if (selection === 'unselected') {
                    matchesSelection = !isChecked;
                }

                if (matchesSearch && matchesSource && matchesSelection) {
                    $card.show();
                    visibleCount++;
                } else {
                    $card.hide();
                }
            });

            var total = $('.presshub-article-checkbox').length;
            var selected = $('.presshub-article-checkbox:checked').length;
            $('#presshub-inspector-count-badge').text('Selected: ' + selected + ' / ' + total + (search || source || selection !== 'all' ? ' (Showing ' + visibleCount + ')' : ''));
        }

        $(document).on('input', '#presshub-article-search', filterInspectorArticles);
        $(document).on('change', '#presshub-article-source-filter', filterInspectorArticles);
        $(document).on('change', '#presshub-article-selection-filter', filterInspectorArticles);

        $(document).on('change', '.presshub-article-checkbox', function() {
            updateSelectedCountBadge();
            if ($('#presshub-article-selection-filter').val() !== 'all') {
                filterInspectorArticles();
            }
        });

        // Inspector Select / Deselect All
        $('#btn-inspector-select-all, #btn-select-all-articles').on('click', function(e) {
            e.preventDefault();
            $('.presshub-inspector-card:visible .presshub-article-checkbox, .presshub-article-checkbox').prop('checked', true);
            updateSelectedCountBadge();
        });

        $('#btn-inspector-deselect-all, #btn-deselect-all-articles').on('click', function(e) {
            e.preventDefault();
            $('.presshub-inspector-card:visible .presshub-article-checkbox, .presshub-article-checkbox').prop('checked', false);
            updateSelectedCountBadge();
        });

        // Toggle individual article text drawer
        $(document).on('click', '.presshub-toggle-text-btn', function(e) {
            e.preventDefault();
            var idx = $(this).data('index');
            var $drawer = $('#inspector-drawer-' + idx);
            var $btn = $(this);
            var $label = $btn.find('.toggle-text-label');

            if ($drawer.is(':visible')) {
                $drawer.slideUp(180);
                $label.text('Read Text ▼');
            } else {
                $drawer.slideDown(220);
                $label.text('Hide Text ▲');
            }
        });

        // Expand All / Collapse All Texts
        $('#btn-inspector-expand-all').on('click', function(e) {
            e.preventDefault();
            $('.presshub-inspector-card:visible .presshub-inspector-card-drawer').slideDown(200);
            $('.presshub-inspector-card:visible .toggle-text-label').text('Hide Text ▲');
        });

        $('#btn-inspector-collapse-all').on('click', function(e) {
            e.preventDefault();
            $('.presshub-inspector-card .presshub-inspector-card-drawer').slideUp(200);
            $('.presshub-inspector-card .toggle-text-label').text('Read Text ▼');
        });

        // Copy Article Text to Clipboard
        $(document).on('click', '.presshub-copy-article-btn', function(e) {
            e.preventDefault();
            var idx = $(this).data('index');
            var $btn = $(this);
            var text = $('#inspector-content-' + idx).text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    var orig = $btn.text();
                    $btn.text('✅ Copied!');
                    setTimeout(function() {
                        $btn.text(orig);
                    }, 2000);
                });
            } else {
                showNotice('info', 'Text selected: use Ctrl+C to copy.');
            }
        });

        // Smooth scroll to inspector
        $('#btn-scroll-to-inspector').on('click', function(e) {
            var target = $(this).attr('href');
            if (target && $(target).length) {
                e.preventDefault();
                $('html, body').animate({
                    scrollTop: $(target).offset().top - 40
                }, 400);
            }
        });

        // -------------------------------------------------------------------------
        // Event: Date Picker Change
        // -------------------------------------------------------------------------
                // Event: Refresh Health Status Button
        $('#btn-refresh-diagnostics').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            setButtonLoading($btn, true, null, 'Refreshing...');
            fetchStatus(currentDate, function() {
                setButtonLoading($btn, false);
                showNotice('info', 'Refreshed source health diagnostics.');
            });
        });

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
                        if (response.data && response.data.budget_exceeded) {
                            var durationSec = response.data.duration_ms ? Math.round(response.data.duration_ms / 1000) : 25;
                            showNotice('warning', response.data.notice || ('Harvest reached time budget (' + durationSec + 's). ' + count + ' articles collected and saved.'));
                        } else {
                            showNotice('success', 'Scraped successfully! ' + count + ' articles saved to snapshot.');
                        }
                        fetchStatus(currentDate);
                    } else {
                        var errMsg = (typeof response.data === 'object' && response.data !== null && response.data.message) ? response.data.message : (response.data || 'Harvest failed.');
                        showNotice('error', errMsg);
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    var errMsg = 'Scrape network error: ' + error;
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        if (typeof xhr.responseJSON.data === 'string') {
                            errMsg = xhr.responseJSON.data;
                        } else if (xhr.responseJSON.data.message) {
                            errMsg = xhr.responseJSON.data.message;
                        }
                    }
                    showNotice('error', errMsg);
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

            var selectedArticles = getSelectedArticleIndices();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_run_curation',
                    nonce: nonce,
                    date: currentDate,
                    selected_articles: selectedArticles
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

            var selectedArticles = getSelectedArticleIndices();
            var contextMode = $('input[name="presshub_podcast_context_mode"]:checked').val() || 'curated_briefing';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'presshub_ai_briefing_run_script',
                    nonce: nonce,
                    date: currentDate,
                    context_mode: contextMode,
                    selected_articles: selectedArticles
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

            var scriptB64 = '';
            try {
                scriptB64 = window.btoa(unescape(encodeURIComponent(scriptText)));
            } catch (e) {
                scriptB64 = '';
            }

            var ajaxData = {
                action: 'presshub_ai_briefing_save_script',
                nonce: nonce,
                date: currentDate
            };
            if (scriptB64) {
                ajaxData.script_b64 = scriptB64;
            } else {
                ajaxData.script = scriptText;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    setButtonLoading($btn, false);
                    if (response.success) {
                        $('#script-save-status').text('Saved ' + (response.data.turns_count || 0) + ' turns').show().delay(3000).fadeOut();
                        showNotice('success', 'Podcast script saved successfully!');
                        fetchStatus(currentDate);
                    } else {
                        showNotice('error', response.data || 'Failed to save script.');
                    }
                },
                error: function(xhr, status, error) {
                    setButtonLoading($btn, false);
                    showNotice('error', 'Save script error: ' + error);
                }
            });
        });

        // -------------------------------------------------------------------------
        // Event: Generate Audio from Script
        // -------------------------------------------------------------------------
        $('#btn-synthesize-audio, #btn-generate-audio').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var scriptText = $('#presshub-briefing-script-editor').val();

            setButtonLoading($btn, true, null, i18n.generating_audio || 'Synthesizing voice audio...');

            var scriptB64 = '';
            try {
                scriptB64 = window.btoa(unescape(encodeURIComponent(scriptText)));
            } catch (e) {
                scriptB64 = '';
            }

            var ajaxData = {
                action: 'presshub_ai_briefing_generate_audio',
                nonce: nonce,
                date: currentDate
            };
            if (scriptB64) {
                ajaxData.script_b64 = scriptB64;
            } else {
                ajaxData.script = scriptText;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: ajaxData,
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

            var selectedArticles = getSelectedArticleIndices();

            // Step 1: Harvest
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: { action: 'presshub_ai_briefing_run_harvest', nonce: nonce, date: currentDate },
                success: function(res1) {
                    if (!res1.success) {
                        setButtonLoading($btn, false);
                        var errMsg = (typeof res1.data === 'object' && res1.data !== null && res1.data.message) ? res1.data.message : (res1.data || 'Step 1 (Scrape) failed');
                        showNotice('error', 'Step 1 (Scrape) failed: ' + errMsg);
                        return;
                    }
                    if (res1.data && res1.data.budget_exceeded) {
                        showNotice('info', 'Step 1 partial harvest saved (' + (res1.data.articles ? res1.data.articles.length : 0) + ' articles). Curating text story...');
                    } else {
                        showNotice('info', 'Step 1 Complete. Curating text story...');
                    }

                    // Step 2: Curation
                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'presshub_ai_briefing_run_curation',
                            nonce: nonce,
                            date: currentDate,
                            selected_articles: selectedArticles
                        },
                        success: function(res2) {
                            if (!res2.success) {
                                setButtonLoading($btn, false);
                                showNotice('error', 'Step 2 (Curation) failed: ' + res2.data);
                                return;
                            }
                            showNotice('info', 'Step 2 Complete. Generating podcast script...');

                            // Step 3: Script
                            var contextMode = $('input[name="presshub_podcast_context_mode"]:checked').val() || 'curated_briefing';
                            $.ajax({
                                url: ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'presshub_ai_briefing_run_script',
                                    nonce: nonce,
                                    date: currentDate,
                                    context_mode: contextMode,
                                    selected_articles: selectedArticles
                                },
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

    // -------------------------------------------------------------------------
        // Initial Paint: recompute aggregate totals from server-rendered cards
        // -------------------------------------------------------------------------
        // updateSelectedCountBadge() reads the data-words / data-tokens of the
        // already-rendered cards and writes the live aggregate spans. Without
        // this boot call the milestone card and inspector toolbar display the
        // PHP placeholder values (0 words / ~0 tokens) until the first
        // checkbox toggle or Scrape AJAX completes. Fixes #57.
        if (typeof updateSelectedCountBadge === 'function') {
            updateSelectedCountBadge();
        }

    });
})(jQuery);
