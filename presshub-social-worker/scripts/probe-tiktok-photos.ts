/// <reference lib="dom" />
/**
 * Diagnostic: what TikTok Studio's upload page offers for a photo post, and where its
 * sound picker is. It uploads three plain test slides with the saved TikTok session,
 * looks for the sound controls, writes what it found to
 * output/probe-tiktok-photos/report.json with screenshots, and closes the page.
 *
 * It opens the Photos tab, and may choose a sound in the editor, but it NEVER clicks
 * Post: nothing is published. Run from the worker folder:
 *   npx ts-node scripts/probe-tiktok-photos.ts
 * Optional: a search phrase for the sound picker, e.g. `-- "news background"`.
 */
import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { connectOrLaunchChrome } from '../src/automation/browser-helper';
import { getSessionPath } from '../src/automation/session-manager';

const OUT = path.resolve(process.cwd(), 'output', 'probe-tiktok-photos');
const SOUND_RE = /sound|music|ήχο|ήχος|μουσικ/i;

async function makeSlides(): Promise<string[]> {
  fs.mkdirSync(OUT, { recursive: true });
  const files: string[] = [];
  for (let i = 1; i <= 3; i++) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1920"><rect width="100%" height="100%" fill="#14213d"/><text x="540" y="960" font-size="90" fill="#ffffff" text-anchor="middle" font-family="Arial">PressHub probe ${i}/3</text></svg>`;
    const file = path.join(OUT, `slide-${i}.jpg`);
    await sharp(Buffer.from(svg)).jpeg({ quality: 85 }).toFile(file);
    files.push(file);
  }
  return files;
}

async function snapshot(page: any, label: string): Promise<any> {
  await page.screenshot({ path: path.join(OUT, `${label}.png`), fullPage: true }).catch(() => {});
  return page.evaluate((reSrc: string) => {
    const re = new RegExp(reSrc, 'i');
    const visible = (el: Element) => {
      const r = (el as HTMLElement).getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    };
    const describe = (el: Element) => ({
      tag: el.tagName.toLowerCase(),
      text: ((el as HTMLElement).innerText || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 120),
      e2e: el.getAttribute('data-e2e') || undefined,
      aria: el.getAttribute('aria-label') || undefined,
      role: el.getAttribute('role') || undefined,
      cls: (el.getAttribute('class') || '').slice(0, 120) || undefined,
      placeholder: el.getAttribute('placeholder') || undefined
    });
    const fileInputs = Array.from(document.querySelectorAll('input[type="file"]')).map((el) => ({
      accept: el.getAttribute('accept'),
      multiple: el.hasAttribute('multiple'),
      e2e: el.getAttribute('data-e2e') || undefined
    }));
    const soundish = Array.from(document.querySelectorAll('button, [role="button"], a, div, span, input'))
      .filter((el) => visible(el))
      .filter((el) => re.test(((el as HTMLElement).innerText || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('data-e2e') || '') + ' ' + (el.getAttribute('placeholder') || '')))
      .filter((el) => ((el as HTMLElement).innerText || '').length < 200)
      .slice(0, 60)
      .map(describe);
    const buttons = Array.from(document.querySelectorAll('button, [role="button"]')).filter(visible).slice(0, 80).map(describe);
    const e2e = Array.from(new Set(Array.from(document.querySelectorAll('[data-e2e]')).map((el) => el.getAttribute('data-e2e')))).slice(0, 200);
    const dialogs = Array.from(document.querySelectorAll('[role="dialog"], .TUXModal, [class*="modal" i], [class*="drawer" i], [class*="panel" i]'))
      .filter(visible)
      .slice(0, 5)
      .map((el) => ({ ...describe(el), inputs: Array.from(el.querySelectorAll('input')).map(describe), items: Array.from(el.querySelectorAll('li, [class*="item" i]')).filter(visible).slice(0, 30).map(describe) }));
    return { url: location.href, lang: document.documentElement.lang, fileInputs, soundish, buttons, e2e, dialogs, body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 3000) };
  }, SOUND_RE.source);
}

