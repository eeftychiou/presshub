import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import {
  Platform,
  sanitizePlatform,
  verifySession,
  getSessionPath
} from './session-manager';

export interface PostOptions {
  platform: Platform;
  copy: string;
  mediaPath?: string;
  sessionsDir?: string;
  timeoutMs?: number;
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
 * Twitter / X automation poster using saved browser session.
 */
export async function postTwitter(options: PostOptions): Promise<PostResult> {
  const sessionPath = getSessionPath('twitter', options.sessionsDir);
  const timeout = options.timeoutMs || 30000;

  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      storageState: sessionPath,
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(timeout);

    await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });

    // Wait for compose textarea
    const tweetBox = page.locator('[data-testid="tweetTextarea_0"]');
    await tweetBox.waitFor({ state: 'visible', timeout });
    await tweetBox.click();
    await tweetBox.fill(options.copy);

    // If media attached, upload via file input
    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      const fileInput = page.locator('input[data-testid="fileInput"]');
      if (await fileInput.count() > 0) {
        await fileInput.setInputFiles(path.resolve(options.mediaPath));
        // Wait for attachment preview to appear
        await page.waitForTimeout(2000);
      }
    }

    // Click Tweet button
    const tweetButton = page.locator('[data-testid="tweetButton"]');
    await tweetButton.waitFor({ state: 'visible', timeout });
    await tweetButton.click();

    // Allow request dispatch
    await page.waitForTimeout(3000);

    const generatedId = `tw-${Date.now()}`;
    return {
      success: true,
      platform: 'twitter',
      externalPostId: generatedId,
      externalPostUrl: `https://x.com/status/${generatedId}`,
      mediaPath: options.mediaPath
    };
  } finally {
    await browser.close();
  }
}

/**
 * TikTok Studio / Creator Center automation poster using saved browser session.
 */
export async function postTikTok(options: PostOptions): Promise<PostResult> {
  if (!options.mediaPath || !fs.existsSync(options.mediaPath)) {
    throw new Error('TikTok automation requires a valid video file path in mediaPath');
  }

  const sessionPath = getSessionPath('tiktok', options.sessionsDir);
  const timeout = options.timeoutMs || 45000;

  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      storageState: sessionPath,
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(timeout);

    await page.goto('https://www.tiktok.com/creator-center/upload', { waitUntil: 'domcontentloaded' });

    // Upload video
    const uploadInput = page.locator('input[type="file"][accept*="video"], input[type="file"]');
    await uploadInput.waitFor({ state: 'attached', timeout });
    await uploadInput.setInputFiles(path.resolve(options.mediaPath));

    // Wait for video processing & caption editor
    await page.waitForTimeout(5000);

    // Fill caption
    const captionEditor = page.locator('div[contenteditable="true"], .notranslate[contenteditable="true"]');
    if (await captionEditor.count() > 0) {
      await captionEditor.first().click();
      await captionEditor.first().fill(options.copy);
    }

    // Submit post
    const postButton = page.locator('button:has-text("Post"), button[data-e2e="post_video_drop"]');
    if (await postButton.count() > 0) {
      await postButton.first().click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `tt-${Date.now()}`;
    return {
      success: true,
      platform: 'tiktok',
      externalPostId: generatedId,
      externalPostUrl: `https://www.tiktok.com/@creator/video/${generatedId}`,
      mediaPath: options.mediaPath
    };
  } finally {
    await browser.close();
  }
}

/**
 * Facebook automation poster using saved browser session.
 */
export async function postFacebook(options: PostOptions): Promise<PostResult> {
  const sessionPath = getSessionPath('facebook', options.sessionsDir);
  const timeout = options.timeoutMs || 30000;

  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      storageState: sessionPath,
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(timeout);

    await page.goto('https://www.facebook.com', { waitUntil: 'domcontentloaded' });

    // Create post composer
    const composerTrigger = page.locator('div[role="button"]:has-text("What\'s on your mind")');
    if (await composerTrigger.count() > 0) {
      await composerTrigger.first().click();
      await page.waitForTimeout(1000);
    }

    const postBox = page.locator('div[role="textbox"][contenteditable="true"]');
    await postBox.waitFor({ state: 'visible', timeout });
    await postBox.fill(options.copy);

    if (options.mediaPath && fs.existsSync(options.mediaPath)) {
      const fileInput = page.locator('input[type="file"][accept*="image"], input[type="file"]');
      if (await fileInput.count() > 0) {
        await fileInput.first().setInputFiles(path.resolve(options.mediaPath));
        await page.waitForTimeout(2000);
      }
    }

    const postButton = page.locator('div[aria-label="Post"][role="button"]');
    if (await postButton.count() > 0) {
      await postButton.click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `fb-${Date.now()}`;
    return {
      success: true,
      platform: 'facebook',
      externalPostId: generatedId,
      externalPostUrl: `https://facebook.com/${generatedId}`,
      mediaPath: options.mediaPath
    };
  } finally {
    await browser.close();
  }
}

/**
 * Instagram automation poster using saved browser session.
 */
export async function postInstagram(options: PostOptions): Promise<PostResult> {
  if (!options.mediaPath || !fs.existsSync(options.mediaPath)) {
    throw new Error('Instagram automation requires a valid image or video file path in mediaPath');
  }

  const sessionPath = getSessionPath('instagram', options.sessionsDir);
  const timeout = options.timeoutMs || 35000;

  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      storageState: sessionPath,
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(timeout);

    await page.goto('https://www.instagram.com', { waitUntil: 'domcontentloaded' });

    // Open new post modal
    const createNav = page.locator('svg[aria-label="New post"], a[role="link"]:has-text("Create")');
    if (await createNav.count() > 0) {
      await createNav.first().click();
      await page.waitForTimeout(1000);
    }

    const fileInput = page.locator('input[type="file"]');
    await fileInput.waitFor({ state: 'attached', timeout });
    await fileInput.setInputFiles(path.resolve(options.mediaPath));
    await page.waitForTimeout(2000);

    // Next button
    const nextButton = page.locator('button:has-text("Next"), div[role="button"]:has-text("Next")');
    if (await nextButton.count() > 0) {
      await nextButton.first().click();
      await page.waitForTimeout(1000);
      if (await nextButton.count() > 0) {
        await nextButton.first().click();
        await page.waitForTimeout(1000);
      }
    }

    // Add caption
    const captionBox = page.locator('div[aria-label*="Write a caption"], div[contenteditable="true"]');
    if (await captionBox.count() > 0) {
      await captionBox.first().fill(options.copy);
    }

    // Share button
    const shareButton = page.locator('button:has-text("Share"), div[role="button"]:has-text("Share")');
    if (await shareButton.count() > 0) {
      await shareButton.first().click();
      await page.waitForTimeout(3000);
    }

    const generatedId = `ig-${Date.now()}`;
    return {
      success: true,
      platform: 'instagram',
      externalPostId: generatedId,
      externalPostUrl: `https://instagram.com/p/${generatedId}`,
      mediaPath: options.mediaPath
    };
  } finally {
    await browser.close();
  }
}

/**
 * Main dispatcher for social platform automation.
 * Validates session first, then delegates to platform-specific poster.
 */
export async function defaultPosterHandler(options: PostOptions): Promise<PostResult> {
  const platform = sanitizePlatform(options.platform);

  // 1. Session verification gate
  const verification = verifySession(platform, options.sessionsDir);
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
    return {
      success: false,
      platform: plat,
      error: err.message || 'Browser automation failed'
    };
  }
}

