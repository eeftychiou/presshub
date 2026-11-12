# PressHub Social Worker

A lightweight, high-performance Node.js & TypeScript companion service for **PressHub AI** that handles:
1. **Pull Mode Automation Client** (`PressHubPoller`) that polls your WordPress site for queued social jobs, renders media locally, executes browser automation, and reports status back without needing public IP or port forwarding.
2. **Branded Social Card Generation** using SVG layouts composited via `sharp` (PNG format, gradient badges, typography, auto-clamping, XML injection protection).
3. **Vertical Video Synthesis** using `fluent-ffmpeg` and `@ffmpeg-installer/ffmpeg` (generates 9:16 vertical MP4s with `libx264` and `aac` audio, including silent stereo streams for TikTok/Reels compatibility).
4. **Playwright Browser Automation & Session Management** (headless browser automation with persistent cookie sessions for Twitter/X, TikTok, Facebook, and Instagram).
5. **Secure Express REST API** protected by constant-time shared secret verification (`PRESSHUB_WORKER_SECRET`).
6. **Zero-Dependency .env Loader** supporting easy configuration via `.env` files.
7. **Interactive CLI Login Assistant** (`npm run login -- <platform>`) to authenticate platforms and capture `storageState` sessions without manual cookie extraction.

---

## 🚀 Getting Started

### Prerequisites
- Node.js >= 18 (Node.js 20 or 22 recommended)
- Playwright Chromium (`npx playwright install chromium`)

### Installation
```bash
npm install
npx playwright install chromium
```

### Environment Configuration (.env)
Copy the example configuration file:
```bash
cp .env.example .env
```

Edit `.env` to configure your settings:
```env
# PressHub WordPress site base URL
PRESSHUB_WP_URL=https://presshub.cy

# Shared secret token matching Settings -> Social Auto-Post -> Worker Secret
PRESSHUB_WORKER_SECRET=your-secret-here

# Port for local worker HTTP server
PRESSHUB_WORKER_PORT=3456

# Polling interval in milliseconds for Pull Mode (default: 30000ms / 30 seconds)
PRESSHUB_POLL_INTERVAL_MS=30000

# Browser automation headless mode (false to launch visible Chrome locally; true for headless/CI)
PRESSHUB_HEADLESS=false
# Dedicated log file destination (default: logs/worker.log)
PRESSHUB_LOG_FILE=logs/worker.log

# Log level (DEBUG, INFO, WARN, ERROR; default: DEBUG)
PRESSHUB_LOG_LEVEL=DEBUG

# Log rotation: rotate when the active log exceeds this many bytes (default: 10485760 = 10 MB)
PRESSHUB_LOG_MAX_BYTES=10485760
# Rotated copies to keep as worker.log.1 ... worker.log.N (default: 5; the oldest is deleted)
PRESSHUB_LOG_MAX_FILES=5
```

### Build & Run
```bash
# Build TypeScript
npm run build

# Start worker (starts HTTP server + Pull Mode poller if PRESSHUB_WP_URL is set)
npm start

# Or run in development mode
npm run dev
```

---

## 🔄 Dual Operational Modes

### 1. Pull Mode (Recommended for Local PCs)
When `PRESSHUB_WP_URL` and `PRESSHUB_WORKER_SECRET` are set in `.env`:
- The worker automatically polls `${PRESSHUB_WP_URL}/wp-json/presshub-social/v1/worker/claim` every 30 seconds.
- It sends session availability statuses (`twitter`, `tiktok`, `facebook`, `instagram`) and claims pending automation jobs.
- Clear, timestamped cycle logging tracks queue activity: e.g. `[PressHub Poller 20:31:00] Polled WordPress -> claimed 0 jobs (queue is empty or no due automation jobs)` or `Claimed 2 jobs: [#12 Twitter, #13 TikTok]`.
- Step-by-step progress logging details browser navigation, login verification, selector matching, and posting.
- In case of failure or selector timeout, a full-page debug screenshot is automatically saved to `output/debug-error-<platform>-<timestamp>.png`.
- The worker generates required media (PNG cards or vertical MP4s), executes browser automation in headless Chromium, and reports completion to `/worker/complete`.
- **Zero router port forwarding or public static IP required.**

### 2. Push Mode (For Cloud / VPS Deployments)
When `PRESSHUB_WP_URL` is omitted, the worker runs as a pure Express HTTP server on `PRESSHUB_WORKER_PORT` (default: 3456), ready to receive inbound requests from WordPress at `POST /dispatch`.

---

## 🪵 Production Logging (`WorkerLogger`)

