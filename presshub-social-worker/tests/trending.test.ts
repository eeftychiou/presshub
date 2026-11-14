import { parseFacebookStories, splitFacebookPayload } from '../src/trending/facebook-parser';
import { parseTrendingBody, trimTrending, TrendingPost, TrendingPlan } from '../src/trending/scanner';
import { TrendingClient } from '../src/trending/trending-client';
import { parseTikTokItemList, parseXGraphql, toWirePost } from '../src/monitor/parsers';

const nowSec = Math.floor(Date.now() / 1000);

function fbStory(id: string, opts: { text?: string; created?: number; reactions?: number; comments?: number; shares?: number; withFeedback?: boolean } = {}) {
  const story: any = {
    __typename: 'Story',
    id: 'UzpfS' + id,
    post_id: id,
    comet_sections: {
      content: { story: { message: { text: opts.text ?? 'Χιόνι στο Τρόοδος σήμερα' }, actors: [{ __typename: 'Page', name: 'Philenews', url: 'https://www.facebook.com/philenews' }] } },
      context_layout: { story: { comet_sections: { metadata: [{ story: { creation_time: opts.created ?? nowSec - 3600, url: `https://www.facebook.com/philenews/posts/pfbid0abc${id}` } }] } } },
      attached_story: { __typename: 'Story', post_id: '999999999', message: { text: 'nested shared story' } }
    }
  };
  if (opts.withFeedback !== false) {
    story.comet_sections.feedback = {
      story: {
        feedback_context: {
          feedback_target_with_context: {
            comet_ufi_summary_and_actions_renderer: {
              feedback: {
                reaction_count: { count: opts.reactions ?? 120 },
                share_count: { count: opts.shares ?? 7 },
                comment_rendering_instance: { comments: { total_count: opts.comments ?? 33 } }
              }
            }
          }
        }
      }
    };
  }
  return story;
}

describe('Facebook parser', () => {
  test('splits multi-line GraphQL bodies and strips the for(;;) guard', () => {
    const body = 'for (;;);' + JSON.stringify({ a: 1 }) + '\n' + JSON.stringify({ b: 2 }) + '\n{"broken":';
    expect(splitFacebookPayload(body)).toEqual([{ a: 1 }, { b: 2 }]);
    expect(splitFacebookPayload(JSON.stringify({ one: true }))).toEqual([{ one: true }]);
  });

  test('reads a Story with message, time, author, permalink and counters', () => {
    const posts = parseFacebookStories([{ data: { node: { timeline_list_feed_units: { edges: [{ node: fbStory('123456789012') }] } } } }]);
    expect(posts).toHaveLength(1);
    const p = posts[0];
    expect(p.id).toBe('123456789012');
    expect(p.text).toBe('Χιόνι στο Τρόοδος σήμερα');
    expect(p.author_name).toBe('Philenews');
    expect(p.handle).toBe('philenews');
    expect(p.url).toBe('https://www.facebook.com/philenews/posts/pfbid0abc123456789012');
    expect(p.metrics).toEqual({ likes: 120, comments: 33, shares: 7, views: 0 });
    expect(Date.parse(p.created_at)).toBe((nowSec - 3600) * 1000);
  });

  test('does not take the text of an attached (shared) story and does not list it separately', () => {
    const story = fbStory('223456789012', { text: '' });
    delete story.comet_sections.content.story.message;
    const posts = parseFacebookStories([story]);
    expect(posts.map((p) => p.id)).toEqual(['223456789012']);
    expect(posts[0].text).toBe('');
  });

  test('merges the same post seen with and without counters', () => {
    const posts = parseFacebookStories([fbStory('323456789012', { withFeedback: false }), fbStory('323456789012', { reactions: 500 })]);
    expect(posts).toHaveLength(1);
    expect(posts[0].metrics.likes).toBe(500);
  });

  test('skips stories without a creation time', () => {
    const s = fbStory('423456789012');
    delete s.comet_sections.context_layout;
    expect(parseFacebookStories([s])).toEqual([]);
  });
});

