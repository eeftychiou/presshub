import { SessionManager, Platform } from './automation/session-manager';
import { dispatchBrowserPost, PosterHandler, PostResult } from './automation/posters';
import { generateCard, CardResult } from './media/card-generator';
import { generateVideo, VideoResult } from './media/video-generator';
import { generateShortVideo, ShortSegment, ShortVideoResult, GenerateShortOptions, siteEndCardLine, YOUTUBE_SHORT_MAX_SECONDS, CardSpan } from './media/short-video';
import { PostCard } from './media/post-card';
import { generateEpisodeVideo, GenerateEpisodeOptions, EpisodeVideoResult } from './media/episode-video';
import { generatePhotoSlides, GeneratePhotoSlidesOptions, PhotoSlidesResult, removePhotoSlides } from './media/photo-slides';
import { WorkerLogger } from './logger';
import fs from 'fs';
import path from 'path';
import { SocialMonitor } from './monitor/monitor-client';
import { TrendingClient } from './trending/trending-client';
import { Mp3Converter } from './media/mp3-converter';
import { uploadToYouTube, YouTubeUploadMeta, YouTubeUploadOptions, YouTubeUploadResult } from './youtube/uploader';
import { findUpload, recordUpload } from './youtube/upload-log';
import { ShortRenderCache, shortRenderKey, SHORT_CACHE_DIRNAME, DEFAULT_SHORT_CACHE_TTL_MS } from './media/short-cache';
import { uploadFacebookReel, FacebookReelOptions, FacebookReelResult } from './facebook/reels-uploader';

