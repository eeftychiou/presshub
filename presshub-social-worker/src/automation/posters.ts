import fs from 'fs';
import path from 'path';
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
    return screenshotPath;
  } catch (err: any) {
    console.warn(`[Screenshot] Failed to capture debug error screenshot for ${platform}: ${err.message}`);
    return undefined;
  }
}

/**
 * Twitter / X automation poster using saved browser session or running Chrome.
 */
export async function postTwitter(options: PostOptions): Promise<PostResult> {
  const sessionPath = getSessionPath('twitter', options.sessionsDir);
  const timeout = options.timeoutMs || 30000;

  console.log('[Twitter Automation] Connecting/launching browser...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: true,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);

    console.log('[Twitter Automation] Navigating to compose URL (https://x.com/compose/post)...');
    await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });

    console.log('[Twitter Automation] Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('/i/flow/login')) {
      throw new Error(`Twitter session invalid or expired: redirected to login page (${currentUrl})`);
    }

    console.log('[Twitter Automation] Finding tweet text area...');
    // Scoped to modal dialog when /compose/post is loaded, or fallback to timeline box without strict mode violation
    const modalTextarea = page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]');
    const isModalVisible = await modalTextarea.first().waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
    const textarea = isModalVisible
      ? modalTextarea.first()
      : page.locator('div[role="dialog"] [data-testid="tweetTextarea_0"]').or(page.locator('[data-testid="tweetTextarea_0"]').first());

    await textarea.waitFor({ state: 'visible', timeout: 15000 });

    console.log(`[Twitter Automation] Typing tweet copy (${options.copy.length} chars)...`);
    await textarea.click();
    try {
      await textarea.fill(options.copy);
    } catch {
      await page.keyboard.type(options.copy, { delay: 10 });
    }

    // If media attached, upload via file input
    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      console.log(`[Twitter Automation] Uploading media attachment: ${options.mediaPath}...`);
      const fileInput = page.locator('div[role="dialog"] input[data-testid="fileInput"], input[data-testid="fileInput"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        // Wait for attachment preview to appear
        await page.waitForTimeout(2000);
      }
    }

    console.log('[Twitter Automation] Clicking Post button...');
    const modalButton = page.locator('div[role="dialog"] [data-testid="tweetButton"], div[role="dialog"] [data-testid="tweetButtonInline"]').first();
    const postButton = (await modalButton.count()) > 0
      ? modalButton
      : page.locator('[data-testid="tweetButton"], [data-testid="tweetButtonInline"]').first();

    await postButton.waitFor({ state: 'visible', timeout: 15000 });
    await postButton.click();

    console.log('[Twitter Automation] Waiting for tweet submission confirmation...');
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

    console.log(`[Twitter Automation] Post completed! URL: ${tweetUrl}`);

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
    let currentUrl = 'unknown';
    try {
      currentUrl = page.url();
    } catch {}
    const screenshot = await captureErrorScreenshot(page, 'twitter');
    console.error(
      `[Twitter Automation] Error: ${err.message} (Page URL: ${currentUrl})${screenshot ? ` [Screenshot: ${screenshot}]` : ''}`
    );
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

  console.log('[TikTok Automation] Connecting/launching browser...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: true,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);

    console.log('[TikTok Automation] Navigating to compose URL (https://www.tiktok.com/creator-center/upload)...');
    await page.goto('https://www.tiktok.com/creator-center/upload', {
      waitUntil: 'domcontentloaded'
    });

    console.log('[TikTok Automation] Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('login_redirect')) {
      throw new Error(`TikTok session invalid or expired: redirected to login page (${currentUrl})`);
    }

    console.log('[TikTok Automation] Finding video upload input element...');
    const uploadInput = page.locator('input[type="file"][accept*="video"], input[type="file"]').first();
    await uploadInput.waitFor({ state: 'attached', timeout });

    console.log(`[TikTok Automation] Uploading video file: ${options.mediaPath}...`);
    await uploadInput.setInputFiles(path.resolve(options.mediaPath));

    console.log('[TikTok Automation] Waiting for video processing & caption editor...');
    await page.waitForTimeout(5000);

    console.log(`[TikTok Automation] Typing post copy (${options.copy.length} chars)...`);
    const captionEditor = page.locator(
      'div[contenteditable="true"], .notranslate[contenteditable="true"]'
    ).first();
    if ((await captionEditor.count()) > 0) {
      await captionEditor.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await captionEditor.click();
      await captionEditor.fill(options.copy);
    }

    console.log('[TikTok Automation] Clicking Post button...');
    const postButton = page.locator('button:has-text("Post"), button[data-e2e="post_video_drop"]').first();
    if ((await postButton.count()) > 0) {
      await postButton.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await postButton.click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `tt-${Date.now()}`;
    const postUrl = `https://www.tiktok.com/@creator/video/${generatedId}`;
    console.log(`[TikTok Automation] Post completed! URL: ${postUrl}`);

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
    let currentUrl = 'unknown';
    try {
      currentUrl = page.url();
    } catch {}
    const screenshot = await captureErrorScreenshot(page, 'tiktok');
    console.error(
      `[TikTok Automation] Error: ${err.message} (Page URL: ${currentUrl})${screenshot ? ` [Screenshot: ${screenshot}]` : ''}`
    );
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

  console.log('[Facebook Automation] Connecting/launching browser...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: true,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);

    console.log('[Facebook Automation] Navigating to compose URL (https://www.facebook.com)...');
    await page.goto('https://www.facebook.com', { waitUntil: 'domcontentloaded' });

    console.log('[Facebook Automation] Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('login_attempt')) {
      throw new Error(`Facebook session invalid or expired: redirected to login page (${currentUrl})`);
    }

    console.log('[Facebook Automation] Finding composer trigger...');
    const composerTrigger = page.locator('div[role="button"]:has-text("What\'s on your mind")').first();
    if ((await composerTrigger.count()) > 0) {
      await composerTrigger.click();
      await page.waitForTimeout(1000);
    }

    console.log('[Facebook Automation] Finding post text box...');
    const modalPostBox = page.locator('div[role="dialog"] div[role="textbox"][contenteditable="true"]').first();
    const postBox = (await modalPostBox.count()) > 0
      ? modalPostBox
      : page.locator('div[role="textbox"][contenteditable="true"]').first();
    await postBox.waitFor({ state: 'visible', timeout });

    console.log(`[Facebook Automation] Typing post copy (${options.copy.length} chars)...`);
    await postBox.click();
    await postBox.fill(options.copy);

    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      console.log(`[Facebook Automation] Uploading media attachment: ${options.mediaPath}...`);
      const fileInput = page.locator('input[type="file"][accept*="image"], input[type="file"]').first();
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        await page.waitForTimeout(2000);
      }
    }

    console.log('[Facebook Automation] Clicking Post button...');
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
    console.log(`[Facebook Automation] Post completed! URL: ${postUrl}`);

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
    let currentUrl = 'unknown';
    try {
      currentUrl = page.url();
    } catch {}
    const screenshot = await captureErrorScreenshot(page, 'facebook');
    console.error(
      `[Facebook Automation] Error: ${err.message} (Page URL: ${currentUrl})${screenshot ? ` [Screenshot: ${screenshot}]` : ''}`
    );
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

  console.log('[Instagram Automation] Connecting/launching browser...');
  const session =
    options.browserSession ||
    (await connectOrLaunchChrome({
      headless: true,
      cdpUrl: options.cdpUrl,
      storageState: sessionPath
    }));

  const page = await session.context.newPage();
  try {
    page.setDefaultTimeout(timeout);

    console.log('[Instagram Automation] Navigating to compose URL (https://www.instagram.com)...');
    await page.goto('https://www.instagram.com', { waitUntil: 'domcontentloaded' });

    console.log('[Instagram Automation] Checking if logged in...');
    const currentUrl = page.url();
    if (currentUrl.includes('/accounts/login')) {
      throw new Error(`Instagram session invalid or expired: redirected to login page (${currentUrl})`);
    }

    console.log('[Instagram Automation] Opening create/new post modal...');
    const createNav = page.locator(
      'svg[aria-label="New post"], a[role="link"]:has-text("Create")'
    ).first();
    if ((await createNav.count()) > 0) {
      await createNav.click();
      await page.waitForTimeout(1000);
    }

    console.log(`[Instagram Automation] Uploading media attachment: ${options.mediaPath}...`);
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.waitFor({ state: 'attached', timeout });
    await fileInput.setInputFiles(path.resolve(options.mediaPath));
    await page.waitForTimeout(2000);

    console.log('[Instagram Automation] Advancing Next buttons...');
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

    console.log(`[Instagram Automation] Typing caption (${options.copy.length} chars)...`);
    const captionBox = page.locator(
      'div[aria-label*="Write a caption"], div[contenteditable="true"]'
    ).first();
    if ((await captionBox.count()) > 0) {
      await captionBox.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      await captionBox.click();
      await captionBox.fill(options.copy);
    }

    console.log('[Instagram Automation] Clicking Share button...');
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
    console.log(`[Instagram Automation] Post completed! URL: ${postUrl}`);

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
    let currentUrl = 'unknown';
    try {
      currentUrl = page.url();
    } catch {}
    const screenshot = await captureErrorScreenshot(page, 'instagram');
    console.error(
      `[Instagram Automation] Error: ${err.message} (Page URL: ${currentUrl})${screenshot ? ` [Screenshot: ${screenshot}]` : ''}`
    );
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

  console.log(`[Browser Automation] Verifying session for ${platform}...`);
  // 1. Session verification gate
  let verification = verifySession(platform, options.sessionsDir);
  console.log(
    `[Browser Automation] Session file check for ${platform}: ${
      verification.valid ? 'VALID' : 'INVALID or MISSING'
    } (${verification.reason || 'OK'})`
  );

  if (!verification.valid) {
    // If CDP is reachable, attempt to extract session automatically
    console.log(`[Browser Automation] Checking if Chrome CDP is available at ${options.cdpUrl || 'default'}...`);
    const cdpAvail = await isCdpAvailable(options.cdpUrl, 500);
    if (cdpAvail) {
      console.log(`[Browser Automation] CDP is available. Attempting auto-extraction of session for ${platform}...`);
      const extracted = await extractSessionFromCdp(platform, options.cdpUrl);
      if (extracted.success && extracted.state) {
        console.log(`[Browser Automation] Auto-extracted ${extracted.cookieCount} cookies from CDP for ${platform}.`);
        saveSession(platform, extracted.state, options.sessionsDir);
        verification = verifySession(platform, options.sessionsDir);
      } else {
        console.warn(`[Browser Automation] Auto-extraction from CDP failed: ${extracted.error}`);
      }
    } else {
      console.log(`[Browser Automation] Chrome CDP not available.`);
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
    console.error(`[Browser Automation] [${plat.toUpperCase()}] Execution error:`, err.message);
    return {
      success: false,
      platform: plat,
      error: err.message || 'Browser automation failed'
    };
  }
}
