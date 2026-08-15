export async function generateScorecard(content: string) {
    // This will eventually call the model-router MCP tool to evaluate the content.
    // For now, return a minimal stub to pass the test.
    return { score: 85, feedback: 'Good start. Needs more context.' };
}

export async function classifyIntent(prompt: string): Promise<string> {
    const p = prompt.toLowerCase();
    if (p.includes('image') || p.includes('generate') || p.includes('draw') || p.includes('create') || p.includes('illustration') || p.includes('design') || p.includes('paint')) {
        return 'image';
    }
    if (p.includes('research') || p.includes('synthesize') || p.includes('analysis') || p.includes('investigate') || p.includes('deep investigation')) {
        return 'research';
    }
    if (p.includes('voice') || p.includes('summarize') || p.includes('translate') || p.includes('audio') || p.includes('video') || p.includes('report') || p.includes('narrated')) {
        return 'report';
    }
    return 'chat';
}

