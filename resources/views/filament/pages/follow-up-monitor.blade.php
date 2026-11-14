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

    <div class="space-y-4">
        <div class="fynn-callout fynn-callout--info">
            A follow-up counts as <strong>on time</strong> when anything is logged for the prospect by the end of the due day plus {{ $grace }} hours.
            Logged after that is <strong>late</strong>; nothing logged at all is <strong>missed</strong>. Daily limit: {{ $capacity }} open follow-ups per caller.
        </div>

        <div class="fynn-card">
            {{ $this->form }}
        </div>
    </div>

    {{-- Headline figures --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-chart-pie" /></span>
                <div class="fynn-kpi-label">On-time rate</div>
            </div>
            <div class="fynn-kpi-value {{ $rateColor($summary['on_time_rate']) }}">
                {{ $summary['on_time_rate'] !== null ? $summary['on_time_rate'].'%' : '—' }}
            </div>
            <div class="fynn-kpi-hint">of {{ $summary['judged'] }} due</div>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #22c55e">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span>
                <div class="fynn-kpi-label">On time</div>
            </div>
            <div class="fynn-kpi-value">{{ $summary['on_time'] }}</div>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-clock" /></span>
                <div class="fynn-kpi-label">Late</div>
            </div>
            <div class="fynn-kpi-value">{{ $summary['late'] }}</div>
            <div class="fynn-kpi-hint">
                {{ $summary['average_late_hours'] !== null ? 'avg '.$summary['average_late_hours'].' h after due' : '' }}
            </div>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #ef4444">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-x-circle" /></span>
                <div class="fynn-kpi-label">Missed</div>
            </div>
            <div class="fynn-kpi-value">{{ $summary['missed'] }}</div>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #f97316">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-exclamation-triangle" /></span>
                <div class="fynn-kpi-label">Open overdue now</div>
            </div>
            <div class="fynn-kpi-value">{{ $report['open_overdue'] }}</div>
            <div class="fynn-kpi-hint">any date</div>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #e11d48">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-fire" /></span>
                <div class="fynn-kpi-label">Overloaded days</div>
            </div>
            <div class="fynn-kpi-value {{ $report['overloaded_next_week'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">
                {{ $report['overloaded_next_week'] }}
            </div>
            <div class="fynn-kpi-hint">caller-days over {{ $capacity }}, next 7 days</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Backlog age --}}
        <x-filament::section heading="How old is the open backlog?" description="Open follow-ups already past their date, by how long they have waited." icon="heroicon-o-inbox-stack">
            @php($backlogMax = max(1, max($report['backlog'])))
            <div class="space-y-3">
                @foreach ($report['backlog'] as $label => $count)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-28 shrink-0 font-medium text-gray-600 dark:text-gray-400">{{ $label }}</span>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div @class([
                                'h-2.5 rounded-full',
                                'bg-info-500' => $loop->index === 0,
                                'bg-warning-500' => $loop->index === 1,
                                'bg-danger-500' => $loop->index === 2,
                                'bg-danger-700' => $loop->index === 3,
                            ]) style="width: {{ round($count / $backlogMax * 100) }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Outcomes logged --}}
        <x-filament::section heading="What callers are logging" description="Status of every follow-up entry logged in the period." icon="heroicon-o-chat-bubble-left-right">
            <div class="flex flex-wrap gap-2">
                @forelse ($report['statuses'] as $status => $count)
                    <span class="fynn-chip">
                        {{ $status }} <span class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $count }}</span>
                    </span>
                @empty
                    <div class="fynn-empty fynn-empty--compact w-full">
                        <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-chat-bubble-left-right" /></span>
                        <span>Nothing logged in this period.</span>
                    </div>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    {{-- Team table --}}
    <x-filament::section heading="Team discipline" description="Due {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}. Worst first. Click a row to see that person's missed follow-ups." icon="heroicon-o-user-group">
        <div class="fynn-table-wrap overflow-x-auto">
            <table class="fynn-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th class="text-end">On-time %</th>
                        <th class="text-end">On time</th>
                        <th class="text-end">Late</th>
                        <th class="text-end">Missed</th>
                        <th class="text-end">Pending</th>
                        <th class="text-end">Open overdue</th>
                        <th class="text-end">Next 7 days</th>
                        <th class="text-end">Days over {{ $capacity }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['team'] as $row)
                        <tr
                            @if ($row['employee_id']) wire:click="focusEmployee({{ $row['employee_id'] }})" @endif
                            @class([
                                'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' => $row['employee_id'],
                                'bg-primary-50 dark:bg-primary-500/10' => $row['employee_id'] === $focusEmployeeId,
                            ])
                        >
                            <td>
                                <div class="font-medium">{{ $row['name'] }}</div>
                                @if ($row['emp_id'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['emp_id'] }}</div>
                                @endif
                            </td>
                            <td class="fynn-num font-bold {{ $rateColor($row['on_time_rate']) }}">
                                {{ $row['on_time_rate'] !== null ? $row['on_time_rate'].'%' : '—' }}
                            </td>
                            <td class="fynn-num">{{ $row['on_time'] }}</td>
                            <td class="fynn-num">{{ $row['late'] }}</td>
                            <td class="fynn-num font-semibold {{ $row['missed'] ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['missed'] }}</td>
                            <td class="fynn-num">{{ $row['pending'] }}</td>
                            <td class="fynn-num">{{ $row['open_overdue'] }}</td>
                            <td class="fynn-num">{{ $row['next_week'] }}</td>
                            <td class="fynn-num {{ $row['overloaded_days'] ? 'font-semibold text-danger-600 dark:text-danger-400' : '' }}">{{ $row['overloaded_days'] }}</td>
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
        <x-filament::section icon="heroicon-o-exclamation-circle">
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

            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th>Prospect</th>
                            <th>Was due</th>
                            <th>Status</th>
                            <th>Last remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($missed as $followUp)
                            <tr>
                                <td class="font-medium">{{ $followUp->display_name }}</td>
                                <td class="whitespace-nowrap">
                                    {{ $followUp->next_follow_up_date->format('d M Y h:i A') }}
                                    <span class="text-xs text-danger-600 dark:text-danger-400">({{ $followUp->next_follow_up_date->diffForHumans() }})</span>
                                </td>
                                <td>{{ $followUp->status }}</td>
                                <td class="text-gray-600 dark:text-gray-400">{{ str($followUp->remarks)->limit(80) }}</td>
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
        <x-filament::section heading="Missed follow-ups by day" description="Darker is worse. Spot a bad day for the whole team, or one caller falling behind." icon="heroicon-o-table-cells">
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
