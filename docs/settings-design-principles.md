# PressHub settings design principles

**Status: binding.** These rules came out of the settings overhaul (epic #232, phases #233–#237, #246, #247; releases 2.39.0–2.46.0). Before the overhaul, PressHub had three top-level menus, two settings pages, about 300 options spread over tabs that no longer matched the features, settings stored but never read, the same AI model chosen in several places, and API keys saved in more than one option. The rules below keep that from happening again.

**When to check them**

1. **Before designing** any change that adds, moves, renames or removes a setting, a store, a settings page, a section or a menu item: the issue or design note says which rules apply and how the change meets them.
2. **Before merging** such a change: the PR fills in the checklist at the end of this document (the PR template carries the same list), and the reviewer checks it. A reviewer requests changes when the checklist is missing or a rule is broken without a recorded exception.

**Exceptions.** A rule may be broken only with the owner's agreement in the issue, recorded under [Exceptions](#exceptions) in the same PR, with the rule, what deviates, why, the tracking issue and when it ends. Places where the code did not yet meet a rule when this document was written are recorded there too. A PR that fixes one deletes its row.

**Terms.** A *setting* is a scalar or list option an editor changes on PressHub → Settings: it is in the settings registry. A *structured store* is a record set with its own editor and save endpoint, outside the registry: settings stores (AI provider records `presshub_ai_configured_providers`, instruction presets `presshub_ai_default_presets` / `presshub_ai_taxonomy_presets` / `presshub_ai_role_presets` (`PressHub_AI_Preset_Store`), the Shorts music library) and editorial-content stores (the editorial agenda, policy and people fiches). Per-author data in user meta (author presets) belongs to its author and checks `edit_posts`. Featured Image Studio templates (`presshub_ai_image_prompt_templates`) are a shared editorial store, edited in the studio and in Writing & QA → Featured images: any editor may save them, and the saving author or a user with `edit_others_posts` may change or delete them. News sources (`presshub_ai_briefing_sources`) are a registered list setting with their own editor in Daily Briefing: they follow rules 1–6 and are also saved by that editor's endpoint. The prompt history (`PressHub_AI_Prompt_Versions`, its two tables) is a read-only record of settings, not a store: it is filled by the prompts' own option hooks, shown next to each prompt, and a restore goes through the prompt's field and the section Save. *Runtime state* is written by PressHub itself (worker heartbeat and logins, connection status cache, clean-up reports). Rules 1–6 apply to settings; rule 2.7 and section 7 say what applies to stores and state.

---

## 1. Where settings live

**1.1 One menu, one Settings page.** Every PressHub screen is a submenu of the one **PressHub** menu (`PressHub_Admin_Menu::PARENT`), in `PressHub_Admin_Menu::ORDER`. There is exactly one settings page, **PressHub → Settings** (`admin.php?page=presshub-ai&section=<slug>`). Never add a top-level menu, an entry under WordPress Settings, a second settings page or a settings tab on another screen.

**1.2 Eight sections, organised by what the editor is doing.** The sections are fixed, in this order (`PressHub_Settings_Sections::definitions()`):

| Section | Slug | Holds |
|---|---|---|
| Connections | `connections` | Every key, token, password, login and endpoint: AI providers, Google Cloud, press inbox login, social accounts, automation worker, plugin updates. The setup check. |
| AI models | `ai-models` | Which provider and model each AI task uses, and its temperature, max tokens and timeout. One row per task. |
| Writing & QA | `writing-qa` | Co-author drafting, the QA gate, SEO and tags, featured images, presets. |
| Daily Briefing | `daily-briefing` | Schedule, sources and harvesting (press inbox folders and filters), text story, podcast script, voices and audio, publishing. |
| Editorial knowledge | `editorial-knowledge` | What is added to every AI call on top of the prompt: policy and people fiches, claim checks, the article pool, editorial memory. |
| Social publishing | `social-publishing` | Platforms, post copy, images and video, Shorts & Reels, the dispatch queue. |
| Social listening | `social-listening` | Account monitoring and Trending in Cyprus. |
| Site & system | `site-system` | Link previews, rate limits, logging and retention, diagnostics, export and import. |

A new section needs an issue and the owner's agreement. A new setting goes in the section of the feature it changes, decided with this table, not wherever the code that reads it happens to live. Its group inside the section is one of the section's `groups`.

**1.3 One home per setting.** Each option is edited in exactly one place (`PressHub_Settings_Sections::section_of()`). Other places that need it show a read-only line with a link to its home (for example `PressHub_Model_Tasks::feature_line()`: "Co-author model: Google Gemini · gemini-3.8-flash. Change in AI models"). Never render the same option as an input in two sections.

**1.4 Secrets only in Connections.** A key, token or password is stored once, rendered with `PressHub_Secret_Field::render()` in Connections (the saved value is never printed; empty keeps it; a Remove box clears it when that is safe), and read through its owner. AI keys are read only from the Connections provider records, through the module's provider record or `PressHub_AI_API_Client::lookup_provider_key_by_type()`. Never add a flat key option, a second copy of a key, or a fallback from one key option to another.

**1.5 Models only in the AI models table.** A provider or model choice is a row in `PressHub_Model_Tasks::rows()`, with the kind of model it needs; new models go in `PressHub_Model_Catalog::MODELS`. Feature sections never get their own provider or model fields.

**1.6 Connections never calls the outside world on page load.** Status comes from the `PressHub_Connection_Status` cache; a remote request happens only when the editor clicks Test or Recheck all. A new connection test records its result and returns the new pill. Anything that stops PressHub from working is reported by `PressHub_Setup_Check::problems()` with a link to its fix.

## 2. Every setting is registered, read and shown

**2.1 The registry is the single source of truth for settings.** Every setting has a `PressHub_Settings_Registry::register()` call (never a bare `register_setting()`) and an entry in `includes/settings-registry-data.php`: page, tab, section, label, help, type, checkbox and secret flags, status, `rendered`, a default for scalar settings, and `depends_on` when a switch disables it. The section Save (`save_section()`), options.php and import all write through the registry's sanitizers.

**2.2 No setting without a reader, no reader without a setting.** A setting is added in the same change as the code that reads it; an option nothing reads is not shipped. A user-visible limit (`max_`, `min_`, `_limit`, `_threshold`, `_timeout`, `_retries`, `_budget`, batch sizes, retention) is never a hard-coded default in business logic: it is a setting (agents.md, Settings-First Principle).

**2.3 Read through one helper.** Business logic reads a setting through its `PressHub_AI_Settings_Storage::get_<knob>()` (or Social storage) helper, which clamps it and holds the one documented default. Never `get_option( 'x', <another default> )` in business logic: two defaults for one option drift apart.

**2.4 One sanitizer that clamps.** Each setting has one `sanitize_<knob>()` that clamps to documented safe bounds. A masked or empty secret keeps the stored value; an explicit Remove clears it.

**2.5 One default, and it is shown.** Every scalar setting has one default in the registry (`default`, or `default_callback` when it depends on another setting), equal to the getter's fallback (`SettingsDefaultsTest`, `SettingsRegistryDefaultsCoverageTest`); an empty default means "use the provider's or the task's default". Inputs and selects show it as "Default: …" with Reset to default. A select shows what is stored, so an untouched Save never stores a value nobody picked. Prompts and templates take their default from an asset file (rule 7.3) and offer "Load default template" instead.

**2.6 Every active setting is on screen.** A setting with `status => active` renders a field in its section and has `rendered => true`. The rows of a section are printed by its `render_section_<slug>()`; `add_settings_field()` is used only by the sections printed field by field: the three AI sections printed by `render_section_with_fields()` and the Social sections printed by `PressHub_Social_Settings_Render::render_settings_sections()` (fields registered in `class-social-settings.php`). A setting nobody should see is not active: it is `unused` and on its way out (rule 4.3). The only active settings without a field of their own are the "Remove stored key" boxes (`removes`), shown while a key is stored.

**2.7 Stores and state.** Structured stores are not settings: they live outside the registry, are edited in their own editor (provider cards in Connections, presets on PressHub → Presets, the music library in Social publishing, fiches and agenda on their PressHub pages), and save through their own AJAX or admin-post endpoint, which checks a nonce and is audited (rule 3.4). Settings stores use the settings capability (rule 3.3). Editorial-content stores (fiches, agenda) use the editorial capability `edit_others_posts` / `presshub_ai_briefing_cap`, so narrowing the settings capability never locks editors out of editorial content (architecture.md §4.0, `AdminMenuCapabilitiesTest`). Runtime state is never shown as an editable field. A new structured store needs an issue, says where it is edited, and is listed in `uninstall.php` (rule 7.6).

## 3. Saving

**3.1 One Save per section, saving only that section.** The section's Save writes only the settings of that section (`PressHub_Settings_Registry::save_section()`), across both option groups, with each setting's own sanitizer. A save never blanks, resets or rewrites a setting of another section, or a setting the editor did not see.

**3.2 Refuse bad values before writing.** Format and range errors are stopped in the browser (field constraints) and clamped by the sanitizer on the server. A semantic check that can refuse a save (for example a speech model on a text task, `PressHub_Model_Tasks::validate()`) runs in `save_settings_section` before anything is written and returns `field_errors` (option => message); the page lists them in the error summary, links to each field and adds "Error:" to the page title. A new semantic check uses this contract. Warnings save and are shown at save time (`model_warnings`).

**3.3 Same rights everywhere.** Every settings page, settings save and settings AJAX action, including settings stores, checks a nonce and `PressHub_Capabilities::manage()` (Social parts: `PressHub_Capabilities::social()`). Editorial-content pages and stores (Hub, Memory, Generation records, fiches, agenda) keep `edit_others_posts` / `presshub_ai_briefing_cap`, and per-author data keeps `edit_posts` (rule 2.7). Never check `manage_options` directly.

**3.4 Every write is audited, never with secret values.** The section Save writes a `settings_saved` audit event listing the saved keys; import writes `settings_imported`; store editors and actions that change a setting or secret write their own event. Secret values never appear in an audit event.

## 4. Changing and removing settings

**4.1 Keep option names.** Labels may change; stored option names do not. A rename needs a migration, a one-release fallback and an issue of its own.

**4.2 Old links keep working.** When a setting, group or page moves, its old URL redirects to the new section and group (`PressHub_Settings_Registry::legacy_admin_redirects()`, `PressHub_Settings_Sections::legacy_redirects()`, `legacy_hash_urls()`), and its old `#anchor` is followed.

**4.3 Removal is a lifecycle, never a silent delete.**
1. Mark the setting `status => unused` in the registry. It appears under Site & system → Diagnostics → Scheduled for removal.
2. The owner approves the removal in the issue.
3. In one change, delete its registration, registry entry, field, getter, sanitizer and every reader; add it to `PressHub_Settings_Cleanup::REMOVED` with a report group; bump `PressHub_Settings_Cleanup::VERSION` (new entries get `since => <new VERSION>`, the release goes in `RELEASES`).
4. The upgrade saves a copy of all settings first and never deletes without a verified copy, copies anything still needed to its new home, writes the clean-up report (Usage & logs) and shows the notice once.
5. For one release, reading a removed option logs `_doing_it_wrong()`.

Never write a separate `presshub_ai_migrate_*` routine or `delete_option()` call to remove a setting.

**4.4 No legacy fallback chains.** Do not keep reading an old option "just in case". Migrate it once, verify, then stop reading it (agents.md, Clean Slate Architecture).

## 5. How settings look and read

**5.1 Plain words first.** Every setting has a label and one sentence of help saying what it does for the editor, in plain language; numbers state their unit; labels are unique within a section. Option names, filters, hooks and class names never appear on screen except under a "Details" disclosure. An environment variable or constant the editor must set by hand (such as `PRESSHUB_WORKER_SECRET` or `PRESSHUB_AI_LOG_DIR`) may be named; new ones are shown in code formatting.

**5.2 Accessible.** Every field has a visible label; groups have headings; status uses text, not colour alone; errors are linked from a summary; focus stays visible and clear of the sticky save bar; leaving with unsaved changes asks first; the page works at 375px wide (the section `<select>`). The section links work without JavaScript, and so does Save: the form posts the one section on screen to admin-post.php (`PressHub_Settings_Sections::POST_ACTION`), through the same save as the AJAX Save, never to options.php.

**5.3 No inline styles.** Settings markup uses classes from `assets/css/settings.css`, never `style=`.

**5.4 Searchable.** Every setting is found by Settings search (label, help or old name) and jumps to its field. Secrets are found by their label and help, never by value, and the result points at their Replace key button.

## 6. AI models and Connections, in short

- A new AI task is one row in `PressHub_Model_Tasks::rows()`; the feature shows its read-only "… model" line.
- A new provider type, key or login goes in Connections with `PressHub_Secret_Field`, a Test that records to `PressHub_Connection_Status`, and a setup-check item if PressHub cannot work without it.
- Speech, image, music and text tasks each name the kind of model they need; a wrong kind is refused (rule 3.2).

## 7. Cross-cutting invariants

**7.1 The worker contract is stable.** Moving, renaming or cleaning up settings never changes the worker claim payload (`/presshub-social/v1/worker/claim`, `PressHub_Social_REST_Controller`). A change to the payload needs its own issue, a worker release and a version bump of the contract; the byte-identical contract test at the end of `presshub-ai-editor/tests/run-social-tests.php` must keep passing otherwise.

**7.2 Secrets stay masked.** Export (`PressHub_Settings_Transfer`) never writes a secret or a secret-bearing store (provider records); it lists them by name only, and import never changes them. Logs, audit events and localized JavaScript data mask secrets through `PressHub_AI_Secret_Masker`. Import preview runs no sanitizer with side effects.

**7.3 Prompts are assets.** A default prompt lives in a file under `assets/prompts/`, is seeded into its setting (`seed_default_*`), and is never an inline PHP fallback string (agents.md, Zero-Silent-Fallback and Prompt Defaults as External Assets). An upgraded default replaces only an unedited stored prompt.

**7.4 Two option groups.** Settings belong to `presshub_ai_options` or `presshub_social_options`. Each registry group is wired to the PressHub capability through `PressHub_Capabilities::register_option_group()` (`option_page_capability_<group>`). Do not add a group without an issue.

**7.5 Translatable and safe.** Every label, help text, error and notice printed by PHP is translatable, with a `/* translators: */` comment for placeholders. Translatable strings carry no block-level HTML; only the inline tags `code`, `strong`, `em`, `br` and `a` are allowed (`SettingsTranslationSafetyTest`). The registry's labels, help and old names are listed for translators in `includes/settings-registry-strings.php` (generated by `scripts/settings-registry-strings.php`) and shown translated through `PressHub_Settings_Registry::translated()`.

**7.6 Uninstall removes what PressHub stored.** `presshub-ai-editor/uninstall.php` deletes every registry setting (read from `settings-registry-data.php`), plus the stores and state listed in it, on every site of a multisite network. A new store or state option outside the registry is added to that list.

## 8. Enforcement

These tests and checks guard the rules. A change that has to edit one of them to pass must say why in the PR.

| Rule | Guard |
|---|---|
| 1.1, 3.3, 4.2, 7.4 | `AdminMenuCapabilitiesTest` (one menu, no raw `manage_options`, option-page capability per group, old URLs redirect) |
| 1.2, 1.3, 3.1, 5.3 | `SettingsSectionsTest` (every setting in exactly one section, each section renders only its own settings, section save isolation, no inline styles in any settings renderer) |
| 1.4, 1.6 | `ConnectionsTest` (no remote request on load, Replace key, status cache) |
| 1.5, 3.2, 6 | `ModelsTableTest` |
| 2.1 | `SettingsRegistryTest`, `SettingsInventoryTest` (regenerate `docs/settings-inventory.md` with `php presshub-ai-editor/scripts/settings-inventory.php`) |
| 2.5 | `SettingsDefaultsTest` (registry default against literal `get_option()` defaults), `SettingsRegistryDefaultsCoverageTest` (every scalar setting declares one), `SettingsSelectDefaultsTest` |
| 2.6 | `SettingsHiddenSurfacedTest` (every active AI and Social setting is marked rendered and has a field; only the "Remove stored key" boxes are exempt) |
| 3.1 | `SettingsSaveIsolationTest`, `SettingsPerTabAjaxSaveTest` (the per-tab save path), `SettingsNoJsSaveTest` (the form without JavaScript) |
| 3.4 | `SettingsAuditCoverageTest` (every writer outside the section Save writes its audit event; the worker secret only masked) |
| 4.3 | `SettingsCleanupTest`, `SettingsUpgradeTest` |
| 5.1 | `SettingsHelpTextLintTest` (help text, units for numeric settings, labels unique within a section) |
| 5.4 | `SettingsSearchSecretsTest` (secrets in search by label only) |
| 5.3 | CI step "No inline style= in the settings renderers" (every settings renderer) |
| 7.1 | the claim-payload contract test at the end of `presshub-ai-editor/tests/run-social-tests.php` |
| 7.2 | `SettingsTransferTest` |
| 7.5 | `SettingsTranslationSafetyTest` (block-level HTML in translatable strings of every settings renderer, inline `code`/`strong`/`em`/`br`/`a` allowed), `SettingsRegistryStringsTest` (registry strings listed for translators and translated in search) |
| 7.6 | `UninstallTest` (registry settings, Social settings, stores and multisite) |
| This document | `SettingsPrinciplesDocTest` (section table, guards, links, PR checklist) |

---

## Checklist (copy into the PR)

- [ ] This change adds, moves, renames or removes no setting, store, section, settings page or menu item, **or** I checked it against `docs/settings-design-principles.md`:
  - [ ] It lives in the right section (1.2) and has one home; other places link to it (1.3).
  - [ ] Keys and logins are only in Connections (1.4, 1.6); models only in AI models (1.5).
  - [ ] Registered with a registry entry, one getter, one clamping sanitizer and one default that the field shows (2.1–2.5), or it is a store with its own editor (2.7).
  - [ ] It has a reader in this change, and it is rendered in its section (2.2, 2.6).
  - [ ] Saving its section touches only that section; refusals use `field_errors` before anything is written (3.1, 3.2).
  - [ ] Nonce plus `PressHub_Capabilities::manage()` / `social()`; every write is audited without secret values (3.3, 3.4).
  - [ ] No option renamed; old URLs and anchors redirect (4.1, 4.2).
  - [ ] Removals follow the lifecycle with the owner's approval (4.3); no new fallback chain (4.4).
  - [ ] Plain-language label and help, accessible, no inline styles, found by search (5.1–5.4).
  - [ ] Worker payload unchanged, secrets stay masked, prompts are assets, translatable, listed in `uninstall.php` (7.1–7.6).
  - [ ] `docs/settings-inventory.md` regenerated, and agents.md, architecture.md and README updated where they describe this setting.
  - [ ] Any rule this change breaks is recorded under Exceptions with the owner's agreement.

## Exceptions

Each row: the rule, what deviates, why it is allowed for now, the tracking issue, and when it ends. Delete a row in the PR that fixes it. When a new exception is agreed, add a table with the columns `#`, Rule, What deviates, Why, Tracking and Ends.

None. #309 closed E1 to E10 (2.68.1 to 2.71.0).
