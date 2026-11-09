# Dashboard, Extension, CDR, and Status Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add accurate registration and date-range workflows while simplifying and correcting tenant System Status.

**Architecture:** Centralize tenant extension AOR matching in the Asterisk service, perform live registration filtering before PHP pagination, and convert tenant-local CDR date boundaries into the configured CDR timezone. Keep host resources Superadmin-only while making daemon version/uptime visible to tenant status viewers and preserving timer-driven history collection.

**Tech Stack:** PHP controllers/views, AMI integration, SQLite smoke tests, systemd deployment scripts.

**Spec:** `docs/superpowers/specs/2026-11-09-dashboard-extension-cdr-status-design.md`

## Global Constraints

- Preserve existing abilities and tenant isolation.
- Registered/Unregistered must never guess when AMI is unavailable.
- Date ranges are tenant-local and inclusive, with CDR-timezone storage boundaries.
- Host resource metrics and graph remain Superadmin-only.
- Do not add Asterisk package upgrades to the panel updater.

## Review Focus

- Multiple Device/Voicemail rows must not duplicate an extension or corrupt totals/pages; pinned by the registration-filter integration test.
- Legacy Web Phone `-web` AORs must agree across Dashboard, Extensions, and tenant status; pinned by canonical-key tests.
- DST and reversed/invalid/one-sided date ranges must remain predictable; pinned by the CDR date-filter test.
- Filter pagination must preserve search, status/disposition, dates, and per-page without malformed query strings; pinned by rendered-link assertions.
- Resource history must continue without a login/page view when the systemd timer is installed; pinned by deployment-script contract assertions.

---

### Task 1: Canonical registration matching and Extension filters

**Files:**
- Modify: `app/Services/Asterisk/AsteriskManager.php:490-519`
- Modify: `app/Controllers/DashboardController.php:88-112`
- Modify: `themes/default/views/dashboard/index.php:56-65`
- Modify: `app/Controllers/ExtensionController.php:25-180`
- Modify: `themes/default/views/extensions/index.php:1-172`
- Create: `tests/extension-registration-filter-smoke.php`

**Interfaces:**
- Produces: `AsteriskManager::extensionContactKeys(string $number, string $realm, string $context): array`.
- Produces: `ExtensionController::registrationMatches(array $extension, array $state, string $filter, bool $available): bool` for deterministic filtering.
- Consumed by: Dashboard registration counts, Extensions filtering, and Task 3 tenant status matching.

- [ ] **Step 1: Write the failing registration-filter smoke test**

Cover canonical realm/`-phone-web`/`-web` keys, Dashboard tile destination, Registered/Unregistered/Disabled classification, AMI-unavailable behavior, duplicate related rows, filtered totals/page membership, toolbar state, and query-preserving pagination.

- [ ] **Step 2: Verify RED**

Run: `php tests/extension-registration-filter-smoke.php`
Expected: FAIL because the canonical helper/filter and `registration` UI do not exist and the tile still links to `/devices`.

- [ ] **Step 3: Implement canonical keys and Dashboard destination**

Add the canonical key helper, replace duplicated Dashboard matching with it, update tenant-contact filtering to include all canonical forms, and link Registered phones to `/extensions?registration=registered`.

- [ ] **Step 4: Implement accurate Extension filtering and pagination**

Validate `registration`, build unique base candidates, attach live state, apply search/status, calculate filtered totals/pages, slice page IDs, and hydrate only selected IDs with deterministic related-record summaries. Pass `registration` and an AMI-unavailable explanation flag to the view.

- [ ] **Step 5: Render and preserve the filter**

Add All/Registered/Unregistered/Disabled controls, contextual empty/unavailable copy, and `http_build_query()` pagination preserving `q`, `registration`, and `per`.

- [ ] **Step 6: Verify GREEN**

Run: `php tests/extension-registration-filter-smoke.php`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit as `feat: filter extensions by registration`.

### Task 2: Tenant-timezone Call History date range

