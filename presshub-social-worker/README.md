# PressHub Social Worker

A lightweight, high-performance Node.js & TypeScript companion service for **PressHub AI** that handles:
1. **Branded Social Card Generation** using SVG layouts composited via `sharp` (PNG format, gradient badges, typography, auto-clamping, XML injection protection).
2. **Vertical Video Synthesis** using `fluent-ffmpeg` and `@ffmpeg-installer/ffmpeg` (generates 9:16 vertical MP4s with `libx264` and `aac` audio, including silent stereo streams for TikTok/Reels compatibility).
3. **Playwright Browser Automation & Session Management** (headless browser automation with persistent cookie sessions for Twitter/X, TikTok, Facebook, and Instagram).
4. **Secure Express REST API** protected by constant-time shared secret verification (`PRESSHUB_WORKER_SECRET`).
5. **Interactive CLI Login Assistant** (`npm run login -- <platform>`) to authenticate platforms and capture `storageState` sessions without manual cookie extraction.

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

### Environment Configuration
Set the shared worker secret in your environment:
```bash
export PRESSHUB_WORKER_SECRET="your-secure-secret-token"
export PRESSHUB_WORKER_PORT="3001" # Defaults to 3001
```

### Build & Run
```bash
# Build TypeScript
npm run build

# Start production server
npm start

# Or run in development mode
npm run dev
```

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
Automates posting to the specified platform, auto-generating media cards or vertical videos when needed.
```json
{
  "platform": "twitter",
  "copy": "Breaking: Renewable energy breakthrough announced today. Full story on our portal.",
  "autoGenerateMedia": true,
  "title": "Renewable Energy Breakthrough"
}
```

---

## 🧪 Testing

Run the comprehensive test suite:
```bash
npm test
```
