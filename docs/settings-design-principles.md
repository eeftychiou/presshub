# PressHub settings design principles

**Status: binding.** These rules came out of the settings overhaul (epic #232, phases #233–#237, releases 2.39.0–2.46.0). Before the overhaul, PressHub had three top-level menus, two settings pages, about 300 options spread over tabs that no longer matched the features, settings stored but never read, the same AI model chosen in several places, and API keys saved in more than one option. The rules below are what keeps that from happening again.

**When to check them**

1. **Before designing** any change that adds, moves, renames or removes a setting, a settings page, a section or a menu item: the issue or design note must say which rules apply and how the change meets them.
2. **Before merging** such a change: the PR description fills in the checklist at the end of this document (the PR template has a copy), and the reviewer checks it.

A rule can only be broken by agreement in the issue, recorded as an exception in this file in the same PR. "It was quicker" is not an exception.

---

## 1. Where settings live

**1.1 One menu, one Settings page.** Every PressHub screen is a submenu of the one **PressHub** menu (`PressHub_Admin_Menu::PARENT`), in `PressHub_Admin_Menu::ORDER`. There is exactly one settings page, **PressHub → Settings** (`admin.php?page=presshub-ai&section=<slug>`). Never add a top-level menu, an entry under WordPress Settings, a second settings page or a settings tab on another screen.

**1.2 Eight sections, organised by what the editor is doing.** The sections are fixed, in this order:

| Section | Slug | Holds |
|---|---|---|
| Connections | `connections` | Every key, token, password, login and endpoint: AI providers, Google Cloud, press inbox login, social accounts, automation worker, plugin updates. The setup check. |
| AI models | `ai-models` | Which provider and model each AI task uses, and its temperature, max tokens and timeout. One row per task. |
| Writing & QA | `writing-qa` | Co-author drafting, the QA gate and scorecard, SEO, featured images. |
| Daily Briefing | `daily-briefing` | Schedule, sources and harvesting, text story, podcast script, voices and audio, publishing. |
| Editorial knowledge | `editorial-knowledge` | Editorial memory, people and policy fiches. |
| Social publishing | `social-publishing` | Auto-posting, platforms, post copy, images and video, Shorts & Reels, the queue. |
| Social listening | `social-listening` | Account monitoring and Trending in Cyprus. |
| Site & system | `site-system` | Link previews, rate limits, logging and retention, diagnostics, export and import. |

A new section needs an issue and the owner's agreement. A new setting goes in the section of the feature it changes, decided with this table, not wherever the code that reads it happens to live.

**1.3 One home per setting.** Each option is edited in exactly one place. Other places that need to show it display a read-only line with a link to its home (for example `PressHub_Model_Tasks::feature_line()`: "Co-author model: Google Gemini · gemini-3.8-flash. Change in AI models"). Never render the same option as an input in two sections.

**1.4 Secrets only in Connections.** A key, token or password is stored once, rendered with `PressHub_Secret_Field::render()` in Connections, and read through its owner (AI keys only from the Connections provider records, through `PressHub_AI_API_Client::lookup_provider_key_by_type()` or the module's provider record). Never add a flat key option, a second copy of a key, or a fallback from one key option to another.

**1.5 Models only in the AI models table.** A provider or model choice is a row in `PressHub_Model_Tasks::rows()`, with the kind of model it needs; new models go in `PressHub_Model_Catalog::MODELS`. Feature sections never get their own provider or model fields.

**1.6 Connections never calls the outside world on page load.** Status comes from the `PressHub_Connection_Status` cache; a remote request happens only when the editor clicks Test or Recheck all. Anything that stops PressHub from working is reported by `PressHub_Setup_Check::problems()` with a link to its fix.

## 2. Every setting is registered, read and shown

**2.1 The registry is the single source of truth.** Every option has a `PressHub_Settings_Registry::register()` call (never a bare `register_setting()`) and an entry in `includes/settings-registry-data.php`: page, tab, section, label, help, type, checkbox and secret flags, status, default, and `depends_on` when a switch disables it. Every save path (the section Save, options.php, import) goes through the registry.

**2.2 No setting without a reader, no reader without a setting.** A setting is added in the same change as the code that reads it. An option that nothing reads is not shipped. A user-visible limit (`max_`, `min_`, `_limit`, `_threshold`, `_timeout`, `_retries`, `_budget`, batch sizes, retention) is never a hard-coded default in business logic: it is a setting (agents.md, Settings-First Principle).

**2.3 Read through one helper.** Business logic reads an option through its `PressHub_AI_Settings_Storage::get_<knob>()` (or the Social storage) helper, which clamps it and holds the one documented default. Never `get_option( 'x', <another default> )` in business logic: two defaults for one option drift apart.

**2.4 One sanitizer that clamps.** Each option has one `sanitize_<knob>()` that clamps to documented safe bounds. An empty or masked secret keeps the stored value; an explicit Remove clears it.

**2.5 One default, and it is shown.** The registry default is the value the getter falls back to, and the field shows it ("Default: …" with Reset to default). `SettingsDefaultsTest` fails when a literal `get_option()` default differs from the registry default. A select shows what is stored, so an untouched Save never stores a value nobody picked.

**2.6 Every setting is on screen.** A registered, active option renders a field in its section. Hidden settings are not allowed (`SettingsHiddenSurfacedTest`). The rows of a section are printed by its `render_section_<slug>()`; only the sections printed by `render_section_with_fields()` use `add_settings_field()`.

## 3. Saving

**3.1 One Save per section, saving only that section.** The section's Save writes only the options of that section (`PressHub_Settings_Registry::save_section()`), across both option groups, with each option's own sanitizer. A save never blanks, resets or rewrites an option of another section, and never rewrites an option the editor did not see.

**3.2 Validate before writing.** Errors are returned for the whole section before anything is stored (for example a speech model on a text task is refused), shown in the error summary with a link to each field, and "Error:" is added to the page title. Warnings save and say so at save time.

**3.3 Same rights everywhere.** Every settings page, save and AJAX action checks a nonce and `PressHub_Capabilities::manage()` (Social parts: `PressHub_Capabilities::social()`). Never check `manage_options` directly.

**3.4 Changes are audited.** A settings save writes a `settings_saved` audit event with the changed keys (never secret values).

## 4. Changing and removing settings

**4.1 Keep option names.** Labels may change; stored option names do not. A rename needs a migration, a one-release fallback and an issue of its own.

**4.2 Old links keep working.** When a setting, tab or page moves, its old URL redirects to the new section and group (`PressHub_Settings_Registry::legacy_admin_redirects()`, `PressHub_Settings_Sections::legacy_redirects()`), and its old `#anchor` is followed.

**4.3 Removal is a lifecycle, never a silent delete.**
1. Mark the option `status => unused` in the registry. It appears under Site & system → Diagnostics → Scheduled for removal.
2. The owner approves the removal in the issue.
3. In one change, delete its registration, registry entry, field, getter, sanitizer and every reader; add it to `PressHub_Settings_Cleanup::REMOVED` with a report group; bump `PressHub_Settings_Cleanup::VERSION` (new entries get `since => <new VERSION>`, the release goes in `RELEASES`).
4. The upgrade saves a copy of all settings first, never deletes without a verified copy, copies anything still needed to its new home, writes the clean-up report (Usage & logs), and shows the notice once.
5. For one release, reading a removed option logs `_doing_it_wrong()`.

**4.4 No legacy fallback chains.** Do not keep reading an old option "just in case". Migrate it once, verify, then stop reading it (agents.md, Clean Slate Architecture).

## 5. How settings look and read

**5.1 Plain words first.** Every setting has a label and one sentence of help saying what it does for the editor, in plain language; numbers state their unit; no two settings on a page share a label. Option names, filters and class names never appear on screen except under a "Details" disclosure.

**5.2 Accessible.** Every field has a visible label; groups have headings; status uses text, not colour alone; errors are linked from a summary; focus stays visible and clear of the sticky save bar; leaving with unsaved changes asks first; the page works at 375px wide (section `<select>`) and without JavaScript for plain fields.

**5.3 No inline styles.** Settings markup uses classes from `assets/css/settings.css`; CI greps the settings renderers for `style=`.

**5.4 Searchable.** Every setting is found by Settings search (label, help or old name) and jumps to its field.

## 6. Enforcement

These tests and checks guard the rules. A change that has to edit one of them to pass must say why in the PR.

| Rule | Guard |
|---|---|
| 1.1, 3.3 | `AdminMenuCapabilitiesTest` (one menu, no raw `manage_options`) |
| 1.2, 1.3 | `SettingsSectionsTest` (every option in exactly one section) |
| 1.4, 1.6 | `ConnectionsTest` (no remote request on load, masked secrets) |
| 1.5 | `ModelsTableTest` |
| 2.1 | `SettingsRegistryTest`, `docs/settings-inventory.md` (`SettingsInventoryTest`; regenerate with `php presshub-ai-editor/scripts/settings-inventory.php`) |
| 2.5 | `SettingsDefaultsTest`, `SettingsSelectDefaultsTest` |
| 2.6 | `SettingsHiddenSurfacedTest` (registry "rendered" flag against the real page) |
| 3.1 | `SettingsSaveIsolationTest`, `SettingsPerTabAjaxSaveTest` |
| 4.3 | `SettingsCleanupTest`, `SettingsUpgradeTest` |
| 5.1 | `SettingsHelpTextLintTest`, `SettingsTranslationSafetyTest` |
| 5.3 | CI step "No inline style= in the settings renderers" |

---

## Checklist (copy into the PR)

- [ ] This change adds, moves, renames or removes no setting, section, settings page or menu item, **or** I checked it against `docs/settings-design-principles.md`:
  - [ ] It lives in the right section (1.2) and has one home; other places link to it (1.3).
  - [ ] Keys and logins are only in Connections (1.4); models only in AI models (1.5).
  - [ ] Registered with a registry entry, one getter, one clamping sanitizer and one default that the field shows (2.1–2.5).
  - [ ] It has a reader in this change, and it is rendered in its section (2.2, 2.6).
  - [ ] Saving its section touches only that section; errors are validated before anything is written (3.1, 3.2).
  - [ ] Nonce plus `PressHub_Capabilities::manage()` / `social()` (3.3).
  - [ ] No option renamed; old URLs and anchors redirect (4.1, 4.2).
  - [ ] Removals follow the lifecycle with the owner's approval (4.3); no new fallback chain (4.4).
  - [ ] Plain-language label and help, accessible, no inline styles, found by search (5.1–5.4).
  - [ ] `docs/settings-inventory.md` regenerated, and agents.md, architecture.md and README updated where they describe this setting.

## Exceptions

None so far.
