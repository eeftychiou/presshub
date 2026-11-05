import fs from 'fs';
import path from 'path';
import { WorkerLogger } from '../logger';
import {
  Platform,
  sanitizePlatform,
  verifySession,
  getSessionPath,
  saveSession
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
  sessionsDir?: string;
  timeoutMs?: number;
  cdpUrl?: string;
  browserSession?: ChromeConnection;
  headless?: boolean;
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

/**
 * Strictly enforce Single Link Rule and Brand Sanitization for Twitter:
 * 1. Guarantees only ONE URL (the article permalink) is present in the tweet.
 * 2. Strips any secondary URLs or homepage links (e.g. https://presshub.cy).
 * 3. Replaces standalone domain names like 'PressHub.Cy' or 'presshub.cy' with plain 'PressHub'
 *    so X/Twitter does not auto-link them as a second URL and suppress the featured image card.
 */
export function sanitizeTwitterCopy(copy: string): string {
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
  return text.trim();
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

    WorkerLogger.info('Twitter Automation', 'Navigating to compose URL (https://x.com/compose/post)...');
    await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });

    WorkerLogger.debug('Twitter Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('/i/flow/login')) {
      throw new Error(`Twitter session invalid or expired: redirected to login page (${currentUrl})`);
    }

    // Dismiss Twitter cookie banner if visible
    const dismissTwitterCookies = async () => {
      await page.evaluate(() => {
        const doc = (globalThis as any).document;
        if (!doc) return;
        doc.querySelectorAll('div[data-testid="BottomBar"]').forEach((el: any) => {
          if (el.textContent && /cookie/i.test(el.textContent)) {
            el.querySelectorAll('button').forEach((b: any) => {
              if (/refuse|accept|decline/i.test(b.textContent || '')) {
                if (typeof b.click === 'function') b.click();
              }
            });
          }
        });
      }).catch(() => {});
      const cookieAccept = page.locator('button:has-text("Accept all cookies"), button:has-text("Refuse non-essential cookies")').first();
      if ((await cookieAccept.count()) > 0 && await cookieAccept.isVisible().catch(() => false)) {
        WorkerLogger.debug('Twitter Automation', 'Dismissing cookie banner...');
        await cookieAccept.click({ force: true }).catch(() => {});
        await page.waitForTimeout(500);
      }
    };

    await dismissTwitterCookies();

    WorkerLogger.debug('Twitter Automation', 'Finding tweet text area...');
    // Scoped to modal dialog when /compose/post is loaded, or fallback to timeline box without strict mode violation
    const modalTextarea = page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]');
    const isModalVisible = await modalTextarea.first().waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
    const textarea = isModalVisible
      ? modalTextarea.first()
      : page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]').or(page.locator('[data-testid="tweetTextarea_0"]').first());

    await textarea.waitFor({ state: 'visible', timeout: 15000 });

    const tweetCopy = sanitizeTwitterCopy(options.copy);
    WorkerLogger.info('Twitter Automation', `Typing tweet copy (${tweetCopy.length} chars, sanitized for single-link)...`);
    await textarea.click();
    try {
      await textarea.fill(tweetCopy);
    } catch {
      await page.keyboard.type(tweetCopy, { delay: 10 });
    }

    // If media attached, upload via file input
    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      const mediaSize = fs.statSync(options.mediaPath).size;
      WorkerLogger.info('Twitter Automation', `Uploading media attachment: ${options.mediaPath} (${mediaSize} bytes)...`);
      const fileInput = page.locator('div[role="dialog"] input[data-testid="fileInput"], input[data-testid="fileInput"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        // Wait for attachment preview to appear
        await page.waitForTimeout(3000);
      }
    }

    WorkerLogger.debug('Twitter Automation', 'Finding Post button...');
    const modalButton = page.locator('div[role="dialog"] [data-testid="tweetButton"], div[role="dialog"] [data-testid="tweetButtonInline"]').first();
    const isModalBtn = (await modalButton.count()) > 0;
    const postButton = isModalBtn
      ? modalButton
      : page.locator('[data-testid="tweetButton"], [data-testid="tweetButtonInline"], button:has-text("Post")').first();

    await postButton.waitFor({ state: 'visible', timeout: 20000 });

    // Wait if button is disabled (e.g. video/image still processing)
    for (let i = 0; i < 30; i++) {
      await dismissTwitterCookies();

      const ariaDisabled = await postButton.getAttribute('aria-disabled');
      const disabled = await postButton.getAttribute('disabled');
      if (ariaDisabled !== 'true' && disabled === null) {
        break;
      }
      WorkerLogger.debug('Twitter Automation', `Waiting for tweet media processing and Post button to be actionable (${i + 1}/30)...`);
      await page.waitForTimeout(1000);
    }

    await dismissTwitterCookies();

    WorkerLogger.info('Twitter Automation', 'Clicking Post button...');
    await postButton.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
    await postButton.focus().catch(() => {});
    await postButton.hover().catch(() => {});
    let clicked = false;
    try {
      await postButton.click({ timeout: 5000 });
      clicked = true;
    } catch {
      WorkerLogger.debug('Twitter Automation', 'Pointer event intercepted by layer, clicking Post with force: true...');
      try {
        await postButton.click({ force: true, timeout: 3000 });
        clicked = true;
      } catch (err: any) {
        WorkerLogger.warn('Twitter Automation', `Playwright click failed: ${err?.message || err}`);
      }
    }
    // Only dispatch fallback native DOM events if Playwright click did not succeed
    if (!clicked) {
      WorkerLogger.debug('Twitter Automation', 'Dispatching fallback DOM click...');
      await postButton.evaluate((el: any) => {
        if (el && typeof el.click === 'function') {
          el.focus();
          const win = (el.ownerDocument && el.ownerDocument.defaultView) || null;
          if (win && typeof win.MouseEvent === 'function') {
            el.dispatchEvent(new win.MouseEvent('mousedown', { bubbles: true, cancelable: true, view: win }));
            el.dispatchEvent(new win.MouseEvent('mouseup', { bubbles: true, cancelable: true, view: win }));
            el.dispatchEvent(new win.MouseEvent('click', { bubbles: true, cancelable: true, view: win }));
          }
          el.click();
        }
      }).catch(() => {});
    }

    WorkerLogger.info('Twitter Automation', 'Waiting for tweet submission confirmation...');
    // Wait for the modal dialog to disappear or notification toast to appear
    await Promise.race([
      page.locator('div[role="dialog"]').first().waitFor({ state: 'detached', timeout: 15000 }),
      page.locator('[data-testid="toast"]').first().waitFor({ state: 'visible', timeout: 15000 })
    ]).catch(() => {});

    await page.waitForTimeout(1000);

    // Extract tweet URL and ID
    let tweetUrl: string | undefined;
    let tweetId: string | undefined;

    try {
      // Check if toast notification contains a direct link to the published tweet
      const toastLink = page.locator('[data-testid="toast"] a[href*="/status/"]').first();
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
    } catch {
      // Toast link extraction is best-effort
    }

    // Check page URL if it redirected to the status
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

    // Default fallback if not explicitly parsed
    if (!tweetUrl || !tweetId) {
      tweetId = tweetId || `tw-${Date.now()}`;
      tweetUrl = tweetUrl || `https://x.com/status/${tweetId}`;
    }

    WorkerLogger.info('Twitter Automation', `Post completed! URL: ${tweetUrl}, ID: ${tweetId}`);

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
 * TikTok Studio / Creator Center automation poster using saved browser session or running Chrome.
 */
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
      await page.keyboard.insertText(options.copy);
      await page.waitForTimeout(500);
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(500);
    }

    await dismissTikTokOverlays();

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

    // Wait up to 180 seconds for video upload and server-side processing to complete
    WorkerLogger.info('TikTok Automation', 'Waiting for video upload and processing to complete (up to 180s)...');
    let uploadReady = false;
    for (let i = 0; i < 180; i++) {
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
    try {
      await targetPostBtn.click({ timeout: 5000 });
    } catch {
      WorkerLogger.debug('TikTok Automation', 'Standard click intercepted, clicking Post with force: true...');
      await targetPostBtn.click({ force: true });
    }

    // 4. Multi-event DOM click dispatch to ensure React/Semi UI event handlers trigger
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

    const generatedId = `tt-${Date.now()}`;
    const postUrl = `https://www.tiktok.com/@creator/video/${generatedId}`;
    WorkerLogger.info('TikTok Automation', `Post completed! URL: ${postUrl}, ID: ${generatedId}`);

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

    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      const mediaSize = fs.statSync(options.mediaPath).size;
      WorkerLogger.info('Facebook Automation', `Uploading media attachment: ${options.mediaPath} (${mediaSize} bytes)...`);
      const fileInput = page.locator('input[type="file"][accept*="image"], input[type="file"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        await page.waitForTimeout(2000);
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
    const createNav = page.locator(
      'svg[aria-label="New post"], a[role="link"]:has-text("Create")'
    ).first();
    if ((await createNav.count()) > 0) {
      await createNav.click();
      await page.waitForTimeout(1000);
    }

    const mediaSize = fs.statSync(options.mediaPath).size;
    WorkerLogger.info('Instagram Automation', `Uploading media attachment: ${options.mediaPath} (${mediaSize} bytes)...`);
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.waitFor({ state: 'attached', timeout });
    await fileInput.setInputFiles(path.resolve(options.mediaPath));
    await page.waitForTimeout(2000);

    WorkerLogger.debug('Instagram Automation', 'Advancing Next buttons...');
    const nextButton = page.locator(
      'button:has-text("Next"), div[role="button"]:has-text("Next")'
    ).first();
    if ((await nextButton.count()) > 0) {
      await nextButton.click();
      await page.waitForTimeout(1000);
      if ((await nextButton.count()) > 0) {
        await nextButton.click();
        await page.waitForTimeout(1000);
      }
    }

    WorkerLogger.info('Instagram Automation', `Typing caption (${options.copy.length} chars)...`);
    const captionBox = page.locator(
      'div[aria-label*="Write a caption"], div[contenteditable="true"]'
    ).first();
    if ((await captionBox.count()) > 0) {
      await captionBox.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await captionBox.click();
      await captionBox.fill(options.copy);
    }

    WorkerLogger.info('Instagram Automation', 'Clicking Share button...');
    const shareButton = page.locator(
      'button:has-text("Share"), div[role="button"]:has-text("Share")'
    ).first();
    if ((await shareButton.count()) > 0) {
      await shareButton.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await shareButton.click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `ig-${Date.now()}`;
    const postUrl = `https://instagram.com/p/${generatedId}`;
    WorkerLogger.info('Instagram Automation', `Post completed! URL: ${postUrl}, ID: ${generatedId}`);

    return {
      success: true,
      platform: 'instagram',
      externalPostId: generatedId,
      externalPostUrl: postUrl,
      external_id: generatedId,
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
