import fs from 'fs';
import path from 'path';

/**
 * A small local record of finished YouTube uploads, so a job that is handed out again
 * (the worker stopped before WordPress heard back, or the report was lost) is reported
 * with the video that is already on YouTube instead of being uploaded a second time.
 */

export interface UploadRecord {
  videoId: string;
  url: string;
  kind: string;
  /** WordPress post of the job; a record for another post (job IDs reused) is ignored. */
  postId?: string;
  playlistItemId?: string;
  playlistError?: string;
  uploadedAt: string;
}

const FILE_NAME = 'youtube-uploads.json';
/** Records older than this are dropped: a job is never handed out again that late. */
const KEEP_MS = 30 * 24 * 3600 * 1000;

function filePath(dir: string): string {
  return path.join(dir, FILE_NAME);
}

function key(wpUrl: string, jobId: number | string): string {
  return `${wpUrl.replace(/\/+$/, '')}#${jobId}`;
}

function readAll(dir: string): Record<string, UploadRecord> {
  try {
    const data = JSON.parse(fs.readFileSync(filePath(dir), 'utf8'));
    return data && typeof data === 'object' ? data : {};
  } catch {
    return {};
  }
}

export function findUpload(dir: string, wpUrl: string, jobId: number | string, postId?: number | string): UploadRecord | null {
  const rec = readAll(dir)[key(wpUrl, jobId)];
  if (!rec || typeof rec.videoId !== 'string' || rec.videoId === '') {
    return null;
  }
  const want = postId === undefined || postId === null ? '' : String(postId);
  if (rec.postId && want !== '' && rec.postId !== want) {
    return null;
  }
  return rec;
}

export function recordUpload(dir: string, wpUrl: string, jobId: number | string, rec: UploadRecord): void {
  const all = readAll(dir);
  const now = Date.now();
  for (const [k, v] of Object.entries(all)) {
    if (!v || now - Date.parse(v.uploadedAt || '') > KEEP_MS) delete all[k];
  }
  all[key(wpUrl, jobId)] = rec;
  fs.mkdirSync(dir, { recursive: true });
  // A name of its own, so two workers sharing the folder never write the same temp file.
  const tmp = `${filePath(dir)}.${process.pid}.${Date.now()}.${Math.random().toString(36).slice(2)}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(all, null, 2), 'utf8');
  fs.renameSync(tmp, filePath(dir));
}
