# Greek Daily News Briefing & Multi-Voice AI Podcast Specification

**Date**: 2026-08-26  
**Status**: Approved Design (Updated with User Review Feedback)  
**Target Platform**: WordPress (via `presshub-ai-editor` plugin & `presshub-workflow`)  

---

## 1. Executive Summary & Goals

This feature automates the creation of a daily morning digital newspaper briefing and dual-host audio podcast in Greek, with full editorial controls, preset integration, and error recovery.

### Daily Lifecycle & Schedule
- **Configurable Harvesting Time** (e.g., 06:30 AM): Scrapes configured Greek news website homepages, extracting main headlines and full article bodies, and archives the raw snapshot to disk.
- **Bot/Cloudflare Protection Detection & Manual Upload Fallback**: If a source site is blocked by Cloudflare/anti-bot protection, the system flags the blocked source in the Daily Briefing Hub and alerts editors with a quick-upload panel to attach PDFs, articles, or text notes for that outlet.
- **Configurable Generation Time** (e.g., 07:15 AM or on-demand):
  1. **Curator (Text Agent)**: Synthesizes the day's top news stories into an analytical, categorized Greek news story using a configurable **PressHub AI Instruction Preset**, published in the designated category (e.g. *In Context* or *Stories*).
  2. **Podcast Producer (Script Agent)**: Generates a natural, conversational 2-host Greek dialogue script adhering to a target **Podcast Duration** (e.g. 3, 5, or 10 minutes) and a selected **PressHub AI Instruction Preset**.
  3. **Editorial Hub Review**: Admins and editors monitor each stage in real-time, with full capabilities to edit the dialogue script and adjust speaker lines before audio synthesis.
  4. **Audio Synthesizer (TTS Agent)**: Alternates between natural male and female Greek voices using configurable Google Cloud Text-to-Speech voice models (with pitch, speed, and voice model selectors), stitches the audio segments, and publishes the podcast episode under the *Podcasts* category with native audio player and transcript.
  5. **Prompt & Preset Customization**: Administrators can configure system prompts, select PressHub Instruction Presets, and fine-tune voice and schedule parameters.

---

## 2. Architecture & Pipeline Stages

```
             [Configurable Harvest Time, e.g. 06:30 AM]
                                │
                                ▼
       ┌─────────────────────────────────────────────────────────┐
       │   Stage 1: Greek News Scraper & Fallback Handler        │
       │   - Crawls configured Greek website homepages           │
       │   - Detects Cloudflare/403 blocks $\to$ Alerts Editor    │
       │   - Editor can manually upload PDFs/notes for blocked   │
       │   - Saves snapshot JSON to uploads directory            │
       └────────────────────────┬────────────────────────────────┘
                                │
             [Configurable Generation Time, e.g. 07:15 AM / On-Demand]
                                │
                                ▼
       ┌─────────────────────────────────────────────────────────┐
       │   Stage 2: Daily News Story Curation (Text Agent)       │
       │   - Applies selected Text Instruction Preset            │
       │   - Synthesizes top stories across categories in Greek  │
       │   - Creates WordPress Post (Pending / Published)        │
       └────────────────────────┬────────────────────────────────┘
                                │
                                ▼
       ┌─────────────────────────────────────────────────────────┐
       │   Stage 3: Podcast Script Generation (Script Agent)     │
       │   - Applies selected Podcast Instruction Preset         │
       │   - Generates 2-host dialogue formatted to target       │
       │     duration (e.g. 3, 5, 10 min word budget)            │
       │   - Displays in Admin Hub for review & inline editing   │
       └────────────────────────┬────────────────────────────────┘
                                │
             [Editor Reviews / Edits Script in Admin Hub]
                                │
                                ▼ (Editor clicks "Synthesize Audio")
       ┌─────────────────────────────────────────────────────────┐
       │   Stage 4: Multi-Voice Audio Podcast Synthesis          │
       │   - Chunks dialogue turns by speaker tag ([Host A/B])   │
       │   - Calls Google Cloud TTS with configured Greek voice   │
       │     models (Neural2/Wavenet/Polyglot + pitch/speed)     │
       │   - Stitches chunks into seamless MP3 file              │
       │   - Imports to Media Library & creates post in          │
       │     "Podcasts" category with audio player               │
       └─────────────────────────────────────────────────────────┘
```

---

## 3. Detailed Component Specifications

