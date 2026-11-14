/**
 * Browser scanner for monitored X and TikTok accounts.
 *
 * Opens each profile (or one private X List) in an isolated browser that uses
 * the *reader* session, captures the JSON the page loads, and keeps posts
 * newer than the account's `since`. Pages are visited slowly, one at a time.
 */
import fs from 'fs';
import path from 'path';
import { connectOrLaunchChrome, ChromeConnection } from '../automation/browser-helper';
import { getReaderSessionsDir, getSessionsDir } from '../automation/session-manager';
import { WorkerLogger } from '../logger';
import { parseTikTokItemList, parseXGraphql, ScannedPost } from './parsers';

export interface ScanTarget {
  source_id?: string;
  platform: 'x' | 'tiktok';
  handle: string;
  name?: string;
  url: string;
  since: string;
}

export interface ScanPlan {
  enabled: boolean;
  due: boolean;
  targets: ScanTarget[];
  x_list_url?: string;
  max_posts_per_account: number;
  max_age_hours: number;
  transcribe_videos: boolean;
  max_video_seconds: number;
  max_video_mb: number;
}

export interface ScanError {
  platform: string;
  handle?: string;
  url: string;
  error: string;
}

export interface ScanSession {
  posts: ScannedPost[];
  errors: ScanError[];
  /** Download one post's video (bytes) through the same browser, or null. */
  download: (post: ScannedPost) => Promise<{ bytes: Buffer; mime: string } | null>;
  close: () => Promise<void>;
}

export type ScanFn = (plan: ScanPlan) => Promise<ScanSession>;

const SESSION_PLATFORM: Record<string, string> = { x: 'twitter', tiktok: 'tiktok', facebook: 'facebook' };

/**
 * Reader session file for a platform: `<monitor sessions dir>/<platform>-session.json`.
 * The monitor sessions dir is PRESSHUB_MONITOR_SESSIONS_DIR, else `sessions-reader`
 * next to the posting sessions. Falls back to the posting session (with a warning).
 */
export function resolveReaderSession(platform: 'x' | 'tiktok' | 'facebook', postingSessionsDir?: string): string | undefined {
  const name = `${SESSION_PLATFORM[platform]}-session.json`;
  const postingDir = getSessionsDir(postingSessionsDir);
  const readerDir = getReaderSessionsDir(postingSessionsDir);
  const readerPath = path.join(readerDir, name);
  if (fs.existsSync(readerPath)) {
    return readerPath;
  }
  const postingPath = path.join(postingDir, name);
  if (fs.existsSync(postingPath)) {
    if (process.env.PRESSHUB_MONITOR_ALLOW_POSTING_SESSION === 'true') {
      WorkerLogger.warn('Social Monitor', `No reader session for ${platform}; using the posting session as allowed by PRESSHUB_MONITOR_ALLOW_POSTING_SESSION.`);
      return postingPath;
    }
    WorkerLogger.warn('Social Monitor', `No reader session for ${platform} at ${readerPath}; browsing logged out. Set PRESSHUB_MONITOR_ALLOW_POSTING_SESSION=true to use the posting account instead.`);
  }
  return undefined;
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function jitter(minMs: number, maxMs: number): number {
  return minMs + Math.floor(Math.random() * (maxMs - minMs));
}

const X_TIMELINE_RE = /\/i\/api\/graphql\/[^/]+\/(UserTweets|UserTweetsAndReplies|UserMedia|ListLatestTweetsTimeline)/;
const TIKTOK_LIST_RE = /\/api\/post\/item_list\//;

/**
 * Visit one page, scroll a few times and return the posts parsed from its responses.
 */
async function scanPage(
  conn: ChromeConnection,
  url: string,
  platform: 'x' | 'tiktok',
  maxVideoBytes: number,
  stopWhen: (posts: ScannedPost[]) => boolean,
  scrolls: number
): Promise<ScannedPost[]> {
  const page = await conn.context.newPage();
  const found = new Map<string, ScannedPost>();
  const pending: Promise<void>[] = [];
  page.on('response', (res) => {
    const u = res.url();
    const match = platform === 'x' ? X_TIMELINE_RE.test(u) : TIKTOK_LIST_RE.test(u);
    if (!match) return;
    pending.push(
      res
        .json()
        .then((json: any) => {
          const posts = platform === 'x' ? parseXGraphql(json, maxVideoBytes) : parseTikTokItemList(json, maxVideoBytes);
          for (const p of posts) found.set(p.id, p);
        })
        .catch(() => {})
    );
  });
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    const landed = page.url();
    if (platform === 'x' && /\/i\/flow\/login|\/login/.test(landed)) {
      throw new Error('X asked for a login (reader session missing or expired)');
    }
    await sleep(jitter(3500, 6000));
    for (let i = 0; i < scrolls; i++) {
      await Promise.all(pending.splice(0));
      if (stopWhen(Array.from(found.values()))) break;
      await page.mouse.wheel(0, jitter(1800, 3200)).catch(() => {});
      await sleep(jitter(2000, 4000));
    }
    await Promise.all(pending.splice(0));
    if (platform === 'tiktok' && found.size === 0) {
      const body = (await page.textContent('body').catch(() => '')) || '';
      if (/verify to continue|drag the slider|captcha/i.test(body)) {
        throw new Error('TikTok showed a verification challenge');
      }
    }
  } finally {
    await page.close().catch(() => {});
  }
  return Array.from(found.values()).sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at));
}

