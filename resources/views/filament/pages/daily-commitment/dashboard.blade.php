<x-filament-panels::page>
    @php
        $summary = $this->summary;
        $monthly = $this->monthly;
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

    {{-- Headline KPIs --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
        <x-daily-commitment.kpi
            label="Commitment"
            :value="shortIndianAmount($summary['committed_amount'])"
            hint="{{ $summary['with_commitment'] }} of {{ $summary['people'] }} committed · {{ $summary['submitted'] }} submitted"
            accent="#3b82f6"
        />
        <x-daily-commitment.kpi
            label="Achievement"
            :value="shortIndianAmount($summary['achieved_amount'])"
            accent="#22c55e"
        />
        <x-daily-commitment.kpi
            label="Achievement"
            :value="$summary['percentage'] . '%'"
            accent="#a855f7"
        />
        <x-daily-commitment.kpi
            label="Pending"
            :value="shortIndianAmount($summary['pending_amount'])"
            accent="#f97316"
        />
        <x-daily-commitment.kpi label="Met" :value="$summary['met']" accent="#22c55e" />
        <x-daily-commitment.kpi label="Failed" :value="$summary['failed']" accent="#ef4444" />
        <x-daily-commitment.kpi label="Overachieved" :value="$summary['overachieved']" accent="#0d9488" />
    </div>

    <div class="mt-2">
        <x-daily-commitment.progress-bar :percentage="$summary['percentage']" color="#22c55e" />
    </div>

    {{-- Attendance + OTP + MTD --}}
    <div class="grid gap-4 lg:grid-cols-3">
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

        <x-filament::section icon="heroicon-o-calendar-days" heading="Month to date" compact>
            <div class="grid grid-cols-2 gap-3">
                <x-daily-commitment.kpi label="Monthly target" :value="shortIndianAmount($monthly['target'])" accent="#3b82f6" />
                <x-daily-commitment.kpi label="MTD achievement" :value="shortIndianAmount($monthly['achieved'])" accent="#22c55e" />
                <x-daily-commitment.kpi label="Pending" :value="shortIndianAmount($monthly['pending'])" accent="#f97316" />
                <x-daily-commitment.kpi
                    label="DRR"
                    :value="shortIndianAmount($monthly['drr'])"
                    hint="MTD ÷ {{ $monthly['elapsed_working_days'] }} working days"
                    accent="#a855f7"
                />
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ $monthly['percentage'] }}% achieved · needs {{ shortIndianAmount($monthly['required_drr']) }}/day
                for the remaining {{ $monthly['remaining_working_days'] }} working days
                ({{ $monthly['people_with_target'] }} with a monthly target).
            </p>
        </x-filament::section>
    </div>

    {{-- Current pipeline — deliberately separate from today's achievement --}}
    <x-filament::section
        icon="heroicon-o-queue-list"
        heading="Current pipeline"
        description="Built only from this module: cases declared on commitments in this period that have not closed out. Never counted as achievement."
    >
        <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
            <span class="text-2xl font-extrabold text-gray-950 dark:text-white">
                {{ shortIndianAmount($summary['pipeline_amount']) }}
            </span>
            <span class="text-sm text-gray-500">
                across {{ number_format($summary['pipeline_count']) }} open
                {{ \Illuminate\Support\Str::plural('case', $summary['pipeline_count']) }}
                (excludes disbursed, dropped and rejected)
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($ladder as $stage)
                @php $totals = $summary['pipeline_totals'][$stage->value]; @endphp
                <div class="dc-card" style="border-color: {{ $stage->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $stage->hex() }}">
                    <x-daily-commitment.stage-chip :stage="$stage" />
                    <div class="dc-card-value">{{ shortIndianAmount($totals['amount']) }}</div>
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
                        <div class="dc-card-hint">{{ shortIndianAmount($totals['amount']) }}</div>
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
                                $fmt = fn ($v) => $row['count_mode'] ? number_format($v) : shortIndianAmount($v);
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
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['target']) : '—' }}</td>
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['achieved']) : '—' }}</td>
                                <td class="dc-num">{{ $stage ? $fmt($row['pending']) : '—' }}</td>
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
