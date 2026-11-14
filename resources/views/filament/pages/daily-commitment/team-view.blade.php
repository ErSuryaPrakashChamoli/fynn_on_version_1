<x-filament-panels::page>
    @php
        $focus = $this->focus;
        $ownRow = $this->ownRow;
        $reporteeRows = $this->reporteeRows;
        $callerRows = $this->callerRows;
        $callerSummary = $this->callerSummary;
        $callerLevels = $this->callerLevels;
        $reporteeLevels = $this->reporteeLevels;
        $date = $this->date;
        $designations = \App\Models\Employee::designationOptions();
        $canSetOtp = $this->canSetExpectedOtp();
    @endphp

    <x-filament::section icon="heroicon-o-funnel" heading="Day" compact>
        {{ $this->form }}

        @if ($focus && $focus->id !== auth()->user()?->employee?->id)
            <div class="fynn-toolbar mt-3 text-sm">
                <span class="fynn-toolbar-label">Viewing</span>
                <span class="flex min-w-0 items-center gap-2">
                    <span class="fynn-avatar fynn-avatar--sm">{{ $focus->initials }}</span>
                    <strong class="text-gray-950 dark:text-white">{{ $focus->emp_name }}</strong>
                    <span class="text-xs {{ \App\Models\Employee::designationColorClass($focus->designation) }}">({{ $designations[$focus->designation] ?? '—' }})</span>
                </span>
                <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="resetFocus" class="ms-auto">
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
                $ownCount = $ownRow['count_mode'];
            @endphp

            @if (! $stage)
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-hand-raised" /></span>
                    <span>No commitment given for this date.</span>
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <x-daily-commitment.kpi label="Commitment" :amount="$ownRow['target']" :count="$ownCount" :hint="$stage->label()" :accent="$stage->hex()" icon="heroicon-o-hand-raised" />
                    <x-daily-commitment.kpi label="Achievement" :amount="$ownRow['achieved']" :count="$ownCount" accent="#22c55e" icon="heroicon-o-banknotes" />
                    <x-daily-commitment.kpi label="Pending" :amount="$ownRow['pending']" :count="$ownCount" accent="#f97316" icon="heroicon-o-clock" />
                    <x-daily-commitment.kpi label="Achievement %" :value="$ownRow['percentage'] . '%'" accent="#3b82f6" icon="heroicon-o-chart-pie" />
                    <div class="dc-card" style="--dc-accent: #8b5cf6">
                        <div class="fynn-kpi-head">
                            <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-check-badge" /></span>
                            <div class="dc-card-label">Result</div>
                        </div>
                        <div class="mt-2.5"><x-daily-commitment.result-chip :result="$ownRow['result']" /></div>
                        <div class="dc-card-hint">
                            Pipeline {{ indianAmount($ownRow['pipeline']['total_amount']) }} (separate)
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
        description="Totalled by their own level — kept apart from your own commitment above and from the callers below."
    >
        @unless ($reporteeRows->isEmpty())
            <div class="mb-4">
                <x-daily-commitment.level-summary :levels="$reporteeLevels" />
            </div>
        @endunless

        <p class="fynn-note mt-0 mb-3">
            <x-filament::icon icon="heroicon-m-information-circle" />
            <span>Click a name to drill into their own team.</span>
        </p>

        @if ($reporteeRows->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-group" /></span>
                <span>No direct reportees.</span>
            </div>
        @else
            <div class="fynn-table-wrap overflow-x-auto">
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
                                <td>
                                    <span class="dc-level {{ \App\Models\Employee::designationColorClass($row['employee']->designation) }}">{{ $designations[$row['employee']->designation] ?? '—' }}</span>
                                </td>
                                <td><x-daily-commitment.presence-chip :present="$row['present']" /></td>
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
                                <td class="dc-num">{{ $row['changes'] }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="—" /></td>
                                <td class="dc-num text-gray-500"><x-daily-commitment.amount :value="$row['pipeline']['total_amount']" /></td>
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
        <div class="mb-4">
            <x-daily-commitment.level-summary :levels="$callerLevels" />
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <x-daily-commitment.kpi label="Callers" :value="$callerSummary['people']" accent="#3b82f6" icon="heroicon-o-phone" />
            <x-daily-commitment.kpi label="Present" :value="$callerSummary['present']" accent="#22c55e" icon="heroicon-o-user-group" />
            <x-daily-commitment.kpi label="Absent" :value="$callerSummary['absent']" accent="#6b7280" icon="heroicon-o-user-minus" />
            <x-daily-commitment.kpi label="Expected OTP" :value="number_format($callerSummary['expected_otp'])" accent="#eab308" icon="heroicon-o-flag" />
            <x-daily-commitment.kpi label="Actual OTP" :value="number_format($callerSummary['actual_otp'])" accent="#0d9488" icon="heroicon-o-device-phone-mobile" />
            <x-daily-commitment.kpi label="OTP %" :value="$callerSummary['otp_percentage'] . '%'" accent="#a855f7" icon="heroicon-o-chart-bar" />
            <x-daily-commitment.kpi
                label="Pipeline"
                :amount="$callerSummary['pipeline_amount']"
                hint="{{ $callerSummary['pipeline_count'] }} open cases"
                accent="#0d9488"
                icon="heroicon-o-queue-list"
            />
        </div>

        @if ($callerRows->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-phone" /></span>
                <span>No callers in this view.</span>
            </div>
        @else
            <div class="fynn-table-wrap overflow-x-auto">
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
                                <td><x-daily-commitment.presence-chip :present="$row['present']" /></td>
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
                                <td class="dc-num">{{ $row['present'] ? number_format($row['expected_otp']) : '—' }}</td>
                                <td class="dc-num">{{ $row['present'] ? number_format($row['actual_otp']) : '—' }}</td>
                                <td class="dc-num">
                                    {{ $row['present'] && $row['expected_otp'] > 0 ? $row['otp_percentage'] . '%' : '—' }}
                                </td>
                                <td class="dc-num">{{ $row['changes'] }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="—" /></td>
                                <td class="dc-num text-gray-500"><x-daily-commitment.amount :value="$row['pipeline']['total_amount']" /></td>
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