/**
 * Stop scrolling once the page shows a post older than what we still need:
 * the account's own `since` on a profile, or the oldest `since` of all X
 * accounts on a List (one busy account must not stop the List early).
 * Pinned posts are often old and sit at the top, so they never count.
 */
export function buildScrollStop(targets: ScanTarget[]): (list: ScannedPost[], handle?: string) => boolean {
  const sinceOf = new Map<string, number>();
  for (const t of targets) sinceOf.set(`${t.platform}:${t.handle}`, Date.parse(t.since) || 0);
  const xFloor = Math.min(Infinity, ...targets.filter((t) => t.platform === 'x').map((t) => Date.parse(t.since) || 0));
  return (list, handle) =>
    list.some((p) => {
      if (p.pinned) return false;
      const created = Date.parse(p.created_at);
      if (!handle) return p.platform === 'x' && created <= xFloor;
      const since = sinceOf.get(`${p.platform}:${p.handle}`);
      return p.handle === handle && since !== undefined && created <= since;
    });
}

/**
 * Default scanner using Playwright.
 */
export const browserScan: ScanFn = async (plan: ScanPlan) => {
  const maxVideoBytes = plan.max_video_mb * 1024 * 1024;
  const posts: ScannedPost[] = [];
  const errors: ScanError[] = [];
  const connections: Partial<Record<'x' | 'tiktok', ChromeConnection>> = {};

  const getConn = async (platform: 'x' | 'tiktok'): Promise<ChromeConnection> => {
    if (!connections[platform]) {
      connections[platform] = await connectOrLaunchChrome({
        skipCdp: true,
        storageState: resolveReaderSession(platform)
      });
    }
    return connections[platform] as ChromeConnection;
  };

  const sinceOf = new Map<string, number>();
  for (const t of plan.targets) sinceOf.set(`${t.platform}:${t.handle}`, Date.parse(t.since) || 0);
  const keep = (p: ScannedPost) => {
    const since = sinceOf.get(`${p.platform}:${p.handle}`);
    return since !== undefined && Date.parse(p.created_at) > since;
  };
  const oldestReached = buildScrollStop(plan.targets);

  const xTargets = plan.targets.filter((t) => t.platform === 'x');
  const tiktokTargets = plan.targets.filter((t) => t.platform === 'tiktok');

  // X: one List page when configured, else each profile.
  if (xTargets.length) {
    const pages = plan.x_list_url ? [{ url: plan.x_list_url, handle: undefined as string | undefined }] : xTargets.map((t) => ({ url: t.url, handle: t.handle }));
    for (const pg of pages) {
      try {
        const conn = await getConn('x');
        const got = await scanPage(conn, pg.url, 'x', maxVideoBytes, (l) => oldestReached(l, pg.handle), pg.handle ? 3 : 8);
        posts.push(...got.filter(keep));
        WorkerLogger.info('Social Monitor', `X ${pg.handle ? '@' + pg.handle : 'list'}: ${got.filter(keep).length} new post(s).`);
      } catch (err: any) {
        errors.push({ platform: 'x', handle: pg.handle, url: pg.url, error: err.message });
        WorkerLogger.warn('Social Monitor', `X scan failed for ${pg.url}: ${err.message}`);
        if (/login/i.test(err.message)) break;
      }
      await sleep(jitter(5000, 12000));
    }
  }

  for (const t of tiktokTargets) {
    try {
      const conn = await getConn('tiktok');
      const got = await scanPage(conn, t.url, 'tiktok', maxVideoBytes, (l) => oldestReached(l, t.handle), 2);
      posts.push(...got.filter(keep));
      WorkerLogger.info('Social Monitor', `TikTok @${t.handle}: ${got.filter(keep).length} new post(s).`);
    } catch (err: any) {
      errors.push({ platform: 'tiktok', handle: t.handle, url: t.url, error: err.message });
      WorkerLogger.warn('Social Monitor', `TikTok scan failed for @${t.handle}: ${err.message}`);
      if (/verification/i.test(err.message)) break;
    }
    await sleep(jitter(5000, 12000));
  }

  const download = async (post: ScannedPost) => {
    const conn = connections[post.platform];
    if (!conn || !post.video || post.video.length === 0) return null;
    for (const src of post.video) {
      try {
        const res = await conn.context.request.get(src.url, { headers: src.headers, timeout: 120000 });
        if (!res.ok()) continue;
        const bytes = await res.body();
        if (bytes.length === 0 || bytes.length > maxVideoBytes) continue;
        const type = (res.headers()['content-type'] || 'video/mp4').split(';')[0].trim();
        return { bytes, mime: type.startsWith('video/') ? type : 'video/mp4' };
      } catch (err: any) {
        WorkerLogger.debug('Social Monitor', `Video download failed for ${post.platform}:${post.id}: ${err.message}`);
      }
    }
    return null;
  };

  return {
    posts,
    errors,
    download,
    close: async () => {
      for (const c of Object.values(connections)) {
        await c?.close().catch(() => {});
      }
    }
  };
};
