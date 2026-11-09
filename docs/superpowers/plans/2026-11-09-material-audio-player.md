# Material Audio Player Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the panel's native `<audio controls>` players with one Material-style pill player, remove the player UI from Active Calls browser listen, and make the Web Phone history render tenant-local times.

**Architecture:** Keep each `<audio>` element as the media engine and wrap it with a JS-built `.audio-player` row (play/pause, seek, time, mute) auto-initialized on `audio[controls]`; the wrapper mirrors the element's `hidden` attribute so existing view scripts that show/hide players keep working. Live listen drops the `controls` attribute, so it is never enhanced and plays silently, with a one-click retry if autoplay is rejected. Times move to the shared `format_cdr_date()` helper.

**Tech Stack:** Vanilla ES module (no dependencies), existing CSS custom properties in `public/assets/css/app.css`, PHP smoke suites as tests.

**Spec:** `docs/superpowers/specs/2026-11-09-material-audio-player-design.md`

## Global Constraints

- No third-party libraries, no bundler: `public/assets/js/audio-player.js` is plain JS served as-is.
- Styling uses only existing tokens: `--surface`, `--surface-alt`, `--border`, `--brand-500`, `--brand-600`, `--text-muted`, `--mono`, `--radius-*`; no hard-coded hex colors.
- Existing view scripts must keep working unmodified: they set `audio.hidden` and call `play()/pause()` on the raw element (MoH, Announcement, IVR, record preview).
- The listen audio element must not be auto-enhanced and must not display any controls.
- Web Phone times come from `format_cdr_date($calldate, DATE_ATOM)`; no raw `date()`/`strtotime()` formatting of CDR timestamps remains in `WebPhoneController`.
- Test count grows from 22 suites; every task ends green before its commit.

## Review Focus

- **Hidden-state mirroring:** MoH/IVR/announcement scripts toggle `audio.hidden`; if the wrapper does not follow it, a hidden player stays visible (or a shown one stays hidden). Pinned by the `MutationObserver`/`hidden` assertions in `tests/material-player-smoke.php`.
- **Native controls leak:** if the enhanced element keeps `controls`, two players render at once. Pinned by the "removes the controls attribute" assertion in `tests/material-player-smoke.php`.
- **Unknown duration:** players use `preload="none"`, so `duration` is `NaN` until `loadedmetadata`; a raw `toFixed` would print `NaN:NaN`. Pinned by the finite-duration guard assertion in `tests/material-player-smoke.php`.
- **Autoplay rejection on listen:** removing the play button means a blocked `play()` has no recovery. Pinned by the retry-on-pointer assertion in `tests/material-player-smoke.php` plus the browser check in Task 4.
- **Wrong-time regression:** the Web Phone must show the same instant as Call History for the same row. Pinned by the guard + equality assertions added to `tests/live-listen-smoke.php` (they fail today).

---

### Task 1: Web Phone history uses tenant-local time

**Files:**
- Modify: `app/Controllers/WebPhoneController.php:220-230` (the `history` map inside `phoneConfig()`)
- Test: `tests/live-listen-smoke.php:367` (append after the existing history check)

**Interfaces:**
- Consumes: `format_cdr_date(?string $dt, string $format): string` from `app/Support/helpers.php`.
- Produces: `history[].time` as an ISO-8601 instant in the active tenant timezone (consumed by `public/assets/js/web-phone.js` `formatTime(new Date(item.time))`).

- [ ] **Step 1: Write the failing assertions**

Append to `tests/live-listen-smoke.php` immediately after the check at line 367:

```php
$historyTime = (string) ($phoneConfig['history'][0]['time'] ?? '');
$expectedHistoryTime = format_cdr_date('2026-11-06 10:00:00', DATE_ATOM);
$check($expectedHistoryTime !== date(DATE_ATOM, strtotime('2026-11-06 10:00:00'))
    && $historyTime === $expectedHistoryTime,
    'Web Phone history renders the tenant-local call time');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/live-listen-smoke.php`
Expected: FAIL with `Web Phone history renders the tenant-local call time` — the guard side is true, the equality side is false because `time` is still built with `date(DATE_ATOM, ...)`.

- [ ] **Step 3: Build `time` with `format_cdr_date()`**

In the `history` map replace the `strtotime`/`date(DATE_ATOM, ...)` pair with a single entry:

```php
'time' => format_cdr_date((string) ($call['calldate'] ?? ''), DATE_ATOM),
```

The `$timestamp` local and its ternary are removed with it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/live-listen-smoke.php`
Expected: PASS (whole file, all existing checks included).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/WebPhoneController.php tests/live-listen-smoke.php
git commit -m "fix: show web phone history in tenant timezone"
```

---

### Task 2: Material-style player component

**Files:**
- Create: `public/assets/js/audio-player.js`
- Modify: `public/assets/css/app.css` (append a `/* Material audio player */` block; keep `.recording-modal audio` rules working with the wrapper)
- Modify: `themes/default/views/layouts/app.php:232` (script tag right after `app.js`)
- Test: `tests/material-player-smoke.php`

**Interfaces:**
- Consumes: `audio[controls]` elements already present in `themes/default/views/cdr/index.php:168`, `themes/default/views/moh/index.php:68,84,104,117`, `themes/default/views/ivr/form.php:112`.
- Produces: markup contract `.audio-player` wrapper containing `[data-ap-toggle]` (play/pause button), `[data-ap-seek]` (range input), `[data-ap-time]` (time label), `[data-ap-mute]` (volume button), and CSS class `.audio-player` — Task 3 and the browser check rely on these names.

- [ ] **Step 1: Write the failing contract test**

Create `tests/material-player-smoke.php` following the shape of `tests/ui-preferences-smoke.php` (read files with `file_get_contents(BASE_PATH . ...)`, `$check = function (bool, string)` throwing `RuntimeException('FAIL: ...')`), asserting:

