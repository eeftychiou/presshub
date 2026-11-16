import fs from 'fs';
import os from 'os';
import path from 'path';
import { accountIdFromState, checkSessionSave, sessionSlots, modeBanner } from '../src/cli/login-guard';

const fbState = (id: string) => ({ cookies: [{ name: 'xs', value: 'x' }, { name: 'c_user', value: id }], origins: [] });

describe('login guard', () => {
  let root: string;
  let slots: { posting: string; reader: string };

  beforeEach(() => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'login-guard-'));
    fs.mkdirSync(path.join(root, 'sessions'));
    fs.mkdirSync(path.join(root, 'sessions-reader'));
    slots = sessionSlots('facebook', path.join(root, 'sessions'), path.join(root, 'sessions-reader'));
  });

  afterEach(() => fs.rmSync(root, { recursive: true, force: true }));

  it('reads the account id per platform', () => {
    expect(accountIdFromState('facebook', fbState('100'))).toBe('100');
    expect(accountIdFromState('instagram', { cookies: [{ name: 'ds_user_id', value: '42' }] })).toBe('42');
    expect(accountIdFromState('twitter', { cookies: [{ name: 'twid', value: 'u%3D777' }] })).toBe('777');
    expect(accountIdFromState('twitter', JSON.stringify({ cookies: [{ name: 'twid', value: '"u=778"' }] }))).toBe('778');
    expect(accountIdFromState('tiktok', { cookies: [{ name: 'sessionid', value: 's' }] })).toBeNull();
    expect(accountIdFromState('facebook', 'not json')).toBeNull();
  });

  it('allows a first login and a re-login of the same account', () => {
    expect(checkSessionSave('facebook', fbState('100'), 'posting', slots)).toBeNull();
    fs.writeFileSync(slots.posting, JSON.stringify(fbState('100')));
    expect(checkSessionSave('facebook', fbState('100'), 'posting', slots)).toBeNull();
  });

  it('refuses to save the reader account in the posting slot, even when the posting slot is empty', () => {
    fs.writeFileSync(slots.reader, JSON.stringify(fbState('200')));
    const reason = checkSessionSave('facebook', fbState('200'), 'posting', slots);
    expect(reason).toMatch(/READER account/);
    expect(reason).toMatch(/POSTING account/);
  });

  it('refuses to save the posting account in the reader slot', () => {
    fs.writeFileSync(slots.posting, JSON.stringify(fbState('100')));
    expect(checkSessionSave('facebook', fbState('100'), 'reader', slots)).toMatch(/POSTING account/);
  });

  it('refuses to replace a slot with a different account', () => {
    fs.writeFileSync(slots.posting, JSON.stringify(fbState('100')));
    expect(checkSessionSave('facebook', fbState('300'), 'posting', slots)).toMatch(/--force/);
  });

  it('names the mode and the file in the banner', () => {
    const banner = modeBanner('reader', 'facebook', slots.reader);
    expect(banner).toContain('READER MODE');
    expect(banner).toContain(slots.reader);
  });
});
