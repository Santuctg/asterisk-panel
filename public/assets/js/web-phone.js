import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const extension = document.querySelector('#web-phone-extension');
const secret = document.querySelector('#web-phone-secret');
const number = document.querySelector('#web-phone-number');
const status = document.querySelector('#web-phone-status');
const audio = document.querySelector('#web-phone-audio');
const start = document.querySelector('#web-phone-start');
const call = document.querySelector('#web-phone-call');
const hangup = document.querySelector('#web-phone-hangup');
const context = document.querySelector('#web-phone-context').value;
let ua = null;
let session = null;

function setStatus(message) { status.textContent = message; }

function attachMedia(current) {
    current.on('peerconnection', ({ peerconnection }) => {
        peerconnection.addEventListener('track', event => {
            audio.srcObject = event.streams[0] || new MediaStream([event.track]);
            audio.hidden = false;
            audio.play().catch(() => setStatus('Call connected. Press play to hear audio.'));
        });
    });
}

function endSession() {
    if (session) { try { session.terminate(); } catch (_) {} }
    session = null;
    hangup.disabled = true;
    call.disabled = !ua;
}

function setupSession(current, originator) {
    if (session) { current.terminate(); return; }
    session = current;
    attachMedia(current);
    current.on('ended', endSession);
    current.on('failed', event => { setStatus('Call failed: ' + (event.cause || 'unknown error')); endSession(); });
    if (originator === 'remote') {
        current.answer({ mediaConstraints: { audio: true, video: false } });
        setStatus('Incoming call connected.');
    } else {
        setStatus('Calling...');
    }
    hangup.disabled = false;
    call.disabled = true;
}

start.addEventListener('click', () => {
    if (!window.isSecureContext || !secret.value) { setStatus('HTTPS and the extension SIP password are required.'); return; }
    start.disabled = true;
    const username = `${context}_${extension.value}-phone-web`;
    ua = new JsSIP.UA({
        sockets: [new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`)],
        uri: `sip:${username}@${window.location.hostname}`,
        password: secret.value, register: true, register_expires: 120, session_timers: false,
    });
    secret.value = '';
    ua.on('newRTCSession', ({ originator, session: current }) => setupSession(current, originator));
    ua.on('registered', () => { call.disabled = false; setStatus(`Connected as ${extension.value}.`); });
    ua.on('registrationFailed', () => { setStatus('SIP registration failed. Check the extension password.'); start.disabled = false; ua = null; });
    setStatus('Connecting browser phone...');
    ua.start();
});

call.addEventListener('click', () => {
    const destination = number.value.trim();
    if (!ua || !destination || !/^[0-9*+#().-]{2,40}$/.test(destination)) { setStatus('Enter a valid number or extension.'); return; }
    setupSession(ua.call(`sip:${destination}@${window.location.hostname}`, {
        mediaConstraints: { audio: true, video: false }, sessionTimersExpires: 90,
    }), 'local');
});

hangup.addEventListener('click', endSession);
window.addEventListener('pagehide', () => { endSession(); if (ua) ua.stop(); });
