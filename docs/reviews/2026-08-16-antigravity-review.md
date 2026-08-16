# PressHub AI Editor — Comprehensive Multidimensional Review

**Date:** 2026-08-16  
**Scope:** `presshub-ai-editor/` (WordPress plugin, ~3.5K LoC PHP + ~690 LoC JS) + `presshub-workflow/` (Node/TS, ~13K LoC) + CI + docs  
**Baseline:** Post-Batch-A/B fixes. 24 PHP harness suites + 67 Jest + tsc clean.  
**Method:** Full source read of every file, cross-referenced against prior reviews (`docs/reviews/`), design docs (`docs/brainstorms/`, `docs/aerodeck/`), and CI configuration.

---

## Table of Contents

1. [Security](#1-security)
2. [Correctness & Bugs](#2-correctness--bugs)
3. [Architecture](#3-architecture)
4. [Performance](#4-performance)
5. [WP Ecosystem Compliance](#5-wp-ecosystem-compliance)
6. [Test Quality](#6-test-quality)
7. [UX & Product](#7-ux--product)
8. [Documentation & Ops](#8-documentation--ops)
9. [Gaps & Roadmap](#9-gaps--roadmap)
10. [Executive Summary](#10-executive-summary)
11. [Prioritized Action Plan](#11-prioritized-action-plan)
12. [Production Readiness Verdict](#12-production-readiness-verdict)

---

## 1. SECURITY

### S-1 · Gemini API Key Leaked in URL Query String
| | |
|---|---|
| **Severity** | **High** |
| **Location** | [`class-api-client.php:481`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L481) |
| **Description** | The Gemini provider passes the API key as `?key=` in the URL: `'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->api_key`. URLs are logged by reverse proxies, CDNs, web servers (access logs), and WP's own `pre_http_request` filter consumers. |
| **Impact** | Key exposure via server logs, HTTP Referer headers, and any request-logging plugin. |
| **Fix** | Move the key to an `x-goog-api-key` header (already done for Imagen/TTS). Replace the URL with the keyless form and add `'x-goog-api-key' => $this->api_key` to the headers array. |

### S-2 · `mcp_config.json` Contains Live API Key in Git History
| | |
|---|---|
| **Severity** | **High** |
| **Location** | [`mcp_config.json:22`](file:///home/hermes/presshub/mcp_config.json#L22) |
| **Description** | The file contains `"X-Royal-MCP-API-Key:77b09e6c14eb66c8e3557635cb92011e"` — a live API key committed to git. Even after rotation, the key remains in history. |
| **Impact** | Anyone with repo read access can extract the key. |
| **Fix** | 1) Rotate the key immediately (user confirmed they will). 2) Add `mcp_config.json` to `.gitignore`. 3) Consider `git filter-repo` to purge from history (force-push required). |

### S-3 · Prompt Injection via Preset Instruction Text
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-api-client.php:119`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L119), [`class-ajax-handlers.php:276`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L276), [`presshub-ai-editor.php:146`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L146) |
| **Description** | Preset `instruction_text` is appended verbatim to the system prompt: `$sys_prompt .= "\n\n" . $preset`. A malicious author (or admin who edits plugin defaults) can craft text that overrides the fixed system prompt ("Ignore all previous instructions..."). The sanitizer validates format (slug regex, length caps) but does not filter adversarial prompt content. |
| **Impact** | An author with `edit_posts` can craft a preset that makes the AI generate harmful/off-brand content, bypass the editorial scoring (though scorecard/classify endpoints are correctly gated from receiving presets). |
| **Fix** | 1) Document that presets are "trusted author input" and that the `edit_posts` capability boundary is the trust boundary. 2) Optionally add a `presshub_ai_sanitize_preset_instruction` filter hook so site owners can add content policy checks. 3) Consider sandboxing presets in the user-message rather than the system prompt. |

### S-4 · Rate Limiter TOCTOU — Non-Atomic Check-then-Record
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-rate-limiter.php:74-103`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-rate-limiter.php#L74-L103) and [`class-ajax-handlers.php:125,179`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L125) |
| **Description** | `check()` reads the transient counter; if under the limit, the request proceeds. `record()` is called only after the API call succeeds. Between check and record, concurrent requests from the same user all pass the check (classic TOCTOU). Transients don't support atomic increment. |
| **Impact** | A burst of parallel AJAX requests can exceed the configured limit. Acceptable for cost-control but not for hard security enforcement. |
| **Fix** | 1) Record optimistically *before* the API call (decrement on failure if desired). 2) Or accept and document: "the rate limiter is a soft cost control, not a hard security boundary." |

### S-5 · Provider Error Messages Echoed Verbatim to Client
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-ajax-handlers.php:86,176,205,281,311,317`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L86) |
| **Description** | `wp_send_json_error( $result->get_error_message() )` passes raw provider error strings (which may include internal API details, rate-limit info, or model names) directly to the browser. |
| **Impact** | Information disclosure — an attacker learns provider internals, exact model names, and error patterns. |
| **Fix** | Map WP_Error codes to user-friendly messages; log the raw error server-side with `error_log()`. |

### S-6 · API Keys Stored Unencrypted in `wp_options`
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-settings.php:652-675`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L652-L675) |
| **Description** | API keys (`presshub_ai_api_key`, `presshub_ai_google_cloud_api_key`, `presshub_ai_github_token`) are stored as plaintext in `wp_options`. The `autoload=false` flag is set via `update_option( $option_name, $final, false )`, which is good, but the keys are still readable by any code that calls `get_option()`. |
| **Impact** | Database compromise exposes all API keys. Standard WP practice; most plugins do this, but worth noting. |
| **Fix** | Consider `wp_encrypt()` / `wp_decrypt()` (WP 6.5+), environment variables via `PRESSHUB_AI_KEY`, or a `define()` constant override. |

### S-7 · Upload Handling — `@unlink` Suppresses Errors
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-ajax-handlers.php:172`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L172), [`class-api-client.php:222`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L222) |
| **Description** | `@unlink( $file_path )` silently fails if the file can't be deleted. Files validated with `wp_check_filetype_and_ext` + extension whitelist + 50MB cap, so the surface is narrow, but cleanup failure is invisible. |
| **Impact** | Disk accumulation of orphaned temp files. |
| **Fix** | Remove `@` error suppression; log failures. |

### S-8 · CSRF / Nonce Coverage
| | |
|---|---|
| **Severity** | **Nit** (Positive Finding) |
| **Location** | All AJAX handlers |
| **Description** | Every AJAX handler correctly calls `check_ajax_referer( 'presshub_ai_nonce', 'nonce' )` as its first operation. All `wp_ajax_` hooks are auth'd (no `wp_ajax_nopriv_` hooks). All state-changing handlers check `current_user_can()`. The settings page uses the WP Settings API (`settings_fields()`) which handles nonces automatically. |
| **Impact** | CSRF surface is well-covered. |

### S-9 · Supply Chain — Vendored PUC Library
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`includes/plugin-update-checker/`](file:///home/hermes/presshub/presshub-ai-editor/includes/plugin-update-checker/) |
| **Description** | Plugin Update Checker (v5p7 per the loader filename) is vendored directly. No integrity check, no automated updates. The PUC README is 21KB; the library adds ~100 files. |
| **Impact** | A known PUC vulnerability wouldn't be patched without manual intervention. The `PRESSHUB_AI_SKIP_UPDATE_CHECKER` escape hatch exists. |
| **Fix** | Pin and document the PUC version; add a `composer.lock` hash or git submodule for integrity; note the version in the README. |

### S-10 · XSS / Output Escaping
| | |
|---|---|
| **Severity** | **Nit** (Mostly Positive) |
| **Location** | Throughout |
| **Description** | Settings page uses `esc_attr_safe()` / `esc_html_safe()` wrappers. Metaboxes use `esc_attr()`, `esc_html()`, `esc_textarea()`. Admin presets use `esc_attr()` / `esc_html()` everywhere. JS uses a manual `presshubEsc()` function. Scorecard results are escaped on both server (`esc_html`) and client (`presshubEsc`). The `draft` response passes through `wp_kses_post()` server-side. |
| **One Gap**: The sidebar JS renders `msg.content` directly via `wp.element.el('div', {}, msg.content)` — React's `el()` auto-escapes text children, so this is safe for text content. However, research results are inserted as `core/html` blocks (`insertBlock('core/html', { content: msg.content })`) which renders raw HTML. The server runs `wp_kses_post()` on research content before storage (`presshub-ai-editor.php:163`), so the HTML is sanitized. |

---

## 2. CORRECTNESS & BUGS

### C-1 · `wp_update_post` Return 0 Treated as Failure
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`presshub-ai-editor.php:166`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L166) |
| **Description** | `if ( is_wp_error( $updated ) || 0 === $updated )` — `wp_update_post()` returns 0 when the post is unchanged (identical content). If the AI returns the same text, research is incorrectly marked `failed`. |
| **Impact** | False failure reports for research jobs that produce identical content. |
| **Fix** | Remove `|| 0 === $updated`. Only check `is_wp_error()`. |

### C-2 · Cron Job Re-entrance — No Processing Guard
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`presshub-ai-editor.php:121-173`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L121-L173) |
| **Description** | `presshub_ai_execute_research_job()` sets `_research_status` to `processing` but doesn't check if it's *already* processing. If WP-Cron fires the same event twice (e.g., duplicate scheduling, manual CLI trigger), two concurrent API calls are made for the same research. |
| **Impact** | Wasted API calls + potential content corruption (last write wins). |
| **Fix** | Add early return: `if ( 'processing' === get_post_meta( $research_id, '_research_status', true ) ) return;` |

### C-3 · Filter Composition Order — Preset Destroyed by Full Replacement
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-api-client.php:118-122`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L118-L122), [`presshub-ai-editor.php:145-149`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L145-L149) |
| **Description** | Preset text is appended *before* `apply_filters()`. If a third-party hook returns a completely new string for `presshub_ai_draft_system_prompt`, the preset is silently lost. |
| **Impact** | Any `add_filter` on `presshub_ai_*_system_prompt` that fully replaces the value drops the user's preset. |
| **Fix** | Apply the filter to the *base* prompt first, then append the preset. Add a separate `presshub_ai_composed_system_prompt` filter for post-composition hooks. Document that the existing filters should use string concatenation. |

### C-4 · Classify Intent Falls Back Silently on API Error
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-api-client.php:162-163`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L162-L163) |
| **Description** | `if ( is_wp_error( $result ) ) { return 'chat'; }` — any API failure during classification silently routes to the chat handler, hiding the root error from the user. The API cost for the chat call is then charged to the user. |
| **Impact** | API failures are invisible; user gets a chat response instead of an error. |
| **Fix** | Log the classification error. Optionally surface: "Classification failed, defaulting to chat." |

### C-5 · `current_user_can()` Returns False in Cron Context
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-workflow.php:33`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-workflow.php#L33) |
| **Description** | The editorial workflow guard calls `current_user_can( 'edit_others_posts' )`. During WP-Cron execution, the current user is 0 (not logged in), so `current_user_can()` always returns false, causing cron-initiated `wp_update_post` calls (research job completion) to be blocked by the workflow guard and demoted to pending. |
| **Impact** | Research job completion that updates a post via `wp_update_post` may trigger unintended workflow enforcement. The research job updates only the research CPT (not regular posts), and the CPT is `public => false, show_ui => false`, which likely means the `transition_post_status` hook won't interfere — but this is fragile and depends on WP internals. |
| **Fix** | Add a `post_type` check: `if ( 'post' !== $post->post_type ) return;` at the top of `enforce_editorial_workflow()`. |

### C-6 · Uninstall Memory Exhaustion on Large Sites
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`uninstall.php:75,83`](file:///home/hermes/presshub/presshub-ai-editor/uninstall.php#L75) |
| **Description** | `get_users( [ 'fields' => 'ID' ] )` and `get_posts( [ 'posts_per_page' => -1 ] )` load all IDs into memory. On sites with millions of users or posts, this will exceed PHP memory limits. |
| **Impact** | Uninstall crashes on large sites, leaving orphaned data. |
| **Fix** | Process in batches of 200 with a `while` loop, or use direct `$wpdb->query()` for bulk deletion. |

---

## 3. ARCHITECTURE

### A-1 · Settings Class Monolith (37KB, 721 Lines)
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-settings.php`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php) |
| **Description** | `PressHub_AI_Settings` handles option registration, sanitization callbacks, field rendering (HTML), help tab, legacy migration, and masking/escaping helpers — all in one 721-line class. This violates SRP. |
| **Impact** | Hard to maintain, test, and extend. Adding new settings requires touching multiple concerns. |
| **Fix** | Split into: `class-settings-registry.php` (option registration + sanitization), `class-settings-page.php` (HTML rendering), `class-settings-migration.php` (legacy model migration). |

### A-2 · Provider Defaults Duplicated Across Two Classes
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-settings.php:67-88`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L67-L88) vs [`class-api-client.php:30-51`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L30-L51) |
| **Description** | Default model names, temperature, max_tokens, and timeout values are defined as static methods in *both* `PressHub_AI_Settings` and `PressHub_AI_API_Client` with identical implementations. If one is updated without the other, the settings page shows a different default than the API client uses. |
| **Impact** | Drift risk; maintenance burden. |
| **Fix** | Extract to a `PressHub_AI_Provider_Defaults` class or trait with a single source of truth. Have both classes reference it. |

### A-3 · AJAX Handlers Embed Store Semantics (Upsert/Remove Logic)
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-ajax-handlers.php:476-526`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L476-L526) (save_preset), [`534-589`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L534-L589) (delete_preset), [`633-688`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L633-L688) (copy_default_preset) |
| **Description** | The AJAX handlers manually iterate preset arrays, check for slug collisions, enforce quotas, and rebuild arrays — logic that should live in `PressHub_AI_Preset_Store`. The handlers should only do auth + sanitize + delegate. |
| **Impact** | Business logic is split between handlers and store; 3 independent implementations of array manipulation increase bug surface. |
| **Fix** | Add `Store::upsert()`, `Store::remove()`, `Store::copy_default_to_author()` methods that encapsulate the collection logic. Handlers become thin auth+delegate wrappers. |

### A-4 · `PressHub_AI_API_Client` Reads Options in Constructor
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-api-client.php:17-28`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L17-L28) |
| **Description** | Constructor calls `get_option()` 5 times to read provider/model/temperature/max_tokens/timeout. Then `test_connection()` overwrites these properties at lines 89-93. This makes the class hard to test (requires option stubs) and the constructor has side effects. |
| **Impact** | Prevents dependency injection; tight coupling to WP options layer. |
| **Fix** | Accept a config array or DTO in the constructor. Extract option reading to a static factory method. |

### A-5 · `excerpt()` Duplicated in Admin Presets and Author Presets
| | |
|---|---|
| **Severity** | **Nit** |
| **Location** | [`class-admin-presets.php:193-199`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-admin-presets.php#L193-L199) and [`class-author-presets.php:239-245`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-author-presets.php#L239-L245) |
| **Description** | Identical `excerpt()` method in both classes. |
| **Fix** | Extract to a shared trait or utility function. |

### A-6 · Extensibility: Presets Limited to Per-Author Scope
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-preset-resolver.php`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-preset-resolver.php) |
| **Description** | The 3-layer resolution (plugin defaults → author defaults → per-request) doesn't support per-role, per-taxonomy, or per-post presets. A sports desk can't enforce a "sports wire style" across all sports-category posts. |
| **Impact** | Limited editorial workflow flexibility for multi-desk newsrooms. |
| **Fix** | (Roadmap) Add resolution layers: post meta `_presshub_preset_override` → taxonomy term meta → role-based default → current chain. The resolver is already well-structured for this. |

---

## 4. PERFORMANCE

### P-1 · Preset Options Autoload Not Explicitly Disabled
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`class-preset-store.php:68,171`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-preset-store.php#L68) |
| **Description** | `update_option( self::OPTION_DEFAULT_PRESETS, $clean )` — no explicit `false` third argument for autoload. WP defaults to `true` for `update_option` when the option doesn't exist yet (first call creates it with autoload=yes). API key options correctly use `autoload=false`, but the presets option (serialized array of up to 50 presets with 4KB text each = ~200KB worst case) is autoloaded on every request. |
| **Impact** | Up to 200KB of preset data loaded into memory on every admin and front-end page load. |
| **Fix** | `update_option( self::OPTION_DEFAULT_PRESETS, $clean, false )`. |

### P-2 · Cleanup Cron Limited to 200 Posts per Run
| | |
|---|---|
| **Severity** | **Nit** (Positive Design) |
| **Location** | [`class-research-cleanup.php:55`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-research-cleanup.php#L55) |
| **Description** | `'posts_per_page' => 200` correctly caps each cron run. On sites with thousands of stale research logs, it may take multiple daily runs to catch up, but this prevents memory exhaustion — good design. |

### P-3 · JS Assets Loaded Only Where Needed
| | |
|---|---|
| **Severity** | **Nit** (Positive Design) |
| **Location** | [`class-metaboxes.php:10-13`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L10-L13), [`class-settings.php:36`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L36), [`class-admin-presets.php:35`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-admin-presets.php#L35), [`class-author-presets.php:38`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-author-presets.php#L38) |
| **Description** | All asset enqueue functions check the `$hook` parameter and only load on their specific admin pages. `sidebar.js` is loaded via `enqueue_block_editor_assets` (block editor only). This is correct and efficient. |

### P-4 · N+1 Reads in Uninstall
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`uninstall.php:76-80`](file:///home/hermes/presshub/presshub-ai-editor/uninstall.php#L76-L80) |
| **Description** | Loops over all user IDs calling `delete_user_meta()` 3 times per user. On a site with 10K users, this is 30K individual SQL deletes. |
| **Fix** | Use `$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (...)" )` for bulk deletion. |

---

## 5. WP ECOSYSTEM COMPLIANCE

### W-1 · i18n — Metabox Strings Entirely Unwrapped
| | |
|---|---|
| **Severity** | **High** |
| **Location** | [`class-metaboxes.php:25,38,39,43,44,50,56,58,132,139`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L25) |
| **Description** | Every user-facing string in `render_metabox()` is hardcoded English without `__()` or `esc_html__()`: "AI Co-Author & Editorial Review", "Multi-Modal Source Material", "Generate Initial Draft", "Run AI Editorial Review", "Editorial Scorecard", "Author Style Preset", etc. |
| **Impact** | WP.org would flag this. Plugin is untranslatable. |
| **Fix** | Wrap all strings in `esc_html__( '...', 'presshub-ai-editor' )`. |

### W-2 · i18n — JS Files Have No Translation Support
| | |
|---|---|
| **Severity** | **High** |
| **Location** | [`assets/sidebar.js`](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js) (all strings), [`assets/admin.js:32-34,86-94`](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L32-L34) |
| **Description** | Sidebar JS contains 15+ user-facing strings (welcome message, "Deep Research Synthesis", "Insert Research Into Article", "Send", "Clear", etc.). Admin JS has `alert()` strings. None use `wp.i18n.__()`. |
| **Impact** | All JS-rendered UI is English-only. |
| **Fix** | Add `wp-i18n` as a dependency, use `const { __ } = wp.i18n;`, wrap all strings. Generate a `.pot` file with `wp i18n make-pot`. |

### W-3 · i18n — AJAX Error Messages Are English-Only
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`class-ajax-handlers.php:75,96,117,151,234`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L75) and [`class-api-client.php:86,102,383`](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L86) |
| **Description** | Error messages like `'Permission denied.'`, `'Sources exceed the 20,000 character limit.'`, `'API key is missing.'`, `'Unsupported file type...'` are not wrapped in `__()`. |
| **Fix** | Wrap in `__( '...', 'presshub-ai-editor' )`. |

### W-4 · No `.pot` File / No `languages/` Directory
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | Plugin root |
| **Description** | The plugin header declares `Domain Path: /languages` and `Text Domain: presshub-ai-editor`, but no `languages/` directory or `.pot` file exists. |
| **Fix** | Run `wp i18n make-pot presshub-ai-editor/ presshub-ai-editor/languages/presshub-ai-editor.pot`. |

### W-5 · WP.org Submission Blockers
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | Various |
| **Description** | The WP.org plugin review team would flag: (1) Vendored PUC library (~100 files) — must be in `/vendor/` or explicitly documented. (2) External HTTP requests without user consent disclosure (API calls to OpenAI/Anthropic/Google/GitHub/picsum.photos). (3) `file_put_contents()` usage in `sideload_media()` ([api-client.php:210](file:///home/hermes/presshub/presshub-ai-editor/includes/class-api-client.php#L210)). (4) `@unlink()` error suppression. (5) i18n incompleteness. (6) Mock fallbacks fetching from external URLs (`picsum.photos`, `github.com/anars/blank-audio`). |
| **Fix** | Address each before any WP.org submission. For non-WP.org distribution (GitHub releases), these are informational. |

### W-6 · Multisite Compatibility — Options Are Site-Specific (Correct)
| | |
|---|---|
| **Severity** | **Nit** (Positive) |
| **Location** | All `get_option()` / `update_option()` calls |
| **Description** | All options use the standard `wp_options` table, which is per-site in multisite. This means each subsite gets its own API keys, provider config, and presets. This is the correct behavior for a plugin where each site may have different AI needs. No `get_site_option()` or network-wide settings exist, which is fine for the current design. |

### W-7 · Plugin Lifecycle — Deactivation/Uninstall Present
| | |
|---|---|
| **Severity** | **Nit** (Positive) |
| **Location** | [`presshub-ai-editor.php:42-46`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L42-L46), [`uninstall.php`](file:///home/hermes/presshub/presshub-ai-editor/uninstall.php) |
| **Description** | Deactivation clears both crons. Uninstall removes all options, user metas, and research posts. Belt-and-braces cron clearing in both. |

---

## 6. TEST QUALITY

### T-1 · Custom Harness Instead of PHPUnit
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`tests/wordpress-stubs.php`](file:///home/hermes/presshub/presshub-ai-editor/tests/wordpress-stubs.php) (508 lines) |
| **Description** | The test suite uses a hand-rolled `wordpress-stubs.php` that re-implements WP functions (`get_option`, `update_option`, `wp_remote_post`, `wp_create_nonce`, `check_ajax_referer`, `current_user_can`, etc.) using `$GLOBALS` as backing store. Tests use `assert(condition) or exit(1)` instead of a test framework. This works but: (1) stubs may diverge from real WP behavior, (2) no test runner features (parallel execution, reporting, fixtures), (3) no code coverage. |
| **Impact** | Tests are fast and hermetic, but fragile long-term. A stub bug can mask real issues. |
| **Fix** | Phase 1: Create a `phpunit.xml` that auto-discovers `tests/*Test.php`. Wrap existing asserts in `PHPUnit\Framework\TestCase::assert*()`. Phase 2: Use `WP_Mock` or `Brain\Monkey` for WP function stubbing. |

### T-2 · CI Hand-Enumerates Every Test File
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`ci.yml:56-70`](file:///home/hermes/presshub/.github/workflows/ci.yml#L56-L70) |
| **Description** | The CI workflow uses a hardcoded `for t in tests/WorkflowRecursionTest.php tests/ImagenProjectIdTest.php ...` list. Adding a new test file requires remembering to update CI. |
| **Impact** | New tests silently excluded from CI if the developer forgets to add them. |
| **Fix** | `for t in tests/*Test.php; do echo "=== $t ==="; php "$t"; done` — or migrate to PHPUnit with `phpunit.xml` auto-discovery. |

### T-3 · No Frontend JS Tests
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | `assets/admin.js`, `assets/sidebar.js`, `assets/presets.js` |
| **Description** | 690 lines of client-side JavaScript (jQuery + WP components + React) with zero test coverage. The sidebar chat flow, research polling, preset CRUD, and draft insertion are all untested. |
| **Impact** | UI regressions are invisible until manual testing. |
| **Fix** | Add Jest tests with `@wordpress/scripts` for the sidebar component. Consider Playwright E2E tests for the admin flows. |

### T-4 · No Integration Tests Against Real WordPress
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | N/A |
| **Description** | All PHP tests run against stubs. No test boots a real WordPress instance. This means: real nonce behavior, real `wp_update_post` transitions, real cron scheduling, and real Settings API form processing are untested. |
| **Impact** | Bugs that only manifest in a real WP environment (e.g., `transition_post_status` hook ordering, cron timing) are missed. |
| **Fix** | Add a `wp-env`-based integration test suite for critical paths: editorial workflow, research job execution, settings save flow. |

### T-5 · Positive: High Unit Coverage of Business Logic
| | |
|---|---|
| **Severity** | **Nit** (Positive) |
| **Location** | 24 PHP test files + 5 TS test files |
| **Description** | The test suite covers: preset sanitizer (boundary conditions, dedup, truncation), preset store (CRUD, seeding, slug validation), preset resolver (3-layer resolution, sentinels, endpoint gating), rate limiter (check/record, window expiry, enabled/disabled, TOCTOU documentation), workflow recursion guard, upload validation, input limits, instruction composition, settings page rendering, settings sanitization, provider config, metabox rendering, uninstall cleanup, research cleanup, and more. This is comprehensive for the current codebase. |

---

## 7. UX & PRODUCT

### U-1 · No Onboarding Flow for New Users
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | Plugin-wide |
| **Description** | After activation, there's no guided setup wizard, no admin notice pointing to settings, and no contextual help in the post editor explaining the AI Co-Pilot. A journalist opening the post editor for the first time sees a metabox with no explanation of what "Sources" vs "Instructions" means, or why they'd pick a preset. |
| **Impact** | High friction for non-technical journalists — the target audience. |
| **Fix** | 1) Show an admin notice after activation linking to settings. 2) Add `?tab=intro` parameter to the settings page for a guided walkthrough. 3) Add `description` text beneath each metabox section explaining the workflow. |

### U-2 · `alert()` Used for Success/Error Feedback
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`admin.js:86,88,94,125,127`](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L86) |
| **Description** | Browser `alert()` for "Draft generated successfully!", "Error: ...", and "Server connection error." is jarring and blocks the UI. Modern WP plugins use inline notices (`wp.data.dispatch('core/notices').createNotice()`). |
| **Fix** | Replace `alert()` with WP admin notices (for classic editor) or Gutenberg notice API (for block editor). |

### U-3 · Sidebar Has No Conversation Persistence
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [`sidebar.js:13`](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L13) |
| **Description** | Chat messages live in React `useState` — closing and reopening the sidebar, or navigating away, clears the entire conversation. The "Clear" button has no confirmation. |
| **Impact** | Research results and chat context are lost if the sidebar is accidentally closed. |
| **Fix** | Persist messages to `sessionStorage` or post meta. Add a confirmation dialog to "Clear". |

### U-4 · Missing Features for Journalists
| | |
|---|---|
| **Severity** | **Low** |
| **Location** | N/A — feature gaps |
| **Description** | Key features for the target audience that are absent: (1) **Track changes** — no diff of what the AI changed vs the original draft. (2) **Version history** — AI drafts don't create WP revisions. (3) **Tone-of-voice preview** — no way to preview how a preset will affect output. (4) **Collaborative review** — no multi-reviewer scorecard aggregation. (5) **Custom taxonomy support** — can't scope presets to news categories (sports, politics, etc.). |

---

## 8. DOCUMENTATION & OPS

### D-1 · No README at Repository Root
| | |
|---|---|
| **Severity** | **High** |
| **Location** | `/home/hermes/presshub/` |
| **Description** | No `README.md` exists at the repo root. Zero onboarding context for contributors: no setup instructions, no architecture overview, no testing commands, no deployment guidance. |
| **Fix** | Create a comprehensive README covering: project overview, installation (WP plugin + optional workflow module), configuration (API keys, providers, Google Cloud), testing (`php tests/*Test.php`, `npm test`), architecture, and contributing guidelines. |

### D-2 · WP-Cron Caveat Documented Only in Code Comments
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [`presshub-ai-editor.php:108-120`](file:///home/hermes/presshub/presshub-ai-editor/presshub-ai-editor.php#L108-L120) |
| **Description** | The WP-Cron dependency and external cron recommendation are only in a PHP code comment. No admin UI notice, no README section, no docs page. On low-traffic sites, research jobs may sit pending indefinitely. |
| **Fix** | 1) Show a notice on the settings page if `DISABLE_WP_CRON` is not defined. 2) Document in README and a deployment guide. |

### D-3 · No Logging / Observability
| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | Plugin-wide |
| **Description** | No `error_log()`, no WP debug log integration, no structured logging. API failures, rate limit hits, cron job outcomes, and preset mutations are invisible unless you read the code. |
| **Fix** | Add a logging utility that writes to `error_log()` when `WP_DEBUG_LOG` is enabled. Log: API call outcomes (provider, model, duration, success/error), rate limit events, cron job execution, and preset mutations. |

### D-4 · Design Docs Are Comprehensive (Positive)
| | |
|---|---|
| **Severity** | **Nit** (Positive) |
| **Location** | [`docs/`](file:///home/hermes/presshub/docs/) |
| **Description** | The `docs/` directory contains excellent design specs (per-author instructions, settings improvements), original architectural designs, three prior code reviews with verified synthesis, and implementation plans. This is strong documentation discipline for a project of this size. |

---

## 9. GAPS & ROADMAP

### Gap Analysis vs Design Docs

| Design Doc | Feature | Status |
|---|---|---|
| `2026-08-15-per-author-instructions.md` | 3-layer preset resolution | ✅ Implemented |
| `2026-08-15-per-author-instructions.md` | Endpoint gating (scorecard/classify excluded) | ✅ Implemented |
| `2026-08-15-per-author-instructions.md` | Per-request AJAX selection | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Settings API sectioning (P1) | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Per-provider model/tuning (P2) | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Capability gate (P3) | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Masked API keys (P4) | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Sanitize callbacks (P5) | ✅ Implemented |
| `2026-08-15-settings-improvements.md` | Provider extras (P6) | ✅ Implemented |
| `00-synthesis` Batch A | Correctness quick wins | ✅ Implemented |
| `00-synthesis` Batch B | Plugin lifecycle & WP.org readiness | ✅ Implemented |
| `00-synthesis` Batch C | Architecture refactors | ⏳ Not started |
| `2026-07-28-presshub-ai-teaming-design.md` | MCP scorecard + editor integration | ✅ In workflow module |
| General | i18n sweep | ❌ Missing |
| General | PHPUnit migration | ❌ Missing |
| General | README | ❌ Missing |

### Tech Debt Inventory

1. **Provider defaults duplication** (Settings ↔ API Client)
2. **AJAX handler Store logic** (3 handlers embed collection manipulation)
3. **Custom test harness** (scaling ceiling)
4. **CI hand-enumerated test list** (drift risk)
5. **`excerpt()` duplication** (AdminPresets ↔ AuthorPresets)
6. **Gemini API key in URL** (security debt)
7. **No logging** (operational debt)
8. **No `.pot` file** (i18n debt)
9. **Uninstall N+1** (performance debt for large sites)
10. **`file_put_contents` in sideload** (WP.org compliance debt)

### Phased Action Plan

#### Phase 1: 0-30 Days — Critical & Quick Wins

| # | Item | Effort | Value |
|---|---|---|---|
| 1 | **Gemini API key → header** (S-1) | S | Critical |
| 2 | **Rotate `mcp_config.json` key + .gitignore** (S-2) | S | Critical |
| 3 | **Fix `wp_update_post` return check** (C-1) | S | High |
| 4 | **Add cron processing guard** (C-2) | S | Medium |
| 5 | **Metabox i18n wrap** (W-1) | S | High |
| 6 | **AJAX error message i18n** (W-3) | S | Medium |
| 7 | **CI glob test discovery** (T-2) | S | Medium |
| 8 | **Create README.md** (D-1) | S | High |
| 9 | **Error logging utility** (D-3) | S | Medium |
| 10 | **Preset option autoload=false** (P-1) | S | Low |

#### Phase 2: 30-90 Days — Architecture & Quality

| # | Item | Effort | Value |
|---|---|---|---|
| 11 | **Sidebar JS i18n** (W-2) | M | High |
| 12 | **Store::upsert/remove delegation** (A-3) | M | Medium |
| 13 | **Provider defaults deduplication** (A-2) | S | Medium |
| 14 | **Filter composition order fix** (C-3) | S | Medium |
| 15 | **Workflow post_type guard** (C-5) | S | Low |
| 16 | **Replace `alert()` with WP notices** (U-2) | M | Medium |
| 17 | **Onboarding notice + guided setup** (U-1) | M | Medium |
| 18 | **Generate `.pot` file** (W-4) | S | Medium |
| 19 | **Map provider errors to user messages** (S-5) | M | Medium |
| 20 | **Settings class split** (A-1) | M | Low |

#### Phase 3: 90+ Days — Strategic

| # | Item | Effort | Value |
|---|---|---|---|
| 21 | **PHPUnit migration** (T-1) | L | Medium |
| 22 | **Frontend JS tests** (T-3) | L | Medium |
| 23 | **wp-env integration tests** (T-4) | L | Medium |
| 24 | **Sidebar conversation persistence** (U-3) | M | Low |
| 25 | **Per-role / per-taxonomy presets** (A-6) | L | Medium |
| 26 | **Track changes / diff view** (U-4) | L | High |
| 27 | **API key encryption** (S-6) | M | Low |
| 28 | **Uninstall batch deletion** (C-6, P-4) | S | Low |
| 29 | **WP.org submission prep** (W-5) | L | Depends |

---

## 10. EXECUTIVE SUMMARY — TOP 10 ISSUES

| # | Severity | Dimension | Issue | Location |
|---|---|---|---|---|
| 1 | **High** | Security | Gemini API key exposed in URL query string | `class-api-client.php:481` |
| 2 | **High** | Security | Live API key in git history (`mcp_config.json`) | `mcp_config.json:22` |
| 3 | **High** | WP Compliance | i18n: metabox strings entirely unwrapped | `class-metaboxes.php` |
| 4 | **High** | WP Compliance | i18n: all sidebar JS strings untranslatable | `sidebar.js` |
| 5 | **High** | Docs | No README at repository root | repo root |
| 6 | **Medium** | Correctness | `wp_update_post` return 0 falsely marks research failed | `presshub-ai-editor.php:166` |
| 7 | **Medium** | Architecture | Provider defaults duplicated across 2 classes | `class-settings.php` ↔ `class-api-client.php` |
| 8 | **Medium** | Security | Rate limiter TOCTOU — concurrent requests bypass limit | `class-rate-limiter.php` |
| 9 | **Medium** | Architecture | AJAX handlers embed Store collection logic | `class-ajax-handlers.php` |
| 10 | **Medium** | Tests | CI hand-enumerates test files; new tests can be missed | `ci.yml:56-70` |

---

## 11. PRIORITIZED ACTION PLAN

| Item | Effort | Value | Risk if Ignored | Phase |
|---|---|---|---|---|
| Gemini key → header | S | Critical | Key exposure | 0-30d |
| Rotate mcp_config key | S | Critical | Active credential leak | 0-30d |
| Metabox + AJAX i18n | S | High | WP.org rejection; untranslatable | 0-30d |
| README.md | S | High | No contributor onboarding | 0-30d |
| `wp_update_post` return fix | S | High | False research failures | 0-30d |
| Cron processing guard | S | Medium | Duplicate API calls | 0-30d |
| CI glob discovery | S | Medium | Silent test omissions | 0-30d |
| Error logging | S | Medium | Zero observability | 0-30d |
| Sidebar JS i18n | M | High | English-only UI | 30-90d |
| Store delegation | M | Medium | Bug surface from duplicated logic | 30-90d |
| Filter composition fix | S | Medium | Presets silently dropped | 30-90d |
| Provider defaults dedup | S | Medium | Drift between Settings and API | 30-90d |
| Replace `alert()` | M | Medium | Poor UX | 30-90d |
| Settings class split | M | Low | Maintenance burden | 30-90d |
| PHPUnit migration | L | Medium | Testing ceiling | 90+d |
| Frontend JS tests | L | Medium | UI regressions invisible | 90+d |
| Track changes UX | L | High | Key journalist feature gap | 90+d |
| Per-role presets | L | Medium | Multi-desk newsroom support | 90+d |

---

## 12. PRODUCTION READINESS VERDICT

### Verdict: **Conditionally Ready** for controlled deployment; **Not Ready** for WP.org or broad distribution.

**The plugin is production-viable today** for a single-tenant newsroom with these conditions:

> [!IMPORTANT]
> **Must-fix before any production use:**
> 1. Move the Gemini API key from URL to header (S-1) — 15-minute fix
> 2. Rotate the `mcp_config.json` key and add to `.gitignore` (S-2)
> 3. Fix the `wp_update_post` return check (C-1) — 1-line fix

> [!WARNING]
> **Must-fix before multi-tenant / shared hosting:**
> 4. Add cron processing guard (C-2)
> 5. Document rate limiter TOCTOU as soft cost control (S-4)
> 6. Map provider errors to user-friendly messages (S-5)

> [!CAUTION]
> **Must-fix before WP.org submission:**
> 7. Complete i18n sweep (W-1, W-2, W-3, W-4) — largest effort
> 8. Remove or document vendored PUC (W-5)
> 9. Replace `file_put_contents` with WP filesystem API
> 10. Remove mock fallbacks to external URLs (picsum.photos, github.com)

**Strengths of the codebase:**
- Solid authn/authz on every AJAX handler (nonce + capability checks)
- Well-designed preset resolver with clean 3-layer composition
- Comprehensive sanitization pipeline for preset data
- 24 PHP test suites covering critical business logic
- Good asset loading discipline (scripts only on relevant pages)
- Clean plugin lifecycle (deactivation clears crons, uninstall cleans data)
- Thoughtful editorial workflow guard with recursion prevention
- Rate limiter with opt-in design and configurable window

**Primary risks:**
- i18n incompleteness prevents international deployment
- No observability (logging) makes production debugging difficult
- Test harness divergence from real WP may mask integration bugs
- Provider defaults duplication is a ticking time bomb for configuration drift
