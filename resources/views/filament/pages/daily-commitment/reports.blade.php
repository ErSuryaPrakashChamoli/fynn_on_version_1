<x-filament-panels::page>
    @php
        $daily = $this->daily;
        $mtd = $this->mtd;
        $caller = $this->callerReport;
        $stageTotals = $this->monthStageTotals;
        $pipeline = $this->pipeline;
        $rangeLabel = $this->rangeLabel;
        $month = $this->month;
        $grandStageCount = collect($stageTotals)->sum('count');
    @endphp

    <x-filament::section icon="heroicon-o-funnel" heading="Filters" collapsible>
        {{ $this->form }}
    </x-filament::section>

    {{-- Daily --}}
    <x-filament::section
        icon="heroicon-o-calendar"
        heading="Period report"
        description="{{ $rangeLabel }}"
    >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <x-daily-commitment.kpi label="Total commitment" :value="shortIndianAmount($daily['committed_amount'])" accent="#3b82f6" />
            <x-daily-commitment.kpi label="Total achievement" :value="shortIndianAmount($daily['achieved_amount'])" accent="#22c55e" />
            <x-daily-commitment.kpi label="Pending" :value="shortIndianAmount($daily['pending_amount'])" accent="#f97316" />
            <x-daily-commitment.kpi label="Achievement %" :value="$daily['percentage'] . '%'" accent="#a855f7" />
            <x-daily-commitment.kpi label="Met" :value="$daily['met']" accent="#22c55e" />
            <x-daily-commitment.kpi label="Failed" :value="$daily['failed']" accent="#ef4444" />
            <x-daily-commitment.kpi label="Overachieved" :value="$daily['overachieved']" accent="#0d9488" />
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ $daily['with_commitment'] }} of {{ $daily['people'] }} people committed
            · {{ $daily['in_progress'] }} still in progress
            · {{ $daily['without_commitment'] }} gave no commitment.
        </p>
    </x-filament::section>

    {{-- MTD --}}
    <x-filament::section
        icon="heroicon-o-calendar-days"
        heading="MTD report"
        description="{{ $month->format('F Y') }} — this module's own monthly targets only."
    >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <x-daily-commitment.kpi label="Monthly target" :value="shortIndianAmount($mtd['target'])" accent="#3b82f6" />
            <x-daily-commitment.kpi label="MTD achievement" :value="shortIndianAmount($mtd['achieved'])" accent="#22c55e" />
            <x-daily-commitment.kpi label="Pending" :value="shortIndianAmount($mtd['pending'])" accent="#f97316" />
            <x-daily-commitment.kpi label="Achievement %" :value="$mtd['percentage'] . '%'" accent="#a855f7" />
            <x-daily-commitment.kpi
                label="DRR"
                :value="shortIndianAmount($mtd['drr'])"
                hint="MTD ÷ {{ $mtd['elapsed_working_days'] }} working days"
                accent="#0d9488"
            />
        </div>
        <div class="mt-3">
            <x-daily-commitment.progress-bar :percentage="$mtd['percentage']" color="#22c55e" />
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            DRR = MTD achievement ÷ working days elapsed. To close the gap,
            <strong>{{ shortIndianAmount($mtd['required_drr']) }}</strong> is needed on each of the
            {{ $mtd['remaining_working_days'] }} remaining working days
            ({{ $mtd['people_with_target'] }} people have a target this month).
        </p>
    </x-filament::section>

    {{-- Caller --}}
    <x-filament::section
        icon="heroicon-o-phone"
        heading="Caller report"
        description="{{ $rangeLabel }} — Present means any login in the existing Screen Time sessions; actual OTPs are cases opened."
    >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-daily-commitment.kpi label="Total callers" :value="$caller['people']" accent="#3b82f6" />
            <x-daily-commitment.kpi label="Present" :value="$caller['present']" accent="#22c55e" />
            <x-daily-commitment.kpi label="Absent" :value="$caller['absent']" accent="#6b7280" />
            <x-daily-commitment.kpi label="Expected OTP" :value="number_format($caller['expected_otp'])" accent="#eab308" />
            <x-daily-commitment.kpi label="Actual OTP" :value="number_format($caller['actual_otp'])" accent="#0d9488" />
            <x-daily-commitment.kpi label="OTP %" :value="$caller['otp_percentage'] . '%'" accent="#a855f7" />
        </div>
        <div class="mt-3">
            <x-daily-commitment.progress-bar :percentage="$caller['otp_percentage']" color="#eab308" />
        </div>
    </x-filament::section>

    {{-- Stage --}}
    <x-filament::section
        icon="heroicon-o-squares-2x2"
        heading="Stage report"
        description="{{ $month->format('F Y') }} — from the fulfilment employees declared, at the highest stage each case reached."
    >
        <div class="overflow-x-auto">
            <table class="dc-table">
                <thead>
                    <tr>
                        <th>Stage</th>
                        <th class="dc-num">Cases</th>
                        <th class="dc-num">Value</th>
                        <th style="min-width: 10rem">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Enums\CommitmentStage::reportable() as $stage)
                        @php
                            $totals = $stageTotals[$stage->value];
                            $share = $grandStageCount > 0 ? round(($totals['count'] / $grandStageCount) * 100, 1) : 0;
                        @endphp
                        <tr>
                            <td><x-daily-commitment.stage-chip :stage="$stage" /></td>
                            <td class="dc-num font-semibold">{{ number_format($totals['count']) }}</td>
                            <td class="dc-num">{{ $stage->rank() ? shortIndianAmount($totals['amount']) : '—' }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <x-daily-commitment.progress-bar :percentage="$share" :color="$stage->hex()" />
                                    <span class="w-12 shrink-0 text-right text-xs text-gray-500">{{ $share }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Pipeline --}}
    <x-filament::section
        icon="heroicon-o-queue-list"
        heading="Current pipeline"
        description="Cases declared on this period's commitments that have not closed out. Module data only — never mixed into achievement."
    >
        <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
            <span class="text-2xl font-extrabold text-gray-950 dark:text-white">
                {{ shortIndianAmount($pipeline['total_amount']) }}
            </span>
            <span class="text-sm text-gray-500">
                across {{ number_format($pipeline['total_count']) }} open
                {{ \Illuminate\Support\Str::plural('case', $pipeline['total_count']) }}
                (excludes disbursed, dropped and rejected)
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach (\App\Enums\CommitmentStage::ladder() as $stage)
                @php $totals = $pipeline['stages'][$stage->value]; @endphp
                <div class="dc-card" style="border-color: {{ $stage->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $stage->hex() }}">
                    <x-daily-commitment.stage-chip :stage="$stage" />
                    <div class="dc-card-value">{{ shortIndianAmount($totals['amount']) }}</div>
                    <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
