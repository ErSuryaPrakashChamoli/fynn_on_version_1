<x-filament-panels::page>
    @php
        $report = $this->report;
        $summary = $report['summary'];
        [$rangeStart, $rangeEnd] = $this->period();
        $capacity = \App\Services\FollowUpMonitorService::DAILY_CAPACITY;
        $grace = \App\Services\FollowUpMonitorService::GRACE_HOURS;
        $heatMax = max(1, collect($report['heat'])->flatten()->max() ?? 1);
        $rateColor = fn (?float $rate) => match (true) {
            $rate === null => 'text-gray-400',
            $rate >= 80 => 'text-success-600 dark:text-success-400',
            $rate >= 50 => 'text-warning-600 dark:text-warning-400',
            default => 'text-danger-600 dark:text-danger-400',
        };
    @endphp

    <div>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            A follow-up counts as <strong>on time</strong> when anything is logged for the prospect by the end of the due day plus {{ $grace }} hours.
            Logged after that is <strong>late</strong>; nothing logged at all is <strong>missed</strong>. Daily limit: {{ $capacity }} open follow-ups per caller.
        </p>

        {{ $this->form }}
    </div>

    {{-- Headline figures --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">On-time rate</div>
            <div class="mt-1 text-2xl font-extrabold {{ $rateColor($summary['on_time_rate']) }}">
                {{ $summary['on_time_rate'] !== null ? $summary['on_time_rate'].'%' : '—' }}
            </div>
            <div class="text-xs text-gray-500">of {{ $summary['judged'] }} due</div>
        </div>
        <div class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-900 dark:bg-success-950/40">
            <div class="text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-400">On time</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $summary['on_time'] }}</div>
        </div>
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-900 dark:bg-warning-950/40">
            <div class="text-xs font-semibold uppercase tracking-wide text-warning-700 dark:text-warning-400">Late</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $summary['late'] }}</div>
            <div class="text-xs text-gray-500">
                {{ $summary['average_late_hours'] !== null ? 'avg '.$summary['average_late_hours'].' h after due' : '' }}
            </div>
        </div>
        <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-900 dark:bg-danger-950/40">
            <div class="text-xs font-semibold uppercase tracking-wide text-danger-700 dark:text-danger-400">Missed</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $summary['missed'] }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Open overdue now</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $report['open_overdue'] }}</div>
            <div class="text-xs text-gray-500">any date</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Overloaded days</div>
            <div class="mt-1 text-2xl font-extrabold {{ $report['overloaded_next_week'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">
                {{ $report['overloaded_next_week'] }}
            </div>
            <div class="text-xs text-gray-500">caller-days over {{ $capacity }}, next 7 days</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Backlog age --}}
        <x-filament::section heading="How old is the open backlog?" description="Open follow-ups already past their date, by how long they have waited.">
            @php($backlogMax = max(1, max($report['backlog'])))
            <div class="space-y-2">
                @foreach ($report['backlog'] as $label => $count)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-28 shrink-0 text-gray-600 dark:text-gray-400">{{ $label }}</span>
                        <div class="h-3 flex-1 rounded-full bg-gray-100 dark:bg-gray-800">
                            <div @class([
                                'h-3 rounded-full',
                                'bg-info-500' => $loop->index === 0,
                                'bg-warning-500' => $loop->index === 1,
                                'bg-danger-500' => $loop->index === 2,
                                'bg-danger-700' => $loop->index === 3,
                            ]) style="width: {{ round($count / $backlogMax * 100) }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right font-semibold text-gray-900 dark:text-white">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Outcomes logged --}}
        <x-filament::section heading="What callers are logging" description="Status of every follow-up entry logged in the period.">
            <div class="flex flex-wrap gap-2">
                @forelse ($report['statuses'] as $status => $count)
                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        {{ $status }} <span class="font-bold text-gray-950 dark:text-white">{{ $count }}</span>
                    </span>
                @empty
                    <span class="text-sm text-gray-500">Nothing logged in this period.</span>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    {{-- Team table --}}
    <x-filament::section heading="Team discipline" description="Due {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}. Worst first. Click a row to see that person's missed follow-ups.">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pe-3">Employee</th>
                        <th class="px-2 py-2 text-right">On-time %</th>
                        <th class="px-2 py-2 text-right">On time</th>
                        <th class="px-2 py-2 text-right">Late</th>
                        <th class="px-2 py-2 text-right">Missed</th>
                        <th class="px-2 py-2 text-right">Pending</th>
                        <th class="px-2 py-2 text-right">Open overdue</th>
                        <th class="px-2 py-2 text-right">Next 7 days</th>
                        <th class="px-2 py-2 text-right">Days over {{ $capacity }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($report['team'] as $row)
                        <tr
                            @if ($row['employee_id']) wire:click="focusEmployee({{ $row['employee_id'] }})" @endif
                            @class([
                                'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' => $row['employee_id'],
                                'bg-primary-50 dark:bg-primary-500/10' => $row['employee_id'] === $focusEmployeeId,
                            ])
                        >
                            <td class="py-2 pe-3">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</div>
                                @if ($row['emp_id'])
                                    <div class="text-xs text-gray-500">{{ $row['emp_id'] }}</div>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right font-bold {{ $rateColor($row['on_time_rate']) }}">
                                {{ $row['on_time_rate'] !== null ? $row['on_time_rate'].'%' : '—' }}
                            </td>
                            <td class="px-2 py-2 text-right">{{ $row['on_time'] }}</td>
                            <td class="px-2 py-2 text-right">{{ $row['late'] }}</td>
                            <td class="px-2 py-2 text-right font-semibold {{ $row['missed'] ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['missed'] }}</td>
                            <td class="px-2 py-2 text-right">{{ $row['pending'] }}</td>
                            <td class="px-2 py-2 text-right">{{ $row['open_overdue'] }}</td>
                            <td class="px-2 py-2 text-right">{{ $row['next_week'] }}</td>
                            <td class="px-2 py-2 text-right {{ $row['overloaded_days'] ? 'font-semibold text-danger-600 dark:text-danger-400' : '' }}">{{ $row['overloaded_days'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-6 text-center text-gray-500">No follow-ups fell due in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Drill-down --}}
    @if ($focused = $this->focusedEmployee())
        @php($missed = $this->focusedMissedFollowUps())
        <x-filament::section>
            <x-slot name="heading">Missed follow-ups — {{ $focused->emp_name }}</x-slot>
            <x-slot name="afterHeader">
                <div class="flex items-center gap-2">
                    @if ($missed->isNotEmpty())
                        {{ $this->spreadFocusedAction }}
                        {{ $this->dropFocusedAction }}
                    @endif
                    <x-filament::button color="gray" size="sm" wire:click="clearFocus">Close</x-filament::button>
                </div>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pe-3">Prospect</th>
                            <th class="px-2 py-2">Was due</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="px-2 py-2">Last remark</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($missed as $followUp)
                            <tr>
                                <td class="py-2 pe-3 font-medium text-gray-950 dark:text-white">{{ $followUp->display_name }}</td>
                                <td class="px-2 py-2 whitespace-nowrap">
                                    {{ $followUp->next_follow_up_date->format('d M Y h:i A') }}
                                    <span class="text-xs text-danger-600 dark:text-danger-400">({{ $followUp->next_follow_up_date->diffForHumans() }})</span>
                                </td>
                                <td class="px-2 py-2">{{ $followUp->status }}</td>
                                <td class="px-2 py-2 text-gray-600 dark:text-gray-400">{{ str($followUp->remarks)->limit(80) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-500">No missed follow-ups in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Heat grid --}}
    @if (filled($report['heat']))
        <x-filament::section heading="Missed follow-ups by day" description="Darker is worse. Spot a bad day for the whole team, or one caller falling behind.">
            <div class="overflow-x-auto">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th class="sticky left-0 bg-white pe-3 text-left font-medium text-gray-500 dark:bg-gray-900">Employee</th>
                            @foreach ($report['heat_dates'] as $date)
                                <th class="px-0.5 pb-1 text-center font-medium text-gray-500">{{ \Illuminate\Support\Carbon::parse($date)->format('d') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['team'] as $row)
                            @continue(! isset($report['heat'][$row['employee_id']]))
                            <tr>
                                <td class="sticky left-0 whitespace-nowrap bg-white py-0.5 pe-3 text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $row['name'] }}</td>
                                @foreach ($report['heat_dates'] as $date)
                                    @php($missedOnDay = $report['heat'][$row['employee_id']][$date] ?? 0)
                                    <td class="px-0.5 py-0.5">
                                        <div
                                            class="flex h-6 w-6 items-center justify-center rounded text-[10px] font-semibold {{ $missedOnDay ? 'text-white' : 'bg-gray-100 dark:bg-gray-800' }}"
                                            @if ($missedOnDay) style="background-color: rgb(220 38 38 / {{ 0.25 + 0.75 * $missedOnDay / $heatMax }})" @endif
                                            title="{{ $row['name'] }} · {{ \Illuminate\Support\Carbon::parse($date)->format('d M') }} · {{ $missedOnDay }} missed"
                                        >{{ $missedOnDay ?: '' }}</div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
