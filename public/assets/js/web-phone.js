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
const voicemailList = $('#web-phone-voicemail');
const contactsList = $('#web-phone-contacts');
const contactIndex = $('#web-phone-contact-index');
const callMenu = $('#web-phone-call-menu');
const contactMenu = $('#web-phone-contact-menu');
const contactCount = $('#web-phone-contact-count');
const listWorkspace = $('#web-phone-list-workspace');
const search = $('#web-phone-search');
const listTitle = $('#web-phone-list-title');
const listSummary = $('#web-phone-list-summary');
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
const settingsOpen = $('#web-phone-settings-open');
const settingsModal = $('#web-phone-settings');
const settingsClose = $('#web-phone-settings-close');
const microphoneSelect = $('#web-phone-microphone');
const speakerSelect = $('#web-phone-speaker-device');
const speakerVolume = $('#web-phone-speaker-volume');
const ringtoneToggle = $('#web-phone-ringtone');
const autoAnswerToggle = $('#web-phone-auto-answer');
const notificationState = $('#web-phone-notification-state');
const permissionModal = $('#web-phone-permissions');
const permissionEnable = $('#web-phone-permission-enable');
const permissionLater = $('#web-phone-permission-later');
const permissionStatus = $('#web-phone-permission-status');
const themeToggle = $('#themeToggle');
const preferenceKey = 'panel-webphone-preferences';
const originalTitle = document.title;

let ua = null;
let session = null;
let realm = '';
let accountKey = '';
let contacts = [];
let history = [];
let currentView = 'calls';
let currentSection = 'recent';
let favorites = new Set();
let voicemailEnabled = false;
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
let ringtoneAudio = null;
let microphoneTestStream = null;
let microphoneTestContext = null;
let microphoneTestTimer = null;
let settingsReturnFocus = null;
let presencePollTimer = null;
let preferences = loadPreferences();

