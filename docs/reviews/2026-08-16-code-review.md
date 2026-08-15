# PressHub Plugin — Deep Code Review

- **Date:** 2026-08-16
- **Reviewer:** Hermes code-review agent (read-only; no files modified)
- **Scope:** `presshub-ai-editor/` (WordPress plugin, all `includes/*.php`, main file, assets) + `presshub-workflow/src/*.ts` (Node/TS workflow). Vendored `includes/plugin-update-checker/` (third-party PUC library) reviewed only for integration points, not line-by-line.
- **Baseline:** commit `63f691e` (2026-08-15), all 18 standalone PHP suites pass; Jest/tsc not re-run (CI workflow covers them).

---

## Executive Summary

The plugin's security posture is **good**: all 10 AJAX handlers verify the `presshub_ai_nonce` nonce *and* capability gates (`edit_posts` / `edit_post` / `manage_options`, including an admin-only belt-and-suspenders gate on the paid image/report intents that survives classifier bypass); render paths escape consistently (`esc_html`/`esc_attr`/`esc_textarea` server-side, React text-escapes + a `presshubEsc` helper client-side); settings have sanitize callbacks on every registered option with masked key display; presets are regex-validated, length-capped and re-sanitized on every read; rate limiting is opt-in and off by default; cron scheduling is guarded against double-registration.

**No Critical findings.** The issues below are dominated by **one High** (a published-post demotion bug in the review flow), several **Mediums** (missing `wp_unslash()` on 5 `$_POST` reads, a silently dead preset throttle, a broken per-provider "Test Connection" UI, `max_tokens` ignored for OpenAI, unbounded research polling, orphaned uploads on rate-limit rejection), and assorted Low/Nit items. One noteworthy structural quirk: the editorial-workflow guard and the research-post creation interact in a way that only works "by accident" (research posts are requested as `publish` and immediately reverted to `pending`).

---

## Findings — Prioritized

