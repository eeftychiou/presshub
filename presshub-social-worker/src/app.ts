import express, { Express, Request, Response, NextFunction } from 'express';
import { createAuthMiddleware, SecretGetter } from './auth';
import { generateSocialCard } from './media/card-generator';
import { generateSocialVideo } from './media/video-generator';
import {
  Platform,
  isValidPlatform,
  verifySession,
  SUPPORTED_PLATFORMS
} from './automation/session-manager';
import {
  PosterHandler,
  defaultPosterHandler,
  PostResult
} from './automation/posters';

export interface AppOptions {
  secret?: SecretGetter;
  posterHandler?: PosterHandler;
  sessionsDir?: string;
  outputDir?: string;
}

export function createApp(options: AppOptions = {}): Express {
  const app = express();

  app.use(express.json({ limit: '50mb' }));

  const auth = createAuthMiddleware(options.secret);
  const posterHandler = options.posterHandler || defaultPosterHandler;

  /**
   * GET /health
   * Authenticated service health check and version metadata.
   */
  app.get('/health', auth, (_req: Request, res: Response) => {
    res.json({
      success: true,
      status: 'ok',
      version: '1.0.0',
      uptime: process.uptime()
    });
  });

  /**
   * POST /verify-session
   * Verifies if browser storageState exists and has active cookies for the platform.
   */
  app.post('/verify-session', auth, (req: Request, res: Response) => {
    const { platform } = req.body || {};

    if (!platform || typeof platform !== 'string') {
      res.status(400).json({
        success: false,
        error: `platform is required and must be one of: ${SUPPORTED_PLATFORMS.join(', ')}`
      });
      return;
    }

    const cleanPlatform = platform.toLowerCase().trim();
    if (!isValidPlatform(cleanPlatform)) {
      res.status(400).json({
        success: false,
        error: `Unsupported platform '${platform}'. Supported platforms: ${SUPPORTED_PLATFORMS.join(', ')}`
      });
      return;
    }

    const sessionStatus = verifySession(cleanPlatform, options.sessionsDir);
    res.json({
      success: true,
      session: sessionStatus
    });
  });

  /**
   * POST /generate-card
   * Generates a branded PNG card image.
   */
  app.post('/generate-card', auth, async (req: Request, res: Response, next: NextFunction) => {
    try {
      const {
        title,
        subtitle,
        category,
        siteName,
        width,
        height,
        backgroundBase64
      } = req.body || {};

      if (!title || typeof title !== 'string' || title.trim() === '') {
        res.status(400).json({
          success: false,
          error: 'title is required and must be a non-empty string'
        });
        return;
      }

      let backgroundBuffer: Buffer | undefined;
      if (backgroundBase64 && typeof backgroundBase64 === 'string') {
        const cleanBase64 = backgroundBase64.replace(/^data:image\/\w+;base64,/, '');
        backgroundBuffer = Buffer.from(cleanBase64, 'base64');
      }

      const result = await generateSocialCard({
        title,
        subtitle,
        category,
        siteName,
        width: typeof width === 'number' ? width : undefined,
        height: typeof height === 'number' ? height : undefined,
        outputDir: options.outputDir,
        backgroundBuffer
      });

      res.json({
        success: true,
        filePath: result.filePath,
        width: result.width,
        height: result.height,
        sizeBytes: result.sizeBytes
      });
    } catch (err) {
      next(err);
    }
  });

  /**
   * POST /generate-video
   * Generates a vertical MP4 video with an audio track.
   */
  app.post('/generate-video', auth, async (req: Request, res: Response, next: NextFunction) => {
    try {
      const {
        title,
        subtitle,
        category,
        siteName,
        imagePath,
        audioPath,
        width,
        height,
        duration
      } = req.body || {};

      const result = await generateSocialVideo({
        title,
        subtitle,
        category,
        siteName,
        imagePath,
        audioPath,
        width: typeof width === 'number' ? width : undefined,
        height: typeof height === 'number' ? height : undefined,
        duration: typeof duration === 'number' ? duration : undefined,
        outputDir: options.outputDir
      });

      res.json({
        success: true,
        filePath: result.filePath,
        width: result.width,
        height: result.height,
        duration: result.duration
      });
    } catch (err) {
      next(err);
    }
  });

  /**
   * POST /dispatch
   * Coordinates media generation if needed and executes platform browser automation.
   */
  app.post('/dispatch', auth, async (req: Request, res: Response, next: NextFunction) => {
    try {
      const {
        platform,
        copy,
        mediaPath,
        autoGenerateMedia,
        title,
        subtitle,
        category,
        siteName
      } = req.body || {};

      if (!platform || typeof platform !== 'string') {
        res.status(400).json({
          success: false,
          error: `platform is required and must be one of: ${SUPPORTED_PLATFORMS.join(', ')}`
        });
        return;
      }

      const cleanPlatform = platform.toLowerCase().trim() as Platform;
      if (!isValidPlatform(cleanPlatform)) {
        res.status(400).json({
          success: false,
          error: `Unsupported platform '${platform}'. Supported platforms: ${SUPPORTED_PLATFORMS.join(', ')}`
        });
        return;
      }

      if (!copy || typeof copy !== 'string' || copy.trim() === '') {
        res.status(400).json({
          success: false,
          error: 'copy is required and must be a non-empty string'
        });
        return;
      }

      let resolvedMediaPath = mediaPath;

      // Auto-generate media if missing and requested or platform-mandated
      if (!resolvedMediaPath && (autoGenerateMedia || cleanPlatform === 'tiktok')) {
        if (cleanPlatform === 'tiktok') {
          // TikTok requires vertical video
          const video = await generateSocialVideo({
            title: title || copy.slice(0, 80),
            subtitle,
            category,
            siteName,
            outputDir: options.outputDir
          });
          resolvedMediaPath = video.filePath;
        } else {
          // Social card image for Twitter / Facebook / Instagram
          const card = await generateSocialCard({
            title: title || copy.slice(0, 80),
            subtitle,
            category,
            siteName,
            outputDir: options.outputDir
          });
          resolvedMediaPath = card.filePath;
        }
      }

      const result: PostResult = await posterHandler({
        platform: cleanPlatform,
        copy,
        mediaPath: resolvedMediaPath,
        sessionsDir: options.sessionsDir
      });

      res.json({
        success: true,
        platform: result.platform,
        externalPostId: result.externalPostId,
        externalPostUrl: result.externalPostUrl,
        mediaPath: result.mediaPath
      });
    } catch (err: any) {
      res.status(500).json({
        success: false,
        error: err.message || 'Dispatch automation failed'
      });
    }
  });

  // Global error handler
  app.use((err: any, _req: Request, res: Response, _next: NextFunction) => {
    res.status(500).json({
      success: false,
      error: err.message || 'Internal Server Error'
    });
  });

  return app;
}
