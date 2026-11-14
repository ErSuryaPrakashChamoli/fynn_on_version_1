<x-filament-panels::page>
    <x-filament::section
        heading="Employee & Period"
        description="Pick an employee and a reporting cadence — every figure below recalculates for that window."
        icon="heroicon-o-adjustments-horizontal"
    >
        {{ $this->form }}
    </x-filament::section>

    @if (! $employee)
        <div class="fynn-empty">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-circle" /></span>
            <span>Select an employee above to see their performance report.</span>
        </div>
    @else
        @php
            $roleColor = \App\Models\Employee::designationColorClass($employee->designation);
            $roleLabel = \App\Models\Employee::designationOptions()[$employee->designation] ?? '—';
            [$rangeStart, $rangeEnd] = $this->range;
            $m = $this->metrics;
        @endphp

        {{-- Profile header --}}
        <div class="fynn-hero">
            <span class="fynn-avatar fynn-avatar--lg">
                {{ $employee->initials }}
            </span>

            <div class="fynn-hero-main">
                <div class="fynn-hero-title">
                    <span>{{ $employee->emp_name }}</span>
                    <span class="dc-level {{ $roleColor }}">{{ $roleLabel }}</span>
                </div>
                <div class="fynn-hero-meta">
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-identification" />
                        ID: {{ $employee->emp_id }}
                    </span>
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-calendar-days" />
                        {{ $rangeStart->format('d M Y') }} – {{ $rangeEnd->format('d M Y') }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Funnel stat cards --}}
        <x-filament::section heading="Funnel" icon="heroicon-o-funnel">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ([
                    ['label' => 'Total OTP', 'value' => number_format($m['otp_count']), 'icon' => 'heroicon-o-device-phone-mobile', 'accent' => '#eab308'],
                    ['label' => 'Eligible OTP', 'value' => number_format($m['eligible_otp_count']), 'icon' => 'heroicon-o-check-badge', 'accent' => '#22c55e'],
                    ['label' => 'Login', 'value' => number_format($m['login_count']), 'icon' => 'heroicon-o-document-arrow-up', 'accent' => '#3b82f6'],
                    ['label' => 'Approval', 'value' => number_format($m['approval_count']), 'icon' => 'heroicon-o-hand-thumb-up', 'accent' => '#10b981'],
                    ['label' => 'Disbursal', 'value' => number_format($m['disbursal_count']), 'icon' => 'heroicon-o-banknotes', 'accent' => '#0d9488'],
                    ['label' => 'Dropped', 'value' => number_format($m['dropped_count']), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => '#64748b'],
                    ['label' => 'Not Approved', 'value' => number_format($m['not_approved_count']), 'icon' => 'heroicon-o-x-circle', 'accent' => '#f43f5e'],
                ] as $stat)
                    <div class="fynn-kpi" style="--fynn-kpi-accent: {{ $stat['accent'] }}">
                        <div class="fynn-kpi-head">
                            <span class="fynn-kpi-icon"><x-filament::icon :icon="$stat['icon']" /></span>
                            <div class="fynn-kpi-label">{{ $stat['label'] }}</div>
                        </div>
                        <div class="fynn-kpi-value">
                            {{ $stat['value'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Money & attendance --}}
        <x-filament::section heading="Achievement & Attendance" icon="heroicon-o-trophy">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-currency-rupee" /></span>
                        <div class="fynn-kpi-label">Disbursal Amount</div>
                    </div>
                    <div class="fynn-kpi-value">{{ indianCurrencyFormat($m['disbursal_amount']) }}</div>
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #10b981">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-trophy" /></span>
                        <div class="fynn-kpi-label">Actual Achievement</div>
                    </div>
                    <div class="fynn-kpi-value">{{ indianCurrencyFormat($m['actual_achievement']) }}</div>
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-flag" /></span>
                        <div class="fynn-kpi-label">Target</div>
                    </div>
                    <div class="fynn-kpi-value">{{ indianCurrencyFormat($m['target_amount']) }}</div>
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #8b5cf6">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
                        <div class="fynn-kpi-label">Present Days</div>
                    </div>
                    <div class="fynn-kpi-value">{{ $m['present_days'] }} / {{ $m['working_days'] }}</div>
                    <div class="fynn-kpi-hint">{{ $m['screen_time_hours'] }} hrs screen time</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Ratios --}}
        <x-filament::section icon="heroicon-o-scale">
            <x-slot name="heading">
                Ratios <span class="font-normal text-gray-500 dark:text-gray-400">— configurable in Performance &rarr; Ratio Builder</span>
            </x-slot>

            @if (empty($this->ratios))
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-scale" /></span>
                    <span>No active ratios have been defined yet.</span>
                </div>
            @else
                <div class="fynn-kpi-grid">
                    @foreach ($this->ratios as $row)
                        <div class="fynn-kpi" style="--fynn-kpi-accent: #8b5cf6">
                            <div class="fynn-kpi-label">{{ $row['ratio']->name }}</div>
                            <div class="fynn-kpi-value">{{ $row['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Trend --}}
        @php $trend = $this->trend; @endphp
        @if (! empty($trend))
            @php $maxDisbursal = max(1, collect($trend)->max('disbursal_amount')); @endphp
            <x-filament::section icon="heroicon-o-chart-bar">
                <x-slot name="heading">
                    Disbursal Trend <span class="font-normal text-gray-500 dark:text-gray-400">— last 6 {{ strtolower(\App\Support\Performance\PerformancePeriod::options()[$this->periodType]) }} periods</span>
                </x-slot>

                <div class="flex items-end gap-3 overflow-x-auto pb-1 pt-2">
                    @foreach ($trend as $point)
                        @php $heightPct = max(4, (int) round(($point['disbursal_amount'] / $maxDisbursal) * 100)); @endphp
                        <div class="flex w-16 shrink-0 flex-col items-center gap-1.5">
                            <span class="text-[10px] font-semibold tabular-nums text-gray-600 dark:text-gray-300">{{ indianCurrencyFormat($point['disbursal_amount']) }}</span>
                            <div class="flex h-32 w-full items-end rounded-md bg-gray-100 dark:bg-white/5">
                                <div class="w-full rounded-md bg-amber-500 shadow-sm" style="height: {{ $heightPct }}%"></div>
                            </div>
                            <span class="text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ $point['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
