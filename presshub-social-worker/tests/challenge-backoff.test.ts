import {
  CHALLENGE_BASE_MS,
  CHALLENGE_MAX_MS,
  challengeBackoffUntil,
  clearChallenge,
  inChallengeBackoff,
  isChallengeError,
  recordChallenge,
  resetChallengeBackoff
} from '../src/automation/challenge-backoff';
import { browserTrendingScan } from '../src/trending/scanner';
import { rotate } from '../src/monitor/scanner';
import { TrendingClient } from '../src/trending/trending-client';
import { WorkerLogger } from '../src/logger';

describe('Verification challenge back-off (#193, #179)', () => {
  beforeEach(() => resetChallengeBackoff());

  test('recognises challenge errors only', () => {
    expect(isChallengeError('TikTok showed a verification challenge')).toBe(true);
    expect(isChallengeError('facebook showed a verification challenge')).toBe(true);
    expect(isChallengeError('X asked for a login (reader session missing or expired)')).toBe(false);
  });

  test('backs off for 2 h, doubles on repeats up to 12 h, and a clean scan clears it', () => {
    const t0 = 1_800_000_000_000;
    expect(recordChallenge('tiktok', 'Test', t0)).toBe(t0 + CHALLENGE_BASE_MS);
    expect(inChallengeBackoff('tiktok', t0 + CHALLENGE_BASE_MS - 1)).toBe(true);
    expect(inChallengeBackoff('tiktok', t0 + CHALLENGE_BASE_MS)).toBe(false);
    expect(inChallengeBackoff('facebook', t0)).toBe(false);
    expect(recordChallenge('tiktok', 'Test', t0)).toBe(t0 + 2 * CHALLENGE_BASE_MS);
    for (let i = 0; i < 6; i++) recordChallenge('tiktok', 'Test', t0);
    expect(challengeBackoffUntil('tiktok', t0)).toBe(t0 + CHALLENGE_MAX_MS);
    clearChallenge('tiktok');
    expect(challengeBackoffUntil('tiktok', t0)).toBe(0);
    expect(recordChallenge('tiktok', 'Test', t0)).toBe(t0 + CHALLENGE_BASE_MS);
  });

  test('the trending scan skips a platform in back-off without opening a browser', async () => {
    recordChallenge('tiktok', 'Test');
    const res = await browserTrendingScan({
      enabled: true,
      due: true,
      run_id: 'r1',
      targets: [
        { platform: 'tiktok', url: 'https://www.tiktok.com/tag/cyprus' },
        { platform: 'tiktok', url: 'https://www.tiktok.com/tag/limassol' }
      ],
      max_age_hours: 24,
      max_per_platform: 20
    });
    expect(res.posts).toEqual([]);
    expect(res.errors).toHaveLength(1);
    expect(res.errors[0].error).toMatch(/^skipped: verification challenge back-off until /);
  });

  test('monitor TikTok passes start where the last one was challenged', () => {
    expect(rotate(['a', 'b', 'c', 'd'], 2)).toEqual(['c', 'd', 'a', 'b']);
    expect(rotate(['a', 'b'], 5)).toEqual(['b', 'a']);
    expect(rotate([], 3)).toEqual([]);
  });

  test('a refused trending submission logs the run and the reason', async () => {
    const warn = jest.spyOn(WorkerLogger, 'warn').mockImplementation(() => undefined as any);
    const plan = {
      enabled: true,
      due: true,
      run_id: 'run-a',
      targets: [{ platform: 'x' as const, url: 'https://x.com/search?q=Cyprus' }],
      max_age_hours: 24,
      max_per_platform: 20
    };
    const fetchFn: any = async (url: string) =>
      url.includes('/trending/plan')
        ? { ok: true, status: 200, json: async () => plan }
        : { ok: false, status: 409, json: async () => ({ code: 'stale_run', message: 'This run is no longer open: run run-b replaced it.' }) };
    const client = new TrendingClient({ wpUrl: 'https://s', workerSecret: 'x', fetchFn, scanFn: async () => ({ posts: [], errors: [] }) });
    await client.runOnce();
    expect(warn).toHaveBeenCalledWith('Trending', 'Sending results for run run-a failed with HTTP 409: This run is no longer open: run run-b replaced it.');
    warn.mockRestore();
  });
});
