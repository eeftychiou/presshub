import fs from 'fs';
import path from 'path';

/**
 * Zero-dependency .env file parser.
 * Parses lines with KEY=VALUE, ignoring comments and empty lines.
 * Supports quoted values (single and double quotes) and inline comment stripping.
 */
export function parseEnv(content: string): Record<string, string> {
  const result: Record<string, string> = {};
  const lines = content.split(/\r?\n/);

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const eqIndex = trimmed.indexOf('=');
    if (eqIndex === -1) {
      continue;
    }

    const key = trimmed.slice(0, eqIndex).trim();
    let val = trimmed.slice(eqIndex + 1).trim();

    if (!key) {
      continue;
    }

    // If value starts with a quote, extract up to the matching closing quote
    if (val.startsWith('"') || val.startsWith("'")) {
      const quoteChar = val[0];
      const closeIndex = val.indexOf(quoteChar, 1);
      if (closeIndex !== -1) {
        let inner = val.slice(1, closeIndex);
        if (quoteChar === '"') {
          inner = inner.replace(/\\n/g, '\n').replace(/\\r/g, '\r').replace(/\\t/g, '\t');
        }
        result[key] = inner;
        continue;
      }
    }

    // If unquoted, strip trailing inline comments (# ...)
    const commentIndex = val.indexOf('#');
    if (commentIndex !== -1) {
      val = val.slice(0, commentIndex).trim();
    }

    result[key] = val;
  }

  return result;
}

/**
 * Loads a .env file into process.env without overwriting already defined keys.
 * Default path: path.resolve(process.cwd(), '.env')
 * @param envPath Optional custom path to .env file
 * @returns Record of keys loaded into process.env
 */
export function loadEnv(envPath?: string): Record<string, string> {
  const targetPath = envPath || path.resolve(process.cwd(), '.env');
  if (!fs.existsSync(targetPath)) {
    return {};
  }

  try {
    const content = fs.readFileSync(targetPath, 'utf-8');
    const parsed = parseEnv(content);
    const loaded: Record<string, string> = {};

    for (const [key, value] of Object.entries(parsed)) {
      if (process.env[key] === undefined) {
        process.env[key] = value;
        loaded[key] = value;
      }
    }

    return loaded;
  } catch (err: any) {
    console.warn(`[PressHub Worker] Failed to read .env file at ${targetPath}:`, err.message);
    return {};
  }
}

export interface WorkerEnvConfig {
  wpUrl?: string;
  workerSecret?: string;
  port: number;
  pollIntervalMs: number;
  chromeCdpUrl?: string;
  headless: boolean;
}

/**
 * Retrieves normalized worker configuration from process.env.
 */
export function getWorkerConfig(): WorkerEnvConfig {
  return {
    wpUrl: process.env.PRESSHUB_WP_URL?.trim() || undefined,
    workerSecret: process.env.PRESSHUB_WORKER_SECRET?.trim() || undefined,
    port: parseInt(process.env.PRESSHUB_WORKER_PORT || '3456', 10),
    pollIntervalMs: parseInt(process.env.PRESSHUB_POLL_INTERVAL_MS || '30000', 10),
    chromeCdpUrl: process.env.PRESSHUB_CHROME_CDP_URL?.trim() || undefined,
    headless: process.env.PRESSHUB_HEADLESS !== undefined
      ? process.env.PRESSHUB_HEADLESS.toLowerCase() === 'true'
      : false
  };
}
