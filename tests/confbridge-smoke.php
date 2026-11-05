<?php

declare(strict_types=1);

// Run: php tests/confbridge-smoke.php (uses only an in-memory SQLite DB).
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}

$tenant = $db->insert('tenants', [
    'name' => 'Conference Test', 'context' => 'conference_test', 'domain' => 'conference.test', 'enabled' => 1,
]);
$db->insert('conferences', [
    'tenant_id' => $tenant, 'extension' => '700', 'name' => 'Daily meeting', 'pin' => '1234',
    'max_members' => 12, 'moh_class' => 'default', 'record' => 1, 'enabled' => 1,
]);

$config = (new App\Services\Asterisk\ConfigGenerator())->renderConfBridge();
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};

$check(str_contains($config, "[conference_test-default]\ntype = bridge\n"), 'tenant bridge profile renders');
$check(str_contains($config, "[conference_test-users]\ntype = user\nannounce_user_count = yes\nannounce_join_leave = yes\nmarked = no\nend_marked = no\nmusic_on_hold_class = default\n"), 'Asterisk 20 user options use valid names');
$check(str_contains($config, "[1234]\ntype = bridge\nmax_members = 12\nrecord_conference = yes\n"), 'room bridge options use valid names');
$check(!preg_match('/^(?:sound-|announce-usercount|announce-joinleave|endmarked|record_call|music_on_hold_class\s*=).*$/m', preg_replace('/\[conference_test-users\].*?(?=\n\[|\z)/s', '', $config)), 'invalid and misplaced bridge options are absent');

echo "All ConfBridge smoke checks passed.\n";
