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
            $tile = match ($announcement->level) {
                'success' => 'bg-success-50 text-success-600 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/25',
                'warning' => 'bg-warning-50 text-warning-600 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/25',
                'danger' => 'bg-danger-50 text-danger-600 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/25',
                default => 'bg-info-50 text-info-600 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/25',
            };
        @endphp

        <div
            wire:key="announcement-{{ $current->getKey() }}"
            class="fixed inset-0 z-[9999] flex items-center-safe justify-center overflow-y-auto bg-gray-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="announcement-prompt-heading"
        >
            <div class="fynn-card fynn-card--flush w-full max-w-xl shadow-2xl">
                <div class="flex items-start gap-4 border-b border-gray-950/5 px-6 py-5 dark:border-white/10" style="background: linear-gradient(180deg, var(--fynn-surface-2), var(--fynn-surface))">
                    <span @class(['flex size-11 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset', $tile])>
                        <x-filament::icon icon="heroicon-o-megaphone" class="size-6" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p @class(['text-xs font-bold uppercase tracking-wider', $accent])>
                                {{ \App\Models\Announcement::LEVELS[$announcement->level] ?? 'Announcement' }}
                            </p>

                            @if ($pendingCount > 1)
                                <span class="fynn-chip">1 of {{ $pendingCount }}</span>
                            @endif
                        </div>

                        <h2 id="announcement-prompt-heading" class="mt-1 text-lg font-extrabold leading-snug tracking-tight text-gray-950 dark:text-white">
                            {{ $announcement->title }}
                        </h2>

                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-m-user" class="size-3.5 text-gray-400" />
                                {{ $announcement->creator?->name ?? 'Admin' }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-m-clock" class="size-3.5 text-gray-400" />
                                {{ $announcement->created_at?->format('d M Y h:i A') }}
                            </span>
                        </p>
                    </div>
                </div>

                <div class="max-h-[50vh] overflow-y-auto px-6 py-5">
                    <p class="whitespace-pre-line break-words text-[0.9375rem] leading-7 text-gray-700 dark:text-gray-200">{{ $announcement->message }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-950/5 bg-gray-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/5">
                    <p class="flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-m-bell" class="mt-px size-4 shrink-0 text-gray-400" />
                        <span>You can read this again any time from the notification bell.</span>
                    </p>

                    <x-filament::button
                        wire:click="acknowledgeAnnouncement({{ $current->getKey() }})"
                        wire:loading.attr="disabled"
                        icon="heroicon-m-check-circle"
                        class="shrink-0"
                    >
                        I have read this
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
