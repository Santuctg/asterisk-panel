import JsSIP from '../vendor/jssip-3.13.9.mjs?v=2';

const secret = document.querySelector('#listen-secret');
const status = document.querySelector('#listen-status');
const stop = document.querySelector('#listen-stop');
const audio = document.querySelector('#listen-audio');
const credentials = document.querySelector('#listen-credentials');
const extensionLabel = document.querySelector('#listen-extension-label');
const connect = document.querySelector('#listen-connect');
const cancel = document.querySelector('#listen-cancel');
let selected = null;
let ua = null;
let session = null;
let busy = false;

function cleanup() {
    const currentSession = session;
    const currentUa = ua;
    session = null; ua = null;
    if (currentSession) { try { currentSession.terminate(); } catch (_) {} }
    if (currentUa) currentUa.stop();
    audio.srcObject = null; audio.hidden = true; stop.hidden = true;
    secret.value = '';
}
function closeCredentials() { credentials.hidden = true; secret.value = ''; selected = null; }
stop.addEventListener('click', () => { cleanup(); status.textContent = 'Browser listening stopped.'; });
cancel.addEventListener('click', closeCredentials);
window.addEventListener('pagehide', cleanup);

async function requestListen(channel) {
    const response = await fetch('/live-listen/start', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: new URLSearchParams({
            _token: document.querySelector('meta[name="csrf-token"]').content,
            channel
        })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Listen request failed');
    return result;
}

document.querySelectorAll('.listen-browser').forEach(button => button.addEventListener('click', () => {
    if (busy) return;
    selected = button;
    extensionLabel.textContent = button.dataset.extension;
    credentials.hidden = false;
    secret.focus();
}));

connect.addEventListener('click', async () => {
    if (busy || !selected) return;
    if (!window.isSecureContext || !secret.value) {
        status.textContent = 'Enter the extension SIP password.';
        return;
    }
    busy = true;
    const button = selected;
    const password = secret.value;
    const extension = button.dataset.extension;
    try {
        cleanup();
        const host = window.location.hostname;
        const context = document.querySelector('#listen-context').value;
        const username = `${context}_${extension}-web`;
        const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
        ua = new JsSIP.UA({
            sockets: [socket], uri: `sip:${username}@${host}`, password,
            register: true, register_expires: 120, session_timers: false
        });
        secret.value = '';
        ua.on('newRTCSession', ({originator, session: incoming}) => {
            if (originator !== 'remote' || session) { incoming.terminate(); return; }
            session = incoming; stop.hidden = false;
            incoming.on('peerconnection', ({peerconnection}) => {
                peerconnection.addEventListener('track', event => {
                    audio.srcObject = event.streams[0] || new MediaStream([event.track]);
                    audio.hidden = false;
                    audio.play().catch(() => { status.textContent = 'Press play to hear the call.'; });
                });
            });
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
        credentials.hidden = true; selected = null;
        const result = await requestListen(button.dataset.channel);
        status.textContent = result.message;
        stop.hidden = false;
    } catch (error) {
        cleanup();
        status.textContent = error.message || 'Listen failed.';
    } finally { busy = false; }
});
