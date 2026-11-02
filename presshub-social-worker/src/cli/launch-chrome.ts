import { spawn } from 'child_process';
import path from 'path';
import fs from 'fs';
import { findChromeExecutable, isCdpAvailable, STEALTH_ARGS } from '../automation/browser-helper';
import { getSessionsDir } from '../automation/session-manager';

export async function launchRealChrome(targetUrl: string = 'https://x.com'): Promise<void> {
  const chromePath = findChromeExecutable();
  const profileDir = path.resolve(getSessionsDir(), 'chrome-profile');

  if (!fs.existsSync(profileDir)) {
    fs.mkdirSync(profileDir, { recursive: true });
  }

  console.log(`\n======================================================`);
  console.log(`🌐 PressHub — Real Google Chrome Launcher`);
  console.log(`======================================================`);
  console.log(`Profile Directory:    ${profileDir}`);
  console.log(`Remote Debug Port:    9222 (CDP)`);
  console.log(`Initial URL:          ${targetUrl}`);

  if (!chromePath) {
    console.error(`\n❌ Error: Google Chrome executable not found in standard system locations.`);
    console.log(`\nYou can start Chrome manually with remote debugging using this command:`);
    console.log(`  chrome.exe --remote-debugging-port=9222 --user-data-dir="${profileDir}"\n`);
    process.exit(1);
  }

  console.log(`Chrome Executable:    ${chromePath}`);
  console.log(`======================================================\n`);

  const alreadyRunning = await isCdpAvailable('http://127.0.0.1:9222', 1000);
  if (alreadyRunning) {
    console.log(`ℹ️ Google Chrome is already running with remote debugging on port 9222.`);
    console.log(`Ready for PressHub automation or session extraction!\n`);
    return;
  }

  const args = [
    '--remote-debugging-port=9222',
    `--user-data-dir=${profileDir}`,
    ...STEALTH_ARGS,
    targetUrl
  ];

  console.log(`Spawning Google Chrome process...`);
  try {
    const child = spawn(chromePath, args, {
      detached: true,
      stdio: 'ignore'
    });
    child.unref();

    console.log(`Waiting for Chrome DevTools Protocol (CDP) to initialize on port 9222...`);
    let connected = false;
    for (let i = 0; i < 10; i++) {
      await new Promise((resolve) => setTimeout(resolve, 500));
      if (await isCdpAvailable('http://127.0.0.1:9222', 500)) {
        connected = true;
        break;
      }
    }

    if (connected) {
      console.log(`\n✅ Google Chrome is running with CDP active at http://127.0.0.1:9222`);
      console.log(`👉 In the opened Chrome window:`);
      console.log(`   1. Log in to your accounts (e.g. X/Twitter, TikTok).`);
      console.log(`   2. Run 'npm run login -- <platform>' to instantly extract cookies into PressHub!`);
      console.log(`   3. You can keep this Chrome window open permanently for zero-bot-detection posting.\n`);
    } else {
      console.log(`\n⚠️ Chrome was launched, but port 9222 did not respond yet.`);
      console.log(`If Chrome was already open under another profile, close all Chrome instances`);
      console.log(`or verify that port 9222 is accessible.\n`);
    }
  } catch (err: any) {
    console.error(`\n❌ Failed to launch Chrome: ${err.message}\n`);
    process.exit(1);
  }
}

if (require.main === module) {
  const target = process.argv[2] || 'https://x.com';
  launchRealChrome(target).catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
