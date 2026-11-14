import { SessionManager, Platform } from './automation/session-manager';
import { dispatchBrowserPost, PosterHandler, PostResult } from './automation/posters';
import { generateCard, CardResult } from './media/card-generator';
import { generateVideo, VideoResult } from './media/video-generator';
import { generateShortVideo, ShortSegment, ShortVideoResult, GenerateShortOptions } from './media/short-video';
import { WorkerLogger } from './logger';
import fs from 'fs';
import path from 'path';
import { SocialMonitor } from './monitor/monitor-client';
import { TrendingClient } from './trending/trending-client';

export interface ClaimedJob {
  id: number | string;
  platform: string;
  post_id?: number | string;
  post_title?: string;
  post_copy: string;
  site_name?: string;
  /** A TikTok short test render: render and save the video, never post it. */
  test_render?: boolean;
  /** On-screen title for the TikTok video (a short's hook); falls back to post_title. */
  video_title?: string;
  /** Set on TikTok short jobs: which story of the podcast this video covers. */
  tiktok_short?: {
    index: number;
    count: number;
    topic?: string;
    hook?: string;
    /** Story images for the short (9:16), shown in turn with a slow zoom or pan. */
    images?: string[];
    /** Spoken turns with their start and end in seconds, for the synced captions. */
    segments?: ShortSegment[];
    /** Background music for the story's mood (absent for no music). */
    music_url?: string;
    /** Music level under the voice, 0-1. */
    music_volume?: number;
    /** Mood the plan chose for the story, for the logs. */
    mood?: string;
  };
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
  generateShortFn?: (params: GenerateShortOptions) => Promise<ShortVideoResult>;
  /** Social Monitor run alongside job polling (WordPress decides when a scan is due). */
  monitor?: SocialMonitor;
  /** Trending in Cyprus roundup reader, run after the monitor (WordPress decides when a run is due). */
  trending?: TrendingClient;
}

export interface PollStats {
  claimed: number;
  succeeded: number;
  failed: number;
}

