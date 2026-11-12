import { chromium } from 'playwright';
import readline from 'readline';
import path from 'path';
import fs from 'fs';
import {
  Platform,
  sanitizePlatform,
  getSessionPath,
  getSessionsDir,
  getReaderSessionsDir,
  verifySession,
  saveSession,
  SUPPORTED_PLATFORMS,
  buildTwitterStorageState,
  buildTikTokStorageState,
  parseCookieExport,
  parseCookieHeader
} from '../automation/session-manager';
import {
  isCdpAvailable,
  extractSessionFromCdp,
  STEALTH_ARGS,
  STEALTH_INIT_SCRIPT
} from '../automation/browser-helper';

const LOGIN_URLS: Record<Platform, string> = {
  twitter: 'https://x.com/i/flow/login',
  tiktok: 'https://www.tiktok.com/login',
  facebook: 'https://www.facebook.com/login',
  instagram: 'https://www.instagram.com/accounts/login/'
};

export function promptQuestion(query: string): Promise<string> {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise<string>((resolve) => {
    rl.question(query, (ans) => {
      rl.close();
      resolve(ans.trim());
    });
  });
}

/**
 * Mode 2: Direct Cookie Paste
 */
export async function handleCookiePaste(platform: Platform, sessionPath: string): Promise<boolean> {
  console.log(`\n📋 Mode 2: Direct Cookie Paste for ${platform.toUpperCase()}`);
  console.log(`------------------------------------------------------`);
  console.log(`You can paste either:`);
  console.log(`  A) Direct cookie values from Chrome DevTools (F12 -> Application -> Cookies)`);
  console.log(`  B) Full JSON cookie export from Cookie-Editor extension or Cookie header\n`);

  if (platform === 'twitter') {
    const inputOrToken = await promptQuestion(
      `Enter 'auth_token' value (or paste JSON export / cookie string): `
    );

    if (!inputOrToken) {
      console.error(`❌ Error: auth_token or JSON cannot be empty.`);
      return false;
    }

    let state: any;
    if (inputOrToken.startsWith('[') || inputOrToken.startsWith('{')) {
      state = parseCookieExport(inputOrToken);
    } else if (inputOrToken.includes('auth_token=')) {
      const parsedHeader = parseCookieHeader(inputOrToken);
      state = buildTwitterStorageState(parsedHeader['auth_token'], parsedHeader['ct0']);
    } else {
      const ct0 = await promptQuestion(
        `Enter 'ct0' CSRF token (press ENTER to skip if not available): `
      );
      state = buildTwitterStorageState(inputOrToken, ct0 || undefined);
    }

    saveSession(platform, state);
    const verification = verifySession(platform);
    if (verification.valid) {
      console.log(
        `\n✅ Twitter session saved and verified successfully! Active cookies: ${verification.cookieCount}`
      );
      console.log(`Session destination: ${sessionPath}\n`);
      return true;
    } else {
      console.warn(`\n⚠️ Warning: Verification failed: ${verification.reason}`);
      return false;
    }
  } else if (platform === 'tiktok') {
    const inputOrSessionId = await promptQuestion(
      `Enter 'sessionid' value (or paste JSON export / cookie string): `
    );

    if (!inputOrSessionId) {
      console.error(`❌ Error: sessionid or JSON cannot be empty.`);
      return false;
    }

    let state: any;
    if (inputOrSessionId.startsWith('[') || inputOrSessionId.startsWith('{')) {
      state = parseCookieExport(inputOrSessionId);
    } else if (inputOrSessionId.includes('sessionid=')) {
      const parsedHeader = parseCookieHeader(inputOrSessionId);
      state = buildTikTokStorageState(
        parsedHeader['sessionid'] || parsedHeader['sessionid_ss'] || inputOrSessionId
      );
    } else {
      state = buildTikTokStorageState(inputOrSessionId);
    }

    saveSession(platform, state);
    const verification = verifySession(platform);
    if (verification.valid) {
      console.log(
        `\n✅ TikTok session saved and verified successfully! Active cookies: ${verification.cookieCount}`
      );
      console.log(`Session destination: ${sessionPath}\n`);
      return true;
    } else {
      console.warn(`\n⚠️ Warning: Verification failed: ${verification.reason}`);
      return false;
    }
  } else {
    console.log(`For ${platform}, paste full JSON cookie export from Cookie-Editor extension:`);
    const jsonStr = await promptQuestion(`Paste JSON here: `);
    if (!jsonStr) {
      console.error(`❌ Error: input cannot be empty.`);
      return false;
    }
    const state = parseCookieExport(jsonStr);
    saveSession(platform, state);
    const verification = verifySession(platform);
    if (verification.valid) {
      console.log(
        `\n✅ ${platform} session saved and verified! Active cookies: ${verification.cookieCount}`
      );
      console.log(`Session destination: ${sessionPath}\n`);
      return true;
    } else {
      console.warn(`\n⚠️ Warning: Verification failed: ${verification.reason}`);
      return false;
    }
  }
}

