# Brainstorm — PressHub AI Editor Settings Improvements

**Date:** 2026-08-15
**Author:** Brainstorm session (subagent, no source changes)
**Scope:** `presshub-ai-editor/includes/class-settings.php` and the surrounding
options surface that `class-api-client.php`, `class-ajax-handlers.php`, and
`class-rate-limiter.php` consume.
**Out of scope:** `presshub-workflow/` (sibling agent owns it). No code
modifications in this doc — proposals only.

---

## 0. Executive Summary

The current settings page (`class-settings.php`, 138 lines) is a single,
flat form that mixes five different concerns on one screen — provider,
model, secrets, Google Cloud media config, and rate limiting — with no
sections, no per-field capability gating, no masked-display UX, and no
sanitization callbacks. The data model is similarly one-dimensional: one
global `presshub_ai_model` (default `gpt-4o`) is read by `class-api-client.php`
regardless of which provider is active, which means the model field becomes
a foot-gun whenever a user switches providers (e.g. selecting Gemini while
still showing `gpt-4o`).

The biggest, highest-leverage improvements, in priority order:

1. **Section the settings page** (General, Providers, Media, Rate Limits,
   Instructions) with the WP Settings API and `add_settings_section()` —
   tiny effort, immediate UX win. **S effort.**
2. **Per-provider model + tuning configuration** (`presshub_ai_model_openai`,
   `…_anthropic`, `…_gemini`, plus per-provider `temperature`,
   `max_tokens`, `timeout_seconds`). Fixes the silent-default foot-gun and
   is the prerequisite for any provider-specific work. **M effort.**
3. **Capability-gate every admin endpoint** (`current_user_can('manage_options')`
   on `test_api_connection`, plus a deny-by-default `presshub_ai_settings_cap`
   filter). Matches the existing `PressHub_AI_Ajax_Handlers::test_api_connection`
   gate (`class-ajax-handlers.php:65`) and extends it to the rest of the
   AJAX surface. **S effort.**
4. **Masked-display + provider-aware test-connection** (a "Test Connection"
   button per provider, displays `••••abcd` style masked keys). **S effort.**
5. **Sanitization callbacks on every registered option** (currently `register_setting`
   is called with only the option name — no `$args['sanitize_callback']`).
   Pairs naturally with capability gating. **S effort.**
6. **Per-provider extras** (Anthropic version header, OpenAI org header,
   Gemini region / safety settings, Gemini Imagen region override). **S/M
   effort** depending on how deep we go.
7. **System instructions field on the settings page** (plugin-level default
   instructions stored in WP option `presshub_ai_default_instructions`,
   paired with per-author user meta from the sibling brainstorm). **M
   effort** because it requires meta plumbing + filter ordering.
8. **Settings export/import + backup-restore** (a Tools submenu that emits
   JSON of all `presshub_ai_*` options, redacted of secret fields). **M
   effort.**
9. **i18n sweep** on the settings page strings. **S effort** but
   low-priority — every new string we add should just be wrapped in
   `__()`/`esc_html__()` from day one, so the right move is *enforcement*,
   not a separate sprint.

The remaining items (rate-limit knob UX, defaults documentation,
`Settings → Reading`-style help blurb) are folded into the sectioning
proposal rather than being standalone work.

---

## 1. Current state — what we are improving

### 1.1 Surface inventory

`presshub-ai-editor/includes/class-settings.php`:

| Line | Option | Purpose |
|------|--------|---------|
| 32 | `presshub_ai_provider` | openai / anthropic / gemini (select) |
| 33 | `presshub_ai_model` | Single free-text model name, default `gpt-4o` |
| 34 | `presshub_ai_api_key` | Bearer / x-api-key / Gemini query-string key |
| 35 | `presshub_ai_google_cloud_api_key` | Imagen + TTS key |
| 36 | `presshub_ai_gcloud_project_id` | Vertex AI project ID, default `presshub-ai` |
| 37 | `presshub_ai_github_token` | Optional PAT for plugin updates |
| 40 | `presshub_ai_rate_limit_enabled` | bool, opt-in |
| 41 | `presshub_ai_rate_limit_per_hour` | int, default 30 |
| 42 | `presshub_ai_rate_limit_window_seconds` | int, default 3600 |

All are `register_setting()`'d on the same `'presshub_ai_options'` group
(lines 32–42). The renderer is a single `render_settings_page()` (lines
45–137) with no `settings_fields()`-style sectioning, no description
callbacks, and no per-field sanitization.

### 1.2 Consumers

`class-api-client.php`:

- `__construct` (lines 10–15) reads `presshub_ai_api_key`,
  `presshub_ai_google_cloud_api_key`, `presshub_ai_provider`, and
  `presshub_ai_model`. The model is global — **never** switched when
  `presshub_ai_provider` flips.
- `call_anthropic` (line 333) hard-codes `anthropic-version:
  '2023-06-01'` and `max_tokens: 2000` (line 342) — both belong in
  settings.
