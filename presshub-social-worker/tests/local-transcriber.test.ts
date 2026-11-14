import fs from 'fs';
import os from 'os';
import path from 'path';
import { LocalTranscriber, LocalTranscriberConfig, cleanTranscript, localTranscriberConfigFromEnv } from '../src/monitor/local-transcriber';
import { removeStaleVideos, SocialMonitor } from '../src/monitor/monitor-client';
import { orderVideoSources, ScanPlan, ScanSession } from '../src/monitor/scanner';
import { ScannedPost } from '../src/monitor/parsers';

const MB = 1024 * 1024;

const cfg: LocalTranscriberConfig = {
  bin: '/opt/whisper/whisper-cli',
  model: '/opt/whisper/ggml-large-v3-turbo-q5_0.bin',
  ffmpeg: '/usr/bin/ffmpeg',
  lang: 'el',
  threads: 6,
  maxSec: 1800,
  maxMb: 300
};

describe('Local transcriber config', () => {
  const exists = (p: string) => p.startsWith('/opt/whisper/');

  it('is off unless the executable and the model exist', () => {
    expect(localTranscriberConfigFromEnv({}, exists)).toBeNull();
    expect(localTranscriberConfigFromEnv({ PRESSHUB_WHISPER_BIN: '/opt/whisper/whisper-cli', PRESSHUB_WHISPER_MODEL: '/missing.bin' }, exists)).toBeNull();
    expect(
      localTranscriberConfigFromEnv({ PRESSHUB_WHISPER_BIN: '/opt/whisper/whisper-cli', PRESSHUB_WHISPER_MODEL: '/opt/whisper/m.bin', PRESSHUB_LOCAL_TRANSCRIBE_DISABLED: 'true' }, exists)
    ).toBeNull();
  });

  it('reads defaults and clamps numbers', () => {
    const c = localTranscriberConfigFromEnv({ PRESSHUB_WHISPER_BIN: '/opt/whisper/whisper-cli', PRESSHUB_WHISPER_MODEL: '/opt/whisper/m.bin' }, exists);
    expect(c).toMatchObject({ lang: 'el', threads: 4, maxSec: 1800, maxMb: 300 });
    const d = localTranscriberConfigFromEnv(
      {
        PRESSHUB_WHISPER_BIN: '/opt/whisper/whisper-cli',
        PRESSHUB_WHISPER_MODEL: '/opt/whisper/m.bin',
        PRESSHUB_WHISPER_LANG: 'auto',
        PRESSHUB_WHISPER_THREADS: '500',
        PRESSHUB_LOCAL_TRANSCRIBE_MAX_SEC: '5'
      },
      exists
    );
    expect(d).toMatchObject({ lang: 'auto', threads: 64, maxSec: 60 });
    const e = localTranscriberConfigFromEnv({ PRESSHUB_WHISPER_BIN: '/opt/whisper/whisper-cli', PRESSHUB_WHISPER_MODEL: '/opt/whisper/m.bin', PRESSHUB_WHISPER_LANG: '--evil' }, exists);
    expect(e?.lang).toBe('el');
  });
});

describe('Local transcriber run', () => {
  it('extracts 16 kHz mono audio, runs whisper and returns the cleaned text', async () => {
    const calls: { file: string; args: string[]; timeout: number }[] = [];
    const exec = jest.fn(async (file: string, args: string[], timeout: number) => {
      calls.push({ file, args, timeout });
      if (file === cfg.bin) {
        const of = args[args.indexOf('-of') + 1];
        fs.writeFileSync(of + '.txt', ' Καλησπέρα σας.\n\n\n Σήμερα μιλούμε για το Κυπριακό. \n');
      }
      return '';
    });
    const t = new LocalTranscriber(cfg, exec);
    const text = await t.transcribeFile('/tmp/in.mp4', 1200);
    expect(text).toBe('Καλησπέρα σας.\n\nΣήμερα μιλούμε για το Κυπριακό.');
    expect(calls[0].file).toBe(cfg.ffmpeg);
    expect(calls[0].args).toEqual(expect.arrayContaining(['-i', '/tmp/in.mp4', '-ac', '1', '-ar', '16000']));
    expect(calls[1].file).toBe(cfg.bin);
    expect(calls[1].args).toEqual(expect.arrayContaining(['-m', cfg.model, '-l', 'el', '-t', '6', '-otxt', '-nt']));
    expect(calls[1].timeout).toBe(3600 * 1000);
    // Scratch folder removed.
    const of = calls[1].args[calls[1].args.indexOf('-of') + 1];
    expect(fs.existsSync(path.dirname(of))).toBe(false);
  });

  it('returns empty text when whisper writes nothing, and passes errors on', async () => {
    const t = new LocalTranscriber(cfg, async () => '');
    expect(await t.transcribeFile('/tmp/in.mp4', 30)).toBe('');
    const bad = new LocalTranscriber(cfg, async (file) => {
      if (file === cfg.bin) throw new Error('whisper-cli failed');
      return '';
    });
    await expect(bad.transcribeFile('/tmp/in.mp4', 30)).rejects.toThrow('whisper-cli failed');
  });

  it('cleans transcripts', () => {
    expect(cleanTranscript('\n a \n\n\n b\r\n')).toBe('a\n\nb');
  });
});

