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
   - **Layer 1**: Structured log (`wp-content/uploads/presshub-logs/`)
   - **Layer 2**: Token log database (`wp_presshub_ai_token_logs`)
   - **Layer 3**: Audit log (`wp_presshub_ai_audit_log`)
   - **Layer 4**: WordPress `debug.log`

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
- **Voice Catalog**:
  - Uses canonical voice catalog (`assets/data/tts-voice-catalog.json`).
  - Supports 30+ voice personas with speed and pitch controls.
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

### 4.5 Briefing Hub Admin & Client Controller
- **Classes**: `PressHub_AI_Briefing_Admin`, `PressHub_AI_Ajax_Handlers`, `assets/briefing-admin.js`.
- **Edition Switcher**:
  - Admin Hub displays responsive navigation tabs: **Morning Edition (Πρωινή Ενημέρωση)** vs. **Noon Update (Μεσημβρινή Ενημέρωση)**.
  - Container element carries `data-edition="morning|noon"`.
  - Client-side script maintains `currentEdition` and attaches `edition: currentEdition` to all AJAX pipeline dispatches (`briefing_get_status`, `briefing_run_harvest`, `briefing_run_curation`, `briefing_run_script`, `briefing_save_script`, `briefing_generate_audio`, `briefing_upload`).
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
- **OpenGraph & Twitter Card Tags**:
  - Hooks `wp_head` at priority 5 to output complete social sharing metadata required by Facebook, X (Twitter), LinkedIn, Slack, and Discord (tested for opengraph.xyz compliance):
    - `og:site_name`, `og:type` (`article`), `og:title`, `og:url`, `og:description`, `og:image`, `og:image:secure_url`.
    - `description` standard HTML meta tag for Google search snippets.
    - `twitter:card` (`summary_large_image`), `twitter:title`, `twitter:description`, `twitter:image`.
- **Document Title Length Optimizer**:
  - Hooks `document_title_parts` at priority 20.
  - Ensures the combined `<title>` (post title + site name) remains **<= 60 characters** to prevent search engine snippet truncation by Google.
  - Formats streamlined mastheads for briefing posts (e.g. `"Πρωινό Podcast (16/10/2026) - PressHub"`).

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

---

## 5. Cron Schedules & Timers

| Event Hook | Default Time | Configurable Option | Subsystem Callback |
|------------|--------------|---------------------|--------------------|
| `presshub_daily_news_harvest` | 06:30 | `presshub_ai_briefing_harvest_time` | `presshub_ai_execute_harvest_cron()` |
| `presshub_daily_news_generate` | 07:15 | `presshub_ai_briefing_generation_time` | `presshub_ai_execute_generation_cron()` |
| `presshub_noon_news_harvest` | 12:00 | `presshub_ai_briefing_noon_harvest_time` | `presshub_ai_execute_noon_harvest_cron()` |
| `presshub_noon_news_generate` | 12:45 | `presshub_ai_briefing_noon_generation_time` | `presshub_ai_execute_noon_generation_cron()` |
| `presshub_ai_prune_audit_logs` | Daily | Fixed 24h | `PressHub_AI_Audit_Logger::prune()` |

