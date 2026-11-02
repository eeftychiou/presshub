import { chromium } from 'playwright';
import readline from 'readline';
import {
  Platform,
  sanitizePlatform,
  getSessionPath,
  verifySession,
  SUPPORTED_PLATFORMS
} from '../automation/session-manager';

const LOGIN_URLS: Record<Platform, string> = {
  twitter: 'https://x.com/i/flow/login',
  tiktok: 'https://www.tiktok.com/login',
  facebook: 'https://www.facebook.com/login',
  instagram: 'https://www.instagram.com/accounts/login/'
};

async function promptEnter(query: string): Promise<void> {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise<void>((resolve) => {
    rl.question(query, () => {
      rl.close();
      resolve();
    });
  });
}

export async function runLogin(platformArg?: string): Promise<void> {
  const target = platformArg || process.argv[2];

  if (!target) {
    console.error(`\n❌ Error: No platform specified.`);
    console.log(`Usage: npm run login -- <platform>`);
    console.log(`Supported platforms: ${SUPPORTED_PLATFORMS.join(', ')}\n`);
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

  console.log(`\n======================================================`);
  console.log(`🔐 PressHub Social Media Worker — Login Flow`);
  console.log(`Platform: ${platform.toUpperCase()}`);
  console.log(`Target URL: ${loginUrl}`);
  console.log(`Session Destination: ${sessionPath}`);
  console.log(`======================================================\n`);
  console.log(`Launching visible Chromium browser window...`);

  const browser = await chromium.launch({
    headless: false,
    channel: 'chrome' // Will fall back to bundled chromium if system chrome is absent
  }).catch(() => chromium.launch({ headless: false }));

  try {
    const context = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
      viewport: { width: 1280, height: 850 }
    });

    const page = await context.newPage();
    console.log(`Navigating to ${loginUrl}...`);
    await page.goto(loginUrl);

    console.log(`\n👉 Please complete your login and any 2FA/verification in the opened browser window.`);
    await promptEnter(`\nOnce you are successfully logged in on ${platform}, press [ENTER] here to save your session... `);

    console.log(`Saving browser cookies and storage state to: ${sessionPath}...`);
    await context.storageState({ path: sessionPath });

    const verification = verifySession(platform);
    if (verification.valid) {
      console.log(`\n✅ Session saved and verified successfully! Active cookies: ${verification.cookieCount}`);
      console.log(`You can now automate posts to ${platform} via PressHub AI.\n`);
    } else {
      console.warn(`\n⚠️ Warning: Saved session check returned invalid: ${verification.reason}`);
    }
  } catch (err: any) {
    console.error(`\n❌ Login session capture failed: ${err.message}\n`);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  runLogin().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
