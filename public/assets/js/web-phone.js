import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const $ = selector => document.querySelector(selector);
const login = $('#web-phone-login');
const loginStatus = $('#web-phone-login-status');
const dialer = $('#web-phone-dialer');
const number = $('#web-phone-number');
const status = $('#web-phone-status');
const audio = $('#web-phone-audio');
const callButton = $('#web-phone-call');
const answerButton = $('#web-phone-answer');
const hangupButton = $('#web-phone-hangup');
const disconnectButton = $('#web-phone-disconnect');
const presence = $('#web-phone-presence');
const identity = $('#web-phone-identity');
const historyList = $('#web-phone-history');
const contactsList = $('#web-phone-contacts');
const search = $('#web-phone-search');
const listTitle = $('#web-phone-list-title');
const clock = $('#web-phone-clock');
const callPanel = $('#web-phone-call-panel');
const callParty = $('#web-phone-call-party');
const partyNumber = $('#web-phone-party-number');
const callTime = $('#web-phone-call-time');
const tools = $('#web-phone-in-call-tools');
const muteButton = $('#web-phone-mute');
const holdButton = $('#web-phone-hold');
const transferButton = $('#web-phone-transfer');
const dtmfButton = $('#web-phone-dtmf');
const speakerButton = $('#web-phone-speaker');
const transferBox = $('#web-phone-transfer-box');
const transferNumber = $('#web-phone-transfer-number');
const numberWrap = $('.webphone-number-wrap');
const passwordToggle = $('#web-phone-password-toggle');
const backspace = $('#web-phone-backspace');
const originalTitle = document.title;

let ua = null;
let session = null;
let realm = '';
let accountKey = '';
let contacts = [];
let history = [];
let currentView = 'dialpad';
let currentTarget = '';
let currentDirection = '';
let startedAt = null;
let confirmedAt = null;
let callTimer = null;
let muted = false;
let held = false;
let dtmfMode = false;
let requestedEndResult = '';
let mediaSyncTimer = null;
const mediaConnections = new WeakSet();

function setStatus(message) { status.textContent = message; }
function setPresence(message, online = false) {
    presence.textContent = message;
    presence.classList.toggle('is-online', online);
}
function formatTime(value) {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return 'Unknown time';
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();
    return new Intl.DateTimeFormat([], sameDay
        ? {hour: 'numeric', minute: '2-digit'}
        : {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}).format(date);
}
function formatDuration(seconds) {
    const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
    return `${minutes}:${(seconds % 60).toString().padStart(2, '0')}`;
}
function setClock() { clock.textContent = formatTime(new Date()); }
setClock();
window.setInterval(setClock, 30000);

function historyStorageKey() { return `panel-webphone-history:${accountKey}`; }
function loadHistory(serverHistory = []) {
    let localHistory = [];
    try { localHistory = JSON.parse(localStorage.getItem(historyStorageKey()) || '[]'); }
    catch (_) { localHistory = []; }
    if (!Array.isArray(localHistory)) localHistory = [];
    const unique = new Map();
    [...serverHistory, ...localHistory].forEach(item => {
        const key = item.id || `${item.direction}:${item.number}:${item.time}`;
        if (!unique.has(key)) unique.set(key, item);
    });
    history = [...unique.values()].sort((left, right) => new Date(right.time) - new Date(left.time)).slice(0, 100);
    renderList();
}
function saveHistory(entry) {
    entry.id = entry.id || `local:${Date.now()}:${entry.direction}:${entry.number}`;
    history.unshift(entry);
    history = history.slice(0, 100);
    try { localStorage.setItem(historyStorageKey(), JSON.stringify(history)); } catch (_) {}
    renderList();
}
function listRow(primary, secondary, numberValue, icon = '☎') {
    const row = document.createElement('button');
    row.type = 'button'; row.className = 'webphone-history-row'; row.dataset.number = numberValue;
    const avatar = document.createElement('span'); avatar.textContent = icon;
    const detail = document.createElement('div');
    const strong = document.createElement('strong'); strong.textContent = primary || numberValue || 'Unknown';
    const small = document.createElement('small'); small.textContent = secondary;
    detail.append(strong, small); row.append(avatar, detail);
    row.addEventListener('click', () => {
        number.value = numberValue;
        switchView('dialpad');
        number.focus();
    });
    return row;
}
function renderList() {
    const query = search.value.trim().toLowerCase();
    const target = currentView === 'contacts' ? contactsList : historyList;
    target.replaceChildren();
    const rows = currentView === 'contacts'
        ? contacts.filter(item => `${item.name} ${item.extension}`.toLowerCase().includes(query))
        : history.filter(item => `${item.number} ${item.result} ${item.direction}`.toLowerCase().includes(query));
    rows.forEach(item => {
        if (currentView === 'contacts') {
            target.appendChild(listRow(item.name, `Extension ${item.extension}`, item.extension, '♙'));
        } else {
            const when = new Date(item.time);
            const direction = item.direction === 'incoming' ? 'Incoming' : 'Outgoing';
            const duration = item.duration ? ` · ${formatDuration(Number(item.duration))}` : '';
            target.appendChild(listRow(item.number, `${direction} · ${item.result}${duration} · ${formatTime(when)}`, item.number));
        }
    });
    if (!rows.length) {
        const empty = document.createElement('div'); empty.className = 'webphone-history-empty';
        empty.textContent = currentView === 'contacts' ? (ua ? 'No contacts found' : 'Sign in to view contacts') : 'No call history';
        target.appendChild(empty);
    }
}
function switchView(view) {
    currentView = view;
    $('.webphone-app').dataset.view = view;
    document.querySelectorAll('[data-phone-view]').forEach(button => button.classList.toggle('is-active', button.dataset.phoneView === view));
    historyList.hidden = view === 'contacts';
    contactsList.hidden = view !== 'contacts';
    listTitle.textContent = view === 'contacts' ? 'Contacts' : 'Calls';
    search.placeholder = view === 'contacts' ? 'Search contacts' : 'Search calls';
    search.value = '';
    renderList();
}
document.querySelectorAll('[data-phone-view]').forEach(button => button.addEventListener('click', () => switchView(button.dataset.phoneView)));
search.addEventListener('input', renderList);

