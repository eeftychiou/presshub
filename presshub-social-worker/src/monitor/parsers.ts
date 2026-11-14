/**
 * Pure parsers for the JSON that X and TikTok web pages load in the background.
 *
 * Reading these responses (instead of the rendered page) keeps working through
 * layout redesigns. The X walker looks for any object shaped like a Tweet, so
 * it does not depend on the exact timeline nesting.
 */

export type MonitorPlatform = 'x' | 'tiktok';

export interface VideoSource {
  /** Direct media URL to download. */
  url: string;
  /** Estimated size in bytes (0 when unknown). */
  estimatedBytes: number;
  /** Extra request headers needed for the download (e.g. Referer). */
  headers?: Record<string, string>;
}

export interface ScannedPost {
  platform: MonitorPlatform;
  id: string;
  handle: string;
  author_name: string;
  url: string;
  text: string;
  /** ISO 8601. */
  created_at: string;
  media: { type: 'video' | 'image' | 'none'; duration_sec: number };
  /** Not sent to WordPress; used for the download step. */
  video?: VideoSource[];
  /** Pinned to the top of the profile (often old). Not sent to WordPress. */
  pinned?: boolean;
  /** Engagement counts as shown when the post was read (used by the trending roundup). */
  metrics?: PostMetrics;
}

export interface PostMetrics {
  likes: number;
  comments: number;
  shares: number;
  views: number;
}

/** Count from a number or a numeric string ("1234"); 0 otherwise. */
export function toCount(v: any): number {
  const n = typeof v === 'string' ? Number(v.replace(/[,\s]/g, '')) : Number(v);
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
}

export interface XVideoVariant {
  bitrate?: number;
  content_type?: string;
  url?: string;
}

/**
 * Order X mp4 variants for download: the highest quality whose estimated size
 * fits the cap first, then smaller ones as fallbacks. Variants that are
 * clearly too big are dropped.
 */
export function rankXVideoVariants(variants: XVideoVariant[], durationSec: number, maxBytes: number): VideoSource[] {
  const mp4 = (variants || [])
    .filter((v) => v && v.url && (v.content_type || '').toLowerCase() === 'video/mp4')
    .map((v) => ({
      url: String(v.url),
      bitrate: typeof v.bitrate === 'number' ? v.bitrate : 0,
      estimatedBytes: Math.round(((typeof v.bitrate === 'number' ? v.bitrate : 0) / 8) * Math.max(1, durationSec) * 1.1)
    }));
  const fitting = mp4.filter((v) => v.estimatedBytes <= maxBytes).sort((a, b) => b.bitrate - a.bitrate);
  return fitting.map((v) => ({ url: v.url, estimatedBytes: v.estimatedBytes }));
}

function xUser(tweet: any): { handle: string; name: string } {
  const u = tweet?.core?.user_results?.result || {};
  const handle = String(u?.core?.screen_name || u?.legacy?.screen_name || '');
  const name = String(u?.core?.name || u?.legacy?.name || handle);
  return { handle, name };
}

function expandXText(legacy: any, noteText?: string): string {
  let text = String(noteText || legacy?.full_text || '');
  const urls: any[] = legacy?.entities?.urls || [];
  for (const u of urls) {
    if (u?.url && u?.expanded_url) {
      text = text.split(String(u.url)).join(String(u.expanded_url));
    }
  }
  const media: any[] = legacy?.extended_entities?.media || legacy?.entities?.media || [];
  for (const m of media) {
    if (m?.url) {
      text = text.split(String(m.url)).join('');
    }
  }
  return text
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .trim();
}

/**
 * Parse one X tweet object (the `result` of a tweet_results entry).
 * Returns null for retweets and for replies to other accounts.
 */
export function parseXTweet(node: any, maxVideoBytes: number): ScannedPost | null {
  const tweet = node?.__typename === 'TweetWithVisibilityResults' ? node.tweet : node;
  const legacy = tweet?.legacy;
  const id = String(legacy?.id_str || tweet?.rest_id || '');
  if (!legacy || !/^\d+$/.test(id)) {
    return null;
  }
  const { handle, name } = xUser(tweet);
  if (!handle) {
    return null;
  }
  if (legacy.retweeted_status_result || /^RT @/.test(String(legacy.full_text || ''))) {
    return null;
  }
  const replyTo = String(legacy.in_reply_to_screen_name || '');
  if (legacy.in_reply_to_status_id_str && replyTo.toLowerCase() !== handle.toLowerCase()) {
    return null;
  }

  const created = new Date(String(legacy.created_at || ''));
  if (isNaN(created.getTime())) {
    return null;
  }

  const noteText = tweet?.note_tweet?.note_tweet_results?.result?.text;
  const media: any[] = legacy?.extended_entities?.media || [];
  const videoMedia = media.find((m) => m?.type === 'video' || m?.type === 'animated_gif');
  let mediaInfo: ScannedPost['media'] = { type: 'none', duration_sec: 0 };
  let video: VideoSource[] | undefined;
  if (videoMedia) {
    const durationSec = Math.round(Number(videoMedia?.video_info?.duration_millis || 0) / 1000);
    mediaInfo = { type: 'video', duration_sec: durationSec };
    video = rankXVideoVariants(videoMedia?.video_info?.variants || [], durationSec, maxVideoBytes);
  } else if (media.some((m) => m?.type === 'photo')) {
    mediaInfo = { type: 'image', duration_sec: 0 };
  }

  return {
    platform: 'x',
    id,
    handle: handle.toLowerCase(),
    author_name: name,
    url: `https://x.com/${handle}/status/${id}`,
    text: expandXText(legacy, noteText),
    created_at: created.toISOString(),
    media: mediaInfo,
    video,
    metrics: {
      likes: toCount(legacy.favorite_count),
      comments: toCount(legacy.reply_count),
      shares: toCount(legacy.retweet_count) + toCount(legacy.quote_count),
      views: toCount(tweet?.views?.count)
    }
  };
}

