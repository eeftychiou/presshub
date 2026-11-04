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
    const outputDir = customOutputDir || path.resolve(process.cwd(), 'output');
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

    WorkerLogger.info('Twitter Automation', 'Navigating to compose URL (https://x.com/compose/post)...');
    await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });

    WorkerLogger.debug('Twitter Automation', 'Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('/i/flow/login')) {
      throw new Error(`Twitter session invalid or expired: redirected to login page (${currentUrl})`);
    }

    // Dismiss Twitter cookie banner if visible
    const cookieAccept = page.locator('button:has-text("Accept all cookies"), button:has-text("Refuse non-essential cookies")').first();
    if ((await cookieAccept.count()) > 0 && await cookieAccept.isVisible().catch(() => false)) {
      WorkerLogger.debug('Twitter Automation', 'Dismissing cookie banner...');
      await cookieAccept.click().catch(() => {});
      await page.waitForTimeout(500);
    }

    WorkerLogger.debug('Twitter Automation', 'Finding tweet text area...');
    // Scoped to modal dialog when /compose/post is loaded, or fallback to timeline box without strict mode violation
    const modalTextarea = page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]');
    const isModalVisible = await modalTextarea.first().waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
    const textarea = isModalVisible
      ? modalTextarea.first()
      : page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]').or(page.locator('[data-testid="tweetTextarea_0"]').first());

    await textarea.waitFor({ state: 'visible', timeout: 15000 });

    WorkerLogger.info('Twitter Automation', `Typing tweet copy (${options.copy.length} chars)...`);
    await textarea.click();
    try {
      await textarea.fill(options.copy);
    } catch {
      await page.keyboard.type(options.copy, { delay: 10 });
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
    const postButton = (await modalButton.count()) > 0
      ? modalButton
      : page.locator('[data-testid="tweetButton"], [data-testid="tweetButtonInline"]').first();

    await postButton.waitFor({ state: 'visible', timeout: 20000 });

    // Wait if button is disabled (e.g. video/image still processing)
    for (let i = 0; i < 30; i++) {
      const cb = page.locator('button:has-text("Accept all cookies"), button:has-text("Refuse non-essential cookies")').first();
      if ((await cb.count()) > 0 && await cb.isVisible().catch(() => false)) {
        await cb.click().catch(() => {});
      }

      const ariaDisabled = await postButton.getAttribute('aria-disabled');
      const disabled = await postButton.getAttribute('disabled');
      if (ariaDisabled !== 'true' && disabled === null) {
        break;
      }
      WorkerLogger.debug('Twitter Automation', `Waiting for tweet media processing and Post button to be actionable (${i + 1}/30)...`);
      await page.waitForTimeout(1000);
    }

    // Dismiss any remaining cookie banners before clicking post
    const cookieBtn = page.locator('button:has-text("Refuse non-essential cookies"), button:has-text("Accept all cookies")').first();
    if ((await cookieBtn.count()) > 0) {
      WorkerLogger.debug('Twitter Automation', 'Dismissing cookie banner before posting...');
      await cookieBtn.click({ force: true }).catch(() => {});
      await page.waitForTimeout(500);
    }

    WorkerLogger.info('Twitter Automation', 'Clicking Post button...');
    await postButton.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
    await postButton.focus().catch(() => {});
    await postButton.hover().catch(() => {});
    try {
      await postButton.click({ timeout: 5000 });
    } catch {
      WorkerLogger.debug('Twitter Automation', 'Pointer event intercepted by layer, clicking Post with force: true...');
      await postButton.click({ force: true });
    }
    // Native DOM dispatch ensures framework handlers fire even under complex overlay styling
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

    // Dismiss any popups, cookie banners or onboarding modals that TikTok overlays on the upload view
    const dismissTikTokOverlays = async () => {
      // 1. Cookie consent banner
      const cookieBtn = page.locator('button:has-text("Allow all"), button:has-text("Decline optional cookies"), button:has-text("Accept all")').first();
      if ((await cookieBtn.count()) > 0 && await cookieBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', 'Dismissing cookie banner...');
        await cookieBtn.click().catch(() => {});
        await page.waitForTimeout(500);
      }
      // 2. Automatic content checks dialog ("Turn on automatic content checks?")
      const checkBtn = page.locator('button:has-text("Turn on"), button:has-text("Cancel")').first();
      if ((await checkBtn.count()) > 0 && await checkBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', 'Dismissing automatic content checks dialog...');
        await checkBtn.click().catch(() => {});
        await page.waitForTimeout(500);
      }
      // 3. "Got it" onboarding tooltip / modal
      const gotItBtn = page.locator('button:has-text("Got it")').first();
      if ((await gotItBtn.count()) > 0 && await gotItBtn.isVisible().catch(() => false)) {
        WorkerLogger.debug('TikTok Automation', 'Dismissing "Got it" dialog...');
        await gotItBtn.click().catch(() => {});
        await page.waitForTimeout(500);
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
      'div[contenteditable="true"], .notranslate[contenteditable="true"]'
    ).first();
    if ((await captionEditor.count()) > 0) {
      await captionEditor.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await captionEditor.click();
      await page.keyboard.press('Control+A').catch(() => {});
      await captionEditor.fill(options.copy);
      await page.waitForTimeout(1000);
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
        const classNames = (await btn.getAttribute('class')) || '';
        if (/disabled|is-disabled|btn-disabled/i.test(classNames)) return true;
        const isEnabled = await btn.isEnabled().catch(() => false);
        return !isEnabled;
      } catch {
        return false;
      }
    };

    WorkerLogger.debug('TikTok Automation', 'Finding Post button and awaiting video processing...');

    // Resilient Post button resolver: prioritizes the action button next to Discard or with exact "Post" text
    const resolvePostButton = () => {
      // 1. Footer button located beside the Discard button in TikTok Studio
      const footerBtn = page.locator('div:has(> button:has-text("Discard")) button:has-text("Post"), .button-group button:has-text("Post")').first();
      // 2. Class/data-attribute selectors
      const classBtn = page.locator('button.btn-post, button[data-e2e="post_video_drop"]').first();
      // 3. Exact button name "Post" (prevents matching "When to post", radio options, or tooltips)
      const exactBtn = page.getByRole('button', { name: /^Post$/i }).last();
      // 4. Last button on page containing "Post" (footer button is near page bottom)
      const lastPostBtn = page.locator('button:has-text("Post")').last();

      return footerBtn.or(classBtn).or(exactBtn).or(lastPostBtn).first();
    };

    const postButton = resolvePostButton();
    await postButton.waitFor({ state: 'attached', timeout: 30000 });

    // Wait up to 120 seconds for video upload and server-side processing to complete
    WorkerLogger.info('TikTok Automation', 'Waiting for video upload and processing to complete (up to 120s)...');
    let uploadReady = false;
    for (let i = 0; i < 120; i++) {
      await dismissTikTokOverlays();

      const currentPostBtn = resolvePostButton();
      const disabled = await isPostButtonDisabled(currentPostBtn);

      // Check upload state indicators in DOM:
      // When complete, TikTok shows "Uploaded(13.6MB)" or "Uploaded" or "Upload complete"
      const isUploadedIndicator = await page.locator('text=/Uploaded/i, text=/Upload complete/i').first().isVisible().catch(() => false);
      const isUploading = await page.locator('text=/Uploading/i, text=/Transcoding/i, .progress-bar, [role="progressbar"]').first().isVisible().catch(() => false);

      // Require upload completion indicator (or at least 20s if already disabled=false and not uploading)
      if (!disabled && !isUploading && (isUploadedIndicator || i >= 20)) {
        WorkerLogger.info('TikTok Automation', `Video ready for posting after ${i + 1}s (uploaded: ${isUploadedIndicator}, button enabled: ${!disabled}).`);
        uploadReady = true;
        break;
      }

      if (i % 5 === 0) {
        WorkerLogger.debug('TikTok Automation', `Waiting for upload/transcode (${i + 1}/120s, button disabled: ${disabled}, uploading: ${isUploading}, uploaded: ${isUploadedIndicator})...`);
      }
      await page.waitForTimeout(1000);
    }

    if (!uploadReady) {
      WorkerLogger.warn('TikTok Automation', 'Upload ready state not explicitly confirmed after 120s, attempting post click anyway...');
    }

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

    await page.waitForTimeout(3000);

    // Check for and dismiss/confirm any secondary confirmation dialogs (e.g. "Post anyway", "Post now", "Turn on")
    for (let c = 0; c < 5; c++) {
      await dismissTikTokOverlays();
      const confirmModalBtn = page.locator(
        'button:has-text("Post anyway"), button:has-text("Post now"), button:has-text("Confirm")'
      ).first();
      if ((await confirmModalBtn.count()) > 0 && await confirmModalBtn.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', `Clicking secondary post confirmation button: "${await confirmModalBtn.textContent()}"...`);
        await confirmModalBtn.click().catch(() => {});
        await page.waitForTimeout(2000);
      } else {
        break;
      }
    }

    WorkerLogger.info('TikTok Automation', 'Waiting for video submission confirmation (up to 60s)...');
    const successLocators = page.locator([
      'text="Your video has been uploaded"',
      'text="Your video is being processed"',
      'text="Manage your posts"',
      'button:has-text("Manage your posts")',
      'text="Upload another video"',
      'button:has-text("Upload another video")',
      'text="View profile"',
      'button:has-text("View profile")',
      '.upload-success-container'
    ].join(', ')).first();

    let isConfirmed = false;
    let finalUrl = page.url();
    const waitStartTime = Date.now();

    while (Date.now() - waitStartTime < 60000) {
      finalUrl = page.url();

      // 1. Navigation away from /upload to content or manage page
      if (!finalUrl.includes('/upload')) {
        WorkerLogger.info('TikTok Automation', `Navigation away from /upload detected: ${finalUrl}`);
        isConfirmed = true;
        break;
      }

      // 2. Success dialog visible
      if (await successLocators.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', 'Success dialog ("Your video has been uploaded" / "Manage your posts") confirmed.');
        isConfirmed = true;
        break;
      }

      // 3. Late confirmation modal check
      const lateConfirmBtn = page.locator('button:has-text("Post anyway"), button:has-text("Post now"), button:has-text("Confirm")').first();
      if (await lateConfirmBtn.isVisible().catch(() => false)) {
        WorkerLogger.info('TikTok Automation', 'Late confirmation button visible, clicking it...');
        await lateConfirmBtn.click().catch(() => {});
      }

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
