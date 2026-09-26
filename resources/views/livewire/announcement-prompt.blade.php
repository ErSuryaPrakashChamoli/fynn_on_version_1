{{--
    The announcement block (App\Livewire\AnnouncementPrompt). Deliberately not
    a Filament modal: it cannot be dismissed, closed on escape or clicked
    past — acknowledging is the only way back into the LMS. Polls so an
    announcement sent while the page is open still blocks it.
--}}
<div wire:poll.30s>
    @if ($current)
        @php
            $announcement = $current->announcement;
            $accent = match ($announcement->level) {
                'success' => 'text-success-600 dark:text-success-400',
                'warning' => 'text-warning-600 dark:text-warning-400',
                'danger' => 'text-danger-600 dark:text-danger-400',
                default => 'text-info-600 dark:text-info-400',
            };
        @endphp

        <div
            wire:key="announcement-{{ $current->getKey() }}"
            class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-gray-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="announcement-prompt-heading"
        >
            <div class="w-full max-w-xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start gap-3 border-b border-gray-200 px-6 py-5 dark:border-white/10">
                    <x-filament::icon
                        icon="heroicon-o-megaphone"
                        @class(['mt-0.5 h-7 w-7 shrink-0', $accent])
                    />

                    <div class="min-w-0 flex-1">
                        <p @class(['text-xs font-semibold uppercase tracking-wide', $accent])>
                            {{ \App\Models\Announcement::LEVELS[$announcement->level] ?? 'Announcement' }}
                            @if ($pendingCount > 1)
                                <span class="font-normal text-gray-400">· 1 of {{ $pendingCount }}</span>
                            @endif
                        </p>

                        <h2 id="announcement-prompt-heading" class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $announcement->title }}
                        </h2>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $announcement->creator?->name ?? 'Admin' }} · {{ $announcement->created_at?->format('d M Y h:i A') }}
                        </p>
                    </div>
                </div>

                <div class="max-h-[50vh] overflow-y-auto px-6 py-5">
                    <p class="whitespace-pre-line break-words text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $announcement->message }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        You can read this again any time from the notification bell.
                    </p>

                    <x-filament::button
                        wire:click="acknowledgeAnnouncement({{ $current->getKey() }})"
                        wire:loading.attr="disabled"
                        icon="heroicon-m-check-circle"
                    >
                        I have read this
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
