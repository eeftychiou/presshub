## What changes

<!-- What this PR does and why. Link the issue. -->

## Settings design gate

<!-- Required by agents.md. Tick the first box, or work through the list. See docs/settings-design-principles.md. -->

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

## Tests

<!-- Which suites you ran and their result. -->

## Log evidence

<!-- For bug fixes, the log excerpts described in agents.md, Phase 6. -->
