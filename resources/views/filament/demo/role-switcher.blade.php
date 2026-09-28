{{--
    /demo only: the "View as" switcher, opened from the "Switch demo user"
    entry under My Profile in the user menu (filament.demo.user-menu-switcher).

    A modal listing every active demo login grouped by role (Admin,
    Business Head, Cluster Manager, Manager, Team Leader, Caller, ...), with
    a search box and a "Back to Admin" shortcut. Each item POSTs to
    demo.switch-user / demo.switch-role — see SwitchDemoRoleController.

    Rendered at BODY_END rather than inside the user menu because the menu
    is a dropdown that closes on click; the modal has to outlive it. It
    replaced a floating, draggable bottom-right pill on 2026-09-27.
--}}
@php
    $user = filament()->auth()->user();
    $logins = config('demo.role_logins', []);
    $groups = app(\App\Support\Demo\DemoLoginSwitcher::class)->usersByRole();
    $currentRole = $user?->roles->pluck('name')->first() ?? 'Demo user';
    $isAdminLogin = $user?->email === ($logins['admin']['email'] ?? null);
    $switchError = session('demo_role_switch_error');
@endphp

<div
    class="demo-view-as"
    @if ($switchError)
        x-data
        x-init="$nextTick(() => $dispatch('open-modal', { id: 'demo-view-as' }))"
    @endif
>
    <x-filament::modal
        id="demo-view-as"
        width="md"
        icon="heroicon-m-arrows-right-left"
        icon-color="warning"
        heading="Switch demo user"
        :description="'Viewing as '.$user?->name.' ('.$currentRole.')'"
    >
        @if ($switchError)
            <div class="demo-view-as__error">{{ $switchError }}</div>
        @endif

        <div x-data="{ search: '' }" class="demo-view-as__list">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="search"
                    x-model="search"
                    placeholder="Search name, role or employee id"
                    x-on:keydown.stop
                />
            </x-filament::input.wrapper>

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
                    <div class="px-1 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
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
    </x-filament::modal>
</div>

<style>
    .demo-view-as__list {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        max-height: 60vh;
        overflow-y: auto;
        padding-inline: 0.125rem;
    }

    .demo-view-as__error {
        margin-bottom: 0.75rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        background: rgb(127 29 29);
        color: rgb(254 226 226);
        font-size: 0.75rem;
    }
</style>
