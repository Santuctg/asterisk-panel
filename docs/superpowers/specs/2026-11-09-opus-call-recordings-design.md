# Opus Call Recordings Design

## Intent

Reduce call-recording storage while preserving reliable Asterisk capture, browser playback, tenant authorization, and safe deletion. New recordings will use Opus at 48 kbps, 16 kHz, mono; existing WAV recordings remain unchanged and playable.

## Goals

- Record every enabled call reliably through the existing `MixMonitor` flow.
- Convert completed recordings to Ogg Opus with `libopus`, mono, 16 kHz, and a target bitrate of 48 kbps.
- Use an atomic final-file move and remove the temporary WAV only after successful conversion.
- Retain the WAV automatically when conversion fails.
- Support playback, download, availability checks, and deletion for both new `.ogg` and legacy `.wav` recordings.
- Install the protected conversion helper on fresh installations and upgrades.

## Non-goals

- Do not bulk-convert existing WAV recordings.
- Do not transcode while a call is active.
- Do not expose recording files directly through the public web root.
- Do not change tenant recording-mode or recording-start behavior.

## Approaches

1. **Selected: temporary WAV plus post-call FFmpeg conversion.** This keeps Asterisk capture dependable, guarantees the requested codec parameters, permits atomic output, and preserves WAV on failure.
2. **Rejected: direct Asterisk Opus/Ogg recording.** Installed Asterisk format modules and encoder controls vary, so 48 kbps Opus cannot be guaranteed consistently.
3. **Rejected: upgrade-time conversion of existing files.** It adds deployment time, CPU load, rollback complexity, and risk without being required.

## Design

- Generated dialplan records to a safe temporary `.wav` filename and stores an extension-independent recording marker in `CDR(userfield)`.
- `MixMonitor` invokes a root-installed, non-privileged-at-runtime helper after capture closes. The helper validates that source and target remain inside the recording directory.
- FFmpeg parameters are `-ac 1 -ar 16000 -c:a libopus -b:a 48k -vbr on -application voip`; output is an Ogg container with `.ogg` extension.
- FFmpeg writes a temporary Ogg file; the helper verifies it is non-empty and atomically renames it. Only then is the WAV removed.
- Recording resolution accepts legacy markers ending in `.wav` and new extension-independent markers. For new markers it prefers `.ogg`, then falls back to `.wav` while conversion is pending or failed.
- HTTP responses use `audio/ogg` for Opus and `audio/wav` for WAV. Download labels reflect the resolved format.
- Deletion removes every supported file associated with the authorized marker without crossing the configured recording directory.
- The installer and updater deploy the helper with executable, root-owned permissions. Asterisk executes it using its existing service account; no elevated privilege is required.

## Security and Reliability Constraints

- Preserve tenant-scoped CDR authorization and internal Nginx delivery.
- Never pass unvalidated user-controlled paths to FFmpeg or the shell.
- Quote all dialplan command arguments and validate basename characters again in the helper.
- Never delete the WAV unless a valid non-empty Ogg file exists.
- Existing WAV recordings and CDR markers remain backward compatible.

## Release

- Publish as `v1.4.3` after PHP lint, shell syntax, smoke suites, generated-dialplan tests, helper conversion/failure tests, browser playback checks, and release-integrity verification pass.
