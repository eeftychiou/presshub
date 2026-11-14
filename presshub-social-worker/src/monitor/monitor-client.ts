/**
 * Social Monitor client.
 *
 * Asks WordPress whether a scan is due, runs the browser scan, sends the posts
 * back, and uploads the videos WordPress asks for (for transcription).
 */
import { WorkerLogger } from '../logger';
import { toWirePost, ScannedPost } from './parsers';
import { browserScan, ScanFn, ScanPlan, ScanSession } from './scanner';
import { LocalTranscriber, localTranscriberConfigFromEnv } from './local-transcriber';
import fs from 'fs';
import os from 'os';
import path from 'path';

export interface MonitorOptions {
  wpUrl: string;
  workerSecret: string;
  fetchFn?: typeof fetch;
  scanFn?: ScanFn;
  /** Local whisper transcriber for long videos; default from the environment, null to turn off. */
  transcriber?: LocalTranscriber | null;
}

export interface MonitorRunResult {
  ran: boolean;
  scanned: number;
  accepted: number;
  media_uploaded: number;
  errors: number;
}

const IDLE: MonitorRunResult = { ran: false, scanned: 0, accepted: 0, media_uploaded: 0, errors: 0 };

/** Temp videos older than this were left by a worker that stopped mid-queue. */
const STALE_VIDEO_MS = 12 * 3600 * 1000;

/** Remove `presshub-long-*` temp videos left behind by an earlier run. */
export function removeStaleVideos(dir: string = os.tmpdir(), now: number = Date.now()): number {
  let removed = 0;
  try {
    for (const name of fs.readdirSync(dir)) {
      if (!/^presshub-long-.*\.mp4$/.test(name)) continue;
      const file = path.join(dir, name);
      try {
        if (now - fs.statSync(file).mtimeMs > STALE_VIDEO_MS) {
          fs.rmSync(file, { force: true });
          removed++;
        }
      } catch {
        // Gone already or in use; leave it.
      }
    }
  } catch {
    // Temp folder unreadable; nothing to clean.
  }
  return removed;
}

export class SocialMonitor {
  private readonly wpUrl: string;
  private readonly secret: string;
  private readonly fetchFn: typeof fetch;
  private readonly scanFn: ScanFn;
  private readonly transcriber: LocalTranscriber | null;
  private running = false;
  /** Local transcriptions run one at a time, in the background, so scans and posting are not held up. */
  private transcribeQueue: Promise<void> = Promise.resolve();

  constructor(options: MonitorOptions) {
    this.wpUrl = options.wpUrl.trim().replace(/\/+$/, '');
    this.secret = options.workerSecret.trim();
    this.fetchFn = options.fetchFn || globalThis.fetch;
    this.scanFn = options.scanFn || browserScan;
    if (options.transcriber !== undefined) {
      this.transcriber = options.transcriber;
    } else {
      const cfg = localTranscriberConfigFromEnv();
      this.transcriber = cfg ? new LocalTranscriber(cfg) : null;
    }
    if (this.transcriber) {
      removeStaleVideos();
      WorkerLogger.info('Social Monitor', `Local transcription on: videos up to ${Math.round(this.transcriber.config.maxSec / 60)} min with whisper.cpp (${path.basename(this.transcriber.config.model)}, ${this.transcriber.config.threads} threads).`);
    }
  }

  /** Resolves when queued local transcriptions are done (tests, shutdown). */
  public idle(): Promise<void> {
    return this.transcribeQueue;
  }

  private headers(extra: Record<string, string> = {}): Record<string, string> {
    return {
      'X-PressHub-Secret': this.secret,
      Authorization: `Bearer ${this.secret}`,
      ...extra
    };
  }

  private endpoint(path: string): string {
    return `${this.wpUrl}/wp-json/presshub-social/v1/monitor/${path}`;
  }

  /** Fetch the scan plan from WordPress, or null when unavailable. */
  public async fetchPlan(force = false): Promise<ScanPlan | null> {
    const res = await this.fetchFn(this.endpoint('targets') + (force ? '?force=1' : ''), { headers: this.headers() });
    if (!res.ok) {
      if (res.status !== 404) {
        WorkerLogger.warn('Social Monitor', `Targets request failed with HTTP ${res.status}`);
      }
      return null;
    }
    const plan: any = await res.json();
    if (!plan || typeof plan !== 'object' || !Array.isArray(plan.targets)) {
      return null;
    }
    return plan as ScanPlan;
  }

