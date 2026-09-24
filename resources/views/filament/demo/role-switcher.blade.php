{{--
    /demo only: the "View as" card at the top of the sidebar.

    Shows that this is the demo environment and who is signed in, and a
    "Switch user" menu listing every active demo login grouped by role
    (Admin, Business Head, Cluster Manager, Manager, Team Leader, Caller,
    ...), with a search box and a "Back to Admin" shortcut. Each item POSTs
    to demo.switch-user / demo.switch-role — see SwitchDemoRoleController.

    Lives in the sidebar (not the topbar) so it never crowds the
    top-performer marquee.
--}}
@php
    $user = filament()->auth()->user();
    $logins = config('demo.role_logins', []);
    $groups = app(\App\Support\Demo\DemoLoginSwitcher::class)->usersByRole();
    $currentRole = $user?->roles->pluck('name')->first() ?? 'Demo user';
    $isAdminLogin = $user?->email === ($logins['admin']['email'] ?? null);
@endphp

<div class="demo-view-as">
    <div class="demo-view-as__badge">
        <span class="demo-view-as__dot"></span>
        Demo environment
    </div>

    <div class="demo-view-as__label">Viewing as</div>
    <div class="demo-view-as__name" title="{{ $user?->email }}">{{ $user?->name }}</div>
    <div class="demo-view-as__role">{{ $currentRole }}</div>

    <x-filament::dropdown placement="bottom-start" max-height="30rem" width="sm" teleport>
        <x-slot name="trigger">
            <button type="button" class="demo-view-as__button">
                <x-filament::icon icon="heroicon-m-arrows-right-left" class="h-4 w-4" />
                Switch user
            </button>
        </x-slot>

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
        margin: 0.75rem 0.75rem 0.5rem;
        padding: 0.75rem 0.875rem;
        border-radius: 0.75rem;
        background: rgb(245 158 11 / 0.10);
        border: 1px solid rgb(245 158 11 / 0.45);
        color: rgb(255 255 255);
    }

    .demo-view-as__badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        margin-bottom: 0.5rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: rgb(252 211 77);
    }

    .demo-view-as__dot {
        width: 0.4375rem;
        height: 0.4375rem;
        border-radius: 9999px;
        background: rgb(245 158 11);
    }

    .demo-view-as__label {
        font-size: 0.6875rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: rgb(255 255 255 / 0.6);
    }

    .demo-view-as__name {
        font-size: 0.9375rem;
        font-weight: 700;
        line-height: 1.3;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .demo-view-as__role {
        margin-bottom: 0.625rem;
        font-size: 0.8125rem;
        color: rgb(252 211 77);
    }

    .demo-view-as__button {
        display: flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: rgb(17 24 39);
        background: rgb(251 191 36);
        transition: background 150ms;
    }

    .demo-view-as__button:hover {
        background: rgb(252 211 77);
    }

    .demo-view-as__error {
        margin-top: 0.5rem;
        font-size: 0.75rem;
        color: rgb(254 202 202);
    }
</style>
