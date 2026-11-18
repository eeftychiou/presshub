import fs from 'fs';
import path from 'path';
import {
  uploadToYouTube,
  buildVideoResource,
  nextOffsetFromRange,
  fetchYouTubeToken,
  YouTubeUploadMeta
} from '../src/youtube/uploader';
import { PressHubPoller, ClaimedJob } from '../src/poller';

const TEST_DIR = path.resolve(__dirname, 'tmp-youtube-test');
const CHUNK = 256 * 1024;

type Call = { url: string; init: any };

function response(status: number, body: any = null, headers: Record<string, string> = {}): any {
  const h = new Map(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v]));
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (k: string) => h.get(k.toLowerCase()) ?? null },
    json: async () => {
      if (body === null) throw new Error('no body');
      return body;
    }
  };
}

function videoFile(bytes: number): string {
  fs.mkdirSync(TEST_DIR, { recursive: true });
  const file = path.join(TEST_DIR, `video-${bytes}-${Math.random().toString(16).slice(2)}.mp4`);
  fs.writeFileSync(file, Buffer.alloc(bytes, 7));
  return file;
}

const episodeMeta: YouTubeUploadMeta = {
  kind: 'episode',
  title: 'Πρωινό podcast',
  description: 'Περιγραφή\n\nhttps://presshub.test/?p=1',
  tags: ['κύπρος'],
  category_id: '25',
  privacy_status: 'private',
  contains_synthetic_media: true,
  made_for_kids: false,
  default_language: 'el',
  playlist_id: 'PLpodcast0001'
};

afterAll(() => {
  try {
    fs.rmSync(TEST_DIR, { recursive: true, force: true });
  } catch {}
});

