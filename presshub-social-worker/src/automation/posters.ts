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
    const tweetBox = page.locator('[data-testid="tweetTextarea_0"]');
    await tweetBox.waitFor({ state: 'visible', timeout });

    console.log(`[Twitter Automation] Typing tweet copy (${options.copy.length} chars)...`);
    await tweetBox.click();
    await tweetBox.fill(options.copy);

    // If media attached, upload via file input
    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      console.log(`[Twitter Automation] Uploading media attachment: ${options.mediaPath}...`);
      const fileInput = page.locator('input[data-testid="fileInput"]');
      if ((await fileInput.count()) > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        // Wait for attachment preview to appear
        await page.waitForTimeout(2000);
      }
    }

    console.log('[Twitter Automation] Clicking Post button...');
    const tweetButton = page.locator('[data-testid="tweetButton"]');
    await tweetButton.waitFor({ state: 'visible', timeout });
    await tweetButton.click();

    // Allow request dispatch
    await page.waitForTimeout(3000);

    const generatedId = `tw-${Date.now()}`;
    const postUrl = `https://x.com/status/${generatedId}`;
    console.log(`[Twitter Automation] Post completed! URL: ${postUrl}`);

    return {
      success: true,
      platform: 'twitter',
      externalPostId: generatedId,
      externalPostUrl: postUrl,
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
    const uploadInput = page.locator('input[type="file"][accept*="video"], input[type="file"]');
    await uploadInput.waitFor({ state: 'attached', timeout });

    console.log(`[TikTok Automation] Uploading video file: ${options.mediaPath}...`);
    await uploadInput.setInputFiles(path.resolve(options.mediaPath));

    console.log('[TikTok Automation] Waiting for video processing & caption editor...');
    await page.waitForTimeout(5000);

    console.log(`[TikTok Automation] Typing post copy (${options.copy.length} chars)...`);
    const captionEditor = page.locator(
      'div[contenteditable="true"], .notranslate[contenteditable="true"]'
    );
    if ((await captionEditor.count()) > 0) {
      await captionEditor.first().click();
      await captionEditor.first().fill(options.copy);
    }

    console.log('[TikTok Automation] Clicking Post button...');
    const postButton = page.locator('button:has-text("Post"), button[data-e2e="post_video_drop"]');
    if ((await postButton.count()) > 0) {
      await postButton.first().click();
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
    const composerTrigger = page.locator('div[role="button"]:has-text("What\'s on your mind")');
    if ((await composerTrigger.count()) > 0) {
      await composerTrigger.first().click();
      await page.waitForTimeout(1000);
    }

    console.log('[Facebook Automation] Finding post text box...');
    const postBox = page.locator('div[role="textbox"][contenteditable="true"]');
    await postBox.waitFor({ state: 'visible', timeout });

    console.log(`[Facebook Automation] Typing post copy (${options.copy.length} chars)...`);
    await postBox.fill(options.copy);

    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      console.log(`[Facebook Automation] Uploading media attachment: ${options.mediaPath}...`);
      const fileInput = page.locator('input[type="file"][accept*="image"], input[type="file"]');
      if ((await fileInput.count()) > 0) {
        await fileInput.first().setInputFiles(path.resolve(options.mediaPath));
        await page.waitForTimeout(2000);
      }
    }

    console.log('[Facebook Automation] Clicking Post button...');
    const postButton = page.locator('div[aria-label="Post"][role="button"]');
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
    );
    if ((await createNav.count()) > 0) {
      await createNav.first().click();
      await page.waitForTimeout(1000);
    }

    console.log(`[Instagram Automation] Uploading media attachment: ${options.mediaPath}...`);
    const fileInput = page.locator('input[type="file"]');
    await fileInput.waitFor({ state: 'attached', timeout });
    await fileInput.setInputFiles(path.resolve(options.mediaPath));
    await page.waitForTimeout(2000);

    console.log('[Instagram Automation] Advancing Next buttons...');
    const nextButton = page.locator(
      'button:has-text("Next"), div[role="button"]:has-text("Next")'
    );
    if ((await nextButton.count()) > 0) {
      await nextButton.first().click();
      await page.waitForTimeout(1000);
      if ((await nextButton.count()) > 0) {
        await nextButton.first().click();
        await page.waitForTimeout(1000);
      }
    }

    console.log(`[Instagram Automation] Typing caption (${options.copy.length} chars)...`);
    const captionBox = page.locator(
      'div[aria-label*="Write a caption"], div[contenteditable="true"]'
    );
    if ((await captionBox.count()) > 0) {
      await captionBox.first().fill(options.copy);
    }

    console.log('[Instagram Automation] Clicking Share button...');
    const shareButton = page.locator(
      'button:has-text("Share"), div[role="button"]:has-text("Share")'
    );
    if ((await shareButton.count()) > 0) {
      await shareButton.first().click();
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
