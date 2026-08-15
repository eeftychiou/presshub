import { generateScorecard, classifyIntent } from '../src/editor';

describe('AI Editor', () => {
    it('returns a scorecard with a score and feedback', async () => {
        const result = await generateScorecard('This is a test draft article.');
        expect(result).toHaveProperty('score');
        expect(result).toHaveProperty('feedback');
        expect(typeof result.score).toBe('number');
    });

    it('classifies general conversations as chat', async () => {
        const intent = await classifyIntent('Hello, how are you?');
        expect(intent).toBe('chat');
    });

    it('classifies image generation requests as image', async () => {
        const intent = await classifyIntent('Generate a beautiful picture of a city');
        expect(intent).toBe('image');
    });

    it('classifies research requests as research', async () => {
        const intent = await classifyIntent('Perform a deep investigation on solar energy trends');
        expect(intent).toBe('research');
    });

    it('classifies report synthesis as report', async () => {
        const intent = await classifyIntent('Summarize this video audio track into a report');
        expect(intent).toBe('report');
    });

    it('rejects empty content with a clear error', async () => {
        await expect(generateScorecard('')).rejects.toThrow(/empty|whitespace/i);
    });

    it('rejects whitespace-only content with a clear error', async () => {
        await expect(generateScorecard('   \n\t  ')).rejects.toThrow(/empty|whitespace/i);
    });

    it('accepts an injected evaluator and uses its return value', async () => {
        const injected = jest.fn().mockResolvedValue({ score: 91, feedback: 'injected feedback' });
        const result = await generateScorecard('Some draft content', { evaluator: injected });
        expect(injected).toHaveBeenCalledTimes(1);
        expect(injected).toHaveBeenCalledWith('Some draft content');
        expect(result).toEqual({ score: 91, feedback: 'injected feedback' });
    });

    it('still validates empty content when an evaluator is injected', async () => {
        const injected = jest.fn().mockResolvedValue({ score: 50, feedback: 'should not run' });
        await expect(generateScorecard('', { evaluator: injected })).rejects.toThrow(/empty|whitespace/i);
        expect(injected).not.toHaveBeenCalled();
    });

    it('throws a clear error when the injected evaluator returns malformed output', async () => {
        const injected = jest.fn().mockResolvedValue({ feedback: 'missing score' });
        await expect(generateScorecard('Some draft content', { evaluator: injected }))
            .rejects.toThrow(/score/i);
    });

    it('throws a clear error when the injected evaluator returns a non-numeric score', async () => {
        const injected = jest.fn().mockResolvedValue({ score: 'high', feedback: 'looks good' });
        await expect(generateScorecard('Some draft content', { evaluator: injected }))
            .rejects.toThrow(/score/i);
    });

    it('throws a clear error when the injected evaluator returns a non-string feedback', async () => {
        const injected = jest.fn().mockResolvedValue({ score: 80, feedback: 42 });
        await expect(generateScorecard('Some draft content', { evaluator: injected }))
            .rejects.toThrow(/feedback/i);
    });

    it('propagates errors thrown by the injected evaluator', async () => {
        const boom = new Error('evaluator_boom');
        const injected = jest.fn().mockRejectedValue(boom);
        await expect(generateScorecard('Some draft content', { evaluator: injected }))
            .rejects.toBe(boom);
    });
});

describe('classifyIntent (PHP-aligned)', () => {
    it('classifies normal Q&A as chat', async () => {
        expect(await classifyIntent('Hello, how are you?')).toBe('chat');
    });

    it('classifies image-generation keywords as image', async () => {
        expect(await classifyIntent('Generate a beautiful picture of a city')).toBe('image');
        expect(await classifyIntent('Draw a cat')).toBe('image');
        expect(await classifyIntent('Paint a sunset')).toBe('image');
        expect(await classifyIntent('Design an illustration for the cover')).toBe('image');
        expect(await classifyIntent('Create an image of a mountain')).toBe('image');
    });

    it('classifies research keywords as research', async () => {
        expect(await classifyIntent('Perform a deep investigation on solar energy trends')).toBe('research');
        expect(await classifyIntent('Research the impact of AI on journalism')).toBe('research');
        expect(await classifyIntent('Synthesize findings on climate change')).toBe('research');
        expect(await classifyIntent('Analysis of Q3 earnings')).toBe('research');
    });

    it('classifies audio/video report keywords as report', async () => {
        expect(await classifyIntent('Summarize this video audio track into a report')).toBe('report');
        expect(await classifyIntent('Voice over this article')).toBe('report');
        expect(await classifyIntent('Translate this audio file')).toBe('report');
        expect(await classifyIntent('Create a narrated report from this podcast')).toBe('report');
    });

    it('defaults to chat for unknown input', async () => {
        expect(await classifyIntent('')).toBe('chat');
        expect(await classifyIntent('something completely unrelated to anything')).toBe('chat');
    });
});

