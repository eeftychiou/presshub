import path from 'path';
import fs from 'fs';

export const SUPPORTED_PLATFORMS = ['twitter', 'tiktok', 'facebook', 'instagram'] as const;
export type Platform = (typeof SUPPORTED_PLATFORMS)[number];

export interface SessionVerificationResult {
  valid: boolean;
  platform: string;
  cookieCount: number;
  reason?: string;
}

export function isValidPlatform(platform: string): platform is Platform {
  return (SUPPORTED_PLATFORMS as readonly string[]).includes(platform);
}

/**
 * Validates platform against allowlist and prevents path traversal attacks.
 */
export function sanitizePlatform(platform: string): Platform {
  if (!platform || typeof platform !== 'string') {
    throw new Error('Platform is required and must be a string');
  }
  const clean = platform.toLowerCase().trim();
  if (!isValidPlatform(clean)) {
    throw new Error(
      `Unsupported platform or directory traversal attempted: '${platform}'. Supported platforms: ${SUPPORTED_PLATFORMS.join(', ')}`
    );
  }
  return clean;
}

export function getSessionsDir(customDir?: string): string {
  const dir = customDir || path.resolve(process.cwd(), 'sessions');
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
  return dir;
}

export function getSessionPath(platform: string, sessionsDir?: string): string {
  const validatedPlatform = sanitizePlatform(platform);
  const dir = getSessionsDir(sessionsDir);
  return path.join(dir, `${validatedPlatform}-session.json`);
}

export function verifySession(platform: string, sessionsDir?: string): SessionVerificationResult {
  let validatedPlatform: Platform;
  try {
    validatedPlatform = sanitizePlatform(platform);
  } catch (err: any) {
    return {
      valid: false,
      platform,
      cookieCount: 0,
      reason: err.message
    };
  }

  const sessionPath = getSessionPath(validatedPlatform, sessionsDir);

  if (!fs.existsSync(sessionPath)) {
    return {
      valid: false,
      platform: validatedPlatform,
      cookieCount: 0,
      reason: `Session file not found at ${sessionPath}`
    };
  }

  let data: any;
  try {
    const raw = fs.readFileSync(sessionPath, 'utf-8');
    data = JSON.parse(raw);
  } catch (err: any) {
    return {
      valid: false,
      platform: validatedPlatform,
      cookieCount: 0,
      reason: `Corrupted session JSON: ${err.message}`
    };
  }

  if (!data || !Array.isArray(data.cookies) || data.cookies.length === 0) {
    return {
      valid: false,
      platform: validatedPlatform,
      cookieCount: 0,
      reason: 'Session contains no cookies'
    };
  }

  const nowSec = Date.now() / 1000;
  // A cookie is valid if expires === -1 (session cookie) or expires > current timestamp
  const activeCookies = data.cookies.filter((c: any) => {
    if (typeof c !== 'object' || c === null) return false;
    if (typeof c.expires !== 'number') return true;
    return c.expires === -1 || c.expires > nowSec;
  });

  if (activeCookies.length === 0) {
    return {
      valid: false,
      platform: validatedPlatform,
      cookieCount: data.cookies.length,
      reason: `All ${data.cookies.length} session cookies have expired`
    };
  }

  return {
    valid: true,
    platform: validatedPlatform,
    cookieCount: activeCookies.length
  };
}

export function saveSession(platform: string, state: object | string, sessionsDir?: string): string {
  const validatedPlatform = sanitizePlatform(platform);
  const sessionPath = getSessionPath(validatedPlatform, sessionsDir);
  const dir = path.dirname(sessionPath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }

  const content = typeof state === 'string' ? state : JSON.stringify(state, null, 2);
  fs.writeFileSync(sessionPath, content, 'utf-8');
  return sessionPath;
}
