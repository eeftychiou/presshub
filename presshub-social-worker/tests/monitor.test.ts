import fs from 'fs';
import os from 'os';
import path from 'path';
import { parseXGraphql, parseXTweet, parseTikTokItemList, rankXVideoVariants, toWirePost, ScannedPost } from '../src/monitor/parsers';
import { SocialMonitor } from '../src/monitor/monitor-client';
import { buildScrollStop, resolveReaderSession, ScanPlan, ScanSession, ScanTarget } from '../src/monitor/scanner';

const MB = 1024 * 1024;

function xTweet(overrides: any = {}): any {
  const legacy = {
    id_str: '1800000000000000001',
    created_at: 'Wed Nov 11 08:30:00 +0000 2026',
    full_text: 'Νέα ανακοίνωση https://t.co/abc &amp; περισσότερα https://t.co/media',
    entities: {
      urls: [{ url: 'https://t.co/abc', expanded_url: 'https://example.cy/news' }]
    },
    extended_entities: { media: [] as any[] },
    ...overrides.legacy
  };
  if (overrides.media) {
    legacy.extended_entities.media = overrides.media;
  }
  return {
    __typename: 'Tweet',
    rest_id: legacy.id_str,
    core: { user_results: { result: { core: { screen_name: overrides.handle || 'MinistryCY', name: 'Ministry' } } } },
    legacy,
    ...(overrides.note ? { note_tweet: { note_tweet_results: { result: { text: overrides.note } } } } : {})
  };
}

describe('X parsers', () => {
  it('parses a tweet, expands links and drops media links', () => {
    const post = parseXTweet(
      xTweet({ media: [{ type: 'photo', url: 'https://t.co/media' }] }),
      15 * MB
    ) as ScannedPost;
    expect(post).not.toBeNull();
    expect(post.handle).toBe('ministrycy');
    expect(post.url).toBe('https://x.com/MinistryCY/status/1800000000000000001');
    expect(post.text).toBe('Νέα ανακοίνωση https://example.cy/news & περισσότερα');
    expect(post.created_at).toBe('2026-11-11T08:30:00.000Z');
    expect(post.media).toEqual({ type: 'image', duration_sec: 0 });
  });

  it('prefers long-form note text', () => {
    const post = parseXTweet(xTweet({ note: 'Πλήρες κείμενο' }), MB);
    expect(post?.text).toBe('Πλήρες κείμενο');
  });

  it('skips retweets and replies to other accounts, keeps self-replies', () => {
    expect(parseXTweet(xTweet({ legacy: { full_text: 'RT @someone: hi' } }), MB)).toBeNull();
    expect(
      parseXTweet(xTweet({ legacy: { in_reply_to_status_id_str: '1', in_reply_to_screen_name: 'other' } }), MB)
    ).toBeNull();
    expect(
      parseXTweet(xTweet({ legacy: { in_reply_to_status_id_str: '1', in_reply_to_screen_name: 'ministrycy' } }), MB)
    ).not.toBeNull();
  });

  it('ranks video variants within the size cap, best first', () => {
    const ranked = rankXVideoVariants(
      [
        { content_type: 'application/x-mpegURL', url: 'https://v/playlist.m3u8' },
        { content_type: 'video/mp4', bitrate: 256000, url: 'https://v/low.mp4' },
        { content_type: 'video/mp4', bitrate: 2176000, url: 'https://v/high.mp4' },
        { content_type: 'video/mp4', bitrate: 832000, url: 'https://v/mid.mp4' }
      ],
      60,
      10 * MB
    );
    // high ≈ 18 MB (too big), mid ≈ 6.9 MB, low ≈ 2.1 MB
    expect(ranked.map((r) => r.url)).toEqual(['https://v/mid.mp4', 'https://v/low.mp4']);
  });

  it('walks a timeline response, unwraps visibility results and skips quoted tweets', () => {
    const video = xTweet({
      legacy: { id_str: '1800000000000000002' },
      media: [
        {
          type: 'video',
          video_info: { duration_millis: 30000, variants: [{ content_type: 'video/mp4', bitrate: 832000, url: 'https://v/a.mp4' }] }
        }
      ]
    });
    const quoted = xTweet({ legacy: { id_str: '1800000000000000009' }, handle: 'someoneelse' });
    const plain = { ...xTweet(), quoted_status_result: { result: quoted } };
    const json = {
      data: {
        user: {
          result: {
            timeline: {
              timeline: {
                instructions: [
                  {
                    entries: [
                      { content: { itemContent: { tweet_results: { result: plain } } } },
                      { content: { itemContent: { tweet_results: { result: { __typename: 'TweetWithVisibilityResults', tweet: video } } } } }
                    ]
                  }
                ]
              }
            }
          }
        }
      }
    };
    const posts = parseXGraphql(json, 15 * MB);
    expect(posts.map((p) => p.id).sort()).toEqual(['1800000000000000001', '1800000000000000002']);
    const v = posts.find((p) => p.id === '1800000000000000002') as ScannedPost;
    expect(v.media).toEqual({ type: 'video', duration_sec: 30 });
    expect(v.video?.[0].url).toBe('https://v/a.mp4');
    expect(toWirePost(v)).not.toHaveProperty('video');
  });
});

