# PressHub AI Architecture & Technical Specification

## 1. System Overview

**PressHub AI Co-Pilot** is an autonomous newsroom, editorial assistant, and multimedia broadcasting system for WordPress. The system gathers news from external RSS and web sources, synthesizes bilingual daily briefings, produces multi-presenter conversational podcast scripts, synthesizes natural speech audio via Google Gemini / Google Cloud TTS, and integrates an AI co-pilot directly into the Gutenberg block editor.

---

## 2. Core Architectural Principles

1. **Settings-First Principle**:
   - No operational threshold, time budget, batch size, or title format is hard-coded into business logic.
   - Every operational parameter is registered as an option in `PressHub_AI_Settings_Storage` with full sanitization, documented defaults, and UI exposure in `PressHub AI → Settings`.
2. **Zero-Silent-Fallback Principle**:
   - Missing configuration, prompts, or model specifications fail loudly and explicitly via `WP_Error` or exceptions with structured error logging. Hidden runtime string fallbacks are strictly prohibited.
3. **External Prompt Assets**:
   - Default prompts and instructions reside in `assets/prompts/` and are loaded via `PressHub_AI_Prompt_Loader`.
4. **Clean Slate Architecture**:
   - No deprecated multi-tier fallbacks or legacy option chains. `PressHub_AI_Provider_Store` is the sole source of truth for AI providers and models.
5. **4-Layer Enterprise Observability**:
   - **Layer 1**: Structured log (`wp-content/uploads/presshub-ai/presshub-debug-<suffix>.log`). All three file logs (app, prompts, TTS) resolve through `PressHub_AI_Log_Paths`: random suffix (`presshub_ai_log_file_suffix`), deny files (`.htaccess`, `index.php`, `web.config`) kept in the folder, optional `PRESSHUB_AI_LOG_DIR` outside the web root (no fallback to uploads when it is unusable), and a web-exposure self-check (`PressHub_AI_Log_Exposure_Check`) on the Logs tab
   - **Layer 2**: Token log database (`wp_presshub_ai_token_logs`)
   - **Layer 3**: Audit log (`wp_presshub_ai_audit_log`)
   - **Layer 4**: WordPress `debug.log`
   - **Generation records**: `wp_presshub_ai_generation_records` (`PressHub_AI_Generation_Recorder`), a durable, always-on record of everything that went into each briefing and podcast-script generation (see §4.19)
   - **Secret masking**: `PressHub_AI_Secret_Masker` is the single masking implementation for logs. `PressHub_AI_Logger` masks every context array and message before writing (so neither the file nor the `error_log` mirror sees a plain key), `PressHub_AI_Audit_Logger` masks audit details, and the prompt/TTS debug logs mask their config, meta and header fields while keeping prompt bodies verbatim. Provider API failures are logged through `PressHub_AI_Logger::error()` with `provider`, `model`, `action` and `http_code` context instead of raw `error_log()`.
   - **Log level default**: `DEBUG` when `WP_DEBUG` is on, otherwise `INFO`, unless `presshub_ai_log_level` is set. Prompt debugging does not change the level.
   - **Audit integrity**: clearing the audit log writes an `audit_log_cleared` event (rows deleted, actor) as the first row of the new trail; the client IP is `REMOTE_ADDR` unless the `presshub_ai_client_ip_header` filter names a trusted proxy header.

---

## 3. High-Level Component Relationship

```mermaid
graph TD
    subgraph Configuration & Storage
        Settings[PressHub_AI_Settings] --> Storage[PressHub_AI_Settings_Storage]
        Storage --> Migration[PressHub_AI_Settings_Migration]
        Storage --> Render[PressHub_AI_Settings_Render]
        Providers[PressHub_AI_Provider_Store]
    end

    subgraph Dual-Edition Pipeline
        Cron[WP-Cron Scheduler] -->|06:30 & 12:00| Harvester[PressHub_AI_News_Harvester]
        Cron -->|07:15 & 12:45| Curator[PressHub_AI_News_Curator]
        Harvester -->|raw-articles(-noon).json| Curator
        Curator -->|Morning Text Post Context| CuratorNoon[Curator: Noon Context Injection]
        Curator -->|Text Story Post & Metadata| Producer[PressHub_AI_Podcast_Producer]
        Producer -->|podcast-script(-noon).txt| Synthesizer[PressHub_AI_Audio_Synthesizer]
        Synthesizer -->|podcast(-noon).mp3| PodcastPost[WordPress Audio Post]
    end

    subgraph Administration & Operational Hub
        AdminHub[PressHub_AI_Briefing_Admin] -->|Date & Edition Selector| Harvester
        AdminHub --> Curator
        AdminHub --> Producer
        AdminHub --> Synthesizer
        Ajax[PressHub_AI_Ajax_Handlers] --> AdminHub
        AdminJS[assets/briefing-admin.js] --> Ajax
    end

    subgraph Observability
        Harvester --> Logger[PressHub_AI_Logger]
        Curator --> Logger
        Producer --> TokenLogger[PressHub_AI_Token_Logger]
        Synthesizer --> TokenLogger
        Storage --> AuditLogger[PressHub_AI_Audit_Logger]
        Curator --> Recorder[PressHub_AI_Generation_Recorder]
        Producer --> Recorder
        Synthesizer --> Recorder
    end
```

---

## 4. Subsystem Breakdown

### 4.1 News Harvester (`PressHub_AI_News_Harvester`)
- **Role**: Scrapes articles from configured news sources (web pages and RSS feeds).
- **Features**:
  - Multi-tier extraction (HTML selectors, Readability, RSS fallback).
  - Polite crawl delay (`presshub_ai_harvest_polite_delay`) and domain throttling.
  - Overall time budget enforcement (`presshub_ai_harvest_time_budget`).
  - Multi-edition snapshot persistence:
    - Morning: `raw-articles.json` + `presshub_ai_harvest_snapshot_{date}` transient.
    - Noon: `raw-articles-noon.json` + `presshub_ai_harvest_snapshot_noon_{date}` transient.

### 4.2 News Curator (`PressHub_AI_News_Curator`)
- **Role**: Selects, deduplicates, and curates news stories into a coherent news briefing.
- **Context Management**:
  - Caps articles (`presshub_ai_curation_max_articles`) and characters (`presshub_ai_curation_max_chars_per_article`).
  - **Noon Edition Morning Context Ingestion**: When generating the Noon edition (`edition = 'noon'`), it queries the morning briefing post (`get_briefing_content($date, 'morning')`). If found, it injects the morning briefing into the curation LLM prompt within an explicit reference block (`--- ΠΡΩΙΝΗ ΕΝΗΜΕΡΩΣΗ ---`) with instructions to highlight new developments since morning.
- **Publishing & Metadata**:
  - Sets post title with configurable prefix (`presshub_ai_briefing_text_title_prefix` for morning, `presshub_ai_briefing_noon_text_title_prefix` for noon).
  - Tags posts with `_presshub_briefing_edition` (`morning` or `noon`) and `_presshub_briefing_date`.

### 4.3 Podcast Producer (`PressHub_AI_Podcast_Producer`)
- **Role**: Generates broadcast-quality spoken dialogue scripts from curated news briefings.
- **Formatting Invariants**:
  - Enforces nameless presenter tags (`[SPEAKER_1]`, `[SPEAKER_2]`, `[SPEAKER_3]`).
  - Translates numeric dates to natural spoken words.
  - Multi-edition persistence:
    - Morning: `podcast-script.txt` + `podcast-script.meta.json` (`edition => 'morning'`).
    - Noon: `podcast-script-noon.txt` + `podcast-script-noon.meta.json` (`edition => 'noon'`).
  - Context Modes:
    - `curated_briefing`: Uses text story post content.
    - `harvested_articles`: Uses raw harvested articles pool.