describe('YouTube uploader', () => {
  test('buildVideoResource maps the job metadata to snippet and status', () => {
    const r = buildVideoResource(episodeMeta);
    expect(r.snippet).toEqual({
      title: 'Πρωινό podcast',
      description: 'Περιγραφή\n\nhttps://presshub.test/?p=1',
      categoryId: '25',
      tags: ['κύπρος'],
      defaultLanguage: 'el'
    });
    expect(r.status).toEqual({
      privacyStatus: 'private',
      selfDeclaredMadeForKids: false,
      containsSyntheticMedia: true,
      embeddable: true
    });
  });

  test('nextOffsetFromRange reads the last received byte', () => {
    expect(nextOffsetFromRange('bytes=0-262143')).toBe(262144);
    expect(nextOffsetFromRange(null)).toBe(0);
  });

  test('fetchYouTubeToken asks WordPress with the worker secret', async () => {
    const calls: Call[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      calls.push({ url, init });
      return response(200, { ok: true, access_token: 'ya29.T' });
    };
    await expect(fetchYouTubeToken('https://presshub.test/', 'S3CRET', fetchFn)).resolves.toBe('ya29.T');
    expect(calls[0].url).toBe('https://presshub.test/wp-json/presshub-social/v1/worker/youtube/token');
    expect(calls[0].init.headers['X-PressHub-Secret']).toBe('S3CRET');
  });

  test('fetchYouTubeToken fails with the site message', async () => {
    const fetchFn: any = async () => response(409, { code: 'youtube_invalid_grant', message: 'Connect YouTube again.' });
    await expect(fetchYouTubeToken('https://presshub.test', 'S', fetchFn)).rejects.toThrow('Connect YouTube again.');
  });

  test('uploads in chunks, then adds an episode to the playlist', async () => {
    const file = videoFile(CHUNK * 2 + 1000);
    const calls: Call[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      calls.push({ url, init });
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'ya29.T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/session1' });
      if (url === 'https://upload.test/session1') {
        const range = String(init.headers['Content-Range']);
        const end = Number(/bytes \d+-(\d+)\//.exec(range)![1]);
        if (end + 1 < CHUNK * 2 + 1000) return response(308, null, { Range: `bytes=0-${end}` });
        return response(200, { id: 'VID123' });
      }
      if (url.includes('/playlistItems')) return response(200, { id: 'PLI1' });
      return response(404);
    };
    const res = await uploadToYouTube({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn, chunkSize: CHUNK });
    expect(res).toEqual({ success: true, videoId: 'VID123', url: 'https://www.youtube.com/watch?v=VID123', playlistItemId: 'PLI1' });

    const init = calls.find((c) => c.url.includes('uploadType=resumable'))!;
    expect(init.init.headers.Authorization).toBe('Bearer ya29.T');
    expect(init.init.headers['X-Upload-Content-Length']).toBe(String(CHUNK * 2 + 1000));
    expect(JSON.parse(init.init.body).status.containsSyntheticMedia).toBe(true);

    const puts = calls.filter((c) => c.url === 'https://upload.test/session1');
    expect(puts.map((c) => c.init.headers['Content-Range'])).toEqual([
      `bytes 0-${CHUNK - 1}/${CHUNK * 2 + 1000}`,
      `bytes ${CHUNK}-${CHUNK * 2 - 1}/${CHUNK * 2 + 1000}`,
      `bytes ${CHUNK * 2}-${CHUNK * 2 + 999}/${CHUNK * 2 + 1000}`
    ]);
    const pl = calls.find((c) => c.url.includes('/playlistItems'))!;
    expect(JSON.parse(pl.init.body).snippet).toEqual({ playlistId: 'PLpodcast0001', resourceId: { kind: 'youtube#video', videoId: 'VID123' } });
  });

  test('a Short gets a /shorts/ link and no playlist call', async () => {
    const file = videoFile(1000);
    const calls: Call[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      calls.push({ url, init });
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'ya29.T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      return response(201, { id: 'SHORT1' });
    };
    const res = await uploadToYouTube({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 'Hook', description: 'd', playlist_id: 'PLignored00' }, fetchFn });
    expect(res.url).toBe('https://www.youtube.com/shorts/SHORT1');
    expect(calls.some((c) => c.url.includes('/playlistItems'))).toBe(false);
  });

  test('a failed chunk resumes from what YouTube received', async () => {
    const total = CHUNK + 500;
    const file = videoFile(total);
    let failedOnce = false;
    const puts: string[] = [];
    const sleeps: number[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'ya29.T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      const range = String(init.headers['Content-Range']);
      puts.push(range);
      if (range === `bytes */${total}`) return response(308, null, { Range: `bytes=0-${CHUNK - 1}` });
      if (range.startsWith(`bytes ${CHUNK}-`) && !failedOnce) {
        failedOnce = true;
        return response(503);
      }
      if (range.startsWith('bytes 0-')) return response(308, null, { Range: `bytes=0-${CHUNK - 1}` });
      return response(200, { id: 'V2' });
    };
    const res = await uploadToYouTube({ wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 't', description: '' }, fetchFn, chunkSize: CHUNK, sleepFn: async (ms) => { sleeps.push(ms); } });
    expect(res.success).toBe(true);
    expect(puts).toEqual([`bytes 0-${CHUNK - 1}/${total}`, `bytes ${CHUNK}-${total - 1}/${total}`, `bytes */${total}`, `bytes ${CHUNK}-${total - 1}/${total}`]);
    expect(sleeps).toEqual([2000]);
  });

  test('a quota error is reported in plain words and not retried', async () => {
    const file = videoFile(1000);
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'ya29.T' });
      return response(403, { error: { message: 'The request cannot be completed because you have exceeded your quota.', errors: [{ reason: 'quotaExceeded' }] } });
    };
    const res = await uploadToYouTube({ wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 't', description: '' }, fetchFn });
    expect(res.success).toBe(false);
    expect(res.error).toContain('upload limit reached for today');
  });

  test('a playlist failure keeps the upload a success', async () => {
    const file = videoFile(1000);
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'ya29.T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (url.includes('/playlistItems')) return response(404, { error: { message: 'Playlist not found.', errors: [{ reason: 'playlistNotFound' }] } });
      return response(200, { id: 'V3' });
    };
    const res = await uploadToYouTube({ wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn });
    expect(res.success).toBe(true);
    expect(res.playlistError).toBe('Playlist not found. (playlistNotFound)');
  });
});

