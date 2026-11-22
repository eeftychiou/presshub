import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { contentBox, trimFlatBorders } from '../src/media/image-borders';
import { renderPostCard, postCardSvg, compactCount, clampLines, CARD } from '../src/media/post-card';
import { applyCardSpans, planSlots, MotionSlot } from '../src/media/short-video';
import { postCardOptions } from '../src/poller';
import { shortRenderKey } from '../src/media/short-cache';

const TEST_DIR = path.resolve(__dirname, 'tmp-post-card-test');

beforeAll(() => fs.mkdirSync(TEST_DIR, { recursive: true }));
afterAll(() => fs.rmSync(TEST_DIR, { recursive: true, force: true }));

/** A picture with detail (a diagonal gradient and shapes), like a real story image. */
async function picture(width: number, height: number): Promise<Buffer> {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#123456"/><stop offset="1" stop-color="#f5a623"/></linearGradient></defs><rect width="100%" height="100%" fill="url(#g)"/><circle cx="${width / 3}" cy="${height / 2}" r="${width / 5}" fill="#e11d48"/><rect x="${width / 2}" y="${height / 4}" width="${width / 4}" height="${height / 3}" fill="#22c55e"/></svg>`;
  return sharp(Buffer.from(svg)).png().toBuffer();
}

/** The picture drawn smaller on a flat grey canvas, the way the image model sometimes returns it. */
async function letterboxed(): Promise<Buffer> {
  const inner = await picture(900, 1000);
  return sharp({ create: { width: 900, height: 1600, channels: 3, background: '#646260' } })
    .composite([{ input: inner, top: 300, left: 0 }])
    .jpeg({ quality: 85 })
    .toBuffer();
}

describe('flat borders', () => {
  test('contentBox finds grey bands above and below the picture', () => {
    const w = 10;
    const h = 20;
    const grey = new Uint8Array(w * h).fill(100);
    for (let y = 4; y < 16; y++) for (let x = 0; x < w; x++) grey[y * w + x] = (x * 25 + y * 7) % 255;
    expect(contentBox(grey, w, h)).toEqual({ left: 0, top: 4, width: 10, height: 12 });
  });

  test('a picture without bands is left whole', async () => {
    const res = await trimFlatBorders(await picture(900, 1600));
    expect(res.trimmed).toBe(false);
  });

  test('a letterboxed picture loses its grey strips', async () => {
    const res = await trimFlatBorders(await letterboxed());
    expect(res.trimmed).toBe(true);
    const meta = await sharp(res.buffer).metadata();
    // The 1000 px tall picture is kept, the 2 x 300 px of grey are not.
    expect(meta.height).toBeGreaterThan(950);
    expect(meta.height).toBeLessThanOrEqual(1000);
    expect(meta.width).toBe(900);
  });

  test('a mostly plain image (more than half flat) is not cut', () => {
    const w = 10;
    const h = 20;
    const grey = new Uint8Array(w * h).fill(200);
    for (let y = 8; y < 12; y++) for (let x = 0; x < w; x++) grey[y * w + x] = x * 20;
    expect(contentBox(grey, w, h)).toEqual({ left: 0, top: 0, width: 10, height: 20 });
  });

  test('a plain area of another shade next to the band stays (it is picture)', () => {
    const w = 10;
    const h = 20;
    const grey = new Uint8Array(w * h).fill(100);
    for (let y = 3; y < 8; y++) grey.fill(200, y * w, (y + 1) * w);
    for (let y = 8; y < 17; y++) for (let x = 0; x < w; x++) grey[y * w + x] = (x * 25 + y * 7) % 255;
    expect(contentBox(grey, w, h)).toEqual({ left: 0, top: 3, width: 10, height: 14 });
  });

  test('a plain edge on one side only (a clear sky) is not cut', () => {
    const w = 10;
    const h = 20;
    const grey = new Uint8Array(w * h).fill(180);
    for (let y = 6; y < 20; y++) for (let x = 0; x < w; x++) grey[y * w + x] = (x * 25 + y * 7) % 255;
    expect(contentBox(grey, w, h)).toEqual({ left: 0, top: 0, width: 10, height: 20 });
  });
});