| # | Severity | Location | Issue | Fix |
|---|----------|----------|-------|-----|
| 1 | **High** | `class-ajax-handlers.php:182-188` | `run_review()` unconditionally sets `post_status => 'pending'` whenever score ≥ 80 and a `post_id` was sent. Reviewing an **already-published** article silently demotes it to pending. Any user with `edit_post` on the post (incl. authors on their own published posts) can trigger it. | Only transition when the current status is not already `publish` (e.g. `draft`/`auto-draft`), or skip auto-status entirely and let the workflow guard handle publish gating. |
| 2 | **Medium** | `class-ajax-handlers.php:102,103,164,200,214` | Missing `wp_unslash()` before sanitizing `$_POST` values (`sources`, `instructions`, `content`, `prompt`, `intent`). WordPress slashes `$_POST` on every request (`wp_magic_quotes`), so apostrophes/quotes arrive as `\'` and get baked into AI prompts, the stored `_research_prompt`, scorecard content and research post titles. Inconsistent with the correct `wp_unslash` pattern used for preset fields (line 108 etc.). The test harness doesn't emulate slashing, so tests don't catch it. | `sanitize_textarea_field( wp_unslash( $_POST['sources'] ) )` etc. (and `wp_kses_post( wp_unslash( ... ) )` for content). |
| 3 | **Medium** | `class-rate-limiter.php:51,92,96` + `class-ajax-handlers.php:339-353` | The preset-CRUD throttle (documented as 60 mutations/min, dedicated bucket) reuses `PressHub_AI_Rate_Limiter`, whose `is_enabled()` is the **global opt-in AI rate-limit option**. When `presshub_ai_rate_limit_enabled` is off (the default), the preset throttle is a complete no-op; when on, `record()` caps the counter at `configured_limit()` (the AI per-hour option, default 30) and `check()` compares against 60 — so the effective limit is `min(AI-limit, 60)` per 60 s, and if an admin raises the AI limit to e.g. 10,000/h the preset throttle becomes 10,000/min. The stated 60/min contract is never what runs. | Give the limiter an independent enable flag/constructor arg (or a dedicated `enabled` option) and pass the same `$limit`/`$window_seconds` to `record()` as to `check()`. |
| 4 | **Medium** | `assets/admin.js:21-23` + `class-settings.php:253-256` | The four "Test OpenAI/Anthropic/Gemini" buttons render with `data-provider`, but the JS POSTs only `action` + `nonce` — the `provider` param is never sent, so all four buttons test the **active** provider. The PHP side supports it (`test_connection($provider)`, `class-api-client.php:75-94`) — the P6 per-provider test feature is dead UI. | Send `provider: $btn.data('provider')` in the AJAX call and pass it through. |
| 5 | **Medium** | `class-api-client.php:372-379` | `max_tokens` per-provider setting is ignored for OpenAI (request body has `model`, `messages`, `temperature` only); only Anthropic sends it (line 448). Gemini sends neither. The P2 setting silently does nothing for 2 of 3 providers. | Add `'max_tokens' => $this->max_tokens` to the OpenAI body; add `maxOutputTokens` to Gemini's `generationConfig`. |
| 6 | **Medium** | `assets/sidebar.js:80-127` | Research polling `setInterval(…, 3000)` only clears on `completed`/`failed`/error. If WP-Cron never fires (documented low-traffic caveat) or the job is stuck `processing` (e.g. fatal in `presshub_ai_execute_research_job`), the browser polls forever, and intervals are never cleared on sidebar unmount. | Cap attempts (e.g. 30–60), clear interval on unmount (useEffect cleanup), and add a server-side stale-`processing` timeout. |
| 7 | **Medium** | `class-ajax-handlers.php:119-147` | Draft file uploads are moved into the uploads dir *before* `enforce_rate_limit()` (line 140). When the limiter rejects, `wp_send_json_error()` dies and the cleanup loop (145-147) never runs — orphaned files accumulate in uploads on every blocked attempt. | Enforce the rate limit before `wp_handle_upload`, or run cleanup in a `try/finally`/shutdown handler. |
| 8 | **Low** | `class-ajax-handlers.php:120-138` | No server-side file-type whitelist or size limit on draft uploads (only the client `accept` attribute). `wp_handle_upload` accepts anything under PHP's `upload_max_filesize`; an authenticated author can repeatedly upload huge files (each request moves a full copy into uploads, then deletes on success) → disk-exhaustion DoS vector; files can also be any type (PHP etc.) though they're transient and never executed. | Validate with `wp_check_filetype_and_ext` against `.pdf,.docx,.mp3,.mp4,.wav,.m4a`, enforce an explicit byte cap, and upload to a private temp dir. |
| 9 | **Low** | `class-ajax-handlers.php:254-258` + `class-workflow.php:29-47` | Research posts are inserted with `post_status => 'publish'`, but for non-editor users the workflow guard immediately reverts them to `pending` (no scorecard exists). It works, but by accident — every research request pays an extra nested `wp_update_post` + hook cascade, and the intent is obscured. | Insert research posts directly as `pending` (or a dedicated private status) so the workflow guard is never involved. |
| 10 | **Low** | `class-api-client.php:18-19`, `class-settings.php:569-588` | API keys stored plaintext in options (default autoload=yes). Standard WP practice, but: (a) options are loaded on every request incl. front-end; (b) the masked-field save logic makes it **impossible to clear a key via the UI** (empty value and `••••…` both map to the existing key), so a compromised key can't be revoked through settings. | `update_option(..., ['autoload' => false])` where feasible; add an explicit "Remove key" action. |
| 11 | **Low** | `class-api-client.php:178` | Imagen API key embedded in the endpoint URL query string (`?key=…`). Keys in URLs can leak via server/proxy logs, referrer headers and browser history. | Restrict the GC API key (IP/referrer restrictions) or move to Application Default Credentials / API-key header auth where supported. |
| 12 | **Low** | `class-api-client.php:404,460,509` etc. | Raw provider error messages (`$body['error']['message']`) are returned verbatim to the browser via `wp_send_json_error`. Mild info disclosure; provider errors can include account/quota details. | Log the raw message server-side; return a generic message to the client. |
| 13 | **Low** | `class-metaboxes.php:63-68` | `$scorecard['score']` / `$scorecard['feedback']` accessed without `is_array()`/`isset()` guards. If meta was written by an older version (string) or the LLM returned a non-string `feedback`, PHP 8 warnings and empty output. | `if ( is_array( $scorecard ) && isset( $scorecard['score'] ) )` before rendering. |
| 14 | **Low** | `class-research-cleanup.php:26-31`, `presshub-ai-editor.php` | No deactivation hook: the daily `presshub_ai_cleanup_research` cron and any pending `presshub_ai_do_research` single events survive plugin deactivation; no `uninstall.php` clears options/meta. | `register_deactivation_hook` → `wp_clear_scheduled_hook()` for both hooks. |
| 15 | **Low** | `class-research-cleanup.php:41-61` | Retention cron only deletes `completed`/`failed` logs; a log stuck in `processing` (fatal mid-run in `presshub_ai_execute_research_job`, e.g. line 132 unhandled exception path) is kept forever. | Include `processing` older than N days, or add a stale-processing sweep. |
| 16 | **Low** | `class-research-cleanup.php:18-21` | Docstring says retention is "configurable"; the value is hardcoded (30 days) with no option/setting anywhere. | Either add the option or fix the docstring. |
| 17 | **Low** | `class-api-client.php:466` | Gemini model names are interpolated into the URL as `models/<model>:generateContent`; an admin entering a full path like `models/gemini-1.5-pro` produces `models/models/…`. Sanitization allows it. | Normalize/strip a leading `models/` prefix in `resolve_model`. |
| 18 | **Nit** | `class-settings.php:608-613` | `sanitize_gcloud_project_id` allows underscores, which are invalid in GCP project IDs (only lowercase letters, digits, hyphens) — fails at the API, harmless locally. | Tighten regex to `[a-z0-9-]`. |
| 19 | **Nit** | `class-metaboxes.php:38-69`, `assets/sidebar.js` (all), `class-workflow.php` | Hardcoded UI strings without `__()`/i18n; PUC and sidebar strings too. Also `uniqid()` without `more_entropy` in sideload filenames. | Minor polish. |
| 20 | **Nit** | `assets/admin.js:84,123`, `assets/sidebar.js:72` | `alert('Error: ' + response.data)` — when `wp_send_json_error` receives a non-string (e.g. array), the user sees `[object Object]`. | `String(response.data)` / `.message`. |
| 21 | **Nit** | `class-rate-limiter.php:158` | Transient writes are non-atomic (read-modify-write), so two concurrent requests from one user can both pass `check()` (TOCTOU). Acceptable for a cost-control feature; worth a comment or a `$wpdb`-based counter if strictness is ever needed. | Document the limitation; optionally use `set_transient` with an atomic increment via `$wpdb`. |
| 22 | **Nit** | `class-workflow.php:33` | `current_user_can('edit_others_posts')` reflects the *current request's* user. Cron/REST-triggered publishes (user 0) are treated as non-editors and reverted — may surprise other plugins/CLI flows. | Consider checking `$post->post_author` vs. current user, or a filter. |

