import fs from 'fs';
import path from 'path';
import {
  uploadToYouTube,
  buildVideoResource,
  nextOffsetFromRange,
  fetchYouTubeToken,
  stopReason,
  YouTubeUploadMeta
} from '../src/youtube/uploader';
import { findUpload, recordUpload } from '../src/youtube/upload-log';
import { isRateLimited, uploadRefusal } from '../src/youtube/uploader';
import { siteEndCardLine, buildAssScript } from '../src/media/short-video';
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
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn, chunkSize: CHUNK });
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
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 'Hook', description: 'd', playlist_id: 'PLignored00' }, fetchFn });
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
      if (url.includes('/youtube/v3/videos?part=status')) return response(200, { items: [{ status: { uploadStatus: 'uploaded' } }] });
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
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 't', description: '' }, fetchFn, chunkSize: CHUNK, sleepFn: async (ms) => { sleeps.push(ms); } });
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
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: { kind: 'short', title: 't', description: '' }, fetchFn });
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
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn });
    expect(res.success).toBe(true);
    expect(res.playlistError).toBe('Playlist not found. (playlistNotFound)');
  });

  const shortMeta: YouTubeUploadMeta = { kind: 'short', title: 't', description: '' };
  const noSleep = async () => {};

  test('a 4xx on a chunk is a refusal: no retry', async () => {
    const file = videoFile(1000);
    let puts = 0;
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      puts++;
      return response(400, { error: { message: 'Bad video.', errors: [{ reason: 'invalidVideo' }] } });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, sleepFn: noSleep });
    expect(res).toMatchObject({ success: false, error: 'YouTube refused the upload: Bad video. (invalidVideo)' });
    expect(res.reason).toBeUndefined();
    expect(puts).toBe(1);
  });

  test('408 and 429 on a chunk are retried', async () => {
    const file = videoFile(1000);
    const codes = [408, 429];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (String(init.headers['Content-Range']).startsWith('bytes */')) return response(308, null, {});
      const code = codes.shift();
      return code ? response(code) : response(200, { id: 'OK1' });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, sleepFn: noSleep });
    expect(res).toMatchObject({ success: true, videoId: 'OK1' });
  });

  test('a chunk that keeps failing ends after the set tries', async () => {
    const file = videoFile(1000);
    const sleeps: number[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (String(init.headers['Content-Range']).startsWith('bytes */')) return response(308, null, {});
      return response(503);
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, maxChunkTries: 3, retryDelayMs: 100, sleepFn: async (ms) => { sleeps.push(ms); } });
    expect(res.success).toBe(false);
    expect(res.error).toContain('after 3 tries at byte 0 of 1000: HTTP 503');
    expect(sleeps).toEqual([100, 200]);
  });

  test('a 308 that moves nothing forward counts as a failed try instead of looping', async () => {
    const file = videoFile(CHUNK + 10);
    let puts = 0;
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      puts++;
      return response(308, null, {}); // no Range header: nothing received
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, chunkSize: CHUNK, maxChunkTries: 2, sleepFn: noSleep });
    expect(res.success).toBe(false);
    expect(res.error).toContain('HTTP 308 without progress at byte 0');
    expect(puts).toBeLessThan(10);
  });

  test('starting the session retries a network error and a 5xx', async () => {
    const file = videoFile(1000);
    const init = ['throw', 500];
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) {
        const next = init.shift();
        if (next === 'throw') throw new Error('socket hang up');
        if (next === 500) return response(500);
        return response(200, {}, { Location: 'https://upload.test/s' });
      }
      return response(200, { id: 'V9' });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, sleepFn: noSleep });
    expect(res).toMatchObject({ success: true, videoId: 'V9' });
  });

  test('a session that cannot be started fails as a result, not an exception', async () => {
    const file = videoFile(1000);
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      throw new Error('ECONNRESET');
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, maxChunkTries: 2, sleepFn: noSleep });
    expect(res).toMatchObject({ success: false });
    expect(res.error).toContain('could not be started after 2 tries: ECONNRESET');
  });

  test('a session without a Location header fails', async () => {
    const file = videoFile(1000);
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      return response(200, {});
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn });
    expect(res).toEqual({ success: false, error: 'YouTube returned no upload session address.' });
  });

  test('quota and sign-in refusals carry a reason so WordPress pauses YouTube', async () => {
    expect(stopReason(403, { error: { errors: [{ reason: 'quotaExceeded' }] } })).toBe('quota');
    expect(stopReason(400, { error: { errors: [{ reason: 'uploadLimitExceeded' }] } })).toBe('quota');
    expect(stopReason(401, { error: { errors: [{ reason: 'authError' }] } })).toBe('auth');
    expect(stopReason(400, { error: { errors: [{ reason: 'invalidTitle' }] } })).toBeUndefined();

    const file = videoFile(1000);
    const limit: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      return response(400, { error: { message: 'Upload limit.', errors: [{ reason: 'uploadLimitExceeded' }] } });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn: limit });
    expect(res).toMatchObject({ success: false, reason: 'quota' });

    const refused: any = async () => response(409, { code: 'youtube_invalid_grant', message: 'Google refused.' });
    const res2 = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn: refused });
    expect(res2).toMatchObject({ success: false, reason: 'auth' });
  });

  test('the playlist insert asks WordPress for the token again', async () => {
    const file = videoFile(1000);
    const tokens = ['FIRST', 'SECOND'];
    let playlistAuth = '';
    const fetchFn: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: tokens.shift() });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (url.includes('/playlistItems')) {
        playlistAuth = init.headers.Authorization;
        return response(200, { id: 'PLI' });
      }
      return response(200, { id: 'V4' });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn });
    expect(res.playlistItemId).toBe('PLI');
    expect(playlistAuth).toBe('Bearer SECOND');
  });

  test('a 401 on the upload session gets one fresh token before it counts as a refused sign-in', async () => {
    const file = videoFile(1000);
    const tokenBodies: string[] = [];
    let inits = 0;
    const fetchFn: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) {
        tokenBodies.push(init.body);
        return response(200, { access_token: tokenBodies.length === 1 ? 'OLD' : 'NEW' });
      }
      if (url.includes('uploadType=resumable')) {
        inits++;
        return init.headers.Authorization === 'Bearer NEW'
          ? response(200, {}, { Location: 'https://upload.test/s' })
          : response(401, { error: { message: 'Invalid Credentials', errors: [{ reason: 'authError' }] } });
      }
      return response(200, { id: 'V401' });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, sleepFn: async () => {} });
    expect(res).toMatchObject({ success: true, videoId: 'V401' });
    // The fresh token, then one more ask before the status check (WordPress returns the cached one).
    expect(tokenBodies).toEqual(['{}', '{"refresh":true}', '{}']);
    expect(inits).toBe(2);

    // Still refused with the new token: now it is a sign-in problem.
    const always401: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      return response(401, { error: { message: 'Invalid Credentials', errors: [{ reason: 'authError' }] } });
    };
    const res2 = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn: always401, sleepFn: async () => {} });
    expect(res2).toMatchObject({ success: false, reason: 'auth' });
  });

  test('an upload YouTube refuses after it finished is a failure, not a publish', async () => {
    expect(uploadRefusal({ uploadStatus: 'rejected', rejectionReason: 'length' })).toBe('rejected: length');
    expect(uploadRefusal({ uploadStatus: 'failed', failureReason: 'codec' })).toBe('failed: codec');
    expect(uploadRefusal({ uploadStatus: 'processed' })).toBe('');
    const file = videoFile(1000);
    const noted: string[] = [];
    let playlist = false;
    const mk = (finalStatus: any, readBack: any): any => async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (url.includes('/youtube/v3/videos?part=status')) return response(200, { items: [{ status: readBack }] });
      if (url.includes('/playlistItems')) {
        playlist = true;
        return response(200, { id: 'PLI' });
      }
      return response(200, { id: 'VLONG', status: finalStatus });
    };
    const onUploaded = (id: string) => noted.push(id);
    const rejectedNow = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn: mk({ uploadStatus: 'rejected', rejectionReason: 'length' }, {}), onUploaded });
    expect(rejectedNow).toMatchObject({ success: false, reason: 'refused', videoId: 'VLONG' });
    expect(rejectedNow.error).toContain('rejected: length');
    const rejectedLater = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn: mk({ uploadStatus: 'uploaded' }, { uploadStatus: 'rejected', rejectionReason: 'length' }), onUploaded });
    expect(rejectedLater.success).toBe(false);
    expect(noted).toEqual([]);
    expect(playlist).toBe(false);
    const ok = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn: mk({ uploadStatus: 'uploaded' }, { uploadStatus: 'uploaded' }), onUploaded });
    expect(ok).toMatchObject({ success: true, videoId: 'VLONG', playlistItemId: 'PLI' });
    expect(noted).toEqual(['VLONG']);

    // While YouTube still says "uploaded" the status is read again with growing waits,
    // and a refusal on a later read still stops the publish.
    let reads = 0;
    const waits: number[] = [];
    const polled: any = async (url: string) => {
      if (url.includes('/youtube/v3/videos?part=status')) {
        reads++;
        return response(200, { items: [{ status: reads < 3 ? { uploadStatus: 'uploaded' } : { uploadStatus: 'rejected', rejectionReason: 'length' } }] });
      }
      return mk({ uploadStatus: 'uploaded' }, {})(url);
    };
    const late = await uploadToYouTube({ statusCheckDelayMs: 10, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn: polled, sleepFn: async (ms: number) => { waits.push(ms); } });
    expect(late).toMatchObject({ success: false, reason: 'refused' });
    expect(reads).toBe(3);
    expect(waits.slice(-3)).toEqual([10, 20, 40]);

    // A failed read (a 401, then a 5xx) is not the end: a fresh token, and the next read finds the refusal.
    let tries = 0;
    const tokens: string[] = [];
    const flaky: any = async (url: string, init: any) => {
      if (url.includes('/worker/youtube/token')) {
        tokens.push(init.body);
        return response(200, { access_token: 'T' });
      }
      if (url.includes('/youtube/v3/videos?part=status')) {
        tries++;
        if (tries === 1) return response(401, { error: { message: 'Invalid Credentials' } });
        if (tries === 2) return response(503, { error: { message: 'Backend Error' } });
        return response(200, { items: [{ status: { uploadStatus: 'rejected', rejectionReason: 'length' } }] });
      }
      return mk({ uploadStatus: 'uploaded' }, {})(url);
    };
    const flakyRes = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn: flaky });
    expect(flakyRes).toMatchObject({ success: false, reason: 'refused' });
    expect(tries).toBe(3);
    expect(tokens).toContain('{"refresh":true}');
  });

  test('rateLimitExceeded is a short wait and another try, not the daily quota', async () => {
    expect(stopReason(403, { error: { errors: [{ reason: 'rateLimitExceeded' }] } })).toBeUndefined();
    expect(isRateLimited(403, { error: { errors: [{ reason: 'rateLimitExceeded' }] } })).toBe(true);
    expect(isRateLimited(403, { error: { errors: [{ reason: 'quotaExceeded' }] } })).toBe(false);
    const file = videoFile(1000);
    let inits = 0;
    const waits: number[] = [];
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) {
        inits++;
        return inits === 1
          ? response(403, { error: { message: 'Rate limit', errors: [{ reason: 'rateLimitExceeded' }] } })
          : response(200, {}, { Location: 'https://upload.test/s' });
      }
      return response(200, { id: 'VRL' });
    };
    const res = await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn, sleepFn: async (ms) => { waits.push(ms); } });
    expect(res).toMatchObject({ success: true, videoId: 'VRL' });
    expect(inits).toBe(2);
    expect(waits).toHaveLength(1);
  });

  test('onUploaded is called as soon as YouTube has the video, before the playlist insert', async () => {
    const file = videoFile(1000);
    const order: string[] = [];
    const fetchFn: any = async (url: string) => {
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      if (url.includes('/playlistItems')) {
        order.push('playlist');
        return response(200, { id: 'PLI' });
      }
      return response(200, { id: 'VUP' });
    };
    await uploadToYouTube({ statusCheckDelayMs: 0,
      wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: episodeMeta, fetchFn,
      onUploaded: (id, url) => order.push(`uploaded:${id}:${url}`)
    });
    expect(order).toEqual(['uploaded:VUP:https://www.youtube.com/watch?v=VUP', 'playlist']);
  });

  test('every request has a timeout signal', async () => {
    const file = videoFile(1000);
    const signals: boolean[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      signals.push(Boolean(init && init.signal));
      if (url.includes('/worker/youtube/token')) return response(200, { access_token: 'T' });
      if (url.includes('uploadType=resumable')) return response(200, {}, { Location: 'https://upload.test/s' });
      return response(200, { id: 'V5' });
    };
    await uploadToYouTube({ statusCheckDelayMs: 0, wpUrl: 'https://w.test', workerSecret: 'S', filePath: file, meta: shortMeta, fetchFn });
    expect(signals.length).toBeGreaterThanOrEqual(3);
    expect(signals.every(Boolean)).toBe(true);
  });
});

