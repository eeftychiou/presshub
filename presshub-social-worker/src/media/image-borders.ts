/**
 * Flat borders baked into an image: image models sometimes return a 9:16 picture drawn
 * smaller inside the canvas, with flat grey (or black, or white) strips above and below
 * it. A cover crop keeps those strips, so the short shows a grey band. trimFlatBorders()
 * cuts them off first, so the crop fills the frame with the picture itself.
 *
 * A border row (or column) is one whose pixels are all nearly the same shade. Only runs
 * of such rows that touch BOTH opposite edges count (a letterbox has a band on each side;
 * a photo with a plain sky at the top, or a white backdrop on one side, does not), and the
 * picture left over must keep at least MIN_KEEP of the side.
 */
import sharp from 'sharp';

/** Width the image is scaled to for the border check (enough to see a band, cheap to scan). */
const PROBE_WIDTH = 200;
/** Largest standard deviation of a row or column (0-255 grey) that still counts as flat. */
const FLAT_STDDEV = 6;
/** Largest difference in mean shade (0-255) between a band's lines. */
const BAND_SHADE = 10;
/** Shortest band that is trimmed, as a share of the side (smaller ones are noise or a frame line). */
const MIN_BAND = 0.02;
/** The picture left after trimming keeps at least this share of each side. */
const MIN_KEEP = 0.5;

export interface Box {
  left: number;
  top: number;
  width: number;
  height: number;
}

function stats(values: number[]): { mean: number; sd: number } {
  if (values.length === 0) return { mean: 0, sd: 0 };
  const mean = values.reduce((a, b) => a + b, 0) / values.length;
  return { mean, sd: Math.sqrt(values.reduce((a, b) => a + (b - mean) * (b - mean), 0) / values.length) };
}

/**
 * How many lines from one end are a band: flat lines of the same shade as the edge line
 * (a plain area of another shade next to the band is picture, not border).
 */
function bandFrom(count: number, at: (k: number) => number[]): number {
  const edge = stats(at(0));
  if (edge.sd >= FLAT_STDDEV) return 0;
  let n = 0;
  while (n < count) {
    const s = stats(at(n));
    if (s.sd >= FLAT_STDDEV || Math.abs(s.mean - edge.mean) > BAND_SHADE) break;
    n++;
  }
  return n;
}

/** How many lines from each end are a band, out of `count` lines, given a line's values. */
function flatRun(count: number, line: (i: number) => number[]): [number, number] {
  const start = bandFrom(count, (k) => line(k));
  const end = bandFrom(count - start, (k) => line(count - 1 - k));
  return [start, end];
}

/**
 * The part of a greyscale image (one byte per pixel, row by row) left after cutting its
 * flat borders, in that image's pixels. The whole image when there is nothing to trim.
 */
export function contentBox(grey: Uint8Array | Buffer, width: number, height: number): Box {
  const full: Box = { left: 0, top: 0, width, height };
  if (width < 2 || height < 2 || grey.length < width * height) return full;
  const row = (y: number) => Array.from(grey.subarray(y * width, (y + 1) * width));
  const col = (x: number) => {
    const out: number[] = [];
    for (let y = 0; y < height; y++) out.push(grey[y * width + x]);
    return out;
  };
  let [top, bottom] = flatRun(height, row);
  // Both sides must have a band: one plain edge alone is picture (sky, backdrop).
  if (top < height * MIN_BAND || bottom < height * MIN_BAND || height - top - bottom < height * MIN_KEEP) {
    top = 0;
    bottom = 0;
  }
  // Columns are checked inside the rows that are kept.
  const keptRows = height - top - bottom;
  const colInside = (x: number) => col(x).slice(top, top + keptRows);
  let [left, right] = flatRun(width, colInside);
  if (left < width * MIN_BAND || right < width * MIN_BAND || width - left - right < width * MIN_KEEP) {
    left = 0;
    right = 0;
  }
  return { left, top, width: width - left - right, height: keptRows };
}

/**
 * The image without its flat borders, as a buffer (the input unchanged when it has none).
 * `trimmed` says whether anything was cut, for the log.
 */
export async function trimFlatBorders(input: string | Buffer): Promise<{ buffer: Buffer; trimmed: boolean; box?: Box }> {
  // Upright first (EXIF rotation applied), so the box is in the pixels that get cropped.
  const buffer = await sharp(input).rotate().toBuffer();
  const meta = await sharp(buffer).metadata();
  const w = meta.width || 0;
  const h = meta.height || 0;
  if (w < 2 || h < 2) return { buffer, trimmed: false };
  const probeW = Math.min(PROBE_WIDTH, w);
  const probeH = Math.max(2, Math.round((h * probeW) / w));
  const { data, info } = await sharp(buffer)
    .resize(probeW, probeH, { fit: 'fill' })
    .greyscale()
    .raw()
    .toBuffer({ resolveWithObject: true });
  const box = contentBox(data, info.width, info.height);
  if (box.width === info.width && box.height === info.height) return { buffer, trimmed: false };
  const sx = w / info.width;
  const sy = h / info.height;
  // Cut one probe pixel further in, so the blurred edge of the band does not stay.
  const left = Math.min(w - 1, Math.round((box.left + (box.left ? 1 : 0)) * sx));
  const top = Math.min(h - 1, Math.round((box.top + (box.top ? 1 : 0)) * sy));
  const right = Math.min(w - left, Math.round((box.left + box.width - (box.left + box.width < info.width ? 1 : 0)) * sx) - left);
  const bottom = Math.min(h - top, Math.round((box.top + box.height - (box.top + box.height < info.height ? 1 : 0)) * sy) - top);
  if (right < 2 || bottom < 2) return { buffer, trimmed: false };
  const out = await sharp(buffer).extract({ left, top, width: right, height: bottom }).toBuffer();
  return { buffer: out, trimmed: true, box: { left, top, width: right, height: bottom } };
}