describe('Timeline order, pinned posts and scroll stop', () => {
  const tw = (id: string, created: string, handle = 'MinistryCY') =>
    xTweet({ legacy: { id_str: id, created_at: created }, handle });

  it('keeps the timeline order (newest first) and flags pinned tweets', () => {
    const json = {
      instructions: [
        { type: 'TimelinePinEntry', entry: { entryId: 'tweet-1', content: { itemContent: { tweet_results: { result: tw('1', 'Mon Jan 01 10:00:00 +0000 2024') } } } } },
        {
          type: 'TimelineAddEntries',
          entries: [
            { entryId: 'tweet-3', content: { itemContent: { tweet_results: { result: tw('3', 'Wed Nov 11 10:00:00 +0000 2026') } } } },
            { entryId: 'tweet-2', content: { itemContent: { tweet_results: { result: tw('2', 'Wed Nov 11 09:00:00 +0000 2026') } } } }
          ]
        }
      ]
    };
    const posts = parseXGraphql(json, MB);
    expect(posts.map((p) => p.id)).toEqual(['1', '3', '2']);
    expect(posts[0].pinned).toBe(true);
    expect(posts[1].pinned).toBeUndefined();
    expect(toWirePost(posts[0])).not.toHaveProperty('pinned');
  });

  it('flags pinned TikTok videos', () => {
    const [p] = parseTikTokItemList(
      { itemList: [{ id: '7', createTime: 1700000000, isPinnedItem: true, author: { uniqueId: 'a' }, video: { duration: 5 } }] },
      MB
    );
    expect(p.pinned).toBe(true);
  });

  const targets: ScanTarget[] = [
    { platform: 'x', handle: 'busy', url: 'https://x.com/busy', since: '2026-11-11T09:50:00Z' },
    { platform: 'x', handle: 'quiet', url: 'https://x.com/quiet', since: '2026-11-10T16:00:00Z' }
  ];
  const post = (handle: string, created: string, pinned = false): ScannedPost => ({
    platform: 'x', id: handle + created, handle, author_name: handle, url: '', text: '', created_at: created,
    media: { type: 'none', duration_sec: 0 }, ...(pinned ? { pinned: true } : {})
  });

  it('does not stop a List when only one busy account is caught up', () => {
    const stop = buildScrollStop(targets);
    expect(stop([post('busy', '2026-11-11T09:40:00Z'), post('quiet', '2026-11-11T09:30:00Z')])).toBe(false);
    expect(stop([post('quiet', '2026-11-10T15:00:00Z')])).toBe(true);
  });

  it('ignores pinned posts on a profile', () => {
    const stop = buildScrollStop(targets);
    expect(stop([post('quiet', '2024-01-01T00:00:00Z', true), post('quiet', '2026-11-11T08:00:00Z')], 'quiet')).toBe(false);
    expect(stop([post('quiet', '2026-11-10T12:00:00Z')], 'quiet')).toBe(true);
  });
});

