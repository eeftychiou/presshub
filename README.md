# PressHub AI Co-Pilot

AI co-authoring and editorial workflow for PressHub — draft generation,
editorial scorecards, chat intents, image/audio production, and scheduled
deep-research logs, with per-author instruction presets and per-user rate
limiting.

> **Status:** maintained, single-tenant production-ready. Not yet
> WP.org-ready (i18n, updater packaging) — see Development.

[![CI](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml/badge.svg)](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml)
<!--
  Badge placeholders: swap the URL above for the repo's real Actions
  badge once the workflow runs on the default branch.
-->

## Features

- **AI draft generation** from pasted sources + optional instructions
  (OpenAI, Anthropic, or Google Gemini; per-provider model/tuning).
- **Editorial scorecard gate** — a 0–100 readiness score with feedback;
  drafts scoring ≥ 80 are moved to `pending`, published posts are never
  demoted.
- **Chat intents** — the sidebar routes prompts to `chat`, `research`,
  `image`, or `report` (audio narration) automatically.
- **Image generation** via Google Imagen (Vertex AI) with media sideload
  into the post.
- **Audio reports** — script-to-speech narration attached to the post.
- **Per-author instruction presets** — named instruction sets (plugin
  defaults, per-author copies, per-request metabox selection) appended to
  the system prompt for drafts, chat, and research.
- **Deep-research logs** — scheduled research jobs stored as
  `presshub_research` posts with a configurable retention sweep
  (default 30 days).
- **Per-user rate limiting** — configurable requests-per-window guard
  across the AI endpoints.
- **Masked API keys** — stored keys render masked in settings, with an
  explicit "remove stored key" control per secret.

## Requirements

- WordPress **≥ 6.0** (tested up to 6.7)
- PHP **≥ 7.4**
- An API key for at least one provider: OpenAI, Anthropic, or Google
  Gemini (Google Cloud key for Imagen/TTS)

## Installation

1. Download the plugin zip (GitHub release asset) or clone this
   repository.
2. Upload `presshub-ai-editor/` to `wp-content/plugins/`, or install the
   zip via **Plugins → Add New → Upload Plugin**.
3. Activate **PressHub AI Co-Pilot**.
4. Open **Settings → PressHub AI** and configure a provider + API key.

The plugin embeds a GitHub update checker: releases published on the
`main` branch of this repository (with the plugin zip attached as a
release asset) are offered as automatic updates. Set the optional GitHub
token in settings for private repositories.

## Settings guide

**Settings → PressHub AI** is organised into sections:

| Section | What it configures |
|---|---|
| General | Active AI provider |
| Providers | Per-provider model, temperature, max tokens, timeout; OpenAI Organization ID; Anthropic API version; optional GitHub token |
| Media | Google Cloud API key (Imagen/TTS), Cloud project ID, Imagen region |
| Rate limits | Enable per-user limiting, requests per window, window length (seconds), research-log retention (days) |

Secrets are stored with autoload disabled and displayed masked
(`••••abcd`). Use the **remove stored key** checkbox to revoke a key —
leaving the field empty keeps the existing stored key.

### Reliable research scheduling (WP-Cron)

Deep-research jobs are scheduled with `wp_schedule_single_event()`, which
only fires when WordPress itself is loaded (typically site traffic). On
low-traffic sites a job may sit `pending` until a visit. For reliable
execution, use a real cron:

```sh
wp config set DISABLE_WP_CRON true --raw
# crontab (every minute):
* * * * * wp cron event run --due-now --path=/path/to/wp
```

A research job already in flight is guarded against duplicate concurrent
executions (a `processing` status prevents a second provider call when
WP-Cron fires the same event twice).

## Instruction presets

Presets are named instruction sets appended to the system prompt for
drafts, chat, and research:

- **Plugin defaults** — admin-curated library available to every author
  (Settings → PressHub AI).
- **Per-author presets** — each author's own library on their profile
  screen; an author preset shadows a plugin default with the same slug.
- **Per-request selection** — the metabox dropdown picks which preset
  applies to the next draft; each author can also set a **default preset**
  applied automatically.

Resolution order: per-request selection → author default → plugin
default → built-in prompt. The built-in prompt is **never replaced** by a
preset — the preset text is appended, preserving the editorial framing.
The `__none__` sentinel disables presets for a request. Scorecard,
classify, and audio endpoints never receive presets.

### Prompt filters (developers)

- `presshub_ai_draft_system_prompt`, `presshub_ai_research_system_prompt`
  — filter the **base** prompt before preset composition.
- `presshub_ai_composed_research_system_prompt` — filter the final
  research prompt (base + preset) after composition.
- `presshub_ai_draft_user_prompt`, `presshub_ai_classify_intent_prompt`,
  `presshub_ai_scorecard_system_prompt` — other template hooks.

## Development

### Repository layout

