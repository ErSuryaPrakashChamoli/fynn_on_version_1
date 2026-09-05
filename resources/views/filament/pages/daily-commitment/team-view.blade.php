<x-filament-panels::page>
    @php
        $focus = $this->focus;
        $ownRow = $this->ownRow;
        $reporteeRows = $this->reporteeRows;
        $callerRows = $this->callerRows;
        $callerSummary = $this->callerSummary;
        $date = $this->date;
        $designations = \App\Models\Employee::designationOptions();
        $canSetOtp = $this->canSetExpectedOtp();
    @endphp

    <x-filament::section icon="heroicon-o-funnel" heading="Day" compact>
        {{ $this->form }}

        @if ($focus && $focus->id !== auth()->user()?->employee?->id)
            <div class="mt-3 flex items-center gap-2 text-sm">
                <span class="text-gray-500">Viewing</span>
                <strong>{{ $focus->emp_name }}</strong>
                <span class="text-xs text-gray-500">({{ $designations[$focus->designation] ?? '—' }})</span>
                <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="resetFocus">
                    Back to my team
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>

    {{-- My commitment --}}
    @if ($ownRow)
        <x-filament::section
            icon="heroicon-o-flag"
            heading="{{ $focus->emp_name }} — own commitment"
            :description="$date->format('d M Y')"
        >
            @php
                $stage = $ownRow['stage'];
                $fmt = fn ($v) => $ownRow['count_mode'] ? number_format($v) : shortIndianAmount($v);
            @endphp

            @if (! $stage)
                <p class="text-sm text-gray-500">No commitment given for this date.</p>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <x-daily-commitment.kpi label="Commitment" :value="$fmt($ownRow['target'])" :hint="$stage->label()" :accent="$stage->hex()" />
                    <x-daily-commitment.kpi label="Achievement" :value="$fmt($ownRow['achieved'])" accent="#22c55e" />
                    <x-daily-commitment.kpi label="Pending" :value="$fmt($ownRow['pending'])" accent="#f97316" />
                    <x-daily-commitment.kpi label="Achievement %" :value="$ownRow['percentage'] . '%'" accent="#3b82f6" />
                    <div class="dc-card">
                        <div class="dc-card-label">Result</div>
                        <div class="mt-2"><x-daily-commitment.result-chip :result="$ownRow['result']" /></div>
                        <div class="dc-card-hint">
                            Pipeline {{ shortIndianAmount($ownRow['pipeline']['total_amount']) }} (separate)
                        </div>
                    </div>
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Direct reportees --}}
    <x-filament::section
        icon="heroicon-o-user-group"
        heading="Direct reportees"
        description="Click a name to drill into their own team."
    >
        @if ($reporteeRows->isEmpty())
            <p class="text-sm text-gray-500">No direct reportees.</p>
        @else
            <div class="overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Stage</th>
                            <th class="dc-num">Commitment</th>
                            <th class="dc-num">Achievement</th>
                            <th class="dc-num">Pending</th>
                            <th class="dc-num">%</th>
                            <th class="dc-num">Changes</th>
                            <th>Final stage</th>
                            <th class="dc-num">Pipeline</th>
                            <th>Result</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reporteeRows as $row)
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
                                <td class="text-xs {{ \App\Models\Employee::designationColorClass($row['employee']->designation) }} font-bold uppercase">
                                    {{ $designations[$row['employee']->designation] ?? '—' }}
                                </td>
                                <td><x-daily-commitment.presence-chip :present="$row['present']" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$stage" /></td>
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['target']) : '—' }}</td>
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['achieved']) : '—' }}</td>
                                <td class="dc-num">{{ $stage ? $fmt($row['pending']) : '—' }}</td>
                                <td class="dc-num">{{ $stage ? $row['percentage'] . '%' : '—' }}</td>
                                <td class="dc-num">{{ $row['changes'] }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="—" /></td>
                                <td class="dc-num text-gray-500">{{ shortIndianAmount($row['pipeline']['total_amount']) }}</td>
                                <td><x-daily-commitment.result-chip :result="$row['result']" /></td>
                                <td x-on:click.stop>
                                    @if ($row['employee']->designation !== \App\Models\Employee::DESIGNATION_CALLER)
                                        <x-filament::button
                                            size="xs"
                                            color="gray"
                                            icon="heroicon-o-arrow-right-circle"
                                            wire:click="focusOn({{ $row['employee']->id }})"
                                        >
                                            Open
                                        </x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Callers --}}
    <x-filament::section
        icon="heroicon-o-phone"
        heading="Callers"
        description="Attendance, commitment and OTP for every caller below this level."
    >
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <x-daily-commitment.kpi label="Callers" :value="$callerSummary['people']" accent="#3b82f6" />
            <x-daily-commitment.kpi label="Present" :value="$callerSummary['present']" accent="#22c55e" />
            <x-daily-commitment.kpi label="Absent" :value="$callerSummary['absent']" accent="#6b7280" />
            <x-daily-commitment.kpi label="Expected OTP" :value="number_format($callerSummary['expected_otp'])" accent="#eab308" />
            <x-daily-commitment.kpi label="Actual OTP" :value="number_format($callerSummary['actual_otp'])" accent="#0d9488" />
            <x-daily-commitment.kpi label="OTP %" :value="$callerSummary['otp_percentage'] . '%'" accent="#a855f7" />
            <x-daily-commitment.kpi
                label="Pipeline"
                :value="shortIndianAmount($callerSummary['pipeline_amount'])"
                hint="{{ $callerSummary['pipeline_count'] }} open cases"
                accent="#0d9488"
            />
        </div>

        @if ($callerRows->isEmpty())
            <p class="text-sm text-gray-500">No callers in this view.</p>
        @else
            <div class="overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Caller</th>
                            <th>Status</th>
                            <th>Stage</th>
                            <th class="dc-num">Commitment</th>
                            <th class="dc-num">Achievement</th>
                            <th class="dc-num">Pending</th>
                            <th class="dc-num">%</th>
                            <th class="dc-num">Exp. OTP</th>
                            <th class="dc-num">Act. OTP</th>
                            <th class="dc-num">OTP %</th>
                            <th class="dc-num">Changes</th>
                            <th>Final stage</th>
                            <th class="dc-num">Pipeline</th>
                            <th>Result</th>
                            @if ($canSetOtp)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($callerRows as $row)
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
                                <td><x-daily-commitment.presence-chip :present="$row['present']" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$stage" /></td>
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['target']) : '—' }}</td>
                                <td class="dc-num font-semibold">{{ $stage ? $fmt($row['achieved']) : '—' }}</td>
                                <td class="dc-num">{{ $stage ? $fmt($row['pending']) : '—' }}</td>
                                <td class="dc-num">{{ $stage ? $row['percentage'] . '%' : '—' }}</td>
                                <td class="dc-num">{{ $row['present'] ? number_format($row['expected_otp']) : '—' }}</td>
                                <td class="dc-num">{{ $row['present'] ? number_format($row['actual_otp']) : '—' }}</td>
                                <td class="dc-num">
                                    {{ $row['present'] && $row['expected_otp'] > 0 ? $row['otp_percentage'] . '%' : '—' }}
                                </td>
                                <td class="dc-num">{{ $row['changes'] }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="—" /></td>
                                <td class="dc-num text-gray-500">{{ shortIndianAmount($row['pipeline']['total_amount']) }}</td>
                                <td><x-daily-commitment.result-chip :result="$row['result']" /></td>
                                @if ($canSetOtp)
                                    <td x-on:click.stop>
                                        {{ ($this->setExpectedOtpAction)(['employee' => $row['employee']->id]) }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
