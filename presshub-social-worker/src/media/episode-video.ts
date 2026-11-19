/**
 * YouTube podcast episode renderer: the whole Daily Briefing audio as a 16:9 video, with
 * the story images of the post's shorts and captions in the same style as the shorts.
 *
 * - One scene per topic of the episode (WordPress matches each topic to the short about
 *   it). A scene cycles its images with a slow zoom or pan, a few seconds each.
 * - Captions that follow the voice a few words at a time, the spoken word highlighted in
 *   the speaker's colour (yellow for the first host, blue for the second), as in the
 *   shorts.
 * - The episode title for the first seconds, each later topic's title as it starts, and
 *   the current topic in a yellow label at the top left.
 * - The PressHub logo in the top-right corner, and the site's address at the end.
 *
 * The story images are 9:16. Each is set, whole, on a blurred and darkened copy of
 * itself filling the 16:9 frame; a landscape image (the featured image) fills the frame.
 *
 * Timings come from the timeline WordPress stores when it stitches the episode: measured
 * for WAV, estimated for MP3. Either way they are scaled to the real audio length here.
 * Without a timeline the video has no captions; without images it uses the branded
 * backgrounds.
 */
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import sharp from 'sharp';
import { probeAudioDuration } from './video-generator';
import { WorkerLogger } from '../logger';
import {
  ShortSegment,
  CaptionChunk,
  MotionSlot,
  COLORS,
  assTime,
  assEscape,
  timeWords,
  buildCaptionChunks,
  zoompanFor,
  audioGraph,
  resolveFfmpegPath,
  assertFfmpegCapabilities,
  logoPath,
  fontsDir,
  download,
  brandedBackgrounds,
  runFfmpeg,
} from './short-video';

export interface EpisodeScene {
  start: number;
  end: number;
  title?: string;
  images?: string[];
}

export interface GenerateEpisodeOptions {
  audioUrl?: string;
  audioPath?: string;
  /** Episode title, shown over the first seconds. */
  title: string;
  siteName?: string;
  /** Spoken turns, in seconds of the timeline. */
  segments?: ShortSegment[];
  /** One per topic, in seconds of the timeline. */
  scenes?: EpisodeScene[];
  /** Every story image, for an episode without scenes. */
  images?: string[];
  /** Local images, for tests. */
  imagePaths?: string[];
  /** Image used when there is no story image (the post's featured image). */
  fallbackImageUrl?: string;
  /** Length of the timeline, to fit it to the real audio. */
  timelineDuration?: number;
  /** Line shown at the end, e.g. "Ολόκληρη η ενημέρωση στο presshub.cy". */
  endCardLine?: string;
  fps?: number;
  outputDir?: string;
}

export interface EpisodeVideoResult {
  filePath: string;
  width: number;
  height: number;
  duration: number;
  captions: number;
  images: number;
  scenes: number;
}

export const EPISODE_CANVAS = { width: 1920, height: 1080 };
/** Source images are prepared at 1.5x, so the slow zoom stays sharp. */
const SOURCE_SCALE = 1.5;
export const EPISODE_SLOT_SECONDS = 6;
/**
 * Most image shots in one video. Every shot is a chain in the FFmpeg filter graph, and
 * the whole command line must stay under Windows' 32,767 characters, so a long episode
 * holds each image a little longer instead.
 */
export const EPISODE_MAX_SLOTS = 120;
export const OPENING_SECONDS = 4.5;
export const TOPIC_CARD_SECONDS = 3.5;
export const EPISODE_END_SECONDS = 4;
const EPISODE_MAX_IMAGES = 40;

const LAYOUT = {
  margin: 64,
  labelY: 56,
  captionY: 935,
  cardY: 470,
  logoWidth: 250,
  logoY: 44,
};

interface PreparedSlot extends MotionSlot {
  /** Index in the list of unique images. */
  image: number;
}

/**
 * Scales the timeline to the real audio length (the WAV timeline is exact, so the ratio
 * is about 1; an MP3 one is estimated) and drops turns and scenes that are not usable.
 * Scenes cover the whole audio: the first starts at 0 and the last ends at the end.
 */
