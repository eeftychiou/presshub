# Per-Author Multi-Instruction System Prompts for PressHub

**Date**: 2026-08-15
**Status**: Brainstorm / Design Proposal (NOT yet approved for implementation)
**Author**: Brainstorming session (delegated subagent)
**Repo path**: `/home/hermes/presshub`
**Plugin under design**: `presshub-ai-editor/`

> This document is **proposal only**. No source files were modified. The
> implementation owner must re-confirm all file:line references against HEAD
> before coding and update this doc's status to "Approved" or "Rejected" once
> a decision lands.

---

## Executive Summary

PressHub's `presshub-ai-editor` plugin currently builds every AI prompt
inline in `includes/class-api-client.php` (`generate_draft()`,
`generate_scorecard()`, `classify_intent()`, `generate_audio_report()`) and
in the cron-scheduled research job (`presshub-ai-editor.php` →
`presshub_ai_execute_research_job()`). Each of those prompts is already
filterable via `apply_filters()` — the `presshub_ai_*_prompt` hooks were
landed in commit `cd54c78` (2026-08-15). What is still missing is a
**user-facing** layer on top: per-author named instruction presets that
authors pick from a metabox dropdown and that get composed into the system
prompt automatically.

**Recommendation**: ship a "Presets" subsystem with three storage layers
in increasing specificity:

1. **Plugin defaults** — a WP option holding a library of named presets
   curated by an admin. Available to every author as a starting point.
2. **Per-author presets** — a `USER_META` array on each author. Authors
   copy/edit/own their own library.
3. **Per-request selection** — a metabox dropdown + an AJAX parameter
   (`instruction_preset_id`) chooses which preset applies to the next
   draft generation.

Composition rule (strictest precedence wins):

> built-in default prompt `<` plugin defaults that match `<` author's
> preset `<` per-request selection (explicit override)

Each preset's instruction text is **appended** to the built-in system
prompt (NOT replaced) — this preserves the editorial framing
("professional AI journalist", "exacting news editor") and only adds the
author's stylistic guidance on top. Scorecard prompts are **not**
customizable (justified in §3). Chat and research receive author presets
**only** when the author is the one initiating the request; audio reports
stay neutral (admin-only context).

**Scope estimate**: M (3–4 days). The data model + AJAX plumbing are S;
the admin UI + per-author screen are M; the TDD plan is M.

---

## 1. Data Model

### 1.1 Storage locations

| Layer | Where | Why |
|---|---|---|
| **Plugin-default presets** | WP option `presshub_ai_default_presets` | Admin-curated library, ship-wide defaults, survives user deletion |
| **Per-author presets** | User meta `presshub_ai_author_presets` (key on `$user->ID`) | Travels with the author across devices; per-user settings UI is the only thing authors edit |
| **Default selection per author** | User meta `presshub_ai_default_preset_id` (slug) | Lets each author set a "default preset" applied automatically when they don't pick one in the metabox |
| **Per-request selection** | AJAX POST `instruction_preset_id` (slug or `'__plugin_default__'` sentinel) | Stateless; never persisted to a post meta because presets are *about the author*, not the post |

This mirrors the existing pattern in `wordpress-stubs.php` where post meta
goes through `POST_META_STORE`, options through `OPTIONS_STORE`, and
current-user caps through `CURRENT_USER_CAPS` (§1.5 stubs below).

### 1.2 Shape of a preset

```php
[
    'slug'             => 'concise-wire-style',   // machine id, unique within scope
    'name'             => 'Concise wire style',   // human label in dropdowns
    'instruction_text' => 'Lead with the news; ...',
    'enabled'          => true,                   // false = soft-deleted, kept for back-compat
]
```

- `slug` — kebab-case, `[a-z0-9-]{1,40}`, used as the in-URL/POST id. Stored
  as-is. Validated server-side via regex; rejected with `wp_send_json_error`
  on mismatch.