  /**
   * One monitor cycle. Does nothing unless WordPress says a scan is due.
   */
  public async runOnce(force = false): Promise<MonitorRunResult> {
    if (this.running) return IDLE;
    this.running = true;
    try {
      const plan = await this.fetchPlan(force);
      if (!plan || !plan.enabled || !plan.due || plan.targets.length === 0) {
        return IDLE;
      }
      WorkerLogger.info('Social Monitor', `Scanning ${plan.targets.length} account(s)...`);
      if (this.transcriber && plan.transcribe_videos) {
        plan.local_max_mb = this.transcriber.config.maxMb;
      }
      const session = await this.scanFn(plan);
      try {
        const postRes = await this.fetchFn(this.endpoint('posts'), {
          method: 'POST',
          headers: this.headers({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({
            posts: session.posts.map(toWirePost),
            errors: session.errors,
            ...(this.transcriber && plan.transcribe_videos ? { local_transcribe: { max_sec: this.transcriber.config.maxSec } } : {})
          })
        });
        if (!postRes.ok) {
          WorkerLogger.warn('Social Monitor', `Posting results failed with HTTP ${postRes.status}`);
          return { ran: true, scanned: session.posts.length, accepted: 0, media_uploaded: 0, errors: session.errors.length };
        }
        const result: any = await postRes.json().catch(() => ({}));
        const accepted = Number(result?.accepted || 0);
        const wants: string[] = Array.isArray(result?.wants_media) ? result.wants_media.map(String) : [];
        const wantsLocal: string[] = this.transcriber && Array.isArray(result?.wants_transcript) ? result.wants_transcript.map(String) : [];
        WorkerLogger.info('Social Monitor', `Sent ${session.posts.length} post(s): ${accepted} new, ${wants.length} video(s) requested${wantsLocal.length ? `, ${wantsLocal.length} long video(s) to transcribe here` : ''}.`);

        let uploaded = 0;
        if (plan.transcribe_videos) {
          for (const storeId of wants) {
            const post = this.findPost(session.posts, storeId);
            if (!post) {
              await this.fetchFn(this.endpoint('media') + `?id=${encodeURIComponent(storeId)}&failed=not_in_scan`, {
                method: 'POST',
                headers: this.headers()
              }).catch(() => undefined);
              continue;
            }
            const media = await session.download(post);
            if (!media) {
              WorkerLogger.info('Social Monitor', `Could not download the video for ${storeId}; it stays text-only.`);
              // Tell WordPress so the harvest does not wait for this transcript.
              await this.fetchFn(this.endpoint('media') + `?id=${encodeURIComponent(storeId)}&failed=download`, {
                method: 'POST',
                headers: this.headers()
              }).catch(() => undefined);
              continue;
            }
            const q = `?id=${encodeURIComponent(storeId)}&mime=${encodeURIComponent(media.mime)}`;
            const up = await this.fetchFn(this.endpoint('media') + q, {
              method: 'POST',
              headers: this.headers({ 'Content-Type': media.mime }),
              body: media.bytes as any
            });
            if (up.ok) {
              uploaded++;
            } else {
              WorkerLogger.warn('Social Monitor', `Video upload for ${storeId} failed with HTTP ${up.status}`);
            }
          }
        }
        if (wantsLocal.length) {
          await this.queueLocalTranscripts(session, wantsLocal);
        }
        return { ran: true, scanned: session.posts.length, accepted, media_uploaded: uploaded, errors: session.errors.length };
      } finally {
        await session.close().catch(() => {});
      }
    } catch (err: any) {
      WorkerLogger.warn('Social Monitor', `Monitor cycle failed: ${err.message}`);
      return IDLE;
    } finally {
      this.running = false;
    }
  }

  private async reportMediaFailed(storeId: string, reason: string): Promise<void> {
    await this.fetchFn(this.endpoint('media') + `?id=${encodeURIComponent(storeId)}&failed=${encodeURIComponent(reason)}`, {
      method: 'POST',
      headers: this.headers()
    }).catch(() => undefined);
  }

  /**
   * Download the long videos now (the browser session closes after the scan),
   * save them to temp files, and queue whisper on each. Transcripts go to
   * POST /monitor/transcript as they finish.
   */
  private async queueLocalTranscripts(session: ScanSession, storeIds: string[]): Promise<void> {
    const t = this.transcriber as LocalTranscriber;
    for (const storeId of storeIds) {
      const post = this.findPost(session.posts, storeId);
      if (!post) {
        await this.reportMediaFailed(storeId, 'not_in_scan');
        continue;
      }
      const media = await session.download(post, { maxBytes: t.config.maxMb * 1024 * 1024, smallestFirst: true });
      if (!media) {
        WorkerLogger.info('Social Monitor', `Could not download the long video for ${storeId}; it stays text-only.`);
        await this.reportMediaFailed(storeId, 'download');
        continue;
      }
      const file = path.join(os.tmpdir(), `presshub-long-${storeId.replace(/[^A-Za-z0-9_]/g, '')}-${Date.now()}.mp4`);
      fs.writeFileSync(file, media.bytes);
      const duration = post.media?.duration_sec || 0;
      this.transcribeQueue = this.transcribeQueue.then(() => this.transcribeOne(storeId, file, duration));
    }
  }

  private async transcribeOne(storeId: string, file: string, durationSec: number): Promise<void> {
    const t = this.transcriber as LocalTranscriber;
    const started = Date.now();
    try {
      WorkerLogger.info('Social Monitor', `Transcribing ${storeId} locally (${Math.round(durationSec / 60)} min of video)...`);
      const text = await t.transcribeFile(file, durationSec);
      const res = await this.fetchFn(this.endpoint('transcript'), {
        method: 'POST',
        headers: this.headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ id: storeId, text, engine: 'whisper.cpp' })
      });
      const secs = Math.round((Date.now() - started) / 1000);
      if (res.ok) {
        WorkerLogger.info('Social Monitor', `Local transcript for ${storeId}: ${text.length} chars in ${secs}s.`);
      } else {
        WorkerLogger.warn('Social Monitor', `Sending the local transcript for ${storeId} failed with HTTP ${res.status}`);
        // 404: WordPress no longer holds the post, so nothing waits for it.
        if (res.status !== 404) await this.reportMediaFailed(storeId, 'local_transcribe');
      }
    } catch (err: any) {
      WorkerLogger.warn('Social Monitor', `Local transcription failed for ${storeId}: ${err.message}`);
      await this.reportMediaFailed(storeId, 'local_transcribe');
    } finally {
      fs.rmSync(file, { force: true });
    }
  }

  /** WordPress store ids look like `<platform>_<id>` (optionally prefixed `sm_`). */
  private findPost(posts: ScannedPost[], storeId: string): ScannedPost | undefined {
    const bare = storeId.replace(/^sm_/, '');
    return posts.find((p) => `${p.platform}_${p.id}` === bare);
  }
}