function loadPreferences() {
    const defaults = {microphone: '', speaker: '', volume: 1, ringtone: true, autoAnswer: false};
    try {
        const saved = JSON.parse(localStorage.getItem(preferenceKey) || '{}');
        return {...defaults, ...saved, volume: Math.min(1, Math.max(0, Number(saved.volume ?? 1)))};
    } catch (_) { return defaults; }
}
function savePreferences() {
    try { localStorage.setItem(preferenceKey, JSON.stringify(preferences)); } catch (_) {}
}
function csrfToken() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
function phoneIcon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    svg.setAttribute('class', 'webphone-icon'); svg.setAttribute('aria-hidden', 'true');
    use.setAttribute('href', `#wp-icon-${name}`); svg.appendChild(use);
    return svg;
}
function setPhoneIcon(element, name) {
    const use = element?.matches?.('svg') ? element.querySelector('use') : element?.querySelector?.('svg use');
    if (use) use.setAttribute('href', `#wp-icon-${name}`);
}
function audioConstraints() {
    return {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
        ...(preferences.microphone ? {deviceId: {exact: preferences.microphone}} : {}),
    };
}
function callMediaConstraints() { return {audio: audioConstraints(), video: false}; }
function normalizeDestination(value) { return String(value || '').trim().replace(/[\s().-]/g, ''); }
function validDestination(value) { return /^[0-9*+#]{2,40}$/.test(value); }
async function getMicrophoneStream() {
    try {
        return await navigator.mediaDevices.getUserMedia({audio: audioConstraints(), video: false});
    } catch (error) {
        const staleDevice = preferences.microphone
            && ['OverconstrainedError', 'NotFoundError', 'NotReadableError'].includes(error?.name);
        if (!staleDevice) throw error;
        preferences.microphone = ''; savePreferences();
        microphoneSelect.value = '';
        return navigator.mediaDevices.getUserMedia({audio: audioConstraints(), video: false});
    }
}
function microphoneErrorMessage(error) {
    const policy = document.permissionsPolicy || document.featurePolicy;
    if (policy?.allowsFeature && !policy.allowsFeature('microphone')) {
        return 'This server is blocking microphone access through its Permissions-Policy header.';
    }
    if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
        return 'Chrome blocked this site’s microphone. Use Site controls → Reset permissions, reload, and allow Microphone.';
    }
    if (error?.name === 'NotFoundError' || error?.name === 'OverconstrainedError') {
        return 'No working microphone was detected. Connect or enable a microphone, then retry.';
    }
    if (error?.name === 'NotReadableError' || error?.name === 'AbortError') {
        return 'The microphone is busy or unavailable. Close other calling/recording apps, then retry.';
    }
    return `Unable to open the microphone${error?.name ? ` (${error.name})` : ''}.`;
}
async function setAudioOutput(element) {
    if (!element || typeof element.setSinkId !== 'function' || !preferences.speaker) return;
    try { await element.setSinkId(preferences.speaker); } catch (_) {}
}

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
function favoriteStorageKey() { return `panel-webphone-favorites:${accountKey}`; }
function loadFavorites() {
    try {
        const saved = JSON.parse(localStorage.getItem(favoriteStorageKey()) || '[]');
        favorites = new Set(Array.isArray(saved) ? saved.map(String) : []);
    } catch (_) { favorites = new Set(); }
}
function saveFavorites() {
    try { localStorage.setItem(favoriteStorageKey(), JSON.stringify([...favorites])); } catch (_) {}
}
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
function callHistoryRow(item) {
    const numberValue = String(item.number || '');
    const contact = contacts.find(candidate => candidate.extension === numberValue);
    const row = document.createElement('button');
    row.type = 'button'; row.className = 'webphone-history-row webphone-call-row'; row.dataset.number = numberValue;
    const avatar = document.createElement('span');
    if (contact) {
        avatar.className = 'webphone-contact-avatar'; avatar.style.setProperty('--contact-hue', String(contactHue(contact)));
        avatar.textContent = contactInitials(contact);
    } else {
        avatar.appendChild(phoneIcon('user'));
    }
    const detail = document.createElement('div');
    const strong = document.createElement('strong'); strong.textContent = contact?.name || numberValue || 'Unknown';
    const small = document.createElement('small'); small.textContent = contact ? numberValue : (item.direction === 'incoming' ? 'Incoming call' : 'Outgoing call');
    const duration = Number(item.duration || 0);
    const outcome = document.createElement('span'); outcome.className = 'webphone-call-outcome';
    const missed = item.direction === 'incoming' && duration < 1;
    outcome.classList.toggle('is-missed', missed);
    outcome.append(phoneIcon(missed ? 'phone-off' : 'phone'));
    outcome.append(document.createTextNode(missed ? 'Missed call' : (duration ? formatDuration(duration) : String(item.result || 'No answer'))));
    const time = document.createElement('time'); time.dateTime = String(item.time || ''); time.textContent = formatTime(new Date(item.time));
    const action = document.createElement('span'); action.className = 'webphone-row-action'; action.appendChild(phoneIcon('phone'));
    detail.append(strong, small); row.append(avatar, detail, outcome, time, action);
    row.addEventListener('click', () => {
        number.value = numberValue;
        setStatus(`Ready to call ${numberValue}.`);
        number.focus();
    });
    return row;
}
function contactInitials(contact) {
    const parts = String(contact.name || contact.extension).trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? parts[0][0] + parts.at(-1)[0] : (parts[0] || '?').slice(0, 2)).toUpperCase();
}
function contactHue(contact) {
    return [...String(contact.name || contact.extension)].reduce((value, char) => (value * 31 + char.charCodeAt(0)) % 360, 210);
}
function selectContactNumber(extension) {
    number.value = extension;
    setStatus(`Ready to call ${extension}.`);
    if (currentView === 'contacts') switchView('calls');
    number.focus();
}
function contactRow(contact) {
    const row = document.createElement('div');
    row.className = `webphone-history-row webphone-contact-row ${contact.online === true ? 'is-online' : (contact.online === false ? 'is-offline' : 'is-unknown')}`;
    row.dataset.number = contact.extension; row.tabIndex = 0; row.setAttribute('role', 'button');
    const avatar = document.createElement('span'); avatar.className = 'webphone-contact-avatar';
    avatar.style.setProperty('--contact-hue', String(contactHue(contact))); avatar.textContent = contactInitials(contact);
    const detail = document.createElement('div');
    const strong = document.createElement('strong'); strong.textContent = contact.name || contact.extension;
    const state = contact.online === true ? 'Online' : (contact.online === false ? 'Offline' : 'Unknown');
    const source = contact.kind === 'contact' ? 'Panel Contact' : 'Tenant Extension';
    const small = document.createElement('small'); small.textContent = `${contact.extension} · ${source} · ${state}`;
    detail.append(strong, small);
    const favorite = document.createElement('button'); favorite.type = 'button'; favorite.className = 'webphone-favorite-action';
    favorite.title = favorites.has(contact.extension) ? 'Remove from favorites' : 'Add to favorites';
    favorite.setAttribute('aria-label', favorite.title); favorite.setAttribute('aria-pressed', String(favorites.has(contact.extension)));
    favorite.classList.toggle('is-active', favorites.has(contact.extension)); favorite.appendChild(phoneIcon('star'));
    favorite.addEventListener('click', event => {
        event.stopPropagation();
        favorites.has(contact.extension) ? favorites.delete(contact.extension) : favorites.add(contact.extension);
        saveFavorites(); renderList();
    });
    const action = document.createElement('button'); action.type = 'button'; action.className = 'webphone-row-action';
    action.title = `Call ${contact.name || contact.extension}`; action.setAttribute('aria-label', action.title);
    action.appendChild(phoneIcon('phone')); action.addEventListener('click', event => { event.stopPropagation(); selectContactNumber(contact.extension); });
    row.append(avatar, detail, favorite, action);
    row.addEventListener('click', () => selectContactNumber(contact.extension));
    row.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectContactNumber(contact.extension); } });
    return row;
}
function renderContactIndex(letters) {
    contactIndex.replaceChildren();
    'ABCDEFGHIJKLMNOPQRSTUVWXYZ#'.split('').forEach(letter => {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = letter;
        button.disabled = !letters.has(letter);
        button.addEventListener('click', () => document.getElementById(`web-phone-contact-group-${letter === '#' ? 'other' : letter}`)?.scrollIntoView({block: 'start'}));
        contactIndex.appendChild(button);
    });
}
function renderList() {
    const query = search.value.trim().toLowerCase();
    if (currentView === 'calls' && currentSection === 'voicemail') {
        voicemailList.replaceChildren();
        const empty = document.createElement('div'); empty.className = 'webphone-history-empty webphone-voicemail-empty';
        if (!voicemailEnabled) {
            empty.classList.add('is-disabled');
            const visual = document.createElement('div'); visual.className = 'webphone-voicemail-illustration';
            const preview = document.createElement('span'); preview.className = 'webphone-voicemail-window'; preview.appendChild(phoneIcon('voicemail'));
            const person = document.createElement('span'); person.className = 'webphone-voicemail-person'; person.appendChild(phoneIcon('user'));
            visual.append(preview, person);
            const title = document.createElement('strong'); title.textContent = 'Voicemail has not been enabled.';
            const settings = document.createElement('a'); settings.className = 'btn webphone-voicemail-settings'; settings.href = '/voicemail';
            settings.target = '_blank'; settings.rel = 'noopener'; settings.textContent = 'Change Settings';
            empty.append(visual, title, settings);
        } else {
            empty.append(phoneIcon('voicemail'), Object.assign(document.createElement('strong'), {textContent: 'No voicemail messages'}), Object.assign(document.createElement('small'), {textContent: 'New mailbox messages will appear here when voicemail message sync is available.'}));
        }
        voicemailList.appendChild(empty); return;
    }
    if (currentView === 'calls') {
        historyList.replaceChildren();
        const rows = history.filter(item => `${item.number} ${item.result} ${item.direction}`.toLowerCase().includes(query));
        rows.forEach(item => {
            historyList.appendChild(callHistoryRow(item));
        });
        if (rows.length) return;
        const empty = document.createElement('div'); empty.className = 'webphone-history-empty';
        empty.textContent = 'No call history'; historyList.appendChild(empty); return;
    }

    contactsList.replaceChildren();
    let rows = contacts.filter(item => `${item.name} ${item.extension}`.toLowerCase().includes(query));
    if (currentSection === 'favorites') rows = rows.filter(item => favorites.has(item.extension));
    rows.sort((left, right) => String(left.name || left.extension).localeCompare(String(right.name || right.extension), undefined, {sensitivity: 'base'}));
    const groups = new Map();
    rows.forEach(item => {
        const first = String(item.name || item.extension).trim().charAt(0).toUpperCase();
        const letter = /^[A-Z]$/.test(first) ? first : '#';
        if (!groups.has(letter)) groups.set(letter, []);
        groups.get(letter).push(item);
    });
    groups.forEach((items, letter) => {
        const heading = document.createElement('div'); heading.className = 'webphone-contact-group';
        heading.id = `web-phone-contact-group-${letter === '#' ? 'other' : letter}`; heading.textContent = `${letter} (${items.length})`;
        contactsList.appendChild(heading); items.forEach(item => contactsList.appendChild(contactRow(item)));
    });
    renderContactIndex(new Set(groups.keys()));
    if (!rows.length) {
        const empty = document.createElement('div'); empty.className = 'webphone-history-empty';
        empty.textContent = currentSection === 'favorites' ? 'No favorite contacts yet' : (ua ? 'No contacts found' : 'Sign in to view contacts');
        contactsList.appendChild(empty);
    }
}
function switchSection(section) {
    const allowed = currentView === 'contacts' ? ['all', 'favorites', 'tenant'] : ['recent', 'voicemail'];
    currentSection = allowed.includes(section) ? section : allowed[0];
    document.querySelectorAll('[data-phone-section]').forEach(button => button.classList.toggle('is-active', button.dataset.phoneSection === currentSection));
    historyList.hidden = currentSection !== 'recent';
    voicemailList.hidden = currentSection !== 'voicemail';
    contactsList.hidden = currentView !== 'contacts';
    contactIndex.hidden = currentView !== 'contacts';
    const metadata = {
        recent: ['Recent Calls', 'Your latest incoming and outgoing calls', 'Search calls'],
        voicemail: ['Voicemail', 'Your voicemail mailbox', 'Search voicemail'],
        all: ['All Contacts', 'All available tenant contacts', 'Search contacts'],
        favorites: ['Favorites', 'Contacts you call most often', 'Search favorites'],
        tenant: ['Tenant Contacts', 'Extensions available in this tenant', 'Search tenant contacts'],
    }[currentSection];
    [listTitle.textContent, listSummary.textContent, search.placeholder] = metadata;
    search.value = '';
    renderList();
}
function switchView(view) {
    currentView = ['calls', 'contacts'].includes(view) ? view : 'calls';
    $('.webphone-app').dataset.view = currentView;
    document.querySelectorAll('[data-phone-view]').forEach(button => button.classList.toggle('is-active', button.dataset.phoneView === currentView));
    callMenu.hidden = currentView !== 'calls'; contactMenu.hidden = currentView !== 'contacts';
    dialer.hidden = currentView !== 'calls' || !ua;
    switchSection(currentView === 'contacts' ? 'all' : 'recent');
}
document.querySelectorAll('[data-phone-view]').forEach(button => button.addEventListener('click', () => switchView(button.dataset.phoneView)));
document.querySelectorAll('[data-phone-section]').forEach(button => button.addEventListener('click', () => switchSection(button.dataset.phoneSection)));
search.addEventListener('input', renderList);

