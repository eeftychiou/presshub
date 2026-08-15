export async function processSources(sources: string[]): Promise<string> {
    return sources.join('\n\n');
}
