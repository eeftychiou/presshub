import sharp from 'sharp';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import { WorkerLogger } from '../logger';

export interface GenerateCardOptions {
  title: string;
  subtitle?: string;
  category?: string;
  siteName?: string;
  width?: number;
  height?: number;
  outputDir?: string;
  backgroundBuffer?: Buffer;
  mediaUrl?: string;
  backgroundUrl?: string;
  isPodcast?: boolean;
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
 * Resolves local media asset file path by checking common worker and repo directories.
 */
export function getMediaAssetPath(fileName: string): string | null {
  const candidates = [
    path.resolve(process.cwd(), 'media', fileName),
    path.resolve(__dirname, '../../media', fileName),
    path.resolve(__dirname, '../media', fileName),
    path.resolve(__dirname, '../../../media', fileName),
    path.resolve(__dirname, '../../../presshub-social-worker/media', fileName)
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) {
      return c;
    }
  }
  return null;
}

/**
 * Downloads an image buffer from an HTTP/HTTPS URL with timeout.
 */
export async function fetchImageBuffer(url: string, timeoutMs: number = 10000): Promise<Buffer | null> {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const res = await fetch(url, {
      signal: controller.signal,
      headers: { 'User-Agent': 'Mozilla/5.0 PressHub-CardGenerator/1.0' }
    });
    clearTimeout(timer);
    if (res.ok) {
      const arrayBuffer = await res.arrayBuffer();
      return Buffer.from(arrayBuffer);
    }
  } catch (err: any) {
    console.warn(`[PressHub Card Generator] Failed to download image from ${url}:`, err.message);
  }
  return null;
}

/**
 * Generates an SVG string layout for the branded social media card matching PressHub website styling.
 * Branding: dark slate/navy (#0f172a, #070c17), electric blue / cyan (#0284c7, #38bdf8), accent crimson/gold (#e11d48, #2563eb).
 */
