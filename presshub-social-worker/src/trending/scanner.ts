/**
 * Browser scanner for the Trending in Cyprus roundup.
 *
 * WordPress sends ready-made URLs (an X search on the Top tab, TikTok video
 * searches, Facebook Pages). Each page is opened in an isolated browser with
 * the platform's *reader* session, the JSON it loads is captured, and the
 * posts are returned with their engagement counts. Pages are visited slowly,
 * one at a time, exactly like the Social Monitor.
 */
import { connectOrLaunchChrome, ChromeConnection } from '../automation/browser-helper';
import { WorkerLogger } from '../logger';
import { parseTikTokItemList, parseXGraphql, PostMetrics, ScannedPost } from '../monitor/parsers';
import { resolveReaderSession } from '../monitor/scanner';
import { parseFacebookStories, splitFacebookPayload } from './facebook-parser';

export type TrendingPlatform = 'x' | 'tiktok' | 'facebook';

export interface TrendingTarget {
  platform: TrendingPlatform;
  url: string;
  label?: string;
}

export interface TrendingPlan {
  enabled: boolean;
  due: boolean;
  run_id: string;
  targets: TrendingTarget[];
  max_age_hours: number;
  max_per_platform: number;
}

export interface TrendingPost {
  platform: TrendingPlatform;
  id: string;
  handle: string;
  author_name: string;
  url: string;
  text: string;
  created_at: string;
  media: { type: 'video' | 'image' | 'none'; duration_sec: number };
  metrics: PostMetrics;
  thumbnail?: string;
}

export interface TrendingError {
  platform: string;
  url: string;
  error: string;
}

export interface TrendingScanResult {
  posts: TrendingPost[];
  errors: TrendingError[];
}

export type TrendingScanFn = (plan: TrendingPlan) => Promise<TrendingScanResult>;

const RESPONSE_MATCH: Record<TrendingPlatform, RegExp> = {
  x: /\/i\/api\/graphql\/[^/]+\/SearchTimeline/,
  tiktok: /\/api\/(search\/(item|general)\/full|challenge\/item_list)\//,
  facebook: /facebook\.com\/api\/graphql\/?/
};

const SCROLLS: Record<TrendingPlatform, number> = { x: 4, tiktok: 3, facebook: 4 };

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function jitter(minMs: number, maxMs: number): number {
  return minMs + Math.floor(Math.random() * (maxMs - minMs));
}

const ZERO: PostMetrics = { likes: 0, comments: 0, shares: 0, views: 0 };

/** Monitor-parser post → trending wire post (drops worker-only fields). */
export function fromScanned(p: ScannedPost): TrendingPost {
  return {
    platform: p.platform,
    id: p.id,
    handle: p.handle,
    author_name: p.author_name,
    url: p.url,
    text: p.text,
    created_at: p.created_at,
    media: p.media,
    metrics: p.metrics || { ...ZERO },
    ...(p.thumbnail ? { thumbnail: p.thumbnail } : {})
  };
}

/** Parse one captured response body for a platform. */
export function parseTrendingBody(platform: TrendingPlatform, body: string): TrendingPost[] {
  if (platform === 'facebook') {
    return parseFacebookStories(splitFacebookPayload(body));
  }
  let json: any;
  try {
    json = JSON.parse(body);
  } catch {
    return [];
  }
  const posts = platform === 'x' ? parseXGraphql(json, 0) : parseTikTokItemList(json, 0);
  return posts.filter((p) => !p.pinned).map(fromScanned);
}

/**
 * Keep posts inside the age window, best engagement first, at most `limit` per platform.
 */
export function trimTrending(posts: TrendingPost[], maxAgeHours: number, limit: number, now = Date.now()): TrendingPost[] {
  const floor = now - maxAgeHours * 3600 * 1000;
  const eng = (p: TrendingPost) => p.metrics.likes + 2 * p.metrics.comments + 3 * p.metrics.shares;
  const byPlatform = new Map<string, TrendingPost[]>();
  const seen = new Set<string>();
  for (const p of posts) {
    const key = `${p.platform}:${p.id}`;
    if (seen.has(key) || Date.parse(p.created_at) < floor) continue;
    seen.add(key);
    const list = byPlatform.get(p.platform) || [];
    list.push(p);
    byPlatform.set(p.platform, list);
  }
  const out: TrendingPost[] = [];
  for (const list of byPlatform.values()) {
    out.push(...list.sort((a, b) => eng(b) - eng(a)).slice(0, Math.max(1, limit)));
  }
  return out;
}

