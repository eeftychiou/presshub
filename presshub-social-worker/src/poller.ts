import { SessionManager, Platform } from './automation/session-manager';
import { dispatchBrowserPost, PosterHandler, PostResult } from './automation/posters';
import { generateCard, CardResult } from './media/card-generator';
import { generateVideo, VideoResult } from './media/video-generator';

export interface ClaimedJob {
  id: number | string;
  platform: string;
  post_id?: number | string;
  post_title?: string;
  post_copy: string;
  site_name?: string;
  specs?: {
    tiktok_video_duration?: number;
    tiktok_video_width?: number;
    tiktok_video_height?: number;
    instagram_card_width?: number;
    instagram_card_height?: number;
    [key: string]: any;
  };
  [key: string]: any;
}

export interface PollerOptions {
  wpUrl: string;
  workerSecret: string;
  pollIntervalMs?: number;
  sessionsDir?: string;
  outputDir?: string;
  fetchFn?: typeof fetch;
  posterHandler?: PosterHandler;
  generateCardFn?: (params: any) => Promise<CardResult>;
  generateVideoFn?: (params: any) => Promise<VideoResult>;
}

export interface PollStats {
  claimed: number;
  succeeded: number;
  failed: number;
}

/**
 * PressHubPoller
 * Pull Mode background polling client.
 * Polls the WordPress site for queued jobs, renders required media,
 * executes browser automation, and reports completion status back to WordPress.
 */
export class PressHubPoller {
  public readonly wpUrl: string;
  public readonly workerSecret: string;
  public readonly pollIntervalMs: number;
  public readonly sessionsDir?: string;
  public readonly outputDir?: string;
  private readonly fetchFn: typeof fetch;
  private readonly posterHandler: PosterHandler;
  private readonly generateCardFn: (params: any) => Promise<CardResult>;
  private readonly generateVideoFn: (params: any) => Promise<VideoResult>;

  private pollTimer?: NodeJS.Timeout;
  private isRunning: boolean = false;
  private isPolling: boolean = false;

  constructor(options: PollerOptions) {
    if (!options.wpUrl) {
      throw new Error('wpUrl is required for PressHubPoller');
    }
    if (!options.workerSecret) {
      throw new Error('workerSecret is required for PressHubPoller');
    }

    this.wpUrl = options.wpUrl.trim().replace(/\/+$/, '');
    this.workerSecret = options.workerSecret.trim();
    this.pollIntervalMs = typeof options.pollIntervalMs === 'number' && options.pollIntervalMs > 0
      ? options.pollIntervalMs
      : 30000;
    this.sessionsDir = options.sessionsDir;
    this.outputDir = options.outputDir;
    this.fetchFn = options.fetchFn || globalThis.fetch;
    this.posterHandler = options.posterHandler || dispatchBrowserPost;
    this.generateCardFn = options.generateCardFn || generateCard;
    this.generateVideoFn = options.generateVideoFn || generateVideo;
  }

  /**
   * Checks browser session validity for all supported platforms.
   */
  public getSessionStatuses(): Record<string, boolean> {
    const platforms = ['twitter', 'tiktok', 'facebook', 'instagram'];
    const statuses: Record<string, boolean> = {};

    for (const platform of platforms) {
      const verification = SessionManager.verifySession(platform, this.sessionsDir);
      statuses[platform] = Boolean(verification.valid);
    }

    return statuses;
  }

