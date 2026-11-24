/**
 * TikTok photo slides (#333): the images of a TikTok photo post, made from a short's
 * plan. There is no voice; the script is the text on the slides.
 *
 *  1. Title slide: the first story image, the badge (ΕΙΔΗΣΗ n/N or the article's
 *     category) and the hook in large type.
 *  2. One slide per slide text from WordPress, over the story images in turn, with the
 *     text in a dark box and a "2/7" counter.
 *  3. Closing slide: the PressHub logo and the site line (siteEndCardLine()).
 *
 * Without a story image a slide gets the branded gradient. 1080x1920 JPEG files, which
 * TikTok takes for photo posts. Tests in tests/photo-slides.test.ts.
 */
import sharp from 'sharp';
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';
import { escapeXml, fetchImageBuffer } from './card-generator';
import { logoPath } from './short-video';
import { WorkerLogger } from '../logger';
import { trimFlatBorders } from './image-borders';

export const SLIDE_WIDTH = 1080;
export const SLIDE_HEIGHT = 1920;
/** TikTok's most photos in one post. */
export const TIKTOK_MAX_PHOTOS = 35;

/** Lowest baseline of the title text, clear of TikTok's caption and buttons. */
const TEXT_BOTTOM = 1380;

const FONT = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

export interface GeneratePhotoSlidesOptions {
  hook: string;
  badge?: string;
  /** Script texts, one per body slide. */
  slides: string[];
  imageUrls?: string[];
  siteName?: string;
  endCardLine: string;
  /** Most slides in all (title and closing included), at most 35. */
  maxSlides?: number;
  outputDir?: string;
  /** Tests inject a fake download. */
  fetchFn?: (url: string) => Promise<Buffer | null>;
}

export interface PhotoSlidesResult {
  files: string[];
  dir: string;
  /** Story images that could not be downloaded (their slides got the gradient). */
  missingImages: number;
}

/**
 * Wrap text into lines of at most maxChars characters, never cutting a word (a word longer
 * than a line gets a line of its own).
 */
export function wrapLines(text: string, maxChars: number): string[] {
  const words = (text || '').trim().split(/\s+/).filter(Boolean);
  const lines: string[] = [];
  let line = '';
  for (const word of words) {
    const next = line ? `${line} ${word}` : word;
    if (next.length <= maxChars || line === '') {
      line = next;
    } else {
      lines.push(line);
      line = word;
    }
  }
  if (line) lines.push(line);
  return lines;
}

/**
 * The largest font size (from max down to min) at which the text fits a box of the given
 * width and height, with its lines. Character width is estimated at 0.56 em, line height
 * at 1.3 em. At the smallest size the text is returned as it wraps, even if taller.
 */
export function fitText(text: string, boxWidth: number, boxHeight: number, maxSize: number, minSize: number): { size: number; lineHeight: number; lines: string[] } {
  for (let size = maxSize; size >= minSize; size -= 2) {
    const maxChars = Math.max(8, Math.floor(boxWidth / (size * 0.56)));
    const lines = wrapLines(text, maxChars);
    const lineHeight = Math.round(size * 1.3);
    if (lines.length * lineHeight <= boxHeight) {
      return { size, lineHeight, lines };
    }
  }
  const maxChars = Math.max(8, Math.floor(boxWidth / (minSize * 0.56)));
  return { size: minSize, lineHeight: Math.round(minSize * 1.3), lines: wrapLines(text, maxChars) };
}

function tspans(lines: string[], x: number, y0: number, lineHeight: number, anchor: 'start' | 'middle'): string {
  return lines
    .map((l, i) => `<tspan x="${x}" y="${y0 + i * lineHeight}" text-anchor="${anchor}">${escapeXml(l)}</tspan>`)
    .join('');
}

const GRADIENT = `<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#070c17"/><stop offset="55%" stop-color="#0f172a"/><stop offset="100%" stop-color="#03254c"/></linearGradient>`;

