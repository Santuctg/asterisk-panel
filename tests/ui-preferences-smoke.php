<?php

declare(strict_types=1);

// Run: php tests/ui-preferences-smoke.php
define('BASE_PATH', dirname(__DIR__));

$appLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/app.php');
$authLayout = file_get_contents(BASE_PATH . '/themes/default/layouts/auth.php');
$css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
$appJs = file_get_contents(BASE_PATH . '/public/assets/js/app.js');
$fields = file_get_contents(BASE_PATH . '/themes/default/views/partials/fields.php');
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
    [str_contains($phoneLayout, "localStorage.getItem('webphone-theme')") && !str_contains($phoneLayout, 'assets/js/app.js')
        && str_contains($phoneJs, "localStorage.setItem('webphone-theme'") && !str_contains($phoneLayout, "localStorage.getItem('panel-theme')"), 'web phone color mode is independent from the main panel'],
    [str_contains($i18nJs, "'Dashboard': 'ড্যাশবোর্ড'") && str_contains($i18nJs, "'Settings': 'সেটিংস'"), 'Bangla navigation translations are available'],
    [str_contains($css, '[data-theme="light"] .sidebar') && str_contains($css, '[data-theme="light"] .nav-link.is-active'), 'sidebar follows light mode'],
    [str_contains($routesView, 'route-modal outbound-route-modal') && str_contains($routesView, 'route-time-grid') && str_contains($routesView, 'Pattern examples'), 'routing editors use compact layouts'],
    [str_contains($extensionsView, 'Extension / name') && !str_contains($extensionsView, '<th>SIP password</th>') && str_contains($extensionsView, 'extensions-table'), 'extension table removes redundant columns and uses compact sizing'],
    [str_contains($appJs, 'enhanceFieldHelp(document)') && str_contains($appJs, 'enhanceCheckHelp(document)') && str_contains($appJs, "button.className = 'field-help-button'") && str_contains($appJs, "required.className = 'required-mark'"), 'field and checkbox instructions become accessible info tooltips'],
    [str_contains($fields, 'type="hidden" name="') && str_contains($fields, 'value="0"'), 'checkboxes submit an explicit off value when unchecked'],
    [str_contains($css, '.form-help-popover') && str_contains($css, '.field-label-row') && str_contains($css, '.required-mark'), 'instruction tooltip and required-field styles are available'],
    [str_contains($css, '.form-grid:has(> .field) > .check') && str_contains($css, '> :not(.field) > .check'), 'checkbox cards align with adjacent form controls'],
    [str_contains($phoneLayout, 'phone-content') && !str_contains($phoneLayout, 'sidebar-nav'), 'web phone uses a standalone shell'],
    [str_contains($loginView, 'Open Web Phone') && str_contains($loginView, 'href="/web-phone"')
        && str_contains($routes, "get('/web-phone', [WebPhoneController::class, 'index'])"), 'main login links to the public Web Phone login'],
    [str_contains($phoneView, 'web-phone-mute') && str_contains($phoneView, 'web-phone-hold')
        && str_contains($phoneView, 'web-phone-transfer') && str_contains($phoneView, 'web-phone-dtmf')
        && str_contains($phoneView, 'data-phone-view="contacts"'), 'web phone exposes Wave-style contacts and in-call controls'],
    [str_contains($phoneView, 'id="wp-icon-phone"') && str_contains($phoneView, 'id="wp-icon-users"')
        && str_contains($phoneView, 'id="wp-icon-phone-off"') && str_contains($phoneView, 'href="#wp-icon-settings"')
        && str_contains($phoneJs, 'function phoneIcon(name)') && str_contains($phoneJs, 'setPhoneIcon(passwordToggle')
        && str_contains($css, '.webphone-icon {'), 'web phone replaces mixed text glyphs with a consistent reusable SVG icon system'],
    [str_contains($phoneJs, 'session.sendDTMF') && str_contains($phoneJs, 'session.refer')
        && str_contains($phoneJs, 'session.hold') && str_contains($phoneJs, 'localStorage.setItem(historyStorageKey()'), 'web phone implements DTMF, transfer, hold, and persistent history'],
    [str_contains($phoneJs, 'JsSIP emits newRTCSession synchronously')
        && !str_contains($phoneJs, 'setupSession(ua.call(')
        && str_contains($phoneJs, 'config.history'), 'outgoing WebRTC calls are initialized once and merge PBX history'],
    [str_contains($phoneJs, 'await startPhone(config, password)')
        && str_contains($phoneJs, 'SIP registration timed out')
        && str_contains($phoneJs, 'session !== current'), 'web phone waits for SIP registration and ignores stale session events'],
    [!str_contains($phoneView, 'data-phone-view="dialpad"') && str_contains($phoneView, 'web-phone-backspace')
        && str_contains($phoneView, 'webphone-view-menu') && str_contains($phoneView, 'webphone-list-workspace')
        && !str_contains($phoneJs, "switchView('dialpad')"), 'calls and contacts share an integrated compact dialer without a separate dialpad page'],
    [str_contains($phoneView, 'data-phone-section="recent"') && str_contains($phoneView, 'data-phone-section="voicemail"')
        && str_contains($phoneView, 'data-phone-section="all"') && str_contains($phoneView, 'data-phone-section="favorites"')
        && str_contains($phoneView, 'data-phone-section="tenant"') && str_contains($phoneView, 'web-phone-contact-index'), 'calls expose Recent Calls and Voicemail while contacts expose all, favorites, tenant list, and alphabet navigation'],
    [str_contains($phoneJs, 'config.voicemailEnabled === true')
        && str_contains($phoneJs, 'Voicemail has not been enabled.') && str_contains($phoneJs, "settings.href = '/voicemail'")
        && str_contains($phoneJs, "settings.target = '_blank'") && str_contains($css, '.webphone-voicemail-illustration'), 'disabled voicemail renders a centered settings state without bypassing panel authentication'],
    [str_contains($phoneJs, 'favoriteStorageKey()') && str_contains($phoneJs, 'function contactInitials(contact)')
        && str_contains($phoneJs, "'ABCDEFGHIJKLMNOPQRSTUVWXYZ#'") && str_contains($phoneJs, 'web-phone-contact-group-')
        && str_contains($css, '.webphone-favorite-action.is-active'), 'contact favorites persist per phone account and contacts render in alphabetic groups'],
    [str_contains($phoneJs, 'function callHistoryRow(item)') && str_contains($phoneJs, "outcome.className = 'webphone-call-outcome'")
        && str_contains($phoneJs, "time.dateTime = String(item.time") && str_contains($css, '.webphone-call-row { grid-template-columns:'), 'recent calls use separate identity, outcome or duration, timestamp, and call-action columns'],
    [str_contains($css, 'background:linear-gradient(180deg,#111a31,#0b1224)')
        && str_contains($css, '.webphone-side .webphone-keypad button { width:38px; height:38px')
        && str_contains($css, '--phone-accent:#5b8cff')
        && str_contains($css, '.webphone-list-workspace')
        && str_contains($css, '.webphone-side .webphone-dialer'),
        'web phone uses the polished responsive Wave-style visual system'],
    [str_contains($phoneJs, 'ua.call(`sip:${destination}@${realm}`')
        && str_contains($phoneView, 'name="extension"') && !str_contains($phoneView, 'name="tenant_domain"'), 'web phone calls from URL-scoped extension login without a tenant field'],
    [str_contains($phoneView, 'webphone-app is-locked') && str_contains($css, '.webphone-app.is-locked .webphone-rail')
        && str_contains($phoneJs, "classList.remove('is-locked')"), 'web phone hides workspace navigation until SIP login succeeds'],
    [str_contains($routes, "post('/web-phone/session'") && str_contains($routes, "post('/web-phone/disconnect'")
        && str_contains($phoneJs, "fetch('/web-phone/session'") && str_contains($phoneJs, 'resumePhoneSession()'), 'web phone resumes its authenticated session after reload and supports explicit sign-out'],
    [str_contains($routes, "post('/web-phone/presence'")
        && str_contains($phoneJs, "fetch('/web-phone/presence'")
        && str_contains($phoneJs, 'startPresencePolling()')
        && str_contains($phoneJs, "contact.online === true ? 'Online'")
        && str_contains($phoneJs, "'is-unknown'")
        && str_contains($css, '.webphone-contact-row.is-online'), 'contacts poll tenant-scoped extension presence and render online, offline, or unknown indicators'],
    [str_contains($phoneView, 'id="web-phone-permissions"') && str_contains($phoneJs, 'showPermissionSetup()')
        && strpos($phoneJs, 'await startPhone(config, password)') < strpos($phoneJs, 'showPermissionSetup();'), 'permission setup appears only after successful SIP registration'],
    [str_contains($listenView, 'active-calls-grid') && str_contains($listenView, 'Listen on an extension')
        && str_contains($listenView, 'Grandstream-style')
        && str_contains($listenJs, "fetch('/active-calls/feed'") && str_contains($listenJs, "requestListen(target.channel, 'extension'")
        && str_contains($listenJs, 'setTimeout(() => refreshCalls(false), 3000)'),
        'Active Calls auto-refreshes and merges browser or tenant-extension listening'],
    [!str_contains($listenView, 'listen-secret') && str_contains($listenView, 'active-call-hangup')
        && str_contains($listenJs, "fetch('/active-calls/browser-session'")
        && str_contains($listenJs, "fetch('/active-calls/hangup'"),
        'Active Calls provides direct restricted browser listening and tenant-safe hangup controls'],
    [str_contains($phoneJs, 'getReceivers()') && !str_contains($phoneJs, 'audio.hidden = false')
        && str_contains($phoneJs, 'mediaSyncTimer') && str_contains($phoneView, 'web-phone-speaker')
        && str_contains($phoneView, 'playsinline hidden') && !str_contains($phoneView, 'playsinline controls'), 'web phone continuously attaches remote media without exposing native audio controls'],
    [str_contains($phoneJs, 'Credentials verified. Registering phone')
        && str_contains($phoneJs, "permissionEnable.addEventListener('click'")
        && !str_contains($phoneJs, 'Credentials verified. Requesting browser permissions'), 'web phone requests browser permissions from a post-login setup dialog'],
    [str_contains($phoneView, 'web-phone-settings-open') && str_contains($phoneView, 'web-phone-microphone')
        && str_contains($phoneView, 'web-phone-speaker-device') && str_contains($phoneView, 'web-phone-ringtone')
        && str_contains($phoneView, 'web-phone-auto-answer'), 'web phone exposes Wave-style audio and incoming-call settings'],
    [str_contains($phoneView, 'webphone-login-brand') && str_contains($phoneView, 'webphone-login-trust')
        && str_contains($css, '.webphone-login-heading') && str_contains($css, 'linear-gradient(145deg,#07101f'), 'web phone login uses the redesigned secure-calling card'],
    [substr_count($phoneView, 'id="web-phone-disconnect"') === 1 && str_contains($phoneView, 'webphone-logout')
        && str_contains($phoneView, '<small>Logout</small>'), 'web phone exposes one persistent workspace logout control'],
    [str_contains($phoneView, 'webphone-login-input') && str_contains($phoneView, 'Enter your extension')
        && str_contains($phoneView, 'Enter your password') && str_contains($phoneView, 'inputmode="numeric" autocomplete="off"')
        && str_contains($css, '.webphone-login-input input { width:100%; }')
        && str_contains($css, '.webphone-login-input:focus-within'), 'web phone login fields use equal full-width polished styling without username autofill'],
    [str_contains($css, '.webphone-row-action') && str_contains($css, 'scrollbar-width:thin')
        && str_contains($css, '::-webkit-scrollbar-thumb:hover'), 'full-width call and contact lists expose call actions and styled scrolling'],
    [str_contains($css, '@media (max-width: 700px)') && str_contains($css, 'position:fixed; inset:auto 0 0')
        && str_contains($css, 'padding-bottom:68px') && str_contains($css, '@media (max-width: 900px) and (min-width: 701px)')
        && str_contains($css, '@media (min-width: 1440px)') && str_contains($css, '@media (max-width: 360px)'), 'web phone adapts navigation and dialer layout across small mobile, tablet, and wide desktop widths'],
    [str_contains($css, 'height:100dvh; min-height:0') && str_contains($css, '.webphone-side { min-width:0; min-height:0')
        && str_contains($css, 'flex-direction:column; overflow:hidden'), 'web phone constrains desktop columns to the viewport and scrolls history independently'],
    [str_contains($phoneJs, 'enumerateDevices()') && str_contains($phoneJs, 'setSinkId')
        && str_contains($phoneJs, 'startRingtone()') && str_contains($phoneJs, 'preferences.autoAnswer')
        && str_contains($phoneJs, 'panel-webphone-preferences'), 'web phone persists device, ringtone, volume, and auto-answer preferences'],
    [str_contains($phoneJs, "['OverconstrainedError', 'NotFoundError', 'NotReadableError']")
        && str_contains($phoneJs, 'No working microphone was detected')
        && str_contains($phoneJs, 'The microphone is busy or unavailable'), 'web phone falls back from stale devices and reports the actual microphone failure'],
    [str_contains($updateView, '/system/update/progress') && str_contains($updateView, 'RECONNECTING') && str_contains($updateView, "setTimeout(poll, 1000)"), 'update page tracks background progress and reconnects automatically'],
    [str_contains($updateService, 'Progress was recovered after the server restart')
        && str_contains($updateHelper, 'systemd-run --quiet --collect')
        && !str_contains($updateHelper, "    systemctl restart php8.3-fpm\n    nginx -t"), 'update completion survives the PHP-FPM restart'],
    [str_contains($updateHelper, 'verify_release_head "$repo_version" "$remote_head"')
        && str_contains($updateHelper, 'refs/tags/$tag:refs/tags/$tag')
        && str_contains($updateHelper, 'BACKUP_RETENTION=5')
        && str_contains($updateHelper, 'prune_backups "$BACKUP_RETENTION"'),
        'updater pins main to its versioned release tag and retains bounded backups'],
    [str_contains($updateHelper, 'enabled virtual host that serves this application')
        && str_contains($updateHelper, 'sites-enabled/*') && str_contains($updateHelper, 'grep -Fq "$APP/public"')
        && str_contains($updateHelper, 'microphone=(self), geolocation=()')
        && str_contains($updateHelper, 'nginx -t'), 'updater repairs restrictive Web Phone microphone headers before reloading Nginx'],
    [str_contains($phoneJs, "allowsFeature('microphone')")
        && str_contains($phoneJs, 'blocking microphone access through its Permissions-Policy header'), 'web phone distinguishes a server microphone policy block from a browser permission denial'],
    [str_contains($phoneJs, "if (!response.ok) throw new Error('The server did not confirm logout.')")
        && str_contains($phoneJs, 'finally { disconnectButton.disabled = false; }')
        && str_contains($phoneJs, "login.querySelector('[name=\"secret\"]').type = 'password'"), 'web phone only shows logout after server confirmation and resets password visibility'],
    [str_contains($phoneJs, 'function normalizeDestination(value)')
        && str_contains($phoneJs, 'if (settled) return;')
        && str_contains($phoneJs, 'catch (error) { setStatus(microphoneErrorMessage(error)); }'), 'web phone normalizes call targets and safely handles late registration and media errors'],
    [str_contains($phoneView, 'aria-label="Web Phone navigation"')
        && str_contains($phoneView, 'aria-label="Calls and contacts"')
        && str_contains($phoneJs, 'settingsReturnFocus')
        && str_contains($phoneJs, 'permissionEnable.focus()'), 'web phone navigation and dialogs preserve accessible labels and keyboard focus'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}

echo "All UI preference smoke checks passed.\n";