describe('YouTube upload record and end card', () => {
  test('a recorded upload is found again for the same site and job only', () => {
    const dir = path.join(TEST_DIR, 'log');
    recordUpload(dir, 'https://a.test/', 7, { videoId: 'V7', url: 'u', kind: 'short', uploadedAt: new Date().toISOString() });
    expect(findUpload(dir, 'https://a.test', 7)?.videoId).toBe('V7');
    expect(findUpload(dir, 'https://a.test', 8)).toBeNull();
    expect(findUpload(dir, 'https://b.test', 7)).toBeNull();
  });

  test('a record for another post is ignored (job IDs reused), and no temp file is left', () => {
    const dir = path.join(TEST_DIR, 'log-post');
    recordUpload(dir, 'https://a.test', 9, { videoId: 'V9', url: 'u', kind: 'short', postId: '100', uploadedAt: new Date().toISOString() });
    expect(findUpload(dir, 'https://a.test', 9, 100)?.videoId).toBe('V9');
    expect(findUpload(dir, 'https://a.test', 9, '101')).toBeNull();
    expect(findUpload(dir, 'https://a.test', 9)?.videoId).toBe('V9');
    expect(fs.readdirSync(dir).filter((f) => f.endsWith('.tmp'))).toEqual([]);
  });

  test('the YouTube end card names the site instead of "link in bio"', () => {
    expect(siteEndCardLine('https://www.presshub.cy/')).toBe('Ολόκληρη η ενημέρωση στο presshub.cy');
    const ass = buildAssScript({ hook: 'H', siteName: 'PressHub', chunks: [], totalDuration: 20, endCardLine: siteEndCardLine('https://presshub.cy') });
    expect(ass).toContain('στο presshub.cy');
    expect(ass).not.toContain('bio');
    expect(buildAssScript({ hook: 'H', siteName: 'PressHub', chunks: [], totalDuration: 20 })).toContain('λινκ στο bio');
  });
});

