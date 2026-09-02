<x-filament-panels::page>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">
            Team &amp; Period
        </label>
        <p class="mb-3 text-xs text-gray-500">
            Pick a Team Leader, Manager, or Cluster Manager to see their whole reporting tree's attrition, retention, and attendance.
        </p>

        {{ $this->form }}
    </div>

    @if (! $teamLead)
        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            Select a team above to see its performance report.
        </div>
    @else
        @php
            [$rangeStart, $rangeEnd] = $this->range;
            $attrition = $this->attrition;
            $attendance = $this->attendance;
        @endphp

        {{-- Header --}}
        <div class="flex flex-wrap items-center gap-4 border-b border-gray-200 pb-5 dark:border-gray-700">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {{ $teamLead->initials }}
            </span>

            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $teamLead->emp_name }}'s Team</span>
                    <span class="text-xs font-bold uppercase tracking-wide {{ \App\Models\Employee::designationColorClass($teamLead->designation) }}">
                        {{ \App\Models\Employee::designationOptions()[$teamLead->designation] ?? '—' }}
                    </span>
                </div>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}
                </div>
            </div>
        </div>

        {{-- Attrition / retention --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Attrition &amp; Retention
            </h3>

            <div class="grid grid-cols-2 gap-4 py-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="performance-card border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Headcount (Start)</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['headcount_start'] }}</div>
                </div>
                <div class="performance-card border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Headcount (End)</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['headcount_end'] }}</div>
                </div>
                <div class="performance-card border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-400">Exits</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['exits'] }}</div>
                </div>
                <div class="performance-card border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Joins</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['joins'] }}</div>
                </div>
                <div class="performance-card border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-400">Attrition Rate</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['attrition_rate'] }}%</div>
                </div>
                <div class="performance-card border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Retention Rate</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $attrition['retention_rate'] }}%</div>
                </div>
            </div>
        </div>

        {{-- Attendance --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Attendance <span class="font-normal normal-case text-gray-400">— team attendance rate: {{ $attendance['team_attendance_rate'] }}%</span>
            </h3>

            @if ($attendance['members']->isEmpty())
                <p class="pt-3 text-sm text-gray-500">No one currently reports into {{ $teamLead->emp_name }}.</p>
            @else
                <div class="overflow-x-auto py-4">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Employee</th>
                                <th class="py-2 pr-4">Role</th>
                                <th class="py-2 pr-4">Present Days</th>
                                <th class="py-2 pr-4">Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attendance['members'] as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row['employee']->emp_name }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-xs font-bold uppercase tracking-wide {{ \App\Models\Employee::designationColorClass($row['employee']->designation) }}">
                                            {{ \App\Models\Employee::designationOptions()[$row['employee']->designation] ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $row['present_days'] }} / {{ $row['working_days'] }}</td>
                                    <td class="py-2 pr-4">
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
        </div>
    @endif
</x-filament-panels::page>
