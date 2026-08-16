# PressHub AI Editor — Comprehensive UI/UX Review Report

**Date**: 2026-08-16  
**Scope**: Read-only review of all user-facing admin surfaces  
**Plugin version**: 1.1.0  

---

## Table of Contents

1. [Finding Catalog (by Dimension)](#1-finding-catalog)
2. [Top 10 UX Issues](#2-top-10-ux-issues)
3. [Prioritized Improvement Plan](#3-prioritized-improvement-plan)
4. [Overall UX Maturity Verdict](#4-verdict)

---

## 1. Finding Catalog

### Dimension 1: Usability & Workflow Friction

#### F-01 · `alert()` interrupts the journalist's flow after every action

| | |
|---|---|
| **Severity** | **Critical** |
| **Location** | [admin.js:86](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L86), [admin.js:88](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L88), [admin.js:94](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L94), [admin.js:125](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L125), [admin.js:127](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L127) |
| **Description** | Both "Generate Draft" and "Run AI Editorial Review" use native `alert()` for **every** outcome — success and failure. `alert()` is a modal dialog that steals focus, blocks the browser thread, and cannot be styled or dismissed with Escape in some browsers. A journalist generating 5 drafts in a session sees 5 intrusive popups. |
| **Impact** | Major flow disruption. The journalist must click OK on a success alert before they can read the draft the AI just inserted. It also means error and success look identical (a browser-chrome dialog box with text). |
| **Recommended fix** | Replace all `alert()` calls with WordPress admin notices (`wp.data.dispatch('core/notices').createNotice()` in block editor, or an inline `<div class="notice notice-success">` in the metabox results area). Errors should use `notice-error` with a "Try again" button. |

---

#### F-02 · Draft is silently appended — no indication of _what_ changed

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [admin.js:79-85](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L79-L85) |
| **Description** | The generated draft is inserted as a single `core/paragraph` block at the bottom of the editor. There is no diff view, no highlighting, and no "Undo" affordance surfaced in the metabox. The journalist cannot compare the AI's output against their source material without scrolling to the bottom and reading it cold. |
| **Impact** | Journalists working on existing content have no way to see what the AI added without manual comparison. Using a single `core/paragraph` block for potentially long multi-paragraph HTML content mangles the structure. |
| **Recommended fix** | (1) Parse the HTML into multiple blocks (`core/html` or `core/freeform`). (2) Wrap inserted blocks in a group block with a visual marker. (3) Show a "Draft inserted — scroll to view" snackbar with an "Undo" action. (4) Long-term: offer a side-by-side diff preview before insertion. |

---

#### F-03 · No input validation before expensive AI operations

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [admin.js:43-97](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L43-L97) |
| **Description** | Clicking "Generate Initial Draft" with empty sources AND empty instructions fires the AJAX request anyway. The server processes it (hitting the AI API with essentially no input). Similarly, "Run AI Editorial Review" fires on an empty editor — burning a rate-limit token and returning a useless scorecard. |
| **Impact** | Wastes API credits and rate-limit budget. User gets a confusing low-quality result. |
| **Recommended fix** | Client-side guard: disable the buttons when inputs are empty; or validate and show an inline warning ("Please provide sources or instructions before generating."). Mirror the sidebar's `disabled: loading \|\| !inputValue.trim()` pattern. |

---

#### F-04 · Metabox file input gives zero feedback after file selection

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [class-metaboxes.php:40](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L40) |
| **Description** | The `<input type="file" multiple>` is a bare browser control. After selecting 3 PDFs and 2 MP3s, the user sees a generic "5 files" label. No file names, sizes, types, or remove-per-file controls. The accepted types (`.wav`, `.m4a`) in the `accept` attribute are not mentioned in the description text ("PDF, DOCX, MP3, MP4"). |
| **Impact** | Users don't know what they attached or whether the right files were selected. The description is incomplete (WAV and M4A are silently accepted). |
| **Recommended fix** | (1) Update description text: "PDF, DOCX, MP3, MP4, WAV, M4A". (2) Add a JS file-list preview showing name + size + a ✕ remove button per file. (3) Show a file-count badge next to the Generate button. |

---

#### F-05 · Preset dropdown label "— Use my default —" is ambiguous

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [class-metaboxes.php:134](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L134) |
| **Description** | "— Use my default —" doesn't tell the user WHICH preset is their default, or whether they have one configured at all. If no default is set, the user doesn't know if selecting this option does nothing or applies the first plugin default. |
| **Impact** | Confusion about what system prompt is being applied. Power users may not realize they need to set a default on their profile. |
| **Recommended fix** | Dynamically label: "— Use my default: *Wire service concise* —" when a default is set, or "— No default set (none applied) —" when unset. Add a "(configure)" link to the profile section. |

---

#### F-06 · Preset management requires navigating away from the editor

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [class-metaboxes.php:132-139](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L132-L139) |
| **Description** | The preset dropdown is a static `<select>` populated at page load. To add/edit/manage presets, the user must navigate to their profile page or the admin presets page. No link from the metabox to those pages. |
| **Impact** | Discovery problem — new users won't know presets exist or where to manage them. |
| **Recommended fix** | Add a small "Manage presets →" link below the dropdown, pointing to the author's profile page `#presshub-ai-author-presets`. |

---

### Dimension 2: Information Architecture

#### F-07 · Settings page shows ALL providers' fields simultaneously — overwhelming

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [class-settings.php:222-228](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L222-L228) |
| **Description** | The Providers section renders Model, Temperature, Max Tokens, and Timeout fields for all 3 providers (OpenAI, Anthropic, Gemini) at once = 12 fields visible, plus API Key, plus per-provider extras. This is a wall of inputs where most admins only use one provider. |
| **Impact** | Visual overload. Admins may misconfigure the wrong provider's settings. The page scrolls for a long time before reaching the Test Connection buttons. |
| **Recommended fix** | Use conditional display: show only the active provider's tuning fields by default. Add a "Show all providers" toggle, or use a tabbed/accordion layout within the Providers section. Use `jQuery` to toggle visibility based on the provider `<select>` value. |

---

#### F-08 · "Research Retention" field is in the wrong section

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [class-settings.php:240](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L240) |
| **Description** | "Research Log Retention (days)" is placed in the "Rate Limits" section. Research retention is a data-lifecycle concern, not a rate-limiting concern. |
| **Impact** | Admin looking for research settings won't look under "Rate Limits". |
| **Recommended fix** | Create a "Data Management" section or move it to General. Alternatively, rename the section to "Rate Limits & Data". |

---

#### F-09 · Preset management pages are hard to discover

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [class-admin-presets.php:74-83](file:///home/hermes/presshub/presshub-ai-editor/includes/class-admin-presets.php#L74-L83), [class-author-presets.php:28-29](file:///home/hermes/presshub/presshub-ai-editor/includes/class-author-presets.php#L28-L29) |
| **Description** | Admin presets are under `Settings → PressHub AI Presets` (a sub-item of Settings), while author presets are on the user profile page (a completely different WP admin area). There's no cross-linking between the PressHub AI settings page, the presets page, the profile section, and the metabox. |
| **Impact** | Users don't know the preset system exists unless they stumble upon it. |
| **Recommended fix** | (1) Add a notice/link on the main settings page: "Manage instruction presets →". (2) Add a link from the metabox preset dropdown to the profile section. (3) Consider consolidating under a PressHub AI top-level admin menu with sub-pages. |

---

#### F-10 · Sidebar chat assistant lacks intent documentation for users

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:14](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L14), [sidebar.js:232](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L232) |
| **Description** | The sidebar's welcome message mentions it can "chat, conduct in-depth research, generate images, or summarize media" but provides no examples of how to trigger each intent. Intent classification is done server-side by an LLM — users have no idea what phrasing triggers research vs. chat. |
| **Impact** | Users type "research X" and get a chat response, or vice versa. The black-box intent classifier is opaque. |
| **Recommended fix** | (1) Add quick-action buttons above the textarea: "📝 Chat", "🔍 Research", "🎨 Image", "🎙️ Audio". (2) Or show example prompts in the welcome message. (3) The textarea placeholder could cycle through examples. |

---

### Dimension 3: Functionality Gaps & Bugs

#### F-11 · Missing `.fail()` handler on `run_review` AJAX — UI hangs on network error

| | |
|---|---|
| **Severity** | **Critical** |
| **Location** | [admin.js:114-129](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L114-L129) |
| **Description** | The `$.post()` call for `presshub_ai_run_review` has a success callback but **no `.fail()` handler**. If the server returns a 500, a network timeout, or a CORS error, the spinner remains active forever and the button stays disabled. The user is stuck. |
| **Impact** | **Dead UI state**. The metabox becomes unusable until a page refresh. This is the most straightforward bug in the codebase. |
| **Recommended fix** | Add `.fail(function() { ... })` matching the pattern used in the test-api and generate-draft handlers: stop spinner, re-enable button, show inline error. |

---

#### F-12 · Sidebar chat has no keyboard submit (Enter key)

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [sidebar.js:229-234](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L229-L234) |
| **Description** | The `TextareaControl` does not handle the Enter key for sending. Users must click the "Send" button with the mouse. There's no `onKeyDown` handler for Enter-to-send (or Ctrl+Enter). |
| **Impact** | Breaks the standard chat UX expectation. Keyboard-centric journalists are forced to reach for the mouse after every message. |
| **Recommended fix** | Add an `onKeyDown` handler: Enter sends (for single-line messages), Shift+Enter adds a newline. Or Ctrl+Enter to send. |

---

#### F-13 · Metabox inputs have no `<label>` elements — accessibility failure

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [class-metaboxes.php:38-45](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L38-L45), [class-metaboxes.php:132-138](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L132-L138) |
| **Description** | The file input, sources textarea, instructions textarea, and preset select all use `<h3>` headings as visual labels but have no `<label for="...">` association. Screen readers cannot identify which control belongs to which label. |
| **Impact** | WCAG 2.1 Level A violation (1.3.1 Info and Relationships). Inaccessible to screen reader users. |
| **Recommended fix** | Wrap each `<h3>` in a `<label for="presshub-ai-XYZ">` or add `aria-labelledby` attributes. The preset pages (admin-presets and author-presets) correctly use `<label>` — the metabox should match. |

---

#### F-14 · Sidebar chat lacks `aria-live` region — screen readers miss new messages

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [sidebar.js:223](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L223) |
| **Description** | The `.presshub-chat-messages` container has no `role="log"` or `aria-live="polite"` attribute. When the AI responds, screen readers do not announce the new message. |
| **Impact** | WCAG 2.1 Level A violation (4.1.3 Status Messages). Chat is unusable for screen reader users. |
| **Recommended fix** | Add `role: 'log'` and `'aria-live': 'polite'` to the messages container div. |

---

#### F-15 · Image preview in sidebar has no `alt` text

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:196](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L196) |
| **Description** | The inline `<img>` preview element for generated images has no `alt` attribute at all. (The inserted block correctly sets `alt: 'AI Generated Illustration'` but the preview doesn't.) |
| **Impact** | WCAG 2.1 Level A violation (1.1.1 Non-text Content). |
| **Recommended fix** | Add `alt: 'Preview of AI-generated image'` to the `el('img', {...})` call. |

---

#### F-16 · Sidebar "Clear" button has no confirmation

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:237](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L237) |
| **Description** | The "Clear" button instantly wipes the entire chat history without confirmation. If the journalist had a multi-step research conversation, a misclick destroys all context with no undo. |
| **Impact** | Destructive action without a safety net. Research results, image URLs, and audio reports are lost. |
| **Recommended fix** | Add a confirmation dialog: `window.confirm('Clear all chat history?')`. Or implement a "soft clear" that can be undone for 5 seconds. |

---

#### F-17 · Presets CRUD does a full page reload on every save

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [presets.js:39](file:///home/hermes/presshub/presshub-ai-editor/assets/presets.js#L39) |
| **Description** | Every preset mutation (create, edit, delete, toggle, set default, copy) does `location.reload()` on success. The profile page is heavy and scrolls back to the top, losing the user's scroll position. The preset section may be far down on the profile page. |
| **Impact** | Sluggish editing experience. Editing 3 presets means 3 full page reloads. |
| **Recommended fix** | Update the DOM inline on success (update the table row, add a row, toggle the checkbox state) without reloading. Use `scrollIntoView` to keep the user's position. |

---

#### F-18 · Sidebar chat doesn't pass preset selection

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:52-56](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L52-L56) |
| **Description** | The sidebar chat AJAX call sends `prompt` and `post_id` but never sends `instruction_preset_id`. The server-side handler for chat DOES read this field ([class-ajax-handlers.php:278](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L278)). This means the per-request preset selected in the metabox dropdown is not applied to sidebar chat — a mismatch between the design intent (§3.3) and the implementation. |
| **Impact** | Authors who set a preset for draft generation expect it to also influence chat, but it doesn't. The design doc explicitly calls for this (§3.3). |
| **Recommended fix** | Read `$('#presshub-ai-preset').val()` in the sidebar JS and include it as `instruction_preset_id` in the AJAX payload. |

---

#### F-19 · Test Connection result area shared across all provider buttons

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [admin.js:20-21](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L20-L21), [class-settings.php:273-274](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L273-L274) |
| **Description** | All 4 Test Connection buttons share a single spinner (`#presshub-ai-test-spinner`) and a single result div (`#presshub-ai-test-result`). Clicking "Test OpenAI" then immediately "Test Gemini" causes the spinner state to conflict and the result from the first request may be overwritten. |
| **Impact** | Confusing — the admin can't test multiple providers in parallel and may misattribute a result to the wrong provider. |
| **Recommended fix** | Disable all test buttons while any test is running. OR use per-provider result areas: `#presshub-ai-test-result-openai`, etc. |

---

### Dimension 4: Error Feedback

#### F-20 · Error messages are developer-facing, not journalist-facing

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [admin.js:88](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L88), [sidebar.js:89](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L89) |
| **Description** | Errors surface the raw server response: `"Error: cURL error 28: Connection timed out"`, `"Error: Rate limit exceeded"`, `"Permission denied."`. These are not actionable for a journalist. |
| **Impact** | Users don't know what to do when they see `cURL error 28`. |
| **Recommended fix** | Map common error patterns to friendly messages: timeout → "The AI service is taking too long. Please try again."; rate limit → "You've reached the usage limit — please wait X minutes."; permission → "You don't have permission for this action. Contact your admin." |

---

#### F-21 · Sidebar errors are plain text in a chat bubble — easy to miss

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:89](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L89), [sidebar.js:93](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L93) |
| **Description** | Errors like "Connection failed." appear as a standard gray AI chat bubble — visually identical to a normal response. There's no red color, no icon, no "Retry" button. |
| **Impact** | Users may mistake an error for an AI response. No way to retry without retyping the prompt. |
| **Recommended fix** | Add an `error` message type with distinct styling (e.g., red border, ⚠️ icon). Include a "Retry" button that resends the last prompt. |

---

#### F-22 · Research timeout message offers no recovery path

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [sidebar.js:110](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L110) |
| **Description** | After 2 minutes of polling, the message says "Research timed out after 40 attempts. The job may still be running — check back later or re-run the request." But there's no "Check again" or "Re-run" button. The user must type a new prompt and hope. |
| **Impact** | Dead end for in-flight research. The user has no way to resume polling or retry. |
| **Recommended fix** | Add a "Check status" button that resumes polling, and a "Retry" button that re-sends the original prompt. |

---

### Dimension 5: Visual Polish

#### F-23 · Scorecard is a plain text box — no visual hierarchy for the score

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [admin.js:123](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L123), [admin.css:5-10](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css#L5-L10) |
| **Description** | The scorecard renders as `<strong>Score: 72/100</strong><p>feedback text</p>` in a light-gray box with a blue left border. There is no color-coding by score range (red/yellow/green), no visual gauge, no breakdown of sub-dimensions. The feedback is a single unformatted paragraph. |
| **Impact** | A score of 45 looks identical to a score of 92. No at-a-glance quality signal. |
| **Recommended fix** | (1) Color-code the border: red (<60), yellow (60-79), green (≥80). (2) Add a visual gauge or badge. (3) If the API returns structured feedback, render it as a bulleted list with categories. |

---

#### F-24 · Spinners lack screen-reader announcement

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [class-metaboxes.php:52](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L52), [class-metaboxes.php:60](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L60), [class-settings.php:273](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L273) |
| **Description** | All WP `.spinner` elements are purely visual. No `role="status"` or `aria-label="Loading…"`. |
| **Impact** | Screen reader users have no indication that an operation is in progress. |
| **Recommended fix** | Add `role="status"` and `aria-label="Loading…"` to each spinner span. Toggle `aria-hidden` when inactive. |

---

#### F-25 · Test result color relies solely on color (no icon/prefix)

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [admin.js:32-34](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L32-L34) |
| **Description** | Success is green text, error is red text. No icon or prefix distinguishes them for colorblind users. |
| **Impact** | WCAG 2.1 Level A violation (1.4.1 Use of Color). |
| **Recommended fix** | Prefix with ✓/✗ icons or use WP's `.notice-success`/`.notice-error` classes. |

---

#### F-26 · Admin preset table has no loading/saving indicator during AJAX

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [presets.js:31-47](file:///home/hermes/presshub/presshub-ai-editor/assets/presets.js#L31-L47) |
| **Description** | When saving/deleting a preset, the user sees no visual feedback until the page reloads. If the network is slow, they may click the button multiple times. |
| **Impact** | Potential double submissions; user uncertainty about whether the action worked. |
| **Recommended fix** | Disable the button and show a spinner next to it during the AJAX call. Or show a WP admin notice before the reload. |

---

### Dimension 6: Journalist-Specific UX

#### F-27 · No draft diffing — AI output is inserted blindly

| | |
|---|---|
| **Severity** | **High** |
| **Location** | [admin.js:79-85](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L79-L85) |
| **Description** | The AI draft is appended to the editor without any diff or comparison view. For the editorial review flow, there's no before/after comparison. The journalist has no structured way to see what the AI contributed. |
| **Impact** | Core journalistic concern: editorial responsibility requires knowing what's AI-generated vs. human-authored. Without diffing, the entire "co-authoring" promise is undercut. |
| **Recommended fix** | (1) MVP: Insert the draft as a distinct Group block with a label "AI Draft — Review and edit". (2) Better: Show a modal preview with the draft before insertion. (3) Best: Side-by-side diff view using a library like `diff-match-patch`. |

---

#### F-28 · Research card body truncated at 150px with no expand

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [admin.css:91-92](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css#L91-L92) |
| **Description** | The `.card-body` in the sidebar has `max-height: 150px; overflow-y: auto`. For a comprehensive research synthesis (potentially thousands of words), the user must scroll inside a tiny 150px box within a sidebar. |
| **Impact** | Research output — the most valuable content the AI produces — is crammed into a postage-stamp viewport. |
| **Recommended fix** | (1) Add an "Expand" button that removes the max-height. (2) Or allow opening the research in a modal/overlay. (3) Set a more generous default (e.g., 400px) with expand/collapse toggle. |

---

#### F-29 · Scorecard auto-transitions post status without clear user notification

| | |
|---|---|
| **Severity** | **Medium** |
| **Location** | [class-ajax-handlers.php:211-218](file:///home/hermes/presshub/presshub-ai-editor/includes/class-ajax-handlers.php#L211-L218) |
| **Description** | When the scorecard returns score ≥ 80, the post is silently transitioned to `pending` status. The user sees `alert('Review completed. Status updated.')` — but "Status updated" doesn't clearly communicate that the post's publication status was changed. |
| **Impact** | Journalists may not realize their draft status changed. If they didn't want it in pending review, they have to manually revert. |
| **Recommended fix** | (1) Make the alert (or better, inline notice) explicit: "Score 85/100 — Post moved to Pending Review". (2) Add a "Keep as Draft" option. (3) Show the status change in the scorecard box itself. |

---

#### F-30 · Chat context is ephemeral — no conversation persistence

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [sidebar.js:13-15](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L13-L15) |
| **Description** | Closing and reopening the sidebar (or navigating away) resets the chat to the welcome message. Previous research results, images, and audio reports are lost. |
| **Impact** | Journalists who need to reference earlier AI research must start over or manually copy results before navigating. |
| **Recommended fix** | Persist chat history in `sessionStorage` or a post meta field. Restore on sidebar open. |

---

#### F-31 · Unused import: `PanelBody` imported but never used

| | |
|---|---|
| **Severity** | **Nit** |
| **Location** | [sidebar.js:4](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L4) |
| **Description** | `PanelBody` is destructured from `wp.components` but never referenced in the component. |
| **Impact** | Dead code; no functional impact but signals incomplete cleanup. |
| **Recommended fix** | Remove `PanelBody` from the import. |

---

#### F-32 · Sidebar doesn't send post content as context for chat

| | |
|---|---|
| **Severity** | **Low** |
| **Location** | [sidebar.js:52-56](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L52-L56) |
| **Description** | The chat AJAX call sends `post_id` but not the post content. The AI chat assistant has no context about the article being written (unlike the research endpoint which does receive content on the server side). |
| **Impact** | The "AI Co-Pilot" can't reference the current article in chat unless the user manually pastes excerpts. |
| **Recommended fix** | Include `content: wp.data.select('core/editor').getEditedPostAttribute('content')` in the AJAX payload, with a reasonable length cap. Let the server truncate if needed. |

---

---

## 2. Top 10 UX Issues

Ranked by severity × user impact × frequency of encounter:

| Rank | ID | Issue | Severity |
|---:|---|---|---|
| 1 | **F-11** | Missing `.fail()` on review AJAX — UI hangs permanently on network error | Critical |
| 2 | **F-01** | `alert()` for all success/error feedback — blocks workflow | Critical |
| 3 | **F-13** | No `<label>` elements on metabox inputs — WCAG failure | High |
| 4 | **F-02** | Draft inserted as opaque paragraph block — no diff or undo | High |
| 5 | **F-12** | No keyboard submit (Enter key) in sidebar chat | High |
| 6 | **F-03** | No client-side validation before firing expensive AI calls | High |
| 7 | **F-07** | Settings page shows all providers simultaneously — overwhelming | High |
| 8 | **F-27** | No draft diffing mechanism for editorial accountability | High |
| 9 | **F-14** | Sidebar chat lacks `aria-live` — inaccessible to screen readers | High |
| 10 | **F-18** | Sidebar chat doesn't pass preset selection (design intent mismatch) | Medium |

---

## 3. Prioritized Improvement Plan

### 🟢 Quick Wins (< 1 day each)

| Priority | Finding | What to do | Effort | Files |
|---:|---|---|---|---|
| **QW-1** | F-11 | Add `.fail()` handler to `$.post()` for `run_review` | 15 min | [admin.js:129](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L129) |
| **QW-2** | F-13 | Add `<label for="...">` to metabox inputs (file, sources, instructions, preset) | 30 min | [class-metaboxes.php:38-45](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L38-L45) |
| **QW-3** | F-15 | Add `alt` attribute to sidebar image preview | 5 min | [sidebar.js:196](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L196) |
| **QW-4** | F-14 | Add `role="log"` and `aria-live="polite"` to chat messages container | 5 min | [sidebar.js:223](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L223) |
| **QW-5** | F-25 | Add ✓/✗ prefixes to test result messages | 10 min | [admin.js:32-34](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L32-L34) |
| **QW-6** | F-24 | Add `role="status"` to spinner elements | 15 min | [class-metaboxes.php:52](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L52), [class-settings.php:273](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L273) |
| **QW-7** | F-31 | Remove unused `PanelBody` import | 2 min | [sidebar.js:4](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L4) |
| **QW-8** | F-16 | Add `window.confirm()` to sidebar Clear button | 5 min | [sidebar.js:237](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L237) |
| **QW-9** | F-04 | Update description text to include WAV and M4A | 2 min | [class-metaboxes.php:39](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L39) |
| **QW-10** | F-18 | Pass `instruction_preset_id` in sidebar chat AJAX payload | 10 min | [sidebar.js:52-56](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L52-L56) |

### 🟡 Medium Effort (1–3 days each)

| Priority | Finding | What to do | Effort | Files |
|---:|---|---|---|---|
| **ME-1** | F-01 | Replace all `alert()` with inline WP notices / Gutenberg snackbars | 1 day | [admin.js](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js) (all 5 alert calls), [admin.css](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css) |
| **ME-2** | F-03 | Client-side validation: disable Generate/Review buttons when inputs are empty | 0.5 day | [admin.js:43-97](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L43-L97), [class-metaboxes.php:49](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L49) |
| **ME-3** | F-12 | Add Enter-to-send keyboard handler to sidebar chat | 0.5 day | [sidebar.js:229-234](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js#L229-L234) |
| **ME-4** | F-23 | Color-coded scorecard (red/yellow/green border + score badge) | 0.5 day | [admin.js:123](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L123), [admin.css:5-10](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css#L5-L10) |
| **ME-5** | F-21 | Add error message styling (red border, retry button) to sidebar | 1 day | [sidebar.js](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js), [admin.css](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css) |
| **ME-6** | F-07 | Conditionally show only active provider fields; toggle for all | 1 day | [class-settings.php:222-228](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php#L222-L228), [admin.js](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js) |
| **ME-7** | F-05 | Dynamic preset dropdown label showing current default name | 0.5 day | [class-metaboxes.php:134](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php#L134) |
| **ME-8** | F-29 | Explicit status-change notification with opt-out | 0.5 day | [admin.js:122-125](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js#L122-L125) |
| **ME-9** | F-17 | Inline DOM updates for preset CRUD instead of page reload | 1.5 days | [presets.js](file:///home/hermes/presshub/presshub-ai-editor/assets/presets.js) |
| **ME-10** | F-20 | Map common error patterns to friendly messages | 1 day | [admin.js](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js), [sidebar.js](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js) |

### 🔴 Larger Features (3+ days each)

| Priority | Finding | What to do | Effort | Files |
|---:|---|---|---|---|
| **LF-1** | F-02, F-27 | Draft preview/diff before insertion: modal showing AI output with accept/reject | 3–5 days | [admin.js](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js), new preview component |
| **LF-2** | F-10 | Sidebar quick-action buttons with explicit intent selection | 2–3 days | [sidebar.js](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js), [admin.css](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css) |
| **LF-3** | F-28 | Expandable research cards with full-view modal | 2 days | [sidebar.js](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js), [admin.css](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.css) |
| **LF-4** | F-30 | Session-persisted chat history | 2–3 days | [sidebar.js](file:///home/hermes/presshub/presshub-ai-editor/assets/sidebar.js) |
| **LF-5** | F-09 | Top-level "PressHub AI" menu consolidating settings, presets, and docs | 3 days | [class-settings.php](file:///home/hermes/presshub/presshub-ai-editor/includes/class-settings.php), [class-admin-presets.php](file:///home/hermes/presshub/presshub-ai-editor/includes/class-admin-presets.php) |
| **LF-6** | F-04 | Rich file upload control with per-file preview, size display, and remove | 2 days | [class-metaboxes.php](file:///home/hermes/presshub/presshub-ai-editor/includes/class-metaboxes.php), [admin.js](file:///home/hermes/presshub/presshub-ai-editor/assets/admin.js) |

---

## 4. Verdict

### Overall UX Maturity: **Early Functional — Solid Backend, Underdeveloped Frontend**

```
┌──────────────────────────────────────────────────────────┐
│  UX Maturity Spectrum                                    │
│                                                          │
│  ■■■■■■■■■■□□□□□□□□□□  ≈ 45/100                         │
│  Prototype  │ Functional │ Polished │ Exceptional        │
│             ▲                                            │
│         YOU ARE HERE                                     │
└──────────────────────────────────────────────────────────┘
```

**What's working well:**

- ✅ **Backend architecture** is solid: clean separation of concerns, thorough sanitization, idempotent migrations, rate limiting, capability gating.
- ✅ **Preset system** is well-designed: the three-layer composition model (plugin defaults → author presets → per-request) with append-not-replace semantics is architecturally sound and well-documented.
- ✅ **Security posture** is strong: nonce validation, capability checks, XSS escaping (`presshubEsc`), input length limits, masked API keys.
- ✅ **Settings page** uses the WordPress Settings API correctly with proper sanitize callbacks.
- ✅ **Sidebar chat** has clean React-style patterns with proper loading states, unmount cleanup, and polling limits.

**What needs work:**

- ❌ **Error/success feedback** relies entirely on `alert()` — the single most impactful UX debt.
- ❌ **Accessibility** has multiple WCAG Level A violations (no labels, no `aria-live`, color-only indicators).
- ❌ **The critical bug** (F-11: missing `.fail()` handler) can permanently break the metabox.
- ❌ **Editorial accountability** — the core value proposition of "AI co-authoring" — has no diff/review mechanism. The AI output is injected opaquely.
- ❌ **Information density** on the settings page is unmanaged (all provider fields visible at once).
- ❌ **Feature discoverability** is poor — the preset system, sidebar capabilities, and settings are siloed with no cross-linking.

**Bottom line**: The plugin has a **strong engineering foundation** with careful data handling and a well-thought-out preset architecture. The frontend UX, however, is at MVP level — functional but rough. The quick wins (QW-1 through QW-10) represent ~2 hours of work and would eliminate the most egregious issues. The medium-effort items (particularly ME-1: replacing `alert()`) would move the needle from "early functional" to "professional". The draft diff/preview feature (LF-1) is the most strategically important investment — it's the UX embodiment of the plugin's editorial co-authoring promise.
