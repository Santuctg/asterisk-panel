<?php
/** @var array $rows @var array|null $selected @var array $tracks @var array $announcements @var array $announcementDestinations */
require dirname(__DIR__) . '/partials/fields.php';
$formatBytes = static fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
$formatDuration = static fn (float $seconds): string => $seconds > 0 ? sprintf('%d:%02d', (int) floor($seconds / 60), (int) round(fmod($seconds, 60))) : '—';
$announcementDestTypes = [
    'extension' => 'Extension', 'ringgroup' => 'Ring group', 'ivr' => 'IVR menu',
    'queue' => 'Call queue', 'conference' => 'Conference', 'parking' => 'Parking lot',
    'timecond' => 'Time condition', 'hangup' => 'Hang up',
];
?>

<div class="page-head">
    <div><h1>Music on Hold</h1><div class="subtitle">Create playlists and manage the audio callers hear while waiting</div></div>
    <div class="page-actions">
        <button class="btn" data-modal-open="announcementModal"><?= $this->fragment('icons/plus') ?> Announcement</button>
        <button class="btn btn-primary" data-modal-open="playlistModal"><?= $this->fragment('icons/plus') ?> New playlist</button>
    </div>
</div>

<div class="moh-manager">
    <section class="card moh-playlists">
        <div class="card-head"><div><h2>Playlists</h2><span class="text-xs muted"><?= count($rows) ?> class(es)</span></div></div>
        <div class="moh-playlist-list">
            <?php if (!$rows): ?><div class="moh-empty-small">No playlists yet.<br>Create one to upload music.</div><?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <a class="moh-playlist-item <?= $selected && (int) $selected['id'] === (int) $row['id'] ? 'active' : '' ?>" href="/moh?class=<?= (int) $row['id'] ?>">
                    <span class="moh-playlist-icon">♫</span><span><strong><?= e($row['name']) ?></strong><small><?= (int) $row['track_count'] ?> track(s)</small></span><span class="moh-chevron">›</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card moh-tracks">
        <?php if (!$selected): ?>
            <div class="moh-no-selection"><div class="moh-large-icon">♫</div><h2>Create your first playlist</h2><p class="muted">Each tenant has private playlists and audio tracks.</p><button class="btn btn-primary" data-modal-open="playlistModal">New playlist</button></div>
        <?php else: ?>
            <div class="card-head moh-track-head">
                <div><h2><?= e($selected['name']) ?></h2><span class="text-xs muted"><?= count($tracks) ?> track(s) · <?= $selected['random'] ? 'Random order' : 'Sequential order' ?></span></div>
                <div class="flex gap-2">
                    <button class="btn btn-sm" data-modal-open="playlistModal" data-edit='<?= e(json_encode($selected, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                    <form method="post" action="/moh/<?= (int) $selected['id'] ?>"><?= csrf() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-ghost moh-danger" data-confirm-btn="Delete this playlist and its managed tracks?"><?= $this->fragment('icons/trash') ?></button></form>
                </div>
            </div>
            <div class="moh-toolbar">
                <button class="btn btn-primary btn-sm" data-modal-open="uploadModal">↑ Upload audio</button>
                <button class="btn btn-sm" data-modal-open="recordModal">● Record</button>
                <button class="btn btn-sm" data-modal-open="speechModal">✦ AI Generated Speech</button>
            </div>
            <?php if (!$tracks): ?>
                <div class="moh-no-selection compact"><div class="moh-large-icon">♪</div><h3>No tracks in this playlist</h3><p class="muted">Upload audio, record from your browser, or generate speech.</p><button class="btn btn-primary btn-sm" data-modal-open="uploadModal">Upload audio</button></div>
            <?php else: ?>
                <div class="table-wrap"><table class="moh-track-table">
                    <thead><tr><th>Track</th><th>Duration</th><th>Size</th><th>Status</th><th class="actions">Actions</th></tr></thead>
                    <tbody><?php foreach ($tracks as $track): $audioUrl = '/moh/' . (int) $selected['id'] . '/tracks/' . rawurlencode($track['filename']); ?>
                        <tr class="<?= $track['enabled'] ? '' : 'moh-track-disabled' ?>">
                            <td><div class="moh-track-name"><span>♪</span><strong><?= e($track['filename']) ?></strong></div></td>
                            <td class="mono"><?= e($formatDuration((float) $track['duration'])) ?></td><td><?= e($formatBytes((int) $track['size'])) ?></td>
                            <td><span class="badge <?= $track['enabled'] ? 'green' : 'gray' ?>"><?= $track['enabled'] ? 'Enabled' : 'Disabled' ?></span></td>
                            <td class="actions moh-track-actions">
                                <button type="button" class="btn btn-sm" data-track-play="<?= e($audioUrl) ?>">▶ Play</button>
                                <form method="post" action="<?= e($audioUrl) ?>/toggle"><?= csrf() ?><button class="btn btn-sm"><?= $track['enabled'] ? 'Disable' : 'Enable' ?></button></form>
                                <a class="btn btn-sm" href="<?= e($audioUrl) ?>?download=1">Download</a>
                                <form method="post" action="<?= e($audioUrl) ?>"><?= csrf() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-ghost moh-danger" data-confirm-btn="Delete <?= e($track['filename']) ?>?"><?= $this->fragment('icons/trash') ?></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div><audio id="mohAudioPlayer" class="moh-audio-player" controls preload="none" hidden></audio>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<section class="card mt-4">
    <div class="card-head"><div><h2>Announcements</h2><span class="text-xs muted">Separate from Music on Hold; plays completely before routing or ringing</span></div><button class="btn btn-sm" data-modal-open="announcementModal">New announcement</button></div>
    <div class="card-body tight">
        <?php if (!$announcements): ?><div class="moh-empty-small">No announcements uploaded.</div><?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Name</th><th>Prompt</th><th>Default destination</th><th>Status</th><th class="actions">Actions</th></tr></thead><tbody>
            <?php foreach ($announcements as $announcement): ?><tr>
                <td><strong><?= e($announcement['name']) ?></strong></td><td><code class="text-xs"><?= e($announcement['prompt_label']) ?></code></td><td><?= e($announcement['dest_label'] ?? 'Hang up') ?></td>
                <td><span class="badge <?= $announcement['enabled'] ? 'green' : 'gray' ?>"><?= $announcement['enabled'] ? 'Active' : 'Disabled' ?></span></td>
                <td class="actions"><button type="button" class="btn btn-sm" data-announcement-play="/announcements/<?= (int) $announcement['id'] ?>/audio">▶ Play</button><a class="btn btn-sm" href="/announcements/<?= (int) $announcement['id'] ?>/audio?download=1">Download</a><button class="btn btn-sm" data-modal-open="announcementModal" data-edit='<?= e(json_encode($announcement, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button><form method="post" action="/announcements/<?= (int) $announcement['id'] ?>"><?= csrf() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-ghost moh-danger" data-confirm-btn="Delete this announcement?"><?= $this->fragment('icons/trash') ?></button></form></td>
            </tr><?php endforeach; ?></tbody></table></div>
            <audio id="announcementAudioPlayer" class="moh-audio-player" controls preload="none" hidden></audio>
        <?php endif; ?>
    </div>
