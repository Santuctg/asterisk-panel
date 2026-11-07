<?php
/**
 * Voicemail create/edit form.
 * Expects: $row (null for create), $available (extensions without a box)
 */
require dirname(__DIR__) . '/partials/fields.php';

$isNew  = empty($row);
$action = $isNew ? '/voicemail' : '/voicemail/' . (int) $row['id'];
$val    = static fn (string $key, $default = '') => $row[$key] ?? old($key, $default);
$selectedTimezone = normalize_timezone((string) $val('timezone', 'America/New_York'), 'America/New_York');
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'New voicemail box' : 'Edit voicemail box' ?></h1>
        <div class="subtitle">Messages can be emailed to one or more recipients</div>
    </div>
    <div class="page-actions">
        <a href="/voicemail" class="btn"><?= $this->fragment('icons/chevron') ?> Back</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Mailbox</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php if ($isNew): ?>
                    <?php
                    $opts = [];
                    foreach ($available as $a) {
                        $opts[] = ['id' => $a['id'], 'label' => "{$a['extension']} — {$a['name']}"];
                    }
                    echo field_select('extension_id', 'Extension', $opts, old('extension_id'), [
                        'required'    => true,
                        'placeholder' => 'Choose an extension…',
                        'hint'        => 'Only extensions that do not already have a mailbox are listed.',
                    ]);
                    ?>
                <?php else: ?>
                    <div class="field">
                        <label>Extension</label>
                        <input type="text" value="<?= e($row['ext'] ?? '') ?>" readonly>
                    </div>
                <?php endif; ?>

                <?php echo field_text('name', 'Mailbox name', $val('name'), [
                    'required' => true, 'placeholder' => 'e.g. Front Desk',
                ]); ?>

                <?php echo field_text('pin', 'PIN', $val('pin'), [
                    'required' => true,
                    'hint'     => '4–10 digits. Callers dial this to check messages.',
                ]); ?>

                <div class="field">
                    <label for="pin_gen">&nbsp;</label>
                    <button class="btn" id="pin_gen" data-random-pin="pin" data-random-length="6">
                        <?= $this->fragment('icons/refresh') ?> Generate PIN
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Email delivery</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('email', 'Email address', $val('email'), [
                    'type' => 'email', 'span' => true,
                    'hint' => 'Receives the voicemail notification (and audio attachment, if enabled).',
                ]); ?>

                <?php echo field_text('pager_email', 'Pager / secondary email', $val('pager_email'), [
                    'type' => 'email',
                    'hint' => 'Optional short notification address (no audio).',
                ]); ?>

                <?php echo field_text('vm_password', 'Mailbox password', '', [
                    'type' => 'password', 'autocomplete' => 'new-password',
                    'hint' => $isNew
                        ? 'Optional alternate password used for mailbox access.'
                        : 'Leave blank to keep the existing mailbox password.',
                ]); ?>

                <div class="span-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <?php echo field_check('attach', 'Attach audio to email',
                        (bool) $val('attach', 1), 'Send the recording as an attachment. Uncheck for notification-only.'); ?>
                    <?php echo field_check('say_cid', 'Announce caller ID',
                        (bool) $val('say_cid', 1), 'Play the caller\'s number before the message.'); ?>
                    <?php echo field_check('envelope', 'Play envelope (date/time)',
                        (bool) $val('envelope', 1), 'Read the timestamp when reviewing messages.'); ?>
                    <?php echo field_check('skip_instructions', 'Skip recording instructions',
                        (bool) $val('skip_instructions', 0), 'Do not play the "record your message" prompt.'); ?>
                    <?php echo field_check('enabled', 'Mailbox active',
                        $isNew ? true : (bool) $row['enabled'], 'Inactive mailboxes reject messages.'); ?>
                </div>

                <?php
                echo field_select('timezone', 'Timezone', timezone_options(), $selectedTimezone, [
                    'hint' => 'Used when reading the message timestamp.',
                ]);
                ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <a href="/voicemail" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?>
                <?= $isNew ? 'Create mailbox' : 'Save changes' ?>
            </button>
        </div>
    </div>
</form>
