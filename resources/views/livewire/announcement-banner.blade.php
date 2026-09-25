{{--
    Floating announcements (App\Livewire\AnnouncementBanner). Stacked in the
    top-right under the topbar; each stays until the user dismisses it. Polls
    so a newly flashed announcement appears without a page reload.
--}}
<div wire:poll.30s>
    @if ($items->isNotEmpty())
        <div
            class="pointer-events-none fixed inset-x-4 top-20 z-[55] flex flex-col items-end gap-3 sm:inset-x-auto sm:right-6 sm:w-96"
            aria-live="polite"
        >
            @foreach ($items as $item)
                @php
                    $announcement = $item['announcement'];
                    $accent = match ($announcement->level) {
                        'success' => 'border-success-500 text-success-600 dark:text-success-400',
                        'warning' => 'border-warning-500 text-warning-600 dark:text-warning-400',
                        'danger' => 'border-danger-500 text-danger-600 dark:text-danger-400',
                        default => 'border-info-500 text-info-600 dark:text-info-400',
                    };
                @endphp

                <div
                    wire:key="announcement-{{ $item['notification']->getKey() }}"
                    x-data
                    x-transition
                    @class([
                        'pointer-events-auto w-full rounded-xl border-l-4 bg-white p-4 shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
                        $accent,
                    ])
                    role="status"
                >
                    <div class="flex items-start gap-3">
                        <x-filament::icon
                            icon="heroicon-o-megaphone"
                            class="mt-0.5 h-6 w-6 shrink-0 animate-pulse"
                        />

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $announcement->title }}
                            </p>

                            <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-600 dark:text-gray-300">{{ $announcement->message }}</p>

                            <p class="mt-2 text-xs text-gray-400">
                                {{ $announcement->created_at?->format('d M Y h:i A') }}
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="dismissAnnouncement('{{ $item['notification']->getKey() }}')"
                            class="shrink-0 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/5"
                            aria-label="Dismiss announcement"
                        >
                            <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
