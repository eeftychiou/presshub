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
});
