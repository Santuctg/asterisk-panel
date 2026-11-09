# v1.4.4 Release Design

**Date:** 2026-11-09
**Status:** Approved

## Intent

Publish the already merged Material audio player and tenant-timezone fixes as
the next patch release so production installations can receive them through the
normal updater.

## Scope

- Bump the application version from `1.4.3` to `1.4.4`.
- Verify the committed `main` tree before publishing.
- Push `main`, create and push an annotated `v1.4.4` tag, and publish a GitHub
  Release with concise user-facing notes.

## Release contents

- Material-style pill audio player across panel-controlled audio.
- No visible audio player for Active Calls browser listening, with safe
  one-click autoplay recovery.
- Tenant-local timestamps aligned across Dashboard, Call History, and Web Phone.
- Player error feedback, accessibility, dynamic-player lifecycle tests, and
  retry cleanup hardening.

## Constraints

- Patch release only; no additional product features.
- Do not run the production updater; deployment remains user-controlled.
- No bulk conversion of existing WAV recordings.

## Approach

Use the repository's existing release flow: version commit, full verification,
annotated Git tag, push `main` and tag, then create a GitHub Release. A larger
minor release or an untagged deployment would not match the size of these
backward-compatible fixes.
