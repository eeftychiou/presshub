/**
 * PressHub AI Daily News Briefing & Multi-Voice AI Podcast Workflow Module.
 *
 * Provides TypeScript utilities for:
 * 1. Dual-host Greek dialogue script parsing with speaker turn normalization.
 * 2. Target duration to word budget and turn pacing calculations.
 * 3. Briefing article payload formatting and source deduplication.
 * 4. Multi-voice Google Cloud TTS audio manifest generation.
 */

export type SpeakerGender = 'female' | 'male';

export interface DialogueTurn {
    speaker: SpeakerGender;
    speakerName: string;
    text: string;
}

export interface DurationBudget {
    minutes: number;
    targetWords: number;
    targetTurns: string;
    description: string;
}

export interface BriefingArticle {
    title: string;
    content: string;
    source_domain?: string;
    source?: string;
    url?: string;
}

export interface BriefingPayload {
    articlesCount: number;
    contextText: string;
    sourcesList: string;
    date?: string;
}

export interface AudioManifestItem {
    speaker: SpeakerGender;
    voice: string;
    text: string;
    speed: number;
    pitch: number;
    pauseAfterMs: number;
}

/**
 * Parses raw Greek dialogue script into structured speaker turns.
 * Handles markdown formatting (e.g. `**[Μαρία]:**`, `[Νίκος]:`, `**Μαρία:**`, `Νίκος:`),
 * multi-line turns, whitespace trimming, and empty line elimination.
 *
 * @param rawText    Raw dialogue text.
 * @param femaleHost Name of the female host (default 'Μαρία').
 * @param maleHost   Name of the male host (default 'Νίκος').
 * @returns Array of structured dialogue turns.
 */