describe('TikTok parser', () => {
  it('parses videos and photo posts', () => {
    const posts = parseTikTokItemList(
      {
        itemList: [
          {
            id: '7300000000000000001',
            desc: ' Δηλώσεις για τον προϋπολογισμό ',
            createTime: 1794384000,
            author: { uniqueId: 'PoliticianCY', nickname: 'Politician' },
            video: {
              duration: 42,
              playAddr: 'https://tt/play.mp4',
              bitrateInfo: [
                { Bitrate: 1000000, PlayAddr: { UrlList: ['https://tt/small.mp4'], DataSize: 3 * MB } },
                { Bitrate: 5000000, PlayAddr: { UrlList: ['https://tt/huge.mp4'], DataSize: 40 * MB } }
              ]
            }
          },
          { id: '7300000000000000002', desc: 'photos', createTime: 1794384100, author: { uniqueId: 'politiciancy' }, imagePost: { images: [] } },
          { id: 'bad', createTime: 1 }
        ]
      },
      15 * MB
    );
    expect(posts).toHaveLength(2);
    const [vid, photo] = posts;
    expect(vid.handle).toBe('politiciancy');
    expect(vid.url).toBe('https://www.tiktok.com/@politiciancy/video/7300000000000000001');
    expect(vid.text).toBe('Δηλώσεις για τον προϋπολογισμό');
    expect(vid.media).toEqual({ type: 'video', duration_sec: 42 });
    expect(vid.video?.map((s) => s.url)).toEqual(['https://tt/small.mp4', 'https://tt/play.mp4']);
    expect(vid.video?.[0].headers?.Referer).toBe('https://www.tiktok.com/');
    expect(photo.media.type).toBe('image');
    expect(photo.url).toContain('/photo/');
    expect(photo.video).toBeUndefined();
  });
});

describe('Reader session resolution', () => {
  const envBackup = { ...process.env };
  let dir: string;
  const posting = () => path.join(dir, 'sessions');

  beforeEach(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'monitor-sessions-'));
    fs.mkdirSync(path.join(dir, 'sessions'));
    fs.mkdirSync(path.join(dir, 'sessions-reader'));
    delete process.env.PRESSHUB_MONITOR_SESSIONS_DIR;
    delete process.env.PRESSHUB_MONITOR_ALLOW_POSTING_SESSION;
  });

  afterEach(() => {
    process.env = { ...envBackup };
    fs.rmSync(dir, { recursive: true, force: true });
  });

  it('uses the reader session when present, and never the posting one unless allowed', () => {
    fs.writeFileSync(path.join(dir, 'sessions', 'twitter-session.json'), '{}');
    expect(resolveReaderSession('x', posting())).toBeUndefined();

    process.env.PRESSHUB_MONITOR_ALLOW_POSTING_SESSION = 'true';
    expect(resolveReaderSession('x', posting())).toBe(path.join(dir, 'sessions', 'twitter-session.json'));

    fs.writeFileSync(path.join(dir, 'sessions-reader', 'twitter-session.json'), '{}');
    expect(resolveReaderSession('x', posting())).toBe(path.join(dir, 'sessions-reader', 'twitter-session.json'));
    expect(resolveReaderSession('tiktok', posting())).toBeUndefined();
  });
});

