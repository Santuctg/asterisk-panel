// Browser SIP uses a bundled MIT-licensed JsSIP build (see assets/vendor).
import JsSIP from '../vendor/jssip-3.13.9.mjs';

const supervisor = document.querySelector('#listen-supervisor');
const secret = document.querySelector('#listen-secret');
const status = document.querySelector('#listen-status');
const stop = document.querySelector('#listen-stop');
const audio = document.querySelector('#listen-audio');
let ua = null;
let session = null;
let busy = false;

function cleanup() {
    const currentSession = session;
    const currentUa = ua;
    session = null;
    ua = null;
    if (currentSession) { try { currentSession.terminate(); } catch (_) { /* already ended */ } }
    if (currentUa) { currentUa.stop(); }
    audio.srcObject = null;
    audio.hidden = true;
    stop.hidden = true;
    secret.value = '';
}

stop.addEventListener('click', () => { cleanup(); status.textContent = 'Browser listening stopped.'; });
window.addEventListener('pagehide', cleanup);

async function requestListen(channel, mode, extension) {
    const response = await fetch('/live-listen/start', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: new URLSearchParams({
            _token: document.querySelector('meta[name="csrf-token"]').content,
            channel, mode, supervisor: extension
        })
    });
    const result = await response.json();
    if (!response.ok) { throw new Error(result.error || 'Listen request failed'); }
    return result;
}

for (const button of document.querySelectorAll('.listen-phone, .listen-browser')) {
    button.addEventListener('click', async () => {
        if (busy) { return; }
        const extension = supervisor.value;
        if (!extension) { status.textContent = 'Select a supervisor extension first.'; return; }
        busy = true;
        const mode = button.classList.contains('listen-browser') ? 'browser' : 'phone';
        try {
            if (mode === 'browser') {
                if (!window.isSecureContext || !secret.value) {
                    throw new Error('HTTPS and the supervisor extension SIP secret are required.');
                }
                const password = secret.value;
                cleanupOldSession();
                const host = window.location.hostname;
                const context = document.querySelector('#listen-context').value;
                const username = `${context}_${extension}-web`;
                const socket = new JsSIP.WebSocketInterface(`wss://${window.location.host}/ws`);
                ua = new JsSIP.UA({ sockets: [socket], uri: `sip:${username}@${host}`,
                    password, register: true, register_expires: 120,
                    session_timers: false });
                secret.value = '';
                ua.on('newRTCSession', ({ originator, session: incoming }) => {
                    if (originator !== 'remote' || session) { incoming.terminate(); return; }
                    session = incoming;
                    stop.hidden = false;
                    incoming.on('peerconnection', ({ peerconnection }) => {
                        peerconnection.addEventListener('track', event => {
                            audio.srcObject = event.streams[0] || new MediaStream([event.track]);
                            audio.hidden = false;
                            audio.play().catch(() => { status.textContent = 'Press play to hear the call.'; });
                        });
                    });
                    incoming.on('ended', () => {
                        if (session !== incoming) { return; }
                        cleanup(); status.textContent = 'Call ended.';
                    });
                    incoming.on('failed', () => {
                        if (session !== incoming) { return; }
                        cleanup(); status.textContent = 'Browser call failed.';
                    });
                    incoming.answer({ mediaConstraints: { audio: false, video: false } });
                    status.textContent = 'Listening in browser. Use Stop to hang up.';
                });
                status.textContent = 'Registering browser SIP endpoint...';
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('Browser SIP registration timed out.')), 15000);
                    ua.once('registered', () => { clearTimeout(timer); resolve(); });
                    ua.once('registrationFailed', () => { clearTimeout(timer); reject(new Error('SIP registration rejected. Check the extension secret.')); });
                    ua.start();
                });
                stop.hidden = false;
            }
            const result = await requestListen(button.dataset.channel, mode, extension);
            status.textContent = result.message;
        } catch (error) {
            if (mode === 'browser') { cleanup(); }
            status.textContent = error.message || 'Listen failed.';
        } finally { busy = false; }
    });
}

function cleanupOldSession() {
    if (ua) { cleanup(); }
}
