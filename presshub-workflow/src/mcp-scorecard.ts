import type { Scorecard, ScorecardEvaluator } from './editor';

export interface McpScorecardEvaluatorOptions {
    /** URL of the MCP model-router scorecard endpoint. */
    endpoint: string;
    /** API key sent as `Authorization: Bearer <apiKey>`. */
    apiKey: string;
    /** Injected fetch implementation (defaults to global fetch). */
    fetchImpl?: typeof fetch;
}

/**
 * Factory that returns a `ScorecardEvaluator` backed by an HTTP MCP-style
 * model-router endpoint. The endpoint is expected to accept JSON
 * `{ content: string }` and respond with JSON `{ score: number, feedback: string }`.
 */
export function createMcpScorecardEvaluator(options: McpScorecardEvaluatorOptions): ScorecardEvaluator {
    if (!options || typeof options !== 'object') {
        throw new Error('createMcpScorecardEvaluator: options object is required');
    }
    if (typeof options.endpoint !== 'string' || options.endpoint.trim() === '') {
        throw new Error('createMcpScorecardEvaluator: options.endpoint must be a non-empty string');
    }
    if (typeof options.apiKey !== 'string' || options.apiKey === '') {
        throw new Error('createMcpScorecardEvaluator: options.apiKey must be a non-empty string');
    }
    const fetchImpl: typeof fetch = options.fetchImpl ?? (typeof fetch !== 'undefined' ? fetch : (() => {
        throw new Error('createMcpScorecardEvaluator: no fetch implementation available');
    }) as unknown as typeof fetch);

    return async function evaluateViaMcp(content: string): Promise<Scorecard> {
        const response = await fetchImpl(options.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${options.apiKey}`,
            },
            body: JSON.stringify({ content }),
        });

        if (!response.ok) {
            let detail = '';
            try {
                detail = await response.text();
            } catch {
                // ignore
            }
            throw new Error(
                `createMcpScorecardEvaluator: MCP scorecard endpoint returned ${response.status} ${response.statusText}${detail ? `: ${detail}` : ''}`
            );
        }

        let body: unknown;
        try {
            body = await response.json();
        } catch (err) {
            throw new Error('createMcpScorecardEvaluator: MCP scorecard endpoint returned non-JSON body');
        }

        if (!body || typeof body !== 'object') {
            throw new Error('createMcpScorecardEvaluator: MCP scorecard endpoint returned non-object body');
        }
        const candidate = body as Record<string, unknown>;
        if (typeof candidate.score !== 'number') {
            throw new Error('createMcpScorecardEvaluator: MCP response missing numeric score field');
        }
        if (typeof candidate.feedback !== 'string') {
            throw new Error('createMcpScorecardEvaluator: MCP response missing string feedback field');
        }
        return { score: candidate.score, feedback: candidate.feedback };
    };
}
