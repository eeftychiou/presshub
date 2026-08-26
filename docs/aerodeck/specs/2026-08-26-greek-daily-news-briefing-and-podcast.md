# Greek Daily News Briefing & Multi-Voice AI Podcast Specification

**Date**: 2026-08-26  
**Status**: Approved Design  
**Target Platform**: WordPress (via `presshub-ai-editor` plugin & `presshub-workflow`)  

---

## 1. Executive Summary & Goals

This feature automates the creation of a daily morning digital newspaper briefing and dual-host audio podcast in Greek.

Every morning at 07:00 AM (or on-demand via the admin dashboard):
1. **Harvester**: Scrapes configured Greek news website homepages, extracting main headlines and full article bodies, and archives the raw snapshot to disk.
2. **Curator (Text Agent)**: Synthesizes the day's top news stories into an analytical, categorized Greek news story published in the designated category (e.g. *In Context* or *Stories*).
3. **Podcast Producer (Script Agent)**: Generates a natural, conversational 2-host Greek dialogue script discussing the news agenda.
4. **Editorial Hub**: Admins and editors monitor each stage in real-time, with full capabilities to edit the dialogue script before audio generation.
5. **Audio Synthesizer (TTS Agent)**: Alternates between natural male and female Greek voices using Google Cloud Text-to-Speech (Neural2 / Wavenet), stitches the audio segments, and publishes the podcast episode under the *Podcasts* category with native audio player and transcript.
6. **Prompt Customization**: Administrators can review and customize the system prompts for both the text curation and podcast dialogue stages directly in WordPress settings.

---

## 2. Architecture & Pipeline Stages

```
                        [07:00 AM WP-Cron / Admin Manual Trigger]
                                           │
                                           ▼
                  ┌─────────────────────────────────────────────────┐
                  │   Stage 1: Greek News Scraper & Downloader      │
                  │   - Crawls configured Greek website homepages   │
                  │   - Extracts headline links & full body text    │
                  │   - Saves snapshot JSON to uploads directory    │
                  └────────────────────────┬────────────────────────┘
                                           │
                                           ▼
                  ┌─────────────────────────────────────────────────┐
                  │   Stage 2: Daily News Story Curation (Text)     │
                  │   - Synthesizes top stories across categories   │
                  │   - Formats clean Greek prose with context      │
                  │   - Creates WordPress Post (Pending / Published)│
                  └────────────────────────┬────────────────────────┘
                                           │
                                           ▼
                  ┌─────────────────────────────────────────────────┐
                  │   Stage 3: Podcast Script Generation (Dialogue) │
                  │   - Generates 2-host conversational dialogue    │
                  │   - Alternates between Host A (F) & Host B (M)  │
                  │   - Displays in Admin Hub for review & editing  │
                  └────────────────────────┬────────────────────────┘
                                           │
                        [Editor Reviews / Edits Script]
                                           │
                                           ▼ (Editor clicks "Synthesize Audio")
                  ┌─────────────────────────────────────────────────┐
                  │   Stage 4: Multi-Voice Audio Podcast Synthesis  │
                  │   - Chunks dialogue turns by speaker tag        │
                  │   - Calls Google Cloud TTS (Neural2/Wavenet)    │
                  │   - Stitches chunks into seamless MP3 file      │
                  │   - Imports to Media Library & creates post in  │
                  │     "Podcasts" category with audio player       │
                  └─────────────────────────────────────────────────┘
```

---

## 3. Detailed Component Specifications

### 3.1 Harvester (`PressHub_AI_News_Harvester`)
- **Homepage Discovery**: Fetches homepages from the list of URLs specified in plugin settings (e.g. `https://www.kathimerini.gr`, `https://www.in.gr`, `https://www.sigmalive.com`, `https://www.tovima.gr`).
- **Article Link Extraction**: Extracts article links using regex and DOM parsing, filtering out navigation, privacy policies, tags, and category landing pages.
- **Content Scraping**: Utilizes `PressHub_AI_URL_Fetcher` to extract clean article text, headline, author, and timestamp.
- **Deduplication & Storage**: Deduplicates overlapping articles across sites and saves the daily payload to:
  `wp-content/uploads/presshub-briefings/YYYY-MM-DD/raw-articles.json`
- **Data Schema**:
  ```json
  {
    "date": "2026-08-26",
    "sources_crawled": 4,
    "articles_count": 18,
    "articles": [
      {
        "id": "art_1",
        "url": "https://www.kathimerini.gr/politics/...",
        "title": "...",
        "source_domain": "kathimerini.gr",
        "content": "...",
        "published_at": "2026-08-26T06:30:00Z"
      }
    ]
  }
  ```

### 3.2 Text Curator (`PressHub_AI_News_Curator`)
- **Prompt Hydration**: Loads the configured Curation Prompt template and replaces variables `{date}`, `{sources_list}`, `{articles_count}`, and `{articles_context}`.
- **AI Synthesis**: Dispatches prompt to the active AI provider (Gemini / OpenAI / Anthropic).
- **Post Publishing**:
  - Sets post title: `Πρωινή Ενημέρωση: [Top Headline Summary] - [Formatted Date]`
  - Converts Markdown output to semantic HTML via `PressHub_AI_Markdown`.
  - Assigns post to configured category (default: *In Context* or *Stories*).
  - Sets status to configured default (*Pending Review* or *Publish*).
  - Stores meta `_presshub_briefing_date` and `_presshub_briefing_type = 'text'`.

