import { chromium, Browser, BrowserContext } from 'playwright';
import http from 'http';
import https from 'https';
import fs from 'fs';
import path from 'path';
import {
  Platform,
  buildTwitterStorageState,
  buildTikTokStorageState,
  parseCookieExport,
  parseCookieHeader
} from './session-manager';

export {
  buildTwitterStorageState,
  buildTikTokStorageState,
  parseCookieExport,
  parseCookieHeader
};

export const STEALTH_ARGS = [
  '--disable-blink-features=AutomationControlled',
  '--no-first-run',
  '--no-default-browser-check'
];

export const STEALTH_INIT_SCRIPT = `Object.defineProperty(navigator, 'webdriver', { get: () => undefined });`;

export const REAL_CHROME_USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

export interface ChromeConnectionOptions {
  headless?: boolean;
  cdpUrl?: string;
  userDataDir?: string;
  storageState?: string;
}

export interface ChromeConnection {
  browser: Browser | null;
  context: BrowserContext;
  isExternalCdp: boolean;
  close: () => Promise<void>;
}

/**
 * Searches system standard paths to locate real installed Google Chrome executable.
 */
export function findChromeExecutable(): string | null {
  if (process.env.PRESSHUB_CHROME_BIN && fs.existsSync(process.env.PRESSHUB_CHROME_BIN)) {
    return process.env.PRESSHUB_CHROME_BIN;
  }
  if (process.env.CHROME_BIN && fs.existsSync(process.env.CHROME_BIN)) {
    return process.env.CHROME_BIN;
  }

  const platform = process.platform;
  const candidates: string[] = [];

  if (platform === 'win32') {
    const programFiles = process.env.PROGRAMFILES || 'C:\\Program Files';
    const programFilesX86 = process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)';
    const localAppData =
      process.env.LOCALAPPDATA || path.join(process.env.USERPROFILE || '', 'AppData', 'Local');

    candidates.push(
      path.join(programFiles, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(programFilesX86, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(localAppData, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
      'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'
    );
  } else if (platform === 'darwin') {
    candidates.push(
      '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      `${process.env.HOME}/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
    );
  } else {
    // Linux
    candidates.push(
      '/usr/bin/google-chrome',
      '/usr/bin/google-chrome-stable',
      '/usr/bin/chromium',
      '/usr/bin/chromium-browser',
      '/snap/bin/chromium'
    );
  }

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return null;
}

/**
 * Probes a CDP endpoint (e.g. http://127.0.0.1:9222) to determine if Chrome is running
 * with remote debugging active. Uses a fast timeout (default 500ms).
 */
export async function isCdpAvailable(
  endpoint: string = process.env.PRESSHUB_CHROME_CDP_URL || 'http://127.0.0.1:9222',
  timeoutMs: number = 500
): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    try {
      const cleanEndpoint = endpoint.replace(/\/$/, '');
      const versionUrl = cleanEndpoint.endsWith('/json/version')
        ? cleanEndpoint
        : `${cleanEndpoint}/json/version`;

      const parsedUrl = new URL(versionUrl);
      const transport = parsedUrl.protocol === 'https:' ? https : http;

      const req = transport.get(
        parsedUrl,
        {
          timeout: timeoutMs,
          headers: { Host: parsedUrl.host }
        },
        (res) => {
          resolve(res.statusCode === 200);
          res.resume();
        }
      );

      req.on('timeout', () => {
        req.destroy();
        resolve(false);
      });

      req.on('error', () => {
        resolve(false);
      });
    } catch {
      resolve(false);
    }
  });
}

/**
 * Connects to a running Google Chrome instance via CDP, or launches the system's real
 * installed Google Chrome with stealth flags (falling back to bundled Chromium in CI).
 */