</section>

<div class="modal-overlay" id="playlistModal" hidden><div class="modal"><form method="post" action="/moh" id="playlistForm"><?= csrf() ?>
    <div class="modal-head"><h2 id="playlistTitle">New playlist</h2><button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button></div>
    <div class="modal-body"><div class="form-grid"><?= field_text('name', 'Playlist / class name', '', ['required' => true, 'span' => true, 'placeholder' => 'e.g. sales_waiting', 'hint' => 'Letters, numbers, dashes and underscores only.']) ?><?= field_check('random', 'Randomize playback order', true) ?><?= field_check('shuffle', 'Shuffle tracks', false) ?></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary" id="playlistSubmit">Create playlist</button></div>
</form></div></div>

<?php if ($selected): ?>
<div class="modal-overlay" id="uploadModal" hidden><div class="modal"><form method="post" action="/moh/<?= (int) $selected['id'] ?>/tracks" enctype="multipart/form-data"><?= csrf() ?>
    <div class="modal-head"><h2>Upload audio</h2><button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button></div>
    <div class="modal-body"><div class="field"><label for="mohAudio">Audio tracks</label><input id="mohAudio" type="file" name="audio[]" multiple required accept="audio/*,.wav,.mp3,.ogg,.flac,.gsm,.ulaw,.alaw,.g722,.sln,.m4a,.mp4"><div class="hint">Maximum 25 MB each. Files are normalized to 8 kHz mono WAV.</div></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary">Upload tracks</button></div>
</form></div></div>

