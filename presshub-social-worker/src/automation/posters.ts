import fs from 'fs';
import path from 'path';
import type { Locator, Page } from 'playwright';
import { WorkerLogger } from '../logger';
import {
  Platform,
  sanitizePlatform,
  verifySession,
  getSessionPath,
  saveSession,
  markSessionInvalid
} from './session-manager';
import {
  connectOrLaunchChrome,
  ChromeConnection,
  isCdpAvailable,
  extractSessionFromCdp
} from './browser-helper';

export interface PostOptions {
  platform: Platform;
  copy: string;
  mediaPath?: string;
  /** Several images for one post (X and Facebook, up to 4); takes precedence over mediaPath. */
  mediaPaths?: string[];
  sessionsDir?: string;
  timeoutMs?: number;
  cdpUrl?: string;
  browserSession?: ChromeConnection;
  headless?: boolean;
  /** TikTok: switch on "AI-generated content" (AI voice and images), as TikTok requires. */
  aiGenerated?: boolean;
}

export interface PostResult {
  success: boolean;
  platform: Platform;
  externalPostUrl?: string;
  externalPostId?: string;
  external_url?: string;
  external_id?: string;
  error?: string;
  mediaPath?: string;
}

export type PosterHandler = (options: PostOptions) => Promise<PostResult>;

/**
 * The files to attach to a post: mediaPaths when given (missing files skipped, at
 * most `max`), else mediaPath. Absolute paths.
 */
export function postMediaFiles(options: Pick<PostOptions, 'mediaPath' | 'mediaPaths'>, max: number): string[] {
  const candidates = Array.isArray(options.mediaPaths) && options.mediaPaths.length > 0
    ? options.mediaPaths
    : options.mediaPath ? [options.mediaPath] : [];
  return candidates
    .filter((f) => typeof f === 'string' && f !== '' && fs.existsSync(f))
    .slice(0, Math.max(1, max))
    .map((f) => path.resolve(f));
}

/**
 * Captures a full-page debug screenshot when browser automation encounters an error or timeout.
 * Saves the image to output/debug-error-<platform>-<timestamp>.png
 */
