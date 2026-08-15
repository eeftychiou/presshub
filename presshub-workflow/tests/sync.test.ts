import { processDrafts, processDraftsWithClient } from '../src/sync';
import { createWordPressSyncClient } from '../src/wordpress-sync';

describe('WordPress Sync', () => {
    it('processes a draft post and returns the updated post data', async () => {
        // Mocking the MCP WP fetch
        const mockDrafts = [{ id: 1, content: { raw: 'Test content' } }];
        const fetchDrafts = jest.fn().mockResolvedValue(mockDrafts);
        const updatePost = jest.fn().mockResolvedValue(true);

        const processedCount = await processDrafts(fetchDrafts, updatePost);
        expect(processedCount).toBe(1);
        expect(updatePost).toHaveBeenCalled();
    });

    it('rejects when fetchDrafts is not a function', async () => {
        await expect(
            processDrafts(null as unknown as () => Promise<any[]>, jest.fn())
        ).rejects.toThrow(/fetchDrafts must be a function/);
    });

    it('rejects when updatePost is not a function', async () => {
        await expect(
            processDrafts(jest.fn().mockResolvedValue([]), null as unknown as (id: number, data: any) => Promise<boolean>)
        ).rejects.toThrow(/updatePost must be a function/);
    });

    it('propagates errors thrown by fetchDrafts', async () => {
        const boom = new Error('wp_fetch_failed');
        const fetchDrafts = jest.fn().mockRejectedValue(boom);
        await expect(processDrafts(fetchDrafts, jest.fn())).rejects.toBe(boom);
    });

    it('propagates errors thrown by updatePost', async () => {
        const boom = new Error('wp_update_failed');
        const fetchDrafts = jest.fn().mockResolvedValue([{ id: 1 }]);
        const updatePost = jest.fn().mockRejectedValue(boom);
        await expect(processDrafts(fetchDrafts, updatePost)).rejects.toBe(boom);
    });

    it('stops processing when updatePost fails partway through', async () => {
        const fetchDrafts = jest.fn().mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }]);
        const updatePost = jest.fn()
            .mockResolvedValueOnce(true)
            .mockRejectedValueOnce(new Error('update_failed'));
        await expect(processDrafts(fetchDrafts, updatePost)).rejects.toThrow('update_failed');
        expect(updatePost).toHaveBeenCalledTimes(2);
    });

    it('processDraftsWithClient wires a WordPressSyncClient into processDrafts', async () => {
        const drafts = [{ id: 1, title: 'A', status: 'draft' }, { id: 2, title: 'B', status: 'draft' }];
        const fetchImpl = jest.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => drafts } as any)
            .mockResolvedValue({ ok: true, status: 200, json: async () => ({ id: 1 }) } as any);
        const client = createWordPressSyncClient({
            baseUrl: 'https://wp.example.test',
            authToken: 'token',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        const count = await processDraftsWithClient(client);

        expect(count).toBe(2);
        // First call: fetchDrafts. Next two: updatePost(id=1), updatePost(id=2).
        expect(fetchImpl).toHaveBeenCalledTimes(3);
        expect(fetchImpl.mock.calls[1][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts/1');
        expect(fetchImpl.mock.calls[2][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts/2');
    });

    it('processDraftsWithClient builds a fresh client when options are passed', async () => {
        const fetchImpl = jest.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => [{ id: 99 }] } as any)
            .mockResolvedValue({ ok: true, status: 200, json: async () => ({ id: 99 }) } as any);
        // Pass a placeholder client (should be ignored when options provided) plus
        // a different fetchImpl in options to confirm the convenience fn uses the
        // options-driven client, not the placeholder.
        const placeholderClient = createWordPressSyncClient({
            baseUrl: 'https://other.example.test',
            authToken: 'placeholder',
            fetchImpl: jest.fn() as unknown as typeof fetch,
        });

        const count = await processDraftsWithClient(placeholderClient, {
            baseUrl: 'https://wp.example.test',
            authToken: 'token',
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        expect(count).toBe(1);
        expect(fetchImpl).toHaveBeenCalledTimes(2);
        expect(fetchImpl.mock.calls[0][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts?status=draft&per_page=100');
        expect(fetchImpl.mock.calls[1][0]).toBe('https://wp.example.test/wp-json/wp/v2/posts/99');
    });
});
