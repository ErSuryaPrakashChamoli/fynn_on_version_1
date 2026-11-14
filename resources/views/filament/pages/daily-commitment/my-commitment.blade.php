{{--
    One day, two steps: give the promise, then answer it.

    Everything that is not one of those two steps — the standing pipeline,
    the month, the change log — is reference material and is folded away
    at the bottom. The day's position is stated ONCE, in the hero; it used
    to be repeated across a KPI row, a split strip and three separate
    ladder grids, which is what made this screen hard to read.
--}}
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
        $accent = $stage?->hex() ?? '#6b7280';
        // One look for the raw zero-declaration controls, matching Filament's own inputs.
        $field = 'block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-danger-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-danger-500';
    @endphp

    {{-- WHAT IS DUE, AND WHEN --}}
    @if ($gate['blocked'])
        <div class="fynn-callout fynn-callout--danger flex items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-danger-500/15 text-danger-700 dark:text-danger-300">
                <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5" />
            </span>
            <div>
                <p class="fynn-callout-title">The rest of the LMS is locked.</p>
                <p class="mt-0.5">{{ $this->gateMessage }}</p>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <span class="fynn-chip">
                <x-filament::icon icon="heroicon-m-sun" />
                <span>Commit by <strong class="font-bold text-gray-950 dark:text-white">{{ $morningDue }}</strong></span>
            </span>
            <span class="fynn-chip">
                <x-filament::icon icon="heroicon-m-moon" />
                <span>Declare by <strong class="font-bold text-gray-950 dark:text-white">{{ $eveningDue }}</strong></span>
            </span>
        </div>
    @endif

    @if (! $row)
        <div class="fynn-empty">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-circle" /></span>
            <span>Your account is not linked to an employee profile yet.</span>
        </div>
    @else
        {{-- ── THE DAY, IN ONE CARD ─────────────────────────────── --}}
        @if ($commitment)
            <div
                class="fynn-card fynn-card--flush"
                style="--fynn-kpi-accent: {{ $accent }}; border-top: 3px solid {{ $accent }}"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-1"
                    style="background: radial-gradient(70% 160% at 0% 0%, color-mix(in oklab, {{ $accent }} 12%, transparent), transparent 70%)"
                >
                    <div class="flex items-center gap-3">
                        <span class="fynn-kpi-icon h-10 w-10 rounded-xl">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                        </span>
                        <div>
                            <div class="fynn-kpi-label">
                                {{ \Illuminate\Support\Carbon::parse($this->date)->format('l') }}
                            </div>
                            <div class="text-lg font-extrabold tracking-tight text-gray-950 dark:text-white">
                                {{ \Illuminate\Support\Carbon::parse($this->date)->format('d M Y') }}
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <x-daily-commitment.result-chip :result="$row['result']" />
                        <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ $submitted ? 'Day closed' : 'Still open' }}
                        </div>
                    </div>
                </div>

                {{-- Committed / achieved / still to go. The three numbers that
                     answer "where am I?", said here and nowhere else. --}}
                <div class="mt-4 grid grid-cols-1 divide-y divide-gray-950/5 border-y border-gray-950/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/5 dark:border-white/5">
                    <div class="px-5 py-4">
                        <div class="fynn-kpi-label">Committed</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-gray-950 dark:text-white">
                            <x-daily-commitment.amount :value="$row['target']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5"><x-daily-commitment.stage-chip :stage="$stage" /></div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="fynn-kpi-label">Achieved</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-green-600 dark:text-green-400">
                            <x-daily-commitment.amount :value="$row['achieved']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                            at {{ $stage->label() }} or above
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="fynn-kpi-label">Still to go</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums {{ $row['pending'] > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-gray-400 dark:text-gray-600' }}">
                            <x-daily-commitment.amount :value="$row['pending']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $row['percentage'] }}% of the promise
                        </div>
                    </div>
                </div>

                <div class="px-5 py-4">
                    <x-daily-commitment.progress-bar :percentage="$row['percentage']" :color="$accent" />

                    {{-- Business that landed below the promised stage is the
                         difference between a partial day and a failed one, so
                         it is said in words rather than left to a colour. --}}
                    @if (($split['below'] ?? 0) > 0)
                        <p class="fynn-note mt-3 bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-600/15 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-400/20">
                            <x-filament::icon icon="heroicon-m-information-circle" class="text-amber-600 dark:text-amber-400" />
                            <span>
                                A further <strong>{{ indianAmount($split['below']) }}</strong> came in below
                                {{ $stage->label() }} — it counts as <strong>partially met</strong>, not as a pass.
                            </span>
                        </p>
                    @endif

                    @if ($isCount)
                        <p class="fynn-note mt-3">
                            <x-filament::icon icon="heroicon-m-information-circle" />
                            <span>
                            OTP is the bottom rung, so every case you declare counts toward it.
                            {{ $row['actual_otp'] }} {{ \Illuminate\Support\Str::plural('case', $row['actual_otp']) }}
                            were opened in the LMS today, for reference — that figure does not settle the commitment.
                            </span>
                        </p>
                    @endif
                </div>

                {{-- Where the declared business actually sits. One ladder, top
                     rung first, dimmed below the committed stage. --}}
                @if ($entries->isNotEmpty())
                    <div class="flex flex-wrap gap-2 border-t border-gray-950/5 bg-gray-50/70 px-5 py-3 dark:border-white/5 dark:bg-white/5">
                        @foreach ($split['stages'] as $value => $totals)
                            @php $rung = \App\Enums\CommitmentStage::from($value); @endphp
                            <div class="fynn-chip bg-white dark:bg-white/5 {{ $totals['counts'] ? '' : 'opacity-50' }}">
                                <span class="dc-dot" style="background: {{ $rung->hex() }}"></span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $rung->label() }}</span>
                                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ indianAmount($totals['amount']) }}
                                </span>
                                <span class="text-gray-400 dark:text-gray-500">({{ $totals['count'] }})</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ── STEP 1 · THE PROMISE ─────────────────────────────── --}}
        @if ($this->isCommitmentLocked())
            {{-- Locked is the normal state for most of the day, and a dead
                 form is just noise — so it shrinks to a single line. --}}
            <div class="fynn-card fynn-card--muted flex flex-wrap items-center gap-3 text-sm">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gray-950/5 text-xs font-bold text-gray-600 ring-1 ring-inset ring-gray-950/10 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">1</span>
                <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4 shrink-0 text-gray-400" />
                <span class="text-gray-600 dark:text-gray-300">
                    Committed to
                    <strong class="font-semibold text-gray-950 dark:text-white">
                        @if ($isCount)
                            {{ number_format($commitment->commitment_count) }} {{ \Illuminate\Support\Str::plural('OTP', $commitment->commitment_count) }}
                        @else
                            {{ indianAmount($commitment->commitment_amount) }}
                        @endif
                    </strong>
                    at {{ $commitment->commitment_stage->label() }} — locked for the day. Only an Admin can correct it.
                </span>

                @if ($commitment->remarks)
                    <span class="w-full ps-10 text-xs text-gray-500 dark:text-gray-400">{{ $commitment->remarks }}</span>
                @endif
            </div>
        @else
            <form wire:submit="save">
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2.5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-500/15 text-xs font-bold text-primary-800 ring-1 ring-inset ring-primary-500/25 dark:text-primary-300">1</span>
                            Give today's commitment
                        </span>
                    </x-slot>

                    <x-slot name="description">
                        A stage and a number — no customer needed yet. Once given it is locked for the day.
                    </x-slot>

                    {{ $this->form }}

                    <div class="mt-5 flex justify-end border-t border-gray-950/5 pt-4 dark:border-white/5">
                        <x-filament::button type="submit" icon="heroicon-m-check">
                            {{ $commitment ? 'Update commitment' : 'Submit commitment' }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            </form>
        @endif

        {{-- ── STEP 2 · THE ANSWER ──────────────────────────────── --}}
        @if ($commitment)
            <x-filament::section>
                <x-slot name="heading">
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold ring-1 ring-inset {{ $submitted ? 'bg-success-500/15 text-success-800 ring-success-500/25 dark:text-success-300' : 'bg-primary-500/15 text-primary-800 ring-primary-500/25 dark:text-primary-300' }}">2</span>
                        Close the day
                    </span>
                </x-slot>

                <x-slot name="description">
                    Due by {{ $eveningDue }}. Part of the commitment is a fine answer, and so is none of it — it just has to be stated.
                </x-slot>

                @if ($submitted)
                    <div class="fynn-callout fynn-callout--success flex flex-wrap items-center gap-3">
                        <x-filament::icon icon="heroicon-m-check-badge" class="h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                        <span class="flex-1 font-semibold text-gray-950 dark:text-white">Submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}.</span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-m-pencil-square" wire:click="reopenFinalStatus">
                            Edit
                        </x-filament::button>
                    </div>

                    @if ($commitment->declaration_note)
                        <p class="fynn-note">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" />
                            <span>
                                <span class="font-semibold text-gray-700 dark:text-gray-200">Note:</span>
                                {{ $commitment->declaration_note }}
                            </span>
                        </p>
                    @endif
                @elseif ($this->declarationMode === null)
                    {{-- Meeting the commitment is not compulsory; declaring the
                         outcome is. So the first question is simply whether
                         there is anything to feed in — the customer list is
                         never put in front of somebody who had no customers.
                         Solid, pressable buttons on purpose: this is a choice,
                         not another panel of fields. --}}
                    <p class="fynn-subheading">How did today end?</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            wire:click="chooseDeclarationMode('cases')"
                            class="group flex cursor-pointer items-center gap-3 rounded-xl bg-success-600 px-5 py-4 text-start text-white shadow-sm transition hover:bg-success-500 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-success-600 active:scale-[0.99]"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-inset ring-white/20">
                                <x-filament::icon icon="heroicon-o-check-badge" class="h-6 w-6" />
                            </span>
                            <span class="flex-1">
                                <span class="block text-base font-bold">I have business to declare</span>
                                <span class="mt-0.5 block text-xs text-white/80">Name each case with its customer and mobile</span>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5 shrink-0 transition group-hover:translate-x-0.5" />
                        </button>

                        <button
                            type="button"
                            wire:click="chooseDeclarationMode('failed')"
                            class="group flex cursor-pointer items-center gap-3 rounded-xl bg-danger-600 px-5 py-4 text-start text-white shadow-sm transition hover:bg-danger-500 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger-600 active:scale-[0.99]"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-inset ring-white/20">
                                <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6" />
                            </span>
                            <span class="flex-1">
                                <span class="block text-base font-bold">Nothing came through</span>
                                <span class="mt-0.5 block text-xs text-white/80">Record a zero against every stage</span>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5 shrink-0 transition group-hover:translate-x-0.5" />
                        </button>
                    </div>
                @elseif ($this->declarationMode === 'cases')
                    <form wire:submit="submitFinalStatus">
                        {{ $this->fulfilmentForm }}

                        <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-gray-950/5 pt-4 dark:border-white/5">
                            <x-filament::button type="submit" icon="heroicon-m-check-badge" color="success">
                                Submit final status
                            </x-filament::button>

                            <x-filament::button type="button" color="gray" icon="heroicon-m-bookmark" wire:click="saveFulfilment">
                                Save for now
                            </x-filament::button>

                            <button
                                type="button"
                                wire:click="chooseDeclarationMode(null)"
                                class="ms-auto text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white"
                            >
                                Nothing came through after all
                            </button>
                        </div>
                    </form>
                @else
                    {{-- The zeros ARE the declaration: a figure against every
                         rung, rather than one vague "nothing". --}}
                    <div class="fynn-callout fynn-callout--danger">
                        <p class="fynn-callout-title">Nothing at any stage today</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Confirm the zeros below. This closes the day as <strong>failed</strong> and unlocks the rest of
                            the LMS. Anything that did come through belongs on a named case instead.
                        </p>

                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            @foreach ($ladder as $rung)
                                <label class="block">
                                    <span class="flex items-center gap-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        <span class="dc-dot" style="background: {{ $rung->hex() }}"></span>
                                        {{ $rung->label() }}
                                    </span>
                                    <input
                                        type="number"
                                        min="0"
                                        wire:model="nilStages.{{ $rung->value }}"
                                        class="mt-1 tabular-nums {{ $field }}"
                                    />
                                </label>
                            @endforeach
                        </div>

                        <textarea
                            wire:model="nothingReason"
                            rows="2"
                            placeholder="Anything worth noting? (optional)"
                            class="mt-4 {{ $field }}"
                        ></textarea>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <x-filament::button size="sm" color="danger" icon="heroicon-m-flag" wire:click="declareNothing">
                                Record commitment as failed
                            </x-filament::button>

                            <button
                                type="button"
                                wire:click="chooseDeclarationMode('cases')"
                                class="text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white"
                            >
                                Actually, I do have something to declare
                            </button>
                        </div>
                    </div>
                @endif

                {{-- What has been claimed so far. Reference and remarks move
                     under the name; the table is for scanning, not for holding
                     every field the row owns. --}}
                @if ($entries->isNotEmpty())
                    <div class="fynn-table-wrap mt-6">
                        <table class="dc-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Mobile</th>
                                    <th>Stage reached</th>
                                    <th class="dc-num">Amount</th>
                                    <th></th>
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
                                            @if ($entry->reference || $entry->remarks)
                                                <div class="text-xs text-gray-500">
                                                    {{ collect([$entry->reference, $entry->remarks])->filter()->join(' · ') }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300">
                                            {{ $entry->mobile_no ?? '—' }}
                                        </td>
                                        <td>
                                            <x-daily-commitment.stage-chip :stage="$effective" />
                                            @if ($entry->outcome)
                                                <x-daily-commitment.stage-chip :stage="$entry->outcome" />
                                            @endif
                                        </td>
                                        <td class="dc-num font-semibold">
                                            <x-daily-commitment.amount :value="$entry->amount" />
                                        </td>
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
                @endif
            </x-filament::section>

            {{-- ── REFERENCE ────────────────────────────────────────
                 None of this is today's job, so all of it starts shut. --}}
            <x-filament::section
                icon="heroicon-o-queue-list"
                heading="Current pipeline"
                :description="indianAmount($row['pipeline']['total_amount']) . ' across ' . $row['pipeline']['total_count'] . ' open ' . \Illuminate\Support\Str::plural('case', $row['pipeline']['total_count'])"
                collapsible
                collapsed
            >
                <p class="fynn-note mt-0 mb-4">
                    <x-filament::icon icon="heroicon-m-information-circle" />
                    <span>
                    Everything you are carrying right now, at whatever stage it is sitting. Separate from today's
                    achievement, and excludes disbursal, dropped and rejected.
                    </span>
                </p>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($ladder as $rung)
                        @php $totals = $row['pipeline']['stages'][$rung->value] ?? ['amount' => 0, 'count' => 0]; @endphp
                        <div class="dc-card" style="--dc-accent: {{ $rung->hex() }}">
                            <x-daily-commitment.stage-chip :stage="$rung" />
                            <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                            <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            @if (($monthly['stage'] ?? null))
                @php $mFmt = fn ($v) => $monthly['is_count'] ? number_format($v) : indianAmount($v); @endphp
                <x-filament::section
                    icon="heroicon-o-calendar-days"
                    heading="Month to date"
                    :description="$mFmt($monthly['achieved']) . ' of ' . $mFmt($monthly['target']) . ' — ' . $monthly['percentage'] . '%'"
                    collapsible
                    collapsed
                >
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <x-daily-commitment.kpi
                            label="Monthly target"
                            :amount="$monthly['target']"
                            :count="$monthly['is_count']"
                            :hint="$monthly['stage']->label()"
                            :accent="$monthly['stage']->hex()"
                            icon="heroicon-o-flag"
                        />
                        <x-daily-commitment.kpi label="MTD achievement" :amount="$monthly['achieved']" :count="$monthly['is_count']" accent="#22c55e" icon="heroicon-o-banknotes" />
                        <x-daily-commitment.kpi label="Pending" :amount="$monthly['pending']" :count="$monthly['is_count']" accent="#f97316" icon="heroicon-o-clock" />
                        <x-daily-commitment.kpi label="Achievement %" :value="$monthly['percentage'] . '%'" accent="#3b82f6" icon="heroicon-o-chart-pie" />
                        <x-daily-commitment.kpi
                            label="DRR"
                            :amount="$monthly['drr']"
                            :count="$monthly['is_count']"
                            hint="{{ $mFmt($monthly['achieved']) }} ÷ {{ $monthly['elapsed_working_days'] }} working days"
                            accent="#a855f7"
                            icon="heroicon-o-arrow-trending-up"
                        />
                    </div>
                    <p class="fynn-note">
                        <x-filament::icon icon="heroicon-m-information-circle" />
                        <span>
                        Needed per remaining working day: <strong class="text-gray-950 dark:text-white">{{ $mFmt($monthly['required_drr']) }}</strong>
                        ({{ $mFmt($monthly['pending']) }} ÷ {{ $monthly['remaining_working_days'] }} days left of
                        {{ $monthly['total_working_days'] }}). MTD is the sum of each day's submitted fulfilment.
                        </span>
                    </p>
                </x-filament::section>
            @endif

            {{-- Day by day: promise against fulfilment, so how often the
                 commitment is actually kept is visible rather than inferred. --}}
            @php $history = $this->history; @endphp
            <x-filament::section
                icon="heroicon-o-clock"
                heading="Commitment history"
                :description="$history['tally']['kept'] . ' of ' . $history['tally']['closed'] . ' closed days kept — ' . $history['tally']['kept_percentage'] . '%'"
                collapsible
                collapsed
            >
                <x-daily-commitment.history
                    :rows="$history['rows']"
                    :filtered="$history['filtered']"
                    :tally="$history['tally']"
                    :range="$this->historyRange"
                    :result="$this->historyResult"
                />
            </x-filament::section>

        @endif
    @endif
</x-filament-panels::page>
