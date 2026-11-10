<?php

declare(strict_types=1);

// Run: php tests/release-version-smoke.php
define('BASE_PATH', dirname(__DIR__));

$config = require BASE_PATH . '/resources/config/app.php';
$actual = (string) ($config['version'] ?? '');
$expected = '1.4.7';

if ($actual !== $expected) {
    throw new RuntimeException("FAIL: application version is {$actual}; expected {$expected}");
}

echo "PASS: application version is {$expected}." . PHP_EOL;
