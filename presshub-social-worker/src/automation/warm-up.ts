/**
 * Warm-up visit: before reading search, hashtag or profile pages, open the
 * platform's home page for a few seconds, the way a person starts on the
 * feed. Nothing is read from it (the home feed is personalised). TikTok only.
 */
import type { ChromeConnection } from './browser-helper';

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function jitter(minMs: number, maxMs: number): number {
  return minMs + Math.floor(Math.random() * (maxMs - minMs));
}

/** Home page opened first on a platform, or undefined for none. */
export function warmUpUrl(platform: string): string | undefined {
  return platform === 'tiktok' ? 'https://www.tiktok.com/' : undefined;
}

/**
 * Open the platform's home page, look around briefly, close it, then pause.
 * Throws a "verification challenge" error when the home page shows one, so
 * the caller stops the platform like any other challenge.
 */
export async function warmUp(conn: ChromeConnection, platform: string): Promise<boolean> {
  const url = warmUpUrl(platform);
  if (!url) return false;
  const page = await conn.context.newPage();
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleep(jitter(4000, 8000));
    const body = (await page.textContent('body').catch(() => '')) || '';
    if (/verify to continue|drag the slider|captcha/i.test(body)) {
      throw new Error(`${platform} showed a verification challenge`);
    }
    for (let i = 0; i < 2; i++) {
      await page.mouse.wheel(0, jitter(900, 1800)).catch(() => {});
      await sleep(jitter(2500, 5000));
    }
  } finally {
    await page.close().catch(() => {});
  }
  await sleep(jitter(3000, 7000));
  return true;
}
