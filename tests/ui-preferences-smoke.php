<?php

declare(strict_types=1);

// Run: php tests/ui-preferences-smoke.php
define('BASE_PATH', dirname(__DIR__));

$appLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/app.php');
$authLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/auth.php');
$css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
$appJs = file_get_contents(BASE_PATH . '/public/assets/js/app.js');
$i18nJs = file_get_contents(BASE_PATH . '/public/assets/js/i18n.js');
$routesView = file_get_contents(BASE_PATH . '/themes/default/views/routes/index.php');
$extensionsView = file_get_contents(BASE_PATH . '/themes/default/views/extensions/index.php');

$checks = [
    [str_contains($appLayout, 'id="themeToggle"') && str_contains($authLayout, 'id="themeToggle"'), 'light/dark controls render in app and auth layouts'],
    [str_contains($appLayout, 'id="languageSelect"') && str_contains($authLayout, 'id="languageSelect"'), 'English/Bangla controls render in app and auth layouts'],
    [str_contains($appLayout, 'assets/js/i18n.js') && str_contains($authLayout, 'assets/js/i18n.js'), 'translation runtime loads in both layouts'],
    [str_contains($css, 'select:not([multiple])') && str_contains($css, 'background-image:url'), 'all single-select controls use the shared dropdown arrow'],
    [str_contains($css, '@media (max-width: 560px)') && str_contains($css, '.repeat-row') && str_contains($css, '.modal-foot .btn'), 'shared mobile rules cover forms, repeat rows, and modals'],
    [str_contains($appJs, "localStorage.setItem('panel-theme'") && str_contains($i18nJs, "localStorage.setItem('panel-language'"), 'theme and language preferences persist'],
    [str_contains($i18nJs, "'Dashboard': 'ড্যাশবোর্ড'") && str_contains($i18nJs, "'Settings': 'সেটিংস'"), 'Bangla navigation translations are available'],
    [str_contains($css, '[data-theme="light"] .sidebar') && str_contains($css, '[data-theme="light"] .nav-link.is-active'), 'sidebar follows light mode'],
    [str_contains($routesView, 'route-modal outbound-route-modal') && str_contains($routesView, 'route-time-grid') && str_contains($routesView, 'Pattern examples'), 'routing editors use compact layouts'],
    [str_contains($extensionsView, 'Extension / name') && !str_contains($extensionsView, '<th>SIP password</th>') && str_contains($extensionsView, 'extensions-table'), 'extension table removes redundant columns and uses compact sizing'],
    [str_contains($appJs, 'enhanceFieldHelp(document)') && str_contains($appJs, "button.className = 'field-help-button'") && str_contains($appJs, "required.className = 'required-mark'"), 'field instructions become accessible info tooltips'],
    [str_contains($css, '.form-help-popover') && str_contains($css, '.field-label-row') && str_contains($css, '.required-mark'), 'instruction tooltip and required-field styles are available'],
    [str_contains($css, '.form-grid:has(> .field) > .check') && str_contains($css, '> :not(.field) > .check'), 'checkbox cards align with adjacent form controls'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}

echo "All UI preference smoke checks passed.\n";
