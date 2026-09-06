# PressHub AI GitHub README Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use aerodeck:subagent-driven-task-pipeline (recommended) or aerodeck:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Comprehensively modernize `README.md` to reflect PressHub AI v2.3.5 architecture, features, observability, and test suites, and push changes directly to `main` on GitHub.

**Architecture/Workflow:** Full-document upgrade aligned with the approved design spec (`docs/aerodeck/specs/2026-09-06-readme-modernization-design.md`), followed by markdown formatting check, git commit, and pushing to `origin/main`.

**Tech Stack/Tools:** Markdown, Git, GitHub Actions CLI / WP-CLI context.

---

### Task 1: Draft the Modernized `README.md`

**Targets:**
- Modify: `c:/Users/User/Antigravity/Presshub/README.md`

- [ ] **Step 1: Define success criteria**
  The new `README.md` must accurately detail:
  - Version 2.3.5 with accurate badges and prerequisites (PHP >= 7.4, WP >= 6.0).
  - Daily Briefing Pipeline (`PressHub_AI_News_Harvester` & `PressHub_AI_News_Curator`).
  - Automated Podcast Producer & Google Cloud TTS Audio Studio (nameless speakers, spoken date formatting, 30-voice catalog, audio stitching).
  - In-Editor Co-Pilot, 0-100 Scorecard gate, Chat intents, Google Imagen sideloading, deep-research jobs.
  - Modular Provider Store & Settings-First, Zero-Silent-Fallback, and Clean-Slate Architecture principles.
  - Enterprise Observability & 4-Layer Logging with CLI diagnostic tools cheat sheet.
  - Local SQLite Dev Environment with 73 passing PHP unit test suites and 81 passing TypeScript Jest tests.
  - Modern tabbed settings and troubleshooting guide.

- [ ] **Step 2: Verify current state lacks criteria**
  Run: Check `README.md` line 157 (currently cites outdated "46 test suites" and lacks briefing/podcast documentation).
  Expected: Outdated content found.

- [ ] **Step 3: Replace `README.md` with complete, polished content**
  Write the modernized `README.md`.

- [ ] **Step 4: Verify state passes criteria**
  Inspect `README.md` to confirm all sections, 73 test suites, v2.3.5 references, and formatting are intact.

- [ ] **Step 5: Checkpoint**
  Verify file length and clean markdown formatting.

---

### Task 2: Git Commit and Push to GitHub `main`

**Targets:**
- Repository: `c:/Users/User/Antigravity/Presshub`

- [ ] **Step 1: Check git status and staged changes**
  Run: `git status`
  Expected: Only `README.md` and spec/plan docs modified or staged.

- [ ] **Step 2: Stage and commit `README.md` and docs**
  Run: `git add README.md docs/aerodeck/`
  Commit with message: `docs: comprehensively modernize README.md for v2.3.5 architecture`

- [ ] **Step 3: Push directly to `origin/main`**
  Run: `git push origin main`
  Expected: Clean push to GitHub remote `origin/main`.

- [ ] **Step 4: Verify remote status**
  Run: `git status`
  Expected: Working tree clean, up to date with `origin/main`.