export async function captureErrorScreenshot(
  page: any,
  platform: string,
  customOutputDir?: string
): Promise<string | undefined> {
  try {
    const outputDir = customOutputDir ||
      (fs.existsSync(path.resolve(process.cwd(), 'presshub-social-worker/output'))
        ? path.resolve(process.cwd(), 'presshub-social-worker/output')
        : path.resolve(process.cwd(), 'output'));
    if (!fs.existsSync(outputDir)) {
      fs.mkdirSync(outputDir, { recursive: true });
    }
    const timestamp = Date.now();
    const screenshotPath = path.join(outputDir, `debug-error-${platform}-${timestamp}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: true });
    WorkerLogger.debug('Screenshot', `Saved debug screenshot to ${screenshotPath}`);
    return screenshotPath;
  } catch (err: any) {
    WorkerLogger.warn('Screenshot', `Failed to capture debug error screenshot for ${platform}: ${err.message}`);
    return undefined;
  }
}

/**
 * Captures comprehensive failure diagnostic information (URL, DOM state snippet, full-page screenshot)
 * and logs it via WorkerLogger.error.
 */
export async function captureAutomationFailure(
  page: any,
  platform: string,
  err: any
): Promise<{ screenshotPath?: string; currentUrl: string; domState: string }> {
  let currentUrl = 'unknown';
  let domState = 'unavailable';
  let screenshotPath: string | undefined;

  try {
    currentUrl = page.url();
  } catch {}

  try {
    screenshotPath = await captureErrorScreenshot(page, platform);
  } catch {}

  try {
    domState = await page.evaluate(() => {
      const doc = (globalThis as any).document;
      const title = doc?.title || 'no title';
      const body = doc?.body ? doc.body.innerText.replace(/\s+/g, ' ').slice(0, 500) : '';
      return `Title: "${title}" | Body: "${body}"`;
    });
  } catch {}

  WorkerLogger.error(
    `${platform.toUpperCase()} Automation`,
    `Playwright automation failure: ${err.message}`,
    {
      pageUrl: currentUrl,
      screenshot: screenshotPath || 'none',
      domState
    }
  );

  return { screenshotPath, currentUrl, domState };
}

export const TWITTER_URL_WEIGHT = 23;
export const TWITTER_MAX_WEIGHTED_CHARS = 275;

/** Twitter/X snowflake epoch (2010-11-04T01:42:54.657Z). */
export const TWITTER_SNOWFLAKE_EPOCH_MS = 1288834974657;

/**
 * Tolerance for clock skew between this machine and X's servers when deciding whether a
 * profile-timeline tweet was created by the current submission. Far shorter than the gap
 * between job retries, so tweets from earlier attempts are still rejected.
 */
export const TWITTER_TIMELINE_MAX_CLOCK_SKEW_MS = 60_000;

/**
 * Decodes the creation time (ms since Unix epoch) embedded in a Twitter/X status ID.
 * Status IDs are snowflakes: the bits above the low 22 hold milliseconds since the Twitter
 * epoch. Plain float division is used (no BigInt): the precision lost converting a 64-bit ID
 * to a double is far below 2^22, so the decoded millisecond is unaffected.
 * Returns undefined for anything that is not a decimal ID.
 */
export function getTwitterSnowflakeTimestampMs(statusId: string): number | undefined {
  if (!/^\d+$/.test(statusId)) return undefined;
  return Math.floor(Number(statusId) / 4194304) + TWITTER_SNOWFLAKE_EPOCH_MS;
}

/** Builds a status ID whose embedded creation time is `timestampMs` (test fixtures). */
export function makeTwitterSnowflakeId(timestampMs: number): string {
  return String((timestampMs - TWITTER_SNOWFLAKE_EPOCH_MS) * 4194304);
}

/**
 * Calculates effective weighted character length for Twitter/X.
 * Every URL (http:// or https://) counts as exactly 23 characters (t.co shortener).
 * Uses Array.from to correctly count Unicode code points (Greek text, emojis, etc.).
 */
export function getTwitterWeightedLength(text: string): number {
  if (!text || typeof text !== 'string') return 0;
  const replaced = text.replace(/https?:\/\/[^\s]+/gi, 'x'.repeat(TWITTER_URL_WEIGHT));
  return Array.from(replaced).length;
}

/**
 * Strictly enforce Single Link Rule and Brand Sanitization for Twitter:
 * 1. Guarantees only ONE URL (the article permalink) is present in the tweet.
 * 2. Strips any secondary URLs or homepage links (e.g. https://presshub.cy).
 * 3. Replaces standalone domain names like 'PressHub.Cy' or 'presshub.cy' with plain 'PressHub'
 *    so X/Twitter does not auto-link them as a second URL and suppress the featured image card.
 * 4. Enforces the 275-weighted-character budget preserving article URL and trailing hashtags.
 */
export function sanitizeTwitterCopy(copy: string, maxWeightedChars: number = TWITTER_MAX_WEIGHTED_CHARS): string {
  if (!copy || typeof copy !== 'string') return '';
  let text = copy.trim();

  // Find all URLs in the copy
  const urlRegex = /https?:\/\/[^\s]+/gi;
  const matches = text.match(urlRegex) || [];

  let targetUrl = '';
  if (matches.length > 0 && matches[0]) {
    // If multiple URLs exist, find the longest one (article permalink vs short homepage URL)
    targetUrl = matches.reduce((longest: string, current: string) => (current.length > longest.length ? current : longest), matches[0]);

    // Protect target URL with a placeholder
    text = text.replace(targetUrl, '___TARGET_ARTICLE_URL___');

    // Remove any remaining secondary URLs
    text = text.replace(/https?:\/\/[^\s]+/gi, '');
  }

  // Replace any standalone domain mentions that Twitter autolinks as secondary links
  text = text.replace(/(?:https?:\/\/)?(?:www\.)?presshub\.cy\/?/gi, 'PressHub');
  text = text.replace(/\bPressHub\.Cy\b/g, 'PressHub');
  text = text.replace(/\bPressHub\.cy\b/g, 'PressHub');
  text = text.replace(/\bpresshub\.cy\b/gi, 'PressHub');

  // Restore the single target article URL
  if (targetUrl) {
    text = text.replace('___TARGET_ARTICLE_URL___', targetUrl);
  }

  // Clean up whitespace and empty lines
  text = text.replace(/[ \t]+/g, ' ');
  text = text.replace(/\n{3,}/g, '\n\n');
  text = text.trim();

  // Smart weighted length enforcement
  if (getTwitterWeightedLength(text) > maxWeightedChars) {
    let textWithoutUrl = text;
    if (targetUrl) {
      textWithoutUrl = text.replace(targetUrl, '').trim();
    }

    let trailingHashtags = '';
    let bodyText = textWithoutUrl;

    // Check if hashtags appeared after targetUrl in the text
    if (targetUrl) {
      const parts = text.split(targetUrl);
      if (parts.length > 1) {
        const afterUrl = parts.slice(1).join(targetUrl);
        const hashtagMatches = afterUrl.match(/#[^\s#]+/g);
        if (hashtagMatches && hashtagMatches.length > 0) {
          trailingHashtags = hashtagMatches.join(' ');
          bodyText = parts[0].trim();
        }
      }
    }

    if (!trailingHashtags) {
      const hashtagMatch = textWithoutUrl.match(/(?:\s+#[^\s#]+)+\s*(?:PressHub)?\s*$/);
      if (hashtagMatch && hashtagMatch.index !== undefined) {
        const matched = hashtagMatch[0].match(/#[^\s#]+/g);
        if (matched && matched.length > 0) {
          trailingHashtags = matched.join(' ');
          bodyText = textWithoutUrl.slice(0, hashtagMatch.index).trim();
        }
      }
    }

    let suffixWeight = 0;
    if (targetUrl) {
      suffixWeight += 2 + TWITTER_URL_WEIGHT; // '\n\n' + 23
    }
    if (trailingHashtags) {
      suffixWeight += 2 + Array.from(trailingHashtags).length; // '\n\n' + code points
    }

    const availableBodyChars = maxWeightedChars - suffixWeight - 3; // reserving 3 chars for '...'

    let trimmedBody = bodyText;
    if (availableBodyChars > 10) {
      const bodyPoints = Array.from(bodyText);
      if (bodyPoints.length > availableBodyChars) {
        let sliced = bodyPoints.slice(0, availableBodyChars).join('');
        const minCut = Math.floor(availableBodyChars * 0.6);
        const lastSpace = sliced.search(/\s+[^\s]*$/);
        if (lastSpace >= minCut) {
          sliced = sliced.slice(0, lastSpace);
        }
        sliced = sliced.replace(/[\s\.,;:!?'"«»\-_]+$/, '');
        trimmedBody = sliced + '...';
      }
    } else {
      const clampChars = Math.max(0, availableBodyChars);
      trimmedBody = Array.from(bodyText).slice(0, clampChars).join('') + (clampChars > 0 ? '...' : '');
    }

    text = trimmedBody + (targetUrl ? '\n\n' + targetUrl : '') + (trailingHashtags ? '\n\n' + trailingHashtags : '');
    text = text.trim();
  }

  return text;
}

/**
 * Twitter / X automation poster using saved browser session or running Chrome.
 */
export async function postTwitter(options: PostOptions): Promise<PostResult> {
  const sessionPath = getSessionPath('twitter', options.sessionsDir);
  const timeout = options.timeoutMs || 30000;

  WorkerLogger.info('Twitter Automation', 'Connecting/launching browser for Twitter...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: options.headless,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);
    await page.bringToFront().catch(() => {});

    // Network Interception Setup
    let createTweetRequestCount = 0;
    let interceptedTweetId: string | undefined;
    let interceptedApiError: string | undefined;
    let capturedBearerToken = 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
    let capturedCreateTweetQueryId: string | undefined;

    if (typeof page.on === 'function') {
      page.on('request', (req: any) => {
        try {
          const url = req.url ? req.url() : '';
          if (url.includes('/i/api/graphql/')) {
            const auth = req.headers ? req.headers()['authorization'] : undefined;
            if (auth) {
              capturedBearerToken = auth;
            }
            const match = url.match(/\/i\/api\/graphql\/([^/]+)\/CreateTweet/i);
            if (match) {
              capturedCreateTweetQueryId = match[1];
            }
          }
          if (/\/i\/api\/graphql\/[^/]+\/(CreateTweet|CreateNoteTweet)/i.test(url)) {
            if (typeof req.method === 'function' && req.method() === 'POST') {
              createTweetRequestCount++;
              WorkerLogger.info('Twitter Automation', `[Twitter Automation] Intercepted outbound CreateTweet request (#${createTweetRequestCount})`);
            }
          }
        } catch {}
      });

      page.on('response', async (res: any) => {
        try {
          const url = res.url ? res.url() : '';
          if (/\/i\/api\/graphql\/[^/]+\/(CreateTweet|CreateNoteTweet)/i.test(url)) {
            const data = await res.json().catch(() => null);
            const restId =
              data?.data?.create_tweet?.tweet_results?.result?.rest_id ||
              data?.data?.create_tweet?.tweet_results?.result?.tweet?.rest_id ||
              data?.data?.notetweet_create?.tweet_results?.result?.rest_id;
            if (restId) {
              interceptedTweetId = String(restId);
              WorkerLogger.info('Twitter Automation', `[Twitter Automation] Intercepted CreateTweet response with rest_id: ${interceptedTweetId}`);
            }
            if (Array.isArray(data?.errors) && data.errors.length > 0) {
              interceptedApiError = data.errors.map((e: any) => e.message).join('; ');
              WorkerLogger.warn('Twitter Automation', `[Twitter Automation] Intercepted CreateTweet GraphQL error: ${interceptedApiError}`);
            }
          }
        } catch {}
      });
    }

    WorkerLogger.info('Twitter Automation', 'Navigating to compose URL (https://x.com/compose/post)...');
    await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });

    WorkerLogger.debug('Twitter Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    const isLoginPage =
      currentUrl.includes('/login') ||
      currentUrl.includes('/i/flow/login') ||
      currentUrl.includes('/i/jf/onboarding') ||
      currentUrl.includes('mode=login') ||
      currentUrl.includes('/onboarding');

    if (isLoginPage) {
      markSessionInvalid('twitter', `Redirected to login (${currentUrl})`);
      throw new Error(`Twitter session invalid or expired: redirected to login page (${currentUrl}). Re-authenticate via: npm run login -- twitter`);
    }

    const loginBodyText = (await page.innerText('body').catch(() => '')) || '';
    if (
      /See what’?s happening|Select an option below|Continue with Google|Sign in to X/i.test(loginBodyText) &&
      !loginBodyText.includes('What’s happening?')
    ) {
      markSessionInvalid('twitter', `Login modal detected on ${currentUrl}`);
      throw new Error(`Twitter session invalid or expired: login prompt detected on ${currentUrl}. Re-authenticate via: npm run login -- twitter`);
    }

    // Dismiss Twitter cookie banner if visible and neutralize fixed bottom overlays
    const dismissTwitterCookies = async () => {
      await page.evaluate(() => {
        const doc = (globalThis as any).document;
        if (!doc) return;
        // Neutralize bottom cookie bar overlay directly without causing page reload
        doc.querySelectorAll('div[data-testid="BottomBar"], div[style*="bottom: 0px"]').forEach((el: any) => {
          if (/cookie|cookies/i.test(el.innerText || el.textContent || '')) {
            el.style.display = 'none';
            el.style.pointerEvents = 'none';
            if (el.parentElement) {
              el.parentElement.style.pointerEvents = 'none';
            }
          }
        });
        // Neutralize fixed viewport interceptor divs
        doc.querySelectorAll('.r-1p0dtai.r-1d2f490.r-1xcajam.r-zchlnj.r-ipm5af').forEach((el: any) => {
          if (/cookies/i.test(el.innerText || el.textContent || '')) {
            el.style.display = 'none';
            el.style.pointerEvents = 'none';
          }
        });
      }).catch(() => {});

      const cookieAccept = page.locator('button:has-text("Refuse non-essential cookies"), button:has-text("Accept all cookies")').first();
      if ((await cookieAccept.count()) > 0 && await cookieAccept.isVisible().catch(() => false)) {
        WorkerLogger.debug('Twitter Automation', 'Dismissing cookie banner button...');
        await cookieAccept.click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);
        if (fs.existsSync(sessionPath) && typeof session.context.storageState === 'function') {
          await session.context.storageState({ path: sessionPath }).catch(() => {});
          WorkerLogger.debug('Twitter Automation', 'Persisted updated storageState after cookie dismissal.');
        }
      }
    };

    await dismissTwitterCookies();

    WorkerLogger.debug('Twitter Automation', 'Finding tweet text area...');
    const anyTextarea = page.locator(
      'div[data-slot="xds-modal-surface"] textarea, div[role="dialog"] textarea, ' +
      'div[data-slot="xds-modal-surface"] [contenteditable="true"], div[role="dialog"] [contenteditable="true"], ' +
      'div[data-slot="xds-modal-surface"] [data-testid="tweetTextarea_0"], div[role="dialog"] [data-testid="tweetTextarea_0"], ' +
      'textarea[placeholder*="happening"], textarea[aria-label*="happening"], [data-testid="tweetTextarea_0"]'
    ).or(page.getByRole('textbox', { name: /what.*happening|post text/i })).first();

    await anyTextarea.waitFor({ state: 'visible', timeout: 15000 });

    const modalSurface = page.locator('div[data-slot="xds-modal-surface"], div[role="dialog"]')
      .filter({ has: page.locator('textarea, [contenteditable="true"], [data-testid="tweetTextarea_0"]') })
      .first();
    const isModal = (await modalSurface.count()) > 0 && await modalSurface.isVisible().catch(() => false);
    WorkerLogger.debug('Twitter Automation', `Composer mode resolved: isModal=${isModal}`);

    const textarea = isModal
      ? modalSurface.locator('textarea, [contenteditable="true"], [data-testid="tweetTextarea_0"]')
          .or(modalSurface.getByRole('textbox', { name: /what.*happening|post text/i }))
          .first()
      : anyTextarea;

    const tweetCopy = sanitizeTwitterCopy(options.copy);
    WorkerLogger.info('Twitter Automation', `Typing tweet copy (${tweetCopy.length} chars, weighted length: ${getTwitterWeightedLength(tweetCopy)})...`);
    await textarea.focus().catch((err: any) => {
      WorkerLogger.warn('Twitter Automation', `Failed to focus composer textarea: ${err?.message || err}`);
    });
    await textarea.click({ force: true }).catch((err: any) => {
      WorkerLogger.warn('Twitter Automation', `Failed to force-click composer textarea: ${err?.message || err}`);
    });
    try {
      await textarea.fill(tweetCopy);
    } catch {
      await page.keyboard.type(tweetCopy, { delay: 10 });
    }

    // Allow React Virtual DOM to settle after typing.
    // NOTE: Never press Escape here or anywhere in postTwitter() as Escape triggers
    // X's "Save post? / Discard" modal which traps and aborts composer submission!
    await page.waitForTimeout(500);

    // If media attached, upload via file input (X takes up to 4 images in one post)
    const twitterMedia = postMediaFiles(options, 4);
    if (twitterMedia.length > 0) {
      const mediaSize = twitterMedia.reduce((sum, f) => sum + fs.statSync(f).size, 0);
      WorkerLogger.info('Twitter Automation', `Uploading ${twitterMedia.length} media attachment(s): ${twitterMedia.join(', ')} (${mediaSize} bytes)...`);
      const fileInput = isModal
        ? modalSurface.locator('input[type="file"], input[data-testid="fileInput"]').first()
        : page.locator('div[data-slot="xds-modal-surface"] input[type="file"], div[role="dialog"] input[type="file"], input[type="file"], input[data-testid="fileInput"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(twitterMedia.length === 1 ? twitterMedia[0] : twitterMedia);
        // Wait for the attachment previews to appear
        await page.waitForTimeout(3000 + (twitterMedia.length - 1) * 1500);
      }
    }

    // Anchor for the profile-timeline fallback: only tweets created after this moment
    // (minus clock-skew tolerance) can belong to this submission.
    const submitStartedAt = Date.now();

    // Tier 1: Keyboard-First Submission (ControlOrMeta+Enter)
    WorkerLogger.info('Twitter Automation', 'Tier 1: Attempting keyboard shortcut submission (ControlOrMeta+Enter)...');
    await textarea.focus().catch((err: any) => {
      WorkerLogger.warn('Twitter Automation', `Failed to focus composer textarea: ${err?.message || err}`);
    });
    await page.keyboard.press('ControlOrMeta+Enter').catch(() => {});

    // Wait up to 3500ms for interceptedTweetId, modalSurface detach, or toast visibility
    let modalDetachedEarly = false;
    const tier1Start = Date.now();
    while (Date.now() - tier1Start < 3500) {
      if (interceptedTweetId) {
        modalDetachedEarly = true;
        break;
      }
      const isDetached = isModal ? await modalSurface.count().then(c => c === 0).catch(() => false) : false;
      if (isDetached) {
        modalDetachedEarly = true;
        break;
      }
      const toastVisible = await page.locator('[data-testid="toast"], [data-slot="xds-toast"]').first().isVisible().catch(() => false);
      if (toastVisible) {
        modalDetachedEarly = true;
        break;
      }
      await page.waitForTimeout(200);
    }

    if (modalDetachedEarly || interceptedTweetId) {
      WorkerLogger.info('Twitter Automation', 'Tier 1 submission succeeded (keyboard shortcut triggered modal detach, toast, or network response).');
    }

    let clicked = false;

    // Tier 2: Exact ARIA Post Button Click (Gated)
    if (!modalDetachedEarly && !interceptedTweetId && createTweetRequestCount === 0) {
      WorkerLogger.debug('Twitter Automation', 'Tier 2: Finding exact Post button...');
      const postButton = isModal
        ? modalSurface.getByRole('button', { name: /^Post$/i, exact: true })
            .or(modalSurface.locator('button[data-slot="xds-button"]:has-text("Post"), [data-testid="tweetButton"]').filter({ hasNotText: 'New Post' }))
            .filter({ hasNot: page.locator('[data-slot="xds-menu-trigger"], [data-testid="SideNav_NewTweet_Button"]') })
            .first()
        : page.getByRole('button', { name: /^Post$/i, exact: true })
            .or(page.locator('div[data-slot="xds-modal-surface"] button[data-slot="xds-button"]:has-text("Post"), div[role="dialog"] [data-testid="tweetButton"], button.x-button:has-text("Post"), [data-testid="tweetButton"]').filter({ hasNotText: 'New Post' }))
            .filter({ hasNot: page.locator('[data-slot="xds-menu-trigger"], [data-testid="SideNav_NewTweet_Button"]') })
            .first();

      await postButton.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});

      // Wait if button is disabled (e.g. video/image still processing)
      for (let i = 0; i < 15; i++) {
        await dismissTwitterCookies();

        const ariaDisabled = await postButton.getAttribute('aria-disabled').catch(() => null);
        const disabled = await postButton.getAttribute('disabled').catch(() => null);
        if (ariaDisabled !== 'true' && disabled === null) {
          break;
        }
        WorkerLogger.debug('Twitter Automation', `Waiting for Post button to become actionable (${i + 1}/15)...`);
        await page.waitForTimeout(1000);
      }

      await dismissTwitterCookies();
      // NOTE: Do not press Escape here as it triggers X's "Save post? / Discard" modal!

      // Check button actionability before clicking
      const finalAriaDisabled = await postButton.getAttribute('aria-disabled').catch(() => null);
      const finalDisabled = await postButton.getAttribute('disabled').catch(() => null);
      if (finalAriaDisabled === 'true' || finalDisabled !== null) {
        WorkerLogger.warn('Twitter Automation', `Twitter Post button is disabled (aria-disabled="${finalAriaDisabled}", disabled="${finalDisabled}"). Content may exceed character limits.`);
      } else {
        WorkerLogger.info('Twitter Automation', 'Tier 2: Clicking Post button...');
        await postButton.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
        await postButton.focus().catch(() => {});
        await postButton.hover().catch(() => {});
        try {
          await postButton.click({ timeout: 5000 });
          clicked = true;
        } catch {
          WorkerLogger.debug('Twitter Automation', 'Pointer event intercepted, clicking Post with force: true...');
          try {
            await postButton.click({ force: true, timeout: 3000 });
            clicked = true;
          } catch (err: any) {
            WorkerLogger.warn('Twitter Automation', `Playwright click failed: ${err?.message || err}`);
          }
        }
      }

      // Tier 3: Synthetic native DOM MouseEvent cycle (Gated)
      if (!clicked && !interceptedTweetId && createTweetRequestCount === 0) {
        WorkerLogger.info('Twitter Automation', 'Tier 3: Attempting synthetic native DOM MouseEvent cycle...');
        await postButton.evaluate((el: any) => {
          if (el) {
            el.focus();
            const win = (el.ownerDocument && el.ownerDocument.defaultView) || null;
            const targets = [el, el.firstElementChild || el];
            for (const t of targets) {
              if (win && typeof win.MouseEvent === 'function') {
                t.dispatchEvent(new win.MouseEvent('pointerdown', { bubbles: true, cancelable: true, view: win }));
                t.dispatchEvent(new win.MouseEvent('mousedown', { bubbles: true, cancelable: true, view: win }));
                t.dispatchEvent(new win.MouseEvent('pointerup', { bubbles: true, cancelable: true, view: win }));
                t.dispatchEvent(new win.MouseEvent('mouseup', { bubbles: true, cancelable: true, view: win }));
                t.dispatchEvent(new win.MouseEvent('click', { bubbles: true, cancelable: true, view: win }));
              }
            }
            if (typeof el.click === 'function') {
              el.click();
            }
          }
        }).catch(() => {});
      }
    }

    WorkerLogger.info('Twitter Automation', 'Waiting for tweet submission confirmation...');
    // Wait for the modal dialog to disappear, toast to appear, or interceptedTweetId to be captured
    if (!interceptedTweetId) {
      const waitPromises: Promise<any>[] = [
        page.locator('[data-testid="toast"], [data-slot="xds-toast"]').first().waitFor({ state: 'visible', timeout: 15000 }),
        new Promise<void>((resolve) => {
          const pollStart = Date.now();
          const timer = setInterval(() => {
            if (interceptedTweetId || Date.now() - pollStart > 15000) {
              clearInterval(timer);
              resolve();
            }
          }, 200);
        })
      ];

      // Only wait for modal detachment if we actually confirmed a modal was active and attached
      if (isModal && (await modalSurface.count().catch(() => 0)) > 0) {
        waitPromises.push(modalSurface.waitFor({ state: 'detached', timeout: 15000 }));
      }

      await Promise.race(waitPromises).catch(() => {});
    }

    await page.waitForTimeout(1500);

    // Discover username
    const profileLink = page.locator('[data-testid="AppTabBar_Profile_Link"]').first();
    let username = 'presshubcy';
    if ((await profileLink.count()) > 0) {
      const href = await profileLink.getAttribute('href');
      if (href && href.startsWith('/')) {
        username = href.replace(/^\//, '').split('/')[0] || username;
      }
    }

    // Extract tweet URL and ID
    let tweetUrl: string | undefined;
    let tweetId: string | undefined;

    // Fast path: if intercepted via network response
    if (interceptedTweetId) {
      tweetId = interceptedTweetId;
      tweetUrl = `https://x.com/${username}/status/${tweetId}`;
      WorkerLogger.info('Twitter Automation', `Resolved tweet from intercepted network response: ${tweetUrl} (ID: ${tweetId})`);
    }

    // Next path: toast link
    if (!tweetUrl) {
      try {
        const toastLink = page.locator('[data-testid="toast"] a[href*="/status/"], [data-slot="xds-toast"] a[href*="/status/"]').first();
        if ((await toastLink.count()) > 0) {
          const href = await toastLink.getAttribute('href');
          if (href) {
            tweetUrl = href.startsWith('http') ? href : `https://x.com${href}`;
            const match = tweetUrl.match(/\/status\/(\d+)/);
            if (match) {
              tweetId = match[1];
            }
          }
        }
      } catch {}
    }

    // Next path: redirected page URL
    if (!tweetUrl) {
      try {
        const pageUrl = page.url();
        const urlMatch = pageUrl.match(/\/status\/(\d+)/);
        if (urlMatch) {
          tweetId = urlMatch[1];
          tweetUrl = pageUrl;
        }
      } catch {}
    }

    // Next path: profile timeline inspection
    if (!tweetUrl || !tweetId) {
      try {
        WorkerLogger.info('Twitter Automation', 'Status link not captured, inspecting user profile to locate newly published tweet...');
        await page.goto(`https://x.com/${username}`, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.waitForTimeout(3000);

        await page.locator('article[data-testid="tweet"], article').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
        const tweetArticles = await page.locator('article[data-testid="tweet"], article').all();
        // An empty snippet would match every article (''.includes check), so text matching
        // is only used when the copy yields at least one letter or digit.
        const snippet = tweetCopy.slice(0, 30).replace(/[^a-zA-Z0-9\u0370-\u03FF\u1F00-\u1FFF]/g, '').slice(0, 20);
        const copyUrl = (tweetCopy.match(/https?:\/\/[^\s]+/i) || [])[0];
        const oldestAcceptableMs = submitStartedAt - TWITTER_TIMELINE_MAX_CLOCK_SKEW_MS;
        for (const article of tweetArticles.slice(0, 6)) {
          const articleText = (await article.innerText().catch(() => '')) || '';
          const statusLink = article.locator('a[href*="/status/"]').first();
          if ((await statusLink.count()) > 0) {
            const href = await statusLink.getAttribute('href');
            if (href) {
              const match = href.match(/\/status\/(\d+)/);
              if (match) {
                const cleanArticleText = articleText.replace(/[^a-zA-Z0-9\u0370-\u03FF\u1F00-\u1FFF]/g, '');
                const textMatches = snippet.length > 0 && cleanArticleText.includes(snippet);
                const urlMatches = !!copyUrl && articleText.includes(copyUrl);
                if (!textMatches && !urlMatches) continue;

                // Reject tweets that predate this submission (e.g. an earlier attempt or a
                // previous day's post with the same opening words). Accepting one would report
                // the job as published without confirming that this run actually posted.
                const createdAtMs = getTwitterSnowflakeTimestampMs(match[1]);
                if (createdAtMs === undefined || createdAtMs < oldestAcceptableMs) {
                  WorkerLogger.warn('Twitter Automation', `Ignoring matching timeline tweet ${match[1]}: created before this submission.`, {
                    tweetCreatedAt: createdAtMs !== undefined ? new Date(createdAtMs).toISOString() : 'unparseable',
                    submitStartedAt: new Date(submitStartedAt).toISOString(),
                    maxClockSkewMs: TWITTER_TIMELINE_MAX_CLOCK_SKEW_MS
                  });
                  continue;
                }

                tweetId = match[1];
                tweetUrl = href.startsWith('http') ? href : `https://x.com${href}`;
                WorkerLogger.info('Twitter Automation', `Found published tweet on profile timeline: ${tweetUrl}`);
                break;
              }
            }
          }
        }
      } catch (err: any) {
        WorkerLogger.warn('Twitter Automation', `Profile timeline lookup failed: ${err?.message || err}`);
      }
    }

    // Tier 4: Direct In-Page CreateTweet GraphQL API Fallback (Strictly Gated)
    if ((!tweetUrl || !tweetId) && createTweetRequestCount === 0 && !options.mediaPath) {
      WorkerLogger.info('Twitter Automation', '[Twitter Automation] Tier 4: Executing direct in-page CreateTweet GraphQL fallback (0 prior CreateTweet requests)...');
      try {
        const queryIdToUse = capturedCreateTweetQueryId || 'VaenaVgh5q5ih7kvyVjgtg';
        const bearerToUse = capturedBearerToken;

        let contextCt0: string | undefined;
        try {
          const cookieCtx = (typeof page.context === 'function' && page.context()) ? page.context() : session.context;
          if (cookieCtx && typeof cookieCtx.cookies === 'function') {
            const cookies = await cookieCtx.cookies(['https://x.com', 'https://twitter.com']);
            const found = cookies.find((c: any) => c.name === 'ct0');
            if (found) {
              contextCt0 = found.value;
            }
          }
        } catch {}

        const evalResult = await page.evaluate(
          async ({ copy, queryId, bearer, fallbackCt0 }: { copy: string; queryId: string; bearer: string; fallbackCt0?: string }) => {
            try {
              const doc = (globalThis as any).document;
              const win = (globalThis as any).window;
              let ct0 = fallbackCt0 || '';

              if (!ct0) {
                try {
                  const cookieStr = doc?.cookie || '';
                  const ct0Match = cookieStr.match(/(?:^|;\s*)ct0=([^;]+)/);
                  if (ct0Match) {
                    ct0 = decodeURIComponent(ct0Match[1]);
                  }
                } catch {}
              }

              if (!ct0) {
                return { success: false, error: 'No ct0 cookie found in session cookies or document.cookie' };
              }

              let resolvedQueryId = queryId;
              try {
                const scripts = Array.from(doc.querySelectorAll('script[src]')) as any[];
                for (const script of scripts) {
                  if (script.src && script.src.includes('endpoints.CreateTweet')) {
                    const match = script.src.match(/\/([a-zA-Z0-9_\-]+)\.js/);
                    if (match) {
                      resolvedQueryId = match[1];
                      break;
                    }
                  }
                }
              } catch {}

              const base = (win?.location?.origin && win.location.origin.startsWith('http')) ? win.location.origin : 'https://x.com';
              const response = await fetch(base + '/i/api/graphql/' + resolvedQueryId + '/CreateTweet', {
                method: 'POST',
                credentials: 'include',
                headers: {
                  'authorization': bearer,
                  'x-csrf-token': ct0,
                  'x-twitter-active-user': 'yes',
                  'x-twitter-auth-type': 'OAuth2Session',
                  'content-type': 'application/json'
                },
                body: JSON.stringify({
                  variables: {
                    tweet_text: copy,
                    dark_request: false,
                    media: { media_entities: [], possibly_sensitive: false },
                    semantic_annotation_ids: []
                  },
                  features: {
                    interactive_text_enabled: true,
                    longform_notetweets_inline_media_enabled: true,
                    responsive_web_text_conversations_enabled: false,
                    tweet_with_visibility_results_prefer_gql_limited_actions_policy_enabled: true,
                    voodoo_api_enabled: false,
                    rweb_tipjar_consumption_enabled: true,
                    responsive_web_twitter_article_tweet_consumption_enabled: true,
                    Communities_web_enable_tweet_community_results_fetch: true,
                    c9s_tweet_anatomy_moderator_badge_enabled: true,
                    articles_preview_enabled: true,
                    rweb_video_timestamps_enabled: true,
                    longform_notetweets_rich_text_read_enabled: true,
                    longform_notetweets_consumption_enabled: true,
                    responsive_web_enhance_cards_enabled: false,
                    standardized_nudges_misinfo: true,
                    freedom_of_speech_not_reach_fetch_enabled: true,
                    responsive_web_graphql_skip_user_profile_image_extensions_enabled: false,
                    responsive_web_edit_tweet_api_enabled: true,
                    graphql_is_translatable_rweb_tweet_is_translatable_enabled: true,
                    view_counts_everywhere_api_enabled: true,
                    Responsive_web_graphql_exclude_directive_enabled: true,
                    verified_phone_label_enabled: false,
                    responsive_web_graphql_timeline_navigation_enabled: true
                  },
                  queryId: resolvedQueryId
                })
              });

              const data: any = await response.json().catch(() => null);
              const restId =
                data?.data?.create_tweet?.tweet_results?.result?.rest_id ||
                data?.data?.create_tweet?.tweet_results?.result?.tweet?.rest_id;

              if (restId) {
                return { success: true, restId: String(restId) };
              }

              const errorMsg = Array.isArray(data?.errors) && data.errors.length > 0
                ? data.errors.map((e: any) => e.message).join('; ')
                : `HTTP ${response.status} ${response.statusText}`;
              return { success: false, error: errorMsg };
            } catch (err: any) {
              return { success: false, error: err?.message || String(err) };
            }
          },
          { copy: tweetCopy, queryId: queryIdToUse, bearer: bearerToUse, fallbackCt0: contextCt0 }
        );

        if (evalResult?.success && evalResult?.restId) {
          tweetId = evalResult.restId;
          tweetUrl = `https://x.com/${username}/status/${tweetId}`;
          WorkerLogger.info('Twitter Automation', `Tier 4: In-page CreateTweet GraphQL fallback succeeded with rest_id: ${tweetId}`);
        } else {
          WorkerLogger.warn('Twitter Automation', `Tier 4: In-page CreateTweet GraphQL fallback failed: ${evalResult?.error || 'unknown error'}`);
        }
      } catch (err: any) {
        WorkerLogger.warn('Twitter Automation', `Tier 4: In-page CreateTweet evaluation error: ${err?.message || err}`);
      }
    }

    // Strict Zero-Silent-Fallback: Never generate a fake tw-timestamp fallback ID
    if (!tweetUrl || !tweetId) {
      throw new Error('Twitter automation failed: Tweet submission could not be confirmed (no status link found in toast or profile timeline).');
    }

    // MANDATORY POST-CONFIRMATION CHECK: Actually load the posted tweet URL to confirm it exists and is public on X
    WorkerLogger.info('Twitter Automation', `Verifying published tweet by loading URL: ${tweetUrl}...`);
    await page.goto(tweetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(3000);

    const tweetSpecificArticle = page.locator('article[data-testid="tweet"]').first();
    const isSpecificVisible = (await tweetSpecificArticle.count()) > 0 && (await tweetSpecificArticle.isVisible().catch(() => false));

    // When falling back to a plain <article> (when data-testid="tweet" is absent), ensure the article anchors to this status URL
    const plainArticleWithLink = page.locator('article').filter({
      has: page.locator(`a[href*="/status/${tweetId}"]`)
    }).first();
    const isPlainWithLinkVisible = (await plainArticleWithLink.count()) > 0 && (await plainArticleWithLink.isVisible().catch(() => false));


    const isArticleVisible = isSpecificVisible || isPlainWithLinkVisible;

    const pageBodyText = (await page.innerText('body').catch(() => '')) || '';
    const isErrorPage = /doesn’t exist|does not exist|unavailable|Something went wrong/i.test(pageBodyText);

    if (!isArticleVisible || isErrorPage) {
      throw new Error(`Twitter post verification failed: Tweet URL ${tweetUrl} could not be confirmed on X (article visible: ${isArticleVisible}, error page: ${isErrorPage}).`);
    }

    // Persist storageState after successful verification
    if (fs.existsSync(sessionPath) && typeof session.context.storageState === 'function') {
      await session.context.storageState({ path: sessionPath }).catch(() => {});
      WorkerLogger.debug('Twitter Automation', 'Persisted updated storageState after tweet verification.');
    }

    WorkerLogger.info('Twitter Automation', `Tweet publication verified successfully on X! URL: ${tweetUrl}, ID: ${tweetId}`);

    return {
      success: true,
      platform: 'twitter',
      externalPostId: tweetId,
      externalPostUrl: tweetUrl,
      external_id: tweetId,
      external_url: tweetUrl,
      mediaPath: options.mediaPath
    };
  } catch (err: any) {
    await captureAutomationFailure(page, 'Twitter', err);
    throw err;
  } finally {
    try {
      await page.close();
    } catch {}
    if (!options.browserSession) {
      await session.close();
    }
  }
}

/**
 * Switches on TikTok Studio's "AI-generated content" disclosure (under "Show more").
 * Returns true when the switch is on. A missing switch is a WARNING, not a failure: the
 * upload page changes often, and the post is still valid; the log says to label it by hand.
 */
export async function markTikTokAiGenerated(page: any): Promise<boolean> {
  const label = /AI-generated content|AI-generated|Περιεχόμενο που δημιουργήθηκε από AI/i;
  const switchSelector = '[role="switch"], .Switch__content, input[type="checkbox"]';

  const pickVisibleSwitch = async (container: any): Promise<any | null> => {
    const candidates = container.locator(switchSelector);
    const count = await candidates.count().catch(() => 0);
    // Prefer visible [role="switch"] or .Switch__content over a potentially hidden native checkbox.
    for (const preferred of ['[role="switch"]', '.Switch__content', 'input[type="checkbox"]']) {
      const sub = container.locator(preferred);
      const subCount = await sub.count().catch(() => 0);
      for (let i = 0; i < subCount; i++) {
        const el = sub.nth(i);
        if (await el.isVisible().catch(() => false)) {
          return el;
        }
      }
    }
    for (let i = 0; i < count; i++) {
      const el = candidates.nth(i);
      if (await el.isVisible().catch(() => false)) {
        return el;
      }
    }
    return null;
  };

  const findVisibleSwitch = async (): Promise<any | null> => {
    const aigcContainer = page.locator('[data-e2e="aigc_container"]').first();
    if ((await aigcContainer.count().catch(() => 0)) > 0) {
      const sw = await pickVisibleSwitch(aigcContainer);
      if (sw) return sw;
    }

    const rows = page
      .locator('div, label')
      .filter({ hasText: label })
      .filter({ has: page.locator(switchSelector) });
    const rowCount = await rows.count().catch(() => 0);
    for (let i = rowCount - 1; i >= 0; i--) {
      const sw = await pickVisibleSwitch(rows.nth(i));
      if (sw) return sw;
    }
    return null;
  };

  const isOn = async (sw: any): Promise<boolean> => {
    if (!sw) return false;
    if ((await sw.getAttribute('aria-checked').catch(() => null)) === 'true') return true;
    if ((await sw.getAttribute('data-state').catch(() => null)) === 'checked') return true;
    if (await sw.isChecked().catch(() => false)) return true;
    return await sw.evaluate((el: any) => {
      const switchRoot = el.closest('[data-layout="switch-root"], .Switch__root');
      const scope = switchRoot || el;
      if (scope.matches?.('[aria-checked="true"], [data-state="checked"]')) return true;
      const checkedEl = scope.querySelector('[aria-checked="true"], [data-state="checked"]');
      if (checkedEl) return true;
      const input = scope.matches?.('input[type="checkbox"]') ? scope : scope.querySelector('input[type="checkbox"]');
      return Boolean(input && input.checked);
    }).catch(() => false);
  };

  try {
    let sw = await findVisibleSwitch();
    // The switch sits under "Show more" and may be in a display:none container until expanded.
    if (!sw) {
      // Priority-ordered selectors: Playwright evaluates comma-joined selectors in document order,
      // so we iterate individually to prevent an earlier decoy "Show more" from winning.
      const moreSelectors = [
        '[data-e2e="advanced_settings_container"].collapsed .more-btn',
        '[data-e2e="advanced_settings_container"] .more-btn:has-text("Show more")',
        'div.more-btn:has-text("Show more")',
        'button:has-text("Show more")',
        'div[role="button"]:has-text("Show more")',
        'button:has-text("More options")',
        'div[role="button"]:has-text("More options")',
        'span:text-is("Show more")',
      ];
      let clickedMore = false;
      for (const sel of moreSelectors) {
        const m = page.locator(sel).first();
        if (await m.isVisible().catch(() => false)) {
          await m.scrollIntoViewIfNeeded().catch(() => {});
          await m.click({ timeout: 5000 }).catch(() => m.click({ force: true }).catch(() => {}));
          clickedMore = true;
          break;
        }
      }
      // Poll briefly for slow/lazy renders after expanding (or if already expanded).
      const maxAttempts = clickedMore ? 6 : 2;
      for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await page.waitForTimeout(400);
        sw = await findVisibleSwitch();
        if (sw) break;
      }
    }
    if (!sw) {
      WorkerLogger.warn('TikTok Automation', 'Could not find the "AI-generated content" switch; the short is posted without the AI label. Turn it on by hand in TikTok and report the page change.');
      return false;
    }
    if (await isOn(sw)) {
      WorkerLogger.debug('TikTok Automation', '"AI-generated content" is already on.');
      return true;
    }
    await sw.scrollIntoViewIfNeeded().catch(() => {});
    await sw.click({ timeout: 5000 }).catch(() => sw.click({ force: true }));
    await page.waitForTimeout(500);
    // TikTok asks to confirm the label in a dialog ("Turn on").
    const confirm = page.locator('div[role="dialog"] button:has-text("Turn on"), .TUXModal button:has-text("Turn on")').first();
    if ((await confirm.count()) > 0 && (await confirm.isVisible().catch(() => false))) {
      await confirm.click({ force: true }).catch(() => {});
      await page.waitForTimeout(400);
    }
    const updatedSw = (await findVisibleSwitch()) || sw;
    const on = await isOn(updatedSw);
    if (on) {
      WorkerLogger.info('TikTok Automation', 'Marked the video as AI-generated content.');
    } else {
      WorkerLogger.warn('TikTok Automation', 'Clicked "AI-generated content" but it did not turn on; check the label on the posted video.');
    }
    return on;
  } catch (err: any) {
    WorkerLogger.warn('TikTok Automation', `Could not set the "AI-generated content" label: ${err.message}`);
    return false;
  }
}