### 4.4 Audio Synthesizer (`PressHub_AI_Audio_Synthesizer` & `PressHub_AI_API_Client`)
- **Role**: Synthesizes speech audio from dialogue scripts using Google Gemini (e.g. `gemini-3.8-flash-tts`, `gemini-3.8-flash-lite-tts`, `gemini-2.5-flash-native-audio`) or Google Cloud TTS.
- **Gemini 3.8 TTS REST Specification & Clean Slate Architecture**:
  - **Verbatim Transcript Invariant**: Spoken content in `contents[0].parts[].text` contains strictly verbatim dialogue text. Prompt directives, delivery styles, and actor roles are **never** prepended to transcript strings, eliminating prompt narration.
  - **Official Gemini 3.8 TTS REST Standard (`structured` — Recommended)**:
    - Strictly conforms to Google's official Gemini 3.8 TTS REST documentation (`https://ai.google.dev/gemini-api/docs/generate-content/speech-generation#rest`).
    - Presenter voices are mapped in `speechConfig.multiSpeakerVoiceConfig.speakerVoiceConfigs` (or `voiceConfig` for single speaker).
    - **Zero-Prefix Verbatim Audio Invariant**: Speaker tags (e.g. `Presenter 1:`, `Presenter 2:`) are cleanly stripped from `contents[0].parts[].text` so the neural TTS engine does not vocalize speaker labels aloud in the generated audio. Turn-level `speech_metadata.speaker` (`Presenter 1` or `Presenter 2`) explicitly tags each turn to map to `speechConfig.multiSpeakerVoiceConfig.speakerVoiceConfigs`.
    - Delivery emotions, pacing/speed, and pitch tuning are passed via sustained turn-level `speech_metadata.style` composed via `PressHub_AI_API_Client::compose_tts_style_string()`.
    - Omits top-level `systemInstruction` (not part of the official Gemini 3.8 TTS REST schema).
    - In the Settings UI, the Gemini Speech System Instruction setting dynamically greys out with an informative notice badge explaining that Google controls tone, speed, and pitch directly via turn-level `speech_metadata.style`.
  - **Hybrid / System Instruction Override Mode (`hybrid_instruction`)**:
    - Passes both turn-level `speech_metadata.style` and a top-level `systemInstruction` block with `{style_instruction}`, `{speed}`, `{pitch}`, `{speaker1}`, and `{speaker2}` placeholders resolved. All settings remain active.
  - **Legacy Prepended Instruction Mode (`legacy` — Deprecated)**:
    - Backward-compatibility mode prepending style instructions directly into script text.
    - System instruction, speaking speed, and voice pitch settings dynamically grey out in the UI.
  - **RIFF WAV Header Handling**:
    - Gemini 3.8 TTS returns full WAV files with RIFF headers; audio decoding checks for the RIFF signature before applying PCM-to-WAV wrapping to prevent audio stream corruption.
- **Unified Turn-by-Turn Synthesis & Voice Isolation**:
  - Eliminates neural voice drift and speaker bleeding across 1, 2, and 3 hosts by synthesizing every dialogue turn individually as an isolated single-speaker request via `synthesize_turn()`.
  - Zero `multiSpeakerVoiceConfig` in podcast audio; every speaker turn uses dedicated `voiceConfig` with isolated persona assignments.
  - Turns within each topic or whole-script mode are stitched with Settings-First natural pauses (`presshub_ai_briefing_tts_turn_pause_ms`, default 250ms), and topics are stitched with topic pauses (`presshub_ai_briefing_tts_topic_pause_ms`, default 600ms) and optional intro/transition/outro SFX.
  - Inter-turn throttling (`presshub_ai_briefing_tts_request_delay_ms`, default 250ms) protects against API rate limiting (automatically bypassed in unit test mock harnesses).
- **Resumable Turn-by-Turn Disk Cache**:
  - Each synthesized turn is hashed deterministically (`hash('sha256', engine|model|voice|style|speed|pitch|payload_mode|text)`) and cached in `wp-content/uploads/presshub-ai/tts-cache/{date}/turn_{hash}.{wav|mp3}`.
  - On retry of a failed or interrupted podcast generation, completed turns are served instantly from disk cache, eliminating redundant API cost and ensuring rapid resumption.
- **Speaker Parsing & Greek Dialogue Colon Protection**:
  - Tightened regex ensures sentences with mid-sentence colons (`Ο υπουργός δήλωσε: τα μέτρα...`) are never falsely parsed as speaker turns.
  - Canonical speaker tags (`[SPEAKER_1]`, `[SPEAKER_2]`, `[SPEAKER_3]`, `[Presenter 1]`, etc.) are mapped to configured host names in transcript output.
- **Exponential Backoff & Structured Error Logging**:
  - Transient HTTP `429`, `500`, `502`, `503`, `504` or connection errors retry automatically up to `presshub_ai_briefing_tts_max_retries` (default 2) with exponential backoff (`presshub_ai_briefing_tts_retry_backoff_ms * 2^attempt`, default 2000ms base).
  - Response error messages, statuses, and truncated bodies are captured in structured payload logs.
- **Centralized Diagnostic Prompt Logging**:
  - `call_provider()` centralizes prompt/response debugging via `presshub_ai_log_prompts()` with caller suppression flags (`$suppress_provider_prompt_log`) to eliminate duplicate logs. Image generation (`generate_image`) and TTS dispatches (`podcast_tts`) emit dedicated trace entries.
- **Voice Catalog & Presenter Configuration**:
  - Uses canonical voice catalog (`assets/data/tts-voice-catalog.json`).
  - Supports 30+ voice personas with speed and pitch controls, grouped in WP Admin by gender `<optgroup>`s (Female Voices first for Presenter 1, Male Voices first for Presenter 2 & 3).
  - Configurable Presenter Names (`presshub_ai_briefing_host_female` / `Presenter 1 Name`, `presshub_ai_briefing_host_male` / `Presenter 2 Name`, `presshub_ai_briefing_host_tertiary` / `Presenter 3 Name`) with single source of truth getters in `PressHub_AI_Settings_Storage`.
  - Settings-First TTS timing & retry controls: `presshub_ai_briefing_tts_turn_pause_ms` (0-2000 ms, default 250), `presshub_ai_briefing_tts_topic_pause_ms` (0-5000 ms, default 600), `presshub_ai_briefing_tts_request_delay_ms` (0-5000 ms, default 250), `presshub_ai_briefing_tts_max_retries` (0-5, default 2), and `presshub_ai_briefing_tts_retry_backoff_ms` (500-15000 ms, default 2000).
- **Multi-Edition Audio & Title Prefixes**:
  - Morning: `podcast.mp3` / `podcast.wav` attached to morning podcast post. Configurable title prefix (`presshub_ai_briefing_podcast_title_prefix`, default `"Πρωινό Podcast Ενημέρωσης - "`).
  - Noon: `podcast-noon.mp3` / `podcast-noon.wav` attached to noon podcast post. Configurable title prefix (`presshub_ai_briefing_noon_podcast_title_prefix`, default `"Μεσημβρινό Podcast Ενημέρωσης - "`).
  - Saves post meta `_presshub_briefing_edition` (`'morning'` or `'noon'`).
- **Podcast Excerpt & Dialogue Suppression**:
  - Audio posts contain only the WordPress audio block (`wp:audio`) in `post_content`.
  - The dialogue transcript is stored in post meta `_presshub_podcast_script` for editing/regeneration in the editor metabox.
  - `post_excerpt` is explicitly set to empty (`''`) on creation and update when `include_text` is off (0).
  - Excerpt filter (`filter_podcast_excerpt`) hooks into `get_the_excerpt` to suppress dialogue text on front-page article previews, loop cards, and archives for both newly generated and legacy podcast posts.
  - Social metadata (`PressHub_AI_Social_Meta`) emits a clean audio summary instead of leaking private dialogue scripts or speaker aliases into OpenGraph and Twitter cards.

- **Resilient Method Signature & Parameter Shift**:
  - `PressHub_AI_Audio_Synthesizer::synthesize_podcast( string $date = '', ?string $custom_script = '', $edition_or_client = 'morning', ?PressHub_AI_API_Client $api_client = null, int $existing_post_id = 0 )` accepts nullable `$custom_script`.
  - Provides smart parameter shifting: if `$custom_script` is `'morning'` or `'noon'`, it automatically maps to `$edition` and resets script to `''`, preventing PHP 8.2+ `TypeError` exceptions.
  - When script is null or empty, it automatically resolves the saved dialogue script from disk for the specified edition.

