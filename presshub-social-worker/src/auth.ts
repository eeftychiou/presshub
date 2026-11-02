import { Request, Response, NextFunction, RequestHandler } from 'express';
import crypto from 'crypto';

export type SecretGetter = string | (() => string | undefined) | undefined;

/**
 * Perform a constant-time comparison of two strings using SHA-256 digests.
 * Hashing both strings to 32-byte buffers ensures identical length comparison,
 * completely preventing timing attacks and buffer length mismatch errors.
 */
export function safeCompare(a: string, b: string): boolean {
  if (typeof a !== 'string' || typeof b !== 'string' || !a || !b) {
    return false;
  }
  const hashA = crypto.createHash('sha256').update(a).digest();
  const hashB = crypto.createHash('sha256').update(b).digest();
  return crypto.timingSafeEqual(hashA, hashB);
}

/**
 * Creates Express middleware to verify worker authentication via shared secret.
 * Accepts Authorization: Bearer <token> or X-PressHub-Secret: <token>.
 */
export function createAuthMiddleware(secretOrGetter?: SecretGetter): RequestHandler {
  return (req: Request, res: Response, next: NextFunction): void => {
    let expectedSecret: string | undefined;

    if (typeof secretOrGetter === 'function') {
      expectedSecret = secretOrGetter();
    } else if (typeof secretOrGetter === 'string') {
      expectedSecret = secretOrGetter;
    } else {
      expectedSecret = process.env.PRESSHUB_WORKER_SECRET;
    }

    if (!expectedSecret || expectedSecret.trim() === '') {
      res.status(401).json({
        success: false,
        error: 'Unauthorized: Invalid or missing worker secret'
      });
      return;
    }

    let token: string | undefined;

    const authHeader = req.headers['authorization'];
    if (authHeader && typeof authHeader === 'string') {
      const match = authHeader.match(/^Bearer\s+(.+)$/i);
      if (match) {
        token = match[1].trim();
      }
    }

    if (!token) {
      const customHeader = req.headers['x-presshub-secret'];
      if (customHeader && typeof customHeader === 'string') {
        token = customHeader.trim();
      }
    }

    if (!token || !safeCompare(token, expectedSecret.trim())) {
      res.status(401).json({
        success: false,
        error: 'Unauthorized: Invalid or missing worker secret'
      });
      return;
    }

    next();
  };
}
