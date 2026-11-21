import fs from 'fs';
import path from 'path';
import { uploadFacebookReel, fetchFacebookPageToken, reelRefusal, reelPublished } from '../src/facebook/reels-uploader';
import { recordUpload } from '../src/youtube/upload-log';
import { PressHubPoller, ClaimedJob } from '../src/poller';

const TEST_DIR = path.resolve(__dirname, 'tmp-facebook-reels-test');

type Call = { url: string; init: any };

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

function videoFile(bytes: number): string {
  fs.mkdirSync(TEST_DIR, { recursive: true });
  const file = path.join(TEST_DIR, `reel-${bytes}-${Math.random().toString(16).slice(2)}.mp4`);
  fs.writeFileSync(file, Buffer.alloc(bytes, 7));
  return file;
}

const TOKEN_BODY = { ok: true, page_id: '1234', access_token: 'PAGE-TOKEN', graph_version: 'v21.0' };

/** A Graph API fake: answers each step, records every call. */
function graphFake(overrides: { token?: any; start?: any; upload?: any; finish?: any; statuses?: any[] } = {}) {
  const calls: Call[] = [];
  const statuses = overrides.statuses ? [...overrides.statuses] : [{ video_status: 'ready', publishing_phase: { status: 'complete' } }];
  const fetchFn: any = async (url: string, init: any) => {
    calls.push({ url, init });
    if (url.endsWith('/worker/facebook/token')) return overrides.token || response(200, TOKEN_BODY);
    if (url === 'https://graph.facebook.com/v21.0/1234/video_reels') {
      const params = new URLSearchParams(init.body);
      if (params.get('upload_phase') === 'start') return overrides.start || response(200, { video_id: 'VID9', upload_url: 'https://rupload.facebook.com/video-upload/v21.0/VID9' });
      if (params.get('upload_phase') === 'finish') return overrides.finish || response(200, { success: true });
    }
    if (url.startsWith('https://rupload.facebook.com/')) return overrides.upload || response(200, { success: true });
    if (url.startsWith('https://graph.facebook.com/v21.0/VID9?fields=status')) {
      return response(200, { status: statuses.length > 1 ? statuses.shift() : statuses[0] });
    }
    return response(404);
  };
  return { fetchFn, calls };
}

afterAll(() => {
  fs.rmSync(TEST_DIR, { recursive: true, force: true });
});

describe('Facebook Reels uploader', () => {
  test('asks WordPress for the Page token with the worker secret', async () => {
    const { fetchFn, calls } = graphFake();
    const tok = await fetchFacebookPageToken('https://presshub.test/', 'S', fetchFn);
    expect(tok).toEqual({ pageId: '1234', accessToken: 'PAGE-TOKEN', graphVersion: 'v21.0' });
    expect(calls[0].url).toBe('https://presshub.test/wp-json/presshub-social/v1/worker/facebook/token');
    expect(calls[0].init.headers['X-PressHub-Secret']).toBe('S');
  });

  test('start, upload, publish with the description, then reads the status', async () => {
    const file = videoFile(3000);
    const { fetchFn, calls } = graphFake();
    const published: string[] = [];
    const res = await uploadFacebookReel({
      wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, description: 'Λεζάντα #news',
      fetchFn, sleepFn: async () => {}, statusCheckDelayMs: 0, onPublished: (id) => published.push(id)
    });
    expect(res).toEqual({ success: true, videoId: 'VID9', url: 'https://www.facebook.com/reel/VID9' });
    expect(published).toEqual(['VID9']);
    const up = calls.find((c) => c.url.startsWith('https://rupload.facebook.com/'))!;
    expect(up.init.headers).toMatchObject({ Authorization: 'OAuth PAGE-TOKEN', offset: '0', file_size: '3000' });
    expect((up.init.body as Buffer).length).toBe(3000);
    const finish = calls.map((c) => c.init?.body).filter((b) => typeof b === 'string' && b.includes('upload_phase=finish'))[0];
    const params = new URLSearchParams(finish);
    expect(params.get('video_id')).toBe('VID9');
    expect(params.get('video_state')).toBe('PUBLISHED');
    expect(params.get('description')).toBe('Λεζάντα #news');
    expect(params.get('access_token')).toBe('PAGE-TOKEN');
  });

  test('a Reel Facebook refuses while processing is a failure', async () => {
    const file = videoFile(1000);
    const { fetchFn } = graphFake({ statuses: [{ video_status: 'processing', processing_phase: { status: 'error', errors: [{ message: 'Bad aspect ratio' }] } }] });
    const res = await uploadFacebookReel({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, description: 'x', fetchFn, sleepFn: async () => {}, statusCheckDelayMs: 0 });
    expect(res.success).toBe(false);
    expect(res.reason).toBe('refused');
    expect(res.videoId).toBe('VID9');
    expect(res.error).toContain('Bad aspect ratio');
  });

  test('still processing after the checks is published, with a note', async () => {
    const file = videoFile(1000);
    const { fetchFn } = graphFake({ statuses: [{ video_status: 'processing', processing_phase: { status: 'in_progress' } }] });
    const res = await uploadFacebookReel({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, description: 'x', fetchFn, sleepFn: async () => {}, statusCheckDelayMs: 0 });
    expect(res.success).toBe(true);
    expect(res.note).toContain('still processing');
  });

  test('a missing permission is an auth failure with the Graph message', async () => {
    const file = videoFile(1000);
    const { fetchFn } = graphFake({ start: response(403, { error: { message: '(#200) Requires pages_manage_posts', code: 200 } }) });
    const res = await uploadFacebookReel({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, description: 'x', fetchFn, sleepFn: async () => {}, statusCheckDelayMs: 0 });
    expect(res).toMatchObject({ success: false, reason: 'auth' });
    expect(res.error).toContain('pages_manage_posts');
  });

  test('no token from WordPress is an auth failure', async () => {
    const file = videoFile(1000);
    const { fetchFn } = graphFake({ token: response(409, { code: 'facebook_not_configured', message: 'The Meta Page ID or access token is not set.' }) });
    const res = await uploadFacebookReel({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, description: 'x', fetchFn });
    expect(res).toMatchObject({ success: false, reason: 'auth' });
    expect(res.error).toContain('not set');
  });

  test('a missing file fails before any call', async () => {
    const { fetchFn, calls } = graphFake();
    const res = await uploadFacebookReel({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: path.join(TEST_DIR, 'nope.mp4'), description: 'x', fetchFn });
    expect(res.success).toBe(false);
    expect(calls).toHaveLength(0);
  });

  test('status helpers', () => {
    expect(reelRefusal({ video_status: 'error' })).toContain('video error');
    expect(reelRefusal({ video_status: 'ready', publishing_phase: { status: 'complete' } })).toBe('');
    expect(reelPublished({ publishing_phase: { status: 'complete' } })).toBe(true);
    expect(reelPublished({ publishing_phase: { status: 'not_started' } })).toBe(false);
  });
});

