(() => {
    'use strict';

    /*
     * Don't start if user isn't authenticated.
     */
    if (!document.querySelector('meta[name="csrf-token"]')) {
        return;
    }

    const heartbeatUrl = '/login-session/heartbeat';

    /*
     * Send heartbeat every 30 seconds.
     */
    const HEARTBEAT_INTERVAL = 30 * 1000;

    /*
     * Prevent multiple heartbeat requests from running
     * at the same time.
     */
    let heartbeatInProgress = false;

    /**
     * Check whether the FYNN-ON browser tab is visible.
     */
    function isPageActive() {
        return document.visibilityState === 'visible';
    }

    /*
     * ---------------------------------------------------------------
     * Idle logout
     * ---------------------------------------------------------------
     *
     * Screen time and idleness are two different questions. A tab left
     * visible on an unattended desk is still accruing screen time, so
     * `isPageActive()` cannot answer "is anyone actually there?" — only
     * real input can. Everything below tracks that separately.
     *
     * The browser half is UX: it warns before signing the user out so
     * unsaved work is not lost, and performs a clean POST logout. The
     * server (App\Http\Middleware\EnforceIdleTimeout) is the authority
     * and will refuse a stale session regardless of what this does.
     *
     * The meta tags are emitted for the LMS panel only, so this whole
     * block stays dormant in the Academy and Demo portals.
     */
    function metaContent(name) {
        return document
            .querySelector('meta[name="' + name + '"]')
            ?.getAttribute('content') ?? null;
    }

    const idleTimeoutSeconds = parseInt(metaContent('idle-timeout-seconds') ?? '0', 10);
    const idleWarningSeconds = parseInt(metaContent('idle-warning-seconds') ?? '60', 10);
    const idleLogoutUrl = metaContent('idle-logout-url');

    const idleLogoutEnabled = idleTimeoutSeconds > 0 && !!idleLogoutUrl;

    /*
     * Interaction since the last heartbeat, reported to the server so it
     * can advance last_activity_at.
     */
    let interactedSinceLastBeat = true;

    /*
     * Seconds of idleness allowed before logout. Re-synced from every
     * heartbeat response so a throttled or slept tab cannot drift away
     * from the server's own view.
     */
    let secondsUntilLogout = idleTimeoutSeconds;

    let warningShown = false;
    let loggingOut = false;

    /*
     * Interval handles, so the timers can be stopped outright once the
     * server tells us this login session is over. Leaving them running
     * would keep firing requests at a session that no longer exists.
     */
    let heartbeatTimer = null;
    let idleCountdownTimer = null;

    /*
     * A reload is only ever worth doing ONCE.
     *
     * The server ending a login session (the idle sweeper, or a login
     * from another device) does not by itself end this browser's auth
     * session, so the page that comes back can easily be the same
     * authenticated page we were already on. Reloading again on the next
     * heartbeat is what produced an endless refresh every few seconds.
     * The flag lives in sessionStorage precisely because it has to
     * survive the reload it is guarding.
     */
    const RELOADED_KEY = 'fynnon.session-ended-reload';

    function stopHeartbeat() {

        if (heartbeatTimer !== null) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }

        if (idleCountdownTimer !== null) {
            clearInterval(idleCountdownTimer);
            idleCountdownTimer = null;
        }

        dismissWarning();
    }

    function hasAlreadyReloaded() {
        try {
            return sessionStorage.getItem(RELOADED_KEY) === '1';
        } catch (error) {
            /*
             * Private mode or blocked storage: without somewhere to keep
             * the flag we cannot prove we have not reloaded already, so
             * treat it as "yes" and never reload. A stale page is a far
             * smaller problem than a refresh loop.
             */
            return true;
        }
    }

    function rememberReload() {
        try {
            sessionStorage.setItem(RELOADED_KEY, '1');
        } catch (error) {
            // Nothing to do — hasAlreadyReloaded() fails closed.
        }
    }

    function forgetReload() {
        try {
            sessionStorage.removeItem(RELOADED_KEY);
        } catch (error) {
            // Nothing to do.
        }
    }

    /**
     * The server says this login session is finished. Stop beating, and
     * reload once so the panel's own middleware can send the user to the
     * login page — but never more than once per browser session.
     */
    function handleSessionEnded() {

        stopHeartbeat();

        if (loggingOut || hasAlreadyReloaded()) {
            return;
        }

        loggingOut = true;
        rememberReload();

        window.location.reload();
    }

    function markInteraction() {
        interactedSinceLastBeat = true;
        secondsUntilLogout = idleTimeoutSeconds;

        if (warningShown) {
            dismissWarning();
        }
    }

    if (idleLogoutEnabled) {
        [
            'mousedown',
            'mousemove',
            'keydown',
            'wheel',
            'scroll',
            'touchstart',
            'pointerdown',
        ].forEach((eventName) => {
            document.addEventListener(eventName, markInteraction, {
                passive: true,
                capture: true,
            });
        });
    }

    /**
     * Sign the user out the same way the user menu does: a POST to the
     * panel's logout route, carrying the CSRF token.
     */
    function logoutForIdle() {

        if (loggingOut) {
            return;
        }

        loggingOut = true;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = idleLogoutUrl;
        form.style.display = 'none';

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    }

    function dismissWarning() {
        warningShown = false;
        document.getElementById('fynnon-idle-warning')?.remove();
    }

    function showWarning(secondsLeft) {

        warningShown = true;

        let banner = document.getElementById('fynnon-idle-warning');

        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'fynnon-idle-warning';
            banner.setAttribute('role', 'alert');
            banner.setAttribute('aria-live', 'assertive');
            banner.style.cssText = [
                'position:fixed',
                'z-index:2147483647',
                'left:50%',
                'top:1.25rem',
                'transform:translateX(-50%)',
                'display:flex',
                'align-items:center',
                'gap:0.75rem',
                'max-width:min(92vw,34rem)',
                'padding:0.75rem 1rem',
                'border-radius:0.75rem',
                'border:1px solid rgba(245,158,11,0.45)',
                'background:#fffbeb',
                'color:#7c2d12',
                'font:500 0.875rem/1.35 ui-sans-serif,system-ui,sans-serif',
                'box-shadow:0 10px 30px rgba(0,0,0,0.18)',
            ].join(';');

            const text = document.createElement('span');
            text.id = 'fynnon-idle-warning-text';
            text.style.flex = '1';

            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = "I'm still here";
            button.style.cssText = [
                'flex:none',
                'cursor:pointer',
                'padding:0.375rem 0.75rem',
                'border-radius:0.5rem',
                'border:0',
                'background:#a6d900',
                'color:#1b1b1b',
                'font:600 0.8125rem/1 ui-sans-serif,system-ui,sans-serif',
            ].join(';');

            button.addEventListener('click', () => {
                markInteraction();
                sendHeartbeat();
            });

            banner.appendChild(text);
            banner.appendChild(button);
            document.body.appendChild(banner);
        }

        const label = document.getElementById('fynnon-idle-warning-text');

        if (label) {
            label.textContent =
                'You will be signed out in ' +
                Math.max(0, Math.round(secondsLeft)) +
                's due to inactivity.';
        }
    }

    /*
     * One-second tick: counts down locally between heartbeats so the
     * warning is accurate to the second without a request per second.
     */
    if (idleLogoutEnabled) {
        idleCountdownTimer = setInterval(() => {

            if (loggingOut) {
                return;
            }

            secondsUntilLogout -= 1;

            if (secondsUntilLogout <= 0) {
                dismissWarning();
                logoutForIdle();

                return;
            }

            if (secondsUntilLogout <= idleWarningSeconds) {
                showWarning(secondsUntilLogout);
            }
        }, 1000);
    }

    /**
     * Send heartbeat to Laravel.
     */
    async function sendHeartbeat() {

        if (heartbeatInProgress) {
            return;
        }

        heartbeatInProgress = true;

        try {
            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch(heartbeatUrl, {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },

                credentials: 'same-origin',

                body: JSON.stringify({
                    active: isPageActive(),
                    interacted: interactedSinceLastBeat,
                }),
            });

            /*
             * The server has already closed this login session — most
             * likely the idle sweeper, or a login from another device —
             * or the request was not authenticated at all. Either way
             * there is nothing left to beat against.
             */
            if (response.status === 401 || response.status === 404) {
                handleSessionEnded();

                return;
            }

            if (!response.ok) {
                console.warn(
                    'Login heartbeat failed:',
                    response.status
                );

                return;
            }

            const data = await response.json();

            /*
             * A healthy heartbeat means this is a live session, so arm
             * the reload again for whenever this one genuinely ends.
             */
            forgetReload();

            /*
             * Only cleared once the server has actually been told, so a
             * failed request never loses an interaction.
             */
            interactedSinceLastBeat = false;

            /*
             * Trust the server's countdown over the local one.
             */
            if (idleLogoutEnabled && typeof data.seconds_until_logout === 'number') {

                secondsUntilLogout = data.seconds_until_logout;

                if (secondsUntilLogout > idleWarningSeconds && warningShown) {
                    dismissWarning();
                }
            }

        } catch (error) {

            /*
             * Network failure should NOT log the employee out.
             */
            console.warn(
                'Login heartbeat network error:',
                error
            );

        } finally {
            heartbeatInProgress = false;
        }
    }

    /*
     * Send the first heartbeat shortly after page load.
     */
    setTimeout(() => {
        sendHeartbeat();
    }, 5000);

    /*
     * Regular heartbeat.
     */
    heartbeatTimer = setInterval(() => {
        sendHeartbeat();
    }, HEARTBEAT_INTERVAL);

    /*
     * When employee comes back to FYNN-ON after switching tabs,
     * immediately send an active heartbeat.
     */
    document.addEventListener(
        'visibilitychange',
        () => {

            if (document.visibilityState === 'visible') {
                sendHeartbeat();
            }
        }
    );

})();
