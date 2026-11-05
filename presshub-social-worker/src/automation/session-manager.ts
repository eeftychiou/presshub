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
  if (customDir) {
    if (!fs.existsSync(customDir)) {
      fs.mkdirSync(customDir, { recursive: true });
    }
    return customDir;
  }
  const defaultDir = fs.existsSync(path.resolve(process.cwd(), 'presshub-social-worker/sessions'))
    ? path.resolve(process.cwd(), 'presshub-social-worker/sessions')
    : (fs.existsSync(path.resolve(__dirname, '../../sessions'))
        ? path.resolve(__dirname, '../../sessions')
        : path.resolve(process.cwd(), 'sessions'));
  if (!fs.existsSync(defaultDir)) {
    fs.mkdirSync(defaultDir, { recursive: true });
  }
  return defaultDir;
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

/**
 * Builds a valid Playwright storageState JSON for Twitter / X.
 * Configures auth_token and optional ct0 CSRF cookie for both .x.com and .twitter.com.
 * Cookies are set to expire 1 year in the future.
 */
export function buildTwitterStorageState(
  authToken: string,
  ct0?: string
): { cookies: any[]; origins: any[] } {
  if (!authToken || typeof authToken !== 'string' || !authToken.trim()) {
    throw new Error('Twitter auth_token is required');
  }

  const cleanToken = authToken.trim();
  const cleanCt0 = ct0 ? ct0.trim() : undefined;
  const expires = Math.floor(Date.now() / 1000) + 365 * 24 * 3600;

  const cookies: any[] = [
    {
      name: 'auth_token',
      value: cleanToken,
      domain: '.x.com',
      path: '/',
      expires,
      httpOnly: true,
      secure: true,
      sameSite: 'None'
    },
    {
      name: 'auth_token',
      value: cleanToken,
      domain: '.twitter.com',
      path: '/',
      expires,
      httpOnly: true,
      secure: true,
      sameSite: 'None'
    }
  ];

  if (cleanCt0) {
    cookies.push(
      {
        name: 'ct0',
        value: cleanCt0,
        domain: '.x.com',
        path: '/',
        expires,
        httpOnly: false,
        secure: true,
        sameSite: 'None'
      },
      {
        name: 'ct0',
        value: cleanCt0,
        domain: '.twitter.com',
        path: '/',
        expires,
        httpOnly: false,
        secure: true,
        sameSite: 'None'
      }
    );
  }

  return {
    cookies,
    origins: []
  };
}

/**
 * Builds a valid Playwright storageState JSON for TikTok.
 * Configures sessionid and sessionid_ss cookies for .tiktok.com.
 * Cookies are set to expire 1 year in the future.
 */
export function buildTikTokStorageState(sessionId: string): { cookies: any[]; origins: any[] } {
  if (!sessionId || typeof sessionId !== 'string' || !sessionId.trim()) {
    throw new Error('TikTok sessionid is required');
  }

  const cleanSessionId = sessionId.trim();
  const expires = Math.floor(Date.now() / 1000) + 365 * 24 * 3600;

  return {
    cookies: [
      {
        name: 'sessionid',
        value: cleanSessionId,
        domain: '.tiktok.com',
        path: '/',
        expires,
        httpOnly: true,
        secure: true,
        sameSite: 'None'
      },
      {
        name: 'sessionid_ss',
        value: cleanSessionId,
        domain: '.tiktok.com',
        path: '/',
        expires,
        httpOnly: true,
        secure: true,
        sameSite: 'None'
      }
    ],
    origins: []
  };
}

/**
 * Parses raw Cookie header string (e.g. "auth_token=abc; ct0=xyz") into key-value pairs.
 */
export function parseCookieHeader(headerStr: string): Record<string, string> {
  const result: Record<string, string> = {};
  const parts = headerStr.split(';');
  for (const part of parts) {
    const eqIdx = part.indexOf('=');
    if (eqIdx !== -1) {
      const k = part.slice(0, eqIdx).trim();
      const v = part.slice(eqIdx + 1).trim();
      if (k && v) {
        result[k] = v;
      }
    }
  }
  return result;
}

/**
 * Parses raw JSON cookie export (e.g. Cookie-Editor format or Playwright storageState format).
 */
export function parseCookieExport(input: string): { cookies: any[]; origins: any[] } {
  let parsed: any;
  try {
    parsed = JSON.parse(input);
  } catch (err: any) {
    throw new Error(`Invalid JSON cookie export: ${err.message}`);
  }

  let cookieArray: any[] = [];
  let originsArray: any[] = [];

  if (Array.isArray(parsed)) {
    cookieArray = parsed;
  } else if (parsed && typeof parsed === 'object') {
    if (Array.isArray(parsed.cookies)) {
      cookieArray = parsed.cookies;
      originsArray = Array.isArray(parsed.origins) ? parsed.origins : [];
    } else {
      throw new Error('JSON object must contain a "cookies" array');
    }
  } else {
    throw new Error('Cookie data must be a JSON array or object with "cookies"');
  }

  const normalizedCookies = cookieArray
    .map((c) => {
      let sameSite: 'Strict' | 'Lax' | 'None' = 'None';
      if (typeof c.sameSite === 'string') {
        const lower = c.sameSite.toLowerCase();
        if (lower === 'strict') sameSite = 'Strict';
        else if (lower === 'lax') sameSite = 'Lax';
      }

      let expires = -1;
      if (typeof c.expirationDate === 'number') {
        expires = Math.floor(c.expirationDate);
      } else if (typeof c.expires === 'number') {
        expires = Math.floor(c.expires);
      }

      return {
        name: String(c.name || ''),
        value: String(c.value || ''),
        domain: String(c.domain || ''),
        path: String(c.path || '/'),
        expires,
        httpOnly: Boolean(c.httpOnly),
        secure: typeof c.secure === 'boolean' ? c.secure : true,
        sameSite
      };
    })
    .filter((c) => c.name && c.value);

  return {
    cookies: normalizedCookies,
    origins: originsArray
  };
}

export const SessionManager = {
  verifySession,
  saveSession,
  getSessionPath,
  getSessionsDir,
  sanitizePlatform,
  isValidPlatform,
  SUPPORTED_PLATFORMS,
  buildTwitterStorageState,
  buildTikTokStorageState,
  parseCookieHeader,
  parseCookieExport
};

