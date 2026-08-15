import { processDrafts } from '../src/sync';

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
});
