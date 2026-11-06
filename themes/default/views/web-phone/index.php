<div class="webphone-app is-locked">
    <aside class="webphone-rail">
        <a href="/login" class="webphone-avatar" title="Back to login">P</a>
        <button type="button" class="webphone-rail-item is-active" data-phone-view="calls" title="Calls"><span>☎</span><small>Calls</small></button>
        <button type="button" class="webphone-rail-item" data-phone-view="contacts" title="Contacts"><span>♙</span><small>Contacts</small></button>
        <span class="webphone-rail-space"></span>
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
                <div class="field"><label for="web-phone-extension">Extension</label><input id="web-phone-extension" name="extension" inputmode="numeric" autocomplete="username" placeholder="Extension" required></div>
                <div class="field"><label for="web-phone-secret">SIP password</label><input id="web-phone-secret" name="secret" type="password" autocomplete="current-password" placeholder="User Password" required></div>
                <button type="submit" class="btn btn-primary btn-block" id="web-phone-start">Log in</button>
                <p id="web-phone-login-status" class="webphone-error" role="alert"></p>
                <a href="/login" class="text-sm">Back to panel login</a>
            </form>

            <div class="webphone-dialer" id="web-phone-dialer" hidden>
                <div class="webphone-welcome"><span id="web-phone-clock"></span><h1 id="web-phone-greeting">Ready to call</h1><p id="web-phone-identity"></p></div>
                <div class="webphone-call-panel" id="web-phone-call-panel">
                    <div class="webphone-call-party" id="web-phone-call-party" hidden><span>☎</span><strong id="web-phone-party-number"></strong><small id="web-phone-call-time">Incoming call</small></div>
                    <input id="web-phone-number" inputmode="tel" autocomplete="off" placeholder="Enter number or extension" aria-label="Number or extension">
                    <div class="webphone-keypad" id="web-phone-keypad"><?php foreach ([['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']] as [$key,$letters]): ?><button type="button" data-key="<?= e($key) ?>"><strong><?= e($key) ?></strong><small><?= e($letters) ?></small></button><?php endforeach; ?></div>
                    <div class="webphone-in-call-tools" id="web-phone-in-call-tools" hidden>
                        <button type="button" id="web-phone-mute" title="Mute"><span>♩</span><small>Mute</small></button>
                        <button type="button" id="web-phone-hold" title="Hold"><span>Ⅱ</span><small>Hold</small></button>
                        <button type="button" id="web-phone-transfer" title="Transfer"><span>↗</span><small>Transfer</small></button>
                        <button type="button" id="web-phone-dtmf" title="Keypad"><span>⌨</span><small>Keypad</small></button>
                    </div>
                    <div class="webphone-transfer-box" id="web-phone-transfer-box" hidden><input id="web-phone-transfer-number" inputmode="tel" placeholder="Transfer to extension"><button type="button" class="btn btn-primary btn-sm" id="web-phone-transfer-send">Transfer</button></div>
                    <div class="webphone-call-actions"><button type="button" class="webphone-call-btn" id="web-phone-call" disabled title="Call">☎</button><button type="button" class="webphone-answer-btn" id="web-phone-answer" hidden title="Answer">☎</button><button type="button" class="webphone-hangup-btn" id="web-phone-hangup" disabled title="Reject or hang up">☎</button></div>
                    <p id="web-phone-status" role="status" aria-live="polite">Connecting…</p>
                    <button type="button" class="btn btn-sm" id="web-phone-disconnect">Sign out</button>
                </div>
            </div>
            <audio id="web-phone-audio" autoplay hidden></audio>
            <script type="module" src="<?= e(asset('assets/js/web-phone.js')) ?>"></script>
        <?php endif; ?>
    </section>
</div>