function formatTime(d: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
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
  private readonly generateShortFn: (params: GenerateShortOptions) => Promise<ShortVideoResult>;
  private readonly monitor?: SocialMonitor;
  private readonly trending?: TrendingClient;
  private scansRunning = false;

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
    this.generateShortFn = options.generateShortFn || generateShortVideo;
    this.monitor = options.monitor;
    this.trending = options.trending;
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
    status: 'published' | 'failed' | 'test_rendered';
    external_post_id?: string;
    external_post_url?: string;
    error?: string;
    metadata?: Record<string, any>;
  }): Promise<void> {
    const url = `${this.wpUrl}/wp-json/presshub-social/v1/worker/complete`;
    WorkerLogger.info('Poller', `Reporting '${payload.status}' status for job #${payload.job_id} to WordPress...`, payload);
    try {
      const res = await this.fetchFn(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-PressHub-Secret': this.workerSecret,
          'Authorization': `Bearer ${this.workerSecret}`
        },
        body: JSON.stringify(payload)
      });
      let responseBody: any = null;
      try {
        if (res && typeof res.json === 'function') {
          responseBody = await res.json();
        }
      } catch {}
      if (res && typeof res.ok === 'boolean' && !res.ok) {
        WorkerLogger.warn(`Poller`, `Failed to report complete for job #${payload.job_id} to ${url}: HTTP ${res.status}`, responseBody);
      } else {
        WorkerLogger.info(`Poller`, `Job #${payload.job_id} completion reported successfully (status: ${payload.status}).`, responseBody);
      }
    } catch (err: any) {
      WorkerLogger.error(`Poller`, `Failed to report complete to ${url}: ${err.message}`, err);
    }
  }

  /**
   * Resolves media assets (featured image URL and podcast audio URL) for a job from WordPress.
   */
  public async resolveJobMedia(
    job: ClaimedJob
  ): Promise<{ imageUrl?: string; audioUrl?: string }> {
    let imageUrl = job.media_url ? String(job.media_url) : undefined;
    let audioUrl: string | undefined = job.audio_url ? String(job.audio_url) : undefined;

    if (job.post_id && (!imageUrl || !audioUrl)) {
      try {
        const endpoint = `${this.wpUrl.replace(/\/$/, '')}/wp-json/wp/v2/posts/${job.post_id}?_embed`;
        const res = await this.fetchFn(endpoint, {
          headers: { 'User-Agent': 'Mozilla/5.0 PressHub-Poller/1.0' }
        });
        if (res && res.ok) {
          const data: any = await res.json();
          if (!imageUrl && data?._embedded?.['wp:featuredmedia']?.[0]?.source_url) {
            imageUrl = data._embedded['wp:featuredmedia'][0].source_url;
          }
          if (!audioUrl && data?.content?.rendered) {
            const audioMatch = String(data.content.rendered).match(/<audio[^>]+src=["']([^"']+)["']/i);
            if (audioMatch && audioMatch[1]) {
              audioUrl = audioMatch[1];
            }
          }
        }
      } catch (err: any) {
        WorkerLogger.debug('Poller', `Error during REST API media resolution for post #${job.post_id}: ${err.message}`);
      }
    }
    WorkerLogger.debug('Poller', `Resolved media for job #${job.id}: imageUrl=${imageUrl || 'none'}, audioUrl=${audioUrl || 'none'}`);
    return { imageUrl, audioUrl };
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
    const timeStr = formatTime();

    try {
      const claimUrl = `${this.wpUrl}/wp-json/presshub-social/v1/worker/claim`;
      const sessions = this.getSessionStatuses();
      WorkerLogger.debug('PressHub Poller', `Active sessions reported: ${JSON.stringify(sessions)}`);

      const claimRes = await this.fetchFn(claimUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-PressHub-Secret': this.workerSecret,
          'Authorization': `Bearer ${this.workerSecret}`
        },
        body: JSON.stringify({
          sessions,
          limit: 5
        })
      });

      if (!claimRes.ok) {
        WorkerLogger.warn('Poller', `Claim request failed with HTTP ${claimRes.status}`);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      let data: any;
      try {
        data = await claimRes.json();
      } catch (parseErr: any) {
        WorkerLogger.warn('Poller', `Failed to parse claim JSON response: ${parseErr.message}`);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      if (!data || !data.jobs || !Array.isArray(data.jobs)) {
        WorkerLogger.warn('Poller', `Claim response missing jobs array`);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      const jobs: ClaimedJob[] = data.jobs;
      if (jobs.length === 0) {
        WorkerLogger.debug('Poller', `Polled WordPress -> claimed 0 jobs (queue is empty or no due automation jobs)`);
        return { claimed: 0, succeeded: 0, failed: 0 };
      }

      const jobList = jobs.map((j) => `#${j.id} ${j.platform}`).join(', ');
      WorkerLogger.info('Poller', `Claimed ${jobs.length} jobs: [${jobList}]`, {
        count: jobs.length,
        jobs: jobs.map((j) => ({
          id: j.id,
          platform: j.platform,
          post_id: j.post_id,
          post_title: j.post_title,
          post_copy: j.post_copy,
          media_url: j.media_url,
          audio_url: j.audio_url,
          specs: j.specs
        }))
      });

      let succeeded = 0;
      let failed = 0;

      for (const job of jobs) {
        const jobTag = `[Job #${job.id} - ${job.platform}]`;
        WorkerLogger.info(
          'Poller',
          `${jobTag} Starting processing... Post ID: ${job.post_id || 'N/A'}, Title: "${job.post_title || ''}"`,
          {
            id: job.id,
            platform: job.platform,
            post_id: job.post_id,
            post_title: job.post_title,
            post_copy: job.post_copy,
            media_url: job.media_url,
            audio_url: job.audio_url,
            specs: job.specs
          }
        );
        try {
          const platform = (job.platform || '').toLowerCase().trim();
          const copy = job.post_copy || '';
          const title = job.post_title || 'PressHub';

          if (copy.trimStart().startsWith('[AI Copy Generation Failed:')) {
            const errMsg = 'Refusing to publish AI copy generation error placeholder.';
            WorkerLogger.error('Poller', `${jobTag} ${errMsg} Copy: ${copy}`);
            await this.reportComplete({
              job_id: job.id,
              status: 'failed',
              error: errMsg
            });
            failed++;
            continue;
          }

          let mediaPath: string | undefined;

          const { imageUrl, audioUrl } = await this.resolveJobMedia(job);
          const isPodcast = Boolean(
            title.toLowerCase().includes('podcast') ||
            copy.toLowerCase().includes('podcast')
          );

          if (platform === 'tiktok' && job.tiktok_short) {
            // A short is rendered with synced captions over its own story images. It
            // never falls back to the podcast's featured image.
            const short = job.tiktok_short;
            if (!audioUrl) {
              throw new Error('TikTok short has no audio_url.');
            }
            const images = Array.isArray(short.images) ? short.images.filter((u) => typeof u === 'string' && u !== '') : [];
            WorkerLogger.info(
              'Poller',
              `${jobTag} Rendering TikTok short ${short.index + 1}${Number(short.count) > 0 ? `/${short.count}` : ''} (${images.length} story image(s), ${Array.isArray(short.segments) ? short.segments.length : 0} timed turns, ${short.music_url ? `${short.mood || 'music'} music` : 'no music'})...`
            );
            const video = await this.generateShortFn({
              audioUrl,
              imageUrls: images,
              hook: String(job.video_title || short.hook || title),
              topic: String(short.topic || ''),
              badge: Number(short.count) > 0 ? `ΕΙΔΗΣΗ ${short.index + 1}/${short.count}` : `ΕΙΔΗΣΗ ${short.index + 1}`,
              siteName: job.site_name || 'PressHub.Cy',
              segments: Array.isArray(short.segments) ? short.segments : [],
              musicUrl: typeof short.music_url === 'string' && short.music_url !== '' ? short.music_url : undefined,
              musicVolume: typeof short.music_volume === 'number' ? short.music_volume : undefined,
              outputDir: this.outputDir
            });
            mediaPath = video?.filePath;
            WorkerLogger.info('Poller', `${jobTag} Short rendered at: ${mediaPath}`);

            if (job.test_render) {
              // Test render: keep the video for review and post nothing.
              if (!mediaPath || !fs.existsSync(mediaPath)) {
                throw new Error('Test render produced no video file.');
              }
              const testDir = path.join(this.outputDir || path.resolve(process.cwd(), 'output'), 'tiktok-short-tests');
              fs.mkdirSync(testDir, { recursive: true });
              const stamp = new Date().toISOString().replace(/[:.]/g, '-');
              const testFile = path.resolve(testDir, `post-${job.post_id || 0}-short-${short.index + 1}-${stamp}.mp4`);
              fs.renameSync(mediaPath, testFile);
              WorkerLogger.info('Poller', `${jobTag} Test render saved (not posted): ${testFile}`);
              await this.reportComplete({ job_id: job.id, status: 'test_rendered', metadata: { test_render_file: testFile } });
              succeeded++;
              continue;
            }
          } else if (platform === 'tiktok') {
            const hasAudio = Boolean(audioUrl);
            WorkerLogger.info(
              'Poller',
              `${jobTag} Generating vertical video (${job.specs?.tiktok_video_width || 1080}x${job.specs?.tiktok_video_height || 1920}${hasAudio ? ', with audio track' : `, ${job.specs?.tiktok_video_duration || 10}s`})...`
            );
            const video = await this.generateVideoFn({
              postId: Number(job.post_id) || 1,
              title,
              subtitle: copy.slice(0, 120),
              durationSec: job.specs?.tiktok_video_duration || 10,
              width: job.specs?.tiktok_video_width || 1080,
              height: job.specs?.tiktok_video_height || 1920,
              mediaUrl: imageUrl,
              audioUrl: audioUrl,
              isPodcast,
              siteName: job.site_name || 'PressHub.Cy',
              outputDir: this.outputDir
            });
            mediaPath = video?.filePath;
            WorkerLogger.info('Poller', `${jobTag} Video generated at: ${mediaPath}`);
          } else if (platform === 'instagram') {
            WorkerLogger.info('Poller', `${jobTag} Generating square card (${job.specs?.instagram_card_width || 1080}x${job.specs?.instagram_card_height || 1080})...`);
            const card = await this.generateCardFn({
              postId: Number(job.post_id) || 1,
              title,
              siteName: job.site_name || 'PressHub.Cy',
              mediaUrl: imageUrl,
              isPodcast,
              width: job.specs?.instagram_card_width || 1080,
              height: job.specs?.instagram_card_height || 1080,
              outputDir: this.outputDir
            });
            mediaPath = card?.filePath;
            WorkerLogger.info('Poller', `${jobTag} Card generated at: ${mediaPath}`);
          }

          WorkerLogger.info('Poller', `${jobTag} Dispatching browser automation poster...`);
          const result: PostResult = await this.posterHandler({
            platform: platform as Platform,
            copy,
            mediaPath,
            sessionsDir: this.sessionsDir,
            // Shorts use an AI voice and AI images: TikTok requires the AI-generated label.
            ...(platform === 'tiktok' && job.tiktok_short ? { aiGenerated: true } : {})
          });

          if (result && result.success) {
            const extUrl = result.external_url || result.externalPostUrl || result.external_id || result.externalPostId;
            WorkerLogger.info('Poller', `${jobTag} Automation succeeded! External URL: ${extUrl}`);
            WorkerLogger.info('Poller', `${jobTag} Reporting 'published' status to WordPress...`);
            await this.reportComplete({
              job_id: job.id,
              status: 'published',
              external_post_id: result.external_id || result.externalPostId || '',
              external_post_url: result.external_url || result.externalPostUrl || ''
            });
            succeeded++;
          } else {
            WorkerLogger.warn('Poller', `${jobTag} Automation failed: ${result?.error || 'Browser automation failed'}`);
            WorkerLogger.info('Poller', `${jobTag} Reporting 'failed' status to WordPress...`);
            await this.reportComplete({
              job_id: job.id,
              status: 'failed',
              error: result?.error || 'Browser automation failed'
            });
            failed++;
          }
        } catch (jobErr: any) {
          WorkerLogger.error('Poller', `${jobTag} Error executing job #${job.id}: ${jobErr.message}`, jobErr);
          try {
            WorkerLogger.info('Poller', `${jobTag} Reporting 'failed' status to WordPress...`);
            await this.reportComplete({
              job_id: job.id,
              status: 'failed',
              error: jobErr.message || 'Worker automation failed'
            });
          } catch (reportErr: any) {
            WorkerLogger.error('Poller', `${jobTag} Failed to report failure for job #${job.id}: ${reportErr.message}`, reportErr);
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
      WorkerLogger.warn('Poller', `Error during poll cycle: ${err.message}`);
      return { claimed: 0, succeeded: 0, failed: 0 };
    } finally {
      this.isPolling = false;
    }
  }

  /**
   * Kicks off a Social Monitor cycle in the background. A scan can take several
   * minutes, so it never holds up posting jobs; the monitor skips a cycle while
   * the previous one is still running.
   */
  private runMonitor(): void {
    if ((!this.monitor && !this.trending) || this.scansRunning) return;
    this.scansRunning = true;
    // One browser scan at a time: the monitor first, then the trending roundup.
    (async () => {
      if (this.monitor) {
        await this.monitor.runOnce().catch((err: any) => {
          WorkerLogger.warn('Poller', `Social monitor cycle failed: ${err.message}`);
        });
      }
      if (this.trending) {
        await this.trending.runOnce().catch((err: any) => {
          WorkerLogger.warn('Poller', `Trending cycle failed: ${err.message}`);
        });
      }
    })().finally(() => {
      this.scansRunning = false;
    });
  }

  /**
   * Starts periodic polling in background mode.
   */
  public start(): void {
    if (this.isRunning) {
      return;
    }
    this.isRunning = true;
    WorkerLogger.info('Poller', `Pull mode active -> polling ${this.wpUrl} every ${this.pollIntervalMs / 1000}s`);

    // Run immediate poll
    this.pollOnce().catch(err => {
      WorkerLogger.error('Poller', `Immediate poll failed: ${err.message}`, err);
    });
    this.runMonitor();

    this.pollTimer = setInterval(async () => {
      if (!this.isRunning) return;
      this.runMonitor();
      try {
        await this.pollOnce();
      } catch (err: any) {
        WorkerLogger.error('Poller', `Scheduled poll failed: ${err.message}`, err);
      }
    }, this.pollIntervalMs);

    // In test suites, unref timer so Jest can exit cleanly; in production, keep the event loop alive
    if (process.env.NODE_ENV === 'test' && this.pollTimer.unref) {
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
    WorkerLogger.info('Poller', 'Pull mode poller stopped.');
  }

  /**
   * Returns current running status.
   */
  public getRunningStatus(): boolean {
    return this.isRunning;
  }
}
