# PressHub AI Co-Pilot

Enterprise AI Newsroom, Editorial Co-Pilot & Multimedia Publishing Pipeline for WordPress.

[![Version](https://img.shields.io/badge/version-2.13.0-blue.svg)](presshub-ai-editor/presshub-ai-editor.php)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4.svg)](https://php.net)
[![PHP Unit Tests](https://img.shields.io/badge/PHP%20Tests-84%20passed%20%7C%20100%25-brightgreen.svg)](presshub-ai-editor/tests/)
[![Social Autopost Tests](https://img.shields.io/badge/Social%20Tests-171%20passed%20%7C%20100%25-brightgreen.svg)](presshub-ai-editor/tests/)
[![Worker Tests](https://img.shields.io/badge/Worker%20Tests-76%20passed%20%7C%20100%25-brightgreen.svg)](presshub-social-worker/tests/)
[![Jest Workflow Tests](https://img.shields.io/badge/Jest%20Tests-81%20passed%20%7C%20100%25-brightgreen.svg)](presshub-workflow/tests/)
[![CI](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml/badge.svg)](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-orange.svg)](presshub-ai-editor/presshub-ai-editor.php)

---

## 🌟 Overview

**PressHub AI Co-Pilot** transforms WordPress into an autonomous, AI-driven newsroom and multimedia production suite. Spanning from multi-source web/RSS news gathering to editorial quality scoring, bilingual daily briefings, multi-host podcast script generation, and broadcast-quality text-to-speech synthesis, PressHub AI provides news organizations and content creators with an enterprise-grade automated editorial workflow.

Built with a modular **Provider Store** (supporting OpenAI, Anthropic, Google Gemini, and Google Cloud Vertex AI/TTS), **4-layer enterprise observability**, and strict architectural principles (**Settings-First**, **Zero-Silent-Fallback**, **Clean-Slate**), PressHub AI ensures total transparency, deterministic control, and reliability.

---

## 🚀 Core Subsystems & Features

```
┌────────────────────────────────────────────────────────────────────────┐
│                        PressHub AI Newsroom                            │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
      ┌────────────────────────────┼────────────────────────────┐
      ▼                            ▼                            ▼
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Daily Briefing  │     │ Podcast Producer │     │  In-Editor AI    │
│     Pipeline     │     │   & TTS Studio   │     │     Co-Pilot     │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ • Morning & Noon │     │ • Multi-Host Dialogue│ │ • Draft Assistant│
│ • RSS & Web Scrape│    │ • Nameless Hosts │     │ • 0-100 Scorecard│
│ • Context Ingest │     │ • Spoken Dates   │     │ • Chat Intents   │
│ • Image Cycling  │     │ • Audio-Only Post│     │ • Imagen Sideload│
│ • Social Meta    │     │ • Script Editor  │     │ • Block Revision │
│ • Stage Triggers │     │ • Audio Re-Gen   │     │ • Revision Logs  │
└────────┬─────────┘     └────────┬─────────┘     └────────┬─────────┘
         │                        │                        │
         └────────────────────────┼────────────────────────┘
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│                  Decoupled Provider Store & Engine                     │
│  • OpenAI    • Anthropic Claude    • Google Gemini    • Google Cloud   │
├────────────────────────────────────────────────────────────────────────┤
│                  4-Layer Observability & CLI Diagnostics               │
│  Layer 1: Structured  │ Layer 2: Token Log │ Layer 3: Audit │ L4: Core │
└────────────────────────────────────────────────────────────────────────┘
```

### 1. 📰 Automated Daily Briefing Pipeline (Morning Edition & Noon News Update)
- **Autonomous News Harvester (`PressHub_AI_News_Harvester`)**: Concurrently scrapes and processes articles from configured RSS feeds and direct web URLs. Features domain politeness delays, per-domain concurrency limits, total execution time budgets, lead-article extraction, and HTML sanitization. Manages edition-partitioned raw snapshots (`raw-articles.json` vs `raw-articles-noon.json`).
- **Intelligent News Curator (`PressHub_AI_News_Curator`)**: Evaluates harvested candidate stories using LLM analysis. Employs semantic article deduplication to prevent repetitive coverage, enforces strict token context limits, and produces comprehensive bilingual (Greek and English) daily briefings.
  - **Noon News Update**: Re-scrapes sources at noon, automatically ingests the morning briefing post as reference context, and generates an updated noon briefing focusing on developments since morning.
- **Featured Image Pools & Interactive Media Library Picker**: Configure dedicated pools of Media Library images for Morning briefings (`presshub_ai_briefing_featured_image_ids_morning`) and Noon updates (`presshub_ai_briefing_featured_image_ids_noon`), with automatic fallback. Features an interactive WordPress Media Library picker UI with multi-select modal, thumbnail preview cards, badge IDs, and individual remove/clear actions. Images are cycled at random and assigned as post thumbnails to daily text briefings and podcasts, with intelligent **same-day collision avoidance** preventing duplicate featured images between morning/noon text briefings and podcasts when alternative images exist in the pool.
- **Social Sharing & OpenGraph Optimization (`PressHub_AI_Social_Meta`)**: Emits rich, opengraph.xyz-compliant metadata (`og:title`, `og:description`, `og:image`, `og:site_name`, `og:type=article`, `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`, and standard `<meta name="description">`). Supports daily briefings, podcasts, and AI Co-Authored articles with multi-tier image fallback (post thumbnail → inline content image → edition pool → site icon). Includes an automated title length optimizer guaranteeing document titles remain ≤ 60 characters for optimal search snippet and social card display, fully configurable via Settings-First options (`presshub_ai_social_meta_enabled`, `presshub_ai_social_meta_all_posts`).
- **Interactive Briefing Admin (`PressHub_AI_Briefing_Admin`)**: A dedicated WordPress administrative control center providing visibility into each pipeline stage (*Harvest → Curate → Audio/Podcast → Publish*), stage event timelines, manual triggers, single-stage regeneration, and full timezone-aware scheduling. Includes interactive tabs to toggle between **Morning Edition** and **Noon Update**, dynamic in-browser audio player mounting on synthesis completion, and role-gating strictly for Editors and above (`edit_others_posts`, filterable via `presshub_ai_briefing_cap`).

### 2. 🎙️ Podcast Producer, Script Editor & Audio Regeneration
- **Multi-Host Script Engine (`PressHub_AI_Podcast_Producer`)**: Automatically transforms curated news briefings into dynamic, conversational podcast scripts between multiple hosts:
  - **Nameless Speaker Invariant**: Presenters never awkwardly refer to each other or themselves by name; dialogue progresses naturally through ideas.
  - **Spoken Date Formatting**: All dates are converted to natural spoken-language phrasing (e.g. *"3rd of May 2026"* or *"3 Μαΐου 2026"*).
- **Configurable Title Prefixes**: Separate editorial title prefix options for Morning Podcast (`presshub_ai_briefing_podcast_title_prefix`, default *"Πρωινό Podcast Ενημέρωσης - "*) and Noon Podcast (`presshub_ai_briefing_noon_podcast_title_prefix`, default *"Μεσημβρινό Podcast Ενημέρωσης - "*), configurable from Settings.
- **Clean Audio Post Content & Front-Page Excerpt Suppression**: By default, podcast post content displays exclusively the audio player block (`wp:audio`), and raw dialogue text is suppressed from `post_excerpt` and filtered out of front-page post previews, loop cards, and public RSS feeds (configurable via `presshub_ai_briefing_podcast_include_text`). Social meta tags emit a clean editorial audio description instead of leaking dialogue turns.
- **TikTok Narration for Text-Only Articles (`PressHub_Social_TikTok_Narrator`)**: Articles without podcast audio (e.g. the Daily Brief) are posted to TikTok as a spoken video. The TikTok copy is read aloud with Gemini TTS (hashtags, emojis, links and the copy's own presshub.cy sentence are skipped) and ends with *"Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy"*. Configurable under **PressHub Social → Settings → Automation Worker → Media & Video Specifications** (`presshub_social_tiktok_narration_enabled`, `_outro`, `_voice`); a TTS failure fails the job instead of posting silently.
- **Podcast Dialogue Editor Metabox & One-Click Audio Regeneration**: Editors can review and refine spoken scripts in the **"Podcast Audio & Dialogue Editor"** metabox on podcast edit screens, and click **"Regenerate Audio from Script"** to re-synthesize and stitch the episode in real time.
- **Resilient Audio Synthesis & Cron Error Isolation**: Synthesizer accepts nullable custom scripts and automatically shifts parameters (e.g. `synthesize_podcast($date, 'noon')`), while briefing crons encapsulate generation and synthesis in try/catch blocks with structured logging to eliminate unhandled runtime fatal errors.
- **Broadcast Audio Synthesizer & Gemini 3.8 TTS REST Architecture (`PressHub_AI_Audio_Synthesizer` & `PressHub_AI_API_Client`)**:
  - **Zero Narrated Instructions**: Passes clean verbatim dialogue turns in `contents[0].parts[].text` without prepended prompt directives, eliminating prompt narration on modern models (`gemini-3.8-flash-tts` / `gemini-3.8-flash-lite-tts`).
  - **Official Gemini 3.8 TTS REST Standard (`structured` — Recommended)**: Conforms strictly to Google's official Gemini 3.8 TTS REST specification. Turn-level `speech_metadata.style` carries delivery emotion, speaking rate (`0.85x - 1.25x`), and voice pitch tuning (`-5.0 to +5.0 semitones`) without top-level system instructions. Inactive settings (e.g. system instructions) dynamically grey out in the UI with informative badges.
  - **Hybrid / System Instruction Override Mode (`hybrid_instruction`)**: Passes both turn-level `speech_metadata.style` and top-level `systemInstruction` with resolved speaker persona and rate placeholders.
  - **Unified Turn-by-Turn Synthesis & Voice Identity Isolation**: Synthesizes turns individually via `synthesize_turn()` with dedicated single-speaker `voiceConfig` across 1, 2, and 3 hosts, completely eliminating voice identity bleeding and multiSpeaker drift.
  - **Resumable Turn Disk Cache**: Deterministically caches each synthesized WAV/MP3 chunk (`tts-cache/{date}/turn_{hash}`), allowing failed or interrupted runs to resume instantly without re-calling the API for completed turns.
  - **Settings-First Pauses & Throttling**: Configurable turn pauses (`presshub_ai_briefing_tts_turn_pause_ms`, default 250ms), topic pauses (`presshub_ai_briefing_tts_topic_pause_ms`, default 600ms), and inter-turn request delays (`presshub_ai_briefing_tts_request_delay_ms`, default 250ms) with intro/transition/outro SFX stitching.
  - **Exponential Backoff & Dialogue Colon Protection**: Automatically retries HTTP 429/5xx errors with exponential backoff (`retry_backoff_ms * 2^attempt`). Tightened regex prevents mid-sentence colons in Greek dialogue from being misidentified as speaker turns.
  - **Zero-Silent-Fallback Enforcement**: Strict validation across all TTS entry points; functions default to empty voice strings and throw explicit `missing_tts_voice` or `missing_tts_model` `WP_Error`s, completely removing silent code defaults.
  - **RIFF WAV Header Handling**: Detects RIFF headers on incoming audio bytes from Gemini 3.8 to prevent double-wrapping WAV headers.
- **Gemini Speech API JSON Inspector & Live Voice Testing Studio**:
  - Live preview container in Daily Briefing settings renders the exact JSON request body sent to Gemini's speech endpoint, updating dynamically as voices, speed, pitch, delivery style, or test scripts change.
  - Interactive testing studio allows editors to audition 1-host and 2-host audio synthesis with immediate in-browser audio playback, execution metrics (duration ms, byte size), and download capabilities before publishing. Accessible directly from the Daily Briefing Hub Audio Synthesis Stage Card.

### 3. ✍️ In-Editor Editorial Co-Pilot
- **AI Draft Generation & Large-Context Ingestion**: Accelerates content creation by synthesizing comprehensive drafts from raw source URLs, interview transcripts, or bullet notes. Supported by a configurable Settings-First source character cap (`presshub_ai_coauthor_max_source_chars`, default: 200,000 characters, up to 1,000,000 characters), enabling long investigative dossiers, books, or multi-document transcripts to be processed without artificial limits.
- **4-Key Editorial Scorecard & Legal Exposure Assessment**: Evaluates drafts using a structured 4-key rubric: overall publication readiness score (0–100), legal exposure rating badge (`Low`, `Medium`, or `High`), 2–3 sentence editorial feedback, and targeted legal concern alerts highlighting potential defamation or unverified claims.
- **Block-Level Gutenberg Editing**: Modifies individual Gutenberg block attributes (`attributes.content` / `attributes.text`) without resetting document state, supporting existing article revisions.

### 4. 🖼️ AI Featured Image Generation & Studio Engine
- **Photojournalistic Editorial Realism**: Analyzes article content and generates visual prompts adhering to 35mm documentary photography aesthetics with natural lighting and realistic textures, strictly barring "AI plastic" looks, surreal saturation, or typography/text in the synthesized image.
- **Configurable Provider & Models**: Powered by Google Gemini (`gemini-3.1-flash-image` via multimodal `generateContent`) or OpenAI (`dall-e-3` via `images/generations`) with configurable aspect ratios (`16:9`, `1:1`, `4:3`). The image model is determined directly by the selected AI provider's configured model in the Provider Store.
- **Dedicated Settings Tab ("AI Featured Images")**: Control prompt analysis templates, image generation providers, and aspect ratios directly from `PressHub AI → Settings` (`#images`).
- **Daily Briefing Hub Selection & Resilient Pool Fallback**: Briefings and podcast production can independently select between the Featured Image Pool or Automatic AI Generation, with automatic, graceful fallback to the image pool if synthesis fails or times out.
- **Post Editor "AI Featured Image Studio"**: Embedded inside the post editor metabox, allowing journalists to:
  - Draft photojournalistic visual prompts from current article content with one click.
  - Review and edit the prompt in an editable textarea (persisted in post meta `_presshub_ai_image_prompt`).
  - Synthesize and regenerate images directly into the WordPress Media Library with live preview.
  - Assign generated images as the article's official featured image with one click.

### 5. 🎛️ Instruction Presets & Prompt Studio
### 6. 🧠 Editorial Memory Stream & Story Continuity (Issue #132, #134)
- **Editorial Continuity**: Multi-day storyline tracking across daily briefings and weekly digests.
- **Daily Memory Digest Builder**: Automatically scheduled in the background (`wp_schedule_single_event`, 30s delay) after briefing text post creation or update (`PressHub_AI_Memory_Digest_Builder`). Extracts structured JSON stories (`assets/prompts/memory/daily_digest.txt`), enforces story continuity by feeding the previous 7 days' story keys/titles, and retries once on schema/validation errors with the error message in Greek.
- **Deterministic Podcast Lines**: `PressHub_AI_Podcast_Producer::save_script()` automatically parses speaker turns and updates `podcast.opening_line` and `podcast.closing_line` in the daily digest deterministically without any extra LLM call.
- **Backfill Workflow**: Batch extracts daily memory digests across existing briefing posts over a configurable date range (via Briefing Hub UI, background single-step cron, or `wp presshub memory backfill [--from=...] [--to=...] [--force]`). Idempotent and resumable, strictly preserving human editor edits.
- **Edit-Protection Invariant**: Human editor revisions (`origin='edited'`) in `wp_presshub_ai_memory_entries` are preserved across regenerations; editors can deliberately reset to generated content via `reset_to_generated()`.
- **Stale Propagation**: Revisions or exclusions to daily digests automatically mark dependent weekly rollups as `stale`.
- **Clean Slate Post Deletion**: Deleting a daily briefing post automatically deletes associated daily memory entries and cleans links in `wp_presshub_ai_memory_entry_links`.
- **Settings Reference**:
  - `presshub_ai_memory_enabled`: Enable or disable the editorial memory stream (default: `true`).
  - `presshub_ai_memory_daily_days`: Number of recent days covered as daily story digests (bounds: 0–14, default: 7).
  - `presshub_ai_memory_background_days`: Background window covered as weekly rollups (bounds: 0–90, clamped >= daily days, default: 30).
  - `presshub_ai_memory_briefing_budget_chars`: Maximum character budget for memory context injected into daily briefing prompt (bounds: 0–60,000, default: 12,000).
  - `presshub_ai_memory_podcast_budget_chars`: Maximum character budget for memory context injected into podcast prompt (bounds: 0–60,000, default: 6,000).
  - `presshub_ai_memory_retention_days`: Retention period before old memory entries and links are pruned (bounds: 30–730, default: 180).
  - Module `briefing_memory`: Dedicated AI provider and model routing for memory digests and weekly rollups (`presshub_ai_briefing_memory_provider`, `presshub_ai_briefing_memory_model`).


### 7. 🔎 Generation Inspector (Issue #133)
- **Where to find it**: open any Daily Briefing or podcast post in the editor (block or classic) and scroll to the **Generation Inspector** box, or go to **Daily Briefing Hub → Generation records** to browse records by date range, kind and status and open any of them. Editors need the `edit_others_posts` capability.
- **What it shows** for each generation step (briefing text, podcast script; regenerations keep the earlier records, pick one in the selector): the settings that were really used (provider, model and where each came from, temperature, max tokens, timeout, preset, token usage and estimate, truncation), the exact **system prompt** and **user prompt** (with a copy button and character count), the **articles** sent and dropped with the reason and the caps, the **memory** that was injected (or that memory was disabled), the raw **response**, and for podcasts the **TTS** settings. **Raw JSON** downloads the whole record.
- **Read-only and safe**: nothing can be edited here; scraped text is always shown as plain text. Large fields load only when their tab is opened, so the editor stays fast. Posts created before records were introduced show "No generation record".

- **Multi-Tier Presets**: Admin-curated global defaults, author-specific profiles, author defaults, and per-request metabox dropdown selection. Presets append specialized instructions to base system prompts without overwriting foundational editorial safety constraints.
- **Externalized Prompt Assets**: Prompts are stored as modular text files under `presshub-ai-editor/assets/prompts/` (e.g. `default_greek_briefing.txt`, `podcast_style_two_hosts_greek.txt`, `article_image_analysis_prompt.txt`) and dynamically managed via `PressHub_AI_Prompt_Loader`.
- **Admin Prompt Studio**: Inspect, customize, and reset prompt templates directly within WordPress settings without editing source code.

---

## 🏛️ Architectural Principles

PressHub AI adheres strictly to enterprise software engineering standards:

### ⚙️ Settings-First Principle
> **No operational limit, threshold, cap, time budget, batch size, retry count, or rate limit that affects user-visible behavior may be hardcoded inside business logic.**

Every parameter is registered in the WordPress Options API with strict sanitization bounds, exposed via `PressHub_AI_Settings_Storage`, and rendered in the admin settings UI. Module-level token limits and HTTP timeouts enforce minimum safe thresholds (e.g. `max_tokens ≥ 64`, `timeout ≥ 10s`) ensuring unrendered form submissions or empty inputs never corrupt model execution parameters.

### 🛡️ Zero-Silent-Fallback Principle
> **No business logic, API client, prompt resolver, or pipeline stage may silently fall back to a hardcoded model, prompt, or operational parameter when configuration is missing or failing.**

Missing keys, unconfigured models, or failed API calls halt immediately with explicit `WP_Error` objects or descriptive exceptions, logging full diagnostic details to structured logs. Hidden code fallbacks and silent multi-model retry loops are prohibited.

### 🧹 Clean-Slate Architecture
> **The Provider Store is the sole source of truth. No legacy option shadowing or backward-compatibility baggage.**

All provider parameters and default models resolve through `PressHub_AI_Provider_Store` (`wp_presshub_ai_providers`). Deprecated options are actively purged via idempotent database migrations rather than lingering in resolution cascades.

---

## 🪵 Enterprise Observability & 4-Layer Logging

PressHub AI implements a 4-layer logging architecture to provide total transparency into every automated decision, token cost, and administrative action:

| Layer | Purpose | Target / Storage | Inspection Tool |
|---|---|---|---|
| **Layer 1: Structured Logs** | Subsystem operations, harvesting steps, TTS synthesis, HTTP events | `wp-content/uploads/presshub-ai/presshub-debug-<suffix>.log` (protected folder, random file name; see **Log storage and privacy**) | `php dev-env/scripts/tail-logs.php --source=presshub` |
| **Layer 2: Token Telemetry** | Model IDs, prompt/completion tokens, latency, cost, and JSON metadata | `wp_presshub_ai_token_logs` | `php dev-env/scripts/view-token-logs.php` |
| **Layer 3: Security & Audit** | Settings updates, provider credential changes, and admin actions | `wp_presshub_ai_audit_log` | `php dev-env/scripts/query-db.php "SELECT * FROM wp_presshub_ai_audit_log"` |
| **Layer 4: WordPress Core** | PHP runtime warnings, fatal tracebacks, cURL/environment issues | `wp-content/debug.log` | `php dev-env/scripts/tail-logs.php --source=wp` |
| **Generation Records** | Everything that went into each briefing and podcast-script generation: exact system and user prompts, the real provider/model/temperature/max tokens/timeout, the articles sent and dropped (with the reason), memory used, the response, token usage, and the TTS settings used for the podcast audio. Linked to the post via `_presshub_generation_record_ids`; kept for `presshub_ai_generation_record_retention_days` (default 90). Recorded always, independent of the debug-log toggles. | `wp_presshub_ai_generation_records` | `php dev-env/scripts/query-db.php "SELECT id, kind, briefing_date, edition, status, post_id FROM wp_presshub_ai_generation_records ORDER BY id DESC LIMIT 5"` |

**Secrets and provider errors:** API keys and Bearer tokens are masked automatically in every log layer (`PressHub_AI_Secret_Masker`). Provider API failures (OpenAI, Anthropic, Gemini, custom endpoints, Imagen, TTS) appear in the plugin log viewer as `ERROR` lines with the provider, model, action, HTTP code and trace ID.

**Audit log behind a proxy:** the audit log records the visitor IP from `REMOTE_ADDR`. If the site sits behind a trusted reverse proxy or CDN, add a filter so the real client IP is used, for example:

```php
add_filter( 'presshub_ai_client_ip_header', fn() => 'HTTP_CF_CONNECTING_IP' );
```

Only set this behind a proxy you control: otherwise clients can spoof the header.

### Log storage and privacy

The three diagnostic log files (application log, LLM prompts & responses, TTS payloads) are written by `PressHub_AI_Log_Paths` to `wp-content/uploads/presshub-ai/` under random file names (`presshub-debug-<suffix>.log`, `presshub-ai-prompts-<suffix>.jsonl`, `presshub-ai-tts-<suffix>.jsonl`). The plugin keeps `.htaccess` (Apache/LiteSpeed), `index.php` and `web.config` (IIS) deny files in that folder and never overwrites an existing one. **Settings → Logs** shows whether the folder can be opened from the web (re-checked every 12 hours, or on demand with **Re-check now**).

- **nginx** ignores `.htaccess`. Add a deny rule to the server block:

  ```nginx
  location ~* /wp-content/uploads/presshub-ai/ { deny all; }
  ```

- **Keep logs outside the website** by defining a writable folder in `wp-config.php`:

  ```php
  define( 'PRESSHUB_AI_LOG_DIR', '/home/example/presshub-logs' );
  ```

  If the folder is missing or not writable, file logging stops and an admin notice names the constant; logs never fall back to uploads.
- **Retention**: rotated log files are deleted after `presshub_ai_log_file_retention_days` (default 14), token logs after `presshub_ai_token_log_retention_days` (default 60) and audit logs after `presshub_ai_audit_log_retention_days` (default 90). **Clear logs** also removes rotated copies.
- The social worker rotates `logs/worker.log` at `PRESSHUB_LOG_MAX_BYTES` (default 10 MB) and keeps `PRESSHUB_LOG_MAX_FILES` old copies (default 5).

### CLI Observability Cheat-Sheet

```bash
# Stream all PressHub logs in real time
php dev-env/scripts/tail-logs.php --follow

# Filter logs by error level
php dev-env/scripts/tail-logs.php --level=ERROR

# Inspect aggregate LLM token usage, latencies, and provider breakdowns
php dev-env/scripts/view-token-logs.php --stats

# View recent token logs with detailed JSON request/response metadata
php dev-env/scripts/view-token-logs.php --detail=1

# Query the SQLite database directly
php dev-env/scripts/query-db.php "SELECT provider, model, total_tokens, duration_ms FROM wp_presshub_ai_token_logs ORDER BY id DESC LIMIT 5"
```

---

## ⚙️ Settings Reference Guide

Navigate to **PressHub AI → Settings** to configure the platform across responsive, per-tab AJAX-saving sections:

- **Providers**: Register API keys, organization IDs, and default models for OpenAI, Anthropic, Google Gemini, and Google Cloud (Vertex AI/TTS). Masked secrets prevent accidental credential exposure.
- **News Harvester**: Configure RSS source URLs, fetch timeout budgets, domain polite delays, concurrency limits, and lead article filtering.
- **News Curation**: Set curation LLM models, curation temperature, maximum article candidate pools, character caps, and language presets.
- **Podcast & Audio Studio**: Configure default podcast presenter personas, audio synthesis voices (male/female, accents), speaking rate, pitch modulation, and audio segment stitching.
- **Social Settings → Automation Worker**: Card and video dimensions, plus TikTok narration (enable, outro sentence, Gemini voice) for articles without audio.
- **Prompt Studio**: Customize system prompts and editorial guidelines for drafts, research, daily briefings, and podcast scripts.
- **Rate Limits & Security**: Enforce per-user request rate limiting windows, IP safeguards, and automated research-log retention sweeps.
- **Logs & Maintenance**: Configure log retention days, log verbosity (`DEBUG`, `INFO`, `WARNING`, `ERROR`), prompt debug logging, token usage analytics, and **Generation Record Retention** (7–365 days, default 90; pruned daily by `presshub_ai_prune_generation_records`).

---

## 💻 Local Development & Testing

The repository features a self-contained local WordPress development environment powered by the official WordPress Core SQLite engine (zero external MySQL/MariaDB or Docker requirements).

### Environment Layout

```
presshub/
├── presshub-ai-editor/          # Core WordPress plugin (PHP)
│   ├── includes/                # Business logic, modules, Provider Store
│   ├── assets/                  # Sidebar UI, admin JS/CSS, prompt assets
│   └── tests/                   # 73 isolated PHP unit test suites
├── presshub-workflow/           # TypeScript workflow & MCP server
│   ├── src/                     # MCP scorecard server & sync utilities
│   └── tests/                   # 6 Jest test suites (81 tests)
├── dev-env/                     # Local SQLite WordPress runtime & CLI tools
│   ├── scripts/                 # tail-logs, view-token-logs, query-db, setup
│   └── wordpress/               # Local WordPress installation
└── docs/                        # Specifications, plans, review records
```

### 1. Starting the Dev Server
```bash
# Start local WordPress instance at http://127.0.0.1:8888
php dev-env/scripts/server.php
```
- **Front-end**: `http://127.0.0.1:8888`
- **WP Admin**: `http://127.0.0.1:8888/wp-admin/` (User: `admin`, Password: `password123`)
- **Live Code Sync**: The plugin directory is dynamically linked via a filesystem junction (`mklink /J`) directly to `presshub-ai-editor/`.

### 2. Running PHP Unit Tests
The PHP test suite runs in isolation without external database dependencies:
```bash
cd presshub-ai-editor
php tests/run-all-tests.php

# PressHub Social Auto-Post PHP unit tests (19 suites, 171 assertions):
php tests/run-social-tests.php

# PressHub Social Worker TypeScript build & Jest tests (5 suites, 76 tests):
cd ../presshub-social-worker
npm test
npm run build
```
*Executes all **84 AI Editor test suites**, **19 Social Auto-Post test suites (171 tests)**, and **5 Social Worker suites (76 tests including 4-tier progressive Twitter architecture with CreateTweet network interception)** with 100% pass rate.*

### 3. Running TypeScript & MCP Workflow Tests
The workflow package integrates Model Context Protocol (MCP) servers and editorial automation:
```bash
cd presshub-workflow
npm install
npm test
npx tsc --noEmit
```
*Executes all **6 Jest test suites (81 tests)** with strict TypeScript type-checking.*

### 4. Running WP-CLI Commands
```bash
# Via Windows batch wrapper
dev-env\bin\wp plugin list
dev-env\bin\wp eval "echo get_option('presshub_ai_log_level');"

# Or via PHP
php dev-env/bin/wp-cli.phar --path=dev-env/wordpress <command>
```

---

## 📦 Requirements & Installation

### Requirements
- **WordPress**: `≥ 6.0` (tested up to `6.7`)
- **PHP**: `≥ 7.4`
- **API Keys**: At least one active provider key (OpenAI, Anthropic Claude, or Google Gemini / Google Cloud Vertex AI & TTS).

### Installation Steps
1. Download the latest release zip (`presshub-ai-editor-x.x.x.zip`) from the [GitHub Releases](https://github.com/eeftychiou/presshub/releases) tab.
2. In your WordPress admin dashboard, navigate to **Plugins → Add New → Upload Plugin**.
3. Choose the downloaded zip file and click **Install Now**.
4. Activate **PressHub AI Co-Pilot**.
5. Navigate to **PressHub AI → Settings** to configure your provider API keys.

### Automatic Updates
The plugin embeds an integrated GitHub update checker. Releases published on the `main` branch with attached zip assets are automatically detected in WordPress. For private repositories, configure the optional GitHub Personal Access Token under **PressHub AI → Settings → Providers**.

### Reliable Background Scheduling (WP-Cron)
For production sites, high-reliability background research and automated daily briefing harvesting should be bound to a system cron daemon rather than relying on web visitor traffic:

```bash
# In wp-config.php:
define( 'DISABLE_WP_CRON', true );

# In crontab (running every minute):
* * * * * wp cron event run --due-now --path=/var/www/html >/dev/null 2>&1
```

---

## 📄 License

This plugin is licensed under the **GPL-2.0-or-later** license. See the license declaration in `presshub-ai-editor/presshub-ai-editor.php` for complete terms.
