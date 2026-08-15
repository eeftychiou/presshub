import { processSources } from '../src/coauthor';

describe('AI Co-Author', () => {
    it('combines multiple sources into a single context string', async () => {
        const sources = ['Note 1: Economy is up.', 'Note 2: Markets are stable.'];
        const result = await processSources(sources);
        expect(result).toContain('Economy is up');
        expect(result).toContain('Markets are stable');
    });

    it('rejects non-array input', async () => {
        await expect(processSources(null as unknown as string[])).rejects.toThrow(/array/i);
        await expect(processSources('not an array' as unknown as string[])).rejects.toThrow(/array/i);
        await expect(processSources({} as unknown as string[])).rejects.toThrow(/array/i);
    });

    it('rejects when any element is not a string', async () => {
        await expect(processSources(['ok', 42 as unknown as string])).rejects.toThrow(/string/i);
        await expect(processSources([null as unknown as string])).rejects.toThrow(/string/i);
        await expect(processSources([{ text: 'no' } as unknown as string])).rejects.toThrow(/string/i);
    });
});
