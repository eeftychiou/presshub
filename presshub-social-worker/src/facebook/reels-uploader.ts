import fs from 'fs';
import { WorkerLogger } from '../logger';

/**
 * Facebook Reels through the Meta Graph API (Page Reels publishing).
 *
 * WordPress keeps the Meta access token. Right before an upload the worker asks it for the
 * Page's ID and a Page access token (POST /worker/facebook/token), then:
 *   1. starts an upload session (POST /{page-id}/video_reels, upload_phase=start),
 *   2. sends the file in one request to rupload.facebook.com,
 *   3. publishes it with its description (upload_phase=finish, video_state=PUBLISHED),
 *   4. reads the video's status back a few times, so a Reel Facebook refuses while
 *      processing is reported as failed instead of published.
 * Needs the pages_manage_posts permission on the token (with pages_read_engagement and
 * pages_show_list).
 */

export interface FacebookReelResult {
  success: boolean;
  /** `auth`: the token was refused or lacks a permission (nothing is wrong with the job). */
  reason?: 'auth' | 'refused';
  videoId?: string;
  url?: string;
  /** Set when the Reel was published but its processing had not finished when we stopped checking. */
  note?: string;
  error?: string;
}

export interface FacebookReelOptions {
  wpUrl: string;
  workerSecret: string;
  filePath: string;
  description: string;
  fetchFn?: typeof fetch;
  sleepFn?: (ms: number) => Promise<void>;
  /** Timeout of the file upload (ms). */
  uploadTimeoutMs?: number;
  /** Timeout of the small JSON calls (ms). */
  requestTimeoutMs?: number;
  /** First wait before reading back the status (ms, default 5000; 0 for tests). */
  statusCheckDelayMs?: number;
  /** Called as soon as Facebook has the published Reel, before the status checks. */
  onPublished?: (videoId: string, url: string) => void;
}

export interface FacebookPageToken {
  pageId: string;
  accessToken: string;
  graphVersion: string;
}

const DEFAULT_GRAPH_VERSION = 'v21.0';
export const DEFAULT_UPLOAD_TIMEOUT_MS = 10 * 60 * 1000;
export const DEFAULT_REQUEST_TIMEOUT_MS = 60 * 1000;
const STATUS_CHECK_DELAY_MS = 5000;
/** Waits between status reads, as multiples of the first wait (about two minutes in all). */
const STATUS_CHECK_STEPS = [1, 2, 4, 8, 9];

const defaultSleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

function withTimeout(init: RequestInit, ms: number): RequestInit {
  if (ms > 0 && typeof AbortSignal !== 'undefined' && typeof (AbortSignal as any).timeout === 'function') {
    return { ...init, signal: (AbortSignal as any).timeout(ms) };
  }
  return init;
}

/** Thrown when the token is refused or lacks a permission. */
export class FacebookAuthError extends Error {}

/** Graph error codes for a refused token or a missing permission. */
function isAuthError(err: any): boolean {
  const code = Number(err?.code);
  return code === 190 || code === 102 || code === 10 || (code >= 200 && code <= 299);
}

function graphError(step: string, status: number, body: any): Error {
  const err = body?.error;
  const msg = err?.message ? String(err.message) : `HTTP ${status}`;
  const text = `Facebook Reel ${step} failed: ${msg}${err?.code ? ` (code ${err.code})` : ''}`;
  return isAuthError(err) ? new FacebookAuthError(text) : new Error(text);
}

async function readJson(res: Response): Promise<any> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

/** Asks WordPress for the Page ID and a Page access token. */
export async function fetchFacebookPageToken(wpUrl: string, workerSecret: string, fetchFn: typeof fetch = globalThis.fetch, timeoutMs: number = DEFAULT_REQUEST_TIMEOUT_MS): Promise<FacebookPageToken> {
  const url = `${wpUrl.replace(/\/+$/, '')}/wp-json/presshub-social/v1/worker/facebook/token`;
  const res = await fetchFn(url, withTimeout({
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PressHub-Secret': workerSecret,
      Authorization: `Bearer ${workerSecret}`
    },
    body: '{}'
  }, timeoutMs));
  const body = await readJson(res);
  if (!res.ok || !body || typeof body.access_token !== 'string' || body.access_token === '' || !body.page_id) {
    const msg = body?.message ? String(body.message) : `HTTP ${res.status}`;
    throw new FacebookAuthError(`WordPress gave no Facebook Page token: ${msg}`);
  }
  return {
    pageId: String(body.page_id),
    accessToken: body.access_token,
    graphVersion: typeof body.graph_version === 'string' && /^v\d+\.\d+$/.test(body.graph_version) ? body.graph_version : DEFAULT_GRAPH_VERSION
  };
}

/**
 * Why Facebook refused the Reel, or '' while it is fine so far. Reads the status of
 * GET /{video-id}?fields=status.
 */