### 4.5 Briefing Hub Admin & Client Controller
- **Classes**: `PressHub_AI_Briefing_Admin`, `PressHub_AI_Ajax_Handlers`, `assets/briefing-admin.js`.
- **Edition Switcher**:
  - Admin Hub displays responsive navigation tabs: **Morning Edition (Πρωινή Ενημέρωση)** vs. **Noon Update (Μεσημβρινή Ενημέρωση)**.
  - Container element carries `data-edition="morning|noon"`.
  - Client-side script maintains `currentEdition` and attaches `edition: currentEdition` to all AJAX pipeline dispatches (`briefing_get_status`, `briefing_run_harvest`, `briefing_run_curation`, `briefing_run_script`, `briefing_save_script`, `briefing_generate_audio`, `briefing_upload`).
  - Dynamic DOM Audio Player Injection: If the audio player DOM element was absent on initial page load (pending audio), completion of AJAX synthesis dynamically creates and mounts `<audio controls>` into `#briefing-audio-container` without requiring a page reload.
  - Full backward compatibility for historical posts that lack `_presshub_briefing_edition` meta (queried as morning).

### 4.6 Featured Image Pools & Interactive Media Library Picker
- **Configuration & Options**:
  - `presshub_ai_briefing_featured_image_ids_morning`: Comma-separated WordPress Media Library attachment IDs for Morning briefings and podcasts.
  - `presshub_ai_briefing_featured_image_ids_noon`: Comma-separated WordPress Media Library attachment IDs for Noon updates. If unpopulated, automatically falls back to the Morning pool.
  - `presshub_ai_briefing_featured_image_ids`: Retained for backward compatibility as legacy fallback if Morning pool is not explicitly configured.
  - Helper methods: `PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( $edition )`, `get_briefing_featured_image_ids_morning()`, `get_briefing_featured_image_ids_noon()`, `get_used_briefing_thumbnail_ids_for_date( $date, $exclude_post_id )`, and `get_random_briefing_featured_image_id( $edition, $exclude_ids )`.
- **Interactive UI**:
  - Replaced plain text input with interactive WordPress Media Library picker modal (`wp.media`) supporting multiple selection.
  - Visual thumbnail card grid with image previews, `#ID` badge overlay, individual `×` removal buttons, and "Clear All" action.
  - Integrated into dirty-state form tracking and responsive save bar.
- **Behavior & Collision Avoidance**:
  - Automatically selects a random image ID from the edition-appropriate pool upon creation of daily text briefings and podcast audio posts.
  - **Same-Day Collision Avoidance**: When multiple posts are generated on the same date (Morning Text Briefing, Morning Podcast, Noon Text Briefing, Noon Podcast), the system checks existing posts for that date via `PressHub_AI_Settings_Storage::get_used_briefing_thumbnail_ids_for_date( $date, $post_id )`.
  - Excludes already-assigned thumbnail IDs from the edition's pool (`array_diff`). If alternatives exist in the pool, a distinct featured image is selected. If the pool is exhausted, it gracefully falls back to the full pool rather than failing.
  - Assigns the image as the post thumbnail (`set_post_thumbnail()`) and sets it as the social card fallback image (`og:image` and `twitter:image`).

### 4.7 Podcast Post Content & Dialogue Script Editor
- **Content Format Invariant**:
  - By default, podcast post `post_content` contains **only the audio player block** (`wp:audio`) and strips transcript text to maintain a clean podcast player presentation for readers.
  - Controlled by option `presshub_ai_briefing_podcast_include_text` (default: 0/disabled).
- **Dialogue Script Persistence & Editor Metabox**:
  - The complete spoken dialogue script is stored in post meta `_presshub_podcast_script`.
  - On podcast edit screens, a dedicated metabox **"Podcast Audio & Dialogue Editor"** (`presshub_ai_podcast_editor`) provides:
    - Live audio playback of the synthesized episode.
    - Editable textarea containing the dialogue script.
    - **"Regenerate Audio from Script"** button triggering the `presshub_ai_regenerate_podcast_audio` AJAX endpoint.
  - The backend method `PressHub_AI_Audio_Synthesizer::regenerate_podcast_for_post()` re-synthesizes audio turns, stitches WAV/MP3 files, updates the media attachment and post content audio block, and saves updated stats in post meta without altering post IDs or losing editorial edits.

### 4.8 Social Metadata & OpenGraph Engine (`PressHub_AI_Social_Meta`)
- **Settings-First Configuration**:
  - `presshub_ai_social_meta_enabled`: Master toggle (default: 1) controlling whether OpenGraph, Twitter Cards, and description tags are output in `wp_head`.
  - `presshub_ai_social_meta_all_posts`: Scope toggle (default: 1) enabling social meta tags and title optimization across all published single posts, or restricting exclusively to PressHub posts when unchecked.
  - Managed via `PressHub_AI_Settings_Storage::get_social_meta_enabled()` and `get_social_meta_all_posts()`, rendered under `PressHub AI → Settings → Advanced & System → Social & OpenGraph Metadata`.
- **Expanded Post Recognition**:
  - In addition to daily briefings and podcasts (`_presshub_briefing_type`, `_presshub_podcast_script`), `is_presshub_post()` automatically recognizes AI Co-Authored posts (`_presshub_ai_coauthored`), editorial scorecards (`_presshub_ai_scorecard`), and AI Featured Image prompts (`_presshub_ai_image_prompt`).
- **OpenGraph & Twitter Card Tags**:
  - Hooks `wp_head` at priority 5 to output complete social sharing metadata required by Facebook, X (Twitter), LinkedIn, Slack, and Discord (tested for opengraph.xyz compliance):
    - `og:site_name`, `og:type` (`article`), `og:title`, `og:url`, `og:description`, `og:image`, `og:image:secure_url`.
    - `description` standard HTML meta tag for Google search snippets.
    - `twitter:card` (`summary_large_image`), `twitter:title`, `twitter:description`, `twitter:image`.
- **Multi-Tier Social Image Resolution**:
  - 1. Featured image thumbnail (`has_post_thumbnail()`).
  - 2. First inline `<img>` extracted from `post_content`.
  - 3. Edition Featured Image Pool (`PressHub_AI_Settings_Storage::get_briefing_featured_image_ids()`).
  - 4. Site icon fallback (`get_site_icon_url()`).
- **Document Title Length Optimizer**:
  - Hooks `document_title_parts` at priority 20.
  - Ensures the combined `<title>` (post title + site name) remains **<= 60 characters** across briefing posts, podcast episodes, and AI Co-Authored articles to eliminate search engine snippet and social card truncation.

### 4.9 Capability & Role Access Gates
- **Editor-and-Above Enclosure**:
  - The Daily Briefing Hub admin menu and page (`admin.php?page=presshub-ai-briefing`) require `edit_others_posts` (WordPress Editors and Administrators), completely blocking simple Authors and Contributors.
  - All Briefing Hub AJAX endpoints (`briefing_run_harvest`, `briefing_run_curation`, `briefing_run_script`, `briefing_generate_audio`, `presshub_ai_regenerate_podcast_audio`) strictly enforce `current_user_can( 'edit_others_posts' )`.

### 4.10 AI Co-Pilot Block-Level Gutenberg Editing
- **Sidebar Architecture**:
  - Uses `applyBlockLevelReplacement` in `assets/sidebar.js` to modify only Gutenberg block attributes (`attributes.content` / `attributes.text`) without destroying block structures or resetting editor state.
  - Isolated per-post chat history via `presshub_ai_chat_{postId}` in localStorage.
  - Drag-resizable container with bounds between 320px and 960px.
  - Automatic hydration of existing post title and content when queried from the post edit screen.

### 4.11 Automated AI Editorial Review & Scorecard (`PressHub_AI_QA_Reviewer`)
- **4-Key Editorial Evaluation**:
  - `score`: Integer 0-100 indicating publication readiness (compared against threshold `presshub_ai_qa_min_score`, default: 80).
  - `libel_risk`: Legal exposure rating clamped to `"Low"`, `"Medium"`, or `"High"`.
  - `feedback`: 2-3 sentence critique summarizing editorial quality, structure, and balance.
  - `legal_concerns`: 1-2 sentence assessment highlighting potentially defamatory or unverified assertions (or `"None identified"`).
