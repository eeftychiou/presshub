import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import {
  generatePhotoSlides,
  fitText,
  wrapLines,
  textSlideSvg,
  titleSlideSvg,
  SLIDE_WIDTH,
  SLIDE_HEIGHT
} from '../src/media/photo-slides';
import { parseTikTokVideoHref, tiktokPostUrl, TIKTOK_SOUND_BUTTON_RE, TIKTOK_PHOTOS_TAB_RE, isOriginalSound, postTikTok, chooseTikTokSound, readRecentTikTokSounds, recordTikTokSound, asTikTokPhotoUrl, TIKTOK_RECENT_SOUNDS } from '../src/automation/posters';
import { PressHubPoller, ClaimedJob } from '../src/poller';

const TEST_DIR = path.resolve(__dirname, 'tmp-photo-slides-test');

function response(status: number, body: any = null): any {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: () => null },
    json: async () => {
      if (body === null) throw new Error('no body');
      return body;
    }
  };
}

afterAll(() => {
  fs.rmSync(TEST_DIR, { recursive: true, force: true });
});

describe('photo slide layout', () => {
  test('wrapLines keeps words whole', () => {
    expect(wrapLines('ένα δύο τρία τέσσερα', 9)).toEqual(['ένα δύο', 'τρία', 'τέσσερα']);
    expect(wrapLines('', 10)).toEqual([]);
  });

  test('fitText shrinks the type until the text fits the box', () => {
    const short = fitText('Λίγες λέξεις', 900, 600, 72, 44);
    expect(short.size).toBe(72);
    const long = fitText(Array(50).fill('λέξη').join(' '), 900, 600, 72, 44);
    expect(long.size).toBeLessThan(72);
    expect(long.lines.length * long.lineHeight).toBeLessThanOrEqual(600);
  });

  test('the SVGs escape the text and show the counter and badge', () => {
    const svg = textSlideSvg('Α & Β <test>', '2/5', true);
    expect(svg).toContain('Α &amp; Β &lt;test&gt;');
    expect(svg).toContain('2/5');
    expect(titleSlideSvg('Hook', 'ΕΙΔΗΣΗ 1/3', 'PressHub.Cy', false)).toContain('ΕΙΔΗΣΗ 1/3');
  });
});

describe('generatePhotoSlides', () => {
  test('title, one slide per text and a closing slide, 1080x1920 JPEG', async () => {
    const img = await sharp({ create: { width: 600, height: 900, channels: 3, background: '#884422' } }).png().toBuffer();
    const res = await generatePhotoSlides({
      hook: 'Τίτλος',
      badge: 'ΕΙΔΗΣΗ 1/2',
      slides: ['Πρώτο κείμενο.', 'Δεύτερο κείμενο.'],
      imageUrls: ['https://x/1.png', 'https://x/broken.png'],
      endCardLine: 'Ολόκληρη η ενημέρωση στο presshub.cy',
      outputDir: TEST_DIR,
      fetchFn: async (u) => (u.endsWith('1.png') ? img : null)
    });
    expect(res.files).toHaveLength(4);
    expect(res.missingImages).toBe(1);
    for (const f of res.files) {
      const meta = await sharp(f).metadata();
      expect(meta.format).toBe('jpeg');
      expect([meta.width, meta.height]).toEqual([SLIDE_WIDTH, SLIDE_HEIGHT]);
    }
  });

  test('never more slides than allowed', async () => {
    const res = await generatePhotoSlides({
      hook: 'H',
      slides: Array(10).fill('Κείμενο.'),
      endCardLine: 'x',
      maxSlides: 4,
      outputDir: TEST_DIR,
      fetchFn: async () => null
    });
    expect(res.files).toHaveLength(4);
  });

  test('no slide text is an error', async () => {
    await expect(generatePhotoSlides({ hook: 'H', slides: ['  '], endCardLine: 'x', outputDir: TEST_DIR })).rejects.toThrow('at least one slide text');
  });
});

