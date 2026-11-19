import fs from 'fs';
import os from 'os';
import path from 'path';
import { Mp3Converter, ffmpegArgs, looksLikeMp3, encodeMp3 } from '../src/media/mp3-converter';

const WP = 'https://presshub.test';
const JOB = { post_id: 10, attachment_id: 20, source_url: 'https://presshub.test/wp-content/uploads/podcast.wav', bitrate_kbps: 64, attempt: 1 };
const FAKE_MP3 = Buffer.concat([Buffer.from('ID3'), Buffer.alloc(2048, 0)]);

function json(body: any, status = 200): any {
  return { ok: status >= 200 && status < 300, status, json: async () => body, arrayBuffer: async () => new ArrayBuffer(0) };
}

/** A fake WordPress: records every call, answers by route. */
function fakeWp(routes: Record<string, (init: any) => any>) {
  const calls: { url: string; init: any }[] = [];
  const fetchFn: any = async (url: string, init: any = {}) => {
    calls.push({ url, init });
    for (const [key, handler] of Object.entries(routes)) {
      if (url.includes(key)) return handler(init);
    }
    return json({}, 404);
  };
  return { calls, fetchFn };
}

const wavResponse = () => ({ ok: true, status: 200, arrayBuffer: async () => new Uint8Array(Buffer.from('RIFF....WAVEdata')).buffer });