- **Post Persistence & Caching**:
  - Scorecard data persisted in `_presshub_ai_scorecard` post meta.
  - Content hash stored in `_presshub_ai_scorecard_hash` avoids redundant LLM dispatches when post text has not changed.
- **Workflow & UI Presentation**:
  - Editorial review metabox (`class-metaboxes.php`) and Admin JS (`assets/admin.js`) render the Score badge, Libel Risk badge (color-coded: subtle green for Low, orange for Medium, red for High), editorial critique paragraph, and alert box for active legal concerns.
### 4.12 Gemini Speech API JSON Payload Inspector & Live Audio Tester
- **Components**: `PressHub_AI_Settings_Render::render_gemini_tts_tester()`, `PressHub_AI_Ajax_Handlers` (`presshub_ai_preview_tts_payload`, `presshub_ai_test_tts_synthesis`), `PressHub_AI_API_Client::test_synthesize_speech()`, `assets/admin.js`.
- **Live JSON Payload Inspection**:
  - Automatically reconstructs the exact JSON payload sent to Gemini's speech generation endpoint in real time whenever any audio option (Presenter 1/2 voices, speaking rate, voice pitch, delivery style, payload structure mode, custom system instructions, or test script) is modified.
  - Formatted inside a responsive, monospace syntax container with one-click clipboard copy (`#presshub-copy-tts-json-btn`).
- **Interactive Audio Synthesis Testing Studio**:
  - Allows editors to verify voice pronunciation, pacing, pitch, and multi-speaker turn transitions without running full daily briefing pipelines.
  - Features quick-switch presets (1 Presenter vs. 2 Presenters) and editable sample scripts in Greek.
  - Sends AJAX request to `test_synthesize_speech()` which invokes Gemini's endpoint and returns an in-browser playable Base64 audio data URL, generation duration in milliseconds, audio byte size, and model identifier.
  - Embeds HTML5 `<audio>` player with instant playback and direct audio file download link.
- **Access & Navigation**:
  - Located in Daily Briefing settings (`admin.php?page=presshub-ai#briefing`).
  - Directly accessible from the Daily Briefing Hub (`admin.php?page=presshub-ai-briefing`) via a direct link button in the **Audio Synthesis Stage Card** (`#stage-audio`).
  - Gated by capability filter `presshub_ai_briefing_cap` (defaults to `edit_others_posts`, Editors and above).

### 4.13 Module Tuning Safeguards & Sub-Threshold Fallbacks
- **Problem & Vulnerability**:
  - When saving settings or tabs without all registered fields present, unrendered options received empty strings or `null`. An integer clamp `max( 1, (int) $val )` previously converted empty strings (`(int) "" === 0`) to `1` for `max_tokens` and `5` for `timeout`.
  - Storing `max_tokens = 1` in `wp_options` caused LLM text and script generations to abort immediately after 1 token with `"reached maximum output tokens limit (1 tokens)"`.
- **Architectural Safeguards**:
  - **Resolution Threshold Guard**: `PressHub_AI_API_Client::resolve_options_for_module()` enforces strict minimum thresholds (`max_tokens >= 64`, `timeout >= 10s`). Sub-threshold options in `wp_options` are safely bypassed in favor of the configured provider record (`$provider_record['max_tokens']`, e.g. 60,000 for Gemini) or class defaults (`PressHub_AI_Provider_Defaults::default_max_tokens()`).
  - **Sanitizer Protection**: `PressHub_AI_Settings_Storage::sanitize_max_tokens()` returns `0` (indicating no module-level override) for `null` or empty string inputs instead of clamping to 1.
  - **Settings-First Accessors**: Added dedicated storage helpers (`get_briefing_podcast_max_tokens()`, `get_briefing_text_max_tokens()`, `get_coauthor_max_tokens()`, `get_copilot_max_tokens()`) returning `0` when unset or `< 64`.
  - **Exposed Settings Inputs**: Exposed explicit "Maximum Output Tokens" inputs across all pipeline modules in `PressHub AI → Settings` with safe bounds (`min="64" max="65536"`).
  - **Auto-Purge Migration**: `presshub_ai_migrate_fix_corrupted_tuning_options()` cleanses corrupted database rows (`max_tokens < 64`, `timeout < 10`) on initialization.

### 4.14 AI Featured Image Generation & Studio Engine
- **Dedicated Settings Tab ("AI Featured Images")**:
  - Located under `PressHub AI → Settings` (`#images`).
  - Managed via `PressHub_AI_Settings_Storage`:
    - `presshub_ai_image_feature_enabled`: Master feature toggle (0/1).
    - `presshub_ai_image_prompt_provider` & `presshub_ai_image_prompt_model`: AI provider & model to analyze article content and formulate visual prompts.
    - `presshub_ai_image_generation_provider`: AI provider for image synthesis (Google Gemini or OpenAI). The synthesis model is resolved directly from the chosen provider's configured `default_model` in `PressHub_AI_Provider_Store` (e.g. `gemini-3.1-flash-image` or `dall-e-3`), eliminating redundant module overrides in accordance with Clean Slate Architecture.
    - `presshub_ai_image_aspect_ratio`: Configurable aspect ratio (`16:9`, `1:1`, `4:3`).
    - `presshub_ai_image_analysis_prompt`: Operator-tunable prompt template (seeded from external asset `assets/prompts/article_image_analysis_prompt.txt`).
- **Editorial Realism & Safety Invariants**:
  - The analysis prompt template strictly enforces:
    - Photojournalistic realism and 35mm documentary photography aesthetics.
    - Natural lighting and authentic textures, strictly eliminating "AI plastic/wax" looks, distorted limbs, surreal saturation, or sci-fi glows.
    - Strict prohibition of rendered text, typography, headlines, watermarks, or lettering.
    - Strict avoidance of controversial, gory, explicit, or offensive imagery.
- **Daily Briefing Hub Integration & Resilient Pool Fallback**:
  - `presshub_ai_briefing_featured_image_source` (Text Briefs) & `presshub_ai_briefing_podcast_featured_image_source` (Podcasts): Allow selecting either `"pool"` (Featured Image Pool with same-day collision avoidance) or `"auto_generate"` (Automatic AI Image Generation).
  - Both `PressHub_AI_News_Curator::create_wordpress_post()` and `PressHub_AI_Audio_Synthesizer::create_podcast_post()` automatically analyze generated content, synthesize an editorial image, sideload it to the WordPress Media Library, and assign it as the post thumbnail.
  - If auto-generation encounters provider errors or timeouts, the pipeline automatically and gracefully falls back to the edition's Featured Image Pool without failing the publication.
- **Interactive Post Editor Studio ("AI Featured Image Studio")**:
  - Embedded inside the "AI Co-Author & Editorial Review" metabox on post edit screens.
  - **Draft Image Prompt from Content**: Extracts live editor content (Gutenberg `core/editor` or Classic TinyMCE) and drafts an editorial visual prompt via AJAX (`presshub_ai_generate_image_prompt`).
  - **Editable Prompt Textarea**: Editors can inspect, refine, or rewrite the prompt; persisted in post meta `_presshub_ai_image_prompt`.
  - **Generate / Regenerate Image**: Dispatches to the configured image generation provider via AJAX (`presshub_ai_generate_featured_image`), saves to Media Library, and displays an instant visual preview card.
  - **Set as Featured Image**: Assigns the media attachment as the post thumbnail via AJAX (`presshub_ai_set_featured_image`) and synchronizes Gutenberg editor state (`core/editor.editPost`).

### 4.15 AI Co-Author Story Drafting & Source Material Capacity
- **Problem**: Previously, source material for story drafting was restricted to a hardcoded 20,000 character limit, preventing editors from feeding full interview transcripts, investigative research dossiers, or long primary documents into large-context LLMs.
- **Settings-First Option**:
  - Registered option `presshub_ai_coauthor_max_source_chars`.
  - Storage helper `PressHub_AI_Settings_Storage::get_coauthor_max_source_chars()` defaults to `200000` (200,000 characters).
  - Sanitizer `PressHub_AI_Settings_Storage::sanitize_coauthor_max_source_chars()` clamps inputs to safe bounds (1,000 to 1,000,000 characters).
  - Configurable in WordPress Admin under `PressHub AI → Settings → AI Co-Author & Editorial Review → Co-Author Story Drafting` (`#coauthor`).