describe('TikTok photo post helpers', () => {
  test('photo post links are read and rebuilt', () => {
    const p = parseTikTokVideoHref('/@presshub.cy/photo/7412345678901234567');
    expect(p).toEqual({ handle: 'presshub.cy', videoId: '7412345678901234567', kind: 'photo' });
    expect(tiktokPostUrl(p!)).toBe('https://www.tiktok.com/@presshub.cy/photo/7412345678901234567');
    expect(tiktokPostUrl(parseTikTokVideoHref('https://www.tiktok.com/@a/video/12345678901')!)).toBe('https://www.tiktok.com/@a/video/12345678901');
  });

  test('people\'s "original sound" clips are told from music tracks', () => {
    expect(isOriginalSound('original sound - zlatnim')).toBe(true);
    expect(isOriginalSound('Original Sound - Presshub.cy')).toBe(true);
    expect(isOriginalSound('Paper Moon Static')).toBe(false);
    expect(TIKTOK_PHOTOS_TAB_RE.test('Photos')).toBe(true);
    expect(TIKTOK_PHOTOS_TAB_RE.test('Videos')).toBe(false);
  });

  test('the sound button names', () => {
    expect(TIKTOK_SOUND_BUTTON_RE.test('Add sound')).toBe(true);
    expect(TIKTOK_SOUND_BUTTON_RE.test('Προσθήκη ήχου')).toBe(true);
    expect(TIKTOK_SOUND_BUTTON_RE.test('Post')).toBe(false);
  });

  test('the sound is one of the top tracks, not one used recently', () => {
    const top = ['Moment Of Reflection', 'Calm Piano', 'Soft Hope', 'Quiet Morning', 'Still Water'];
    expect(chooseTikTokSound(top, [], () => 0)).toBe(0);
    expect(chooseTikTokSound(top, [], () => 0.99)).toBe(4);
    expect(chooseTikTokSound(top, ['moment of reflection'], () => 0)).toBe(1);
    expect(chooseTikTokSound(top, ['Calm Piano', 'Moment Of Reflection'], () => 0.5)).toBe(3);
    // All used: the one used longest ago.
    expect(chooseTikTokSound(top, ['Still Water', 'Calm Piano', 'Quiet Morning', 'Moment Of Reflection', 'Soft Hope'], () => 0)).toBe(2);
    expect(chooseTikTokSound([], ['x'])).toBe(-1);
  });

  test('recent sounds are remembered, newest first and capped', () => {
    const dir = path.join(TEST_DIR, `sounds-${Math.random().toString(16).slice(2)}`);
    expect(readRecentTikTokSounds(dir)).toEqual([]);
    recordTikTokSound(dir, 'A');
    recordTikTokSound(dir, 'B');
    recordTikTokSound(dir, 'a');
    expect(readRecentTikTokSounds(dir)).toEqual(['a', 'B']);
    for (let i = 0; i < 20; i++) recordTikTokSound(dir, `S${i}`);
    expect(readRecentTikTokSounds(dir)).toHaveLength(TIKTOK_RECENT_SOUNDS);
    expect(readRecentTikTokSounds(dir)[0]).toBe('S19');
    expect(readRecentTikTokSounds(undefined)).toEqual([]);
  });

  test('a photo post is linked as /photo/', () => {
    expect(asTikTokPhotoUrl('https://www.tiktok.com/@presshub.cy/video/7710864748400643350')).toBe('https://www.tiktok.com/@presshub.cy/photo/7710864748400643350');
    expect(asTikTokPhotoUrl('https://www.tiktok.com/@presshub.cy')).toBe('https://www.tiktok.com/@presshub.cy');
  });

  test('a photo post without images fails before any browser starts', async () => {
    await expect(postTikTok({ platform: 'tiktok', copy: 'x', photoPost: true, mediaPaths: [path.join(TEST_DIR, 'none.jpg')] })).rejects.toThrow('at least one image');
  });
});

