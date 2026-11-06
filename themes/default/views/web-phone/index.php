<div class="webphone-app">
    <aside class="webphone-rail">
        <a href="/login" class="webphone-avatar" title="Back to login">P</a>
        <button type="button" class="webphone-rail-item is-active" title="Calls"><span>☎</span><small>Calls</small></button>
        <button type="button" class="webphone-rail-item" title="Contacts" disabled><span>♙</span><small>Contacts</small></button>
        <span class="webphone-rail-space"></span>
        <select id="languageSelect" aria-label="Language"><option value="en">EN</option><option value="bn">বাং</option></select>
        <button type="button" class="webphone-rail-item theme-toggle" id="themeToggle" title="Switch color mode" aria-label="Switch color mode"><span class="theme-icon">☾</span></button>
    </aside>

    <aside class="webphone-side">
        <div class="webphone-side-head"><strong>Web Phone</strong><span id="web-phone-presence" class="webphone-presence">Offline</span></div>
        <div class="webphone-search">⌕ <input type="search" id="web-phone-search" placeholder="Search calls" disabled></div>
        <div class="webphone-history" id="web-phone-history"><div class="webphone-history-empty">No call history</div></div>
    </aside>

    <section class="webphone-main">
        <?php if (!$browserReady): ?>
            <div class="webphone-login-card"><h1>Web Phone requires HTTPS</h1><p>Open this page through the secure PBX address.</p></div>
        <?php else: ?>
            <form class="webphone-login-card" id="web-phone-login" autocomplete="off">
                <?= csrf() ?>
                <div class="webphone-logo">☎</div>
                <h1>Sign in to Web Phone</h1>
                <p>Use your extension SIP credentials.</p>
                <?php if (!$tenantDetected): ?><div class="field"><label for="web-phone-domain">PBX tenant domain</label><input id="web-phone-domain" name="tenant_domain" placeholder="pbx.example.com" required></div><?php else: ?><input type="hidden" name="tenant_domain" value=""><?php endif; ?>
                <div class="field"><label for="web-phone-extension">Extension</label><input id="web-phone-extension" name="extension" inputmode="numeric" autocomplete="username" placeholder="e.g. 201" required></div>
                <div class="field"><label for="web-phone-secret">SIP password</label><input id="web-phone-secret" name="secret" type="password" autocomplete="current-password" required></div>
                <button type="submit" class="btn btn-primary btn-block" id="web-phone-start">Connect</button>
                <p id="web-phone-login-status" class="webphone-error" role="alert"></p>
                <a href="/login" class="text-sm">Back to panel login</a>
            </form>

            <div class="webphone-dialer" id="web-phone-dialer" hidden>
                <div class="webphone-welcome"><span id="web-phone-clock"></span><h1 id="web-phone-greeting">Ready to call</h1><p id="web-phone-identity"></p></div>
                <div class="webphone-call-panel">
                    <input id="web-phone-number" inputmode="tel" autocomplete="off" placeholder="Enter number or extension" aria-label="Number or extension">
                    <div class="webphone-keypad" id="web-phone-keypad"><?php foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $key): ?><button type="button" data-key="<?= e($key) ?>"><?= e($key) ?></button><?php endforeach; ?></div>
                    <div class="webphone-call-actions"><button type="button" class="webphone-call-btn" id="web-phone-call" disabled title="Call">☎</button><button type="button" class="webphone-answer-btn" id="web-phone-answer" hidden title="Answer">☎</button><button type="button" class="webphone-hangup-btn" id="web-phone-hangup" disabled title="Hang up">☎</button></div>
                    <p id="web-phone-status" role="status" aria-live="polite">Connecting…</p>
                    <button type="button" class="btn btn-sm" id="web-phone-disconnect">Sign out</button>
                </div>
            </div>
            <audio id="web-phone-audio" autoplay hidden></audio>
            <script type="module" src="<?= e(asset('assets/js/web-phone.js')) ?>"></script>
        <?php endif; ?>
    </section>
</div>