function playRemoteMedia(peerconnection, stream = null) {
    if (!peerconnection) return;
    const remote = stream || new MediaStream(peerconnection.getReceivers()
        .map(receiver => receiver.track).filter(track => track && track.kind === 'audio'));
    if (!remote.getAudioTracks().length) return;
    audio.srcObject = remote;
    audio.muted = false; audio.volume = 1;
    audio.hidden = false;
    audio.play().catch(() => setStatus('Call connected. Press Play below to hear audio.'));
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
function attachMedia(current) {
    bindMedia(current.connection);
    current.on('peerconnection', ({peerconnection}) => bindMedia(peerconnection));
    window.clearInterval(mediaSyncTimer);
    mediaSyncTimer = window.setInterval(() => {
        if (session !== current) { window.clearInterval(mediaSyncTimer); mediaSyncTimer = null; return; }
        bindMedia(current.connection);
    }, 500);
}
function updateTimer() {
    if (!confirmedAt) return;
    callTime.textContent = formatDuration(Math.max(0, Math.floor((Date.now() - confirmedAt) / 1000)));
}
function showCallState(active) {
    callPanel.classList.toggle('has-call', active);
    callParty.hidden = !active;
    tools.hidden = true;
    numberWrap.hidden = active;
    callButton.hidden = active;
    hangupButton.hidden = !active;
    hangupButton.disabled = !active;
    transferBox.hidden = true;
    $('#web-phone-keypad').hidden = active;
    if (!active) {
        answerButton.hidden = true;
        muted = false; held = false; dtmfMode = false;
        muteButton.classList.remove('is-active'); holdButton.classList.remove('is-active'); dtmfButton.classList.remove('is-active');
        speakerButton.classList.remove('is-active'); speakerButton.querySelector('small').textContent = 'Speaker';
        muteButton.querySelector('small').textContent = 'Mute'; holdButton.querySelector('small').textContent = 'Hold';
        transferNumber.value = '';
        $('#web-phone-keypad').hidden = false;
    }
}
function finalizeSession(current, result) {
    if (current && session !== current) return;
    if (!session && !currentTarget) return;
    const target = currentTarget;
    const direction = currentDirection;
    const began = startedAt || new Date();
    const duration = confirmedAt ? Math.max(0, Math.floor((Date.now() - confirmedAt) / 1000)) : 0;
    session = null; currentTarget = ''; currentDirection = ''; startedAt = null; confirmedAt = null; requestedEndResult = '';
    document.title = originalTitle;
    window.clearInterval(callTimer); callTimer = null;
    window.clearInterval(mediaSyncTimer); mediaSyncTimer = null;
    audio.pause(); audio.srcObject = null; audio.hidden = true;
    showCallState(false);
    callButton.disabled = !ua;
    setStatus(ua ? 'Ready to call.' : 'Disconnected.');
    if (target) saveHistory({number: target, direction, result, duration, time: began.toISOString()});
}
function setupSession(current, originator) {
    if (session) { current.terminate(); return; }
    session = current;
    currentDirection = originator === 'remote' ? 'incoming' : 'outgoing';
    currentTarget = originator === 'remote'
        ? String(current.remote_identity?.uri?.user || 'Unknown')
        : number.value.trim();
    startedAt = new Date();
    if (originator === 'remote') {
        switchView('dialpad');
        document.title = `Incoming: ${currentTarget}`;
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Incoming Web Phone call', {body: currentTarget, tag: 'web-phone-incoming'}); } catch (_) {}
        }
    }
    partyNumber.textContent = originator === 'remote' && current.remote_identity?.display_name
        ? `${current.remote_identity.display_name} · ${currentTarget}` : currentTarget;
    callTime.textContent = originator === 'remote' ? 'Incoming call' : 'Calling…';
    attachMedia(current);
    current.on('confirmed', () => {
        bindMedia(current.connection);
        confirmedAt = Date.now(); answerButton.hidden = true;
        tools.hidden = false;
        setStatus(`Connected with ${currentTarget}.`); updateTimer();
        callTimer = window.setInterval(updateTimer, 1000);
    });
    current.on('ended', () => finalizeSession(current, requestedEndResult || 'Completed'));
    current.on('failed', event => finalizeSession(current, event.cause || (originator === 'remote' ? 'Missed' : 'Failed')));
    showCallState(true);
    callButton.disabled = true;
    if (originator === 'remote') {
        answerButton.hidden = false;
        setStatus(`Incoming call from ${currentTarget}.`);
    } else {
        answerButton.hidden = true;
        setStatus(`Calling ${currentTarget}…`);
    }
}
function startPhone(config, password) {
    realm = config.realm;
    accountKey = `${config.realm}:${config.extension}`;
    contacts = Array.isArray(config.contacts) ? config.contacts : [];
    loadHistory(Array.isArray(config.history) ? config.history : []); renderList();
    const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
    ua = new JsSIP.UA({
        sockets: [socket], uri: `sip:${config.username}@${config.realm}`,
        password, register: true, register_expires: 120, session_timers: false,
    });
    ua.on('newRTCSession', ({originator, session: current}) => setupSession(current, originator));
    identity.textContent = `${config.extension} · ${config.name}`;
    setStatus('Connecting browser phone…');
    return new Promise((resolve, reject) => {
        let settled = false;
        const timer = window.setTimeout(() => {
            if (settled) return;
            settled = true; ua?.stop(); ua = null;
            reject(new Error('SIP registration timed out. Check WebRTC/WSS configuration.'));
        }, 15000);
        ua.on('registered', () => {
            callButton.disabled = false; setPresence('Online', true); setStatus('Ready to call.');
            if (settled) return;
            settled = true; window.clearTimeout(timer);
            login.hidden = true; dialer.hidden = false; $('.webphone-app').classList.remove('is-locked');
            switchView('dialpad');
            resolve();
        });
        ua.on('unregistered', () => { setPresence('Offline'); callButton.disabled = true; });
        ua.on('registrationFailed', event => {
            setPresence('Offline'); callButton.disabled = true;
            if (settled) { setStatus('SIP registration lost. Sign out and log in again.'); return; }
            settled = true; window.clearTimeout(timer); ua?.stop(); ua = null;
            reject(new Error(event.cause || 'SIP registration failed. Apply PBX configuration and check the SIP password.'));
        });
        ua.start();
    });
}

