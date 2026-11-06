<?php

declare(strict_types=1);

// Run: php tests/ui-preferences-smoke.php
define('BASE_PATH', dirname(__DIR__));

$appLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/app.php');
$authLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/auth.php');
$css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
$appJs = file_get_contents(BASE_PATH . '/public/assets/js/app.js');
$i18nJs = file_get_contents(BASE_PATH . '/public/assets/js/i18n.js');

$checks = [
    [str_contains($appLayout, 'id="themeToggle"') && str_contains($authLayout, 'id="themeToggle"'), 'light/dark controls render in app and auth layouts'],
    [str_contains($appLayout, 'id="languageSelect"') && str_contains($authLayout, 'id="languageSelect"'), 'English/Bangla controls render in app and auth layouts'],
    [str_contains($appLayout, 'assets/js/i18n.js') && str_contains($authLayout, 'assets/js/i18n.js'), 'translation runtime loads in both layouts'],
    [str_contains($css, 'select:not([multiple])') && str_contains($css, 'background-image:url'), 'all single-select controls use the shared dropdown arrow'],
    [str_contains($css, '@media (max-width: 560px)') && str_contains($css, '.repeat-row') && str_contains($css, '.modal-foot .btn'), 'shared mobile rules cover forms, repeat rows, and modals'],
    [str_contains($appJs, "localStorage.setItem('panel-theme'") && str_contains($i18nJs, "localStorage.setItem('panel-language'"), 'theme and language preferences persist'],
    [str_contains($i18nJs, "'Dashboard': 'ড্যাশবোর্ড'") && str_contains($i18nJs, "'Settings': 'সেটিংস'"), 'Bangla navigation translations are available'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}

echo "All UI preference smoke checks passed.\n";