- `name` — display label, `sanitize_text_field` + 80-char cap.
- `instruction_text` — `sanitize_textarea_field` + 4,000-char cap (less than
  the per-request `instructions` cap of 5,000 to leave headroom for the
  built-in prefix).
- `enabled` — boolean. `false` hides the preset from the dropdown but keeps
  the row so existing selections don't break.

### 1.3 Limits

| Constraint | Value | Rationale |
|---|---|---|
| Max presets per author | 25 | Generous for power users; small enough to render as a `<select>` |
| Max plugin-default presets | 50 | Library can be richer than per-author quota |
| Max `instruction_text` length | 4,000 chars | Stays under the 5,000-char `instructions` field; gives 1k headroom for prefix |
| Max `name` length | 80 chars | Fits a select option on mobile |
| Max total instruction_text payload | built-in prefix (~150) + plugin-default preset (~4,000) + per-author preset (~4,000) ≈ 8,150 chars | Hard cap via token-budget filter would be a future enhancement |

### 1.4 Sanitization pipeline

Every read passes through a new `PressHub_AI_Preset_Sanitizer` (new file,
`includes/class-preset-sanitizer.php`) that:

1. Casts each entry to an array (`is_array($row) ? $row : []`).
2. Drops entries missing `slug` or `instruction_text`.
3. Re-validates the slug regex; rejects illegal slugs.
4. Truncates `name` to 80 chars, `instruction_text` to 4,000 chars.
5. Coerces `enabled` to bool.
6. De-duplicates by slug (first wins).

This runs both at read-time (defense in depth — bad data in the DB never
escapes into prompts) and at write-time (clean before storing). Same
sanitizer is reused for `OPTION` and `USER_META`.

### 1.5 Stubs needed in `tests/wordpress-stubs.php`

Currently the stubs expose `POST_META_STORE`, `OPTIONS_STORE`,
`CURRENT_USER_ID`, `CURRENT_USER_CAPS`. We need analogues for user meta
and an admin page mock:

```php
// New stubs (S effort):
if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key, $single = false ) {
        $store = $GLOBALS['USER_META_STORE'] ?? [];
        $val = $store[ $user_id ][ $key ] ?? ( $single ? '' : [] );
        return $val;
    }
}
if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $key, $value ) {
        $GLOBALS['USER_META_STORE'][ $user_id ][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_user_meta' ) ) {
    function delete_user_meta( $user_id, $key, $value = '' ) {
        unset( $GLOBALS['USER_META_STORE'][ $user_id ][ $key ] );
        return true;
    }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() {
        $id = (int) ( $GLOBALS['CURRENT_USER_ID'] ?? 0 );
        return (object) [ 'ID' => $id, 'roles' => $GLOBALS['CURRENT_USER_ROLES'] ?? [] ];
    }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'http://example.test/wp-admin/' . ltrim( $path, '/' ); }
}
```

Mirrors `POST_META_STORE` / `OPTIONS_STORE` exactly so tests can manipulate
state the same way the existing suite does. S effort (~30 lines of stubs).

---

## 2. Plugin-Level Defaults

### 2.1 Storage

WP option `presshub_ai_default_presets` — array of preset records (same
shape as §1.2). Seeded on first init with **three** curated presets:

```php
[
    [ 'slug' => 'wire-style',      'name' => 'Wire service concise', 'instruction_text' => 'Use the inverted pyramid. Lead with the news; compress context into subsequent paragraphs.', 'enabled' => true ],
    [ 'slug' => 'interview-focus', 'name' => 'Interview-driven',     'instruction_text' => 'Anchor every section in a direct quotation from the source notes.', 'enabled' => true ],
    [ 'slug' => 'fact-check',      'name' => 'Fact-check everything','instruction_text' => 'For every factual claim, add a parenthetical citing the source paragraph. Flag any unsourced claim.', 'enabled' => true ],
]
```

Seeding is idempotent (only writes when the option is empty). This keeps
new installations useful out of the box and existing installations
untouched.

### 2.2 Author override / disable

