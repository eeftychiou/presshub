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
- The worker generates required media (PNG cards or vertical MP4s), executes browser automation in headless Chromium, and reports completion to `/worker/complete`.
- **Zero router port forwarding or public static IP required.**

### 2. Push Mode (For Cloud / VPS Deployments)
When `PRESSHUB_WP_URL` is omitted, the worker runs as a pure Express HTTP server on `PRESSHUB_WORKER_PORT` (default: 3456), ready to receive inbound requests from WordPress at `POST /dispatch`.

---

## 🔐 Authentication & Session Login

### Interactive Session Login
To authenticate a platform for browser automation:
```bash
npm run login -- twitter
npm run login -- tiktok
npm run login -- facebook
npm run login -- instagram
```
This launches a visible browser window, waits for you to sign in and complete 2FA, and saves session cookies to `sessions/<platform>-session.json`.

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
