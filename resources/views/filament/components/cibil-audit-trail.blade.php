@php
    use App\Filament\Pages\CibilAuditHistory;

    /** @var \Illuminate\Support\Collection<int, \Spatie\Activitylog\Models\Activity> $entries */
@endphp

@if ($entries->isEmpty())
    <div class="fynn-empty fynn-empty--compact">
        <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-shield-check" /></span>
        <span>Nothing logged for this request yet.</span>
    </div>
@else
    {{-- A vertical timeline: the event on the strong line, who and when underneath. --}}
    <ol class="text-sm">
        @foreach ($entries as $entry)
            <li class="group/tl flex gap-3">
                <div class="flex flex-col items-center" aria-hidden="true">
                    <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-primary-500 ring-4 ring-primary-500/15"></span>
                    <span class="mt-1.5 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
                </div>
                <div class="min-w-0 flex-1 pb-4 group-last/tl:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="fynn-chip">{{ CibilAuditHistory::EVENTS[$entry->event] ?? $entry->event }}</span>
                        <span class="font-semibold text-gray-950 dark:text-white">{{ $entry->description }}</span>
                    </div>
                    <p class="fynn-list-meta mt-1">
                        {{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format('d M Y, h:i:s A') }}
                    </p>
                </div>
            </li>
        @endforeach
    </ol>
@endif