async function scanTrendingPage(conn: ChromeConnection, target: TrendingTarget): Promise<TrendingPost[]> {
  const page = await conn.context.newPage();
  const found = new Map<string, TrendingPost>();
  const pending: Promise<void>[] = [];
  const add = (list: TrendingPost[]) => {
    for (const p of list) {
      const prev = found.get(p.id);
      if (!prev || prev.metrics.likes < p.metrics.likes) found.set(p.id, p);
    }
  };
  page.on('response', (res) => {
    if (!RESPONSE_MATCH[target.platform].test(res.url())) return;
    pending.push(
      res
        .text()
        .then((body) => add(parseTrendingBody(target.platform, body)))
        .catch(() => {})
    );
  });
  try {
    await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    const landed = page.url();
    if (/\/i\/flow\/login|\/login(\.php)?(\/|\?|$)|\/checkpoint\//.test(landed)) {
      throw new Error(`${target.platform} asked for a login (reader session missing or expired)`);
    }
    await sleep(jitter(4000, 7000));
    if (target.platform === 'facebook') {
      // The first posts of a Page come inside the HTML, not from GraphQL.
      const blobs: string[] = await page
        .$$eval('script[type="application/json"]', (els) => els.map((e) => e.textContent || ''))
        .catch(() => [] as string[]);
      for (const b of blobs) {
        if (b.includes('"post_id"')) add(parseTrendingBody('facebook', b));
      }
    }
    for (let i = 0; i < SCROLLS[target.platform]; i++) {
      await page.mouse.wheel(0, jitter(1800, 3200)).catch(() => {});
      await sleep(jitter(2500, 4500));
      await Promise.all(pending.splice(0));
    }
    await Promise.all(pending.splice(0));
    if (found.size === 0) {
      const body = (await page.textContent('body').catch(() => '')) || '';
      if (/verify to continue|drag the slider|captcha/i.test(body)) {
        throw new Error(`${target.platform} showed a verification challenge`);
      }
    }
  } finally {
    await page.close().catch(() => {});
  }
  return Array.from(found.values());
}

/**
 * Default scanner using Playwright.
 */
export const browserTrendingScan: TrendingScanFn = async (plan: TrendingPlan) => {
  const posts: TrendingPost[] = [];
  const errors: TrendingError[] = [];
  const connections: Partial<Record<TrendingPlatform, ChromeConnection>> = {};
  const blocked = new Set<TrendingPlatform>();

  try {
    for (const target of plan.targets) {
      if (!RESPONSE_MATCH[target.platform] || blocked.has(target.platform)) continue;
      try {
        if (!connections[target.platform]) {
          connections[target.platform] = await connectOrLaunchChrome({
            skipCdp: true,
            storageState: resolveReaderSession(target.platform)
          });
        }
        const got = await scanTrendingPage(connections[target.platform] as ChromeConnection, target);
        posts.push(...got);
        WorkerLogger.info('Trending', `${target.label || target.url}: ${got.length} post(s).`);
      } catch (err: any) {
        errors.push({ platform: target.platform, url: target.url, error: err.message });
        WorkerLogger.warn('Trending', `${target.label || target.url} failed: ${err.message}`);
        // A login wall or a challenge will not go away on the next page of the same platform.
        if (/login|verification/i.test(err.message)) blocked.add(target.platform);
      }
      await sleep(jitter(5000, 12000));
    }
  } finally {
    for (const c of Object.values(connections)) {
      await c?.close().catch(() => {});
    }
  }
  return { posts: trimTrending(posts, plan.max_age_hours, plan.max_per_platform), errors };
};
