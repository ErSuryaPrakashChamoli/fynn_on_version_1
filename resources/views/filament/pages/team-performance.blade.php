<x-filament-panels::page>
    <x-filament::section
        heading="Team & Period"
        description="Pick a Team Leader, Manager, or Cluster Manager to see their whole reporting tree's attrition, retention, and attendance."
        icon="heroicon-o-adjustments-horizontal"
    >
        {{ $this->form }}
    </x-filament::section>

    @if (! $teamLead)
        <div class="fynn-empty">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-group" /></span>
            <span>Select a team above to see its performance report.</span>
        </div>
    @else
        @php
            [$rangeStart, $rangeEnd] = $this->range;
            $attrition = $this->attrition;
            $attendance = $this->attendance;
        @endphp

        {{-- Header --}}
        <div class="fynn-hero">
            <span class="fynn-avatar fynn-avatar--lg">
                {{ $teamLead->initials }}
            </span>

            <div class="fynn-hero-main">
                <div class="fynn-hero-title">
                    <span>{{ $teamLead->emp_name }}'s Team</span>
                    <span class="dc-level {{ \App\Models\Employee::designationColorClass($teamLead->designation) }}">
                        {{ \App\Models\Employee::designationOptions()[$teamLead->designation] ?? '—' }}
                    </span>
                </div>
                <div class="fynn-hero-meta">
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-calendar-days" />
                        {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Attrition / retention --}}
        <x-filament::section heading="Attrition & Retention" icon="heroicon-o-arrows-right-left">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="fynn-kpi" style="--fynn-kpi-accent: #64748b">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-users" /></span>
                        <div class="fynn-kpi-label">Headcount (Start)</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['headcount_start'] }}</div>
                </div>
                <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-user-group" /></span>
                        <div class="fynn-kpi-label">Headcount (End)</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['headcount_end'] }}</div>
                </div>
                <div class="fynn-kpi" style="--fynn-kpi-accent: #f43f5e">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" /></span>
                        <div class="fynn-kpi-label">Exits</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['exits'] }}</div>
                </div>
                <div class="fynn-kpi" style="--fynn-kpi-accent: #10b981">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-user-plus" /></span>
                        <div class="fynn-kpi-label">Joins</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['joins'] }}</div>
                </div>
                <div class="fynn-kpi" style="--fynn-kpi-accent: #e11d48">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-trending-down" /></span>
                        <div class="fynn-kpi-label">Attrition Rate</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['attrition_rate'] }}%</div>
                </div>
                <div class="fynn-kpi" style="--fynn-kpi-accent: #059669">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-shield-check" /></span>
                        <div class="fynn-kpi-label">Retention Rate</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $attrition['retention_rate'] }}%</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Attendance --}}
        <x-filament::section icon="heroicon-o-calendar-days">
            <x-slot name="heading">
                Attendance <span class="font-normal text-gray-500 dark:text-gray-400">— team attendance rate: {{ $attendance['team_attendance_rate'] }}%</span>
            </x-slot>

            @if ($attendance['members']->isEmpty())
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                    <span>No one currently reports into {{ $teamLead->emp_name }}.</span>
                </div>
            @else
                <div class="fynn-table-wrap overflow-x-auto">
                    <table class="fynn-table min-w-[36rem]">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th class="text-end">Present Days</th>
                                <th class="text-end">Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attendance['members'] as $row)
                                <tr>
                                    <td class="font-medium">{{ $row['employee']->emp_name }}</td>
                                    <td>
                                        <span class="dc-level {{ \App\Models\Employee::designationColorClass($row['employee']->designation) }}">
                                            {{ \App\Models\Employee::designationOptions()[$row['employee']->designation] ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="fynn-num">{{ $row['present_days'] }} / {{ $row['working_days'] }}</td>
                                    <td class="fynn-num">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-bold
                                            {{ $row['attendance_rate'] >= 90 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : ($row['attendance_rate'] >= 70 ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-400') }}">
                                            {{ $row['attendance_rate'] }}%
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
