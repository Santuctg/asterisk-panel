<div class="page-head"><div>
    <h1>Web Phone</h1>
    <div class="subtitle">Make and receive calls from this browser using a tenant extension.</div>
</div></div>

<div class="card"><div class="card-body">
    <?php if (!$browserReady): ?>
        <p>Web Phone requires HTTPS.</p>
    <?php elseif (!$extensions): ?>
        <p>No enabled extension is available in this tenant.</p>
    <?php else: ?>
        <div class="form-grid">
            <div class="field">
                <label for="web-phone-extension">Use extension</label>
                <select id="web-phone-extension">
                    <?php foreach ($extensions as $extension): ?>
                        <option value="<?= e($extension['extension']) ?>"><?= e($extension['extension'] . ' — ' . $extension['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="web-phone-secret">SIP password</label>
                <input id="web-phone-secret" type="password" autocomplete="off" placeholder="Extension SIP password">
            </div>
            <div class="field span-2">
                <label for="web-phone-number">Number or extension</label>
                <input id="web-phone-number" inputmode="tel" autocomplete="off" placeholder="e.g. 1001 or an outbound number">
            </div>
        </div>
        <div class="flex gap-2 mt-4">
            <button type="button" class="btn btn-primary" id="web-phone-start">Connect</button>
            <button type="button" class="btn" id="web-phone-call" disabled>Call</button>
            <button type="button" class="btn" id="web-phone-hangup" disabled>Hang up</button>
        </div>
        <p id="web-phone-status" role="status" aria-live="polite"></p>
        <audio id="web-phone-audio" autoplay controls hidden></audio>
        <input type="hidden" id="web-phone-context" value="<?= e($context) ?>">
        <p class="hint">Your SIP password is used only in browser memory. Microphone permission is required for two-way calls.</p>
    <?php endif; ?>
</div></div>

<?php if ($browserReady && $extensions): ?>
<script type="module" src="<?= e(asset('assets/js/web-phone.js')) ?>"></script>
<?php endif; ?>
