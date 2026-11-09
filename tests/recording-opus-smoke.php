<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$helper = $base . '/scripts/transcode-recording.php';
if (!is_file($helper)) {
    throw new RuntimeException('FAIL: Opus recording converter helper is missing');
}
require $helper;

$dir = $base . '/storage/cache/recording-opus-smoke';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('FAIL: could not create recording fixture directory');
}
$dir = realpath($dir) ?: $dir;
foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
putenv('ASTERISK_RECORDING_DIR=' . $dir);

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$basename = 'tenant-1-201-202-20261109-120000-123.45';
$source = $dir . DIRECTORY_SEPARATOR . $basename . '.wav';
$target = $dir . DIRECTORY_SEPARATOR . $basename . '.ogg';
file_put_contents($source, 'RIFF-call-audio');
$captured = [];
$successRunner = static function (array $command) use (&$captured): int {
    $captured = $command;
    file_put_contents((string) end($command), 'OggS-opus-audio');
    return 0;
};
$status = transcode_recording_main(['transcode-recording', $basename], $successRunner);
$check($status === 0 && is_file($target) && filesize($target) > 0 && !is_file($source),
    'successful conversion atomically publishes Ogg and removes temporary WAV');
$check($captured === [
    '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
    '-i', $source, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libopus',
    '-b:a', '48k', '-vbr', 'on', '-application', 'voip', '-f', 'ogg', $captured[count($captured) - 1] ?? null,
], 'converter invokes FFmpeg with exact mono 16 kHz 48 kbps Opus settings');
$check(glob($target . '.tmp-*') === [], 'successful conversion leaves no temporary Ogg file');

$failureBase = 'tenant-1-conversion-failure';
$failureWav = $dir . '/' . $failureBase . '.wav';
file_put_contents($failureWav, 'RIFF-call-audio');
$failureStatus = transcode_recording_main(['transcode-recording', $failureBase], static function (array $command): int {
    file_put_contents((string) end($command), 'partial-output');
    return 1;
});
$check($failureStatus !== 0 && is_file($failureWav) && !is_file($dir . '/' . $failureBase . '.ogg')
    && glob($dir . '/' . $failureBase . '.ogg.tmp-*') === [],
    'failed conversion retains WAV and removes partial Ogg output');

$emptyBase = 'tenant-1-empty-output';
$emptyWav = $dir . '/' . $emptyBase . '.wav';
file_put_contents($emptyWav, 'RIFF-call-audio');
$emptyStatus = transcode_recording_main(['transcode-recording', $emptyBase], static function (array $command): int {
    touch((string) end($command));
    return 0;
});
$check($emptyStatus !== 0 && is_file($emptyWav) && !is_file($dir . '/' . $emptyBase . '.ogg')
    && glob($dir . '/' . $emptyBase . '.ogg.tmp-*') === [],
    'empty encoder output retains WAV and is never published');

$runnerCalled = false;
$unsafeStatus = transcode_recording_main(['transcode-recording', '../outside'], static function () use (&$runnerCalled): int {
    $runnerCalled = true;
    return 0;
});
$check($unsafeStatus !== 0 && !$runnerCalled, 'unsafe recording basenames are rejected before FFmpeg runs');

$missingStatus = transcode_recording_main(['transcode-recording', 'missing-recording'], static fn (): int => 0);
$check($missingStatus !== 0, 'missing or empty WAV input is rejected');

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

echo 'All Opus recording converter checks passed.' . PHP_EOL;