describe('post cards', () => {
  test('compactCount writes counts the way the feeds do', () => {
    expect(compactCount(950)).toBe('950');
    expect(compactCount(1234)).toBe('1,2K');
    expect(compactCount(23400)).toBe('23K');
    expect(compactCount(3_400_000)).toBe('3,4M');
    expect(compactCount(-5)).toBe('0');
  });

  test('clampLines ends a cut text with an ellipsis', () => {
    expect(clampLines(['ένα', 'δύο', 'τρία,'], 2)).toEqual(['ένα', 'δύο…']);
    expect(clampLines(['ένα'], 2)).toEqual(['ένα']);
  });

  test('the card shows the author, handle, platform and numbers, escaped', () => {
    const { svg, height } = postCardSvg({ platform: 'x', author: 'Νίκος <K>', handle: 'nikos_cy', text: 'Κείμενο & σχόλιο', likes: 1200, comments: 0, views: 30000 });
    expect(svg).toContain('Νίκος &lt;K&gt;');
    expect(svg).toContain('@nikos_cy');
    expect(svg).toContain('>X</text>');
    expect(svg).toContain('Κείμενο &amp; σχόλιο');
    expect(svg).toContain('1,2K');
    expect(svg).toContain('30K');
    // No comments count when there are none.
    expect(svg).not.toContain('>0</text>');
    expect(CARD.y + height).toBeLessThanOrEqual(CARD.maxBottom);
  });

  test('a long text is cut to fit above the captions', () => {
    const { lines, height } = postCardSvg({ platform: 'facebook', author: 'Philenews', text: 'λέξη '.repeat(400), likes: 5 });
    expect(lines.length).toBeGreaterThan(2);
    expect(lines[lines.length - 1].endsWith('…')).toBe(true);
    expect(CARD.y + height).toBeLessThanOrEqual(CARD.maxBottom);
  });

  test('a card without a name uses the handle', () => {
    expect(postCardSvg({ platform: 'tiktok', handle: 'maria.cy' }).svg).toContain('@maria.cy');
  });

  test('renderPostCard draws a 2x 9:16 frame with the post photo filling it', async () => {
    const dest = path.join(TEST_DIR, 'card.jpg');
    const photo = await letterboxed();
    expect(await renderPostCard({ platform: 'x', author: 'Νίκος', handle: 'n', text: 'Γεια', photo: 'https://pbs.twimg.com/a.jpg' }, dest, async () => photo)).toBe(true);
    const meta = await sharp(dest).metadata();
    expect([meta.width, meta.height]).toEqual([2160, 3840]);
    // The bottom of the frame is the photo, not the grey strip it came with.
    const { data } = await sharp(dest).extract({ left: 0, top: 3700, width: 2160, height: 40 }).greyscale().raw().toBuffer({ resolveWithObject: true });
    const values = Array.from(data);
    const mean = values.reduce((a, b) => a + b, 0) / values.length;
    const sd = Math.sqrt(values.reduce((a, b) => a + (b - mean) ** 2, 0) / values.length);
    expect(sd).toBeGreaterThan(8);
  });

  test('a photo that cannot be fetched leaves a card with its text', async () => {
    const dest = path.join(TEST_DIR, 'nophoto.jpg');
    expect(await renderPostCard({ platform: 'facebook', author: 'Philenews', text: 'Κείμενο', photo: 'https://fbcdn.net/x.jpg' }, dest, async () => null)).toBe(true);
    expect(fs.existsSync(dest)).toBe(true);
  });
});

describe('cards on the slots', () => {
  test('applyCardSpans puts a card on the slots inside its span, zooming only', () => {
    const slots = planSlots(20, 2); // 5 slots of 4 s
    const out = applyCardSpans(slots, [{ card: 0, start: 4, end: 12 }, { card: 1, start: 12, end: 14.5 }], [2, 3]);
    expect(out.map((s) => s.image)).toEqual([0, 2, 2, 3, 0]);
    for (const s of out.slice(1, 4)) expect(['in', 'out']).toContain(s.motion);
    expect(out[1].motion).not.toBe(out[2].motion);
    expect(out[2].motion).not.toBe(out[3].motion);
  });

  test('a card that could not be drawn leaves the story image', () => {
    const slots: MotionSlot[] = planSlots(8, 1);
    expect(applyCardSpans(slots, [{ card: 0, start: 0, end: 8 }], [-1]).map((s) => s.image)).toEqual([0, 0]);
  });

  test('postCardOptions checks what WordPress sent', () => {
    expect(postCardOptions({})).toEqual({});
    expect(postCardOptions({ cards: [{ platform: 'x' }], card_spans: [] })).toEqual({});
    expect(postCardOptions({ cards: [{ platform: 'x' }, 'bad'], card_spans: [{ card: 0, start: 0, end: 4 }] })).toEqual({});
    expect(postCardOptions({ cards: [{ platform: 'x' }], card_spans: [{ card: 0, start: 1, end: 4 }, { card: 3, start: 0, end: 2 }, { card: 0, start: 5, end: 5 }] })).toEqual({
      postCards: [{ platform: 'x' }],
      cardSpans: [{ card: 0, start: 1, end: 4 }],
    });
  });

  test('the render key changes with the cards', () => {
    const base = { hook: 'h', audioUrl: 'a' };
    expect(shortRenderKey(base)).not.toBe(shortRenderKey({ ...base, postCards: [{ platform: 'x' }], cardSpans: [{ card: 0, start: 0, end: 4 }] }));
  });
});
