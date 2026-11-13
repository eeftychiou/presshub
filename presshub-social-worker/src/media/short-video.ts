/**
 * TikTok short renderer: turns one prepared short (voice track, per-turn timings,
 * story images, hook) into a 9:16 video built for the feed:
 *
 * - The hook in very large type for the first seconds, while the voice starts.
 * - Captions that follow the voice, a few words at a time, with the word being spoken
 *   highlighted (yellow for the first speaker, blue for the second).
 * - The story images with a slow zoom or pan, changing every few seconds.
 * - A progress bar at the top, a story badge and topic, and an end card.
 *
 * Text sits inside TikTok's safe zone: nothing in the top 15% except the thin progress
 * bar, nothing in the bottom 25%, and wide side margins for the right-hand buttons.
 *
 * The PressHub logo (assets/brand/presshub-logo.png, the mic wordmark from
 * media/PressHub.cy Mic.png with its background removed) sits in the top-right corner
 * and large on the end card; without it the site name is written as text.
 *
 * Captions are burned in with libass (FFmpeg `subtitles` filter) using the bundled Inter
 * fonts (assets/fonts, SIL OFL 1.1), which cover Greek. When the FFmpeg build has no
 * libass the render fails loudly instead of posting a short without captions.
 */
import ffmpegInstaller from '@ffmpeg-installer/ffmpeg';
import { spawn, execFile } from 'child_process';
import util from 'util';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import sharp from 'sharp';
import { probeAudioDuration } from './video-generator';
import { WorkerLogger } from '../logger';

const execFileAsync = util.promisify(execFile);

/** One spoken turn of the short, in seconds from the start of the audio. */
export interface ShortSegment {
  speaker: number;
  text: string;
  start: number;
  end: number;
}

export interface TimedWord {
  text: string;
  start: number;
  end: number;
  speaker: number;
}

export interface CaptionChunk {
  words: TimedWord[];
  start: number;
  end: number;
  speaker: number;
}

export interface MotionSlot {
  image: number;
  motion: 'in' | 'out' | 'left' | 'right';
  duration: number;
}

export interface GenerateShortOptions {
  audioUrl?: string;
  audioPath?: string;
  imageUrls?: string[];
  imagePaths?: string[];
  hook: string;
  topic?: string;
  badge?: string;
  siteName?: string;
  segments?: ShortSegment[];
  width?: number;
  height?: number;
  fps?: number;
  outputDir?: string;
  /** Seconds the end card stays after the voice ends. */
  tailSeconds?: number;
}

export interface ShortVideoResult {
  filePath: string;
  width: number;
  height: number;
  duration: number;
  captions: number;
  images: number;
}

// Layout on the 1080x1920 ASS canvas (libass scales it to the output size).
export const CANVAS = { width: 1080, height: 1920 };
const LAYOUT = {
  badgeY: 300,
  topicY: 372,
  hookY: 860,
  captionY: 1150,
  sideMargin: 90,
};
const COLORS = {
  white: '&H00FFFFFF',
  black: '&H00000000',
  // PressHub yellow #FACC15 and sky blue #38BDF8, as ASS &HAABBGGRR.
  speaker1: '&H0015CCFA',
  speaker2: '&H00F8BD38',
  badgeText: '&H000F172A',
};

export const HOOK_SECONDS = 2.6;
export const END_CARD_SECONDS = 2.5;
export const SLOT_SECONDS = 4;
const MAX_WORDS_PER_CHUNK = 4;
const MAX_CHARS_PER_CHUNK = 24;

export const END_CARD_TEXT = {
  follow: 'Ακολούθησε για την επόμενη ενημέρωση',
  bio: 'Ολόκληρη η ενημέρωση: λινκ στο bio',
};

export function resolveFfmpegPath(): string {
  if (process.env.FFMPEG_PATH && process.env.FFMPEG_PATH.trim() !== '') {
    return process.env.FFMPEG_PATH.trim();
  }
  return ffmpegInstaller && ffmpegInstaller.path ? ffmpegInstaller.path : 'ffmpeg';
}

