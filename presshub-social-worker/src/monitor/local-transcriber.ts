/**
 * Local speech-to-text for videos that are too long for the Gemini path.
 *
 * Uses whisper.cpp (the `whisper-cli` executable and a ggml model) on this
 * machine: ffmpeg turns the video into 16 kHz mono WAV, whisper writes a
 * plain-text transcript. Configured with environment variables; switched
 * off unless both the executable and the model exist.
 *
 *   PRESSHUB_WHISPER_BIN            path to whisper-cli(.exe)
 *   PRESSHUB_WHISPER_MODEL          path to the ggml model (.bin)
 *   PRESSHUB_WHISPER_LANG           spoken language, default "el" ("auto" detects)
 *   PRESSHUB_WHISPER_THREADS        CPU threads, default 4
 *   PRESSHUB_LOCAL_TRANSCRIBE_MAX_SEC  longest video to transcribe, default 1800
 *   PRESSHUB_LOCAL_TRANSCRIBE_MAX_MB   largest download, default 300
 *   PRESSHUB_LOCAL_TRANSCRIBE_DISABLED=true  turn it off
 */
import { execFile } from 'child_process';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { resolveFfmpegPath } from '../media/short-video';

export interface LocalTranscriberConfig {
  bin: string;
  model: string;
  ffmpeg: string;
  lang: string;
  threads: number;
  maxSec: number;
  maxMb: number;
}

/** Runs an executable; resolves with stdout, rejects on a non-zero exit or timeout. */
export type ExecFn = (file: string, args: string[], timeoutMs: number) => Promise<string>;

const defaultExec: ExecFn = (file, args, timeoutMs) =>
  new Promise((resolve, reject) => {
    execFile(file, args, { timeout: timeoutMs, maxBuffer: 64 * 1024 * 1024, windowsHide: true }, (err, stdout, stderr) => {
      if (err) {
        const tail = String(stderr || '').trim().split(/\r?\n/).slice(-3).join(' | ');
        reject(new Error(`${path.basename(file)} failed: ${err.message}${tail ? ` (${tail})` : ''}`));
        return;
      }
      resolve(String(stdout || ''));
    });
  });

function intEnv(env: NodeJS.ProcessEnv, name: string, def: number, min: number, max: number): number {
  const n = parseInt(String(env[name] ?? ''), 10);
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : def;
}

/** Config from the environment, or null when local transcription is off or not installed. */
export function localTranscriberConfigFromEnv(env: NodeJS.ProcessEnv = process.env, exists: (p: string) => boolean = fs.existsSync): LocalTranscriberConfig | null {
  if (env.PRESSHUB_LOCAL_TRANSCRIBE_DISABLED === 'true') return null;
  const bin = String(env.PRESSHUB_WHISPER_BIN || '').trim();
  const model = String(env.PRESSHUB_WHISPER_MODEL || '').trim();
  if (!bin || !model || !exists(bin) || !exists(model)) return null;
  const lang = String(env.PRESSHUB_WHISPER_LANG || 'el').trim().toLowerCase();
  return {
    bin,
    model,
    ffmpeg: resolveFfmpegPath(),
    lang: /^[a-z]{2,3}$|^auto$/.test(lang) ? lang : 'el',
    threads: intEnv(env, 'PRESSHUB_WHISPER_THREADS', 4, 1, 64),
    maxSec: intEnv(env, 'PRESSHUB_LOCAL_TRANSCRIBE_MAX_SEC', 1800, 60, 4 * 3600),
    maxMb: intEnv(env, 'PRESSHUB_LOCAL_TRANSCRIBE_MAX_MB', 300, 10, 2048)
  };
}

/** Plain text from whisper's .txt output: trimmed lines, blank runs collapsed. */
export function cleanTranscript(raw: string): string {
  return String(raw || '')
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l, i, all) => l !== '' || (i > 0 && all[i - 1] !== ''))
    .join('\n')
    .trim();
}

export class LocalTranscriber {
  constructor(
    public readonly config: LocalTranscriberConfig,
    private readonly exec: ExecFn = defaultExec
  ) {}

  /**
   * Transcribe one video file. Returns the text ('' when nobody speaks).
   * Scratch files are removed afterwards.
   */
  public async transcribeFile(videoPath: string, durationSec: number): Promise<string> {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'presshub-whisper-'));
    try {
      const wav = path.join(dir, 'audio.wav');
      const base = path.join(dir, 'transcript');
      // Allow ~3x real time for whisper on a CPU, and at least 10 minutes.
      const budget = Math.max(600, Math.round(Math.max(1, durationSec) * 3)) * 1000;
      await this.exec(this.config.ffmpeg, ['-hide_banner', '-loglevel', 'error', '-y', '-i', videoPath, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', wav], 10 * 60 * 1000);
      await this.exec(
        this.config.bin,
        ['-m', this.config.model, '-f', wav, '-l', this.config.lang, '-t', String(this.config.threads), '-nt', '-otxt', '-of', base],
        budget
      );
      const txt = fs.existsSync(base + '.txt') ? fs.readFileSync(base + '.txt', 'utf8') : '';
      return cleanTranscript(txt);
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  }
}
