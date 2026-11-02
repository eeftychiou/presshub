import fs from 'fs';
import path from 'path';
import { parseEnv, loadEnv, getWorkerConfig } from '../src/config';
import { PressHubPoller, ClaimedJob } from '../src/poller';
import { SessionManager, saveSession } from '../src/automation/session-manager';
import { PostOptions, PostResult } from '../src/automation/posters';

const TEST_DIR = path.resolve(__dirname, 'tmp-poller-test');
const TEST_SESSIONS_DIR = path.join(TEST_DIR, 'sessions');

describe('Pull Mode Poller & .env Loader Suite', () => {
  beforeAll(() => {
    fs.mkdirSync(TEST_SESSIONS_DIR, { recursive: true });
  });

  afterAll(() => {
    try {
      fs.rmSync(TEST_DIR, { recursive: true, force: true });
    } catch {
      // Ignore cleanup error on windows locks
    }
  });

  describe('1. .env File Parser & Config Loader', () => {
    test('parseEnv parses key-value pairs, comments, empty lines, and quotes', () => {
      const sampleEnv = `
        # Comment line
        PRESSHUB_WP_URL=https://presshub.cy
        PRESSHUB_WORKER_SECRET="secret-value-123" # inline comment
        SINGLE_QUOTED='hello world'
        WITH_ESCAPES="line1\\nline2"
        EMPTY_VALUE=
        INVALID_LINE_WITHOUT_EQUALS
      `;

      const parsed = parseEnv(sampleEnv);
      expect(parsed.PRESSHUB_WP_URL).toBe('https://presshub.cy');
      expect(parsed.PRESSHUB_WORKER_SECRET).toBe('secret-value-123');
      expect(parsed.SINGLE_QUOTED).toBe('hello world');
      expect(parsed.WITH_ESCAPES).toBe('line1\nline2');
      expect(parsed.EMPTY_VALUE).toBe('');
      expect(parsed.INVALID_LINE_WITHOUT_EQUALS).toBeUndefined();
    });

    test('loadEnv loads file content into process.env without overwriting existing keys', () => {
      const testEnvFile = path.join(TEST_DIR, '.test.env');
      fs.writeFileSync(
        testEnvFile,
        'TEST_PRE_EXISTING=new-val\nTEST_NEW_KEY=loaded-val\nPRESSHUB_WP_URL=https://presshub.test',
        'utf-8'
      );

      process.env.TEST_PRE_EXISTING = 'original-val';
      delete process.env.TEST_NEW_KEY;

      const loaded = loadEnv(testEnvFile);

      expect(process.env.TEST_PRE_EXISTING).toBe('original-val');
      expect(process.env.TEST_NEW_KEY).toBe('loaded-val');
      expect(loaded.TEST_NEW_KEY).toBe('loaded-val');
      expect(loaded.TEST_PRE_EXISTING).toBeUndefined();

      delete process.env.TEST_PRE_EXISTING;
      delete process.env.TEST_NEW_KEY;
    });

    test('loadEnv safely handles missing file', () => {
      const loaded = loadEnv(path.join(TEST_DIR, 'non-existent.env'));
      expect(loaded).toEqual({});
    });

    test('getWorkerConfig returns default port and interval when not specified', () => {
      const originalPort = process.env.PRESSHUB_WORKER_PORT;
      const originalInterval = process.env.PRESSHUB_POLL_INTERVAL_MS;

      delete process.env.PRESSHUB_WORKER_PORT;
      delete process.env.PRESSHUB_POLL_INTERVAL_MS;

      const config = getWorkerConfig();
      expect(config.port).toBe(3456);
      expect(config.pollIntervalMs).toBe(30000);

      if (originalPort) process.env.PRESSHUB_WORKER_PORT = originalPort;
      if (originalInterval) process.env.PRESSHUB_POLL_INTERVAL_MS = originalInterval;
    });
  });

  describe('2. PressHubPoller Initialization & Validation', () => {
    test('throws if wpUrl or workerSecret is missing', () => {
      expect(() => new PressHubPoller({ wpUrl: '', workerSecret: 'sec' })).toThrow(/wpUrl is required/);
      expect(() => new PressHubPoller({ wpUrl: 'https://presshub.cy', workerSecret: '' })).toThrow(
        /workerSecret is required/
      );
    });

    test('normalizes wpUrl and sets custom pollIntervalMs', () => {
      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy///',
        workerSecret: 'sec-123',
        pollIntervalMs: 15000
      });

      expect(poller.wpUrl).toBe('https://presshub.cy');
      expect(poller.workerSecret).toBe('sec-123');
      expect(poller.pollIntervalMs).toBe(15000);
    });
  });

  describe('3. getSessionStatuses() Platform Checks', () => {
    test('returns boolean status for twitter, tiktok, facebook, instagram', () => {
      // Create a valid session for twitter
      saveSession(
        'twitter',
        {
          cookies: [{ name: 'auth_token', value: 'xyz', expires: Math.floor(Date.now() / 1000) + 3600 }]
        },
        TEST_SESSIONS_DIR
      );

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'secret-key',
        sessionsDir: TEST_SESSIONS_DIR
      });

      const statuses = poller.getSessionStatuses();
      expect(statuses).toHaveProperty('twitter');
      expect(statuses).toHaveProperty('tiktok');
      expect(statuses).toHaveProperty('facebook');
      expect(statuses).toHaveProperty('instagram');

      expect(statuses.twitter).toBe(true);
      expect(statuses.tiktok).toBe(false); // No session file
      expect(statuses.facebook).toBe(false);
      expect(statuses.instagram).toBe(false);
    });
  });

  describe('4. pollOnce() Job Claiming & Execution Flow', () => {
    test('handles non-OK response from claim endpoint', async () => {
      const mockFetch: typeof fetch = jest.fn().mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error'
      } as any);

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'secret-key',
        fetchFn: mockFetch
      });

      const stats = await poller.pollOnce();
      expect(stats).toEqual({ claimed: 0, succeeded: 0, failed: 0 });
      expect(mockFetch).toHaveBeenCalledTimes(1);
    });

    test('handles invalid JSON or missing jobs array', async () => {
      const mockFetch: typeof fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ status: 'ok' }) // missing jobs
      } as any);

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'secret-key',
        fetchFn: mockFetch
      });

      const stats = await poller.pollOnce();
      expect(stats).toEqual({ claimed: 0, succeeded: 0, failed: 0 });
    });

    test('successfully processes claimed jobs, generates media, and reports completion', async () => {
      const capturedRequests: Array<{ url: string; options: any }> = [];

      const mockFetch: typeof fetch = jest.fn().mockImplementation(async (url: string, options: any) => {
        capturedRequests.push({ url, options });

        if (url.endsWith('/worker/claim')) {
          const body = JSON.parse(options.body);
          expect(body).toHaveProperty('sessions');
          expect(body.limit).toBe(5);

          return {
            ok: true,
            json: async () => ({
              success: true,
              jobs: [
                {
                  id: 101,
                  platform: 'twitter',
                  post_id: 42,
                  post_title: 'Breaking News',
                  post_copy: 'Latest update on technology breakthroughs.'
                },
                {
                  id: 102,
                  platform: 'tiktok',
                  post_id: 43,
                  post_title: 'TikTok Viral Story',
                  post_copy: 'Check out this awesome story!',
                  specs: {
                    tiktok_video_duration: 12,
                    tiktok_video_width: 1080,
                    tiktok_video_height: 1920
                  }
                },
                {
                  id: 103,
                  platform: 'instagram',
                  post_id: 44,
                  post_title: 'Instagram Photo Story',
                  post_copy: 'Branded photo post copy',
                  site_name: 'PressHub News',
                  specs: {
                    instagram_card_width: 1080,
                    instagram_card_height: 1080
                  }
                }
              ]
            })
          } as any;
        }

        if (url.endsWith('/worker/complete')) {
          return {
            ok: true,
            json: async () => ({ success: true })
          } as any;
        }

        return { ok: false, status: 404 } as any;
      });

      const mockPoster: (options: PostOptions) => Promise<PostResult> = jest.fn().mockImplementation(async (options: PostOptions) => {
        return {
          success: true,
          platform: options.platform,
          externalPostId: `ext-${options.platform}-777`,
          externalPostUrl: `https://${options.platform}.com/post/ext-${options.platform}-777`,
          mediaPath: options.mediaPath
        };
      });

      const mockGenerateCard = jest.fn().mockResolvedValue({
        filePath: '/tmp/generated-card.png',
        width: 1080,
        height: 1080,
        sizeBytes: 12345
      });

      const mockGenerateVideo = jest.fn().mockResolvedValue({
        filePath: '/tmp/generated-video.mp4',
        width: 1080,
        height: 1920,
        duration: 12
      });

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'test-secret-token',
        fetchFn: mockFetch,
        posterHandler: mockPoster,
        generateCardFn: mockGenerateCard,
        generateVideoFn: mockGenerateVideo
      });

      const stats = await poller.pollOnce();

      expect(stats.claimed).toBe(3);
      expect(stats.succeeded).toBe(3);
      expect(stats.failed).toBe(0);

      // Verify media generation calls
      expect(mockGenerateVideo).toHaveBeenCalledTimes(1);
      expect(mockGenerateVideo).toHaveBeenCalledWith(
        expect.objectContaining({
          postId: 43,
          title: 'TikTok Viral Story',
          durationSec: 12
        })
      );

      expect(mockGenerateCard).toHaveBeenCalledTimes(1);
      expect(mockGenerateCard).toHaveBeenCalledWith(
        expect.objectContaining({
          postId: 44,
          title: 'Instagram Photo Story',
          siteName: 'PressHub News'
        })
      );

      // Verify poster calls
      expect(mockPoster).toHaveBeenCalledTimes(3);

      // Verify completion reports
      const completeReports = capturedRequests.filter(r => r.url.endsWith('/worker/complete'));
      expect(completeReports).toHaveLength(3);

      const job101Report = JSON.parse(completeReports[0].options.body);
      expect(job101Report.job_id).toBe(101);
      expect(job101Report.status).toBe('published');
      expect(job101Report.external_post_id).toBe('ext-twitter-777');

      const job102Report = JSON.parse(completeReports[1].options.body);
      expect(job102Report.job_id).toBe(102);
      expect(job102Report.status).toBe('published');
      expect(job102Report.external_post_id).toBe('ext-tiktok-777');
    });

    test('handles failure from poster and reports status failed to worker/complete', async () => {
      const capturedReports: any[] = [];

      const mockFetch: typeof fetch = jest.fn().mockImplementation(async (url: string, options: any) => {
        if (url.endsWith('/worker/claim')) {
          return {
            ok: true,
            json: async () => ({
              jobs: [
                {
                  id: 201,
                  platform: 'twitter',
                  post_copy: 'Failing tweet'
                }
              ]
            })
          } as any;
        }

        if (url.endsWith('/worker/complete')) {
          capturedReports.push(JSON.parse(options.body));
          return { ok: true, json: async () => ({ success: true }) } as any;
        }

        return { ok: false, status: 404 } as any;
      });

      const mockFailingPoster = jest.fn().mockResolvedValue({
        success: false,
        platform: 'twitter',
        error: 'Twitter rate limit reached (HTTP 429)'
      });

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'test-secret',
        fetchFn: mockFetch,
        posterHandler: mockFailingPoster
      });

      const stats = await poller.pollOnce();
      expect(stats.claimed).toBe(1);
      expect(stats.succeeded).toBe(0);
      expect(stats.failed).toBe(1);

      expect(capturedReports).toHaveLength(1);
      expect(capturedReports[0].job_id).toBe(201);
      expect(capturedReports[0].status).toBe('failed');
      expect(capturedReports[0].error).toMatch(/Twitter rate limit/);
    });

    test('catches unhandled exceptions during job execution and reports failed', async () => {
      const capturedReports: any[] = [];

      const mockFetch: typeof fetch = jest.fn().mockImplementation(async (url: string, options: any) => {
        if (url.endsWith('/worker/claim')) {
          return {
            ok: true,
            json: async () => ({
              jobs: [
                {
                  id: 301,
                  platform: 'tiktok',
                  post_copy: 'Will explode'
                }
              ]
            })
          } as any;
        }

        if (url.endsWith('/worker/complete')) {
          capturedReports.push(JSON.parse(options.body));
          return { ok: true, json: async () => ({ success: true }) } as any;
        }

        return { ok: false, status: 404 } as any;
      });

      const mockExplodingVideo = jest.fn().mockRejectedValue(new Error('FFmpeg binary missing'));

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'test-secret',
        fetchFn: mockFetch,
        generateVideoFn: mockExplodingVideo
      });

      const stats = await poller.pollOnce();
      expect(stats.claimed).toBe(1);
      expect(stats.succeeded).toBe(0);
      expect(stats.failed).toBe(1);

      expect(capturedReports).toHaveLength(1);
      expect(capturedReports[0].job_id).toBe(301);
      expect(capturedReports[0].status).toBe('failed');
      expect(capturedReports[0].error).toMatch(/FFmpeg binary missing/);
    });
  });

  describe('5. Poller Lifecycle (start & stop)', () => {
    test('start() sets running state and stop() clears timer', () => {
      const mockFetch: typeof fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ jobs: [] })
      } as any);

      const poller = new PressHubPoller({
        wpUrl: 'https://presshub.cy',
        workerSecret: 'test-secret',
        pollIntervalMs: 50000,
        fetchFn: mockFetch
      });

      expect(poller.getRunningStatus()).toBe(false);
      poller.start();
      expect(poller.getRunningStatus()).toBe(true);

      poller.stop();
      expect(poller.getRunningStatus()).toBe(false);
    });
  });
});
