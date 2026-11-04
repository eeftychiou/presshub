import ffmpeg from 'fluent-ffmpeg';
import ffmpegInstaller from '@ffmpeg-installer/ffmpeg';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import { execFile } from 'child_process';
import util from 'util';
import { generateSocialCard } from './card-generator';
import { WorkerLogger } from '../logger';

const execFileAsync = util.promisify(execFile);

if (ffmpegInstaller && ffmpegInstaller.path) {
  ffmpeg.setFfmpegPath(ffmpegInstaller.path);
}

/**
 * TikTok web creator center maximum upload duration in seconds (10 minutes).
 */
export const TIKTOK_MAX_DURATION = 600;

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
  maxDuration?: number;
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
 * Probes the duration in seconds of an audio file using FFmpeg metadata inspection.
 * Returns the duration in seconds as a float, or null if duration cannot be detected.
 */
export async function probeAudioDuration(audioPath: string): Promise<number | null> {
  if (!audioPath || !fs.existsSync(audioPath)) {
    return null;
  }

  const ffmpegBin = (ffmpegInstaller && ffmpegInstaller.path) ? ffmpegInstaller.path : 'ffmpeg';

  try {
    const result = await execFileAsync(ffmpegBin, ['-i', audioPath]).catch((err: any) => {
      // FFmpeg exits with non-zero code when no output file is specified, but prints metadata to stderr
      return { stdout: err.stdout || '', stderr: err.stderr || '' };
    });

    const stderr = result.stderr || '';
    const match = stderr.match(/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/i);
    if (match) {
      const hours = parseFloat(match[1]);
      const minutes = parseFloat(match[2]);
      const seconds = parseFloat(match[3]);
      const totalSec = hours * 3600 + minutes * 60 + seconds;
      if (!isNaN(totalSec) && totalSec > 0) {
        return totalSec;
      }
    }
  } catch (err: any) {
    WorkerLogger.warn('VideoGenerator', `Error probing audio duration for ${audioPath}: ${err.message}`);
  }

  return null;
}

/**
 * Downloads remote audio file to a local temporary path.
 */
async function downloadAudio(url: string, outputDir: string): Promise<string | null> {
  try {
    const extMatch = url.match(/\.(wav|mp3|aac|m4a|ogg)(\?.*)?$/i);
    const ext = extMatch ? `.${extMatch[1]}` : '.wav';
    const tempAudioPath = path.join(outputDir, `audio-${Date.now()}-${crypto.randomBytes(3).toString('hex')}${ext}`);

    WorkerLogger.info('VideoGenerator', `Downloading audio track from ${url}...`);
    const res = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 PressHub-VideoGenerator/1.0' } });
    if (res.ok) {
      const buffer = Buffer.from(await res.arrayBuffer());
      fs.writeFileSync(tempAudioPath, buffer);
      WorkerLogger.debug('VideoGenerator', `Audio track downloaded (${buffer.length} bytes) to ${tempAudioPath}`);
      return tempAudioPath;
    } else {
      WorkerLogger.warn('VideoGenerator', `Failed to download audio from ${url}: HTTP ${res.status}`);
    }
  } catch (err: any) {
    WorkerLogger.warn('VideoGenerator', `Failed to download audio from ${url}: ${err.message}`);
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

  // Determine video duration: probe audio if present, else fallback to default duration
  let duration: number;

  if (hasAudioFile) {
    WorkerLogger.debug('VideoGenerator', `Audio track attached (${resolvedAudioPath}). Probing duration...`);
    const probed = await probeAudioDuration(resolvedAudioPath!);
    if (probed && probed > 0) {
      WorkerLogger.info('VideoGenerator', `Probed audio duration: ${probed.toFixed(2)}s`);
      if (probed > TIKTOK_MAX_DURATION) {
        WorkerLogger.warn(
          'VideoGenerator',
          `Audio duration (${probed.toFixed(1)}s) exceeds TikTok max upload duration (${TIKTOK_MAX_DURATION}s). Clamping video duration to ${TIKTOK_MAX_DURATION}s.`
        );
        duration = TIKTOK_MAX_DURATION;
      } else {
        // Use full audio duration (ceiling to guarantee no end-of-speech truncation)
        duration = Math.max(5, Math.ceil(probed));
      }

      // Check if caller specified a lower custom clamp
      if (options.maxDuration && options.maxDuration > 0 && duration > options.maxDuration) {
        duration = options.maxDuration;
        WorkerLogger.info('VideoGenerator', `Audio duration clamped to explicit maxDuration: ${duration}s`);
      }
    } else {
      WorkerLogger.warn('VideoGenerator', `Could not probe audio duration. Falling back to default duration.`);
      duration = clampDuration(options.duration, 10, 5, 60);
    }
  } else {
    duration = clampDuration(options.duration, 10, 5, 60);
    WorkerLogger.debug('VideoGenerator', `No audio track attached. Using still-image duration: ${duration}s`);
  }

  const videoFileName = `video-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.mp4`;
  const outputFilePath = path.join(outputDir, videoFileName);
  const filterString = `scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2,setsar=1`;

  WorkerLogger.info('VideoGenerator', `Starting FFmpeg video render to ${outputFilePath}`, {
    image: resolvedImagePath,
    audio: hasAudioFile ? resolvedAudioPath : 'silent (lavfi)',
    duration,
    width,
    height,
    filters: filterString
  });

  return new Promise<VideoResult>((resolve, reject) => {
    let command = ffmpeg();

    // Image input looped
    command = command.input(resolvedImagePath!).inputOption('-loop 1');

    if (hasAudioFile) {
      WorkerLogger.info('VideoGenerator', `Using audio track: ${resolvedAudioPath}`);
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
        `-vf ${filterString}`,
        '-movflags +faststart'
      ])
      .audioCodec('aac')
      .audioBitrate('192k')
      .output(outputFilePath)
      .on('start', (commandLine) => {
        WorkerLogger.debug('VideoGenerator', `FFmpeg spawned command: ${commandLine}`);
      })
      .on('stderr', (stderrLine) => {
        WorkerLogger.debug('VideoGenerator', `[FFmpeg stderr] ${stderrLine}`);
      })
      .on('end', () => {
        // Clean up downloaded temp audio file if any
        if (downloadedTempAudio && fs.existsSync(downloadedTempAudio)) {
          try {
            fs.unlinkSync(downloadedTempAudio);
          } catch {}
        }
        WorkerLogger.info('VideoGenerator', `FFmpeg video successfully generated: ${outputFilePath} (${duration}s)`);
        resolve({
          filePath: outputFilePath,
          width,
          height,
          duration
        });
      })
      .on('error', (err, stdout, stderr) => {
        if (downloadedTempAudio && fs.existsSync(downloadedTempAudio)) {
          try {
            fs.unlinkSync(downloadedTempAudio);
          } catch {}
        }
        WorkerLogger.error('VideoGenerator', `FFmpeg video generation failed: ${err.message}`, {
          error: err.message,
          stdout,
          stderr
        });
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
  maxDuration?: number;
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
    maxDuration: params.maxDuration,
    outputDir: params.outputDir
  });
}