function stopPresencePolling() {
    window.clearTimeout(presencePollTimer); presencePollTimer = null;
}
function applyContactPresence(states, available) {
    contacts = contacts.map(contact => ({
        ...contact,
        online: available && Object.prototype.hasOwnProperty.call(states, contact.extension)
            ? states[contact.extension] === true : null,
    }));
    if (currentView === 'contacts') renderList();
}
async function refreshContactPresence() {
    if (!ua) return;
    try {
        const response = await fetch('/web-phone/presence', {
            method: 'POST', headers: {'X-CSRF-Token': csrfToken(), Accept: 'application/json'}, cache: 'no-store',
        });
        if (!response.ok) throw new Error('Presence unavailable');
        const data = await response.json();
        applyContactPresence(data.presence && typeof data.presence === 'object' ? data.presence : {}, data.available === true);
    } catch (_) {
        applyContactPresence({}, false);
    } finally {
        if (ua) presencePollTimer = window.setTimeout(refreshContactPresence, 10000);
    }
}
function startPresencePolling() {
    stopPresencePolling(); refreshContactPresence();
}

function playRemoteMedia(peerconnection, stream = null) {
    if (!peerconnection) return;
    const remote = stream || new MediaStream(peerconnection.getReceivers()
        .map(receiver => receiver.track).filter(track => track && track.kind === 'audio'));
    if (!remote.getAudioTracks().length) return;
    audio.srcObject = remote;
    audio.muted = false; audio.volume = preferences.volume;
    setAudioOutput(audio);
    audio.play().catch(() => setStatus('Call connected. Press Speaker to start remote audio.'));
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
    stopRingtone();
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
        switchView('calls');
        document.title = `Incoming: ${currentTarget}`;
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Incoming Web Phone call', {body: currentTarget, tag: 'web-phone-incoming'}); } catch (_) {}
        }
        startRingtone();
    }
    partyNumber.textContent = originator === 'remote' && current.remote_identity?.display_name
        ? `${current.remote_identity.display_name} · ${currentTarget}` : currentTarget;
    callTime.textContent = originator === 'remote' ? 'Incoming call' : 'Calling…';
    attachMedia(current);
    current.on('confirmed', () => {
        stopRingtone();
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
        if (preferences.autoAnswer) {
            window.setTimeout(() => {
                if (session !== current || confirmedAt) return;
                try { current.answer({mediaConstraints: callMediaConstraints()}); answerButton.hidden = true; }
                catch (_) { setStatus('Unable to auto-answer this call.'); }
            }, 500);
        }
    } else {
        answerButton.hidden = true;
        setStatus(`Calling ${currentTarget}…`);
    }
}

