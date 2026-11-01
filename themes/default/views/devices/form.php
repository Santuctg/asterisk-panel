<?php
/**
 * Device create/edit form.
 * Expects: $row, $extensions, $vendors, optional $provision_url
 */
require dirname(__DIR__) . '/partials/fields.php';

$isNew  = empty($row);
$action = $isNew ? '/devices' : '/devices/' . (int) $row['id'];
$val    = static fn (string $key, $default = '') => $row[$key] ?? old($key, $default);

$vendorOpts = [];
foreach ($vendors as $v) {
    $vendorOpts[$v] = ucfirst($v);
}

$extOpts = [];
foreach ($extensions as $e) {
    $extOpts[] = ['id' => $e['id'], 'label' => "{$e['extension']} — {$e['name']}"];
}
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Add device' : 'Edit device' ?></h1>
        <div class="subtitle">Register a phone by MAC address and link it to an extension</div>
    </div>
    <div class="page-actions">
        <a href="/devices" class="btn"><?= $this->fragment('icons/chevron') ?> Back</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Device</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('mac', 'MAC address', $val('mac'), [
                    'required'    => true,
                    'placeholder' => '00:15:65:aa:bb:cc',
                    'hint'        => 'Found on the phone\'s label or web interface. Colons or dashes are fine.',
                ]); ?>

                <?php echo field_select('vendor', 'Vendor', $vendorOpts, $val('vendor', 'generic'), [
                    'required' => true,
                    'hint'     => 'Determines the provisioning file format generated.',
                ]); ?>

                <?php echo field_text('model', 'Model', $val('model'), [
                    'placeholder' => 'e.g. T46U',
                ]); ?>

                <?php echo field_select('extension_id', 'Linked extension', $extOpts, $val('extension_id'), [
                    'placeholder' => 'None (unassigned)',
                    'hint'        => 'Credentials from this extension are written into the phone config.',
                ]); ?>

                <?php echo field_text('description', 'Description', $val('description'), [
                    'span' => true,
                    'hint' => 'Where the phone is located or who uses it.',
                ]); ?>

                <?php echo field_text('firmware', 'Firmware version', $val('firmware'), [
                    'hint' => 'Optional, for inventory tracking.',
                ]); ?>

                <div class="field">
                    <label>Provisioning URL</label>
                    <?php if (!empty($provision_url)): ?>
                        <div class="copy-field">
                            <input type="text" readonly value="<?= e($provision_url) ?>" id="provUrl">
                            <button class="btn btn-sm" type="button" data-copy-target="provUrl">
                                <?= $this->fragment('icons/copy') ?>
                            </button>
                        </div>
                    <?php else: ?>
                        <input type="text" readonly value="Available after saving">
                    <?php endif; ?>
                    <span class="hint">Enter this into the phone's provisioning/server URL setting.</span>
                </div>

                <div class="span-2">
                    <?php echo field_check('enabled', 'Device active',
                        $isNew ? true : (bool) $row['enabled'],
                        'Disabled devices get a 404 when they try to provision.'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <a href="/devices" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?>
                <?= $isNew ? 'Add device' : 'Save changes' ?>
            </button>
        </div>
    </div>
</form>