/**
 * Walk any X GraphQL response and collect the tweets in it, in the order the
 * timeline lists them (newest first). Quoted and retweeted tweets are not
 * descended into. Tweets under a pinned entry are flagged `pinned`.
 */
export function parseXGraphql(json: any, maxVideoBytes: number): ScannedPost[] {
  const out = new Map<string, ScannedPost>();
  // Depth-first, children pushed in reverse so they pop in document order.
  const stack: { node: any; pinned: boolean }[] = [{ node: json, pinned: false }];
  let guard = 0;
  while (stack.length && guard++ < 200000) {
    const { node, pinned: parentPinned } = stack.pop() as { node: any; pinned: boolean };
    if (!node || typeof node !== 'object') {
      continue;
    }
    if (Array.isArray(node)) {
      for (let i = node.length - 1; i >= 0; i--) stack.push({ node: node[i], pinned: parentPinned });
      continue;
    }
    const pinned =
      parentPinned ||
      node.type === 'TimelinePinEntry' ||
      /^pinned/i.test(String(node.entryId || '')) ||
      node.socialContext?.contextType === 'Pin';
    const typename = node.__typename;
    if ((typename === 'Tweet' || typename === 'TweetWithVisibilityResults') && (node.legacy || node.tweet)) {
      const post = parseXTweet(node, maxVideoBytes);
      if (post && !out.has(post.id)) {
        if (pinned) post.pinned = true;
        out.set(post.id, post);
      }
      continue;
    }
    const entries = Object.entries(node);
    for (let i = entries.length - 1; i >= 0; i--) {
      const [key, value] = entries[i];
      if (key === 'quoted_status_result' || key === 'retweeted_status_result') continue;
      if (value && typeof value === 'object') stack.push({ node: value, pinned });
    }
  }
  return Array.from(out.values());
}

/**
 * Parse a TikTok /api/post/item_list/ response.
 */
export function parseTikTokItemList(json: any, maxVideoBytes: number): ScannedPost[] {
  // Profile / hashtag pages: itemList. Video search: item_list. General search: data[].item.
  const items: any[] = Array.isArray(json?.itemList)
    ? json.itemList
    : Array.isArray(json?.items)
      ? json.items
      : Array.isArray(json?.item_list)
        ? json.item_list
        : Array.isArray(json?.data)
          ? json.data.map((d: any) => d?.item).filter(Boolean)
          : [];
  const out: ScannedPost[] = [];
  for (const item of items) {
    const id = String(item?.id || '');
    const handle = String(item?.author?.uniqueId || item?.author || '').toLowerCase();
    const createdSec = Number(item?.createTime || 0);
    if (!/^\d+$/.test(id) || !handle || !createdSec) {
      continue;
    }
    const isPhoto = Boolean(item?.imagePost);
    const durationSec = Number(item?.video?.duration || 0);
    const sources: VideoSource[] = [];
    if (!isPhoto) {
      const headers = { Referer: 'https://www.tiktok.com/' };
      const bitrates: any[] = Array.isArray(item?.video?.bitrateInfo) ? item.video.bitrateInfo : [];
      const ranked = bitrates
        .map((b) => ({
          url: b?.PlayAddr?.UrlList?.[0],
          bytes: Number(b?.PlayAddr?.DataSize || 0) || Math.round((Number(b?.Bitrate || 0) / 8) * Math.max(1, durationSec))
        }))
        .filter((b) => b.url && (b.bytes === 0 || b.bytes <= maxVideoBytes))
        .sort((a, b) => b.bytes - a.bytes);
      for (const b of ranked) sources.push({ url: String(b.url), estimatedBytes: b.bytes, headers });
      for (const u of [item?.video?.playAddr, item?.video?.downloadAddr]) {
        if (u && typeof u === 'string' && !sources.some((s) => s.url === u)) {
          sources.push({ url: u, estimatedBytes: 0, headers });
        }
      }
    }
    out.push({
      platform: 'tiktok',
      id,
      handle,
      author_name: String(item?.author?.nickname || handle),
      url: `https://www.tiktok.com/@${handle}/${isPhoto ? 'photo' : 'video'}/${id}`,
      text: String(item?.desc || '').trim(),
      created_at: new Date(createdSec * 1000).toISOString(),
      media: isPhoto ? { type: 'image', duration_sec: 0 } : { type: 'video', duration_sec: Math.round(durationSec) },
      video: isPhoto ? undefined : sources,
      metrics: {
        likes: toCount(item?.statsV2?.diggCount ?? item?.stats?.diggCount),
        comments: toCount(item?.statsV2?.commentCount ?? item?.stats?.commentCount),
        shares: toCount(item?.statsV2?.shareCount ?? item?.stats?.shareCount),
        views: toCount(item?.statsV2?.playCount ?? item?.stats?.playCount)
      },
      ...(item?.isPinnedItem ? { pinned: true } : {})
    });
  }
  return out;
}

/** Strip worker-only fields before sending a post to the Social Monitor. */
export function toWirePost(p: ScannedPost): Omit<ScannedPost, 'video' | 'pinned' | 'metrics'> {
  const { video, pinned, metrics, ...rest } = p;
  return rest;
}
