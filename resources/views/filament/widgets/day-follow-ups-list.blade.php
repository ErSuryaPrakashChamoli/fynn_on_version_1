@php
    $statusColor = fn (string $status) => match ($status) {
        'Interested' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
        'Not Interested', 'Not Eligible' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
        'Busy', 'No Response' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
        'Eligible for Other Bank' => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-400',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/10 dark:text-gray-300',
    };

    // A follow-up here is tied to a Customer, an AI-extracted customer
    // record, or a raw Lead — "open the record" means whichever of those
    // three it's linked to.
    $recordUrl = function ($followUp) {
        if ($followUp->customer_id) {
            return \App\Filament\Resources\Customers\CustomerResource::getUrl('view', ['record' => $followUp->customer_id]);
        }

        if ($followUp->ai_customer_record_id) {
            return \App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource::getUrl('view', ['record' => $followUp->ai_customer_record_id]);
        }

        if ($followUp->lead_id) {
            return \App\Filament\Resources\Leads\LeadResource::getUrl('edit', ['record' => $followUp->lead_id]);
        }

        return null;
    };

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
                'block rounded-lg border border-gray-200 p-3 dark:border-gray-700',
                'transition-colors hover:border-primary-400 hover:bg-primary-50/50 dark:hover:border-primary-500 dark:hover:bg-primary-500/5' => $url,
            ])
        >
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $followUp->display_name }}
                </p>
                <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor($followUp->status) }}">
                    {{ $followUp->status }}
                </span>
            </div>

            <dl class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                <div class="flex items-center justify-between gap-2">
                    <dt>Type</dt>
                    <dd class="text-gray-700 dark:text-gray-300">{{ $followUp->follow_up_type }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt>Time</dt>
                    <dd class="text-gray-700 dark:text-gray-300">
                        {{ $followUp->next_follow_up_date?->format('h:i A') ?? '-' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt>Owner</dt>
                    <dd class="text-gray-700 dark:text-gray-300">{{ $followUp->employee?->emp_name ?? '-' }}</dd>
                </div>
            </dl>

            @if ($followUp->remarks)
                <p class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-700 dark:border-gray-800 dark:text-gray-300">
                    {{ $followUp->remarks }}
                </p>
            @endif
        </{{ $url ? 'a' : 'div' }}>

        @if ($history->count() > 1)
            <details class="-mt-2 rounded-b-lg border border-t-0 border-gray-200 px-3 pb-2 dark:border-gray-700">
                <summary class="cursor-pointer py-2 text-xs font-medium text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                    Followed up {{ $history->count() }} times — view log
                </summary>

                <ol class="space-y-2 border-t border-gray-100 pt-2 dark:border-gray-800">
                    @foreach ($history as $index => $entry)
                        <li class="flex items-start justify-between gap-2 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $index + 1 }}.
                                {{ $entry->created_at?->format('d M Y h:i A') }}
                                <span class="text-gray-400 dark:text-gray-500">
                                    · {{ $entry->employee?->emp_name ?? 'Admin' }}
                                </span>
                            </span>
                            <span @class([
                                'shrink-0 text-right',
                                'font-semibold text-primary-600 dark:text-primary-400' => $entry->is($followUp),
                                'text-gray-400 line-through dark:text-gray-500' => ! $entry->is($followUp),
                            ])>
                                {{ $entry->next_follow_up_date?->format('d M Y h:i A') ?? 'No next date' }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    @empty
        <div class="col-span-full rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            No follow-ups found for this day.
        </div>
    @endforelse
</div>
