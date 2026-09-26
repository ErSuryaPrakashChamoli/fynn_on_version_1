@php($missedCount = $selectedDate ? $this->missedFollowUpsForSelectedDate()->count() : 0)

@if ($missedCount > 0)
    <div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg bg-danger-50 px-3 py-2 dark:bg-danger-500/10">
        <span class="me-auto text-xs font-semibold text-danger-700 dark:text-danger-400">
            {{ $missedCount }} missed {{ str('follow-up')->plural($missedCount) }}
        </span>

        {{ $this->spreadMissedAction }}
        {{ $this->dropMissedAction }}
    </div>
@endif