describe('SocialMonitor client', () => {
  const plan: ScanPlan = {
    enabled: true,
    due: true,
    targets: [{ platform: 'tiktok', handle: 'politiciancy', url: 'https://www.tiktok.com/@politiciancy', since: '2026-11-10T00:00:00Z' }],
    max_posts_per_account: 10,
    max_age_hours: 24,
    transcribe_videos: true,
    max_video_seconds: 180,
    max_video_mb: 15
  };
  const post: ScannedPost = {
    platform: 'tiktok',
    id: '7300000000000000001',
    handle: 'politiciancy',
    author_name: 'Politician',
    url: 'https://www.tiktok.com/@politiciancy/video/7300000000000000001',
    text: 'hello',
    created_at: '2026-11-11T08:00:00.000Z',
    media: { type: 'video', duration_sec: 42 },
    video: [{ url: 'https://tt/small.mp4', estimatedBytes: 100 }]
  };

  function makeFetch(planBody: any, postsBody: any) {
    const calls: { url: string; init: any }[] = [];
    const fn = jest.fn(async (url: string, init: any = {}) => {
      calls.push({ url, init });
      if (url.includes('/monitor/targets')) return { ok: true, status: 200, json: async () => planBody } as any;
      if (url.includes('/monitor/posts')) return { ok: true, status: 200, json: async () => postsBody } as any;
      if (url.includes('/monitor/media')) return { ok: true, status: 200, json: async () => ({}) } as any;
      return { ok: false, status: 404, json: async () => ({}) } as any;
    });
    return { fn, calls };
  }

  it('scans, sends posts without video URLs and uploads requested videos', async () => {
    const { fn, calls } = makeFetch(plan, { accepted: 1, wants_media: ['tiktok_7300000000000000001'] });
    const closed = jest.fn(async () => {});
    const session: ScanSession = {
      posts: [post],
      errors: [{ platform: 'x', url: 'https://x.com/i/lists/1', error: 'boom' }],
      download: jest.fn(async () => ({ bytes: Buffer.from('mp4data'), mime: 'video/mp4' })),
      close: closed
    };
    const scanFn = jest.fn(async () => session);
    const monitor = new SocialMonitor({ wpUrl: 'https://site.test/', workerSecret: 's3cret', fetchFn: fn as any, scanFn });

    const result = await monitor.runOnce();

    expect(result).toEqual({ ran: true, scanned: 1, accepted: 1, media_uploaded: 1, errors: 1 });
    expect(scanFn).toHaveBeenCalledWith(plan);
    expect(calls[0].url).toBe('https://site.test/wp-json/presshub-social/v1/monitor/targets');
    expect(calls[0].init.headers['X-PressHub-Secret']).toBe('s3cret');
    const sent = JSON.parse(calls[1].init.body);
    expect(sent.posts[0]).not.toHaveProperty('video');
    expect(sent.errors).toHaveLength(1);
    expect(calls[2].url).toBe(
      'https://site.test/wp-json/presshub-social/v1/monitor/media?id=tiktok_7300000000000000001&mime=video%2Fmp4'
    );
    expect(calls[2].init.headers['Content-Type']).toBe('video/mp4');
    expect(closed).toHaveBeenCalled();
  });

  it('tells WordPress when a requested video cannot be downloaded', async () => {
    const { fn, calls } = makeFetch(plan, { accepted: 1, wants_media: ['tiktok_7300000000000000001'] });
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      scanFn: async () => ({ posts: [post], errors: [], download: async () => null, close: async () => {} })
    });
    const result = await monitor.runOnce();
    expect(result.media_uploaded).toBe(0);
    expect(calls[2].url).toBe('https://site.test/wp-json/presshub-social/v1/monitor/media?id=tiktok_7300000000000000001&failed=download');
  });

  it('does not open a browser when no scan is due', async () => {
    const { fn, calls } = makeFetch({ ...plan, due: false }, {});
    const scanFn = jest.fn();
    const monitor = new SocialMonitor({ wpUrl: 'https://site.test', workerSecret: 's', fetchFn: fn as any, scanFn: scanFn as any });
    expect((await monitor.runOnce()).ran).toBe(false);
    expect(scanFn).not.toHaveBeenCalled();
    expect(calls).toHaveLength(1);
  });

  it('stays quiet when the WordPress side is not installed', async () => {
    const fn = jest.fn(async () => ({ ok: false, status: 404, json: async () => ({}) }) as any);
    const scanFn = jest.fn();
    const monitor = new SocialMonitor({ wpUrl: 'https://site.test', workerSecret: 's', fetchFn: fn as any, scanFn: scanFn as any });
    expect((await monitor.runOnce()).ran).toBe(false);
    expect(scanFn).not.toHaveBeenCalled();
  });

  it('skips video uploads when transcription is off and still closes the browser on failure', async () => {
    const { fn, calls } = makeFetch({ ...plan, transcribe_videos: false }, { accepted: 1, wants_media: ['tiktok_7300000000000000001'] });
    const closed = jest.fn(async () => {});
    const download = jest.fn();
    const monitor = new SocialMonitor({
      wpUrl: 'https://site.test',
      workerSecret: 's',
      fetchFn: fn as any,
      scanFn: async () => ({ posts: [post], errors: [], download: download as any, close: closed })
    });
    const result = await monitor.runOnce();
    expect(result.media_uploaded).toBe(0);
    expect(download).not.toHaveBeenCalled();
    expect(calls.some((c) => c.url.includes('failed='))).toBe(false);
    expect(calls.some((c) => c.url.includes('/monitor/media'))).toBe(false);
    expect(closed).toHaveBeenCalled();
  });
});
