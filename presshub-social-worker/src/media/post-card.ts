/**
 * Post cards for the trending roundup short: one frame per social media post the voice
 * talks about, so viewers see the post itself and who wrote it. The card is drawn from
 * what the trending scan already collected (platform, author, @handle, text, numbers and
 * the post's own photo), never a screenshot, so it needs no logged-in browser.
 *
 * The frame is 9:16 at 2x (like the short's story images, so the zoom stays sharp): the
 * post's own photo fills the frame (cover crop, slightly darkened; the branded gradient
 * when the post has none), and a dark card with the author, the text and the numbers sits
 * between the topic line and the captions, so the captions never cover the post's text.
 * The card stays inside the part of the frame a 1.15 zoom keeps. Tests in
 * tests/post-card.test.ts.
 */
import sharp from 'sharp';
import { escapeXml, fetchImageBuffer } from './card-generator';
import { fitText } from './photo-slides';
import { trimFlatBorders } from './image-borders';
import { WorkerLogger } from '../logger';

/** One post as WordPress hands it over (`tiktok_short.cards[]`). */
export interface PostCard {
  platform: string;
  author?: string;
  handle?: string;
  text?: string;
  likes?: number;
  comments?: number;
  shares?: number;
  views?: number;
  /** The post's own photo or video cover (https), when it has one. */
  photo?: string;
}

/** Layout on the 1080x1920 canvas; the file is drawn at SCALE times that. */
const W = 1080;
const H = 1920;
const SCALE = 2;
/** The card sits between the topic line (ends near y 420) and the captions (centred at 1150). */
export const CARD = { x: 110, y: 450, width: 860, pad: 44, radius: 40, maxBottom: 1040 };
const FONT = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

const PLATFORMS: Record<string, { label: string; color: string }> = {
  x: { label: 'X', color: '#000000' },
  twitter: { label: 'X', color: '#000000' },
  tiktok: { label: 'TikTok', color: '#fe2c55' },
  facebook: { label: 'Facebook', color: '#1877f2' },
  instagram: { label: 'Instagram', color: '#d62976' },
};

/** A count the way the feeds show it, Greek style: 950, 1,2K, 3,4M. */
export function compactCount(n: number): string {
  const v = Math.max(0, Math.floor(Number(n) || 0));
  const fmt = (x: number, unit: string) => `${(Math.round(x * 10) / 10).toString().replace('.', ',')}${unit}`;
  if (v >= 1_000_000) return fmt(v / 1_000_000, 'M');
  if (v >= 10_000) return `${Math.round(v / 1000)}K`;
  if (v >= 1000) return fmt(v / 1000, 'K');
  return String(v);
}

/** The text cut to `maxLines` lines, the last one ending in an ellipsis when text was left out. */
export function clampLines(lines: string[], maxLines: number): string[] {
  if (lines.length <= maxLines) return lines;
  const kept = lines.slice(0, Math.max(1, maxLines));
  kept[kept.length - 1] = `${kept[kept.length - 1].replace(/[\s.,;:!?…-]+$/u, '')}…`;
  return kept;
}

// Small icons (24x24 paths), drawn in the footer.
const ICONS = {
  likes: 'M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.8 4.5c2.2 0 3.6 1.2 5.2 3 1.6-1.8 3-3 5.2-3 3.8 0 5.9 3.9 4.4 7.3C19.5 16.4 12 21 12 21z',
  comments: 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  shares: 'M7 7h10l-3-3 1.4-1.4L21 8l-5.6 5.4L14 12l3-3H7v4H5V9a2 2 0 0 1 2-2zm10 10H7l3 3-1.4 1.4L3 16l5.6-5.4L10 12l-3 3h10v-4h2v4a2 2 0 0 1-2 2z',
  views: 'M12 5c5 0 9 4.5 10 7-1 2.5-5 7-10 7S3 14.5 2 12c1-2.5 5-7 10-7zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
};

export interface CardLayout {
  height: number;
  /** Text lines shown (the text is cut with an ellipsis to fit the card). */
  lines: string[];
  svg: string;
}

