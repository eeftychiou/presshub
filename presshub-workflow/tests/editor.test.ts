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
});