describe('classifyIntent edge cases', () => {
    it('treats summarize without an audio/video keyword as chat (not report)', async () => {
        expect(await classifyIntent('Summarize the meeting document')).toBe('chat');
        expect(await classifyIntent('Please summarize this article')).toBe('chat');
    });

    it('treats translate without an audio/video keyword as chat (not report)', async () => {
        expect(await classifyIntent('Translate this paragraph to Spanish')).toBe('chat');
        expect(await classifyIntent('Translate the press release')).toBe('chat');
    });

    it('treats generate/make without an image-noun as chat (not image)', async () => {
        expect(await classifyIntent('Generate a report about sales')).toBe('chat');
        expect(await classifyIntent('Make me a summary')).toBe('chat');
        expect(await classifyIntent('Render a table')).toBe('chat');
    });

    it('still classifies as image when both create+image-noun appear alongside other verbs', async () => {
        expect(await classifyIntent('Please create an image of a dog')).toBe('image');
        expect(await classifyIntent('Design a graphic for the launch')).toBe('image');
        expect(await classifyIntent('Make a picture of the moon')).toBe('image');
    });

    it('matches summarize + audio/video/podcast/track in either order', async () => {
        expect(await classifyIntent('Summarize this audio')).toBe('report');
        expect(await classifyIntent('Audio file: summarize please')).toBe('report');
        expect(await classifyIntent('Translate the podcast')).toBe('report');
        expect(await classifyIntent('A video I want you to translate')).toBe('report');
    });

    it('matches the narrate verb forms', async () => {
        expect(await classifyIntent('Narrate the article')).toBe('report');
        expect(await classifyIntent('A narrated version of this post')).toBe('report');
        expect(await classifyIntent('Add narration please')).toBe('report');
    });

    it('matches "deep dive" / "deep analysis" / "deep investigation"', async () => {
        expect(await classifyIntent('Do a deep dive on the data')).toBe('research');
        expect(await classifyIntent('Provide a deep analysis of the market')).toBe('research');
        expect(await classifyIntent('I need a deep investigation into this leak')).toBe('research');
    });

    it('matches synthesis / synthesize / analysis / investigate / investigation forms', async () => {
        expect(await classifyIntent('Synthesize these findings')).toBe('research');
        expect(await classifyIntent('Synthesis of recent studies')).toBe('research');
        expect(await classifyIntent('Investigate the cause')).toBe('research');
        expect(await classifyIntent('Investigation of supply chains')).toBe('research');
        expect(await classifyIntent('Analyze the trend')).toBe('research');
    });

    it('is case-insensitive and whitespace-tolerant', async () => {
        expect(await classifyIntent('RESEARCH the topic')).toBe('research');
        expect(await classifyIntent('  Generate   an   image  ')).toBe('image');
        expect(await classifyIntent('\tSummarize\tthis\tvideo\n')).toBe('report');
    });

    it('handles surrounding punctuation without losing classification', async () => {
        expect(await classifyIntent('Research? Please.')).toBe('research');
        expect(await classifyIntent('"Draw a fox," she said.')).toBe('image');
        expect(await classifyIntent('(Voice over)')).toBe('report');
    });

    it('respects precedence when prompts mix intents (image wins over research)', async () => {
        // Aligned with PHP comment: image-verbs imply image intent even without image-noun.
        expect(await classifyIntent('Create an image and research the topic')).toBe('image');
    });

    it('respects precedence (research wins over report)', async () => {
        // research keyword present, but no audio/video keyword to trigger report
        expect(await classifyIntent('Investigate the podcast industry')).toBe('research');
    });

    it('returns exactly one of chat|research|image|report for varied inputs', async () => {
        const samples = [
            'Hello',
            'Draw a cat',
            'Research this',
            'Summarize the video',
            '',
            '   ',
            'Generate a graphic',
            'Please translate',
        ];
        const allowed = new Set(['chat', 'research', 'image', 'report']);
        for (const s of samples) {
            const intent = await classifyIntent(s);
            expect(allowed.has(intent)).toBe(true);
        }
    });
});
