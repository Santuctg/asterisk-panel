# Role Permission Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the tenant role editor, Dashboard authorization, and System navigation consistently reflect the application's assignable permissions.

**Architecture:** Keep `Auth::assignableAbilities()` as the source of truth for tenant-delegable permissions. The role view will group every assignable key exactly once, Dashboard endpoints will use normal ability middleware, and the layout will filter System children individually while linking the parent to the first visible child.

**Tech Stack:** PHP controllers/views, route middleware, PHP smoke tests.

**Spec:** `docs/superpowers/specs/2026-11-09-role-permission-consistency-design.md`

## Global Constraints

- Preserve the standalone Web Phone extension/password authentication flow.
- `webphone.use` controls only the authenticated panel navigation link.
- Do not make global abilities (`tenant.*`, `system.update`, `system.apply`, `security.fail2ban`) tenant-assignable.
- Do not alter existing saved role permission JSON or built-in Admin/User defaults.
- Keep `manage` implying the matching `view` through the existing `Auth::can()` behavior.

## Review Focus

- Permission drift: every key from `Auth::assignableAbilities()` must appear exactly once in the role editor groups; pinned by the role consistency smoke test.
- Direct URL bypass: `/`, `/dashboard`, `/dashboard/live-calls`, and `/search` must require `dashboard.view`; pinned by exact route assertions.
- Restricted-role landing: login and authorization denial must choose the first authorized destination, falling back to `/profile`; pinned by behavioral tenant-auth coverage.
- Settings-only roles: System parent must remain visible even without `system_status.view`, and link to the first permitted child; pinned by layout contract assertions.
- No System access: users with no visible System child must not see an empty System group; pinned by the child-filtering assertion.
- Public Web Phone compatibility: its routes must remain unauthenticated panel routes; pinned by unchanged-route assertions and the existing Web Phone smoke coverage.

---

### Task 1: Align role picker, Dashboard routes, and System navigation

**Files:**
- Modify: `themes/default/views/roles/index.php:2-8`
- Modify: `resources/routes.php:45-50`
- Modify: `themes/default/layouts/app.php:36-71,84-110`
- Create: `tests/role-permission-consistency-smoke.php`
- Include: `docs/superpowers/specs/2026-11-09-role-permission-consistency-design.md`
- Include: `docs/superpowers/plans/2026-11-09-role-permission-consistency.md`

**Interfaces:**
- Consumes: `Auth::assignableAbilities(): array<string,string>` and `Auth::can(string): bool`.
- Produces: a role-editor group map containing each assignable key once; Dashboard routes protected by `dashboard.view`; permission-filtered System child navigation.

- [ ] **Step 1: Write the failing consistency smoke test**

Create `tests/role-permission-consistency-smoke.php` that:

1. Loads `Auth::assignableAbilities()` and extracts permission keys from `$permissionGroups` in the role view; asserts no missing, duplicate, or non-assignable keys. The initial failure must name `contact.view`, `contact.manage`, and `call.hangup` as missing and `system.apply` as non-assignable.
2. Asserts `/`, `/dashboard`, `/dashboard/live-calls`, and `/search` each use `Auth::require('dashboard.view')`.
3. Asserts the Dashboard nav item carries `dashboard.view`.
4. Asserts System children carry `system_status.view`, `system_settings.view`, `tenant.manage`, and `audit.view`; rendering filters children with `$canSee`, skips an empty parent, and points the parent href to its first visible child.
5. Asserts `/web-phone` and its connect/session routes remain outside panel `Auth::require('webphone.use')` middleware.

- [ ] **Step 2: Verify RED**

Run: `php tests/role-permission-consistency-smoke.php`
Expected: FAIL first on role-group drift, reporting the three missing permissions and the non-assignable `system.apply` entry.

- [ ] **Step 3: Align the role permission groups**

Add `contact.view` and `contact.manage` to People & access (or a dedicated contacts group), add `call.hangup` to Reports & tools, and remove `system.apply` from the tenant role group map. Ensure all 38 assignable keys appear exactly once.

- [ ] **Step 4: Protect Dashboard endpoints**

Change the four specified routes to `Auth::require('dashboard.view')` and set the Dashboard navigation item's ability to `dashboard.view`. Leave tenant switching and standalone Web Phone routes unchanged.

- [ ] **Step 5: Filter System children**

Add abilities to each System child. Before rendering a parent with children, filter children through `$canSee`; skip the parent when none remain; when some remain, use the first visible child's href for the parent link and active matching.

- [ ] **Step 6: Verify GREEN and regression suite**

Run the new smoke test, PHP lint, every `tests/*smoke.php`, and `git diff --check`.
Expected: all checks pass (25 smoke suites after adding this test).

- [ ] **Step 7: Commit**

Commit product files, test, spec, and plan as `fix: align role permission controls`.

- [ ] **Step 8: Resolve restricted-role landing review finding**

Add a behavioral regression test for settings-only and no-permission roles.
Centralize authorized landing selection in `Auth`, and use it after successful
login, when an authenticated user visits `/login`, and after permission denial.