---

## Detailed Findings

### HIGH-1 — `run_review` demotes published posts to `pending` (correctness/data integrity)

`presshub-ai-editor/includes/class-ajax-handlers.php:182-188`:

```php
if ( $post_id ) {
    update_post_meta( $post_id, '_presshub_ai_scorecard', $scorecard );
    if ( isset( $scorecard['score'] ) && intval( $scorecard['score'] ) >= 80 ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
    }
}
```

There is no status check. An editor (or author with `edit_post`) running "Run AI Editorial Review" on a **published** article with a good score silently unpublishes it. The review feature should only move *unpublished* drafts to pending. The workflow guard (`class-workflow.php:29-47`) already enforces the "no publish without ≥80 scorecard" rule, so the demotion-on-review isn't needed for already-published posts at all.

### MEDIUM-2 — Missing `wp_unslash()` on five `$_POST` reads

`class-ajax-handlers.php` lines 102, 103, 164, 200, 214. WP's `wp_magic_quotes()` slashes `$_POST` for every request (including admin-ajax). Sanitizers (`sanitize_textarea_field`, `wp_kses_post`) do **not** unslash. Result: user text containing apostrophes/quotes (`it's`, `don't`) is sent to the AI providers and stored in `_research_prompt`/post titles with literal backslashes — visible corruption in generated drafts and research titles, and inconsistent with the `wp_unslash` pattern used on lines 108/240/405 etc. The test harness (tests/wordpress-stubs.php) does not emulate slashing, so the suite is green.