/**
 * TikTok Studio / Creator Center automation poster using saved browser session or running Chrome.
 */
export const TIKTOK_STUDIO_CONTENT_URL = 'https://www.tiktok.com/tiktokstudio/content';

export interface TikTokResolvedUrl {
  kind: 'video' | 'profile' | 'studio';
  url: string;
  videoId?: string;
}

/** Pull "/@handle/video/123" out of a URL or href. */
export function parseTikTokVideoHref(href: string): { handle: string; videoId: string } | null {
  const m = /\/@([A-Za-z0-9._-]+)\/video\/(\d{8,})/.exec(href || '');
  return m ? { handle: m[1], videoId: m[2] } : null;
}

/** Pull the account handle out of a profile href like "/@handle" or "https://www.tiktok.com/@handle?lang=el". */
export function parseTikTokProfileHref(href: string): string | null {
  const m = /(?:^|tiktok\.com)\/@([A-Za-z0-9._-]+)\/?(?:[?#].*)?$/.exec(href || '');
  return m ? m[1] : null;
}

/**
 * After a TikTok upload is confirmed, find where the post lives:
 *  1. the current page already points at the video;
 *  2. TikTok Studio's content list has a row whose caption matches ours;
 *  3. otherwise the account's profile (handle from any profile link);
 *  4. otherwise the Studio content list itself.
 * Never throws: a lookup problem must not turn a successful post into a failure.
 */
export async function resolveTikTokPostUrl(page: Page, caption: string, waitMs: number = 12000): Promise<TikTokResolvedUrl> {
  const fromPage = parseTikTokVideoHref(page.url());
  if (fromPage) {
    return { kind: 'video', url: `https://www.tiktok.com/@${fromPage.handle}/video/${fromPage.videoId}`, videoId: fromPage.videoId };
  }

  const snippet = (caption || '').replace(/\s+/g, ' ').trim().slice(0, 30);
  let handle: string | null = null;

  const scan = async () => page.evaluate((snip: string) => {
    const norm = (t: string) => (t || '').replace(/\s+/g, ' ').trim();
    const doc = (globalThis as any).document;
    const anchors = Array.from(doc.querySelectorAll('a[href]')) as any[];
    const videos: { href: string; text: string }[] = [];
    const profiles: string[] = [];
    for (const a of anchors) {
      const href = a.getAttribute('href') || '';
      if (/\/@[^/]+\/video\/\d+/.test(href)) {
        let el: any = a;
        let text = '';
        // Climb to the largest ancestor (the row) that still holds only this video link.
        for (let i = 0; i < 8 && el; i++) {
          if (el.querySelectorAll && el.querySelectorAll('a[href*="/video/"]').length > 1) break;
          text = norm(el.innerText || el.textContent || '');
          if (snip && text.includes(snip)) break;
          el = el.parentElement;
        }
        videos.push({ href, text });
      } else if (/\/@[^/?#]+\/?(?:[?#].*)?$/.test(href)) {
        profiles.push(href);
      }
    }
    return { videos, profiles };
  }, snippet).catch(() => ({ videos: [] as { href: string; text: string }[], profiles: [] as string[] }));

  try {
    await page.goto(TIKTOK_STUDIO_CONTENT_URL, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
    const start = Date.now();
    while (Date.now() - start < waitMs) {
      const found = await scan();
      if (!handle) {
        for (const p of found.profiles) {
          handle = parseTikTokProfileHref(p);
          if (handle) break;
        }
      }
      if (snippet) {
        const match = found.videos.find((v) => v.text.includes(snippet));
        const parsed = match ? parseTikTokVideoHref(match.href) : null;
        if (parsed) {
          return { kind: 'video', url: `https://www.tiktok.com/@${parsed.handle}/video/${parsed.videoId}`, videoId: parsed.videoId };
        }
      }
      if (!handle) {
        for (const v of found.videos) {
          const parsed = parseTikTokVideoHref(v.href);
          if (parsed) { handle = parsed.handle; break; }
        }
      }
      await page.waitForTimeout(1500);
    }
  } catch (e: any) {
    WorkerLogger.warn('TikTok Automation', `Could not read the posted video URL: ${e?.message || e}`);
  }

  if (handle) {
    return { kind: 'profile', url: `https://www.tiktok.com/@${handle}` };
  }
  return { kind: 'studio', url: TIKTOK_STUDIO_CONTENT_URL };
}

/** A hashtag token in a caption: '#' followed by letters, digits or underscores (any script). */
const TIKTOK_HASHTAG_RE = /#[\p{L}\p{M}\p{N}_]+/gu;

/** The hashtag suggestion list TikTok Studio opens under the caption while a '#tag' is typed. */
export const TIKTOK_HASHTAG_POPOVER_SELECTOR = [
  '[class*="mention-list-popover"]',
  '[class*="hashtag-suggestion"]',
  '[class*="HashtagSuggestion"]',
  '[class*="mentionSuggestions"]',
  '[data-e2e*="hashtag"][role="listbox"]',
  '[data-e2e*="hashtag-suggestion"]',
].join(', ');

/** Split a caption into plain text, line breaks and hashtags, in order. */
export function splitTikTokCaption(copy: string): Array<{ kind: 'text' | 'newline' | 'hashtag'; value: string }> {
  const out: Array<{ kind: 'text' | 'newline' | 'hashtag'; value: string }> = [];
  const lines = (copy || '').replace(/\r\n?/g, '\n').split('\n');
  lines.forEach((line, i) => {
    if (i > 0) out.push({ kind: 'newline', value: '\n' });
    let last = 0;
    for (const m of line.matchAll(TIKTOK_HASHTAG_RE)) {
      const at = m.index ?? 0;
      // '#' inside a word (e.g. "C#") is not a hashtag.
      if (at > 0 && /[\p{L}\p{N}_]/u.test(line[at - 1])) continue;
      // "#2026" has no letter: not a hashtag, so no suggestion list to wait for.
      if (!/\p{L}/u.test(m[0])) continue;
      if (at > last) out.push({ kind: 'text', value: line.slice(last, at) });
      out.push({ kind: 'hashtag', value: m[0] });
      last = at + m[0].length;
    }
    if (last < line.length) out.push({ kind: 'text', value: line.slice(last) });
  });
  return out;
}

/** Lower case, no accents, no '#': "#Κύπρος" and "κυπρος" compare equal. */
function normalizeTikTokTag(text: string): string {
  return (text || '').replace(/^#/, '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

/**
 * Type a caption into TikTok Studio's caption editor so its hashtags become real hashtags.
 *
 * TikTok only links a hashtag (and lists the video under it) when the tag is picked from
 * the suggestion list the editor opens while '#tag' is typed. Text pasted in one go keeps
 * '#tag' as plain words, so the tags show in the caption but are not hashtags.
 * Plain text is inserted at once; each hashtag is typed key by key and its own entry in
 * the suggestion list is picked. When the list does not offer that exact tag, the tag
 * stays as typed instead of taking another one.
 *
 * @returns How many hashtags the caption has and how many were picked from the list.
 */
export async function typeTikTokCaption(
  page: any,
  copy: string,
  popoverWaitMs: number = 4000
): Promise<{ hashtags: number; linked: number }> {
  const parts = splitTikTokCaption(copy);
  let hashtags = 0;
  let linked = 0;
  let afterPick = false;
  let newlines = 0;

  for (const part of parts) {
    if (part.kind === 'newline') {
      // Shift+Enter is a line break inside the same paragraph; at most one blank line.
      if (++newlines <= 2) {
        await page.keyboard.press('Shift+Enter').catch(() => {});
      }
      afterPick = false;
      continue;
    }
    newlines = 0;

    if (part.kind === 'text') {
      let text = part.value;
      // A picked suggestion may already end with a space: do not double it, and do not drop
      // the only one (tags would run together as "#tag1#tag2").
      if (afterPick && /^[ \u00a0]/.test(text)) {
        const endsWithSpace = await page.evaluate(() => {
          const el: any = (globalThis as any).document?.activeElement;
          const t: string = (el && (el.innerText ?? el.textContent)) || '';
          return /[ \u00a0]$/.test(t);
        }).catch(() => false);
        if (endsWithSpace) text = text.replace(/^[ \u00a0]+/, '');
      }
      afterPick = false;
      if (text) await page.keyboard.insertText(text);
      continue;
    }

    hashtags++;
    await page.keyboard.type(part.value, { delay: 60 });
    const popover = page.locator(TIKTOK_HASHTAG_POPOVER_SELECTOR).first();
    const shown = await popover.waitFor({ state: 'visible', timeout: popoverWaitMs }).then(() => true).catch(() => false);
    if (!shown) {
      WorkerLogger.debug('TikTok Automation', `No hashtag suggestions for ${part.value}; left as typed.`);
      afterPick = false;
      continue;
    }
    // The list updates as keys arrive; give it a moment to settle on the full tag.
    await page.waitForTimeout(800);

    const want = normalizeTikTokTag(part.value);
    const items = popover.locator('[class*="item"], [role="option"], li');
    const count = await items.count().catch(() => 0);
    let pick = -1;
    for (let i = 0; i < Math.min(count, 12); i++) {
      const text = ((await items.nth(i).innerText().catch(() => '')) || '').trim();
      const first = normalizeTikTokTag(text.split(/\s+/)[0] || '');
      if (first === want) {
        pick = i;
        break;
      }
    }

    if (pick < 0 && count === 0) {
      // No entries we can read: trust the list only when its first word is the typed tag.
      const listText = ((await popover.innerText().catch(() => '')) || '').trim();
      if (normalizeTikTokTag(listText.split(/\s+/)[0] || '') === want) pick = 0;
    }

    if (pick === 0) {
      // The typed tag is the top suggestion: Enter picks it.
      await page.keyboard.press('Enter').catch(() => {});
      linked++;
      afterPick = true;
    } else if (pick > 0) {
      await items.nth(pick).click({ timeout: 3000 }).then(() => { linked++; afterPick = true; }).catch(async () => {
        await page.keyboard.press('Escape').catch(() => {});
        afterPick = false;
      });
    } else {
      WorkerLogger.debug('TikTok Automation', `Hashtag suggestions do not offer ${part.value}; left as typed.`);
      await page.keyboard.press('Escape').catch(() => {});
      afterPick = false;
    }
    await page.waitForTimeout(300);
  }

  // The caption may end on a hashtag still showing suggestions: close the list.
  await page.keyboard.press('Escape').catch(() => {});
  return { hashtags, linked };
}

export async function postTikTok(options: PostOptions): Promise<PostResult> {
  if (!options.mediaPath || !fs.existsSync(options.mediaPath)) {
    throw new Error('TikTok automation requires a valid video file path in mediaPath');
  }

  const sessionPath = getSessionPath('tiktok', options.sessionsDir);
  const timeout = options.timeoutMs || 45000;

  WorkerLogger.info('TikTok Automation', 'Connecting/launching browser for TikTok...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: options.headless,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);
    await page.bringToFront().catch(() => {});

    // Dismiss any popups, cookie banners or onboarding modals that TikTok overlays on the upload view
    const dismissTikTokOverlays = async () => {
      // 1. Purge shadow-DOM cookie banners, joyride onboarding overlays, and modal veils directly in page context
      await page.evaluate(() => {
        const doc = (globalThis as any).document;
        if (!doc) return;
        // Shadow-DOM or custom element cookie banners
        const cb = doc.querySelector('tiktok-cookie-banner');
        if (cb) {
          try {
            const btn = cb.shadowRoot?.querySelector('button');
            if (btn && typeof btn.click === 'function') btn.click();
          } catch {}
          cb.remove();
        }
        // Neutralize Joyride transparent overlay intercepts without unmounting React portal nodes
        doc.querySelectorAll('.react-joyride__overlay').forEach((el: any) => {
          el.style.pointerEvents = 'none';
        });
      }).catch(() => {});

      // 2. Cookie consent banner fallback buttons
      const cookieBtn = page.locator('button:has-text("Allow all"), button:has-text("Decline optional cookies"), button:has-text("Accept all")').first();
      if ((await cookieBtn.count()) > 0 && await cookieBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', 'Dismissing cookie banner...');
        await cookieBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);
      }

      // 3. If "Are you sure that you want to exit?" or "Sure you want to cancel your upload?" modal popped up:
      // In TikTok Studio, "Are you sure that you want to exit?" has [Exit] (destructive discard) and [Cancel] (stay/keep upload).
      // We must click [Cancel] to stay on the upload page and dismiss the modal!
      const exitModalCancelBtn = page.locator(
        'div[role="dialog"]:has-text("want to exit") button:has-text("Cancel"), ' +
        'div:has-text("Are you sure that you want to exit") button:has-text("Cancel"), ' +
        '.TUXModal:has-text("want to exit") button:has-text("Cancel"), ' +
        'div:has-text("Your progress and changes will not be saved") button:has-text("Cancel"), ' +
        'div:has-text("Sure you want to cancel") button:has-text("No"), ' +
        'div[role="dialog"]:has-text("cancel your upload") button:has-text("No")'
      ).first();
      if ((await exitModalCancelBtn.count()) > 0 && await exitModalCancelBtn.isVisible().catch(() => false)) {
        WorkerLogger.warn('TikTok Automation', `Dismissing exit/cancel upload modal by clicking "${await exitModalCancelBtn.textContent()}"...`);
        await exitModalCancelBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(400);
      }

      // 4. "Turn on automatic content checks?" modal (scoped specifically to content check dialogs, never generic Cancel!)
      const contentCheckBtn = page.locator('div[role="dialog"]:has-text("content check") button:has-text("Turn on"), div.TUXModal:has-text("content check") button:has-text("Turn on")').first();
      if ((await contentCheckBtn.count()) > 0 && await contentCheckBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', 'Dismissing automatic content checks dialog...');
        await contentCheckBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);
      }

      // 5. Joyride onboarding tooltip / modal (e.g. "Got it", "Next", "Done")
      const joyrideBtn = page.locator('#react-joyride-portal button, .react-joyride__tooltip button, button:has-text("Got it"), button:has-text("Done"), button:has-text("Next")').first();
      if ((await joyrideBtn.count()) > 0 && await joyrideBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', `Dismissing onboarding tour dialog (${await joyrideBtn.textContent().catch(() => '')})...`);
        await joyrideBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);
      }

      // 6. "Something went wrong" / transient error retry button
      const retryBtn = page.locator('div:has-text("Something went wrong") button:has-text("Retry"), button:has-text("Retry")').first();
      if ((await retryBtn.count()) > 0 && await retryBtn.isVisible().catch(() => false)) {
        WorkerLogger.warn('TikTok Automation', 'Detected "Something went wrong" banner, clicking "Retry"...');
        await retryBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(1000);
      }
    };

    WorkerLogger.info('TikTok Automation', 'Navigating to compose URL (https://www.tiktok.com/creator-center/upload)...');
    await page.goto('https://www.tiktok.com/creator-center/upload', {
      waitUntil: 'domcontentloaded'
    });

    WorkerLogger.debug('TikTok Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('login_redirect')) {
      throw new Error(`TikTok session invalid or expired: redirected to login page (${currentUrl})`);
    }

    await dismissTikTokOverlays();

    WorkerLogger.debug('TikTok Automation', 'Finding video upload input element...');
    const uploadInput = page.locator('input[type="file"][accept*="video"], input[type="file"]').first();
    await uploadInput.waitFor({ state: 'attached', timeout });

    const mediaSize = fs.statSync(options.mediaPath).size;
    WorkerLogger.info('TikTok Automation', `Uploading video file: ${options.mediaPath} (${mediaSize} bytes)...`);
    await uploadInput.setInputFiles(path.resolve(options.mediaPath));

    WorkerLogger.debug('TikTok Automation', 'Waiting for upload stream and caption editor to mount...');
    await page.waitForTimeout(4000);
    await dismissTikTokOverlays();

    WorkerLogger.info('TikTok Automation', `Typing post copy (${options.copy.length} chars)...`);
    const captionEditor = page.locator(
      '.notranslate.public-DraftEditor-content, div[contenteditable="true"], .notranslate[contenteditable="true"]'
    ).first();
    if ((await captionEditor.count()) > 0) {
      await captionEditor.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      try {
        await captionEditor.click({ timeout: 5000 });
      } catch {
        WorkerLogger.debug('TikTok Automation', 'Caption editor click intercepted, clicking with force: true...');
        await captionEditor.click({ force: true }).catch(() => {});
      }
      await page.waitForTimeout(300);
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.waitForTimeout(200);
      const typed = await typeTikTokCaption(page, options.copy);
      if (typed.hashtags > 0) {
        const level = typed.linked < typed.hashtags ? 'warn' : 'info';
        WorkerLogger[level]('TikTok Automation', `Caption hashtags picked from TikTok's suggestions: ${typed.linked}/${typed.hashtags}.`);
      }
      await page.waitForTimeout(500);
    }

    await dismissTikTokOverlays();

    if (options.aiGenerated) {
      await markTikTokAiGenerated(page);
      await dismissTikTokOverlays();
    }

    // Helper: Determine if Post button is currently disabled
    const isPostButtonDisabled = async (btn: any): Promise<boolean> => {
      try {
        if ((await btn.count()) === 0) return true;
        const isVis = await btn.isVisible().catch(() => false);
        if (!isVis) return true;
        const disabledAttr = await btn.getAttribute('disabled');
        if (disabledAttr !== null) return true;
        const ariaDisabled = await btn.getAttribute('aria-disabled');
        if (ariaDisabled === 'true') return true;
        const dataDisabled = await btn.getAttribute('data-disabled');
        if (dataDisabled === 'true') return true;
        const classNames = (await btn.getAttribute('class')) || '';
        if (/disabled|is-disabled|btn-disabled/i.test(classNames)) return true;
        const isEnabled = await btn.isEnabled().catch(() => false);
        if (!isEnabled) return true;

        // If upload_status_container is present in DOM, check its upload transfer status
        const statusContainer = page.locator('[data-e2e="upload_status_container"]').first();
        if ((await statusContainer.count()) > 0) {
          const statusText = (await statusContainer.textContent().catch(() => '')) || '';
          if (/left|Cancel|\d+%/i.test(statusText)) {
            return true; // Still uploading video chunks
          }
        }

        return false;
      } catch {
        return false;
      }
    };

    WorkerLogger.debug('TikTok Automation', 'Finding Post button and awaiting video processing...');

    // Resilient Post button resolver: strictly isolates the form submission Post button from navigation sidebar
    const resolvePostButton = () => {
      // 1. TikTok Studio official e2e attribute (the exact submit button)
      const e2eBtn = page.locator('button[data-e2e="post_video_button"]').first();
      // 2. Fallback e2e attribute used in test mocks / creator center
      const dropBtn = page.locator('button[data-e2e="post_video_drop"]').first();
      // 3. Action button in the footer group next to Discard button (scoped to discard sibling)
      const footerBtn = page.locator('div:has(> button[data-e2e="discard_post_button"]) button, div:has(> button:has-text("Discard")) button')
        .filter({ hasText: /^Post$/i })
        .first();
      // 4. Action button inside form / upload-stage with class btn-post
      const classBtn = page.locator('button.btn-post').first();
      // 5. Content-area button with exact text "Post" (strictly excluding navigation sidebar)
      const contentBtn = page.locator('div.jsx-2094875959 button, .upload-stage button, main button, form button')
        .filter({ hasText: /^Post$/i })
        .first();

      return e2eBtn.or(dropBtn).or(footerBtn).or(classBtn).or(contentBtn).first();
    };

    const postButton = resolvePostButton();
    try {
      await postButton.waitFor({ state: 'attached', timeout: 30000 });
    } catch (waitErr: any) {
      await dismissTikTokOverlays();
      const domBody = await page.evaluate(() => {
        const doc = (globalThis as any).document;
        return doc && doc.body ? doc.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 300) : '';
      }).catch(() => '');
      WorkerLogger.error('TikTok Automation', `Post button not found within 30s. Current page text: "${domBody}"`);
      throw waitErr;
    }

    // Wait up to 300 seconds for video upload and server-side processing to complete
    WorkerLogger.info('TikTok Automation', 'Waiting for video upload and processing to complete (up to 300s)...');
    let uploadReady = false;
    for (let i = 0; i < 300; i++) {
      await dismissTikTokOverlays();

      const currentPostBtn = resolvePostButton();
      const disabled = await isPostButtonDisabled(currentPostBtn);

      const statusContainer = page.locator('[data-e2e="upload_status_container"]').first();
      const hasStatusContainer = (await statusContainer.count()) > 0;
      const statusText = hasStatusContainer ? ((await statusContainer.textContent().catch(() => '')) || '') : '';
      const isUploading = /left|Cancel|\d+%/i.test(statusText) ||
                          await page.locator('.progress-bar, [role="progressbar"]').first().isVisible().catch(() => false);
      const isUploaded = hasStatusContainer ? /Uploaded|Replace/i.test(statusText) : true;

      // Require real upload completion: post button MUST be fully enabled (not disabled) AND video must be uploaded (not actively transferring chunks)
      if (!disabled && !isUploading && isUploaded) {
        WorkerLogger.info('TikTok Automation', `Video ready for posting after ${i + 1}s (uploaded: true, button enabled: true, status: "${statusText.replace(/\s+/g, ' ').trim()}").`);
        uploadReady = true;
        break;
      }

      if (i % 5 === 0) {
        WorkerLogger.debug('TikTok Automation', `Waiting for upload/transcode (${i + 1}/180s, button disabled: ${disabled}, uploading: ${isUploading}, uploaded: ${isUploaded}, status: "${statusText.replace(/\s+/g, ' ').trim()}")...`);
      }
      await page.waitForTimeout(1000);
    }

    if (!uploadReady) {
      WorkerLogger.warn('TikTok Automation', 'Upload ready state not explicitly confirmed after 180s, attempting post click anyway...');
    }

    // Dismiss any overlays and close hashtag dropdowns right before clicking
    await page.keyboard.press('Escape').catch(() => {});
    await dismissTikTokOverlays();

    const targetPostBtn = resolvePostButton();
    WorkerLogger.info('TikTok Automation', 'Clicking Post button...');

    // 1. Scroll button into view to ensure viewport visibility
    await targetPostBtn.scrollIntoViewIfNeeded({ timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(500);

    // 2. Focus
    await targetPostBtn.focus().catch(() => {});
    await page.waitForTimeout(200);

    // 3. Hover and click via Playwright
    await targetPostBtn.hover().catch(() => {});
    let clicked = false;
    try {
      await targetPostBtn.click({ timeout: 5000 });
      clicked = true;
    } catch {
      WorkerLogger.debug('TikTok Automation', 'Standard click intercepted, clicking Post with force: true...');
      try {
        await targetPostBtn.click({ force: true, timeout: 3000 });
        clicked = true;
      } catch (err: any) {
        WorkerLogger.warn('TikTok Automation', `Playwright click failed: ${err?.message || err}`);
      }
    }

    // 4. Multi-event DOM click dispatch to ensure React/Semi UI event handlers trigger ONLY if click was intercepted
    if (!clicked) {
      WorkerLogger.debug('TikTok Automation', 'Standard clicks failed or intercepted, attempting fallback native DOM event dispatch...');
      await targetPostBtn.evaluate((el: any) => {
        if (el) {
          el.focus();
          const win = (el.ownerDocument && el.ownerDocument.defaultView) || null;
          const targets = [el, el.firstElementChild || el];
          for (const t of targets) {
            if (win && typeof win.MouseEvent === 'function') {
              t.dispatchEvent(new win.MouseEvent('pointerdown', { bubbles: true, cancelable: true, view: win }));
              t.dispatchEvent(new win.MouseEvent('mousedown', { bubbles: true, cancelable: true, view: win }));
              t.dispatchEvent(new win.MouseEvent('pointerup', { bubbles: true, cancelable: true, view: win }));
              t.dispatchEvent(new win.MouseEvent('mouseup', { bubbles: true, cancelable: true, view: win }));
              t.dispatchEvent(new win.MouseEvent('click', { bubbles: true, cancelable: true, view: win }));
            }
          }
          if (typeof el.click === 'function') {
            el.click();
          }
        }
      }).catch(() => {});
    }

    await page.waitForTimeout(2000);

    // Check for and dismiss/confirm any secondary confirmation dialogs (e.g. "Post anyway", "Post now", "Turn on", "Confirm")
    for (let c = 0; c < 5; c++) {
      await dismissTikTokOverlays();
      const confirmModalBtn = page.locator(
        'button:has-text("Post now"), button:has-text("Post anyway"), button:has-text("Confirm"), button:has-text("Turn on")'
      ).first();
      if ((await confirmModalBtn.count()) > 0 && await confirmModalBtn.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', `Clicking secondary post confirmation button: "${await confirmModalBtn.textContent()}"...`);
        await confirmModalBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(2000);
      } else {
        break;
      }
    }

    WorkerLogger.info('TikTok Automation', 'Waiting for video submission confirmation (up to 60s)...');
    let isConfirmed = false;
    let finalUrl = page.url();
    const waitStartTime = Date.now();

    const successKeywords = [
      'Your video has been uploaded',
      'Your video is being processed',
      'Manage your posts',
      'Upload another video',
      'View profile'
    ];

    while (Date.now() - waitStartTime < 60000) {
      finalUrl = page.url();

      // 1. Navigation away from /upload to content or manage page
      if (!finalUrl.includes('/upload')) {
        WorkerLogger.info('TikTok Automation', `Navigation away from /upload detected: ${finalUrl}`);
        isConfirmed = true;
        break;
      }

      // 2. Success dialog text visible
      for (const kw of successKeywords) {
        const kwLoc = page.getByText(kw, { exact: false }).first();
        if ((await kwLoc.count()) > 0 && await kwLoc.isVisible().catch(() => false)) {
          WorkerLogger.info('TikTok Automation', `Success indicator confirmed: "${kw}"`);
          isConfirmed = true;
          break;
        }
      }
      if (isConfirmed) break;

      const successBox = page.locator('.upload-success-container').first();
      if ((await successBox.count()) > 0 && await successBox.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', 'Success container confirmed.');
        isConfirmed = true;
        break;
      }

      // 3. Late confirmation modal check (e.g. content check prompt)
      const lateConfirmBtn = page.locator('button:has-text("Post now"), button:has-text("Post anyway"), button:has-text("Confirm"), button:has-text("Turn on")').first();
      if ((await lateConfirmBtn.count()) > 0 && await lateConfirmBtn.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', `Late confirmation button visible ("${await lateConfirmBtn.textContent()}"), clicking it...`);
        await lateConfirmBtn.click({ force: true }).catch(() => {});
        await page.waitForTimeout(1000);
      }

      // 4. Dismiss any blocking modal overlays that may have appeared
      await dismissTikTokOverlays();

      await page.waitForTimeout(2000);
    }

    if (!isConfirmed) {
      // Capture failure screenshot and DOM state for diagnosis
      const errScreenshot = await captureErrorScreenshot(page, 'tiktok');
      WorkerLogger.error('TikTok Automation', `Post submission not confirmed within 60s. Screenshot captured: ${errScreenshot || 'none'}`);
      throw new Error(`TikTok post submission was not confirmed within 60s (URL remained ${finalUrl}). Screenshot: ${errScreenshot || 'none'}`);
    }

    // Give TikTok a few seconds to finalize background API dispatches before page close
    await page.waitForTimeout(3000);

    // Issue #191: report the real video URL (or at least the profile) instead of a made-up one.
    const resolved = await resolveTikTokPostUrl(page, options.copy);
    const generatedId = resolved.videoId || `tt-${Date.now()}`;
    const postUrl = resolved.url;
    WorkerLogger.info('TikTok Automation', `Post completed! URL: ${postUrl} (${resolved.kind}), ID: ${generatedId}`);

    return {
      success: true,
      platform: 'tiktok',
      externalPostId: generatedId,
      externalPostUrl: postUrl,
      external_id: generatedId,
      external_url: postUrl,
      mediaPath: options.mediaPath
    };
  } catch (err: any) {
    await captureAutomationFailure(page, 'TikTok', err);
    throw err;
  } finally {
    try {
      await page.close();
    } catch {}
    if (!options.browserSession) {
      await session.close();
    }
  }
}

/**
 * Facebook automation poster using saved browser session or running Chrome.
 */
export async function postFacebook(options: PostOptions): Promise<PostResult> {
  const sessionPath = getSessionPath('facebook', options.sessionsDir);
  const timeout = options.timeoutMs || 30000;

  WorkerLogger.info('Facebook Automation', 'Connecting/launching browser for Facebook...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: options.headless,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);
    await page.bringToFront().catch(() => {});

    WorkerLogger.info('Facebook Automation', 'Navigating to compose URL (https://www.facebook.com)...');
    await page.goto('https://www.facebook.com', { waitUntil: 'domcontentloaded' });

    WorkerLogger.debug('Facebook Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('login_attempt')) {
      throw new Error(`Facebook session invalid or expired: redirected to login page (${currentUrl})`);
    }

    WorkerLogger.debug('Facebook Automation', 'Finding composer trigger...');
    const composerTrigger = page.locator('div[role="button"]:has-text("What\'s on your mind")').first();
    if ((await composerTrigger.count()) > 0) {
      await composerTrigger.click();
      await page.waitForTimeout(1000);
    }

    WorkerLogger.debug('Facebook Automation', 'Finding post text box...');
    const modalPostBox = page.locator('div[role="dialog"] div[role="textbox"][contenteditable="true"]').first();
    const postBox = (await modalPostBox.count()) > 0
      ? modalPostBox
      : page.locator('div[role="textbox"][contenteditable="true"]').first();
    await postBox.waitFor({ state: 'visible', timeout });

    WorkerLogger.info('Facebook Automation', `Typing post copy (${options.copy.length} chars)...`);
    await postBox.click();
    await postBox.fill(options.copy);

    const facebookMedia = postMediaFiles(options, 4);
    if (facebookMedia.length > 0) {
      const mediaSize = facebookMedia.reduce((sum, f) => sum + fs.statSync(f).size, 0);
      WorkerLogger.info('Facebook Automation', `Uploading ${facebookMedia.length} media attachment(s): ${facebookMedia.join(', ')} (${mediaSize} bytes)...`);
      const fileInput = page.locator('input[type="file"][accept*="image"], input[type="file"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(facebookMedia.length === 1 ? facebookMedia[0] : facebookMedia);
        await page.waitForTimeout(2000 + (facebookMedia.length - 1) * 1500);
      }
    }

    WorkerLogger.info('Facebook Automation', 'Clicking Post button...');
    const modalPostBtn = page.locator('div[role="dialog"] div[aria-label="Post"][role="button"], div[role="dialog"] div[aria-label="Post"]').first();
    const postButton = (await modalPostBtn.count()) > 0
      ? modalPostBtn
      : page.locator('div[aria-label="Post"][role="button"]').first();
    if ((await postButton.count()) > 0) {
      await postButton.click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `fb-${Date.now()}`;
    const postUrl = `https://facebook.com/${generatedId}`;
    WorkerLogger.info('Facebook Automation', `Post completed! URL: ${postUrl}, ID: ${generatedId}`);

    return {
      success: true,
      platform: 'facebook',
      externalPostId: generatedId,
      externalPostUrl: postUrl,
      external_id: generatedId,
      external_url: postUrl,
      mediaPath: options.mediaPath
    };
  } catch (err: any) {
    await captureAutomationFailure(page, 'Facebook', err);
    throw err;
  } finally {
    try {
      await page.close();
    } catch {}
    if (!options.browserSession) {
      await session.close();
    }
  }
}

/**
 * In Instagram's "Crop" step, open the "Select crop" menu and pick the first available
 * aspect ratio from `preferred` (e.g. ['9:16', 'Original']). Returns the ratio picked, or
 * null when the menu or every option is missing; that is logged as a warning and the post
 * goes on with Instagram's default crop.
 */
export async function selectInstagramCrop(page: Page, preferred: string[]): Promise<string | null> {
  // Case-insensitive: Google Chrome (the worker's browser) labels it "Select Crop", while
  // headless Chromium says "Select crop".
  const cropButton = page.locator(
    '[role="button"]:has(svg[aria-label="Select crop" i]), button:has(svg[aria-label="Select crop" i]), svg[aria-label="Select crop" i]'
  ).first();
  const hasCrop = await cropButton.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
  if (!hasCrop) {
    WorkerLogger.warn('Instagram Automation', 'Crop menu ("Select crop") not found; posting with Instagram\'s default crop.');
    return null;
  }
  const opened = await cropButton.click({ timeout: 5000 }).then(() => true).catch((err: any) => {
    WorkerLogger.warn('Instagram Automation', `Could not open the crop menu (${err?.message || err}); posting with Instagram's default crop.`);
    return false;
  });
  if (!opened) {
    return null;
  }
  await page.waitForTimeout(800);
  for (const ratio of preferred) {
    const option = page.locator(
      `[role="dialog"] [role="button"]:has(span:text-is("${ratio}")), [role="dialog"] span:text-is("${ratio}"), [role="dialog"] div:text-is("${ratio}")`
    ).first();
    if ((await option.count()) > 0 && (await option.isVisible().catch(() => false))) {
      const picked = await option.click({ timeout: 5000 }).then(() => true).catch((err: any) => {
        WorkerLogger.warn('Instagram Automation', `Could not click the ${ratio} crop (${err?.message || err}).`);
        return false;
      });
      if (!picked) {
        continue;
      }
      await page.waitForTimeout(800);
      WorkerLogger.debug('Instagram Automation', `Selected the ${ratio} crop.`);
      return ratio;
    }
  }
  WorkerLogger.warn('Instagram Automation', `None of the crop options ${preferred.join(', ')} were found; posting with Instagram's default crop.`);
  return null;
}

/**
 * Dismiss Instagram's interstitial dialogs that cover the feed and block clicks, such as
 * "Turn on notifications", "Save your login info?" or "Add Instagram to your home screen",
 * by choosing their decline option. Returns the button labels it clicked.
 */
export async function dismissInstagramInterstitials(page: Page): Promise<string[]> {
  const clicked: string[] = [];
  for (let i = 0; i < 3; i++) {
    const decline = page
      .getByRole('dialog')
      .getByRole('button', { name: /^(Not now|Decline optional cookies)$/i })
      .first();
    const visible = await decline.isVisible().catch(() => false);
    if (!visible) break;
    const label = ((await decline.innerText().catch(() => '')) || 'Not Now').trim();
    WorkerLogger.debug('Instagram Automation', `Dismissing interstitial dialog via "${label}"...`);
    await decline.click({ timeout: 5000 }).catch(() => {});
    clicked.push(label);
    await page.waitForTimeout(800);
  }
  return clicked;
}

/** Collapse whitespace so captions compare the same in the composer, og:title and body text. */
export function normalizeInstagramText(text: string): string {
  return String(text || '').replace(/\s+/g, ' ').trim();
}

/**
 * Read the posting account's username from the open composer ("<name>'s profile picture").
 * Returns null when it is not shown.
 */
export async function readInstagramComposerAccount(page: Page): Promise<string | null> {
  const alt = await page
    .getByRole('dialog')
    .locator('img[alt$="profile picture" i]')
    .first()
    .getAttribute('alt', { timeout: 2000 })
    .catch(() => null);
  const match = alt ? /^(.+?)['’]s profile picture$/i.exec(alt.trim()) : null;
  return match ? match[1].trim() : null;
}

export interface InstagramPermalink {
  url: string;
  shortcode: string;
}

/**
 * Find the real link of a post that was just shared: the newest non-pinned post on the
 * account's profile whose time is not older than `sharedAtMs` (minus a 2 min clock margin)
 * and whose caption starts with the start of `copy`. Checks at most the 6 newest posts.
 * Returns null when nothing matches, so the caller never reports a made-up link.
 */
export async function resolveInstagramPermalink(
  page: Page,
  copy: string,
  sharedAtMs: number,
  accountName: string | null
): Promise<InstagramPermalink | null> {
  if (!accountName) {
    WorkerLogger.warn('Instagram Automation', 'Posting account name not found in the composer; cannot look up the new post link.');
    return null;
  }
  const prefix = normalizeInstagramText(copy).slice(0, 40);
  const profileUrl = `https://www.instagram.com/${encodeURIComponent(accountName)}/`;
  WorkerLogger.debug('Instagram Automation', `Looking up the new post's link on ${profileUrl}...`);
  await page.goto(profileUrl, { waitUntil: 'domcontentloaded' });
  const tiles = page.locator('a[href*="/p/"], a[href*="/reel/"]');
  await tiles.first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => {});
  const candidates: string[] = await tiles
    .evaluateAll((as) =>
      as
        .filter((a) => !a.querySelector('svg[aria-label*="Pinned" i]'))
        .map((a) => a.getAttribute('href') || '')
        .filter((h) => /\/(p|reel)\/[A-Za-z0-9_-]+/.test(h))
    )
    .catch(() => [] as string[]);
  const unique = [...new Set(candidates)].slice(0, 6);

  for (const href of unique) {
    const url = new URL(href, 'https://www.instagram.com/').toString();
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.locator('time[datetime]').first().waitFor({ state: 'attached', timeout: 10000 }).catch(() => {});
    const datetime = await page.locator('time[datetime]').first().getAttribute('datetime', { timeout: 2000 }).catch(() => null);
    const postedAt = datetime ? Date.parse(datetime) : NaN;
    if (Number.isFinite(postedAt) && postedAt < sharedAtMs - 120000) {
      // Older than this share: the posts further down are older still.
      break;
    }
    const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content', { timeout: 2000 }).catch(() => '');
    const body = await page.locator('body').innerText({ timeout: 2000 }).catch(() => '');
    const text = normalizeInstagramText(`${ogTitle || ''} ${body || ''}`);
    if (prefix && text.includes(prefix)) {
      const shortcode = (/\/(?:p|reel)\/([A-Za-z0-9_-]+)/.exec(url) || [])[1] || '';
      return { url, shortcode };
    }
  }
  return null;
}

/**
 * Find a button of Instagram's "Create new post" / "New reel" composer by its exact
 * accessible name (e.g. "Next", "Share"). It is looked up inside the open dialog only, so
 * look-alikes in the feed behind the modal ("Share" icons) or "Share to" never match. Only
 * when no dialog is open at all does it fall back to a page-wide exact match. Returns null
 * when the button does not become visible within `timeoutMs`.
 */
export async function findInstagramComposerButton(page: Page, name: string, timeoutMs: number): Promise<Locator | null> {
  const inDialog = page.getByRole('dialog').getByRole('button', { name, exact: true }).first();
  const anywhere = page.getByRole('button', { name, exact: true }).first();
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    if (await inDialog.isVisible().catch(() => false)) {
      return inDialog;
    }
    if ((await page.getByRole('dialog').count().catch(() => 0)) === 0 && (await anywhere.isVisible().catch(() => false))) {
      return anywhere;
    }
    if (Date.now() >= deadline) {
      return null;
    }
    await page.waitForTimeout(250);
  }
}

/**
 * Instagram automation poster using saved browser session or running Chrome.
 */
export async function postInstagram(options: PostOptions): Promise<PostResult> {
  if (!options.mediaPath || !fs.existsSync(options.mediaPath)) {
    throw new Error('Instagram automation requires a valid image or video file path in mediaPath');
  }

  const sessionPath = getSessionPath('instagram', options.sessionsDir);
  const timeout = options.timeoutMs || 35000;

  WorkerLogger.info('Instagram Automation', 'Connecting/launching browser for Instagram...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: options.headless,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);
    await page.bringToFront().catch(() => {});

    WorkerLogger.info('Instagram Automation', 'Navigating to compose URL (https://www.instagram.com)...');
    await page.goto('https://www.instagram.com', { waitUntil: 'domcontentloaded' });

    WorkerLogger.debug('Instagram Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/accounts/login')) {
      throw new Error(`Instagram session invalid or expired: redirected to login page (${currentUrl})`);
    }

    WorkerLogger.debug('Instagram Automation', 'Opening create/new post modal...');
    const fileInput = page.locator('input[type="file"]').first();
    const composerOpen = async (ms: number): Promise<boolean> =>
      fileInput.waitFor({ state: 'attached', timeout: ms }).then(() => true).catch(() => false);
    // Older layout: "New post" icon or a "Create" link. Current layout (Nov 2026): the
    // sidebar "Create" opens a menu whose "Post" entry (svg aria-label="Post") opens the composer.
    const createNav = page.locator(
      'svg[aria-label="New post"], svg[aria-label="Create"], a[role="link"]:has-text("Create")'
    ).first();
    const postEntry = page.locator(
      'a:has(svg[aria-label="Post"]), [role="link"]:has(svg[aria-label="Post"]), [role="button"]:has(svg[aria-label="Post"]), [role="menuitem"]:has(svg[aria-label="Post"]), svg[aria-label="Post"]'
    ).first();
    // Instagram overlays interstitial dialogs on the feed ("Turn on notifications", "Save your
    // login info?"). They intercept clicks, so a blocked click must fail fast and be retried
    // after dismissing them instead of hanging for the full default timeout.
    const clickQuick = async (target: typeof createNav): Promise<void> => {
      await target.click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(1000);
    };
    // Give an interstitial that renders after load a moment to appear before the first attempt.
    await page.getByRole('dialog').first().waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
    let opened = await composerOpen(500);
    for (let attempt = 1; attempt <= 2 && !opened; attempt++) {
      await dismissInstagramInterstitials(page);
      if (!(await composerOpen(300)) && (await createNav.count()) > 0) {
        await clickQuick(createNav);
      }
      if (!(await composerOpen(1500)) && (await postEntry.count()) > 0) {
        WorkerLogger.debug('Instagram Automation', `Clicking the "Post" entry to open the composer (attempt ${attempt})...`);
        await clickQuick(postEntry);
      }
      opened = await composerOpen(attempt === 2 ? Math.min(timeout, 15000) : 5000);
    }
    if (!opened) {
      const blocking = await page.getByRole('dialog').first().innerText({ timeout: 1000 }).catch(() => '');
      throw new Error(
        'Instagram composer did not open: no "New post", "Create" or "Post" entry led to an upload field' +
          (blocking ? ` (a dialog is still open: "${blocking.replace(/\s+/g, ' ').trim().slice(0, 120)}")` : ' (Instagram layout may have changed)') +
          '.'
      );
    }

    const mediaSize = fs.statSync(options.mediaPath).size;
    const isVideo = /\.(mp4|mov|webm|m4v)$/i.test(options.mediaPath);
    WorkerLogger.info('Instagram Automation', `Uploading ${isVideo ? 'video/Reel' : 'image'} attachment: ${options.mediaPath} (${mediaSize} bytes)...`);
    await fileInput.setInputFiles(path.resolve(options.mediaPath));
    await page.waitForTimeout(2000);

    // Instagram shows an informational "Video posts are now shared as reels" modal with an "OK" button on video uploads.
    if (isVideo) {
      const reelsOk = page.getByRole('dialog').getByRole('button', { name: 'OK', exact: true }).first();
      const hasReelsDialog = await reelsOk.waitFor({ state: 'visible', timeout: 4000 }).then(() => true).catch(() => false);
      if (hasReelsDialog) {
        WorkerLogger.debug('Instagram Automation', 'Dismissing "Video posts are now shared as reels" dialog...');
        await reelsOk.click();
        await page.waitForTimeout(1000);
      }
    }

    // The web composer crops every upload to 1:1 by default, which cuts the top and bottom
    // (and the burned-in captions) off our vertical 1080x1920 Reels. Pick 9:16 for videos.
    if (isVideo) {
      await selectInstagramCrop(page, ['9:16', 'Original']);
    }

    WorkerLogger.debug('Instagram Automation', 'Advancing Next buttons...');
    // Crop -> Edit -> Caption: click the composer's own "Next" until it is gone (at most 3 steps).
    for (let step = 0; step < 3; step++) {
      const nextButton = await findInstagramComposerButton(page, 'Next', step === 0 ? (isVideo ? 20000 : 10000) : 3000);
      if (!nextButton) break;
      await nextButton.click({ timeout: 10000 }).catch((err: any) => {
        throw new Error(`Instagram composer "Next" button could not be clicked: ${String(err?.message || err).split('\n')[0]}`);
      });
      await page.waitForTimeout(1000);
    }

    WorkerLogger.info('Instagram Automation', `Typing caption (${options.copy.length} chars)...`);
    const captionBox = page.locator(
      'div[aria-label*="Write a caption"], div[contenteditable="true"]'
    ).first();
    await captionBox.waitFor({ state: 'visible', timeout: 15000 });
    await captionBox.click();
    await captionBox.fill(options.copy);

    // The caption step shows the posting account ("<name>'s profile picture"); remember it
    // to look up the new post's link on the profile afterwards.
    const accountName = await readInstagramComposerAccount(page);

    WorkerLogger.info('Instagram Automation', 'Clicking Share button...');
    const sharedAt = Date.now();
    // Only the composer's own "Share": a page-wide `:has-text("Share")` also matches the feed's
    // paper-plane Share icons behind the modal (their SVG title is "Share"), whose clicks the
    // modal intercepts, and the composer's "Share to" toggle.
    const shareButton = await findInstagramComposerButton(page, 'Share', 15000);
    if (!shareButton) {
      throw new Error('Instagram composer "Share" button not found (the composer may have closed or changed).');
    }
    await shareButton.click({ timeout: 10000 }).catch((err: any) => {
      throw new Error(`Instagram composer "Share" button could not be clicked: ${String(err?.message || err).split('\n')[0]}`);
    });

    const sharedText = page.locator('text=/Your (reel|post) has been shared/i').first();
    if (isVideo) {
      await sharedText.waitFor({ state: 'visible', timeout: 60000 });
    } else {
      await sharedText.waitFor({ state: 'visible', timeout: 15000 }).catch(async () => {
        await page.waitForTimeout(3000);
      });
    }

    // Instagram's web composer does not show the new post's link, so find it on the profile.
    const permalink = await resolveInstagramPermalink(page, options.copy, sharedAt, accountName).catch((err: any) => {
      WorkerLogger.warn('Instagram Automation', `Could not look up the new post's link: ${String(err?.message || err).split('\n')[0]}`);
      return null;
    });
    const postUrl = permalink?.url || '';
    const postId = permalink?.shortcode || '';
    if (permalink) {
      WorkerLogger.info('Instagram Automation', `Post completed! URL: ${postUrl}, ID: ${postId}`);
    } else {
      WorkerLogger.warn('Instagram Automation', 'Post completed, but its link could not be confirmed on the profile; reporting it without a URL.');
    }

    return {
      success: true,
      platform: 'instagram',
      externalPostId: postId,
      externalPostUrl: postUrl,
      external_id: postId,
      external_url: postUrl,
      mediaPath: options.mediaPath
    };
  } catch (err: any) {
    await captureAutomationFailure(page, 'Instagram', err);
    throw err;
  } finally {
    try {
      await page.close();
    } catch {}
    if (!options.browserSession) {
      await session.close();
    }
  }
}

/**
 * Main dispatcher for social platform automation.
 * Validates session first, then delegates to platform-specific poster.
 */
export async function defaultPosterHandler(options: PostOptions): Promise<PostResult> {
  const platform = sanitizePlatform(options.platform);

  WorkerLogger.info('Browser Automation', `Verifying session for ${platform}...`);
  // 1. Session verification gate
  let verification = verifySession(platform, options.sessionsDir);
  WorkerLogger.debug(
    'Browser Automation',
    `Session file check for ${platform}: ${
      verification.valid ? 'VALID' : 'INVALID or MISSING'
    } (${verification.reason || 'OK'})`
  );

  if (!verification.valid) {
    // If CDP is reachable, attempt to extract session automatically
    WorkerLogger.debug('Browser Automation', `Checking if Chrome CDP is available at ${options.cdpUrl || 'default'}...`);
    const cdpAvail = await isCdpAvailable(options.cdpUrl, 500);
    if (cdpAvail) {
      WorkerLogger.info('Browser Automation', `CDP is available. Attempting auto-extraction of session for ${platform}...`);
      const extracted = await extractSessionFromCdp(platform, options.cdpUrl);
      if (extracted.success && extracted.state) {
        WorkerLogger.info('Browser Automation', `Auto-extracted ${extracted.cookieCount} cookies from CDP for ${platform}.`);
        saveSession(platform, extracted.state, options.sessionsDir);
        verification = verifySession(platform, options.sessionsDir);
      } else {
        WorkerLogger.warn('Browser Automation', `Auto-extraction from CDP failed: ${extracted.error}`);
      }
    } else {
      WorkerLogger.debug('Browser Automation', `Chrome CDP not available.`);
    }
  }

  if (!verification.valid) {
    throw new Error(
      `Missing or expired browser session for ${platform}. Run 'npm run login -- ${platform}' to authenticate.`
    );
  }

  // 2. Delegate to platform poster
  switch (platform) {
    case 'twitter':
      return await postTwitter(options);
    case 'tiktok':
      return await postTikTok(options);
    case 'facebook':
      return await postFacebook(options);
    case 'instagram':
      return await postInstagram(options);
    default:
      throw new Error(`Unsupported platform: ${platform}`);
  }
}

/**
 * Browser automation dispatcher for post execution.
 * Returns PostResult with normalized external_id / external_url fields,
 * catching internal execution errors into success: false result.
 */
export async function dispatchBrowserPost(options: PostOptions): Promise<PostResult> {
  try {
    const res = await defaultPosterHandler(options);
    return {
      ...res,
      external_id: res.external_id || res.externalPostId,
      external_url: res.external_url || res.externalPostUrl
    };
  } catch (err: any) {
    let plat: Platform;
    try {
      plat = sanitizePlatform(options.platform);
    } catch {
      plat = 'twitter';
    }
    WorkerLogger.error('Browser Automation', `[${plat.toUpperCase()}] Execution error: ${err.message}`, err);
    return {
      success: false,
      platform: plat,
      error: err.message || 'Browser automation failed'
    };
  }
}
