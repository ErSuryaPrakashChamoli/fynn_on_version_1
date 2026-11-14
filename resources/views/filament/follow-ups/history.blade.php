{{--
    The follow-up log for one prospect: every interaction that was logged and,
    with it, every next-follow-up date that was set along the way. Only the
    most recent entry drives the calendars and the listing — the earlier
    entries are superseded and shown struck through.
--}}
<div class="space-y-4">
    <p class="fynn-note mt-0">
        <x-filament::icon icon="heroicon-m-information-circle" />
        <span>
        Followed up {{ $history->count() }} {{ \Illuminate\Support\Str::plural('time', $history->count()) }}.
        The highlighted row is the follow-up currently in force.
        </span>
    </p>

    {{-- A vertical timeline: one dot per logged follow-up, the one in force in the accent colour. --}}
    <ol class="text-sm">
        @foreach ($history as $index => $entry)
            <li class="group/tl flex gap-3">
                <div class="flex flex-col items-center" aria-hidden="true">
                    <span @class([
                        'mt-3.5 size-2.5 shrink-0 rounded-full ring-4',
                        'bg-primary-500 ring-primary-500/20' => $entry->is($current),
                        'bg-gray-300 ring-gray-300/25 dark:bg-gray-600 dark:ring-gray-600/25' => ! $entry->is($current),
                    ])></span>
                    <span class="mt-1.5 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
                </div>

                <div class="min-w-0 flex-1 pb-3 group-last/tl:pb-0">
                    <div @class([
                        'flex flex-col gap-1 rounded-lg px-3 py-2.5',
                        'bg-primary-50/60 ring-1 ring-inset ring-primary-500/25 dark:bg-primary-500/10' => $entry->is($current),
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-gray-950 dark:text-white">
                                {{ $index + 1 }}. {{ $entry->created_at?->format('d M Y h:i A') ?? '-' }}
                            </span>

                            <span @class([
                                'text-right',
                                'font-semibold text-(--fynn-accent-text)' => $entry->is($current),
                                'text-gray-400 line-through dark:text-gray-500' => ! $entry->is($current),
                            ])>
                                {{ $entry->next_follow_up_date?->format('d M Y h:i A') ?? 'No next date set' }}
                            </span>
                        </div>

                        <div class="fynn-list-meta flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span>{{ $entry->follow_up_type }}</span>
                            <span>·</span>
                            <span>{{ $entry->status }}</span>
                            <span>·</span>
                            <span>{{ $entry->employee?->emp_name ?? 'Admin' }}</span>
                        </div>

                        @if ($entry->remarks)
                            <p class="text-xs text-gray-700 dark:text-gray-300">{{ $entry->remarks }}</p>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
</div>
