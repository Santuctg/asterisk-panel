#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Record one converter failure in the system log so silent MixMonitor
 * post-call failures stay visible to operators.
 *
 * @param null|callable(string):void $logger
 */
function transcode_log(string $message, ?callable $logger = null): void
{
    if ($logger !== null) {
        $logger($message);
        return;
    }
    if (!function_exists('syslog') || !function_exists('openlog')) {
        return;
    }
    @openlog('asterisk-panel', LOG_PID | LOG_NDELAY, LOG_USER);
    @syslog(LOG_WARNING, 'transcode-recording: ' . $message);
    @closelog();
}

/**
 * Convert one completed MixMonitor WAV to Ogg Opus.
 *
 * @param array<int,string> $argv
 * @param null|callable(array<int,string>):int $runner
 * @param null|callable(string):void $logger
 */
function transcode_recording_main(array $argv, ?callable $runner = null, ?callable $logger = null): int
{
    $log = static function (string $message) use ($logger): void {
        transcode_log($message, $logger);
    };

    $basename = $argv[1] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,240}$/D', $basename)) {
        $log('rejected unsafe recording basename');
        return 2;
    }

    $configuredDir = getenv('ASTERISK_RECORDING_DIR');
    $recordingDir = $configuredDir !== false && $configuredDir !== ''
        ? $configuredDir
        : '/var/spool/asterisk/monitor/asterisk-panel';
    $recordingDir = realpath($recordingDir);
    if ($recordingDir === false || !is_dir($recordingDir)) {
        $log('recording directory is unavailable for ' . $basename);
        return 3;
    }

    $source = $recordingDir . DIRECTORY_SEPARATOR . $basename . '.wav';
    $target = $recordingDir . DIRECTORY_SEPARATOR . $basename . '.ogg';
    if (!is_file($source) || filesize($source) === 0) {
        $log('missing or empty WAV input for ' . $basename);
        return 4;
    }

    try {
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
    } catch (Throwable) {
        $log('could not allocate a temporary output for ' . $basename);
        return 5;
    }

    $command = [
        '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
        '-i', $source, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libopus',
        '-b:a', '48k', '-vbr', 'on', '-application', 'voip', '-f', 'ogg', $temporary,
    ];

    $runner ??= static function (array $command): int {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        return is_resource($process) ? proc_close($process) : 127;
    };

    try {
        $status = (int) $runner($command);
    } catch (Throwable) {
        $status = 1;
    }

    clearstatcache(true, $temporary);
    if ($status !== 0 || !is_file($temporary) || filesize($temporary) === 0) {
        @unlink($temporary);
        $log('FFmpeg Opus conversion failed (exit ' . $status . ') for ' . $basename . '; WAV retained');
        return 6;
    }
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        $log('could not publish Ogg output for ' . $basename . '; WAV retained');
        return 7;
    }
    if (!@unlink($source)) {
        $log('could not remove converted WAV for ' . $basename);
        return 8;
    }

    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(transcode_recording_main($argv));
}