describe('Poller: YouTube jobs', () => {
  function makePoller(job: ClaimedJob, overrides: Record<string, any> = {}) {
    const reports: any[] = [];
    const uploads: any[] = [];
    const posted: any[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.endsWith('/worker/claim')) return response(200, { ok: true, jobs: [job] });
      if (url.endsWith('/worker/complete')) {
        reports.push(JSON.parse(init.body));
        return response(200, { ok: true });
      }
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
        return { success: true, platform: o.platform } as any;
      },
      generateShortFn: async () => ({ filePath: rendered }) as any,
      generateVideoFn: async (p: any) => {
        overrides.videoParams = p;
        return { filePath: rendered, width: p.width, height: p.height, duration: 600 };
      },
      youtubeUploadFn: async (o: any) => {
        uploads.push(o);
        return overrides.uploadResult || { success: true, videoId: 'VID', url: 'https://www.youtube.com/watch?v=VID', playlistItemId: 'PLI' };
      }
    });
    return { poller, reports, uploads, posted, overrides, rendered };
  }

  test('reports YouTube as available in its sessions', () => {
    const { poller } = makePoller({ id: 1, platform: 'youtube', post_copy: '' });
    expect(poller.getSessionStatuses().youtube).toBe(true);
  });

  test('an episode is rendered 16:9 and uploaded, never sent to a browser poster', async () => {
    const job: ClaimedJob = {
      id: 41,
      platform: 'youtube',
      post_id: 880,
      post_title: 'Πρωινό Podcast',
      post_copy: 'Περιγραφή',
      audio_url: 'https://presshub.test/podcast.mp3',
      media_url: 'https://presshub.test/cover.jpg',
      youtube: { ...episodeMeta, width: 1920, height: 1080 }
    };
    const { poller, reports, uploads, posted, overrides, rendered } = makePoller(job);
    const stats = await poller.pollOnce();
    expect(stats).toEqual({ claimed: 1, succeeded: 1, failed: 0 });
    expect(posted).toHaveLength(0);
    expect(overrides.videoParams).toMatchObject({ width: 1920, height: 1080, audioUrl: 'https://presshub.test/podcast.mp3', isPodcast: true });
    expect(uploads[0]).toMatchObject({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: rendered, meta: job.youtube });
    expect(reports[0]).toMatchObject({ job_id: 41, status: 'published', external_post_id: 'VID', external_post_url: 'https://www.youtube.com/watch?v=VID', metadata: { youtube_kind: 'episode', youtube_playlist_item_id: 'PLI' } });
    expect(fs.existsSync(rendered)).toBe(false);
  });

  test('a YouTube short is rendered like the TikTok short and uploaded', async () => {
    const job: ClaimedJob = {
      id: 42,
      platform: 'youtube',
      post_id: 890,
      post_copy: 'Λεζάντα',
      audio_url: 'https://presshub.test/short.wav',
      video_title: 'Hook',
      tiktok_short: { index: 0, count: 1, segments: [], images: [] },
      youtube: { kind: 'short', title: 'Hook', description: 'Λεζάντα' }
    };
    const { poller, reports, uploads, posted } = makePoller(job);
    await poller.pollOnce();
    expect(posted).toHaveLength(0);
    expect(uploads[0].meta.kind).toBe('short');
    expect(reports[0].status).toBe('published');
  });

  test('a failed upload is reported as failed with the reason', async () => {
    const job: ClaimedJob = { id: 43, platform: 'youtube', post_copy: '', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta } };
    const { poller, reports, overrides } = makePoller(job);
    overrides.uploadResult = { success: false, error: 'YouTube upload limit reached for today' };
    const stats = await poller.pollOnce();
    expect(stats.failed).toBe(1);
    expect(reports[0]).toMatchObject({ job_id: 43, status: 'failed', error: 'YouTube upload limit reached for today' });
  });

  test('a YouTube job without upload details fails', async () => {
    const job: ClaimedJob = { id: 44, platform: 'youtube', post_copy: '', audio_url: 'https://presshub.test/p.mp3' };
    const { poller, reports } = makePoller(job);
    await poller.pollOnce();
    expect(reports[0].status).toBe('failed');
  });
});
