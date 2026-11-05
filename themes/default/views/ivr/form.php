<?php
/**
 * IVR menu create/edit form.
 * Expects: $row (with decoded 'keys'), $destinations, $next
 */
require dirname(__DIR__) . '/partials/fields.php';

$isNew  = empty($row);
$action = $isNew ? '/ivr' : '/ivr/' . (int) $row['id'];
$val    = static fn (string $key, $default = '') => $row[$key] ?? old($key, $default);

// Human labels for each destination type.
$typeLabels = [
    'extension'  => 'Extension',
    'ringgroup'  => 'Ring group',
    'ivr'        => 'Another IVR',
    'queue'      => 'Call queue',
    'conference' => 'Conference',
    'parking'    => 'Parking lot',
    'timecond'   => 'Time condition',
    'announcement' => 'Announcement',
    'external'   => 'External number',
    'hangup'     => 'Hang up',
];

$keys = $row['keys'] ?? (json_decode((string) old('keys', '[]'), true) ?: []);
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'New IVR menu' : 'Edit IVR menu' ?></h1>
        <div class="subtitle">An auto-attendant: play a greeting and route by key press</div>
    </div>
    <div class="page-actions">
        <a href="/ivr" class="btn"><?= $this->fragment('icons/chevron') ?> Back</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Menu</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('extension', 'Menu number', $val('extension', $next['extension'] ?? ''), [
                    'required' => true,
                    'hint'     => 'The number callers dial to reach this menu.',
                ]); ?>

                <?php echo field_text('name', 'Menu name', $val('name'), [
                    'required' => true, 'placeholder' => 'e.g. Main Auto-Attendant',
                ]); ?>

                <?php echo field_text('greeting', 'Greeting audio file', $val('greeting'), [
                    'span' => true,
                    'hint' => 'Path to the prompt relative to the Asterisk sounds dir, e.g. custom/welcome. Leave blank for silence.',
                ]); ?>

                <?php echo field_text('timeout', 'Digit timeout (seconds)', $val('timeout', 3), ['type' => 'number']); ?>
                <?php echo field_text('response_timeout', 'Overall response timeout (seconds)', $val('response_timeout', 8), ['type' => 'number']); ?>
                <?php echo field_text('max_retries', 'Max invalid retries', $val('max_retries', 3), ['type' => 'number']); ?>

                <?php
                $timeoutActions = ['' => 'Repeat menu'] + $typeLabels;
                echo field_select('timeout_action', 'On timeout', $timeoutActions, $val('timeout_action'), [
                    'hint' => 'What happens if the caller presses nothing.',
                ]);
                ?>

                <?php
                // Destination for the timeout action, if one was chosen.
                echo field_select('timeout_dest_id', 'Timeout destination', $destinations, $val('timeout_dest_id'), [
                    'placeholder' => 'Choose…',
                ]);
                ?>

                <div class="span-2">
                    <?php echo field_check('enabled', 'Menu active', $isNew ? true : (bool) $row['enabled']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Key mappings</h2>
            <button class="btn btn-sm" type="button" data-repeat-add="#keyRows"
                    data-repeat-template='<?= e('<div class="repeat-row flex gap-2 items-center mb-2">
                        <input type="text" name="key[]" placeholder="0-9, *, or #" maxlength="1" style="width:70px" required>
                        <select name="key_type[]" style="width:170px">' .
                        implode('', array_map(
                            static fn ($t, $l) => '<option value="' . e($t) . '">' . e($l) . '</option>',
                            array_keys($typeLabels), array_values($typeLabels)
                        )) . '</select>
                        <input type="number" name="key_dest[]" placeholder="Destination ID" style="width:150px">
                        <button class="btn btn-sm btn-ghost" type="button" data-repeat-remove style="color:var(--danger-500)">' .
                        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>' .
                        '</button>
                    </div>') ?>'>
                <?= $this->fragment('icons/plus') ?> Add key
            </button>
        </div>
        <div class="card-body">
            <p class="muted text-sm">
                Map each key a caller can press to a destination. The destination ID is the numeric ID of the
                target object (extension, ring group, etc.) — see the lists on the Call Routing page.
            </p>

            <div id="keyRows">
                <?php foreach ($keys as $k => $dest): ?>
                    <div class="repeat-row flex gap-2 items-center mb-2">
                        <input type="text" name="key[]" value="<?= e((string) $k) ?>"
                               placeholder="0-9, *, or #" maxlength="1" style="width:70px" required>
                        <select name="key_type[]" style="width:170px">
                            <?php foreach ($typeLabels as $t => $l): ?>
                                <option value="<?= e($t) ?>" <?= ($dest['type'] ?? '') === $t ? 'selected' : '' ?>>
                                    <?= e($l) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="key_dest[]" value="<?= e((string) ($dest['id'] ?? '')) ?>"
                               placeholder="Destination ID" style="width:150px">
                        <button class="btn btn-sm btn-ghost" type="button" data-repeat-remove
                                style="color:var(--danger-500)">
                            <?= $this->fragment('icons/x') ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <a href="/ivr" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?>
                <?= $isNew ? 'Create IVR menu' : 'Save changes' ?>
            </button>
        </div>
    </div>
</form>