export function logoPath(): string | null {
  const file = path.resolve(__dirname, '..', '..', 'assets', 'brand', 'presshub-logo.png');
  return fs.existsSync(file) ? file : null;
}

// Logo placement on the 1080x1920 canvas.
export const LOGO = { cornerWidth: 230, cornerY: 262, endWidth: 640, endY: 520 };

export function fontsDir(): string {
  // src/media and dist/media are both two levels below the package root.
  return path.resolve(__dirname, '..', '..', 'assets', 'fonts');
}

let filterCheck: Promise<void> | null = null;

/**
 * Fails when the FFmpeg build cannot burn captions (no libass) or animate images.
 */
export function assertFfmpegCapabilities(ffmpegBin: string = resolveFfmpegPath()): Promise<void> {
  if (!filterCheck) {
    filterCheck = execFileAsync(ffmpegBin, ['-hide_banner', '-filters'], { maxBuffer: 8 * 1024 * 1024 })
      .then(({ stdout }) => {
        const missing = ['subtitles', 'zoompan', 'drawbox', 'overlay'].filter(
          (f) => !new RegExp(`\\s${f}\\s`).test(stdout)
        );
        if (missing.length > 0) {
          throw new Error(
            `FFmpeg at ${ffmpegBin} lacks the ${missing.join(', ')} filter(s) needed for TikTok shorts (captions need a build with libass). Set FFMPEG_PATH to a full FFmpeg build.`
          );
        }
      })
      .catch((err) => {
        filterCheck = null;
        throw err;
      });
  }
  return filterCheck;
}

