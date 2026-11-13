import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import {
  assTime,
  assEscape,
  fitSegments,
  timeWords,
  buildCaptionChunks,
  buildAssScript,
  planSlots,
  hookSeconds,
  buildFfmpegArgs,
  generateShortVideo,
  resolveFfmpegPath,
  logoPath,
  END_CARD_SECONDS,
  ShortSegment
} from '../src/media/short-video';

const OUT = path.resolve(__dirname, '..', 'tmp', 'short-video-test');

const segments: ShortSegment[] = [
  { speaker: 1, text: 'Χίλια ευρώ. Τόσος θα είναι ο νέος κατώτατος μισθός στην Κύπρο.', start: 0, end: 4 },
  { speaker: 2, text: 'Η αύξηση αφορά είκοσι χιλιάδες εργαζόμενους.', start: 4.4, end: 7 }
];

describe('TikTok short renderer', () => {
  test('assTime and assEscape format ASS timestamps and strip override braces', () => {
    expect(assTime(0)).toBe('0:00:00.00');
    expect(assTime(61.237)).toBe('0:01:01.24');
    expect(assTime(3725.5)).toBe('1:02:05.50');
    expect(assEscape('Νέος {\\b1} μισθός\n 1.000')).toBe('Νέος ⧵b1 μισθός 1.000');
  });

  test('fitSegments keeps exact timings and rescales estimates to the audio length', () => {
    expect(fitSegments(segments, 7.1)[1].end).toBe(7);
    const scaled = fitSegments(segments, 14);
    expect(scaled[0].end).toBeCloseTo(8);
    expect(scaled[1].start).toBeCloseTo(8.8);
    expect(scaled[1].end).toBeCloseTo(14);
    expect(fitSegments([{ speaker: 1, text: '', start: 0, end: 1 }, { speaker: 3, text: 'x', start: 1, end: 2 }], 2)).toEqual([
      { speaker: 1, text: 'x', start: 1, end: 2 }
    ]);
  });

  test('timeWords spreads each turn over its time span, in order', () => {
    const words = timeWords(segments);
    expect(words.map((w) => w.text).join(' ')).toBe(`${segments[0].text} ${segments[1].text}`);
    for (let i = 1; i < words.length; i++) {
      expect(words[i].start).toBeGreaterThanOrEqual(words[i - 1].end - 1e-9);
    }
    const firstTurn = words.filter((w) => w.speaker === 1);
    expect(firstTurn[0].start).toBeGreaterThanOrEqual(0);
    expect(firstTurn[firstTurn.length - 1].end).toBeLessThanOrEqual(4);
    expect(words.find((w) => w.speaker === 2)!.start).toBeGreaterThanOrEqual(4.4);
  });

  test('caption chunks hold a few words, break at sentences and never mix speakers', () => {
    const chunks = buildCaptionChunks(timeWords(segments));
    for (const c of chunks) {
      expect(c.words.length).toBeLessThanOrEqual(5);
      expect(new Set(c.words.map((w) => w.speaker)).size).toBe(1);
    }
    expect(chunks[0].words.map((w) => w.text).join(' ')).toBe('Χίλια ευρώ.');
    // No chunk is a lone trailing word when it can join the one before.
    const lone = chunks.filter((c, i) => c.words.length === 1 && i > 0 && chunks[i - 1].speaker === c.speaker && !/[.;!?]$/.test(chunks[i - 1].words.slice(-1)[0].text));
    expect(lone).toHaveLength(0);
  });

  test('the ASS script has the hook first, highlighted words, and the end card at the end', () => {
    const total = 8;
    const ass = buildAssScript({
      hook: 'Νέος μισθός 1.000 ευρώ',
      topic: 'Κατώτατος μισθός',
      badge: 'ΕΙΔΗΣΗ 1/3',
      siteName: 'PressHub.Cy',
      chunks: buildCaptionChunks(timeWords(segments)),
      totalDuration: total
    });
    expect(ass).toContain('PlayResY: 1920');
    expect(ass).toMatch(/Dialogue: 2,0:00:00\.00,0:00:02\.60,Hook,.*Νέος μισθός 1\.000 ευρώ/);
    expect(ass).toContain('ΕΙΔΗΣΗ 1/3');
    // Speaker 1 words in yellow, speaker 2 words in blue.
    expect(ass).toContain('{\\c&H0015CCFA&}');
    expect(ass).toContain('{\\c&H00F8BD38&}');
    const captions = ass.split('\n').filter((l) => l.includes(',Caption,'));
    expect(captions.length).toBeGreaterThan(5);
    const toSec = (t: string) => {
      const [h, m, s] = t.split(':');
      return Number(h) * 3600 + Number(m) * 60 + Number(s);
    };
    for (const line of captions) {
      const [, start, end] = line.split(',');
      // Captions start after the hook and stop before the end card.
      expect(toSec(start)).toBeGreaterThanOrEqual(hookSeconds(total) - 1e-9);
      expect(toSec(end)).toBeLessThanOrEqual(total - END_CARD_SECONDS + 0.01);
    }
    expect(ass).toMatch(/Dialogue: 4,0:00:05\.50,0:00:08\.00,EndTitle,.*PressHub\.Cy/);
  });

  test('with the logo the site name is not written as text', () => {
    const ass = buildAssScript({ hook: 'Hook', siteName: 'PressHub.Cy', chunks: [], totalDuration: 8, hasLogo: true });
    expect(ass).not.toMatch(/Dialogue: .*,(Brand|EndTitle),/);
    expect(ass).toMatch(/Dialogue: 4,.*,EndLine,/);
  });

  test('the bundled PressHub logo exists and is overlaid in the corner and on the end card', () => {
    expect(logoPath()).not.toBeNull();
    const args = buildFfmpegArgs({
      slots: planSlots(8, 1),
      images: ['img-0.jpg'],
      audio: 'voice.wav',
      shade: 'shade.png',
      ass: 'captions.ass',
      fonts: 'fonts',
      output: 'out.mp4',
      width: 1080,
      height: 1920,
      fps: 30,
      total: 8,
      logo: 'logo.png'
    });
    expect(args).toContain('logo.png');
    const graph = args[args.indexOf('-filter_complex') + 1];
    expect(graph).toContain("[lgs]overlay=x=W-w-90:y=262:shortest=1:enable='lt(t,5.500)'");
    expect(graph).toContain("[lgb]overlay=x=(W-w)/2:y=520:shortest=1:enable='gte(t,5.500)'");
    expect(graph).toContain('scale=640:-1,fade=t=in:st=5.500');
  });

  test('planSlots changes the shot about every 4 seconds and varies the motion', () => {
    const slots = planSlots(30, 3);
    expect(slots).toHaveLength(8);
    expect(slots.reduce((n, s) => n + s.duration, 0)).toBeCloseTo(30);
    expect(slots.map((s) => s.image)).toEqual([0, 1, 2, 0, 1, 2, 0, 1]);
    for (let i = 1; i < slots.length; i++) {
      expect(slots[i].motion).not.toBe(slots[i - 1].motion);
    }
    expect(planSlots(2, 0)).toHaveLength(1);
  });

  test('FFmpeg arguments burn the captions with the bundled fonts using relative paths', () => {
    const args = buildFfmpegArgs({
      slots: planSlots(8, 2),
      images: ['img-0.jpg', 'img-1.jpg'],
      audio: 'voice.wav',
      shade: 'shade.png',
      ass: 'captions.ass',
      fonts: 'fonts',
      output: 'out.mp4',
      width: 1080,
      height: 1920,
      fps: 30,
      total: 8
    });
    const graph = args[args.indexOf('-filter_complex') + 1];
    expect(graph).toContain('subtitles=captions.ass:fontsdir=fonts');
    expect(graph).toContain('concat=n=2');
    expect(graph).toMatch(/zoompan=z='1\+0\.15\*on\/120'/);
    expect(graph).toContain("enable='gte(t,5.500)'");
    expect(args).toContain('-t');
    expect(args[args.length - 1]).toBe('out.mp4');
  });

  test('renders a short video with captions over the branded background', async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const voice = path.join(OUT, 'voice.wav');
    execFileSync(resolveFfmpegPath(), ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', voice]);
    const result = await generateShortVideo({
      audioPath: voice,
      hook: 'Νέος μισθός',
      topic: 'Κατώτατος μισθός',
      badge: 'ΕΙΔΗΣΗ 1/1',
      segments: [{ speaker: 1, text: 'Χίλια ευρώ από τον Ιανουάριο.', start: 0, end: 3 }],
      tailSeconds: 0.5,
      outputDir: OUT
    });
    expect(fs.existsSync(result.filePath)).toBe(true);
    expect(fs.statSync(result.filePath).size).toBeGreaterThan(10000);
    expect(result.duration).toBeCloseTo(3.5, 1);
    expect(result.images).toBe(0);
    // The work directory is cleaned up; only the voice and the video remain.
    expect(fs.readdirSync(OUT).filter((f) => f.startsWith('short-') && !f.endsWith('.mp4'))).toHaveLength(0);
    fs.rmSync(OUT, { recursive: true, force: true });
  }, 120000);

  test('fails without a voice track instead of rendering a silent short', async () => {
    await expect(generateShortVideo({ hook: 'x', outputDir: OUT })).rejects.toThrow(/no voice track/);
    fs.rmSync(OUT, { recursive: true, force: true });
  });
});
