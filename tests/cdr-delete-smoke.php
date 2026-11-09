<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('APP_PRIMARY_HOST=panel.example.test');
$recordingDir = BASE_PATH . '/storage/cache/cdr-recording-smoke';
@mkdir($recordingDir, 0775, true);
foreach (glob($recordingDir . '/*') ?: [] as $file) { @unlink($file); }
file_put_contents($recordingDir . '/tenant-a.wav', 'RIFF-test');
file_put_contents($recordingDir . '/tenant-b.wav', 'RIFF-test');
file_put_contents($recordingDir . '/tenant-b.ogg', 'OggS-test');
file_put_contents($recordingDir . '/new-opus.wav', 'RIFF-fallback');
file_put_contents($recordingDir . '/new-opus.ogg', 'OggS-opus');
file_put_contents($recordingDir . '/pending-opus.wav', 'RIFF-pending');
file_put_contents($recordingDir . '/delete-both.wav', 'RIFF-delete');
file_put_contents($recordingDir . '/delete-both.ogg', 'OggS-delete');
putenv('ASTERISK_RECORDING_DIR=' . $recordingDir);
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }
$a = $db->insert('tenants', ['name'=>'A','context'=>'a','domain'=>'a.example.test','enabled'=>1]);
$b = $db->insert('tenants', ['name'=>'B','context'=>'b','domain'=>'b.example.test','enabled'=>1]);
$admin = $db->insert('users', [
    'tenant_id'=>$a,'username'=>'admin','password'=>password_hash('test-password', PASSWORD_DEFAULT),'role'=>'admin','active'=>1,
]);
$a1 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1001','dst'=>'1002','uniqueid'=>'a1']);
$a2 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1002','dst'=>'1003','uniqueid'=>'a2']);
$b1 = $db->insert('cdr', ['tenant_id'=>$b,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1001','dst'=>'1002','uniqueid'=>'b1','userfield'=>'recording:tenant-b']);
$_SERVER['HTTP_HOST']='a.example.test';
$app->auth()->login($admin);
$controller = new App\Controllers\CdrController();
$recordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1001','dst'=>'1002',
    'uniqueid'=>'recording-a','disposition'=>'ANSWERED','billsec'=>12,'userfield'=>'recording:tenant-a.wav',
]);
$unansweredRecordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1003','dst'=>'1004',
    'uniqueid'=>'recording-no-answer','disposition'=>'NO ANSWER','billsec'=>0,'userfield'=>'recording:tenant-a.wav',
]);
$missingRecordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1005','dst'=>'1006',
    'uniqueid'=>'recording-missing','disposition'=>'ANSWERED','billsec'=>8,'userfield'=>'recording:missing.wav',
]);
$opusRecordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1007','dst'=>'1008',
    'uniqueid'=>'recording-opus','disposition'=>'ANSWERED','billsec'=>20,'userfield'=>'recording:new-opus',
]);
$pendingRecordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1009','dst'=>'1010',
    'uniqueid'=>'recording-pending','disposition'=>'ANSWERED','billsec'=>18,'userfield'=>'recording:pending-opus',
]);
$deleteBothId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1011','dst'=>'1012',
    'uniqueid'=>'recording-delete-both','disposition'=>'ANSWERED','billsec'=>16,'userfield'=>'recording:delete-both',
]);
$unsafeRecordingId = $db->insert('cdr', [
    'tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1013','dst'=>'1014',
    'uniqueid'=>'recording-unsafe','disposition'=>'ANSWERED','billsec'=>14,'userfield'=>'recording:../tenant-b',
]);
$page = $controller->index(new App\Core\Request('GET', '/cdr'));
if (!str_contains($page->body(), 'Delete all history')) throw new RuntimeException('CDR delete controls missing');
if (!str_contains($page->body(), 'data-modal-open="recordingPlaybackModal"')) throw new RuntimeException('Recording play button missing');
if (!str_contains($page->body(), 'id="recordingPlaybackModal"')) throw new RuntimeException('Recording playback modal missing');
if (!str_contains($page->body(), 'data-recording-src="/cdr/'.$recordingId.'/recording?inline=1"')) throw new RuntimeException('Modal recording URL missing');
if (!str_contains($page->body(), 'data-recording-src="/cdr/'.$opusRecordingId.'/recording?inline=1"')
    || !str_contains($page->body(), 'data-recording-format="OPUS"')) throw new RuntimeException('Opus recording format missing from playback control');
if (!str_contains($page->body(), 'data-recording-src="/cdr/'.$pendingRecordingId.'/recording?inline=1"')
    || !str_contains($page->body(), 'data-recording-format="WAV"')) throw new RuntimeException('Pending WAV fallback missing from playback control');
