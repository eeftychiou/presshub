import {
  fitTimeline,
  planEpisodeSlots,
  buildEpisodeAssScript,
  buildEpisodeFfmpegArgs,
  EPISODE_MAX_SLOTS,
  EPISODE_SLOT_SECONDS,
} from '../src/media/episode-video';
import { buildCaptionChunks, timeWords } from '../src/media/short-video';

describe('YouTube episode renderer', () => {
  const segments = [
    { speaker: 1, text: 'Καλημέρα σας.', start: 3.6, end: 6 },
    { speaker: 2, text: 'Καλημέρα Μαρία.', start: 6.25, end: 9 },
    { speaker: 1, text: '', start: 10, end: 11 },
  ];
  const scenes = [
    { start: 3.6, end: 50, title: 'Βουλή', images: ['a', 'b'] },
    { start: 50, end: 50.2, title: 'Πολύ σύντομο', images: [] },
    { start: 50.2, end: 100, title: 'Καιρός', images: ['c'] },
  ];

  test('an exact timeline is kept as it is, and scenes cover the whole audio', () => {
    const fit = fitTimeline(segments, scenes, 100, 100);
    expect(fit.segments).toHaveLength(2);
    expect(fit.segments[0]).toMatchObject({ start: 3.6, end: 6, speaker: 1 });
    expect(fit.segments[1].speaker).toBe(2);
    expect(fit.scenes.map((s) => [s.start, s.end])).toEqual([[0, 50.2], [50.2, 100]]);
    expect(fit.scenes[0].title).toBe('Βουλή');
  });

  test('an estimated timeline is scaled to the real audio length', () => {
    const fit = fitTimeline(segments, scenes, 100, 120);
    expect(fit.segments[0].start).toBeCloseTo(4.32);
    expect(fit.scenes[1].start).toBeCloseTo(60.24);
    expect(fit.scenes[1].end).toBe(120);
  });

  test('each scene shows its own images, and scenes without images show them all', () => {
    const index = (u: string) => ['a', 'b', 'c'].indexOf(u);
    const slots = planEpisodeSlots(
      [{ start: 0, end: 24, images: ['a', 'b'] }, { start: 24, end: 36, images: [] }, { start: 36, end: 48, images: ['c'] }],
      48,
      index,
      3
    );
    expect(slots.map((s) => s.image)).toEqual([0, 1, 0, 1, 0, 1, 2, 2]);
    expect(slots.reduce((t, s) => t + s.duration, 0)).toBeCloseTo(48);
    expect(new Set(slots.slice(0, 4).map((s) => s.motion)).size).toBe(4);
  });

  test('a long episode never has more shots than the command line can hold', () => {
    const total = 25 * 60;
    const slots = planEpisodeSlots([{ start: 0, end: total, images: [] }], total, () => -1, 30);
    expect(slots.length).toBeLessThanOrEqual(EPISODE_MAX_SLOTS);
    const args = buildEpisodeFfmpegArgs({
      slots,
      images: Array.from({ length: 30 }, (_, i) => `img-${i}.jpg`),
      audio: 'voice.wav',
      shade: 'shade.png',
      ass: 'captions.ass',
      fonts: 'fonts',
      output: 'out.mp4',
      fps: 25,
      total,
      logo: 'logo.png',
    });
    expect(args.join(' ').length).toBeLessThan(30000);
    // Every image is read once and split into its shots.
    expect(args.filter((a) => /^img-\d+\.jpg$/.test(a))).toHaveLength(30);
    const graph = args[args.indexOf('-filter_complex') + 1];
    expect(graph).toContain(`concat=n=${slots.length}:v=1:a=0`);
    expect(graph).toContain('subtitles=captions.ass:fontsdir=fonts');
    const frames = [...graph.matchAll(/:d=(\d+):/g)].reduce((t, m) => t + Number(m[1]), 0);
    expect(frames).toBe(total * 25);
  });

  test('short episodes use shots of about six seconds', () => {
    const slots = planEpisodeSlots([], 60, () => -1, 2);
    expect(slots).toHaveLength(60 / EPISODE_SLOT_SECONDS);
    expect(slots.map((s) => s.image)).toEqual([0, 1, 0, 1, 0, 1, 0, 1, 0, 1]);
  });

  test('the captions, titles and end line are in the ASS script', () => {
    const fit = fitTimeline(segments, scenes, 100, 100);
    const chunks = buildCaptionChunks(timeWords(fit.segments));
    const ass = buildEpisodeAssScript({
      title: 'Πρωινό Podcast',
      siteName: 'PressHub',
      chunks,
      scenes: fit.scenes,
      totalDuration: 100.5,
      hasLogo: true,
      endCardLine: 'Ολόκληρη η ενημέρωση στο presshub.cy',
    });
    expect(ass).toContain('PlayResX: 1920');
    expect(ass).toMatch(/Dialogue: 2,0:00:00\.00,0:00:04\.50,Card,.*Πρωινό Podcast/);
    expect(ass).toMatch(/Dialogue: 2,0:00:50\.20,0:00:53\.70,Card,.*Καιρός/);
    expect(ass).toMatch(/Label,.*Βουλή/);
    // The second host's words are highlighted in blue, as in the shorts.
    expect(ass).toMatch(/\\c&H00F8BD38&\}Καλημέρα/);
    expect(ass).toMatch(/Dialogue: 4,0:01:36\.50,0:01:40\.50,EndLine,.*presshub\.cy/);
    expect(ass).not.toMatch(/Dialogue: [^\n]*,Brand,/);
  });
});
