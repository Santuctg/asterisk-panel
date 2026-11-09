# Opus Call Recordings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store new call recordings as browser-playable Ogg Opus at 48 kbps, 16 kHz, mono while retaining reliable temporary WAV capture and legacy WAV compatibility.

**Architecture:** `MixMonitor` records a temporary WAV and invokes a standalone PHP helper after capture closes. The helper uses FFmpeg with fixed Opus parameters, atomically publishes `.ogg`, and removes WAV only after success; CDR markers become extension-independent and the controller resolves Opus first with WAV fallback.

**Tech Stack:** PHP 8.2+, Asterisk MixMonitor dialplan, FFmpeg/libopus, Nginx X-Accel-Redirect, existing PHP smoke tests.

**Spec:** `docs/superpowers/specs/2026-11-09-opus-call-recordings-design.md`

## Global Constraints

- New recordings use Ogg Opus, mono, 16 kHz, target bitrate 48 kbps, VBR, and the Opus `voip` application.
- Never delete a WAV unless a valid non-empty Ogg output has been atomically published.
- Keep existing `.wav` markers and files playable and deletable; do not bulk-convert them.
- Preserve tenant-scoped CDR authorization and internal Nginx delivery.
- Reject unsafe basenames and never interpolate unvalidated paths into a shell command.
- Preserve recording mode and recording-start behavior.
- Publish as version `1.4.3` only after full verification.

## Review Focus

- FFmpeg missing, non-zero exit, empty output, or interrupted conversion leaves the WAV intact and no final Ogg.
- New extension-independent markers resolve `.ogg` first and `.wav` while conversion is pending or failed.
- Legacy `recording:name.wav` markers behave exactly as before.
- Deleting a new marker removes both supported files but cannot remove another tenant's file or escape the recording directory.
- MixMonitor command arguments contain only generated safe basenames and use the protected installed helper.

---

### Task 1: Safe Opus conversion helper

**Files:**
- Create: `scripts/transcode-recording.php`
- Create: `tests/recording-opus-smoke.php`

**Interfaces:**
- Produces: `transcode_recording_main(array $argv, ?callable $runner = null): int` and CLI usage `transcode-recording BASENAME`.
- Consumes later: a generated basename matching `^[A-Za-z0-9][A-Za-z0-9_.-]{0,240}$` in `ASTERISK_RECORDING_DIR`.

- [x] **Step 1: Write failing helper tests**

Test an injected runner that captures exact FFmpeg arguments (`-ac 1`, `-ar 16000`, `-c:a libopus`, `-b:a 48k`, `-vbr on`, `-application voip`, `-f ogg`), writes the temporary output, and returns success. Assert atomic final `.ogg`, removed WAV, no temporary file, failure/empty-output WAV retention, and unsafe-basename rejection.

- [x] **Step 2: Run the focused test and confirm failure**

Run: `php tests/recording-opus-smoke.php`

Expected: FAIL because the helper does not exist.

- [x] **Step 3: Implement the standalone helper**

Implement `transcode_recording_main()` without shell interpolation. Resolve the recording directory from `ASTERISK_RECORDING_DIR` with the production default, run `/usr/bin/ffmpeg` through an argument array when no runner is injected, write to a unique temporary path using `-f ogg`, require a non-empty output, rename atomically, then remove WAV. Clean temporary output on every failure and return non-zero while retaining WAV.

- [x] **Step 4: Run the focused test and confirm pass**

Run: `php tests/recording-opus-smoke.php`

Expected: all Opus helper checks pass.

- [x] **Step 5: Commit Task 1**

```bash
git add scripts/transcode-recording.php tests/recording-opus-smoke.php
git commit -m "feat: add safe Opus recording converter"
```

### Task 2: Dual-format CDR playback and deletion

**Files:**
- Modify: `app/Controllers/CdrController.php`
- Modify: `themes/default/views/cdr/index.php`
- Modify: `tests/cdr-delete-smoke.php`

**Interfaces:**
- Consumes: legacy markers `recording:name.wav` and new markers `recording:name`.
- Produces: resolved records `{filename, path, mime, format}` preferring `.ogg`; HTTP `audio/ogg` or `audio/wav`; format-aware modal download label.

- [x] **Step 1: Write failing CDR compatibility tests**

Create legacy WAV, new Ogg, and pending/fallback WAV fixtures. Assert new markers prefer Ogg, pending markers resolve WAV, legacy markers remain unchanged, MIME and X-Accel filenames are correct, modal labels show OPUS/WAV, unsafe markers fail closed, and authorized deletion removes both candidate files while cross-tenant deletion removes neither.