if (!str_contains($page->body(), "download.textContent = 'Download ' +")) throw new RuntimeException('Recording modal download label is not format aware');
if (str_contains($page->body(), 'data-recording-src="/cdr/'.$unansweredRecordingId.'/recording?inline=1"')) throw new RuntimeException('Unanswered call exposed a play button');
if (str_contains($page->body(), 'data-recording-src="/cdr/'.$missingRecordingId.'/recording?inline=1"')) throw new RuntimeException('Missing recording file exposed a play button');
if (str_contains($page->body(), '<th class="num">Billable</th>')) throw new RuntimeException('Duplicate billable duration column remains');
$inlineRequest = new App\Core\Request('GET', '/cdr/'.$recordingId.'/recording', ['inline' => '1']);
$inlineRequest->params = ['id' => $recordingId];
$inline = $controller->recording($inlineRequest);
if ($inline->status() !== 200 || ($inline->headers()['Content-Disposition'] ?? '') !== 'inline; filename="tenant-a.wav"') throw new RuntimeException('Inline recording response is invalid');
if (($inline->headers()['X-Accel-Redirect'] ?? '') !== '/_protected/recordings/tenant-a.wav') throw new RuntimeException('Protected recording delegation missing');
$downloadRequest = new App\Core\Request('GET', '/cdr/'.$recordingId.'/recording');
$downloadRequest->params = ['id' => $recordingId];
$download = $controller->recording($downloadRequest);
if (($download->headers()['Content-Disposition'] ?? '') !== 'attachment; filename="tenant-a.wav"') throw new RuntimeException('Recording download response changed');
if (($download->headers()['Content-Type'] ?? '') !== 'audio/wav') throw new RuntimeException('Legacy WAV response MIME changed');
$opusRequest = new App\Core\Request('GET', '/cdr/'.$opusRecordingId.'/recording', ['inline' => '1']);
$opusRequest->params = ['id' => $opusRecordingId];
$opus = $controller->recording($opusRequest);
if ($opus->status() !== 200 || ($opus->headers()['Content-Type'] ?? '') !== 'audio/ogg'
    || ($opus->headers()['Content-Disposition'] ?? '') !== 'inline; filename="new-opus.ogg"'
    || ($opus->headers()['X-Accel-Redirect'] ?? '') !== '/_protected/recordings/new-opus.ogg') throw new RuntimeException('New marker did not prefer protected Ogg playback');
$pendingRequest = new App\Core\Request('GET', '/cdr/'.$pendingRecordingId.'/recording', ['inline' => '1']);
$pendingRequest->params = ['id' => $pendingRecordingId];
$pending = $controller->recording($pendingRequest);
if ($pending->status() !== 200 || ($pending->headers()['Content-Type'] ?? '') !== 'audio/wav'
    || ($pending->headers()['Content-Disposition'] ?? '') !== 'inline; filename="pending-opus.wav"') throw new RuntimeException('Pending conversion did not fall back to WAV');
$unsafeRequest = new App\Core\Request('GET', '/cdr/'.$unsafeRecordingId.'/recording', ['inline' => '1']);
$unsafeRequest->params = ['id' => $unsafeRecordingId];
if ($controller->recording($unsafeRequest)->status() !== 404) throw new RuntimeException('Unsafe recording marker was resolved');
$crossTenantRequest = new App\Core\Request('GET', '/cdr/'.$b1.'/recording', ['inline' => '1']);
$crossTenantRequest->params = ['id' => $b1];
if ($controller->recording($crossTenantRequest)->status() !== 404) throw new RuntimeException('Cross-tenant recording was exposed');
$request = new App\Core\Request('DELETE', '/cdr/'.$a1);
$request->params=['id'=>$a1];
$controller->destroy($request);
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id = ?', [$a1]) !== 0) throw new RuntimeException('Own CDR was not deleted');
$request = new App\Core\Request('DELETE', '/cdr/'.$b1); $request->params=['id'=>$b1];
$controller->destroy($request);
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id = ?', [$b1]) !== 1) throw new RuntimeException('Cross-tenant CDR was deleted');
if (!is_file($recordingDir . '/tenant-b.wav') || !is_file($recordingDir . '/tenant-b.ogg')) throw new RuntimeException('Cross-tenant recording files were deleted');
$deleteBothRequest = new App\Core\Request('DELETE', '/cdr/'.$deleteBothId); $deleteBothRequest->params=['id'=>$deleteBothId];
$controller->destroy($deleteBothRequest);
if (is_file($recordingDir . '/delete-both.wav') || is_file($recordingDir . '/delete-both.ogg')) throw new RuntimeException('Authorized new recording deletion did not remove both formats');
$controller->clear(new App\Core\Request('DELETE', '/cdr'));
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$a]) !== 0) throw new RuntimeException('Tenant CDR clear failed');
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$b]) !== 1) throw new RuntimeException('Tenant CDR clear crossed scope');

// Bulk delete: own records go, other tenants' records stay.
$m1 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'2001','dst'=>'2002','uniqueid'=>'m1']);
$m2 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'2002','dst'=>'2003','uniqueid'=>'m2']);
$m3 = $db->insert('cdr', ['tenant_id'=>$b,'calldate'=>date('Y-m-d H:i:s'),'src'=>'2001','dst'=>'2002','uniqueid'=>'m3']);
$page = $controller->index(new App\Core\Request('GET', '/cdr'));
if (!str_contains($page->body(), 'Delete selected')) throw new RuntimeException('CDR bulk delete button missing');
if (!str_contains($page->body(), 'data-select-all')) throw new RuntimeException('CDR select-all control missing');
$bulk = new App\Core\Request('POST', '/cdr/bulk-delete', [], ['ids' => [$m1, $m2, $m3, 'junk', 0, -5]]);
$controller->bulkDelete($bulk);
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id IN (?,?)', [$m1, $m2]) !== 0) throw new RuntimeException('Bulk delete removed no own records');
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id = ?', [$m3]) !== 1) throw new RuntimeException('Bulk delete crossed tenant scope');
// Empty / all-invalid selections must delete nothing.
$before = (int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$a]);
$controller->bulkDelete(new App\Core\Request('POST', '/cdr/bulk-delete', [], ['ids' => []]));
$controller->bulkDelete(new App\Core\Request('POST', '/cdr/bulk-delete', [], ['ids' => ['junk', 0]]));
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$a]) !== $before) throw new RuntimeException('Invalid bulk selection deleted records');
foreach (glob($recordingDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($recordingDir);
echo "All tenant-scoped CDR delete checks passed.\n";
