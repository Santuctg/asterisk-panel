# v1.4.6 Release Design

**Date:** 2026-11-09
**Status:** Approved

## Intent

Publish the merged Dashboard, Extensions registration filters, Call History
date filters, and tenant System Status corrections as Asterisk Panel v1.4.6
through the repository's existing verified GitHub release channel.

## Goals

- Bump the application version and release smoke expectation to `1.4.6`.
- Run the full PHP lint, smoke-test, and Bash verification before publication.
- Commit the release preparation, create an annotated `v1.4.6` tag, push
  `main` and the tag, and publish a GitHub Release.
- Describe registration navigation/filtering, tenant-timezone CDR ranges,
  corrected tenant status metadata, background resource history, and tenant
  isolation hardening.

## Constraints and non-goals

- Never overwrite or move an existing `v1.4.6` tag or release.
- Do not add product behavior beyond the already merged work.
- Do not deploy or trigger a production server update.
- Do not introduce Asterisk package upgrades into the panel updater.
- Local `HEAD`, `origin/main`, the tag, and GitHub Release target must match.

## Approach

Use the established patch-release flow from v1.4.5: update the single version
source and its smoke test, verify the complete tree, commit, create and push an
annotated tag, publish user-facing notes, and verify every release reference.
An untagged deployment or a larger semantic-version bump would not match the
approved patch scope.
