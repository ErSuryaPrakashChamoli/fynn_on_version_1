<x-filament-panels::page>
    @php
        $commitment = $this->commitment;
        $row = $this->row;
        $monthly = $this->monthly;
        $entries = $this->entries;
        $ladder = \App\Enums\CommitmentStage::ladder();
        $submitted = $commitment?->submitted_at !== null;
        $stage = $row['stage'] ?? null;
        $isCount = (bool) $stage?->isCount();
        $gate = $this->gateStatus;
        $split = $this->split;
        $morningDue = \App\Services\DailyCommitmentGate::MORNING_DEADLINE;
        $eveningDue = \App\Services\DailyCommitmentGate::EVENING_DEADLINE;
    @endphp

    {{-- 0. WHAT IS DUE, AND WHEN --}}
    @if ($gate['blocked'])
        <div class="flex items-start gap-3 rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-200 dark:ring-danger-400/30">
            <x-filament::icon icon="heroicon-o-lock-closed" class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <p class="font-semibold">The rest of the LMS is locked.</p>
                <p class="mt-0.5">{{ $this->gateMessage }}</p>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
            <span class="flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-o-sun" class="h-4 w-4 shrink-0" />
                Commitment due by <strong>{{ $morningDue }}</strong>
            </span>
            <span class="flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-o-moon" class="h-4 w-4 shrink-0" />
                Achievement due by <strong>{{ $eveningDue }}</strong>
            </span>
            <span class="text-gray-500 dark:text-gray-400">Both are compulsory — the panel closes behind either deadline.</span>
        </div>
    @endif

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
                    :amount="$row['target']"
                    :count="$isCount"
                    :hint="$stage->label()"
                    :accent="$stage->hex()"
                />
                <x-daily-commitment.kpi
                    label="Achievement"
                    :amount="$row['achieved']"
                    :count="$isCount"
                    hint="{{ $stage->label() }} and beyond"
                    accent="#22c55e"
                />
                <x-daily-commitment.kpi label="Pending" :amount="$row['pending']" :count="$isCount" accent="#f97316" />
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
                    OTP is the bottom rung, so every case you declare below counts toward it.
                    ({{ $row['actual_otp'] }} {{ \Illuminate\Support\Str::plural('case', $row['actual_otp']) }}
                    opened in the LMS today, for reference — that figure does not settle the commitment.)
                </p>
            @endif
        </x-filament::section>

        {{-- 3. FINAL STATUS / FULFILMENT --}}
            <x-filament::section
                icon="heroicon-o-clipboard-document-check"
                heading="Final status / fulfilment"
                description="Name the cases that make up today's business. A day can be closed in parts — lower stages still count, they just do not earn a full pass."
            >
                {{-- Where the day sits against the promise: what landed at
                     or above the committed stage, and what came in below
                     it. This is the whole 18:30 question in one strip. --}}
                <div class="mb-5 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="grid grid-cols-1 divide-y divide-gray-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10">
                        <div class="p-4">
                            <div class="dc-card-label">You committed to</div>
                            <div class="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">
                                <x-daily-commitment.amount :value="$split['target']" :count="$split['is_count']" :words="false" />
                            </div>
                            <div class="mt-1"><x-daily-commitment.stage-chip :stage="$split['stage']" /></div>
                        </div>
                        <div class="p-4">
                            <div class="dc-card-label">At {{ $split['stage']?->label() }} or above</div>
                            <div class="mt-1 text-xl font-extrabold text-green-600 dark:text-green-400">
                                <x-daily-commitment.amount :value="$split['at_or_above']" :count="$split['is_count']" :words="false" />
                            </div>
                            <div class="dc-card-hint">Counts in full</div>
                        </div>
                        <div class="p-4">
                            <div class="dc-card-label">Below {{ $split['stage']?->label() }}</div>
                            <div class="mt-1 text-xl font-extrabold text-amber-600 dark:text-amber-400">
                                <x-daily-commitment.amount :value="$split['below']" :count="$split['is_count']" :words="false" />
                            </div>
                            <div class="dc-card-hint">
                                @if ($split['is_count'])
                                    Nothing ranks below OTP — every declared case counts
                                @else
                                    Makes the day <strong>partially met</strong>, not a pass
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- The ladder, top rung first, so the committed stage
                         is what the eye lands on. --}}
                    <div class="border-t border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            @foreach ($split['stages'] as $value => $totals)
                                @php $rung = \App\Enums\CommitmentStage::from($value); @endphp
                                <div class="flex items-center gap-2 text-sm {{ $totals['counts'] ? '' : 'opacity-60' }}">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $rung->hex() }}"></span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ $rung->label() }}</span>
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ indianAmount($totals['amount']) }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if ($submitted)
                    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg bg-green-50 p-3 text-sm text-green-800 dark:bg-green-500/10 dark:text-green-300">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                        <span>Submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}.</span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-pencil-square" wire:click="reopenFinalStatus">
                            Edit final status
                        </x-filament::button>
                    </div>

                    @if ($commitment->declaration_note)
                        <div class="mb-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <span class="font-semibold">Nil day:</span> {{ $commitment->declaration_note }}
                        </div>
                    @endif
                @else
                    {{-- Meeting the commitment is not compulsory; declaring
                         the outcome is. So the first question is simply
                         whether there is anything to feed in — the customer
                         list is never put in front of somebody who had no
                         customers. --}}
                    @if ($this->declarationMode === null)
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                type="button"
                                wire:click="chooseDeclarationMode('cases')"
                                class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-500 hover:bg-primary-50/50 dark:border-white/10 dark:hover:border-primary-400 dark:hover:bg-primary-500/10"
                            >
                                <x-filament::icon icon="heroicon-o-check-badge" class="mt-0.5 h-6 w-6 shrink-0 text-success-600 dark:text-success-400" />
                                <span>
                                    <span class="block font-semibold text-gray-950 dark:text-white">I have business to declare</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                        Name each case with its customer and mobile number. Part of the commitment is fine —
                                        lower stages still count.
                                    </span>
                                </span>
                            </button>

                            <button
                                type="button"
                                wire:click="chooseDeclarationMode('failed')"
                                class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 text-start transition hover:border-danger-500 hover:bg-danger-50/50 dark:border-white/10 dark:hover:border-danger-400 dark:hover:bg-danger-500/10"
                            >
                                <x-filament::icon icon="heroicon-o-x-circle" class="mt-0.5 h-6 w-6 shrink-0 text-danger-600 dark:text-danger-400" />
                                <span>
                                    <span class="block font-semibold text-gray-950 dark:text-white">Nothing came through — commitment failed</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                        Record a zero against every stage. A failed day is a legitimate answer; it just has
                                        to be stated.
                                    </span>
                                </span>
                            </button>
                        </div>
                    @elseif ($this->declarationMode === 'cases')
                        <form wire:submit="submitFinalStatus">
                            {{ $this->fulfilmentForm }}

                            <div class="mt-5 flex flex-wrap items-center gap-3">
                                <x-filament::button type="submit" icon="heroicon-o-check-badge" color="success">
                                    Submit final status
                                </x-filament::button>

                                <x-filament::button type="button" color="gray" icon="heroicon-o-bookmark" wire:click="saveFulfilment">
                                    Save without submitting
                                </x-filament::button>

                                <button
                                    type="button"
                                    wire:click="chooseDeclarationMode(null)"
                                    class="text-xs text-gray-500 underline-offset-2 hover:underline dark:text-gray-400"
                                >
                                    Nothing came through after all
                                </button>
                            </div>
                        </form>
                    @else
                        {{-- The zeros ARE the declaration: a figure against
                             every rung, rather than one vague "nothing". --}}
                        <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">Nothing at any stage today</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Confirm the zeros below. This closes the day as <strong>failed</strong> and unlocks the rest
                                of the LMS. Anything that did come through belongs on a named case instead.
                            </p>

                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                @foreach ($ladder as $rung)
                                    <label class="block">
                                        <span class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $rung->hex() }}"></span>
                                            {{ $rung->label() }}
                                        </span>
                                        <input
                                            type="number"
                                            min="0"
                                            wire:model="nilStages.{{ $rung->value }}"
                                            class="mt-1 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                        />
                                    </label>
                                @endforeach
                            </div>

                            <textarea
                                wire:model="nothingReason"
                                rows="2"
                                placeholder="Anything worth noting? (optional) e.g. Two files were pushed back by credit."
                                class="mt-4 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            ></textarea>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <x-filament::button size="sm" color="danger" icon="heroicon-o-flag" wire:click="declareNothing">
                                    Record commitment as failed
                                </x-filament::button>

                                <button
                                    type="button"
                                    wire:click="chooseDeclarationMode('cases')"
                                    class="text-xs text-gray-500 underline-offset-2 hover:underline dark:text-gray-400"
                                >
                                    Actually, I do have something to declare
                                </button>
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Customer-wise breakup --}}
                @if ($entries->isNotEmpty())
                    <div class="mt-6 overflow-x-auto">
                        <table class="dc-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Mobile</th>
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
                                        <td class="whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">{{ $entry->mobile_no ?? '—' }}</td>
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
                                        <td class="dc-num font-semibold"><x-daily-commitment.amount :value="$entry->amount" /></td>
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
                                <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                                <div class="dc-card-words">{{ indianAmountInWords($totals['amount']) }}</div>
                                <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

        {{-- 4. CURRENT PIPELINE — never mixed into today's achievement --}}
        <x-filament::section
            icon="heroicon-o-queue-list"
            heading="Current pipeline"
            description="Everything you are carrying right now, at whatever stage it is sitting. Separate from today's achievement."
        >
            <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
                <span class="text-2xl font-extrabold text-gray-950 dark:text-white">
                    {{ indianAmount($row['pipeline']['total_amount']) }}
                </span>
                <span class="text-sm text-gray-500">
                    across {{ $row['pipeline']['total_count'] }} open {{ \Illuminate\Support\Str::plural('case', $row['pipeline']['total_count']) }}
                    (excludes disbursal, dropped and rejected)
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($ladder as $rung)
                    @php $totals = $row['pipeline']['stages'][$rung->value] ?? ['amount' => 0, 'count' => 0]; @endphp
                    <div class="dc-card" style="border-color: {{ $rung->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $rung->hex() }}">
                        <x-daily-commitment.stage-chip :stage="$rung" />
                        <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                        <div class="dc-card-words">{{ indianAmountInWords($totals['amount']) }}</div>
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
                @php $mFmt = fn ($v) => $monthly['is_count'] ? number_format($v) : indianAmount($v); @endphp
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <x-daily-commitment.kpi
                        label="Monthly target"
                        :amount="$monthly['target']"
                        :count="$monthly['is_count']"
                        :hint="$monthly['stage']->label()"
                        :accent="$monthly['stage']->hex()"
                    />
                    <x-daily-commitment.kpi label="MTD achievement" :amount="$monthly['achieved']" :count="$monthly['is_count']" accent="#22c55e" />
                    <x-daily-commitment.kpi label="Pending" :amount="$monthly['pending']" :count="$monthly['is_count']" accent="#f97316" />
                    <x-daily-commitment.kpi label="Achievement %" :value="$monthly['percentage'] . '%'" accent="#3b82f6" />
                    <x-daily-commitment.kpi
                        label="DRR"
                        :amount="$monthly['drr']"
                        :count="$monthly['is_count']"
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
                                            {{ $log->old_count ? number_format($log->old_count) : indianAmount($log->old_amount) }}
                                        </span>
                                    </td>
                                    <td>
                                        <x-daily-commitment.stage-chip
                                            :stage="$log->new_stage ? \App\Enums\CommitmentStage::tryFrom($log->new_stage) : null"
                                        />
                                        <span class="ms-1 text-xs text-gray-500">
                                            {{ $log->new_count ? number_format($log->new_count) : indianAmount($log->new_amount) }}
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