describe('Poller with TikTok photo slides', () => {
  const slidesJob: ClaimedJob = {
    id: 90,
    platform: 'tiktok',
    post_id: 913,
    post_title: 'Podcast',
    post_copy: 'Λεζάντα #news',
    tiktok_slides: {
      index: 0,
      count: 3,
      hook: 'Hook one',
      slides: ['Πρώτη πρόταση.'],
      images: ['https://presshub.test/img.png'],
      mood: 'serious',
      sound_query: 'emotional piano',
      max_slides: 10
    }
  };

  function makePoller(postResult: any) {
    const reports: any[] = [];
    const posted: any[] = [];
    const rendered: any[] = [];
    const slideDir = path.join(TEST_DIR, `set-${Math.random().toString(16).slice(2)}`);
    const fetchFn: any = async (url: string, init: any) => {
      if (url.endsWith('/worker/claim')) return response(200, { ok: true, jobs: [slidesJob] });
      if (url.endsWith('/worker/complete')) {
        reports.push(JSON.parse(init.body));
        return response(200, { ok: true });
      }
      return response(404);
    };
    const poller = new PressHubPoller({
      wpUrl: 'https://presshub.test',
      workerSecret: 'S',
      fetchFn,
      outputDir: TEST_DIR,
      sessionsDir: path.join(TEST_DIR, 'sessions'),
      posterHandler: async (o: any) => {
        posted.push(o);
        return postResult;
      },
      generateSlidesFn: async (o: any) => {
        rendered.push(o);
        fs.mkdirSync(slideDir, { recursive: true });
        const files = [1, 2, 3].map((n) => {
          const f = path.join(slideDir, `slide-0${n}.jpg`);
          fs.writeFileSync(f, 'x');
          return f;
        });
        return { files, dir: slideDir, missingImages: 0 };
      },
      generateShortFn: async () => {
        throw new Error('a photo post must not render a video');
      },
      reportSleepFn: async () => {}
    });
    return { poller, reports, posted, rendered, slideDir };
  }

  test('renders the slides and posts them as a photo post with the sound search', async () => {
    const { poller, reports, posted, rendered, slideDir } = makePoller({ success: true, platform: 'tiktok', external_url: 'https://www.tiktok.com/@a/photo/12345678901', external_id: '12345678901' });
    const stats = await poller.pollOnce();
    expect(stats).toEqual({ claimed: 1, succeeded: 1, failed: 0 });
    expect(rendered[0]).toMatchObject({ hook: 'Hook one', badge: 'ΕΙΔΗΣΗ 1/3', slides: ['Πρώτη πρόταση.'], imageUrls: ['https://presshub.test/img.png'], maxSlides: 10 });
    expect(rendered[0].endCardLine).toContain('presshub.test');
    expect(posted[0]).toMatchObject({ platform: 'tiktok', copy: 'Λεζάντα #news', photoPost: true, soundQuery: 'emotional piano', aiGenerated: true, stateDir: path.resolve(TEST_DIR) });
    expect(posted[0].mediaPaths).toHaveLength(3);
    expect(reports[0]).toMatchObject({ job_id: 90, status: 'published', external_post_url: 'https://www.tiktok.com/@a/photo/12345678901' });
    expect(fs.existsSync(slideDir)).toBe(false);
  });

  test('a post that went out without its sound reports the note', async () => {
    const { poller, reports } = makePoller({ success: true, platform: 'tiktok', external_url: 'u', note: 'Posted with the sound TikTok chose: no sound found' });
    await poller.pollOnce();
    expect(reports[0].metadata).toEqual({ tiktok_slides_note: 'Posted with the sound TikTok chose: no sound found' });
  });

  test('a failed photo post is reported failed', async () => {
    const { poller, reports } = makePoller({ success: false, platform: 'tiktok', error: 'TikTok session invalid' });
    const stats = await poller.pollOnce();
    expect(stats.failed).toBe(1);
    expect(reports[0]).toMatchObject({ job_id: 90, status: 'failed', error: 'TikTok session invalid' });
  });
});