/** Longest video X takes from a standard (non-Premium) account: 2 minutes 20 seconds. */
export const TWITTER_VIDEO_MAX_SECONDS = 140;

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
    /** 'article' for the one short of an article (not a podcast story). */
    source?: string;
    /** On-screen badge set by the site (e.g. the article's category); replaces ΕΙΔΗΣΗ n/N. */
    badge?: string;
    /** X: longest video the account may post, in seconds (default TWITTER_VIDEO_MAX_SECONDS). */
    max_seconds?: number;
    /** Trending roundup: the social media posts the voice talks about, drawn as post cards. */
    cards?: PostCard[];
    /** When each card is on screen, in seconds of the voice track. */
    card_spans?: CardSpan[];
  };
  /**
   * Set on TikTok photo slide jobs (#333): a short's hook and script as text on photo
   * slides, posted as a TikTok photo post with a sound found for the story's mood.
   */
  tiktok_slides?: {
    index: number;
    count: number;
    topic?: string;
    hook?: string;
    /** Script texts, one per slide (the worker adds the title and closing slides). */
    slides?: string[];
    images?: string[];
    mood?: string;
    /** Words to search in TikTok's sound picker ('' keeps the sound TikTok adds). */
    sound_query?: string;
    /** Most slides in all, title and closing slide included. */
    max_slides?: number;
    source?: string;
    badge?: string;
  };
  /** Set on YouTube jobs by WordPress: what to upload and how (title, description, playlist...). */
  youtube?: YouTubeUploadMeta;
  /**
   * Set on Facebook Reel jobs by WordPress: `api` uploads the rendered short through the
   * Graph API (Page token from WordPress), `browser` posts it with the browser session.
   */
  facebook_reel?: { mode?: 'api' | 'browser' };
  /** AI post images for X and Facebook (all of them; media_url is the first). */
  media_urls?: string[];
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
  /**
   * How long a rendered short is kept for the story's other platforms (ms, default 24 h);
   * 0 renders every platform's copy again and removes it after posting.
   */
  shortCacheTtlMs?: number;
  generateEpisodeFn?: (params: GenerateEpisodeOptions) => Promise<EpisodeVideoResult>;
  /** Renders the slides of a TikTok photo post (tests inject a fake). */
  generateSlidesFn?: (params: GeneratePhotoSlidesOptions) => Promise<PhotoSlidesResult>;
  /** Uploads a rendered video to YouTube (tests inject a fake). */
  youtubeUploadFn?: (options: YouTubeUploadOptions) => Promise<YouTubeUploadResult>;
  /** Uploads a rendered short as a Facebook Reel through the Graph API (tests inject a fake). */
  facebookReelUploadFn?: (options: FacebookReelOptions) => Promise<FacebookReelResult>;
  /** Tries of a YouTube "published" report (default 5) and the first wait between them (ms). */
  reportTries?: number;
  reportRetryDelayMs?: number;
  reportSleepFn?: (ms: number) => Promise<void>;
  /** Social Monitor run alongside job polling (WordPress decides when a scan is due). */
  monitor?: SocialMonitor;
  /** Trending in Cyprus roundup reader, run after the monitor (WordPress decides when a run is due). */
  trending?: TrendingClient;
  /** Converts WAV podcast episodes to MP3, one per poll, beside job polling. */
  mp3?: Mp3Converter;
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
  private readonly shortCacheTtlMs: number;
  private shortCacheInstance?: ShortRenderCache;
  private readonly generateEpisodeFn: (params: GenerateEpisodeOptions) => Promise<EpisodeVideoResult>;
  private readonly generateSlidesFn: (params: GeneratePhotoSlidesOptions) => Promise<PhotoSlidesResult>;
  private readonly youtubeUploadFn: (options: YouTubeUploadOptions) => Promise<YouTubeUploadResult>;
  private readonly facebookReelUploadFn: (options: FacebookReelOptions) => Promise<FacebookReelResult>;
  private readonly reportTries: number;
  private readonly reportRetryDelayMs: number;
  private readonly reportSleepFn: (ms: number) => Promise<void>;
  private readonly monitor?: SocialMonitor;
  private readonly trending?: TrendingClient;
  private readonly mp3?: Mp3Converter;
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
    this.shortCacheTtlMs = typeof options.shortCacheTtlMs === 'number' ? Math.max(0, options.shortCacheTtlMs) : DEFAULT_SHORT_CACHE_TTL_MS;
    this.generateEpisodeFn = options.generateEpisodeFn || generateEpisodeVideo;
    this.generateSlidesFn = options.generateSlidesFn || generatePhotoSlides;
    this.youtubeUploadFn = options.youtubeUploadFn || uploadToYouTube;
    this.facebookReelUploadFn = options.facebookReelUploadFn || uploadFacebookReel;
    this.reportTries = Math.max(1, options.reportTries ?? 5);
    this.reportRetryDelayMs = Math.max(0, options.reportRetryDelayMs ?? 2000);
    this.reportSleepFn = options.reportSleepFn || ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)));
    this.monitor = options.monitor;
    this.trending = options.trending;
    this.mp3 = options.mp3;
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
    // YouTube needs no browser login here: this worker uploads through the API with a
    // token from WordPress, which only hands out YouTube jobs while a channel is connected.
    statuses.youtube = true;

    return statuses;
  }

  /**
   * Folder of the local YouTube upload record. Not relative to the current folder, so a
   * worker started from elsewhere still finds the record.
   */
  private uploadLogDir(): string {
    return this.outputDir ? path.resolve(this.outputDir) : path.resolve(__dirname, '..', 'output');
  }

  /**
   * Reports an uploaded YouTube video, trying a few times: a lost report would leave the
   * job "processing" with the video already on YouTube.
   */
  private async reportYouTubePublished(job: ClaimedJob, videoId: string, url: string, kind: string, playlistItemId?: string, playlistError?: string, episodeNote?: string): Promise<void> {
    const payload = {
      job_id: job.id,
      status: 'published' as const,
      external_post_id: videoId,
      external_post_url: url,
      metadata: {
        youtube_kind: kind,
        ...(playlistItemId ? { youtube_playlist_item_id: playlistItemId } : {}),
        ...(playlistError ? { youtube_playlist_error: playlistError } : {}),
        ...(episodeNote ? { youtube_episode_note: episodeNote } : {})
      }
    };
    for (let attempt = 1; attempt <= this.reportTries; attempt++) {
      if (await this.reportComplete(payload)) {
        return;
      }
      if (attempt < this.reportTries) {
        await this.reportSleepFn(this.reportRetryDelayMs * 2 ** (attempt - 1));
      }
    }
    WorkerLogger.error('Poller', `[Job #${job.id}] YouTube video ${url} is uploaded, but WordPress could not be told. It is reported again (not re-uploaded) when the job is handed out next.`);
  }

  /**
   * Reports a published Facebook Reel, trying a few times: a lost report would leave the
   * job "processing" with the Reel already on the Page.
   */
  private async reportReelPublished(job: ClaimedJob, videoId: string, url: string, note?: string): Promise<void> {
    const payload = {
      job_id: job.id,
      status: 'published' as const,
      external_post_id: videoId,
      external_post_url: url,
      metadata: {
        facebook_reel_mode: 'api',
        ...(note ? { facebook_reel_note: note } : {})
      }
    };
    for (let attempt = 1; attempt <= this.reportTries; attempt++) {
      if (await this.reportComplete(payload)) {
        return;
      }
      if (attempt < this.reportTries) {
        await this.reportSleepFn(this.reportRetryDelayMs * 2 ** (attempt - 1));
      }
    }
    WorkerLogger.error('Poller', `[Job #${job.id}] Facebook Reel ${url} is published, but WordPress could not be told. It is reported again (not re-uploaded) when the job is handed out next.`);
  }

  /**
   * Reports job completion or failure back to the WordPress REST API endpoint.
   * Resolves to whether WordPress accepted the report.
   */
  private async reportComplete(payload: {
    job_id: number | string;
    status: 'published' | 'failed' | 'test_rendered';
    external_post_id?: string;
    external_post_url?: string;
    error?: string;
    metadata?: Record<string, any>;
  }): Promise<boolean> {
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
        return false;
      }
      WorkerLogger.info(`Poller`, `Job #${payload.job_id} completion reported successfully (status: ${payload.status}).`, responseBody);
      return true;
    } catch (err: any) {
      WorkerLogger.error(`Poller`, `Failed to report complete to ${url}: ${err.message}`, err);
      return false;
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
   * Downloads a job's AI post images (media_urls) for upload to X or Facebook.
   * A failed download is skipped; the post goes out with the images that arrived
   * (or with none, as a text post with its link preview).
   */
  public async downloadPostImages(job: ClaimedJob, max: number = 4): Promise<string[]> {
    const urls = Array.isArray(job.media_urls)
      ? job.media_urls.filter((u) => typeof u === 'string' && /^https?:\/\//i.test(u)).slice(0, max)
      : [];
    if (urls.length === 0) return [];

    const dir = path.join(this.outputDir || path.resolve(process.cwd(), 'output'), 'post-images');
    fs.mkdirSync(dir, { recursive: true });
    const files: string[] = [];
    for (let i = 0; i < urls.length; i++) {
      try {
        const res = await this.fetchFn(urls[i], { headers: { 'User-Agent': 'Mozilla/5.0 PressHub-Poller/1.0' } });
        if (!res || !res.ok) {
          WorkerLogger.warn('Poller', `Job #${job.id}: AI image ${i + 1}/${urls.length} download failed: HTTP ${res?.status}`);
          continue;
        }
        const type = String(res.headers?.get?.('content-type') || '');
        const ext = /png/i.test(type) || /\.png(\?|$)/i.test(urls[i]) ? 'png' : /webp/i.test(type) ? 'webp' : 'jpg';
        const file = path.join(dir, `job-${job.id}-${i + 1}.${ext}`);
        const buf = Buffer.from(await res.arrayBuffer());
        if (buf.length === 0) {
          WorkerLogger.warn('Poller', `Job #${job.id}: AI image ${i + 1}/${urls.length} download was empty; skipped.`);
          continue;
        }
        fs.writeFileSync(file, buf);
        files.push(file);
      } catch (err: any) {
        WorkerLogger.warn('Poller', `Job #${job.id}: AI image ${i + 1}/${urls.length} download failed: ${err.message}`);
      }
    }
    return files;
  }

  /**
   * Executes a single poll cycle: claims jobs from WordPress, executes them,
   * and reports results back to WordPress.
   */
  /** The render cache of shorts, under the output folder. */
  private shortCache(): ShortRenderCache {
    if (!this.shortCacheInstance) {
      const base = this.outputDir ? path.resolve(this.outputDir) : path.resolve(process.cwd(), 'output');
      this.shortCacheInstance = new ShortRenderCache(path.join(base, SHORT_CACHE_DIRNAME), this.shortCacheTtlMs);
    }
    return this.shortCacheInstance;
  }

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
          // This worker can post Facebook Reels through the Graph API, without a browser session.
          // ...and TikTok photo slide posts (#333), and shorts as X videos (1.12.0).
          capabilities: ['facebook_reels_api', 'tiktok_photo_slides', 'twitter_video'],
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
      // Set when a YouTube upload hits the daily quota or a refused sign-in: the other
      // YouTube jobs of this batch would fail the same way, so they are not rendered.
      let youtubeHalt: { reason: 'quota' | 'auth'; error: string } | null = null;

      for (const job of jobs) {
        const jobTag = `[Job #${job.id} - ${job.platform}]`;
        // True while a short is being rendered: a failure then is reported with
        // short_render_failed, so WordPress can post an article the classic way instead.
        let shortRendering = false;
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

          if (platform === 'youtube' && !job.test_render) {
            // Already uploaded on an earlier claim (WordPress never heard back): report that
            // video instead of uploading it a second time.
            const done = findUpload(this.uploadLogDir(), this.wpUrl, job.id, job.post_id);
            if (done) {
              WorkerLogger.warn('Poller', `${jobTag} Already uploaded as ${done.url}; reporting it instead of uploading again.`);
              const playlistError = done.playlistError
                || (done.kind === 'episode' && !done.playlistItemId && job.youtube?.playlist_id ? 'The worker stopped before the video was added to the playlist.' : undefined);
              await this.reportYouTubePublished(job, done.videoId, done.url, done.kind, done.playlistItemId, playlistError);
              succeeded++;
              continue;
            }
            if (youtubeHalt) {
              WorkerLogger.warn('Poller', `${jobTag} Not rendered: an earlier YouTube upload in this batch stopped (${youtubeHalt.reason}).`);
              await this.reportComplete({
                job_id: job.id,
                status: 'failed',
                error: youtubeHalt.error,
                metadata: { youtube_error_reason: youtubeHalt.reason }
              });
              failed++;
              continue;
            }
          }

          const reelApi = platform === 'facebook' && Boolean(job.tiktok_short) && !job.test_render && job.facebook_reel?.mode === 'api';
          if (reelApi) {
            // Already published on an earlier claim (WordPress never heard back).
            const done = findUpload(this.uploadLogDir(), this.wpUrl, job.id, job.post_id);
            if (done && done.kind === 'facebook_reel') {
              WorkerLogger.warn('Poller', `${jobTag} Already published as ${done.url}; reporting it instead of uploading again.`);
              await this.reportReelPublished(job, done.videoId, done.url);
              succeeded++;
              continue;
            }
          }

          let mediaPath: string | undefined;
          // True when mediaPath is a cached short that the story's other platforms reuse.
          let mediaCached = false;
          const dropMedia = () => {
            if (mediaPath && !mediaCached) {
              try { fs.unlinkSync(mediaPath); } catch {}
            }
          };
          let episodeNote: string | undefined;
          let mediaPaths: string[] | undefined;
          let downloadedImages: string[] = [];

          if (platform === 'tiktok' && job.tiktok_slides) {
            // A photo post: the short's hook and script as text on the slides, no voice.
            const sl = job.tiktok_slides;
            const texts = Array.isArray(sl.slides) ? sl.slides.filter((t) => typeof t === 'string' && t.trim() !== '') : [];
            const images = Array.isArray(sl.images) ? sl.images.filter((u) => typeof u === 'string' && u !== '') : [];
            WorkerLogger.info(
              'Poller',
              `${jobTag} Rendering TikTok photo slides ${sl.index + 1}${Number(sl.count) > 0 ? `/${sl.count}` : ''} (${texts.length} text slide(s), ${images.length} story image(s), sound search: ${sl.sound_query ? `"${sl.sound_query}"` : 'none, TikTok\'s own'})...`
            );
            const rendered = await this.generateSlidesFn({
              hook: String(sl.hook || title),
              badge: shortBadge(sl),
              slides: texts,
              imageUrls: images,
              siteName: job.site_name || 'PressHub.Cy',
              endCardLine: siteEndCardLine(this.wpUrl),
              maxSlides: typeof sl.max_slides === 'number' ? sl.max_slides : undefined,
              outputDir: this.outputDir
            });
            if (rendered.missingImages > 0) {
              WorkerLogger.warn('Poller', `${jobTag} ${rendered.missingImages} story image(s) could not be downloaded; those slides use the branded background.`);
            }
            let slideResult: PostResult;
            try {
              slideResult = await this.posterHandler({
                platform: 'tiktok',
                copy,
                mediaPath: rendered.files[0],
                mediaPaths: rendered.files,
                photoPost: true,
                soundQuery: typeof sl.sound_query === 'string' ? sl.sound_query : '',
                sessionsDir: this.sessionsDir,
                stateDir: this.uploadLogDir(),
                // The story images are AI-generated: TikTok requires the label.
                aiGenerated: true
              });
            } finally {
              removePhotoSlides(rendered.dir);
            }
            if (slideResult && slideResult.success) {
              WorkerLogger.info('Poller', `${jobTag} Photo post published: ${slideResult.external_url || slideResult.externalPostUrl || ''}`);
              await this.reportComplete({
                job_id: job.id,
                status: 'published',
                external_post_id: slideResult.external_id || slideResult.externalPostId || '',
                external_post_url: slideResult.external_url || slideResult.externalPostUrl || '',
                ...(slideResult.note ? { metadata: { tiktok_slides_note: slideResult.note } } : {})
              });
              succeeded++;
            } else {
              await this.reportComplete({ job_id: job.id, status: 'failed', error: slideResult?.error || 'TikTok photo post failed' });
              failed++;
            }
            continue;
          }

          const { imageUrl, audioUrl } = await this.resolveJobMedia(job);
          const isPodcast = Boolean(
            title.toLowerCase().includes('podcast') ||
            copy.toLowerCase().includes('podcast')
          );

          if ((platform === 'tiktok' || platform === 'instagram' || platform === 'youtube' || platform === 'facebook' || platform === 'twitter') && job.tiktok_short) {
            // A short is rendered with synced captions over its own story images. It
            // never falls back to the podcast's featured image.
            const short = job.tiktok_short;
            shortRendering = true;
            if (!audioUrl) {
              throw new Error(`${platformLabel(platform)} short has no audio_url.`);
            }
            const images = Array.isArray(short.images) ? short.images.filter((u) => typeof u === 'string' && u !== '') : [];
            WorkerLogger.info(
              'Poller',
              `${jobTag} Rendering ${platformLabel(platform)} short ${short.index + 1}${Number(short.count) > 0 ? `/${short.count}` : ''} (${images.length} story image(s), ${Array.isArray(short.segments) ? short.segments.length : 0} timed turns, ${short.music_url ? `${short.mood || 'music'} music` : 'no music'})...`
            );
            const shortOptions: GenerateShortOptions = {
              audioUrl,
              imageUrls: images,
              hook: String(job.video_title || short.hook || title),
              topic: String(short.topic || ''),
              badge: shortBadge(short),
              siteName: job.site_name || 'PressHub.Cy',
              segments: Array.isArray(short.segments) ? short.segments : [],
              musicUrl: typeof short.music_url === 'string' && short.music_url !== '' ? short.music_url : undefined,
              musicVolume: typeof short.music_volume === 'number' ? short.music_volume : undefined,
              // One end line for every platform: the site's name, true everywhere,
              // where "link in bio" was true only on TikTok and Instagram.
              endCardLine: siteEndCardLine(this.wpUrl),
              outputDir: this.outputDir,
              ...postCardOptions(short)
            };
            // A story is rendered once and reused by its other platforms; a test
            // render is always made fresh, since it is moved away for review.
            const cache = job.test_render ? null : this.shortCache();
            const renderKey = cache ? shortRenderKey(shortOptions) : '';
            let video: { filePath: string; duration: number } | null = cache ? cache.get(renderKey) : null;
            if (video) {
              mediaCached = true;
              WorkerLogger.info('Poller', `${jobTag} Reusing this story's render from another platform (${renderKey}).`);
            } else {
              if (cache) {
                const pruned = cache.prune();
                if (pruned > 0) WorkerLogger.info('Poller', `Removed ${pruned} cached short render(s) older than a day.`);
              }
              const fresh = await this.generateShortFn(shortOptions);
              if (cache) {
                const stored = cache.put(renderKey, fresh);
                video = stored;
                mediaCached = stored.cached;
              } else {
                video = fresh;
              }
            }
            mediaPath = video?.filePath;
            shortRendering = false;
            WorkerLogger.info('Poller', `${jobTag} Short rendered at: ${mediaPath}`);
            if (platform === 'youtube' && !job.test_render && Number(video?.duration) > YOUTUBE_SHORT_MAX_SECONDS) {
              // Longer than 3 minutes, YouTube files it as a normal video, not a Short.
              dropMedia();
              throw new Error(`The short is ${Math.round(Number(video.duration))}s long, over YouTube's ${YOUTUBE_SHORT_MAX_SECONDS}s limit for Shorts. Lower the words per short or raise the voice speed.`);
            }
            const xMax = Number(short.max_seconds) > 0 ? Number(short.max_seconds) : TWITTER_VIDEO_MAX_SECONDS;
            if (platform === 'twitter' && !job.test_render && Number(video?.duration) > xMax) {
              // WordPress checks the voice's length first; this catches what it could not know.
              dropMedia();
              throw new Error(`The video is ${Math.round(Number(video?.duration))}s long, over X's ${xMax}s limit. Shorten the Daily Brief Script Length or raise the short voice speed.`);
            }

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
          } else if (platform === 'youtube') {
            // A full podcast episode: the whole audio over the podcast's image, 16:9.
            if (!job.youtube || job.youtube.kind !== 'episode') {
              throw new Error('YouTube job is neither a short nor a podcast episode.');
            }
            if (!audioUrl) {
              throw new Error('YouTube podcast episode has no audio_url.');
            }
            const ev = job.youtube.episode_video;
            const enhanced = Boolean(ev && ((ev.images?.length || 0) > 0 || (ev.segments?.length || 0) > 0));
            if (enhanced && ev) {
              // The shorts' story images, one scene per topic, with captions like the shorts.
              WorkerLogger.info('Poller', `${jobTag} Rendering YouTube podcast episode (1920x1080, ${ev.scenes?.length || 0} topic(s), ${ev.images?.length || 0} story image(s), ${ev.segments?.length || 0} timed turns)...`);
              const video = await this.generateEpisodeFn({
                audioUrl,
                // An English or Turkish episode brings its own title and end line.
                title: typeof ev.title === 'string' && ev.title.trim() !== '' ? ev.title : title,
                siteName: job.site_name || 'PressHub.Cy',
                segments: Array.isArray(ev.segments) ? ev.segments : [],
                scenes: Array.isArray(ev.scenes) ? ev.scenes : [],
                images: Array.isArray(ev.images) ? ev.images : [],
                fallbackImageUrl: imageUrl,
                timelineDuration: typeof ev.duration === 'number' ? ev.duration : undefined,
                endCardLine: typeof ev.end_card_line === 'string' && ev.end_card_line.trim() !== '' ? ev.end_card_line : siteEndCardLine(this.wpUrl),
                outputDir: this.outputDir
              });
              mediaPath = video?.filePath;
              episodeNote = Array.isArray(video?.notes) && video.notes.length > 0 ? `Rendered with ${video.notes.join('; ')}.` : undefined;
              WorkerLogger.info('Poller', `${jobTag} Episode rendered at: ${mediaPath}`);
            } else {
              const width = job.youtube.width || 1920;
              const height = job.youtube.height || 1080;
              WorkerLogger.info('Poller', `${jobTag} Rendering YouTube podcast episode (${width}x${height}, full audio)...`);
              const video = await this.generateVideoFn({
                postId: Number(job.post_id) || 1,
                // The upload's title: an English or Turkish episode's own, not the Greek post's.
                title: typeof job.youtube.title === 'string' && job.youtube.title.trim() !== '' ? job.youtube.title : title,
                subtitle: '',
                width,
                height,
                mediaUrl: imageUrl,
                audioUrl,
                isPodcast: true,
                requireAudio: true,
                siteName: job.site_name || 'PressHub.Cy',
                outputDir: this.outputDir
              });
              mediaPath = video?.filePath;
              WorkerLogger.info('Poller', `${jobTag} Episode rendered at: ${mediaPath}`);
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
          } else if ((platform === 'twitter' || platform === 'facebook') && Array.isArray(job.media_urls) && job.media_urls.length > 0) {
            // AI post images: attach every one of them to the post.
            downloadedImages = await this.downloadPostImages(job);
            if (downloadedImages.length > 0) {
              mediaPaths = downloadedImages;
              mediaPath = downloadedImages[0];
              WorkerLogger.info('Poller', `${jobTag} Attaching ${downloadedImages.length} AI image(s).`);
            } else {
              WorkerLogger.warn('Poller', `${jobTag} No AI image could be downloaded; posting without images.`);
            }
          }

          if (reelApi) {
            // Uploaded through the Graph API, not a browser.
            WorkerLogger.info('Poller', `${jobTag} Uploading the Reel through the Facebook Graph API...`);
            const remember = (videoId: string, url: string) => {
              recordUpload(this.uploadLogDir(), this.wpUrl, job.id, {
                videoId,
                url,
                kind: 'facebook_reel',
                ...(job.post_id !== undefined && job.post_id !== null && String(job.post_id) !== '' ? { postId: String(job.post_id) } : {}),
                uploadedAt: new Date().toISOString()
              });
            };
            let reel: FacebookReelResult;
            try {
              reel = await this.facebookReelUploadFn({
                wpUrl: this.wpUrl,
                workerSecret: this.workerSecret,
                filePath: mediaPath || '',
                description: copy,
                fetchFn: this.fetchFn,
                onPublished: (videoId, url) => remember(videoId, url)
              });
            } finally {
              dropMedia();
            }
            if (reel.success && reel.videoId) {
              await this.reportReelPublished(job, reel.videoId, reel.url || `https://www.facebook.com/reel/${reel.videoId}`, reel.note);
              succeeded++;
            } else {
              if (reel.videoId) {
                // Refused while processing: forget it, so a new claim uploads it again.
                try {
                  recordUpload(this.uploadLogDir(), this.wpUrl, job.id, { videoId: '', url: '', kind: 'facebook_reel', uploadedAt: new Date().toISOString() });
                } catch {}
              }
              const error = reel.error || 'Facebook Reel upload failed';
              WorkerLogger.warn('Poller', `${jobTag} Facebook Reel upload failed: ${error}`);
              await this.reportComplete({
                job_id: job.id,
                status: 'failed',
                error,
                metadata: { facebook_reel_mode: 'api', ...(reel.reason ? { facebook_reel_error_reason: reel.reason } : {}) }
              });
              failed++;
            }
            continue;
          }

          if (platform === 'youtube') {
            // Uploaded through the YouTube Data API, not a browser.
            if (!job.youtube) {
              throw new Error('YouTube job has no upload details from WordPress.');
            }
            WorkerLogger.info('Poller', `${jobTag} Uploading to YouTube (${job.youtube.kind}, ${job.youtube.privacy_status || 'public'})...`);
            let upload: YouTubeUploadResult;
            const ytMeta = job.youtube;
            const remember = (videoId: string, url: string, playlistItemId?: string, playlistError?: string) => {
              recordUpload(this.uploadLogDir(), this.wpUrl, job.id, {
                videoId,
                url,
                kind: ytMeta.kind,
                ...(job.post_id !== undefined && job.post_id !== null && String(job.post_id) !== '' ? { postId: String(job.post_id) } : {}),
                playlistItemId,
                playlistError,
                uploadedAt: new Date().toISOString()
              });
            };
            try {
              upload = await this.youtubeUploadFn({
                wpUrl: this.wpUrl,
                workerSecret: this.workerSecret,
                filePath: mediaPath || '',
                meta: ytMeta,
                fetchFn: this.fetchFn,
                // Noted as soon as YouTube has the video, before the playlist insert.
                onUploaded: (videoId, url) => remember(videoId, url)
              });
            } finally {
              dropMedia();
            }
            if (upload.success && upload.videoId) {
              // Remember the video before telling WordPress, so a lost report never
              // leads to a second upload when the job is handed out again.
              try {
                remember(upload.videoId, upload.url || '', upload.playlistItemId, upload.playlistError);
              } catch (err: any) {
                WorkerLogger.warn('Poller', `${jobTag} Could not save the local upload record: ${err.message}`);
              }
              await this.reportYouTubePublished(job, upload.videoId, upload.url || '', job.youtube.kind, upload.playlistItemId, upload.playlistError, episodeNote);
              succeeded++;
            } else {
              const error = upload.error || 'YouTube upload failed';
              WorkerLogger.warn('Poller', `${jobTag} YouTube upload failed: ${error}`);
              // A quota or sign-in stop halts the batch's other YouTube jobs; a refused
              // video concerns this job only.
              if (upload.reason === 'quota' || upload.reason === 'auth') {
                youtubeHalt = { reason: upload.reason, error };
              }
              await this.reportComplete({
                job_id: job.id,
                status: 'failed',
                error,
                ...(upload.reason ? { metadata: { youtube_error_reason: upload.reason } } : {})
              });
              failed++;
            }
            continue;
          }

          WorkerLogger.info('Poller', `${jobTag} Dispatching browser automation poster...`);
          let result: PostResult;
          try {
            result = await this.posterHandler({
              platform: platform as Platform,
              copy,
              mediaPath,
              ...(mediaPaths ? { mediaPaths } : {}),
              sessionsDir: this.sessionsDir,
              // Shorts use an AI voice and AI images: TikTok requires the AI-generated label.
              ...(platform === 'tiktok' && job.tiktok_short ? { aiGenerated: true } : {})
            });
          } finally {
            for (const file of downloadedImages) {
              try { fs.unlinkSync(file); } catch {}
            }
            // A rendered short is made for this one post: remove it once posted (or failed),
            // as the YouTube upload does, so each platform's copy does not pile up in output/.
            if (job.tiktok_short) {
              dropMedia();
            }
          }

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
              error: jobErr.message || 'Worker automation failed',
              ...(shortRendering ? { metadata: { short_render_failed: true } } : {})
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
   * Converts one waiting podcast episode to MP3 in the background (the converter
   * skips a cycle while the previous one is still running).
   */
  private runMp3(): void {
    if (!this.mp3) return;
    this.mp3.runOnce().catch((err: any) => {
      WorkerLogger.warn('Poller', `MP3 cycle failed: ${err.message}`);
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
    this.runMp3();

    this.pollTimer = setInterval(async () => {
      if (!this.isRunning) return;
      this.runMonitor();
      this.runMp3();
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

/** Name of a shorts platform for log lines and errors. */
export function platformLabel(platform: string): string {
  if (platform === 'instagram') return 'Instagram Reel';
  if (platform === 'youtube') return 'YouTube';
  if (platform === 'facebook') return 'Facebook Reel';
  if (platform === 'twitter') return 'X';
  return 'TikTok';
}

/** Badge of a short: the site's own badge (an article's category), else ΕΙΔΗΣΗ n/N. */
/**
 * The post cards of a trending roundup short, checked: cards with a platform, and spans
 * with a card that exists and an end after the start. Empty for any other short.
 */
export function postCardOptions(short: { cards?: unknown; card_spans?: unknown }): Pick<GenerateShortOptions, 'postCards' | 'cardSpans'> {
  const cards = Array.isArray(short.cards) ? short.cards.filter((c: any) => c && typeof c === 'object' && typeof c.platform === 'string') as PostCard[] : [];
  if (cards.length !== (Array.isArray(short.cards) ? short.cards.length : 0)) {
    // A broken entry would shift the card numbers the spans use.
    return {};
  }
  const spans = (Array.isArray(short.card_spans) ? short.card_spans : [])
    .map((s: any) => ({ card: Number(s?.card), start: Number(s?.start), end: Number(s?.end) }))
    .filter((s) => Number.isInteger(s.card) && s.card >= 0 && s.card < cards.length && Number.isFinite(s.start) && Number.isFinite(s.end) && s.end > s.start);
  return cards.length && spans.length ? { postCards: cards, cardSpans: spans } : {};
}

export function shortBadge(short: { index: number; count: number; badge?: string }): string {
  if (typeof short.badge === 'string' && short.badge.trim() !== '') {
    return short.badge.trim();
  }
  return Number(short.count) > 0 ? `ΕΙΔΗΣΗ ${short.index + 1}/${short.count}` : `ΕΙΔΗΣΗ ${short.index + 1}`;
}
