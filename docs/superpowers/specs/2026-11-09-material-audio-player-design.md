# Design — Material-style audio player, hands-off live listen, web-phone time

**Date:** 2026-11-09
**Status:** Approved by the human partner ("koro" after the approach was laid out)

## Intent

Recording playback in the panel should look like a Material pill player instead of
the browser's native controls, and it should look the same wherever audio plays.
Browser Listen on the Active Calls page should be hands-free: no player UI at all.
The web phone's call history must show the same tenant-local times as the rest of
the panel (it currently shows the stored UTC wall-clock value, the same defect
just fixed on the Dashboard).

## Goals

- One reusable player component, styled from the existing design tokens, used by
  every native `<audio controls>` element in the panel (Call recording modal,
  MoH, Announcement upload/prompt/record preview, IVR greeting).
- Active Calls browser listen keeps playing audio with **no visible player**.
- Web-phone call history renders tenant-local timestamps like Call History and
  Dashboard.

## Non-goals / anti-goals

- No audio library, no bundler, no third-party dependency.
- No change to recording resolution, CDR flows, or the Opus conversion pipeline.
- No bulk conversion of existing recordings.
- Not a redesign of the modal, MoH, IVR or announcement pages — only the player.

## Constraints

- Dark and light themes both exist (`[data-theme]`); the player must use
  existing semantic design tokens only (surface, brand, border, text, error,
  shadow, and neutral tokens), with no hard-coded colors.
- Existing view scripts toggle `audio.hidden` and call `.play()`/`.pause()` on the
  raw `<audio>` element; the component must not break them (it wraps the element
  and mirrors the `hidden` attribute).
- The panel ships plain PHP views plus static assets; tests are PHP smoke suites
  that assert contracts, with a browser check for the visuals.

## Approaches considered

1. **Reusable custom component wrapping the native `<audio>`** — keep the element
   as the media engine, remove its `controls`, build pill UI next to it, auto-init
   on `audio[controls]`. *Chosen:* zero dependencies, inherits the brand look,
   automatically covers every current and future player.
2. **Ship a player library (Plyr / MediaElement)** — faster to first pixel but adds
   a vendored dependency, foreign look, and theming work to match the panel.
   Rejected: heavier than the problem.
3. **CSS-restyle of the native controls** — `::-webkit-media-controls-*` cannot
   rebuild the layout and differs per browser. Rejected: cannot deliver a
   Material look reliably.

## The design

### Component

- `public/assets/js/audio-player.js` — auto-initializes every `audio[controls]`
  on load. For each element it:
  - inserts a wrapper `<div class="audio-player">` around it, moves the element
    inside, removes the `controls` attribute, keeps the element as the engine;
  - renders: round play/pause button (SVG), `current / total` time, seek bar
    (`<input type="range">` with a filled track), volume icon button that toggles
    mute, and the wrapper mirrors `audio[hidden]` through a `MutationObserver` so
    existing scripts that show/hide the element keep working;
  - listens to `loadedmetadata`, `durationchange`, `timeupdate`, `play`, `pause`,
    `ended`, `emptied` — showing `--:--` until metadata is known;
  - keyboard: the range input provides seek, buttons expose `aria-label`s.
- `public/assets/css/app.css` — `.audio-player` rules: 999px pill, surface
  background, 36px round button, mono time labels, brand-colored progress fill
  and thumb, compact single-row layout, responsive down to the modal width.
- The layout includes the script once (next to `app.js`).

### Live listen (Active Calls)

- `themes/default/views/live-listen/index.php` — `#listen-audio` loses `controls`,
  so it is neither shown nor picked up by the auto-initializer.
- `public/assets/js/live-listen.js` — if `audio.play()` is rejected (autoplay
  policy), the status line asks for one click and the next pointer activation
  retries `play()` once, then clears the message.

### Web-phone history time

- `app/Controllers/WebPhoneController.php` — build `time` with
  `format_cdr_date($calldate, DATE_ATOM)` so `web-phone.js` formats the
  tenant-local instant instead of the stored UTC wall clock.

## Verification

- New `tests/material-player-smoke.php`: component contract (CSS class, JS
  auto-init + hidden mirroring, layout includes the script), listen audio carries
  no `controls`, and the other players still declare `controls` so they are
  enhanced.
- New `tests/web-phone-time-smoke.php` (RED first): history JSON time is the
  tenant-local instant, not the raw stored value.
- Full smoke suite + `git diff --check` + a browser check of the recording modal
  and the Active Calls page.

## What's left to the builder

Order of work, exact markup for the player rows, SVG choice for icons, and the
exact retry message wording on the live listen page.