describe('Video source order', () => {
  const sources = [
    { url: 'big', estimatedBytes: 40 * MB },
    { url: 'mid', estimatedBytes: 12 * MB },
    { url: 'small', estimatedBytes: 3 * MB },
    { url: 'unknown', estimatedBytes: 0 }
  ];
  it('biggest that fits first for Gemini, unknown sizes last', () => {
    expect(orderVideoSources(sources, 15 * MB).map((s) => s.url)).toEqual(['mid', 'small', 'unknown']);
  });
  it('smallest first for speech-to-text', () => {
    expect(orderVideoSources(sources, 300 * MB, true).map((s) => s.url)).toEqual(['small', 'mid', 'big', 'unknown']);
  });
});

describe('SocialMonitor with local transcription', () => {
  const plan: ScanPlan = {
    enabled: true,
    due: true,
    targets: [{ platform: 'tiktok', handle: 'influencer', url: 'https://www.tiktok.com/@influencer', since: '2026-11-10T00:00:00Z' }],
    max_posts_per_account: 10,
    max_age_hours: 24,
    transcribe_videos: true,
    max_video_seconds: 180,
    max_video_mb: 15
  };
  const longPost: ScannedPost = {
    platform: 'tiktok',
    id: '7300000000000000009',
    handle: 'influencer',
    author_name: 'Influencer',
    url: 'https://www.tiktok.com/@influencer/video/7300000000000000009',
    text: 'long talk',
    created_at: '2026-11-11T08:00:00.000Z',
    media: { type: 'video', duration_sec: 1200 },
    video: [{ url: 'https://tt/long.mp4', estimatedBytes: 80 * MB }]
  };

  function makeFetch(postsBody: any, transcriptOk = true) {
    const calls: { url: string; init: any }[] = [];
    const fn = jest.fn(async (url: string, init: any = {}) => {
      calls.push({ url, init });
      if (url.includes('/monitor/targets')) return { ok: true, status: 200, json: async () => ({ ...plan }) } as any;
      if (url.includes('/monitor/posts')) return { ok: true, status: 200, json: async () => postsBody } as any;
      if (url.includes('/monitor/transcript')) return { ok: transcriptOk, status: transcriptOk ? 200 : 500, json: async () => ({}) } as any;
      if (url.includes('/monitor/media')) return { ok: true, status: 200, json: async () => ({}) } as any;
      return { ok: false, status: 404, json: async () => ({}) } as any;
    });
    return { fn, calls };
  }

  function fakeTranscriber(result: string | Error) {
    const t = new LocalTranscriber(cfg, async () => '');
    const seen: string[] = [];
    t.transcribeFile = jest.fn(async (file: string) => {
      seen.push(fs.readFileSync(file, 'utf8'));
      if (result instanceof Error) throw result;
      return result;
    }) as any;
    return { t, seen };
  }

  it('offers local transcription, downloads the smallest source and sends the text', async () => {
    const { fn, calls } = makeFetch({ accepted: 1, wants_media: [], wants_transcript: ['tiktok_7300000000000000009'] });
    const { t, seen } = fakeTranscriber('Καλησπέρα σας.');
    let scannedPlan: ScanPlan | undefined;
    const download = jest.fn(async () => ({ bytes: Buffer.from('longvideo'), mime: 'video/mp4' }));
    const session: ScanSession = { posts: [longPost], errors: [], download, close: async () => {} };
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      transcriber: t,
      scanFn: async (p) => {
        scannedPlan = { ...p };
        return session;
      }
    });

    await monitor.runOnce();
    await monitor.idle();

    expect(scannedPlan?.local_max_mb).toBe(300);
    const sent = JSON.parse(calls.find((c) => c.url.includes('/monitor/posts'))!.init.body);
    expect(sent.local_transcribe).toEqual({ max_sec: 1800 });
    expect(download).toHaveBeenCalledWith(longPost, { maxBytes: 300 * MB, smallestFirst: true });
    expect(seen).toEqual(['longvideo']);
    const tr = calls.find((c) => c.url.endsWith('/monitor/transcript'));
    expect(JSON.parse(tr!.init.body)).toEqual({ id: 'tiktok_7300000000000000009', text: 'Καλησπέρα σας.', engine: 'whisper.cpp' });
    // Temp video removed.
    const leftovers = fs.readdirSync(os.tmpdir()).filter((f) => f.startsWith('presshub-long-tiktok_7300000000000000009'));
    expect(leftovers).toEqual([]);
  });

  it('reports a failed local transcription so the harvest stops waiting', async () => {
    const { fn, calls } = makeFetch({ accepted: 1, wants_media: [], wants_transcript: ['tiktok_7300000000000000009'] });
    const { t } = fakeTranscriber(new Error('whisper-cli failed'));
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      transcriber: t,
      scanFn: async () => ({ posts: [longPost], errors: [], download: async () => ({ bytes: Buffer.from('v'), mime: 'video/mp4' }), close: async () => {} })
    });
    await monitor.runOnce();
    await monitor.idle();
    expect(calls.some((c) => c.url.endsWith('/monitor/media?id=tiktok_7300000000000000009&failed=local_transcribe'))).toBe(true);
  });

  it('reports a transcript WordPress did not accept, except a 404', async () => {
    for (const [status, expectFailed] of [[500, true], [404, false]] as const) {
      const { fn, calls } = makeFetch({ accepted: 1, wants_media: [], wants_transcript: ['tiktok_7300000000000000009'] }, false);
      fn.mockImplementation(async (url: string, init: any = {}) => {
        calls.push({ url, init });
        if (url.includes('/monitor/targets')) return { ok: true, status: 200, json: async () => ({ ...plan }) } as any;
        if (url.includes('/monitor/posts')) return { ok: true, status: 200, json: async () => ({ accepted: 1, wants_transcript: ['tiktok_7300000000000000009'] }) } as any;
        if (url.includes('/monitor/transcript')) return { ok: false, status, json: async () => ({}) } as any;
        return { ok: true, status: 200, json: async () => ({}) } as any;
      });
      const { t } = fakeTranscriber('text');
      const monitor = new SocialMonitor({
        wpUrl: 'https://site.test',
        workerSecret: 's',
        fetchFn: fn as any,
        transcriber: t,
        scanFn: async () => ({ posts: [longPost], errors: [], download: async () => ({ bytes: Buffer.from('v'), mime: 'video/mp4' }), close: async () => {} })
      });
      await monitor.runOnce();
      await monitor.idle();
      expect(calls.some((c) => c.url.endsWith('failed=local_transcribe'))).toBe(expectFailed);
    }
  });

  it('reports a failed download', async () => {
    const { fn, calls } = makeFetch({ accepted: 1, wants_transcript: ['tiktok_7300000000000000009'] });
    const { t } = fakeTranscriber('x');
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      transcriber: t,
      scanFn: async () => ({ posts: [longPost], errors: [], download: async () => null, close: async () => {} })
    });
    await monitor.runOnce();
    await monitor.idle();
    expect(calls.some((c) => c.url.endsWith('failed=download'))).toBe(true);
    expect(t.transcribeFile).not.toHaveBeenCalled();
  });

  it('does not offer anything without a transcriber, and ignores wants_transcript', async () => {
    const { fn, calls } = makeFetch({ accepted: 1, wants_transcript: ['tiktok_7300000000000000009'] });
    const download = jest.fn();
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      transcriber: null,
      scanFn: async () => ({ posts: [longPost], errors: [], download: download as any, close: async () => {} })
    });
    await monitor.runOnce();
    const sent = JSON.parse(calls.find((c) => c.url.includes('/monitor/posts'))!.init.body);
    expect(sent).not.toHaveProperty('local_transcribe');
    expect(download).not.toHaveBeenCalled();
  });
});

describe('Stale temp videos', () => {
  it('removes old presshub-long videos only', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'presshub-stale-'));
    try {
      const now = Date.now();
      const old = path.join(dir, 'presshub-long-tiktok_1-1.mp4');
      const fresh = path.join(dir, 'presshub-long-tiktok_2-2.mp4');
      const other = path.join(dir, 'other.mp4');
      for (const f of [old, fresh, other]) fs.writeFileSync(f, 'x');
      const past = new Date(now - 13 * 3600 * 1000);
      fs.utimesSync(old, past, past);
      fs.utimesSync(other, past, past);
      expect(removeStaleVideos(dir, now)).toBe(1);
      expect(fs.existsSync(old)).toBe(false);
      expect(fs.existsSync(fresh)).toBe(true);
      expect(fs.existsSync(other)).toBe(true);
      expect(removeStaleVideos(path.join(dir, 'missing'), now)).toBe(0);
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });
});
