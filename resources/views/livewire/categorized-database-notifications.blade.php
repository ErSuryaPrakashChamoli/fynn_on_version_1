{{--
    Filament's database-notifications modal (vendor/filament/notifications/
    resources/views/database-notifications.blade.php) with a tab bar added
    for App\Livewire\CategorizedDatabaseNotifications. Re-sync with the
    vendor view when upgrading Filament.
--}}
@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    // Keep the tab bar up while a filtered tab is empty, so the user can switch back.
    $hasNotifications = $notifications->count() || $this->activeCategory !== 'all';
    $categoryTabs = $hasNotifications ? $this->getCategoryTabs() : [];
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div class="fi-no-database">
    {{-- The focus trap autofocuses the modal window itself when the slide-over opens, since the first tabbable element is the `Mark all as read` header action, which `Enter` would otherwise immediately (and irreversibly) trigger. The window must carry the `autofocus` attribute because the focus trap resolves it once, when the modal first initializes, and the window is always rendered. --}}
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        aria-labelledby="database-notifications.heading"
        close-button
        :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
        :extra-modal-window-attribute-bag="
            new \Filament\Support\View\ComponentAttributeBag([
                'autofocus' => true,
                'tabindex' => '-1',
            ])
        "
        :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
        :icon="$hasNotifications ? null : \Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        :icon-alias="
            $hasNotifications
            ? null
            : \Filament\Notifications\View\NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE
        "
        :icon-color="$hasNotifications ? null : 'gray'"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new \Filament\Support\View\ComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div>
                    <h2
                        id="database-notifications.heading"
                        class="fi-modal-heading"
                    >
                        {{ __('filament-notifications::database.modal.heading') }}

                        @if ($unreadNotificationsCount)
                            <span
                                {{
                                    (new FilamentComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    <div class="fi-ac">
                        @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        @if ($this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif
                    </div>
                </div>
            </x-slot>

            @if (count($categoryTabs) > 1)
                <div
                    wire:key="database-notifications.tabs"
                    class="-mx-1 mb-3 flex gap-1 overflow-x-auto px-1 pb-1"
                    role="tablist"
                    aria-label="Notification types"
                >
                    @foreach ($categoryTabs as $value => $tab)
                        <button
                            type="button"
                            role="tab"
                            wire:key="database-notifications.tab.{{ $value }}"
                            wire:click="showCategory('{{ $value }}')"
                            aria-selected="{{ $this->activeCategory === $value ? 'true' : 'false' }}"
                            @class([
                                'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition',
                                'bg-primary-50 text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400' => $this->activeCategory === $value,
                                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->activeCategory !== $value,
                            ])
                        >
                            @if ($tab['icon'])
                                <x-filament::icon :icon="$tab['icon']" class="h-4 w-4" />
                            @endif
                            {{ $tab['label'] }}
                            @if ($tab['unread'])
                                <span
                                    {{
                                        (new FilamentComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                            'fi-badge fi-size-xs',
                                        ])
                                    }}
                                >
                                    {{ $tab['unread'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            @if (! $notifications->count())
                <p wire:key="database-notifications.tab-empty" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No notifications of this type.
                </p>
            @endif

            <div
                aria-label="{{ __('filament-notifications::database.modal.heading') }}"
                role="list"
                class="fi-no-notifications"
            >
                @foreach ($notifications as $notification)
                    <div
                        role="listitem"
                        wire:key="{{ $notification->getKey() }}.database-notifications.ctn"
                        @class([
                            'fi-no-notification-read-ctn' => ! $notification->unread(),
                            'fi-no-notification-unread-ctn' => $notification->unread(),
                        ])
                    >
                        @if ($notification->unread())
                            <span class="fi-sr-only">
                                {{ __('filament-notifications::database.modal.unread_label') }}
                            </span>
                        @endif

                        {{ $this->getNotification($notification)->inline() }}
                    </div>
                @endforeach
            </div>

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>