function toneUrl(frequency = 440, seconds = 1.8, ringPattern = false) {
    const rate = 8000;
    const samples = Math.floor(rate * seconds);
    const buffer = new ArrayBuffer(44 + samples * 2);
    const view = new DataView(buffer);
    const write = (offset, value) => [...value].forEach((char, index) => view.setUint8(offset + index, char.charCodeAt(0)));
    write(0, 'RIFF'); view.setUint32(4, 36 + samples * 2, true); write(8, 'WAVE'); write(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    write(36, 'data'); view.setUint32(40, samples * 2, true);
    for (let index = 0; index < samples; index++) {
        const elapsed = index / rate;
        const audible = !ringPattern || elapsed < .45 || (elapsed > .65 && elapsed < 1.1);
        const fade = Math.min(1, index / 120, (samples - index) / 120);
        view.setInt16(44 + index * 2, audible ? Math.sin(2 * Math.PI * frequency * elapsed) * 5000 * fade : 0, true);
    }
    return URL.createObjectURL(new Blob([buffer], {type: 'audio/wav'}));
}
async function startRingtone() {
    if (!preferences.ringtone) return;
    stopRingtone();
    ringtoneAudio = new Audio(toneUrl(440, 1.8, true));
    ringtoneAudio.loop = true; ringtoneAudio.volume = preferences.volume;
    await setAudioOutput(ringtoneAudio);
    ringtoneAudio.play().catch(() => {});
}
function stopRingtone() {
    if (!ringtoneAudio) return;
    const source = ringtoneAudio.src;
    ringtoneAudio.pause(); ringtoneAudio.src = ''; ringtoneAudio = null;
    if (source.startsWith('blob:')) URL.revokeObjectURL(source);
}
function replaceDeviceOptions(select, devices, fallback) {
    const selected = select.value || (select === microphoneSelect ? preferences.microphone : preferences.speaker);
    select.replaceChildren();
    const defaultOption = document.createElement('option'); defaultOption.value = ''; defaultOption.textContent = fallback;
    select.appendChild(defaultOption);
    devices.forEach((device, index) => {
        const option = document.createElement('option'); option.value = device.deviceId;
        option.textContent = device.label || `${fallback.replace('Default ', '')} ${index + 1}`;
        select.appendChild(option);
    });
    if ([...select.options].some(option => option.value === selected)) select.value = selected;
}
async function refreshAudioDevices() {
    if (!navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        replaceDeviceOptions(microphoneSelect, devices.filter(device => device.kind === 'audioinput'), 'Default microphone');
        replaceDeviceOptions(speakerSelect, devices.filter(device => device.kind === 'audiooutput'), 'Default speaker');
        const outputSupported = typeof audio.setSinkId === 'function';
        speakerSelect.disabled = !outputSupported;
        $('#web-phone-speaker-hint').textContent = outputSupported ? '' : 'Speaker selection is not supported by this browser.';
    } catch (_) {}
}
function updateNotificationState() {
    const permission = typeof Notification === 'undefined' ? 'unsupported' : Notification.permission;
    notificationState.textContent = permission === 'granted' ? 'Incoming call notifications are allowed.'
        : permission === 'denied' ? 'Notifications are blocked in browser site settings.'
        : permission === 'unsupported' ? 'Desktop notifications are not supported by this browser.'
        : 'Browser permission has not been granted.';
    $('#web-phone-notification-request').disabled = permission === 'granted' || permission === 'unsupported';
}
function stopMicrophoneTest() {
    window.clearTimeout(microphoneTestTimer); microphoneTestTimer = null;
    microphoneTestStream?.getTracks().forEach(track => track.stop()); microphoneTestStream = null;
    microphoneTestContext?.close().catch(() => {}); microphoneTestContext = null;
    $('#web-phone-microphone-level').style.width = '0%';
}
async function testMicrophone() {
    stopMicrophoneTest();
    try {
        microphoneTestStream = await getMicrophoneStream();
        microphoneTestContext = new AudioContext();
        const analyser = microphoneTestContext.createAnalyser(); analyser.fftSize = 256;
        microphoneTestContext.createMediaStreamSource(microphoneTestStream).connect(analyser);
        const values = new Uint8Array(analyser.frequencyBinCount);
        const draw = () => {
            if (!microphoneTestStream) return;
            analyser.getByteFrequencyData(values);
            const level = Math.min(100, values.reduce((sum, value) => sum + value, 0) / values.length * 1.4);
            $('#web-phone-microphone-level').style.width = `${level}%`;
            requestAnimationFrame(draw);
        };
        draw(); microphoneTestTimer = window.setTimeout(stopMicrophoneTest, 5000);
    } catch (error) { setStatus(microphoneErrorMessage(error)); }
}

microphoneSelect.value = preferences.microphone;
speakerSelect.value = preferences.speaker;
speakerVolume.value = String(Math.round(preferences.volume * 100));
ringtoneToggle.checked = preferences.ringtone;
autoAnswerToggle.checked = preferences.autoAnswer;
updateNotificationState();
const applyPhoneTheme = theme => {
    document.documentElement.dataset.theme = theme;
    const icon = themeToggle.querySelector('.theme-icon');
    if (icon) setPhoneIcon(icon, theme === 'dark' ? 'sun' : 'moon');
};
applyPhoneTheme(localStorage.getItem('webphone-theme') || 'dark');
themeToggle.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('webphone-theme', next); applyPhoneTheme(next);
});
settingsOpen.addEventListener('click', () => {
    settingsReturnFocus = document.activeElement;
    settingsModal.hidden = false; refreshAudioDevices();
    window.requestAnimationFrame(() => settingsClose.focus());
});
settingsClose.addEventListener('click', () => {
    settingsModal.hidden = true; stopMicrophoneTest();
    if (settingsReturnFocus instanceof HTMLElement) settingsReturnFocus.focus();
});
settingsModal.addEventListener('click', event => { if (event.target === settingsModal) settingsClose.click(); });
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (!settingsModal.hidden) settingsClose.click();
    else if (!permissionModal.hidden) permissionLater.click();
});
document.querySelectorAll('[data-phone-setting]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-phone-setting]').forEach(item => item.classList.toggle('is-active', item === button));
    document.querySelectorAll('[data-phone-setting-panel]').forEach(panel => { panel.hidden = panel.dataset.phoneSettingPanel !== button.dataset.phoneSetting; });
}));
microphoneSelect.addEventListener('change', () => { preferences.microphone = microphoneSelect.value; savePreferences(); });
speakerSelect.addEventListener('change', () => { preferences.speaker = speakerSelect.value; savePreferences(); setAudioOutput(audio); });
speakerVolume.addEventListener('input', () => { preferences.volume = Number(speakerVolume.value) / 100; audio.volume = preferences.volume; savePreferences(); });
ringtoneToggle.addEventListener('change', () => { preferences.ringtone = ringtoneToggle.checked; savePreferences(); if (!preferences.ringtone) stopRingtone(); });
autoAnswerToggle.addEventListener('change', () => { preferences.autoAnswer = autoAnswerToggle.checked; savePreferences(); });
$('#web-phone-devices-refresh').addEventListener('click', refreshAudioDevices);
$('#web-phone-microphone-test').addEventListener('click', testMicrophone);
$('#web-phone-speaker-test').addEventListener('click', async () => {
    const source = toneUrl(523, .45); const testAudio = new Audio(source); testAudio.volume = preferences.volume;
    await setAudioOutput(testAudio); testAudio.addEventListener('ended', () => URL.revokeObjectURL(source), {once: true});
    testAudio.play().catch(() => { URL.revokeObjectURL(source); setStatus('Unable to play the speaker test.'); });
});
$('#web-phone-notification-request').addEventListener('click', async () => {
    if (typeof Notification !== 'undefined' && Notification.permission === 'default') await Notification.requestPermission();
    updateNotificationState();
});

