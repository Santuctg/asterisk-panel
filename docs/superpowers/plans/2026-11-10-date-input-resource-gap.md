# Date Input and Resource-History Gap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Style Call History date fields consistently and represent missing resource-history intervals without fabricated continuity.

**Architecture:** Extend the shared form CSS to date controls. Normalize `ServerStatsHistory` persistence and parsing to UTC, then apply one 20-minute gap contract to the PHP SVG renderer and browser-side renderer/tooltip.

**Tech Stack:** PHP, SQLite smoke tests, CSS, vanilla JavaScript SVG rendering.

**Spec:** `docs/superpowers/specs/2026-11-10-date-input-resource-gap-design.md`

## Global Constraints

- Keep the chart scale at 0–100%.
- Never interpolate or backfill missing samples.
- Preserve 24-hour display, 48-hour retention, and one-minute throttling.
- Keep hover timestamps browser-local and host metrics Superadmin-only.
- Existing naive history rows must remain readable.

## Review Focus

- A 20-minute boundary is still continuous; any larger interval breaks paths and reports no data.
- PHP initial rendering and JavaScript polling must apply identical gap behavior.
- UTC persistence must not depend on active tenant or process timezone.
- Date controls must retain browser calendar behavior while matching both themes.
- Sparse/null metrics must not hide usable lines from other metrics.

---

### Task 1: Style date inputs

**Files:**
- Modify: `public/assets/css/app.css:621-670`
- Modify: `tests/ui-preferences-smoke.php`

**Interfaces:**
- Produces: shared form-control and dark-theme styling for `input[type=date]`.

- [ ] **Step 1: Add failing CSS contract assertions**

Assert date inputs are in the shared control selector, toolbar sizing selector, and dark `color-scheme` selector.

- [ ] **Step 2: Verify RED**

Run: `php tests/ui-preferences-smoke.php`
Expected: FAIL because date controls are absent from those selectors.

- [ ] **Step 3: Add minimal date-input CSS**

Reuse existing input tokens and native calendar picker; do not replace the browser date UI.

- [ ] **Step 4: Verify GREEN**

Run: `php tests/ui-preferences-smoke.php`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit as `fix: style call history date filters`.

### Task 2: Preserve UTC and expose resource-history gaps

**Files:**
- Modify: `app/Services/ServerStatsHistory.php`
- Modify: `app/Support/helpers.php:402-489`
- Modify: `themes/default/views/system/status.php:395-493`
- Modify: `tests/server-stats-smoke.php`
- Include: `docs/superpowers/specs/2026-11-10-date-input-resource-gap-design.md`
- Include: `docs/superpowers/plans/2026-11-10-date-input-resource-gap.md`

**Interfaces:**
- Produces: `ServerStatsHistory::GAP_SECONDS = 1200`.
- Produces: UTC `sampled_at` writes/ranges/pruning and Unix-second series output.
- Consumed by: PHP and JavaScript chart renderers for path breaks and no-data hover.

- [ ] **Step 1: Add failing history and chart assertions**

Pin timezone-independent UTC recording/parsing, continuity at exactly 1200 seconds, path breaks above 1200 seconds, and JavaScript no-data tooltip behavior.

- [ ] **Step 2: Verify RED**

Run: `php tests/server-stats-smoke.php`
Expected: FAIL on UTC and gap contracts.

- [ ] **Step 3: Normalize history timestamps to UTC**

Use explicit UTC formatting/parsing for writes, look-back queries, pruning, throttle comparisons, and series timestamps without changing the schema.

- [ ] **Step 4: Break server-rendered paths across gaps**

Start a new SVG subpath when consecutive samples differ by more than `GAP_SECONDS`; exactly 1200 seconds remains continuous.

- [ ] **Step 5: Match JavaScript paths and hover behavior**

Apply the same threshold after polling. In a missing interval, show `No sample data` and do not report a stale metric value.

- [ ] **Step 6: Verify GREEN and full repository**

Run focused tests, PHP lint, all 27 smoke suites, Bash syntax, and `git diff --check`.

- [ ] **Step 7: Commit**

Commit as `fix: expose gaps in resource history`.

### Task 3: Whole-branch review

**Files:**
- Review all changes from the branch merge base.

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces: reviewed branch ready for integration.

- [ ] **Step 1: Request fresh review**

Review date-field consistency, UTC compatibility, threshold boundaries, server/client parity, and permission boundaries.

- [ ] **Step 2: Resolve Critical/Important findings with RED→GREEN tests**

- [ ] **Step 3: Run final full verification**