export function parseDialogueScript(
    rawText: string,
    femaleHost: string = 'Μαρία',
    maleHost: string = 'Νίκος'
): DialogueTurn[] {
    if (!rawText || typeof rawText !== 'string' || rawText.trim() === '') {
        return [];
    }

    const lines = rawText.split(/\r?\n/);
    const turns: DialogueTurn[] = [];

    let currentSpeaker: SpeakerGender | null = null;
    let currentSpeakerName: string | null = null;
    let currentTextLines: string[] = [];

    // Match speaker lines like: [Μαρία]: ..., **[Μαρία]:** ..., **[Νίκος]**: ..., **Μαρία:** ..., Μαρία: ...
    const tagPattern = /^(?:[\s*_#]*)\s*(?:\[|\()?([^\n:\]\)]+)(?:\]|\))?\s*[*_]*\s*:\s*[*_]*\s*(.*)$/u;

    for (const line of lines) {
        const trimmed = line.trim();
        if (trimmed === '') {
            continue;
        }

        const match = trimmed.match(tagPattern);
        if (match) {
            const rawTag = match[1].trim();
            const lineContent = match[2].trim();

            // Clean speaker tag of markdown symbols or punctuation
            const cleanTag = rawTag.replace(/[^\p{L}\p{N}\s]+/gu, '').trim();

            let matchedSpeaker: SpeakerGender | null = null;
            let matchedName: string | null = null;

            const isFemale = (
                cleanTag.localeCompare(femaleHost, undefined, { sensitivity: 'accent' }) === 0
                || cleanTag.toLowerCase().includes(femaleHost.toLowerCase())
                || cleanTag.toLowerCase().includes('maria')
                || cleanTag.toLowerCase().includes('μαρια')
                || cleanTag.toLowerCase().includes('μαρία')
                || cleanTag.toLowerCase().includes('female')
                || cleanTag.toLowerCase().includes('host1')
                || cleanTag.toLowerCase().includes('host 1')
            );

            const isMale = (
                cleanTag.localeCompare(maleHost, undefined, { sensitivity: 'accent' }) === 0
                || cleanTag.toLowerCase().includes(maleHost.toLowerCase())
                || cleanTag.toLowerCase().includes('nikos')
                || cleanTag.toLowerCase().includes('νικος')
                || cleanTag.toLowerCase().includes('νίκος')
                || cleanTag.toLowerCase().includes('male')
                || cleanTag.toLowerCase().includes('host2')
                || cleanTag.toLowerCase().includes('host 2')
            );

            if (isFemale) {
                matchedSpeaker = 'female';
                matchedName = femaleHost;
            } else if (isMale) {
                matchedSpeaker = 'male';
                matchedName = maleHost;
            }

            if (matchedSpeaker !== null) {
                // Save previous turn if non-empty
                if (currentSpeaker !== null && currentTextLines.length > 0) {
                    let fullText = currentTextLines.join('\n').trim();
                    fullText = fullText.replace(/^\*\*|\*\*$/g, '').trim();
                    if (fullText !== '') {
                        turns.push({
                            speaker: currentSpeaker,
                            speakerName: currentSpeakerName || '',
                            text: fullText,
                        });
                    }
                }

                currentSpeaker = matchedSpeaker;
                currentSpeakerName = matchedName;
                currentTextLines = [];

                const cleanedContent = lineContent.replace(/^\*\*|\*\*$/g, '').trim();
                if (cleanedContent !== '') {
                    currentTextLines.push(cleanedContent);
                }
                continue;
            }
        }

        // If inside an active turn, append continuation line
        if (currentSpeaker !== null) {
            // Ignore Markdown title headings if at start of a block
            if (trimmed.startsWith('#') && currentTextLines.length === 0) {
                continue;
            }
            currentTextLines.push(trimmed);
        }
    }

    // Flush final turn
    if (currentSpeaker !== null && currentTextLines.length > 0) {
        let fullText = currentTextLines.join('\n').trim();
        fullText = fullText.replace(/^\*\*|\*\*$/g, '').trim();
        if (fullText !== '') {
            turns.push({
                speaker: currentSpeaker,
                speakerName: currentSpeakerName || '',
                text: fullText,
            });
        }
    }

    return turns;
}

/**
 * Calculates word budget, turn count targets, and description for a target podcast duration.
 *
 * @param duration Duration indicator (e.g. 3, '3_min', 5, '5_min', 10, '10_min').
 * @returns DurationBudget object with minutes, targetWords, targetTurns, and description.
 */
export function calculateDurationBudget(duration: string | number): DurationBudget {
    const raw = String(duration ?? '').trim().toLowerCase();

    if (['3', '3_min', '3min', '3_minutes', '3 minutes', '3 min'].includes(raw)) {
        return {
            minutes: 3,
            targetWords: 450,
            targetTurns: '6-8 turns',
            description: '3 λεπτά (~450 λέξεις, 6-8 διάλογοι)',
        };
    }

    if (['10', '10_min', '10min', '10_minutes', '10 minutes', '10 min'].includes(raw)) {
        return {
            minutes: 10,
            targetWords: 1500,
            targetTurns: '20+ turns',
            description: '10 λεπτά (~1500 λέξεις, 20+ διάλογοι)',
        };
    }

    // Default to 5 minutes
    return {
        minutes: 5,
        targetWords: 750,
        targetTurns: '12-15 turns',
        description: '5 λεπτά (~750 λέξεις, 12-15 διάλογοι)',
    };
}

/**
 * Formats a list of harvested news articles into structured markdown context and deduplicates sources.
 *
 * @param articles List of harvested news articles.
 * @param date     Optional briefing date (YYYY-MM-DD).
 * @returns Formatted BriefingPayload.
 */
export function formatBriefingPayload(
    articles: BriefingArticle[],
    date?: string
): BriefingPayload {
    if (!Array.isArray(articles) || articles.length === 0) {
        return {
            articlesCount: 0,
            contextText: 'Δεν υπάρχουν διαθέσιμα άρθρα.',
            sourcesList: 'Καμία πηγή',
            ...(date ? { date } : {}),
        };
    }

    // Extract unique sources
    const sourcesSet = new Set<string>();
    for (const article of articles) {
        const src = (article.source_domain || article.source || '').trim();
        if (src !== '') {
            sourcesSet.add(src);
        }
    }

    const sourcesList = sourcesSet.size > 0
        ? Array.from(sourcesSet).join(', ')
        : 'Καμία πηγή';

    // Format article blocks
    const blocks: string[] = [];
    for (let i = 0; i < articles.length; i++) {
        const article = articles[i];
        const num = i + 1;
        const title = (article.title || '').trim() || 'Χωρίς τίτλο';
        const source = (article.source_domain || article.source || '').trim() || 'Άγνωστη πηγή';
        const url = (article.url || '').trim();
        const content = (article.content || '').trim();

        let block = `### ${num}. ${title}\n**Πηγή:** ${source}`;
        if (url !== '') {
            block += ` | **URL:** ${url}`;
        }
        if (content !== '') {
            block += `\n\n${content}`;
        }

        blocks.push(block);
    }

    const contextText = blocks.join('\n\n---\n\n');

    return {
        articlesCount: articles.length,
        contextText,
        sourcesList,
        ...(date ? { date } : {}),
    };
}

/**
 * Generates an audio synthesis manifest mapping each dialogue turn to its assigned voice model,
 * pitch, speed, and inter-speaker pause interval.
 *
 * @param turns        Array of dialogue turns.
 * @param femaleVoice  Google Cloud TTS female voice model (default 'el-GR-Neural2-A').
 * @param maleVoice    Google Cloud TTS male voice model (default 'el-GR-Neural2-B').
 * @param speed        Speaking rate (default 1.0).
 * @param pitch        Voice pitch adjustment (default 0.0).
 * @param pauseAfterMs Inter-speaker silent pause duration in ms (default 400).
 * @returns Array of AudioManifestItem entries ready for batch synthesis.
 */
export function generateAudioManifest(
    turns: Array<{ speaker: SpeakerGender; text: string; speakerName?: string }>,
    femaleVoice: string = 'el-GR-Neural2-A',
    maleVoice: string = 'el-GR-Neural2-B',
    speed: number = 1.0,
    pitch: number = 0.0,
    pauseAfterMs: number = 400
): AudioManifestItem[] {
    if (!Array.isArray(turns)) {
        return [];
    }

    const resolvedSpeed = typeof speed === 'number' && !isNaN(speed) && speed > 0 ? speed : 1.0;
    const resolvedPitch = typeof pitch === 'number' && !isNaN(pitch) ? pitch : 0.0;
    const resolvedPause = typeof pauseAfterMs === 'number' && !isNaN(pauseAfterMs) && pauseAfterMs >= 0 ? pauseAfterMs : 400;

    return turns
        .filter(turn => turn && typeof turn === 'object' && typeof turn.text === 'string' && turn.text.trim() !== '')
        .map(turn => {
            const isFemale = turn.speaker === 'female';
            const voice = isFemale
                ? (femaleVoice || 'el-GR-Neural2-A')
                : (maleVoice || 'el-GR-Neural2-B');

            return {
                speaker: turn.speaker,
                voice,
                text: turn.text.trim(),
                speed: resolvedSpeed,
                pitch: resolvedPitch,
                pauseAfterMs: resolvedPause,
            };
        });
}
