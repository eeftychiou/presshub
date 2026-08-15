import { processSources } from '../src/coauthor';

describe('AI Co-Author', () => {
    it('combines multiple sources into a single context string', async () => {
        const sources = ['Note 1: Economy is up.', 'Note 2: Markets are stable.'];
        const result = await processSources(sources);
        expect(result).toContain('Economy is up');
        expect(result).toContain('Markets are stable');
    });
});