  /**
   * Reports job completion or failure back to the WordPress REST API endpoint.
   */
  private async reportComplete(payload: {
    job_id: number | string;
    status: 'published' | 'failed';
    external_post_id?: string;
    external_post_url?: string;
    error?: string;
  }): Promise<void> {
    const url = `${this.wpUrl}/wp-json/presshub-social/v1/worker/complete`;
    try {
      await this.fetchFn(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-PressHub-Secret': this.workerSecret,
          'Authorization': `Bearer ${this.workerSecret}`
        },
        body: JSON.stringify(payload)
      });
    } catch (err: any) {
      console.error(`[PressHub Poller] Failed to report complete to ${url}:`, err.message);
    }
  }

  /**
   * Executes a single poll cycle: claims jobs from WordPress, executes them,
   * and reports results back to WordPress.
   */
  public async pollOnce(): Promise<PollStats> {
    if (this.isPolling) {
      return { claimed: 0, succeeded: 0, failed: 0 };
    }
    this.isPolling = true;

    try {
      const claimUrl = `${this.wpUrl}/wp-json/presshub-social/v1/worker/claim`;
      const claimRes = await this.fetchFn(claimUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-PressHub-Secret': this.workerSecret,
          'Authorization': `Bearer ${this.workerSecret}`
        },
        body: JSON.stringify({
          sessions: this.getSessionStatuses(),
          limit: 5
        })
      });

      if (!claimRes.ok) {
        console.warn(`[PressHub Poller] Claim request failed with HTTP ${claimRes.status}`);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      let data: any;
      try {
        data = await claimRes.json();
      } catch (parseErr: any) {
        console.warn('[PressHub Poller] Failed to parse claim JSON response:', parseErr.message);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      if (!data || !data.jobs || !Array.isArray(data.jobs)) {
        console.warn('[PressHub Poller] Claim response missing jobs array');
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      const jobs: ClaimedJob[] = data.jobs;
      if (jobs.length === 0) {
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      let succeeded = 0;
      let failed = 0;

      for (const job of jobs) {
        try {
          const platform = (job.platform || '').toLowerCase().trim();
          const copy = job.post_copy || '';
          const title = job.post_title || 'PressHub';

          let mediaPath: string | undefined;

          if (platform === 'tiktok') {
            const video = await this.generateVideoFn({
              postId: Number(job.post_id) || 1,
              title,
              subtitle: copy.slice(0, 120),
              durationSec: job.specs?.tiktok_video_duration || 10,
              width: job.specs?.tiktok_video_width || 1080,
              height: job.specs?.tiktok_video_height || 1920,
              outputDir: this.outputDir
            });
            mediaPath = video?.filePath;
          } else if (platform === 'instagram') {
            const card = await this.generateCardFn({
              postId: Number(job.post_id) || 1,
              title,
              siteName: job.site_name || 'PressHub',
              width: job.specs?.instagram_card_width || 1080,
              height: job.specs?.instagram_card_height || 1080,
              outputDir: this.outputDir
            });
            mediaPath = card?.filePath;
          }

          const result: PostResult = await this.posterHandler({
            platform: platform as Platform,
            copy,
            mediaPath,
            sessionsDir: this.sessionsDir
          });

          if (result && result.success) {
            await this.reportComplete({
              job_id: job.id,
              status: 'published',
              external_post_id: result.external_id || result.externalPostId || '',
              external_post_url: result.external_url || result.externalPostUrl || ''
            });
            succeeded++;
          } else {
            await this.reportComplete({
              job_id: job.id,
              status: 'failed',
              error: result?.error || 'Browser automation failed'
            });
            failed++;
          }
        } catch (jobErr: any) {
          console.error(`[PressHub Poller] Error executing job #${job.id}:`, jobErr.message);
          try {
            await this.reportComplete({
              job_id: job.id,
              status: 'failed',
              error: jobErr.message || 'Worker automation failed'
            });
          } catch (reportErr: any) {
            console.error(`[PressHub Poller] Failed to report failure for job #${job.id}:`, reportErr.message);
          }
          failed++;
        }
      }

      return {
        claimed: jobs.length,
        succeeded,
        failed
      };
    } catch (err: any) {
      console.warn('[PressHub Poller] Error during poll cycle:', err.message);
      return { claimed: 0, succeeded: 0, failed: 0 };
    } finally {
      this.isPolling = false;
    }
  }

  /**
   * Starts periodic polling in background mode.
   */
  public start(): void {
    if (this.isRunning) {
      return;
    }
    this.isRunning = true;
    console.log(`[PressHub Worker] Pull mode active -> polling ${this.wpUrl} every ${this.pollIntervalMs / 1000}s`);

    // Run immediate poll
    this.pollOnce().catch(err => {
      console.error('[PressHub Poller] Immediate poll failed:', err.message);
    });

    this.pollTimer = setInterval(async () => {
      if (!this.isRunning) return;
      try {
        await this.pollOnce();
      } catch (err: any) {
        console.error('[PressHub Poller] Scheduled poll failed:', err.message);
      }
    }, this.pollIntervalMs);

    if (this.pollTimer.unref) {
      this.pollTimer.unref();
    }
  }

  /**
   * Stops the background poller and clears interval timers.
   */
  public stop(): void {
    this.isRunning = false;
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = undefined;
    }
    console.log('[PressHub Worker] Pull mode poller stopped.');
  }

  /**
   * Returns current running status.
   */
  public getRunningStatus(): boolean {
    return this.isRunning;
  }
}