function startPhone(config, password) {
    if (config.branding && typeof config.branding === 'object') {
        const brandName = String(config.branding.name || 'Asterisk Panel');
        document.title = `Web Phone · ${brandName}`;
        document.querySelectorAll('[data-brand-phone-name]').forEach(node => { node.textContent = `${brandName} Web Phone`; });
        document.querySelectorAll('[data-brand-mark]').forEach(mark => {
            mark.replaceChildren();
            if (config.branding.logo_url) {
                const image = document.createElement('img'); image.src = `${config.branding.logo_url}?v=${Date.now()}`; image.alt = '';
                mark.appendChild(image);
            } else {
                mark.textContent = String(config.branding.initial || brandName.charAt(0)).toUpperCase();
            }
        });
    }
    realm = config.realm;
    accountKey = `${config.realm}:${config.extension}`;
    contacts = Array.isArray(config.contacts) ? config.contacts : [];
    voicemailEnabled = config.voicemailEnabled === true;
    loadFavorites(); contactCount.textContent = String(contacts.length);
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
            settled = true; stopPresencePolling(); ua?.stop(); ua = null;
            reject(new Error('SIP registration timed out. Check WebRTC/WSS configuration.'));
        }, 15000);
        ua.on('registered', () => {
            if (settled) return;
            settled = true; window.clearTimeout(timer);
            callButton.disabled = false; setPresence('Online', true); setStatus('Ready to call.');
            login.hidden = true; dialer.hidden = false; listWorkspace.hidden = false; $('.webphone-app').classList.remove('is-locked');
            switchView('calls');
            startPresencePolling();
            resolve();
        });
        ua.on('unregistered', () => { setPresence('Offline'); callButton.disabled = true; });
        ua.on('registrationFailed', event => {
            setPresence('Offline'); callButton.disabled = true;
            if (settled) { setStatus('SIP registration lost. Sign out and log in again.'); return; }
            settled = true; window.clearTimeout(timer); stopPresencePolling(); ua?.stop(); ua = null;
            reject(new Error(event.cause || 'SIP registration failed. Apply PBX configuration and check the SIP password.'));
        });
        ua.start();
    });
}

