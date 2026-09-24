{{--
    The one visual difference between /demo and /admin: a persistent
    "DEMO ENVIRONMENT" marker, rendered by DemoPanelProvider on every page
    (topbar) and on the login screen, so nobody mistakes the demo copy for
    the live system.
--}}
<span class="demo-environment-badge" title="Demo environment — separate demo database. Nothing here affects live data.">
    <span class="demo-environment-badge__dot"></span>
    Demo environment
</span>

<style>
    .demo-environment-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        margin-inline: 0.5rem;
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: rgb(180 83 9);
        background: rgb(245 158 11 / 0.16);
        border: 1px solid rgb(245 158 11 / 0.45);
        white-space: nowrap;
    }

    .demo-environment-badge__dot {
        width: 0.4375rem;
        height: 0.4375rem;
        border-radius: 9999px;
        background: rgb(245 158 11);
    }

    .dark .demo-environment-badge {
        color: rgb(252 211 77);
    }
</style>