export function fitTimeline(
  segments: ShortSegment[] | undefined,
  scenes: EpisodeScene[] | undefined,
  timelineDuration: number | undefined,
  audioDuration: number
): { segments: ShortSegment[]; scenes: EpisodeScene[] } {
  const lastEnd = Math.max(0, ...(segments || []).map((s) => Number(s?.end) || 0));
  const base = Number(timelineDuration) > 0 ? Number(timelineDuration) : lastEnd;
  const ratio = base > 0 && audioDuration > 0 ? audioDuration / base : 1;
  const clamp = (t: number) => Math.max(0, Math.min(audioDuration, t * ratio));

  const fitted = (segments || [])
    .filter((s) => s && typeof s.text === 'string' && s.text.trim() !== '' && Number.isFinite(Number(s.start)) && Number.isFinite(Number(s.end)) && Number(s.end) > Number(s.start))
    .map((s) => ({ speaker: Number(s.speaker) === 2 ? 2 : 1, text: s.text.trim(), start: clamp(Number(s.start)), end: clamp(Number(s.end)) }))
    .filter((s) => s.end > s.start)
    .sort((a, b) => a.start - b.start);

  const cleanScenes = (scenes || [])
    .filter((s) => s && Number.isFinite(Number(s.start)))
    .map((s) => ({ start: clamp(Number(s.start)), end: clamp(Number(s.end)), title: String(s.title || '').trim(), images: Array.isArray(s.images) ? s.images.filter((u) => typeof u === 'string' && u !== '') : [] }))
    .sort((a, b) => a.start - b.start);
  const out: EpisodeScene[] = [];
  cleanScenes.forEach((s, i) => {
    const start = i === 0 ? 0 : s.start;
    const end = i + 1 < cleanScenes.length ? cleanScenes[i + 1].start : audioDuration;
    if (end - start > 0.5 || out.length === 0) {
      out.push({ ...s, start, end: Math.max(end, start) });
    } else if (out.length > 0) {
      // A scene too short to show joins the one before it.
      out[out.length - 1].end = end;
    }
  });
  return { segments: fitted, scenes: out };
}

/**
 * Image shots of the video: each scene cycles its own images (by their index in the
 * list of unique images), about `slotSeconds` each, and the motion changes every shot.
 * Scenes without images use every image.
 */
export function planEpisodeSlots(scenes: EpisodeScene[], total: number, imageIndex: (url: string) => number, imageCount: number): PreparedSlot[] {
  const motions: MotionSlot['motion'][] = ['in', 'left', 'out', 'right'];
  const all = Array.from({ length: Math.max(1, imageCount) }, (_, i) => i);
  const list = scenes.length > 0 ? scenes : [{ start: 0, end: total, images: [] as string[] }];
  const slotSeconds = Math.max(EPISODE_SLOT_SECONDS, total / EPISODE_MAX_SLOTS);
  const slots: PreparedSlot[] = [];
  list.forEach((scene, si) => {
    const end = si === list.length - 1 ? total : Math.min(total, scene.end);
    const duration = end - scene.start;
    if (duration <= 0) return;
    const own = (scene.images || []).map(imageIndex).filter((i) => i >= 0);
    const pool = own.length > 0 ? own : all;
    const count = Math.max(1, Math.round(duration / slotSeconds));
    for (let k = 0; k < count; k++) {
      slots.push({ image: pool[k % pool.length], motion: motions[slots.length % motions.length], duration: duration / count });
    }
  });
  return slots;
}

export interface EpisodeAssOptions {
  title: string;
  siteName: string;
  chunks: CaptionChunk[];
  scenes: EpisodeScene[];
  totalDuration: number;
  hasLogo?: boolean;
  endCardLine?: string;
}