- `call_openai` (lines 286–293) hard-codes `timeout => 60` and never
  sends the `OpenAI-Organization` header even though OpenAI's docs make
  that a supported knob for multi-org accounts.
- `call_gemini` (lines 358–404) hard-codes `timeout => 90` and the
  endpoint URL is fixed to `generativelanguage.googleapis.com` (line
  359). No safety settings, no region, no system-instruction override.

`class-ajax-handlers.php`:

- `test_api_connection` (lines 62–77) already gates on
  `current_user_can('manage_options')` (line 65) — good. Other AJAX
  entry points (`generate_draft`, `run_review`, `handle_chat_routing`)
  only require `edit_posts` (line 82) and never touch the settings cap.
  The settings-page `render_settings_page()` itself has no capability
  check beyond what `add_options_page` provides.

`class-rate-limiter.php`:

- `is_enabled()` / `configured_limit()` / `window_seconds_for_key()` all
  read from options — settings improvements that add new keys here only
  need to add new `get_option()` calls.

### 1.3 Gaps

- No sectioning: providers, media, and rate limits are visually
  separated only by an `<h2>` (line 99) — no `do_settings_sections`
  call, no `add_settings_field` registration. This means field IDs are
  not portable (a DOM-level selector like `#presshub_ai_api_key` works,
  but adding a help tab or `get_settings_help` integration is awkward).
- No sanitization callbacks: `register_setting( 'presshub_ai_options',
  'presshub_ai_api_key' )` (line 34) accepts whatever the form posts. A
  malicious admin (or XSS in a compromised editor account on a
  multi-site) can write arbitrary bytes into `wp_options`.
- No capability filter: the cap check in `test_api_connection` is hard
  coded to `manage_options` (line 65). Sites that want to delegate "edit
  AI settings" to a custom role cannot without a fork.
- No model defaults per provider: switching provider in the dropdown
  leaves `gpt-4o` selected even though that name is meaningless to
  Gemini or Claude. `class-api-client.php:14` reads the option
  unconditionally.
- API keys stored in plaintext `<input type="password">` fields with
  no masking UX (no "show/hide" toggle, no prefix-only display). Stolen
  DB dumps leak keys in cleartext form.
- No "Test Connection" per provider: the existing button
  (`render_settings_page` line 132) hits the *active* provider only.
  If a user has set an OpenAI key but currently has Anthropic selected,
  clicking Test Connection reports "missing key" even though the
  OpenAI key is fine.
- No export/import: when migrating between staging/production, the
  only path is hand-typing the keys again.
- No system instructions field: the sibling brainstorm will add a
  per-author instruction system; the settings page needs a plugin-level
  default that the API client / metabox can fall back to.

---

## 2. Prioritized Proposals

Effort key: **S** = ≤2 hours, **M** = half-day, **L** = >1 day.
Value key: ★ low → ★★★★★ high.

### P1 — Sectioned settings page (Settings API) ★★★★ / **S**

**What.** Move `render_settings_page` to use `add_settings_section()`,
`add_settings_field()`, and `do_settings_sections()`. Sections:

| Section ID | Title | Fields |
|-----------|-------|--------|
| `presshub_ai_general` | General | `presshub_ai_provider` only |
| `presshub_ai_providers` | Providers | All per-provider fields (see P2/P4/P6) |
| `presshub_ai_media` | Media (Google Cloud) | `…_google_cloud_api_key`, `…_gcloud_project_id`, Gemini Imagen region |
| `presshub_ai_rate_limits` | Rate Limits | `…_rate_limit_enabled`, `…_rate_limit_per_hour`, `…_rate_limit_window_seconds` |
| `presshub_ai_instructions` | System Instructions | `presshub_ai_default_instructions` (P7) |

**Value.** Standard WordPress settings-page UX means screens like
Settings → Reading, the help tab system, and Settings API validation
all work for free. Removes ~40 lines of hand-rolled `<table class="form-table">`
HTML.

**Effort.** S — pure refactor; the existing fields stay where they are,
just relocated into section callbacks.

**Risks.**

- Custom JS in `assets/admin.js` may reference field IDs by
  `presshub_ai_provider` etc. — those IDs don't change, so this is a
  no-op for JS.
- The Settings API wires up `register_setting` differently for fields
  inside sections; we must call `register_setting` **before**
  `add_settings_section` (which is the order WordPress expects, and what
  the existing code already does on lines 32–42, then
  `admin_init → render_settings_page`).

**TDD test plan.**

- New file `presshub-ai-editor/tests/SettingsSectionsTest.php`.
- Stub additions needed in `wordpress-stubs.php`:
  - `add_settings_section( $id, $title, $cb, $page )` — appends to
    `$GLOBALS['SECTIONS'][$page][]`.
  - `add_settings_field( $id, $title, $cb, $page, $section, $args )` —
    appends to `$GLOBALS['FIELDS'][$page][$section][]`.
  - `do_settings_sections( $page )` — records call; render order is
    asserted from `$GLOBALS['SECTIONS']` / `$GLOBALS['FIELDS']`.
