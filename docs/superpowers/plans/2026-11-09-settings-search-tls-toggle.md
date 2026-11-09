# Settings Search and SIP Transport Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Settings sections discoverable through permission-safe panel search and make SIP TCP/TLS On/Off controls explicit.

**Architecture:** Extend `SearchController` with a static, non-secret Settings section catalog filtered by `system_settings.view`, while retaining tenant filters for database entities. Render transport state as On/Off selects using the existing POST keys, add section anchors, and use a small generic DOM binding to disable the corresponding port input when Off.

**Tech Stack:** PHP 8.2+, vanilla JavaScript, existing PHP view helpers, SQLite/MySQL-compatible application layer, smoke-test scripts.

**Spec:** `docs/superpowers/specs/2026-11-09-settings-search-tls-toggle-design.md`

## Global Constraints

- Do not index settings values, credentials, certificate contents, or secrets.
- Do not return Settings results without `system_settings.view`.
- Preserve tenant scoping, CSRF middleware, authorization, responsive layout, and keyboard search behavior.
- Preserve UDP behavior and the server-side check preventing disablement of a transport still assigned to a trunk.
- Publish as version `1.4.2` only after full verification.

## Review Focus

- A user without `system_settings.view` searching `TLS` receives no Settings result.
- A user with permission gets relevant section results for case-insensitive terms and synonyms, not stored values.
- Empty and entity-only searches preserve current response shape and tenant isolation.
- Selecting Off disables only the matching TCP/TLS port control; selecting On restores it.
- Submitting Off still sends `0`, and server-side trunk-in-use rejection remains authoritative.

---

### Task 1: Permission-safe Settings section search

**Files:**
- Modify: `app/Controllers/SearchController.php`
- Modify: `themes/default/views/system/settings.php`
- Test: `tests/contacts-branding-smoke.php`

**Interfaces:**
- Consumes: `Auth::can(string $ability): bool`, current `/search?q=` JSON response.
- Produces: search rows shaped as `{kind: "Setting", title: string, meta: string, url: "/system/settings#<id>"}` and matching view IDs.

- [ ] **Step 1: Write failing search tests**

Add assertions that an authorized user searching `TLS` receives `SIP transports` at `/system/settings#sip-transports`, searching `branding` receives the branding section, no stored setting value appears, and a user lacking `system_settings.view` receives no `Setting` row.

- [ ] **Step 2: Run the focused test and confirm failure**

Run: `php tests/contacts-branding-smoke.php`

Expected: FAIL because Settings metadata is not currently searched.

- [ ] **Step 3: Implement the catalog and anchors**

Add a private `settingResults(string $query): array` method to `SearchController`. Match normalized query text against fixed title/description/keyword strings, return at most the relevant fixed rows, and call it only when `system_settings.view` is allowed. Add IDs `sip-transports`, `panel-branding`, `business-details`, `voicemail-email`, and `call-recording` to the corresponding Settings cards.

- [ ] **Step 4: Run the focused test and confirm pass**

Run: `php tests/contacts-branding-smoke.php`

Expected: all contact, branding, and search checks pass.

- [ ] **Step 5: Commit Task 1**

```bash
git add app/Controllers/SearchController.php themes/default/views/system/settings.php tests/contacts-branding-smoke.php
git commit -m "feat: add settings sections to panel search"
```

### Task 2: Explicit SIP TCP/TLS On/Off controls

**Files:**
- Modify: `themes/default/views/system/settings.php`
- Modify: `public/assets/js/app.js`
- Test: `tests/ui-preferences-smoke.php`
- Test: `tests/live-listen-smoke.php`

**Interfaces:**
- Consumes: POST keys `sip_tcp_enabled`, `sip_tls_enabled`, `sip_tcp_port`, `sip_tls_port` and existing `SystemController::saveSettings()` validation.
- Produces: selects with values `1`/`0` and `data-transport-toggle` pointing to the matching port input ID.

- [ ] **Step 1: Write failing markup and behavior tests**

Assert both transport state fields are selects with exact On/Off values, each is bound to the proper port field, the JS initializes and updates `disabled` plus an explanatory disabled style, and generated PJSIP output still omits disabled transports while trunk-in-use validation remains present.

- [ ] **Step 2: Run focused tests and confirm failure**

Run: `php tests/ui-preferences-smoke.php` and `php tests/live-listen-smoke.php`

Expected: UI smoke test fails because the view still renders checkboxes and has no port-state binding.

- [ ] **Step 3: Implement explicit selectors and generic UI binding**

Render `field_select()` controls labelled `SIP TCP` and `SIP TLS`, with `1 => On` and `0 => Off`, preserving existing names. Add `data-transport-toggle="<port-id>"`. In `app.js`, bind each selector on load and change; set the target input's `disabled` state and toggle a `transport-disabled` class on its `.field` container. Do not alter backend defaults or safety validation.

- [ ] **Step 4: Run focused tests and confirm pass**

Run: `php tests/ui-preferences-smoke.php` and `php tests/live-listen-smoke.php`

Expected: all checks pass.

- [ ] **Step 5: Commit Task 2**

```bash
git add themes/default/views/system/settings.php public/assets/js/app.js public/assets/css/app.css tests/ui-preferences-smoke.php tests/live-listen-smoke.php
git commit -m "feat: clarify SIP transport on off controls"
```

### Task 3: Version, full verification, and release

**Files:**
- Modify: `resources/config/app.php`
- Test: all `tests/*smoke.php`

**Interfaces:**
- Consumes: completed Tasks 1–2.
- Produces: verified `v1.4.2` commit, annotated tag, pushed `main`, and published GitHub Release.

- [ ] **Step 1: Set version to `1.4.2`**

Update only `resources/config/app.php` version value.

- [ ] **Step 2: Run complete verification**

Run full PHP lint, Git-for-Windows Bash syntax checks for all `scripts/*.sh`, every `tests/*smoke.php`, `git diff --check`, and Chromium checks for search result navigation plus On/Off port behavior.

Expected: all commands and browser assertions pass.

- [ ] **Step 3: Request whole-change review and resolve findings**

Review against the spec, permission boundaries, tenant isolation, POST semantics, accessibility, and release constraints. Re-run affected focused tests after any correction.

- [ ] **Step 4: Commit release version and tag**

```bash
git add resources/config/app.php docs/superpowers/specs/2026-11-09-settings-search-tls-toggle-design.md docs/superpowers/plans/2026-11-09-settings-search-tls-toggle.md
git commit -m "v1.4.2: improve settings search and SIP controls"
git tag -a v1.4.2 -m "Asterisk Panel v1.4.2"
```

- [ ] **Step 5: Push and publish**

Push `main` and `v1.4.2`, publish release notes describing Settings section search and explicit transport On/Off controls, then verify local HEAD, `origin/main`, tag commit, release target, and clean worktree all match.