/**
 * Mode 3: Real Google Chrome with Stealth Flags & Persistent Profile
 */
export async function handleBrowserLogin(
  platform: Platform,
  loginUrl: string,
  sessionPath: string
): Promise<boolean> {
  const profileDir = path.resolve(getSessionsDir(), 'chrome-profile');
  if (!fs.existsSync(profileDir)) {
    fs.mkdirSync(profileDir, { recursive: true });
  }

  console.log(`\n🌐 Mode 3: Launching Real Google Chrome (Persistent Profile & Stealth Flags)`);
  console.log(`Profile Directory: ${profileDir}`);
  console.log(`Target URL:        ${loginUrl}`);

  let context: any;
  try {
    context = await chromium.launchPersistentContext(profileDir, {
      channel: 'chrome',
      headless: false,
      ignoreDefaultArgs: ['--enable-automation'],
      args: STEALTH_ARGS,
      viewport: null
    });
  } catch {
    console.warn(
      `System Google Chrome not found via channel 'chrome', falling back to default Chromium...`
    );
    context = await chromium.launchPersistentContext(profileDir, {
      headless: false,
      ignoreDefaultArgs: ['--enable-automation'],
      args: STEALTH_ARGS,
      viewport: null
    });
  }

  try {
    await context.addInitScript(STEALTH_INIT_SCRIPT);

    const page = context.pages()[0] || (await context.newPage());
    console.log(`Navigating to ${loginUrl}...`);
    await page.goto(loginUrl);

    console.log(
      `\n👉 Please complete your login and any 2FA/verification in the opened Chrome window.`
    );
    await promptQuestion(
      `\nOnce you are successfully logged in on ${platform}, press [ENTER] here to save your session... `
    );

    console.log(`Saving browser cookies and storage state to: ${sessionPath}...`);
    await context.storageState({ path: sessionPath });

    const verification = verifySession(platform);
    if (verification.valid) {
      console.log(
        `\n✅ Session saved and verified successfully! Active cookies: ${verification.cookieCount}`
      );
      console.log(`You can now automate posts to ${platform} via PressHub AI.\n`);
      return true;
    } else {
      console.warn(`\n⚠️ Warning: Saved session check returned invalid: ${verification.reason}`);
      return false;
    }
  } finally {
    try {
      await context.close();
    } catch {}
  }
}

export async function runLogin(platformArg?: string): Promise<void> {
  const args = process.argv.slice(2);
  const target = platformArg || args.find((a) => !a.startsWith('-'));

  // --reader: save the session of the Social Monitor's reader account instead
  // of the posting account (separate folder and separate Chrome profile).
  if (args.includes('--reader')) {
    const readerDir = getReaderSessionsDir();
    fs.mkdirSync(readerDir, { recursive: true });
    const previous = process.env.PRESSHUB_SESSIONS_DIR;
    process.env.PRESSHUB_SESSIONS_DIR = readerDir;
    console.log(`\n👀 Reader account mode: the session is saved for the Social Monitor in ${readerDir}`);
    try {
      return await runLoginFlow(target, args);
    } finally {
      if (previous === undefined) delete process.env.PRESSHUB_SESSIONS_DIR;
      else process.env.PRESSHUB_SESSIONS_DIR = previous;
    }
  }
  return runLoginFlow(target, args);
}