export function buildCardSvg(options: {
  title: string;
  subtitle?: string;
  category?: string;
  siteName?: string;
  width: number;
  height: number;
  hasCustomBackground: boolean;
  hasLogoIcon?: boolean;
  isPodcast?: boolean;
}): string {
  const { title, subtitle, category, siteName, width, height, hasCustomBackground, hasLogoIcon, isPodcast } = options;

  const escTitle = escapeXml(title);
  const escSubtitle = subtitle ? escapeXml(subtitle) : '';
  const escCategory = (category || (isPodcast ? 'PODCAST BRIEFING' : 'UPDATES')).toUpperCase();
  const escSite = escapeXml(siteName || 'PressHub.Cy');

  const isVertical = height > width * 1.2;
  const padding = Math.round(width * 0.08); // 8% padding
  const contentWidth = width - padding * 2;

  // Scale typography relative to a 1080px base
  const scale = width / 1080;
  const titleFontSize = Math.round((isVertical ? 46 : 48) * scale);
  const titleLineHeight = Math.round((isVertical ? 60 : 62) * scale);
  const subtitleFontSize = Math.round(28 * scale);
  const subtitleLineHeight = Math.round(38 * scale);
  const metaFontSize = Math.round(22 * scale);

  const maxTitleChars = Math.max(16, Math.floor(contentWidth / (titleFontSize * 0.58)));
  const titleLines = wrapText(title, maxTitleChars, isVertical ? 5 : 4);

  const maxSubtitleChars = Math.max(22, Math.floor(contentWidth / (subtitleFontSize * 0.55)));
  const subtitleLines = subtitle ? wrapText(subtitle, maxSubtitleChars, isVertical ? 3 : 2) : [];

  // Vertical layout positioning
  const topY = Math.round(padding * 1.1);
  const categoryBadgeWidth = Math.max(140, Math.round(escCategory.length * 14 * scale + 40 * scale));
  const categoryBadgeHeight = Math.round(38 * scale);

  // Logo offset on top-right
  const logoSize = hasLogoIcon ? Math.max(36, Math.round(50 * scale)) : 0;
  const siteTextX = hasLogoIcon ? width - padding - logoSize - Math.round(14 * scale) : width - padding;

  // Vertical starting point
  let titleStartY: number;
  if (isVertical) {
    // In vertical 9:16 layout, push title into the middle-lower zone
    titleStartY = Math.round(height * 0.44);
  } else {
    titleStartY = topY + Math.round(110 * scale);
  }

  let curY = titleStartY;
  const titleTspans = titleLines
    .map((line, i) => `<tspan x="${padding}" y="${curY + i * titleLineHeight}">${escapeXml(line)}</tspan>`)
    .join('\n');

  curY += titleLines.length * titleLineHeight + Math.round(24 * scale);

  const subtitleTspans = subtitleLines
    .map((line, i) => `<tspan x="${padding}" y="${curY + i * subtitleLineHeight}">${escapeXml(line)}</tspan>`)
    .join('\n');

  const bottomY = height - padding;

  // Center play badge for vertical or podcast cards
  let centerBadge = '';
  if (isVertical) {
    const badgeCenterY = Math.round(height * 0.28);
    const badgeRadius = Math.round(52 * scale);
    const playOffset = Math.round(18 * scale);
    centerBadge = `
    <!-- Play / Podcast central indicator -->
    <g id="center-play-badge" filter="url(#shadow)">
      <circle cx="${width / 2}" cy="${badgeCenterY}" r="${badgeRadius + 8 * scale}" fill="#0284c7" fill-opacity="0.25" />
      <circle cx="${width / 2}" cy="${badgeCenterY}" r="${badgeRadius}" fill="#0284c7" />
      <polygon points="${width / 2 - playOffset * 0.6},${badgeCenterY - playOffset} ${width / 2 + playOffset},${badgeCenterY} ${width / 2 - playOffset * 0.6},${badgeCenterY + playOffset}" fill="#ffffff" />
    </g>
    `;
  }

  return `
<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- Dark slate / navy background gradient -->
    <linearGradient id="bgGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#070c17" />
      <stop offset="50%" stop-color="#0f172a" />
      <stop offset="100%" stop-color="#03254c" />
    </linearGradient>

    <!-- Translucent overlay when featured image background is active -->
    <linearGradient id="overlayGradient" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#070c17" stop-opacity="0.80" />
      <stop offset="45%" stop-color="#0f172a" stop-opacity="0.88" />
      <stop offset="100%" stop-color="#03162b" stop-opacity="0.96" />
    </linearGradient>

    <!-- Top accent border: electric blue / cyan to royal blue to accent crimson -->
    <linearGradient id="brandGradient" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#0284c7" />
      <stop offset="55%" stop-color="#2563eb" />
      <stop offset="100%" stop-color="#e11d48" />
    </linearGradient>

    <!-- Category Pill gradient -->
    <linearGradient id="pillGradient" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#0284c7" />
      <stop offset="100%" stop-color="#2563eb" />
    </linearGradient>

    <filter id="shadow" x="-5%" y="-5%" width="110%" height="110%">
      <feDropShadow dx="0" dy="8" stdDeviation="12" flood-color="#000000" flood-opacity="0.65"/>
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

    <!-- Site Name -->
    <text x="${siteTextX}" y="${topY + categoryBadgeHeight / 2 + Math.round(5 * scale)}"
          fill="#cbd5e1" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="700" text-anchor="end">
      ${escSite}
    </text>
  </g>

  ${centerBadge}

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

  <!-- Footer Divider and Editorial Verification -->
  <g id="footer">
    <line x1="${padding}" y1="${bottomY - Math.round(45 * scale)}" x2="${width - padding}" y2="${bottomY - Math.round(45 * scale)}" stroke="#334155" stroke-width="1.5" />
    <circle cx="${padding + Math.round(8 * scale)}" cy="${bottomY - Math.round(12 * scale)}" r="${Math.round(6 * scale)}" fill="#10b981" />
    <text x="${padding + Math.round(26 * scale)}" y="${bottomY - Math.round(6 * scale)}"
          fill="#94a3b8" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="500">
      Verified Editorial • PressHub.Cy
    </text>
    <text x="${width - padding}" y="${bottomY - Math.round(6 * scale)}"
          fill="#38bdf8" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
          font-size="${metaFontSize}" font-weight="700" text-anchor="end">
      ${isPodcast ? 'Listen Podcast →' : 'Read More →'}
    </text>
  </g>
</svg>
  `.trim();
}

/**
 * Generates a branded PNG card image file using sharp.
 * Supports downloading featured images from mediaUrl/backgroundUrl, compositing media icons (logo, podcast mic),
 * and applying PressHub website branding.
 */
