{{--
    /demo only: the floating "View as" switcher, pinned bottom-right.

    A compact pill showing that this is the demo and who is signed in; a
    click opens (upwards) every active demo login grouped by role (Admin,
    Business Head, Cluster Manager, Manager, Team Leader, Caller, ...), with
    a search box and a "Back to Admin" shortcut. Each item POSTs to
    demo.switch-user / demo.switch-role — see SwitchDemoRoleController.

    Floating rather than in the topbar or sidebar, so the admin layout
    (and its top-performer marquee) is left exactly as it is on /admin.
    It can be dragged anywhere on screen so it never covers what is being
    shown; the spot is remembered per browser.
--}}
@php
    $user = filament()->auth()->user();
    $logins = config('demo.role_logins', []);
    $groups = app(\App\Support\Demo\DemoLoginSwitcher::class)->usersByRole();
    $currentRole = $user?->roles->pluck('name')->first() ?? 'Demo user';
    $isAdminLogin = $user?->email === ($logins['admin']['email'] ?? null);
@endphp

<div
    class="demo-view-as"
    @include('filament.demo.partials.draggable', ['storageKey' => 'fynnon.demo-view-as-position'])
>
    <x-filament::dropdown placement="top-end" max-height="26rem" width="sm" teleport>
        <x-slot name="trigger">
            <button type="button" class="demo-view-as__pill" title="Demo environment — switch user">
                <span class="demo-view-as__demo">
                    <span class="demo-view-as__dot"></span>
                    Demo
                </span>
                <span class="demo-view-as__who">
                    <span class="demo-view-as__name">{{ $user?->name }}</span>
                    <span class="demo-view-as__role">{{ $currentRole }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-arrows-right-left" class="demo-view-as__icon" />
            </button>
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-m-user-circle">
            Viewing as {{ $user?->name }} ({{ $currentRole }})
        </x-filament::dropdown.header>

        <div x-data="{ search: '' }">
            <div class="p-2">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="search"
                        x-model="search"
                        placeholder="Search name, role or employee id"
                        x-on:keydown.stop
                    />
                </x-filament::input.wrapper>
            </div>

            @unless ($isAdminLogin)
                <x-filament::dropdown.list x-show="search === ''">
                    <x-filament::dropdown.list.item
                        tag="form"
                        method="post"
                        :action="route('demo.switch-role', ['role' => 'admin'])"
                        icon="heroicon-m-arrow-uturn-left"
                        color="primary"
                    >
                        Back to Admin
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            @endunless

            @foreach ($groups as $role => $members)
                @php
                    $haystacks = $members->mapWithKeys(fn ($member): array => [
                        $member->getKey() => strtolower($member->name.' '.$role.' '.($member->employee?->emp_id ?? '').' '.$member->email),
                    ]);
                @endphp

                <div
                    x-data="{ haystacks: @js($haystacks->values()) }"
                    x-show="search === '' || haystacks.some((text) => text.includes(search.toLowerCase()))"
                >
                    <div class="px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $role }} ({{ $members->count() }})
                    </div>

                    <x-filament::dropdown.list>
                        @foreach ($members as $member)
                            @php
                                $isCurrent = $member->is($user);
                            @endphp

                            <div x-show="search === '' || @js($haystacks[$member->getKey()]).includes(search.toLowerCase())">
                                <x-filament::dropdown.list.item
                                    tag="form"
                                    method="post"
                                    :action="route('demo.switch-user', ['user' => $member->getKey()])"
                                    :icon="$isCurrent ? 'heroicon-m-check-circle' : 'heroicon-m-user'"
                                    :color="$isCurrent ? 'primary' : 'gray'"
                                >
                                    {{ $member->name }}
                                    @if ($member->employee?->emp_id)
                                        <span class="text-xs text-gray-400">· {{ $member->employee->emp_id }}</span>
                                    @endif
                                </x-filament::dropdown.list.item>
                            </div>
                        @endforeach
                    </x-filament::dropdown.list>
                </div>
            @endforeach
        </div>
    </x-filament::dropdown>

    @if (session('demo_role_switch_error'))
        <div class="demo-view-as__error">{{ session('demo_role_switch_error') }}</div>
    @endif
</div>

<style>
    .demo-view-as {
        position: fixed;
        right: 1.25rem;
        bottom: 1.25rem;
        z-index: 30;
        touch-action: none;
        user-select: none;
    }

    .demo-view-as.is-dragging .demo-view-as__pill {
        cursor: grabbing;
        transform: none;
    }

    .demo-view-as__pill {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        padding: 0.4rem 0.9rem 0.4rem 0.45rem;
        border-radius: 9999px;
        background: rgb(17 24 39);
        border: 1px solid rgb(245 158 11 / 0.7);
        box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.35);
        color: rgb(255 255 255);
        cursor: grab;
        transition: transform 150ms, box-shadow 150ms;
    }

    .demo-view-as__pill:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 30px -6px rgb(0 0 0 / 0.45);
    }

    .demo-view-as__demo {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.55rem;
        border-radius: 9999px;
        background: rgb(251 191 36);
        color: rgb(17 24 39);
        font-size: 0.6875rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .demo-view-as__dot {
        width: 0.375rem;
        height: 0.375rem;
        border-radius: 9999px;
        background: rgb(17 24 39);
    }

    .demo-view-as__who {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        line-height: 1.15;
        text-align: left;
    }

    .demo-view-as__name {
        max-width: 11rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.8125rem;
        font-weight: 700;
    }

    .demo-view-as__role {
        font-size: 0.6875rem;
        color: rgb(252 211 77);
    }

    .demo-view-as__icon {
        width: 1rem;
        height: 1rem;
        color: rgb(252 211 77);
    }

    .demo-view-as__error {
        margin-top: 0.5rem;
        max-width: 16rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        background: rgb(127 29 29);
        color: rgb(254 226 226);
        font-size: 0.75rem;
    }
</style>