export function reelRefusal(status: any): string {
  if (!status || typeof status !== 'object') return '';
  if (String(status.video_status || '') === 'error') {
    return `video error${status.error?.message ? `: ${status.error.message}` : ''}`;
  }
  for (const phase of ['uploading_phase', 'processing_phase', 'publishing_phase']) {
    const p = status[phase];
    if (p && String(p.status || '') === 'error') {
      const errs = Array.isArray(p.errors) ? p.errors.map((e: any) => e?.message).filter(Boolean).join('; ') : '';
      return `${phase.replace('_phase', '')} error${errs ? `: ${errs}` : ''}`;
    }
  }
  return '';
}

/** Whether Facebook has finished publishing the Reel. */
export function reelPublished(status: any): boolean {
  const pub = status?.publishing_phase;
  return Boolean(pub && (String(pub.status || '') === 'complete' || String(pub.publish_status || '') === 'published'));
}

export async function uploadFacebookReel(options: FacebookReelOptions): Promise<FacebookReelResult> {
  const fetchFn = options.fetchFn || globalThis.fetch;
  const sleep = options.sleepFn || defaultSleep;
  const requestTimeout = options.requestTimeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
  const uploadTimeout = options.uploadTimeoutMs ?? DEFAULT_UPLOAD_TIMEOUT_MS;
  const statusDelay = options.statusCheckDelayMs ?? STATUS_CHECK_DELAY_MS;

  try {
    if (!options.filePath || !fs.existsSync(options.filePath)) {
      return { success: false, reason: 'refused', error: 'Facebook Reel has no rendered video to upload.' };
    }
    const size = fs.statSync(options.filePath).size;
    if (size <= 0) {
      return { success: false, reason: 'refused', error: 'Facebook Reel video file is empty.' };
    }

    const { pageId, accessToken, graphVersion } = await fetchFacebookPageToken(options.wpUrl, options.workerSecret, fetchFn, requestTimeout);
    const reelsUrl = `https://graph.facebook.com/${graphVersion}/${encodeURIComponent(pageId)}/video_reels`;

    // 1. Start the upload session.
    const startRes = await fetchFn(reelsUrl, withTimeout({
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ upload_phase: 'start', access_token: accessToken }).toString()
    }, requestTimeout));
    const start = await readJson(startRes);
    if (!startRes.ok || !start?.video_id) {
      throw graphError('start', startRes.status, start);
    }
    const videoId = String(start.video_id);
    const uploadUrl = typeof start.upload_url === 'string' && start.upload_url.startsWith('https://')
      ? start.upload_url
      : `https://rupload.facebook.com/video-upload/${graphVersion}/${encodeURIComponent(videoId)}`;

    // 2. Send the file.
    WorkerLogger.info('FacebookReel', `Uploading ${size} bytes as video ${videoId}...`);
    const data = fs.readFileSync(options.filePath);
    const upRes = await fetchFn(uploadUrl, withTimeout({
      method: 'POST',
      headers: {
        Authorization: `OAuth ${accessToken}`,
        offset: '0',
        file_size: String(size),
        'Content-Type': 'application/octet-stream'
      },
      body: data as any
    }, uploadTimeout));
    const up = await readJson(upRes);
    if (!upRes.ok || (up && up.success === false)) {
      throw graphError('upload', upRes.status, up);
    }

    // 3. Publish it with its description.
    const finishRes = await fetchFn(reelsUrl, withTimeout({
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        upload_phase: 'finish',
        video_id: videoId,
        video_state: 'PUBLISHED',
        description: options.description || '',
        access_token: accessToken
      }).toString()
    }, requestTimeout));
    const finish = await readJson(finishRes);
    if (!finishRes.ok || !finish || finish.success === false) {
      throw graphError('publish', finishRes.status, finish);
    }
    const url = `https://www.facebook.com/reel/${videoId}`;
    try {
      options.onPublished?.(videoId, url);
    } catch (err: any) {
      WorkerLogger.warn('FacebookReel', `Could not note the published Reel: ${err.message}`);
    }

    // 4. Read the status back: Facebook processes the video after "finish".
    const statusUrl = `https://graph.facebook.com/${graphVersion}/${encodeURIComponent(videoId)}?fields=status&access_token=${encodeURIComponent(accessToken)}`;
    for (const step of STATUS_CHECK_STEPS) {
      await sleep(statusDelay * step);
      let status: any = null;
      try {
        const res = await fetchFn(statusUrl, withTimeout({ method: 'GET' }, requestTimeout));
        status = (await readJson(res))?.status;
      } catch (err: any) {
        WorkerLogger.warn('FacebookReel', `Status check of video ${videoId} failed: ${err.message}`);
        continue;
      }
      const refusal = reelRefusal(status);
      if (refusal) {
        return { success: false, reason: 'refused', videoId, error: `Facebook refused the Reel (${refusal}).` };
      }
      if (reelPublished(status)) {
        return { success: true, videoId, url };
      }
    }
    return { success: true, videoId, url, note: 'Facebook was still processing the Reel when the worker stopped checking.' };
  } catch (err: any) {
    const auth = err instanceof FacebookAuthError;
    return { success: false, ...(auth ? { reason: 'auth' as const } : {}), error: err?.message || 'Facebook Reel upload failed' };
  }
}
