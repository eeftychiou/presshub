/**
 * PressHub AI — Gutenberg sidebar (block editor) plugin.
 *
 * Renders the AI Co-Pilot panel (chat, research polling, image and audio
 * report insertion). Strings are translatable via the wp.i18n runtime
 * that the PHP enqueue wires in.
 */
/* global wp, jQuery, presshubAI */
const { __, sprintf } = wp.i18n;

const { registerPlugin } = wp.plugins;
const { PluginSidebar } = wp.editPost;
const { el, useState, useEffect, useRef } = wp.element;
const { Button, TextareaControl, Spinner, PanelBody } = wp.components;

// Research status polling bounds: 40 attempts x 3s = 2 minutes max.
// Beyond that the job is stuck (or the site's wp-cron is starved) and we
// stop hammering the server, surfacing a 'timeout' status instead.
const RESEARCH_POLL_MAX_ATTEMPTS = 40;
const RESEARCH_POLL_INTERVAL_MS = 3000;

const AICoPilotSidebar = () => {
    const [messages, setMessages] = useState([
        { role: 'ai', type: 'text', content: __('Hello! I am your AI Co-Pilot. I can chat, conduct in-depth research, generate images, or summarize media. How can I help you draft your article today?', 'presshub-ai-editor') }
    ]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const messagesEndRef = useRef(null);
    // Active research-poll intervals, cleared on unmount so a closed
    // sidebar never keeps polling (or leaking timers) in the background.
    const researchIntervalsRef = useRef([]);

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

    const handleSend = () => {
        if (!inputValue.trim() || loading) return;

        const userMsg = { role: 'user', type: 'text', content: inputValue };
        setMessages(prev => [...prev, userMsg]);
        const promptText = inputValue;
        setInputValue('');
        setLoading(true);

        const currentPostId = wp.data.select('core/editor').getCurrentPostId();

        jQuery.post(presshubAI.ajax_url, {
            action: 'presshub_ai_chat',
            nonce: presshubAI.nonce,
            prompt: promptText,
            post_id: currentPostId
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
                        id: data.id
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
                    content: __('Error: ', 'presshub-ai-editor') + response.data
                }]);
            }
        }).fail(() => {
            setLoading(false);
            setMessages(prev => [...prev, {
                role: 'ai',
                type: 'text',
                content: __('Connection failed.', 'presshub-ai-editor')
            }]);
        });
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
            return el('div', { key: index, className: bubbleClass }, msg.content);
        } else if (msg.type === 'research') {
            return el('div', { key: index, className: bubbleClass + ' research-card' },
                el('div', { className: 'card-header' }, __('🔍 Deep Research Synthesis', 'presshub-ai-editor')),
                el('div', { className: 'card-body' }, msg.content),
                msg.status === 'completed' && el(Button, {
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
                el('img', { src: msg.url, style: { width: '100%', borderRadius: '4px', marginBottom: '8px' } }),
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
        el('div', { className: 'presshub-chat-messages' },
            messages.map((msg, index) => renderMessage(msg, index)),
            loading && el('div', { className: 'presshub-msg ai loading' }, el(Spinner)),
            el('div', { ref: messagesEndRef })
        ),
        el('div', { className: 'presshub-chat-input-area' },
            el(TextareaControl, {
                value: inputValue,
                onChange: setInputValue,
                placeholder: __('Ask Co-Pilot or request research/image/audio...', 'presshub-ai-editor'),
                rows: 2
            }),
            el('div', { className: 'presshub-chat-actions' },
                el(Button, { isPrimary: true, onClick: handleSend, disabled: loading || !inputValue.trim() }, __('Send', 'presshub-ai-editor')),
                el(Button, { isDestructive: true, isLink: true, onClick: () => setMessages([messages[0]]) }, __('Clear', 'presshub-ai-editor'))
            )
        )
    ));
};

registerPlugin('presshub-ai-copilot', { render: AICoPilotSidebar });
