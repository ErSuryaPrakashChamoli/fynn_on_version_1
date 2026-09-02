<x-filament-panels::page>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">
            Employee &amp; Period
        </label>
        <p class="mb-3 text-xs text-gray-500">
            Pick an employee and a reporting cadence — every figure below recalculates for that window.
        </p>

        {{ $this->form }}
    </div>

    @if (! $employee)
        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            Select an employee above to see their performance report.
        </div>
    @else
        @php
            $roleColor = \App\Models\Employee::designationColorClass($employee->designation);
            $roleLabel = \App\Models\Employee::designationOptions()[$employee->designation] ?? '—';
            [$rangeStart, $rangeEnd] = $this->range;
            $m = $this->metrics;
        @endphp

        {{-- Profile header --}}
        <div class="flex flex-wrap items-center gap-4 border-b border-gray-200 pb-5 dark:border-gray-700">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {{ $employee->initials }}
            </span>

            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $employee->emp_name }}</span>
                    <span class="text-xs font-bold uppercase tracking-wide {{ $roleColor }}">{{ $roleLabel }}</span>
                </div>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-bold text-gray-800 dark:text-gray-200">ID: {{ $employee->emp_id }}</span>
                    &middot; {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}
                </div>
            </div>
        </div>

        {{-- Funnel stat cards --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Funnel
            </h3>

            <div class="grid grid-cols-2 gap-4 py-4 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ([
                    ['label' => 'Total OTP', 'value' => number_format($m['otp_count']), 'icon' => '👥'],
                    ['label' => 'Eligible OTP', 'value' => number_format($m['eligible_otp_count']), 'icon' => '🎯'],
                    ['label' => 'Login', 'value' => number_format($m['login_count']), 'icon' => '📝'],
                    ['label' => 'Approval', 'value' => number_format($m['approval_count']), 'icon' => '✅'],
                    ['label' => 'Disbursal', 'value' => number_format($m['disbursal_count']), 'icon' => '🏦'],
                    ['label' => 'Dropped', 'value' => number_format($m['dropped_count']), 'icon' => '❌'],
                    ['label' => 'Not Approved', 'value' => number_format($m['not_approved_count']), 'icon' => '🚫'],
                ] as $stat)
                    <div class="performance-card border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $stat['icon'] }} {{ $stat['label'] }}
                        </div>
                        <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">
                            {{ $stat['value'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Money & attendance --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Achievement &amp; Attendance
            </h3>

            <div class="grid grid-cols-1 gap-4 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="performance-card border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">💰 Disbursal Amount</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianCurrencyFormat($m['disbursal_amount']) }}</div>
                </div>

                <div class="performance-card border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">🏆 Actual Achievement</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianCurrencyFormat($m['actual_achievement']) }}</div>
                </div>

                <div class="performance-card border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-400">🎯 Target</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianCurrencyFormat($m['target_amount']) }}</div>
                </div>

                <div class="performance-card border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">🗓️ Present Days</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $m['present_days'] }} / {{ $m['working_days'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ $m['screen_time_hours'] }} hrs screen time</div>
                </div>
            </div>
        </div>

        {{-- Ratios --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Ratios <span class="font-normal normal-case text-gray-400">— configurable in Performance &rarr; Ratio Builder</span>
            </h3>

            @if (empty($this->ratios))
                <p class="pt-3 text-sm text-gray-500">No active ratios have been defined yet.</p>
            @else
                <div class="flex flex-wrap gap-3 py-4">
                    @foreach ($this->ratios as $row)
                        <div class="performance-card flex min-w-[10rem] flex-col border border-violet-200 bg-violet-50 px-4 py-3 dark:border-violet-900 dark:bg-violet-950/40">
                            <span class="text-xs font-semibold text-violet-700 dark:text-violet-400">{{ $row['ratio']->name }}</span>
                            <span class="text-xl font-extrabold text-gray-950 dark:text-white">{{ $row['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Trend --}}
        @php $trend = $this->trend; @endphp
        @if (! empty($trend))
            @php $maxDisbursal = max(1, collect($trend)->max('disbursal_amount')); @endphp
            <div>
                <h3 class="border-b border-gray-200 pb-2 pt-4 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                    Disbursal Trend <span class="font-normal normal-case text-gray-400">— last 6 {{ strtolower(\App\Support\Performance\PerformancePeriod::options()[$this->periodType]) }} periods</span>
                </h3>

                <div class="flex items-end gap-3 overflow-x-auto py-6">
                    @foreach ($trend as $point)
                        @php $heightPct = max(4, (int) round(($point['disbursal_amount'] / $maxDisbursal) * 100)); @endphp
                        <div class="flex w-16 shrink-0 flex-col items-center gap-1">
                            <span class="text-[10px] font-semibold text-gray-600 dark:text-gray-300">{{ indianCurrencyFormat($point['disbursal_amount']) }}</span>
                            <div class="flex h-32 w-full items-end rounded bg-gray-100 dark:bg-gray-800">
                                <div class="w-full rounded bg-amber-500" style="height: {{ $heightPct }}%"></div>
                            </div>
                            <span class="text-[10px] text-gray-500">{{ $point['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