/** SVG overlay of the title slide (badge and hook over the lower half). */
export function titleSlideSvg(hook: string, badge: string, siteName: string, hasImage: boolean): string {
  const pad = 80;
  const fit = fitText(hook, SLIDE_WIDTH - pad * 2, 640, 96, 60);
  // TikTok lays the caption over the bottom quarter: the hook ends above it.
  const textTop = TEXT_BOTTOM - fit.lines.length * fit.lineHeight;
  const badgeW = Math.max(220, Math.round(badge.length * 26 + 70));
  const badgeY = textTop - fit.size - 110;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${SLIDE_WIDTH}" height="${SLIDE_HEIGHT}">
  <defs>${GRADIENT}<linearGradient id="shade" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#000" stop-opacity="0"/><stop offset="45%" stop-color="#000" stop-opacity="0.25"/><stop offset="100%" stop-color="#000" stop-opacity="0.9"/></linearGradient></defs>
  ${hasImage ? '' : '<rect width="100%" height="100%" fill="url(#bg)"/>'}
  <rect width="100%" height="100%" fill="url(#shade)"/>
  ${badge ? `<rect x="${pad}" y="${badgeY}" width="${badgeW}" height="72" rx="36" fill="#facc15"/><text x="${pad + badgeW / 2}" y="${badgeY + 49}" font-family="${FONT}" font-size="34" font-weight="800" fill="#0f172a" text-anchor="middle">${escapeXml(badge)}</text>` : ''}
  <text font-family="${FONT}" font-size="${fit.size}" font-weight="800" fill="#ffffff">${tspans(fit.lines, pad, textTop, fit.lineHeight, 'start')}</text>
  <text x="${pad}" y="${TEXT_BOTTOM + 90}" font-family="${FONT}" font-size="36" font-weight="700" fill="#38bdf8">${escapeXml(siteName)}</text>
</svg>`;
}

/** SVG overlay of a body slide: the text in a dark rounded box, centred, and the counter. */
export function textSlideSvg(text: string, counter: string, hasImage: boolean): string {
  const boxX = 70;
  const boxW = SLIDE_WIDTH - boxX * 2;
  const innerPad = 60;
  const fit = fitText(text, boxW - innerPad * 2, 1040, 72, 44);
  const boxH = fit.lines.length * fit.lineHeight + innerPad * 2;
  // Centred a little above the middle, away from TikTok's caption at the bottom.
  const boxY = Math.max(200, Math.round(860 - boxH / 2));
  const firstBaseline = boxY + innerPad + Math.round(fit.size * 0.95);
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${SLIDE_WIDTH}" height="${SLIDE_HEIGHT}">
  <defs>${GRADIENT}</defs>
  ${hasImage ? '<rect width="100%" height="100%" fill="#000" fill-opacity="0.35"/>' : '<rect width="100%" height="100%" fill="url(#bg)"/>'}
  <rect x="${boxX}" y="${boxY}" width="${boxW}" height="${boxH}" rx="36" fill="#0f172a" fill-opacity="0.82"/>
  <rect x="${boxX}" y="${boxY}" width="12" height="${boxH}" rx="6" fill="#facc15"/>
  <text font-family="${FONT}" font-size="${fit.size}" font-weight="700" fill="#ffffff">${tspans(fit.lines, SLIDE_WIDTH / 2, firstBaseline, fit.lineHeight, 'middle')}</text>
  ${counter ? `<text x="${SLIDE_WIDTH - 90}" y="150" font-family="${FONT}" font-size="38" font-weight="800" fill="#ffffff" text-anchor="end">${escapeXml(counter)}</text>` : ''}
</svg>`;
}

