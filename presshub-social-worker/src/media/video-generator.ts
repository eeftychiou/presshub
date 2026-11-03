import ffmpeg from 'fluent-ffmpeg';
import ffmpegInstaller from '@ffmpeg-installer/ffmpeg';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import { generateSocialCard } from './card-generator';

if (ffmpegInstaller && ffmpegInstaller.path) {
  ffmpeg.setFfmpegPath(ffmpegInstaller.path);
}

export interface GenerateVideoOptions {
  title?: string;
  subtitle?: string;
  category?: string;
  siteName?: string;
  imagePath?: string;
  audioPath?: string;
  mediaUrl?: string;
  backgroundUrl?: string;
  audioUrl?: string;
  isPodcast?: boolean;
  width?: number;
  height?: number;
  duration?: number;
  outputDir?: string;
}

export interface VideoResult {
  filePath: string;
  width: number;
  height: number;
  duration: number;
}

/**
 * Clamps numeric value between min and max, ensuring it's an even integer for video codecs.
 */
export function clampEven(val: number | undefined, defaultVal: number, min: number, max: number): number {
  let num = typeof val === 'number' && !isNaN(val) ? Math.round(val) : defaultVal;
  num = Math.min(max, Math.max(min, num));
  if (num % 2 !== 0) {
    num = num + 1 <= max ? num + 1 : num - 1;
  }
  return num;
}

/**
 * Clamps duration between min and max seconds.
 */
export function clampDuration(val: number | undefined, defaultVal: number = 10, min: number = 5, max: number = 60): number {
  if (typeof val !== 'number' || isNaN(val)) return defaultVal;
  return Math.min(max, Math.max(min, Math.round(val)));
}

/**
 * Downloads remote audio file to a local temporary path.
 */
async function downloadAudio(url: string, outputDir: string): Promise<string | null> {
  try {
    const extMatch = url.match(/\.(wav|mp3|aac|m4a|ogg)(\?.*)?$/i);
    const ext = extMatch ? `.${extMatch[1]}` : '.wav';
    const tempAudioPath = path.join(outputDir, `audio-${Date.now()}-${crypto.randomBytes(3).toString('hex')}${ext}`);

    console.log(`[PressHub Video Generator] Downloading audio track from ${url}...`);
    const res = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 PressHub-VideoGenerator/1.0' } });
    if (res.ok) {
      const buffer = Buffer.from(await res.arrayBuffer());
      fs.writeFileSync(tempAudioPath, buffer);
      return tempAudioPath;
    }
  } catch (err: any) {
    console.warn(`[PressHub Video Generator] Failed to download audio from ${url}:`, err.message);
  }
  return null;
}

/**
 * Generates a vertical MP4 video (libx264 / aac) with audio track.
 * Supports using featured images, composited icons, real podcast audio tracks, and PressHub branding.
 */
export async function generateSocialVideo(options: GenerateVideoOptions): Promise<VideoResult> {
  const width = clampEven(options.width, 1080, 540, 2160);
  const height = clampEven(options.height, 1920, 960, 3840);
  const duration = clampDuration(options.duration, 10, 5, 60);
  const outputDir = options.outputDir || path.resolve(process.cwd(), 'output');

  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  let resolvedImagePath = options.imagePath;
  let generatedTempImage = false;

  if (!resolvedImagePath || !fs.existsSync(resolvedImagePath)) {
    const isPodcast = Boolean(
      options.isPodcast ||
      options.title?.toLowerCase().includes('podcast') ||
      options.category?.toLowerCase().includes('podcast')
    );

    const cardResult = await generateSocialCard({
      title: options.title || 'Breaking Update',
      subtitle: options.subtitle,
      category: options.category || (isPodcast ? 'PODCAST BRIEFING' : 'PRESS BRIEFING'),
      siteName: options.siteName || 'PressHub.Cy',
      mediaUrl: options.mediaUrl,
      backgroundUrl: options.backgroundUrl,
      isPodcast,
      width,
      height,
      outputDir
    });
    resolvedImagePath = cardResult.filePath;
    generatedTempImage = true;
  }

  // Resolve audio path (either local file or downloaded from audioUrl)
  let resolvedAudioPath = options.audioPath;
  let downloadedTempAudio: string | null = null;

  if ((!resolvedAudioPath || !fs.existsSync(resolvedAudioPath)) && options.audioUrl) {
    downloadedTempAudio = await downloadAudio(options.audioUrl, outputDir);
    if (downloadedTempAudio) {
      resolvedAudioPath = downloadedTempAudio;
    }
  }

  const hasAudioFile = Boolean(resolvedAudioPath && fs.existsSync(resolvedAudioPath));
  const videoFileName = `video-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.mp4`;
  const outputFilePath = path.join(outputDir, videoFileName);

  return new Promise<VideoResult>((resolve, reject) => {
    let command = ffmpeg();

    // Image input looped
    command = command.input(resolvedImagePath!).inputOption('-loop 1');

    if (hasAudioFile) {
      console.log(`[PressHub Video Generator] Using audio track: ${resolvedAudioPath}`);
      command = command.input(resolvedAudioPath!);
    } else {
      // Lavfi silent stereo stream so video always has a valid AAC audio track
      command = command.input('anullsrc=r=44100:cl=stereo').inputOption('-f lavfi');
    }

    command
      .videoCodec('libx264')
      .outputOptions([
        '-tune stillimage',
        '-pix_fmt yuv420p',
        '-shortest',
        `-t ${duration}`,
        `-vf scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2,setsar=1`,
        '-movflags +faststart'
      ])
      .audioCodec('aac')
      .audioBitrate('192k')
      .output(outputFilePath)
      .on('end', () => {
        // Clean up downloaded temp audio file if any
        if (downloadedTempAudio && fs.existsSync(downloadedTempAudio)) {
          try {
            fs.unlinkSync(downloadedTempAudio);
          } catch {}
        }
        resolve({
          filePath: outputFilePath,
          width,
          height,
          duration
        });
      })
      .on('error', (err) => {
        if (downloadedTempAudio && fs.existsSync(downloadedTempAudio)) {
          try {
            fs.unlinkSync(downloadedTempAudio);
          } catch {}
        }
        reject(new Error(`FFmpeg video generation failed: ${err.message}`));
      })
      .run();
  });
}

export interface GenerateVideoParams {
  postId?: number;
  title?: string;
  subtitle?: string;
  durationSec?: number;
  duration?: number;
  width?: number;
  height?: number;
  outputDir?: string;
  siteName?: string;
  category?: string;
  imagePath?: string;
  audioPath?: string;
  mediaUrl?: string;
  backgroundUrl?: string;
  audioUrl?: string;
  isPodcast?: boolean;
}

export async function generateVideo(params: GenerateVideoParams): Promise<VideoResult> {
  return generateSocialVideo({
    title: params.title,
    subtitle: params.subtitle,
    category: params.category,
    siteName: params.siteName,
    imagePath: params.imagePath,
    audioPath: params.audioPath,
    mediaUrl: params.mediaUrl,
    backgroundUrl: params.backgroundUrl,
    audioUrl: params.audioUrl,
    isPodcast: params.isPodcast,
    width: params.width,
    height: params.height,
    duration: params.durationSec ?? params.duration,
    outputDir: params.outputDir
  });
}
