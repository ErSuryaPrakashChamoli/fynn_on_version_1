{{--
    The follow-up log for one prospect: every interaction that was logged and,
    with it, every next-follow-up date that was set along the way. Only the
    most recent entry drives the calendars and the listing — the earlier
    entries are superseded and shown struck through.
--}}
<div class="space-y-3">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Followed up {{ $history->count() }} {{ \Illuminate\Support\Str::plural('time', $history->count()) }}.
        The highlighted row is the follow-up currently in force.
    </p>

    <ol class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
        @foreach ($history as $index => $entry)
            <li @class([
                'flex flex-col gap-1 p-3 text-sm',
                'bg-primary-50/60 dark:bg-primary-500/10' => $entry->is($current),
            ])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">
                        {{ $index + 1 }}. {{ $entry->created_at?->format('d M Y h:i A') ?? '-' }}
                    </span>

                    <span @class([
                        'text-right',
                        'font-semibold text-primary-600 dark:text-primary-400' => $entry->is($current),
                        'text-gray-400 line-through dark:text-gray-500' => ! $entry->is($current),
                    ])>
                        {{ $entry->next_follow_up_date?->format('d M Y h:i A') ?? 'No next date set' }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $entry->follow_up_type }}</span>
                    <span>·</span>
                    <span>{{ $entry->status }}</span>
                    <span>·</span>
                    <span>{{ $entry->employee?->emp_name ?? 'Admin' }}</span>
                </div>

                @if ($entry->remarks)
                    <p class="text-xs text-gray-700 dark:text-gray-300">{{ $entry->remarks }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</div>