/** SVG of the closing slide (the logo is composited above the line when there is one). */
export function endSlideSvg(line: string, siteName: string, hasLogo: boolean): string {
  const fit = fitText(line, SLIDE_WIDTH - 200, 400, 72, 48);
  const y0 = SLIDE_HEIGHT / 2 + 120;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${SLIDE_WIDTH}" height="${SLIDE_HEIGHT}">
  <defs>${GRADIENT}</defs>
  <rect width="100%" height="100%" fill="url(#bg)"/>
  ${hasLogo ? '' : `<text x="${SLIDE_WIDTH / 2}" y="${SLIDE_HEIGHT / 2 - 60}" font-family="${FONT}" font-size="96" font-weight="800" fill="#ffffff" text-anchor="middle">${escapeXml(siteName)}</text>`}
  <text font-family="${FONT}" font-size="${fit.size}" font-weight="800" fill="#facc15">${tspans(fit.lines, SLIDE_WIDTH / 2, y0, fit.lineHeight, 'middle')}</text>
</svg>`;
}

async function background(image: Buffer | null): Promise<sharp.Sharp> {
  if (image) {
    try {
      // A letterboxed story image loses its flat strips first, so it fills the slide.
      const { buffer } = await trimFlatBorders(image);
      return sharp(await sharp(buffer).resize(SLIDE_WIDTH, SLIDE_HEIGHT, { fit: 'cover', position: 'attention' }).toBuffer());
    } catch (err: any) {
      WorkerLogger.warn('PhotoSlides', `A story image could not be read (${err?.message || err}); using the branded background.`);
    }
  }
  return sharp({ create: { width: SLIDE_WIDTH, height: SLIDE_HEIGHT, channels: 3, background: '#0f172a' } });
}

/** Render the slides of one photo post. */
export async function generatePhotoSlides(opts: GeneratePhotoSlidesOptions): Promise<PhotoSlidesResult> {
  const maxSlides = Math.max(3, Math.min(TIKTOK_MAX_PHOTOS, Math.round(opts.maxSlides || 10)));
  const bodies = (opts.slides || []).map((s) => String(s || '').trim()).filter(Boolean).slice(0, maxSlides - 2);
  if (bodies.length === 0) {
    throw new Error('Photo slides need at least one slide text.');
  }
  const siteName = opts.siteName || 'PressHub.Cy';
  const fetchFn = opts.fetchFn || ((u: string) => fetchImageBuffer(u, 20000));
  const urls = (opts.imageUrls || []).filter((u) => typeof u === 'string' && u !== '');
  const images: Array<Buffer | null> = [];
  for (const url of urls) {
    images.push(await fetchFn(url).catch(() => null));
  }
  const missingImages = images.filter((b) => !b).length;
  const usable = images.filter((b): b is Buffer => Boolean(b));

  const base = opts.outputDir || path.resolve(process.cwd(), 'output');
  const dir = path.join(base, 'photo-slides', `${Date.now()}-${crypto.randomBytes(3).toString('hex')}`);
  fs.mkdirSync(dir, { recursive: true });
  const total = bodies.length + 2;
  const files: string[] = [];
  const save = async (img: sharp.Sharp, n: number) => {
    const file = path.join(dir, `slide-${String(n).padStart(2, '0')}.jpg`);
    await img.jpeg({ quality: 88, mozjpeg: true }).toFile(file);
    files.push(file);
  };

  // 1. Title
  const titleImg = usable[0] || null;
  await save((await background(titleImg)).composite([{ input: Buffer.from(titleSlideSvg(opts.hook, (opts.badge || '').trim(), siteName, Boolean(titleImg))) }]), 1);

  // 2. Text slides over the images in turn (the title's image comes last in the cycle).
  for (let i = 0; i < bodies.length; i++) {
    const img = usable.length > 0 ? usable[(i + 1) % usable.length] : null;
    await save((await background(img)).composite([{ input: Buffer.from(textSlideSvg(bodies[i], `${i + 2}/${total}`, Boolean(img))) }]), i + 2);
  }

  // 3. Closing slide
  const logo = logoPath();
  const layers: sharp.OverlayOptions[] = [{ input: Buffer.from(endSlideSvg(opts.endCardLine, siteName, Boolean(logo))) }];
  if (logo) {
    try {
      const logoBuf = await sharp(logo).resize({ width: 640, withoutEnlargement: false }).png().toBuffer();
      const meta = await sharp(logoBuf).metadata();
      layers.push({ input: logoBuf, left: Math.round((SLIDE_WIDTH - (meta.width || 640)) / 2), top: Math.round(SLIDE_HEIGHT / 2 - 80 - (meta.height || 200)) });
    } catch (err: any) {
      WorkerLogger.warn('PhotoSlides', `The PressHub logo could not be drawn: ${err?.message || err}`);
    }
  }
  await save((await background(null)).composite(layers), total);

  return { files, dir, missingImages };
}

/** Remove a slide set once posted. */
export function removePhotoSlides(dir: string): void {
  try {
    fs.rmSync(dir, { recursive: true, force: true });
  } catch {}
}