The worker incorporates a dedicated logging module (`src/logger.ts`) that writes synchronized logs to both standard output/stderr and `logs/worker.log`:
- **ISO-8601 Timestamps & Severity**: Every line formatted as `[<TIMESTAMP>] [<LEVEL>] [<TAG>] <MESSAGE>`.
- **WordPress Payloads**: Records full `/worker/claim` responses including post title, copy/LLM text, media URLs, audio URLs, and specs.
- **Media Generation**: Logs Sharp SVG layouts, layer compositing, and output file sizes.
- **FFmpeg & Audio Probing**: Logs exact FFmpeg spawn commands, audio duration probe results, video filters, and completion status.
- **Playwright Automation**: Tracks browser launch mode, navigation URLs, selector evaluations, and text input lengths.
- **Size-based Rotation**: Before each write, if the log file exceeds `PRESSHUB_LOG_MAX_BYTES` (default `10485760`, 10 MB), `worker.log` becomes `worker.log.1`, `worker.log.1` becomes `worker.log.2`, and so on. At most `PRESSHUB_LOG_MAX_FILES` (default `5`) rotated copies are kept and the oldest is deleted. Invalid values fall back to the defaults, and a rotation failure only prints a console warning.
- **Failure Diagnostics**: Captures full-page screenshots to `output/debug-error-<platform>-<timestamp>.png` and logs current page URL and DOM state snippet upon failure.

---

## 🎵 Full Audio Track & Podcast Duration Probing

When generating video clips for TikTok and Reels:
- If a podcast audio file or audio URL is attached (MP3/WAV), `video-generator.ts` uses FFmpeg metadata inspection to probe the **exact audio duration** and renders the vertical video to match the full audio track length.
- Prevents premature cutoff (e.g. 10s default still-image duration).
- If the audio track exceeds TikTok Web Creator Center's maximum 600s (10 minutes) limit, it safely clamps to 600s and emits a `[WARN]` diagnostic.
- If no audio file is attached, the still-image duration (default 10s, clamped 5-60s) applies with a silent stereo track.

### Interactive Session Login
To authenticate a platform for browser automation:
```bash
npm run login -- twitter
npm run login -- tiktok
npm run login -- facebook
npm run login -- instagram
```
This launches a visible browser window, waits for you to sign in and complete 2FA, and saves session cookies to `sessions/<platform>-session.json`.


### Social Monitor (X and TikTok)
WordPress can feed the latest posts of selected X and TikTok accounts into the Daily Briefing. Add each account as a News Source with the media type **X (Twitter) Account** or **TikTok Account**, then turn on **Social media monitoring** under Settings → Daily Briefing. The Pull Mode poller asks WordPress whether a scan is due; when it is, the worker opens each profile in its own browser, reads the posts, and sends them back. Videos are sent to WordPress for a Gemini transcript.

Use separate **reader accounts** for this, not the accounts PressHub posts from, so a block or a captcha never affects posting:
```bash
npm run login -- twitter --reader
npm run login -- tiktok --reader
```
Sessions are saved in `sessions-reader/` (or `PRESSHUB_MONITOR_SESSIONS_DIR`). For many X accounts, create a private List on the reader account with all of them and paste its URL into the **X List** setting: the worker then reads one page instead of one per account. `PRESSHUB_MONITOR_DISABLED=true` turns the monitor off on this worker.

---

## 📡 API Endpoints

All endpoints require authentication via either:
- Header: `Authorization: Bearer <PRESSHUB_WORKER_SECRET>`
- Header: `X-PressHub-Secret: <PRESSHUB_WORKER_SECRET>`

### 1. `GET /health`
Returns service status, version, and uptime.

### 2. `POST /verify-session`
Checks whether a platform session exists, contains cookies, and has not expired.
```json
{
  "platform": "twitter"
}
```

### 3. `POST /generate-card`
Generates a branded social media card (PNG).
```json
{
  "title": "Major Breakthrough in Renewable Energy",
  "subtitle": "Scientists announce positive net energy gain in nuclear fusion experiments.",
  "category": "TECHNOLOGY",
  "siteName": "PressHub Daily",
  "width": 1080,
  "height": 1080
}
```

### 4. `POST /generate-video`
Generates a vertical MP4 video with audio track.
```json
{
  "title": "Breaking Video Report",
  "subtitle": "Daily briefing",
  "category": "NEWS",
  "width": 1080,
  "height": 1920,
  "duration": 10
}
```

### 5. `POST /dispatch`
Executes browser posting for a given platform. Auto-generates card/video media if needed.
```json
{
  "platform": "twitter",
  "copy": "Breaking: Clean energy breakthrough announced today! #TechNews",
  "autoGenerateMedia": true,
  "title": "Clean Energy Breakthrough"
}
```
