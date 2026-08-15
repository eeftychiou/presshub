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