/** Seconds as an ASS timestamp (H:MM:SS.cc). */
export function assTime(seconds: number): string {
  const cs = Math.max(0, Math.round(seconds * 100));
  const h = Math.floor(cs / 360000);
  const m = Math.floor((cs % 360000) / 6000);
  const s = Math.floor((cs % 6000) / 100);
  const c = cs % 100;
  return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(c).padStart(2, '0')}`;
}

/** Text safe for an ASS Dialogue line: no override braces, no line breaks. */
export function assEscape(text: string): string {
  return String(text)
    .replace(/\\/g, '⧵')
    .replace(/[{}]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Fits the turn timings to the real audio length. The WordPress side measures WAV turns
 * exactly; MP3 turns are estimates, so when the last turn's end and the audio length
 * disagree by more than 3% every time is scaled to match.
 */
export function fitSegments(segments: ShortSegment[], audioDuration: number): ShortSegment[] {
  const clean = (segments || [])
    .filter((s) => s && typeof s.text === 'string' && s.text.trim() !== '' && Number.isFinite(s.start) && Number.isFinite(s.end) && s.end > s.start)
    .map((s) => ({ speaker: Number(s.speaker) === 2 ? 2 : 1, text: s.text.trim(), start: Number(s.start), end: Number(s.end) }))
    .sort((a, b) => a.start - b.start);
  if (clean.length === 0 || !(audioDuration > 0)) {
    return clean;
  }
  const last = clean[clean.length - 1].end;
  const ratio = audioDuration / last;
  if (Math.abs(ratio - 1) <= 0.03) {
    return clean.map((s) => ({ ...s, end: Math.min(s.end, audioDuration) }));
  }
  return clean.map((s) => ({ ...s, start: s.start * ratio, end: Math.min(s.end * ratio, audioDuration) }));
}

/**
 * Spreads each turn's words over the turn by their length. TTS reports no word times,
 * but within one turn this is close enough for captions of a few words.
 */
export function timeWords(segments: ShortSegment[]): TimedWord[] {
  const words: TimedWord[] = [];
  for (const seg of segments) {
    const parts = seg.text.split(/\s+/).filter((w) => w !== '');
    if (parts.length === 0) continue;
    const lead = Math.min(0.08, (seg.end - seg.start) * 0.05);
    const trail = Math.min(0.15, (seg.end - seg.start) * 0.08);
    const start = seg.start + lead;
    const span = Math.max(0.1, seg.end - trail - start);
    const weights = parts.map((w) => w.length + 2 + (/[.,;:!?·»]$/.test(w) ? 3 : 0));
    const total = weights.reduce((a, b) => a + b, 0);
    let t = start;
    parts.forEach((w, i) => {
      const d = (span * weights[i]) / total;
      words.push({ text: w, start: t, end: t + d, speaker: seg.speaker });
      t += d;
    });
  }
  return words;
}

/**
 * Groups words into caption chunks of up to 4 words / 24 characters, breaking after
 * punctuation and never across a change of speaker.
 */
export function buildCaptionChunks(words: TimedWord[]): CaptionChunk[] {
  const chunks: CaptionChunk[] = [];
  let current: TimedWord[] = [];
  const flush = () => {
    if (current.length === 0) return;
    chunks.push({ words: current, start: current[0].start, end: current[current.length - 1].end, speaker: current[0].speaker });
    current = [];
  };
  for (const w of words) {
    if (current.length > 0) {
      const chars = current.reduce((n, x) => n + x.text.length + 1, 0) + w.text.length;
      const prev = current[current.length - 1];
      const gap = w.start - prev.end;
      if (
        w.speaker !== prev.speaker ||
        current.length >= MAX_WORDS_PER_CHUNK ||
        chars > MAX_CHARS_PER_CHUNK ||
        /[.;!?·]$/.test(prev.text) ||
        (/[,:»]$/.test(prev.text) && current.length >= 2) ||
        gap > 0.25
      ) {
        flush();
      }
    }
    current.push(w);
  }
  flush();

  // A lone word at the end of a sentence joins the chunk before it (up to two lines),
  // so a caption never flashes a single trailing word.
  const merged: CaptionChunk[] = [];
  for (const chunk of chunks) {
    const prev = merged[merged.length - 1];
    const prevLast = prev?.words[prev.words.length - 1];
    if (
      prev &&
      prevLast &&
      chunk.words.length === 1 &&
      chunk.speaker === prev.speaker &&
      !/[.;!?·]$/.test(prevLast.text) &&
      chunk.start - prev.end <= 0.25 &&
      prev.words.length < MAX_WORDS_PER_CHUNK + 1 &&
      prev.words.reduce((n, x) => n + x.text.length + 1, 0) + chunk.words[0].text.length <= MAX_CHARS_PER_CHUNK + 12
    ) {
      prev.words.push(chunk.words[0]);
      prev.end = chunk.end;
      continue;
    }
    merged.push(chunk);
  }
  return merged;
}

/** Time the hook stays on screen: about 2.6 s, never more than a third of the video. */
export function hookSeconds(totalDuration: number): number {
  return Math.max(1.2, Math.min(HOOK_SECONDS, totalDuration / 3));
}

/**
 * Image slots of about 4 seconds each, cycling the images and their motion so two
 * consecutive slots never move the same way.
 */
export function planSlots(totalDuration: number, imageCount: number): MotionSlot[] {
  const motions: MotionSlot['motion'][] = ['in', 'left', 'out', 'right'];
  const count = Math.max(1, Math.round(totalDuration / SLOT_SECONDS));
  const duration = totalDuration / count;
  const images = Math.max(1, imageCount);
  const slots: MotionSlot[] = [];
  for (let i = 0; i < count; i++) {
    slots.push({ image: i % images, motion: motions[i % motions.length], duration });
  }
  return slots;
}

/** zoompan expressions for one slot of `frames` frames on a 2x source image. */
export function zoompanFor(motion: MotionSlot['motion'], frames: number, width: number, height: number, fps: number): string {
  const d = Math.max(1, frames);
  const p = `on/${d}`;
  let z = '1.15';
  let x = 'iw/2-(iw/zoom/2)';
  const y = 'ih/2-(ih/zoom/2)';
  if (motion === 'in') z = `1+0.15*${p}`;
  if (motion === 'out') z = `1.15-0.15*${p}`;
  if (motion === 'left') x = `(iw-iw/zoom)*(1-${p})`;
  if (motion === 'right') x = `(iw-iw/zoom)*${p}`;
  return `zoompan=z='${z}':x='${x}':y='${y}':d=${d}:s=${width}x${height}:fps=${fps}`;
}

export interface AssOptions {
  hook: string;
  topic?: string;
  badge?: string;
  siteName: string;
  chunks: CaptionChunk[];
  totalDuration: number;
  /** The logo image is overlaid by FFmpeg, so the site name is not written as text. */
  hasLogo?: boolean;
}

/**
 * Builds the ASS script: badge, topic, hook, word-highlighted captions and end card.
 */
export function buildAssScript(opts: AssOptions): string {
  const total = opts.totalDuration;
  const hookEnd = hookSeconds(total);
  const endCardStart = Math.max(hookEnd, total - END_CARD_SECONDS);
  const m = LAYOUT.sideMargin;
  const cx = CANVAS.width / 2;

  const header = [
    '[Script Info]',
    'ScriptType: v4.00+',
    `PlayResX: ${CANVAS.width}`,
    `PlayResY: ${CANVAS.height}`,
    'WrapStyle: 0',
    'ScaledBorderAndShadow: yes',
    'YCbCr Matrix: TV.709',
    '',
    '[V4+ Styles]',
    'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
    `Style: Caption,Inter ExtraBold,84,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,7,3,5,${m},${m},0,1`,
    `Style: Hook,Inter Black,104,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,8,4,5,${m - 20},${m - 20},0,1`,
    `Style: Badge,Inter Black,40,${COLORS.badgeText},${COLORS.badgeText},${COLORS.speaker1},&H00000000,0,0,0,0,100,100,1,0,3,14,0,7,${m},${m},0,1`,
    `Style: Topic,Inter ExtraBold,46,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,4,2,7,${m},${m + 60},0,1`,
    `Style: Brand,Inter ExtraBold,38,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,3,2,9,${m},${m},0,1`,
    `Style: EndTitle,Inter Black,120,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,6,3,5,${m},${m},0,1`,
    `Style: EndLine,Inter ExtraBold,58,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,4,2,5,${m},${m},0,1`,
    `Style: EndBio,Inter ExtraBold,46,${COLORS.speaker1},${COLORS.speaker1},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,4,2,5,${m},${m},0,1`,
    '',
    '[Events]',
    'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
  ];

  const lines: string[] = [];
  const dialogue = (layer: number, start: number, end: number, style: string, text: string) => {
    if (end - start < 0.01) return;
    lines.push(`Dialogue: ${layer},${assTime(start)},${assTime(end)},${style},,0,0,0,,${text}`);
  };

  // Badge, topic and brand stay until the end card.
  if (opts.badge && opts.badge.trim() !== '') {
    dialogue(1, 0, endCardStart, 'Badge', `{\\pos(${m},${LAYOUT.badgeY})}${assEscape(opts.badge)}`);
  }
  if (!opts.hasLogo) {
    dialogue(1, 0, endCardStart, 'Brand', `{\\pos(${CANVAS.width - m},${LAYOUT.badgeY - 4})}${assEscape(opts.siteName)}`);
  }
  if (opts.topic && opts.topic.trim() !== '') {
    dialogue(1, 0, endCardStart, 'Topic', `{\\pos(${m},${LAYOUT.topicY})}${assEscape(opts.topic)}`);
  }

  // Hook: pops in at once and fades out as the captions take over.
  dialogue(
    2,
    0,
    hookEnd,
    'Hook',
    `{\\pos(${cx},${LAYOUT.hookY})\\fscx80\\fscy80\\t(0,160,\\fscx104\\fscy104)\\t(160,260,\\fscx100\\fscy100)\\fad(0,180)}${assEscape(opts.hook)}`
  );

  // Captions: one event per word, the spoken word highlighted in the speaker's colour.
  opts.chunks.forEach((chunk, ci) => {
    const next = opts.chunks[ci + 1];
    const chunkEnd = Math.min(next ? next.start : chunk.end + 0.4, chunk.end + 0.4, endCardStart);
    const highlight = chunk.speaker === 2 ? COLORS.speaker2 : COLORS.speaker1;
    chunk.words.forEach((word, wi) => {
      const start = Math.max(wi === 0 ? chunk.start : word.start, hookEnd);
      const end = Math.min(wi === chunk.words.length - 1 ? chunkEnd : chunk.words[wi + 1].start, endCardStart);
      if (end <= start) return;
      const pop = wi === 0 || start === hookEnd ? '\\fscx90\\fscy90\\t(0,90,\\fscx100\\fscy100)' : '';
      const text = chunk.words
        .map((w, k) => (k === wi ? `{\\c${highlight}&}${assEscape(w.text)}{\\c${COLORS.white}&}` : assEscape(w.text)))
        .join(' ');
      dialogue(3, start, end, 'Caption', `{\\pos(${cx},${LAYOUT.captionY})${pop}}${text}`);
    });
  });

  // End card over the dimmed picture.
  if (!opts.hasLogo) {
    dialogue(4, endCardStart, total, 'EndTitle', `{\\pos(${cx},820)\\fad(200,0)\\fscx85\\fscy85\\t(0,200,\\fscx100\\fscy100)}${assEscape(opts.siteName)}`);
  }
  dialogue(4, endCardStart, total, 'EndLine', `{\\pos(${cx},970)\\fad(250,0)}${assEscape(END_CARD_TEXT.follow)}`);
  dialogue(4, endCardStart, total, 'EndBio', `{\\pos(${cx},1070)\\fad(300,0)}${assEscape(END_CARD_TEXT.bio)}`);

  return [...header, ...lines, ''].join('\n');
}

async function download(url: string, dest: string): Promise<boolean> {
  try {
    const res = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 PressHub-ShortVideo/1.0' } });
    if (!res.ok) {
      WorkerLogger.warn('ShortVideo', `Download failed for ${url}: HTTP ${res.status}`);
      return false;
    }
    fs.writeFileSync(dest, Buffer.from(await res.arrayBuffer()));
    return true;
  } catch (err: any) {
    WorkerLogger.warn('ShortVideo', `Download failed for ${url}: ${err.message}`);
    return false;
  }
}

/** Crops a story image to a 2x 9:16 frame, so the zoom stays sharp and smooth. */
async function prepareImage(src: string, dest: string, width: number, height: number): Promise<boolean> {
  try {
    await sharp(src)
      .resize(width * 2, height * 2, { fit: 'cover', position: sharp.strategy.attention })
      .modulate({ saturation: 1.08 })
      .jpeg({ quality: 90 })
      .toFile(dest);
    return true;
  } catch (err: any) {
    WorkerLogger.warn('ShortVideo', `Could not use image ${src}: ${err.message}`);
    return false;
  }
}

/** Branded backgrounds used when a short has no story image. */
async function brandedBackgrounds(dir: string, width: number, height: number): Promise<string[]> {
  const variants = [
    ['#0f172a', '#1e3a8a', '#facc15'],
    ['#111827', '#0e7490', '#38bdf8'],
  ];
  const out: string[] = [];
  for (let i = 0; i < variants.length; i++) {
    const [a, b, accent] = variants[i];
    const w = width * 2;
    const h = height * 2;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="${i ? 1 : 0}" y2="1">
      <stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/>
    </linearGradient>
    <radialGradient id="r" cx="${i ? 0.25 : 0.75}" cy="0.3" r="0.6">
      <stop offset="0" stop-color="${accent}" stop-opacity="0.35"/><stop offset="1" stop-color="${accent}" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#g)"/>
  <rect width="100%" height="100%" fill="url(#r)"/>
  ${Array.from({ length: 9 }, (_, k) => `<circle cx="${(k * 0.17 + (i ? 0.1 : 0)) % 1 * w}" cy="${((k * 0.29) % 1) * h}" r="${w * (0.08 + (k % 3) * 0.05)}" fill="none" stroke="${accent}" stroke-opacity="0.12" stroke-width="${w * 0.006}"/>`).join('\n  ')}
</svg>`;
    const file = path.join(dir, `bg-${i}.jpg`);
    const composites: sharp.OverlayOptions[] = [];
    const logo = logoPath();
    if (logo) {
      // A faint, large PressHub logo as a watermark behind the text.
      const mark = await sharp(logo)
        .resize(Math.round(w * 0.8))
        .ensureAlpha()
        .composite([{ input: Buffer.from([255, 255, 255, 36]), raw: { width: 1, height: 1, channels: 4 }, tile: true, blend: 'dest-in' }])
        .png()
        .toBuffer();
      const meta = await sharp(mark).metadata();
      composites.push({ input: mark, left: Math.round((w - (meta.width || 0)) / 2), top: Math.round(h * (i ? 0.62 : 0.46)) });
    }
    await sharp(Buffer.from(svg)).composite(composites).jpeg({ quality: 90 }).toFile(file);
    out.push(file);
  }
  return out;
}

