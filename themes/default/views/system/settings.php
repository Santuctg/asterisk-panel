<?php
/**
 * System settings. Expects: $settings (key=>value), $ami
 */
require dirname(__DIR__) . '/partials/fields.php';

$s = static fn (string $k, string $d = '') => $settings[$k] ?? $d;
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <div class="subtitle">Tenant details, outbound caller ID and defaults</div>
    </div>
    <div class="page-actions">
        <a href="/system/status" class="btn"><?= $this->fragment('icons/pulse') ?> Status</a>
    </div>
</div>

<?php if (!empty($canChangeSipPort)): ?>
<div class="card">
    <div class="card-head"><h2>SIP listener</h2></div>
    <div class="card-body">
        <?php echo field_text('sip_udp_port', 'SIP UDP port', (string) ($sipPort ?? 5060), [
            'type' => 'number', 'min' => 1024, 'max' => 65535,
            'hint' => 'Global Asterisk PJSIP port. RTP uses 10000–20000, so that range is excluded. Existing phones must be updated after changing it.',
        ]); ?>
        <p class="hint mt-3 mb-0"><strong>Important:</strong> saving regenerates the configuration, but Asterisk must be restarted for a changed transport port to start listening. Update firewall rules and phone provisioning too.</p>
    </div>
</div>
<?php endif; ?>

<form method="post" action="/system/settings" enctype="multipart/form-data">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Panel branding</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('panel_name', 'Panel name', $s('panel_name', config('app.name', 'Asterisk Panel')), [
                    'required' => true, 'span' => true,
                    'hint' => 'Used in the main panel, sign-in screen and Web Phone.',
                ]); ?>
                <div class="field span-2">
                    <label for="panel_logo">Panel logo</label>
                    <?php if (!empty($branding['logo_url'])): ?><img class="branding-logo-preview" src="<?= e($branding['logo_url']) ?>" alt="Current panel logo"><?php endif; ?>
                    <input id="panel_logo" name="panel_logo" type="file" accept="image/png,image/jpeg,image/webp">
                    <span class="hint">PNG, JPEG or WebP; maximum 2 MB. A square or horizontal transparent image works best.</span>
                </div>
                <?php if (!empty($branding['logo_url'])): ?><div class="span-2"><?= field_check('remove_panel_logo', 'Remove current logo', false, 'Return to the generated panel initial.') ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Business details</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('company_name', 'Company name', $s('company_name'), ['span' => true]); ?>
                <?php echo field_text('company_email', 'Email address', $s('company_email'), ['type' => 'email']); ?>
                <?php echo field_text('company_phone', 'Phone number', $s('company_phone'), ['type' => 'tel']); ?>
                <?php echo field_select('timezone', 'Timezone', timezone_options(), $s('timezone', 'UTC'), [
                    'span' => true,
                    'hint' => 'Controls timestamps shown in the panel. Current time: ' . date('M j, Y g:i:s A'),
                ]); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Outbound caller ID</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('outbound_cid_name', 'Caller ID name', $s('outbound_cid_name'), [
                    'hint' => 'Presented on outbound calls when a route does not override it.',
                ]); ?>
                <?php echo field_text('outbound_cid_number', 'Caller ID number', $s('outbound_cid_number'), [
                    'hint' => 'Should match a number your provider has authorized.',
                ]); ?>
                <?php echo field_select('time_format', 'Time format', [
                    '12' => '12-hour (3:04 PM)', '24' => '24-hour (15:04)',
                ], $s('time_format', '12')); ?>
                <?php echo field_text('provision_base_url', 'Provisioning base URL', $s('provision_base_url'), [
                    'hint' => 'Overrides the default URL used to build device provisioning links.',
                ]); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Voicemail email</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('voicemail_email_subject', 'Email subject', $s('voicemail_email_subject', 'New message from ${CALLERID(num)}'), [
                    'span' => true,
                    'hint' => 'Asterisk variable substitution works here, e.g. ${CALLERID(num)}.',
                ]); ?>
                <?php echo field_textarea('voicemail_email_body', 'Email body', $s('voicemail_email_body'), [
                    'rows' => 4, 'span' => true,
                    'hint' => 'Leave blank to use the default body from scripts/asterisk-mail.php.',
                ]); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Automatic call recording</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_select('recording_mode', 'Recording mode', [
                    'off'      => 'Off',
                    'all'      => 'All calls (inbound, outbound and internal)',
                    'inbound'  => 'Inbound calls only',
                    'outbound' => 'Outbound calls only',
                ], $s('recording_mode', 'off'), [
                    'span' => true,
                    'hint' => 'Recordings are WAV files under the protected Asterisk recording directory.',
                ]); ?>
                <?php echo field_select('recording_start', 'Start recording', [
                    'answered' => 'After the call is answered (conversation only)',
                    'full' => 'From call start (announcement and ringing included)',
                ], $s('recording_start', 'full'), [
                    'span' => true,
                    'hint' => 'After answer starts when the inbound agent or outbound remote party joins the call.',
                ]); ?>
            </div>
            <p class="hint mb-0">Call recording laws vary. Enable this only after confirming consent, disclosure and retention requirements for every location involved.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Connection</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label>AMI host</label>
                    <input type="text" value="<?= e($ami['host'] ?? '') ?>" readonly>
                    <span class="hint">Configured via AMI_HOST in the environment.</span>
                </div>
                <div class="field">
                    <label>AMI port</label>
                    <input type="text" value="<?= e((string) ($ami['port'] ?? 5038)) ?>" readonly>
                </div>
                <div class="field">
                    <label>AMI username</label>
                    <input type="text" value="<?= e($ami['username'] ?? '') ?>" readonly>
                </div>
                <div class="field">
                    <label>AMI secret</label>
                    <input type="text" value="<?= e(mask_secret($ami['secret'] ?? '')) ?>" readonly>
                </div>
            </div>
            <p class="hint mt-3 mb-0">These are read-only here and set through environment variables. See <code>resources/config/asterisk.php</code>.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <span class="muted text-sm">Changes are stored in the panel database.</span>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?> Save settings
            </button>
        </div>
    </div>
</form>
