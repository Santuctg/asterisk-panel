# v1.4.5 Release Design

**Date:** 2026-11-09
**Status:** Approved

## Intent

Publish the merged role-permission consistency work as Asterisk Panel v1.4.5
through the repository's existing verified GitHub release channel.

## Goals

- Bump the application version and release smoke expectation to `1.4.5`.
- Run the full PHP lint and smoke-test verification before publication.
- Commit the release preparation, create an annotated `v1.4.5` tag, push
  `main` and the tag, and publish a GitHub Release.
- Describe the complete role picker, Dashboard authorization, permission-aware
  System navigation, and restricted-role redirect-loop prevention.

## Constraints and non-goals

- Never overwrite or move an existing `v1.4.5` tag or release.
- Do not add product behavior beyond the already merged permission work.
- Do not deploy or trigger a production server update.
- Local `HEAD`, `origin/main`, the tag, and GitHub Release target must match.

## Approach

Use the established patch-release flow from v1.4.4: update the single version
source and its smoke test, verify the complete tree, commit, create and push an
annotated tag, publish user-facing notes, and verify all release references.
An untagged deployment or a larger semantic-version bump would not match the
approved patch scope.
