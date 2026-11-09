<?php

declare(strict_types=1);

// Run: php tests/material-player-smoke.php
define('BASE_PATH', dirname(__DIR__));

$playerJsPath = BASE_PATH . '/public/assets/js/audio-player.js';
$playerJs = @file_get_contents($playerJsPath);
$css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
$appLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/app.php');
$cdrView = file_get_contents(BASE_PATH . '/themes/default/views/cdr/index.php');

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$check(is_string($playerJs) && $playerJs !== '', 'public/assets/js/audio-player.js exists');
$check(str_contains($playerJs, 'audio[controls]'), 'player selects enhancement targets with audio[controls]');
$check(str_contains($playerJs, "removeAttribute('controls')"), 'player removes the native controls attribute');
$check(str_contains($playerJs, "'hidden'") && str_contains($playerJs, 'MutationObserver'),
    'player mirrors the element hidden state');
foreach (['data-ap-toggle', 'data-ap-seek', 'data-ap-time', 'data-ap-mute'] as $contractName) {
    $check(str_contains($playerJs, $contractName), "player renders {$contractName}");
}
$check(str_contains($playerJs, 'data-ap-status') && str_contains($playerJs, "addEventListener('error'")
    && str_contains($playerJs, 'aria-valuetext'),
    'player reports playback errors and exposes a meaningful seek value');
$check(str_contains($playerJs, 'Number.isFinite')
    && (str_contains($playerJs, 'padStart') || str_contains($playerJs, 'pad2')),
    'player guards unknown durations and formats time as m:ss');
$check(str_contains($css, '.audio-player {') && str_contains($css, '.audio-player[hidden]')
    && str_contains($css, '--ap-progress'),
    'app.css styles the pill wrapper, hidden state, and progress fill');
$materialCss = strstr($css, '/* ---- Material audio player ---- */') ?: '';
$check(str_contains($playerJs, 'audio-player--moh') && str_contains($playerJs, 'audio-player--prompt')
    && str_contains($css, '.audio-player--moh') && str_contains($css, '.audio-player--prompt'),
    'visible wrappers preserve MoH and prompt player spacing');
$check(!str_contains($materialCss, '#fff') && !str_contains($materialCss, 'rgba('),
    'material player styles use theme tokens instead of hard-coded colors');
$check(str_contains($appLayout, 'assets/js/audio-player.js'), 'app layout loads the audio player script');
$check((bool) preg_match('/id="recordingPlaybackAudio"[^>]*\scontrols|controls[^>]*id="recordingPlaybackAudio"/', $cdrView),
    'recording modal audio still declares controls for auto-enhancement');

$listenView = file_get_contents(BASE_PATH . '/themes/default/views/live-listen/index.php');
$listenJs = file_get_contents(BASE_PATH . '/public/assets/js/live-listen.js');
$retryJs = file_get_contents(BASE_PATH . '/public/assets/js/playback-retry.js');
$check(!str_contains($listenView, 'id="listen-audio"') || !preg_match('/id="listen-audio"[^>]*\scontrols/', $listenView),
    'Active Calls listen audio carries no native controls');
$check(!str_contains($listenJs, 'Press Play below') && str_contains($retryJs, 'pointerdown')
    && str_contains($listenJs, 'play: () => audio.play()'),
    'blocked autoplay retries from the next click instead of pointing at a play button');
$check(str_contains($listenJs, 'createPlaybackRetry') && str_contains($listenJs, '.cancel()'),
    'Active Calls uses one cancellable retry controller across media polling and cleanup');
$webPhoneJs = file_get_contents(BASE_PATH . '/public/assets/js/web-phone.js');
$check(str_contains($webPhoneJs, 'item.displayTime') && !str_contains($webPhoneJs, 'time.textContent = formatTime(new Date(item.time))'),
    'Web Phone uses the server-provided tenant-local wall clock instead of browser timezone conversion');

echo 'All material player checks passed.' . PHP_EOL;
