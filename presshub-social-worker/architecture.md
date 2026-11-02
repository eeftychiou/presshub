# PressHub Social Worker — Architecture & Data Flow

```mermaid
flowchart TD
    Client["WordPress Plugin (PressHub AI)"] -->|"HTTP POST /dispatch (Bearer Token)"| Auth["Auth Middleware (safeCompare SHA-256)"]
    Auth --> App["Express Application Layer (app.ts)"]

    subgraph Media Engine
        App -->|"POST /generate-card"| CardGen["Card Generator (sharp + SVG)"]
        App -->|"POST /generate-video"| VideoGen["Video Generator (fluent-ffmpeg + ffmpeg)"]
        CardGen -->|"Output PNG"| DiskStorage[("Disk Storage /output")]
        VideoGen -->|"Output MP4 (libx264/aac)"| DiskStorage
    end

    subgraph Automation & Session Engine
        App -->|"POST /verify-session"| SessionMgr["Session Manager (session-manager.ts)"]
        SessionMgr -->|"Read/Verify JSON"| SessionsDir[("Sessions /sessions/<platform>-session.json")]
        CLI["CLI Interactive Login (login.ts)"] -->|"Save State"| SessionsDir

        App -->|"POST /dispatch"| PosterDispatcher["Poster Dispatcher (posters.ts)"]
        PosterDispatcher -->|"Verify Gate"| SessionMgr
        PosterDispatcher -->|"Launch Context"| PlaywrightEngine["Playwright Chromium Browser"]
        PlaywrightEngine -->|"Post tweet / upload media"| Twitter["Twitter / X"]
        PlaywrightEngine -->|"Upload vertical video"| TikTok["TikTok Creator Center"]
        PlaywrightEngine -->|"Share post"| Facebook["Facebook"]
        PlaywrightEngine -->|"Create media post"| Instagram["Instagram"]
    end
```

## Security & Architecture Principles

1. **Constant-Time Verification**: Shared secret comparison hashes both candidate and stored secret with SHA-256 prior to `crypto.timingSafeEqual`, preventing timing attacks and buffer length leakage.
2. **Path Traversal Guards**: The platform string is strictly validated against an immutable allowlist (`['twitter', 'tiktok', 'facebook', 'instagram']`) to prevent directory traversal or file injection attacks.
3. **Fail-Fast Session Gate**: All automation handlers inspect the session storage state before launching browser instances. If cookies are missing or all expired, the worker immediately rejects the request with an actionable command message (`npm run login -- <platform>`).
4. **Resilient Media Compositing**: Video generation automatically injects a silent audio track (`anullsrc`) if an external voice file is not attached, ensuring platforms like TikTok that require audio streams accept the upload without re-encoding errors.
5. **Decoupled Testability**: The Express app factory accepts injected poster handlers, enabling complete end-to-end unit testing without launching heavy browser instances during CI.
