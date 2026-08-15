import { createWordPressSyncClient, WordPressSyncOptions } from '../src/wordpress-sync';

function makeFetch(impl: Parameters<typeof createWordPressSyncClient>[0]['fetchImpl']) {
    return impl as unknown as typeof fetch;
}

function jsonResponse(body: unknown, init: { status?: number; statusText?: string } = {}) {
    return {
        ok: init.status === undefined ? true : init.status >= 200 && init.status < 300,
        status: init.status ?? 200,
        statusText: init.statusText ?? 'OK',
        json: async () => body,
        text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
    } as any;
}

describe('createWordPressSyncClient', () => {
    const baseOpts: WordPressSyncOptions = {
        baseUrl: 'https://wp.example.test',
        authToken: 'wp-app-password',
    };

    it('returns an object with fetchDrafts and updatePost methods', () => {
        const client = createWordPressSyncClient(baseOpts);
        expect(typeof client.fetchDrafts).toBe('function');
        expect(typeof client.updatePost).toBe('function');
    });

    it('throws when options is missing', () => {
        expect(() => createWordPressSyncClient(undefined as unknown as WordPressSyncOptions)).toThrow(/options/i);
    });

    it('throws when baseUrl is missing or empty', () => {
        expect(() => createWordPressSyncClient({ ...baseOpts, baseUrl: '' } as WordPressSyncOptions)).toThrow(/baseUrl/i);
        expect(() => createWordPressSyncClient({ authToken: 'x' } as unknown as WordPressSyncOptions)).toThrow(/baseUrl/i);
    });

    it('throws when authToken is missing or empty', () => {
        expect(() => createWordPressSyncClient({ ...baseOpts, authToken: '' } as WordPressSyncOptions)).toThrow(/authToken/i);
        expect(() => createWordPressSyncClient({ baseUrl: 'https://x' } as unknown as WordPressSyncOptions)).toThrow(/authToken/i);
    });

    describe('fetchDrafts', () => {
        it('GETs the WP REST endpoint with status=draft&per_page=100 and Bearer auth', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse([]));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });

            await client.fetchDrafts();

            expect(fetchImpl).toHaveBeenCalledTimes(1);
            const [calledUrl, calledInit] = fetchImpl.mock.calls[0];
            expect(calledUrl).toBe('https://wp.example.test/wp-json/wp/v2/posts?status=draft&per_page=100');
            expect(calledInit.method).toBe('GET');
            expect(calledInit.headers['Authorization']).toBe('Bearer wp-app-password');
            expect(calledInit.headers['Content-Type']).toBe('application/json');
        });

        it('trims trailing slash from baseUrl', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse([]));
            const client = createWordPressSyncClient({
                ...baseOpts,
                baseUrl: 'https://wp.example.test/',
                fetchImpl: makeFetch(fetchImpl),
            });
            await client.fetchDrafts();
            const [calledUrl] = fetchImpl.mock.calls[0];
            expect(calledUrl).toBe('https://wp.example.test/wp-json/wp/v2/posts?status=draft&per_page=100');
        });

        it('parses an array of draft objects with numeric ids', async () => {
            const drafts = [
                { id: 11, title: { rendered: 'Hello' }, status: 'draft' },
                { id: 12, title: { rendered: 'World' }, status: 'draft' },
            ];
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse(drafts));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });

            const result = await client.fetchDrafts();

            expect(result).toEqual([
                { id: 11, title: 'Hello', status: 'draft' },
                { id: 12, title: 'World', status: 'draft' },
            ]);
        });

        it('throws a descriptive error on non-OK response', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse('forbidden', { status: 403, statusText: 'Forbidden' }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.fetchDrafts()).rejects.toThrow(/403|Forbidden|forbidden/i);
        });

        it('throws a descriptive error when the body is non-JSON', async () => {
            const fetchImpl = jest.fn().mockResolvedValue({
                ok: true,
                status: 200,
                statusText: 'OK',
                json: async () => { throw new SyntaxError('Unexpected token <'); },
                text: async () => '<html>oops</html>',
            } as any);
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.fetchDrafts()).rejects.toThrow(/non-JSON|json|JSON/i);
        });

        it('throws when the response is not an array', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse({ message: 'not a list' }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.fetchDrafts()).rejects.toThrow(/array|list/i);
        });

        it('throws when a draft item is missing a numeric id', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse([
                { id: 1, title: 'ok', status: 'draft' },
                { title: 'no-id', status: 'draft' },
            ]));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.fetchDrafts()).rejects.toThrow(/id|numeric/i);
        });
    });

    describe('updatePost', () => {
        it('POSTs JSON to /posts/:id with Bearer auth and returns true on 200', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse({ id: 1, status: 'pending' }, { status: 200 }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });

            const ok = await client.updatePost(1, { status: 'pending' });

            expect(ok).toBe(true);
            const [calledUrl, calledInit] = fetchImpl.mock.calls[0];
            expect(calledUrl).toBe('https://wp.example.test/wp-json/wp/v2/posts/1');
            expect(calledInit.method).toBe('POST');
            expect(calledInit.headers['Authorization']).toBe('Bearer wp-app-password');
            expect(calledInit.headers['Content-Type']).toBe('application/json');
            expect(JSON.parse(calledInit.body)).toEqual({ status: 'pending' });
        });

        it('returns true on other 2xx responses (e.g. 201 Created)', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse({ id: 1 }, { status: 201 }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.updatePost(1, { status: 'pending' })).resolves.toBe(true);
        });

        it('throws a descriptive error on non-2xx response', async () => {
            const fetchImpl = jest.fn().mockResolvedValue(jsonResponse({ message: 'oops' }, { status: 500, statusText: 'Server Error' }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.updatePost(1, { status: 'pending' })).rejects.toThrow(/500|Server Error/i);
        });

        it('propagates network errors from fetchImpl', async () => {
            const boom = new Error('ECONNRESET');
            const fetchImpl = jest.fn().mockRejectedValue(boom);
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });
            await expect(client.updatePost(1, { status: 'pending' })).rejects.toBe(boom);
        });
    });

    describe('integration with processDrafts', () => {
        it('feeds drafts from createWordPressSyncClient(...).fetchDrafts through processDrafts', async () => {
            const drafts = [
                { id: 21, title: { rendered: 'first' }, status: 'draft' },
                { id: 22, title: { rendered: 'second' }, status: 'draft' },
            ];
            // First call returns drafts, subsequent calls (updatePost) return OK.
            const fetchImpl = jest.fn()
                .mockResolvedValueOnce(jsonResponse(drafts))
                .mockResolvedValue(jsonResponse({ id: 1 }, { status: 200 }));
            const client = createWordPressSyncClient({ ...baseOpts, fetchImpl: makeFetch(fetchImpl) });

            const { processDrafts } = await import('../src/sync');
            const processedCount = await processDrafts(client.fetchDrafts, client.updatePost);

            expect(processedCount).toBe(2);
            // First call: GET drafts. Next two calls: POST updates for ids 21 and 22.
            expect(fetchImpl).toHaveBeenCalledTimes(3);
            expect(fetchImpl.mock.calls[1][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts/21');
            expect(fetchImpl.mock.calls[2][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts/22');
            expect(JSON.parse(fetchImpl.mock.calls[1][1].body)).toEqual({ status: 'pending' });
            expect(JSON.parse(fetchImpl.mock.calls[2][1].body)).toEqual({ status: 'pending' });
        });
    });
});