/** ASS script of the episode: opening title, topic label and cards, captions, end line. */
export function buildEpisodeAssScript(opts: EpisodeAssOptions): string {
  const W = EPISODE_CANVAS.width;
  const H = EPISODE_CANVAS.height;
  const m = LAYOUT.margin;
  const cx = W / 2;
  const total = opts.totalDuration;
  const lastSpeech = opts.chunks.length > 0 ? opts.chunks[opts.chunks.length - 1].end : 0;
  const endStart = Math.max(lastSpeech + 0.3, total - EPISODE_END_SECONDS);

  const header = [
    '[Script Info]',
    'ScriptType: v4.00+',
    `PlayResX: ${W}`,
    `PlayResY: ${H}`,
    'WrapStyle: 0',
    'ScaledBorderAndShadow: yes',
    'YCbCr Matrix: TV.709',
    '',
    '[V4+ Styles]',
    'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
    `Style: Caption,Inter ExtraBold,70,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,6,3,5,${m * 3},${m * 3},0,1`,
    `Style: Card,Inter Black,84,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,7,4,5,${m * 2},${m * 2},0,1`,
    `Style: Label,Inter Black,34,${COLORS.badgeText},${COLORS.badgeText},${COLORS.speaker1},&H00000000,0,0,0,0,100,100,1,0,3,12,0,7,${m},${m},0,1`,
    `Style: Brand,Inter ExtraBold,36,${COLORS.white},${COLORS.white},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,3,2,9,${m},${m},0,1`,
    `Style: EndLine,Inter ExtraBold,56,${COLORS.speaker1},${COLORS.speaker1},${COLORS.black},&H96000000,0,0,0,0,100,100,0,0,1,5,2,5,${m},${m},0,1`,
    '',
    '[Events]',
    'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
  ];

  const lines: string[] = [];
  const dialogue = (layer: number, start: number, end: number, style: string, text: string) => {
    const e = Math.min(end, total);
    if (e - start < 0.01) return;
    lines.push(`Dialogue: ${layer},${assTime(start)},${assTime(e)},${style},,0,0,0,,${text}`);
  };

  if (!opts.hasLogo) {
    dialogue(1, 0, total, 'Brand', `{\\pos(${W - m},${LAYOUT.labelY})}${assEscape(opts.siteName)}`);
  }

  // Opening: the episode title.
  const openingEnd = Math.min(OPENING_SECONDS, total / 3);
  if (opts.title.trim() !== '') {
    dialogue(
      2,
      0,
      openingEnd,
      'Card',
      `{\\pos(${cx},${LAYOUT.cardY})\\fscx85\\fscy85\\t(0,200,\\fscx100\\fscy100)\\fad(150,300)}${assEscape(opts.title)}`
    );
  }

  // The current topic at the top left; each later topic's title as it starts.
  opts.scenes.forEach((scene, i) => {
    const title = String(scene.title || '').trim();
    if (title === '') return;
    dialogue(1, Math.max(scene.start, i === 0 ? openingEnd : scene.start), Math.min(scene.end, endStart), 'Label', `{\\pos(${m},${LAYOUT.labelY})\\fad(250,150)}${assEscape(title)}`);
    if (i > 0) {
      dialogue(
        2,
        scene.start,
        Math.min(scene.start + TOPIC_CARD_SECONDS, scene.end),
        'Card',
        `{\\pos(${cx},${LAYOUT.cardY})\\fscx90\\fscy90\\t(0,180,\\fscx100\\fscy100)\\fad(150,300)}${assEscape(title)}`
      );
    }
  });

  // Captions, one event per word with the spoken word highlighted (as in the shorts).
  opts.chunks.forEach((chunk, ci) => {
    const next = opts.chunks[ci + 1];
    const chunkEnd = Math.min(next ? next.start : chunk.end + 0.4, chunk.end + 0.4);
    const highlight = chunk.speaker === 2 ? COLORS.speaker2 : COLORS.speaker1;
    chunk.words.forEach((word, wi) => {
      const start = wi === 0 ? chunk.start : word.start;
      const end = wi === chunk.words.length - 1 ? chunkEnd : chunk.words[wi + 1].start;
      if (end <= start) return;
      const pop = wi === 0 ? '\\fscx92\\fscy92\\t(0,90,\\fscx100\\fscy100)' : '';
      const text = chunk.words
        .map((w, k) => (k === wi ? `{\\c${highlight}&}${assEscape(w.text)}{\\c${COLORS.white}&}` : assEscape(w.text)))
        .join(' ');
      dialogue(3, start, end, 'Caption', `{\\pos(${cx},${LAYOUT.captionY})${pop}}${text}`);
    });
  });

  if (opts.endCardLine && opts.endCardLine.trim() !== '' && endStart < total) {
    dialogue(4, endStart, total, 'EndLine', `{\\pos(${cx},${LAYOUT.captionY})\\fad(300,0)}${assEscape(opts.endCardLine)}`);
  }

  return [...header, ...lines, ''].join('\n');
}

