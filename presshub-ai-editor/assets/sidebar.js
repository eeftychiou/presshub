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
    // research polling from the restored conversation.
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

        jQuery.post(presshubAI.ajax_url, {
            action: 'presshub_ai_chat',
            nonce: presshubAI.nonce,
            prompt: trimmed,
            post_id: currentPostId,
            instruction_preset_id: getSelectedPreset()
        }, (response) => {
            setLoading(false);
            if (response.success) {
                const data = response.data;
                if (data.type === 'chat') {
                    setMessages(prev => [...prev, { role: 'ai', type: 'text', content: data.content }]);
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
    }, el('div', { className: 'presshub-sidebar-container' },
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