- [x] **Step 2: Run the focused test and confirm failure**

Run: `php tests/cdr-delete-smoke.php`

Expected: FAIL because extension-independent and Ogg markers are unsupported.

- [x] **Step 3: Implement dual-format resolution and deletion**

Refactor private marker parsing so new basenames resolve `.ogg` then `.wav`, legacy `.wav` resolves exactly, and every candidate remains a safe basename in the configured directory. Return dynamic MIME/format, expose format in CDR rows, update playback response headers, and delete all files associated with an authorized marker.

- [x] **Step 4: Run the focused test and confirm pass**

Run: `php tests/cdr-delete-smoke.php`

Expected: all tenant-scoped CDR recording checks pass.

- [x] **Step 5: Commit Task 2**

```bash
git add app/Controllers/CdrController.php themes/default/views/cdr/index.php tests/cdr-delete-smoke.php
git commit -m "feat: support Opus and legacy WAV recordings"
```

### Task 3: Asterisk and deployment integration

**Files:**
- Modify: `app/Services/Asterisk/ConfigGenerator.php`
- Modify: `scripts/install-server-ubuntu.sh`
- Modify: `scripts/update-helper.sh`
- Modify: `tests/announcement-smoke.php`
- Modify: `tests/ui-preferences-smoke.php`

**Interfaces:**
- Consumes: Task 1 CLI `transcode-recording BASENAME` and Task 2 extension-independent marker.
- Produces: generated MixMonitor lines that write `<basename>.wav`, store `recording:<basename>`, and invoke `/usr/local/lib/asterisk-panel/transcode-recording <basename>` after recording closes.

- [x] **Step 1: Write failing dialplan and deployment tests**

Assert generated dialplan separates basename/WAV variables, stores an extension-independent CDR marker, passes the helper as MixMonitor's command for full and answered-only recording, and never includes request-derived text. Assert installer/updater deploy `scripts/transcode-recording.php` as root-owned mode `0755` at the exact protected path.

- [x] **Step 2: Run focused tests and confirm failure**

Run: `php tests/announcement-smoke.php` and `php tests/ui-preferences-smoke.php`

Expected: recording dialplan/deployment assertions fail.

- [x] **Step 3: Implement dialplan and deployment wiring**

Generate a safe `PANEL_RECORDING_BASENAME`, derive the temporary WAV filename, set the base marker, and add the installed helper as MixMonitor's third argument for both branches. Install the helper during fresh install and update with `root:root` ownership and `0755` mode.

- [x] **Step 4: Run focused tests and confirm pass**

Run the two focused tests plus `php tests/recording-opus-smoke.php` and `php tests/cdr-delete-smoke.php`.

Expected: all focused suites pass.

- [x] **Step 5: Commit Task 3**

```bash
git add app/Services/Asterisk/ConfigGenerator.php scripts/install-server-ubuntu.sh scripts/update-helper.sh tests/announcement-smoke.php tests/ui-preferences-smoke.php
git commit -m "feat: transcode completed call recordings to Opus"
```

### Task 4: Version, verification, and release

**Files:**
- Modify: `resources/config/app.php`
- Include: approved design and implementation plan
- Test: all `tests/*smoke.php`

**Interfaces:**
- Consumes: completed Tasks 1–3.
- Produces: verified `v1.4.3` commit, annotated tag, pushed `main`, and GitHub Release.

- [x] **Step 1: Set version to `1.4.3`**

Update only the application version value.

- [x] **Step 2: Run complete verification**

Run full PHP lint, shell syntax for all deployment scripts, every PHP smoke suite including helper/CDR tests, `git diff --check`, and browser checks confirming legacy WAV and Opus playback/download UI behavior.

Expected: all checks pass with no warnings or failures attributable to the change.

- [x] **Step 3: Review the whole branch and fix Critical/Important findings with RED→GREEN tests**

Review codec parameters, command/path safety, atomic failure handling, CDR tenant isolation, backward compatibility, installer/updater behavior, and release integrity.

- [ ] **Step 4: Commit, tag, integrate, and publish**

Commit version/design/plan, create annotated `v1.4.3`, integrate into `main` after user choice, push `main` and tag, publish release notes with storage estimate and WAV fallback behavior, and verify clean repository plus matching local/remote/tag/release commits.