describe('Engagement metrics in the shared parsers', () => {
  test('X tweets carry likes, replies, reposts + quotes and views', () => {
    const json = {
      data: {
        search_by_raw_query: {
          search_timeline: {
            timeline: {
              instructions: [
                {
                  entries: [
                    {
                      content: {
                        itemContent: {
                          tweet_results: {
                            result: {
                              __typename: 'Tweet',
                              rest_id: '1890000000000000001',
                              core: { user_results: { result: { core: { screen_name: 'CyprusMail', name: 'Cyprus Mail' } } } },
                              views: { count: '45210' },
                              legacy: {
                                id_str: '1890000000000000001',
                                full_text: 'Storm warning for Cyprus',
                                created_at: new Date().toUTCString(),
                                favorite_count: 321,
                                reply_count: 12,
                                retweet_count: 40,
                                quote_count: 5
                              }
                            }
                          }
                        }
                      }
                    }
                  ]
                }
              ]
            }
          }
        }
      }
    };
    const [p] = parseXGraphql(json, 0);
    expect(p.metrics).toEqual({ likes: 321, comments: 12, shares: 45, views: 45210 });
    expect(toWirePost(p)).not.toHaveProperty('metrics');
    const [t] = parseTrendingBody('x', JSON.stringify(json));
    expect(t.platform).toBe('x');
    expect(t.metrics.likes).toBe(321);
    expect(t).not.toHaveProperty('video');
  });

  test('TikTok search responses (item_list and data[].item) are read with stats', () => {
    const item = {
      id: '7400000000000000001',
      desc: 'Λεμεσός #cyprus',
      createTime: nowSec - 600,
      author: { uniqueId: 'someone.cy', nickname: 'Someone' },
      video: { duration: 20 },
      stats: { diggCount: 1500, commentCount: 80, shareCount: 30, playCount: 90000 }
    };
    expect(parseTikTokItemList({ item_list: [item] }, 0)[0].metrics).toEqual({ likes: 1500, comments: 80, shares: 30, views: 90000 });
    expect(parseTikTokItemList({ data: [{ type: 1, item }, { type: 4, user_list: [] }] }, 0)).toHaveLength(1);
    const statsV2 = { ...item, statsV2: { diggCount: '2000', commentCount: '1', shareCount: '2', playCount: '3' } };
    expect(parseTikTokItemList({ itemList: [statsV2] }, 0)[0].metrics?.likes).toBe(2000);
  });
});

describe('trimTrending', () => {
  const mk = (platform: 'x' | 'tiktok', id: string, likes: number, hoursAgo: number): TrendingPost => ({
    platform,
    id,
    handle: 'h',
    author_name: 'H',
    url: 'u',
    text: '',
    created_at: new Date(Date.now() - hoursAgo * 3600 * 1000).toISOString(),
    media: { type: 'none', duration_sec: 0 },
    metrics: { likes, comments: 0, shares: 0, views: 0 }
  });

  test('drops old posts and duplicates, keeps the best per platform', () => {
    const out = trimTrending([mk('x', '1', 10, 1), mk('x', '2', 50, 2), mk('x', '2', 50, 2), mk('x', '3', 999, 30), mk('tiktok', '4', 5, 1)], 24, 1);
    expect(out.map((p) => p.id).sort()).toEqual(['2', '4']);
  });
});

describe('TrendingClient', () => {
  const plan: TrendingPlan = {
    enabled: true,
    due: true,
    run_id: '20261114090000-abc123',
    targets: [{ platform: 'x', url: 'https://x.com/search?q=Cyprus&f=top' }],
    max_age_hours: 24,
    max_per_platform: 20
  };

  test('reads the plan, scans and posts the results with the run id', async () => {
    const calls: { url: string; init?: any }[] = [];
    const fetchFn: any = async (url: string, init?: any) => {
      calls.push({ url, init });
      if (url.includes('/trending/plan')) return { ok: true, status: 200, json: async () => plan };
      return { ok: true, status: 200, json: async () => ({ accepted: 1, post_id: 55 }) };
    };
    const scanFn = jest.fn(async () => ({
      posts: [
        {
          platform: 'x' as const,
          id: '1',
          handle: 'a',
          author_name: 'A',
          url: 'https://x.com/a/status/1',
          text: 't',
          created_at: new Date().toISOString(),
          media: { type: 'none' as const, duration_sec: 0 },
          metrics: { likes: 1, comments: 0, shares: 0, views: 0 }
        }
      ],
      errors: []
    }));
    const client = new TrendingClient({ wpUrl: 'https://site.test/', workerSecret: 's3cret', fetchFn, scanFn });
    const res = await client.runOnce();
    expect(res).toEqual({ ran: true, sent: 1, accepted: 1, errors: 0 });
    expect(scanFn).toHaveBeenCalledWith(plan);
    expect(calls[0].url).toBe('https://site.test/wp-json/presshub-social/v1/trending/plan');
    expect(calls[0].init.headers['X-PressHub-Secret']).toBe('s3cret');
    const body = JSON.parse(calls[1].init.body);
    expect(calls[1].url).toBe('https://site.test/wp-json/presshub-social/v1/trending/posts');
    expect(body.run_id).toBe(plan.run_id);
    expect(body.posts).toHaveLength(1);
  });

  test('does nothing when no run is due or the plugin is older (404)', async () => {
    const scanFn = jest.fn();
    const notDue: any = async () => ({ ok: true, status: 200, json: async () => ({ ...plan, due: false }) });
    expect((await new TrendingClient({ wpUrl: 'https://s', workerSecret: 'x', fetchFn: notDue, scanFn }).runOnce()).ran).toBe(false);
    const missing: any = async () => ({ ok: false, status: 404, json: async () => ({}) });
    expect((await new TrendingClient({ wpUrl: 'https://s', workerSecret: 'x', fetchFn: missing, scanFn }).runOnce()).ran).toBe(false);
    expect(scanFn).not.toHaveBeenCalled();
  });
});

