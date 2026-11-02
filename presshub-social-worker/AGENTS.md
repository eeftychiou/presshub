# Agent & Developer Guidelines: PressHub Social Worker

## Architecture Overview
`presshub-social-worker` is a TypeScript Node.js microservice providing server-side card rendering (`sharp`), vertical video creation (`fluent-ffmpeg`), and Playwright automation for social media platforms.

## Key Files
- `src/auth.ts`: Constant-time authentication middleware via SHA-256 and `crypto.timingSafeEqual`.
- `src/media/card-generator.ts`: SVG layout generator & `sharp` PNG compositor.
- `src/media/video-generator.ts`: `fluent-ffmpeg` vertical MP4 generator with audio fallback.
- `src/automation/session-manager.ts`: Cookie validation, platform allowlisting, and storageState persistence.
- `src/automation/posters.ts`: Playwright automation implementations for Twitter, TikTok, Facebook, Instagram.
- `src/cli/login.ts`: Interactive login CLI to generate storageState JSON files.
- `src/app.ts`: Express application factory with injection hooks for testing.
- `src/index.ts`: Production entrypoint.
- `tests/worker.test.ts`: Complete unit and integration test suite.

## Verification Commands
Before submitting PRs or finalizing tasks:
```bash
npm run build
npm test
```
All tests must pass 100%.