async function requestPhonePermissions() {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('This browser cannot access the microphone.');
    const notificationRequest = typeof Notification !== 'undefined' && Notification.permission === 'default'
        ? Notification.requestPermission().catch(() => 'denied') : Promise.resolve('unavailable');
    try {
        const stream = await getMicrophoneStream();
        stream.getTracks().forEach(track => track.stop());
    } catch (error) {
        throw new Error(microphoneErrorMessage(error));
    }
    await refreshAudioDevices();
    await notificationRequest;
    localStorage.setItem('panel-webphone-permissions-ready', '1');
    updateNotificationState();
}

function showPermissionSetup() {
    if (localStorage.getItem('panel-webphone-permissions-ready') === '1') return;
    permissionStatus.textContent = '';
    permissionModal.hidden = false;
    window.requestAnimationFrame(() => permissionEnable.focus());
}
permissionLater.addEventListener('click', () => { permissionModal.hidden = true; number.focus(); });
permissionEnable.addEventListener('click', async () => {
    permissionEnable.disabled = true; permissionStatus.textContent = 'Requesting browser permissions…';
    try {
        await requestPhonePermissions();
        permissionModal.hidden = true;
    } catch (error) {
        permissionStatus.textContent = error.message;
    } finally { permissionEnable.disabled = false; }
});

