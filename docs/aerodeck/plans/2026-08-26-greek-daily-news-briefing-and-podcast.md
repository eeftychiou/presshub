# Greek Daily News Briefing & Multi-Voice AI Podcast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use aerodeck:subagent-driven-task-pipeline (recommended) or aerodeck:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automate morning Greek news harvesting, AI text story curation with PressHub Instruction Presets, dual-host conversational dialogue script generation with duration tuning, editorial review/editing hub, and multi-voice Google Cloud TTS podcast synthesis.

**Architecture/Workflow:** Multi-stage pipeline with dual cron scheduling (Harvest Time vs Generation Time), Cloudflare bot-protection detection with manual document/text upload fallback, integration with `PressHub_AI_Preset_Store`, Google Cloud TTS Neural2/Wavenet multi-speaker binary stitching, and a dedicated WP Admin Daily Briefing Hub.

**Tech Stack/Tools:** PHP 7.4+ / WordPress 6.0+, Google Cloud Text-to-Speech API, TypeScript / Node.js / Jest in `presshub-workflow`, WordPress REST/AJAX and Block Editor Audio integration.

---

### Structure Mapping

| Component | Responsibility | File Path |
|---|---|---|
| **Harvester Engine** | Scrapes Greek news homepages, extracts articles, detects Cloudflare blocks, handles manual uploads, saves daily JSON snapshot. | `presshub-ai-editor/includes/class-news-harvester.php` |
| **Text Curator Agent** | Hydrates Greek curation prompt with selected PressHub Preset & articles, synthesizes news story, creates WordPress post. | `presshub-ai-editor/includes/class-news-curator.php` |
| **Podcast Producer Agent** | Hydrates Greek dialogue prompt with selected PressHub Preset & target duration (3/5/10 min), generates 2-host script with speaker tags. | `presshub-ai-editor/includes/class-podcast-producer.php` |
| **Audio Synthesizer** | Splits dialogue by speaker, calls Google Cloud TTS with configured Greek voice models, stitches audio into MP3, creates podcast post. | `presshub-ai-editor/includes/class-audio-synthesizer.php` |
| **Settings & Cron** | Dual schedule times, source URLs, voice model dropdowns, preset dropdowns, prompt customization with reset. | `presshub-ai-editor/includes/class-settings.php`, `presshub-ai-editor.php` |
| **Daily Briefing Hub UI** | WP Admin dashboard for pipeline monitoring, Cloudflare upload modal, interactive script editor, and manual run triggers. | `presshub-ai-editor/includes/class-briefing-admin.php`, `assets/briefing-admin.js`, `assets/briefing-admin.css` |
| **Workflow Engine & Tests** | TypeScript script parsing, duration calculations, and unit tests. | `presshub-workflow/src/briefing.ts`, `presshub-workflow/tests/briefing.test.ts` |

---

### Task 1: Harvester Engine (`PressHub_AI_News_Harvester`) & Cloudflare Fallback

**Targets:**
- Create: `presshub-ai-editor/tests/NewsHarvesterTest.php`
- Create: `presshub-ai-editor/includes/class-news-harvester.php`
- Modify: `presshub-ai-editor/presshub-ai-editor.php`

- [ ] **Step 1: Write failing test in `NewsHarvesterTest.php`**
  Cover:
  - Homepage link extraction from Greek news markup.
  - Cloudflare/403 block detection and recording in `blocked_sources`.
  - Manual upload merging (PDF/text extracted content merged into article pool).
  - Deduplication and JSON snapshot persistence to uploads directory.

- [ ] **Step 2: Run test to verify RED state**
  Run: `php presshub-ai-editor/tests/NewsHarvesterTest.php` (or run via PHP wrapper)
  Expected: FAIL (Class `PressHub_AI_News_Harvester` does not exist).

- [ ] **Step 3: Implement minimal code in `class-news-harvester.php`**
  - Implement `fetch_homepage_links( $url )`
  - Implement `harvest_all( $sources, $date )`
  - Implement `handle_manual_upload( $files, $notes, $date )`
  - Implement `save_snapshot( $date, $data )` and `load_snapshot( $date )`

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and verify all assertions pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 1 changes to Git.

---

### Task 2: Text Curator Agent (`PressHub_AI_News_Curator`) & Preset Integration