async function main() {
  const query = process.argv.slice(2).join(' ').trim() || 'news';
  const report: any = { startedAt: new Date().toISOString(), query, steps: {} };
  const session = await connectOrLaunchChrome({ storageState: getSessionPath('tiktok') });
  const page = await session.context.newPage();
  page.setDefaultTimeout(30000);
  try {
    await page.goto('https://www.tiktok.com/creator-center/upload', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);
    report.steps.open = await snapshot(page, '1-open');

    // Photo posts have their own tab on the upload page.
    const photosTab = page.getByRole('tab', { name: /^(photos?|φωτογραφίες)$/i }).first();
    report.steps.photosTab = (await photosTab.count()) > 0 ? 'found' : 'not found';
    if ((await photosTab.count()) > 0) {
      await photosTab.click().catch((e: any) => { report.steps.photosTab = `click failed: ${e?.message || e}`; });
      await page.waitForTimeout(3000);
    }
    report.steps.photosOpen = await snapshot(page, '2-photos-tab');

    const slides = await makeSlides();
    const imageInput = page.locator('input[type="file"][accept*="image"]').first();
    report.steps.inputUsed = (await imageInput.count()) > 0 ? 'accept*=image' : 'none';
    if ((await imageInput.count()) > 0) {
      try {
        const multiple = await imageInput.evaluate((el: any) => el.multiple);
        await imageInput.setInputFiles(multiple ? slides : slides.slice(0, 1));
        report.steps.upload = multiple ? 'ok (3 files)' : 'ok (single-file input, 1 file)';
      } catch (e: any) {
        report.steps.upload = `failed: ${e?.message || e}`;
      }
    }
    await page.waitForTimeout(15000);
    const gotIt = page.getByRole('button', { name: /^got it$/i }).first();
    if ((await gotIt.count()) > 0) await gotIt.click().catch(() => {});
    report.steps.afterUpload = await snapshot(page, '3-after-upload');

    // The editor's "Add sound" picker, the same steps as pickTikTokSound() in the worker.
    const opener = page.locator('button, [role="button"]').filter({ hasText: /^(?:add sound|προσθήκη ήχου)$/i }).filter({ hasNotText: /royalty-free/i }).first();
    report.steps.addSoundButton = (await opener.count()) > 0 ? 'found' : 'not found';
    if ((await opener.count()) > 0) {
      await opener.click({ timeout: 5000 });
      const modal = page.locator('.TUXModal').filter({ has: page.locator('input[placeholder="Search sounds"], input[placeholder*="Search sound" i]') }).first();
      await modal.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
      report.steps.soundPicker = await snapshot(page, '4-sound-picker');
      const search = modal.locator('input[placeholder="Search sounds"], input[placeholder*="Search sound" i]').first();
      report.steps.searchBox = (await search.count()) > 0 ? 'found' : 'not found';
      if ((await search.count()) > 0) {
        const titles = async () => modal.locator('.MusicPanelMusicItem__infoBasicTitle').allInnerTexts().catch(() => [] as string[]);
        report.steps.titlesBefore = (await titles()).slice(0, 10);
        await search.click();
        await search.fill('');
        await search.pressSequentially(query, { delay: 40 });
        await search.press('Enter').catch(() => {});
        await page.waitForTimeout(6000);
        report.steps.titlesAfterSearch = (await titles()).slice(0, 15);
        report.steps.soundSearch = await snapshot(page, '5-sound-search');
        const rows = modal.locator('.MusicPanelMusicItem__wrap');
        const count = await rows.count();
        let pick = -1;
        for (let i = 0; i < Math.min(count, 20); i++) {
          const t = ((await rows.nth(i).locator('.MusicPanelMusicItem__infoBasicTitle').first().innerText().catch(() => '')) || '').trim();
          if (t && !/^(?:original sound|πρωτότυπος ήχος)/i.test(t)) { pick = i; report.steps.picked = t; break; }
        }
        if (pick >= 0) {
          await rows.nth(pick).hover().catch(() => {});
          await page.waitForTimeout(400);
          await rows.nth(pick).locator('.MusicPanelMusicItem__operation').first().click({ timeout: 5000 }).catch((e: any) => { report.steps.useClick = `failed: ${e?.message || e}`; });
          await page.waitForTimeout(3000);
          report.steps.modalOpenAfterUse = await modal.isVisible().catch(() => false);
          if (report.steps.modalOpenAfterUse) await page.keyboard.press('Escape').catch(() => {});
          await page.waitForTimeout(1000);
          report.steps.addSoundStillShown = await page.locator('button').filter({ hasText: /^add sound$/i }).first().isVisible().catch(() => false);
          report.steps.soundFieldText = ((await page.locator('[class*="formItem"]').filter({ hasText: /^Sound/ }).first().innerText().catch(() => '')) || '').replace(/\s+/g, ' ').slice(0, 200);
          report.steps.afterUse = await snapshot(page, '6-after-use');
        } else {
          report.steps.picked = 'no music track in the results';
        }
      }
    }
  } catch (e: any) {
    report.error = e?.message || String(e);
    await page.screenshot({ path: path.join(OUT, 'error.png'), fullPage: true }).catch(() => {});
  } finally {
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    // Close without posting; an external Chrome is left running.
    await page.close().catch(() => {});
    if (!session.isExternalCdp) await session.close().catch(() => {});
    console.log(`Report written to ${path.join(OUT, 'report.json')}`);
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