login.addEventListener('submit', async event => {
    event.preventDefault(); loginStatus.textContent = '';
    if (!window.isSecureContext) { loginStatus.textContent = 'HTTPS is required for Web Phone.'; return; }
    const submit = login.querySelector('[type="submit"]');
    const data = new FormData(login); const password = String(data.get('secret') || '');
    submit.disabled = true; submit.textContent = 'Connecting…';
    try {
        loginStatus.textContent = 'Checking SIP credentials…';
        const response = await fetch('/web-phone/connect', {method: 'POST', body: data, headers: {Accept: 'application/json'}});
        const config = await response.json();
        if (!response.ok) throw new Error(config.error || 'Unable to sign in.');
        loginStatus.textContent = 'Credentials verified. Registering phone…';
        await startPhone(config, password);
        login.querySelector('[name="secret"]').value = '';
        login.querySelector('[name="secret"]').type = 'password';
        setPhoneIcon(passwordToggle, 'eye');
        passwordToggle.title = 'Show password'; passwordToggle.setAttribute('aria-label', passwordToggle.title);
        loginStatus.textContent = '';
        showPermissionSetup();
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
    setPhoneIcon(passwordToggle, show ? 'eye-off' : 'eye');
    passwordToggle.title = show ? 'Hide password' : 'Show password';
    passwordToggle.setAttribute('aria-label', passwordToggle.title);
});
backspace.addEventListener('click', () => { number.value = number.value.slice(0, -1); number.focus(); });
number.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); callButton.click(); } });
callButton.addEventListener('click', () => {
    const destination = normalizeDestination(number.value);
    if (!ua || !validDestination(destination)) { setStatus('Enter a valid number or extension.'); return; }
    number.value = destination;
    // JsSIP emits newRTCSession synchronously for local calls; that event is the
    // single owner of setupSession. Setting it up again here terminates it as a duplicate.
    try {
        ua.call(`sip:${destination}@${realm}`, {mediaConstraints: callMediaConstraints(), sessionTimersExpires: 90});
    } catch (error) { setStatus(microphoneErrorMessage(error)); }
});
answerButton.addEventListener('click', () => {
    if (!session) return;
    try { session.answer({mediaConstraints: callMediaConstraints()}); answerButton.hidden = true; }
    catch (error) { setStatus(microphoneErrorMessage(error)); }
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
    audio.muted = false; audio.volume = preferences.volume;
    setAudioOutput(audio);
    bindMedia(session.connection);
    audio.play().then(() => {
        speakerButton.classList.add('is-active');
        speakerButton.querySelector('small').textContent = 'Playing';
        setStatus(`Playing audio from ${currentTarget}.`);
    }).catch(() => setStatus('Remote audio is not available yet. Try Speaker again.'));
});
transferButton.addEventListener('click', () => { transferBox.hidden = !transferBox.hidden; transferNumber.focus(); });
$('#web-phone-transfer-send').addEventListener('click', () => {
    const target = normalizeDestination(transferNumber.value);
    if (!session || !confirmedAt || !validDestination(target)) { setStatus('Enter a valid transfer destination.'); return; }
    try {
        session.refer(`sip:${target}@${realm}`);
        setStatus(`Transferring call to ${target}…`); transferBox.hidden = true; transferNumber.value = '';
    } catch (_) { setStatus('Call transfer failed.'); }
});
disconnectButton.addEventListener('click', async () => {
    disconnectButton.disabled = true;
    try {
        const response = await fetch('/web-phone/disconnect', {method: 'POST', headers: {'X-CSRF-Token': csrfToken(), Accept: 'application/json'}});
        if (!response.ok) throw new Error('The server did not confirm logout.');
        const current = session;
        if (current) { try { current.terminate(); } catch (_) { finalizeSession(current, 'Ended'); } }
        stopPresencePolling();
        if (ua) { ua.stop(); ua = null; }
        stopMicrophoneTest(); stopRingtone(); settingsModal.hidden = true; permissionModal.hidden = true;
        setPresence('Offline'); dialer.hidden = true; listWorkspace.hidden = true; login.hidden = false; number.value = ''; contacts = []; favorites = new Set(); voicemailEnabled = false; contactCount.textContent = '0';
        login.querySelector('[name="secret"]').value = ''; login.querySelector('[name="secret"]').type = 'password';
        setPhoneIcon(passwordToggle, 'eye');
        passwordToggle.title = 'Show password'; passwordToggle.setAttribute('aria-label', passwordToggle.title);
        $('.webphone-app').classList.add('is-locked');
        callButton.disabled = true; finalizeSession(session, 'Ended'); setStatus('Disconnected.'); renderList();
    } catch (error) {
        setStatus(error.message || 'Unable to log out. Check the connection and try again.');
    } finally { disconnectButton.disabled = false; }
});
window.addEventListener('pagehide', () => {
    stopPresencePolling(); stopMicrophoneTest(); stopRingtone();
    if (session) session.terminate(); if (ua) ua.stop();
});

async function resumePhoneSession() {
    const submit = login.querySelector('[type="submit"]');
    submit.disabled = true; submit.textContent = 'Restoring…';
    try {
        const response = await fetch('/web-phone/session', {
            method: 'POST', headers: {'X-CSRF-Token': csrfToken(), Accept: 'application/json'}, cache: 'no-store',
        });
        if (!response.ok) return;
        const config = await response.json();
        const password = String(config.password || ''); delete config.password;
        if (!password) return;
        loginStatus.textContent = 'Restoring phone session…';
        await startPhone(config, password);
        loginStatus.textContent = '';
        showPermissionSetup();
    } catch (error) {
        loginStatus.textContent = error.message || 'Unable to restore the phone session.';
    } finally {
        if (!login.hidden) { submit.disabled = false; submit.textContent = 'Log in'; }
    }
}
resumePhoneSession();