/** The card drawn as SVG on the 1080x1920 canvas, drawn over the backdrop by renderPostCard(). */
export function postCardSvg(card: PostCard): CardLayout {
  const p = PLATFORMS[String(card.platform || '').toLowerCase()] || { label: String(card.platform || '') || '?', color: '#334155' };
  const inner = CARD.width - CARD.pad * 2;
  const x0 = CARD.x + CARD.pad;
  const author = String(card.author || '').trim() || (card.handle ? `@${String(card.handle).replace(/^@/, '')}` : p.label);
  const handle = card.handle ? `@${String(card.handle).replace(/^@/, '')}` : '';
  const text = String(card.text || '').replace(/\s+/g, ' ').trim();
  const stats = (['likes', 'comments', 'shares', 'views'] as const).filter((k) => Number(card[k]) > 0);

  // Header: avatar initial, name and @handle, platform pill.
  const headerTop = CARD.y + CARD.pad;
  const avatar = 84;
  const pillW = Math.round(p.label.length * 17 + 52);
  const nameMaxChars = Math.max(8, Math.floor((inner - avatar - 24 - pillW - 16) / (40 * 0.56)));
  const name = author.length > nameMaxChars ? `${author.slice(0, nameMaxChars - 1).trim()}…` : author;
  const nameY = handle ? headerTop + 38 : headerTop + 56;
  let y = headerTop + avatar + 34;

  // Text: as large as fits between the header and the numbers.
  const footerH = stats.length ? 80 : 0;
  const room = CARD.maxBottom - CARD.pad - footerH - y;
  const fit = text ? fitText(text, inner, room, 46, 32) : { size: 40, lineHeight: 52, lines: [] as string[] };
  const lines = clampLines(fit.lines, Math.max(1, Math.floor(room / fit.lineHeight)));
  const textTop = y + Math.round(fit.size * 0.95);
  y += lines.length * fit.lineHeight + (lines.length ? 12 : 0);

  // Footer: the numbers the post has.
  const footerY = y + 44;
  let fx = x0;
  const footer = stats
    .map((k) => {
      const label = compactCount(Number(card[k]));
      const part = `<g transform="translate(${fx},${footerY - 30}) scale(1.6)"><path d="${ICONS[k]}" fill="#94a3b8"/></g><text x="${fx + 50}" y="${footerY + 2}" font-family="${FONT}" font-size="34" font-weight="700" fill="#cbd5e1">${escapeXml(label)}</text>`;
      fx += 50 + label.length * 20 + 56;
      return part;
    })
    .join('');
  if (stats.length) y = footerY + 12;
  const height = Math.min(CARD.maxBottom - CARD.y, Math.max(avatar + CARD.pad * 2, y + CARD.pad - CARD.y));

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W * SCALE}" height="${H * SCALE}" viewBox="0 0 ${W} ${H}">
  <rect x="${CARD.x}" y="${CARD.y}" width="${CARD.width}" height="${height}" rx="${CARD.radius}" fill="#0b1220" fill-opacity="0.92" stroke="#ffffff" stroke-opacity="0.16" stroke-width="2"/>
  <circle cx="${x0 + avatar / 2}" cy="${headerTop + avatar / 2}" r="${avatar / 2}" fill="${p.color}" stroke="#ffffff" stroke-opacity="0.35" stroke-width="2"/>
  <text x="${x0 + avatar / 2}" y="${headerTop + avatar / 2 + 15}" font-family="${FONT}" font-size="42" font-weight="800" fill="#ffffff" text-anchor="middle">${escapeXml(Array.from(author.replace(/^@/, ''))[0]?.toUpperCase() || '?')}</text>
  <text x="${x0 + avatar + 24}" y="${nameY}" font-family="${FONT}" font-size="40" font-weight="800" fill="#ffffff">${escapeXml(name)}</text>
  ${handle ? `<text x="${x0 + avatar + 24}" y="${headerTop + 80}" font-family="${FONT}" font-size="32" fill="#94a3b8">${escapeXml(handle)}</text>` : ''}
  <rect x="${CARD.x + CARD.width - CARD.pad - pillW}" y="${headerTop + 14}" width="${pillW}" height="56" rx="28" fill="${p.color}" stroke="#ffffff" stroke-opacity="0.35" stroke-width="2"/>
  <text x="${CARD.x + CARD.width - CARD.pad - pillW / 2}" y="${headerTop + 52}" font-family="${FONT}" font-size="30" font-weight="800" fill="#ffffff" text-anchor="middle">${escapeXml(p.label)}</text>
  ${lines.length ? `<text font-family="${FONT}" font-size="${fit.size}" font-weight="600" fill="#f8fafc">${lines.map((l, i) => `<tspan x="${x0}" y="${textTop + i * fit.lineHeight}">${escapeXml(l)}</tspan>`).join('')}</text>` : ''}
  ${footer}
</svg>`;
  return { height, lines, svg };
}

async function backdrop(photo: Buffer | null): Promise<Buffer> {
  if (photo) {
    try {
      // The post's photo fills the frame (no band), a little darker so the card and the
      // captions stand out.
      return await sharp(photo)
        .resize(W * SCALE, H * SCALE, { fit: 'cover', position: sharp.strategy.attention })
        .modulate({ brightness: 0.8, saturation: 1.05 })
        .jpeg({ quality: 90 })
        .toBuffer();
    } catch {
      // Falls through to the gradient.
    }
  }
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W * SCALE}" height="${H * SCALE}" viewBox="0 0 ${W} ${H}"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0f172a"/><stop offset="1" stop-color="#1e3a8a"/></linearGradient></defs><rect width="100%" height="100%" fill="url(#g)"/></svg>`;
  return sharp(Buffer.from(svg)).jpeg({ quality: 90 }).toBuffer();
}

/**
 * Draws one post card into `dest` (JPEG, 2160x3840). A photo that cannot be fetched or read
 * leaves the card without it (a warning), so the post is still shown with its text.
 */
export async function renderPostCard(
  card: PostCard,
  dest: string,
  fetchFn: (url: string) => Promise<Buffer | null> = (u) => fetchImageBuffer(u, 15000)
): Promise<boolean> {
  try {
    let photo: Buffer | null = null;
    if (card.photo && /^https:\/\//.test(card.photo)) {
      const raw = await fetchFn(card.photo);
      if (raw) {
        try {
          photo = (await trimFlatBorders(raw)).buffer;
        } catch (err: any) {
          WorkerLogger.warn('PostCard', `The photo of a ${card.platform} post could not be read (${err?.message || err}); drawing the card without it.`);
        }
      } else {
        WorkerLogger.warn('PostCard', `The photo of a ${card.platform} post could not be downloaded (${card.photo}); drawing the card without it.`);
      }
    }
    const layers: sharp.OverlayOptions[] = [{ input: Buffer.from(postCardSvg(card).svg), left: 0, top: 0 }];
    await sharp(await backdrop(photo)).composite(layers).jpeg({ quality: 90 }).toFile(dest);
    return true;
  } catch (err: any) {
    WorkerLogger.warn('PostCard', `Could not draw the card of a ${card.platform} post: ${err?.message || err}`);
    return false;
  }
}