**Files:**
- Modify: `app/Controllers/CdrController.php:18-75`
- Modify: `themes/default/views/cdr/index.php:1-155`
- Create: `tests/cdr-date-filter-smoke.php`

**Interfaces:**
- Consumes: active tenant timezone set by `BaseController` and `config('asterisk.cdr_timezone')`.
- Produces: validated `from`/`to` view values and CDR-timezone SQL boundaries.

- [ ] **Step 1: Write the failing date-range smoke test**

Use an in-memory tenant and CDR rows around local midnight/DST boundaries. Cover From-only, To-only, inclusive two-sided, reversed dates, invalid dates, tenant isolation, search/disposition composition, normalized form values, and pagination query preservation.

- [ ] **Step 2: Verify RED**

Run: `php tests/cdr-date-filter-smoke.php`
Expected: FAIL because `from`/`to` are not consumed or rendered.

- [ ] **Step 3: Implement validated timezone boundaries**

Parse strict `Y-m-d` in the active tenant timezone, normalize/swap valid dates, convert local start and exclusive next-midnight end to Asterisk CDR timezone, and append parameterized `calldate` predicates.

- [ ] **Step 4: Render and preserve the date range**

Add From/To date inputs and build the pagination base from `q`, `disposition`, `from`, `to`, and `per` with `http_build_query()`.

- [ ] **Step 5: Verify GREEN**

Run: `php tests/cdr-date-filter-smoke.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit as `feat: filter call history by date range`.

### Task 3: Correct tenant System Status and pin background sampling

**Files:**
- Modify: `app/Controllers/SystemController.php:19-125`
- Modify: `themes/default/views/system/status.php:33-69`
- Modify: `tests/status-page-smoke.php`
- Modify: `tests/server-stats-smoke.php`
- Include: `docs/superpowers/specs/2026-11-09-dashboard-extension-cdr-status-design.md`
- Include: `docs/superpowers/plans/2026-11-09-dashboard-extension-cdr-status.md`

**Interfaces:**
- Consumes: Task 1 `AsteriskManager::extensionContactKeys()`.
- Produces: tenant-visible Asterisk version/uptime with unchanged Superadmin-only host resources.

- [ ] **Step 1: Write failing status regressions**

Assert Connected extensions is absent; version/uptime remain rendered; controller source/runtime obtains daemon metadata for tenant status viewers; tenant extension IP matching accepts all canonical keys; monitor script records server history; installer and updater create, enable, and restart the minute timer.

- [ ] **Step 2: Verify RED**

Run: `php tests/status-page-smoke.php` and `php tests/server-stats-smoke.php`
Expected: status-page fails on Connected extensions removal and tenant metadata behavior.

- [ ] **Step 3: Implement tenant metadata and simplify summary**

Fetch Asterisk version/uptime whenever AMI is online, retain tenant scoping for all live rows, use canonical extension keys for IP mapping, and remove the Connected extensions tile.

- [ ] **Step 4: Verify GREEN and full repository**

Run both focused tests, PHP lint, all `tests/*smoke.php`, Bash syntax checks for `scripts/*.sh`, and `git diff --check`. Expected: all 27 smoke suites pass.

- [ ] **Step 5: Commit**

Commit status code, tests, design, and plan as `fix: correct tenant system status`.

### Task 4: Whole-branch review

**Files:**
- Review all changes from the branch merge base.

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: reviewed, fully verified branch ready for integration.

- [ ] **Step 1: Request fresh review**

Review registration accuracy, duplicate-row handling, tenant/date timezone boundaries, permission boundaries, timer wiring, and Asterisk-upgrade non-scope.

- [ ] **Step 2: Resolve blocking findings with RED→GREEN tests**

Fix any Critical/Important findings and rerun focused plus full verification.

- [ ] **Step 3: Final verification**

Run PHP lint, all smoke suites, Bash syntax, and `git diff --check`; confirm a clean worktree.