describe('Poller: YouTube jobs', () => {
  function makePoller(job: ClaimedJob, overrides: Record<string, any> = {}) {
    const reports: any[] = [];
    const uploads: any[] = [];
    const posted: any[] = [];
    const fetchFn: any = async (url: string, init: any) => {
      if (url.endsWith('/worker/claim')) return response(200, { ok: true, jobs: overrides.jobs || [job] });
      if (url.endsWith('/worker/complete')) {
        reports.push(JSON.parse(init.body));
        if (overrides.failReports && overrides.failReports > 0) {
          overrides.failReports--;
          return response(503);
        }
        return response(200, { ok: true });
      }
      return response(404);
    };
    const rendered = videoFile(2000);
    const poller = new PressHubPoller({
      // These tests check one job's render and its removal, not the render cache.
      shortCacheTtlMs: 0,
      wpUrl: 'https://presshub.test',
      workerSecret: 'S',
      fetchFn,
      outputDir: TEST_DIR,
      sessionsDir: path.join(TEST_DIR, 'sessions'),
      posterHandler: async (o: any) => {
        posted.push(o);
        return { success: true, platform: o.platform } as any;
      },
      generateShortFn: async (p: any) => {
        overrides.shortParams = p;
        return { filePath: rendered, duration: overrides.shortDuration ?? 40 } as any;
      },
      generateEpisodeFn: async (p: any) => {
        overrides.episodeParams = p;
        return { filePath: rendered, width: 1920, height: 1080, duration: 600, captions: 10, images: 2, scenes: 2, ...(overrides.episodeNotes ? { notes: overrides.episodeNotes } : {}) };
      },
      generateVideoFn: async (p: any) => {
        overrides.videoParams = p;
        return { filePath: rendered, width: p.width, height: p.height, duration: 600 };
      },
      youtubeUploadFn: async (o: any) => {
        uploads.push(o);
        if (overrides.uploadFn) return overrides.uploadFn(o);
        return overrides.uploadResult || { success: true, videoId: 'VID', url: 'https://www.youtube.com/watch?v=VID', playlistItemId: 'PLI' };
      },
      reportSleepFn: async () => {}
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
    expect(overrides.videoParams).toMatchObject({ width: 1920, height: 1080, audioUrl: 'https://presshub.test/podcast.mp3', isPodcast: true, requireAudio: true });
    expect(uploads[0]).toMatchObject({ wpUrl: 'https://presshub.test', workerSecret: 'S', filePath: rendered, meta: job.youtube });
    expect(reports[0]).toMatchObject({ job_id: 41, status: 'published', external_post_id: 'VID', external_post_url: 'https://www.youtube.com/watch?v=VID', metadata: { youtube_kind: 'episode', youtube_playlist_item_id: 'PLI' } });
    expect(fs.existsSync(rendered)).toBe(false);
  });

  test('an enhanced episode is rendered with the story images, scenes and captions', async () => {
    const episode_video = {
      duration: 600,
      segments: [{ speaker: 1, text: 'Καλημέρα', start: 3.6, end: 5 }],
      scenes: [{ start: 0, end: 300, title: 'Α', images: ['https://presshub.test/a.jpg'] }, { start: 300, end: 600, title: 'Β', images: [] }],
      images: ['https://presshub.test/a.jpg']
    };
    const job: ClaimedJob = {
      id: 49,
      platform: 'youtube',
      post_id: 881,
      post_title: 'Πρωινό Podcast',
      post_copy: 'Περιγραφή',
      audio_url: 'https://presshub.test/podcast.wav',
      media_url: 'https://presshub.test/cover.jpg',
      youtube: { ...episodeMeta, width: 1920, height: 1080, episode_video }
    };
    const { poller, uploads, overrides } = makePoller(job);
    const stats = await poller.pollOnce();
    expect(stats).toEqual({ claimed: 1, succeeded: 1, failed: 0 });
    expect(overrides.videoParams).toBeUndefined();
    expect(overrides.episodeParams).toMatchObject({
      audioUrl: 'https://presshub.test/podcast.wav',
      title: 'Πρωινό Podcast',
      segments: episode_video.segments,
      scenes: episode_video.scenes,
      images: episode_video.images,
      fallbackImageUrl: 'https://presshub.test/cover.jpg',
      timelineDuration: 600,
      endCardLine: 'Ολόκληρη η ενημέρωση στο presshub.test'
    });
    expect(uploads).toHaveLength(1);
  });

  test('a translated episode is rendered with its own title and end line', async () => {
    const episode_video = {
      duration: 300,
      segments: [{ speaker: 1, text: 'Günaydın', start: 3.6, end: 5 }],
      scenes: [{ start: 0, end: 300, title: 'Ekonomi', images: ['https://presshub.test/a.jpg'] }],
      images: ['https://presshub.test/a.jpg'],
      title: 'PressHub Sabah Bülteni: Ekonomi',
      end_card_line: 'Haberlerin tamamı ve kaynakları: presshub.test'
    };
    const job: ClaimedJob = {
      id: 52,
      platform: 'youtube',
      post_id: 884,
      post_title: 'Πρωινό Podcast',
      post_copy: 'Açıklama',
      audio_url: 'https://presshub.test/podcast-tr.wav',
      youtube: { ...episodeMeta, title: 'PressHub Sabah Bülteni: Ekonomi', width: 1920, height: 1080, episode_video }
    };
    const { poller, overrides } = makePoller(job);
    await poller.pollOnce();
    expect(overrides.episodeParams).toMatchObject({
      audioUrl: 'https://presshub.test/podcast-tr.wav',
      title: 'PressHub Sabah Bülteni: Ekonomi',
      endCardLine: 'Haberlerin tamamı ve kaynakları: presshub.test'
    });
  });

  test('a plain translated episode shows its own title, not the Greek post title', async () => {
    const job: ClaimedJob = { id: 53, platform: 'youtube', post_id: 885, post_title: 'Πρωινό Podcast', post_copy: 'x', audio_url: 'https://presshub.test/p-en.wav', youtube: { ...episodeMeta, title: 'PressHub Morning Briefing' } };
    const { poller, overrides } = makePoller(job);
    await poller.pollOnce();
    expect(overrides.videoParams).toMatchObject({ isPodcast: true, title: 'PressHub Morning Briefing' });
  });

  test('what an enhanced episode lacks is reported with the published video', async () => {
    const job: ClaimedJob = { id: 51, platform: 'youtube', post_id: 883, post_copy: 'x', audio_url: 'https://presshub.test/p.wav', youtube: { ...episodeMeta, episode_video: { images: ['https://presshub.test/a.jpg'] } } };
    const { poller, reports, overrides } = makePoller(job);
    overrides.episodeNotes = ['1 of 1 story image(s) could not be used', 'no captions (no caption timeline)'];
    await poller.pollOnce();
    expect(reports[0]).toMatchObject({ status: 'published', metadata: { youtube_episode_note: 'Rendered with 1 of 1 story image(s) could not be used; no captions (no caption timeline).' } });
  });

  test('an episode without a timeline or images is rendered the plain way', async () => {
    const job: ClaimedJob = { id: 50, platform: 'youtube', post_id: 882, post_copy: 'x', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta, episode_video: { images: [] } } };
    const { poller, overrides } = makePoller(job);
    await poller.pollOnce();
    expect(overrides.episodeParams).toBeUndefined();
    expect(overrides.videoParams).toMatchObject({ isPodcast: true });
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

  test('a YouTube short ends on the site name, not "link in bio"', async () => {
    const job: ClaimedJob = {
      id: 45, platform: 'youtube', post_id: 891, post_copy: 'c', audio_url: 'https://presshub.test/s.wav',
      tiktok_short: { index: 0, count: 1, segments: [], images: [] }, youtube: { kind: 'short', title: 'H', description: 'c' }
    };
    const { poller, overrides } = makePoller(job);
    await poller.pollOnce();
    expect(overrides.shortParams.endCardLine).toBe('Ολόκληρη η ενημέρωση στο presshub.test');
  });

  test('a short over 3 minutes fails instead of going up as a normal video', async () => {
    const job: ClaimedJob = {
      id: 46, platform: 'youtube', post_id: 892, post_copy: 'c', audio_url: 'https://presshub.test/s.wav',
      tiktok_short: { index: 0, count: 1, segments: [], images: [] }, youtube: { kind: 'short', title: 'H', description: 'c' }
    };
    const { poller, overrides, reports, uploads } = makePoller(job, { shortDuration: 185 });
    void overrides;
    await poller.pollOnce();
    expect(uploads).toHaveLength(0);
    expect(reports[0].status).toBe('failed');
    expect(reports[0].error).toContain("over YouTube's 180s limit");
  });

  test('a quota refusal is reported with its reason', async () => {
    const job: ClaimedJob = { id: 47, platform: 'youtube', post_copy: '', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta } };
    const { poller, reports } = makePoller(job, { uploadResult: { success: false, error: 'limit', reason: 'quota' } });
    await poller.pollOnce();
    expect(reports[0]).toMatchObject({ status: 'failed', error: 'limit', metadata: { youtube_error_reason: 'quota' } });
  });

  test('a lost report is retried, and a job handed out again is not uploaded twice', async () => {
    const job: ClaimedJob = { id: 48, platform: 'youtube', post_id: 900, post_copy: 'x', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta } };
    const first = makePoller(job, { failReports: 2 });
    await first.poller.pollOnce();
    expect(first.uploads).toHaveLength(1);
    expect(first.reports.map((r) => r.status)).toEqual(['published', 'published', 'published']);

    // Every report lost: the next claim of the same job reports the recorded video.
    const job2: ClaimedJob = { ...job, id: 49 };
    const lost = makePoller(job2, { failReports: 99 });
    await lost.poller.pollOnce();
    expect(lost.uploads).toHaveLength(1);
    const again = makePoller(job2);
    await again.poller.pollOnce();
    expect(again.uploads).toHaveLength(0);
    expect(again.reports[0]).toMatchObject({ job_id: 49, status: 'published', external_post_id: 'VID' });
  });

  test('the video is recorded when YouTube has it, so a crash before the playlist insert never uploads it twice', async () => {
    const job: ClaimedJob = { id: 60, platform: 'youtube', post_id: 960, post_copy: 'x', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta } };
    const crash = makePoller(job, {
      uploadFn: async (o: any) => {
        o.onUploaded('VCRASH', 'https://www.youtube.com/watch?v=VCRASH');
        throw new Error('worker stopped');
      }
    });
    await crash.poller.pollOnce();
    const again = makePoller(job);
    await again.poller.pollOnce();
    expect(again.uploads).toHaveLength(0);
    expect(again.reports[0]).toMatchObject({ job_id: 60, status: 'published', external_post_id: 'VCRASH' });
    expect(again.reports[0].metadata.youtube_playlist_error).toContain('playlist');
  });

  test('after a quota stop the other YouTube jobs of the batch are not rendered', async () => {
    const mk = (id: number): ClaimedJob => ({ id, platform: 'youtube', post_id: 1000 + id, post_copy: 'x', audio_url: 'https://presshub.test/p.mp3', youtube: { ...episodeMeta } });
    const jobs = [mk(70), mk(71), mk(72)];
    const t = makePoller(jobs[0], { jobs, uploadResult: { success: false, error: 'limit', reason: 'quota' } });
    const stats = await t.poller.pollOnce();
    expect(stats).toEqual({ claimed: 3, succeeded: 0, failed: 3 });
    expect(t.uploads).toHaveLength(1);
    expect(t.reports.map((r) => [r.job_id, r.status, r.metadata?.youtube_error_reason])).toEqual([
      [70, 'failed', 'quota'], [71, 'failed', 'quota'], [72, 'failed', 'quota']
    ]);
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
