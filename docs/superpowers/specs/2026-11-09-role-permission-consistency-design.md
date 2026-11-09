# Role Permission Consistency Design

**Date:** 2026-11-09
**Status:** Approved approach; awaiting document confirmation

## Intent

Make tenant role configuration match the permissions the application actually
enforces, without breaking the standalone Web Phone login flow.

## Goals

- Expose all tenant-assignable permissions in the role editor, including
  `contact.view`, `contact.manage`, and `call.hangup`.
- Require `dashboard.view` on the Dashboard page, live-calls polling endpoint,
  and global search endpoint.
- Show each System submenu link only when the user has the corresponding
  permission.
- Keep `manage` implying the matching `view`, as implemented by `Auth::can()`.

## Compatibility decision

The public Web Phone remains authenticated by tenant hostname plus extension
credentials. `webphone.use` continues to control whether a panel role sees the
Web Phone link; it will not replace the independent phone authentication flow.

## Non-goals

- Do not make global abilities (`tenant.*`, `system.update`, `system.apply`, or
  `security.fail2ban`) assignable to tenant custom roles.
- Do not change existing saved role permission JSON.
- Do not change the built-in Admin or User defaults.

## Approaches considered

1. **Compatibility-safe consistency (chosen):** repair the role picker,
   Dashboard authorization, and System submenu filtering while preserving the
   standalone Web Phone.
2. **Strict panel-controlled Web Phone:** require a panel session and
   `webphone.use`; rejected because it breaks the existing public
   extension/password workflow.

## Verification

- A contract test compares every assignable ability against the role editor
  groups, allowing no omissions.
- Route tests prove Dashboard and search require `dashboard.view`.
- Layout tests prove System children carry and apply their own abilities.
- Existing Web Phone authentication tests remain green.