### MEDIUM-3 — Preset throttle is dead by default and mis-bucketed when enabled

Design (docs/brainstorms/2026-08-15-per-author-instructions.md §6.5) calls for a dedicated coarse throttle on the 5 preset CRUD endpoints that does **not** depend on the AI rate limiter. Implementation reuses `PressHub_AI_Rate_Limiter`:

- `check()`/`record()` both early-return when `presshub_ai_rate_limit_enabled` is falsy (`class-rate-limiter.php:51,92`) — the default. So the preset throttle is a no-op unless the admin has opted into AI rate limiting.
- `record()` caps the counter at `configured_limit()` = option `presshub_ai_rate_limit_per_hour` (default 30) (`class-rate-limiter.php:96,110`), while `enforce_preset_throttle()` checks against 60 (`class-ajax-handlers.php:341`). Effective limit = `min(AI per-hour limit, 60)` per 60-second window, and the window is whatever `check()` is passed (60) vs. what `record()` anchors from the stored window (option, default 3600) — the two can disagree when the option changes mid-window.

Not a security hole (nonce + caps are the real controls), but the documented contract is never honored and the throttle disappears exactly when it's most needed.

### MEDIUM-4 — Per-provider Test Connection buttons are inert

`class-settings.php:253-256` renders 4 buttons with `data-provider="openai|anthropic|gemini"`. `assets/admin.js:14-37` posts only `{action, nonce}`. `test_api_connection()` (`class-ajax-handlers.php:71-86`) accepts no provider input, and `test_connection($provider)` (`class-api-client.php:75-94`) — which correctly re-snapshots per-provider config — is only ever called with its default. The P6 feature "verify each provider separately" is non-functional; all buttons test the active provider.

### MEDIUM-5 — `max_tokens` never sent to OpenAI (or Gemini)

`call_openai()` body (`class-api-client.php:372-379`) contains `model`, `messages`, `temperature` — no `max_tokens`. `call_gemini()` (`class-api-client.php:480-495`) sets only `temperature` (+ `responseMimeType` in JSON mode). Only `call_anthropic()` (line 448) honors `presshub_ai_max_tokens_<provider>`. The P2 per-provider max-tokens setting is dead for OpenAI and Gemini; Gemini also has no output-token cap at all.

### MEDIUM-6 — Research polling never terminates

`assets/sidebar.js:80-127`: `startPollingResearch` clears the interval only on `completed`/`failed`. Statuses `pending`/`processing` poll every 3 s indefinitely. Per the plugin's own documentation (main file lines 87-99), WP-Cron may not fire on low-traffic sites, so `pending` can persist for hours — the sidebar keeps polling (and firing `check_research_status` AJAX) forever. Additionally, `setInterval` handles are never cleared when the plugin sidebar unmounts (navigating away), leaving orphaned timers.

### MEDIUM-7 — Uploaded files orphaned when rate limit blocks

`generate_draft()` uploads files first (lines 119-138), then `enforce_rate_limit()` (line 140) which `wp_send_json_error()`-dies on block. The cleanup loop (145-147) is after it, so every rate-limited attempt with attached files leaves them in `wp_uploads`. Same for fatal errors mid-request. Repeated attempts = disk accumulation.

### LOW-8 — Upload validation: no type whitelist, no size cap

