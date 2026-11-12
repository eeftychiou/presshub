import request from 'supertest';
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';
import { createApp } from '../src/app';
import { WorkerLogger } from '../src/logger';
import { safeCompare, createAuthMiddleware } from '../src/auth';
import {
  verifySession,
  saveSession,
  getSessionPath,
  sanitizePlatform,
  SUPPORTED_PLATFORMS
} from '../src/automation/session-manager';
import {
  generateSocialCard,
  escapeXml,
  wrapText,
  clampDimension,
  getMediaAssetPath,
  fetchImageBuffer
} from '../src/media/card-generator';
import {
  generateSocialVideo,
  clampEven,
  clampDuration,
  probeAudioDuration,
  TIKTOK_MAX_DURATION
} from '../src/media/video-generator';
import { PostOptions, PostResult } from '../src/automation/posters';
import sharp from 'sharp';

const TEST_SECRET = 'test-worker-secret-xyz-12345';
const TEST_DIR = path.resolve(__dirname, 'tmp-test');
const TEST_SESSIONS_DIR = path.join(TEST_DIR, 'sessions');
const TEST_OUTPUT_DIR = path.join(TEST_DIR, 'output');

describe('PressHub Social Media Worker Suite', () => {
  beforeAll(() => {
    fs.mkdirSync(TEST_SESSIONS_DIR, { recursive: true });
    fs.mkdirSync(TEST_OUTPUT_DIR, { recursive: true });
  });

  afterAll(() => {
    try {
      fs.rmSync(TEST_DIR, { recursive: true, force: true });
    } catch {
      // Ignore cleanup error on windows locks
    }
  });

  describe('1. Authentication Middleware & Cryptographic Security', () => {
    test('safeCompare accurately matches identical strings and rejects mismatches', () => {
      expect(safeCompare('secret-key-1', 'secret-key-1')).toBe(true);
      expect(safeCompare('secret-key-1', 'secret-key-2')).toBe(false);
      expect(safeCompare('', 'secret-key-1')).toBe(false);
      expect(safeCompare('short', 'much-longer-secret-token')).toBe(false);
    });

    test('rejects request with 401 when no auth header is provided', async () => {
      const app = createApp({ secret: TEST_SECRET });
      const res = await request(app).get('/health');
      expect(res.status).toBe(401);
      expect(res.body.success).toBe(false);
      expect(res.body.error).toMatch(/Unauthorized/);
    });

    test('rejects request with 401 when incorrect secret is provided', async () => {
      const app = createApp({ secret: TEST_SECRET });
      const res = await request(app)
        .get('/health')
        .set('Authorization', 'Bearer wrong-secret');
      expect(res.status).toBe(401);
      expect(res.body.success).toBe(false);
    });

    test('accepts request with 200 when valid Bearer token is provided', async () => {
      const app = createApp({ secret: TEST_SECRET });
      const res = await request(app)
        .get('/health')
        .set('Authorization', `Bearer ${TEST_SECRET}`);
      expect(res.status).toBe(200);
      expect(res.body.success).toBe(true);
      expect(res.body.status).toBe('ok');
      expect(res.body.version).toBe('1.0.0');
    });

    test('accepts request with 200 when valid X-PressHub-Secret header is provided', async () => {
      const app = createApp({ secret: TEST_SECRET });
      const res = await request(app)
        .get('/health')
        .set('X-PressHub-Secret', TEST_SECRET);
      expect(res.status).toBe(200);
      expect(res.body.success).toBe(true);
      expect(res.body.status).toBe('ok');
    });
  });

  describe('2. Session Manager & Security Gates', () => {
    test('rejects invalid platforms and directory traversal attacks', () => {
      expect(() => sanitizePlatform('../etc/passwd')).toThrow(/Unsupported platform/);
      expect(() => sanitizePlatform('malicious/path')).toThrow(/Unsupported platform/);
      expect(() => sanitizePlatform('unknown-platform')).toThrow(/Unsupported platform/);

      const verification = verifySession('../../danger', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(false);
      expect(verification.reason).toMatch(/Unsupported platform/);
    });

    test('detects missing session file', () => {
      const verification = verifySession('twitter', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(false);
      expect(verification.reason).toMatch(/Session file not found/);
    });

    test('detects corrupted session JSON', () => {
      const sessionPath = getSessionPath('facebook', TEST_SESSIONS_DIR);
      fs.writeFileSync(sessionPath, 'invalid-json-content', 'utf-8');

      const verification = verifySession('facebook', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(false);
      expect(verification.reason).toMatch(/Corrupted session JSON/);
      fs.unlinkSync(sessionPath);
    });

    test('detects expired session cookies', () => {
      const pastTimestamp = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago
      const expiredSession = {
        cookies: [
          { name: 'auth_token', value: 'xyz', expires: pastTimestamp, domain: '.twitter.com' },
          { name: 'ct0', value: 'csrf', expires: pastTimestamp - 100, domain: '.twitter.com' }
        ]
      };
      saveSession('twitter', expiredSession, TEST_SESSIONS_DIR);

      const verification = verifySession('twitter', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(false);
      expect(verification.reason).toMatch(/expired/i);
      expect(verification.cookieCount).toBe(2);
    });

    test('validates session with active and non-expiring cookies', () => {
      const futureTimestamp = Math.floor(Date.now() / 1000) + 86400 * 30; // 30 days
      const validSession = {
        cookies: [
          { name: 'session_id', value: 'abc', expires: -1, domain: '.tiktok.com' },
          { name: 'token', value: '123', expires: futureTimestamp, domain: '.tiktok.com' }
        ]
      };
      saveSession('tiktok', validSession, TEST_SESSIONS_DIR);

      const verification = verifySession('tiktok', TEST_SESSIONS_DIR);
      expect(verification.valid).toBe(true);
      expect(verification.cookieCount).toBe(2);
    });
  });

  describe('3. Card Generator & Sharp Rendering', () => {
    test('escapes XML characters to prevent SVG malformation', () => {
      const raw = '<Script & "Test" \'Alert\'>';
      const escaped = escapeXml(raw);
      expect(escaped).toBe('&lt;Script &amp; &quot;Test&quot; &apos;Alert&apos;&gt;');
    });

    test('wraps text and clamps dimensions properly', () => {
      const lines = wrapText('This is a headline about breaking news developments in international commerce', 24, 3);
      expect(lines.length).toBeGreaterThan(1);
      expect(clampDimension(400, 1080)).toBe(600);
      expect(clampDimension(3000, 1080)).toBe(2160);
      expect(clampDimension(undefined, 1080)).toBe(1080);
    });

    test('generates a valid branded PNG file with sharp', async () => {
      const result = await generateSocialCard({
        title: 'Historic Discovery: Breakthrough in Clean Energy Production',
        subtitle: 'Researchers at the National Institute confirm sustainable net energy gain.',
        category: 'TECHNOLOGY',
        siteName: 'PressHub World',
        width: 1080,
        height: 1080,
        outputDir: TEST_OUTPUT_DIR
      });

      expect(fs.existsSync(result.filePath)).toBe(true);
      expect(result.width).toBe(1080);
      expect(result.height).toBe(1080);
      expect(result.sizeBytes).toBeGreaterThan(1000);

      // Verify PNG magic bytes
      const fileBuffer = fs.readFileSync(result.filePath);
      const isPng =
        fileBuffer[0] === 0x89 &&
        fileBuffer[1] === 0x50 &&
        fileBuffer[2] === 0x4e &&
        fileBuffer[3] === 0x47;
      expect(isPng).toBe(true);

      // Verify image dimensions with sharp metadata
      const metadata = await sharp(result.filePath).metadata();
      expect(metadata.width).toBe(1080);
      expect(metadata.height).toBe(1080);
      expect(metadata.format).toBe('png');
    });

    test('generates card with custom background image buffer', async () => {
      const bgBuffer = await sharp({
        create: {
          width: 800,
          height: 800,
          channels: 3,
          background: { r: 40, g: 80, b: 120 }
        }
      }).jpeg().toBuffer();

      const result = await generateSocialCard({
        title: 'Custom Background Post',
        category: 'FEATURE',
        width: 1200,
        height: 1200,
        backgroundBuffer: bgBuffer,
        outputDir: TEST_OUTPUT_DIR
      });

      expect(fs.existsSync(result.filePath)).toBe(true);
      expect(result.width).toBe(1200);
      expect(result.height).toBe(1200);
    });

    test('getMediaAssetPath locates existing media files and returns null for missing ones', () => {
      const icon = getMediaAssetPath('cropped-PressHub-Cy-ICON.png');
      expect(icon).not.toBeNull();
      expect(fs.existsSync(icon!)).toBe(true);

      const mic = getMediaAssetPath('PressHub.cy Mic.png');
      expect(mic).not.toBeNull();
      expect(fs.existsSync(mic!)).toBe(true);

      const missing = getMediaAssetPath('non-existent-image-12345.png');
      expect(missing).toBeNull();
    });

    test('generates vertical card compositing mic and logo icons with website branding', async () => {
      const result = await generateSocialCard({
        title: 'Morning Briefing & Analysis Podcast',
        subtitle: 'Key political and economic updates of the day',
        category: 'PODCAST',
        siteName: 'PressHub.Cy',
        width: 600,
        height: 960,
        isPodcast: true,
        outputDir: TEST_OUTPUT_DIR
      });

      expect(fs.existsSync(result.filePath)).toBe(true);
      expect(result.width).toBe(600);
      expect(result.height).toBe(960);
      expect(result.sizeBytes).toBeGreaterThan(1000);
    });

    test('an explicit isPodcast: false wins over "podcast" in the title (TikTok short hooks)', async () => {
      const spy = jest.spyOn(WorkerLogger, 'debug');
      await generateSocialCard({
        title: 'Τι είπε το podcast για τον μισθό',
        category: 'ΕΙΔΗΣΗ 1/3',
        width: 600,
        height: 960,
        isPodcast: false,
        outputDir: TEST_OUTPUT_DIR
      });
      const layout = spy.mock.calls.find((c) => String(c[1]).includes('Building SVG layout'));
      spy.mockRestore();
      expect(layout).toBeDefined();
      expect((layout![2] as any).isPodcast).toBe(false);
    });
  });

  describe('4. Video Generator & FFmpeg Pipeline', () => {
    test('clampEven ensures dimensions are even integers and clampDuration bounds duration', () => {
      expect(clampEven(1079, 1080, 540, 2160) % 2).toBe(0);
      expect(clampEven(1081, 1080, 540, 2160) % 2).toBe(0);
      expect(clampDuration(2)).toBe(5);
      expect(clampDuration(100)).toBe(60);
      expect(clampDuration(15)).toBe(15);
    });

    test('probeAudioDuration returns null for non-existent file', async () => {
      const duration = await probeAudioDuration('/path/to/non-existent-file.mp3');
      expect(duration).toBeNull();
    });

    test('generates vertical MP4 video with silent audio track from card', async () => {
      const videoResult = await generateSocialVideo({
        title: 'Breaking Video Story for TikTok & Reels',
        subtitle: 'Automated video generation test',
        category: 'PRESS BRIEFING',
        width: 720,
        height: 1280,
        duration: 5,
        outputDir: TEST_OUTPUT_DIR
      });

      expect(fs.existsSync(videoResult.filePath)).toBe(true);
      expect(videoResult.width).toBe(720);
      expect(videoResult.height).toBe(1280);
      expect(videoResult.duration).toBe(5);

      const stats = fs.statSync(videoResult.filePath);
      expect(stats.size).toBeGreaterThan(5000); // Has both video and audio streams
    }, 45000);

    test('probes audio duration and generates video matching full audio track duration', async () => {
      // 1. Create a 7-second audio file using ffmpeg installer
      const ffmpegInstaller = require('@ffmpeg-installer/ffmpeg');
      const { execFileSync } = require('child_process');
      const testAudioPath = path.join(TEST_OUTPUT_DIR, 'test-podcast-7s.wav');

      execFileSync(ffmpegInstaller.path, [
        '-f', 'lavfi',
        '-i', 'sine=frequency=440:duration=7',
        '-y',
        testAudioPath
      ]);

      expect(fs.existsSync(testAudioPath)).toBe(true);

      // 2. Probe audio duration
      const probed = await probeAudioDuration(testAudioPath);
      expect(probed).toBeGreaterThanOrEqual(6.9);
      expect(probed).toBeLessThanOrEqual(7.2);

      // 3. Generate social video with attached audio track
      // Pass duration: 10 (the default still duration) to verify it is overridden by the full probed audio duration!
      const videoResult = await generateSocialVideo({
        title: 'Podcast Episode 42 Audio Test',
        subtitle: 'Full audio duration test',
        audioPath: testAudioPath,
        width: 720,
        height: 1280,
        duration: 10,
        outputDir: TEST_OUTPUT_DIR
      });

      expect(fs.existsSync(videoResult.filePath)).toBe(true);
      // Duration should match full audio (Math.ceil(7) = 7s) instead of 10s default
      expect(videoResult.duration).toBe(7);
    }, 45000);

    test('TIKTOK_MAX_DURATION constant is set to 3600s (60 min) limit', () => {
      expect(TIKTOK_MAX_DURATION).toBe(3600);
    });
  });

  describe('5. Express HTTP Endpoints', () => {
    const mockPosterHandler = jest.fn(async (opts: PostOptions): Promise<PostResult> => {
      return {
        success: true,
        platform: opts.platform,
        externalPostId: `mock-${opts.platform}-999`,
        externalPostUrl: `https://${opts.platform}.com/status/999`,
        mediaPath: opts.mediaPath
      };
    });

    const app = createApp({
      secret: TEST_SECRET,
      posterHandler: mockPosterHandler,
      sessionsDir: TEST_SESSIONS_DIR,
      outputDir: TEST_OUTPUT_DIR
    });

    test('POST /verify-session validates platform parameter and checks session', async () => {
      const badReq = await request(app)
        .post('/verify-session')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({ platform: 'unsupported_platform' });
      expect(badReq.status).toBe(400);

      const okReq = await request(app)
        .post('/verify-session')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({ platform: 'tiktok' });
      expect(okReq.status).toBe(200);
      expect(okReq.body.success).toBe(true);
      expect(okReq.body.session.platform).toBe('tiktok');
    });

    test('POST /generate-card validates body and generates image', async () => {
      const missingTitle = await request(app)
        .post('/generate-card')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({});
      expect(missingTitle.status).toBe(400);

      const validCard = await request(app)
        .post('/generate-card')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({
          title: 'HTTP Card Generation Test',
          subtitle: 'Generated via REST API endpoint',
          category: 'API_TEST',
          width: 800,
          height: 800
        });
      expect(validCard.status).toBe(200);
      expect(validCard.body.success).toBe(true);
      expect(validCard.body.width).toBe(800);
      expect(validCard.body.height).toBe(800);
      expect(fs.existsSync(validCard.body.filePath)).toBe(true);
    });

    test('POST /generate-video creates video via API endpoint', async () => {
      const res = await request(app)
        .post('/generate-video')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({
          title: 'HTTP Video Generation Test',
          width: 540,
          height: 960,
          duration: 5
        });
      expect(res.status).toBe(200);
      expect(res.body.success).toBe(true);
      expect(res.body.width).toBe(540);
      expect(res.body.height).toBe(960);
      expect(fs.existsSync(res.body.filePath)).toBe(true);
    }, 45000);

    test('POST /dispatch validates inputs and executes poster handler', async () => {
      const missingFields = await request(app)
        .post('/dispatch')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({ platform: 'twitter' });
      expect(missingFields.status).toBe(400);

      const res = await request(app)
        .post('/dispatch')
        .set('Authorization', `Bearer ${TEST_SECRET}`)
        .send({
          platform: 'twitter',
          copy: 'Check out this automated update! #presshub',
          autoGenerateMedia: true,
          title: 'Automated Tweet with Card'
        });

      expect(res.status).toBe(200);
      expect(res.body.success).toBe(true);
      expect(res.body.platform).toBe('twitter');
      expect(res.body.externalPostId).toBe('mock-twitter-999');
      expect(res.body.mediaPath).toBeDefined();
      expect(mockPosterHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          platform: 'twitter',
          copy: 'Check out this automated update! #presshub'
        })
      );
    });
  });
});
