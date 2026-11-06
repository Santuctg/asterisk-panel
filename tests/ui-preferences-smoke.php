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
$phoneLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/phone.php');
$updateView = file_get_contents(BASE_PATH . '/themes/default/views/system/update.php');
$loginView = file_get_contents(BASE_PATH . '/themes/default/views/auth/login.php');
$routes = file_get_contents(BASE_PATH . '/resources/routes.php');
$updateService = file_get_contents(BASE_PATH . '/app/Services/UpdateService.php');
$updateHelper = file_get_contents(BASE_PATH . '/scripts/update-helper.sh');
$phoneView = file_get_contents(BASE_PATH . '/themes/default/views/web-phone/index.php');
$phoneJs = file_get_contents(BASE_PATH . '/public/assets/js/web-phone.js');
$listenView = file_get_contents(BASE_PATH . '/themes/default/views/live-listen/index.php');
$listenJs = file_get_contents(BASE_PATH . '/public/assets/js/live-listen.js');

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
    [str_contains($appJs, 'enhanceFieldHelp(document)') && str_contains($appJs, 'enhanceCheckHelp(document)') && str_contains($appJs, "button.className = 'field-help-button'") && str_contains($appJs, "required.className = 'required-mark'"), 'field and checkbox instructions become accessible info tooltips'],
    [str_contains($css, '.form-help-popover') && str_contains($css, '.field-label-row') && str_contains($css, '.required-mark'), 'instruction tooltip and required-field styles are available'],
    [str_contains($css, '.form-grid:has(> .field) > .check') && str_contains($css, '> :not(.field) > .check'), 'checkbox cards align with adjacent form controls'],
    [str_contains($phoneLayout, 'phone-content') && !str_contains($phoneLayout, 'sidebar-nav'), 'web phone uses a standalone shell'],
    [str_contains($loginView, 'Open Web Phone') && str_contains($loginView, 'href="/web-phone"')
        && str_contains($routes, "get('/web-phone', [WebPhoneController::class, 'index'])"), 'main login links to the public Web Phone login'],
    [str_contains($phoneView, 'web-phone-mute') && str_contains($phoneView, 'web-phone-hold')
        && str_contains($phoneView, 'web-phone-transfer') && str_contains($phoneView, 'web-phone-dtmf')
        && str_contains($phoneView, 'data-phone-view="contacts"'), 'web phone exposes Wave-style contacts and in-call controls'],
    [str_contains($phoneJs, 'session.sendDTMF') && str_contains($phoneJs, 'session.refer')
        && str_contains($phoneJs, 'session.hold') && str_contains($phoneJs, 'localStorage.setItem(historyStorageKey()'), 'web phone implements DTMF, transfer, hold, and persistent history'],
    [str_contains($phoneView, 'webphone-app is-locked') && str_contains($css, '.webphone-app.is-locked .webphone-rail')
        && str_contains($phoneJs, "classList.remove('is-locked')"), 'web phone hides workspace navigation until SIP login succeeds'],
    [!str_contains($listenView, 'listen-supervisor') && str_contains($listenView, 'Listen in browser')
        && str_contains($listenJs, 'dataset.extension') && !str_contains($listenJs, 'supervisor:'), 'live listen derives a restricted browser identity without a supervisor selector'],
    [str_contains($updateView, '/system/update/progress') && str_contains($updateView, 'RECONNECTING') && str_contains($updateView, "setTimeout(poll, 1000)"), 'update page tracks background progress and reconnects automatically'],
    [str_contains($updateService, 'Progress was recovered after the server restart')
        && str_contains($updateHelper, 'systemd-run --quiet --collect')
        && !str_contains($updateHelper, "    systemctl restart php8.3-fpm\n    nginx -t"), 'update completion survives the PHP-FPM restart'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}

echo "All UI preference smoke checks passed.\n";