describe('Poller runs the monitor and the trending reader one after the other', () => {
  test('trending starts only after the monitor finished, and a tick is skipped while they run', async () => {
    const { PressHubPoller } = require('../src/poller');
    const order: string[] = [];
    let releaseMonitor: () => void = () => {};
    const monitor: any = {
      runOnce: jest.fn(() => {
        order.push('monitor:start');
        return new Promise<void>((r) => {
          releaseMonitor = () => {
            order.push('monitor:end');
            r();
          };
        });
      })
    };
    const trending: any = { runOnce: jest.fn(async () => order.push('trending')) };
    const poller = new PressHubPoller({ wpUrl: 'https://s', workerSecret: 'x', monitor, trending, fetchFn: (async () => ({ ok: false, status: 503, json: async () => ({}) })) as any });
    (poller as any).runMonitor();
    (poller as any).runMonitor();
    expect(monitor.runOnce).toHaveBeenCalledTimes(1);
    expect(trending.runOnce).not.toHaveBeenCalled();
    releaseMonitor();
    await new Promise((r) => setTimeout(r, 10));
    expect(order).toEqual(['monitor:start', 'monitor:end', 'trending']);
    (poller as any).runMonitor();
    await new Promise((r) => setTimeout(r, 10));
    expect(monitor.runOnce).toHaveBeenCalledTimes(2);
  });
});

describe('Facebook handles', () => {
  const { handleFromUrl } = require('../src/trending/facebook-parser');
  test('vanity names, profile.php ids and people/ URLs', () => {
    expect(handleFromUrl('https://www.facebook.com/philenews')).toBe('philenews');
    expect(handleFromUrl('https://www.facebook.com/profile.php?id=100064123456789')).toBe('id-100064123456789');
    expect(handleFromUrl('https://www.facebook.com/people/Some-Page/100064123456789/')).toBe('id-100064123456789');
    expect(handleFromUrl('https://www.facebook.com/groups/123')).toBe('');
  });

  test('a Page without a vanity URL gets an id handle from the actor id', () => {
    const s = fbStory('523456789012');
    s.comet_sections.content.story.actors = [{ __typename: 'Page', id: '100064123456789', name: 'Δήμος', url: 'https://www.facebook.com/profile.php?id=100064123456789' }];
    expect(parseFacebookStories([s])[0].handle).toBe('id-100064123456789');
  });
});

describe('Thumbnails', () => {
  test('Facebook preferred thumbnail, TikTok cover and X photo are read; the monitor never gets them', () => {
    const s = fbStory('623456789012');
    s.comet_sections.content.story.attachments = [{ media: { __typename: 'Photo', photo_image: { uri: 'https://scontent.fnic1-1.fna.fbcdn.net/v/p.jpg' } } }];
    expect(parseFacebookStories([s])[0].thumbnail).toBe('https://scontent.fnic1-1.fna.fbcdn.net/v/p.jpg');
    const v = fbStory('723456789012');
    v.comet_sections.content.story.attachments = [{ media: { __typename: 'Video', preferred_thumbnail: { image: { uri: 'https://scontent.xx.fbcdn.net/v/thumb.jpg' } } } }];
    const fv = parseFacebookStories([v])[0];
    expect(fv.thumbnail).toBe('https://scontent.xx.fbcdn.net/v/thumb.jpg');
    expect(fv.media.type).toBe('video');

    const item = { id: '7400000000000000002', desc: 'x', createTime: nowSec, author: { uniqueId: 'a.b' }, video: { duration: 5, cover: 'https://p16-sign.tiktokcdn.com/c.jpeg' } };
    const t = parseTikTokItemList({ itemList: [item] }, 0)[0];
    expect(t.thumbnail).toBe('https://p16-sign.tiktokcdn.com/c.jpeg');
    expect(toWirePost(t)).not.toHaveProperty('thumbnail');
    expect(parseTrendingBody('tiktok', JSON.stringify({ itemList: [item] }))[0].thumbnail).toBe('https://p16-sign.tiktokcdn.com/c.jpeg');
  });
});
