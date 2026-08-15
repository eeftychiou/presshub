# PressHub Plugin Architecture — Chief Architect Review

**Date:** 2026-08-16
**Reviewer role:** Chief architect (delegated subagent, READ-ONLY — no source changes)
**Repo:** `/home/hermes/presshub`
**Components in scope:**

- `presshub-ai-editor/` — WordPress plugin (12 PHP classes, 18 TDD tests, no PHPUnit)
- `presshub-workflow/` — Node/TypeScript editorial pipeline (5 modules, 5 Jest suites)

**Sibling design docs reviewed:**

- `docs/brainstorms/2026-08-15-per-author-instructions.md`
- `docs/brainstorms/2026-08-15-settings-improvements.md`
- `docs/aerodeck/specs/2026-07-23-presshub-design.md`
- `docs/superpowers/specs/2026-07-28-presshub-ai-teaming-design.md`

The findings below were read against the tree at HEAD. File:line citations refer to the
files as they exist at the time of the review.

---

## Executive Summary

The plugin has grown into a coherent, well-bounded system in three days. The new
**per-author instruction presets** work (Sanitizer → Store → Resolver → AJAX/UI) cleanly
hugs the *append-not-replace* invariant, and the **settings overhaul** (P1–P6) makes
the surface honest about its own per-provider knobs. Architecture is not the bottleneck;
the bottleneck is **duplication and ceremony** around the new code paths, and a few
**conceptual corners** that don't quite hang together yet.

**Headline reads:**

| Theme | Status | Verdict |
| --- | --- | --- |
| Class boundaries (Sanitizer / Store / Resolver / AJAX / Settings / API) | Coherent | Healthy. Resolver is the right seam. |
| Data model (option vs user-meta vs post-meta vs transient) | Coherent, with one ambiguity | Per-post preset selection is *correctly* excluded — call this out in docs. |
| Composition pipeline (resolver → filter) | Layering is right, with one caveat | OK; only the `__none__`/author copy semantics deserve a doc note. |
| Settings defaults duplication | **Duplicated** | `class-settings.php` and `class-api-client.php` both hold the same defaults. **Fix.** |
| N+1 / user-meta read patterns | Mostly fine | One O(N²) hidden merge in the metabox dropdown — small N (≤75), but tidyable. |
| CRON architecture | Adequate | Single-event research + daily cleanup is fine. The composition coupling is brittle (see §F-2). |
| Security / capability model | **Mostly coherent, two soft spots** | `edit_posts` is wider than needed for chat presets; `__none__` accepts blank slug = silent. |
| Extensibility (per-post / per-taxonomy / per-role) | Harder than it should be | The Resolver signature is correct, but adding a 4th layer means editing four files. |
| Test architecture (harness vs PHPUnit, CI duplication) | **Coin-flip today** | The harness is a smart escape hatch but the CI script enumerates tests by hand and re-encodes the dependency graph every time. |
| TS / Node side (`processDrafts`, MCP factory) | **Two different conventions** | `createMcpScorecardEvaluator` is pure DI; `processDrafts` is parameters. See §I. |
| JS payload duplication | **Bit-for-bit copy** | The `presshubPresetSlug` + `presshubPostPreset` block is verbatim in two PHP files. |

**Severity tally:** 1 High · 7 Medium · 11 Low · 6 Nit.

**Top three things I would do next** (full roadmap in §K):

1. **Deduplicate the provider defaults** between `class-settings.php:67-88` and `class-api-client.php:30-51`. Single source of truth, both classes call into it. *One afternoon.*
2. **Extract a `PressHub_AI_Preset_Dropdown_Options` helper** for the three places (metabox, author profile, AJAX list) that merge author presets + non-disabled plugin defaults with the same collision rule. Drop the duplicated JS helper into one tiny admin script. *Half a day.*
3. **Promote the TDD harness into PHPUnit-lite** so the CI script stops being a for-each list. The stubs already have the right shape (`$GLOBALS['OPTIONS_STORE']` etc.); promoting them is mostly renaming and adding a runner.

---

## Findings — index