<div class="modal-overlay" id="recordModal" hidden><div class="modal"><form id="recordForm" data-url="/moh/<?= (int) $selected['id'] ?>/record"><?= csrf() ?>
    <div class="modal-head"><h2>Record audio</h2><button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button></div>
    <div class="modal-body"><?= field_text('name', 'Track name', 'Browser recording', ['required' => true]) ?><div class="moh-recorder"><span></span><strong id="recordStatus">Ready to record</strong><small id="recordTimer">00:00</small></div><div class="flex gap-2"><button type="button" class="btn btn-primary" id="recordStart">Start recording</button><button type="button" class="btn" id="recordStop" disabled>Stop</button></div><audio id="recordPreview" class="moh-audio-player" controls hidden></audio><div class="hint mt-3">Your browser will ask for microphone permission.</div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button type="button" class="btn btn-primary" id="recordSave" disabled>Save recording</button></div>
</form></div></div>

<div class="modal-overlay" id="speechModal" hidden><div class="modal"><form method="post" action="/moh/<?= (int) $selected['id'] ?>/speech"><?= csrf() ?>
    <div class="modal-head"><h2>AI Generated Speech</h2><button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button></div>
    <div class="modal-body"><div class="form-grid"><?= field_text('name', 'Track name', 'Generated speech', ['required' => true, 'span' => true]) ?><?= field_select('voice', 'Voice / language', ['en-us' => 'English (US)', 'en-gb' => 'English (UK)', 'bn' => 'বাংলা', 'hi' => 'हिन्दी'], 'en-us') ?><?= field_text('speed', 'Speech speed', '155', ['type' => 'number', 'min' => 80, 'max' => 300]) ?><div class="field span-2"><label for="speechText">Text to speak</label><textarea id="speechText" name="text" rows="6" maxlength="1500" required placeholder="Enter the message callers should hear..."></textarea><div class="hint">Up to 1,500 characters. Speech is generated locally on the PBX server.</div></div></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary">Generate speech</button></div>
</form></div></div>
<?php endif; ?>

<div class="modal-overlay" id="announcementModal" hidden><div class="modal"><form method="post" action="/announcements" id="announcementForm" enctype="multipart/form-data"><?= csrf() ?>
    <div class="modal-head"><h2 id="announcementTitle">New announcement</h2><button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button></div>
    <div class="modal-body"><div class="form-grid"><?= field_text('name', 'Announcement name (optional)', '', ['span' => true, 'placeholder' => 'Uses the prompt filename when left blank']) ?><div class="field span-2" id="announcementPromptField"><label for="announcementPrompt">Prompt</label><div class="flex gap-2"><span class="announcement-prompt-select-wrap"><select class="announcement-prompt-select" id="announcementPrompt" name="prompt_id"><option value="">Choose uploaded prompt</option><?php foreach ($announcements as $prompt): ?><option value="<?= (int) $prompt['id'] ?>" data-audio="/announcements/<?= (int) $prompt['id'] ?>/audio"><?= e($prompt['prompt_label']) ?></option><?php endforeach; ?></select></span><button type="button" class="btn" id="announcementPromptPlay" title="Play selected prompt">▶</button><button type="button" class="btn" id="announcementAddAudio">＋ Add Audio</button><input id="announcementAudio" type="file" name="audio" hidden accept="audio/*,.wav,.mp3,.ogg,.flac,.gsm,.ulaw,.alaw,.m4a,.mp4,.webm"></div><div class="hint" id="announcementAudioStatus">Choose an uploaded prompt or add a new audio file. Maximum 25 MB.</div><audio id="announcementPromptPlayer" class="announcement-prompt-player" controls preload="none" hidden></audio></div><?= field_select('dest_type', 'Default destination type', $announcementDestTypes, 'hangup', ['required' => true]) ?><?= field_select('dest_id', 'Default destination', $announcementDestinations, null, ['placeholder' => 'Choose destination']) ?></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary" id="announcementSubmit">Upload announcement</button></div>
</form></div></div>