/**
 * A story image as a 16:9 frame at 1.5x: a portrait image whole, on a blurred and
 * darkened copy of itself; a landscape image cropped to fill the frame.
 */
async function prepareEpisodeImage(src: string, dest: string): Promise<boolean> {
  const w = Math.round(EPISODE_CANVAS.width * SOURCE_SCALE);
  const h = Math.round(EPISODE_CANVAS.height * SOURCE_SCALE);
  try {
    const meta = await sharp(src).metadata();
    const ratio = (meta.width || 1) / (meta.height || 1);
    if (ratio >= 1.4) {
      await sharp(src).resize(w, h, { fit: 'cover', position: sharp.strategy.attention }).modulate({ saturation: 1.08 }).jpeg({ quality: 88 }).toFile(dest);
      return true;
    }
    const backdrop = await sharp(src).resize(Math.round(w / 4), Math.round(h / 4), { fit: 'cover' }).blur(12).modulate({ brightness: 0.55, saturation: 1.1 }).toBuffer();
    const fgHeight = Math.round(h * 0.94);
    const fg = await sharp(src).resize({ height: fgHeight, width: Math.round(w * 0.9), fit: 'inside' }).modulate({ saturation: 1.08 }).toBuffer();
    const fgMeta = await sharp(fg).metadata();
    await sharp(backdrop)
      .resize(w, h)
      .composite([{ input: fg, left: Math.round((w - (fgMeta.width || 0)) / 2), top: Math.round((h - (fgMeta.height || 0)) / 2) }])
      .jpeg({ quality: 88 })
      .toFile(dest);
    return true;
  } catch (err: any) {
    WorkerLogger.warn('EpisodeVideo', `Could not use image ${src}: ${err.message}`);
    return false;
  }
}