`class-ajax-handlers.php:120-138` passes `$_FILES['files']` straight to `wp_handle_upload($file, ['test_form' => false])`. No `wp_check_filetype_and_ext` against the declared accept list (`.pdf,.docx,.mp3,.mp4`), no per-file size limit beyond PHP ini. An author can upload arbitrarily large files of any type; they're deleted after a successful call, but a large file + slow AI call means the full copy sits in uploads for the request duration, and the MEDIUM-7 orphan path makes it permanent. Authenticated disk-fill DoS, low likelihood but real.

### LOW-9 — Research posts: `publish` requested, `pending` delivered (by accident)

`class-ajax-handlers.php:257` inserts research posts with `post_status => 'publish'`. `wp_insert_post` fires `transition_post_status` → `PressHub_AI_Workflow::enforce_editorial_workflow()` sees `new=publish`, finds no scorecard, and (for non-`edit_others_posts` users) reverts to `pending` (`class-workflow.php:29-47`). Works, but: (a) it's coupling between two features that neither side knows about; (b) every research request pays a nested update + hook cascade; (c) if the guard ever changes, research posts would become publicly listed (the CPT is `public => false`, so impact is limited).

### LOW-10 — Secret handling

- Options are stored plaintext and autoloaded (`get_option` on every request). Standard WP practice, but consider `autoload => false`.
- `sanitize_secret` (`class-settings.php:569-588`): empty **or** mask-containing values map to the existing key. There is no way to delete/rotate a key by emptying the field — if a key is compromised the only path is `wp option delete` or direct DB edit. Consider a dedicated "remove" control.
- The GitHub token option is read on every page load by the update checker (`presshub-ai-editor.php:43-46`) — fine, but same storage caveat.

### LOW-11 — API key in URL query string (Imagen)

`class-api-client.php:178` builds `https://<region>-aiplatform.googleapis.com/v1/projects/...:predict?key=<google_cloud_api_key>`. Keys in URLs appear in proxy/access logs, browser history, and referrers. The `key=` legacy auth on `-aiplatform` is also being deprecated by Google. Recommend restricting the key and/or migrating to a header/service-account auth.

### LOW-12 — Provider errors echoed to clients

`class-api-client.php:404, 460, 509` return `$body['error']['message']` as `WP_Error`, which AJAX handlers forward verbatim (`wp_send_json_error( $result->get_error_message() )`). Provider error payloads can include org/quota/request details. Cosmetic in practice, but genericize for defense.

### LOW-13 — Scorecard meta shape not guarded at render

`class-metaboxes.php:63-68` indexes `$scorecard['score']`/`$scorecard['feedback']` directly after `if ( $scorecard )`. `get_post_meta` returns whatever was stored; a string (older format or manual edit) or an array without those keys produces PHP 8 warnings and empty UI. Also `esc_html( $scorecard['feedback'] )` warns if `feedback` is an array (LLM can return non-string JSON).

### LOW-14 — Cron hygiene on deactivation / uninstall

`PressHub_AI_Research_Cleanup::register()` (`class-research-cleanup.php:26-31`) schedules the daily cron with no `register_deactivation_hook` counterpart; `presshub_ai_do_research` single events also linger. No `uninstall.php` cleans `presshub_ai_*` options/user-meta. Leftover cron entries fire harmless no-ops, but accumulate across installs.

### LOW-15 — Stuck `processing` research logs never cleaned

`class-research-cleanup.php:52-58` meta-query only selects `['completed','failed']`. A job that fatals mid-run (e.g. provider call throws, or PHP timeout) leaves `_research_status = 'processing'` forever; the post is invisible in admin (`show_ui => false`) and unbounded in count (each request creates one). The daily cron caps at 200 posts per run, so the worst case is a slow-growing invisible table. Combined with MEDIUM-6, the UI also polls those forever.

### LOW-16 — Retention "configurable" but hardcoded

Docstring (`class-research-cleanup.php:18-21`) says "older than a configurable number of days" — no option exists; `run()` defaults to 30 and the cron passes nothing.

### LOW-17 — Gemini model path normalization

`class-api-client.php:466`: `…/models/' . $this->model . ':generateContent`. The settings field is free text; pasting the full model path (as Google docs commonly show it) yields `models/models/gemini-…` and a 404. Trim a leading `models/`.

