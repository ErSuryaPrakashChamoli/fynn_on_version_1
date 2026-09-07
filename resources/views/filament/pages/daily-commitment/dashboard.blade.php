<x-filament-panels::page>
    @php
        $summary = $this->summary;
        $levels = $this->levelSummaries;
        $monthly = $this->monthly;
        $monthlyLevels = $this->monthlyByLevel;
        $rows = $this->tableRows;
        $allRows = $this->rows;
        $rangeLabel = $this->rangeLabel;
        [$rangeStart, $rangeEnd] = $this->range;
        $isSingleDay = $rangeStart->isSameDay($rangeEnd);
        $ladder = \App\Enums\CommitmentStage::ladder();
        $stageTotals = $summary['stage_totals'];
    @endphp

    <x-filament::section icon="heroicon-o-funnel" heading="Filters" collapsible>
        {{ $this->form }}
    </x-filament::section>

    {{-- Commitment by level — never one blended total --}}
    <x-filament::section
        icon="heroicon-o-bars-3-bottom-left"
        heading="Commitment by level — {{ $rangeLabel }}"
        description="Managers, Team Leaders and Callers are totalled separately. They are never added together — a Manager's commitment already covers their team."
    >
        <x-daily-commitment.level-summary :levels="$levels" />
    </x-filament::section>

    {{-- Headline counts (people, not money — these do add up across levels) --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-6">
        <x-daily-commitment.kpi
            label="Committed"
            :value="$summary['with_commitment'] . ' / ' . $summary['people']"
            hint="{{ $summary['submitted'] }} submitted a final status"
            accent="#3b82f6"
        />
        <x-daily-commitment.kpi label="Met" :value="$summary['met']" accent="#22c55e" />
        <x-daily-commitment.kpi label="Failed" :value="$summary['failed']" accent="#ef4444" />
        <x-daily-commitment.kpi label="Overachieved" :value="$summary['overachieved']" accent="#0d9488" />
        <x-daily-commitment.kpi label="In progress" :value="$summary['in_progress']" accent="#eab308" />
    </div>

    {{-- Attendance + OTP + MTD --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section icon="heroicon-o-users" heading="Callers today" compact>
            <div class="grid grid-cols-2 gap-3">
                <x-daily-commitment.kpi label="Present" :value="$summary['present']" accent="#22c55e" />
                <x-daily-commitment.kpi label="Absent" :value="$summary['absent']" accent="#6b7280" />
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Anyone with a login in the existing Screen Time sessions counts as Present — {{ $rangeLabel }}.
            </p>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-device-phone-mobile" heading="OTP performance" compact>
            <div class="grid grid-cols-3 gap-3">
                <x-daily-commitment.kpi label="Expected" :value="number_format($summary['expected_otp'])" accent="#eab308" />
                <x-daily-commitment.kpi label="Actual" :value="number_format($summary['actual_otp'])" accent="#3b82f6" />
                <x-daily-commitment.kpi label="Achievement" :value="$summary['otp_percentage'] . '%'" accent="#22c55e" />
            </div>
            <div class="mt-3">
                <x-daily-commitment.progress-bar :percentage="$summary['otp_percentage']" color="#eab308" />
            </div>
        </x-filament::section>

    </div>

    {{-- Month to date, bifurcated. There is no combined figure on purpose:
         adding a Manager's target to their own Team Leaders' and Callers'
         counts the same business three times over. --}}
    <x-filament::section
        icon="heroicon-o-trophy"
        heading="Month to date by level — {{ $rangeEnd->format('F Y') }}"
        description="The total of all the Callers' targets, of all the Team Leaders' and of all the Managers', each on its own line. They are deliberately not added together."
    >
        <x-daily-commitment.monthly-level-summary :levels="$monthlyLevels" />

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ $monthly['people_with_target'] }} {{ \Illuminate\Support\Str::plural('person', $monthly['people_with_target']) }}
            in scope {{ $monthly['people_with_target'] === 1 ? 'has' : 'have' }} a target this month ·
            {{ $monthly['elapsed_working_days'] }} working {{ \Illuminate\Support\Str::plural('day', $monthly['elapsed_working_days']) }} elapsed,
            {{ $monthly['remaining_working_days'] }} remaining.
            DRR is each level's own MTD ÷ working days elapsed; "needed / day" closes that level's own gap.
        </p>
    </x-filament::section>

    {{-- Current pipeline — deliberately separate from today's achievement --}}
    <x-filament::section
        icon="heroicon-o-queue-list"
        heading="Current pipeline"
        description="Built only from this module: cases declared on commitments in this period that have not closed out. Never counted as achievement."
    >
        <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
            <span class="text-2xl font-extrabold text-gray-950 dark:text-white">
                {{ indianAmount($summary['pipeline_amount']) }}
            </span>
            <span class="text-sm text-gray-500">
                across {{ number_format($summary['pipeline_count']) }} open
                {{ \Illuminate\Support\Str::plural('case', $summary['pipeline_count']) }}
                (excludes disbursal, dropped and rejected)
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($ladder as $stage)
                @php $totals = $summary['pipeline_totals'][$stage->value]; @endphp
                <div class="dc-card" style="border-color: {{ $stage->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $stage->hex() }}">
                    <x-daily-commitment.stage-chip :stage="$stage" />
                    <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                    <div class="dc-card-words">{{ indianAmountInWords($totals['amount']) }}</div>
                    <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Declared fulfilment for the day --}}
    <x-filament::section
        icon="heroicon-o-squares-2x2"
        heading="Declared business"
        description="Only the customers people actually listed against their commitments in this period, at the highest stage each reached."
    >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            @foreach (\App\Enums\CommitmentStage::reportable() as $stage)
                @php $totals = $stageTotals[$stage->value]; @endphp
                <div class="dc-card" style="border-color: {{ $stage->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $stage->hex() }}">
                    <x-daily-commitment.stage-chip :stage="$stage" />
                    <div class="dc-card-value">{{ $totals['count'] }}</div>
                    @if ($stage->rank())
                        <div class="dc-card-hint">{{ indianAmount($totals['amount']) }} · {{ indianAmountInWords($totals['amount']) }}</div>
                    @else
                        <div class="dc-card-hint">cases</div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Today's commitment table --}}
    <x-filament::section
        icon="heroicon-o-table-cells"
        heading="Commitments — {{ $rangeLabel }}"
        :description="$rows->count() . ' of ' . $allRows->count() . ' ' . \Illuminate\Support\Str::plural('person', $allRows->count()) . ' shown — click a row for full details'"
    >
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">Nothing matches these filters.</p>
        @else
            <div class="overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Stage</th>
                            <th class="dc-num">Commitment</th>
                            <th class="dc-num">Achievement</th>
                            <th class="dc-num">Pending</th>
                            <th class="dc-num">%</th>
                            <th style="min-width: 6rem">Progress</th>
                            <th class="dc-num">Changes</th>
                            <th>Final stage</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $stage = $row['stage'];
                                $countMode = $row['count_mode'];
                            @endphp
                            @php
                                $detailUrl = $row['commitment']
                                    ? \App\Filament\Pages\DailyCommitmentDetail::getUrl(['record' => $row['commitment']->getKey()])
                                    : null;
                            @endphp
                            <tr
                                @if ($detailUrl)
                                    class="dc-row-link"
                                    x-data
                                    x-on:click="window.Livewire.navigate(@js($detailUrl))"
                                    title="Open commitment details"
                                @endif
                            >
                                <td>
                                    @if ($detailUrl)
                                        <a href="{{ $detailUrl }}" wire:navigate class="dc-row-name font-semibold">
                                            {{ $row['employee']->emp_name }}
                                        </a>
                                    @else
                                        <div class="font-semibold">{{ $row['employee']->emp_name }}</div>
                                    @endif
                                    <div class="text-xs text-gray-500">{{ $row['employee']->emp_id }}</div>
                                </td>
                                <td class="text-xs font-bold uppercase {{ \App\Models\Employee::designationColorClass($row['designation']) }}">
                                    {{ \App\Models\Employee::designationOptions()[$row['designation']] ?? '—' }}
                                </td>
                                <td>
                                    <x-daily-commitment.presence-chip :present="$row['present']" />
                                    @unless ($isSingleDay)
                                        <div class="text-xs text-gray-500">{{ $row['present_days'] }} days</div>
                                    @endunless
                                </td>
                                <td><x-daily-commitment.stage-chip :stage="$stage" /></td>
                                <td class="dc-num font-semibold">
                                    @if ($stage)
                                        <x-daily-commitment.amount :value="$row['target']" :count="$countMode" />
                                    @else — @endif
                                </td>
                                <td class="dc-num font-semibold">
                                    @if ($stage)
                                        <x-daily-commitment.amount :value="$row['achieved']" :count="$countMode" />
                                    @else — @endif
                                </td>
                                <td class="dc-num">
                                    @if ($stage)
                                        <x-daily-commitment.amount :value="$row['pending']" :count="$countMode" />
                                    @else — @endif
                                </td>
                                <td class="dc-num">{{ $stage ? $row['percentage'] . '%' : '—' }}</td>
                                <td>
                                    @if ($stage)
                                        <x-daily-commitment.progress-bar
                                            :percentage="$row['percentage']"
                                            :color="$stage->hex()"
                                        />
                                    @endif
                                </td>
                                <td class="dc-num" title="How many times the commitment or its status moved">
                                    {{ $row['changes'] }}
                                    @unless ($isSingleDay)
                                        <div class="text-xs text-gray-500">{{ $row['days'] }} days</div>
                                    @endunless
                                </td>
                                <td><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="Not started" /></td>
                                <td><x-daily-commitment.result-chip :result="$row['result']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
