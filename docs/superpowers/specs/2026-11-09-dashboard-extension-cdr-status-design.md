# Dashboard, Extension, CDR, and Status Improvements Design

**Date:** 2026-11-09
**Status:** Approved

## Intent

Make live registration navigation and filtering accurate, add tenant-timezone
date-range filtering to Call History, and simplify tenant System Status while
preserving background resource-history collection.

## Goals

- Make the Dashboard Registered phones tile open the Extensions page filtered
  to registered extensions.
- Add server-side Extensions filters for All, Registered, Unregistered, and
  Disabled, with correct totals and pagination.
- Add inclusive From date and To date filters to Call History.
- Show Asterisk version and uptime to tenant users who may view System Status.
- Remove the redundant Connected extensions summary tile.
- Ensure and document that resource history is sampled without an open browser
  session by the existing systemd monitor timer.

## Chosen approach

### Dashboard and Extensions

The Registered phones tile links to
`/extensions?registration=registered`. `ExtensionController::index()` accepts
only `registered`, `unregistered`, or `disabled`; any other value means All.

Registration is live AMI state and cannot be expressed in the database query.
The controller therefore loads the tenant's database/search candidates,
attaches live registration state, applies the selected status, then paginates
the filtered result in PHP. A single Asterisk helper will produce canonical
contact keys for a tenant extension (`number@realm`, `-phone-web`, and legacy
`-web` forms); Dashboard counts, Extensions filters, tenant contact filtering,
and extension-IP lookup will all use it so their registration results agree.
Status meanings are:

- **Registered:** enabled extension with at least one matching Asterisk contact.
- **Unregistered:** enabled extension with no matching contact while AMI is online.
- **Disabled:** database `enabled = 0`, independent of AMI.

If AMI is unavailable, Registered and Unregistered filters return no claimed
matches and show an unavailable-state explanation instead of misclassifying
phones. Disabled remains usable. Search, status, page, and per-page values are
preserved in filter forms and pagination links.

Filtering starts from unique base extension records, not rows produced by
one-to-many joins. After status filtering and pagination choose extension IDs,
the controller hydrates only those page IDs. Any Voicemail or Device summary
uses deterministic aggregation/subqueries so duplicate related rows cannot
corrupt totals or page membership.

### Call History date range

The CDR toolbar adds optional HTML date inputs named `from` and `to`. Each is
strictly validated as `Y-m-d`; invalid values are ignored. When both are valid
and From is later than To, the controller swaps them for a usable range.

Dates are entered in the active tenant timezone. The controller converts the
inclusive local boundaries (`00:00:00` on From and the next local midnight
after To) to `ASTERISK_CDR_TIMEZONE`, then filters the timezone-less
`cdr.calldate` column with `>= start` and `< exclusiveEnd`. This handles DST and
keeps filtering consistent with `format_cdr_date()`. Search, disposition,
dates, page, and per-page values remain in pagination URLs.
Only validated, normalized dates are returned to the view; when reversed input
is swapped, the form displays the resulting chronological order.

All filter and pagination URLs are built from parameter arrays with
`http_build_query()` rather than manual `?`/`&` concatenation.

### System Status

Asterisk version and uptime are harmless daemon metadata already visible to
Superadmin and will be fetched for any authenticated user authorized for
System Status. Tenant scoping remains on channels, contacts, conferences,
queues, and extension IPs. Host CPU, memory, disk, network, and resource graph
remain Superadmin-only.

The Connected extensions summary tile is removed. Registered endpoints remains
the single registration summary and detailed table.

### Resource history

No browser session is required for history collection. The existing
`asterisk-panel-trunk-monitor.timer` runs `scripts/monitor-trunks.php` once per
minute; that script records and prunes server samples. Page loads and
Superadmin polling remain supplementary. Tests will pin the background sampler
and installer/updater timer wiring so this behavior cannot silently regress.

## Permissions and tenant isolation

- Dashboard and Extensions retain their existing abilities.
- Call History retains `cdr.view`; date filters never cross tenant scope.
- System Status retains `system_status.view`.
- Host resource metrics remain Superadmin-only.
- No new permissions or global operations are introduced.

## Asterisk upgrade boundary

The panel updater updates Asterisk Panel only. Upgrading Asterisk itself remains
an operating-system/package-repository administration task because it can
change modules, configuration compatibility, and service availability. No
Asterisk upgrade button or automated package command is added in this work.

## Alternatives considered

1. **Server-side accurate filtering (chosen):** correct totals, pagination,
   tenant scope, and timezone semantics.
2. **Client-side current-page filtering:** rejected because matches on other
   pages disappear and displayed totals become false.
3. **Raw SQL date strings in tenant timezone:** rejected because CDR timestamps
   are stored in the configured Asterisk CDR timezone.

## Verification

- Dashboard smoke coverage pins the Registered phones destination.
- Extension smoke coverage exercises all status filters, search composition,
  AMI-unavailable handling, totals, and pagination query preservation.
- CDR smoke coverage exercises one-sided/two-sided ranges, reversed dates,
  invalid dates, tenant isolation, disposition/search composition, and
  tenant-to-CDR timezone conversion across a DST boundary.
- Status smoke coverage proves tenant version/uptime visibility, Connected
  extensions removal, host-resource restriction, and background timer wiring.