export async function connectOrLaunchChrome(
  options: ChromeConnectionOptions = {}
): Promise<ChromeConnection> {
  const cdpTarget =
    options.cdpUrl || process.env.PRESSHUB_CHROME_CDP_URL || 'http://127.0.0.1:9222';

  // 1. First probe if Chrome CDP endpoint is running
  const hasCdp = await isCdpAvailable(cdpTarget, 500);
  if (hasCdp) {
    try {
      const browser = await chromium.connectOverCDP(cdpTarget);
      const contexts = browser.contexts();
      const context = contexts[0] || (await browser.newContext({ userAgent: REAL_CHROME_USER_AGENT }));

      await context.addInitScript(STEALTH_INIT_SCRIPT);

      if (options.storageState && fs.existsSync(options.storageState)) {
        try {
          const raw = JSON.parse(fs.readFileSync(options.storageState, 'utf-8'));
          if (Array.isArray(raw?.cookies) && raw.cookies.length > 0) {
            await context.addCookies(raw.cookies);
          }
        } catch {}
      }

      return {
        browser,
        context,
        isExternalCdp: true,
        close: async () => {
          // When connected to external running Chrome via CDP,
          // do NOT kill the user's browser process!
        }
      };
    } catch (err: any) {
      console.warn(
        `[PressHub Browser] CDP connection failed (${err.message}). Launching local Chrome...`
      );
    }
  }

  // 2. Otherwise launch real installed Google Chrome with stealth flags
  if (options.userDataDir) {
    let context: BrowserContext;
    try {
      context = await chromium.launchPersistentContext(options.userDataDir, {
        channel: 'chrome',
        headless: options.headless ?? false,
        ignoreDefaultArgs: ['--enable-automation'],
        args: STEALTH_ARGS,
        userAgent: REAL_CHROME_USER_AGENT,
        viewport: null
      });
    } catch {
      // Fallback to default Chromium if channel 'chrome' is absent (e.g. CI)
      context = await chromium.launchPersistentContext(options.userDataDir, {
        headless: options.headless ?? false,
        ignoreDefaultArgs: ['--enable-automation'],
        args: STEALTH_ARGS,
        userAgent: REAL_CHROME_USER_AGENT,
        viewport: null
      });
    }

    await context.addInitScript(STEALTH_INIT_SCRIPT);

    return {
      browser: context.browser(),
      context,
      isExternalCdp: false,
      close: async () => {
        try {
          await context.close();
        } catch {}
      }
    };
  }

  // Non-persistent launch
  let browser: Browser;
  try {
    browser = await chromium.launch({
      channel: 'chrome',
      headless: options.headless ?? true,
      ignoreDefaultArgs: ['--enable-automation'],
      args: STEALTH_ARGS
    });
  } catch {
    // Fallback to default Chromium if channel 'chrome' is absent (e.g. CI)
    browser = await chromium.launch({
      headless: options.headless ?? true,
      ignoreDefaultArgs: ['--enable-automation'],
      args: STEALTH_ARGS
    });
  }

  const contextOptions: any = {
    userAgent: REAL_CHROME_USER_AGENT
  };
  if (options.storageState && fs.existsSync(options.storageState)) {
    contextOptions.storageState = options.storageState;
  }

  const context = await browser.newContext(contextOptions);
  await context.addInitScript(STEALTH_INIT_SCRIPT);

  return {
    browser,
    context,
    isExternalCdp: false,
    close: async () => {
      try {
        await context.close();
      } catch {}
      try {
        await browser.close();
      } catch {}
    }
  };
}

/**
 * Connects to running Chrome over CDP and extracts cookies/storageState for the given platform.
 */
export async function extractSessionFromCdp(
  platform: Platform,
  cdpUrl: string = process.env.PRESSHUB_CHROME_CDP_URL || 'http://127.0.0.1:9222'
): Promise<{ success: boolean; cookieCount: number; state?: any; error?: string }> {
  try {
    const isAvail = await isCdpAvailable(cdpUrl, 1000);
    if (!isAvail) {
      return { success: false, cookieCount: 0, error: `CDP endpoint not reachable at ${cdpUrl}` };
    }

    const browser = await chromium.connectOverCDP(cdpUrl);
    const contexts = browser.contexts();
    const context = contexts[0] || (await browser.newContext());

    const storageState = await context.storageState();
    const cookies = storageState.cookies || [];

    let hasEssentialCookies = false;
    let missingNote = '';

    if (platform === 'twitter') {
      hasEssentialCookies = cookies.some((c) => c.name === 'auth_token');
      if (!hasEssentialCookies) {
        missingNote = 'No "auth_token" cookie found in Chrome session for x.com / twitter.com';
      }
    } else if (platform === 'tiktok') {
      hasEssentialCookies = cookies.some((c) => c.name === 'sessionid' || c.name === 'sessionid_ss');
      if (!hasEssentialCookies) {
        missingNote = 'No "sessionid" cookie found in Chrome session for tiktok.com';
      }
    } else if (platform === 'facebook') {
      hasEssentialCookies = cookies.some((c) => c.name === 'c_user' || c.name === 'xs');
      if (!hasEssentialCookies) {
        missingNote = 'No "c_user" or "xs" cookie found in Chrome session for facebook.com';
      }
    } else if (platform === 'instagram') {
      hasEssentialCookies = cookies.some((c) => c.name === 'sessionid');
      if (!hasEssentialCookies) {
        missingNote = 'No "sessionid" cookie found in Chrome session for instagram.com';
      }
    } else {
      hasEssentialCookies = cookies.length > 0;
    }

    if (!hasEssentialCookies) {
      return {
        success: false,
        cookieCount: cookies.length,
        state: storageState,
        error: missingNote || `No valid session cookies found for ${platform}`
      };
    }

    return {
      success: true,
      cookieCount: cookies.length,
      state: storageState
    };
  } catch (err: any) {
    return {
      success: false,
      cookieCount: 0,
      error: `Failed to extract session over CDP: ${err.message}`
    };
  }
}
