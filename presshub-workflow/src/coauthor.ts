export async function processSources(sources: string[]): Promise<string> {
    if (!Array.isArray(sources)) {
        throw new Error('processSources: sources must be an array');
    }
    for (const item of sources) {
        if (typeof item !== 'string') {
            throw new Error('processSources: every source must be a string');
        }
    }
    return sources.join('\n\n');
}
