{{--
    Day-by-day commitment history: what was promised each day and what came
    back, so "how often does this person keep it?" is answerable at a glance.

    The tally is always counted over the whole range, never over the filtered
    table — "12 failed" has to mean twelve failed days, not twelve rows
    currently on screen. Filtering only narrows what is listed below it.

    Binds to `historyRange`, `historyFrom`, `historyTo` and `historyResult` on
    the parent Livewire component; both My Commitment and the admin's detail
    page declare those same four properties.
--}}
@props(['rows', 'tally', 'filtered', 'range', 'result'])

<div>
    {{-- How the range landed --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="dc-card">
            <div class="dc-card-label">Days committed</div>
            <div class="dc-card-value">{{ $tally['days'] }}</div>
            <div class="dc-card-hint">{{ $tally['in_progress'] }} still open</div>
        </div>
        <div class="dc-card" style="box-shadow: inset 3px 0 0 0 #22c55e">
            <div class="dc-card-label">Met</div>
            <div class="dc-card-value">{{ $tally['met'] }}</div>
        </div>
        <div class="dc-card" style="box-shadow: inset 3px 0 0 0 #059669">
            <div class="dc-card-label">Overachieved</div>
            <div class="dc-card-value">{{ $tally['overachieved'] }}</div>
        </div>
        <div class="dc-card" style="box-shadow: inset 3px 0 0 0 #f59e0b">
            <div class="dc-card-label">Partially met</div>
            <div class="dc-card-value">{{ $tally['partial'] }}</div>
        </div>
        <div class="dc-card" style="box-shadow: inset 3px 0 0 0 #ef4444">
            <div class="dc-card-label">Failed</div>
            <div class="dc-card-value">{{ $tally['failed'] }}</div>
        </div>
        <div class="dc-card" style="box-shadow: inset 3px 0 0 0 #3b82f6">
            <div class="dc-card-label">Kept</div>
            <div class="dc-card-value">{{ $tally['kept_percentage'] }}%</div>
            <div class="dc-card-hint">{{ $tally['kept'] }} of {{ $tally['closed'] }} closed {{ \Illuminate\Support\Str::plural('day', $tally['closed']) }}</div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Period</span>
            <select
                wire:model.live="historyRange"
                class="mt-1 block w-48 rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
            >
                @foreach (\App\Services\DailyCommitmentService::rangeOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ($range === 'custom')
            <label class="block">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">From</span>
                <input
                    type="date"
                    wire:model.live="historyFrom"
                    class="mt-1 block rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                />
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">To</span>
                <input
                    type="date"
                    wire:model.live="historyTo"
                    class="mt-1 block rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                />
            </label>
        @endif

        <label class="block">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Result</span>
            <select
                wire:model.live="historyResult"
                class="mt-1 block w-44 rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
            >
                <option value="all">All results</option>
                @foreach (\App\Enums\CommitmentResult::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ($result !== 'all')
            <button
                type="button"
                wire:click="$set('historyResult', 'all')"
                class="pb-2 text-xs text-gray-500 underline-offset-2 hover:underline dark:text-gray-400"
            >
                Clear
            </button>
        @endif
    </div>

    {{-- The days themselves --}}
    <div class="mt-4 overflow-x-auto">
        <table class="dc-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Committed to</th>
                    <th class="dc-num">Commitment</th>
                    <th class="dc-num">Fulfilment</th>
                    <th class="dc-num">%</th>
                    <th>Result</th>
                    <th>Closed</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($filtered as $day)
                    <tr>
                        <td class="whitespace-nowrap">
                            <div class="font-semibold">{{ $day['date']->format('d M Y') }}</div>
                            <div class="text-xs text-gray-500">{{ $day['date']->format('l') }}</div>
                        </td>
                        <td><x-daily-commitment.stage-chip :stage="$day['stage']" /></td>
                        <td class="dc-num font-semibold">
                            <x-daily-commitment.amount :value="$day['target']" :count="$day['is_count']" :words="false" />
                        </td>
                        <td class="dc-num font-semibold">
                            <x-daily-commitment.amount :value="$day['achieved']" :count="$day['is_count']" :words="false" />
                            @if ($day['below'] > 0)
                                <div class="text-xs font-normal text-amber-600 dark:text-amber-400">
                                    +{{ indianAmount($day['below']) }} below stage
                                </div>
                            @endif
                            <div class="text-xs font-normal text-gray-500">
                                {{ $day['cases'] }} {{ \Illuminate\Support\Str::plural('case', $day['cases']) }}
                            </div>
                        </td>
                        <td class="dc-num tabular-nums">{{ $day['percentage'] }}%</td>
                        <td>
                            <x-daily-commitment.result-chip :result="$day['result']" />
                            @if ($day['note'])
                                <div class="mt-1 text-xs text-gray-500">{{ $day['note'] }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-xs text-gray-500">
                            {{ $day['submitted_at']?->format('d M, H:i') ?? 'Not closed' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-sm text-gray-500">
                            No commitments {{ $result === 'all' ? 'in this period' : 'with that result in this period' }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
