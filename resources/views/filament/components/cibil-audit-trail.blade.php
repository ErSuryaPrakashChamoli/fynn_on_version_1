@php
    use App\Filament\Pages\CibilAuditHistory;

    /** @var \Illuminate\Support\Collection<int, \Spatie\Activitylog\Models\Activity> $entries */
@endphp

@if ($entries->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing logged for this request yet.</p>
@else
    <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
        @foreach ($entries as $entry)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="gray">{{ CibilAuditHistory::EVENTS[$entry->event] ?? $entry->event }}</x-filament::badge>
                    <span class="text-gray-950 dark:text-white">{{ $entry->description }}</span>
                </div>
                <span class="text-gray-500 dark:text-gray-400">
                    {{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format('d M Y, h:i:s A') }}
                </span>
            </li>
        @endforeach
    </ul>
@endif
