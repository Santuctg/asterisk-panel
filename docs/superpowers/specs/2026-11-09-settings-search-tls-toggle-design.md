# Settings Search and SIP Transport Toggle Design

## Intent

Make panel search useful for configuration discovery and make disabling SIP TCP/TLS obvious. A permitted user searching for terms such as `TLS`, `SIP port`, `branding`, or `voicemail email` should reach the relevant Settings section directly.

## Goals

- Add permission-scoped Settings section results to the existing tenant-scoped search response.
- Add stable anchors for Settings sections.
- Replace the visually ambiguous TCP/TLS checkboxes with explicit On/Off selectors beside their ports.
- Visually disable a transport's port input when that transport is Off.
- Preserve the existing backend protection that prevents disabling a transport assigned to a trunk.

## Non-goals

- Do not index secret values, credentials, certificate contents, or arbitrary database settings.
- Do not expose Settings links to users without `system_settings.view`.
- Do not change UDP availability or trunk transport semantics.

## Approaches

1. **Selected: section-level search catalog and explicit selectors.** A small permission-aware catalog in `SearchController` maps searchable terms to Settings anchors. This is deterministic, safe, and takes the user directly to the relevant control.
2. **Rejected: one generic Settings result.** Simpler, but does not help users locate a specific configuration section and keeps the TLS control ambiguous.

## Design

- Search results include a `Setting` kind for matching sections such as SIP transports, branding, business details, voicemail email, and call recording.
- Results are filtered by the current user's permission before being returned. Existing entity queries remain tenant-scoped.
- Settings cards receive stable IDs, and result URLs use `/system/settings#<section>`.
- TCP and TLS use select controls with `On` and `Off` values while retaining the existing POST keys (`sip_tcp_enabled`, `sip_tls_enabled`).
- Client-side behavior disables and dims the matching port field when Off; server-side validation and trunk-in-use checks remain authoritative.
- Tests cover permission filtering, term matching, anchors, selector rendering, and transport configuration behavior.

## Constraints

- Preserve CSRF handling and existing authorization middleware.
- Keep responsive layout and keyboard-accessible search behavior.
- Complete the patch as a new versioned release after verification.
