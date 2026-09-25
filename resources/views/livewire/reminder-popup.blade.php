{{--
    The reminder pop-up (App\Livewire\ReminderPopup). Not blocking: Skip is
    always one click away. Polls so a reminder that comes due while the page
    is open still pops up.

    Every block that can be swapped for another carries a wire:key — see
    .ai/rules/livewire.md: a reused <input> keeps the binding it was first
    initialised with.
--}}
<div wire:poll.60s>
    @if ($current)
        @php
            $actions = $filament?->getActions() ?? [];
            $openUrl = collect($actions)->map(fn ($action) => $action->getUrl())->filter()->first();
        @endphp

        <div
            wire:key="reminder-{{ $current->getKey() }}"
            class="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto bg-gray-950/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reminder-popup-heading"
        >
            <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start gap-3 border-b border-gray-200 px-6 py-4 dark:border-white/10">
                    <x-filament::icon
                        :icon="$category->icon()"
                        class="mt-0.5 h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                    />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge size="sm" color="primary">{{ $category->label() }}</x-filament::badge>

                            @if ($pendingCount > 1)
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    1 of {{ $pendingCount }} pending reminders
                                </span>
                            @endif
                        </div>

                        <h2 id="reminder-popup-heading" class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                            {{ $filament?->getTitle() }}
                        </h2>

                        @if (filled($filament?->getBody()))
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $filament->getBody() }}</p>
                        @endif

                        <p class="mt-1 text-xs text-gray-400">{{ $current->created_at?->diffForHumans() }}</p>
                    </div>
                </div>

                <div class="space-y-3 px-6 py-4">
                    @if ($mode === 'close' || $mode === 'drop')
                        <div wire:key="reminder-remarks-{{ $mode }}">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                {{ $mode === 'drop' ? 'Why drop this follow-up?' : 'Closing remarks' }}
                                <textarea
                                    wire:model="remarks"
                                    rows="3"
                                    class="mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                ></textarea>
                            </label>
                            @error('remarks') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($mode === 'reschedule')
                        <div wire:key="reminder-reschedule" class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                Remind me again on
                                <input
                                    type="datetime-local"
                                    wire:model="rescheduleAt"
                                    min="{{ now()->format('Y-m-d\TH:i') }}"
                                    class="mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                />
                            </label>
                            @error('rescheduleAt') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror

                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                Remarks (optional)
                                <textarea
                                    wire:model="remarks"
                                    rows="2"
                                    class="mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                ></textarea>
                            </label>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($mode === 'close')
                            <x-filament::button wire:key="reminder-confirm-close" size="sm" color="success" icon="heroicon-o-check" wire:click="closeReminder('{{ $current->getKey() }}')">
                                Close with remarks
                            </x-filament::button>
                        @elseif ($mode === 'drop')
                            <x-filament::button wire:key="reminder-confirm-drop" size="sm" color="danger" icon="heroicon-o-x-circle" wire:click="dropFollowUp('{{ $current->getKey() }}')">
                                Drop follow-up
                            </x-filament::button>
                        @elseif ($mode === 'reschedule')
                            <x-filament::button wire:key="reminder-confirm-reschedule" size="sm" color="warning" icon="heroicon-o-clock" wire:click="rescheduleReminder('{{ $current->getKey() }}')">
                                Reschedule
                            </x-filament::button>
                        @else
                            <x-filament::button wire:key="reminder-open-close" size="sm" color="success" icon="heroicon-o-check" wire:click="openMode('close')">
                                Close
                            </x-filament::button>
                            <x-filament::button wire:key="reminder-open-reschedule" size="sm" color="warning" icon="heroicon-o-clock" wire:click="openMode('reschedule')">
                                Reschedule
                            </x-filament::button>
                            @if ($isFollowUp)
                                <x-filament::button wire:key="reminder-open-drop" size="sm" color="danger" icon="heroicon-o-x-circle" wire:click="openMode('drop')">
                                    Drop
                                </x-filament::button>
                            @endif
                            <x-filament::button wire:key="reminder-skip" size="sm" color="gray" wire:click="skipReminder('{{ $current->getKey() }}')">
                                Skip
                            </x-filament::button>
                        @endif

                        @if ($mode)
                            <x-filament::link wire:key="reminder-cancel" tag="button" size="sm" color="gray" wire:click="cancelMode">
                                Back
                            </x-filament::link>
                        @elseif ($openUrl)
                            <x-filament::link wire:key="reminder-open-link" :href="$openUrl" size="sm" icon="heroicon-m-arrow-top-right-on-square" class="ms-auto">
                                Open
                            </x-filament::link>
                        @endif
                    </div>
                </div>

                @if (! $mode && ($pendingCount > 1 || $overdueCount > 0))
                    <div wire:key="reminder-bulk" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-200 bg-gray-50 px-6 py-3 text-sm dark:border-white/10 dark:bg-white/5">
                        @if ($pendingCount > 1)
                            <x-filament::link
                                tag="button"
                                size="sm"
                                color="gray"
                                wire:click="skipAllReminders"
                                wire:confirm="Skip all {{ $pendingCount }} pending reminders?"
                            >
                                Skip all {{ $pendingCount }}
                            </x-filament::link>
                        @endif

                        @if ($overdueCount > 0)
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $overdueCount }} overdue {{ str('follow-up')->plural($overdueCount) }}
                            </span>

                            <x-filament::link
                                tag="button"
                                size="sm"
                                color="danger"
                                wire:click="dropOverdueFollowUps"
                                wire:confirm="Drop all {{ $overdueCount }} overdue follow-ups? They come off every calendar and are logged as Dropped."
                            >
                                Drop all overdue
                            </x-filament::link>

                            @if ($followUpsUrl)
                                <x-filament::link :href="$followUpsUrl" size="sm">Review list</x-filament::link>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
