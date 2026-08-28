# PressHub AI Dynamic Providers, Modular Routing, Token Logging & Podcast Pipeline Implementation Plan

## 1. Overview & Architecture Overhaul

This plan addresses all items from our discussion and your feedback:

1. **Dynamic Provider Management ("Add Provider" Registry)**:
   - Replaces the cluttered list of stacked provider inputs with a dynamic "Configured Providers" manager.
   - Users can click **"Add Provider"** to add any provider from presets (**Google Gemini**, **OpenAI**, **Anthropic**, **Groq**, **Mistral**, **Ollama/Local**, **Google Cloud TTS**) or configure a **Custom OpenAI-Compatible Provider** (with custom base URL, auth headers, and model list).
   - Each provider card offers **Edit**, **Delete**, and instant **Test Connection**.

2. **Per-Module Provider & Model Assignment**:
   - Each subsystem selects its servicing provider from the active configured providers:
     - **AI Co-Author & Editorial Review**: Assigned Provider, Model, Max Tokens, Temperature.
     - **Daily Briefing — Text Story Curator**: Assigned Provider, Model, Max Tokens, Temperature.
     - **Daily Briefing — Podcast Script Producer**: Assigned Provider, Model, Max Tokens, Temperature.
     - **AI Copilot & Assistant**: Assigned Provider, Model, Max Tokens, Temperature.

3. **Prompt Deduplication & Production-Time Context Selection**:
   - Eliminates duplicate raw article ingestion in [`PressHub_AI_Podcast_Producer`](file:///c:/Users/User/Antigravity/Presshub/presshub-ai-editor/includes/class-podcast-producer.php).
   - In Daily Briefing Hub, editors can choose the Podcast context source:
     - **(o) Curated Morning Briefing Story** (Fast, cohesive, high-quality dialogue).
     - **( ) Selected Harvested Articles** (Specific news items checked by the editor).
   - Interactive article checklist in Step 1 with *Select All* / *Deselect All* and live counter (`Selected: X / Total: Y`) passed into both Text Curation and Podcast Script generation.

4. **Gemini & Default Timeout Expansion**:
   - Provider timeouts increased to **300 seconds (5 minutes)**.

5. **Database-Backed Token & Activity Logging (`wp_presshub_ai_token_logs`)**:
   - Tracks exact prompt/completion tokens for LLM requests, character counts and audio duration for TTS, and sources/articles for Web Scraping.
   - Includes Admin **Token & Usage Logs** dashboard tab with filters, summary cards, CSV export, and 60-day auto-retention cron.

---

## 2. Proposed File Map & Tasks

```
presshub-ai-editor/
├── includes/
│   ├── class-provider-store.php          [NEW] Dynamic provider registry & custom endpoints
│   ├── class-token-logger.php            [NEW] DB table manager, token ingestion & reporting
│   ├── class-provider-defaults.php       [MODIFY] 300s timeout & provider preset templates
│   ├── class-api-client.php              [MODIFY] Dynamic modular routing & token extraction
│   ├── class-settings.php                [MODIFY] Add Provider manager, modular tabs & token viewer
│   ├── class-news-harvester.php          [MODIFY] Article tagging & token logging integration
│   ├── class-news-curator.php            [MODIFY] Article filtering & helper to get briefing story
│   ├── class-podcast-producer.php        [MODIFY] Deduplication, Curated Briefing mode & article filters
│   ├── class-briefing-admin.php          [MODIFY] Article checklist UI & context mode radio selector
│   └── class-ajax-handlers.php           [MODIFY] Endpoints for providers, token logs & modular briefing
├── assets/
│   ├── admin.js                          [MODIFY] Dynamic Add Provider modal, test connection & token viewer
│   ├── admin.css                         [MODIFY] Styles for provider cards, modal & token log dashboard
│   ├── briefing-admin.js                 [MODIFY] Article checklist handlers & context mode dispatch
│   └── briefing-admin.css                [MODIFY] Styles for article selection rows & badges
└── tests/
    ├── ProviderStoreTest.php             [NEW] Tests for dynamic provider CRUD & custom endpoints
    ├── TokenLoggerTest.php               [NEW] Tests for token logging, aggregation & pruning
    ├── APIClientModularTest.php          [NEW] Tests for per-module routing
    ├── PodcastProducerTest.php           [MODIFY] Tests for deduplication & context modes
    └── NewsCuratorTest.php               [MODIFY] Tests for filtered article curation
```

---

## 3. Verification Plan

### Automated Tests
- Run `tests/run-all-tests.php` covering:
  - Dynamic provider storage, legacy options migration, custom endpoints.
  - Per-module API routing (Daily Briefing, Co-Author, Copilot).
  - Token logger DB schema creation, event logging, summary queries, and 60-day pruning.
  - Podcast Producer deduplication and Curated Briefing context mode.
  - News Curator article filtering.

### Manual / Browser Verification
- Open PressHub AI Settings in WP Admin:
  - Verify "Add Provider" modal and provider cards (edit, test connection, delete).
  - Verify each module tab can select from configured providers and set models.
  - Verify Token & Usage Logs tab renders metrics, filters, and CSV export.
- Open Daily Briefing Hub in WP Admin:
  - Verify article checkboxes and live counter.
  - Generate Podcast Script with "Curated Morning Briefing" context mode.
  - Verify execution completes in ~5-10 seconds and logs prompt/completion tokens.
