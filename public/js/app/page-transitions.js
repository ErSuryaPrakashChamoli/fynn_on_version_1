(() => {
    'use strict';

    /*
     * Registered with `navigateOnce`, so this only runs once per SPA
     * session — guard anyway in case that ever changes.
     */
    if (window.__fynnPageTransitionsInitialized) {
        return;
    }

    window.__fynnPageTransitionsInitialized = true;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function playEnterAnimation() {
        if (reducedMotion.matches) {
            return;
        }

        const main = document.getElementById('fi-main-content');

        if (!main) {
            return;
        }

        /*
         * The `#fi-main-content` node itself survives wire:navigate morphs
         * (only its children are swapped), so a plain CSS animation class
         * won't replay on its own — remove and re-add it, forcing a reflow
         * in between so the browser treats it as a fresh animation.
         */
        main.classList.remove('fynn-page-enter');
        void main.offsetWidth;
        main.classList.add('fynn-page-enter');
    }

    document.addEventListener('livewire:navigated', playEnterAnimation);
})();
