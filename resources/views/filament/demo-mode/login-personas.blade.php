{{--
    Demo mode only (see App\Providers\DemoModeServiceProvider): one-click
    sign-in as each seeded persona, under the ordinary login form. Every
    button is a plain POST to the persona switch route.
--}}
<style>
    .fynn-demo-personas {
        margin-top: 1.75rem;
        padding-top: 1.25rem;
        border-top: 1px dashed rgb(203 213 225);
    }

    .fynn-demo-personas-title {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        margin-bottom: 0.25rem;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: rgb(71 85 105);
    }

    .fynn-demo-personas-hint {
        margin-bottom: 0.875rem;
        text-align: center;
        font-size: 0.8rem;
        color: rgb(100 116 139);
    }

    .fynn-demo-personas-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }

    @media (min-width: 640px) {
        .fynn-demo-personas-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .fynn-demo-persona {
        width: 100%;
        padding: 0.55rem 0.5rem;
        border-radius: 0.5rem;
        border: 1px solid rgb(226 232 240);
        background: rgb(248 250 252);
        font-size: 0.8125rem;
        font-weight: 600;
        color: rgb(30 41 59);
        cursor: pointer;
        transition: border-color 120ms, background-color 120ms, box-shadow 120ms;
    }

    .fynn-demo-persona:hover,
    .fynn-demo-persona:focus-visible {
        border-color: var(--primary-500);
        background: var(--primary-50);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-400) 25%, transparent);
        outline: none;
    }
</style>

<div class="fynn-demo-personas">
    <p class="fynn-demo-personas-title">
        <x-filament::icon icon="heroicon-o-play-circle" class="h-4 w-4" />
        Explore the demo as
    </p>
    <p class="fynn-demo-personas-hint">Sample data only. Switch roles any time from the top bar.</p>

    <div class="fynn-demo-personas-grid">
        @foreach ($personas as $slug => $persona)
            <form method="POST" action="{{ route('demo-persona.switch', $slug) }}">
                @csrf
                <button type="submit" class="fynn-demo-persona" title="{{ $persona['description'] }}">
                    {{ $persona['label'] }}
                </button>
            </form>
        @endforeach
    </div>
</div>