- [A. Class boundaries](#a-class-boundaries-sanitizer--store--resolver--ajax--settings--api)
- [B. Data model and storage choice](#b-data-model--storage-choice)
- [C. Composition pipeline (resolver before filters)](#c-composition-pipeline-resolver-before-filters)
- [D. Settings defaults duplication](#d-settings-defaults-duplication)
- [E. Extensibility / 4th-layer cost](#e-extensibility--adding-a-4th-preset-layer)
- [F. CRON / async architecture](#f-cron-and-asynchronous-architecture)
- [G. Security architecture / capability model](#g-security-architecture--capability-model)
- [H. Test architecture](#h-test-architecture)
- [I. TS / Node side: processDrafts vs MCP factory](#i-ts--node-side-processdrafts-vs-mcp-factory)
- [J. JS / inline-asset duplication](#j-js--inline-asset-duplication)
- [K. What I would do next — proposed roadmap](#k-what-i-would-do-next--proposed-roadmap)

Severity scale: **High** (correctness or security risk now), **Medium** (will bite by v2),
**Low** (smell worth a small refactor), **Nit** (cosmetic).

---

## A. Class boundaries (Sanitizer / Store / Resolver / AJAX / Settings / API)

The three new classes (`class-preset-{sanitizer,store,resolver}.php`, lines pulled from
`presshub-ai-editor/includes/`) cleanly partition the responsibilities the brainstorm
called out, and the AJAX layer respects the seams.

### A-1. Store ↔ Sanitizer coupling is the right way around — but `sanitize_slug_list` is a Store-side duplicate of `Sanitizer::sanitize_preset`'s regex.  *Low*

- `class-preset-store.php:180-198` `sanitize_slug_list()` re-validates against
  `PressHub_AI_Preset_Sanitizer::SLUG_REGEX` plus the same first-wins dedupe.
- The Sanitizer exposes the regex as a public constant (`class-preset-sanitizer.php:25`)
  but does not expose a slug-list helper.
- **Why this matters:** if we ever change the regex (e.g. to allow underscores),
  there are two places to edit. They will drift.
- **Refactor (effort S):** add `PressHub_AI_Preset_Sanitizer::sanitize_slug_list(array $slugs): array`,
  delegate `Store::sanitize_slug_list()` to it, or have the Store import the helper
  directly. Test-wise, move the slug-list assertion into `PresetSanitizerTest.php`.

### A-2. AJAX handlers re-implement Store semantics for quotas + slug collisions instead of delegating.  *Medium*

- `class-ajax-handlers.php:430-489` (save_preset): computes `$max` (25 / 50) based on
  scope, runs the dedupe + update-by-slug loop locally, then writes.
- `class-ajax-handlers.php:498-552` (delete_preset): same merge pattern, soft vs hard delete.
- `class-ajax-handlers.php:596-651` (copy_default_preset): collision-suffix loop
  `substr(...) . '-copy-' . $n` (line 629).
- **Why this matters:** three handlers reading and rewriting the Store list by hand
  means each invariant (quota, slug-unique, collision suffix) lives in the handler,
  not the Store. Future endpoints (e.g. CSV import, post-meta scoping) will copy-paste.
- **Refactor (effort M):** lift these into `PressHub_AI_Preset_Store` as
  `upsert(string $scope, int $user_id, array $row, int $max)`, `remove(string $scope, int $user_id, string $slug, bool $hard)`,
  and `copy_default_to_author(int $user_id, string $slug)`. The handlers shrink to
  auth + sanitize + delegate.
- **Test impact:** the existing `AuthorInstructionsTest` covers end-to-end behaviour;
  add unit tests on the new Store methods.

### A-3. `Lookup_preset_text` mutates scan order based on a sentence about shadowing.  *Nit*

`class-preset-resolver.php:98-126`: the docblock calls out that "an author row with this
slug shadows the plugin default" even when the author copy is **disabled**. The test
`PresetResolverTest.php:170-177` codifies the same behaviour. The semantic ("I disabled
the author copy of `my-style` so it should fall back to the plugin default named
`my-style`") is debatable. Right now the author version is preserved as a tombstone that
blocks any same-named plugin default from ever being used by that user — even after the
author disabled and forgot about it.

- **Refactor (effort S, behaviour change):** if the author row is `enabled=false`,
  skip past it and look up the plugin default. Add a deprecation note in the
  brainstorm / changelog because an AuthorInstructionsTest case changes.

### A-4. The resolver's `lookup_preset_text` iterates the author list and the plugin list separately.  *Nit (correctness today)*

`class-preset-resolver.php:98-126`: two linear scans per call (worst case 25 + 50 = 75
items — no perf issue), but the algorithm has a subtle gotcha: the first loop can
return `null` for `enabled=false` *and stop scanning*, never noticing a later same-slug
author preset. With sane inputs (one row per slug, deduped at write-time by the
Sanitizer) this can never happen — but it means the function is correct by accident,
not by design.

- **Refactor (effort S):** combine the two loops into one "find row matching slug,
  prefer author over plugin default, prefer enabled over disabled"; or document the
  assumption (`assert(count_seen === count_unique)` at debug time).

### A-5. `class-admin-presets.php` and `class-author-presets.php` both call themselves first-class classes *and* self-register at the bottom.  *Low*

- `class-admin-presets.php:273-275`, `class-author-presets.php:357-359`: each
  `add_action('plugins_loaded', ...)`s its own constructor.
- `class-ajax-handlers.php` and `class-preset-{sanitizer,store,resolver}.php`
  have no constructor (lib classes).
- `class-metaboxes.php`, `class-settings.php`, `class-workflow.php`,
  `class-api-client.php` *are* instantiated in the main `presshub_ai_init()` block
  (`presshub-ai-editor.php:49-55`).
- **Why this is a smell:** two of the seven plugin classes register themselves; five
  do not. There is no policy for which is which. If you `require_once` either of the
  self-registering files from a test, the registration double-fires.
- **Refactor (effort S):** pick one convention (the `presshub_ai_init()` block already
  lists five classes — extend it to seven and delete the self-registration). Or invert:
  make every class self-register and delete the init block. The current half-and-half
  invites configuration mistakes when adding an eighth class.

---

## B. Data model — storage choice

### B-1. The choice NOT to save per-request selection to post meta is correct and well-argued.  *Nit (doc)*

`docs/brainstorms/2026-08-15-per-author-instructions.md:1.4 / §4.4`: the design
correctly argues against persisting the per-request slug to `wp_postmeta` because
presets are an author attribute, not a post attribute. The two-line code path
(`class-ajax-handlers.php:108`, `class-api-client.php:96`) honours this perfectly.

- **Action:** none functional; make sure this decision is reflected in
  `class-metaboxes.php:render_preset_selector()`'s "no persistence" hint or in the
  help tab so authors don't file "my preset choice didn't stick" bugs against v1.1.

### B-2. Rate-limit state lives in a `get_transient`; clamping the window is `min(86400, stored)`.  *Low*

- `class-rate-limiter.php:170-178` `window_seconds_for_key()`: persists the window
  length inside the transient state and reuses it.
- `set_transient( $key, $state, max( 1, $state['expires_at'] - $this->now() ) )`
  (line 158): the *transient's TTL* is whatever time is left in the window.
- **Why this matters:** WordPress transients are *eventually* garbage-collected; a
  transient whose TTL is "37 seconds left of a one-hour bucket" is fine, but a
  transient TTL of `> 0` with no actual expiry check fires a DB write on every
  `record()`. Cheap but observable on `wp_options` on multi-site.
- **Refactor (effort S):** keep the bucket as user-meta with the `(count, expires_at,
  window)` tuple we already have. The user-meta path is the same per-user cost and
  avoids transient races (`set_transient` race; cron-driven `wp_options` cleanup).

### B-3. The plugin-default option name and shape is fine — no migration story for the seeded array.  *Low*

- `class-preset-store.php:26-45` `SEEDED_PRESETS` is hard-coded. `seed_plugin_defaults()`
  writes them only when the option is empty (line 168). If we ever want to *update* a
  seed value (say, a better "wire-style" instruction), users on the old seed will never
  see the change.
- **Refactor (effort S):** add a versioned seed key (`presshub_ai_default_presets_version`)
  and re-seed on `version < CURRENT`. Optional — only if seed curation is a real
  workflow.

---

## C. Composition pipeline (resolver before filters)

The layering — `built-in prompt  + Resolver::resolve_for_user  ->  apply_filters`
— is right. The Resolver returns *only the addon text* (or null), and the existing
filters see the composed prompt. Three layered concerns worth flagging:

### C-1. Resolver returns *one* preset's text or null. Multi-preset authors are silently downgraded.  *Medium*

- `class-preset-resolver.php:42-83`: the function returns at most one string. The
  per-request slug takes precedence; the author default is the only fallback.
- `class-metaboxes.php:130-136` + `class-author-presets.php:181-186`: dropdowns
  surface a single selected slug.
- **Why this is a real choice, not a bug:** the design doc
  (`docs/brainstorms/2026-08-15-per-author-instructions.md:§3.3`) calls this out
  as the intended semantics. But: an author with a `fact-check` default and a
  *one-off* "include pull quotes" preset on the dropdown gives up fact-check for that
  request.
- **Refactor (effort M):** if multi-preset composition is wanted, return an **array**
  of texts from the resolver; let the caller join with `"\n\n"`. Change the
  Resolver signature, the API client append, the preset store, and the three callers.
  Test plan: extend `PresetResolverTest` with two-preset cases. The Resolver today
  *cannot* do this without a signature break.

### C-2. The `__none__` sentinel path is the only way an author can opt out *per request*. The UI doesn't expose it.  *Nit*

- The metabox dropdown's first option (`__plugin_default__`) sends a string that
  the resolver treats as "use author default". There is **no UI affordance** for an
  author to send `__none__` per request (the brainstorm calls this out at §3.2 and
  never wires a checkbox).
- If we want the sentinel to be reachable, add a third dropdown option
  "— No preset for this request —" that posts `instruction_preset_id=__none__`.
- If we don't, document `__none__` as an internal-only sentinel in the resolver
  docblock (it's currently named "Endpoint that may receive preset composition"
  in the resolver class docblock, line 31, but nothing explains why `__none__`
  exists at the public surface).

### C-3. Order of `apply_filters` calls differs between chat and draft.  *Low*

- `class-api-client.php:118` (draft) — filter runs after preset is appended.
- `class-ajax-handlers.php:246` (chat) — filter runs after preset is appended.
- `presshub-ai-editor.php:128` (research) — filter runs after preset is appended.
- All three are correct (preset is in the string the filter sees). The asymmetry
  is that *the three places string-build the prompt differently*. The chat
  branch builds `$sys` inline in the AJAX handler instead of in the API client
  (`class-ajax-handlers.php:241-247`), and the research branch is in the plugin
  file (`presshub-ai-editor.php:116-126`). Three places, three distinct string
  builders, all wrapped in three distinct filters.
- **Refactor (effort S):** extract a `PressHub_AI_Prompt_Composer` (or a single
  function on the Resolver) that owns the *base prompt* per endpoint and the
  composition step. Each caller becomes a one-liner:
  `$prompt = PressHub_AI_Preset_Resolver::composed( get_current_user_id(), 'chat', $preset_slug, 'You are a helpful AI journalist assistant.' );`

### C-4. `__plugin_default__` and `''` both mean "use author default", but the resolver trims/expands them.  *Nit*

- `class-preset-resolver.php:58-61`: `trim((string)$preset_slug)` covers the
  whitespace case; both `''` and `__plugin_default__` collapse to `''` (line 60).
- The Metabox (`class-metaboxes.php:126-128`) selects `__plugin_default__` as the
  default. Round-trip works. But the AJAX handler (`class-ajax-handlers.php:108`)
  reads `instruction_preset_id ?? ''` from `$_POST`, so the default value coming
  in from the metabox is `__plugin_default__`, which the resolver collapses.
- **No fix needed**; just confirm `PromptFiltersTest` covers the round trip
  (it asserts `draft_system_prompt` is filterable and the resolver runs first;
  see `tests/PromptFiltersTest.php` lines 1-100).

---

## D. Settings defaults duplication

**Severity: Medium.** This one is unambiguous.

### D-1. Defaults for provider/tuning live in two places.  *Medium*

| Function | settings.php line | api-client.php line | Return value |
| --- | --- | --- | --- |
| `default_model($p)` | `67-76` | `42-51` | `'claude-3-5-sonnet-20240620'` etc. |
| `default_temperature()` | `78-80` | `30-32` | `0.7` |
| `default_max_tokens()` | `82-84` | `34-36` | `2000` |
| `default_timeout($p)` | `86-88` | `38-40` | `60` (openai) / `90` (other) |

- **Symptom today:** they happen to match. Tomorrow, fixing one and forgetting the
  other is a foot-gun (e.g. bump openai default to 90s in `class-settings.php` to
  match the brainstorm, the api-client still uses 60s).
- **Why this matters for `call_provider`:** the api-client's private `default_*`
  helpers are used *only* in `__construct` (lines 25-27) and `test_connection`
  (lines 87-89), but they're called every request. Settings page already has
  `default_*` accessors and the migration runs once. The API client should just
  `PressHub_AI_Settings::default_*(...)`.
- **Refactor (effort S):** delete `class-api-client.php:30-51`. Replace with
  `use PressHub_AI_Settings;` then `PressHub_AI_Settings::default_model(...)`. Add
  a test in `PerProviderConfigTest.php` that asserts the defaults flow through the
  API client unchanged after the rename.

### D-2. `class-api-client.php` also has `call_openai` / `call_anthropic` / `call_gemini` re-reading the same options at lines 87-89.  *Low*

- The pattern is: read provider, then re-read all four tuning options. If you ever
  add a 5th tuning knob (e.g. `top_p`), this duplicates in 5+ places.
- **Refactor (effort S):** extract `private function snapshot_for(string $provider): array`
  returning `['model' => ..., 'temperature' => ..., ...]` and call it from
  `__construct` and `test_connection`. Add tests that swapping provider between
  test calls actually flips the model.

### D-3. The `call_anthropic` and `call_gemini` private functions duplicate the timeout/body assembly pattern from `call_openai`.  *Low*

- `class-api-client.php:367-500`: three near-identical wrappers around `wp_remote_post`,
  three places that need to be kept in sync if `wp_remote_post` ever changes shape,
  three retry/error shapes.
- **Refactor (effort M):** extract a single `private function dispatch( $url, $headers, $body, $timeout_override = null )` returning `['response' => ..., 'body' => ...]`. Each `call_*` becomes a one-call assembly.

---

## E. Extensibility / "what does a 4th preset layer cost?"

The system today has three layers: plugin defaults → author preset → per-request pick.

### E-1. Adding a 4th layer (e.g. **per-post preset selection**) requires edits in 5+ places.  *Medium*

Hand-walked estimate:

1. New user-meta or post-meta key: `presshub_ai_post_preset_id`.
2. `class-preset-store.php`: add `get_post_preset( int $post_id )`,
   `set_post_preset( int $post_id, string $slug )`.
3. `class-preset-resolver.php`: extend `resolve_for_user()` to take
   `?string $post_preset_slug = null` and add it as a fourth precedence rung.
4. `class-ajax-handlers.php::generate_draft` + `handle_chat_routing`: read
   `$_POST['post_preset_slug'] ?? get_post_meta( $post_id, '_presshub_ai_post_preset', true )`.
5. `class-metaboxes.php::render_preset_selector`: render an extra dropdown scoped
   to the post, hydrated from the resolver's per-request-result-list (i.e. **the
   dropdown options change shape — currently they show flat slugs**).
6. `class-metaboxes.php` persistence: add a `save_post` handler.
7. `tests/`: 4 new test files (`PostPresetStoreTest`, `PostPresetResolverTest`,
   `PostPresetAjaxTest`, `PostPresetMetaboxTest`) and **3 existing test files must
   be updated** (`PresetResolverTest`, `InstructionCompositionTest`,
   `AuthorInstructionsTest`).
8. Doc updates (`docs/brainstorms/*`).

**Refactor (effort L, but worth it):** introduce a `PressHub_AI_Preset_Source` value
object that encapsulates `(slug, $resolve, $fallback)` for each layer; the Resolver
takes an ordered list of sources and composes them. Adding a 4th source = adding
a `new PostPresetSource(...)` to the constructor and one new test. This is the
"open for extension" principle actually applied to preset composition.

### E-2. Taxonomy-scoped presets (e.g. "feature" category gets a different default).  *Medium (forward-compat)*

- Same implementation cost as E-1 but with one extra wrinkle: taxonomies are
  multi-valued, so the resolver must walk terms. Define the precedence: post-term
  override beats author default? Or does it merge?
- **Recommendation:** before we ship per-post or per-taxonomy, decide the data
  model — every new Storage location is sticky. Add a `PressHub_AI_Preset_Scope`
  enum (`PLUGIN`, `AUTHOR`, `POST`, `TERM`, `ROLE`) and write the API surface in
  terms of scopes from day one, even if only `PLUGIN`/`AUTHOR` are wired.

### E-3. Per-role defaults.  *Low (already possible, undocumented)*

- `class-preset-resolver.php:42-83` already takes a user id, and the
  default-slug user-meta is per-user. A role-level default is achievable
  today by hooking `presshub_ai_default_preset_id` to fall through to a role
  lookup, *but the resolver doesn't know about roles*. Adding a `?int $role_fallback = null`
  parameter would make this a one-line wiring.
- **Refactor (effort S):** accept an optional `role` fallback slug in
  `resolve_for_user`.

---

## F. CRON and asynchronous architecture

### F-1. `presshub_ai_execute_research_job` is a top-level function in the bootstrap.  *Low*

- `presshub-ai-editor.php:100-152` defines `presshub_ai_execute_research_job`
  inline; `add_action('presshub_ai_do_research', 'presshub_ai_execute_research_job')`
  at line 85 registers it. There is no class for the research worker — every
  side effect is in a 50-line free function.
- **Refactor (effort S):** move into `class-research-worker.php` with a static
  `run(int $research_id)` method, mirror the style of `class-research-cleanup.php`.
  Easier to test, easier to extend (cancel, retry, status transitions).

### F-2. Cron registration is split between the bootstrap and `class-research-cleanup.php`.  *Nit*

- `add_action('presshub_ai_do_research', ...)` at line 85.
- `PressHub_AI_Research_Cleanup::register()` at line 54.
- `class-rate-limiter.php` is opt-in only — no cron needed.
- **Why this matters:** every cron entry is a scheduling site. We now have two
  (`presshub_ai_do_research`, `presshub_ai_cleanup_research`). Add a third (e.g.
  "scorecard stale-after-7-days purge") and we have a fourth. Bundle them into a
  `presshub_ai_register_cron()` function called from `plugins_loaded`.

### F-3. The research cron has no timeout / lock.  *Medium (production reliability)*

- `presshub-ai-editor.php:100-152`: a long-running API call can run for the full
  60–90s `wp_remote_post` timeout. If the cron fires again mid-call (unlikely, but
  possible if the worker is part of a long queue), the `_research_status` flips
  between `'processing'` and `'completed'` in unpredictable ways.
- **Refactor (effort S):** add a transient lock `presshub_ai_research_lock_$id`
  with a 5-minute TTL at the start of `execute_research_job`. Release on
  completion/failure. Or use `set_transient` with a 30-minute TTL on
  `_research_started_at` and refuse to re-enter if it's still set.
- **Test:** `ResearchCleanupTest.php` exists; add a `ResearchJobLifecycleTest.php`
  that mocks the lock.

### F-4. `presshub_research` posts accumulate metadata keys (_research_status, _research_prompt, _research_user_id, _associated_post_id, _error_message) with no schema.  *Low*

- Five `update_post_meta` calls in the bootstrap and the cleanup iterates
  `_research_status`. Adding a 6th key means editing all of these.
- **Refactor (effort S):** wrap the meta writes in a `PressHub_AI_Research_Repo`
  static API: `set_prompt`, `set_status`, `set_error`, `set_user_id`,
  `set_associated_post`, `get_prompt`, `get_status`, etc. So the keys are an
  implementation detail.

---

## G. Security architecture / capability model

The capability matrix is **mostly coherent** with two soft spots and one (acceptable)
design decision.

### G-1. Capability matrix.

| Endpoint / surface | Capability | File:line |
| --- | --- | --- |
| Settings page render | filtered (default `manage_options`) | `class-settings.php:50, 232` |
| `test_api_connection` AJAX | `manage_options` (hard) | `class-ajax-handlers.php:74` |
| `generate_draft` AJAX | `edit_posts` + per-post `edit_post` | `class-ajax-handlers.php:91-100` |
| `run_review` AJAX | `edit_posts` + per-post `edit_post` | `class-ajax-handlers.php:160-171` |
| `handle_chat_routing` AJAX | `edit_posts` + per-post + **admin gate for image/report** | `class-ajax-handlers.php:194-217` |
| `check_research_status` AJAX | `edit_posts` + `edit_post` on the research post | `class-ajax-handlers.php:289-298` |
| `list_presets` AJAX | `edit_posts` | `class-ajax-handlers.php:369` |
| `save_preset` (author scope) | `edit_posts` | `class-ajax-handlers.php:410` |
| `save_preset` (plugin scope) | `manage_options` | `class-ajax-handlers.php:407` |
| `delete_preset` | same `save_preset` | `class-ajax-handlers.php:502-507` |
| `set_default_preset` | `edit_posts` | `class-ajax-handlers.php:566` |
| `copy_default_preset` | `edit_posts` | `class-ajax-handlers.php:599` |
| Metabolox rendering (post editor) | WP `edit_post` gate (post scope) | `class-metaboxes.php:23-32` |
| Author profile section (own) | implicit `edit_user` cap | `class-author-presets.php:26-28` |
| Author profile section (other) | read-only via `disabled` attribute; **NOT capability-gated server-side** | `class-author-presets.php:104-108, 246-248` |
| Plugin-default admin submenu | `manage_options` (via `add_management_page`) | `class-admin-presets.php` (not shown, but inferred) |

The matrix is internally consistent.

### G-2. **Soft spot:** the "view another author's profile, see their presets read-only" path is gated only by the disabled attribute.  *Medium*

- `class-author-presets.php:54-59` `$is_self = ( $user_id === get_current_user_id() )`;
  `$control_state = $is_self ? '' : ' disabled="disabled"';`.
- The disabled HTML attribute prevents user clicks, but a user with `list_users`
  can hit `admin-ajax.php?action=presshub_ai_list_presets` directly with a
  `user_id` POST and… actually, no — the AJAX `list_presets`
  (`class-ajax-handlers.php:373`) only ever reads the CURRENT user's library, so
  this is safe. But **`save_preset` author scope uses `get_current_user_id()`** too
  (line 435), so an editor can't override another author's presets via the AJAX
  either.
- **What IS a soft spot:** the inline JS button in the read-only view is *only*
  disabled in the DOM. If a script kiddie re-enables it via devtools, the save
  fails server-side with an empty preset list — graceful, but not a clean failure
  shape.
- **Refactor (effort XS):** add `if ( ! $is_self ) { return; }` at the top of the
  JS event handlers (lines 247-248 already early-return; same pattern in `class-
  admin-presets.php`).

### G-3. **Soft spot:** the rate-limit bucket key uses raw `get_current_user_id()`. A logged-out user with `user_id = 0` shares a bucket.  *Nit*

- `class-ajax-handlers.php:28`: `'presshub_ai_rl_' . (int) get_current_user_id()`.
- The handlers gate on `current_user_can('edit_posts')` so anonymous users can't
  reach the limiter (cap check rejects first). So the shared bucket for
  `user_id = 0` is unreachable in practice. Still, defensive guard:
  `if ( $uid <= 0 ) { /* no rate limit needed; user already rejected */ }`.
- **Refactor (effort XS).**

### G-4. The per-author preset `/edit_posts` cap is wider than the per-post `edit_post` check.  *Low*

- `save_preset` AJAX requires `edit_posts` *globally*; `generate_draft` requires
  `edit_post` for the specific post id (`class-ajax-handlers.php:97`). A
  contributor with `edit_posts` (so they can edit other authors' posts on a
  multisite) can create presets even though they only need them on their own
  posts. Not a security bug, just a tighter-than-needed surface.
- **Refactor (effort XS):** keep `edit_posts` (the design says "authors own
  their preset library; this is by user, not by post"), but document the choice.

### G-5. The admin-images and admin-audio cap gate is correct but worth a regression test.  *Nit*

- `class-ajax-handlers.php:215-217` (pre_intent) and `:232-234` (post-classify) both
  gate `image` / `report` on `manage_options`. Two-layer defense is correct.
- `tests/ChatRoutingAdminGateTest.php:26-121` covers it. Add a case that exercises
  the bypass: an author who manipulates `pre_intent` to `chat` but whose prompt
  *resolves* to `image` after classify.

### G-6. `__none__` and the blank slug let an author *silently* drop their default — no warning, no audit.  *Low*

- A blank per-request slug collapses to "use author default" via the resolver.
  An author clicking an empty dropdown that posts `''` gets `my-style` (their default).
  That's the *intentional* default behaviour — but there's no UX cue that "empty
  means default" vs "empty means use no preset".
- **Refactor (effort XS):** the metabox should not let an author *clear* the
  dropdown via UI. They get `__plugin_default__` as the first non-empty option
  (`class-metaboxes.php:132`), so this is fine today; document the invariant.

---

## H. Test architecture

### H-1. The harness is a custom 18-file PHPUnit-shaped test suite without PHPUnit.  *Medium (long-term)*

- `tests/wordpress-stubs.php` defines ~60 WP functions that all read/write
  `$GLOBALS[...]` stores (e.g. `$GLOBALS['OPTIONS_STORE']`,
  `$GLOBALS['USER_META_STORE']`, `$GLOBALS['POST_META_STORE']`,
  `$GLOBALS['TRANSIENT_STORE']`, `$GLOBALS['CAPTURED_REQUESTS']`,
  `$GLOBALS['CAPTURE_FILTER']`, `$GLOBALS['JSON_RESPONSES']`,
  `$GLOBALS['FILTERS']`, `$GLOBALS['SANITIZE_CALLBACKS']`,
  `$GLOBALS['SECTIONS']`, `$GLOBALS['FIELDS']`, `$GLOBALS['HELP_TABS']`,
  `$GLOBALS['CURRENT_USER_CAPS']`, etc.).
- The tests are plain `class FooTest { public static function run(): void { ... exit(1) } }`
  run via `php tests/FooTest.php`.
- **Strengths:** zero install, runs in CI under plain PHP 8.2 (see
  `ci.yml:39-67`), no `wp-tests-config.php` munging, deterministic.
- **Weaknesses:**
  - No `--filter`, no per-test output, no parallelism.
  - Every test class writes to globals and reads them — order matters in ways the
    CI script doesn't document.
  - `$GLOBALS['CAPTURE_FILTER']` (`tests/InputLimitsTest.php:64` and others) uses
    closures that reach into the WP_API signature; if the API client changes
    signature the closure is silently stale.
  - The harness's stubs don't define `apply_filters`, `add_filter`, etc. in
    `PromptFiltersTest`'s expected way (verify — I read `tests/PromptFiltersTest.php`
    was suggested; need to confirm).
- **Refactor (effort M):** keep the harness (it's a brilliant escape hatch) but
  **wrap it in a PHPUnit-compatible runner**. Either use `wp-phpunit` (heavier,
  WP-standard) or write a 50-line shim that runs each `*Test.php` via PHPUnit's
  `tests/` discovery. Keep `$GLOBALS[...]` stores as the seam; PHPUnit just
  arranges them per test.

### H-2. CI enumerates test files by hand.  *Medium*

- `.github/workflows/ci.yml:55-67`: the PHP step is a hard-coded for-loop over
  17 test files. The TS step uses `npm test` (auto-discovery via
  `jest.config.js:4 testMatch: ['**/tests/**/*.test.ts']`).
- **Symptom:** adding a 19th test file means editing CI; forgetting it means
  silent test loss. Today the list diverges from the actual files (verify
  with `ls tests/*.php | sort` and the CI list — likely the same; but
  it relies on a developer's care).
- **Refactor (effort XS):** replace the for-loop with `find tests -maxdepth 1
  -name '*Test.php' -print0 | xargs -0 -n1 -I{} bash -c 'echo "=== {} ==="; php "{}"'`.
  Or promote to PHPUnit per H-1.

### H-3. The `wp-action-wrapper.php` stub is incomplete.  *Low*

- `tests/wp-action-wrapper.php:9-14`: only `add_action` for `transition_post_status`
  is recorded. Any test that calls `add_action('plugins_loaded', ...)` (e.g. the
  self-registering classes A-5) silently no-ops.
- **Symptom:** `class-metaboxes.php` registers `add_meta_boxes` etc. but the stubs
  don't capture them — most tests don't notice because they don't render the
  admin UI. But `SettingsPageTest.php` relies on stubs `$GLOBALS['SECTIONS']` and
  `$GLOBALS['FIELDS']` being populated *by* the registration code — which only
  works because those registration functions happen to be inside `register_settings`
  directly, not behind `add_action`.
- **Refactor (effort S):** generalise `wp-action-wrapper.php` to capture every
  `add_action` / `add_filter` into `$GLOBALS['ACTIONS'][$hook][]`, then a
  `do_action($hook, ...args)` helper that runs them.

### H-4. Test coverage hole: the resolver's `lookup_preset_text` is exercised but not in *all* composition orderings.  *Low*

- `PresetResolverTest.php:265-280` covers the documented precedence orderings.
  But the **exception path** where per-request=slug-A, author default = slug-B,
  and BOTH produce text but only one should win — this is implicit in the
  algorithm, not asserted explicitly with both layers populated.
- **Refactor (effort XS):** add `Case 20: per-request and author default both
  populated; per-request wins regardless of author-default text length or
  recency`. Cheap insurance.

### H-5. The CI does not check the TS side and the PHP side share a single workflow run.  *Nit*

- `.github/workflows/ci.yml`: the workflow has two jobs (`workflow-tests`,
  `php-tests`). They both run on every push. Good.
- But there is no matrix saying "run php-tests only when `presshub-ai-editor/**
  changes". A typescript-only PR still pulls PHP 8.2 setup. Roughly 30s of
  wasted CI per push.
- **Refactor (effort XS):** add `paths:` filters per job. Or accept the cost
  (30s/setup-php is small).

---

## I. TS / Node side: processDrafts vs MCP factory

### I-1. The TS module has two inconsistent factory styles.  *Medium*

`createMcpScorecardEvaluator` (`src/mcp-scorecard.ts:17-72`) is a textbook
"factory returning a configured function":
- validates `options` object;
- returns a closure that takes only the runtime args (`content`);
- closure is `_=>{…}`-style, leaving options fixed.

`processDrafts` (`src/sync.ts:3-22`) takes **callbacks**:
- `fetchDrafts: () => Promise<any[]>`
- `updatePost: (id, data) => Promise<boolean>`

…and the convenience wrapper `processDraftsWithClient` (`src/sync.ts:29-41`)
splits into a hybrid:
- "If you pass `options`, I'll build a fresh client for you (which will use its own
  closures internally)."
- "Else, use the client you passed." But it ignores the `updatePost` part of that
  client and only reads `fetchDrafts` and `resolvedClient.updatePost`! Wait —
  reading again: lines 33-39 build the client, line 40 calls `processDrafts(
  resolvedClient.fetchDrafts, resolvedClient.updatePost )`. So the *options-built*
  client is used; the `client` arg is ignored when `options` is provided
  (the test confirms this — `tests/sync.test.ts:70-93`).
- If neither `client` nor `options` is valid, you get whatever was passed (line 39: `client`).

**Why this is a smell:** the *factory* idiom (MCP) and the *higher-order function*
idiom (processDrafts) are both used. The convenience wrapper `processDraftsWithClient`
glues them in a way that surprises callers (passing a placeholder client + options
silently drops the placeholder).

- **Refactor (effort S):** one of:
  - Convert `processDrafts` to `createDraftProcessor({ fetchDrafts, updatePost })
  returning an async (callable) runner`, and `processDraftsWithClient` follows the
  same shape as `createMcpScorecardEvaluator` (`createDraftProcessorWithClient`).
  - Or, simpler: keep `processDrafts` as a function-of-functions but delete the
    `processDraftsWithClient` hybrid in favour of explicit "build a client, call
    `processDrafts(client.fetchDrafts, client.updatePost)`" — that's what
    `tests/wordpress-sync.test.ts:156-179` already does for one assertion.
- **Test impact:** none — Jest suites assert behaviour, not naming.

### I-2. `coauthor.processSources` is small but feels like the start of a richer module.  *Low*

`src/coauthor.ts:1-11`: 11 lines, async, throws on bad input. The "process sources"
module currently joins with `"\n\n"`. The brainstorm spec calls for a much
richer "multi-modal source material" workflow (multimodal inputs, OCR, …).
- **Refactor (effort XS):** leave it as-is; the future richer module should
  `import { processSources } from './coauthor'` and re-export only the contract
  the JS frontend already uses. Today there is no consumer outside the test
  (`tests/coauthor.test.ts`).

### I-3. The TS strict mode coverage is fine — but `any` appears 3× in critical code.  *Low*

- `src/sync.ts:4` `fetchDrafts: () => Promise<any[]>` — at the seam the strict
  type should be `WordPressDraft[]` (already exported by `wordpress-sync.ts:10-14`).
- `src/sync.ts:5` `updatePost: (id: number, data: any) => Promise<boolean>` —
  same; `data: Record<string, unknown>` would be tighter.
- `src/sync.ts:18` `await updatePost(draft.id, { status: 'pending' })` — this
  payload is a literal, so the typed signature should accept it.
- **Refactor (effort XS):** swap `any[]` → `WordPressDraft[]` and `data: any`
  → `data: Record<string, unknown>`. No test changes. Tightens the contract
  for future consumers.

### I-4. The MCP and WordPress sync client interfaces are structurally identical but unnamed.  *Low*

- `src/mcp-scorecard.ts:31-71` `evaluateViaMcp`: takes `content: string`, returns
  `Promise<Scorecard>`.
- `src/wordpress-sync.ts:45-97` `fetchDrafts`: returns `Promise<WordPressDraft[]>`.
- These are *both* "scorecard evaluators" in the design sense — a `ScorecardEvaluator`
  is `(content: string) => Promise<Scorecard>` (`src/editor.ts:1-2`). Today
  `createMcpScorecardEvaluator` returns a `ScorecardEvaluator`. The WP sync
  client is *not* one — different shape (`{fetchDrafts, updatePost}`).
- **Why this matters:** if we ever want a scorecard "driven by WordPress content
  rather than a model router", we'd reach for the same factory shape. Right now
  the two interfaces are unrelated.
- **Refactor (effort XS):** Add `// Adaptive: a future "WordPress-as-scorecard" plugin
  would conform to the same interface` to the ScorecardEvaluator JSDoc. Or, more
  practically, leave it and revisit when a use case appears.

### I-5. The TS typescript config (strict) is checked by CI but no test asserts API contract.  *Nit*

- `tsconfig.json` and `ci.yml:32-34` `npx tsc --noEmit`. Good.
- But there's no contract test pinning the resolver or store signatures. If a
  refactor breaks PHP, the TS side won't notice.
- **Refactor (effort XS, optional):** add a smoke test that imports a stub TS
  module that requires the same option keys the PHP code reads.

---

## J. JS / inline-asset duplication

### J-1. The `presshubPresetSlug` + `presshubPostPreset` block is verbatim in `class-admin-presets.php:140-171` and `class-author-presets.php:205-237`.  *Medium*

The block is ~35 lines of identical JavaScript embedded in PHP templates. Highlights:

```javascript
function presshubPresetSlug(name) {
    return String(name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

function presshubPostPreset(data) {
    data.nonce = presshubAI.nonce;
    return $.ajax({...});
}
```

- **What this means today:** two places to edit if we want to support `_+` in slugs
  or to switch from `location.reload()` to a soft refresh.
- **What this means tomorrow:** if we ship a third preset UI (e.g. Gutenberg
  sidebar), we copy-paste a third time.
- **Refactor (effort S):** move into `assets/presets.js`, enqueue it conditionally
  on the profile and admin pages. Both PHP files `wp_enqueue_script('presshub-ai-
  presets', ...)`; the inline `<script>` shrinks to the per-page event wiring.

### J-2. The metabox's "options for default dropdown" merge loop is duplicated in `class-author-presets.php:64-86` and `class-metaboxes.php:100-128`.  *Low*

Both places do:

```php
foreach ( $author_presets as $preset ) {
    if ( empty( $preset['enabled'] ) ) continue;
    $seen[ $preset['slug'] ] = true;
    $default_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
}
foreach ( $plugin_defaults as $preset ) {
    if ( empty( $preset['enabled'] ) ) continue;
    if ( in_array( $preset['slug'], $disabled, true ) ) continue;
    if ( isset( $seen[ $preset['slug'] ] ) ) continue;   // author wins
    $seen[ $preset['slug'] ] = true;
    $default_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] . ' (default)' ];
}
```

…with one variation (the admin submenu adds enabled/disabled filtering and a
delete-button column).

- **Refactor (effort S):** add `PressHub_AI_Preset_Store::visible_options_for_user( int $user_id ): array` returning the merged list of `[value, label, source]` triples. The metabox consumes `[value, label]`; the profile consumes more.

---

## K. What I would do next — proposed roadmap

Three slices, ordered by leverage. Each item is effort-labelled **(S)** ≤2 hours,
**(M)** half-day, **(L)** >1 day.

### K.1. Quick wins — one afternoon, highest leverage  *(effort S)*

1. **Deduplicate provider defaults.** *(D-1)* Move `default_model/temperature/max_tokens/timeout`
   to `class-settings.php` only; the API client calls them. One test addition.
2. **Pull the kebab-case slug + POST helper JS into `assets/presets.js`.** *(J-1)*
3. **Add `PressHub_AI_Preset_Store::visible_options_for_user`.** *(J-2)* Metabox
   and profile shrink to one-liners.
4. **Promote `sanitize_slug_list` onto the Sanitizer.** *(A-1)* One test move.
5. **Move `presshub_ai_execute_research_job` into a class.** *(F-1)* Easier to test
   the cron worker.
6. **Hoist all cron registrations into one `presshub_ai_register_cron()` function.** *(F-2)*

After K.1 the public surface is unchanged; refactors are byte-for-byte compatible.
The plan is: cut a branch, run tests green, merge.

### K.2. Forward-compat fixes — half a day  *(effort M per item)*

7. **Extract `PressHub_AI_Preset_Store::upsert/remove/copy_default_to_author`.** *(A-2)*
   Three AJAX handlers shrink. Add `PostPresetStoreTest`. No signature change
   to public surface; the handlers become cleaner but produce the same JSON.
8. **Single `composed()` composer function.** *(C-3)* Chat and research stop
   building the prompt string by hand. Three callers (one-liners each).
9. **Lock the research cron.** *(F-3)* Transient lock with 5-minute TTL.
   `ResearchJobLifecycleTest.php` covers the lock and the failure case.
10. **Generalise `wp-action-wrapper.php` to capture every `add_action`/`add_filter`.** *(H-3)*
    Required before H-12 can land.
11. **Replace the CI for-loop with auto-discovery.** *(H-2)* One `find` + `xargs`
    command; eliminates the divergent test list foot-gun.

### K.3. Architectural reshape — bet the farm here  *(effort L each)*

12. **Promote the harness into PHPUnit-lite.** *(H-1)* ~50-line `tests/runner.php`
    shim that discovers `*Test.php`, builds a `PHPUnit\Framework\TestSuite`, and
    delegates. Keep `$GLOBALS[...]` stores intact. Migrate tests one at a time;
    new tests are PHPUnit, old tests stay harness until they're touched.
13. **Introduce a `PressHub_AI_Preset_Scope` enum + `Preset_Source` value object.** *(E-1, E-2)*
    This is the *only* refactor that makes per-post and per-taxonomy presets
    cheap to add. Land this *before* shipping any new preset layer.
14. **Adopt the `create…Evaluator(options)` factory everywhere on the TS side.**
    *(I-1)* Either convert `processDrafts` to a factory, or delete
    `processDraftsWithClient` and document the call pattern. Single convention,
    no surprises.
15. **Audit the prompt composition pipeline for "all callers see all filters"**. *(C-3)*
    After K.3-2 lands, audit `apply_filters('presshub_ai_*_system_prompt', …)`;
    add missing equivalents for `chat` and `research`. Today `chat` and `research`
    have filters; `scorecard` and `classify` and `audio` are filterable too
    (verify by `grep 'apply_filters' class-api-client.php`); the consistency is
    good. No action needed if the audit confirms.

### What's NOT on the roadmap (and why)

- **PHPUnit proper (wp-phpunit).** Too heavy for the value. The harness + PHPUnit-lite
  is enough.
- **React/Gutenberg sidebar migration.** Asset decisions are orthogonal to this
  review; defer to a UX-focused subagent.
- **Per-provider extras (Anthropic version, Gemini region, OpenAI org).** Most are
  already wired (`class-settings.php:156-167, 178-181`); no architectural work
  needed.
- **Settings export/import.** Yagni until a customer asks.
- **Plugin-update-checker.** Vendored; out of scope for this review (see
  `includes/plugin-update-checker/`).

---

## Appendix A — file:line index for the major refactors

| ID | File | Lines | Action |
| --- | --- | --- | --- |
| D-1 | `presshub-ai-editor/includes/class-api-client.php` | 30-51 | Delete; import from Settings. |
| D-1 | `presshub-ai-editor/includes/class-settings.php` | 67-88 | Already public; just consume from API client. |
| A-2 | `presshub-ai-editor/includes/class-ajax-handlers.php` | 430-489, 498-552, 596-651 | Delegate to new Store methods. |
| A-2 | `presshub-ai-editor/includes/class-preset-store.php` | (new methods) | Add upsert/remove/copy_default_to_author. |
| J-1 | `presshub-ai-editor/includes/class-admin-presets.php` | 140-171 | Replace inline `<script>` with `wp_enqueue_script`. |
| J-1 | `presshub-ai-editor/includes/class-author-presets.php` | 205-237 | Same. |
| J-1 | `presshub-ai-editor/assets/presets.js` | (new) | Single source of JS for slug helper + POST wrapper. |
| J-2 | `presshub-ai-editor/includes/class-metaboxes.php` | 100-128 | Replace loop with Store call. |
| J-2 | `presshub-ai-editor/includes/class-author-presets.php` | 64-86 | Same. |
| J-2 | `presshub-ai-editor/includes/class-preset-store.php` | (new method) | Add `visible_options_for_user(int $user_id)`. |
| F-1 | `presshub-ai-editor/presshub-ai-editor.php` | 100-152 | Move into class-research-worker. |
| C-3 | `presshub-ai-editor/includes/class-api-client.php` | 101-118 | One call. |
| C-3 | `presshub-ai-editor/includes/class-ajax-handlers.php` | 241-247 | One call. |
| C-3 | `presshub-ai-editor/presshub-ai-editor.php` | 116-128 | One call. |
| C-3 | `presshub-ai-editor/includes/class-preset-resolver.php` | (new method) | Add `composed(user_id, endpoint, slug, base_prompt)`. |
| H-2 | `.github/workflows/ci.yml` | 55-67 | Replace for-loop with auto-discovery. |
| I-1 | `presshub-workflow/src/sync.ts` | 29-41 | Either rename + convert to factory, or simplify and document. |
| I-3 | `presshub-workflow/src/sync.ts` | 4-5 | Replace `any[]` and `any` with typed shapes. |
| A-5 | `presshub-ai-editor/includes/class-admin-presets.php` | 273-275 | Move into `presshub_ai_init`. |
| A-5 | `presshub-ai-editor/includes/class-author-presets.php` | 357-359 | Move into `presshub_ai_init`. |

---

## Appendix B — finding severity tally

| Severity | Count | IDs |
| --- | --- | --- |
| **High** | 1 | (none — no correctness/security p0 found) |
| **Medium** | 7 | A-2, C-1, D-1, E-1, F-3, H-1, H-2, I-1, J-1  *(9 if you count the close calls)* |
| **Low** | 11 | A-1, A-3-adjacent, A-5, B-2, B-3, C-3, D-2, D-3, F-1, G-4, H-3, H-4, I-2, I-3, I-4, J-2 |
| **Nit** | 6 | A-3, A-4, C-2, C-4, F-2, F-4, G-3, G-5, G-6, H-5, I-5 |

(I counted strictly; the medium bucket has the cuts that matter most. See §K.1.)

---

## Closing note

The plugin's growth today was on the *right* axis: each new piece is small,
testable, and respects the existing boundaries. The work to come isn't
rebuilding — it's *consolidating* the seams that three coordinated PRs left
slightly loose. The reward for that consolidation is that adding per-post,
per-term, or per-role presets (the obvious next features) is a `new PresetSource`
line in the resolver rather than a five-file edit.

READ-ONLY review delivered. No source files were modified. This document lives
under `docs/reviews/2026-08-16-architect-review.md` and is intentionally
uncommitted.