- **Enforcement & Localization**:
  - `PressHub_AI_Ajax_Handlers::generate_draft()` validates source text against `get_coauthor_max_source_chars()`. If exceeded, it halts immediately with a localized error string formatted with `number_format_i18n()` displaying the active limit.
  - `PressHub_AI_Metaboxes::render_metabox()` renders `maxlength` on `#presshub_ai_source_material` aligned with the active configured limit.

---

### 4.16 PressHub Social Auto-Post Subsystem (Natively Integrated into `presshub-ai-editor`)
- **Overview**: An automated multi-platform social media publishing and queue dispatcher pipeline for WordPress, natively bundled into `presshub-ai-editor`, delivering optimized copies and media to Twitter/X, Facebook, Instagram, and TikTok via both official APIs and browser automation worker integration. By integrating into the primary plugin, social operations natively leverage `PressHub_AI_Logger`, `PressHub_AI_Token_Logger` (Layer 2 token tracking for social copywriting), and `PressHub_AI_Audit_Logger` (Layer 3 mutation auditing).
- **Components & Responsibilities**:
  - **`PressHub_Social_Logger`**: Structured logger delegating directly to `PressHub_AI_Logger` (Layer 1 structured logs) and `PressHub_AI_Audit_Logger` (Layer 3 audit trail), while maintaining an in-memory diagnostic ring buffer, with `mask_secret()` redaction (`sk-p••••••••7890`).
  - **`PressHub_Social_Prompt_Loader`**: External prompt asset loader (`assets/prompts/*.txt`) enforcing directory traversal protection and Zero-Silent-Fallback error handling.
  - **`PressHub_Social_Settings_Storage` & `PressHub_Social_Settings_Render`**: Settings-First storage and UI controller exposing all operational knobs (worker mode `pull` vs `push`, engines per platform, AI tuning overrides, retry caps, dispatch intervals, media dimensions, worker credentials, platform API tokens, and `presshub_social_github_token` for automatic GitHub release updates) with clamping sanitizers, unified multi-tab form rendering (`#presshub-social-settings-form` containing all 6 tab panes to prevent partial-submission option wiping), and automatic `v2.6.3` option repair.
  - **`PressHub_Social_REST_Controller`**: REST API controller (`presshub-social/v1`) powering **Pull Mode** for local workers. Provides `/worker/heartbeat`, `/worker/claim`, and `/worker/complete` authenticated via constant-time token verification (`X-PressHub-Secret`, `X-Worker-Secret`, or `Authorization: Bearer`). Claims due automation jobs and delivers them bundled with post metadata, audio enclosure URLs (`audio_url`), and rendering specs (`instagram_card_*`, `tiktok_video_*`).
  - **`PressHub_Social_TikTok_Narrator`**: Gemini TTS voice-over for text-only TikTok posts (e.g. Daily Briefing articles with no podcast audio). On `/worker/claim`, a TikTok job with no `audio_url` gets narration: the TikTok copy with hashtags, emojis, URLs and any sentence mentioning `presshub.cy` removed, followed by the outro (`presshub_social_tiktok_narration_outro`, default *"Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy"*). Audio is synthesized via `PressHub_AI_Audio_Synthesizer::synthesize_turn()` with `presshub_social_tiktok_narration_voice` (empty = Daily Briefing Presenter 1 voice) and the briefing speed/pitch, sideloaded as `tiktok-narration-job-<id>-<hash>.wav`, and returned as `audio_url` so the worker renders a spoken video whose length matches the audio. The result is cached in job `metadata.tiktok_narration` keyed by a SHA-256 of voice + spoken text (retries reuse it; edited copy re-synthesizes). Podcast audio always takes precedence. Zero-Silent-Fallback: if narration is enabled (`presshub_social_tiktok_narration_enabled`, default on) and synthesis fails or the copy has nothing speakable, the job is marked failed with the TTS error and is not handed to the worker. Pull Mode only; TikTok Stories and clickable links are not available through TikTok Studio web, so the post is a normal video and `presshub.cy` stays plain text in the caption.
  - **Unified Release Pipeline**: Managed under the primary `presshub-ai-editor` update checker and GitHub release pipeline, completely eliminating version desynchronization and update notification loops.
  - **`PressHub_Social_AI_Copywriter`**: Generates platform-tailored copy (`generate_for_post()` and batch `generate_all_for_post()`) using `PressHub_AI_Provider_Store` and `PressHub_AI_API_Client` (tracking `provider_source`, `model_source`, token metrics, and duration in `wp_presshub_ai_token_logs`), or static templates when `copy_mode = 'template'`. Supports `$force_ai = true` flag used by the queue **"Regen AI"** button and metabox generator to guarantee AI execution regardless of default site `copy_mode`. Features intelligent podcast script and briefing transcript ingestion in `build_article_data()`: for podcast/briefing posts or whenever post content is short (< 300 chars, e.g. audio players), automatically extracts dialogue scripts from postmeta (`_presshub_podcast_script` or common fallbacks) or Harvester/Producer snapshot files (`podcast-script.txt`), sanitizing topic markers (`TOPIC: ...`, `TOPIC_START`, `TOPIC_END`) and speaker tags (`[SPEAKER_0]:`, `[Presenter 1]:`, `**[SPEAKER_1]:**`, `Host 1:`) via `clean_podcast_script()` to populate article content (up to 4,000 chars) and excerpt (first 50 words). Enforces Twitter/X character standard in `get_twitter_effective_length()` and `truncate_twitter_text()`, counting any URL as exactly 23 characters (`t.co`), thereby preventing 200+ character Greek percent-encoded permalinks from prematurely truncating Greek editorial copy. For Twitter/X, enforces the **Single Link Rule** and **Brand Sanitization**: strips domain extensions (e.g. `.cy`, `.com`) from `$site_name` so the brand is rendered strictly as plain text `"PressHub"`, post-processes generated tweets to replace standalone `PressHub.Cy` / `presshub.cy` outside the article URL with `"PressHub"`, and strips extraneous secondary URLs so that Twitter automatically renders the article's rich OpenGraph `summary_large_image` Twitter Card with its featured image. Enforces Zero-Silent-Fallback when prompts are missing, and resilient provider store fallback if a module provider override lacks an API key.
  - **`PressHub_Social_Activator`**: Manages installation lifecycle, SQLite/MySQL table creation (`wp_presshub_social_queue`), options defaults seeding, and cron registration (`presshub_social_interval`).
  - **`PressHub_Social_Queue`**: Resilient queue manager supporting database persistence and in-memory test fallback, query methods, status state machine (`pending` -> `processing` -> `published` / `failed` / `cancelled`), exponential backoff retry scheduling, failure email alerting, `repost_as_new_job()` for creating new queue items with custom copy while leaving original records intact, `update_job_copy()` for in-place copy modification, and enhanced `retry_job()` supporting published jobs with clean state resets. In Pull Mode, `get_due_pending_jobs( $limit, $engine, $platforms )` performs direct SQL engine filtering (`engine = 'automation'`) and active platform session scoping, eliminating head-of-line starvation from pending API jobs.
  - **`PressHub_Social_Meta_API_Client`**: Direct official API integration for Facebook Pages (feed & photos via Graph API v21.0), Instagram Business (2-step media container creation & publishing), Twitter/X API v2 (`POST /2/tweets`), and TikTok Content Posting API with Zero-Silent-Fallback validation.
  - **`PressHub_Social_Worker_Client`**: HTTP client communicating with the `presshub-social-worker` service (Playwright / Puppeteer automation), supporting dual Pull/Push connection modes, health checks (`check_health` observing worker heartbeat in Pull mode), session verification (`verify_session` inspecting local worker platform sessions), social card generation, video generation, and headless browser dispatch.
  - **`PressHub_Social_Dispatcher`**: Orchestrates `transition_post_status` hook gating (priority 20 after editorial QA workflow, persisting metabox overrides from `$_POST` prior to enqueue), inter-platform dispatch intervals, AI/template copy resolution with automatic retry copy regeneration, dynamic media attachment resolution, Pull Mode job reservation (skipping automation jobs during server cron so the local worker can claim them), and queue batch processing.
  - **`PressHub_Social_Metabox`**: Post edit screen UI providing per-platform toggles, engine indicators (`Official API` vs `Browser Automation`), individual and batch AI copy generation buttons, an on-demand **"Queue Social Posts Now"** button (`#presshub-enqueue-social-now`), a **"Queue on Update"** (`presshub_social_requeue_on_save`) checkbox allowing editors to explicitly re-enqueue posts on save, automatic enqueueing for published posts that currently have zero queue items (e.g. after database wipes or imports), live character counting, and dispatch history.
  - **`PressHub_Social_Queue_Admin`**: Dedicated monitoring dashboard (`PressHub Social -> Social Queue`) with KPI stat cards (Total, Pending, Processing, Published, Failed), platform/status filtering, on-demand queue processing, inline **"Regen AI"** button in the Actions column to regenerate copy directly via AI prompts, always-available **"Retry"** button (for published, failed, and cancelled jobs), and interactive **"Edit / Re-post"** modal dialog allowing operators to modify post copy and immediately post it again as a new queue job without deleting the original record.
  - **`PressHub_Social_Ajax_Handlers`**: AJAX endpoints (`presshub_social_generate_copy`, `presshub_social_enqueue_post`, `presshub_social_test_connection`, `presshub_social_retry_job`, `presshub_social_cancel_job`, `presshub_social_dispatch_now`, `presshub_social_regenerate_job_copy`, `presshub_social_update_job_copy`, `presshub_social_repost_job`) secured by nonces (`presshub_social_nonce`) and capability gates (`edit_posts`, `manage_options`). Correctly treats `pull_mode_queued` status as successful queue scheduling for local workers.

