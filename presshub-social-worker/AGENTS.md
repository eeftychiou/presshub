# Agent & Developer Guidelines: PressHub Social Worker

## Architecture Overview
`presshub-social-worker` is a TypeScript Node.js microservice providing server-side card rendering (`sharp`), vertical video creation (`fluent-ffmpeg`), and Playwright automation for social media platforms. It operates in dual operational modes:
- **Pull Mode**: Background polling client (`PressHubPoller`) pulling queued jobs from WordPress REST endpoints (`/worker/claim` & `/worker/complete`). Ideal for local PCs behind NAT/firewalls.
- **Push Mode**: Express HTTP API server listening for incoming push notifications from WordPress.

## Key Files
- `src/config.ts`: Zero-dependency `.env` file parser and configuration loader.
- `src/poller.ts`: Background `PressHubPoller` client for Pull Mode claiming, timestamped diagnostics, and reporting.
- `src/auth.ts`: Constant-time authentication middleware via SHA-256 and `crypto.timingSafeEqual`.
- `src/media/card-generator.ts`: SVG layout generator & `sharp` PNG compositor (`generateSocialCard`, `generateCard`).
- `src/media/video-generator.ts`: `fluent-ffmpeg` vertical MP4 generator with audio fallback (`generateSocialVideo`, `generateVideo`).
- `src/automation/session-manager.ts`: Cookie validation, platform allowlisting, and storageState persistence (`SessionManager`, `verifySession`).
- `src/automation/posters.ts`: Playwright automation implementations for Twitter, TikTok, Facebook, Instagram (`dispatchBrowserPost`, `defaultPosterHandler`, `captureErrorScreenshot`).
- `src/cli/login.ts`: Interactive login CLI to generate storageState JSON files.
- `src/app.ts`: Express application factory with injection hooks for testing.
- `src/index.ts`: Production entrypoint; auto-starts `PressHubPoller` if `PRESSHUB_WP_URL` and `PRESSHUB_WORKER_SECRET` are configured.
- `tests/worker.test.ts`: Express HTTP and media generation unit tests.
- `tests/poller.test.ts`: `.env` parser and Pull Mode poller unit tests.

## Verification Commands
Before submitting PRs or finalizing tasks:
```bash
npm run build
npm test
```
All tests must pass 100%.
