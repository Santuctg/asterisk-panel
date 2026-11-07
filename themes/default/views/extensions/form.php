<?php
/**
 * Extension create/edit form.
 * Expects: $row (null for create), $defaults (suggestNext), $tenants, $outboundRoutes
 */
require dirname(__DIR__) . '/partials/fields.php';

$isNew  = empty($row);
$action = $isNew ? '/extensions' : '/extensions/' . (int) $row['id'];
$val    = static fn (string $key, $default = '') => $row[$key] ?? old($key, $default);
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'New extension' : 'Edit extension ' . e($row['extension']) ?></h1>
        <div class="subtitle">
            <?= $isNew
                ? 'A SIP endpoint a phone registers with. Extensions are scoped to the active tenant.'
                : 'Update settings for this extension. Changes apply when config is applied to Asterisk.' ?>
        </div>
    </div>
    <div class="page-actions">
        <a href="/extensions" class="btn"><?= $this->fragment('icons/chevron') ?> Back to extensions</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Identity</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php
                $extVal = $isNew ? ($defaults['extension'] ?? '') : $row['extension'];
                echo field_text('extension', 'Extension number', old('extension', $extVal), [
                    'required' => true,
                    'hint'     => 'Digits only, e.g. 1001. Must be unique within this tenant.',
                    'type'     => 'text',
                ]);
                ?>

                <?php echo field_text('name', 'Display name', $val('name'), [
                    'required'    => true,
                    'placeholder' => 'e.g. Front Desk',
                    'hint'        => 'Shown on the phone screen and in caller ID.',
                ]); ?>

                <?php echo field_text('cid_name', 'Caller ID name', $val('cid_name'), [
                    'placeholder' => 'e.g. Acme Front Desk',
                ]); ?>

                <?php echo field_text('cid_number', 'Caller ID number', $val('cid_number'), [
                    'placeholder' => 'e.g. +15551234567',
                ]); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Security</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php
                echo field_text('secret', 'SIP password', '', [
                    'type' => 'password',
                    'required' => $isNew,
                    'autocomplete' => 'new-password',
                    'hint' => $isNew
                        ? 'Generate a strong password; the phone must use this exact value.'
                        : 'Leave blank to keep the current SIP password, or enter a new one to rotate it.',
                ]);
                ?>
                <div class="field">
                    <label>&nbsp;</label>
                    <button class="btn" data-random-target="secret" data-random-length="14">
                        <?= $this->fragment('icons/refresh') ?> Generate strong password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Behavior</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('ring_timeout', 'Ring timeout (seconds)', $val('ring_timeout', $defaults['ring_timeout'] ?? 25), [
                    'type' => 'number',
                    'hint' => 'How long the phone rings before moving on (e.g. to voicemail).',
                ]); ?>

                <?php echo field_text('max_registrations', 'Max simultaneous registrations', $val('max_registrations', 1), [
                    'type' => 'number',
                    'hint' => 'Set higher to allow a desk phone + softphone at once.',
                ]); ?>

                <?php echo field_select('outbound_route_id', 'Outbound route', $outboundRoutes ?? [], $val('outbound_route_id'), [
                    'placeholder' => 'Internal calls only',
                    'hint' => 'Without an assigned route, this extension cannot make external calls.',
                ]); ?>

                <div class="span-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <?php echo field_check('vm_enabled', 'Voicemail enabled',
                        (bool) $val('vm_enabled', 1), 'Allow this extension to receive voicemail.'); ?>
                    <?php echo field_check('follow_me', 'Follow me / mobile',
                        (bool) $val('follow_me', 0), 'Continue ringing other destinations after the primary.'); ?>
                    <?php echo field_check('vm_on_busy', 'Voicemail on busy',
                        (bool) $val('vm_on_busy', 1), 'Send to voicemail when the line is busy.'); ?>
                    <?php echo field_check('vm_on_noanswer', 'Voicemail on no answer',
                        (bool) $val('vm_on_noanswer', 1), 'Send to voicemail when nobody picks up.'); ?>
                    <?php echo field_check('enabled', 'Enabled',
                        $isNew ? true : (bool) $row['enabled'], 'Disabled extensions cannot register or receive calls.'); ?>

                    <?php echo field_select('record', 'Call recording', [
                        '0' => 'Tenant default',
                        '1' => 'Always record',
                        '2' => 'Never record',
                    ], (string) ($row['record'] ?? 0), [
                        'hint' => 'Override the tenant-wide automatic recording setting for this extension.',
                    ]); ?>
                </div>

                <?php echo field_textarea('description', 'Notes', (string) $val('description'), [
                    'rows' => 2, 'span' => true,
                    'hint' => 'Optional internal notes (e.g. desk location, who uses it).',
                ]); ?>
            </div>
        </div>
    </div>

    <?php if ($isNew): ?>
    <div class="card">
        <div class="card-head"><h2>Optional: create a voicemail box now</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="span-2">
                    <?php echo field_check('create_voicemail', 'Create a matching voicemail box',
                        (bool) old('create_voicemail'), 'Sets up voicemail-to-email for this extension immediately.'); ?>
                </div>
                <div class="field">
                    <label for="vm_email">Voicemail email</label>
                    <input type="email" id="vm_email" name="vm_email"
                           value="<?= e(old('vm_email')) ?>" placeholder="user@example.com">
                    <span class="hint">Where voicemail notifications (and audio, if enabled) are sent.</span>
                </div>
                <div class="field">
                    <label for="vm_pin">Voicemail PIN</label>
                    <div class="flex gap-2">
                        <input type="text" id="vm_pin" name="vm_pin" value="<?= e(old('vm_pin')) ?>" maxlength="10">
                        <button class="btn" data-random-pin="vm_pin" data-random-length="6">Random</button>
                    </div>
                    <span class="hint">Leave blank to auto-generate a 6-digit PIN.</span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-foot">
            <a href="/extensions" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?>
                <?= $isNew ? 'Create extension' : 'Save changes' ?>
            </button>
        </div>
    </div>
</form>
