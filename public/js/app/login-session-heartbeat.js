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
                }),
            });

            /*
             * If Laravel says the session no longer exists,
             * stop sending heartbeats.
             */
            if (response.status === 401 || response.status === 404) {
                console.warn(
                    'Login session is no longer active.'
                );

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
             * Useful for development/debugging.
             *
             * Remove this later if you don't want console output.
             */
            console.debug(
                'Login heartbeat:',
                data
            );

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
    setInterval(() => {
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