1. `public/assets/js/audio-player.js` exists.
2. It selects the enhancement targets with `audio[controls]`.
3. It removes the native controls (`removeAttribute('controls')`).
4. It mirrors the element's hidden state (references both `'hidden'` and `MutationObserver`).
5. It renders the four contract names: `data-ap-toggle`, `data-ap-seek`, `data-ap-time`, `data-ap-mute`.
6. It guards unknown durations (references `Number.isFinite`) and formats time as `m:ss` (a `padStart` or equivalent two-digit helper).
7. `public/assets/css/app.css` contains `.audio-player {`, `.audio-player[hidden]`, and a progress variable (`--ap-progress`) used by the seek track fill.
8. `themes/default/views/layouts/app.php` includes `assets/js/audio-player.js`.
9. `themes/default/views/cdr/index.php` still declares `controls` on `#recordingPlaybackAudio` (it must be auto-enhanced, not hand-wired).

End the file with `echo 'All material player checks passed.' . PHP_EOL;`

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/material-player-smoke.php`
Expected: FAIL on assertion 1 — `FAIL: public/assets/js/audio-player.js exists` (or the first missing contract).

- [ ] **Step 3: Implement the component**

`public/assets/js/audio-player.js` (ES module, no imports) exposes `initAudioPlayers(root = document)` and runs it on `DOMContentLoaded` plus a `MutationObserver` on `root` that enhances newly inserted `audio[controls]`. Per element: wrap it in `<div class="audio-player">`, move it inside, `removeAttribute('controls')`, style it as the hidden engine, and render the four contract elements. Play/pause button toggles `audio.play()`/`audio.pause()`; seek `input` sets `audio.currentTime`; mute button toggles `audio.muted` and its `aria-pressed`; time label shows `current / duration` or `0:00 / --:--` until `Number.isFinite(audio.duration)`. A `MutationObserver` on the audio element copies its `hidden` attribute onto the wrapper.

CSS block in `app.css`: pill wrapper (`border-radius:999px`), 36px round brand play button, mono time label, seek track filled to `var(--ap-progress, 0%)` via a `linear-gradient`, `.audio-player[hidden] { display:none; }`, and `audio` inside the wrapper visually collapsed but present (never `display:none`, so playback is uninterrupted).

Layout: add `<script src="<?= e(asset('assets/js/audio-player.js')) ?>"></script>` on the line after the `app.js` script tag.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/material-player-smoke.php`
Expected: PASS `All material player checks passed.`

- [ ] **Step 5: Commit**

```bash
git add public/assets/js/audio-player.js public/assets/css/app.css themes/default/views/layouts/app.php tests/material-player-smoke.php
git commit -m "feat: add Material-style audio player"
```

---

### Task 3: Active Calls listen without a player

**Files:**
- Modify: `themes/default/views/live-listen/index.php:13`
- Modify: `public/assets/js/live-listen.js:45`
- Test: `tests/material-player-smoke.php` (append a second section)

**Interfaces:**
- Consumes: `.audio-player` auto-initializer from Task 2 (removing `controls` is what keeps listen out of it).
- Produces: none for later tasks.

- [ ] **Step 1: Append the failing assertions**

Add to `tests/material-player-smoke.php` before the final echo:

```php
$listenView = file_get_contents(BASE_PATH . '/themes/default/views/live-listen/index.php');
$listenJs = file_get_contents(BASE_PATH . '/public/assets/js/live-listen.js');
$check(!str_contains($listenView, 'id="listen-audio"') || !preg_match('/id="listen-audio"[^>]*\scontrols/', $listenView),
    'Active Calls listen audio carries no native controls');
$check(!str_contains($listenJs, 'Press Play below') && str_contains($listenJs, 'pointerdown')
    && str_contains($listenJs, 'audio.play()'),
    'blocked autoplay retries from the next click instead of pointing at a play button');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/material-player-smoke.php`
Expected: FAIL with `Active Calls listen audio carries no native controls` (the view still has `controls`; the retry assertion fails after that is fixed).

- [ ] **Step 3: Remove the controls and add the click retry**

In the view, delete ` controls` from `<audio id="listen-audio" class="mb-4" autoplay playsinline controls hidden>`.
In `live-listen.js` replace the rejected-`play()` handler: show `Listening connected. Click anywhere to hear audio.`, register a one-time `window` `pointerdown` listener that calls `audio.play()` and clears the message (removing the listener once playback starts).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/material-player-smoke.php`
Expected: PASS `All material player checks passed.`

- [ ] **Step 5: Commit**

```bash
git add themes/default/views/live-listen/index.php public/assets/js/live-listen.js tests/material-player-smoke.php
git commit -m "feat: listen to active calls without a player UI"
```

---

### Task 4: Verification and browser check

**Files:**
- Test: full suite, no production changes.

- [ ] **Step 1: Run complete verification**

Run: PHP lint over every `*.php`, `bash -n` over every `scripts/*.sh`, every `tests/*smoke.php`, `git diff --check`.
Expected: all green; suite count is 23 (22 existing + `material-player-smoke`).

- [ ] **Step 2: Browser check**

Start `php -S 127.0.0.1:8767 -t public`, open the CDR page, play a recording: the modal shows the pill player (round button, `0:03 / 0:06`, seek fill) with no native controls, and `Download WAV`/`Download OPUS` still behaves; open Active Calls and confirm the listen status area shows no player element; console has no errors. Repeat once with `data-theme="light"` if reachable.

- [ ] **Step 3: Commit any verification artifacts**

Ledger/checklist updates only; no product code may change in this task.

```bash
git add -A docs/superpowers
git commit -m "docs: record material player verification"
```
