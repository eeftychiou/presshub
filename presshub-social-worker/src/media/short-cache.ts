/**
 * Render cache for shorts: one story is rendered once and the same MP4 is posted to
 * TikTok, Instagram, Facebook and YouTube.
 *
 * WordPress hands every platform's job of a story the same inputs (voice track, story images,
 * hook, badge, timed turns, music), so the render key is a hash of those inputs. The first job
 * of a story renders the video and moves it into `<output>/short-cache/<key>.mp4`, with a
 * `<key>.json` sidecar holding its duration; the next jobs of that story reuse the file. Files
 * older than the time to live (24 hours by default) are removed, which covers every platform's
 * slot of a story plus its retries.
 */

import * as crypto from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { GenerateShortOptions, ShortVideoResult } from './short-video';

export const SHORT_CACHE_DIRNAME = 'short-cache';
export const DEFAULT_SHORT_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

/** The inputs that decide what a short looks and sounds like (not where it is written). */
export function shortRenderKey(opts: GenerateShortOptions): string {
  const inputs = {
    audioUrl: opts.audioUrl || '',
    audioPath: opts.audioPath || '',
    imageUrls: opts.imageUrls || [],
    imagePaths: opts.imagePaths || [],
    hook: opts.hook || '',
    topic: opts.topic || '',
    badge: opts.badge || '',
    siteName: opts.siteName || '',
    segments: opts.segments || [],
    width: opts.width || 0,
    height: opts.height || 0,
    fps: opts.fps || 0,
    tailSeconds: opts.tailSeconds ?? null,
    musicUrl: opts.musicUrl || '',
    musicPath: opts.musicPath || '',
    musicVolume: opts.musicVolume ?? null,
    endCardLine: opts.endCardLine || '',
    postCards: opts.postCards || [],
    cardSpans: opts.cardSpans || []
  };
  return crypto.createHash('sha256').update(JSON.stringify(inputs)).digest('hex').slice(0, 32);
}

export interface CachedShort extends ShortVideoResult {
  /** True when the file lives in the cache: the caller must not delete it. */
  cached: boolean;
}

export class ShortRenderCache {
  constructor(private readonly dir: string, private readonly ttlMs: number = DEFAULT_SHORT_CACHE_TTL_MS) {}

  get enabled(): boolean {
    return this.ttlMs > 0;
  }

  private files(key: string): { video: string; meta: string } {
    return { video: path.join(this.dir, `${key}.mp4`), meta: path.join(this.dir, `${key}.json`) };
  }

  /** The cached render of a key, or null when there is none (or it is too old). */
  get(key: string, now: number = Date.now()): CachedShort | null {
    if (!this.enabled) return null;
    const { video, meta } = this.files(key);
    try {
      const stat = fs.statSync(video);
      if (stat.size <= 0 || now - stat.mtimeMs > this.ttlMs) return null;
      const info = JSON.parse(fs.readFileSync(meta, 'utf8'));
      return { ...info, filePath: video, cached: true };
    } catch {
      return null;
    }
  }

  /**
   * Moves a fresh render into the cache and returns where it now is. When the cache is off
   * or the file cannot be moved, the render is returned where it was (cached: false), so the
   * caller removes it after posting as before.
   */
  put(key: string, result: ShortVideoResult): CachedShort {
    const plain: CachedShort = { ...result, cached: false };
    if (!this.enabled || !result?.filePath || !fs.existsSync(result.filePath)) return plain;
    const { video, meta } = this.files(key);
    try {
      fs.mkdirSync(this.dir, { recursive: true });
      try {
        fs.renameSync(result.filePath, video);
      } catch {
        // Another drive: copy, then remove the original.
        fs.copyFileSync(result.filePath, video);
        try { fs.unlinkSync(result.filePath); } catch {}
      }
      const { filePath: _ignored, ...info } = result;
      fs.writeFileSync(meta, JSON.stringify(info));
      return { ...result, filePath: video, cached: true };
    } catch {
      return fs.existsSync(result.filePath) ? plain : { ...result, filePath: video, cached: fs.existsSync(video) };
    }
  }

  /** Removes cached renders older than the time to live. Returns how many videos were removed. */
  prune(now: number = Date.now()): number {
    if (!this.enabled || !fs.existsSync(this.dir)) return 0;
    let removed = 0;
    for (const name of fs.readdirSync(this.dir)) {
      const file = path.join(this.dir, name);
      try {
        const stat = fs.statSync(file);
        if (!stat.isFile() || now - stat.mtimeMs <= this.ttlMs) continue;
        fs.unlinkSync(file);
        if (name.endsWith('.mp4')) removed++;
      } catch {}
    }
    return removed;
  }
}
