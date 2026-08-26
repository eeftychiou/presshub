import {
    parseDialogueScript,
    calculateDurationBudget,
    formatBriefingPayload,
    generateAudioManifest,
    BriefingArticle,
    DialogueTurn,
} from '../src/briefing';

describe('Briefing & Podcast Workflow Module', () => {
    describe('parseDialogueScript', () => {
        it('parses standard clean bracketed dialogue turns', () => {
            const script = `[Μαρία]: Καλημέρα σε όλους τους ακροατές του PressHub!
[Νίκος]: Καλημέρα Μαρία, καλημέρα σε όλους. Σήμερα έχουμε πλούσια ατζέντα.
[Μαρία]: Ξεκινάμε με τις εξελίξεις στην οικονομία και τα νέα μέτρα.
[Νίκος]: Ακριβώς, ας δούμε αναλυτικά τα στοιχεία.`;

            const turns = parseDialogueScript(script);

            expect(turns).toHaveLength(4);
            expect(turns[0]).toEqual({
                speaker: 'female',
                speakerName: 'Μαρία',
                text: 'Καλημέρα σε όλους τους ακροατές του PressHub!',
            });
            expect(turns[1]).toEqual({
                speaker: 'male',
                speakerName: 'Νίκος',
                text: 'Καλημέρα Μαρία, καλημέρα σε όλους. Σήμερα έχουμε πλούσια ατζέντα.',
            });
            expect(turns[2]).toEqual({
                speaker: 'female',
                speakerName: 'Μαρία',
                text: 'Ξεκινάμε με τις εξελίξεις στην οικονομία και τα νέα μέτρα.',
            });
            expect(turns[3]).toEqual({
                speaker: 'male',
                speakerName: 'Νίκος',
                text: 'Ακριβώς, ας δούμε αναλυτικά τα στοιχεία.',
            });
        });

        it('handles markdown bold variations and multiline turns', () => {
            const messy = `### PressHub Morning Podcast

**[Μαρία]:** Καλημέρα σας!
Σήμερα έχουμε μια έκτακτη είδηση από τις Βρυξέλλες.

**[Νίκος]**: Καλημέρα Μαρία.
Πράγματι, οι ανακοινώσεις έγιναν νωρίς το πρωί.
Συνεχίζουμε με τις αντιδράσεις.

**Μαρία:** Ποιες είναι οι πρώτες εκτιμήσεις;

Νίκος:   Όλοι συμφωνούν ότι πρόκειται για θετική εξέλιξη.`;

            const turns = parseDialogueScript(messy);

            expect(turns).toHaveLength(4);
            expect(turns[0].speaker).toBe('female');
            expect(turns[0].text).toBe('Καλημέρα σας!\nΣήμερα έχουμε μια έκτακτη είδηση από τις Βρυξέλλες.');
            expect(turns[1].speaker).toBe('male');
            expect(turns[1].text).toBe('Καλημέρα Μαρία.\nΠράγματι, οι ανακοινώσεις έγιναν νωρίς το πρωί.\nΣυνεχίζουμε με τις αντιδράσεις.');
            expect(turns[2].speaker).toBe('female');
            expect(turns[2].text).toBe('Ποιες είναι οι πρώτες εκτιμήσεις;');
            expect(turns[3].speaker).toBe('male');
            expect(turns[3].text).toBe('Όλοι συμφωνούν ότι πρόκειται για θετική εξέλιξη.');
        });

        it('supports English/Latin speaker aliases and host tags', () => {
            const script = `[Maria]: Good morning everyone.
[Nikos]: Hello Maria.
[Host1]: We have breaking news.
[Host 2]: Let us review the report.
[Female]: Additional details here.
[Male]: Thank you.`;

            const turns = parseDialogueScript(script);

            expect(turns).toHaveLength(6);
            expect(turns[0].speaker).toBe('female');
            expect(turns[1].speaker).toBe('male');
            expect(turns[2].speaker).toBe('female');
            expect(turns[3].speaker).toBe('male');
            expect(turns[4].speaker).toBe('female');
            expect(turns[5].speaker).toBe('male');
        });

        it('supports custom host names', () => {
            const script = `[Ελένη]: Καλωσορίσατε στην εκπομπή.
[Γιώργος]: Ευχαριστούμε που είστε μαζί μας.`;

            const turns = parseDialogueScript(script, 'Ελένη', 'Γιώργος');

            expect(turns).toHaveLength(2);
            expect(turns[0]).toEqual({
                speaker: 'female',
                speakerName: 'Ελένη',
                text: 'Καλωσορίσατε στην εκπομπή.',
            });
            expect(turns[1]).toEqual({
                speaker: 'male',
                speakerName: 'Γιώργος',
                text: 'Ευχαριστούμε που είστε μαζί μας.',
            });
        });

        it('returns empty array on empty, non-string, or tagless input', () => {
            expect(parseDialogueScript('')).toEqual([]);
            expect(parseDialogueScript('   \n\n  ')).toEqual([]);
            expect(parseDialogueScript(null as unknown as string)).toEqual([]);
            expect(parseDialogueScript(undefined as unknown as string)).toEqual([]);
            expect(parseDialogueScript('Αυτό είναι ένα απλό κείμενο χωρίς ετικέτες.')).toEqual([]);
        });
    });

    describe('calculateDurationBudget', () => {
        it('calculates 3 minute budget correctly', () => {
            const inputs = [3, '3', '3_min', '3min', '3 minutes', '3_minutes', '3 min'];
            for (const input of inputs) {
                const res = calculateDurationBudget(input);
                expect(res.minutes).toBe(3);
                expect(res.targetWords).toBe(450);
                expect(res.targetTurns).toBe('6-8 turns');
                expect(res.description).toContain('3 λεπτά');
                expect(res.description).toContain('450');
            }
        });

        it('calculates 5 minute budget correctly (and as default)', () => {
            const inputs = [5, '5', '5_min', '5min', '5 minutes', '5_minutes', '', 'unknown', 'abc'];
            for (const input of inputs) {
                const res = calculateDurationBudget(input);
                expect(res.minutes).toBe(5);
                expect(res.targetWords).toBe(750);
                expect(res.targetTurns).toBe('12-15 turns');
                expect(res.description).toContain('5 λεπτά');
                expect(res.description).toContain('750');
            }
        });

        it('calculates 10 minute budget correctly', () => {
            const inputs = [10, '10', '10_min', '10min', '10 minutes', '10_minutes', '10 min'];
            for (const input of inputs) {
                const res = calculateDurationBudget(input);
                expect(res.minutes).toBe(10);
                expect(res.targetWords).toBe(1500);
                expect(res.targetTurns).toBe('20+ turns');
                expect(res.description).toContain('10 λεπτά');
                expect(res.description).toContain('1500');
            }
        });
    });

    describe('formatBriefingPayload', () => {
        it('returns fallback structure when article array is empty or missing', () => {
            const emptyPayload = formatBriefingPayload([]);
            expect(emptyPayload.articlesCount).toBe(0);
            expect(emptyPayload.contextText).toBe('Δεν υπάρχουν διαθέσιμα άρθρα.');
            expect(emptyPayload.sourcesList).toBe('Καμία πηγή');

            const nullPayload = formatBriefingPayload(null as unknown as BriefingArticle[], '2026-08-26');
            expect(nullPayload.articlesCount).toBe(0);
            expect(nullPayload.date).toBe('2026-08-26');
        });

        it('formats articles into structured context and deduplicates sources', () => {
            const articles: BriefingArticle[] = [
                {
                    title: 'Νέο φορολογικό νομοσχέδιο',
                    source_domain: 'Kathimerini',
                    url: 'https://kathimerini.gr/tax',
                    content: 'Σημαντικές ελαφρύνσεις για τις επιχειρήσεις.',
                },
                {
                    title: 'Ψηφιακές πλατφόρμες υγείας',
                    source_domain: 'In.gr',
                    url: 'https://in.gr/health',
                    content: 'Νέες υπηρεσίες για τους ασφαλισμένους.',
                },
                {
                    title: 'Επενδύσεις στην ενέργεια',
                    source_domain: 'Kathimerini',
                    url: 'https://kathimerini.gr/energy',
                    content: 'Ανανεώσιμες πηγές και δίκτυα.',
                },
            ];

            const result = formatBriefingPayload(articles, '2026-08-26');

            expect(result.articlesCount).toBe(3);
            expect(result.date).toBe('2026-08-26');
            // Sources should be deduplicated: Kathimerini, In.gr
            expect(result.sourcesList).toBe('Kathimerini, In.gr');
            // Context should contain numbered markdown sections
            expect(result.contextText).toContain('### 1. Νέο φορολογικό νομοσχέδιο');
            expect(result.contextText).toContain('**Πηγή:** Kathimerini | **URL:** https://kathimerini.gr/tax');
            expect(result.contextText).toContain('### 2. Ψηφιακές πλατφόρμες υγείας');
            expect(result.contextText).toContain('### 3. Επενδύσεις στην ενέργεια');
            expect(result.contextText).toContain('---');
        });

        it('handles articles with missing optional fields', () => {
            const articles: BriefingArticle[] = [
                {
                    title: '',
                    content: 'Μόνο κείμενο',
                    source: 'Tovima',
                },
            ];

            const result = formatBriefingPayload(articles);

            expect(result.articlesCount).toBe(1);
            expect(result.sourcesList).toBe('Tovima');
            expect(result.contextText).toContain('### 1. Χωρίς τίτλο');
            expect(result.contextText).toContain('**Πηγή:** Tovima');
            expect(result.contextText).toContain('Μόνο κείμενο');
            expect(result.contextText).not.toContain('URL:');
        });
    });

    describe('generateAudioManifest', () => {
        const sampleTurns: DialogueTurn[] = [
            {
                speaker: 'female',
                speakerName: 'Μαρία',
                text: 'Καλημέρα σε όλους!',
            },
            {
                speaker: 'male',
                speakerName: 'Νίκος',
                text: 'Καλημέρα Μαρία!',
            },
        ];

        it('generates manifest with default voice models and default audio parameters', () => {
            const manifest = generateAudioManifest(sampleTurns);

            expect(manifest).toHaveLength(2);
            expect(manifest[0]).toEqual({
                speaker: 'female',
                voice: 'el-GR-Neural2-A',
                text: 'Καλημέρα σε όλους!',
                speed: 1.0,
                pitch: 0.0,
                pauseAfterMs: 400,
            });
            expect(manifest[1]).toEqual({
                speaker: 'male',
                voice: 'el-GR-Neural2-B',
                text: 'Καλημέρα Μαρία!',
                speed: 1.0,
                pitch: 0.0,
                pauseAfterMs: 400,
            });
        });

        it('respects custom voice models, speed, pitch, and pause duration', () => {
            const manifest = generateAudioManifest(
                sampleTurns,
                'el-GR-Wavenet-A',
                'el-GR-Wavenet-B',
                1.15,
                1.5,
                500
            );

            expect(manifest).toHaveLength(2);
            expect(manifest[0].voice).toBe('el-GR-Wavenet-A');
            expect(manifest[0].speed).toBe(1.15);
            expect(manifest[0].pitch).toBe(1.5);
            expect(manifest[0].pauseAfterMs).toBe(500);

            expect(manifest[1].voice).toBe('el-GR-Wavenet-B');
            expect(manifest[1].speed).toBe(1.15);
            expect(manifest[1].pitch).toBe(1.5);
            expect(manifest[1].pauseAfterMs).toBe(500);
        });

        it('filters out empty or invalid turns and handles non-array input', () => {
            const messyTurns = [
                { speaker: 'female' as const, text: 'Έγκυρη ατάκα' },
                { speaker: 'male' as const, text: '   ' },
                null as unknown as DialogueTurn,
                { speaker: 'male' as const, text: 'Δεύτερη έγκυρη ατάκα' },
            ];

            const manifest = generateAudioManifest(messyTurns);
            expect(manifest).toHaveLength(2);
            expect(manifest[0].text).toBe('Έγκυρη ατάκα');
            expect(manifest[1].text).toBe('Δεύτερη έγκυρη ατάκα');

            expect(generateAudioManifest(null as unknown as DialogueTurn[])).toEqual([]);
        });
    });
});
