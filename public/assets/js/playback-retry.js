/** Keep at most one user-gesture playback retry alive at a time. */
export function createPlaybackRetry({ target, play, onBlocked = () => {}, onStarted = () => {} }) {
    let retryHandler = null;
    let attempting = false;
    let generation = 0;

    function removeRetry() {
        if (!retryHandler) return;
        target.removeEventListener('pointerdown', retryHandler);
        retryHandler = null;
    }

    function scheduleRetry(expectedGeneration) {
        if (expectedGeneration !== generation || retryHandler) return;
        onBlocked();
        retryHandler = () => {
            removeRetry();
            attempt();
        };
        target.addEventListener('pointerdown', retryHandler);
    }

    function attempt() {
        if (attempting || retryHandler) return;
        attempting = true;
        const expectedGeneration = generation;
        let result;
        try {
            result = play();
        } catch (_) {
            attempting = false;
            scheduleRetry(expectedGeneration);
            return;
        }
        Promise.resolve(result).then(() => {
            if (expectedGeneration !== generation) return;
            attempting = false;
            removeRetry();
            onStarted();
        }).catch(() => {
            if (expectedGeneration !== generation) return;
            attempting = false;
            scheduleRetry(expectedGeneration);
        });
    }

    function cancel() {
        generation++;
        attempting = false;
        removeRetry();
    }

    return { attempt, cancel };
}