### 3.1 Harvester & Fallback Engine (`PressHub_AI_News_Harvester`)
- **Homepage Crawling**: Crawls configured Greek URLs (e.g. `kathimerini.gr`, `in.gr`, `sigmalive.com`, `tovima.gr`).
- **Cloudflare & Bot-Block Detection**:
  - Detects HTTP 403, 503, Cloudflare challenge headers, and captcha blocks.
  - Flags blocked domains in the daily briefing run state (`blocked_sources`).
  - Emits an admin notice on the Daily Briefing Hub: *"Kathimerini is protected by Cloudflare. Click here to upload today's PDF or paste article text."*
- **Manual Upload Integration**:
  - Allows editors to upload source files (`.pdf`, `.docx`, `.txt`) or paste raw article text directly into the briefing run.
  - Automatically parses and extracts text using existing upload handlers, merging them with successfully scraped articles.
- **Deduplication & Storage**: Deduplicates overlapping articles across sites and saves the daily payload to:
  `wp-content/uploads/presshub-briefings/YYYY-MM-DD/raw-articles.json`

### 3.2 Text Curator Agent (`PressHub_AI_News_Curator`)
- **Instruction Preset Integration**:
  - Can inherit the organization default preset or use a designated **Daily Briefing Text Preset** from `PressHub_AI_Preset_Store` (e.g. *In-Depth Analytical*, *Wire Service Concise*, *Narrative Morning Brief*).
- **Prompt Hydration**: Merges the preset system prompt with runtime placeholders `{date}`, `{sources_list}`, `{articles_count}`, and `{articles_context}`.
- **AI Synthesis**: Dispatches prompt to the active AI provider (Gemini / OpenAI / Anthropic).
- **Post Publishing**:
  - Sets post title: `Πρωινή Ενημέρωση: [Top Headline Summary] - [Formatted Date]`
  - Converts Markdown output to semantic HTML via `PressHub_AI_Markdown`.
  - Assigns post to configured category (default: *In Context* or *Stories*).
  - Sets status to configured default (*Pending Review* or *Publish*).

### 3.3 Podcast Producer Agent (`PressHub_AI_Podcast_Producer`)
- **Instruction Preset Integration**:
  - Uses a designated **Daily Briefing Podcast Preset** from `PressHub_AI_Preset_Store` (e.g. *Morning Coffee Debate*, *Executive Briefing*, *Investigative Breakdown*).
- **Target Duration & Word Budget Tuning**:
  - Supports configurable duration targets:
    - **3 Minutes** (~450 words / ~6-8 dialogue exchanges)
    - **5 Minutes** (~750 words / ~12-15 dialogue exchanges)
    - **10 Minutes** (~1500 words / deep conversational dive)
  - Automatically prompts the model with explicit pacing and word budget constraints to match the desired audio length.
- **Strict Speaker Tagging**:
  ```text
  [Μαρία]: Καλημέρα σε όλους! Είναι Τετάρτη 26 Αυγούστου και παρακολουθείτε το πρωινό ενημερωτικό podcast του PressHub.
  [Νίκος]: Καλημέρα Μαρία, καλημέρα σε όλους τους ακροατές μας. Σήμερα η ατζέντα κυριαρχείται από σημαντικές εξελίξεις...
  ```
- **Staging Storage**: Stores editable script in the briefing run record for review in the Daily Briefing Hub.

### 3.4 Multi-Voice Audio Synthesizer (`PressHub_AI_Audio_Synthesizer`)
- **Configurable Voice Model Directory**:
  - Lists available Greek (`el-GR`) Google Cloud TTS voice models dynamically:
    - **Female Voices**: `el-GR-Neural2-A`, `el-GR-Wavenet-A`, `el-GR-Standard-A`
    - **Male Voices**: `el-GR-Neural2-B` (or `el-GR-Wavenet-B`), `el-GR-Standard-B`
  - Per-voice controls in settings:
    - Speaking Rate / Speed (e.g. `0.90x` to `1.20x`, default `1.0x`)
    - Pitch Adjustment (e.g. `-2.0` to `+2.0`, default `0.0`)
- **Audio Stitching & Pauses**:
  - Chunks script by speaker turn and synthesizes individual audio buffers.
  - Inserts natural inter-speaker pauses (400ms) between turns.
  - Stitches buffers into a continuous `.mp3`.
- **Media Sideloading & Post Creation**:
  - Sideloads MP3 into WordPress Media Library.
  - Creates a post in category **Podcasts** with embedded audio player block and formatted transcript.

---

## 4. WP Admin Daily News Briefing Hub

Located at **PressHub AI > Daily Briefing Hub**:
1. **Pipeline Status Overview**:
   - Card for current date showing 4 progress milestones:
     - [x] Step 1: Articles Harvested (Status, article count, and Cloudflare blocked alert if any)
     - [x] Step 2: Text Story Generated (Link to Post Edit)
     - [ ] Step 3: Podcast Dialogue Script (Inline editable textarea)
     - [ ] Step 4: Audio Podcast (Generate Audio / Player)
