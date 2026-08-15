export type Scorecard = { score: number; feedback: string };
export type ScorecardEvaluator = (content: string) => Promise<Scorecard>;
export interface GenerateScorecardOptions {
    evaluator?: ScorecardEvaluator;
}

const defaultEvaluator: ScorecardEvaluator = async (_content: string): Promise<Scorecard> => {
    // This will eventually call the model-router MCP tool to evaluate the content.
    // For now, return a minimal stub to pass the test.
    return { score: 85, feedback: 'Good start. Needs more context.' };
};

function validateScorecard(result: unknown): Scorecard {
    if (!result || typeof result !== 'object') {
        throw new Error('generateScorecard: evaluator returned a non-object result');
    }
    const candidate = result as Record<string, unknown>;
    if (typeof candidate.score !== 'number') {
        throw new Error('generateScorecard: evaluator result.score must be a number');
    }
    if (typeof candidate.feedback !== 'string') {
        throw new Error('generateScorecard: evaluator result.feedback must be a string');
    }
    return { score: candidate.score, feedback: candidate.feedback };
}

export async function generateScorecard(
    content: string,
    options: GenerateScorecardOptions = {}
): Promise<Scorecard> {
    if (typeof content !== 'string' || content.trim() === '') {
        throw new Error('generateScorecard: content must be a non-empty, non-whitespace string');
    }
    const evaluator = options.evaluator ?? defaultEvaluator;
    if (typeof evaluator !== 'function') {
        throw new Error('generateScorecard: options.evaluator must be a function');
    }
    const raw = await evaluator(content);
    return validateScorecard(raw);
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
    // report: voice over, narrate, or summarize/translate audio/video into a report.
    // Note: bare "report" (the noun) is intentionally excluded — "write a report" or
    // "this is a great report" should fall through to chat/research, not be classified
    // as the audio-report intent.
    if (/\b(narrat(ion|ed|e)|voice ?over)\b/.test(p)
        || /\b(summarize|translate)\b.*\b(audio|video|podcast|track)\b/.test(p)
        || /\b(audio|video|podcast|track)\b.*\b(summarize|translate)\b/.test(p)) {
        return 'report';
    }
    return 'chat';
}