**Targets:**
- Create: `presshub-ai-editor/tests/NewsCuratorTest.php`
- Create: `presshub-ai-editor/includes/class-news-curator.php`
- Modify: `presshub-ai-editor/presshub-ai-editor.php`

- [ ] **Step 1: Write failing test in `NewsCuratorTest.php`**
  Cover:
  - Loading preset from `PressHub_AI_Preset_Store` using `presshub_ai_briefing_text_preset`.
  - Hydrating Greek curation prompt with `{date}`, `{sources_list}`, `{articles_count}`, `{articles_context}`.
  - Calling AI client and converting Markdown to HTML via `PressHub_AI_Markdown`.
  - Post creation with title `Πρωινή Ενημέρωση: [Headline] - [Date]`, configured category, and meta `_presshub_briefing_date`.

- [ ] **Step 2: Run test to verify RED state**
  Expected: FAIL (Class `PressHub_AI_News_Curator` does not exist).

- [ ] **Step 3: Implement minimal code in `class-news-curator.php`**
  - Implement `build_prompt( $articles, $preset_id, $date )`
  - Implement `generate_story( $date )`
  - Implement `create_wordpress_post( $story_html, $headline, $date )`

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and confirm clean pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 2 changes to Git.

---

### Task 3: Podcast Producer Agent (`PressHub_AI_Podcast_Producer`) & Duration Budget

**Targets:**
- Create: `presshub-ai-editor/tests/PodcastProducerTest.php`
- Create: `presshub-ai-editor/includes/class-podcast-producer.php`
- Modify: `presshub-ai-editor/presshub-ai-editor.php`

- [ ] **Step 1: Write failing test in `PodcastProducerTest.php`**
  Cover:
  - Duration-based word budget constraints (3 min -> ~450 words, 5 min -> ~750 words, 10 min -> ~1500 words).
  - Loading preset from `PressHub_AI_Preset_Store` for the podcast producer.
  - Generating and validating dual-speaker dialogue tags (`[Μαρία]: ...`, `[Νίκος]: ...`).
  - Dialogue parsing into speaker-turn array `[ ['speaker' => 'female', 'text' => '...'], ... ]`.

- [ ] **Step 2: Run test to verify RED state**
  Expected: FAIL (Class `PressHub_AI_Podcast_Producer` does not exist).

- [ ] **Step 3: Implement minimal code in `class-podcast-producer.php`**
  - Implement `get_duration_specs( $duration_option )`
  - Implement `build_dialogue_prompt( $articles, $preset_id, $duration, $date )`
  - Implement `parse_script_turns( $raw_script )`
  - Implement `save_script( $date, $script_text )` and `get_script( $date )`

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and confirm clean pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 3 changes to Git.

---

### Task 4: Multi-Voice Audio Synthesizer (`PressHub_AI_Audio_Synthesizer`)

**Targets:**
- Create: `presshub-ai-editor/tests/AudioSynthesizerTest.php`
- Create: `presshub-ai-editor/includes/class-audio-synthesizer.php`
- Modify: `presshub-ai-editor/includes/class-api-client.php`
- Modify: `presshub-ai-editor/presshub-ai-editor.php`

- [ ] **Step 1: Write failing test in `AudioSynthesizerTest.php`**
  Cover:
  - Voice mapping per speaker: Female voice (`el-GR-Neural2-A`) and Male voice (`el-GR-Neural2-B`).
  - Rate and pitch configuration encoding in Google Cloud TTS request payload.
  - Multi-chunk binary MP3 concatenation with silent inter-speaker pause frames.
  - Sideloading into Media Library and creating a WordPress post under **Podcasts** with native audio block (`<!-- wp:audio -->`) and transcript.

- [ ] **Step 2: Run test to verify RED state**
  Expected: FAIL (Class `PressHub_AI_Audio_Synthesizer` does not exist).

- [ ] **Step 3: Implement minimal code in `class-audio-synthesizer.php` & `class-api-client.php`**
  - Implement `synthesize_speaker_turn( $text, $voice_model, $speed, $pitch )`
  - Implement `stitch_audio_chunks( array $mp3_buffers, $pause_ms = 400 )`
  - Implement `create_podcast_post( $audio_url, $audio_id, $transcript, $date )`

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and confirm clean pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 4 changes to Git.

---

