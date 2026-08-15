# PressHub — WordPress Plugin Expert Review (WP-Ecosystem Findings)

- **Date:** 2026-08-16
- **Reviewer role:** WordPress plugin expert (delegated subagent, READ-ONLY)
- **Scope:** `presshub-ai-editor/` — WP ecosystem best-practices, WP Coding
  Standards, Settings/hook/cron correctness, asset/script handling,
  compatibility, WP.org readiness. Sibling reviewers already covered deep
  code review and architecture; this report focuses on WordPress-specific
  surface area that overlaps but is distinct.
- **Baseline:** HEAD `63f691e` (2026-08-15). 18 standalone PHP test suites
  pass per sibling code review.
- **Cited locations:** `file:line` against the tree at review time.

---

## Executive Summary

PressHub's plugin-side code is **security-conscious** (nonce + capability on
every AJAX path, sanitizers on every settings option, regex-validated slugs,
length-capped preset fields) and **architecturally modern** (typed methods,
PHP 7.0-compatible, Settings API, custom user meta + option split, opt-in
rate limiter, idempotent cron registration). The pieces that would block a
WP.org reviewer today are concentrated in three areas:

1. **The plugin header is incomplete** for WP.org submission (no
   `Requires at least`, `Requires PHP`, `Tested up to`, `License`,
   `Text Domain`, `Domain Path`; "v1.1" embedded in the Plugin Name).
2. **No plugin lifecycle management** — no `register_deactivation_hook`,
   no `uninstall.php`, scheduled events and stored options survive
   deactivation. The daily `presshub_ai_cleanup_research` cron and any
   pending `presshub_ai_do_research` single events pile up if the user
   uninstalls.
3. **Two large inline `<script>` blocks** (admin submenu + author profile)
   that bypass `wp_enqueue_script`, use raw `alert()`/`confirm()` strings,
   assume jQuery without declaring it as a dependency on the admin
   submenu page, and embed ~80 lines of duplicated jQuery logic.

The remaining items are Mediums and Lows: missing `wp_unslash()` on five
`$_POST` reads (sibling reviewer caught these), a few hardcoded
user-facing strings without `__()`, the `current_user_can('edit_others_posts')`
fall-through in cron contexts, options autoload=yes for API keys (default
behaviour), and a couple of i18n gaps in the inline JS.

The masked API-key round-trip **is implemented correctly** — the
sanitizer detects the bullet character (`••••`) substring and preserves
the existing key on save. The `wp_ajax_*` (no `nopriv`) registrations
are correct for paid endpoints. No direct `$wpdb` queries anywhere. No
SQL preparation issues to flag because no raw SQL is used.

**Headline numbers:** 2 High · 6 Medium · 7 Low · 5 Nit (overlap with
sibling reviewers is acknowledged where it occurs).

---

## Findings — Prioritized Table

