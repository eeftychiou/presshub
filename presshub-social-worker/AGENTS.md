# Agent & Developer Guidelines: PressHub Social Worker

## Architecture Overview
`presshub-social-worker` is a TypeScript Node.js microservice providing server-side card rendering (`sharp`), vertical video creation (`fluent-ffmpeg`), and Playwright automation for social media platforms. It operates in dual operational modes:
- **Pull Mode**: Background polling client (`PressHubPoller`) pulling queued jobs from WordPress REST endpoints (`/worker/claim` & `/worker/complete`). Ideal for local PCs behind NAT/firewalls.
- **Push Mode**: Express HTTP API server listening for incoming push notifications from WordPress.

## Key Files
- `src/config.ts`: Zero-dependency `.env` file parser and configuration loader.
- `src/logger.ts`: Dedicated `WorkerLogger` facility writing ISO-timestamped, severity-graded logs to console and `logs/worker.log`.
- `src/poller.ts`: Background `PressHubPoller` client for Pull Mode claiming, timestamped diagnostics, and reporting.
- `src/auth.ts`: Constant-time authentication middleware via SHA-256 and `crypto.timingSafeEqual`.
- `src/media/card-generator.ts`: SVG layout generator & `sharp` PNG compositor (`generateSocialCard`, `generateCard`).
- `src/media/video-generator.ts`: `fluent-ffmpeg` vertical MP4 generator with `probeAudioDuration` for full podcast playback and TikTok 600s clamping (`generateSocialVideo`, `generateVideo`).
- `src/media/short-video.ts`: TikTok short renderer (`generateShortVideo`): word-synced captions burned in with libass (ASS file, bundled Inter fonts in `assets/fonts`, SIL OFL 1.1), hook overlay, story images with zoom/pan shots, progress bar and end card. Fails when the FFmpeg build lacks libass; `FFMPEG_PATH` overrides the binary. Tests in `tests/short-video.test.ts`.
- `src/automation/session-manager.ts`: Cookie validation, platform allowlisting, and storageState persistence (`SessionManager`, `verifySession`).
- `src/automation/browser-helper.ts`: Chrome launcher & CDP connection with `resolveHeadless` (reads `PRESSHUB_HEADLESS`), stealth arguments, and Windows visible browser support.
- `src/automation/posters.ts`: Playwright automation implementations for Twitter, TikTok, Facebook, Instagram (`dispatchBrowserPost`, `defaultPosterHandler`, `captureErrorScreenshot`, `captureAutomationFailure`, overlay/cookie dismissal, force-click fallback, TikTok upload progress observation up to 120s, post confirmation verification up to 60s, and Zero-Silent-Fallback error propagation).
- `src/cli/login.ts`: Interactive login CLI to generate storageState JSON files.
- `src/app.ts`: Express application factory with injection hooks for testing.
- `src/index.ts`: Production entrypoint; auto-starts `PressHubPoller` if `PRESSHUB_WP_URL` and `PRESSHUB_WORKER_SECRET` are configured.
- `tests/logger.test.ts`: `WorkerLogger` file creation, formatting, metadata serialization, and level filtering unit tests.
- `tests/worker.test.ts`: Express HTTP and media generation unit tests.
- `tests/browser-helper.test.ts`: Stealth configuration, CDP probing, and `resolveHeadless` unit tests.
- `tests/poller.test.ts`: `.env` parser and Pull Mode poller unit tests.
- `tests/posters.test.ts`: Playwright poster automation unit tests (strict-mode violation prevention, dialog vs timeline scoping, toast URL extraction).

## Verification Commands
Before submitting PRs or finalizing tasks:
```bash
npm run build
npm test
```
All tests must pass 100%.
