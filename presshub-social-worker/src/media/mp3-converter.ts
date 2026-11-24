/**
 * Podcast MP3 converter: WordPress saves Gemini TTS episodes as WAV (about 32 MB
 * for 11 minutes) and its host has no MP3 encoder. On each poll this client asks
 * WordPress for one waiting episode, downloads the WAV, encodes it with the
 * bundled ffmpeg and sends the MP3 back; WordPress then points the post at it.
 *
 * Routes (presshub-social/v1, worker secret):
 *   POST /worker/audio/claim     -> { job: null | AudioJob }
 *   POST /worker/audio/complete  ?post_id=&attachment_id=, body: the MP3 (audio/mpeg)
 *   POST /worker/audio/fail      { post_id, attachment_id, error }
 */
import fs from 'fs';
import os from 'os';
import path from 'path';
import { spawn } from 'child_process';
import ffmpegInstaller from '@ffmpeg-installer/ffmpeg';
import { WorkerLogger } from '../logger';

export interface AudioJob {
  post_id: number;
  attachment_id: number;
  source_url: string;
  bitrate_kbps: number;
  attempt?: number;
}

/** Encodes `input` (any audio ffmpeg reads) to a mono MP3 at `bitrateKbps`, giving up after `timeoutMs`. */
export type EncodeMp3Fn = (input: string, output: string, bitrateKbps: number, timeoutMs?: number) => Promise<void>;

/** How long each step may take before the episode is reported as failed. */
export interface Mp3Timeouts {
  /** Claim and failure report (default 30 s). */
  requestMs: number;
  /** WAV download and MP3 upload (default 10 min). */
  transferMs: number;
  /** The ffmpeg encode (default 10 min). */
  encodeMs: number;
}

export const DEFAULT_MP3_TIMEOUTS: Mp3Timeouts = { requestMs: 30_000, transferMs: 600_000, encodeMs: 600_000 };

export interface Mp3ConverterOptions {
  wpUrl: string;
  workerSecret: string;
  fetchFn?: typeof fetch;
  encodeFn?: EncodeMp3Fn;
  /** Folder for the temporary WAV and MP3 (default: the OS temp folder). */
  tmpDir?: string;
  timeouts?: Partial<Mp3Timeouts>;
}

export interface Mp3RunResult {
  ran: boolean;
  postId?: number;
  ok?: boolean;
  wavBytes?: number;
  mp3Bytes?: number;
  error?: string;
}

const IDLE: Mp3RunResult = { ran: false };

/** Bitrates WordPress offers; anything else is encoded at 64 kbps. */
const BITRATES = [48, 64, 96, 128];

export function ffmpegArgs(input: string, output: string, bitrateKbps: number): string[] {
  const kbps = BITRATES.includes(bitrateKbps) ? bitrateKbps : 64;
  return ['-hide_banner', '-loglevel', 'error', '-y', '-i', input, '-vn', '-ac', '1', '-codec:a', 'libmp3lame', '-b:a', `${kbps}k`, output];
}

export const encodeMp3: EncodeMp3Fn = (input, output, bitrateKbps, timeoutMs = DEFAULT_MP3_TIMEOUTS.encodeMs) =>
  new Promise<void>((resolve, reject) => {
    const bin = ffmpegInstaller && ffmpegInstaller.path ? ffmpegInstaller.path : 'ffmpeg';
    const proc = spawn(bin, ffmpegArgs(input, output, bitrateKbps));
    let stderr = '';
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      proc.kill('SIGKILL');
    }, timeoutMs);
    proc.stderr.on('data', (d: Buffer) => {
      stderr = (stderr + d.toString()).slice(-2000);
    });
    proc.on('error', (err) => {
      clearTimeout(timer);
      reject(err);
    });
    proc.on('close', (code: number | null) => {
      clearTimeout(timer);
      if (timedOut) reject(new Error(`ffmpeg was stopped after ${Math.round(timeoutMs / 1000)} s`));
      else if (code === 0) resolve();
      else reject(new Error(`ffmpeg exited with code ${code}: ${stderr.trim().split('\n').pop() || ''}`));
    });
  });

/** Turns an aborted fetch into a readable error. */
function timeoutError(err: any, what: string, ms: number): Error {
  if (err && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
    return new Error(`${what} timed out after ${Math.round(ms / 1000)} s`);
  }
  return err instanceof Error ? err : new Error(String(err));
}

/** True when the bytes start like an MP3 (ID3 tag or an MPEG audio frame). */
export function looksLikeMp3(buf: Buffer): boolean {
  if (buf.length < 1024) return false;
  if (buf.subarray(0, 3).toString('latin1') === 'ID3') return true;
  return buf[0] === 0xff && (buf[1] & 0xe0) === 0xe0;
}

export class Mp3Converter {
  private readonly wpUrl: string;
  private readonly secret: string;
  private readonly fetchFn: typeof fetch;
  private readonly encodeFn: EncodeMp3Fn;
  private readonly tmpDir: string;
  private readonly timeouts: Mp3Timeouts;
  private running = false;

  constructor(options: Mp3ConverterOptions) {
    this.wpUrl = options.wpUrl.trim().replace(/\/+$/, '');
    this.secret = options.workerSecret.trim();
    this.fetchFn = options.fetchFn || globalThis.fetch;
    this.encodeFn = options.encodeFn || encodeMp3;
    this.tmpDir = options.tmpDir || os.tmpdir();
    this.timeouts = { ...DEFAULT_MP3_TIMEOUTS, ...(options.timeouts || {}) };
  }