### Task 5: Settings & Dual Scheduling Integration

**Targets:**
- Create: `presshub-ai-editor/tests/DailyBriefingSettingsTest.php`
- Modify: `presshub-ai-editor/includes/class-settings.php`
- Modify: `presshub-ai-editor/presshub-ai-editor.php`

- [ ] **Step 1: Write failing test in `DailyBriefingSettingsTest.php`**
  Cover:
  - Sanitization of dual cron times (`harvest_time` and `generation_time`).
  - Available Greek Google Cloud TTS voice models directory and validation.
  - Duration options (`3_min`, `5_min`, `10_min`) validation.
  - Default Greek prompts hydration and "Reset to Default" handler.

- [ ] **Step 2: Run test to verify RED state**
  Expected: FAIL (New settings options and validation handlers not implemented).

- [ ] **Step 3: Implement settings tab & cron handlers in `class-settings.php` and `presshub-ai-editor.php`**
  - Add **Daily Briefing** tab in settings page.
  - Register all options with robust sanitization callbacks.
  - Schedule dual WP-Cron events: `presshub_daily_news_harvest` and `presshub_daily_news_generate`.

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and confirm clean pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 5 changes to Git.

---

### Task 6: Daily News Briefing Hub (WP Admin Dashboard & AJAX Handlers)

**Targets:**
- Create: `presshub-ai-editor/tests/DailyBriefingAdminTest.php`
- Create: `presshub-ai-editor/includes/class-briefing-admin.php`
- Create: `presshub-ai-editor/assets/briefing-admin.js`
- Create: `presshub-ai-editor/assets/briefing-admin.css`
- Modify: `presshub-ai-editor/includes/class-ajax-handlers.php`

- [ ] **Step 1: Write failing test in `DailyBriefingAdminTest.php`**
  Cover:
  - Admin menu registration: **PressHub AI > Daily Briefing Hub**.
  - Pipeline status rendering (Harvested, Text Story, Script, Audio Podcast).
  - AJAX endpoint for Cloudflare blocked manual upload (`presshub_ai_briefing_upload`).
  - AJAX endpoint for saving edited podcast script (`presshub_ai_briefing_save_script`).
  - AJAX endpoint for on-demand triggers (`presshub_ai_briefing_run_harvest`, `presshub_ai_briefing_generate_audio`).

- [ ] **Step 2: Run test to verify RED state**
  Expected: FAIL (Class `PressHub_AI_Briefing_Admin` not found).

- [ ] **Step 3: Implement minimal code for Briefing Hub & AJAX handlers**
  - Implement `render_hub_page()` in `class-briefing-admin.php`.
  - Add JavaScript event handlers and UI state updates in `assets/briefing-admin.js`.
  - Add styling for pipeline cards, blocked alerts, and script editor in `assets/briefing-admin.css`.
  - Add secure AJAX handlers in `class-ajax-handlers.php`.

- [ ] **Step 4: Verify test passes (GREEN state)**
  Run test and confirm clean pass.

- [ ] **Step 5: Save/Checkpoint**
  Commit Task 6 changes to Git.

---

### Task 7: TypeScript Workflow Tests & End-to-End Verification

**Targets:**
- Create: `presshub-workflow/src/briefing.ts`
- Create: `presshub-workflow/tests/briefing.test.ts`

- [ ] **Step 1: Write TypeScript test in `briefing.test.ts`**
  Cover:
  - Dialogue speaker parsing and verification.
  - Duration-to-word budget calculation.
  - Briefing workflow data transformations.

- [ ] **Step 2: Run `npm test` in `presshub-workflow` to verify RED state**
  Expected: FAIL (`briefing.ts` not implemented).

- [ ] **Step 3: Implement `briefing.ts`**
  - Implement `parseDialogueScript(rawText)`
  - Implement `calculateDurationBudget(durationMinutes)`
  - Implement `formatBriefingPayload(articles, options)`

- [ ] **Step 4: Run `npm test` in `presshub-workflow` to verify GREEN state**
  Run `npm test` and verify 100% test pass rate across all suites.

- [ ] **Step 5: Run complete test suite across PHP and TypeScript**
  Confirm all tests pass without errors or warnings.

- [ ] **Step 6: Final Commit & Push**
  Commit all files and push to `https://github.com/eeftychiou/presshub.git`.
