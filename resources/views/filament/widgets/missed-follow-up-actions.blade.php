@php($missedCount = $selectedDate ? $this->missedFollowUpsForSelectedDate()->count() : 0)

@if ($missedCount > 0)
    <div class="fynn-callout fynn-callout--danger mt-3 flex flex-wrap items-center gap-2 px-3.5 py-2 before:inset-y-2">
        <span class="me-auto inline-flex items-center gap-1.5 text-xs font-semibold text-danger-700 dark:text-danger-400">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="size-4 shrink-0" />
            {{ $missedCount }} missed {{ str('follow-up')->plural($missedCount) }}
        </span>

        {{ $this->spreadMissedAction }}
        {{ $this->dropMissedAction }}
    </div>
@endif