async function runLoginFlow(target: string | undefined, args: string[]): Promise<void> {

  if (!target) {
    console.error(`\n❌ Error: No platform specified.`);
    console.log(`Usage: npm run login -- <platform> [options]`);
    console.log(`Supported platforms: ${SUPPORTED_PLATFORMS.join(', ')}`);
    console.log(`\nOptions:`);
    console.log(`  --cookies, -c    Direct cookie paste mode (paste auth_token or JSON export)`);
    console.log(`  --cdp            Auto-extract session from running Chrome (port 9222)`);
    console.log(`  --browser, -b    Open Real Google Chrome window with persistent profile`);
    console.log(`  --reader         Save the Social Monitor reader account (not the posting account)\n`);
    process.exit(1);
  }

  let platform: Platform;
  try {
    platform = sanitizePlatform(target);
  } catch (err: any) {
    console.error(`\n❌ ${err.message}\n`);
    process.exit(1);
  }

  const loginUrl = LOGIN_URLS[platform];
  const sessionPath = getSessionPath(platform);
  const isCookiesMode = args.includes('--cookies') || args.includes('-c');
  const isBrowserMode =
    args.includes('--browser') || args.includes('-b') || args.includes('--interactive');

  console.log(`\n======================================================`);
  console.log(`🔐 PressHub Social Media Worker — Login Flow`);
  console.log(`Platform:            ${platform.toUpperCase()}`);
  console.log(`Target URL:          ${loginUrl}`);
  console.log(`Session Destination: ${sessionPath}`);
  console.log(`======================================================`);

  // Explicit flag: Direct cookie paste
  if (isCookiesMode) {
    const success = await handleCookiePaste(platform, sessionPath);
    if (!success) process.exit(1);
    return;
  }

  // Explicit flag: Browser launch
  if (isBrowserMode) {
    const success = await handleBrowserLogin(platform, loginUrl, sessionPath);
    if (!success) process.exit(1);
    return;
  }

  // Check if CDP is active (Mode 1: Auto-extract from running Chrome)
  const cdpAvailable = await isCdpAvailable('http://127.0.0.1:9222', 500);
  if (cdpAvailable) {
    console.log(`\n🔍 Found running Google Chrome with remote debugging on port 9222!`);
    console.log(`Attempting to auto-extract active ${platform} session from live Chrome profile...`);

    const result = await extractSessionFromCdp(platform, 'http://127.0.0.1:9222');
    if (result.success && result.state) {
      saveSession(platform, result.state);
      const verification = verifySession(platform);
      console.log(`\n✅ Mode 1 Success: Extracted ${platform} session from running Chrome!`);
      console.log(`Active cookies: ${verification.cookieCount}`);
      console.log(`Saved to: ${sessionPath}`);
      console.log(`You can now automate posts to ${platform} via PressHub AI.\n`);
      return;
    }

    console.warn(`\n⚠️ Connected to running Chrome on port 9222, but: ${result.error}`);
    console.log(
      `You can log in to ${loginUrl} in that Chrome window and press ENTER, or choose another option below:`
    );
  }

  // Prompt user for preferred mode
  console.log(`\nChoose login method:`);
  console.log(`  [ENTER] Launch Real Google Chrome (Stealth window with persistent profile)`);
  console.log(`  [c]     Direct cookie paste (enter auth_token or paste JSON export)`);
  if (cdpAvailable) {
    console.log(`  [r]     Retry extracting from running Chrome (port 9222)`);
  }

  const choice = await promptQuestion(`\nSelect [ENTER / c / r]: `);

  if (choice.toLowerCase() === 'c' || choice === '2') {
    const success = await handleCookiePaste(platform, sessionPath);
    if (!success) process.exit(1);
    return;
  }

  if (choice.toLowerCase() === 'r' && cdpAvailable) {
    const result = await extractSessionFromCdp(platform, 'http://127.0.0.1:9222');
    if (result.success && result.state) {
      saveSession(platform, result.state);
      const verification = verifySession(platform);
      console.log(`\n✅ Mode 1 Success: Extracted ${platform} session from running Chrome!`);
      console.log(`Active cookies: ${verification.cookieCount}`);
      console.log(`Saved to: ${sessionPath}\n`);
      return;
    } else {
      console.error(`\n❌ Failed: ${result.error}\n`);
      process.exit(1);
    }
  }

  // Default: Mode 3 (Real Google Chrome with persistent profile & stealth flags)
  const success = await handleBrowserLogin(platform, loginUrl, sessionPath);
  if (!success) process.exit(1);
}

if (require.main === module) {
  runLogin().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