Authors can disable individual plugin-default presets by adding them to a
per-author **disabled list** (user meta
`presshub_ai_disabled_default_presets`, array of slugs). Authors cannot
**edit** the text of a plugin-default preset — they can only copy it to
their own library. This is the "copy-on-edit" model: clicking "Edit" on a
plugin default creates a per-author preset with the same slug (suffix
`-copy-<n>` if collision) and the editable text.

Effort: S. The UI is a "Use this" / "Copy to my library" pair of buttons
on the plugin-default presets list.

### 2.3 Capability gating on the option

- Read: any user with `edit_posts` (authors see the plugin-default list
  in their dropdown).
- Write (CRUD on plugin defaults): `manage_options` only. Admins edit
  plugin defaults in a dedicated submenu page (§5).

---

## 3. Runtime Composition Semantics

### 3.1 The composition pipeline (new file `includes/class-preset-resolver.php`)

A single helper:

```php
class PressHub_AI_Preset_Resolver {
    /**
     * Resolve which instruction text applies for a given user + optional
     * per-request preset slug, in the context of an endpoint type.
     *
     * @return string|null  The instruction text to append, or null if none.
     */
    public static function resolve_for_user( int $user_id, string $endpoint, ?string $preset_slug ): ?string;
}
```

The composition order (built-in → plugin-default → per-author → per-request)
is enforced here, NOT in `class-api-client.php`. The API client receives a
single composed system prompt string from the resolver and applies it as
the `apply_filters()` argument, so the new filters fit cleanly on top of
the existing pipeline without forking it.

### 3.2 Composition rules

