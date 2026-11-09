import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const $ = selector => document.querySelector(selector);
const status = $('#listen-status');
const refreshStatus = $('#listen-refresh-status');
const stop = $('#listen-stop');
const audio = $('#listen-audio');
const extensionDialog = $('#listen-extension-dialog');
const targetLabel = $('#listen-target-label');
const listenerExtension = $('#listen-extension');
const callsBody = $('#listen-calls-body');
const callsTotal = $('#active-calls-total');
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const browserReady = $('#listen-browser-ready').value === '1';
const canHangup = $('#listen-can-hangup').value === '1';
let selected = null;
let ua = null;
let session = null;
let busy = false;
let refreshTimer = null;
let listenerMediaTimer = null;
const mediaConnections = new WeakSet();

function cleanup() {
    const currentSession = session;
    const currentUa = ua;
    session = null; ua = null;
    if (currentSession) { try { currentSession.terminate(); } catch (_) {} }
    if (currentUa) currentUa.stop();
    window.clearInterval(listenerMediaTimer); listenerMediaTimer = null;
    audio.pause(); audio.srcObject = null; audio.hidden = true; stop.hidden = true;
}
function closeDialogs() {
    extensionDialog.hidden = true;
    selected = null;
}
function playRemoteMedia(peerconnection, stream = null) {
    if (!peerconnection) return;
    const remote = stream || new MediaStream(peerconnection.getReceivers()
        .map(receiver => receiver.track).filter(track => track && track.kind === 'audio'));
    if (!remote.getAudioTracks().length) return;
    audio.srcObject = remote;
    audio.muted = false; audio.volume = 1;
    audio.hidden = false;
    audio.play().catch(() => {
        status.textContent = 'Listening connected. Click anywhere to hear audio.';
        const retry = () => {
            window.removeEventListener('pointerdown', retry);
            const started = audio.play();
            if (started && typeof started.then === 'function') {
                started.then(() => {
                    if (status.textContent === 'Listening connected. Click anywhere to hear audio.') {
                        status.textContent = 'Listening in browser. Use Stop to hang up.';
                    }
                }).catch(() => {});
            }
        };
        window.addEventListener('pointerdown', retry);
    });
}
function bindMedia(peerconnection) {
    if (!peerconnection) return;
    if (!mediaConnections.has(peerconnection)) {
        mediaConnections.add(peerconnection);
        peerconnection.addEventListener('track', event => {
            playRemoteMedia(peerconnection, event.streams[0] || new MediaStream([event.track]));
        });
    }
    playRemoteMedia(peerconnection);
}
function button(label, className, call) {
    const element = document.createElement('button');
    element.type = 'button'; element.className = className; element.textContent = label;
    element.dataset.channel = call.name; element.dataset.extension = call.listen_extension;
    element.disabled = !call.monitorable;
    return element;
}
function textElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = text;
    return element;
}
function renderCalls(calls) {
    callsBody.replaceChildren();
    callsTotal.textContent = String(calls.length);
    if (!calls.length) {
        const empty = document.createElement('div'); empty.className = 'empty dashboard-empty active-calls-empty';
        empty.append(textElement('span', 'active-call-empty-icon', '☎'), textElement('strong', '', 'No active calls'), textElement('p', '', 'Tenant calls will appear here automatically.'));
        callsBody.append(empty); return;
    }
    calls.forEach(call => {
        const card = document.createElement('article'); card.className = 'active-session-card';
        const head = document.createElement('div'); head.className = 'active-session-head';
        const identity = document.createElement('div');
        identity.append(textElement('strong', '', `Extension ${call.listen_extension}`), textElement('small', '', `${call.device} · ${call.direction}`));
        const state = textElement('span', `active-session-state${call.status === 'Connected' ? ' is-connected' : ''}`, call.status || 'Ringing');
        head.append(textElement('span', 'active-session-icon', '☎'), identity, state);
        const route = document.createElement('div'); route.className = 'active-session-route';
        const from = document.createElement('div');
        const to = document.createElement('div');
        const inbound = call.direction === 'Inbound';
        from.append(textElement('small', '', inbound ? 'From' : 'Extension'), textElement('strong', '', inbound ? call.remote : call.listen_extension));
        to.append(textElement('small', '', inbound ? 'Extension' : 'To'), textElement('strong', '', inbound ? call.listen_extension : call.remote));
        route.append(from, textElement('span', '', '→'), to);
        const meta = document.createElement('div'); meta.className = 'active-session-meta';
        const duration = document.createElement('span'); duration.append('Duration ', textElement('strong', 'mono', call.duration || '00:00:00'));
        meta.append(duration, textElement('span', '', call.device || 'Phone'));
        const actions = document.createElement('div'); actions.className = 'active-session-actions';
        if (browserReady) actions.append(button('Listen in browser', 'btn btn-primary listen-browser', call));
        actions.append(button('Listen on extension', 'btn listen-extension', call));
        if (canHangup) actions.append(button('End call', 'btn btn-danger active-call-hangup', {...call, monitorable: true}));
        card.append(head, route, meta, actions); callsBody.append(card);
    });
}
async function refreshCalls(announce = false) {
    try {
        const response = await fetch('/active-calls/feed', {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not refresh calls');
        renderCalls(Array.isArray(result.calls) ? result.calls : []);
        refreshStatus.textContent = announce ? 'Active calls refreshed.' : 'Calls update automatically.';
    } catch (error) {
        refreshStatus.textContent = error.message || 'Live update failed. Retrying…';
    } finally {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => refreshCalls(false), 3000);
    }
}
async function requestListen(channel, mode, listener = '') {
    const response = await fetch('/active-calls/listen', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: new URLSearchParams({_token: csrf, channel, mode, listener_extension: listener})
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Listen request failed');
    return result;
}
async function requestBrowserSession() {
    const response = await fetch('/active-calls/browser-session', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: new URLSearchParams({_token: csrf})
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Browser listener is unavailable');
    return result;
}
async function requestHangup(channel) {
    const response = await fetch('/active-calls/hangup', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: new URLSearchParams({_token: csrf, channel})
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Could not end the call');
    return result;
}

callsBody.addEventListener('click', event => {
    const browser = event.target.closest('.listen-browser');
    const extension = event.target.closest('.listen-extension');
    const hangup = event.target.closest('.active-call-hangup');
    const trigger = browser || extension || hangup;
    if (!trigger || busy) return;
    selected = {channel: trigger.dataset.channel, extension: trigger.dataset.extension};
    if (browser) {
        startBrowserListening(selected);
    } else if (hangup) {
        const target = selected;
        selected = null;
        if (!window.confirm(`End the active call for extension ${target.extension}?`)) return;
        busy = true; status.textContent = 'Ending call…';
        requestHangup(target.channel)
            .then(result => { status.textContent = result.message; refreshCalls(false); })
            .catch(error => { status.textContent = error.message || 'Could not end the call.'; })
            .finally(() => { busy = false; });
    } else {
        targetLabel.textContent = selected.extension;
        extensionDialog.hidden = false;
        listenerExtension.value = '';
    }
});

async function startBrowserListening(target) {
    if (busy || !target) return;
    if (!window.isSecureContext) { status.textContent = 'Secure browser listening requires HTTPS.'; return; }
    busy = true;
    selected = null;
    try {
        cleanup();
        const credential = await requestBrowserSession();
        const host = window.location.hostname;
        const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
        ua = new JsSIP.UA({
            sockets: [socket], uri: `sip:${credential.username}@${host}`, password: credential.password,
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
            listenerMediaTimer = window.setInterval(() => {
                if (session !== incoming) { window.clearInterval(listenerMediaTimer); listenerMediaTimer = null; return; }
                bindMedia(incoming.connection);
            }, 500);
            status.textContent = 'Listening in browser. Use Stop to hang up.';
        });
        status.textContent = 'Registering secure browser listener…';
        await new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error('Browser SIP registration timed out.')), 15000);
            ua.once('registered', () => { window.clearTimeout(timer); resolve(); });
            ua.once('registrationFailed', () => { window.clearTimeout(timer); reject(new Error('SIP registration rejected. Check the extension password.')); });
            ua.start();
        });
        const result = await requestListen(target.channel, 'browser');
        status.textContent = result.message; stop.hidden = false;
    } catch (error) {
        cleanup(); status.textContent = error.message || 'Listen failed.';
    } finally { busy = false; }
}

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
$('#listen-extension-cancel').addEventListener('click', closeDialogs);
$('#listen-refresh').addEventListener('click', () => refreshCalls(true));
window.addEventListener('pagehide', () => { window.clearTimeout(refreshTimer); cleanup(); });
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshCalls(false);
});
refreshCalls(false);