### 3.3 Podcast Producer (`PressHub_AI_Podcast_Producer`)
- **Prompt Hydration**: Loads the configured Podcast Dialogue Prompt template.
- **Dialogue Format**: Enforces strict speaker tags:
  ```text
  [Μαρία]: Καλημέρα σε όλους! Είναι Τετάρτη 26 Αυγούστου και παρακολουθείτε το πρωινό ενημερωτικό podcast του PressHub.
  [Νίκος]: Καλημέρα Μαρία, καλημέρα σε όλους τους ακροατές μας. Σήμερα η ατζέντα κυριαρχείται από σημαντικές εξελίξεις...
  ```
- **Staging CPT / Storage**: Stores the script in the briefing run record (`_presshub_podcast_script`) for inline editing in the WP Admin Daily Briefing Hub.

### 3.4 Multi-Voice Audio Synthesizer (`PressHub_AI_Audio_Synthesizer`)
- **Dialogue Parser**: Parses script into sequential speaker turns:
  ```php
  [
    ['speaker' => 'female', 'voice' => 'el-GR-Neural2-A', 'text' => 'Καλημέρα σε όλους...'],
    ['speaker' => 'male',   'voice' => 'el-GR-Neural2-B', 'text' => 'Καλημέρα Μαρία...']
  ]
  ```
- **Synthesis & Concatenation**:
  - Synthesizes each turn via Google Cloud TTS REST API using `presshub_ai_google_cloud_api_key`.
  - Combines binary MP3 frames with short natural pauses between speakers (400ms).
  - Saves final MP3 to temporary path: `presshub-briefing-YYYY-MM-DD.mp3`.
- **Media Sideloading & Post Creation**:
  - Sideloads MP3 into WordPress Media Library (`wp-content/uploads/...`).
  - Creates a WordPress post under category **Podcasts**:
    - **Title**: `PressHub Daily Podcast: [Episode Title] - [Date]`
    - **Content**: Native Audio Block (`<!-- wp:audio {"id":...} -->`) followed by complete transcript block.
    - **Status**: Configured status (*Pending Review* or *Publish*).

---

## 4. WP Admin Daily News Briefing Hub

Located at **PressHub AI > Daily Briefing Hub**:
1. **Pipeline Overview**:
   - Card for current date showing 4 progress milestones:
     - [x] Step 1: Articles Harvested (e.g. "18 articles scraped from 4 outlets")
     - [x] Step 2: Text Story Generated (View / Edit Post)
     - [ ] Step 3: Podcast Dialogue Script (Editable textarea)
     - [ ] Step 4: Audio Podcast (Generate Audio / Player)
2. **Inline Script Editor**:
   - Full WYSIWYG/code editor for the podcast dialogue script.
   - Allows changing wording, fixing Greek phonetic pronunciations, and adding/removing host lines.
   - "Save Script" and "Generate Audio Podcast" buttons.
3. **Manual Trigger**:
   - "Run Daily Briefing Scrape Now" button allowing instant execution of the workflow.
4. **History Log**:
   - Archive table of past briefing runs with links to raw article dumps, text posts, and podcast episodes.

---

## 5. Plugin Settings & Prompt Customization

Under **PressHub AI > Settings > Daily Briefing**:
- `presshub_ai_briefing_sources`: Textarea for target Greek website URLs (one per line).
- `presshub_ai_briefing_cron_time`: Time picker / dropdown (default: `07:00`).
- `presshub_ai_briefing_text_category`: Category dropdown for Text Stories.
- `presshub_ai_briefing_text_status`: Status dropdown (`pending` / `publish`).
- `presshub_ai_briefing_podcast_category`: Category dropdown for Podcasts.
- `presshub_ai_briefing_podcast_status`: Status dropdown (`pending` / `publish`).
- `presshub_ai_briefing_voice_female`: Greek Female voice ID (default: `el-GR-Neural2-A`).
- `presshub_ai_briefing_voice_male`: Greek Male voice ID (default: `el-GR-Neural2-B`).
- `presshub_ai_briefing_text_prompt`: Full customizable Greek text curation prompt + "Reset to Default" button.
- `presshub_ai_briefing_podcast_prompt`: Full customizable Greek dual-host dialogue prompt + "Reset to Default" button.

---

## 6. Test-Driven Development Plan

### PHPUnit / Unit & Integration Tests (`presshub-ai-editor/tests/`)
1. `NewsHarvesterTest.php`:
   - URL extraction from HTML markup.
   - Deduplication across multiple outlets.
   - Raw JSON serialization and filesystem storage.
2. `NewsCuratorTest.php`:
   - Variable substitution in Greek curation prompts.
   - Category and post metadata assignment.
3. `PodcastProducerTest.php`:
   - Dialogue speaker turn parsing (`[Μαρία]: ...`, `[Νίκος]: ...`).
   - Handling unknown speaker tags or malformed scripts.
4. `AudioSynthesizerTest.php`:
   - Voice mapping by speaker tag.
   - Audio buffer stitching & pause insertion.
5. `BriefingSettingsTest.php`:
   - Settings validation, voice option sanitization, and prompt reset behavior.

### TypeScript Workflow Tests (`presshub-workflow/tests/`)
- `briefing.test.ts`: Tests script parsing, source filtering, and WordPress REST API briefing creation.

---

## 7. Spec Self-Review Checklist
- [x] **Placeholders**: No TODOs or TBDs; all classes, hooks, options, and paths are explicitly specified.
- [x] **Internal Consistency**: Data flow from Harvester $\to$ Curator $\to$ Producer $\to$ Synthesizer is unified.
- [x] **Scope**: Clearly bounded to the Greek Daily Briefing and Podcast pipeline and admin hub.
- [x] **Ambiguity**: Greek voice IDs, file storage paths, prompt template placeholders, and user roles are specifically detailed.