```
presshub-ai-editor/          WordPress plugin (PHP)
  includes/                  classes (settings, API client, presets, rate limiter, …)
  tests/                     TDD harness suites (plain-PHP, no PHPUnit)
  assets/                    sidebar/editor scripts
presshub-workflow/           TypeScript workflow tests (Jest + tsc)
dev-env/                     Local WordPress dev environment & debugging tooling (SQLite-powered)
.github/workflows/           CI (PHP harness + lint; Jest + tsc)
docs/                        design docs, review reports
AGENTS.md                    Instructions and tooling guide for AI coding assistants
```

### Local WordPress Development Environment

The repository includes a self-contained local WordPress environment powered by the WordPress Core SQLite database engine (zero external database daemons required).

- **Start Dev Server**: `php dev-env/scripts/server.php` → `http://127.0.0.1:8888` (Admin: `admin` / `password123`)
- **Live Plugin Linking**: `wp-content/plugins/presshub-ai-editor` directly maps to `presshub-ai-editor/`.
- **Stream Logs**: `php dev-env/scripts/tail-logs.php --follow` (Core `debug.log` & PressHub `presshub-debug.log`)
- **Inspect Token & DB Logs**: `php dev-env/scripts/view-token-logs.php`
- **Query Database**: `php dev-env/scripts/query-db.php --tables`
- **Run Live Integration Tests**: `php dev-env/scripts/run-integration-tests.php`

For complete instructions and CLI workflows, see [AGENTS.md](file:///c:/Users/User/Antigravity/Presshub/AGENTS.md) and [dev-env/README.md](file:///c:/Users/User/Antigravity/Presshub/dev-env/README.md).

### TDD harness

Each suite is a self-bootstrapping PHP file (WordPress functions are
stubbed in `tests/wordpress-stubs.php`):

```sh
cd presshub-ai-editor
php tests/run-all-tests.php              # runs all 46 test suites
php tests/ResearchJobGuardTest.php       # single suite
```

The workflow package is tested separately:

```sh
cd presshub-workflow
npm install
npm test          # Jest
npx tsc --noEmit  # strict typecheck
```

CI runs both suites on every push/PR (`php -l` on all plugin files
included).

### Logging

The plugin writes to PHP's error log (`error_log`) on research-job
failures (provider errors and post-update failures), each entry including
the research post ID. Tests redirect the log target to a temp file.

### Internationalization (i18n)

All user-facing strings (PHP + JS) carry the `presshub-ai-editor`
textdomain. To rebuild the gettext template after adding new
translatable strings:

```sh
cd presshub-ai-editor
php scripts/make-pot.php          # writes languages/presshub-ai-editor.pot
```

For WP.org-style translation tooling (requires WP-CLI and `wp i18n`):

```sh
wp i18n make-pot . languages/presshub-ai-editor.pot --domain=presshub-ai-editor
```

The PHP enqueue declares `wp-i18n` as a script dependency on
`admin.js`, `sidebar.js`, and `presets.js`; in production the
runtime translations are served by WordPress's standard
`wp_set_script_translations()` call (not wired here because the
plugin ships only one locale — English). Translators can open the
generated `.pot` in Poedit or upload it to translate.wordpress.org
to produce `.po` / `.mo` files; those are dropped into
`presshub-ai-editor/languages/` next to the `.pot`.

## License

GPL-2.0-or-later — see the plugin header in
`presshub-ai-editor/presshub-ai-editor.php`.

## Troubleshooting

### "Update failed: Unable to rename the update to match the existing directory."

WordPress downloads and extracts the new version, then renames the extracted
folder into `wp-content/plugins/`. This error means that rename failed —
almost always a **server-side permission/ownership problem**, not a release
problem (the release zip is verified to contain a single top-level folder
`presshub-ai-editor/` matching the installed slug). Check, in order:

1. **Installed folder name** — the plugin must live in exactly
   `wp-content/plugins/presshub-ai-editor/` (case-sensitive). If it was
   installed from a differently-named zip or manually renamed, WordPress
   tries to rename the update to match the odd name and fails. Fix:
   deactivate, rename the folder to `presshub-ai-editor`, reactivate.
2. **Filesystem permissions** — the PHP/web-server user (e.g. `www-data`)
   needs write+execute on `wp-content/plugins/` itself (the rename creates
   the new folder there) and must be able to delete the old plugin folder's
   files (WP removes it before/while installing). Typical on FTP-installed
   or root-owned sites:
   `sudo chown -R www-data:www-data wp-content/plugins/presshub-ai-editor`
   (adjust the user to match your PHP-FPM/Apache user).
3. **Stale temp folder** — a leftover `wp-content/upgrade/presshub-ai-editor/`
   from a failed run can block extraction. Delete `wp-content/upgrade/*`
   and retry.
4. **Case-sensitivity** — on Linux, `PressHub-AI-Editor` ≠ `presshub-ai-editor`.
   If the folder name differs only by case, rename it to the lowercase slug.

Manual fallback that bypasses the rename entirely: deactivate → delete the
plugin folder → upload the release zip from the latest GitHub release
(Plugins → Add New → Upload Plugin) → activate.
