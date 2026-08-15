import { createMcpScorecardEvaluator } from '../src/mcp-scorecard';

describe('createMcpScorecardEvaluator', () => {
    it('returns a function', () => {
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
        });
        expect(typeof evaluator).toBe('function');
    });

    it('POSTs the content as JSON to the configured endpoint with an Authorization header', async () => {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ score: 77, feedback: 'ship it' }),
        });

        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'secret-key',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        const result = await evaluator('draft body');

        expect(fetchImpl).toHaveBeenCalledTimes(1);
        const [calledUrl, calledInit] = fetchImpl.mock.calls[0];
        expect(calledUrl).toBe('https://example.test/scorecard');
        expect(calledInit.method).toBe('POST');
        expect(calledInit.headers['Authorization']).toBe('Bearer secret-key');
        expect(calledInit.headers['Content-Type']).toBe('application/json');
        expect(JSON.parse(calledInit.body)).toEqual({ content: 'draft body' });

        expect(result).toEqual({ score: 77, feedback: 'ship it' });
    });

    it('throws when the endpoint is missing', () => {
        expect(() => createMcpScorecardEvaluator({ apiKey: 'k' } as any)).toThrow(/endpoint/i);
    });

    it('throws when the apiKey is missing', () => {
        expect(() => createMcpScorecardEvaluator({ endpoint: 'https://x' } as any)).toThrow(/apiKey/i);
    });

    it('propagates non-2xx HTTP responses as a clear error', async () => {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: false,
            status: 502,
            statusText: 'Bad Gateway',
            text: async () => 'upstream down',
        });
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });
        await expect(evaluator('content')).rejects.toThrow(/502|Bad Gateway|upstream/i);
    });

    it('throws when the response body is malformed (missing score)', async () => {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ feedback: 'no score here' }),
        });
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });
        await expect(evaluator('content')).rejects.toThrow(/score/i);
    });

    it('throws when the response body is malformed (non-numeric score)', async () => {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ score: 'high', feedback: 'f' }),
        });
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });
        await expect(evaluator('content')).rejects.toThrow(/score/i);
    });

    it('propagates fetch network errors', async () => {
        const boom = new Error('ECONNREFUSED');
        const fetchImpl = jest.fn().mockRejectedValue(boom);
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });
        await expect(evaluator('content')).rejects.toBe(boom);
    });

    it('integrates with generateScorecard: the injected MCP evaluator drives the scorecard', async () => {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ score: 95, feedback: 'AI: excellent draft' }),
        });
        const evaluator = createMcpScorecardEvaluator({
            endpoint: 'https://example.test/scorecard',
            apiKey: 'k',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        // Re-import to keep this test self-contained against editor.ts
        const { generateScorecard } = await import('../src/editor');
        const result = await generateScorecard('article body', { evaluator });
        expect(result).toEqual({ score: 95, feedback: 'AI: excellent draft' });
    });
});