/** Darkens the top and bottom of the frame so white text stays readable on any image. */
async function shadeOverlay(dir: string, width: number, height: number): Promise<string> {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
  <defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#000" stop-opacity="0.62"/>
    <stop offset="0.28" stop-color="#000" stop-opacity="0.12"/>
    <stop offset="0.42" stop-color="#000" stop-opacity="0.18"/>
    <stop offset="0.62" stop-color="#000" stop-opacity="0.45"/>
    <stop offset="1" stop-color="#000" stop-opacity="0.7"/>
  </linearGradient></defs>
  <rect width="100%" height="100%" fill="url(#s)"/>
</svg>`;
  const file = path.join(dir, 'shade.png');
  await sharp(Buffer.from(svg)).png().toFile(file);
  return file;
}

function runFfmpeg(bin: string, args: string[], cwd: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const proc = spawn(bin, args, { cwd });
    let tail = '';
    proc.stderr.on('data', (d) => {
      tail = (tail + d.toString()).slice(-4000);
    });
    proc.on('error', (err) => reject(err));
    proc.on('close', (code) => {
      if (code === 0) resolve();
      else reject(new Error(`FFmpeg exited with code ${code}: ${tail.split('\n').slice(-6).join(' | ')}`));
    });
  });
}

/**
 * Builds the FFmpeg arguments. Paths are relative to the work directory (the process
 * cwd), so the subtitles filter needs no Windows drive-letter escaping.
 */
export function buildFfmpegArgs(p: {
  slots: MotionSlot[];
  images: string[];
  audio: string;
  shade: string;
  ass: string;
  fonts: string;
  output: string;
  width: number;
  height: number;
  fps: number;
  total: number;
  /** PressHub logo (transparent PNG): top-right corner, then large on the end card. */
  logo?: string;
}): string[] {
  const args: string[] = ['-hide_banner', '-y'];
  p.slots.forEach((slot) => {
    args.push('-i', p.images[slot.image]);
  });
  const audioIdx = p.slots.length;
  const shadeIdx = audioIdx + 1;
  const barIdx = audioIdx + 2;
  args.push('-i', p.audio);
  args.push('-loop', '1', '-i', p.shade);
  args.push('-f', 'lavfi', '-i', `color=c=0xFACC15:s=${p.width}x10:r=${p.fps}`);
  const logoIdx = barIdx + 1;
  if (p.logo) {
    args.push('-loop', '1', '-framerate', String(p.fps), '-i', p.logo);
  }

  const parts: string[] = [];
  let frameBudget = Math.round(p.total * p.fps);
  p.slots.forEach((slot, i) => {
    const frames = i === p.slots.length - 1 ? Math.max(1, frameBudget) : Math.max(1, Math.round(slot.duration * p.fps));
    frameBudget -= frames;
    parts.push(`[${i}:v]${zoompanFor(slot.motion, frames, p.width, p.height, p.fps)},setsar=1,format=yuv420p[s${i}]`);
  });
  parts.push(`${p.slots.map((_, i) => `[s${i}]`).join('')}concat=n=${p.slots.length}:v=1:a=0[bg]`);
  parts.push(`[bg][${shadeIdx}:v]overlay=0:0:shortest=1[shaded]`);
  parts.push(`[shaded][${barIdx}:v]overlay=x='-w+w*t/${p.total.toFixed(3)}':y=0:shortest=1[bar]`);
  const endStart = Math.max(0, p.total - END_CARD_SECONDS).toFixed(3);
  const k = p.width / CANVAS.width;
  if (p.logo) {
    const small = Math.round(LOGO.cornerWidth * k);
    const big = Math.round(LOGO.endWidth * k);
    parts.push(`[${logoIdx}:v]format=rgba,split=2[lg1][lg2]`);
    parts.push(`[lg1]scale=${small}:-1[lgs]`);
    parts.push(`[lg2]scale=${big}:-1,fade=t=in:st=${endStart}:d=0.3:alpha=1[lgb]`);
    parts.push(`[bar][lgs]overlay=x=W-w-${Math.round(LAYOUT.sideMargin * k)}:y=${Math.round(LOGO.cornerY * k)}:shortest=1:enable='lt(t,${endStart})'[branded]`);
    parts.push(`[branded]drawbox=x=0:y=0:w=iw:h=ih:color=black@0.55:t=fill:enable='gte(t,${endStart})'[dimmed]`);
    parts.push(`[dimmed][lgb]overlay=x=(W-w)/2:y=${Math.round(LOGO.endY * k)}:shortest=1:enable='gte(t,${endStart})'[dim]`);
  } else {
    parts.push(`[bar]drawbox=x=0:y=0:w=iw:h=ih:color=black@0.55:t=fill:enable='gte(t,${endStart})'[dim]`);
  }
  parts.push(`[dim]subtitles=${p.ass}:fontsdir=${p.fonts},format=yuv420p[v]`);
  parts.push(`[${audioIdx}:a]apad[a]`);

  args.push(
    '-filter_complex', parts.join(';'),
    '-map', '[v]', '-map', '[a]',
    '-t', p.total.toFixed(3),
    '-r', String(p.fps),
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
    '-c:a', 'aac', '-b:a', '192k', '-ar', '44100',
    '-movflags', '+faststart',
    p.output
  );
  return args;
}

/**
 * Renders a TikTok short. Throws on a missing voice track or an FFmpeg failure.
 */
export async function generateShortVideo(options: GenerateShortOptions): Promise<ShortVideoResult> {
  const width = CANVAS.width;
  const height = CANVAS.height;
  const fps = options.fps && options.fps > 0 ? Math.round(options.fps) : 30;
  const outputDir = options.outputDir || path.resolve(process.cwd(), 'output');
  const ffmpegBin = resolveFfmpegPath();
  await assertFfmpegCapabilities(ffmpegBin);

  const work = path.join(outputDir, `short-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`);
  fs.mkdirSync(path.join(work, 'fonts'), { recursive: true });
  try {
    for (const f of fs.readdirSync(fontsDir())) {
      if (/\.(otf|ttf)$/i.test(f)) fs.copyFileSync(path.join(fontsDir(), f), path.join(work, 'fonts', f));
    }

    // Voice track.
    // FFmpeg runs inside the work directory, so a local track is copied in.
    let audio = '';
    if (options.audioPath && fs.existsSync(options.audioPath)) {
      audio = path.join(work, `voice${path.extname(options.audioPath) || '.wav'}`);
      fs.copyFileSync(options.audioPath, audio);
    }
    if (!audio && options.audioUrl) {
      const ext = (options.audioUrl.match(/\.(wav|mp3|m4a|aac|ogg)(\?|$)/i)?.[1] || 'wav').toLowerCase();
      const dest = path.join(work, `voice.${ext}`);
      if (await download(options.audioUrl, dest)) audio = dest;
    }
    if (!audio) {
      throw new Error('TikTok short has no voice track to render.');
    }
    const audioDuration = await probeAudioDuration(audio);
    if (!audioDuration || audioDuration <= 0) {
      throw new Error(`Could not read the length of the short's voice track (${audio}).`);
    }
    const total = Math.round((audioDuration + (options.tailSeconds ?? 1.0)) * 1000) / 1000;

    // Story images, or branded backgrounds when there are none.
    const images: string[] = [];
    const sources = [...(options.imagePaths || [])];
    for (const [i, url] of (options.imageUrls || []).entries()) {
      const dest = path.join(work, `src-${i}`);
      if (await download(url, dest)) sources.push(dest);
    }
    for (const [i, src] of sources.entries()) {
      const dest = path.join(work, `img-${i}.jpg`);
      if (await prepareImage(src, dest, width, height)) images.push(dest);
    }
    const storyImages = images.length;
    if (storyImages === 0) {
      WorkerLogger.warn('ShortVideo', 'TikTok short has no usable story image; rendering it on the branded background.');
      images.push(...(await brandedBackgrounds(work, width, height)));
    }

    // PressHub logo, copied into the work directory like every other input.
    const logoSrc = logoPath();
    let logo = '';
    if (logoSrc) {
      logo = path.join(work, 'logo.png');
      fs.copyFileSync(logoSrc, logo);
    } else {
      WorkerLogger.warn('ShortVideo', 'PressHub logo (assets/brand/presshub-logo.png) is missing; writing the site name as text.');
    }

    // Captions.
    const segments = fitSegments(options.segments || [], audioDuration);
    if (segments.length === 0) {
      WorkerLogger.warn('ShortVideo', 'TikTok short has no turn timings; rendering it without captions.');
    }
    const chunks = buildCaptionChunks(timeWords(segments));
    const ass = buildAssScript({
      hook: options.hook,
      topic: options.topic,
      badge: options.badge,
      siteName: options.siteName || 'PressHub.Cy',
      chunks,
      totalDuration: total,
      hasLogo: Boolean(logo),
    });
    fs.writeFileSync(path.join(work, 'captions.ass'), ass, 'utf8');
    const shade = await shadeOverlay(work, width, height);

    const slots = planSlots(total, images.length);
    const outName = `short-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.mp4`;
    const args = buildFfmpegArgs({
      slots,
      images: images.map((f) => path.basename(f)),
      audio: path.basename(audio),
      shade: path.basename(shade),
      ass: 'captions.ass',
      fonts: 'fonts',
      output: outName,
      width,
      height,
      fps,
      total,
      logo: logo ? path.basename(logo) : undefined,
    });

    WorkerLogger.info('ShortVideo', `Rendering short (${total.toFixed(1)}s, ${storyImages} story image(s), ${chunks.length} caption chunks, ${slots.length} shots)`);
    const started = Date.now();
    await runFfmpeg(ffmpegBin, args, work);
    const finalPath = path.join(outputDir, outName);
    fs.renameSync(path.join(work, outName), finalPath);
    WorkerLogger.info('ShortVideo', `Short rendered in ${((Date.now() - started) / 1000).toFixed(1)}s: ${finalPath}`);

    return { filePath: finalPath, width, height, duration: total, captions: chunks.length, images: storyImages };
  } finally {
    fs.rmSync(work, { recursive: true, force: true });
  }
}
