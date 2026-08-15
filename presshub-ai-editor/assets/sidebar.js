const { registerPlugin } = wp.plugins;
const { PluginSidebar } = wp.editPost;
const { el, useState, useEffect, useRef } = wp.element;
const { Button, TextareaControl, Spinner, PanelBody } = wp.components;

const AICoPilotSidebar = () => {
    const [messages, setMessages] = useState([
        { role: 'ai', type: 'text', content: 'Hello! I am your AI Co-Pilot. I can chat, conduct in-depth research, generate images, or summarize media. How can I help you draft your article today?' }
    ]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const messagesEndRef = useRef(null);

    const scrollToBottom = () => {
        if (messagesEndRef.current) {
            messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    };

    useEffect(() => {
        scrollToBottom();
    }, [messages]);

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
                        content: 'Initiating deep research task... Please wait.'
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
                setMessages(prev => [...prev, { role: 'ai', type: 'text', content: 'Error: ' + response.data }]);
            }
        }).fail(() => {
            setLoading(false);
            setMessages(prev => [...prev, { role: 'ai', type: 'text', content: 'Connection failed.' }]);
        });
    };

    const startPollingResearch = (researchId) => {
        const interval = setInterval(() => {
            jQuery.post(presshubAI.ajax_url, {
                action: 'presshub_ai_check_research',
                nonce: presshubAI.nonce,
                research_id: researchId
            }, (response) => {
                if (response.success) {
                    const status = response.data.status;
                    if (status === 'completed') {
                        clearInterval(interval);
                        setMessages(prev => prev.map(msg => 
                            msg.researchId === researchId 
                                ? { ...msg, status: 'completed', content: response.data.content } 
                                : msg
                        ));
                    } else if (status === 'failed') {
                        clearInterval(interval);
                        setMessages(prev => prev.map(msg => 
                            msg.researchId === researchId 
                                ? { ...msg, status: 'failed', content: 'Research failed: ' + response.data.error } 
                                : msg
                        ));
                    } else {
                        setMessages(prev => prev.map(msg => 
                            msg.researchId === researchId 
                                ? { ...msg, status: status, content: 'Status: ' + status + '...' } 
                                : msg
                        ));
                    }
                } else {
                    clearInterval(interval);
                    setMessages(prev => prev.map(msg => 
                        msg.researchId === researchId 
                            ? { ...msg, status: 'failed', content: 'Polling error: ' + (response.data || 'Failed') } 
                            : msg
                    ));
                }
            }).fail(() => {
                clearInterval(interval);
                setMessages(prev => prev.map(msg => 
                    msg.researchId === researchId 
                        ? { ...msg, status: 'failed', content: 'Network polling error.' } 
                        : msg
                ));
            });
        }, 3000);
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
                el('div', { className: 'card-header' }, '🔍 Deep Research Synthesis'),
                el('div', { className: 'card-body' }, msg.content),
                msg.status === 'completed' && el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        // Insert raw HTML in a custom HTML block or paragraphs
                        insertBlock('core/html', { content: msg.content });
                    }
                }, 'Insert Research Into Article'),
                (msg.status === 'pending' || msg.status === 'processing') && el(Spinner)
            );
        } else if (msg.type === 'image') {
            return el('div', { key: index, className: bubbleClass + ' image-card' },
                el('div', { className: 'card-header' }, '🎨 Generated Image'),
                el('img', { src: msg.url, style: { width: '100%', borderRadius: '4px', marginBottom: '8px' } }),
                el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        insertBlock('core/image', { url: msg.url, id: msg.id, alt: 'AI Generated Illustration' });
                    }
                }, 'Insert Image Block')
            );
        } else if (msg.type === 'report') {
            return el('div', { key: index, className: bubbleClass + ' report-card' },
                el('div', { className: 'card-header' }, '🎙️ AI Radio Audio Report'),
                el('audio', { controls: true, src: msg.url, style: { width: '100%', marginBottom: '8px' } }),
                el(Button, {
                    isPrimary: true,
                    onClick: () => {
                        insertBlock('core/audio', { src: msg.url, id: msg.id, caption: 'AI Generated Audio Report' });
                    }
                }, 'Insert Audio Block')
            );
        }
    };

    return el(PluginSidebar, {
        name: 'presshub-ai-copilot',
        icon: 'format-chat',
        title: 'AI Co-Pilot',
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
                placeholder: 'Ask Co-Pilot or request research/image/audio...',
                rows: 2
            }),
            el('div', { className: 'presshub-chat-actions' },
                el(Button, { isPrimary: true, onClick: handleSend, disabled: loading || !inputValue.trim() }, 'Send'),
                el(Button, { isDestructive: true, isLink: true, onClick: () => setMessages([messages[0]]) }, 'Clear')
            )
        )
    ));
};

registerPlugin('presshub-ai-copilot', { render: AICoPilotSidebar });