/** Darkens the top (topic label) and the bottom (captions) of the frame. */
async function episodeShade(dir: string): Promise<string> {
  const { width, height } = EPISODE_CANVAS;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
  <defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#000" stop-opacity="0.45"/>
    <stop offset="0.16" stop-color="#000" stop-opacity="0"/>
    <stop offset="0.62" stop-color="#000" stop-opacity="0"/>
    <stop offset="1" stop-color="#000" stop-opacity="0.72"/>
  </linearGradient></defs>
  <rect width="100%" height="100%" fill="url(#s)"/>
</svg>`;
  const file = path.join(dir, 'shade.png');
  await sharp(Buffer.from(svg)).png().toFile(file);
  return file;
}

/** FFmpeg arguments of the episode. Paths are relative to the work directory. */
export function buildEpisodeFfmpegArgs(p: {
  slots: PreparedSlot[];
  images: string[];
  audio: string;
  shade: string;
  ass: string;
  fonts: string;
  output: string;
  fps: number;
  total: number;
  logo?: string;
}): string[] {
  const { width, height } = EPISODE_CANVAS;
  const used = Array.from(new Set(p.slots.map((s) => s.image))).sort((a, b) => a - b);
  const inputOf = new Map<number, number>();
  const args: string[] = ['-hide_banner', '-y'];
  used.forEach((img, i) => {
    inputOf.set(img, i);
    args.push('-i', p.images[img]);
  });
  const audioIdx = used.length;
  const shadeIdx = audioIdx + 1;
  args.push('-i', p.audio);
  args.push('-loop', '1', '-i', p.shade);
  const logoIdx = shadeIdx + 1;
  if (p.logo) {
    args.push('-loop', '1', '-framerate', String(p.fps), '-i', p.logo);
  }

  const parts: string[] = [];
  // Each image is read once and split into as many copies as it has shots.
  const uses = new Map<number, number>();
  p.slots.forEach((s) => uses.set(s.image, (uses.get(s.image) || 0) + 1));
  used.forEach((img) => {
    const n = uses.get(img) || 1;
    const outs = Array.from({ length: n }, (_, k) => `[i${img}_${k}]`).join('');
    parts.push(`[${inputOf.get(img)}:v]${n > 1 ? `split=${n}` : 'null'}${outs}`);
  });
  const taken = new Map<number, number>();
  let frameBudget = Math.round(p.total * p.fps);
  p.slots.forEach((slot, i) => {
    const k = taken.get(slot.image) || 0;
    taken.set(slot.image, k + 1);
    const frames = i === p.slots.length - 1 ? Math.max(1, frameBudget) : Math.max(1, Math.round(slot.duration * p.fps));
    frameBudget -= frames;
    parts.push(`[i${slot.image}_${k}]${zoompanFor(slot.motion, frames, width, height, p.fps)},setsar=1,format=yuv420p[s${i}]`);
  });
  parts.push(`${p.slots.map((_, i) => `[s${i}]`).join('')}concat=n=${p.slots.length}:v=1:a=0[bg]`);
  parts.push(`[bg][${shadeIdx}:v]overlay=0:0:shortest=1[shaded]`);
  if (p.logo) {
    parts.push(`[${logoIdx}:v]format=rgba,scale=${LAYOUT.logoWidth}:-1[lg]`);
    parts.push(`[shaded][lg]overlay=x=W-w-${LAYOUT.margin}:y=${LAYOUT.logoY}:shortest=1[branded]`);
  } else {
    parts.push('[shaded]null[branded]');
  }
  parts.push(`[branded]subtitles=${p.ass}:fontsdir=${p.fonts},format=yuv420p[v]`);
  parts.push(...audioGraph(audioIdx, null, p.total));

  args.push(
    '-filter_complex', parts.join(';'),
    '-map', '[v]', '-map', '[a]',
    '-t', p.total.toFixed(3),
    '-r', String(p.fps),
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p',
    '-c:a', 'aac', '-b:a', '160k', '-ar', '44100',
    '-movflags', '+faststart',
    p.output
  );
  return args;
}

/** Renders a YouTube podcast episode. Throws on a missing voice track or an FFmpeg failure. */
export async function generateEpisodeVideo(options: GenerateEpisodeOptions): Promise<EpisodeVideoResult> {
  const { width, height } = EPISODE_CANVAS;
  const fps = options.fps && options.fps > 0 ? Math.round(options.fps) : 25;
  const outputDir = options.outputDir || path.resolve(process.cwd(), 'output');
  const ffmpegBin = resolveFfmpegPath();
  await assertFfmpegCapabilities(ffmpegBin);

  const work = path.join(outputDir, `episode-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`);
  fs.mkdirSync(path.join(work, 'fonts'), { recursive: true });
  try {
    for (const f of fs.readdirSync(fontsDir())) {
      if (/\.(otf|ttf)$/i.test(f)) fs.copyFileSync(path.join(fontsDir(), f), path.join(work, 'fonts', f));
    }

    let audio = '';
    if (options.audioPath && fs.existsSync(options.audioPath)) {
      audio = path.join(work, `voice${path.extname(options.audioPath) || '.wav'}`);
      fs.copyFileSync(options.audioPath, audio);
    }
    if (!audio && options.audioUrl) {
      const ext = (options.audioUrl.match(/\.(wav|mp3|m4a|aac|ogg)(\?|$)/i)?.[1] || 'mp3').toLowerCase();
      const dest = path.join(work, `voice.${ext}`);
      if (await download(options.audioUrl, dest)) audio = dest;
    }
    if (!audio) {
      throw new Error('YouTube podcast episode has no audio track to render.');
    }
    const audioDuration = await probeAudioDuration(audio);
    if (!audioDuration || audioDuration <= 0) {
      throw new Error(`Could not read the length of the episode's audio (${audio}).`);
    }
    const total = Math.round((audioDuration + 0.5) * 1000) / 1000;
    const { segments, scenes } = fitTimeline(options.segments, options.scenes, options.timelineDuration, audioDuration);

    // Unique images: every scene's, then the flat list, then the featured image.
    const urls: string[] = [];
    const addUrl = (u: unknown) => {
      if (typeof u === 'string' && u !== '' && !urls.includes(u) && urls.length < EPISODE_MAX_IMAGES) urls.push(u);
    };
    scenes.forEach((s) => (s.images || []).forEach(addUrl));
    (options.images || []).forEach(addUrl);
    const storyUrls = urls.length;
    if (storyUrls === 0 && !(options.imagePaths || []).length) addUrl(options.fallbackImageUrl);

    const images: string[] = [];
    const indexOfUrl = new Map<string, number>();
    for (const [i, url] of urls.entries()) {
      const src = path.join(work, `src-${i}`);
      const dest = path.join(work, `img-${i}.jpg`);
      if ((await download(url, src)) && (await prepareEpisodeImage(src, dest))) {
        indexOfUrl.set(url, images.length);
        images.push(dest);
      }
    }
    for (const [i, src] of (options.imagePaths || []).entries()) {
      const dest = path.join(work, `local-${i}.jpg`);
      if (await prepareEpisodeImage(src, dest)) images.push(dest);
    }
    const usable = images.length;
    if (usable === 0) {
      WorkerLogger.warn('EpisodeVideo', 'The episode has no usable image; rendering it on the branded background.');
      images.push(...(await brandedBackgrounds(work, Math.round((width * SOURCE_SCALE) / 2), Math.round((height * SOURCE_SCALE) / 2))));
    }

    const logoSrc = logoPath();
    let logo = '';
    if (logoSrc) {
      logo = path.join(work, 'logo.png');
      fs.copyFileSync(logoSrc, logo);
    }

    if (segments.length === 0) {
      WorkerLogger.warn('EpisodeVideo', 'The episode has no caption timeline; rendering it without captions.');
    }
    const chunks = buildCaptionChunks(timeWords(segments));
    const ass = buildEpisodeAssScript({
      title: options.title || '',
      siteName: options.siteName || 'PressHub.Cy',
      chunks,
      scenes,
      totalDuration: total,
      hasLogo: Boolean(logo),
      endCardLine: options.endCardLine,
    });
    fs.writeFileSync(path.join(work, 'captions.ass'), ass, 'utf8');
    const shade = await episodeShade(work);

    const slots = planEpisodeSlots(scenes, total, (u) => indexOfUrl.get(u) ?? -1, images.length);
    const outName = `episode-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.mp4`;
    const args = buildEpisodeFfmpegArgs({
      slots,
      images: images.map((f) => path.basename(f)),
      audio: path.basename(audio),
      shade: path.basename(shade),
      ass: 'captions.ass',
      fonts: 'fonts',
      output: outName,
      fps,
      total,
      logo: logo ? path.basename(logo) : undefined,
    });

    WorkerLogger.info('EpisodeVideo', `Rendering episode (${(total / 60).toFixed(1)} min, ${scenes.length} scene(s), ${usable} image(s), ${chunks.length} caption chunks, ${slots.length} shots)`);
    const started = Date.now();
    await runFfmpeg(ffmpegBin, args, work);
    const finalPath = path.join(outputDir, outName);
    fs.renameSync(path.join(work, outName), finalPath);
    WorkerLogger.info('EpisodeVideo', `Episode rendered in ${((Date.now() - started) / 1000).toFixed(1)}s: ${finalPath}`);

    return { filePath: finalPath, width, height, duration: total, captions: chunks.length, images: usable, scenes: scenes.length };
  } finally {
    fs.rmSync(work, { recursive: true, force: true });
  }
}
