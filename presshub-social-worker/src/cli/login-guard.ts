import fs from 'fs';
import path from 'path';
import { Platform } from '../automation/session-manager';

/**
 * Keeps the posting account and the Social Monitor's reader account apart.
 *
 * Each platform has two session slots: the posting account (sessions/) and the reader account
 * (sessions-reader/). A login that ran in the wrong mode, or in a Chrome profile still signed in
 * as the other account, used to save one account over the other without a word. Before a login
 * saves, checkSessionSave() compares the account in the new cookies with the account in both
 * slots and refuses when it is the other slot's account, or a different account than the one
 * the slot already holds. `--force` saves anyway.
 */

/** Cookie holding the signed-in account's id, per platform. TikTok has no stable one. */
const ACCOUNT_COOKIES: Partial<Record<Platform, string>> = {
  facebook: 'c_user',
  instagram: 'ds_user_id',
  twitter: 'twid'
};

export type LoginMode = 'posting' | 'reader';

export interface SessionSlots {
  posting: string;
  reader: string;
}

/** The account id in a Playwright storage state (object or JSON), or null when unknown. */
export function accountIdFromState(platform: Platform, state: unknown): string | null {
  const name = ACCOUNT_COOKIES[platform];
  if (!name) return null;
  let parsed: any = state;
  if (typeof state === 'string') {
    try {
      parsed = JSON.parse(state);
    } catch {
      return null;
    }
  }
  const cookies: any[] = Array.isArray(parsed?.cookies) ? parsed.cookies : [];
  const cookie = cookies.find((c) => c && c.name === name && String(c.value || '').trim() !== '');
  if (!cookie) return null;
  let value = String(cookie.value).trim();
  try {
    value = decodeURIComponent(value);
  } catch {}
  // X stores "u=<id>" in twid.
  return value.replace(/^"?u=/, '').replace(/"$/, '') || null;
}

/** The account id of a saved session file, or null when there is none or it has no id. */
export function accountIdFromFile(platform: Platform, file: string): string | null {
  try {
    if (!fs.existsSync(file)) return null;
    return accountIdFromState(platform, fs.readFileSync(file, 'utf-8'));
  } catch {
    return null;
  }
}

export function sessionSlots(platform: Platform, postingDir: string, readerDir: string): SessionSlots {
  return {
    posting: path.join(postingDir, `${platform}-session.json`),
    reader: path.join(readerDir, `${platform}-session.json`)
  };
}

/**
 * Whether a new session may be saved in `mode`'s slot: null when it may, else the reason.
 */
export function checkSessionSave(
  platform: Platform,
  state: unknown,
  mode: LoginMode,
  slots: SessionSlots
): string | null {
  const incoming = accountIdFromState(platform, state);
  if (!incoming) return null;

  const other: LoginMode = mode === 'posting' ? 'reader' : 'posting';
  const otherId = accountIdFromFile(platform, slots[other]);
  if (otherId && otherId === incoming) {
    return (
      `this browser is signed in to the ${other.toUpperCase()} account (${platform} id ${incoming}), ` +
      `which is already saved in ${slots[other]}. Log out of it, sign in with the ${mode.toUpperCase()} account, and try again.`
    );
  }

  const currentId = accountIdFromFile(platform, slots[mode]);
  if (currentId && currentId !== incoming) {
    return (
      `${slots[mode]} holds a different ${platform} account (id ${currentId}) than the one signed in now (id ${incoming}). ` +
      `If you mean to replace it, run the login again with --force.`
    );
  }
  return null;
}

/** The banner naming which account and file a login saves, shown before and at the save. */
export function modeBanner(mode: LoginMode, platform: Platform, sessionPath: string): string {
  const who = mode === 'reader' ? 'READER account (Social Monitor / harvesting)' : 'POSTING account (publishes posts)';
  return [
    '######################################################',
    `#  ${mode.toUpperCase()} MODE — sign in with the ${platform.toUpperCase()} ${who}`,
    `#  Saves to: ${sessionPath}`,
    '######################################################'
  ].join('\n');
}
