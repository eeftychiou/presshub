/**
 * Social Monitor client.
 *
 * Asks WordPress whether a scan is due, runs the browser scan, sends the posts
 * back, and uploads the videos WordPress asks for (for transcription).
 */
import { WorkerLogger } from '../logger';
import { toWirePost, ScannedPost } from './parsers';
import { browserScan, ScanFn, ScanPlan } from './scanner';

export interface MonitorOptions {
  wpUrl: string;
  workerSecret: string;
  fetchFn?: typeof fetch;
  scanFn?: ScanFn;
}

export interface MonitorRunResult {
  ran: boolean;
  scanned: number;
  accepted: number;
  media_uploaded: number;
  errors: number;
}

const IDLE: MonitorRunResult = { ran: false, scanned: 0, accepted: 0, media_uploaded: 0, errors: 0 };

export class SocialMonitor {
  private readonly wpUrl: string;
  private readonly secret: string;
  private readonly fetchFn: typeof fetch;
  private readonly scanFn: ScanFn;
  private running = false;

  constructor(options: MonitorOptions) {
    this.wpUrl = options.wpUrl.trim().replace(/\/+$/, '');
    this.secret = options.workerSecret.trim();
    this.fetchFn = options.fetchFn || globalThis.fetch;
    this.scanFn = options.scanFn || browserScan;
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
      const session = await this.scanFn(plan);
      try {
        const postRes = await this.fetchFn(this.endpoint('posts'), {
          method: 'POST',
          headers: this.headers({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ posts: session.posts.map(toWirePost), errors: session.errors })
        });
        if (!postRes.ok) {
          WorkerLogger.warn('Social Monitor', `Posting results failed with HTTP ${postRes.status}`);
          return { ran: true, scanned: session.posts.length, accepted: 0, media_uploaded: 0, errors: session.errors.length };
        }
        const result: any = await postRes.json().catch(() => ({}));
        const accepted = Number(result?.accepted || 0);
        const wants: string[] = Array.isArray(result?.wants_media) ? result.wants_media.map(String) : [];
        WorkerLogger.info('Social Monitor', `Sent ${session.posts.length} post(s): ${accepted} new, ${wants.length} video(s) requested.`);

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

  /** WordPress store ids look like `<platform>_<id>` (optionally prefixed `sm_`). */
  private findPost(posts: ScannedPost[], storeId: string): ScannedPost | undefined {
    const bare = storeId.replace(/^sm_/, '');
    return posts.find((p) => `${p.platform}_${p.id}` === bare);
  }
}
