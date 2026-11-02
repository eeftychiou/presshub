import sharp from 'sharp';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';

export interface GenerateCardOptions {
  title: string;
  subtitle?: string;
  category?: string;
  siteName?: string;
  width?: number;
  height?: number;
  outputDir?: string;
  backgroundBuffer?: Buffer;
}

export interface CardResult {
  filePath: string;
  width: number;
  height: number;
  sizeBytes: number;
}

/**
 * Escapes XML/SVG special characters to prevent SVG parsing errors and injection.
 */
export function escapeXml(str: string): string {
  if (!str) return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

/**
 * Word wraps text into lines fitting within maxCharsPerLine.
 */
export function wrapText(text: string, maxCharsPerLine: number, maxLines: number): string[] {
  if (!text) return [];
  const words = text.trim().split(/\s+/);
  const lines: string[] = [];
  let currentLine = '';

  for (const word of words) {
    const candidate = currentLine ? `${currentLine} ${word}` : word;
    if (candidate.length <= maxCharsPerLine) {
      currentLine = candidate;
    } else {
      if (currentLine) {
        lines.push(currentLine);
      }
      currentLine = word;
      if (lines.length === maxLines - 1) {
        break;
      }
    }
  }

  if (currentLine && lines.length < maxLines) {
    lines.push(currentLine);
  } else if (lines.length === maxLines && words.length > 0) {
    // Add ellipsis to last line if truncated
    const lastIdx = lines.length - 1;
    if (!lines[lastIdx].endsWith('...')) {
      lines[lastIdx] = lines[lastIdx].substring(0, Math.max(0, maxCharsPerLine - 3)) + '...';
    }
  }

  return lines;
}

/**
 * Clamps dimensions safely to bounds.
 */
export function clampDimension(val: number | undefined, defaultVal: number, min: number = 600, max: number = 2160): number {
  if (typeof val !== 'number' || isNaN(val)) return defaultVal;
  return Math.min(max, Math.max(min, Math.round(val)));
}

/**
 * Generates an SVG string layout for the branded social media card.
 */
export function buildCardSvg(options: {
  title: string;
  subtitle?: string;
  category?: string;
  siteName?: string;
  width: number;
  height: number;
  hasCustomBackground: boolean;
}): string {
  const { title, subtitle, category, siteName, width, height, hasCustomBackground } = options;

  const escTitle = escapeXml(title);
  const escSubtitle = subtitle ? escapeXml(subtitle) : '';
  const escCategory = (category || 'UPDATES').toUpperCase();
  const escSite = escapeXml(siteName || 'PressHub AI');

  const padding = Math.round(width * 0.08); // 8% padding
  const contentWidth = width - padding * 2;

  // Scale typography relative to a 1080px base
  const scale = width / 1080;
  const titleFontSize = Math.round(48 * scale);
  const titleLineHeight = Math.round(62 * scale);
  const subtitleFontSize = Math.round(28 * scale);
  const subtitleLineHeight = Math.round(38 * scale);
  const metaFontSize = Math.round(22 * scale);

  const maxTitleChars = Math.max(16, Math.floor(contentWidth / (titleFontSize * 0.58)));
  const titleLines = wrapText(title, maxTitleChars, 4);

  const maxSubtitleChars = Math.max(22, Math.floor(contentWidth / (subtitleFontSize * 0.55)));
  const subtitleLines = subtitle ? wrapText(subtitle, maxSubtitleChars, 2) : [];

  // Vertical layout positioning
  const topY = Math.round(padding * 1.1);
  const titleStartY = topY + Math.round(110 * scale);

  let curY = titleStartY;
  const titleTspans = titleLines
    .map((line, i) => `<tspan x="${padding}" y="${curY + i * titleLineHeight}">${escapeXml(line)}</tspan>`)
    .join('\n');

  curY += titleLines.length * titleLineHeight + Math.round(24 * scale);

  const subtitleTspans = subtitleLines
    .map((line, i) => `<tspan x="${padding}" y="${curY + i * subtitleLineHeight}">${escapeXml(line)}</tspan>`)
    .join('\n');

  const bottomY = height - padding;
  const categoryBadgeWidth = Math.max(120, Math.round(escCategory.length * 14 * scale + 36 * scale));
  const categoryBadgeHeight = Math.round(36 * scale);

  return `
<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bgGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#090d16" />
      <stop offset="45%" stop-color="#0f172a" />
      <stop offset="100%" stop-color="#1e1b4b" />
    </linearGradient>

    <linearGradient id="overlayGradient" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#090d16" stop-opacity="0.82" />
      <stop offset="60%" stop-color="#090d16" stop-opacity="0.88" />
      <stop offset="100%" stop-color="#04060b" stop-opacity="0.96" />
    </linearGradient>

    <linearGradient id="brandGradient" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#6366f1" />
      <stop offset="50%" stop-color="#8b5cf6" />
      <stop offset="100%" stop-color="#ec4899" />
    </linearGradient>

    <linearGradient id="pillGradient" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#3b82f6" />
      <stop offset="100%" stop-color="#2563eb" />
    </linearGradient>

    <filter id="shadow" x="-5%" y="-5%" width="110%" height="110%">
      <feDropShadow dx="0" dy="8" stdDeviation="12" flood-color="#000000" flood-opacity="0.6"/>
    </filter>
  </defs>

  ${
    hasCustomBackground
      ? `<rect width="${width}" height="${height}" fill="url(#overlayGradient)" />`
      : `<rect width="${width}" height="${height}" fill="url(#bgGradient)" />`
  }

  <!-- Accent top border glow -->
  <rect x="0" y="0" width="${width}" height="${Math.max(6, Math.round(8 * scale))}" fill="url(#brandGradient)" />

  <!-- Site Badge & Category Header -->
  <g id="header">
    <!-- Category Pill -->
    <rect x="${padding}" y="${topY}" width="${categoryBadgeWidth}" height="${categoryBadgeHeight}" rx="${categoryBadgeHeight / 2}" fill="url(#pillGradient)" />
    <text x="${padding + categoryBadgeWidth / 2}" y="${topY + categoryBadgeHeight / 2 + Math.round(5 * scale)}"
          fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="700" letter-spacing="1.2" text-anchor="middle">
      ${escapeXml(escCategory)}
    </text>

    <!-- Site Badge -->
    <text x="${width - padding}" y="${topY + categoryBadgeHeight / 2 + Math.round(5 * scale)}"
          fill="#cbd5e1" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="600" text-anchor="end">
      ${escSite}
    </text>
  </g>

  <!-- Title -->
  <text fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
        font-size="${titleFontSize}" font-weight="800" letter-spacing="-0.5" filter="url(#shadow)">
    ${titleTspans}
  </text>

  <!-- Subtitle -->
  ${
    subtitleLines.length > 0
      ? `<text fill="#94a3b8" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
              font-size="${subtitleFontSize}" font-weight="400" line-height="${subtitleLineHeight}">
          ${subtitleTspans}
        </text>`
      : ''
  }

  <!-- Footer Divider and CTA -->
  <g id="footer">
    <line x1="${padding}" y1="${bottomY - Math.round(45 * scale)}" x2="${width - padding}" y2="${bottomY - Math.round(45 * scale)}" stroke="#334155" stroke-width="1.5" />
    <circle cx="${padding + Math.round(8 * scale)}" cy="${bottomY - Math.round(12 * scale)}" r="${Math.round(6 * scale)}" fill="#10b981" />
    <text x="${padding + Math.round(26 * scale)}" y="${bottomY - Math.round(6 * scale)}"
          fill="#94a3b8" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="500">
      Verified Editorial • Powered by PressHub
    </text>
    <text x="${width - padding}" y="${bottomY - Math.round(6 * scale)}"
          fill="#6366f1" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="700" text-anchor="end">
      Read More →
    </text>
  </g>
</svg>
  `.trim();
}

/**
 * Generates a branded PNG card image file using sharp.
 */
export async function generateSocialCard(options: GenerateCardOptions): Promise<CardResult> {
  const width = clampDimension(options.width, 1080, 600, 2160);
  const height = clampDimension(options.height, 1080, 600, 2160);
  const outputDir = options.outputDir || path.resolve(process.cwd(), 'output');

  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const fileName = `card-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
  const filePath = path.join(outputDir, fileName);

  const hasCustomBackground = Boolean(options.backgroundBuffer && options.backgroundBuffer.length > 0);

  const svg = buildCardSvg({
    title: options.title || 'Breaking Update',
    subtitle: options.subtitle,
    category: options.category,
    siteName: options.siteName,
    width,
    height,
    hasCustomBackground
  });

  const svgBuffer = Buffer.from(svg);

  let sharpInstance: sharp.Sharp;

  if (hasCustomBackground && options.backgroundBuffer) {
    // Resize background image and composite SVG over it
    sharpInstance = sharp(options.backgroundBuffer)
      .resize(width, height, { fit: 'cover' })
      .composite([{ input: svgBuffer, top: 0, left: 0 }]);
  } else {
    // Sharp create blank canvas and composite SVG
    sharpInstance = sharp({
      create: {
        width,
        height,
        channels: 4,
        background: { r: 9, g: 13, b: 22, alpha: 1 }
      }
    }).composite([{ input: svgBuffer, top: 0, left: 0 }]);
  }

  await sharpInstance.png({ compressionLevel: 9 }).toFile(filePath);

  const stats = fs.statSync(filePath);

  return {
    filePath,
    width,
    height,
    sizeBytes: stats.size
  };
}
