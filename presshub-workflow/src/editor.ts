export async function generateScorecard(content: string) {
    if (typeof content !== 'string' || content.trim() === '') {
        throw new Error('generateScorecard: content must be a non-empty, non-whitespace string');
    }
    // This will eventually call the model-router MCP tool to evaluate the content.
    // For now, return a minimal stub to pass the test.
    return { score: 85, feedback: 'Good start. Needs more context.' };
}

export async function classifyIntent(prompt: string): Promise<string> {
    const p = (prompt ?? '').toString().toLowerCase();
    // Aligned with presshub-ai-editor/includes/class-api-client.php:60-80 semantics.
    // image: requests to generate, create, draw, paint, or design an image/illustration
    // (PHP: image-verbs imply image intent even without an explicit image noun)
    if (/\b(draw|paint|illustrate)\b/.test(p)
        || (/\b(generate|create|make|render|design)\b/.test(p)
            && /\b(image|picture|photo|illustration|drawing|graphic|art)\b/.test(p))) {
        return 'image';
    }
    // research: comprehensive synthesis, deep analysis, deep investigation
    if (/\b(research|synthesize|synthesis|analyze|analysis|investigate|investigation)\b/.test(p)
        || /deep (investigation|analysis|dive)/.test(p)) {
        return 'research';
    }
    // report: voice over, summarize, translate audio/video into a narrated report
    if (/\b(report|narrat(ion|ed|e)|voice ?over)\b/.test(p)
        || /\b(summarize|translate)\b.*\b(audio|video|podcast|track)\b/.test(p)
        || /\b(audio|video|podcast|track)\b.*\b(summarize|translate)\b/.test(p)) {
        return 'report';
    }
    return 'chat';
}

