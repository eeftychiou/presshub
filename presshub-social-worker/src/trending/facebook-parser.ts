/**
 * Parser for the JSON Facebook pages load (GraphQL responses and the JSON
 * embedded in the first HTML). Facebook nests posts very deeply and changes
 * the nesting often, so this looks for any object shaped like a Story with a
 * numeric `post_id` and reads its fields by name, breadth-first, without
 * descending into shared / attached stories.
 */
import { PostMetrics, toCount } from '../monitor/parsers';

export interface FacebookPost {
  platform: 'facebook';
  id: string;
  handle: string;
  author_name: string;
  url: string;
  text: string;
  created_at: string;
  media: { type: 'video' | 'image' | 'none'; duration_sec: number };
  metrics: PostMetrics;
}

/**
 * Split a Facebook response body into JSON values. GraphQL answers are often
 * several JSON objects, one per line, sometimes behind a `for (;;);` guard.
 */
export function splitFacebookPayload(body: string): any[] {
  const text = String(body || '').replace(/^for \(;;\);/, '').trim();
  if (!text) return [];
  try {
    return [JSON.parse(text)];
  } catch {
    // fall through to one JSON value per line
  }
  const out: any[] = [];
  for (const line of text.split(/\r?\n/)) {
    const l = line.trim();
    if (!l || (l[0] !== '{' && l[0] !== '[')) continue;
    try {
      out.push(JSON.parse(l));
    } catch {
      // skip partial lines
    }
  }
  return out;
}

const NESTED_STORY_KEYS = new Set(['attached_story', 'attached_story_layout', 'comet_footer_renderer', 'comments', 'comment_list_renderer', 'interesting_top_level_comments']);

/** Breadth-first search inside one story for the first value that `pick` accepts. */
function bfs<T>(root: any, pick: (key: string, value: any) => T | undefined, maxNodes = 20000): T | undefined {
  const queue: any[] = [root];
  let n = 0;
  while (queue.length && n++ < maxNodes) {
    const node = queue.shift();
    if (!node || typeof node !== 'object') continue;
    const entries = Array.isArray(node) ? node.map((v, i) => [String(i), v] as [string, any]) : Object.entries(node);
    for (const [k, v] of entries) {
      const got = pick(k, v);
      if (got !== undefined) return got;
    }
    for (const [k, v] of entries) {
      if (NESTED_STORY_KEYS.has(k)) continue;
      if (v && typeof v === 'object') {
        if (!Array.isArray(v) && v.__typename === 'Story' && v !== root && v.post_id && String(v.post_id) !== String(root.post_id)) continue;
        queue.push(v);
      }
    }
  }
  return undefined;
}

/**
 * Account handle from an actor URL: the vanity name (facebook.com/philenews),
 * or `id-<number>` for Pages without one (profile.php?id=123), so the
 * per-account cap in WordPress still groups their posts.
 */
