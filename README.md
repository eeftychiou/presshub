# PressHub AI Co-Pilot

Enterprise AI Newsroom, Editorial Co-Pilot & Multimedia Publishing Pipeline for WordPress.

[![Version](https://img.shields.io/badge/version-2.5.8-blue.svg)](presshub-ai-editor/presshub-ai-editor.php)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4.svg)](https://php.net)
[![PHP Unit Tests](https://img.shields.io/badge/PHP%20Tests-82%20passed%20%7C%20100%25-brightgreen.svg)](presshub-ai-editor/tests/)
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
- **Social Sharing & OpenGraph Optimization (`PressHub_AI_Social_Meta`)**: Emits rich, opengraph.xyz-compliant metadata (`og:title`, `og:description`, `og:image`, `og:site_name`, `og:type=article`, `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`, and standard `<meta name="description">`). Includes an automated title length optimizer guaranteeing document titles remain ≤ 60 characters for optimal search snippet display.
- **Interactive Briefing Admin (`PressHub_AI_Briefing_Admin`)**: A dedicated WordPress administrative control center providing visibility into each pipeline stage (*Harvest → Curate → Audio/Podcast → Publish*), stage event timelines, manual triggers, single-stage regeneration, and full timezone-aware scheduling. Includes interactive tabs to toggle between **Morning Edition** and **Noon Update**, dynamic in-browser audio player mounting on synthesis completion, and role-gating strictly for Editors and above (`edit_others_posts`, filterable via `presshub_ai_briefing_cap`).

### 2. 🎙️ Podcast Producer, Script Editor & Audio Regeneration
- **Multi-Host Script Engine (`PressHub_AI_Podcast_Producer`)**: Automatically transforms curated news briefings into dynamic, conversational podcast scripts between multiple hosts:
  - **Nameless Speaker Invariant**: Presenters never awkwardly refer to each other or themselves by name; dialogue progresses naturally through ideas.
  - **Spoken Date Formatting**: All dates are converted to natural spoken-language phrasing (e.g. *"3rd of May 2026"* or *"3 Μαΐου 2026"*).
- **Configurable Title Prefixes**: Separate editorial title prefix options for Morning Podcast (`presshub_ai_briefing_podcast_title_prefix`, default *"Πρωινό Podcast Ενημέρωσης - "*) and Noon Podcast (`presshub_ai_briefing_noon_podcast_title_prefix`, default *"Μεσημβρινό Podcast Ενημέρωσης - "*), configurable from Settings.
- **Clean Audio Post Content & Front-Page Excerpt Suppression**: By default, podcast post content displays exclusively the audio player block (`wp:audio`), and raw dialogue text is suppressed from `post_excerpt` and filtered out of front-page post previews, loop cards, and public RSS feeds (configurable via `presshub_ai_briefing_podcast_include_text`). Social meta tags emit a clean editorial audio description instead of leaking dialogue turns.
- **Podcast Dialogue Editor Metabox & One-Click Audio Regeneration**: Editors can review and refine spoken scripts in the **"Podcast Audio & Dialogue Editor"** metabox on podcast edit screens, and click **"Regenerate Audio from Script"** to re-synthesize and stitch the episode in real time.
- **Resilient Audio Synthesis & Cron Error Isolation**: Synthesizer accepts nullable custom scripts and automatically shifts parameters (e.g. `synthesize_podcast($date, 'noon')`), while briefing crons encapsulate generation and synthesis in try/catch blocks with structured logging to eliminate unhandled runtime fatal errors.
- **Broadcast Audio Synthesizer & Gemini 3.8 TTS REST Architecture (`PressHub_AI_Audio_Synthesizer` & `PressHub_AI_API_Client`)**:
  - **Zero Narrated Instructions**: Passes clean verbatim dialogue turns in `contents[0].parts[].text` without prepended prompt directives, eliminating prompt narration on modern models (`gemini-3.8-flash-tts` / `gemini-3.8-flash-lite-tts`).
  - **Official Gemini 3.8 TTS REST Standard (`structured` — Recommended)**: Conforms strictly to Google's official Gemini 3.8 TTS REST specification. Turn-level `speech_metadata.style` carries delivery emotion, speaking rate (`0.85x - 1.25x`), and voice pitch tuning (`-5.0 to +5.0 semitones`) without top-level system instructions. Inactive settings (e.g. system instructions) dynamically grey out in the UI with informative badges.
  - **Hybrid / System Instruction Override Mode (`hybrid_instruction`)**: Passes both turn-level `speech_metadata.style` and top-level `systemInstruction` with resolved speaker persona and rate placeholders.
  - **Multi-Speaker Persona Mapping & Deterministic Binding**: Maps Presenter 1 and Presenter 2 to distinct voice personas (e.g. Charon, Kore, Fenrir, Aoede) in `speechConfig.multiSpeakerVoiceConfig`. Retains canonical inline speaker prefixes (`Presenter 1: ...`, `Presenter 2: ...`) in `contents[0].parts[].text` so Gemini's multi-speaker model deterministically binds turns to voices across all topic chunks without random voice swapping.
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
| **Layer 1: Structured Logs** | Subsystem operations, harvesting steps, TTS synthesis, HTTP events | `wp-content/presshub-debug.log` | `php dev-env/scripts/tail-logs.php --source=presshub` |
| **Layer 2: Token Telemetry** | Model IDs, prompt/completion tokens, latency, cost, and JSON metadata | `wp_presshub_ai_token_logs` | `php dev-env/scripts/view-token-logs.php` |
| **Layer 3: Security & Audit** | Settings updates, provider credential changes, and admin actions | `wp_presshub_ai_audit_log` | `php dev-env/scripts/query-db.php "SELECT * FROM wp_presshub_ai_audit_log"` |
| **Layer 4: WordPress Core** | PHP runtime warnings, fatal tracebacks, cURL/environment issues | `wp-content/debug.log` | `php dev-env/scripts/tail-logs.php --source=wp` |

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
- **Prompt Studio**: Customize system prompts and editorial guidelines for drafts, research, daily briefings, and podcast scripts.
- **Rate Limits & Security**: Enforce per-user request rate limiting windows, IP safeguards, and automated research-log retention sweeps.
- **Logs & Maintenance**: Configure log retention days, log verbosity (`DEBUG`, `INFO`, `WARNING`, `ERROR`), prompt debug logging, and token usage analytics.

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
```
*Executes all **73 test suites** with 100% pass rate.*

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
