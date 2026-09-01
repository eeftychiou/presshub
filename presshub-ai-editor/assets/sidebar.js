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

/** localStorage key for a post's preferred sidebar width; 'global' when no post. */
const sidebarWidthStorageKey = (postId) => 'presshub_ai_sidebar_width_' + (postId ? postId : 'global');

/** Sidebar resize bounds (Issue #59 part B). */
const SIDEBAR_WIDTH_MIN = 320;
const SIDEBAR_WIDTH_MAX = 960;
const SIDEBAR_WIDTH_DEFAULT = 520;

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
 * Escape literal `$` characters in a replacement string so that
 * `String.prototype.replace()` does not interpret `$&`, `$1`, `$$` etc.
 * as special tokens (Issue #59 revision-transfer bug).
 */
const escapeReplacementString = (s) => String(s == null ? '' : s).replace(/\$/g, '$$$$');

/**
 * Replace ALL occurrences of `needle` in `haystack` with `replacement`.
 * Implemented with `split().join()` so it:
 *   1. Replaces every match (not just the first).
 *   2. Has no `$`-special-token semantics — the caller is expected to
 *      have already pre-escaped `$` via escapeReplacementString().
 * Returning the haystack unchanged when needle is empty keeps this
 * helper safe for the "snippet not found" fallback path.
 */
const replaceAllSafe = (haystack, needle, replacement) => {
    if (haystack == null || needle == null || needle === '') {
        return haystack;
    }
    return String(haystack).split(needle).join(replacement);
};

/**
 * Block-level replacement fallback for applyReplacementInEditor().
 *
 * Scans the editor's blocks for the first block whose serialized HTML
 * (or, for blocks that store content under attributes.content, that
 * string directly) contains `originalText`, and replaces only the
 * matching occurrences inside THAT block — leaving every other block
 * untouched.
 *
 * The previous implementation used `wp.blocks.serialize([b])` followed
 * by `wp.blocks.parse(updatedHtml)`, which round-trips EVERY block
 * through serialization and silently corrupts nested block markup
 * (image captions, list-item nesting, inner blocks). The new helper
 * mutates only the targeted block's content, then asks the editor to
 * `resetBlocks([newBlocks])` with the unchanged siblings preserved.
 *
 * Returns true if a replacement was made, false otherwise.
 */
const applyBlockLevelReplacement = (originalText, revisedText) => {
    if (!wp.data || !wp.data.select('core/editor') || !wp.data.dispatch('core/editor')) {
        return false;
    }
    const blocks = wp.data.select('core/editor').getBlocks() || [];
    if (!originalText || blocks.length === 0) {
        return false;
    }
    const safeRevised = escapeReplacementString(revisedText);

    let replaced = false;
    const newBlocks = blocks.map((b) => {
        if (replaced) {
            return b;
        }
        // Prefer direct attribute mutation (lossless). For classic,
        // freeform, html, and shortcode blocks the editable payload
        // lives in attributes.content / attributes.text / innerBlocks.
        if (b && b.attributes && typeof b.attributes.content === 'string' && b.attributes.content.indexOf(originalText) !== -1) {
            replaced = true;
            return {
                ...b,
                attributes: {
                    ...b.attributes,
                    content: replaceAllSafe(b.attributes.content, originalText, safeRevised)
                }
            };
        }
        if (b && b.attributes && typeof b.attributes.text === 'string' && b.attributes.text.indexOf(originalText) !== -1) {
            replaced = true;
            return {
                ...b,
                attributes: {
                    ...b.attributes,
                    text: replaceAllSafe(b.attributes.text, originalText, safeRevised)
                }
            };
        }
        return b;
    });

    if (replaced) {
        wp.data.dispatch('core/editor').resetBlocks(newBlocks);
    }
    return replaced;
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
    // Ref to the sidebar container — the resize handle's drag listeners
    // resize this element (Issue #59 part B).
    const sidebarContainerRef = useRef(null);
    // Drag state captured in a ref so the move/up listeners (attached
    // on document for the duration of the drag) don't go stale across
    // re-renders.
    const dragStateRef = useRef(null);

    // Current sidebar width. Loaded from localStorage on mount /
    // post-switch; falls back to SIDEBAR_WIDTH_DEFAULT.
    const [sidebarWidth, setSidebarWidth] = useState(() => {
        try {
            const raw = window.localStorage.getItem(sidebarWidthStorageKey(getCurrentPostId()));
            if (!raw) return SIDEBAR_WIDTH_DEFAULT;
            const n = parseInt(raw, 10);
            if (!Number.isFinite(n) || n < SIDEBAR_WIDTH_MIN || n > SIDEBAR_WIDTH_MAX) {
                return SIDEBAR_WIDTH_DEFAULT;
            }
            return n;
        } catch (e) {
            return SIDEBAR_WIDTH_DEFAULT;
        }
    });
    // Active research-poll intervals, cleared on unmount so a closed
    // sidebar never keeps polling (or leaking timers) in the background.
    const researchIntervalsRef = useRef([]);
    // Last user prompt sent — powers the error-card Retry button.
    const lastUserPromptRef = useRef('');
    // Skips the post-switch effect's first run (mount is handled by the
    // lazy useState initializer above).
    const didMountRef = useRef(false);

    // Persist the chosen width when it changes (per-post key).
    useEffect(() => {
        try {
            window.localStorage.setItem(sidebarWidthStorageKey(getCurrentPostId()), String(sidebarWidth));
        } catch (e) { /* Storage unavailable — the next reload falls back to the default. */ }
    }, [sidebarWidth]);

    // Attach mousedown / keydown listeners to the drag handle. The
    // handle lives inside the sidebar container rendered below; we
    // find it after the first render with a small effect that targets
    // its stable className so the listeners are cleaned up on unmount.
    useEffect(() => {
        const container = sidebarContainerRef.current;
        if (!container) return undefined;
        const handle = container.querySelector('.presshub-sidebar-resize-handle');
        if (!handle) return undefined;

        const clamp = (n) => Math.min(SIDEBAR_WIDTH_MAX, Math.max(SIDEBAR_WIDTH_MIN, n));
        const applyWidth = (n) => setSidebarWidth(clamp(n));

        const onMouseMove = (e) => {
            const state = dragStateRef.current;
            if (!state) return;
            const dx = e.clientX - state.startX;
            applyWidth(state.startWidth + dx);
        };
        const onMouseUp = () => {
            dragStateRef.current = null;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };
        const onMouseDown = (e) => {
            if (e.button !== 0) return;
            const containerRect = container.getBoundingClientRect();
            dragStateRef.current = {
                startX: e.clientX,
                startWidth: containerRect.width
            };
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            document.body.style.cursor = 'ew-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        };
        const onKeyDown = (e) => {
            // Arrow keys resize by 16px, Shift+Arrow by 64px, Home/End
            // jump to the bounds. Keyboard-accessible per WAI-ARIA
            // "separator" pattern.
            const cur = sidebarContainerRef.current
                ? sidebarContainerRef.current.getBoundingClientRect().width
                : sidebarWidth;
            let next = cur;
            const step = e.shiftKey ? 64 : 16;
            if (e.key === 'ArrowLeft') next = cur - step;
            else if (e.key === 'ArrowRight') next = cur + step;
            else if (e.key === 'Home') next = SIDEBAR_WIDTH_MIN;
            else if (e.key === 'End') next = SIDEBAR_WIDTH_MAX;
            else return;
            e.preventDefault();
            applyWidth(next);
        };

        handle.addEventListener('mousedown', onMouseDown);
        handle.addEventListener('keydown', onKeyDown);
        return () => {
            handle.removeEventListener('mousedown', onMouseDown);
            handle.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
    // stored history (or a fresh greeting), resume any in-flight
    // research polling from the restored conversation, and reload the
    // saved sidebar width for the new post.
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
        // Reload the saved width for the new post (or the default).
        try {
            const raw = window.localStorage.getItem(sidebarWidthStorageKey(currentPostId));
            const n = raw ? parseInt(raw, 10) : NaN;
            setSidebarWidth(
                Number.isFinite(n) && n >= SIDEBAR_WIDTH_MIN && n <= SIDEBAR_WIDTH_MAX
                    ? n
                    : SIDEBAR_WIDTH_DEFAULT
            );
        } catch (e) {
            setSidebarWidth(SIDEBAR_WIDTH_DEFAULT);
        }
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

    /**
     * Transfer a single LLM-proposed revision into the Gutenberg editor.
     *
     * Issues fixed (Issue #59):
     *   1. Replaces ALL matches, not just the first (split().join()).
     *   2. Survives literal `$` characters in the revised text
     *      (escapeReplacementString pre-escapes `$$` -> `$$$$`).
     *   3. Falls back to a block-level replacement that ONLY mutates
     *      the matching block's `attributes.content` / `attributes.text`
     *      (applyBlockLevelReplacement) — no more wp.blocks.serialize
     *      round-trip that corrupts nested block markup.
     *   4. If neither path can locate the snippet, surfaces an in-chat
     *      error card (instead of silently appending `revisedText` to
     *      the article and corrupting it further).
     *
     * Returns true if a replacement was made, false otherwise. The
     * single-revision and accept-all callers use this to gate the
     * "Accepted" badge flip.
     */
    const applyReplacementInEditor = (originalText, revisedText) => {
        try {
            if (!wp.data || !wp.data.select('core/editor') || !wp.data.dispatch('core/editor')) {
                return false;
            }
            const currentContent = wp.data.select('core/editor').getEditedPostContent() || '';
            const safeRevised = escapeReplacementString(revisedText);

            // Primary path: serialized post content contains the
            // substring verbatim. replaceAllSafe replaces ALL matches
            // and never interprets `$` tokens in `safeRevised`.
            if (originalText && currentContent && currentContent.indexOf(originalText) !== -1) {
                const updated = replaceAllSafe(currentContent, originalText, safeRevised);
                wp.data.dispatch('core/editor').editPost({ content: updated });
                // Verify the replacement actually landed before we
                // claim success — guards against silent no-ops when
                // currentContent changes between the includes() check
                // and the editPost() dispatch.
                const after = wp.data.select('core/editor').getEditedPostContent() || '';
                if (after.indexOf(revisedText) !== -1) {
                    return true;
                }
            }

            // Fallback: walk the block tree, mutate only the matching
            // block's content. Returns true on success.
            if (applyBlockLevelReplacement(originalText, revisedText)) {
                const after = wp.data.select('core/editor').getEditedPostContent() || '';
                if (after.indexOf(revisedText) !== -1) {
                    return true;
                }
            }

            // Could not locate the snippet — append a localized error
            // message to the chat instead of silently appending
            // `revisedText` to the article (which was corrupting
            // unrelated content).
            const errMsg = sprintf(
                /* translators: %s: the original text snippet that could not be located in the article. */
                __('Could not locate the original text in the article: "%s". The article may have been edited since this revision was proposed. Re-run the prompt against the current article.', 'presshub-ai-editor'),
                String(originalText || '').slice(0, 120)
            );
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                isError: true,
                content: errMsg
            }]);
            return false;
        } catch (err) {
            console.error('PressHub AI: Error applying revision', err);
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                isError: true,
                content: __('PressHub AI: Error applying revision. See browser console for details.', 'presshub-ai-editor')
            }]);
            return false;
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
        const ok = applyReplacementInEditor(rev.original, revisedText);

        // Only flip the badge to 'accepted' when the editor confirmed
        // the revised text actually landed in the post. A failed
        // transfer (snippet not found, dispatch error) leaves the
        // revision 'pending' and surfaces an in-chat error card.
        if (ok) {
            setMessages(prev => prev.map((m, i) => {
                if (i !== msgIndex || !m.revisions) return m;
                return {
                    ...m,
                    revisions: m.revisions.map(r => r.id === revId ? { ...r, status: 'accepted' } : r)
                };
            }));
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

    const acceptAllRevisions = (msgIndex) => {
        const msg = messages[msgIndex];
        if (!msg || !msg.revisions) return;

        // Iterate sequentially with a functional update so each
        // replacement re-reads the CURRENT post content. This keeps
        // the operation idempotent — a prior accepted revision that
        // shifted line indexes no longer breaks the next one. Each
        // call still goes through applyReplacementInEditor's
        // split().join() path which is order-independent anyway, but
        // the sequential read prevents stale-snapshot bugs when the
        // editor dispatches its own state updates between calls.
        const pending = msg.revisions.filter(r => r.status === 'pending');
        const succeeded = new Set();
        pending.forEach(rev => {
            const revisedText = rev.editedRevised !== undefined ? rev.editedRevised : rev.revised;
            const ok = applyReplacementInEditor(rev.original, revisedText);
            if (ok) {
                succeeded.add(rev.id);
            }
        });

        setMessages(prev => prev.map((m, i) => {
            if (i !== msgIndex || !m.revisions) return m;
            return {
                ...m,
                revisions: m.revisions.map(r => succeeded.has(r.id) ? { ...r, status: 'accepted' } : r)
            };
        }));
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
        ref: sidebarContainerRef,
        className: 'presshub-sidebar-container',
        // Inline width so the persisted preference always wins over
        // the CSS default. min/max clamp to the bounds in CSS as well.
        style: { width: sidebarWidth + 'px' }
    },
        // Resize handle on the inner (left) edge. The drag/keyboard
        // listeners are attached by the effect above, which targets
        // this element by its stable className.
        el('div', {
            className: 'presshub-sidebar-resize-handle',
            tabIndex: 0,
            role: 'separator',
            'aria-orientation': 'vertical',
            'aria-label': __('Resize AI Co-Pilot sidebar', 'presshub-ai-editor'),
            'aria-valuemin': SIDEBAR_WIDTH_MIN,
            'aria-valuemax': SIDEBAR_WIDTH_MAX,
            'aria-valuenow': sidebarWidth
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
