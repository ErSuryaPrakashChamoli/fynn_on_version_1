<x-filament-panels::page>
    @php
        $commitment = $this->commitment;
        $row = $this->row;
        $monthly = $this->monthly;
        $entries = $this->entries;
        $ladder = \App\Enums\CommitmentStage::ladder();
        $submitted = $commitment?->submitted_at !== null;
        $stage = $row['stage'] ?? null;
        $fmt = fn ($v) => $stage && $stage->isCount() ? number_format($v) : shortIndianAmount($v);
    @endphp

    {{-- 1. MORNING --}}
    <form wire:submit="save">
        <x-filament::section
            icon="heroicon-o-sun"
            heading="Morning commitment"
            description="Just a stage and a number — no customer needed yet. Once submitted it is locked for the day."
        >
            {{ $this->form }}

            @if ($this->isCommitmentLocked())
                <p class="mt-5 flex items-center gap-2 rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4 shrink-0" />
                    Your commitment for this day is locked. It cannot be changed or withdrawn — only an Admin can correct it,
                    and the correction is recorded in the change log.
                </p>
            @else
                <div class="mt-5">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        {{ $commitment ? 'Update commitment' : 'Submit commitment' }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>
    </form>

    @if (! $row)
        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            Your account is not linked to an employee profile yet.
        </div>
    @elseif ($commitment)
        {{-- 2. TODAY'S POSITION --}}
        <x-filament::section
            icon="heroicon-o-chart-bar"
            heading="Today's position"
            :description="\Illuminate\Support\Carbon::parse($this->date)->format('l, d M Y')"
        >
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <x-daily-commitment.kpi
                    label="Commitment"
                    :value="$fmt($row['target'])"
                    :hint="$stage->label()"
                    :accent="$stage->hex()"
                />
                <x-daily-commitment.kpi
                    label="Achievement"
                    :value="$fmt($row['achieved'])"
                    hint="{{ $stage->label() }} and beyond"
                    accent="#22c55e"
                />
                <x-daily-commitment.kpi label="Pending" :value="$fmt($row['pending'])" accent="#f97316" />
                <x-daily-commitment.kpi label="Achievement %" :value="$row['percentage'] . '%'" accent="#3b82f6" />
                <div class="dc-card">
                    <div class="dc-card-label">Result</div>
                    <div class="mt-2">
                        <x-daily-commitment.result-chip :result="$row['result']" />
                    </div>
                    <div class="dc-card-hint">
                        {{ $submitted ? 'Final status submitted' : 'Not submitted yet' }}
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <x-daily-commitment.progress-bar :percentage="$row['percentage']" :color="$stage->hex()" />
            </div>

            @if ($stage->isCount())
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    An OTP commitment is counted automatically from the cases you opened today
                    ({{ $row['actual_otp'] }} so far) — no customer list is needed.
                </p>
            @endif
        </x-filament::section>

        {{-- 3. FINAL STATUS / FULFILMENT --}}
        @unless ($stage->isCount())
            <x-filament::section
                icon="heroicon-o-clipboard-document-check"
                heading="Final status / fulfilment"
                description="Which customers make up today's business? Only what you list here counts as achievement."
            >
                @if ($submitted)
                    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg bg-green-50 p-3 text-sm text-green-800 dark:bg-green-500/10 dark:text-green-300">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                        <span>Submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}.</span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-pencil-square" wire:click="reopenFinalStatus">
                            Edit final status
                        </x-filament::button>
                    </div>
                @else
                    <form wire:submit="submitFinalStatus">
                        {{ $this->fulfilmentForm }}

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <x-filament::button type="submit" icon="heroicon-o-check-badge" color="success">
                                Submit final status
                            </x-filament::button>

                            <x-filament::button type="button" color="gray" icon="heroicon-o-bookmark" wire:click="saveFulfilment">
                                Save without submitting
                            </x-filament::button>
                        </div>
                    </form>
                @endif

                {{-- Customer-wise breakup --}}
                @if ($entries->isNotEmpty())
                    <div class="mt-6 overflow-x-auto">
                        <table class="dc-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Lead / App ID</th>
                                    <th>Stage reached</th>
                                    <th>Outcome</th>
                                    <th class="dc-num">Amount</th>
                                    <th>Counts?</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    @php
                                        $effective = $entry->effectiveStage();
                                        $counts = $entry->countsToward($stage);
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="font-semibold">{{ $entry->customer_name }}</div>
                                            @if ($entry->remarks)
                                                <div class="text-xs text-gray-500">{{ $entry->remarks }}</div>
                                            @endif
                                        </td>
                                        <td class="text-xs text-gray-500">{{ $entry->reference ?? '—' }}</td>
                                        <td>
                                            <x-daily-commitment.stage-chip :stage="$effective" />
                                            @if ($entry->lms_highest_stage && $entry->lms_highest_stage !== $entry->stage)
                                                <div class="mt-1 text-xs text-gray-500">
                                                    LMS history: {{ $entry->lms_highest_stage->label() }}
                                                </div>
                                            @endif
                                        </td>
                                        <td><x-daily-commitment.stage-chip :stage="$entry->outcome" muted="Live" /></td>
                                        <td class="dc-num font-semibold">{{ shortIndianAmount($entry->amount) }}</td>
                                        <td>
                                            @if ($counts)
                                                <span class="dc-chip bg-green-100 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30">Counts</span>
                                            @else
                                                <span class="dc-chip bg-gray-200 text-gray-600 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30">Below {{ $stage->label() }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($ladder as $rung)
                            @php $totals = $row['breakdown']['stages'][$rung->value] ?? ['amount' => 0, 'count' => 0]; @endphp
                            <div class="dc-card" style="border-color: {{ $rung->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $rung->hex() }}">
                                <x-daily-commitment.stage-chip :stage="$rung" />
                                <div class="dc-card-value">{{ shortIndianAmount($totals['amount']) }}</div>
                                <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endunless

        {{-- 4. CURRENT PIPELINE — never mixed into today's achievement --}}
        <x-filament::section
            icon="heroicon-o-queue-list"
            heading="Current pipeline"
            description="Everything you are carrying right now, at whatever stage it is sitting. Separate from today's achievement."
        >
            <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
                <span class="text-2xl font-extrabold text-gray-950 dark:text-white">
                    {{ shortIndianAmount($row['pipeline']['total_amount']) }}
                </span>
                <span class="text-sm text-gray-500">
                    across {{ $row['pipeline']['total_count'] }} open {{ \Illuminate\Support\Str::plural('case', $row['pipeline']['total_count']) }}
                    (excludes disbursed, dropped and rejected)
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($ladder as $rung)
                    @php $totals = $row['pipeline']['stages'][$rung->value] ?? ['amount' => 0, 'count' => 0]; @endphp
                    <div class="dc-card" style="border-color: {{ $rung->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $rung->hex() }}">
                        <x-daily-commitment.stage-chip :stage="$rung" />
                        <div class="dc-card-value">{{ shortIndianAmount($totals['amount']) }}</div>
                        <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- 5. MTD --}}
        @if (($monthly['stage'] ?? null))
            <x-filament::section
                icon="heroicon-o-calendar-days"
                heading="Month to date"
                collapsible
            >
                @php $mFmt = fn ($v) => $monthly['is_count'] ? number_format($v) : shortIndianAmount($v); @endphp
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <x-daily-commitment.kpi
                        label="Monthly target"
                        :value="$mFmt($monthly['target'])"
                        :hint="$monthly['stage']->label()"
                        :accent="$monthly['stage']->hex()"
                    />
                    <x-daily-commitment.kpi label="MTD achievement" :value="$mFmt($monthly['achieved'])" accent="#22c55e" />
                    <x-daily-commitment.kpi label="Pending" :value="$mFmt($monthly['pending'])" accent="#f97316" />
                    <x-daily-commitment.kpi label="Achievement %" :value="$monthly['percentage'] . '%'" accent="#3b82f6" />
                    <x-daily-commitment.kpi
                        label="DRR"
                        :value="$mFmt($monthly['drr'])"
                        hint="{{ $mFmt($monthly['achieved']) }} ÷ {{ $monthly['elapsed_working_days'] }} working days"
                        accent="#a855f7"
                    />
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Needed per remaining working day: <strong>{{ $mFmt($monthly['required_drr']) }}</strong>
                    ({{ $mFmt($monthly['pending']) }} ÷ {{ $monthly['remaining_working_days'] }} days left of {{ $monthly['total_working_days'] }}).
                    MTD is the sum of each day's submitted fulfilment.
                </p>
            </x-filament::section>
        @endif

        {{-- 6. LOG --}}
        @if ($this->logs->isNotEmpty())
            <x-filament::section icon="heroicon-o-clock" heading="Commitment history" collapsible collapsed>
                <div class="overflow-x-auto">
                    <table class="dc-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Change</th>
                                <th>From</th>
                                <th>To</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->logs as $log)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                                    <td class="capitalize">{{ $log->change_type }}</td>
                                    <td>
                                        <x-daily-commitment.stage-chip
                                            :stage="$log->old_stage ? \App\Enums\CommitmentStage::tryFrom($log->old_stage) : null"
                                        />
                                        <span class="ms-1 text-xs text-gray-500">
                                            {{ $log->old_count ? number_format($log->old_count) : shortIndianAmount($log->old_amount) }}
                                        </span>
                                    </td>
                                    <td>
                                        <x-daily-commitment.stage-chip
                                            :stage="$log->new_stage ? \App\Enums\CommitmentStage::tryFrom($log->new_stage) : null"
                                        />
                                        <span class="ms-1 text-xs text-gray-500">
                                            {{ $log->new_count ? number_format($log->new_count) : shortIndianAmount($log->new_amount) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