  /** fetch with a time limit; a timeout throws "<what> timed out after N s". */
  private async timedFetch(url: string, init: any, ms: number, what: string): Promise<Response> {
    try {
      return await this.fetchFn(url, { ...init, signal: AbortSignal.timeout(ms) });
    } catch (err: any) {
      throw timeoutError(err, what, ms);
    }
  }

  private headers(extra: Record<string, string> = {}): Record<string, string> {
    return { 'X-PressHub-Secret': this.secret, Authorization: `Bearer ${this.secret}`, ...extra };
  }

  private endpoint(route: string): string {
    return `${this.wpUrl}/wp-json/presshub-social/v1/worker/audio/${route}`;
  }

  public async claim(): Promise<AudioJob | null> {
    const res = await this.timedFetch(
      this.endpoint('claim'),
      { method: 'POST', headers: this.headers({ 'Content-Type': 'application/json' }), body: '{}' },
      this.timeouts.requestMs,
      'Claiming an episode'
    );
    if (!res.ok) {
      // 404: the WordPress plugin is older than this worker.
      if (res.status !== 404) WorkerLogger.warn('Mp3', `Claim failed with HTTP ${res.status}`);
      return null;
    }
    const body: any = await res.json().catch(() => null);
    const job = body && body.job;
    if (!job || !Number(job.post_id) || !Number(job.attachment_id) || typeof job.source_url !== 'string' || !job.source_url) {
      return null;
    }
    return {
      post_id: Number(job.post_id),
      attachment_id: Number(job.attachment_id),
      source_url: job.source_url,
      bitrate_kbps: Number(job.bitrate_kbps) || 64,
      attempt: Number(job.attempt) || 1
    };
  }

  private async reportFailure(job: AudioJob, error: string): Promise<void> {
    await this.timedFetch(
      this.endpoint('fail'),
      {
        method: 'POST',
        headers: this.headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ post_id: job.post_id, attachment_id: job.attachment_id, error: error.slice(0, 500) })
      },
      this.timeouts.requestMs,
      'Reporting the failure'
    ).catch((err: any) => {
      WorkerLogger.warn('Mp3', `Reporting the failure of post #${job.post_id} failed: ${err.message}`);
    });
  }

  /** One cycle: converts at most one episode. */
  public async runOnce(): Promise<Mp3RunResult> {
    if (this.running) return IDLE;
    this.running = true;
    let job: AudioJob | null = null;
    const stamp = `${process.pid}-${Date.now()}`;
    const wavPath = path.join(this.tmpDir, `presshub-podcast-${stamp}.wav`);
    const mp3Path = path.join(this.tmpDir, `presshub-podcast-${stamp}.mp3`);
    try {
      job = await this.claim();
      if (!job) return IDLE;
      WorkerLogger.info('Mp3', `Post #${job.post_id}: converting the podcast to MP3 at ${job.bitrate_kbps} kbps (try ${job.attempt})...`);

      // One time limit covers the response and its body.
      const signal = AbortSignal.timeout(this.timeouts.transferMs);
      let wav: Buffer;
      try {
        const src = await this.fetchFn(job.source_url, { signal });
        if (!src.ok) throw new Error(`Downloading the WAV failed with HTTP ${src.status}`);
        wav = Buffer.from(await src.arrayBuffer());
      } catch (err: any) {
        throw timeoutError(err, 'Downloading the WAV', this.timeouts.transferMs);
      }
      fs.writeFileSync(wavPath, wav);

      await this.encodeFn(wavPath, mp3Path, job.bitrate_kbps, this.timeouts.encodeMs);
      const mp3 = fs.readFileSync(mp3Path);
      if (!looksLikeMp3(mp3)) throw new Error('ffmpeg did not write an MP3 file');

      const qs = `?post_id=${job.post_id}&attachment_id=${job.attachment_id}`;
      const res = await this.timedFetch(
        this.endpoint('complete') + qs,
        { method: 'POST', headers: this.headers({ 'Content-Type': 'audio/mpeg' }), body: mp3 },
        this.timeouts.transferMs,
        'Uploading the MP3'
      );
      if (!res.ok) {
        const reason = await res
          .json()
          .then((b: any) => String(b?.message || b?.code || ''))
          .catch(() => '');
        // 409: superseded or no longer claimed; WordPress already knows, nothing to report.
        if (res.status !== 409) await this.reportFailure(job, `Upload failed with HTTP ${res.status}${reason ? `: ${reason}` : ''}`);
        WorkerLogger.warn('Mp3', `Post #${job.post_id}: sending the MP3 failed with HTTP ${res.status}${reason ? `: ${reason}` : ''}`);
        return { ran: true, postId: job.post_id, ok: false, wavBytes: wav.length, mp3Bytes: mp3.length, error: reason || `HTTP ${res.status}` };
      }
      const mb = (n: number) => (n / 1048576).toFixed(1);
      WorkerLogger.info('Mp3', `Post #${job.post_id}: now plays the MP3 (${mb(wav.length)} MB WAV -> ${mb(mp3.length)} MB MP3).`);
      return { ran: true, postId: job.post_id, ok: true, wavBytes: wav.length, mp3Bytes: mp3.length };
    } catch (err: any) {
      const message = String(err?.message || err);
      if (job) {
        WorkerLogger.warn('Mp3', `Post #${job.post_id}: MP3 conversion failed: ${message}`);
        await this.reportFailure(job, message);
        return { ran: true, postId: job.post_id, ok: false, error: message };
      }
      WorkerLogger.warn('Mp3', `MP3 cycle failed: ${message}`);
      return IDLE;
    } finally {
      for (const f of [wavPath, mp3Path]) {
        try {
          if (fs.existsSync(f)) fs.unlinkSync(f);
        } catch {
          /* temp file */
        }
      }
      this.running = false;
    }
  }
}
