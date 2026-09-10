{{--
    Academy-only chrome.

    Emitted through the panel's STYLES_AFTER render hook rather than a
    Vite theme, so the portal needs no new build input and cannot affect
    the admin panel's compiled stylesheet. Everything is scoped under
    .fi-body so it can only ever apply inside a Filament panel page, and
    the panel these rules load in is the Academy alone.
--}}
<style>
    .academy-timeline {
        --academy-line: var(--gray-200, #DDE1D8);
    }

    .dark .academy-timeline {
        --academy-line: var(--gray-700, #383C37);
    }

    .academy-step {
        position: relative;
        padding-left: 1.75rem;
        padding-bottom: 1rem;
    }

    .academy-step::before {
        content: '';
        position: absolute;
        left: 0.3125rem;
        top: 1.25rem;
        bottom: 0;
        width: 2px;
        background: var(--academy-line);
    }

    .academy-step:last-child::before {
        display: none;
    }

    .academy-step__dot {
        position: absolute;
        left: 0;
        top: 0.3125rem;
        width: 0.75rem;
        height: 0.75rem;
        border-radius: 9999px;
        border: 2px solid var(--academy-line);
        background: var(--gray-50, #F7F8F6);
    }

    .academy-step--done .academy-step__dot {
        background: rgb(var(--success-600, 143 190 0));
        border-color: rgb(var(--success-600, 143 190 0));
    }

    .academy-step--current .academy-step__dot {
        background: rgb(var(--primary-500, 166 217 0));
        border-color: rgb(var(--primary-500, 166 217 0));
        box-shadow: 0 0 0 4px rgb(var(--primary-500, 166 217 0) / 0.18);
    }

    .academy-progress {
        height: 0.5rem;
        border-radius: 9999px;
        background: var(--academy-line);
        overflow: hidden;
    }

    .academy-progress__bar {
        height: 100%;
        border-radius: 9999px;
        background: linear-gradient(90deg, rgb(var(--primary-500, 166 217 0)), rgb(var(--primary-700, 127 175 0)));
        transition: width 240ms ease;
    }
</style>
