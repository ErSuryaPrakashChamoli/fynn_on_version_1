{{--
    Sandbox-only chrome. Same reasoning as the Academy's: a render hook
    rather than a Vite theme, so no build step and no shared stylesheet
    with the admin panel.
--}}
<style>
    .demo-sandbox-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: rgb(var(--warning-700, 180 83 9));
        background: rgb(var(--warning-500, 245 158 11) / 0.14);
        border: 1px solid rgb(var(--warning-500, 245 158 11) / 0.35);
        white-space: nowrap;
    }

    .demo-sandbox-badge__dot {
        width: 0.4375rem;
        height: 0.4375rem;
        border-radius: 9999px;
        background: rgb(var(--warning-500, 245 158 11));
    }

    .demo-metric-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    }
</style>