| #  | Severity | Location | Issue |
|----|----------|----------|-------|
| 1  | **High** | `presshub-ai-editor/presshub-ai-editor.php:1-7` | Plugin header missing the WP.org-required fields (`Requires at least`, `Requires PHP`, `Tested up to`, `License`, `License URI`, `Text Domain`, `Domain Path`); `Plugin Name` carries "v1.1" (version belongs in `Version`). |
| 2  | **High** | `presshub-ai-editor/presshub-ai-editor.php` (whole file) | No `register_deactivation_hook` and no `uninstall.php`. The daily `presshub_ai_cleanup_research` cron and pending `presshub_ai_do_research` single events, plus all options and CPT entries, survive uninstall. |
| 3  | **Medium** | `presshub-ai-editor/includes/class-admin-presets.php:139-244`, `class-author-presets.php:205-327` | Two large inline `<script>` blocks that bypass `wp_enqueue_script`, hardcode user-facing strings (`alert()`, `confirm()`), and assume jQuery on the admin submenu page without declaring it as a script dependency. Violates the WP.org "do not include inline scripts" guideline. |
| 4  | **Medium** | `presshub-ai-editor/includes/class-admin-presets.php:33-42` | Admin submenu page registered against `options-general.php` — the `Settings` menu — rather than the plugin's own root menu. There is no parent menu; the page is the only PressHub entry in the Settings menu, so it looks orphaned. |
| 5  | **Medium** | `presshub-ai-editor/includes/class-ajax-handlers.php:96, 102, 103, 164, 200, 214` | Five `$_POST` reads lack `wp_unslash()` before sanitization — `sources`, `instructions`, `content`, `prompt`, `intent`. Inconsistent with the correct pattern at lines 108, 240, 405, 414-416. (Sibling code-review #2.) |
| 6  | **Medium** | `presshub-ai-editor/includes/class-rate-limiter.php:91-130` + `class-ajax-handlers.php:339-353` | Preset-CRUD throttle reuses the global AI rate-limiter (`is_enabled()` reads `presshub_ai_rate_limit_enabled`); `record()` reads the AI per-hour option (default 30) as its cap. The documented "60/min" throttle is therefore only what runs when both options are at defaults; raise either and the throttle drifts. (Sibling code-review #3.) |
| 7  | **Medium** | `presshub-ai-editor/includes/class-ajax-handlers.php:71-86`, `class-ajax-handlers.php:194-217` | `test_api_connection` and `handle_chat_routing` declare `wp_ajax_*` only. Correct for logged-in endpoints, but: (a) the four per-provider "Test" buttons are dead UI — `admin.js` doesn't pass the `provider` param (`admin.js:21-23`, `class-settings.php:253-256`); (b) the `check_research_status` handler relies on the caller having `edit_post` on the `presshub_research` post ID, but the post has no UI and is `publish` — any subscriber who knows the ID can probe statuses. (Sibling code-review #4.) |
| 8  | **Medium** | `presshub-ai-editor/includes/class-metaboxes.php:38-69`, `class-author-presets.php`/`class-admin-presets.php` (most user-facing labels), `assets/admin.js:82-90, 121-123`, `assets/sidebar.js` (all user-facing strings) | Hardcoded English UI strings without `__()`/`esc_html__()` or `wp_set_script_translations()` for JS. Plugin text-domain `'presshub-ai-editor'` is declared consistently, but most admin strings in the metabox, the inline scripts, and both JS files are untranslatable. |
| 9  | **Low** | `presshub-ai-editor/includes/class-metaboxes.php:34-72` | The metabox renders `$scorecard['score']` and `$scorecard['feedback']` without `is_array()` / `isset()` guards — older/hand-edited meta can produce PHP 8 warnings and broken render. |
| 10 | **Low** | `presshub-ai-editor/presshub-ai-editor.php:58-68` | `presshub_research` CPT has `show_ui => false` and `public => false`, but `'supports' => [ 'title', 'editor', 'custom-fields' ]` is registered against a hidden post type — fine, but `register_post_type()` runs inside an `init` closure that depends on `presshub_ai_init` having fired; the post type is correctly registered, but the hook priority is implicit and undocumented. |
| 11 | **Low** | `presshub-ai-editor/includes/class-api-client.php:18-19`, `class-settings.php` (all API keys) | API keys stored as options without `autoload => false`. Default autoload=yes means every front-end request loads every API key from the DB, even for anonymous visitors. |
| 12 | **Low** | `presshub-ai-editor/includes/class-workflow.php:21-47` | `transition_post_status` guard checks `current_user_can('edit_others_posts')` — under WP-Cron or REST/CLI (user id 0) the check returns false, so `wp_update_post` reverts to `pending`. Other plugins' CLI tools may be surprised. |
| 13 | **Low** | `presshub-ai-editor/includes/class-rate-limiter.php:91-130` | Read-modify-write on `get_transient`/`set_transient` is non-atomic (TOCTOU window) — two concurrent requests from one user can both pass `check()`. Acceptable for cost control; document or move to `$wpdb` atomic increment. |
| 14 | **Low** | `presshub-ai-editor/includes/class-research-cleanup.php:18-21` | Doc says retention is "configurable"; the value is hardcoded to 30 days with no option. Either expose it or fix the docstring. |
| 15 | **Low** | `presshub-ai-editor/includes/class-metaboxes.php:10-21` | `enqueue_assets()` runs on every admin page when `$hook` is `post.php`/`post-new.php`, but does not gate on post type — the assets and `presshubAI` localization ship on every edit screen, including CPTs that have no metabox. Cheap, but adds payload to unrelated editors. |
| 16 | **Nit** | `presshub-ai-editor/includes/class-admin-presets.php:141-144`, `class-author-presets.php:207-210` | The inline `<script>` declares `var presshubAI = {…}` inside a closure — fine for one render but if both screens render on the same admin request (unlikely but possible via custom nav) the second declaration is a JS error. |
| 17 | **Nit** | `presshub-ai-editor/includes/class-settings.php:610-613` | `sanitize_slug` regex (`[^a-z0-9\-_]`) allows underscores; GCP project IDs do not (lowercase letters/digits/hyphens only). Harmless — the API rejects it — but tighter regex is a free fix. |
| 18 | **Nit** | `presshub-ai-editor/includes/class-api-client.php:178` | Imagen API key embedded in URL query string (`?key=…`); Google supports `x-goog-api-key` header. Move to header. |
| 19 | **Nit** | `presshub-ai-editor/assets/admin.js:14-37`, `assets/sidebar.js` (multiple) | `alert('Error: ' + response.data)` — when `wp_send_json_error()` is given a non-string the user sees `[object Object]`. Coerce with `String(response.data)` / `response.data.message`. |
| 20 | **Nit** | `presshub-ai-editor/includes/class-settings.php:268-282` | `register_help_tab` uses `function_exists('get_current_screen')` then `add_help_tab()` — in real WP only the screen method is correct; the global `add_help_tab()` doesn't exist. Test-only. |

---

## Detailed Findings

### HIGH-1 — Plugin header is incomplete for WP.org

**Location:** `presshub-ai-editor/presshub-ai-editor.php:1-7`

```
/**
 * Plugin Name: PressHub AI Co-Pilot v1.1
 * Description: AI Co-Authoring and Editorial Workflow for PressHub.
 * Version: 1.1.0
 * Author: Antigravity
 */
```

**Description:** The plugin header is missing the fields the WP.org
plugin review team checks first:

- `Requires at least` (WP minimum version)
- `Tested up to` (current WP version)
- `Requires PHP` (PHP minimum)
- `License` + `License URI` (GPL-2.0-or-later is the WP.org norm)
- `Text Domain` (should match `presshub-ai-editor` everywhere)
- `Domain Path` (for `/languages` if present)

Also, the version `v1.1` is baked into the **Plugin Name** — the
canonical version lives in the `Version` header. The Plugin Name is what
users see in the Add Plugins list and on the Plugins page; carrying the
version there is non-standard and a WP.org reviewer will flag it.

**Impact:** The plugin cannot be accepted into the WP.org repository as-is.
Also, no `Requires PHP` means the plugin can be installed on a server
running a PHP version that breaks scalar/return type hints — runtime
fatal with no clear remediation for the user.

**Fix:** Bring the header to spec:

```
/**
 * Plugin Name: PressHub AI Co-Pilot
 * Description: AI Co-Authoring and Editorial Workflow for PressHub.
 * Version: 1.1.0
 * Requires at least: 5.5
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presshub-ai-editor
 * Domain Path: /languages
 * Author: Antigravity
 * Author URI: https://example.com/presshub
 */
```

The `Requires PHP: 7.4` matches the actual code surface (typed methods,
nullable returns — no PHP 8-only syntax like `enum`, `match`, `readonly`,
`mixed`, `never`, `#[\Attribute]`, or arrow functions).

---

### HIGH-2 — No plugin lifecycle management (deactivation/uninstall)

**Location:** `presshub-ai-editor/presshub-ai-editor.php` (entire file)

Searches for `register_deactivation_hook`, `register_uninstall_hook`,
and any `uninstall.php` return nothing. The vendored PUC library
registers its own deactivation hook for its own cron
(`includes/plugin-update-checker/Puc/v5p7/Plugin/UpdateChecker.php:89`),
but no hook covers the plugin's own:

- Daily `presshub_ai_cleanup_research` event
  (`includes/class-research-cleanup.php:26-31`)
- Pending `presshub_ai_do_research` single events
  (`includes/class-ajax-handlers.php:270`)
- Plugin options (`presshub_ai_*`)
- `presshub_research` CPT entries
- Per-author user meta (`presshub_ai_author_presets`,
  `presshub_ai_default_preset_id`,
  `presshub_ai_disabled_default_presets`)

**Impact:** On "Uninstall" in the WP admin (which calls the multisite
hook → `uninstall.php`), nothing is removed — the `options` table keeps
every provider key, every default-preset library, and the `wp_options`
table keeps the autoload=yes options (every page load on every site on
the network). The cron keeps firing daily and inserting/cleaning its
post type even though the plugin's UI is gone.

**Fix:**

1. Add a `register_deactivation_hook` to `presshub-ai-editor.php` that
   calls `wp_clear_scheduled_hook('presshub_ai_cleanup_research')` and
   unschedules all `presshub_ai_do_research` events (loop over stored
   research post IDs and call `wp_unschedule_event`).

2. Add `uninstall.php` at the plugin root that:

```php
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
// Delete plugin options (not autoload — explicit by name).
$opts = [
    'presshub_ai_provider', 'presshub_ai_api_key',
    'presshub_ai_model', 'presshub_ai_model_openai',
    'presshub_ai_model_anthropic', 'presshub_ai_model_gemini',
    'presshub_ai_temperature_openai', 'presshub_ai_temperature_anthropic',
    'presshub_ai_temperature_gemini',
    'presshub_ai_max_tokens_openai', 'presshub_ai_max_tokens_anthropic',
    'presshub_ai_max_tokens_gemini',
    'presshub_ai_timeout_openai', 'presshub_ai_timeout_anthropic',
    'presshub_ai_timeout_gemini',
    'presshub_ai_openai_org', 'presshub_ai_anthropic_version',
    'presshub_ai_github_token', 'presshub_ai_google_cloud_api_key',
    'presshub_ai_gcloud_project_id', 'presshub_ai_imagen_region',
    'presshub_ai_rate_limit_enabled', 'presshub_ai_rate_limit_per_hour',
    'presshub_ai_rate_limit_window_seconds',
    'presshub_ai_default_presets', 'presshub_ai_migrated_models',
];
foreach ( $opts as $opt ) {
    delete_option( $opt );
}

// Delete all research CPT entries (purge post meta + posts).
$research = get_posts( [
    'post_type'      => 'presshub_research',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
] );
foreach ( $research as $rid ) {
    wp_delete_post( $rid, true );
}

// Per-author user meta (multisite-safe loop).
$users = get_users( [ 'fields' => 'ID' ] );
foreach ( $users as $uid ) {
    delete_user_meta( $uid, 'presshub_ai_author_presets' );
    delete_user_meta( $uid, 'presshub_ai_default_preset_id' );
    delete_user_meta( $uid, 'presshub_ai_disabled_default_presets' );
}

// Cron events (deactivation also clears these but uninstall is final).
wp_clear_scheduled_hook( 'presshub_ai_cleanup_research' );
```

This is exactly the kind of cleanup WP.org reviewers look for in
`uninstall.php`. The "if plugin is deactivated, settings remain;
if uninstalled, settings and data are gone" pattern is the canonical
WP convention.

---

### MEDIUM-3 — Two large inline `<script>` blocks bypass `wp_enqueue_script`

**Location:**
- `presshub-ai-editor/includes/class-admin-presets.php:139-244`
- `presshub-ai-editor/includes/class-author-presets.php:205-327`

Both files print ~80 lines of jQuery directly inside the PHP render
output, including `alert()` / `confirm()` strings and a
`presshubPresetSlug()` slug helper that is **byte-identical** between
the two files (the architect-review sibling flagged this).

Beyond the duplication, the inline scripts have three WP-specific
problems:

1. **No enqueue dependency declaration.** jQuery is the only JS used,
   but neither file declares `wp_enqueue_script( 'jquery' )` or any
   `wp_enqueue_script` call at all. On the admin submenu page
   (`admin.php?page=presshub-ai-presets`), jQuery happens to be loaded
   by the WP admin shell, but on the user profile page it is loaded by
   `user-edit.php` / `profile.php` — fragile if either screen ever ships
   without it (e.g., a stripped-down admin).

2. **Hardcoded UI strings.** `alert('Name must contain at least one
   letter or number…')`, `window.confirm('Delete this preset?')`,
   `alert('Server connection error.')`. None are wrapped in `__()`,
   and no `wp_set_script_translations()` is registered, so these are
   untranslatable.

3. **WP.org guideline violation.** WP.org's plugin handbook explicitly
   recommends against inline scripts because they (a) cannot be
   cached/minified by performance plugins, (b) cannot be deferred to
   the footer by the script-loader, (c) leak the page's nonce and AJAX
   URL to anyone viewing the page source, and (d) bypass WP's
   `script_loader_tag` filter that other plugins use to add CSP nonces
   / SRI hashes.

**Fix:** Extract both inline scripts into `assets/admin-presets.js` and
`assets/author-presets.js`, register `wp_enqueue_script` callbacks on
the right `$hook` filters, declare `jquery` as a dep, use
`wp_localize_script` for the nonce and AJAX URL, and use
`wp.i18n.__()` from the `wp-i18n` package — or, since this is plain
jQuery, use the `wp_set_script_translations()` mechanism by attaching a
`wp.i18n` runtime and re-wrapping the strings.

For the slug helper, dedupe: one shared `assets/presshub-presets.js`
containing the slug + ajax helpers, enqueued on both screens.

---

### MEDIUM-4 — Admin submenu registered under Settings rather than a plugin menu

**Location:** `presshub-ai-editor/includes/class-admin-presets.php:33-42`

```php
add_submenu_page(
    'options-general.php',
    __( 'PressHub AI — Instruction Presets', 'presshub-ai-editor' ),
    __( 'PressHub AI Presets', 'presshub-ai-editor' ),
    $this->settings_cap(),
    'presshub-ai-presets',
    [ $this, 'render_page' ]
);
```

There is no parent menu registered by the plugin — only
`add_options_page(...)` for the settings page
(`class-settings.php:54-61`) and this `add_submenu_page(...)` under
`options-general.php`. The result: the plugin's "Instruction Presets"
appears as a sub-item of WP core's `Settings` menu, while the main
"PressHub AI" settings page is itself a top-level `Settings` entry.
This is **two entries in two different places** for one plugin, which
is confusing for end users and is flagged by WP.org reviewers as a
non-standard navigation pattern.

**Fix:** Register a top-level menu (`add_menu_page` with a Dashicon and
the same capability) and put both the Settings and the Presets under
it as submenus:

```php
add_menu_page(
    __( 'PressHub AI', 'presshub-ai-editor' ),
    __( 'PressHub AI', 'presshub-ai-editor' ),
    $this->settings_cap(),
    'presshub-ai',
    [ $this, 'render_settings_page' ],   // primary page render
    'dashicons-edit',
    81
);
add_submenu_page(
    'presshub-ai',
    __( 'Settings', 'presshub-ai-editor' ),
    __( 'Settings', 'presshub-ai-editor' ),
    $this->settings_cap(),
    'presshub-ai',
    [ $this, 'render_settings_page' ]
);
add_submenu_page(
    'presshub-ai',
    __( 'Instruction Presets', 'presshub-ai-editor' ),
    __( 'Instruction Presets', 'presshub-ai-editor' ),
    $this->settings_cap(),
    'presshub-ai-presets',
    [ $this, 'render_presets_page' ]
);
```

---

### MEDIUM-5 — Five `$_POST` reads lack `wp_unslash()`

**Location:** `presshub-ai-editor/includes/class-ajax-handlers.php:96, 102, 103, 164, 200, 214`

```php
// line 96
$post_id = intval( $_POST['post_id'] );
// line 102
$sources = isset( $_POST['sources'] ) ? sanitize_textarea_field( $_POST['sources'] ) : '';
// line 103
$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( $_POST['instructions'] ) : '';
// line 164
$content = isset( $_POST['content'] ) ? wp_kses_post( $_POST['content'] ) : '';
// line 200
$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( $_POST['prompt'] ) : '';
// line 214
$pre_intent = isset( $_POST['intent'] ) ? sanitize_textarea_field( $_POST['intent'] ) : '';
```

WordPress runs `wp_magic_quotes()` on every request, which `addslashes()`
every `$_POST` value. Apostrophes and quotes arrive as `\'` and end up
in AI prompts, stored research meta, scorecard content, etc. The other
`$_POST` reads in the same file (lines 108, 240, 405, 414-416, 501,
510, 570, 603) use the correct `wp_unslash()` first — this is an
inconsistency, not a new pattern.

**Fix:** Standardize on `sanitize_*( wp_unslash( $_POST[…] ) )`. WP.org's
plugin handbook explicitly calls this out: "Always unslash superglobals
before use, even if you intend to escape or sanitize them later."

---

### MEDIUM-6 — Preset-CRUD throttle is silently disabled or mis-limited

**Location:**
- `presshub-ai-editor/includes/class-rate-limiter.php:91-130`
- `presshub-ai-editor/includes/class-ajax-handlers.php:339-353`

The preset-CRUD handlers (`save_preset`, `delete_preset`,
`set_default_preset`, `copy_default_preset`) call a private
`enforce_preset_throttle()` that uses the same
`PressHub_AI_Rate_Limiter` instance as the AI endpoint limiter. The
`record()` method has this implementation:

```php
public function record( string $key ): void {
    if ( ! $this->is_enabled() ) {     // reads presshub_ai_rate_limit_enabled
        return;
    }
    $limit = $this->configured_limit(); // reads presshub_ai_rate_limit_per_hour (default 30)
    ...
}
```

Three things go wrong:

1. **Off-by-default.** When `presshub_ai_rate_limit_enabled` is off
   (the WP default), the preset throttle is a complete no-op. An
   attacker who has author privileges can fire unlimited
   `save_preset` requests and never be throttled.
2. **Wrong cap.** `configured_limit()` reads the AI per-hour option,
   not a preset-specific one. If an admin raises the AI limit to
   10,000/hour for legitimate AI work, the preset CRUD suddenly allows
   10,000 mutations/minute. The documented "60/min" contract is never
   what runs.
3. **TOCTOU window.** Read-modify-write on `get_transient`/`set_transient`
   is non-atomic; two concurrent preset requests from the same user can
   both pass `check()`.

**Fix:** Add a `PressHub_AI_Rate_Limiter::__construct( $enabled, $limit, $window_seconds )`
or a dedicated `presshub_ai_preset_throttle_enabled` option so the
preset CRUD has its own on/off and cap independent of the AI limiter.
Pass `record($key, $limit, $window_seconds)` the same values used by
`check()`, and consider a `$wpdb`-based atomic increment for stricter
correctness.

---

### MEDIUM-7 — `wp_ajax_*` registrations are correct, but two issues follow

**Location:**
- `presshub-ai-editor/includes/class-ajax-handlers.php:11-21`
- `presshub-ai-editor/includes/class-ajax-handlers.php:71-86`
- `presshub-ai-editor/includes/class-ajax-handlers.php:289-316`
- `presshub-ai-editor/assets/admin.js:14-37`

**The good news:** No `wp_ajax_nopriv_*` handlers exist for any paid or
authenticated endpoint — `grep -r wp_ajax_nopriv` returns zero matches.
All 10 AJAX actions (`generate_draft`, `run_review`, `test_api`,
`chat`, `check_research`, plus 5 preset CRUD actions) are correctly
gated to logged-in users. Every handler begins with `check_ajax_referer`
followed by `current_user_can`. This is exactly the WP.org-expected
pattern for paid/logged-in endpoints.

**Two related issues:**

1. **Dead per-provider "Test" buttons.** `assets/admin.js:21-23` posts
   only `{action, nonce}` to the test endpoint, dropping the per-provider
   buttons (`data-provider="openai"` etc., `class-settings.php:253-256`)
   to the floor. The PHP side supports it
   (`test_connection($provider)` in `class-api-client.php:75-94`), so the
   feature is broken UI rather than broken code.

2. **`check_research_status` has a capability hole.** The handler reads
   `$research_id` from `$_POST`, then does
   `current_user_can('edit_post', $research_id)`. The post type
   `presshub_research` has `public => false` and `show_ui => false`
   (`presshub-ai-editor.php:60-67`), so by WP's defaults any user with
   `edit_posts` who knows a research post ID can poll its status and
   read the AI-generated content (`$post->post_content` echoed back at
   `class-ajax-handlers.php:309`). Combined with the fact that
   research posts are inserted as `post_status => 'publish'` (the
   workflow guard then immediately reverts to `pending`), any
   subscriber who guesses or scrapes an ID has read access to the
   author's research drafts.

**Fixes:**

- For (1): add `provider: $btn.data('provider')` to the AJAX data in
  `admin.js:21-23`.
- For (2): gate `check_research_status` on `current_user_can('edit_post', $research_id)`
  **AND** ownership: `get_post($research_id)->post_author ===
  get_current_user_id()` — or admins — and never return
  `$post->post_content` to non-owners. Even better, register
  `presshub_research` with `capability_type => 'post'` and an explicit
  `map_meta_cap` so the post-type capability tree enforces it.

---

### MEDIUM-8 — i18n coverage is incomplete

**Location:**
- `presshub-ai-editor/includes/class-metaboxes.php:38-69, 130-137`
- `presshub-ai-editor/includes/class-admin-presets.php` (most user-facing labels)
- `presshub-ai-editor/includes/class-author-presets.php` (most user-facing labels)
- `presshub-ai-editor/assets/admin.js:82-90, 121-123`
- `presshub-ai-editor/assets/sidebar.js` (all user-facing strings)

The text domain `'presshub-ai-editor'` is declared consistently in
`__()` calls inside `class-admin-presets.php`, `class-author-presets.php`,
`class-rate-limiter.php`, and `class-settings.php`. But a sweep of the
rendered HTML and JS shows several strings that are not wrapped:

- `class-metaboxes.php:38, 43, 56, 130` — `<h3>Multi-Modal Source Material</h3>`,
  `<h3>Journalist Instructions</h3>`, `<h3>Editorial Scorecard</h3>`,
  `<h3>Author Style Preset</h3>` — untranslated.
- `class-metaboxes.php:39, 41, 44, 49, 58, 137` — `<p class="description">…</p>`,
  placeholders, button labels — untranslated.
- `assets/admin.js:82, 84, 90, 121, 123` — alert messages, all raw.
- `assets/sidebar.js:8, 52, 144, 156, 167, 193, 197` — all chat UI
  strings raw.
- Inline JS in `class-admin-presets.php` and `class-author-presets.php`
  — all `alert()` and `confirm()` raw.

For JS localization, register `wp_set_script_translations()` on the
`admin.js`, `sidebar.js`, and the (recommended) extracted preset JS
file with `presshub-ai-editor` as the domain, and wrap strings with
`wp.i18n.__()` (from the `wp-i18n` package, which is already a dep of
`wp-plugins` etc.). Add a `/languages` directory.

---

### LOW-9 — Metabox reads scorecard meta without guards

**Location:** `presshub-ai-editor/includes/class-metaboxes.php:34-72`

```php
$scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );
...
<?php if ( $scorecard ) : ?>
    <div class="scorecard-box">
        <strong>Score: <?php echo esc_html( $scorecard['score'] ); ?>/100</strong>
        <p><?php echo esc_html( $scorecard['feedback'] ); ?></p>
    </div>
<?php endif; ?>
```

The conditional checks only `$scorecard` is truthy — a stored string
`"0"`, an int, or a non-array scalar would all `if ($scorecard)`
evaluate as truthy and then trip PHP 8 "trying to access array offset on
value of type X" warnings. Likewise `wp_send_json_success( $scorecard )`
in `run_review` (`class-ajax-handlers.php:191`) returns raw AI output
shape that may not always be an array.

**Fix:** `if ( is_array( $scorecard ) && isset( $scorecard['score'] ) )` before
rendering; `if ( is_array( $scorecard ) )` before sending back via JSON.
Apply the same to `admin.js:119` where the response data is read.

---

### LOW-10 — CPT registration inside anonymous `init` closure

**Location:** `presshub-ai-editor/presshub-ai-editor.php:58-68`

The `presshub_research` CPT is registered inside an inline closure
hooked to `init`. Functionally fine — it runs on every WP request — but
two conventions to flag:

1. No priority. Default is `10`, which is fine, but `register_post_type`
   conventionally runs on the default priority without explicit
   declaration. Either is fine, but a comment would help.
2. `'supports' => [ 'title', 'editor', 'custom-fields' ]` against a
   `public => false` / `show_ui => false` CPT — fine, but those fields
   are still rendered into the post object. For a hidden CPT, a more
   minimal `'supports' => []` is the WP convention; the meta you need
   already goes through `update_post_meta()`.

**Fix (optional polish):** Move the `register_post_type` into a method
on a small `class-research-cpt.php` (mirroring the existing class
organization), and add a docblock describing the `presshub_research`
post type's lifecycle (created by `handle_chat_routing`, deleted by
`PressHub_AI_Research_Cleanup::run()` after 30 days).

---

### LOW-11 — API keys autoload=yes by default

**Location:**
- `presshub-ai-editor/includes/class-api-client.php:18-19`
- `presshub-ai-editor/includes/class-settings.php` (all key options)

`get_option('presshub_ai_api_key')` and the per-provider keys
(`presshub_ai_openai_org`, `presshub_ai_anthropic_version`,
`presshub_ai_github_token`, `presshub_ai_google_cloud_api_key`,
`presshub_ai_gcloud_project_id`, `presshub_ai_imagen_region`) all
default to `autoload=yes`. That means every front-end request (logged-
out visitors included) loads every key from `wp_options` via the
`alloptions` cache, even though the keys are only ever used on admin
AJAX calls.

This is **standard WP behaviour**, but a WP.org reviewer will note it,
and it's a cheap performance + posture improvement to set
`autoload => no` on the secret-bearing options.

**Fix:** In every `update_option(...)` call for these keys, pass
`[ 'autoload' => false ]` — or in the Settings API `register_setting`
third arg, set `'autoload' => false`:

```php
register_setting( 'presshub_ai_options', 'presshub_ai_api_key', [
    'sanitize_callback' => [ __CLASS__, 'sanitize_api_key' ],
    'type'              => 'string',
    'default'           => '',
] );
```

(`register_setting` doesn't expose `autoload` directly — set it via a
follow-up `update_option` with the autoload flag on first save, or use
`add_option` for the initial seeding.)

---

### LOW-12 — Workflow guard depends on request user

**Location:** `presshub-ai-editor/includes/class-workflow.php:21-47`

`current_user_can('edit_others_posts')` reflects the **current request
user**. Under WP-Cron, REST, WP-CLI, and other contexts where the
acting user is `0` or unset, this check returns false — so the workflow
guard immediately reverts the post to `pending`. CLI publishing tools
that intentionally publish on behalf of a system user will be
surprised by the silent revert.

**Fix:** Either:

- Use `$post->post_author` and check ownership against the current
  user, or
- Allow a filter (`apply_filters('presshub_ai_workflow_allow_publish', $allow, $post)`)
  so other plugins / CLI tools can opt out for a specific transition,
  or
- Use `wp_get_current_user()->exists()` and treat user-id-0 transitions
  as admin (system) actions.

---

### LOW-13 — Rate-limiter transient TOCTOU

**Location:** `presshub-ai-editor/includes/class-rate-limiter.php:91-130`

Read-modify-write on `get_transient` / `set_transient` is non-atomic.
Two concurrent requests from the same user can both pass `check()`
(both see count < limit), both increment, and end up 1 over the limit.
For cost control this is acceptable; for a hard cap it's not.

**Fix:** Document the limitation in the class docblock (or in the
admin UI as "approximate per-user cap"), or move to a `$wpdb` atomic
increment:

```php
$count = (int) $wpdb->query( $wpdb->prepare(
    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
     VALUES (%s, 1, 'no')
     ON DUPLICATE KEY UPDATE option_value = option_value + 1",
    '_transient_' . $key
) );
```

(That pattern needs the `wp_options` table to be InnoDB; on the rare
shared-host MyISAM setup it falls back to a row lock.)

---

### LOW-14 — Hardcoded 30-day retention in docblock

**Location:** `presshub-ai-editor/includes/class-research-cleanup.php:18-21`

The docblock says "Default retention: keep completed/failed logs for 30
days" and the constant is hardcoded. There is no setting, no option, no
filter to change it. Either expose it (`add_settings_field`) or fix the
docstring to say "Hardcoded retention: 30 days."

This is a documentation/expectation mismatch that a WP.org reviewer
will note if it claims to be configurable.

---

### LOW-15 — Metabox assets load on every edit screen

**Location:** `presshub-ai-editor/includes/class-metaboxes.php:10-21`

```php
public function enqueue_assets( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'presshub-ai-admin-css', ... );
    wp_enqueue_script( 'presshub-ai-admin-js', ... );
    wp_localize_script( ... );
}
```

The gate is on the WP screen hook but not on post type. Every CPT edit
screen ships the `presshubAI` localize payload (admin AJAX URL and
nonce) regardless of whether the PressHub metabox is registered there.
The metabox is only registered for `post` (`class-metaboxes.php:28`),
so the assets are dead payload on every other edit screen.

**Fix:** Add a third condition:

```php
if ( ! in_array( $post_type, [ 'post' ], true ) ) {
    return;
}
```

(`$hook` is a screen id; `get_current_screen()->post_type` is the
right gate inside the function.)

---

### NIT-16 — Duplicated `presshubAI` closure name

**Location:**
- `presshub-ai-editor/includes/class-admin-presets.php:141-144`
- `presshub-ai-editor/includes/class-author-presets.php:207-210`

Each inline script declares `var presshubAI = {…}` inside a closure
that uses jQuery. If both screens render on the same admin request
(unlikely but possible), the second `var` declaration in the same
closure would be a SyntaxError. Wrapping each in an IIFE or scoping to
its own data attribute would remove the risk.

**Fix:** Already addressed by extracting both to enqueued JS files
(see MEDIUM-3).

---

### NIT-17 — `sanitize_slug` allows underscores for GCP project IDs

**Location:** `presshub-ai-editor/includes/class-settings.php:610-613`

```php
$value = preg_replace( '/[^a-z0-9\-_]/', '', $value );
```

GCP project IDs reject underscores (`[a-z0-9-]+` only). The sanitizer
is shared between Anthropic version (no underscores expected) and the
GC project ID (no underscores allowed). Tighten the regex to
`[^a-z0-9\-]` or split into two sanitizers.

---

### NIT-18 — Imagen API key in URL query string

**Location:** `presshub-ai-editor/includes/class-api-client.php:178`

```php
return 'https://' . $region . '-aiplatform.googleapis.com/v1/projects/' . $project_id . '/locations/' . $region . '/publishers/google/models/imagen-3.0-generate-002:predict?key=' . $this->google_cloud_api_key;
```

Google Cloud supports passing the API key in an `x-goog-api-key` header
or via `?key=` in the query string. The header is preferable for the
usual reasons (server/proxy logs, referrer leakage). Move to a header.

---

### NIT-19 — `alert('Error: ' + response.data)` can stringify objects as `[object Object]`

**Location:**
- `presshub-ai-editor/assets/admin.js:84, 123`
- `presshub-ai-editor/assets/sidebar.js:72, 100, 114, 122`

If `wp_send_json_error()` receives an array or object, the user sees
`[object Object]`. Coerce with `String(response.data)` or, preferably,
`response.data?.message ?? String(response.data)`.

---

### NIT-20 — Help-tab fallback uses a non-existent global

**Location:** `presshub-ai-editor/includes/class-settings.php:268-282`

```php
if ( function_exists( 'get_current_screen' ) ) {
    $screen = get_current_screen();
    if ( $screen && is_object( $screen ) && method_exists( $screen, 'add_help_tab' ) ) {
        $screen->add_help_tab( $args );
        return;
    }
}
add_help_tab( $args );   // global add_help_tab() doesn't exist in WP
```

In real WP the global `add_help_tab()` doesn't exist; this branch is
test-only. Fine for the test harness, but the `function_exists` check
on `get_current_screen` should be paired with a real `WP_Screen`
fallback (e.g., `function_exists('get_current_screen')` returning
`false` falls through to a no-op rather than calling the non-existent
global). Wrap the second branch in `if ( function_exists('add_help_tab') )`.

---

## What a WP.org Plugin Reviewer Would Block

Reading the WP.org Detailed Plugin Guidelines
(`https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`)
against this tree, the items a reviewer would **stop** the submission on:

| Guideline | Finding |
|---|---|
| "Don't include inline scripts or styles in your plugin" | MEDIUM-3 |
| "Use the Settings API for all plugin options" | OK — uses it. |
| "Use nonces for all form submissions" | OK — `check_ajax_referer` on every AJAX handler. |
| "Use `current_user_can()` for capability checks" | OK. |
| "Use `wp_enqueue_script()` to load scripts" | OK for admin.js + sidebar.js; **fails** for the two inline blocks. |
| "Don't track users without consent" | N/A — no analytics. |
| "Don't obfuscate code" | OK. |
| "Provide an `uninstall.php`" | **FAILS** — see HIGH-2. |
| "Don't include premium features that need payment" | N/A. |
| "Use a unique prefix everywhere" | OK — every symbol is `PressHub_AI_*` or `presshub_ai_*`. |
| "Provide a stable, semantic version" | OK — `Version: 1.1.0`. |
| "Don't include version numbers in Plugin Name" | **FAILS** — `Plugin Name: PressHub AI Co-Pilot v1.1`. |
| "Declare `Requires at least`, `Tested up to`, `Requires PHP`, `License`" | **FAILS** — see HIGH-1. |
| "Don't hardcode URLs/paths" | OK — uses `plugin_dir_url` / `plugin_dir_path`. |
| "Sanitize on input, escape on output" | OK overall; see MEDIUM-5 for the few `wp_unslash` misses. |
| "Use `wp_remote_post` / `wp_remote_get`" | OK. |
| "Don't ship your own copy of WordPress" | OK. |
| "Don't ship your own copy of large third-party libraries unless required" | The vendored PUC is borderline — `plugin-update-checker/` is ~700 KB and is itself a v5p7 build. PUC's WP.org policy is that it ships inside the consuming plugin; OK. |
| "Don't include minified source without an unminified copy" | N/A — no minified files. |

**Verdict:** **Would not pass review today** without:

1. Filling out the plugin header (HIGH-1).
2. Adding `uninstall.php` and a deactivation hook (HIGH-2).
3. Either removing or extracting the two inline `<script>` blocks
   into enqueued assets (MEDIUM-3).

Once those three are fixed, the remaining findings (Mediums 4-8 and
the Lows/Nits) would either be **notes** ("consider tightening
i18n") or **not flagged** by an automated review.

---

## What a WP.org Reviewer Would Note (Not Block)

These would be sent as a "Review Team Comments" follow-up but wouldn't
block acceptance on their own:

- MEDIUM-4 (submenu under `options-general.php` instead of a plugin
  menu).
- MEDIUM-5 (five missing `wp_unslash()`).
- MEDIUM-7 part 2 (`check_research_status` content disclosure).
- MEDIUM-8 (i18n gaps).
- LOW-9, LOW-11, LOW-12.

---

## What a WP.org Reviewer Would Not Mention

The following are clean and would pass without comment:

- Settings API usage (`register_setting`, `add_settings_field`,
  `settings_fields`, `do_settings_sections`, `submit_button`).
- Masked key round-trip (`sanitize_secret` correctly preserves the
  stored value when the posted value is the mask or empty).
- Nonce usage on every AJAX handler.
- Capability checks on every AJAX handler.
- Use of `wp_remote_post` / `wp_remote_get`.
- Custom user meta + option split for presets.
- Slug regex validation (`/^[a-z0-9-]{1,40}$/`) on every preset read.
- Length caps on every text input.
- Idempotent `seed_plugin_defaults()`.
- Idempotent cron registration (guarded by `wp_next_scheduled`).
- The opt-in nature of the rate limiter.
- The `apply_filters()` exposure on every prompt (`presshub_ai_*_system_prompt`).
- The belt-and-suspenders admin gate on paid intents (`class-ajax-handlers.php:215, 232`).
- No `wp_ajax_nopriv_*` registrations.
- No direct `$wpdb` queries (no SQL preparation issues to flag).
- No use of `eval`, `extract`, `compact`, or other risky
  PHP constructs.

---

## Top Five Things to Do Next (priority order)

1. **HIGH-1 + HIGH-2** — plugin header and `uninstall.php` /
   `register_deactivation_hook`. One PR each, mechanical.
2. **MEDIUM-3** — extract the two inline `<script>` blocks into
   enqueued assets under `/assets/`, dedupe the slug helper, register
   `wp_set_script_translations()`. Single PR, mechanical.
3. **MEDIUM-5** — fix the five missing `wp_unslash()` calls. One-line
   patches each.
4. **MEDIUM-6** — give the preset-CRUD throttle its own enable flag
   and cap; stop reusing the AI limiter's `is_enabled()`.
5. **MEDIUM-7 part 1** — fix the dead per-provider "Test" buttons in
   `admin.js:21-23`.

After these five, the remaining items are notes rather than blocks.
