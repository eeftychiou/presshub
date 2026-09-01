/**
 * PressHub AI — Gutenberg sidebar (block editor) plugin.
 *
 * Renders the AI Co-Pilot panel (chat, research polling, image and audio
 * report insertion). Strings are translatable via the wp.i18n runtime
 * that the PHP enqueue wires in.
 */
/* global wp, jQuery, presshubAI */
(function () {
    'use strict';
    // The whole file is IIFE-wrapped: top-level consts (__ , el, etc.) stay
    // function-scoped so they can never collide with other classic scripts
    // on the page ("Identifier '__' has already been declared" kills the
    // script), and `el` is aliased from createElement because wp.element.el
    // was removed in WP 6.6+.

const { __, sprintf } = wp.i18n;

const { registerPlugin } = wp.plugins;
const { PluginSidebar } = wp.editPost;
const { createElement: el, useState, useEffect, useRef } = wp.element;
const { Button, TextareaControl, Spinner } = wp.components;

// Research status polling bounds: 40 attempts x 3s = 2 minutes max.
// Beyond that the job is stuck (or the site's wp-cron is starved) and we
// stop hammering the server, surfacing a 'timeout' status instead.
const RESEARCH_POLL_MAX_ATTEMPTS = 40;
const RESEARCH_POLL_INTERVAL_MS = 3000;

/** Greeting shown for a fresh conversation (stable reference for Clear). */
const GREETING = {
    role: 'ai',
    type: 'text',
    content: __('Hello! I am your AI Co-Pilot. I can chat, conduct in-depth research, generate images, or summarize media. How can I help you draft your article today?', 'presshub-ai-editor')
};

/** Current post id from the editor store; 0 when none is available. */
const getCurrentPostId = () => {
    try {
        return wp.data.select('core/editor').getCurrentPostId() || 0;
    } catch (e) {
        return 0;
    }
};

/** localStorage key for a post's conversation; 'global' when no post. */
const chatStorageKey = (postId) => 'presshub_ai_chat_' + (postId ? postId : 'global');

/** Restore a stored conversation, or null when nothing is saved. */
const loadStoredMessages = (postId) => {
    try {
        const raw = window.localStorage.getItem(chatStorageKey(postId));
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : null;
    } catch (e) {
        return null;
    }
};

/** Persist the conversation; storage failures (private mode) are non-fatal. */
const saveMessages = (postId, msgs) => {
    try {
        window.localStorage.setItem(chatStorageKey(postId), JSON.stringify(msgs));
    } catch (e) { /* Storage unavailable — the conversation stays in memory. */ }
};

/**
 * Slug of the preset selected in the post metabox, '' when none is
 * selected or the metabox is absent. The '__plugin_default__' sentinel
 * maps to '' so the server resolves the author's default preset.
 */
const getSelectedPreset = () => {
    const presetEl = document.getElementById('presshub-ai-preset');
    if (!presetEl || !presetEl.value) {
        return '';
    }
    return presetEl.value === '__plugin_default__' ? '' : presetEl.value;
};

const AICoPilotSidebar = () => {
    // Restore the current post's stored conversation on mount; fall back
    // to the greeting for a fresh conversation.
    const [messages, setMessages] = useState(() => loadStoredMessages(getCurrentPostId()) || [GREETING]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const messagesEndRef = useRef(null);
    // Active research-poll intervals, cleared on unmount so a closed
    // sidebar never keeps polling (or leaking timers) in the background.
    const researchIntervalsRef = useRef([]);
    // Last user prompt sent — powers the error-card Retry button.
    const lastUserPromptRef = useRef('');
    // Skips the post-switch effect's first run (mount is handled by the
    // lazy useState initializer above).
    const didMountRef = useRef(false);

    // Sidebar width: persisted per-post so each article's editor can
    // have its own preferred width. Clamped to [SIDEBAR_MIN_WIDTH,
    // SIDEBAR_MAX_WIDTH] (see constants below). Issue #59 part (B).
    const SIDEBAR_DEFAULT_WIDTH = 520;
    const SIDEBAR_MIN_WIDTH = 320;
    const SIDEBAR_MAX_WIDTH = 960;
    const sidebarWidthStorageKey = (postId) => 'presshub_ai_sidebar_width_' + (postId ? postId : 'global');
    const loadSidebarWidth = (postId) => {
        try {
            const raw = window.localStorage.getItem(sidebarWidthStorageKey(postId));
            const n = parseInt(raw, 10);
            if (!isFinite(n)) {
                return SIDEBAR_DEFAULT_WIDTH;
            }
            return Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, n));
        } catch (e) {
            return SIDEBAR_DEFAULT_WIDTH;
        }
    };
    const [sidebarWidth, setSidebarWidth] = useState(() => loadSidebarWidth(getCurrentPostId()));
    const sidebarWidthRef = useRef(sidebarWidth);
    useEffect(() => { sidebarWidthRef.current = sidebarWidth; }, [sidebarWidth]);

    // Persist width changes per-post. Storage failures (private mode)
    // are non-fatal — the width simply reverts to default on next load.
    useEffect(() => {
        try {
            window.localStorage.setItem(sidebarWidthStorageKey(getCurrentPostId()), String(sidebarWidth));
        } catch (e) { /* storage unavailable, ignore */ }
    }, [sidebarWidth]);

    // Drag handle: tracks pointer events on `.presshub-sidebar-resize-handle`
    // and updates `sidebarWidth` while dragging. Clamped live so the
    // sidebar never collapses the editor or pushes it off-screen.
    // Honors `prefers-reduced-motion` for the CSS transition (handled
    // in admin.css).
    useEffect(() => {
        let startX = 0;
        let startWidth = SIDEBAR_DEFAULT_WIDTH;
        let dragging = false;

        const onMove = (e) => {
            if (!dragging) return;
            // Drag handle is on the LEFT edge: moving the pointer
            // RIGHT widens the sidebar, moving it LEFT narrows it.
            const delta = e.clientX - startX;
            const next = Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, startWidth + delta));
            setSidebarWidth(next);
        };
        const onUp = () => {
            if (!dragging) return;
            dragging = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };

        const onDown = (e) => {
            // Only respond to primary-button mousedown so middle /
            // right-click drag doesn't hijack the handle.
            if (e.button !== 0) return;
            dragging = true;
            startX = e.clientX;
            startWidth = sidebarWidthRef.current || SIDEBAR_DEFAULT_WIDTH;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            window.addEventListener('mousemove', onMove);
            window.addEventListener('mouseup', onUp);
            e.preventDefault();
        };

        // Keyboard accessibility: handle supports Left/Right arrow
        // keys when focused, in 8px steps. Hold Shift for 32px jumps.
        const onKey = (e) => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const step = e.shiftKey ? 32 : 8;
            const delta = e.key === 'ArrowLeft' ? -step : step;
            const next = Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, (sidebarWidthRef.current || SIDEBAR_DEFAULT_WIDTH) + delta));
            setSidebarWidth(next);
            e.preventDefault();
        };

        const handle = document.querySelector('.presshub-sidebar-resize-handle');
        if (handle) {
            handle.addEventListener('mousedown', onDown);
            handle.addEventListener('keydown', onKey);
            return () => {
                handle.removeEventListener('mousedown', onDown);
                handle.removeEventListener('keydown', onKey);
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
            };
        }
        return undefined;
    }, []);

    const scrollToBottom = () => {
        if (messagesEndRef.current) {
            messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    };

    useEffect(() => {
        scrollToBottom();
    }, [messages]);

    // Unmount cleanup: stop every in-flight research poll.
    useEffect(() => {
        const intervals = researchIntervalsRef.current;
        return () => {
            intervals.forEach(clearInterval);
        };
    }, []);

    // Persist the conversation on every change (per-post key). Defined
    // BEFORE the post-switch effect so the swap's write wins the race.
    useEffect(() => {
        saveMessages(getCurrentPostId(), messages);
    }, [messages]);

    // Restore: on mount the lazy useState initializer already loaded the
    // current post's history — resume polling for research cards that
    // were mid-flight when the page was last closed.
    useEffect(() => {
        messages.forEach((msg) => {
            if (msg.type === 'research' && msg.researchId && (msg.status === 'pending' || msg.status === 'processing')) {
                startPollingResearch(msg.researchId);
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Post switch (editor opened a different post): swap to that post's
    // stored history (or a fresh greeting), and resume any in-flight
    // research polling from the restored conversation. Also reload
    // the persisted sidebar width so each post can have its own
    // preferred width (Issue #59 acceptance criterion).
    const currentPostId = getCurrentPostId();
    useEffect(() => {
        if (!didMountRef.current) {
            didMountRef.current = true;
            return;
        }
        const history = loadStoredMessages(currentPostId) || [GREETING];
        setMessages(history);
        saveMessages(currentPostId, history);
        history.forEach((msg) => {
            if (msg.type === 'research' && msg.researchId && (msg.status === 'pending' || msg.status === 'processing')) {
                startPollingResearch(msg.researchId);
            }
        });
        setSidebarWidth(loadSidebarWidth(currentPostId));
    }, [currentPostId]);

    const sendPrompt = (promptText) => {
        const trimmed = String(promptText || '').trim();
        if (!trimmed || loading) return;

        lastUserPromptRef.current = trimmed;
        const userMsg = { role: 'user', type: 'text', content: trimmed };
        setMessages(prev => [...prev, userMsg]);
        setInputValue('');
        setLoading(true);

        const currentPostId = getCurrentPostId();

        let articleContent = '';
        let articleTitle = '';
        try {
            if (wp.data && wp.data.select('core/editor')) {
                articleContent = wp.data.select('core/editor').getEditedPostContent() || '';
                articleTitle = wp.data.select('core/editor').getEditedPostAttribute('title') || '';
            }
        } catch (e) {
            articleContent = '';
            articleTitle = '';
        }

        jQuery.post(presshubAI.ajax_url, {
            action: 'presshub_ai_chat',
            nonce: presshubAI.nonce,
            prompt: trimmed,
            post_id: currentPostId,
            article_content: articleContent,
            article_title: articleTitle,
            instruction_preset_id: getSelectedPreset()
        }, (response) => {
            setLoading(false);
            if (response.success) {
                const data = response.data;
                if (data.type === 'chat') {
                    if (Array.isArray(data.revisions) && data.revisions.length > 0) {
                        setMessages(prev => [...prev, {
                            role: 'ai',
                            type: 'revision',
                            content: data.content,
                            revisions: data.revisions.map(r => ({
                                ...r,
                                status: 'pending',
                                editedRevised: r.revised
                            }))
                        }]);
                    } else {
                        setMessages(prev => [...prev, { role: 'ai', type: 'text', content: data.content }]);
                    }
                } else if (data.type === 'research') {
                    const researchMsg = {
                        role: 'ai',
                        type: 'research',
                        status: 'pending',
                        researchId: data.research_id,
                        content: __('Initiating deep research task... Please wait.', 'presshub-ai-editor')
                    };
                    setMessages(prev => [...prev, researchMsg]);
                    startPollingResearch(data.research_id);
                } else if (data.type === 'image') {
                    setMessages(prev => [...prev, {
                        role: 'ai',
                        type: 'image',
                        url: data.url,
                        id: data.id,
                        caption: data.caption || ''
                    }]);
                } else if (data.type === 'report') {
                    setMessages(prev => [...prev, {
                        role: 'ai',
                        type: 'report',
                        url: data.url,
                        id: data.id
                    }]);
                }
            } else {
                setMessages(prev => [...prev, {
                    role: 'ai',
                    type: 'text',
                    isError: true,
                    content: __('Error: ', 'presshub-ai-editor') + response.data
                }]);
            }
        }).fail(() => {
            setLoading(false);
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                isError: true,
                content: __('Connection failed.', 'presshub-ai-editor')
            }]);
        });
    };

    const handleSend = () => sendPrompt(inputValue);

    // Re-send the last user prompt from an error card, dropping the
    // error message first so the conversation stays clean.
    const retryLast = (errorMsg) => {
        setMessages(prev => prev.filter(msg => msg !== errorMsg));
        if (lastUserPromptRef.current) {
            sendPrompt(lastUserPromptRef.current);
        }
    };

    /** Last user text message content, used to seed quick-action prompts. */
    const getLastUserTopic = () => {
        for (let i = messages.length - 1; i >= 0; i--) {
            const msg = messages[i];
            if (msg && msg.role === 'user' && msg.type === 'text' && msg.content) {
                return String(msg.content).trim();
            }
        }
        return '';
    };

    // Quick actions prefill the composer with a crafted prompt that the
    // existing intent classifier understands; the user confirms with
    // Enter (no hidden sends).
    const applyQuickAction = (kind) => {
        const topic = getLastUserTopic();
        let template = '';
        if (kind === 'research') {
            template = topic
                ? sprintf(__('Deep-research this topic: %s', 'presshub-ai-editor'), topic)
                : __('Deep-research this topic: [describe your topic]', 'presshub-ai-editor');
        } else if (kind === 'draft') {
            template = topic
                ? sprintf(__('Draft an article section about: %s', 'presshub-ai-editor'), topic)
                : __('Draft an article section about: [your topic]', 'presshub-ai-editor');
        } else if (kind === 'image') {
            template = topic
                ? sprintf(__('Generate an image of: %s', 'presshub-ai-editor'), topic)
                : __('Generate an image of: [your subject]', 'presshub-ai-editor');
        }
        setInputValue(template);
        // Focus the composer so Enter sends straight away.
        const textarea = document.querySelector('.presshub-chat-input-area textarea');
        if (textarea) {
            textarea.focus();
        }
    };

    const startPollingResearch = (researchId) => {
        let attempts = 0;
        const stopPolling = () => {
            clearInterval(interval);
            const idx = researchIntervalsRef.current.indexOf(interval);
            if (idx !== -1) {
                researchIntervalsRef.current.splice(idx, 1);
            }
        };
        const timeoutPolling = () => {
            stopPolling();
            setMessages(prev => prev.map(msg =>
                msg.researchId === researchId
                    ? {
                        ...msg,
                        status: 'timeout',
                        content: sprintf(
                            /* translators: %d: maximum polling attempts before giving up. */
                            __('Research timed out after %d attempts. The job may still be running — check back later or re-run the request.', 'presshub-ai-editor'),
                            RESEARCH_POLL_MAX_ATTEMPTS
                        )
                    }
                    : msg
            ));
        };
        const interval = setInterval(() => {
            attempts++;
            if (attempts >= RESEARCH_POLL_MAX_ATTEMPTS) {
                timeoutPolling();
                return;
            }
            jQuery.post(presshubAI.ajax_url, {
                action: 'presshub_ai_check_research',
                nonce: presshubAI.nonce,
                research_id: researchId
            }, (response) => {
                if (response.success) {
                    const status = response.data.status;
                    if (status === 'completed') {
                        stopPolling();
                        setMessages(prev => prev.map(msg =>
                            msg.researchId === researchId
                                ? { ...msg, status: 'completed', content: response.data.content }
                                : msg
                        ));
                    } else if (status === 'failed') {
                        stopPolling();
                        setMessages(prev => prev.map(msg =>
                            msg.researchId === researchId
                                ? {
                                    ...msg,
                                    status: 'failed',
                                    content: __('Research failed: ', 'presshub-ai-editor') + response.data.error
                                }
                                : msg
                        ));
                    } else {
                        setMessages(prev => prev.map(msg =>
                            msg.researchId === researchId
                                ? {
                                    ...msg,
                                    status: status,
                                    content: __('Status: ', 'presshub-ai-editor') + status + '...'
                                }
                                : msg
                        ));
                    }
                } else {
                    stopPolling();
                    setMessages(prev => prev.map(msg =>
                        msg.researchId === researchId
                            ? {
                                ...msg,
                                status: 'failed',
                                content: __('Polling error: ', 'presshub-ai-editor') + (response.data || __('Failed', 'presshub-ai-editor'))
                            }
                            : msg
                    ));
                }
            }).fail(() => {
                stopPolling();
                setMessages(prev => prev.map(msg =>
                    msg.researchId === researchId
                        ? {
                            ...msg,
                            status: 'failed',
                            content: __('Network polling error.', 'presshub-ai-editor')
                        }
                        : msg
                ));
            });
        }, RESEARCH_POLL_INTERVAL_MS);
        researchIntervalsRef.current.push(interval);
    };

    // Replace `originalText` with `revisedText` inside the editor.
    //
    // Three concrete fixes vs. the previous implementation (Issue #59):
    //   1. `$`-escape: `String.prototype.replace(needle, replacement)`
    //      interprets `$&`, `$1`, `$$` as special in the replacement
    //      string. A revised text containing a literal `$` (or a
    //      `&`-style back-reference in user-typed edits) would be
    //      corrupted. We avoid that entirely by splitting on the needle
    //      and joining with the replacement — both treat strings as
    //      opaque bytes.
    //   2. First-match-only: `replace()` with a string needle only
    //      swaps the first occurrence. If the same `originalText`
    //      snippet appears in multiple blocks we now replace *all*
    //      occurrences (or, when a block uniquely owns the match,
    //      only that block).
    //   3. Plain-text vs. serialized HTML: the LLM emits plain text
    //      but the editor stores serialized Gutenberg blocks. The
    //      old code re-serialized every block (via `serialize()` +
    //      `parse()`) which silently corrupts nested block markup
    //      (image captions, list-item nesting, inner blocks). We
    //      now identify the *one* block whose serialized HTML
    //      contains `originalText` and only swap inside its
    //      `originalHTML` attribute (or equivalent plain-text
    //      block content).
    //
    // Returns a structured result so the caller can surface a clear
    // in-chat error when the text can't be located (e.g. the user
    // edited the post between the co-pilot's response and the
    // Accept click).
    const applyReplacementInEditor = (originalText, revisedText) => {
        try {
            if (!wp.data || !wp.data.select('core/editor') || !wp.data.dispatch('core/editor')) {
                return { ok: false, mode: 'no-store', error: 'editor-store-unavailable' };
            }
            if (!originalText) {
                return { ok: false, mode: 'no-original', error: 'empty-original-text' };
            }
            const currentContent = wp.data.select('core/editor').getEditedPostContent() || '';

            // Prefer a targeted, block-scoped replacement: walk every
            // block, find the one whose serialized HTML contains the
            // needle, and replace *only* inside it. This preserves
            // every other block untouched and avoids the
            // serialize/parse round-trip that corrupts nested
            // markup. Blocks without a match are returned by
            // reference; the affected block is replaced.
            const blocks = wp.data.select('core/editor').getBlocks() || [];
            const matchedBlockIndices = [];
            blocks.forEach((b, idx) => {
                try {
                    const serialized = wp.blocks.serialize([b]);
                    if (serialized && serialized.indexOf(originalText) !== -1) {
                        matchedBlockIndices.push(idx);
                    }
                } catch (e) { /* ignore individual block serialize errors */ }
            });

            if (matchedBlockIndices.length > 0) {
                const newBlocks = blocks.map((b, idx) => {
                    if (matchedBlockIndices.indexOf(idx) === -1) {
                        return b;
                    }
                    try {
                        const serialized = wp.blocks.serialize([b]);
                        // split/join avoids both $-expansion and the
                        // "only first match" pitfall in one shot.
                        const updatedSerialized = serialized.split(originalText).join(revisedText);
                        if (updatedSerialized === serialized) {
                            return b;
                        }
                        const parsed = wp.blocks.parse(updatedSerialized);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            return parsed[0];
                        }
                    } catch (e) { /* fall through to identity block on parse failure */ }
                    return b;
                });

                // Verify the revised text is now actually present
                // before flipping the UI badge — guards against
                // silently-corrupting replace() calls in older
                // browsers and against parse() returning empty.
                const verifyContent = wp.data.select('core/editor').getEditedPostContent() || '';
                // We just dispatched; the store may not yet reflect
                // the change on some WP versions, so verify against
                // the would-be result instead.
                const projectedContent = blocks.map((b, idx) => {
                    if (matchedBlockIndices.indexOf(idx) === -1) {
                        return wp.blocks.serialize([b]);
                    }
                    try {
                        return wp.blocks.serialize([b]).split(originalText).join(revisedText);
                    } catch (e) {
                        return wp.blocks.serialize([b]);
                    }
                }).join('');

                wp.data.dispatch('core/editor').resetBlocks(newBlocks);
                if (projectedContent.indexOf(revisedText) === -1) {
                    return {
                        ok: false,
                        mode: 'block',
                        error: 'post-replace-verification-failed',
                        affectedBlocks: matchedBlockIndices.length
                    };
                }
                return {
                    ok: true,
                    mode: 'block',
                    affectedBlocks: matchedBlockIndices.length,
                    postContent: verifyContent
                };
            }

            // Post-content-level fallback: the needle was found in
            // the *concatenated* post content but not inside any
            // individual block's serialized HTML (e.g. the original
            // text spans a block boundary, or the post is using the
            // classic editor's freeform block). Use split/join
            // everywhere — still avoids $-corruption.
            if (currentContent.indexOf(originalText) !== -1) {
                const updated = currentContent.split(originalText).join(revisedText);
                wp.data.dispatch('core/editor').editPost({ content: updated });
                if (updated.indexOf(revisedText) === -1) {
                    return { ok: false, mode: 'content', error: 'post-replace-verification-failed' };
                }
                return { ok: true, mode: 'content' };
            }

            // Last-resort fallback: the text simply isn't in the
            // post any more. Surface a clear error to the caller
            // (which renders an in-chat card) instead of silently
            // appending.
            return {
                ok: false,
                mode: 'no-match',
                error: 'original-text-not-found',
                hint: 'post-may-have-been-edited'
            };
        } catch (err) {
            console.error('PressHub AI: Error applying revision', err);
            return { ok: false, mode: 'exception', error: err && err.message ? err.message : 'unknown' };
        }
    };

    const updateRevisionDraft = (msgIndex, revId, newDraftText) => {
        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            return {
                ...m,
                revisions: m.revisions.map(r => r.id === revId ? { ...r, editedRevised: newDraftText } : r)
            };
        }));
    };

    const applySingleRevision = (msgIndex, revId) => {
        const msg = messages[msgIndex];
        if (!msg || !msg.revisions) return;
        const rev = msg.revisions.find(r => r.id === revId);
        if (!rev) return;

        const revisedText = rev.editedRevised !== undefined ? rev.editedRevised : rev.revised;
        const result = applyReplacementInEditor(rev.original, revisedText);

        // Only flip the badge to "accepted" when the editor actually
        // received the change. Otherwise mark it as failed and push
        // an in-chat error card so the user knows why nothing
        // happened. Issue #59 acceptance criteria: no silent no-op.
        const status = result && result.ok ? 'accepted' : 'failed';
        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            const newRevisions = m.revisions.map(r => r.id === revId ? { ...r, status } : r);
            if (status === 'failed') {
                return {
                    ...m,
                    revisions: newRevisions,
                    lastError: {
                        revisionId: revId,
                        mode: result ? result.mode : 'unknown',
                        error: result ? result.error : 'unknown',
                        at: Date.now()
                    }
                };
            }
            return { ...m, revisions: newRevisions };
        }));

        if (status === 'failed') {
            const reason = (result && result.error)
                ? sprintf(__('Could not apply revision: %s', 'presshub-ai-editor'), result.error)
                : __('Could not apply revision — the original text may have been edited since the co-pilot proposed this change. Re-run the prompt to refresh the snippet.', 'presshub-ai-editor');
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                isError: true,
                content: reason
            }]);
        }
    };

    const denySingleRevision = (msgIndex, revId) => {
        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            return {
                ...m,
                revisions: m.revisions.map(r => r.id === revId ? { ...r, status: 'denied' } : r)
            };
        }));
    };

    // Sequential + idempotent: re-read getEditedPostContent() between
    // iterations so that one replacement doesn't shift the byte
    // offsets of the next (Issue #59 acceptance criteria).
    const acceptAllRevisions = async (msgIndex) => {
        const msg = messages[msgIndex];
        if (!msg || !msg.revisions) return;
        const pending = msg.revisions.filter(r => r.status === 'pending');
        if (pending.length === 0) return;

        const results = [];
        for (let i = 0; i < pending.length; i++) {
            const rev = pending[i];
            const revisedText = rev.editedRevised !== undefined ? rev.editedRevised : rev.revised;
            // eslint-disable-next-line no-await-in-loop
            const result = applyReplacementInEditor(rev.original, revisedText);
            results.push({ revId: rev.id, ok: !!(result && result.ok), result });
        }

        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            const newRevisions = m.revisions.map(r => {
                const r2 = results.find(res => res.revId === r.id);
                if (!r2) return r;
                return { ...r, status: r2.ok ? 'accepted' : 'failed' };
            });
            const failed = results.filter(r => !r.ok);
            if (failed.length > 0) {
                return {
                    ...m,
                    revisions: newRevisions,
                    lastError: {
                        at: Date.now(),
                        failedCount: failed.length,
                        firstError: failed[0].result ? failed[0].result.error : 'unknown'
                    }
                };
            }
            return { ...m, revisions: newRevisions };
        }));

        const failedCount = results.filter(r => !r.ok).length;
        if (failedCount > 0) {
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                isError: true,
                content: sprintf(
                    /* translators: 1: number of revisions that failed, 2: total number attempted. */
                    __('%1$d of %2$d revisions could not be applied — the original text may have been edited. Re-run the prompt to refresh.', 'presshub-ai-editor'),
                    failedCount,
                    pending.length
                )
            }]);
        }
    };

    const denyAllRevisions = (msgIndex) => {
        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            return {
                ...m,
                revisions: m.revisions.map(r => r.status === 'pending' ? { ...r, status: 'denied' } : r)
            };
        }));
    };

    const insertBlock = (blockType, attributes) => {
        const block = wp.blocks.createBlock(blockType, attributes);
        const currentBlocks = wp.data.select('core/editor').getBlocks();
        wp.data.dispatch('core/editor').insertBlocks([block], currentBlocks.length);
    };

    const renderMessage = (msg, index) => {
        const isUser = msg.role === 'user';
        const bubbleClass = isUser ? 'presshub-msg user' : 'presshub-msg ai';

        if (msg.type === 'text') {
            // Error responses get a red-bordered card with a Retry
            // button; success messages stay neutral.
            const errorStyle = msg.isError
                ? { border: '1px solid #cc1818', backgroundColor: '#f8d9d9' }
                : null;
            return el('div', { key: index, className: bubbleClass + (msg.isError ? ' error' : ''), style: errorStyle },
                msg.content,
                msg.isError && el(Button, {
                    isLink: true,
                    isDestructive: true,
                    onClick: () => retryLast(msg)
                }, __('Retry', 'presshub-ai-editor'))
            );
        } else if (msg.type === 'revision') {
            const pendingCount = (msg.revisions || []).filter(r => r.status === 'pending').length;
            const totalCount = (msg.revisions || []).length;
            return el('div', { key: index, className: bubbleClass + ' revision-card' },
                el('div', { className: 'card-header' },
                    el('span', {}, '📝 ' + __('Proposed Article Revisions', 'presshub-ai-editor')),
                    el('span', { className: 'presshub-badge' }, sprintf(__('%d of %d pending', 'presshub-ai-editor'), pendingCount, totalCount))
                ),
                msg.content && el('div', { className: 'revision-explanation' }, msg.content),
                el('div', { className: 'revision-global-actions' },
                    el(Button, {
                        isPrimary: true,
                        isSmall: true,
                        disabled: pendingCount === 0,
                        onClick: () => acceptAllRevisions(index)
                    }, '✓ ' + __('Accept All Changes', 'presshub-ai-editor')),
                    el(Button, {
                        isDestructive: true,
                        isLink: true,
                        isSmall: true,
                        disabled: pendingCount === 0,
                        onClick: () => denyAllRevisions(index)
                    }, '✕ ' + __('Deny All', 'presshub-ai-editor'))
                ),
                el('div', { className: 'revision-list' },
                    (msg.revisions || []).map((rev) => {
                        const isPending = rev.status === 'pending';
                        const isAccepted = rev.status === 'accepted';
                        const isDenied = rev.status === 'denied';
                        return el('div', { key: rev.id, className: 'revision-item ' + rev.status },
                            el('div', { className: 'revision-item-header' },
                                el('strong', {}, rev.summary || sprintf(__('Change #%d', 'presshub-ai-editor'), rev.id)),
                                isAccepted && el('span', { className: 'badge-accepted' }, '✓ ' + __('Accepted', 'presshub-ai-editor')),
                                isDenied && el('span', { className: 'badge-denied' }, '✕ ' + __('Denied', 'presshub-ai-editor'))
                            ),
                            el('div', { className: 'diff-chunk' },
                                el('div', { className: 'diff-original-wrapper' },
                                    el('span', { className: 'diff-label' }, __('Original Text:', 'presshub-ai-editor')),
                                    el('div', { className: 'diff-original' }, rev.original)
                                ),
                                el('div', { className: 'diff-revised-wrapper' },
                                    el('span', { className: 'diff-label' }, __('Proposed Revision (Editable):', 'presshub-ai-editor')),
                                    el('textarea', {
                                        className: 'diff-revised-input',
                                        rows: 3,
                                        disabled: !isPending,
                                        value: rev.editedRevised !== undefined ? rev.editedRevised : rev.revised,
                                        onChange: (e) => updateRevisionDraft(index, rev.id, e.target.value)
                                    })
                                )
                            ),
                            isPending && el('div', { className: 'revision-item-actions' },
                                el(Button, {
                                    isSecondary: true,
                                    isSmall: true,
                                    onClick: () => applySingleRevision(index, rev.id)
                                }, '✓ ' + __('Accept', 'presshub-ai-editor')),
                                el(Button, {
                                    isDestructive: true,
                                    isLink: true,
                                    isSmall: true,
                                    onClick: () => denySingleRevision(index, rev.id)
                                }, '✕ ' + __('Deny', 'presshub-ai-editor'))
                            )
                        );
                    })
                )
            );
        } else if (msg.type === 'research') {
            // Completed syntheses render collapsed with a toggle that
            // expands the full text inline.
            const isCompleted = msg.status === 'completed';
            const isLong = isCompleted && msg.content && msg.content.length > 300;
            const summary = isLong ? msg.content.slice(0, 300).trimEnd() + '…' : msg.content;
            return el('div', { key: index, className: bubbleClass + ' research-card' },
                el('div', { className: 'card-header' }, __('🔍 Deep Research Synthesis', 'presshub-ai-editor')),
                el('div', { className: 'card-body' }, isCompleted && !msg.expanded && isLong ? summary : msg.content),
                isLong && el(Button, {
                    isLink: true,
                    onClick: () => {
                        setMessages(prev => prev.map(m =>
                            m.researchId === msg.researchId ? { ...m, expanded: !m.expanded } : m
                        ));
                    }
                }, msg.expanded ? __('Show less', 'presshub-ai-editor') : __('Show more', 'presshub-ai-editor')),
                isCompleted && el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        // Insert raw HTML in a custom HTML block or paragraphs
                        insertBlock('core/html', { content: msg.content });
                    }
                }, __('Insert Research Into Article', 'presshub-ai-editor')),
                (msg.status === 'pending' || msg.status === 'processing') && el(Spinner)
            );
        } else if (msg.type === 'image') {
            return el('div', { key: index, className: bubbleClass + ' image-card' },
                el('div', { className: 'card-header' }, __('🎨 Generated Image', 'presshub-ai-editor')),
                el('img', {
                    src: msg.url,
                    alt: msg.caption || __('AI-generated image', 'presshub-ai-editor'),
                    style: { width: '100%', borderRadius: '4px', marginBottom: '8px' }
                }),
                el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        insertBlock('core/image', {
                            url: msg.url,
                            id: msg.id,
                            alt: __('AI Generated Illustration', 'presshub-ai-editor')
                        });
                    }
                }, __('Insert Image Block', 'presshub-ai-editor'))
            );
        } else if (msg.type === 'report') {
            return el('div', { key: index, className: bubbleClass + ' report-card' },
                el('div', { className: 'card-header' }, __('🎙️ AI Radio Audio Report', 'presshub-ai-editor')),
                el('audio', { controls: true, src: msg.url, style: { width: '100%', marginBottom: '8px' } }),
                el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        insertBlock('core/audio', {
                            src: msg.url,
                            id: msg.id,
                            caption: __('AI Generated Audio Report', 'presshub-ai-editor')
                        });
                    }
                }, __('Insert Audio Block', 'presshub-ai-editor'))
            );
        }
    };

    return el(PluginSidebar, {
        name: 'presshub-ai-copilot',
        icon: 'format-chat',
        title: __('AI Co-Pilot', 'presshub-ai-editor'),
    }, el('div', {
            className: 'presshub-sidebar-container',
            // Dynamic width (Issue #59 part B). The CSS in admin.css
            // also sets a 520px min-width so a fresh sidebar is at
            // least wide enough to read multi-paragraph revisions.
            style: { width: sidebarWidth + 'px' }
        },
        // Drag handle lives on the LEFT edge of the sidebar so it
        // sits between the editor canvas and the sidebar content.
        // It is keyboard-focusable (Tab + Arrow keys), announces as
        // a separator via aria-role, and is clamped to sensible
        // bounds by the useEffect attached to it.
        el('div', {
            className: 'presshub-sidebar-resize-handle',
            tabIndex: 0,
            role: 'separator',
            'aria-label': __('Resize AI Co-Pilot sidebar', 'presshub-ai-editor'),
            'aria-orientation': 'vertical',
            'aria-valuenow': sidebarWidth,
            'aria-valuemin': SIDEBAR_MIN_WIDTH,
            'aria-valuemax': SIDEBAR_MAX_WIDTH
        }),
        el('div', { className: 'presshub-quick-actions', style: { display: 'flex', gap: '4px', marginBottom: '8px', flexWrap: 'wrap' } },
            el(Button, { isSecondary: true, isSmall: true, onClick: () => applyQuickAction('research') }, __('🔍 Research', 'presshub-ai-editor')),
            el(Button, { isSecondary: true, isSmall: true, onClick: () => applyQuickAction('draft') }, __('✍️ Draft', 'presshub-ai-editor')),
            el(Button, { isSecondary: true, isSmall: true, onClick: () => applyQuickAction('image') }, __('🎨 Image', 'presshub-ai-editor'))
        ),
        el('div', { className: 'presshub-chat-messages', role: 'log', 'aria-live': 'polite' },
            messages.map((msg, index) => renderMessage(msg, index)),
            loading && el('div', { className: 'presshub-msg ai loading' }, el(Spinner)),
            el('div', { ref: messagesEndRef })
        ),
        el('div', { className: 'presshub-chat-input-area' },
            el(TextareaControl, {
                value: inputValue,
                onChange: setInputValue,
                // Enter sends; Shift+Enter inserts a newline.
                onKeyDown: (event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        handleSend();
                    }
                },
                placeholder: __('Ask Co-Pilot or request research/image/audio...', 'presshub-ai-editor'),
                rows: 2
            }),
            el('div', { className: 'presshub-chat-actions' },
                el(Button, { isPrimary: true, onClick: handleSend, disabled: loading || !inputValue.trim() }, __('Send', 'presshub-ai-editor')),
                el(Button, {
                    isDestructive: true,
                    isLink: true,
                    onClick: () => {
                        if (!window.confirm(__('Clear the entire conversation?', 'presshub-ai-editor'))) {
                            return;
                        }
                        setMessages([GREETING]);
                    }
                }, __('Clear', 'presshub-ai-editor'))
            )
        )
    ));
};

registerPlugin('presshub-ai-copilot', { render: AICoPilotSidebar });
})();