### 4.17 PressHub Social Media Worker (`presshub-social-worker`)
- **Overview**: A self-contained Node.js + TypeScript service listening on port `3456` (`PRESSHUB_WORKER_PORT`) that provides branded visual/video media synthesis and Playwright browser automation for platforms where official APIs are paid or gated.
- **Components & Responsibilities**:
  - **Authentication Middleware (`src/auth.ts`)**: Validates shared secret tokens (`Authorization: Bearer <secret>` or `X-PressHub-Secret`) using constant-time SHA-256 comparison (`crypto.timingSafeEqual`).
  - **Branded Card Generator (`src/media/card-generator.ts`)**: Uses `sharp` to composite XML-escaped SVG typography, category badges, and dark gradients over featured images into high-resolution PNG cards (`1080x1080` / `1080x1350`) for Instagram and social cards.
  - **Vertical Video Generator (`src/media/video-generator.ts`)**: Uses bundled `@ffmpeg-installer/ffmpeg` and `fluent-ffmpeg` to synthesize vertical `1080x1920` H.264/AAC MP4 videos (`yuv420p`). Features `probeAudioDuration()` to probe attached podcast audio files (MP3/WAV) and match the full audio track duration (matching full audio track duration up to 3600s / 60 minutes supported by TikTok Studio web without truncation) or fallback to silent/ambient stereo audio tracks (`anullsrc`) for still-image video synthesis.
  - **Structured Production Logging (`src/logger.ts` - `WorkerLogger`)**: Dedicated dual-target logging module writing synchronized ISO-timestamped, severity-graded (`[DEBUG]`, `[INFO]`, `[WARN]`, `[ERROR]`), and component-tagged log lines to console and `logs/worker.log`. Captures all WordPress claim payloads, Sharp rendering stages, FFmpeg commands, Playwright browser interactions, failures with URL/DOM state/screenshots, and completion confirmations.
  - **Browser & Stealth Helper (`src/automation/browser-helper.ts`)**: Provides real Google Chrome CDP connectivity (`http://127.0.0.1:9222`), stealth launcher (`channel: 'chrome'`, falling back to bundled Chromium in CI), `--disable-blink-features=AutomationControlled` anti-detection flags, `navigator.webdriver` removal via init scripts, and external CDP management (`isExternalCdp: true` leaves user browser open after posting).
  - **Session Manager (`src/automation/session-manager.ts`)**: Manages persistent Playwright `storageState` JSON files (`sessions/<platform>-session.json`), verifying cookie presence and expiration timestamps, plus programmatic session builders (`buildTwitterStorageState`, `buildTikTokStorageState`) and Cookie-Editor JSON / HTTP header parsers (`parseCookieExport`, `parseCookieHeader`). Provides `markSessionInvalid()` and `clearSessionInvalid()` for dynamic invalidation of revoked sessions upon encountering login redirects, preventing worker polling loops on unauthenticated accounts.
  - **Playwright Posters (`src/automation/posters.ts`)**: Automates Chromium and real Chrome publishing to Twitter/X (`x.com/compose/post`), TikTok Creator Center (`tiktok.com/creator-center/upload` / `tiktokstudio/upload`), Facebook, and Instagram with strict-mode compliant locators (scoping to active modal dialogs vs background timeline compose boxes via `.first()`), resilient post button selection, detached-dialog/toast confirmation race, and live toast status URL extraction (`/status/<id>`).
    - **Twitter/X 4-Tier Resilient Progressive Architecture & Zero-Silent-Fallback**:
      - **Pre-flight Sanitization & Smart Weighted Trimming**: Single-link rule enforcement (`sanitizeTwitterCopy()`) preserving canonical article permalinks and trailing hashtags while trimming body copy on word boundaries with ellipsis (`...`). Computes Twitter weighted lengths (`getTwitterWeightedLength()`, `TWITTER_URL_WEIGHT = 23`, `TWITTER_MAX_WEIGHTED_CHARS = 275`) using Unicode code points (`Array.from()`) for Greek characters and emojis, preventing character overflow from disabling the Post button.
      - **Network Response Interception**: Attaches `page.on('request')` and `page.on('response')` listeners for `/i/api/graphql/.../(CreateTweet|CreateNoteTweet)` endpoints to count outbound attempts (`createTweetRequestCount`), capture authorization headers and query IDs, and capture `rest_id` directly from JSON responses without relying on ephemeral toast UI notifications.
      - **Tier 1 (Keyboard-First Submission)**: Fast-path keyboard shortcut submission (`ControlOrMeta+Enter`) directly inside the focused composer textarea, immediately triggering native form submission and bypassing button DOM lookups and pointer interception.
      - **Tier 2 (Exact ARIA Button Click - Gated)**: Resolves the exact Post button via ARIA role (`getByRole('button', { name: /^Post$/i, exact: true })`), strictly excluding dropdown triggers (`[data-slot="xds-menu-trigger"]`), navigation links (`'New Post'`), and sidebar buttons (`[data-testid="SideNav_NewTweet_Button"]`). Strictly gated by `!modalDetachedEarly && !interceptedTweetId && createTweetRequestCount === 0`.
      - **Tier 3 (Synthetic DOM MouseEvent Cycle - Gated)**: Dispatches complete native pointer/mouse event cycles (`pointerdown`, `mousedown`, `pointerup`, `mouseup`, `click`), strictly gated by `!clicked && !interceptedTweetId && createTweetRequestCount === 0`.
      - **Tier 4 (Direct In-Page CreateTweet GraphQL Fallback - Strictly Gated)**: Executes direct in-page authenticated `fetch('/i/api/graphql/.../CreateTweet')` via `page.evaluate()` utilizing active session `ct0` CSRF cookies, strictly gated to run ONLY when `createTweetRequestCount === 0` after Tiers 1-3 AND profile timeline inspection confirms absence of the tweet.
      - **Escape Key Elimination & Modal Lifecycle Evaluation**: Completely eliminates `Escape` key presses during Twitter posting. On Twitter/X's `/compose/post` modal (`div[data-slot="xds-modal-surface"]`), pressing `Escape` triggers X's "Save post? / Discard" modal dialog which unfocuses the composer and breaks automated submission. Evaluates `isModal` only after `anyTextarea.waitFor({ state: 'visible' })` mounts, strictly scoping `textarea` to `modalSurface` elements to prevent matching background timeline textareas behind `div[data-slot="xds-modal-backdrop"]`, and employs focused force-clicking (`textarea.focus()`, `textarea.click({ force: true })`).
      - **Cookie Consent & Session Persistence**: Saves updated Playwright `storageState` to `sessions/twitter-session.json` upon cookie banner dismissal and post verification to persist consent choices and rotated `ct0` session tokens.
      - **Responsive Portal & Native Textarea Selectors**: Targets Twitter's modern UI architecture using `div[data-slot="xds-modal-surface"], div[role="dialog"]` portal surfaces with native HTML `<textarea placeholder="What's happening?">` and standard submit buttons (`button:has-text("Post")`). Anchors modal surface resolution specifically to the dialog containing the composer via `.filter({ has: page.locator('textarea, [contenteditable="true"], [data-testid="tweetTextarea_0"]') })` to isolate from preceding dialogs, and logs warnings if initial focus or force-click fails.
      - **Cookie Overlay Neutralization**: Neutralizes full-width fixed cookie banners (`Did someone say … cookies?`, `div[style*="bottom: 0px"]`) via targeted DOM style override (`display: none`, `pointerEvents: none`) to prevent pointer event interception on `tweetButton` without triggering page navigation or compose dialog dismissal.
      - **Single-Click Anti-Duplicate Submission Gating**: Strictly gates fallback native DOM mouse/click event dispatch behind `if (!clicked)`. Eliminates duplicate tweet submissions by preventing secondary DOM clicks when Playwright's primary click on `tweetButton` succeeds.
      - **Login Redirect & Onboarding Detection**: Detects modern X login redirects (`/i/jf/onboarding/web`, `mode=login`, login modal prompts). Calls `markSessionInvalid('twitter')`, reports `twitter: false` to WordPress, and throws an actionable message prompting re-authentication via `npm run login -- twitter`.
      - **Zero Fake Fallbacks**: Completely eliminated fake status fallbacks (`tw-${Date.now()}`). If toast confirmation is missed, performs an automated profile timeline lookup (`https://x.com/${username}`) matching article text or permalink to retrieve the genuine status ID.
      - **Timeline Match Freshness Gate**: Decodes each candidate's creation time from its snowflake status ID (`getTwitterSnowflakeTimestampMs()`) and rejects tweets created before `submitStartedAt - TWITTER_TIMELINE_MAX_CLOCK_SKEW_MS` (60s), logging a `WARN`. Empty text snippets never match. Earlier attempts, pinned tweets, and same-prefix posts can no longer be reported as this job's tweet, so an unconfirmed submission falls through to Tier 4 or fails loudly.
      - **Mandatory Post-Confirmation Verification**: Direct navigation to the published `tweetUrl`, verifying `article[data-testid="tweet"]` or standard `<article>` containing a link to `/status/${tweetId}` is visible and confirming absence of error messages ("This Post doesn't exist", "unavailable", "Something went wrong") before marking as successful.
    - **TikTok Studio Upload Automation**: Strictly isolates the submit Post button (`button[data-e2e="post_video_button"]` / footer action group next to Discard) from navigation sidebar elements (specifically preventing matching `<button>Posts</button>` in the sidebar which previously triggered page discard modals), handles rich text input natively through Draft.js editor compatibility (`page.keyboard.insertText`) rather than destructive `.fill()` to protect the React Virtual DOM from desynchronization crashes (`Something went wrong Please try again. Retry`), dismisses Joyride onboarding tour dialogs non-destructively without unmounting React portal nodes, recovers from transient retry dialogs, tracks live video chunk transfer progress and completion through `[data-e2e="upload_status_container"]` (verifying `Uploaded`/`Replace` state and ensuring no active transfer percentages, time remaining, or cancel triggers linger), dismisses sticky cookie consent banners (`Decline optional cookies`/`Allow all`), presses Escape to close automatic hashtag dropdown popups, dispatches complete DOM pointer/mouse event cycles (`pointerdown`, `mousedown`, `pointerup`, `mouseup`, `click`) to the action Post button, resolves secondary confirmation dialogs ("Post now", "Post anyway", "Confirm", "Turn on" during content checks), and verifies post publication via URL navigation to `tiktokstudio/content` or success notices within 60s, enforcing Zero-Silent-Fallback error propagation and screenshot captures. Supports CDP awareness and tab-only closure when attached to existing browser sessions.
  - **Interactive Multi-Mode Login CLI (`src/cli/login.ts`)**: 3-mode session capture utility (`npm run login -- <platform>`):
    1. **Mode 1 (CDP Auto-Extract)**: Connects to running Chrome on port 9222, verifies essential platform cookies, and extracts session instantly.
    2. **Mode 2 (Direct Cookie Paste)**: Prompts for `auth_token` / `ct0` (Twitter) or `sessionid` (TikTok), or parses Cookie-Editor JSON exports into valid Playwright storage states with 1-year expiration.
    3. **Mode 3 (Real Chrome Persistent Profile)**: Launches genuine Google Chrome with persistent profile (`sessions/chrome-profile`) and `--enable-automation` stripped.
  - **Real Chrome Launcher (`src/cli/launch-chrome.ts`)**: Convenience CLI (`npm run chrome`) detecting installed Google Chrome executable on Windows/macOS/Linux and launching with `--remote-debugging-port=9222` and persistent profile.

