<x-filament-panels::page>
    @php
        $daily = $this->daily;
        $dailyLevels = $this->dailyByLevel;
        $mtd = $this->mtd;
        $mtdLevels = $this->mtdByLevel;
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
        description="{{ $rangeLabel }} — totalled one level at a time"
    >
        <x-daily-commitment.level-summary :levels="$dailyLevels" />

        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-daily-commitment.kpi label="Met" :value="$daily['met']" accent="#22c55e" icon="heroicon-o-check-circle" />
            <x-daily-commitment.kpi label="Partially met" :value="$daily['partial'] ?? 0" accent="#f59e0b" icon="heroicon-o-adjustments-horizontal" />
            <x-daily-commitment.kpi label="Failed" :value="$daily['failed']" accent="#ef4444" icon="heroicon-o-x-circle" />
            <x-daily-commitment.kpi label="Overachieved" :value="$daily['overachieved']" accent="#0d9488" icon="heroicon-o-rocket-launch" />
        </div>
        <p class="fynn-note">
            <x-filament::icon icon="heroicon-m-information-circle" />
            <span>
            {{ $daily['with_commitment'] }} of {{ $daily['people'] }} people committed
            · {{ $daily['in_progress'] }} still in progress
            · {{ $daily['without_commitment'] }} gave no commitment.
            </span>
        </p>
    </x-filament::section>

    {{-- MTD --}}
    <x-filament::section
        icon="heroicon-o-calendar-days"
        heading="MTD report"
        description="{{ $month->format('F Y') }} — this module's own monthly targets, bifurcated by level."
    >
        <x-daily-commitment.monthly-level-summary :levels="$mtdLevels" />

        <p class="fynn-note">
            <x-filament::icon icon="heroicon-m-information-circle" />
            <span>
            {{ $mtd['people_with_target'] }} {{ \Illuminate\Support\Str::plural('person', $mtd['people_with_target']) }}
            in scope {{ $mtd['people_with_target'] === 1 ? 'has' : 'have' }} a target this month ·
            {{ $mtd['elapsed_working_days'] }} working {{ \Illuminate\Support\Str::plural('day', $mtd['elapsed_working_days']) }} elapsed,
            {{ $mtd['remaining_working_days'] }} remaining.
            DRR = that level's own MTD achievement ÷ working days elapsed; "needed / day" is what closes that
            level's own gap. The levels are never added together — a Manager's target already covers their team.
            </span>
        </p>
    </x-filament::section>

    {{-- Caller --}}
    <x-filament::section
        icon="heroicon-o-phone"
        heading="Caller report"
        description="{{ $rangeLabel }} — Present means any login in the existing Screen Time sessions; actual OTPs are cases opened."
    >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-daily-commitment.kpi label="Total callers" :value="$caller['people']" accent="#3b82f6" icon="heroicon-o-phone" />
            <x-daily-commitment.kpi label="Present" :value="$caller['present']" accent="#22c55e" icon="heroicon-o-user-group" />
            <x-daily-commitment.kpi label="Absent" :value="$caller['absent']" accent="#6b7280" icon="heroicon-o-user-minus" />
            <x-daily-commitment.kpi label="Expected OTP" :value="number_format($caller['expected_otp'])" accent="#eab308" icon="heroicon-o-flag" />
            <x-daily-commitment.kpi label="Actual OTP" :value="number_format($caller['actual_otp'])" accent="#0d9488" icon="heroicon-o-device-phone-mobile" />
            <x-daily-commitment.kpi label="OTP %" :value="$caller['otp_percentage'] . '%'" accent="#a855f7" icon="heroicon-o-chart-bar" />
        </div>
        <div class="mt-4">
            <x-daily-commitment.progress-bar :percentage="$caller['otp_percentage']" color="#eab308" show-value />
        </div>
    </x-filament::section>

    {{-- Stage --}}
    <x-filament::section
        icon="heroicon-o-squares-2x2"
        heading="Stage report"
        description="{{ $month->format('F Y') }} — from the fulfilment employees declared, at the highest stage each case reached."
    >
        <div class="fynn-table-wrap overflow-x-auto">
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
                            <td class="dc-num">
                                @if ($stage->rank())
                                    <x-daily-commitment.amount :value="$totals['amount']" />
                                @else — @endif
                            </td>
                            <td>
                                <x-daily-commitment.progress-bar :percentage="$share" :color="$stage->hex()" show-value />
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
        <div class="fynn-headline">
            <span class="fynn-headline-value">
                {{ indianAmount($pipeline['total_amount']) }}
            </span>
            <span class="fynn-headline-caption">
                across {{ number_format($pipeline['total_count']) }} open
                {{ \Illuminate\Support\Str::plural('case', $pipeline['total_count']) }}
                (excludes disbursal, dropped and rejected)
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach (\App\Enums\CommitmentStage::ladder() as $stage)
                @php $totals = $pipeline['stages'][$stage->value]; @endphp
                <div class="dc-card" style="--dc-accent: {{ $stage->hex() }}">
                    <x-daily-commitment.stage-chip :stage="$stage" />
                    <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                    <div class="dc-card-words">{{ indianAmountInWords($totals['amount']) }}</div>
                    <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
