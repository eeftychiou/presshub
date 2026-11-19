import fs from 'fs';
import { WorkerLogger } from '../logger';

/**
 * YouTube uploads through the YouTube Data API v3.
 *
 * WordPress keeps the Google sign-in (client secret and refresh token) and writes each
 * video's metadata at claim time (`job.youtube`). Right before an upload the worker asks
 * WordPress for a short-lived access token (POST /worker/youtube/token), then:
 *   1. starts a resumable upload session (videos.insert, uploadType=resumable),
 *   2. sends the file in chunks, asking for the received range again after a failed chunk,
 *   3. adds a podcast episode to its playlist (playlistItems.insert).
 * A Short is a normal upload: YouTube files any vertical video of three minutes or less
 * as a Short by itself.
 */

export interface YouTubeUploadMeta {
  kind: 'short' | 'episode';
  title: string;
  description: string;
  tags?: string[];
  category_id?: string;
  privacy_status?: 'public' | 'unlisted' | 'private';
  contains_synthetic_media?: boolean;
  made_for_kids?: boolean;
  default_language?: string;
  default_audio_language?: string;
  playlist_id?: string;
  width?: number;
  height?: number;
  /**
   * Enhanced episode (WordPress PressHub_Social_YouTube::episode_video()): the episode's
   * caption timeline and its scenes with the shorts' story images. Not sent to YouTube.
   */
  episode_video?: {
    duration?: number;
    estimated?: boolean;
    segments?: { speaker: number; text: string; start: number; end: number }[];
    scenes?: { start: number; end: number; title?: string; images?: string[] }[];
    images?: string[];
  };
}

export interface YouTubeUploadResult {
  success: boolean;
  /**
   * Why a failed upload failed, when nothing is wrong with the job itself: `quota` (the
   * daily upload quota is used up) or `auth` (the sign-in was refused). WordPress then
   * holds the job and pauses YouTube instead of failing it.
   */
  reason?: 'quota' | 'auth';
  videoId?: string;
  url?: string;
  playlistItemId?: string;
  playlistError?: string;
  error?: string;
}

export interface YouTubeUploadOptions {
  wpUrl: string;
  workerSecret: string;
  filePath: string;
  meta: YouTubeUploadMeta;
  fetchFn?: typeof fetch;
  /** Bytes per chunk; a multiple of 256 KiB as the API requires. */
  chunkSize?: number;
  /** Tries per chunk before the upload fails. */
  maxChunkTries?: number;
  /** Wait between chunk tries (ms), doubled each time. */
  retryDelayMs?: number;
  sleepFn?: (ms: number) => Promise<void>;
  /** Timeout of one chunk PUT (ms). */
  chunkTimeoutMs?: number;
  /** Timeout of the small JSON calls (ms). */
  requestTimeoutMs?: number;
  /**
   * Called as soon as YouTube has the video, before the playlist insert, so the caller
   * can remember it even if the worker stops before the upload call returns.
   */
  onUploaded?: (videoId: string, url: string) => void;
}

const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
const PLAYLIST_ITEMS_URL = 'https://www.googleapis.com/youtube/v3/playlistItems';
const CHUNK_UNIT = 256 * 1024;
/** 8 MiB: large enough to be quick, small enough that a failed chunk costs little. */
export const DEFAULT_CHUNK_SIZE = 32 * CHUNK_UNIT;
export const DEFAULT_MAX_CHUNK_TRIES = 5;
export const DEFAULT_RETRY_DELAY_MS = 2000;
/** An 8 MiB chunk needs well under this even on a slow line; a stalled socket is cut off. */
export const DEFAULT_CHUNK_TIMEOUT_MS = 5 * 60 * 1000;
export const DEFAULT_REQUEST_TIMEOUT_MS = 60 * 1000;

/** Adds an abort timeout to a request, so a stalled connection never hangs the worker. */
function withTimeout(init: RequestInit, ms: number): RequestInit {
  if (ms > 0 && typeof AbortSignal !== 'undefined' && typeof (AbortSignal as any).timeout === 'function') {
    return { ...init, signal: (AbortSignal as any).timeout(ms) };
  }
  return init;
}

/** Thrown by fetchYouTubeToken when WordPress says the sign-in must be renewed. */
export class YouTubeAuthError extends Error {}

const defaultSleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/** Asks WordPress for an access token of the connected YouTube channel. */
export async function fetchYouTubeToken(wpUrl: string, workerSecret: string, fetchFn: typeof fetch = globalThis.fetch, timeoutMs: number = DEFAULT_REQUEST_TIMEOUT_MS, fresh: boolean = false): Promise<string> {
  const url = `${wpUrl.replace(/\/+$/, '')}/wp-json/presshub-social/v1/worker/youtube/token`;
  const res = await fetchFn(url, withTimeout({
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PressHub-Secret': workerSecret,
      Authorization: `Bearer ${workerSecret}`
    },
    // fresh: YouTube refused the cached token, so WordPress gets a new one from Google.
    body: fresh ? '{"refresh":true}' : '{}'
  }, timeoutMs));
  let body: any = null;
  try {
    body = await res.json();
  } catch {}
  if (!res.ok || !body || typeof body.access_token !== 'string' || body.access_token === '') {
    const msg = body && typeof body.message === 'string' ? body.message : `HTTP ${res.status}`;
    const code = body && typeof body.code === 'string' ? body.code : '';
    // Google refused the stored sign-in: no upload can work until Connect YouTube again.
    if (code === 'youtube_invalid_grant' || code === 'youtube_not_connected') {
      throw new YouTubeAuthError(`WordPress gave no YouTube access token: ${msg}`);
    }
    throw new Error(`WordPress gave no YouTube access token: ${msg}`);
  }
  return body.access_token;
}

/** The videos.insert request body (snippet and status) for a job's metadata. */
export function buildVideoResource(meta: YouTubeUploadMeta): Record<string, any> {
  const snippet: Record<string, any> = {
    title: meta.title,
    description: meta.description || '',
    categoryId: meta.category_id || '25'
  };
  if (Array.isArray(meta.tags) && meta.tags.length > 0) snippet.tags = meta.tags;
  if (meta.default_language) snippet.defaultLanguage = meta.default_language;
  if (meta.default_audio_language) snippet.defaultAudioLanguage = meta.default_audio_language;
  return {
    snippet,
    status: {
      privacyStatus: meta.privacy_status || 'public',
      selfDeclaredMadeForKids: Boolean(meta.made_for_kids),
      containsSyntheticMedia: meta.contains_synthetic_media !== false,
      embeddable: true
    }
  };
}

/** Why a refusal stops every upload for now (quota or sign-in), or undefined. */
export function stopReason(status: number, body: any): 'quota' | 'auth' | undefined {
  const err = body && body.error;
  const reason = err && Array.isArray(err.errors) && err.errors[0] ? String(err.errors[0].reason || '') : '';
  if (reason === 'quotaExceeded' || reason === 'uploadLimitExceeded' || reason === 'dailyLimitExceeded') {
    return 'quota';
  }
  if (status === 401) {
    return 'auth';
  }
  return undefined;
}

function apiError(status: number, body: any): string {
  const err = body && body.error;
  const reason = err && Array.isArray(err.errors) && err.errors[0] ? err.errors[0].reason : '';
  const message = (err && err.message) || `HTTP ${status}`;
  if (reason === 'quotaExceeded' || reason === 'uploadLimitExceeded') {
    return `YouTube upload limit reached for today (${reason}): ${message}`;
  }
  return reason ? `${message} (${reason})` : message;
}

/** A 5xx, 408 or 429 is worth another try; any other 4xx is a refusal. */
function isRetryable(status: number): boolean {
  return status >= 500 || status === 408 || status === 429;
}

/**
 * "Too many requests for now" (a 429, or a 403 with rateLimitExceeded): worth another
 * try after a short wait, unlike the daily quota.
 */
export function isRateLimited(status: number, body: any): boolean {
  const err = body && body.error;
  const reason = err && Array.isArray(err.errors) && err.errors[0] ? String(err.errors[0].reason || '') : '';
  if (reason === 'quotaExceeded' || reason === 'uploadLimitExceeded' || reason === 'dailyLimitExceeded') {
    return false;
  }
  return status === 429 || reason === 'rateLimitExceeded' || reason === 'userRateLimitExceeded';
}

async function readJson(res: Response): Promise<any> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

/** Reads bytes [start, end) of a file. */
function readChunk(filePath: string, start: number, end: number): Buffer {
  const fd = fs.openSync(filePath, 'r');
  try {
    const buf = Buffer.alloc(end - start);
    let read = 0;
    while (read < buf.length) {
      const n = fs.readSync(fd, buf, read, buf.length - read, start + read);
      if (n <= 0) break;
      read += n;
    }
    return read === buf.length ? buf : buf.subarray(0, read);
  } finally {
    fs.closeSync(fd);
  }
}

/** Next byte to send from a 308 response's Range header ("bytes=0-1234"), or 0. */
export function nextOffsetFromRange(range: string | null | undefined): number {
  const m = /bytes=\d+-(\d+)/.exec(String(range || ''));
  return m ? Number(m[1]) + 1 : 0;
}