### 4.18 Turn-by-Turn Podcast Audio Synthesis & Voice Identity Isolation
- **Problem & Root Cause Analysis**:
  - Multi-host podcast generation previously attempted single-call topic batching using `multiSpeakerVoiceConfig` with `speech_metadata.speaker`. Because Gemini REST does not steer per-part speaker routing via `speech_metadata.speaker`, the neural TTS model drifted between voices across turns, resulting in inconsistent audio delivery.
- **Turn-by-Turn Synthesis Architecture**:
  - Unified across 1, 2, and 3 hosts: `PressHub_AI_Audio_Synthesizer::synthesize_podcast()` iterates through structured dialogue turns (`$topic['turns']`) and synthesizes each turn individually via `synthesize_turn()` using a dedicated single-speaker `voiceConfig` with `prebuiltVoiceConfig.voiceName`.
  - Zero `multiSpeakerVoiceConfig` is used in podcast audio streams, guaranteeing absolute voice identity isolation and eliminating neural baseline drift.
  - Turn audio chunks are stitched together using `stitch_wav_chunks()` (Gemini 24kHz PCM) or `stitch_audio_chunks()` (Google Cloud MP3) with natural configurable pauses.
- **Resumable Turn Disk Cache**:
  - Each synthesized turn is deterministically hashed (`hash('sha256', implode('|', [$engine, $model, $voice, $style, $speed, $pitch, $payload_mode, $text]))`) and persisted in `wp-content/uploads/presshub-ai/tts-cache/{date}/turn_{hash}.{wav|mp3}`.
  - Interrupted or failed syntheses resume seamlessly from the first un-synthesized turn without re-calling the API for cached turns, saving costs and avoiding quota waste.
- **Settings-First Audio Controls**:
  - `presshub_ai_briefing_tts_turn_pause_ms`: Configurable pause between dialogue turns (default: 250ms).
  - `presshub_ai_briefing_tts_topic_pause_ms`: Configurable pause between topic sections (default: 600ms).
  - `presshub_ai_briefing_tts_request_delay_ms`: Inter-request throttling delay (default: 250ms) to prevent hitting provider rate limits.
  - `presshub_ai_briefing_tts_max_retries`: Maximum retry attempts on HTTP 429/503 (default: 2).
  - `presshub_ai_briefing_tts_retry_backoff_ms`: Base exponential backoff delay (default: 2000ms).
- **Zero-Silent-Fallback & Strict Logging**:
  - `synthesize_speech_via_gemini()` and `build_gemini_tts_request_body()` default `$voice_name = ''`, strictly requiring callers to provide a voice persona or failing loudly with `WP_Error('missing_tts_voice')` and logging `ERROR`.
  - Missing TTS models fail loudly with `WP_Error('missing_tts_model')` with zero silent fallbacks.
  - Unknown speaker labels and rogue presenter tags in solo-host briefings log structured `ERROR` events to `PressHub_AI_Logger`.

