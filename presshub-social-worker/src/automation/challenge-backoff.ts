/**
 * Back-off after a platform shows a verification challenge (#193, #179).
 *
 * A challenge sits on the reader session, not on one page, so retrying on the
 * next scan only adds more challenges. After one, the platform is skipped for
 * a while (2 h, doubling on each repeat, at most 12 h). A clean scan clears it.
 * The "solve it by hand" warning is logged once per back-off, not on every scan,
 * because skipped scans log only a short info line.
 */
import { WorkerLogger } from '../logger';

export const CHALLENGE_BASE_MS = 2 * 60 * 60 * 1000;
export const CHALLENGE_MAX_MS = 12 * 60 * 60 * 1000;

interface Entry {
  until: number;
  strikes: number;
}

const entries = new Map<string, Entry>();

export function isChallengeError(message: string): boolean {
  return /verification challenge|verify to continue|captcha/i.test(message || '');
}

/** Record a challenge on `platform`; returns the time the back-off ends. */
export function recordChallenge(platform: string, context: string, now: number = Date.now()): number {
  const prev = entries.get(platform);
  const strikes = (prev?.strikes || 0) + 1;
  const wait = Math.min(CHALLENGE_MAX_MS, CHALLENGE_BASE_MS * Math.pow(2, strikes - 1));
  const entry: Entry = { until: now + wait, strikes };
  entries.set(platform, entry);
  WorkerLogger.warn(
    context,
    `${platform} showed a verification challenge. Skipping ${platform} scans until ${new Date(entry.until).toISOString()} ` +
      `(${Math.round(wait / 60000)} min). If it keeps happening, open ${platform} with the reader session and solve the check by hand.`
  );
  return entry.until;
}

/** True while `platform` is backing off. */
export function inChallengeBackoff(platform: string, now: number = Date.now()): boolean {
  const e = entries.get(platform);
  return Boolean(e && now < e.until);
}

/** When the current back-off for `platform` ends, or 0. */
export function challengeBackoffUntil(platform: string, now: number = Date.now()): number {
  const e = entries.get(platform);
  return e && now < e.until ? e.until : 0;
}

/** A scan of `platform` worked: forget earlier strikes. */
export function clearChallenge(platform: string): void {
  entries.delete(platform);
}

/** Tests only. */
export function resetChallengeBackoff(): void {
  entries.clear();
}