- Cases:
  1. After `register_settings()`, the set of registered option names
     matches the pre-sectioning list **plus** the new fields added by
     P2/P6/P7 (proves the migration doesn't drop options).
  2. `render_settings_page()` invokes `do_settings_sections('presshub-ai')`
     exactly five times — one per section.
  3. Field `presshub_ai_provider` is registered to section
     `presshub_ai_general`; `presshub_ai_rate_limit_*` to
     `presshub_ai_rate_limits`; `presshub_ai_default_instructions` to
     `presshub_ai_instructions`.
  4. Capability check (P3) short-circuits before any section callback
     runs when `current_user_can('manage_options')` is false — assert
     `$GLOBALS['SECTIONS']` is empty.

---

### P2 — Per-provider model + tuning configuration ★★★★★ / **M**

**What.** Replace the single `presshub_ai_model` option with three:

- `presshub_ai_model_openai` — default `gpt-4o-mini` (smaller, cheaper
  default; admins can opt up).
- `presshub_ai_model_anthropic` — default `claude-3-5-sonnet-20241022`.
- `presshub_ai_model_gemini` — default `gemini-1.5-pro-latest`.

Add four per-provider tuning options, all with sensible defaults:

| Option | Type | Default | Used by |
|--------|------|---------|---------|
| `presshub_ai_temperature` | float 0–2 | `0.7` | All providers (passed through `call_*`) |
| `presshub_ai_max_tokens` | int 1–8192 | `2000` | All providers |
| `presshub_ai_timeout_seconds` | int 5–300 | `60` (OpenAI/Anthropic), `90` (Gemini — overridable per provider by `presshub_ai_timeout_seconds_gemini`) | All providers |
| `presshub_ai_json_mode_default` | bool | `1` | Scorecard always, draft optional |

Each option's rendering:

- Model: `<select>` populated from a hard-coded list of known-good
  models **plus** a "Custom…" option that falls back to the existing
  free-text input. This kills the "type a typo and get a 404" failure
  mode.
- Temperature: `<input type="number" min="0" max="2" step="0.1">`.
- Max tokens: `<input type="number" min="1" max="8192">`.
- Timeout: `<input type="number" min="5" max="300">`.

Migration: on plugin upgrade, read the legacy `presshub_ai_model` and
write it into the appropriate per-provider key based on
`presshub_ai_provider`. After migration, delete the legacy option.
Store the migration step in a one-shot `presshub_ai_migrated_models`
option so it never re-runs.

**Value.** Fixes the silent-default foot-gun (today: switching to
Anthropic silently sends `gpt-4o` to Anthropic's API), and gives
admins the per-provider knobs every other AI plugin already has.

**Effort.** M — touches `class-api-client.php:14` (model read),
`class-api-client.php:278` (model used in `call_openai`), the three
`call_*` methods' hard-coded timeouts (lines 292, 344, 392), and the
max_tokens at line 342. Plus migration code in the settings class.

**Risks.**

- An admin who saved a custom `presshub_ai_model` value on the old
  page and then upgrades must see their value preserved on the right
  provider card. Migration test must assert this.
- `register_setting` adds new options; autoload behavior should stay
  the default (`yes`) — model strings are short and cheap to cache.
- Test fixtures: `PressHub_AI_API_Client::__construct` snapshots
  options at construction time, so any test that wants to change the
  model between calls must rebuild the client (already the pattern in
  `PromptFiltersTest.php:21`).

**TDD test plan.**

- New file `presshub-ai-editor/tests/PerProviderModelTest.php`.
- Stubs needed: none — `get_option`/`update_option` already work.
- Cases:
  1. With `provider=openai`, `model_openai=gpt-4o`, the outgoing
     OpenAI request body contains `"model":"gpt-4o"`.
  2. With `provider=anthropic` and the legacy global `model=gpt-4o`,
     migration runs on `plugins_loaded` and writes
     `model_anthropic=gpt-4o` **only** if `model_anthropic` is empty
     (so the user's accidental legacy value is preserved verbatim
     under the new key, not silently overwritten to the Anthropic
     default).
  3. With `provider=anthropic`, `model_anthropic=claude-3-5-sonnet`,
     the Anthropic request body contains that model string.
  4. `temperature=0.2`, `max_tokens=500`, `timeout=45` are passed
     into the OpenAI request's `temperature`, `max_tokens`, and
     `wp_remote_post` `timeout` arg respectively.
  5. Validation: out-of-range temperature (e.g. `5.0`) is rejected by
     the sanitize callback (P5) and the option is saved as `0.7`.

---

### P3 — Capability gating + capability filter ★★★★ / **S**

**What.** Add a `current_user_can( 'manage_options' )` guard at the top
of `PressHub_AI_Settings::render_settings_page` (currently: only the
`add_options_page` capability arg guards menu rendering, but the page
itself doesn't re-check). Plus a filter:

```php
$cap = apply_filters( 'presshub_ai_settings_cap', 'manage_options' );
if ( ! current_user_can( $cap ) ) {
    wp_die( esc_html__( 'You do not have permission to view PressHub AI settings.', 'presshub-ai-editor' ) );
}
```

Mirror the filter on the `test_api_connection` AJAX action (which today
hard-codes `manage_options` at `class-ajax-handlers.php:65`).

**Value.** Sites with custom roles (e.g. an "AI Editor" custom role)
gain a single hook for delegation. Multi-site super-admins keep their
existing capability. Without this, P2/P7's richer settings expose more
knobs to the same flat `manage_options` gate, which is fine for most
sites but locks out custom roles.

**Effort.** S — three lines of guard code, one filter, plus applying
it in two places.

**Risks.**

- Plugin update path: existing deployments already rely on
  `manage_options`; the filter preserves that behaviour by default.
- AJAX test: `PressHub_AI_Ajax_Handlers::__construct` registers
  handlers via `add_action`. The cap check fires only when the handler
  is called, so existing AJAX tests don't break — they just need a new
  cap check.

**TDD test plan.**

- New file `presshub-ai-editor/tests/SettingsCapabilityTest.php`.
- Cases:
  1. With `CURRENT_USER_CAPS = []` and no filter, `render_settings_page`
     short-circuits without writing any HTML (assert no
     `<form method="post">` substring in captured output).
  2. With `CURRENT_USER_CAPS = ['manage_options']`,
     `render_settings_page` emits the form.
  3. With `CURRENT_USER_CAPS = ['manage_options']` and a filter
     `add_filter('presshub_ai_settings_cap', fn() => 'edit_others_posts')`
     plus `CURRENT_USER_CAPS = ['edit_others_posts']`, the page renders.
  4. With `CURRENT_USER_CAPS = ['manage_options']` and the same filter
     plus `CURRENT_USER_CAPS = ['edit_posts']` only, the page rejects.

---

### P4 — Masked display + per-provider "Test Connection" ★★★★ / **S**

**What.**

- Render API-key inputs as `type="password"` (already done) plus a
  "Show" toggle button that flips to `type="text"`. Same UX as
  WooCommerce's REST API keys screen.
- Add a "Test Connection" button **per provider**: one each for
  OpenAI, Anthropic, Gemini, Google Cloud. Today's single button at
  line 132 only tests the active provider, which makes it impossible
  to verify a key without first switching providers.
- The Google Cloud test verifies the Vertex AI Imagen endpoint with a
  zero-shot `sampleCount: 1` call; on success, display
  `Project: <project_id>`; on failure, surface the API error verbatim.
- For each provider, display the masked key on save: e.g. `••••abcd`
  where `abcd` is the last 4 characters of the saved value. Provide
  a "Replace key" mode that clears the saved value when the field is
  emptied on save.

**Value.** Cuts the "did I paste the right key?" support question in
half, and turns the rate-limit-test workflow (toggle provider, click
Test, switch back) into one click.

**Effort.** S — pure UI work plus a 4th AJAX handler `test_connection_provider`
that takes a `provider` argument. The existing
`PressHub_AI_API_Client::test_connection` (lines 17–24) needs a
`$provider` parameter and `$api_key` parameter to test the non-active
provider.

**Risks.**

- API-key masking can be defeated by an attacker with DB read access
  anyway, so we don't claim a security win — only a UX win.
- Per-provider test means we'll issue real API calls when admins click
  "Test". Add a rate-limit bucket for the test endpoint so admins
  can't accidentally hammer their own provider.

**TDD test plan.**

- Extend `AjaxIntegrationRateLimitTest.php` with a 4th handler case
  (`test_api_provider`).
- New file `presshub-ai-editor/tests/MaskedKeyDisplayTest.php`.
- Cases:
  1. Saved `presshub_ai_api_key = "sk-abcdef1234567890"`; rendered
     placeholder is `••••7890`.
  2. Saving an empty `presshub_ai_api_key` clears the option (the
     "Replace key" affordance).
  3. `test_api_connection` with `provider=openai` uses
     `presshub_ai_api_key`; with `provider=gemini` uses the same key
     but expects the Gemini URL pattern in `wp_remote_post`.
  4. Per-provider test issues exactly one HTTP request regardless of
     which provider is currently selected (capture filter asserts
     URL host matches the requested provider).

---

### P5 — Sanitization callbacks on every option ★★★★ / **S**

**What.** Today, `register_setting( 'presshub_ai_options',
'presshub_ai_api_key' )` (line 34) has no `$args['sanitize_callback']`.
This means whatever is POSTed lands in `wp_options` verbatim. Add:

| Option | Sanitize callback | Result |
|--------|-------------------|--------|
| `presshub_ai_api_key` | `sanitize_text_field` + length cap 1–512 | Strips HTML, rejects empty / overlong |
| `presshub_ai_google_cloud_api_key` | same | |
| `presshub_ai_github_token` | same | |
| `presshub_ai_model_*` | `sanitize_text_field` + allowlist check | Unknown model → fallback to provider default + WP error notice |
| `presshub_ai_provider` | `sanitize_text_field` + `in_array(['openai','anthropic','gemini'])` | Invalid → `'openai'` |
| `presshub_ai_gcloud_project_id` | `sanitize_key` (lowercase, dashes) | Invalid → `'presshub-ai'` |
| `presshub_ai_rate_limit_per_hour` | `absint` clamped to [1, 10000] | |
| `presshub_ai_rate_limit_window_seconds` | `absint` clamped to [1, 86400] | |
| `presshub_ai_rate_limit_enabled` | rest_sanitize_boolean | |
| `presshub_ai_temperature` | float clamp [0.0, 2.0] | |
| `presshub_ai_max_tokens` | absint clamp [1, 8192] | |
| `presshub_ai_timeout_seconds` | absint clamp [5, 300] | |
| `presshub_ai_default_instructions` | `sanitize_textarea_field` + length cap 0–10000 | (P7) |

For non-secret options, also add `show_in_rest => false` and
`autoload => 'yes'` (model strings are short, autoload is fine).

For API keys specifically: if the POSTed value is **identical** to the
existing saved value, the sanitize callback returns the existing
unchanged value — this lets an admin save the rest of the form without
overwriting a key they didn't touch (today: re-saving with a blank
key field wipes the saved key because `<input type="password"
value="">` always submits empty).

**Value.** Closes a small but real sanitization gap. Also gives admins
a less surprising "I didn't change the key, why is the field blank?"
experience.

**Effort.** S — one new method per option type, wired into the
existing `register_setting` calls.

**Risks.**

- The "key unchanged → preserve" trick must NOT preserve an old empty
  value (i.e. if no key was saved, posting blank should stay blank).
  Test case 3 below nails this down.
- Showing WP settings errors (via `add_settings_error`) for invalid
  models is fine, but the existing settings page doesn't currently
  call `settings_errors()` — add it.

**TDD test plan.**

- New file `presshub-ai-editor/tests/SettingsSanitizationTest.php`.
- Stub additions needed: `register_setting` should record the
  `$args['sanitize_callback']` so tests can invoke it directly via
  `$GLOBALS['SANITIZE_CALLBACKS'][$option_name]`.
- Cases:
  1. Sanitizing `presshub_ai_api_key = '<script>alert(1)</script>'`
     returns the literal HTML stripped text.
  2. Sanitizing `presshub_ai_provider = 'foo'` returns `'openai'`.
  3. Sanitizing `presshub_ai_api_key = ''` when the saved value is
     `'sk-existing'` returns `'sk-existing'` (preserve).
  4. Sanitizing `presshub_ai_api_key = ''` when no saved value exists
     returns `''` (no preservation of non-existent value).
  5. Sanitizing `presshub_ai_temperature = 5.0` returns `0.7` and
     surfaces a settings error.
  6. Sanitizing `presshub_ai_default_instructions = '<script>foo</script>'`
     (P7) returns `'foo'`.

---

### P6 — Provider-specific settings ★★★ / **S**

**What.** Add the knobs that the providers actually expose, but keep
the surface small.

- **OpenAI**: `presshub_ai_openai_org` (optional text — sent as the
  `OpenAI-Organization` header; only relevant for multi-org accounts).
- **Anthropic**: `presshub_ai_anthropic_version` (default
  `'2023-06-01'`; admins stuck on a deprecated version can pin a
  newer one). Hard-coded at `class-api-client.php:333`.
- **Gemini**: `presshub_ai_gemini_region` (optional; default empty
  uses `generativelanguage.googleapis.com`; if set to e.g.
  `europe-west4`, the client rewrites the URL to use the regional
  endpoint and adds the matching location header). Also:
  `presshub_ai_gemini_safety_settings` — JSON-encoded array of the 4
  Gemini safety categories with `BLOCK_NONE`/`BLOCK_LOW_AND_ABOVE`
  etc. (rendered as 4 selects; default `BLOCK_MEDIUM_AND_ABOVE`).
- **Google Cloud (Imagen/TTS)**: `presshub_ai_gcloud_region` (default
  `us-central1`; today hard-coded at
  `class-api-client.php:90`). For European tenants who want
  `europe-west4`.

**Value.** Small per-provider polish; biggest win is the Anthropic
version knob (versions rotate yearly and a hard-coded string ages
fast) and the Imagen region (European customers are blocked from
`us-central1` by data-residency policy).

**Effort.** S — all four fields are <30 lines of HTML and the
provider-side plumbing is small (the Anthropic version is a one-line
header change).

**Risks.**

- The Gemini region feature adds a URL-rewrite branch in
  `call_gemini`. Keep the existing URL as the default path so behaviour
  is unchanged when the option is empty.
- Safety settings are JSON-encoded — must use `wp_json_encode` on the
  way out and a JSON-decode + re-encode sanitiser on the way in to
  guard against arbitrary shape (e.g. `{ "category": "HARM_CATEGORY_<script>" }`).

**TDD test plan.**

- Extend `PerProviderModelTest.php` with three extra cases (or new
  file `ProviderExtrasTest.php`).
- Cases:
  1. `openai_org = 'org-abc'` produces `OpenAI-Organization: org-abc`
     header on the OpenAI request.
  2. `anthropic_version = '2024-01-01'` produces
     `anthropic-version: 2024-01-01`.
  3. `gemini_region = 'europe-west4'` rewrites the request URL from
     `generativelanguage.googleapis.com` to
     `europe-west4-generativelanguage.googleapis.com`.
  4. Sanitized `gemini_safety_settings` rejects any category not in
     the 4-category allowlist.

---

### P7 — System instructions field (paired with sibling brainstorm) ★★★★★ / **M**

**What.** Add a section to the settings page:

- **Plugin-level default instructions** (`presshub_ai_default_instructions`):
  a `<textarea>` rendered in the new `presshub_ai_instructions`
  section (P1). Default value: empty string (the existing
  `class-api-client.php:31` string remains the in-code default).
- **Per-author instructions** stored in user meta
  (`presshub_ai_author_instructions`). Rendered on the user-edit
  screen via `edit_user_profile` / `show_user_profile` hooks, with
  capability gate `edit_user`. Implementation lives in the sibling
  brainstorm; settings page just needs to surface the existence of
  the feature with a help blurb ("Per-author overrides live on each
  user's profile").
- **Filter ordering**: extend the existing
  `presshub_ai_draft_system_prompt` filter chain at
  `class-api-client.php:32` to read:
  1. `get_user_meta( get_current_user_id(), 'presshub_ai_author_instructions', true )`
  2. `get_option( 'presshub_ai_default_instructions' )`
  3. Existing hard-coded `'You are a professional AI journalist.'`
  Priority is user > plugin > built-in. Wrap this precedence into a
  new helper `PressHub_AI_Instructions::resolve( $user_id )` so all
  five filter sites (`generate_draft`, `generate_scorecard`,
  `classify_intent`, `generate_audio_report`,
  `presshub_ai_execute_research_job`) read from the same source.

**Value.** Closes the loop with the sibling brainstorm. Without this
proposal, per-author instructions have nowhere to fall back to when
an author hasn't set their own — currently the built-in default is
used silently, which surprises authors who thought the team-level
guidance was active.

**Effort.** M — touches all five prompt sites in
`class-api-client.php:32, 34, 45, 65, 205`, plus
`presshub-ai-editor.php:113`. Plus a new tiny helper class
`class-instructions.php` (~30 lines).

**Risks.**

- The existing `presshub_ai_draft_system_prompt` filter is a public
  API. Changing the *fallback order* without warning is breaking. Keep
  the `apply_filters` call as the outermost layer (priority 99999) so
  any site that hooks in still wins; user > plugin > built-in runs
  inside the filter callback.
- User meta is per-user; for cron-driven research (no current user),
  the per-author step is skipped — falls back to plugin default, then
  built-in. Document this.
- Length cap (10k chars) prevents prompt-injection via absurdly long
  per-author text.

**TDD test plan.**

- New file `presshub-ai-editor/tests/SystemInstructionsResolverTest.php`.
- Stub additions: `get_user_meta( $id, $key, $single )` reads from
  `$GLOBALS['USER_META_STORE'][$id][$key]`; `update_user_meta` writes
  there. (Tiny addition; copy the `get_post_meta` pattern.)
- Cases:
  1. With no user meta and no option, `resolve(0)` returns the
     built-in default.
  2. With option set and no user meta, `resolve(0)` returns the
     option value.
  3. With user meta set, `resolve(7)` returns the user meta value,
     ignoring the option.
  4. `generate_draft` with all three sources populated ships the user
     meta string in the request body.
  5. Sanitize callback (P5) caps `presshub_ai_default_instructions`
     at 10k chars and strips HTML.

---

### P8 — Settings export / import ★★★ / **M**

**What.** Add a `Tools → PressHub AI` submenu with two actions:

- **Export**: emits a JSON blob of all `presshub_ai_*` options, with
  secret fields (`presshub_ai_api_key`,
  `presshub_ai_google_cloud_api_key`, `presshub_ai_github_token`)
  replaced by `null` plus a `"_redacted": true` marker. The export
  includes a `schema_version` field so future imports can migrate.
- **Import**: accepts a posted JSON blob (uploaded via the standard
  WP `wp_handle_upload` path, then parsed), validates against
  `schema_version`, and writes each option. Secrets, if the blob
  contains `null`, are skipped (not overwritten with empty). The
  import endpoint is nonce-protected and `manage_options`-gated.

The plugin ships with a "Download settings (secrets redacted)" button
on the existing settings page footer — it links to
`tools.php?page=presshub-ai-tools&action=export`.

**Value.** Dev/staging parity, faster onboarding for new sites, and a
safety valve when an admin wants to see what the plugin has
configured without exposing secrets to a screen-share.

**Effort.** M — JSON serialization (5 options + schema), an import
handler with nonce + cap check, a small CSV/JSON parsing test
surface. Roughly 100–150 lines new code.

**Risks.**

- Export with redaction must be tested for completeness: a forgotten
  secret field in the redaction list = a leaked key. The test should
  enumerate every option the plugin registers and assert none of the
  known-secret names appear unredacted.
- Import is destructive — must require explicit confirmation
  (separate nonce per import) and a `confirm=1` form field so a
  curious admin can't accidentally wipe their config by clicking
  twice.

**TDD test plan.**

- New file `presshub-ai-editor/tests/SettingsExportImportTest.php`.
- Cases:
  1. Export JSON contains exactly the registered option keys
     (asserted via `array_keys` against the registered set).
  2. Export JSON's `presshub_ai_api_key` field is `null` and the
     parent object has `"_redacted": true`.
  3. Import of a valid blob with all fields writes each one via
     `update_option` and the redacted secrets are not overwritten
     (i.e. `presshub_ai_api_key` stays at the existing value).
  4. Import of a malformed blob returns a WP_Error and writes
     nothing.
  5. Import without `confirm=1` aborts.

---

### P9 — i18n sweep + help tabs ★★ / **S**

**What.**

- Wrap every new (and existing) hard-coded English string in
  `__()` / `esc_html__()` with the `'presshub-ai-editor'` text
  domain.
- Add a WP help tab on the settings page via
  `add_help_tab()` describing: which provider to pick, what each
  section does, what the rate-limit defaults mean, and where to find
  the Anthropic/OpenAI/Gemini keys.
- Add a single `Settings → Permalinks`-style "PressHub AI" submenu
  under Settings (already exists at
  `class-settings.php:22` as `add_options_page`) — keep that
  placement, just add the help tab.

**Value.** Discoverability for new admins. Mostly a hygiene win.

**Effort.** S.

**Risks.** None significant — `__()` already exists in
`wordpress-stubs.php:366`.

**TDD test plan.**

- No new test file; extend `SettingsSectionsTest.php` (P1) with an
  assertion that `render_settings_page` calls
  `add_help_tab( $settings_page )` once and that the registered tab
  has a non-empty `content` callback that produces at least the
  literal string "PressHub AI" — a small regression guard against
  accidentally removing the help tab.

---

## 3. Cross-cutting concerns

### 3.1 Order of execution

P1 and P3 are independent of P2/P5/P6 — they can ship first as a
"settings page hardening" PR. P2 requires P5 (sanitization on the new
fields). P7 depends on P1 (the new section) and on the sibling
brainstorm. P8 depends on P5.

Recommended order:

1. P1 + P3 + P9 (single "settings page overhaul" PR; low risk).
2. P5 (sanitization; can land alongside or separately).
3. P2 + P6 + P4 (per-provider model + tuning + extras + masked UX —
   the user-facing payoff PR).
4. P7 (system instructions; ties into sibling brainstorm).
5. P8 (export/import; pure additive).

### 3.2 Stubs the TDD plan needs to add to `wordpress-stubs.php`

Compiled list across all proposals:

- `add_settings_section( $id, $title, $cb, $page )` — records into
  `$GLOBALS['SECTIONS'][$page][]`.
- `add_settings_field( $id, $title, $cb, $page, $section, $args )` —
  records into `$GLOBALS['FIELDS'][$page][$section][]`.
- `do_settings_sections( $page )` — records call (tests assert
  presence; rendering can stay no-op in the harness).
- `add_help_tab( $args )` — records into `$GLOBALS['HELP_TABS'][]`.
- `get_user_meta( $id, $key, $single )` / `update_user_meta( $id,
  $key, $value )` — copy the `get_post_meta` shape.
- `register_setting` should record the `sanitize_callback` arg in
  `$GLOBALS['SANITIZE_CALLBACKS'][$option_name]` so tests can invoke
  it directly.
- `wp_die( $msg )` — for P3 (assert no-render case). Throw a
  `RuntimeException` like `wp_send_json_error` does.

### 3.3 Migration risk

P2 deletes the legacy `presshub_ai_model` option after migration.
Existing deployments with a saved custom value must see it preserved
under the new provider key. The migration must be idempotent (guarded
by `presshub_ai_migrated_models`).

### 3.4 Test count and runtime

After all proposals land, the test count grows from 10 to ~17 files.
Each new file is <300 lines following the existing pattern
(`PromptFiltersTest.php` is 80 lines, `InputLimitsTest.php` is 115).
Total expected new test code: ~1500–2000 lines. Runtime: each test
exits in <100ms today; even 17 sequential tests stay under 2 seconds.

### 3.5 Backward compatibility

- All public filters
  (`presshub_ai_draft_system_prompt`,
  `presshub_ai_draft_user_prompt`,
  `presshub_ai_scorecard_system_prompt`,
  `presshub_ai_classify_intent_prompt`,
  `presshub_ai_audio_script_prompt`,
  `presshub_ai_research_system_prompt`)
  remain unchanged. P7 only adds a *new* filter
  (`presshub_ai_instructions_resolved`) layered **inside** the
  existing callback chain.
- The `apply_filters( 'presshub_ai_draft_system_prompt', $sys_prompt
  )` call shape is preserved — the new helper just changes the
  *default* `$sys_prompt` argument.

### 3.6 Documentation debt

Each proposal above will need a one-paragraph update to the project
README's "Settings" section. Bundle the README update into each PR.

---

## 4. What we explicitly are NOT proposing

- **Per-role model selection** (e.g. "Editors get Anthropic, Authors
  get OpenAI"). High value but high scope — multiplies every knob by
  the number of roles. Defer.
- **Settings schema versioning on read** (refusing to load options
  saved by a newer plugin version). Premature; the schema is small
  and additive.
- **A REST API for settings** (`/wp-json/presshub-ai/v1/settings`).
  Useful for headless configurations but currently the admin page is
  the only consumer. Defer until there's a real second consumer.
- **A setup wizard**. The current "save settings, click Test
  Connection" UX is two clicks. A wizard is a UX regression.
- **Migrating settings to a single serialized option** (one
  `presshub_ai_settings` option holding the whole config). The current
  one-option-per-field pattern matches WP core conventions and keeps
  cache-invalidation granularity intact.

---

## 5. File / line reference index

For quick navigation while implementing:

| Proposal | File:line |
|----------|-----------|
| P1 sectioning | `presshub-ai-editor/includes/class-settings.php:21-137` (refactor `add_settings_page` + `render_settings_page`) |
| P2 per-provider model | `presshub-ai-editor/includes/class-api-client.php:14` (constructor reads), `class-api-client.php:278` (openai), `class-api-client.php:337` (anthropic), `class-api-client.php:359` (gemini) |
| P2 tuning knobs | `class-api-client.php:292` (openai timeout), `class-api-client.php:344` (anthropic timeout + max_tokens), `class-api-client.php:392` (gemini timeout) |
| P3 capability gating | `class-settings.php:45` (add cap check), `class-ajax-handlers.php:65` (existing check; apply filter) |
| P4 masked display + per-provider test | `class-settings.php:73,79,93` (render with mask), `class-settings.php:132` (per-provider buttons), `class-api-client.php:17-24` (extend `test_connection`) |
| P5 sanitization | `class-settings.php:32-42` (add `$args` to each `register_setting`) |
| P6 provider extras | `class-api-client.php:287` (openai org), `class-api-client.php:333` (anthropic version), `class-api-client.php:90` (imagen region), `class-api-client.php:359` (gemini region rewrite) |
| P7 system instructions | `presshub-ai-editor.php:113` (research site), `class-api-client.php:32,34,45,65,205` (five prompt sites) |
| P8 export/import | new file `class-tools.php`, new submenu in `class-settings.php:21-29` |
| P9 i18n + help tabs | all `__()` calls, new `add_help_tab()` call in `class-settings.php:21` |

---

## 6. Open questions for the parent agent

1. **Do we want a "per-author instructions" UI on the user-edit screen
   now, or do we defer the meta plumbing to the sibling brainstorm's
   PR?** This doc assumes the meta side lands in the sibling; the
   settings-side fallback (P7) can ship independently.
2. **Temperature on classify_intent and audio script**: do we expose
   these prompts' temperature through the new `temperature` option, or
   do we lock it to `0.0` for deterministic classification? Today's
   code is provider-default. Recommend `0.0` for classify, default
   (`0.7`) for everything else.
3. **Should the per-provider test endpoint count against the user's
   rate limit?** Recommend yes (it's a real API call), but the rate
   limit's transient bucket is keyed per-user, so an admin clicking
   Test Connection 30 times in a row would block themselves — possibly
   surprising. Recommend a separate bucket
   (`presshub_ai_test_rl_<user_id>`) with a higher limit (200/hour).
4. **Settings export — JSON or PHP serialized array?** JSON is more
   portable (an admin can paste it into a support ticket and we can
   read it); serialized PHP is more compact. Recommend JSON.

---

## 7. TL;DR — the next PR

If we do exactly one thing this week, it should be **P1 + P3** — section
the settings page and add a capability filter. ~30 lines of
`class-settings.php`, three new test cases, zero behaviour change.
That's the cheapest, safest step that unlocks P2, P6, P7, and P8 to
land as additive PRs without touching each other.