async function requestPhonePermissions() {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('This browser cannot access the microphone.');
    const notificationRequest = typeof Notification !== 'undefined' && Notification.permission === 'default'
        ? Notification.requestPermission().catch(() => 'denied') : Promise.resolve('unavailable');
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}, video: false});
    } catch (_) {
        throw new Error('Microphone permission is required. Allow microphone access, then log in again.');
    }
    stream.getTracks().forEach(track => track.stop());
    await notificationRequest;
}

login.addEventListener('submit', async event => {
    event.preventDefault(); loginStatus.textContent = '';
    if (!window.isSecureContext) { loginStatus.textContent = 'HTTPS is required for Web Phone.'; return; }
    const submit = login.querySelector('[type="submit"]');
    const data = new FormData(login); const password = String(data.get('secret') || '');
    submit.disabled = true; submit.textContent = 'Connecting…';
    try {
        loginStatus.textContent = 'Requesting browser permissions…';
        await requestPhonePermissions();
        loginStatus.textContent = 'Checking SIP credentials…';
        const response = await fetch('/web-phone/connect', {method: 'POST', body: data, headers: {Accept: 'application/json'}});
        const config = await response.json();
        if (!response.ok) throw new Error(config.error || 'Unable to sign in.');
        login.querySelector('[name="secret"]').value = '';
        await startPhone(config, password);
    } catch (error) {
        loginStatus.textContent = error.message;
    } finally {
        submit.disabled = false; submit.textContent = 'Log in';
    }
});

