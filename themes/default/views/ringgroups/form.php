<?php
/**
 * Ring group create/edit form.
 * Expects: $row (with 'members' list of extension_id), $extensions, $next
 */
require dirname(__DIR__) . '/partials/fields.php';

$isNew  = empty($row);
$action = $isNew ? '/ring-groups' : '/ring-groups/' . (int) $row['id'];
$val    = static fn (string $key, $default = '') => $row[$key] ?? old($key, $default);

$strategies = [
    'ringall'     => 'Ring all simultaneously (everyone rings at once)',
    'leastrecent' => 'Least recently answered first',
    'random'      => 'Random order',
    'wrandom'     => 'Weighted random',
    'seqfixed'    => 'Sequential in a fixed order',
];

// The set of currently-selected member extension IDs.
$selected = array_map(
    static fn ($m) => (int) ($m['extension_id'] ?? $m['id'] ?? 0),
    $row['members'] ?? []
);
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'New ring group' : 'Edit ring group' ?></h1>
        <div class="subtitle">A single number that rings several extensions together</div>
    </div>
    <div class="page-actions">
        <a href="/ring-groups" class="btn"><?= $this->fragment('icons/chevron') ?> Back</a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf() ?>

    <div class="card">
        <div class="card-head"><h2>Group</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <?php echo field_text('extension', 'Group number', $val('extension', $next['extension'] ?? ''), [
                    'required' => true,
                    'hint'     => 'The number people dial to reach this group.',
                ]); ?>

                <?php echo field_text('name', 'Group name', $val('name'), [
                    'required' => true, 'placeholder' => 'e.g. Sales Team',
                ]); ?>

                <?php echo field_select('strategy', 'Ring strategy', $strategies, $val('strategy', 'ringall'), [
                    'required' => true, 'span' => true,
                ]); ?>

                <?php echo field_text('timeout', 'Ring timeout (seconds)', $val('timeout', 30), [
                    'type' => 'number',
                    'hint' => 'Total time before falling through to voicemail.',
                ]); ?>

                <?php
                $announcementOptions = [];
                foreach ((array) ($announcements ?? []) as $announcement) {
                    $announcementOptions[(int) $announcement['id']] = $announcement['name'];
                }
                echo '<div class="field"><label for="announcement_id">Custom prompt</label><div class="flex gap-2">';
                echo '<select id="announcement_id" name="announcement_id"><option value="">No custom prompt</option>';
                foreach ($announcementOptions as $id => $label) {
                    echo '<option value="' . (int) $id . '"' . ((string) $val('announcement_id') === (string) $id ? ' selected' : '') . '>' . e($label) . '</option>';
                }
                echo '</select><a class="btn" href="/moh?add=announcement">Add Audio</a></div>';
                echo '<span class="hint">Plays completely and cannot be skipped; ringing starts afterwards.</span></div>';
                ?>

                <div class="span-2">
                    <?php echo field_check('enabled', 'Group active',
                        $isNew ? true : (bool) $row['enabled']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Members</h2></div>
        <div class="card-body">
            <?php if (empty($extensions)): ?>
                <p class="muted mb-0">No extensions available. <a href="/extensions/create">Create an extension</a> first.</p>
            <?php else: ?>
                <div class="form-grid">
                    <?php foreach ($extensions as $ext): ?>
                        <label class="check">
                            <input type="checkbox" name="members[]" value="<?= (int) $ext['id'] ?>"
                                <?= in_array((int) $ext['id'], $selected, true) ? 'checked' : '' ?>>
                            <span class="check-body">
                                <strong class="mono"><?= e($ext['extension']) ?></strong>
                                <span><?= e($ext['name']) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="hint mt-3 mb-0">Checked extensions will ring when this group is dialed.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <a href="/ring-groups" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $this->fragment('icons/check') ?>
                <?= $isNew ? 'Create ring group' : 'Save changes' ?>
            </button>
        </div>
    </div>
</form>
