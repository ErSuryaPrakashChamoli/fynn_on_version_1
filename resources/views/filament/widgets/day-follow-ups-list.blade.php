@php
    $statusColor = fn (string $status) => match ($status) {
        'Interested', 'Eligible' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
        'Not Interested', 'Not Eligible', 'Dropped' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
        'Busy', 'No Response', 'Call Back' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
        'Eligible for Other Bank' => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-400',
        'Bureau Check' => 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/10 dark:text-gray-300',
    };

    // A follow-up here is tied to a Customer, an AI-extracted customer
    // record, or a raw Lead — "open the record" means whichever of those
    // three it's linked to.
    $recordUrl = fn ($followUp) => \App\Support\FollowUpRecordUrl::for($followUp);

    // The full follow-up log for every prospect shown here, loaded in one
    // query so each card can show how many times — and when — the prospect
    // was followed up, and every next-follow-up date that was set along the
    // way. Only the latest follow-up is listed on the calendar itself; the
    // superseded dates live here.
    $histories = \App\Models\FollowUp::historiesFor($followUps);
@endphp

<div class="grid grid-cols-1 gap-3">
    @forelse ($followUps as $followUp)
        @php($url = $recordUrl($followUp))
        @php($history = $histories[$followUp->subject_key] ?? collect())
        <{{ $url ? 'a' : 'div' }}
            @if ($url) href="{{ $url }}" @endif
            @class([
                'fynn-card block p-3.5',
                'transition hover:border-primary-400 hover:bg-primary-50/50 hover:shadow-md dark:hover:border-primary-500 dark:hover:bg-primary-500/5' => $url,
            ])
        >
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $followUp->display_name }}
                </p>
                <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ring-current/15 {{ $statusColor($followUp->status) }}">
                    {{ $followUp->status }}
                </span>
            </div>

            <dl class="mt-2.5 space-y-1.5 border-t border-(--fynn-border-soft) pt-2.5 text-xs text-gray-500 dark:text-gray-400">
                <div class="flex items-center justify-between gap-2">
                    <dt>Type</dt>
                    <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $followUp->follow_up_type }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt>{{ ($showDueDate ?? false) ? 'Due' : 'Time' }}</dt>
                    <dd class="font-medium tabular-nums text-gray-700 dark:text-gray-300">
                        {{ $followUp->next_follow_up_date?->format(($showDueDate ?? false) ? 'd M Y h:i A' : 'h:i A') ?? '-' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt>Owner</dt>
                    <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $followUp->employee?->emp_name ?? '-' }}</dd>
                </div>
                @if ($followUp->monitor_outcome instanceof \App\Enums\FollowUpOutcome)
                    <div class="flex items-center justify-between gap-2">
                        <dt>Outcome</dt>
                        <dd class="flex items-center gap-1 font-medium text-gray-700 dark:text-gray-300">
                            @if ($followUp->acted_at)
                                <span>{{ $followUp->acted_at->format('d M h:i A') }}</span>
                            @endif
                            <x-filament::badge size="sm" :color="$followUp->monitor_outcome->color()">
                                {{ $followUp->monitor_outcome->label() }}
                            </x-filament::badge>
                        </dd>
                    </div>
                @endif
            </dl>

            @if ($followUp->remarks)
                <p class="mt-2.5 rounded-md bg-(--fynn-surface-2) px-2.5 py-1.5 text-xs text-gray-700 dark:text-gray-300">
                    {{ $followUp->remarks }}
                </p>
            @endif
        </{{ $url ? 'a' : 'div' }}>

        @if ($history->count() > 1)
            <details class="fynn-card fynn-card--muted group/log -mt-2 px-3 py-0">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 py-2 text-xs font-medium text-gray-500 hover:text-(--fynn-accent-text) dark:text-gray-400 [&::-webkit-details-marker]:hidden">
                    <x-filament::icon icon="heroicon-m-chevron-right" class="size-3.5 shrink-0 transition-transform group-open/log:rotate-90" />
                    Followed up {{ $history->count() }} times — view log
                </summary>

                {{-- The prospect's follow-up log as a small vertical timeline; the one in force has the accent dot. --}}
                <ol class="border-t border-(--fynn-border-soft) py-2.5">
                    @foreach ($history as $index => $entry)
                        <li class="group/tl flex gap-2.5 text-xs">
                            <div class="flex flex-col items-center" aria-hidden="true">
                                <span @class([
                                    'mt-1 size-2 shrink-0 rounded-full',
                                    'bg-primary-500 ring-3 ring-primary-500/20' => $entry->is($followUp),
                                    'bg-gray-300 dark:bg-gray-600' => ! $entry->is($followUp),
                                ])></span>
                                <span class="mt-1 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
                            </div>
                            <div class="flex min-w-0 flex-1 items-start justify-between gap-2 pb-2 group-last/tl:pb-0">
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ $index + 1 }}.
                                    {{ $entry->created_at?->format('d M Y h:i A') }}
                                    <span class="text-gray-400 dark:text-gray-500">
                                        · {{ $entry->employee?->emp_name ?? 'Admin' }}
                                    </span>
                                </span>
                                <span @class([
                                    'shrink-0 text-right',
                                    'font-semibold text-(--fynn-accent-text)' => $entry->is($followUp),
                                    'text-gray-400 line-through dark:text-gray-500' => ! $entry->is($followUp),
                                ])>
                                    {{ $entry->next_follow_up_date?->format('d M Y h:i A') ?? 'No next date' }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    @empty
        <div class="fynn-empty fynn-empty--compact col-span-full">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
            <span>{{ $emptyMessage ?? 'No follow-ups found for this day.' }}</span>
        </div>
    @endforelse
</div>