describe('Podcast MP3 converter', () => {
  test('ffmpeg arguments: mono MP3 at the asked bitrate, unknown bitrates become 64 kbps', () => {
    expect(ffmpegArgs('in.wav', 'out.mp3', 96)).toEqual(['-hide_banner', '-loglevel', 'error', '-y', '-i', 'in.wav', '-vn', '-ac', '1', '-codec:a', 'libmp3lame', '-b:a', '96k', 'out.mp3']);
    expect(ffmpegArgs('in.wav', 'out.mp3', 70)).toContain('64k');
  });

  test('recognises MP3 bytes', () => {
    expect(looksLikeMp3(FAKE_MP3)).toBe(true);
    expect(looksLikeMp3(Buffer.concat([Buffer.from([0xff, 0xf3]), Buffer.alloc(2000)]))).toBe(true);
    expect(looksLikeMp3(Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(2000)]))).toBe(false);
    expect(looksLikeMp3(Buffer.from('ID3'))).toBe(false);
  });

  test('does nothing when WordPress has no episode waiting, or is older than this worker', async () => {
    const idle = fakeWp({ '/worker/audio/claim': () => json({ job: null }) });
    expect(await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: idle.fetchFn }).runOnce()).toEqual({ ran: false });
    const old = fakeWp({});
    expect(await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: old.fetchFn }).runOnce()).toEqual({ ran: false });
    expect(idle.calls).toHaveLength(1);
    expect(idle.calls[0].init.headers['X-PressHub-Secret']).toBe('s');
  });

  test('downloads, encodes and uploads the MP3 with the post and attachment ids', async () => {
    const wp = fakeWp({
      '/worker/audio/claim': () => json({ job: JOB }),
      'podcast.wav': () => wavResponse(),
      '/worker/audio/complete': () => json({ ok: true, attachment_id: 77 })
    });
    const encodeFn = jest.fn(async (input: string, output: string, kbps: number) => {
      expect(fs.existsSync(input)).toBe(true);
      expect(kbps).toBe(64);
      fs.writeFileSync(output, FAKE_MP3);
    });
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'mp3-test-'));
    const result = await new Mp3Converter({ wpUrl: WP + '/', workerSecret: 's', fetchFn: wp.fetchFn, encodeFn, tmpDir }).runOnce();
    expect(result).toMatchObject({ ran: true, ok: true, postId: 10, mp3Bytes: FAKE_MP3.length });
    const upload = wp.calls.find((c) => c.url.includes('/complete'))!;
    expect(upload.url).toBe(`${WP}/wp-json/presshub-social/v1/worker/audio/complete?post_id=10&attachment_id=20`);
    expect(upload.init.headers['Content-Type']).toBe('audio/mpeg');
    expect(Buffer.compare(upload.init.body, FAKE_MP3)).toBe(0);
    expect(fs.readdirSync(tmpDir)).toEqual([]); // temp files removed
  });

  test('reports a failed encode so WordPress tries again', async () => {
    const wp = fakeWp({
      '/worker/audio/claim': () => json({ job: JOB }),
      'podcast.wav': () => wavResponse(),
      '/worker/audio/fail': () => json({ ok: true })
    });
    const encodeFn = async () => {
      throw new Error('ffmpeg exited with code 1: bad input');
    };
    const result = await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: wp.fetchFn, encodeFn }).runOnce();
    expect(result).toMatchObject({ ran: true, ok: false, error: 'ffmpeg exited with code 1: bad input' });
    const fail = wp.calls.find((c) => c.url.endsWith('/worker/audio/fail'))!;
    expect(JSON.parse(fail.init.body)).toEqual({ post_id: 10, attachment_id: 20, error: 'ffmpeg exited with code 1: bad input' });
    expect(wp.calls.some((c) => c.url.includes('/complete'))).toBe(false);
  });

  test('reports a failed download', async () => {
    const wp = fakeWp({
      '/worker/audio/claim': () => json({ job: JOB }),
      'podcast.wav': () => json({}, 404),
      '/worker/audio/fail': () => json({ ok: true })
    });
    const result = await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: wp.fetchFn, encodeFn: jest.fn() }).runOnce();
    expect(result.ok).toBe(false);
    expect(JSON.parse(wp.calls.find((c) => c.url.endsWith('/fail'))!.init.body).error).toBe('Downloading the WAV failed with HTTP 404');
  });

  test('a 409 on upload (episode regenerated meanwhile) is not reported as a failure', async () => {
    const wp = fakeWp({
      '/worker/audio/claim': () => json({ job: JOB }),
      'podcast.wav': () => wavResponse(),
      '/worker/audio/complete': () => json({ code: 'mp3_superseded', message: 'The episode plays another file now' }, 409)
    });
    const encodeFn = async (_i: string, o: string) => fs.writeFileSync(o, FAKE_MP3);
    const result = await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: wp.fetchFn, encodeFn }).runOnce();
    expect(result.ok).toBe(false);
    expect(wp.calls.some((c) => c.url.endsWith('/fail'))).toBe(false);
  });

  test('a 500 on upload is reported', async () => {
    const wp = fakeWp({
      '/worker/audio/claim': () => json({ job: JOB }),
      'podcast.wav': () => wavResponse(),
      '/worker/audio/complete': () => json({ message: 'Disk full' }, 500),
      '/worker/audio/fail': () => json({ ok: true })
    });
    const encodeFn = async (_i: string, o: string) => fs.writeFileSync(o, FAKE_MP3);
    await new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: wp.fetchFn, encodeFn }).runOnce();
    expect(JSON.parse(wp.calls.find((c) => c.url.endsWith('/fail'))!.init.body).error).toBe('Upload failed with HTTP 500: Disk full');
  });

  test('converts one episode at a time', async () => {
    let release: () => void = () => undefined;
    const wp = fakeWp({
      '/worker/audio/claim': () => new Promise((resolve) => { release = () => resolve(json({ job: null })); })
    });
    const conv = new Mp3Converter({ wpUrl: WP, workerSecret: 's', fetchFn: wp.fetchFn });
    const first = conv.runOnce();
    expect(await conv.runOnce()).toEqual({ ran: false });
    release();
    await first;
    expect(wp.calls).toHaveLength(1);
  });

  test('the bundled ffmpeg encodes a real WAV to a small MP3', async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mp3-real-'));
    const wav = path.join(dir, 'in.wav');
    const mp3 = path.join(dir, 'out.mp3');
    // 10 s of 24 kHz 16-bit mono tone, like a Gemini TTS episode.
    const rate = 24000;
    const samples = rate * 10;
    const header = Buffer.alloc(44);
    header.write('RIFF', 0); header.writeUInt32LE(36 + samples * 2, 4); header.write('WAVE', 8);
    header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
    header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate * 2, 28); header.writeUInt16LE(2, 32); header.writeUInt16LE(16, 34);
    header.write('data', 36); header.writeUInt32LE(samples * 2, 40);
    const pcm = Buffer.alloc(samples * 2);
    for (let i = 0; i < samples; i++) pcm.writeInt16LE(Math.round(8000 * Math.sin((2 * Math.PI * 220 * i) / rate)), i * 2);
    fs.writeFileSync(wav, Buffer.concat([header, pcm]));
    await encodeMp3(wav, mp3, 64);
    const out = fs.readFileSync(mp3);
    expect(looksLikeMp3(out)).toBe(true);
    expect(out.length).toBeLessThan(fs.statSync(wav).size / 4);
    fs.rmSync(dir, { recursive: true, force: true });
  }, 30000);
});
