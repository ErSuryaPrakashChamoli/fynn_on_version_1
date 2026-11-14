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

@php
    // One look for the raw filter controls, matching Filament's own inputs.
    $field = 'rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:outline-none focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500';
@endphp

<div>
    {{-- How the range landed --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-daily-commitment.kpi
            label="Days committed"
            :value="$tally['days']"
            :hint="$tally['in_progress'] . ' still open'"
            icon="heroicon-o-calendar-days"
        />
        <x-daily-commitment.kpi label="Met" :value="$tally['met']" accent="#22c55e" icon="heroicon-o-check-circle" />
        <x-daily-commitment.kpi label="Overachieved" :value="$tally['overachieved']" accent="#059669" icon="heroicon-o-rocket-launch" />
        <x-daily-commitment.kpi label="Partially met" :value="$tally['partial']" accent="#f59e0b" icon="heroicon-o-adjustments-horizontal" />
        <x-daily-commitment.kpi label="Failed" :value="$tally['failed']" accent="#ef4444" icon="heroicon-o-x-circle" />
        <x-daily-commitment.kpi
            label="Kept"
            :value="$tally['kept_percentage'] . '%'"
            :hint="$tally['kept'] . ' of ' . $tally['closed'] . ' closed ' . \Illuminate\Support\Str::plural('day', $tally['closed'])"
            accent="#3b82f6"
            icon="heroicon-o-trophy"
        />
    </div>

    {{-- Filters --}}
    <div class="fynn-toolbar mt-5 items-end">
        <label class="block">
            <span class="fynn-toolbar-label">Period</span>
            <select
                wire:model.live="historyRange"
                class="mt-1 block w-48 {{ $field }}"
            >
                @foreach (\App\Services\DailyCommitmentService::rangeOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ($range === 'custom')
            <label class="block">
                <span class="fynn-toolbar-label">From</span>
                <input
                    type="date"
                    wire:model.live="historyFrom"
                    class="mt-1 block {{ $field }}"
                />
            </label>

            <label class="block">
                <span class="fynn-toolbar-label">To</span>
                <input
                    type="date"
                    wire:model.live="historyTo"
                    class="mt-1 block {{ $field }}"
                />
            </label>
        @endif

        <label class="block">
            <span class="fynn-toolbar-label">Result</span>
            <select
                wire:model.live="historyResult"
                class="mt-1 block w-44 {{ $field }}"
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
                class="inline-flex items-center gap-1 pb-2.5 text-xs font-semibold text-gray-500 transition hover:text-gray-950 dark:text-gray-400 dark:hover:text-white"
            >
                <x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" />
                Clear
            </button>
        @endif
    </div>

    {{-- The days themselves --}}
    <div class="fynn-table-wrap mt-4">
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
                        <td class="dc-num">{{ $day['percentage'] }}%</td>
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
                        <td colspan="7">
                            <div class="fynn-empty fynn-empty--compact">
                                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
                                <span>No commitments {{ $result === 'all' ? 'in this period' : 'with that result in this period' }}.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
