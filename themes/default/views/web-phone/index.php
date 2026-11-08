<div class="webphone-app is-locked">
    <aside class="webphone-rail">
        <a href="/login" class="webphone-avatar" title="Back to login">P</a>
        <button type="button" class="webphone-rail-item" data-phone-view="calls" title="Calls"><span>☎</span><small>Calls</small></button>
        <button type="button" class="webphone-rail-item is-active" data-phone-view="dialpad" title="Dialpad"><span>⌨</span><small>Dialpad</small></button>
        <button type="button" class="webphone-rail-item" data-phone-view="contacts" title="Contacts"><span>♙</span><small>Contacts</small></button>
        <span class="webphone-rail-space"></span>
        <button type="button" class="webphone-rail-item" id="web-phone-settings-open" title="Phone settings"><span>⚙</span><small>Settings</small></button>
        <select id="languageSelect" aria-label="Language"><option value="en">EN</option><option value="bn">বাং</option></select>
        <button type="button" class="webphone-rail-item theme-toggle" id="themeToggle" title="Switch color mode" aria-label="Switch color mode"><span class="theme-icon">☾</span></button>
    </aside>

    <aside class="webphone-side">
        <div class="webphone-side-head"><strong id="web-phone-list-title">Calls</strong><span id="web-phone-presence" class="webphone-presence">Offline</span></div>
        <div class="webphone-search">⌕ <input type="search" id="web-phone-search" placeholder="Search calls"></div>
        <div class="webphone-history" id="web-phone-history"><div class="webphone-history-empty">No call history</div></div>
        <div class="webphone-contacts" id="web-phone-contacts" hidden><div class="webphone-history-empty">Sign in to view contacts</div></div>
    </aside>

    <section class="webphone-main">
        <?php if (!$browserReady): ?>
            <div class="webphone-login-card"><h1>Web Phone requires HTTPS</h1><p>Open this page through the secure PBX address.</p></div>
        <?php else: ?>
            <form class="webphone-login-card" id="web-phone-login" autocomplete="off">
                <?= csrf() ?>
                <span class="webphone-qr-corner" aria-hidden="true">▦</span>
                <h1>Welcome to Web Phone</h1>
                <p>Log in with your extension SIP credentials.</p>
                <p class="webphone-permission-note">After your credentials are verified, Web Phone will request microphone and notification permissions.</p>
                <div class="field"><label for="web-phone-extension">Extension</label><input id="web-phone-extension" name="extension" inputmode="numeric" autocomplete="username" placeholder="Extension" required></div>
                <div class="field"><label for="web-phone-secret">SIP password</label><div class="webphone-password-wrap"><input id="web-phone-secret" name="secret" type="password" autocomplete="current-password" placeholder="User Password" required><button type="button" id="web-phone-password-toggle" aria-label="Show password" title="Show password">◉</button></div></div>
                <button type="submit" class="btn btn-primary btn-block" id="web-phone-start">Log in</button>
                <p id="web-phone-login-status" class="webphone-error" role="alert"></p>
                <a href="/login" class="text-sm">Back to panel login</a>
            </form>

            <div class="webphone-dialer" id="web-phone-dialer" hidden>
                <div class="webphone-welcome"><span id="web-phone-clock"></span><h1 id="web-phone-greeting">Ready to call</h1><p id="web-phone-identity"></p></div>
                <div class="webphone-call-panel" id="web-phone-call-panel">
                    <div class="webphone-call-party" id="web-phone-call-party" hidden><span>☎</span><strong id="web-phone-party-number"></strong><small id="web-phone-call-time">Incoming call</small></div>
                    <div class="webphone-number-wrap"><input id="web-phone-number" inputmode="tel" autocomplete="off" placeholder="Enter number or extension" aria-label="Number or extension"><button type="button" id="web-phone-backspace" title="Delete last digit" aria-label="Delete last digit">⌫</button></div>
                    <div class="webphone-keypad" id="web-phone-keypad"><?php foreach ([['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']] as [$key,$letters]): ?><button type="button" data-key="<?= e($key) ?>"><strong><?= e($key) ?></strong><small><?= e($letters) ?></small></button><?php endforeach; ?></div>
                    <div class="webphone-in-call-tools" id="web-phone-in-call-tools" hidden>
                        <button type="button" id="web-phone-mute" title="Mute"><span>♩</span><small>Mute</small></button>
                        <button type="button" id="web-phone-hold" title="Hold"><span>Ⅱ</span><small>Hold</small></button>
                        <button type="button" id="web-phone-transfer" title="Transfer"><span>↗</span><small>Transfer</small></button>
                        <button type="button" id="web-phone-dtmf" title="Keypad"><span>⌨</span><small>Keypad</small></button>
                        <button type="button" id="web-phone-speaker" title="Play remote audio"><span>◖</span><small>Speaker</small></button>
                    </div>
                    <div class="webphone-transfer-box" id="web-phone-transfer-box" hidden><input id="web-phone-transfer-number" inputmode="tel" placeholder="Transfer to extension"><button type="button" class="btn btn-primary btn-sm" id="web-phone-transfer-send">Transfer</button></div>
                    <div class="webphone-call-actions"><button type="button" class="webphone-call-btn" id="web-phone-call" disabled title="Call">☎</button><button type="button" class="webphone-answer-btn" id="web-phone-answer" hidden title="Answer">☎</button><button type="button" class="webphone-hangup-btn" id="web-phone-hangup" hidden disabled title="Reject or hang up">☎</button></div>
                    <p id="web-phone-status" role="status" aria-live="polite">Connecting…</p>
                    <button type="button" class="btn btn-sm" id="web-phone-disconnect">Sign out</button>
                </div>
            </div>
            <audio id="web-phone-audio" autoplay playsinline hidden></audio>
            <div class="webphone-settings-backdrop" id="web-phone-settings" hidden>
                <section class="webphone-settings" role="dialog" aria-modal="true" aria-labelledby="web-phone-settings-title">
                    <header><div><h2 id="web-phone-settings-title">Phone settings</h2><p>Audio and incoming call preferences are saved in this browser.</p></div><button type="button" id="web-phone-settings-close" aria-label="Close settings">×</button></header>
                    <div class="webphone-settings-body">
                        <nav aria-label="Phone settings sections">
                            <button type="button" class="is-active" data-phone-setting="audio">♩ <span>Audio</span></button>
                            <button type="button" data-phone-setting="calls">☎ <span>Calls</span></button>
                        </nav>
                        <div class="webphone-settings-content">
                            <div data-phone-setting-panel="audio">
                                <h3>Microphone settings</h3>
                                <div class="field"><label for="web-phone-microphone">Microphone</label><div class="webphone-setting-line"><select id="web-phone-microphone"><option value="">Default microphone</option></select><button type="button" class="btn btn-sm" id="web-phone-microphone-test">Test</button></div><div class="webphone-audio-meter"><span id="web-phone-microphone-level"></span></div></div>
                                <h3>Speaker settings</h3>
                                <div class="field"><label for="web-phone-speaker-device">Speaker</label><div class="webphone-setting-line"><select id="web-phone-speaker-device"><option value="">Default speaker</option></select><button type="button" class="btn btn-sm" id="web-phone-speaker-test">Test</button></div><span class="hint" id="web-phone-speaker-hint"></span></div>
                                <div class="field"><label for="web-phone-speaker-volume">Speaker volume</label><input id="web-phone-speaker-volume" type="range" min="0" max="100" value="100"></div>
                                <button type="button" class="btn btn-sm" id="web-phone-devices-refresh">Refresh devices</button>
                            </div>
                            <div data-phone-setting-panel="calls" hidden>
                                <h3>Incoming call settings</h3>
                                <label class="webphone-setting-toggle"><span><strong>Play ringtone</strong><small>Ring through the selected speaker for incoming calls.</small></span><input id="web-phone-ringtone" type="checkbox" checked></label>
                                <label class="webphone-setting-toggle"><span><strong>Auto answer</strong><small>Answer incoming calls automatically while Web Phone is open.</small></span><input id="web-phone-auto-answer" type="checkbox"></label>
                                <label class="webphone-setting-toggle"><span><strong>Desktop notifications</strong><small id="web-phone-notification-state">Browser permission has not been granted.</small></span><button type="button" class="btn btn-sm" id="web-phone-notification-request">Allow</button></label>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <script type="module" src="<?= e(asset('assets/js/web-phone.js')) ?>"></script>
        <?php endif; ?>
    </section>
</div>
