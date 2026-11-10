# Date Input and Resource-History Gap Design

**Date:** 2026-11-10
**Status:** Approved

## Intent

Make Call History date controls visually consistent and ensure the resource
history chart represents missing samples honestly instead of connecting stale
and fresh values across a collection outage.

## Goals

- Style `input[type=date]` like the existing text, search, time, and select
  controls in both light and dark themes.
- Store and query new server-resource timestamps in UTC, independent of the
  active tenant or browser timezone.
- Treat a gap longer than 20 minutes as missing history.
- Break each resource line across missing intervals in both server-rendered
  and JavaScript-refreshed charts.
- Show `No sample data` when hovering in a missing interval rather than
  snapping to a stale nearby sample.
- Keep timestamps in hover text localized by the viewer's browser.

## Constraints and non-goals

- Keep the graph's percentage axis fixed at 0–100%.
- Do not synthesize, interpolate, or backfill missing historical samples.
- Preserve the 24-hour display window, 48-hour retention, one-minute sampling
  throttle, and existing CPU/Memory/Disk colors.
- Existing naive timestamps remain readable; the change cannot recover data
  that was never collected.
- Host resource data remains Superadmin-only.

## Approach

Use the shared form-control CSS for date inputs and apply the existing dark
`color-scheme` behavior to date and time controls. For history, add explicit
UTC formatting/parsing helpers in `ServerStatsHistory`, use those helpers for
recording, range queries, pruning, and series timestamps, and keep returned
timestamps as Unix seconds. Add a shared 20-minute gap contract to the PHP SVG
path builder and its JavaScript equivalent: start a new path segment after a
gap, and display a no-data tooltip when the hovered instant is too far from
the nearest recorded point.

## Alternatives considered

- **Keep connecting points:** visually smooth but falsely implies samples
  existed during outages; rejected.
- **Interpolate/backfill values:** fills the chart but fabricates monitoring
  data; rejected.
- **Auto-scale the Y axis:** makes low CPU values larger but does not solve the
  missing-time problem and weakens percentage comparisons; rejected.