### Nits (18-22)

- `sanitize_gcloud_project_id` allows `_` (invalid in GCP project IDs).
- No i18n on metabox/sidebar/admin-preset strings; `uniqid()` without entropy.
- `alert('[object Object]')` when `response.data` is an array.
- Rate limiter check/record is a non-atomic read-modify-write (TOCTOU) — fine for cost control, but two concurrent requests can both slip through.
- `enforce_editorial_workflow` judges `current_user_can('edit_others_posts')` with the current request user — headless/cron publishes by authors get reverted; verify this matches intent.

---

## Security Checklist Summary

| Check | Result |
|---|---|
| Nonce on every AJAX handler (10/10) | ✅ `check_ajax_referer('presshub_ai_nonce','nonce')` everywhere |
| Cap checks on every handler | ✅ `edit_posts`/`edit_post`/`manage_options`; per-object `edit_post` on post/research ids |
| Admin gate on paid media (image/report) | ✅ Pre-intent check (`class-ajax-handlers.php:215`) + post-classifier defense-in-depth (line 232) |
| XSS in PHP render paths | ✅ `esc_html`/`esc_attr`/`esc_textarea`/`wp_json_encode`; settings uses esc helpers; no raw `echo $var` found |
| XSS in JS render paths | ✅ React text escaping; `presshubEsc` helper for injected HTML (`admin.js:5-12,119`); research HTML kses'd at write (`presshub-ai-editor.php:142`) |
| CSRF on settings form | ✅ `settings_fields()` |
| SQL injection | ✅ No direct SQL; `get_posts` with meta/date queries only |
| Option injection | ✅ Sanitize callback on every registered option; provider whitelist; length caps |
| Secret masking | ✅ Masked key display (last-4) in settings; but see LOW-10 (no key-clear path, plaintext autoloaded options) |
| File uploads | ⚠️ No type whitelist/size cap (LOW-8); orphan risk on rate-limit block (MEDIUM-7) |
| Prompt injection | ⚠️ Inherent to feature: preset text is appended to the *system* prompt (author-controlled content in the trusted prompt segment) — UI copy claims presets "cannot replace" the base prompt, which is not technically enforceable; user sources/draft content flow into the user prompt unescaped (standard LLM-app risk, worth a documented caveat) |
| Cron double-scheduling | ✅ `wp_next_scheduled` guard; single events deduped by WP |
| Backward compat: `generate_draft` 4-arg | ✅ `$uploaded_files = []`, `$preset_slug = ''` defaults; legacy 3-arg callers unaffected |
| Backward compat: settings migration | ✅ Idempotent `migrate_legacy_model()` (flag-gated), `resolve_model()` falls back to legacy `presshub_ai_model`; masked-key save preserves untouched keys |
| Rate limiter | ✅ Opt-in, default-off; per-user bucket; WP_Error messaging; ⚠️ preset-throttle coupling (MEDIUM-3), TOCTOU (nit) |

---

## What Could NOT Be Verified

1. **No live WordPress install** — review is static + standalone-harness based. The `transition_post_status` behavior on `wp_insert_post` (research `publish` → `pending` revert, MEDIUM/LOW-9) is inferred from WP core semantics, not executed; the workflow tests exercise the guard directly, not through a real `wp_insert_post`.
2. **`$_POST` slashing** (MEDIUM-2) relies on standard `wp_magic_quotes` behavior; the unit-test stubs don't emulate it, so it's unverified in the harness.
3. **Vendored PUC library** (`includes/plugin-update-checker/`) reviewed only at integration points (slug, branch, auth token wiring).
4. **Jest/tsc suites** not re-run locally (node_modules present; CI workflow covers them). `mcp-scorecard.ts` / tests reference `apiKey: string` — confirmed fine via byte-level check (a display-layer redaction initially obscured it).
5. **Provider API behavior** (Imagen `?key=` auth, Gemini `models/` URL shape, OpenAI `max_tokens` acceptance) not exercised against live endpoints.
6. **Multi-site/network-activated** behavior and `wp_schedule_event` under object-cache backends not assessed.