describe('Poller with Facebook Reels through the API', () => {
  function makePoller(job: ClaimedJob, uploadResult?: any) {
    const reports: any[] = [];
    const uploads: any[] = [];
    const posted: any[] = [];
    const claims: any[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.endsWith('/worker/claim')) {
        claims.push(JSON.parse(init.body));
        return response(200, { ok: true, jobs: [job] });
      }
      if (url.endsWith('/worker/complete')) {
        reports.push(JSON.parse(init.body));
        return response(200, { ok: true });
      }
      if (url.startsWith('https://presshub.test/reel.wav')) return response(200, {});
      return response(404);
    };
    const rendered = videoFile(2000);
    const poller = new PressHubPoller({
      wpUrl: 'https://presshub.test',
      workerSecret: 'S',
      fetchFn,
      outputDir: TEST_DIR,
      sessionsDir: path.join(TEST_DIR, 'sessions'),
      posterHandler: async (o: any) => {
        posted.push(o);
        return { success: true, platform: o.platform, external_url: 'https://www.facebook.com/reel/BROWSER' } as any;
      },
      generateShortFn: async () => ({ filePath: rendered, duration: 40 } as any),
      facebookReelUploadFn: async (o: any) => {
        uploads.push(o);
        if (uploadResult) return uploadResult;
        o.onPublished?.('VID9', 'https://www.facebook.com/reel/VID9');
        return { success: true, videoId: 'VID9', url: 'https://www.facebook.com/reel/VID9' };
      },
      reportSleepFn: async () => {}
    });
    return { poller, reports, uploads, posted, claims, rendered };
  }

  const reelJob = (id: number, mode: 'api' | 'browser'): ClaimedJob => ({
    id,
    platform: 'facebook',
    post_id: 912,
    post_title: 'Podcast',
    post_copy: 'Λεζάντα Reel #news',
    audio_url: 'https://presshub.test/reel.wav',
    tiktok_short: { index: 0, count: 1, hook: 'Hook', images: [], segments: [] },
    facebook_reel: { mode }
  });

  test('the claim says the worker can post Reels through the API', async () => {
    const { poller, claims } = makePoller(reelJob(70, 'api'));
    await poller.pollOnce();
    expect(claims[0].capabilities).toEqual(['facebook_reels_api']);
  });

  test('mode api uploads through the Graph API, never the browser, and removes the video', async () => {
    const { poller, reports, uploads, posted, rendered } = makePoller(reelJob(71, 'api'));
    const stats = await poller.pollOnce();
    expect(stats).toEqual({ claimed: 1, succeeded: 1, failed: 0 });
    expect(posted).toHaveLength(0);
    expect(uploads[0]).toMatchObject({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: rendered, description: 'Λεζάντα Reel #news' });
    expect(reports[0]).toMatchObject({ job_id: 71, status: 'published', external_post_id: 'VID9', external_post_url: 'https://www.facebook.com/reel/VID9', metadata: { facebook_reel_mode: 'api' } });
    expect(fs.existsSync(rendered)).toBe(false);
  });

  test('a Reel already published on an earlier claim is reported, not uploaded again', async () => {
    recordUpload(TEST_DIR, 'https://presshub.test', 72, { videoId: 'OLD1', url: 'https://www.facebook.com/reel/OLD1', kind: 'facebook_reel', postId: '912', uploadedAt: new Date().toISOString() });
    const { poller, reports, uploads } = makePoller(reelJob(72, 'api'));
    await poller.pollOnce();
    expect(uploads).toHaveLength(0);
    expect(reports[0]).toMatchObject({ job_id: 72, status: 'published', external_post_id: 'OLD1' });
  });

  test('a failed upload is reported failed with its reason', async () => {
    const { poller, reports } = makePoller(reelJob(73, 'api'), { success: false, reason: 'auth', error: 'Facebook Reel start failed: (#200) Requires pages_manage_posts' });
    const stats = await poller.pollOnce();
    expect(stats.failed).toBe(1);
    expect(reports[0]).toMatchObject({ job_id: 73, status: 'failed', metadata: { facebook_reel_mode: 'api', facebook_reel_error_reason: 'auth' } });
    expect(reports[0].error).toContain('pages_manage_posts');
  });

  test('mode browser still posts with the browser session', async () => {
    const { poller, uploads, posted } = makePoller(reelJob(74, 'browser'));
    await poller.pollOnce();
    expect(uploads).toHaveLength(0);
    expect(posted).toHaveLength(1);
    expect(posted[0].platform).toBe('facebook');
  });
});
