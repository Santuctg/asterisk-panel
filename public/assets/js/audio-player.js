/* Material-style pill player wrapping every native audio[controls] element.
 * Keeps the <audio> element as the media engine: existing view scripts keep
 * toggling audio.hidden and calling play()/pause() on it unchanged. */
(function () {
    'use strict';

    var SELECTOR = 'audio[controls]';
    var PLAY_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
    var PAUSE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>';
    var VOLUME_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10v4h4l5 4V6L7 10H3zm12.5-.5a5 5 0 0 1 0 7l1.4 1.4a7 7 0 0 0 0-9.8L15.5 9.5z"/></svg>';
    var MUTE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10v4h4l5 4V6L7 10H3zm14.6 2 2.7-2.7-1.4-1.4-2.7 2.7-2.7-2.7-1.4 1.4 2.7 2.7-2.7 2.7 1.4 1.4 2.7-2.7 2.7 2.7 1.4-1.4-2.7-2.7z"/></svg>';

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatClock(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return '--:--';
        var total = Math.floor(seconds);
        return Math.floor(total / 60) + ':' + pad2(total % 60);
    }

    function enhance(audio) {
        if (!(audio instanceof HTMLAudioElement) || audio.closest('.audio-player')) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'audio-player';
        if (audio.classList.contains('moh-audio-player')) wrapper.classList.add('audio-player--moh');
        if (audio.classList.contains('announcement-prompt-player')) wrapper.classList.add('audio-player--prompt');
        if (audio.closest('.recording-modal')) wrapper.classList.add('audio-player--recording');

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'ap-toggle';
        toggle.setAttribute('data-ap-toggle', '');
        toggle.setAttribute('aria-label', 'Play');
        toggle.innerHTML = PLAY_ICON;

        var seek = document.createElement('input');
        seek.type = 'range';
        seek.className = 'ap-seek';
        seek.setAttribute('data-ap-seek', '');
        seek.min = '0';
        seek.max = '1000';
        seek.value = '0';
        seek.setAttribute('aria-label', 'Seek');

        var time = document.createElement('span');
        time.className = 'ap-time';
        time.setAttribute('data-ap-time', '');
        time.textContent = '0:00 / --:--';

        var status = document.createElement('span');
        status.className = 'ap-status';
        status.setAttribute('data-ap-status', '');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;

        var mute = document.createElement('button');
        mute.type = 'button';
        mute.className = 'ap-mute';
        mute.setAttribute('data-ap-mute', '');
        mute.setAttribute('aria-label', 'Mute');
        mute.innerHTML = VOLUME_ICON;

        audio.parentNode.insertBefore(wrapper, audio);
        wrapper.appendChild(toggle);
        wrapper.appendChild(seek);
        wrapper.appendChild(time);
        wrapper.appendChild(status);
        wrapper.appendChild(mute);
        wrapper.appendChild(audio);
        audio.removeAttribute('controls');

        function syncHidden() {
            wrapper.hidden = audio.hasAttribute('hidden');
        }
        syncHidden();
        new MutationObserver(syncHidden).observe(audio, { attributes: true, attributeFilter: ['hidden'] });

        function render() {
            var duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : NaN;
            var current = Number.isFinite(audio.currentTime) && audio.currentTime > 0 ? audio.currentTime : 0;
            var percent = Number.isFinite(duration) ? (current / duration) * 100 : 0;
            time.textContent = formatClock(current) + ' / ' + formatClock(duration);
            seek.value = String(Math.round(percent * 10));
            seek.disabled = !Number.isFinite(duration);
            seek.setAttribute('aria-valuetext', formatClock(current) + ' of ' + formatClock(duration));
            wrapper.style.setProperty('--ap-progress', percent.toFixed(2) + '%');
        }

        function clearError() {
            wrapper.classList.remove('is-error');
            status.hidden = true;
            status.textContent = '';
        }

        function showError() {
            wrapper.classList.add('is-error');
            status.textContent = 'Audio could not be played.';
            status.hidden = false;
            syncToggle();
        }

        function syncToggle() {
            var paused = audio.paused;
            toggle.innerHTML = paused ? PLAY_ICON : PAUSE_ICON;
            toggle.setAttribute('aria-label', paused ? 'Play' : 'Pause');
        }

        function syncMute() {
            mute.innerHTML = audio.muted ? MUTE_ICON : VOLUME_ICON;
            mute.setAttribute('aria-pressed', audio.muted ? 'true' : 'false');
            mute.setAttribute('aria-label', audio.muted ? 'Unmute' : 'Mute');
        }

        toggle.addEventListener('click', function () {
            if (audio.paused) {
                clearError();
                var started = audio.play();
                if (started && typeof started.catch === 'function') started.catch(showError);
            } else {
                audio.pause();
            }
        });

        seek.addEventListener('input', function () {
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
                audio.currentTime = (Number(seek.value) / 1000) * audio.duration;
            }
        });

        mute.addEventListener('click', function () {
            audio.muted = !audio.muted;
        });

        ['loadedmetadata', 'durationchange', 'timeupdate', 'emptied', 'seeked'].forEach(function (name) {
            audio.addEventListener(name, render);
        });
        ['play', 'pause', 'ended'].forEach(function (name) {
            audio.addEventListener(name, syncToggle);
        });
        audio.addEventListener('volumechange', syncMute);
        audio.addEventListener('error', showError);
        ['loadstart', 'loadedmetadata', 'canplay'].forEach(function (name) {
            audio.addEventListener(name, clearError);
        });

        render();
        syncToggle();
        syncMute();
    }

    function initAudioPlayers(root) {
        var scope = root || document;
        if (scope.nodeType === 1 && typeof scope.matches === 'function' && scope.matches(SELECTOR)) {
            enhance(scope);
        }
        if (typeof scope.querySelectorAll === 'function') {
            Array.prototype.forEach.call(scope.querySelectorAll(SELECTOR), enhance);
        }
    }
    window.initAudioPlayers = initAudioPlayers;

    function boot() {
        initAudioPlayers(document);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (node.nodeType === 1) initAudioPlayers(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