### 4.19 Generation Records (`PressHub_AI_Generation_Recorder`)
- **Purpose**: editorial transparency. Every briefing-pipeline LLM generation stores a durable record of exactly what went into it, so editors (and the Generation Inspector, #133) can review it later. Recording is always on and independent of the prompt/TTS debug-log toggles.
- **Storage**: table `wp_presshub_ai_generation_records` (created via `dbDelta`, versioned by `presshub_ai_generation_records_db_version`, SQLite-safe: JSON kept as TEXT, no FULLTEXT or JSON SQL functions). Columns: `trace_id`, `kind` (`briefing_text`, `podcast_script`, `memory_digest`, `memory_rollup`), `briefing_date`, `edition`, `post_id`, `status`, `error_message`, `config_json`, `inputs_json`, `memory_json`, `tts_json`, `system_prompt`, `user_prompt`, `response_text`, `usage_json`, `duration_ms`, `created_at`.
- **Capture**: at the call site, around `call_provider()`, in `PressHub_AI_News_Curator::generate_briefing()` and `PressHub_AI_Podcast_Producer::generate_dialogue_script()`. Prompts and response are stored verbatim (never rebuilt afterwards). Failed generations are recorded too (`status = error`, with the prompts that were sent).
  - `config_json` comes from the client instance via `build_client_config()`: module, action, provider type/ID/source, model and model source, temperature, max tokens, timeout, plus preset, edition, context mode and where the articles were placed (system or user prompt).
  - `inputs_json`: per-article `sent` list (ID, title, source, URL, characters sent, truncated by `max_chars_per_article`) and `dropped` list with the reason (`cap_articles`, `not_selected`), plus the pool/cap totals and token estimate. The curator exposes this via `last_articles_breakdown()`; the rendered prompt is unchanged.
  - `usage_json`: the token counts from the matching `log_llm_request()` call (`PressHub_AI_Token_Logger::get_last_llm_request()`).
  - `memory_json`: `{"enabled": false}` until editorial memory lands; `set_memory_context()` lets the memory injection step attach what it used.
  - `tts_json` (podcast): added by `PressHub_AI_Audio_Synthesizer::synthesize_podcast()` when the audio is synthesized: engine, model, payload mode, voices per presenter, style, speed, pitch, pauses, topic split, turn count, and whether the stored or an edited script was used.
- **Linking**: record IDs are appended to the post meta `_presshub_generation_record_ids` (ordered by pipeline step; regenerations add records and keep the old ones). The podcast-script record ID travels through the script sidecar (`podcast-script[-noon].meta.json` → `generation_record_id`) until the podcast post exists.
- **Retention**: `presshub_ai_generation_record_retention_days` (default 90, 7–365), pruned daily by `presshub_ai_prune_generation_records`.
- **Zero-Silent-Fallback**: an insert failure logs an `ERROR` with the `trace_id` and never fails the generation.

---


### 4.20 Editorial Memory Stream (Store, Settings & Retention - Issue #132)
- **Overview**: Editorial memory maintains multi-day storyline continuity across daily briefings and weekly digests.
- **Database Tables** (dbDelta-managed, SQLite & MySQL compatible):
  - `wp_presshub_ai_memory_entries`: Stores structured memory entries with `scope` (`daily` | `weekly`), `period_start`, `period_end`, `edition`, `generated_json`, `content_json`, `origin` (`generated` | `edited`), `status` (`active` | `excluded` | `stale` | `failed` | `pending`), `version_hash` (sha256), `source_post_id`, `generation_record_id`, and editor audit fields.
  - `wp_presshub_ai_memory_entry_links`: Many-to-many relationship tracking weekly rollup entries to the daily digest entry IDs they were constructed from.
- **Strict Content Schema Validation**:
  - `stories`: Array of objects containing stable slug `key`, non-empty `title`, summary `development`, `status` (`new` | `developing` | `resolved`), `entities` list, `source_urls` list, and `include` boolean.
  - `podcast`: Object with `opening_line` and `closing_line`.
  - `narrative`: Compact background narrative string (weekly rollups).
- **Edit-Protection Invariant**:
  - When an editor modifies `content_json`, `origin` is permanently set to `edited` and a new `version_hash` is computed.
  - Subsequent AI regeneration calls via `upsert_generated()` update `generated_json` and `updated_at`, but strictly preserve the editor-edited `content_json` (`origin='edited'`), returning `preserved_edit => true`.
  - The editor can deliberately revert to AI output using `reset_to_generated()`.
- **Stale Propagation**:
  - When a daily digest entry is edited or its status changes (`excluded` / `active`), any weekly rollups linked in `wp_presshub_ai_memory_entry_links` automatically transition to `stale` status, and an audit entry is logged.
- **Clean Slate Post Deletion Lifecycle**:
  - Deleting a daily briefing post (`before_delete_post`) deletes associated daily digest records and associated links in `wp_presshub_ai_memory_entry_links`, marking any dependent weekly rollups stale.
- **Settings-First Memory Controls**:
  - `presshub_ai_memory_enabled`: Master switch (default: true).
  - `presshub_ai_memory_daily_days`: Active daily digest lookback window (default: 7, bounds: 0–14).
  - `presshub_ai_memory_background_days`: Background weekly rollup lookback window (default: 30, bounds: 0–90, clamped >= daily days).
  - `presshub_ai_memory_briefing_budget_chars`: Prompt injection character budget for daily briefing (default: 12,000, bounds: 0–60,000).
  - `presshub_ai_memory_podcast_budget_chars`: Prompt injection character budget for podcast (default: 6,000, bounds: 0–60,000).
  - `presshub_ai_memory_retention_days`: Database retention threshold for old memory entries (default: 180, bounds: 30–730).
- **Daily Prune Cron Job**:
  - Scheduled event `presshub_ai_prune_memory_entries` runs daily via WP-Cron and purges entries older than `presshub_ai_memory_retention_days` and their links, recording audit logs.

## 5. Cron Schedules & Timers

| Event Hook | Default Time | Configurable Option | Subsystem Callback |
|------------|--------------|---------------------|--------------------|
| `presshub_daily_news_harvest` | 06:30 | `presshub_ai_briefing_harvest_time` | `presshub_ai_execute_harvest_cron()` |
| `presshub_daily_news_generate` | 07:15 | `presshub_ai_briefing_generation_time` | `presshub_ai_execute_generation_cron()` |
| `presshub_noon_news_harvest` | 12:00 | `presshub_ai_briefing_noon_harvest_time` | `presshub_ai_execute_noon_harvest_cron()` |
| `presshub_noon_news_generate` | 12:45 | `presshub_ai_briefing_noon_generation_time` | `presshub_ai_execute_noon_generation_cron()` |
| `presshub_ai_prune_audit_logs` | Daily | `presshub_ai_audit_log_retention_days` (default 90) | `PressHub_AI_Audit_Logger::prune_logs()` |
| `presshub_ai_prune_memory_entries` | Daily | `presshub_ai_memory_retention_days` | `PressHub_AI_Memory_Store::prune_old_entries()` |
| `presshub_ai_prune_generation_records` | Daily | `presshub_ai_generation_record_retention_days` (retention window) | `PressHub_AI_Generation_Recorder::prune()` |
| `presshub_ai_prune_log_files` | Daily | `presshub_ai_log_file_retention_days` (default 14) | Deletes rotated log files older than the window |

### 5.1 Cron Execution & Error Resilience
- Both generation cron callbacks (`presshub_ai_execute_generation_cron` and `presshub_ai_execute_noon_generation_cron`) encapsulate all pipeline stages (curation, script generation, and audio synthesis) in dedicated error-handling and `try / catch (\Throwable $t)` blocks.
- Uncaught exceptions during synthesis are caught, logged to `PressHub_AI_Logger::error()` with full stack traces, and wrapped into `WP_Error`, ensuring that cron tasks never crash the PHP runtime or leave tasks half-executed without diagnostic trace.