<script>
(function () {
    'use strict';
    var playlistForm = document.getElementById('playlistForm');
    document.querySelectorAll('[data-modal-open="playlistModal"][data-edit]').forEach(function (button) { button.addEventListener('click', function () { var data = JSON.parse(button.getAttribute('data-edit')); playlistForm.action = '/moh/' + data.id; document.getElementById('playlistTitle').textContent = 'Edit playlist'; document.getElementById('playlistSubmit').textContent = 'Save changes'; playlistForm.elements.name.value = data.name || ''; playlistForm.elements.random.checked = Number(data.random) === 1; playlistForm.elements.shuffle.checked = Number(data.shuffle) === 1; }); });
    document.querySelectorAll('[data-modal-open="playlistModal"]:not([data-edit])').forEach(function (button) { button.addEventListener('click', function () { playlistForm.reset(); playlistForm.action = '/moh'; document.getElementById('playlistTitle').textContent = 'New playlist'; document.getElementById('playlistSubmit').textContent = 'Create playlist'; }); });

    var announcementForm = document.getElementById('announcementForm');
    var announcementPromptField = document.getElementById('announcementPromptField');
    var announcementPrompt = document.getElementById('announcementPrompt');
    var announcementAudio = document.getElementById('announcementAudio');
    var announcementAddAudio = document.getElementById('announcementAddAudio');
    var announcementAudioStatus = document.getElementById('announcementAudioStatus');
    var filterAnnouncementDestinations = function () { var type = announcementForm.elements.dest_type.value; var destination = announcementForm.elements.dest_id; Array.prototype.forEach.call(destination.querySelectorAll('optgroup'), function (group) { group.hidden = group.dataset.type !== type; Array.prototype.forEach.call(group.querySelectorAll('option'), function (option) { option.disabled = group.dataset.type !== type; }); }); destination.disabled = type === 'hangup'; if (type === 'hangup') destination.value = ''; };
    announcementForm.elements.dest_type.addEventListener('change', filterAnnouncementDestinations);
    document.querySelectorAll('[data-modal-open="announcementModal"][data-edit]').forEach(function (button) { button.addEventListener('click', function () { var data = JSON.parse(button.getAttribute('data-edit')); announcementForm.action = '/announcements/' + data.id; document.getElementById('announcementTitle').textContent = 'Edit announcement'; document.getElementById('announcementSubmit').textContent = 'Save changes'; announcementForm.elements.name.value = data.name || ''; announcementAudio.value = ''; announcementPrompt.value = data.id; announcementAudioStatus.textContent = data.prompt_label || 'Current uploaded prompt selected.'; announcementForm.elements.dest_type.value = data.dest_type || 'hangup'; filterAnnouncementDestinations(); announcementForm.elements.dest_id.value = data.dest_id || ''; }); });
    document.querySelectorAll('[data-modal-open="announcementModal"]:not([data-edit])').forEach(function (button) { button.addEventListener('click', function () { announcementForm.reset(); announcementForm.action = '/announcements'; document.getElementById('announcementTitle').textContent = 'New announcement'; document.getElementById('announcementSubmit').textContent = 'Save'; announcementAudioStatus.textContent = 'Choose an uploaded prompt or add a new audio file. Maximum 25 MB.'; announcementForm.elements.dest_type.value = 'hangup'; filterAnnouncementDestinations(); }); });
    filterAnnouncementDestinations();
    if (new URLSearchParams(window.location.search).get('add') === 'announcement') { var modal = document.getElementById('announcementModal'); if (modal) { modal.hidden = false; document.body.style.overflow = 'hidden'; } }

    var player = document.getElementById('mohAudioPlayer');
    document.querySelectorAll('[data-track-play]').forEach(function (button) { button.addEventListener('click', function () { if (!player) return; var url = button.getAttribute('data-track-play') + '?inline=1'; if (player.src && player.src.indexOf(url) !== -1 && !player.paused) { player.pause(); button.textContent = '▶ Play'; return; } document.querySelectorAll('[data-track-play]').forEach(function (item) { item.textContent = '▶ Play'; }); player.src = url; player.hidden = false; player.play().then(function () { button.textContent = '❚❚ Pause'; }).catch(function () {}); }); });
    if (player) player.addEventListener('ended', function () { document.querySelectorAll('[data-track-play]').forEach(function (item) { item.textContent = '▶ Play'; }); });

    var announcementPlayer = document.getElementById('announcementAudioPlayer');
    document.querySelectorAll('[data-announcement-play]').forEach(function (button) { button.addEventListener('click', function () { if (!announcementPlayer) return; var url = button.getAttribute('data-announcement-play'); if (announcementPlayer.src && announcementPlayer.src.indexOf(url) !== -1 && !announcementPlayer.paused) { announcementPlayer.pause(); button.textContent = '▶ Play'; return; } document.querySelectorAll('[data-announcement-play]').forEach(function (item) { item.textContent = '▶ Play'; }); announcementPlayer.src = url; announcementPlayer.hidden = false; announcementPlayer.play().then(function () { button.textContent = '❚❚ Pause'; }).catch(function () {}); }); });
    if (announcementPlayer) announcementPlayer.addEventListener('ended', function () { document.querySelectorAll('[data-announcement-play]').forEach(function (item) { item.textContent = '▶ Play'; }); });
    var announcementPromptPlay = document.getElementById('announcementPromptPlay');
    var announcementPromptPlayer = document.getElementById('announcementPromptPlayer');
    if (announcementPromptPlay) announcementPromptPlay.addEventListener('click', function () { if (!announcementPromptPlayer || !announcementPrompt) return; var option = announcementPrompt.options[announcementPrompt.selectedIndex]; var url = option ? option.getAttribute('data-audio') : ''; if (!url) return; if (announcementPromptPlayer.src && announcementPromptPlayer.src.indexOf(url) !== -1 && !announcementPromptPlayer.paused) { announcementPromptPlayer.pause(); announcementPromptPlay.textContent = '▶'; return; } announcementPromptPlayer.src = url; announcementPromptPlayer.hidden = false; announcementPromptPlayer.play().then(function () { announcementPromptPlay.textContent = '❚❚'; }); });
    if (announcementPromptPlayer) announcementPromptPlayer.addEventListener('ended', function () { announcementPromptPlay.textContent = '▶'; });
    if (announcementPrompt) announcementPrompt.addEventListener('change', function () { if (announcementPromptPlayer) { announcementPromptPlayer.pause(); announcementPromptPlayer.hidden = true; announcementPromptPlay.textContent = '▶'; } });
    var announcementModal = document.getElementById('announcementModal');
    if (announcementModal) announcementModal.querySelectorAll('[data-modal-close]').forEach(function (button) { button.addEventListener('click', function () { if (announcementPromptPlayer) { announcementPromptPlayer.pause(); announcementPromptPlay.textContent = '▶'; } }); });
    if (announcementAddAudio) announcementAddAudio.addEventListener('click', function () { announcementAudio.click(); });
    if (announcementAudio) announcementAudio.addEventListener('change', function () { if (announcementAudio.files[0]) { announcementAudioStatus.textContent = announcementAudio.files[0].name; if (announcementPromptPlayer) { announcementPromptPlayer.pause(); announcementPromptPlayer.hidden = true; announcementPromptPlay.textContent = '▶'; } } });

    var recordForm = document.getElementById('recordForm');
    if (recordForm) {
        var recorder = null, stream = null, chunks = [], blob = null, started = 0, timer = null;
        var start = document.getElementById('recordStart'), stop = document.getElementById('recordStop'), save = document.getElementById('recordSave'), status = document.getElementById('recordStatus'), timerText = document.getElementById('recordTimer'), preview = document.getElementById('recordPreview');
        var tick = function () { var elapsed = Math.floor((Date.now() - started) / 1000); timerText.textContent = String(Math.floor(elapsed / 60)).padStart(2, '0') + ':' + String(elapsed % 60).padStart(2, '0'); };
        start.addEventListener('click', function () { if (!navigator.mediaDevices || !window.MediaRecorder) { status.textContent = 'Recording is not supported by this browser.'; return; } navigator.mediaDevices.getUserMedia({audio:true}).then(function (mediaStream) { stream = mediaStream; chunks = []; blob = null; recorder = new MediaRecorder(stream); recorder.ondataavailable = function (event) { if (event.data.size) chunks.push(event.data); }; recorder.onstop = function () { blob = new Blob(chunks, {type:recorder.mimeType || 'audio/webm'}); preview.src = URL.createObjectURL(blob); preview.hidden = false; save.disabled = false; status.textContent = 'Recording ready'; stream.getTracks().forEach(function (track) { track.stop(); }); }; recorder.start(); started = Date.now(); tick(); timer = setInterval(tick, 500); status.textContent = 'Recording…'; start.disabled = true; stop.disabled = false; save.disabled = true; }).catch(function () { status.textContent = 'Microphone permission was denied.'; }); });
        stop.addEventListener('click', function () { if (recorder && recorder.state !== 'inactive') recorder.stop(); clearInterval(timer); start.disabled = false; stop.disabled = true; });
        save.addEventListener('click', function () { if (!blob) return; save.disabled = true; save.textContent = 'Saving…'; var data = new FormData(); data.append('_token', recordForm.elements._token.value); data.append('name', recordForm.elements.name.value || 'Browser recording'); data.append('audio', blob, 'browser-recording.webm'); fetch(recordForm.dataset.url, {method:'POST', body:data, headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}}).then(function (response) { return response.json().then(function (json) { if (!response.ok) throw new Error(json.error || 'Save failed.'); return json; }); }).then(function (json) { window.location.href = json.redirect; }).catch(function (error) { status.textContent = error.message; save.disabled = false; save.textContent = 'Save recording'; }); });
    }
})();
</script>