export async function generateSocialCard(options: GenerateCardOptions): Promise<CardResult> {
  const width = clampDimension(options.width, 1080, 540, 3840);
  const height = clampDimension(options.height, 1080, 540, 3840);
  const outputDir = options.outputDir || path.resolve(process.cwd(), 'output');

  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const fileName = `card-${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
  const filePath = path.join(outputDir, fileName);

  WorkerLogger.info('CardGenerator', `Starting card generation for "${options.title}" (${width}x${height})`, {
    width,
    height,
    category: options.category,
    siteName: options.siteName,
    outputDir,
    hasMediaUrl: Boolean(options.mediaUrl || options.backgroundUrl)
  });

  // Resolve background buffer: either provided directly or fetched from mediaUrl/backgroundUrl
  let backgroundBuffer = options.backgroundBuffer;
  if (!backgroundBuffer && (options.mediaUrl || options.backgroundUrl)) {
    const targetUrl = options.mediaUrl || options.backgroundUrl;
    WorkerLogger.info('CardGenerator', `Downloading featured image from ${targetUrl}...`);
    const fetched = await fetchImageBuffer(targetUrl!);
    if (fetched && fetched.length > 0) {
      backgroundBuffer = fetched;
      WorkerLogger.debug('CardGenerator', `Featured background image fetched successfully (${fetched.length} bytes)`);
    } else {
      WorkerLogger.warn('CardGenerator', `Failed to fetch background image from ${targetUrl}`);
    }
  }

  const hasCustomBackground = Boolean(backgroundBuffer && backgroundBuffer.length > 0);
  const isVertical = height > width * 1.2;
  const isPodcast = Boolean(
    options.isPodcast ||
    options.title?.toLowerCase().includes('podcast') ||
    options.category?.toLowerCase().includes('podcast')
  );

  // Check for PressHub icon in media/ folder
  const iconPath = getMediaAssetPath('cropped-PressHub-Cy-ICON.png');
  const hasLogoIcon = Boolean(iconPath && fs.existsSync(iconPath));

  // Check for PressHub mic in media/ folder
  const micPath = getMediaAssetPath('PressHub.cy Mic.png');
  const hasMicIcon = Boolean(micPath && fs.existsSync(micPath) && (isPodcast || isVertical));

  WorkerLogger.debug('CardGenerator', `Building SVG layout`, {
    hasCustomBackground,
    hasLogoIcon,
    hasMicIcon,
    isVertical,
    isPodcast
  });

  const svg = buildCardSvg({
    title: options.title || 'Breaking Update',
    subtitle: options.subtitle,
    category: options.category,
    siteName: options.siteName,
    width,
    height,
    hasCustomBackground,
    hasLogoIcon,
    isPodcast
  });

  const svgBuffer = Buffer.from(svg);
  const scale = width / 1080;
  const padding = Math.round(width * 0.08);
  const topY = Math.round(padding * 1.1);
  const categoryBadgeHeight = Math.round(38 * scale);

  const composites: sharp.OverlayOptions[] = [
    { input: svgBuffer, top: 0, left: 0 }
  ];

  // Composite PressHub logo icon in top right header if available
  if (hasLogoIcon && iconPath) {
    try {
      const logoSize = Math.max(36, Math.round(50 * scale));
      const logoLeft = width - padding - logoSize;
      const logoTop = topY + Math.round((categoryBadgeHeight - logoSize) / 2);

      const logoBuffer = await sharp(iconPath)
        .resize(logoSize, logoSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();

      composites.push({ input: logoBuffer, top: Math.max(0, logoTop), left: Math.max(0, logoLeft) });
      WorkerLogger.debug('CardGenerator', `Composited logo icon from ${iconPath} at (${logoLeft}, ${logoTop})`);
    } catch (logoErr: any) {
      WorkerLogger.warn('CardGenerator', `Could not composite logo icon: ${logoErr.message}`);
    }
  }

  // Composite Podcast Microphone icon if available and podcast/vertical
  if (hasMicIcon && micPath && isVertical) {
    try {
      const micSize = Math.round(140 * scale);
      const micLeft = Math.round((width - micSize) / 2);
      const micTop = Math.round(height * 0.16);

      const micBuffer = await sharp(micPath)
        .resize(micSize, micSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();

      composites.push({ input: micBuffer, top: Math.max(0, micTop), left: Math.max(0, micLeft) });
      WorkerLogger.debug('CardGenerator', `Composited mic icon from ${micPath} at (${micLeft}, ${micTop})`);
    } catch (micErr: any) {
      WorkerLogger.warn('CardGenerator', `Could not composite mic icon: ${micErr.message}`);
    }
  }

  let sharpInstance: sharp.Sharp;

  if (hasCustomBackground && backgroundBuffer) {
    // Resize featured image to cover canvas and composite SVG and icon overlays
    WorkerLogger.debug('CardGenerator', `Resizing background buffer (${width}x${height}) and compositing ${composites.length} layers`);
    sharpInstance = sharp(backgroundBuffer)
      .resize(width, height, { fit: 'cover' })
      .composite(composites);
  } else {
    // Blank dark slate canvas with composite layers
    WorkerLogger.debug('CardGenerator', `Creating dark canvas (${width}x${height}) and compositing ${composites.length} layers`);
    sharpInstance = sharp({
      create: {
        width,
        height,
        channels: 4,
        background: { r: 7, g: 12, b: 23, alpha: 1 }
      }
    }).composite(composites);
  }

  WorkerLogger.debug('CardGenerator', `Rendering PNG to ${filePath}...`);
  await sharpInstance.png({ compressionLevel: 9 }).toFile(filePath);

  const stats = fs.statSync(filePath);
  WorkerLogger.info('CardGenerator', `Card successfully generated: ${filePath} (${stats.size} bytes, ${width}x${height})`);

  return {
    filePath,
    width,
    height,
    sizeBytes: stats.size
  };
}

export interface GenerateCardParams {
  postId?: number;
  title: string;
  subtitle?: string;
  siteName?: string;
  category?: string;
  width?: number;
  height?: number;
  outputDir?: string;
  backgroundBuffer?: Buffer;
  mediaUrl?: string;
  backgroundUrl?: string;
  isPodcast?: boolean;
}

export async function generateCard(params: GenerateCardParams): Promise<CardResult> {
  return generateSocialCard({
    title: params.title,
    subtitle: params.subtitle,
    category: params.category,
    siteName: params.siteName,
    width: params.width,
    height: params.height,
    outputDir: params.outputDir,
    backgroundBuffer: params.backgroundBuffer,
    mediaUrl: params.mediaUrl,
    backgroundUrl: params.backgroundUrl,
    isPodcast: params.isPodcast
  });
}
