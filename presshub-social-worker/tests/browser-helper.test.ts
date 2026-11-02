import fs from 'fs';
import path from 'path';
import {
  STEALTH_ARGS,
  STEALTH_INIT_SCRIPT,
  REAL_CHROME_USER_AGENT,
  isCdpAvailable,
  findChromeExecutable,
  connectOrLaunchChrome,
  buildTwitterStorageState,
  buildTikTokStorageState,
  parseCookieExport,
  parseCookieHeader
} from '../src/automation/browser-helper';
import {
  saveSession,
  verifySession,
  SessionManager
} from '../src/automation/session-manager';

const TEST_DIR = path.resolve(__dirname, 'tmp-stealth-test');
const TEST_SESSIONS_DIR = path.join(TEST_DIR, 'sessions');

describe('Browser Helper, Stealth & Session Builders Suite', () => {
  beforeAll(() => {
    fs.mkdirSync(TEST_SESSIONS_DIR, { recursive: true });
  });

  afterAll(() => {
    try {
      fs.rmSync(TEST_DIR, { recursive: true, force: true });
    } catch {}
  });

  describe('1. Stealth Configuration & Environment Constants', () => {
    test('STEALTH_ARGS includes anti-detection and initialization flags', () => {
      expect(STEALTH_ARGS).toContain('--disable-blink-features=AutomationControlled');
      expect(STEALTH_ARGS).toContain('--no-first-run');
      expect(STEALTH_ARGS).toContain('--no-default-browser-check');
    });

    test('STEALTH_INIT_SCRIPT defines navigator.webdriver override', () => {
      expect(STEALTH_INIT_SCRIPT).toContain('navigator');
      expect(STEALTH_INIT_SCRIPT).toContain('webdriver');
      expect(STEALTH_INIT_SCRIPT).toContain('undefined');
    });

    test('REAL_CHROME_USER_AGENT matches genuine Chrome user-agent without Headless', () => {
      expect(REAL_CHROME_USER_AGENT).toMatch(/Chrome\/\d+/);
      expect(REAL_CHROME_USER_AGENT).not.toContain('HeadlessChrome');
    });

    test('findChromeExecutable detects Chrome or honors PRESSHUB_CHROME_BIN override', () => {
      const detected = findChromeExecutable();
      // On Windows development machine, Google Chrome is installed
      if (process.platform === 'win32' && fs.existsSync('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
        expect(detected).toBe('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe');
      }

      const customBin = path.join(TEST_DIR, 'custom-chrome.exe');
      fs.writeFileSync(customBin, 'fake-binary');
      process.env.PRESSHUB_CHROME_BIN = customBin;
      expect(findChromeExecutable()).toBe(customBin);
      delete process.env.PRESSHUB_CHROME_BIN;
    });

    test('isCdpAvailable returns false for inactive ports quickly', async () => {
      const startTime = Date.now();
      const available = await isCdpAvailable('http://127.0.0.1:54321', 300);
      const duration = Date.now() - startTime;

      expect(available).toBe(false);
      expect(duration).toBeLessThan(1500);
    });
  });

  describe('2. Twitter / X Session Builder (buildTwitterStorageState)', () => {
    test('throws error if auth_token is empty or whitespace', () => {
      expect(() => buildTwitterStorageState('')).toThrow(/Twitter auth_token is required/);
      expect(() => buildTwitterStorageState('   ')).toThrow(/Twitter auth_token is required/);
    });

    test('builds valid storageState with auth_token for .x.com and .twitter.com', () => {
      const state = buildTwitterStorageState('mock_auth_token_12345');
      expect(state).toHaveProperty('cookies');
      expect(state).toHaveProperty('origins');
      expect(state.cookies).toHaveLength(2);

      const xCookie = state.cookies.find((c) => c.domain === '.x.com' && c.name === 'auth_token');
      const twCookie = state.cookies.find(
        (c) => c.domain === '.twitter.com' && c.name === 'auth_token'
      );

      expect(xCookie).toBeDefined();
      expect(xCookie?.value).toBe('mock_auth_token_12345');
      expect(xCookie?.secure).toBe(true);
      expect(xCookie?.httpOnly).toBe(true);
      expect(xCookie?.sameSite).toBe('None');

      expect(twCookie).toBeDefined();
      expect(twCookie?.value).toBe('mock_auth_token_12345');

      const nowSec = Math.floor(Date.now() / 1000);
      expect(xCookie?.expires).toBeGreaterThan(nowSec + 300 * 86400); // ~1 year in future
    });

    test('includes ct0 CSRF cookie when provided', () => {
      const state = buildTwitterStorageState('mock_auth_token_12345', 'mock_ct0_csrf_secret');
      expect(state.cookies).toHaveLength(4);

      const ct0X = state.cookies.find((c) => c.domain === '.x.com' && c.name === 'ct0');
      const ct0Tw = state.cookies.find((c) => c.domain === '.twitter.com' && c.name === 'ct0');

      expect(ct0X).toBeDefined();
      expect(ct0X?.value).toBe('mock_ct0_csrf_secret');
      expect(ct0X?.httpOnly).toBe(false);
      expect(ct0X?.secure).toBe(true);
      expect(ct0X?.sameSite).toBe('None');

      expect(ct0Tw).toBeDefined();
      expect(ct0Tw?.value).toBe('mock_ct0_csrf_secret');
    });

    test('generated Twitter session passes verifySession', () => {
      const state = buildTwitterStorageState('token_abc', 'ct0_xyz');
      saveSession('twitter', state, TEST_SESSIONS_DIR);

      const verification = verifySession('twitter', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(true);
      expect(verification.cookieCount).toBe(4);
    });
  });

  describe('3. TikTok Session Builder (buildTikTokStorageState)', () => {
    test('throws error if sessionid is empty or whitespace', () => {
      expect(() => buildTikTokStorageState('')).toThrow(/TikTok sessionid is required/);
      expect(() => buildTikTokStorageState('   ')).toThrow(/TikTok sessionid is required/);
    });

    test('builds valid storageState with sessionid and sessionid_ss for .tiktok.com', () => {
      const state = buildTikTokStorageState('mock_tiktok_session_999');
      expect(state.cookies).toHaveLength(2);

      const sid = state.cookies.find((c) => c.name === 'sessionid');
      const sidSs = state.cookies.find((c) => c.name === 'sessionid_ss');

      expect(sid).toBeDefined();
      expect(sid?.value).toBe('mock_tiktok_session_999');
      expect(sid?.domain).toBe('.tiktok.com');
      expect(sid?.httpOnly).toBe(true);
      expect(sid?.secure).toBe(true);
      expect(sid?.sameSite).toBe('None');

      expect(sidSs).toBeDefined();
      expect(sidSs?.value).toBe('mock_tiktok_session_999');

      const nowSec = Math.floor(Date.now() / 1000);
      expect(sid?.expires).toBeGreaterThan(nowSec + 300 * 86400);
    });

    test('generated TikTok session passes verifySession', () => {
      const state = buildTikTokStorageState('valid_session_123');
      saveSession('tiktok', state, TEST_SESSIONS_DIR);

      const verification = verifySession('tiktok', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(true);
      expect(verification.cookieCount).toBe(2);
    });
  });

  describe('4. Cookie String and JSON Export Parsers', () => {
    test('parseCookieHeader parses cookie header string into key-value map', () => {
      const header = 'auth_token=secret123; ct0=csrf456; guest_id=v1%3A999;';
      const parsed = parseCookieHeader(header);

      expect(parsed['auth_token']).toBe('secret123');
      expect(parsed['ct0']).toBe('csrf456');
      expect(parsed['guest_id']).toBe('v1%3A999');
    });

    test('parseCookieExport parses Cookie-Editor JSON array format', () => {
      const jsonStr = JSON.stringify([
        {
          name: 'auth_token',
          value: 'ext_token_777',
          domain: '.x.com',
          path: '/',
          secure: true,
          httpOnly: true,
          sameSite: 'no_restriction',
          expirationDate: 1800000000
        },
        {
          name: 'ct0',
          value: 'ext_ct0_888',
          domain: '.x.com',
          path: '/',
          secure: true,
          httpOnly: false,
          sameSite: 'lax',
          expires: 1800000000
        }
      ]);

      const state = parseCookieExport(jsonStr);
      expect(state.cookies).toHaveLength(2);
      expect(state.cookies[0].name).toBe('auth_token');
      expect(state.cookies[0].value).toBe('ext_token_777');
      expect(state.cookies[0].sameSite).toBe('None');
      expect(state.cookies[0].expires).toBe(1800000000);

      expect(state.cookies[1].name).toBe('ct0');
      expect(state.cookies[1].sameSite).toBe('Lax');
    });

    test('parseCookieExport parses standard Playwright storageState JSON object', () => {
      const storageState = JSON.stringify({
        cookies: [
          {
            name: 'sessionid',
            value: 'sess_123',
            domain: '.tiktok.com',
            path: '/',
            expires: 1900000000,
            httpOnly: true,
            secure: true,
            sameSite: 'None'
          }
        ],
        origins: [{ origin: 'https://www.tiktok.com', localStorage: [] }]
      });

      const parsed = parseCookieExport(storageState);
      expect(parsed.cookies).toHaveLength(1);
      expect(parsed.cookies[0].name).toBe('sessionid');
      expect(parsed.origins).toHaveLength(1);
    });

    test('parseCookieExport rejects invalid JSON or unexpected schema', () => {
      expect(() => parseCookieExport('invalid-json')).toThrow(/Invalid JSON/);
      expect(() => parseCookieExport(JSON.stringify({ notCookies: true }))).toThrow(
        /must contain a "cookies" array/
      );
    });
  });

  describe('5. connectOrLaunchChrome Integration & Stealth Context', () => {
    test('launches browser context with stealth args and navigator.webdriver overridden', async () => {
      const connection = await connectOrLaunchChrome({
        headless: true
      });

      expect(connection.isExternalCdp).toBe(false);
      expect(connection.context).toBeDefined();

      const page = await connection.context.newPage();
      await page.goto('about:blank');

      // Verify stealth init script removed navigator.webdriver
      const webdriverValue = await page.evaluate(() => (globalThis as any).navigator?.webdriver);
      expect(webdriverValue).toBeUndefined();

      await page.close();
      await connection.close();
    }, 20000);

    test('loads saved storageState into context', async () => {
      const sessionPath = path.join(TEST_SESSIONS_DIR, 'test-load-session.json');
      const state = buildTwitterStorageState('preload_token_999');
      fs.writeFileSync(sessionPath, JSON.stringify(state));

      const connection = await connectOrLaunchChrome({
        headless: true,
        storageState: sessionPath
      });

      const cookies = await connection.context.cookies(['https://x.com']);
      const authToken = cookies.find((c) => c.name === 'auth_token');
      expect(authToken).toBeDefined();
      expect(authToken?.value).toBe('preload_token_999');

      await connection.close();
    }, 20000);
  });
});
