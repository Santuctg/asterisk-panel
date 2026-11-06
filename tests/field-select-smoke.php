<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';
require BASE_PATH . '/themes/default/views/partials/fields.php';

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};

$records = field_select('extension_id', 'Extension', [
    ['id' => 7, 'label' => '201 — Front desk'],
    ['id' => 9, 'label' => '202 — Accounts'],
], 9);
$check(str_contains($records, '<option value="7">201 — Front desk</option>')
    && str_contains($records, '<option value="9" selected>202 — Accounts</option>')
    && !str_contains($records, '<optgroup'), 'record-list options render labels and selected values');

$grouped = field_select('destination', 'Destination', [
    'extension' => [['id' => 7, 'label' => '201 — Front desk']],
    'queue' => [['id' => 3, 'label' => '600 — Sales']],
], 3);
$check(str_contains($grouped, '<optgroup label="Extension" data-type="extension">')
    && str_contains($grouped, '<option value="7">201 — Front desk</option>')
    && str_contains($grouped, '<option value="3" selected>600 — Sales</option>'), 'grouped record options retain destination groups');

$flat = field_select('vendor', 'Vendor', ['generic' => 'Generic', 'yealink' => 'Yealink'], 'generic');
$check(str_contains($flat, '<option value="generic" selected>Generic</option>')
    && str_contains($flat, '<option value="yealink">Yealink</option>'), 'flat associative options remain supported');

echo "All shared field select checks passed.\n";