/**
 * Uploads a rendered video and, for an episode, adds it to the podcast playlist.
 * A failed playlist insert does not fail the upload: the video is on YouTube.
 */
export async function uploadToYouTube(options: YouTubeUploadOptions): Promise<YouTubeUploadResult> {
  const fetchFn = options.fetchFn || globalThis.fetch;
  const sleep = options.sleepFn || defaultSleep;
  const chunkSize = Math.max(CHUNK_UNIT, Math.floor((options.chunkSize || DEFAULT_CHUNK_SIZE) / CHUNK_UNIT) * CHUNK_UNIT);
  const maxTries = Math.max(1, options.maxChunkTries || DEFAULT_MAX_CHUNK_TRIES);
  const baseDelay = Math.max(0, options.retryDelayMs ?? DEFAULT_RETRY_DELAY_MS);
  const chunkTimeout = options.chunkTimeoutMs ?? DEFAULT_CHUNK_TIMEOUT_MS;
  const requestTimeout = options.requestTimeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
  const { meta, filePath } = options;

  if (!filePath || !fs.existsSync(filePath)) {
    return { success: false, error: 'No rendered video file to upload to YouTube.' };
  }
  if (!meta || !meta.title) {
    return { success: false, error: 'The YouTube job has no title.' };
  }
  const total = fs.statSync(filePath).size;
  if (total <= 0) {
    return { success: false, error: 'The rendered video file is empty.' };
  }

  let token: string;
  try {
    token = await fetchYouTubeToken(options.wpUrl, options.workerSecret, fetchFn, requestTimeout);
  } catch (err: any) {
    return { success: false, error: err.message, ...(err instanceof YouTubeAuthError ? { reason: 'auth' as const } : {}) };
  }

  // 1. Start the resumable session. A network error, 5xx, 408 or 429 is retried; any
  // other refusal fails at once.
  const startedAt = Date.now();
  let sessionUrl = '';
  let freshTokenTried = false;
  for (let attempt = 1; ; attempt++) {
    let initRes: Response | null = null;
    let failure = '';
    try {
      initRes = await fetchFn(`${UPLOAD_URL}?uploadType=resumable&part=snippet,status`, withTimeout({
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json; charset=UTF-8',
          'X-Upload-Content-Type': 'video/mp4',
          'X-Upload-Content-Length': String(total)
        },
        body: JSON.stringify(buildVideoResource(meta))
      }, requestTimeout));
    } catch (err: any) {
      failure = err.message;
    }
    if (initRes && initRes.ok) {
      sessionUrl = initRes.headers.get('location') || '';
      if (!sessionUrl) {
        return { success: false, error: 'YouTube returned no upload session address.' };
      }
      break;
    }
    if (initRes && initRes.status === 401 && !freshTokenTried) {
      // The cached token may have been revoked or expired early: ask WordPress for a
      // new one once before treating the sign-in as refused.
      freshTokenTried = true;
      try {
        token = await fetchYouTubeToken(options.wpUrl, options.workerSecret, fetchFn, requestTimeout, true);
      } catch (err: any) {
        return { success: false, error: err.message, ...(err instanceof YouTubeAuthError ? { reason: 'auth' as const } : {}) };
      }
      WorkerLogger.warn('YouTube', 'YouTube refused the access token (HTTP 401); trying once more with a new one.');
      continue;
    }
    if (initRes && (!isRetryable(initRes.status) || initRes.status === 429)) {
      const body = await readJson(initRes);
      const reason = stopReason(initRes.status, body);
      if (!reason && isRateLimited(initRes.status, body)) {
        // Too many requests for now: worth another try after a wait.
      } else {
        const error = apiError(initRes.status, body);
        WorkerLogger.error('YouTube', `Upload session refused: ${error}`, { http_code: initRes.status });
        return { success: false, error: `YouTube refused the upload: ${error}`, ...(reason ? { reason } : {}) };
      }
    }
    if (initRes) failure = `HTTP ${initRes.status}`;
    if (attempt >= maxTries) {
      return { success: false, error: `YouTube upload session could not be started after ${attempt} tries: ${failure}` };
    }
    const wait = baseDelay * 2 ** (attempt - 1);
    WorkerLogger.warn('YouTube', `Starting the upload session failed (${failure}); try ${attempt + 1}/${maxTries} in ${wait} ms.`);
    await sleep(wait);
  }
  WorkerLogger.info('YouTube', `Uploading ${meta.kind} "${meta.title}" (${(total / 1048576).toFixed(1)} MB)...`);

  // 2. Send the file chunk by chunk.
  let offset = 0;
  let tries = 0;
  let video: any = null;
  while (video === null) {
    const end = Math.min(total, offset + chunkSize);
    let res: Response | null = null;
    let failure = '';
    try {
      res = await fetchFn(sessionUrl, withTimeout({
        method: 'PUT',
        headers: {
          'Content-Type': 'video/mp4',
          'Content-Length': String(end - offset),
          'Content-Range': `bytes ${offset}-${end - 1}/${total}`
        },
        body: readChunk(filePath, offset, end)
      }, chunkTimeout));
    } catch (err: any) {
      failure = err.message;
    }

    if (res && (res.status === 200 || res.status === 201)) {
      video = (await readJson(res)) || {};
      break;
    }
    if (res && res.status === 308) {
      const next = nextOffsetFromRange(res.headers.get('range'));
      if (next > offset) {
        offset = next;
        tries = 0;
        continue;
      }
      // YouTube kept nothing of this chunk: count it as a failed try, so a stuck
      // session ends instead of looping.
      failure = `HTTP 308 without progress at byte ${offset}`;
      res = null;
    } else if (res && !isRetryable(res.status)) {
      const body = await readJson(res);
      const reason = stopReason(res.status, body);
      if (reason || !isRateLimited(res.status, body)) {
        const error = apiError(res.status, body);
        WorkerLogger.error('YouTube', `Upload refused: ${error}`, { http_code: res.status });
        return { success: false, error: `YouTube refused the upload: ${error}`, ...(reason ? { reason } : {}) };
      }
      // Too many requests for now: retried below after a wait.
    }

    tries++;
    if (res) failure = `HTTP ${res.status}`;
    if (tries >= maxTries) {
      return { success: false, error: `YouTube upload failed after ${tries} tries at byte ${offset} of ${total}: ${failure}` };
    }
    const wait = baseDelay * 2 ** (tries - 1);
    WorkerLogger.warn('YouTube', `Chunk at byte ${offset} failed (${failure}); try ${tries + 1}/${maxTries} in ${wait} ms.`);
    await sleep(wait);
    // Ask what YouTube has received, and continue from there.
    try {
      const status = await fetchFn(sessionUrl, withTimeout({
        method: 'PUT',
        headers: { 'Content-Length': '0', 'Content-Range': `bytes */${total}` }
      }, requestTimeout));
      if (status.status === 200 || status.status === 201) {
        video = (await readJson(status)) || {};
        break;
      }
      if (status.status === 308) {
        offset = nextOffsetFromRange(status.headers.get('range'));
      }
    } catch {}
  }

  const videoId = String(video?.id || '');
  if (!videoId) {
    return { success: false, error: 'YouTube finished the upload but returned no video ID.' };
  }
  const url = meta.kind === 'short' ? `https://www.youtube.com/shorts/${videoId}` : `https://www.youtube.com/watch?v=${videoId}`;
  WorkerLogger.info('YouTube', `Uploaded ${meta.kind} ${url} in ${Math.round((Date.now() - startedAt) / 1000)}s.`);
  const result: YouTubeUploadResult = { success: true, videoId, url };
  if (options.onUploaded) {
    try {
      options.onUploaded(videoId, url);
    } catch (err: any) {
      WorkerLogger.warn('YouTube', `Could not note the uploaded video ${videoId}: ${err.message}`);
    }
  }

  // 3. An episode goes into the podcast playlist.
  if (meta.kind === 'episode' && meta.playlist_id) {
    // A long upload can outlast the first token; ask again (WordPress returns the cached
    // one while it has time left) and keep the old one if that fails.
    try {
      token = await fetchYouTubeToken(options.wpUrl, options.workerSecret, fetchFn, requestTimeout);
    } catch (err: any) {
      WorkerLogger.warn('YouTube', `Could not refresh the access token before the playlist insert: ${err.message}`);
    }
    try {
      const res = await fetchFn(`${PLAYLIST_ITEMS_URL}?part=snippet`, withTimeout({
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json; charset=UTF-8' },
        body: JSON.stringify({
          snippet: { playlistId: meta.playlist_id, resourceId: { kind: 'youtube#video', videoId } }
        })
      }, requestTimeout));
      const body = await readJson(res);
      if (res.ok) {
        result.playlistItemId = String(body?.id || '');
        WorkerLogger.info('YouTube', `Added ${videoId} to playlist ${meta.playlist_id}.`);
      } else {
        result.playlistError = apiError(res.status, body);
        WorkerLogger.warn('YouTube', `Video ${videoId} is uploaded but could not be added to playlist ${meta.playlist_id}: ${result.playlistError}`);
      }
    } catch (err: any) {
      result.playlistError = err.message;
      WorkerLogger.warn('YouTube', `Video ${videoId} is uploaded but could not be added to playlist ${meta.playlist_id}: ${err.message}`);
    }
  }
  return result;
}
