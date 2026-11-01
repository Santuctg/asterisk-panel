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

<form method="post" action="/system/settings">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Business details</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('company_name', 'Company name', $s('company_name'), ['span' => true]); ?>
                <?php echo field_text('company_email', 'Email address', $s('company_email'), ['type' => 'email']); ?>
                <?php echo field_text('company_phone', 'Phone number', $s('company_phone'), ['type' => 'tel']); ?>
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
