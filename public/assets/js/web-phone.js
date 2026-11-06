import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const login = document.querySelector('#web-phone-login');
const loginStatus = document.querySelector('#web-phone-login-status');
const dialer = document.querySelector('#web-phone-dialer');
const number = document.querySelector('#web-phone-number');
const status = document.querySelector('#web-phone-status');
const audio = document.querySelector('#web-phone-audio');
const call = document.querySelector('#web-phone-call');
const answer = document.querySelector('#web-phone-answer');
const hangup = document.querySelector('#web-phone-hangup');
const disconnect = document.querySelector('#web-phone-disconnect');
const presence = document.querySelector('#web-phone-presence');
const identity = document.querySelector('#web-phone-identity');
const history = document.querySelector('#web-phone-history');
const clock = document.querySelector('#web-phone-clock');
let ua = null;
let session = null;
let currentTarget = '';
let realm = '';

function setStatus(message) { status.textContent = message; }
function setPresence(message, online = false) {
    presence.textContent = message;
    presence.classList.toggle('is-online', online);
}
function setClock() {
    clock.textContent = new Intl.DateTimeFormat([], {hour:'numeric', minute:'2-digit'}).format(new Date());
}
setClock(); window.setInterval(setClock, 30000);

function addHistory(target, result) {
    const empty = history.querySelector('.webphone-history-empty');
    if (empty) empty.remove();
    const row = document.createElement('div');
    row.className = 'webphone-history-row';
    const avatar = document.createElement('span'); avatar.textContent = '☎';
    const detail = document.createElement('div');
    const strong = document.createElement('strong'); strong.textContent = target || 'Unknown';
    const small = document.createElement('small'); small.textContent = `${result} · ${new Intl.DateTimeFormat([], {hour:'numeric',minute:'2-digit'}).format(new Date())}`;
    detail.append(strong, small); row.append(avatar, detail); history.prepend(row);
}

function attachMedia(current) {
    current.on('peerconnection', ({peerconnection}) => {
        peerconnection.addEventListener('track', event => {
            audio.srcObject = event.streams[0] || new MediaStream([event.track]);
            audio.play().catch(() => setStatus('Call connected. Press browser play to hear audio.'));
        });
    });
}

function clearSession(result = 'Ended') {
    if (session && !session.isEnded()) { try { session.terminate(); } catch (_) {} }
    if (currentTarget) addHistory(currentTarget, result);
    session = null; currentTarget = '';
    answer.hidden = true; hangup.disabled = true; call.disabled = !ua;
    setStatus(ua ? 'Ready to call.' : 'Disconnected.');
}

function setupSession(current, originator) {
    if (session) { current.terminate(); return; }
    session = current;
    attachMedia(current);
    currentTarget = originator === 'remote'
        ? String(current.remote_identity && current.remote_identity.uri && current.remote_identity.uri.user || 'Incoming call')
        : number.value.trim();
    current.on('confirmed', () => { answer.hidden = true; setStatus(`Connected with ${currentTarget}.`); });
    current.on('ended', () => clearSession('Completed'));
    current.on('failed', event => clearSession(event.cause || 'Failed'));
    hangup.disabled = false; call.disabled = true;
    if (originator === 'remote') {
        number.value = currentTarget;
        answer.hidden = false;
        setStatus(`Incoming call from ${currentTarget}.`);
    } else {
        setStatus(`Calling ${currentTarget}…`);
    }
}

function startPhone(config, password) {
    realm = config.realm;
    const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
    ua = new JsSIP.UA({
        sockets: [socket], uri: `sip:${config.username}@${config.realm}`,
        password, register: true, register_expires: 120, session_timers: false,
    });
    ua.on('newRTCSession', ({originator, session: current}) => setupSession(current, originator));
    ua.on('registered', () => { call.disabled = false; setPresence('Online', true); setStatus('Ready to call.'); });
    ua.on('unregistered', () => setPresence('Offline'));
    ua.on('registrationFailed', () => {
        setStatus('SIP registration failed. Check the extension password.');
        setPresence('Offline'); call.disabled = true;
    });
    identity.textContent = `${config.extension} · ${config.name}`;
    login.hidden = true; dialer.hidden = false;
    setStatus('Connecting browser phone…'); ua.start();
}

login.addEventListener('submit', async event => {
    event.preventDefault(); loginStatus.textContent = '';
    if (!window.isSecureContext) { loginStatus.textContent = 'HTTPS is required for Web Phone.'; return; }
    const submit = login.querySelector('[type="submit"]');
    const data = new FormData(login); const password = String(data.get('secret') || '');
    submit.disabled = true; submit.textContent = 'Connecting…';
    try {
        const response = await fetch('/web-phone/connect', {method:'POST', body:data, headers:{Accept:'application/json'}});
        const config = await response.json();
        if (!response.ok) throw new Error(config.error || 'Unable to sign in.');
        login.querySelector('[name="secret"]').value = '';
        startPhone(config, password);
    } catch (error) {
        loginStatus.textContent = error.message;
    } finally {
        submit.disabled = false; submit.textContent = 'Connect';
    }
});

document.querySelectorAll('#web-phone-keypad [data-key]').forEach(button => {
    button.addEventListener('click', () => { number.value += button.dataset.key; number.focus(); });
});
call.addEventListener('click', () => {
    const destination = number.value.trim();
    if (!ua || !destination || !/^[0-9*+#().-]{2,40}$/.test(destination)) { setStatus('Enter a valid number or extension.'); return; }
    setupSession(ua.call(`sip:${destination}@${realm}`, {
        mediaConstraints:{audio:true,video:false}, sessionTimersExpires:90,
    }), 'local');
});
answer.addEventListener('click', () => {
    if (!session) return;
    session.answer({mediaConstraints:{audio:true,video:false}}); answer.hidden = true;
});
hangup.addEventListener('click', () => clearSession('Ended'));
disconnect.addEventListener('click', () => {
    clearSession(); if (ua) { ua.stop(); ua = null; }
    setPresence('Offline'); dialer.hidden = true; login.hidden = false; number.value = '';
});
window.addEventListener('pagehide', () => { if (session) clearSession(); if (ua) ua.stop(); });
