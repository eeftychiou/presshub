import fs from 'fs';
import path from 'path';
import { ShortRenderCache, shortRenderKey, SHORT_CACHE_DIRNAME } from '../src/media/short-cache';
import { PressHubPoller, ClaimedJob } from '../src/poller';

const TEST_DIR = path.resolve(__dirname, 'tmp-short-cache-test');

function render(name: string): { filePath: string; width: number; height: number; duration: number; captions: number; images: number; music: boolean } {
  fs.mkdirSync(TEST_DIR, { recursive: true });
  const filePath = path.join(TEST_DIR, `${name}-${Math.random().toString(16).slice(2)}.mp4`);
  fs.writeFileSync(filePath, Buffer.alloc(1000, 3));
  return { filePath, width: 1080, height: 1920, duration: 42, captions: 8, images: 2, music: true };
}

beforeEach(() => {
  fs.rmSync(TEST_DIR, { recursive: true, force: true });
});

afterAll(() => {
  fs.rmSync(TEST_DIR, { recursive: true, force: true });
});

describe('shortRenderKey', () => {
  const base = { audioUrl: 'https://x/a.wav', imageUrls: ['https://x/1.jpg'], hook: 'Hook', badge: 'ΕΙΔΗΣΗ 1/3', segments: [{ speaker: 1, text: 'Hi', start: 0, end: 1 }] as any };

  test('is the same for the same story, wherever the video is written', () => {
    expect(shortRenderKey({ ...base, outputDir: '/a' })).toBe(shortRenderKey({ ...base, outputDir: '/b' }));
  });

  test('changes when anything seen or heard changes', () => {
    const key = shortRenderKey(base);
    expect(shortRenderKey({ ...base, hook: 'Other' })).not.toBe(key);
    expect(shortRenderKey({ ...base, musicUrl: 'https://x/m.mp3' })).not.toBe(key);
    expect(shortRenderKey({ ...base, imageUrls: ['https://x/2.jpg'] })).not.toBe(key);
    expect(shortRenderKey({ ...base, endCardLine: 'x' })).not.toBe(key);
  });
});

describe('ShortRenderCache', () => {
  const dir = () => path.join(TEST_DIR, SHORT_CACHE_DIRNAME);

  test('keeps a render and hands it back with its duration', () => {
    const cache = new ShortRenderCache(dir());
    const fresh = render('a');
    const stored = cache.put('k1', fresh);
    expect(stored.cached).toBe(true);
    expect(stored.filePath).toBe(path.join(dir(), 'k1.mp4'));
    expect(fs.existsSync(fresh.filePath)).toBe(false);
    expect(cache.get('k1')).toMatchObject({ filePath: stored.filePath, duration: 42, cached: true });
    expect(cache.get('other')).toBeNull();
  });

  test('forgets and prunes renders older than the time to live', () => {
    const cache = new ShortRenderCache(dir(), 1000);
    cache.put('old', render('b'));
    const later = Date.now() + 5000;
    expect(cache.get('old', later)).toBeNull();
    expect(cache.prune(later)).toBe(1);
    expect(fs.readdirSync(dir())).toEqual([]);
  });

  test('a time to live of 0 turns the cache off', () => {
    const cache = new ShortRenderCache(dir(), 0);
    const fresh = render('c');
    const stored = cache.put('k', fresh);
    expect(stored).toMatchObject({ filePath: fresh.filePath, cached: false });
    expect(cache.get('k')).toBeNull();
  });

  test('a render that is not on disk is returned as it was', () => {
    const cache = new ShortRenderCache(dir());
    expect(cache.put('k', { ...render('d'), filePath: '/nowhere/x.mp4' })).toMatchObject({ filePath: '/nowhere/x.mp4', cached: false });
  });
});

describe('Poller: one render per story', () => {
  const story = { index: 0, count: 2, topic: 'Θέμα', hook: 'Hook', images: ['https://presshub.test/1.jpg'], segments: [{ speaker: 1, text: 'Γεια', start: 0, end: 1 }] };
  const job = (id: number, platform: string, extra: Partial<ClaimedJob> = {}): ClaimedJob => ({
    id,
    platform,
    post_id: 950,
    post_title: 'Podcast',
    post_copy: `Λεζάντα ${platform}`,
    video_title: 'Hook',
    audio_url: 'https://presshub.test/story.wav',
    tiktok_short: { ...story },
    ...extra
  } as ClaimedJob);

  function makePoller(jobs: ClaimedJob[]) {
    const reports: any[] = [];
    const posted: any[] = [];
    const fetchFn: any = async (url: string, init?: any) => {
      if (url.endsWith('/worker/claim')) return { ok: true, json: async () => ({ jobs }) };
      if (url.endsWith('/worker/complete')) {
        reports.push(JSON.parse(init.body));
        return { ok: true, json: async () => ({ success: true }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    };
    const generateShortFn = jest.fn().mockImplementation(async () => render('story'));
    const poller = new PressHubPoller({
      wpUrl: 'https://presshub.test',
      workerSecret: 'S',
      fetchFn,
      outputDir: TEST_DIR,
      sessionsDir: path.join(TEST_DIR, 'sessions'),
      generateShortFn,
      posterHandler: async (o: any) => {
        posted.push({ ...o, existed: fs.existsSync(o.mediaPath) });
        return { success: true, platform: o.platform, external_url: `https://example.test/${o.platform}` } as any;
      },
      reportSleepFn: async () => {}
    } as any);
    return { poller, reports, posted, generateShortFn };
  }

  test('the same story for TikTok and Instagram is rendered once and posted twice', async () => {
    const { poller, reports, posted, generateShortFn } = makePoller([job(1, 'tiktok'), job(2, 'instagram')]);
    const stats = await poller.pollOnce();
    expect(stats).toEqual({ claimed: 2, succeeded: 2, failed: 0 });
    expect(generateShortFn).toHaveBeenCalledTimes(1);
    expect(posted.map((p) => p.platform)).toEqual(['tiktok', 'instagram']);
    expect(posted[0].mediaPath).toBe(posted[1].mediaPath);
    expect(posted.every((p) => p.existed)).toBe(true);
    expect(posted[0].mediaPath).toContain(SHORT_CACHE_DIRNAME);
    // Kept for the story's later platforms (and retries) until it is a day old.
    expect(fs.existsSync(posted[0].mediaPath)).toBe(true);
    expect(reports.map((r) => r.status)).toEqual(['published', 'published']);
  });

  test('a different story is rendered on its own', async () => {
    const other = job(4, 'instagram', { tiktok_short: { ...story, index: 1, topic: 'Άλλο', hook: 'Hook 2' } as any, video_title: 'Hook 2' });
    const { poller, generateShortFn } = makePoller([job(3, 'tiktok'), other]);
    await poller.pollOnce();
    expect(generateShortFn).toHaveBeenCalledTimes(2);
  });

  test('a test render is always made fresh and never cached', async () => {
    const { poller, generateShortFn } = makePoller([job(5, 'tiktok', { test_render: true }), job(6, 'tiktok')]);
    await poller.pollOnce();
    expect(generateShortFn).toHaveBeenCalledTimes(2);
  });
});
