# Design Spec: PressHub AI GitHub README Modernization

**Date:** 2026-09-06  
**Status:** Approved by User  
**Target:** `README.md` in repository root  
**Release Target:** v2.3.5 / `main` branch  

---

## 1. Objective & Scope

Bring the public GitHub repository documentation (`README.md`) up to date with the current enterprise architecture of **PressHub AI (v2.3.5)**. The document must serve as a comprehensive technical guide for newsroom operators, publishers, and developers.

Key updates:
1. Elevate positioning from a simple drafting plugin to an **enterprise AI newsroom, multimedia publishing pipeline, and editorial co-pilot**.
2. Document the **Daily Briefing Pipeline** (multi-source RSS & direct scraping Harvester, LLM scoring/deduplication Curator, bilingual synthesis).
3. Document the **Podcast Producer & Google Cloud TTS Audio Studio** (multi-host dialogue scripts, spoken date formatting, presenter personas, 30-voice multilingual catalog, audio stitching).
4. Document the **Modular Provider Store** (OpenAI, Anthropic, Google Gemini, Google Cloud) with dynamic model discovery, module overrides, and zero silent fallbacks.
5. Document the **In-Editor Co-Pilot & Scorecard Gate** (0-100 rubric, chat intents, Google Imagen sideloading, deep-research jobs).
6. Document **Enterprise Observability & 4-Layer Logging** with CLI diagnostic tooling (`tail-logs.php`, `view-token-logs.php`, `query-db.php`).
7. Update developer and local testing instructions (73 isolated PHP unit test suites + 81 Jest tests in `presshub-workflow`, SQLite local dev environment).
8. Detail modern tabbed **Settings & Architecture Principles** (Settings-First, Zero-Silent-Fallback, Clean Slate).

---

## 2. Document Structure for `README.md`

- **Header & Badges**: Project title, version 2.3.5, CI status, WordPress / PHP requirements, license.
- **Overview & Core Capabilities**: High-level workflow from ingest to broadcast.
- **Daily Briefing Pipeline**: Harvester (RSS/web, politeness, budgets) -> Curator (scoring, deduplication, bilingual synthesis) -> Briefing Admin.
- **Podcast Producer & TTS Audio Studio**: Multi-host dialogue (nameless dialogue invariant, spoken dates, personas), Google Cloud TTS integration, 30-voice catalog, audio controls & stitching.
- **In-Editor Co-Pilot**: Draft generator, 0-100 Scorecard gate, chat intents, deep-research scheduled jobs, Google Imagen sideloading.
- **Modular Provider Store & Settings**: Decoupled provider engine, dynamic discovery, architectural principles (Settings-First, Zero-Silent-Fallback, Clean Slate).
- **Enterprise Observability**: The 4-layer logging architecture and CLI tool cheat-sheet.
- **Developer Guide & Testing**: Local SQLite dev environment, 73 unit tests, 81 Jest workflow tests, WP-CLI commands.
- **Installation, Updates & Troubleshooting**: Steps, GitHub update checker, WP-Cron recommendations, permission tips.

---

## 3. Verification & Git Delivery
- Verify formatting and markdown validity.
- Stage `README.md` and commit with conventional commit message `docs: comprehensively modernize README.md for v2.3.5 architecture`.
- Push directly to `origin/main` as requested by the user.
- Verify remote synchronization.