export function handleFromUrl(url: string): string {
  const id = /^https:\/\/(?:www\.|web\.|m\.)?facebook\.com\/(?:profile\.php\?(?:.*&)?id=|people\/[^/]+\/)(\d{5,25})/.exec(url || '');
  if (id) {
    return `id-${id[1]}`;
  }
  const m = /^https:\/\/(?:www\.|web\.|m\.)?facebook\.com\/([A-Za-z0-9.\-]{2,100})\/?(?:$|[?#])/.exec(url || '');
  if (m && !['profile.php', 'groups', 'watch', 'photo', 'reel', 'story.php', 'permalink.php'].includes(m[1].toLowerCase())) {
    return m[1].toLowerCase();
  }
  return '';
}

function parseStory(story: any): FacebookPost | null {
  const id = String(story?.post_id || '');
  if (!/^\d{5,25}$/.test(id)) return null;

  const text =
    bfs<string>(story, (k, v) => (k === 'message' && v && typeof v.text === 'string' && v.text.trim() ? v.text.trim() : undefined)) || '';
  const created = bfs<number>(story, (k, v) => (k === 'creation_time' && typeof v === 'number' && v > 1e9 ? v : undefined));
  if (!created) return null;

  const actor = bfs<any>(story, (k, v) => (k === 'actors' && Array.isArray(v) && v[0]?.name ? v[0] : undefined)) || {};
  const permalink =
    (typeof story.url === 'string' && /facebook\.com\//.test(story.url) ? story.url : undefined) ||
    bfs<string>(story, (k, v) =>
      (k === 'url' || k === 'permalink_url' || k === 'wwwURL') && typeof v === 'string' && /facebook\.com\/.+\/(posts|videos|reel)\/|story_fbid=|permalink\.php|\/photo/.test(v) ? v : undefined
    ) ||
    `https://www.facebook.com/${id}`;

  const reactions =
    bfs<number>(story, (k, v) => (k === 'reaction_count' && v && typeof v.count === 'number' ? v.count : undefined)) ??
    bfs<number>(story, (k, v) => (k === 'reactors' && v && typeof v.count === 'number' ? v.count : undefined)) ??
    0;
  const shares = bfs<number>(story, (k, v) => (k === 'share_count' && v && typeof v.count === 'number' ? v.count : undefined)) ?? 0;
  const comments =
    bfs<number>(story, (k, v) => (k === 'comments' && v && typeof v.total_count === 'number' ? v.total_count : undefined)) ??
    bfs<number>(story, (k, v) => (k === 'total_comment_count' && typeof v === 'number' ? v : undefined)) ??
    0;
  const views = bfs<number>(story, (k, v) => (k === 'video_view_count' || k === 'play_count') && typeof v === 'number' ? v : undefined) ?? 0;

  const hasVideo = bfs<boolean>(story, (k, v) => (k === '__typename' && v === 'Video' ? true : undefined)) === true;
  const hasPhoto = !hasVideo && bfs<boolean>(story, (k, v) => (k === '__typename' && v === 'Photo' ? true : undefined)) === true;

  const actorUrl = typeof actor.url === 'string' ? actor.url : typeof actor.profile_url === 'string' ? actor.profile_url : '';
  const actorId = typeof actor.id === 'string' && /^\d{5,25}$/.test(actor.id) ? actor.id : '';
  return {
    platform: 'facebook',
    id,
    handle: handleFromUrl(actorUrl) || (actorId ? `id-${actorId}` : ''),
    author_name: String(actor.name || ''),
    url: permalink,
    text,
    created_at: new Date(created * 1000).toISOString(),
    media: { type: hasVideo ? 'video' : hasPhoto ? 'image' : 'none', duration_sec: 0 },
    metrics: { likes: toCount(reactions), comments: toCount(comments), shares: toCount(shares), views: toCount(views) }
  };
}

/**
 * Collect the posts in one or more Facebook JSON values. A post seen twice
 * (once without its counters, once with) is merged, keeping the higher counts.
 */
export function parseFacebookStories(values: any[]): FacebookPost[] {
  const out = new Map<string, FacebookPost>();
  const stack: any[] = [...values];
  let guard = 0;
  while (stack.length && guard++ < 300000) {
    const node = stack.pop();
    if (!node || typeof node !== 'object') continue;
    if (Array.isArray(node)) {
      for (let i = node.length - 1; i >= 0; i--) stack.push(node[i]);
      continue;
    }
    if (node.__typename === 'Story' && node.post_id) {
      const post = parseStory(node);
      if (post) {
        const prev = out.get(post.id);
        if (!prev) {
          out.set(post.id, post);
        } else {
          prev.text = prev.text || post.text;
          prev.author_name = prev.author_name || post.author_name;
          prev.handle = prev.handle || post.handle;
          for (const k of ['likes', 'comments', 'shares', 'views'] as const) prev.metrics[k] = Math.max(prev.metrics[k], post.metrics[k]);
        }
        continue;
      }
    }
    for (const v of Object.values(node)) {
      if (v && typeof v === 'object') stack.push(v);
    }
  }
  return Array.from(out.values());
}