document.querySelectorAll('#web-phone-keypad [data-key]').forEach(button => {
    button.addEventListener('click', () => {
        const key = button.dataset.key;
        if (session && confirmedAt && dtmfMode) {
            try { session.sendDTMF(key, {duration: 160, interToneGap: 70}); setStatus(`DTMF ${key} sent.`); } catch (_) { setStatus('Unable to send DTMF.'); }
        } else if (!session) {
            number.value += key; number.focus();
        }
    });
});
passwordToggle.addEventListener('click', () => {
    const show = login.querySelector('[name="secret"]').type === 'password';
    login.querySelector('[name="secret"]').type = show ? 'text' : 'password';
    passwordToggle.title = show ? 'Hide password' : 'Show password';
    passwordToggle.setAttribute('aria-label', passwordToggle.title);
});
backspace.addEventListener('click', () => { number.value = number.value.slice(0, -1); number.focus(); });
number.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); callButton.click(); } });
callButton.addEventListener('click', () => {
    const destination = number.value.trim().replace(/[\s().-]/g, '');
    if (!ua || !destination || !/^[0-9*+#().-]{2,40}$/.test(destination)) { setStatus('Enter a valid number or extension.'); return; }
    number.value = destination;
    // JsSIP emits newRTCSession synchronously for local calls; that event is the
    // single owner of setupSession. Setting it up again here terminates it as a duplicate.
    ua.call(`sip:${destination}@${realm}`, {mediaConstraints: {audio: true, video: false}, sessionTimersExpires: 90});
});
answerButton.addEventListener('click', () => {
    if (!session) return;
    session.answer({mediaConstraints: {audio: true, video: false}}); answerButton.hidden = true;
});
hangupButton.addEventListener('click', () => {
    if (!session) return;
    const current = session;
    requestedEndResult = confirmedAt ? 'Ended' : (currentDirection === 'incoming' ? 'Rejected' : 'Cancelled');
    try { current.terminate(); } catch (_) { finalizeSession(current, requestedEndResult); }
});
muteButton.addEventListener('click', () => {
    if (!session?.connection) return;
    muted = !muted;
    session.connection.getSenders().filter(sender => sender.track?.kind === 'audio').forEach(sender => { sender.track.enabled = !muted; });
    muteButton.classList.toggle('is-active', muted); muteButton.querySelector('small').textContent = muted ? 'Unmute' : 'Mute';
    setStatus(muted ? 'Microphone muted.' : 'Microphone unmuted.');
});
holdButton.addEventListener('click', () => {
    if (!session || !confirmedAt) return;
    const done = () => {
        held = !held; holdButton.classList.toggle('is-active', held);
        holdButton.querySelector('small').textContent = held ? 'Resume' : 'Hold'; setStatus(held ? 'Call on hold.' : 'Call resumed.');
    };
    try { held ? session.unhold({}, done) : session.hold({}, done); } catch (_) { setStatus('Unable to change hold state.'); }
});
dtmfButton.addEventListener('click', () => {
    dtmfMode = !dtmfMode; dtmfButton.classList.toggle('is-active', dtmfMode);
    $('#web-phone-keypad').hidden = !dtmfMode; transferBox.hidden = true;
});
speakerButton.addEventListener('click', () => {
    if (!session) return;
    audio.muted = false; audio.volume = 1;
    bindMedia(session.connection);
    audio.play().then(() => {
        speakerButton.classList.add('is-active');
        speakerButton.querySelector('small').textContent = 'Playing';
        setStatus(`Playing audio from ${currentTarget}.`);
    }).catch(() => setStatus('Remote audio is not available yet. Try Speaker again.'));
});
transferButton.addEventListener('click', () => { transferBox.hidden = !transferBox.hidden; transferNumber.focus(); });
$('#web-phone-transfer-send').addEventListener('click', () => {
    const target = transferNumber.value.trim();
    if (!session || !confirmedAt || !/^[0-9*+#().-]{2,40}$/.test(target)) { setStatus('Enter a valid transfer destination.'); return; }
    try {
        session.refer(`sip:${target}@${realm}`);
        setStatus(`Transferring call to ${target}…`); transferBox.hidden = true; transferNumber.value = '';
    } catch (_) { setStatus('Call transfer failed.'); }
});
disconnectButton.addEventListener('click', () => {
    const current = session;
    if (current) { try { current.terminate(); } catch (_) { finalizeSession(current, 'Ended'); } }
    if (ua) { ua.stop(); ua = null; }
    setPresence('Offline'); dialer.hidden = true; login.hidden = false; number.value = ''; contacts = [];
    $('.webphone-app').classList.add('is-locked');
    callButton.disabled = true; finalizeSession(session, 'Ended'); setStatus('Disconnected.'); renderList();
});
window.addEventListener('pagehide', () => { if (session) session.terminate(); if (ua) ua.stop(); });
