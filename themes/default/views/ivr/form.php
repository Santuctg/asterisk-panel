<?php
/**
 * IVR menu create/edit form.
 * Expects: $row (with decoded 'keys'), $destinations, $greetings, $next
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
$selectedGreeting = (string) $val('greeting');
$knownGreetingPaths = array_map(static fn (array $greeting): string => (string) $greeting['value'], $greetings);

$renderKeyTypeOptions = static function (string $selected) use ($typeLabels): string {
    $html = '';
    foreach ($typeLabels as $type => $label) {
        $html .= '<option value="' . e($type) . '"' . ($selected === $type ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
};
$renderKeyDestination = static function (string $type, mixed $selected = '') use ($destinations): string {
    if ($type === 'external') {
        return '<input class="ivr-key-destination" type="text" name="key_dest[]" value="' . e((string) $selected)
            . '" placeholder="External number" pattern="\\+?[0-9]{2,20}" required style="width:240px">';
    }
    if ($type === 'hangup') {
        return '<input class="ivr-key-destination" type="hidden" name="key_dest[]" value="0"><span class="muted">No destination required</span>';
    }
    $html = '<select class="ivr-key-destination" name="key_dest[]" required style="width:240px"><option value="">Choose destination</option>';
    foreach ($destinations[$type] ?? [] as $destination) {
        $id = $destination['id'] ?? '';
        $html .= '<option value="' . e((string) $id) . '"' . ((string) $selected === (string) $id ? ' selected' : '') . '>'
            . e((string) ($destination['label'] ?? '')) . '</option>';
    }
    return $html . '</select>';
};
$renderKeyRow = static function (string $key, array $destination) use ($renderKeyTypeOptions, $renderKeyDestination): string {
    $type = (string) ($destination['type'] ?? 'extension');
    return '<div class="repeat-row ivr-key-row flex gap-2 items-center mb-2">'
        . '<input type="text" name="key[]" value="' . e($key) . '" placeholder="0-9, *, or #" maxlength="1" pattern="[0-9*#]" required style="width:90px">'
        . '<select class="ivr-key-type" name="key_type[]" style="width:190px">' . $renderKeyTypeOptions($type) . '</select>'
        . '<span class="ivr-key-destination-wrap">' . $renderKeyDestination($type, $destination['id'] ?? '') . '</span>'
        . '<button class="btn btn-sm btn-ghost" type="button" data-repeat-remove style="color:var(--danger-500)" aria-label="Remove key mapping">'
        . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>'
        . '</button></div>';
};
$newKeyRow = $renderKeyRow('', ['type' => 'extension', 'id' => '']);
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

                <div class="field span-2">
                    <label for="greeting">Greeting audio</label>
                    <div class="flex gap-2">
                        <select id="greeting" name="greeting" style="flex:1">
                            <option value="">No greeting (silence)</option>
                            <?php if ($selectedGreeting !== '' && !in_array($selectedGreeting, $knownGreetingPaths, true)): ?>
                                <option value="<?= e($selectedGreeting) ?>" selected>Existing greeting (legacy)</option>
                            <?php endif; ?>
                            <?php foreach ($greetings as $greeting): ?>
                                <option value="<?= e((string) $greeting['value']) ?>"
                                        data-audio="/announcements/<?= (int) $greeting['id'] ?>/audio"
                                        <?= $selectedGreeting === (string) $greeting['value'] ? 'selected' : '' ?>>
                                    <?= e((string) $greeting['label']) ?><?= !empty($greeting['original_filename']) ? ' — ' . e((string) $greeting['original_filename']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn" id="ivrGreetingPlay" title="Play selected greeting">▶ Play</button>
                        <a class="btn" href="/moh">＋ Add Audio</a>
                    </div>
                    <span class="hint">Upload prompts from Audio → Announcements, then select one here.</span>
                    <audio id="ivrGreetingPlayer" controls preload="none" hidden></audio>
                </div>

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
            <button class="btn btn-sm" type="button" data-repeat-add="#keyRows">
                <?= $this->fragment('icons/plus') ?> Add key
            </button>
        </div>
        <div class="card-body">
            <p class="muted text-sm">
                Map each key a caller can press by choosing its destination type and destination.
            </p>

            <div id="keyRows" data-repeat-template="<?= e($newKeyRow) ?>">
                <?php foreach ($keys as $k => $dest): ?>
                    <?= $renderKeyRow((string) $k, $dest) ?>
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

<script>
(function () {
    var catalog = <?= json_encode($destinations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var greeting = document.getElementById('greeting');
    var greetingPlay = document.getElementById('ivrGreetingPlay');
    var greetingPlayer = document.getElementById('ivrGreetingPlayer');

    if (greetingPlay && greeting && greetingPlayer) {
        greetingPlay.addEventListener('click', function () {
            var option = greeting.options[greeting.selectedIndex];
            var source = option && option.getAttribute('data-audio');
            if (!source) return;
            if (greetingPlayer.getAttribute('src') !== source) greetingPlayer.setAttribute('src', source);
            greetingPlayer.hidden = false;
            greetingPlayer.play();
        });
    }

    function destinationControl(type) {
        if (type === 'external') {
            var input = document.createElement('input');
            input.className = 'ivr-key-destination'; input.type = 'text'; input.name = 'key_dest[]';
            input.placeholder = 'External number'; input.pattern = '\\+?[0-9]{2,20}'; input.required = true; input.style.width = '240px';
            return input;
        }
        if (type === 'hangup') {
            var fragment = document.createDocumentFragment();
            var hidden = document.createElement('input');
            hidden.className = 'ivr-key-destination'; hidden.type = 'hidden'; hidden.name = 'key_dest[]'; hidden.value = '0';
            var label = document.createElement('span'); label.className = 'muted'; label.textContent = 'No destination required';
            fragment.appendChild(hidden); fragment.appendChild(label); return fragment;
        }
        var select = document.createElement('select');
        select.className = 'ivr-key-destination'; select.name = 'key_dest[]'; select.required = true; select.style.width = '240px';
        var placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Choose destination'; select.appendChild(placeholder);
        (catalog[type] || []).forEach(function (destination) {
            var option = document.createElement('option'); option.value = destination.id; option.textContent = destination.label; select.appendChild(option);
        });
        return select;
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('.ivr-key-type')) return;
        var row = event.target.closest('.ivr-key-row');
        var wrap = row && row.querySelector('.ivr-key-destination-wrap');
        if (wrap) { wrap.replaceChildren(destinationControl(event.target.value)); }
    });
})();
</script>
