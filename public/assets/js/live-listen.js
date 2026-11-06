import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const $ = selector => document.querySelector(selector);
const secret = $('#listen-secret');
const status = $('#listen-status');
const refreshStatus = $('#listen-refresh-status');
const stop = $('#listen-stop');
const audio = $('#listen-audio');
const credentials = $('#listen-credentials');
const extensionDialog = $('#listen-extension-dialog');
const extensionLabel = $('#listen-extension-label');
const targetLabel = $('#listen-target-label');
const listenerExtension = $('#listen-extension');
const callsBody = $('#listen-calls-body');
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const browserReady = $('#listen-browser-ready').value === '1';
let selected = null;
let ua = null;
let session = null;
let busy = false;
let refreshTimer = null;
const mediaConnections = new WeakSet();

function cleanup() {
    const currentSession = session;
    const currentUa = ua;
    session = null; ua = null;
    if (currentSession) { try { currentSession.terminate(); } catch (_) {} }
    if (currentUa) currentUa.stop();
    audio.pause(); audio.srcObject = null; audio.hidden = true; stop.hidden = true;
    secret.value = '';
}
function closeDialogs() {
    credentials.hidden = true;
    extensionDialog.hidden = true;
    secret.value = '';
    selected = null;
}
function playRemoteMedia(peerconnection, stream = null) {
    if (!peerconnection) return;
    const remote = stream || new MediaStream(peerconnection.getReceivers()
        .map(receiver => receiver.track).filter(track => track && track.kind === 'audio'));
    if (!remote.getAudioTracks().length) return;
    audio.srcObject = remote;
    audio.hidden = false;
    audio.play().catch(() => { status.textContent = 'Listening connected. Press Play below to hear audio.'; });
}
function bindMedia(peerconnection) {
    if (!peerconnection || mediaConnections.has(peerconnection)) return;
    mediaConnections.add(peerconnection);
    peerconnection.addEventListener('track', event => {
        playRemoteMedia(peerconnection, event.streams[0] || new MediaStream([event.track]));
    });
    playRemoteMedia(peerconnection);
}
function button(label, className, call) {
    const element = document.createElement('button');
    element.type = 'button'; element.className = className; element.textContent = label;
    element.dataset.channel = call.name; element.dataset.extension = call.listen_extension;
    return element;
}
function renderCalls(calls) {
    callsBody.replaceChildren();
    if (!calls.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 4; cell.textContent = 'No connected tenant extension calls found.';
        row.append(cell); callsBody.append(row); return;
    }
    calls.forEach(call => {
        const row = document.createElement('tr');
        const extension = document.createElement('td');
        const strong = document.createElement('strong'); strong.textContent = call.listen_extension;
        extension.append(strong);
        const caller = document.createElement('td'); caller.textContent = call.connected || call.caller || 'Unknown';
        const duration = document.createElement('td'); duration.className = 'mono'; duration.textContent = call.duration || '00:00:00';
        const actions = document.createElement('td');
        const wrap = document.createElement('div'); wrap.className = 'form-actions';
        if (browserReady) wrap.append(button('Browser', 'btn btn-primary listen-browser', call));
        wrap.append(button('Extension', 'btn listen-extension', call));
        actions.append(wrap); row.append(extension, caller, duration, actions); callsBody.append(row);
    });
}
async function refreshCalls(announce = false) {
    try {
        const response = await fetch('/live-listen/calls', {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not refresh calls');
        renderCalls(Array.isArray(result.calls) ? result.calls : []);
        refreshStatus.textContent = announce ? 'Connected calls refreshed.' : 'Calls update automatically.';
    } catch (error) {
        refreshStatus.textContent = error.message || 'Live update failed. Retrying…';
    } finally {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => refreshCalls(false), 3000);
    }
}
async function requestListen(channel, mode, listener = '') {
    const response = await fetch('/live-listen/start', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: new URLSearchParams({_token: csrf, channel, mode, listener_extension: listener})
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Listen request failed');
    return result;
}

callsBody.addEventListener('click', event => {
    const browser = event.target.closest('.listen-browser');
    const extension = event.target.closest('.listen-extension');
    const trigger = browser || extension;
    if (!trigger || busy) return;
    selected = {channel: trigger.dataset.channel, extension: trigger.dataset.extension};
    if (browser) {
        extensionLabel.textContent = selected.extension;
        extensionDialog.hidden = true; credentials.hidden = false; secret.focus();
    } else {
        targetLabel.textContent = selected.extension;
        credentials.hidden = true; extensionDialog.hidden = false;
        listenerExtension.value = '';
    }
});

$('#listen-connect').addEventListener('click', async () => {
    if (busy || !selected) return;
    if (!window.isSecureContext || !secret.value) { status.textContent = 'Enter the extension SIP password.'; return; }
    busy = true;
    const target = selected;
    const password = secret.value;
    try {
        cleanup();
        const host = window.location.hostname;
        const context = $('#listen-context').value;
        const username = `${context}_${target.extension}-web`;
        const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
        ua = new JsSIP.UA({
            sockets: [socket], uri: `sip:${username}@${host}`, password,
            register: true, register_expires: 120, session_timers: false
        });
        ua.on('newRTCSession', ({originator, session: incoming}) => {
            if (originator !== 'remote' || session) { incoming.terminate(); return; }
            session = incoming; stop.hidden = false;
            bindMedia(incoming.connection);
            incoming.on('peerconnection', ({peerconnection}) => bindMedia(peerconnection));
            incoming.on('confirmed', () => bindMedia(incoming.connection));
            incoming.on('ended', () => { if (session === incoming) { cleanup(); status.textContent = 'Call ended.'; } });
            incoming.on('failed', () => { if (session === incoming) { cleanup(); status.textContent = 'Browser listener failed.'; } });
            incoming.answer({mediaConstraints: {audio: false, video: false}});
            status.textContent = 'Listening in browser. Use Stop to hang up.';
        });
        status.textContent = 'Registering secure browser listener…';
        await new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error('Browser SIP registration timed out.')), 15000);
            ua.once('registered', () => { window.clearTimeout(timer); resolve(); });
            ua.once('registrationFailed', () => { window.clearTimeout(timer); reject(new Error('SIP registration rejected. Check the extension password.')); });
            ua.start();
        });
        closeDialogs();
        const result = await requestListen(target.channel, 'browser');
        status.textContent = result.message; stop.hidden = false;
    } catch (error) {
        cleanup(); status.textContent = error.message || 'Listen failed.';
    } finally { busy = false; }
});

$('#listen-extension-start').addEventListener('click', async () => {
    if (busy || !selected) return;
    if (!listenerExtension.value) { status.textContent = 'Select a listener extension.'; return; }
    busy = true;
    const target = selected;
    try {
        const result = await requestListen(target.channel, 'extension', listenerExtension.value);
        closeDialogs(); status.textContent = result.message;
    } catch (error) { status.textContent = error.message || 'Listen failed.'; }
    finally { busy = false; }
});

stop.addEventListener('click', () => { cleanup(); status.textContent = 'Browser listening stopped.'; });
$('#listen-cancel').addEventListener('click', closeDialogs);
$('#listen-extension-cancel').addEventListener('click', closeDialogs);
$('#listen-refresh').addEventListener('click', () => refreshCalls(true));
window.addEventListener('pagehide', () => { window.clearTimeout(refreshTimer); cleanup(); });
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshCalls(false);
});
refreshCalls(false);