2. **Blocked Source Manual Upload Modal / Box**:
   - Displays any outlets blocked during crawling.
   - Allows dropping PDF files or pasting article text with a "Process & Merge Uploads" button.
3. **Inline Script Editor**:
   - Full code/text editor for the podcast dialogue script.
   - Allows modifying dialogue lines, fixing Greek phonetic pronunciations, and adding host remarks.
   - Buttons: **"Save Script"** and **"Generate Audio Podcast"**.
4. **Manual On-Demand Triggers**:
   - "Run Scrape Now" and "Run Full Generation Now" buttons for instant execution.

---

## 5. Plugin Settings & Prompt Customization

Under **PressHub AI > Settings > Daily Briefing**:
- **Scheduling**:
  - `presshub_ai_briefing_harvest_time`: Harvesting time picker (default: `06:30`).
  - `presshub_ai_briefing_generation_time`: Generation time picker (default: `07:15`).
- **Sources**:
  - `presshub_ai_briefing_sources`: Textarea for target Greek website URLs.
- **Instruction Presets**:
  - `presshub_ai_briefing_text_preset`: Dropdown selecting a PressHub AI Preset for Text Curation.
  - `presshub_ai_briefing_podcast_preset`: Dropdown selecting a PressHub AI Preset for Podcast Producer.
- **Podcast Configuration**:
  - `presshub_ai_briefing_target_duration`: Dropdown (`3 minutes`, `5 minutes`, `10 minutes`).
  - `presshub_ai_briefing_voice_female`: Voice model selector for Host 1 (Female).
  - `presshub_ai_briefing_voice_male`: Voice model selector for Host 2 (Male).
  - `presshub_ai_briefing_voice_speed`: Speaking rate slider/input (`0.85` - `1.25`).
  - `presshub_ai_briefing_voice_pitch`: Pitch slider/input (`-4.0` - `+4.0`).
- **Target Categories & Status**:
  - Text Story Category and Status (`pending` / `publish`).
  - Podcast Category and Status (`pending` / `publish`).
- **Prompts & Reset Affordance**:
  - Text Curation base prompt editor + "Reset to Default" button.
  - Podcast Dialogue base prompt editor + "Reset to Default" button.

---

## 6. Test-Driven Development Plan

### PHPUnit / Unit & Integration Tests (`presshub-ai-editor/tests/`)
1. `NewsHarvesterTest.php`:
   - URL extraction from HTML markup.
   - Cloudflare / 403 detection and error flagging.
   - Manual upload / PDF text integration into daily payload.
   - Raw JSON serialization and filesystem storage.
2. `NewsCuratorTest.php`:
   - Preset resolution and variable substitution in Greek curation prompts.
   - Category and post metadata assignment.
3. `PodcastProducerTest.php`:
   - Preset resolution and duration-based token/word budget calculation (3, 5, 10 min).
   - Dialogue speaker turn parsing (`[Μαρία]: ...`, `[Νίκος]: ...`).
4. `AudioSynthesizerTest.php`:
   - Voice model selection, pitch/speed parameter encoding.
   - Audio buffer stitching & pause insertion.
5. `DailyBriefingSettingsTest.php`:
   - Dual-time schedule sanitization (harvest time vs generation time).
   - Preset dropdown validation, voice model option validation, and prompt reset behavior.

### TypeScript Workflow Tests (`presshub-workflow/tests/`)
- `briefing.test.ts`: Tests script parsing, duration calculations, source filtering, and WordPress REST API briefing creation.

---

## 7. Spec Self-Review Checklist
- [x] **Placeholders**: No TODOs or TBDs; all classes, hooks, options, and paths are explicitly specified.
- [x] **Internal Consistency**: Seamlessly integrates with existing `PressHub_AI_Preset_Store`, `PressHub_AI_URL_Fetcher`, and `PressHub_AI_Markdown`.
- [x] **Scope**: Clearly bounded to the Greek Daily Briefing and Podcast pipeline, settings, and admin hub.
- [x] **User Comments Addressed**:
  1. Configurable separate harvest and generation times.
  2. Text Agent uses PressHub AI Instruction Presets.
  3. Podcast Producer uses PressHub AI Instruction Presets.
  4. Configurable Voice Models (speed, pitch) & target Podcast Duration (3, 5, 10 min).
  5. Cloudflare/bot block detection with editor alert & manual upload fallback.