| Precedence | Source | Combined how? |
|---|---|---|
| 0 (base) | Built-in prompt in `class-api-client.php` (e.g. *"You are a professional AI journalist."*) | Always present, unchanged |
| 1 (plugin default) | `presshub_ai_default_presets` entry whose slug matches `presshub_ai_default_preset_id` for the author | **Appended** with `\n\n` |
| 2 (per-author preset) | `presshub_ai_author_presets` entry whose slug matches the author's default preset slug | **Appended** with `\n\n` |
| 3 (per-request) | Preset the author picked in the metabox (POST `instruction_preset_id`) | **Appended** with `\n\n` (and overrides the author's default for this one request) |

Append-not-replace is a deliberate decision: the built-in framing is
editorial-policy code, not user content. Replacing it would let an author
silently change the AI's role ("You are a pirate journalist") and corrupt
the scorecard gate. We want the author content to be **additive
guidance**, not role replacement.

If any layer's text is empty after sanitization, that layer is skipped
(doesn't add a blank `\n\n`).

The final composed string goes through `apply_filters(
'presshub_ai_<endpoint>_system_prompt', $composed )` exactly like today.
This means an integration can still wrap the whole thing if needed —
backward compatible with the existing `PromptFiltersTest`.

### 3.3 Which endpoints apply

| Endpoint | Apply presets? | Rationale |
|---|---|---|
| `generate_draft()` | **YES** | Primary use case. Author is iterating on their own draft. |
| `generate_scorecard()` | **NO** | The scorecard is an editorial gate. If authors can reframe "exacting news editor", the gate stops being standardized and quality drift happens. Keeping the scorecard prompt immutable preserves a single, defensible threshold for the auto-publish-to-pending behavior in `run_review()` (line 171-173 of `class-ajax-handlers.php`). |
| `classify_intent()` | **NO** | Output is one of four literal strings; an extra paragraph would only add prompt-confusion risk. The classifier must remain deterministic. |
| `generate_audio_report()` | **NO** | The narrator framing ("professional news radio narrator") is a brand decision. Audio reports are admin-gated anyway (see `class-ajax-handlers.php` line 201), so the relevant admin can hardcode any style they want into the built-in prompt in a future PR. |
| Chat (`'chat'` intent) | **YES** (optional) | Authors iterate on ideas; presets are useful here. **Caveat**: chat's system prompt is built inline in `class-ajax-handlers.php` line 223 — moving it into the API client so the resolver can find it is part of the refactor. |
| Research (`presshub_ai_research_system_prompt`) | **YES** (optional) | The research job is initiated by the author and benefits from the same style guidance. The hook already exists; only the resolver call is new. |

**Chat and research are recommended as "YES, but optional"** because they
extend the same UX the author is already using in the metabox; if we
excluded them, an author's preset would mysteriously stop working when
they click the sidebar. The proposal is YES for both, gated behind the
same composition pipeline so the rules stay consistent.

### 3.4 Risks

- **Token budget**: composing four layers of system text can push past the
  model's context window for chat models. Mitigation: the
  4,000-char-per-preset cap + 50-preset plugin cap + the resolver
  short-circuits on the first non-empty layer so unused layers don't bloat.
- **Prompt injection via preset text**: an author could put "Ignore all
  previous instructions and ..." into their own preset. Risk is bounded
  because authors are trusted users (`edit_posts`), but document it in the
  admin UI ("Presets are appended to a fixed editorial prompt; they cannot
  replace it"). Plugin defaults are admin-only so they can't be poisoned.
- **Scorecard drift**: if we ever change our minds on §3.3, the
  `PromptFiltersTest` needs a sister assertion that
  `presshub_ai_scorecard_system_prompt` is never touched by the resolver.

---

## 4. Selection UX (per-request)

### 4.1 Metabox dropdown

Add a third control to `class-metaboxes.php:render_metabox()` between the
instructions textarea and the Generate button:

```html
<h3>Author Style Preset</h3>
<select id="presshub-ai-preset">
    <option value="__plugin_default__">— Use author's default —</option>
    <!-- one <option> per enabled author preset, plus any plugin default
         the author has copied to their library -->
</select>
<p class="description">Pick a preset to influence the system prompt. The
per-article instructions above still take precedence in the user prompt.</p>
```

Default value: whatever the author set as their default preset; falls
back to `__plugin_default__` (i.e. the resolver picks the author's default,
which itself may be the plugin default or `null`).

### 4.2 AJAX protocol change

`class-ajax-handlers.php:generate_draft()` reads one new field:

```php
$preset_slug = isset( $_POST['instruction_preset_id'] )
    ? sanitize_text_field( wp_unslash( $_POST['instruction_preset_id'] ) )
    : '';
```

The string is passed to `$api->generate_draft()` as a new 4th arg
(currently `$uploaded_files` is the 3rd). The signature becomes:

```php
public function generate_draft( $sources, $instructions, $uploaded_files = [], $preset_slug = '' );
```

**Backward compatible** — all existing call sites pass three args; the
new arg defaults to `''` (resolver returns null → no composition).

### 4.3 Multiple presets coexist — which wins per request?

Strict rule: **exactly one preset applies per request** (the most specific
layer wins, see §3.2). The UI surfaces only enabled presets in the
dropdown, so the user cannot pick an ambiguous combination. If the author
has 25 presets and the metabox dropdown is unwieldy, group by tag (future
enhancement, not in this PR).

### 4.4 Persistence: why NOT save the per-request selection on the post

Presets are a **per-author preference**, not a per-post attribute.
Persisting it to post meta would:
- Leak the author's private library state across authors (an editor viewing
  the post would see the preset slug the original author used).
- Bloat `wp_postmeta` with N×`posts` rows of duplicated data.
- Require migration if the slug is renamed later.

We do NOT save it. The only persisted preset state is the author's default
(user meta `presshub_ai_default_preset_id`).

---

## 5. UI for Managing Presets

### 5.1 Three surfaces

| Surface | Audience | Purpose |
|---|---|---|
| **Plugin submenu: `PressHub AI → Instruction Presets`** | Admins (`manage_options`) | CRUD the plugin-default presets. Seeded on first load. |
| **Author profile section: `Your Profile → AI Presets`** | Any user (`edit_posts`) | CRUD their own presets; copy a plugin default; pick a default preset |
| **Co-author metabox dropdown** (§4.1) | Authors | Per-request selection |

**Rationale for splitting**:
- A plugin submenu is the right home for plugin defaults because they
  affect every author on the site; the existing `PressHub AI` settings
  page (`class-settings.php:21-29`) is already there.
- The author profile section keeps per-user CRUD out of the global admin
  chrome. It uses WP's `show_user_profile` / `edit_user_profile` hooks
  plus a small render + AJAX handler. Authors can't edit other authors'
  presets even if they can view the profile page.
- The metabox dropdown is the only place a preset is "used", not managed.

### 5.2 Plugin submenu page

New file `includes/class-admin-presets.php`. Renders a table of plugin
default presets with inline edit rows (name, instruction_text, enabled
toggle, delete button). AJAX endpoints handle the CRUD — see §6.

### 5.3 Author profile section

Hooked via:

```php
add_action( 'show_user_profile', [ $this, 'render_author_presets_section' ] );
add_action( 'edit_user_profile', [ $this, 'render_author_presets_section' ] );
```

The render is a self-contained `<table>` + "Add preset" button + per-row
delete + a "Default preset" `<select>` populated from the author's enabled
presets plus the plugin-default slugs the author hasn't disabled.

Cap check: when an admin views another user's profile, the section still
renders but the form posts are scoped via the
`presshub_ai_preset_admin_edit_other` capability (default
`manage_options`). The form posts that capability into a hidden field so
the AJAX handler can re-check.

### 5.4 Metabox dropdown

Already covered in §4.1. The dropdown is populated via
`wp_localize_script` (`presshubAI.presets` array) or a fresh AJAX call —
proposal: localize on metabox render, because the list is small (≤25
author presets + ≤50 plugin defaults = 75 options, well under the local
script size budget).

---

## 6. API / Plumbing

### 6.1 New AJAX handlers (added to `class-ajax-handlers.php`)

| AJAX action | Capability | Purpose |
|---|---|---|
| `presshub_ai_list_presets` | `edit_posts` (own presets) or `manage_options` (any) | Returns `{ 'own': [...], 'defaults': [...], 'default_slug': '...' }` for the metabox / profile UI to render |
| `presshub_ai_save_preset` | `edit_posts` (own) or `manage_options` (plugin defaults) | Create or update a preset; returns the sanitized record |
| `presshub_ai_delete_preset` | same as save | Soft-delete (sets `enabled=false`) by default; hard-delete for admins |
| `presshub_ai_set_default_preset` | `edit_posts` | Updates user meta `presshub_ai_default_preset_id` |
| `presshub_ai_copy_default_preset` | `edit_posts` | Copies a plugin-default preset into the author's library |

All five use the same nonce (`presshub_ai_nonce`) and the same JSON
response envelope (`wp_send_json_success` / `wp_send_json_error`) as the
existing handlers.

### 6.2 New PHP classes / files

| File | Purpose |
|---|---|
| `includes/class-preset-sanitizer.php` | Pure-function sanitizer (§1.4). No state, easy to unit test. |
| `includes/class-preset-store.php` | Thin wrapper around `get_option` / `update_option` / `get_user_meta` / `update_user_meta` for presets. Static methods so it's testable without `new`. |
| `includes/class-preset-resolver.php` | Composition logic (§3.1). Pure function — takes a user_id + slug, returns the composed text. The only side-effect-free file in this design. |
| `includes/class-admin-presets.php` | Admin submenu page (plugin defaults CRUD). |
| `includes/class-author-presets.php` | Profile-section render + form POST handling for per-author CRUD. |

### 6.3 Signature changes (backward compatible)

```php
// class-api-client.php
public function generate_draft( $sources, $instructions, $uploaded_files = [], $preset_slug = '' );

// class-ajax-handlers.php — no public signature changes (extra $_POST
// reads inside generate_draft(), new methods added to the class).
```

All other public methods (`generate_scorecard`, `classify_intent`,
`generate_audio_report`) keep their current signatures — the resolver
short-circuits to null for endpoints we don't apply presets to (§3.3).

### 6.4 Filter integration

The resolver does NOT register a new `apply_filters()` hook. Instead, it
is called from `class-api-client.php` BEFORE the existing filter:

```php
// class-api-client.php — generate_draft() lines ~31-32
$sys_prompt = 'You are a professional AI journalist.';
$sys_prompt = PressHub_AI_Preset_Resolver::resolve_for_user(
    get_current_user_id(),
    'draft',
    $preset_slug
) === null
    ? $sys_prompt
    : $sys_prompt . "\n\n" . PressHub_AI_Preset_Resolver::resolve_for_user(...);

$sys_prompt = apply_filters( 'presshub_ai_draft_system_prompt', $sys_prompt );
```

(The implementation will cache the resolver call in a local var — the
above is for clarity.) This preserves the existing filter contract: a
third-party filter receives a string that already contains the author's
preset, so they can wrap or replace the whole thing if they want.

### 6.5 Rate limiting interaction

The new CRUD endpoints do NOT consume the per-user AI rate limit (they're
DB-only). They DO need their own coarse throttle to prevent a buggy
script from spamming `update_user_meta`. Proposal: a simple
"max 60 preset saves per minute per user" check via the existing
`PressHub_AI_Rate_Limiter`. Effort S; reuse existing class.

---

## 7. TDD Plan

### 7.1 New test files

| File | Targets | Effort |
|---|---|---|
| `tests/PresetSanitizerTest.php` | `PressHub_AI_Preset_Sanitizer` — shape, limits, dedupe, illegal-slug rejection | S |
| `tests/PresetStoreTest.php` | `PressHub_AI_Preset_Store` — read/write/delete for option + user meta, idempotent seed | S |
| `tests/PresetResolverTest.php` | `PressHub_AI_Preset_Resolver` — composition order (§3.2), empty-layer skip, endpoint gating (§3.3) | M |
| `tests/AuthorInstructionsTest.php` | End-to-end AJAX CRUD + permissions for the 5 new AJAX handlers; uses the existing `wp-action-wrapper.php` pattern | M |
| `tests/InstructionCompositionTest.php` | Black-box assertion on the outgoing request body: when a preset is set, the composed prompt string appears; when no preset, the original prompt is unchanged; per-endpoint gating is correct | S |

### 7.2 Stub additions required in `wordpress-stubs.php`

§1.5 enumerates them: `get_user_meta`, `update_user_meta`,
`delete_user_meta`, `wp_get_current_user`, `admin_url`. Mirror the
existing `POST_META_STORE` / `OPTIONS_STORE` patterns so the existing
test reset idioms (`unset($GLOBALS['POST_META_STORE'])`) keep working.
S effort.

### 7.3 Test cases — `InstructionCompositionTest.php` (the contract test)

Each case resets `$GLOBALS['FILTERS']`, `$GLOBALS['CAPTURED_REQUESTS']`,
`$GLOBALS['OPTIONS_STORE']`, `$GLOBALS['USER_META_STORE']`,
`$GLOBALS['CURRENT_USER_ID']`.

1. **No presets anywhere** — `generate_draft()` outgoing body contains
   only `"You are a professional AI journalist."` and the user prompt
   (no extra `\n\n` beyond what the default composition already had).
2. **Plugin default only** — set the plugin-default option to a
   single preset; outgoing body contains both the built-in and the
   preset text, separated by `\n\n`.
3. **Author default only** — set the author's default to a slug in
   their user meta; outgoing body contains the built-in + author
   preset text.
4. **Plugin + author defaults** — both present; both appear in the
   body in the order (built-in, plugin default, author preset).
5. **Per-request selection wins** — author default set, request
   passes `instruction_preset_id=other-slug`; outgoing body contains
   the per-request preset, NOT the author default.
6. **Per-request can disable** — request passes
   `instruction_preset_id=__none__` (or empty string); outgoing body
   matches case (1).
7. **Scorecard is untouched** — preset set; `generate_scorecard()`
   outgoing body contains the built-in scorecard prompt only, no
   preset text appended. (This is the §3.3 editorial gate invariant.)
8. **Classify-intent is untouched** — same assertion, different
   endpoint.
9. **Audio report is untouched** — same assertion, different
   endpoint.
10. **Sanitization strips oversized text** — preset with
    `instruction_text` of 5,000 chars gets truncated to 4,000 before
    being appended.
11. **Disabled presets are skipped** — author preset with
    `enabled=false` does NOT appear in the outgoing body, even if it's
    the author's default.
12. **Empty instruction_text is skipped** — preset with empty text
    does NOT add a blank `\n\n` separator.
13. **Backward compatibility** — `generate_draft()` called WITHOUT the
    new `$preset_slug` arg (3-arg signature) still works exactly as
    before; the new 4th arg defaults to `''` and produces the
    no-preset case.
14. **Filter still receives the composed string** — register a custom
    filter that captures the prompt; the captured value equals the
    expected composed string (built-in + plugin default + author
    preset + per-request, all in order).

### 7.4 Test cases — `AuthorInstructionsTest.php` (the AJAX contract)

For each of the 5 new AJAX handlers, cover:
- `nonce` failure → `wp_send_json_error`.
- Capability failure → `wp_send_json_error`.
- Happy path → `wp_send_json_success` with the right shape.
- Slug regex rejection → `wp_send_json_error`.
- Over-length `instruction_text` → `wp_send_json_error`.
- Author can only CRUD their own presets; admin can CRUD any.

Effort M; ~6 cases × 5 handlers = 30 cases, but most are 3-line
boilerplate.

### 7.5 Test cases — `PresetResolverTest.php`

Unit tests on the resolver alone (no AJAX):
- Composition order across all four layers.
- Endpoint gating: `resolve_for_user( $id, 'scorecard', 'slug' )`
  returns null.
- Null short-circuit on missing user.
- Dedupe by slug (plugin default + author preset with same slug →
  per-request selection wins, not duplicated).
- Empty layer skip.

### 7.6 Existing suites that MUST stay green

| File | Why |
|---|---|
| `tests/PromptFiltersTest.php` | The new resolver runs BEFORE the existing filter; if it breaks the filter chain, this fails. |
| `tests/InputLimitsTest.php` | `generate_draft()` signature changes (extra optional arg); the test must still pass the original 2 or 3 args. |
| `tests/ClassifyIntentProviderTest.php` | Endpoint gating means classify-intent is unchanged; this test asserts that. |
| `tests/ChatRoutingAdminGateTest.php` | Chat now applies presets (optional); the existing assertions about admin gating must still hold. |
| `tests/AjaxIntegrationRateLimitTest.php` | New endpoints must NOT consume the AI rate limit; this test must still pass for draft/review/chat paths. |
| `tests/RateLimiterTest.php` | No changes. |
| `tests/ResearchCleanupTest.php` | No changes. |
| `tests/WorkflowRecursionTest.php` | No changes. |
| `tests/ImagenProjectIdTest.php` | No changes. |
| `tests/SideloadMediaTest.php` | No changes. |

### 7.7 Stub migration checklist

The new user-meta stubs must:
- Not shadow real WP behavior if WP is loaded for any reason (the
  `if ( ! function_exists( ... ) )` guards handle this — same pattern as
  the existing stubs).
- Reset cleanly in the existing `reset()` helpers used by
  `InputLimitsTest::reset()`. Add a one-liner:
  `unset( $GLOBALS['USER_META_STORE'] );` to each test class's reset
  method if a new global is introduced.

### 7.8 Test execution

The existing suite is run as `php tests/FooTest.php` from anywhere (each
test file is a self-bootstrapping script). The new test files follow the
same shape. No new test runner is required. CI is unchanged — the GitHub
Actions workflow (added in commit `46f6b00`) already globs the `tests/`
directory.

---

## 8. Effort & Risk Roll-up

| Area | Effort | Risk | Notes |
|---|---|---|---|
| Stubs in `wordpress-stubs.php` | S | L (touches shared infra) | Mirror `POST_META_STORE` pattern exactly; keep reset semantics |
| `Preset_Sanitizer` + tests | S | L (garbage-in/garbage-out) | TDD first — sanitizer is the trust boundary |
| `Preset_Store` + tests | S | M | Pure DB wrapper; trivial |
| `Preset_Resolver` + tests | M | M (composition rules are the spec) | TDD first; resolve_for_user is the heart of the feature |
| API client signature change | S | M | One-line additive change; backward compatible |
| New AJAX handlers + tests | M | M | Five handlers, mostly boilerplate |
| Metabox dropdown + JS | S | L (touches `admin.js`) | Localize via `wp_localize_script`; reuse existing nonce |
| Admin submenu page | M | M | Inline-edit UX is the only novel piece; reuse WP list-table patterns |
| Author profile section | M | L (security: viewing another user's profile) | Capability-gate the form POST; render is fine |
| Composition filter integration | S | M | Reuses existing `apply_filters()` hooks; no new filter needed |
| Docs (this file) | — | — | Update status to "Approved" once a decision lands |

**Total effort**: M (~3–4 working days). Largest single risk is the
profile-section security model; everything else is incremental.

---

## 9. Open Questions (for the human reviewer)

1. **Slug uniqueness across scopes**: if a plugin default and an author
   preset share a slug, the resolver picks the per-request one. Should the
   UI *prevent* a collision (block author from naming a preset
   `wire-style` if the plugin already has one)? **Proposal: yes — block
   on save in `Preset_Sanitizer`.**
2. **Should chat presets be optional or out of scope?** §3.3 recommends
   YES for symmetry, but it does touch `class-ajax-handlers.php:223` (the
   inline chat system prompt). The minimal-risk version is to ship
   draft-only first and add chat in a follow-up.
3. **Per-post vs per-author default**: should the author be able to mark
   "always use X for posts in category Y"? Out of scope here, but worth
   noting the data model is flexible enough to add it later (slug +
   taxonomy).
4. **Migration**: existing installations have ZERO presets. Do we want a
   one-time "import from the presshub-ai-editor admin defaults" prompt?
   **Proposal: no — auto-seed the three curated presets silently on
   first init; admins can edit/delete them.**

---

## 10. Reference: file:line map

| Concern | File | Lines |
|---|---|---|
| Built-in draft system prompt | `presshub-ai-editor/includes/class-api-client.php` | 31–32 |
| Built-in scorecard prompt | `presshub-ai-editor/includes/class-api-client.php` | 44–45 |
| Built-in classify-intent prompt | `presshub-ai-editor/includes/class-api-client.php` | 64–65 |
| Built-in audio-script prompt | `presshub-ai-editor/includes/class-api-client.php` | 204–205 |
| Built-in research prompt (cron) | `presshub-ai-editor/presshub-ai-editor.php` | 111–113 |
| `generate_draft()` signature | `presshub-ai-editor/includes/class-api-client.php` | 26 |
| AJAX `generate_draft()` handler | `presshub-ai-editor/includes/class-ajax-handlers.php` | 79–141 |
| Metabox render | `presshub-ai-editor/includes/class-metaboxes.php` | 34–70 |
| Generate button JS | `presshub-ai-editor/assets/admin.js` | 39–92 |
| Settings page | `presshub-ai-editor/includes/class-settings.php` | 21–137 |
| Existing test harness | `presshub-ai-editor/tests/wordpress-stubs.php` | (whole file — new stubs in §1.5) |
| Existing prompt-filter tests | `presshub-ai-editor/tests/PromptFiltersTest.php` | (whole file — must keep green) |
| Existing input-limit tests | `presshub-ai-editor/tests/InputLimitsTest.php` | (whole file — must keep green) |
| Rate limiter (reused for new endpoints) | `presshub-ai-editor/includes/class-rate-limiter.php` | (whole file — reuse as-is) |

---

*End of proposal. Awaiting reviewer sign-off before implementation.*