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
    @endphp

    {{-- WHAT IS DUE, AND WHEN --}}
    @if ($gate['blocked'])
        <div class="flex items-start gap-3 rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-200 dark:ring-danger-400/30">
            <x-filament::icon icon="heroicon-o-lock-closed" class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <p class="font-semibold">The rest of the LMS is locked.</p>
                <p class="mt-0.5">{{ $this->gateMessage }}</p>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-m-sun" class="h-4 w-4 shrink-0" />
                Commit by <strong class="font-semibold text-gray-700 dark:text-gray-200">{{ $morningDue }}</strong>
            </span>
            <span class="flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-m-moon" class="h-4 w-4 shrink-0" />
                Declare by <strong class="font-semibold text-gray-700 dark:text-gray-200">{{ $eveningDue }}</strong>
            </span>
        </div>
    @endif

    @if (! $row)
        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            Your account is not linked to an employee profile yet.
        </div>
    @else
        {{-- ── THE DAY, IN ONE CARD ─────────────────────────────── --}}
        @if ($commitment)
            <div
                class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                style="border-top: 3px solid {{ $accent }}"
            >
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            {{ \Illuminate\Support\Carbon::parse($this->date)->format('l') }}
                        </div>
                        <div class="text-lg font-bold text-gray-950 dark:text-white">
                            {{ \Illuminate\Support\Carbon::parse($this->date)->format('d M Y') }}
                        </div>
                    </div>

                    <div class="text-end">
                        <x-daily-commitment.result-chip :result="$row['result']" />
                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            {{ $submitted ? 'Day closed' : 'Still open' }}
                        </div>
                    </div>
                </div>

                {{-- Committed / achieved / still to go. The three numbers that
                     answer "where am I?", said here and nowhere else. --}}
                <div class="mt-4 grid grid-cols-3 divide-x divide-gray-100 border-y border-gray-100 dark:divide-white/5 dark:border-white/5">
                    <div class="px-5 py-4">
                        <div class="text-xs font-medium text-gray-400 dark:text-gray-500">Committed</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-gray-950 dark:text-white">
                            <x-daily-commitment.amount :value="$row['target']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5"><x-daily-commitment.stage-chip :stage="$stage" /></div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="text-xs font-medium text-gray-400 dark:text-gray-500">Achieved</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-green-600 dark:text-green-400">
                            <x-daily-commitment.amount :value="$row['achieved']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                            at {{ $stage->label() }} or above
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="text-xs font-medium text-gray-400 dark:text-gray-500">Still to go</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums {{ $row['pending'] > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-gray-400 dark:text-gray-600' }}">
                            <x-daily-commitment.amount :value="$row['pending']" :count="$isCount" />
                        </div>
                        <div class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                            {{ $row['percentage'] }}% of the promise
                        </div>
                    </div>
                </div>

                <div class="px-5 py-4">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div
                            class="h-full rounded-full transition-all"
                            style="width: {{ min($row['percentage'], 100) }}%; background: {{ $accent }}"
                        ></div>
                    </div>

                    {{-- Business that landed below the promised stage is the
                         difference between a partial day and a failed one, so
                         it is said in words rather than left to a colour. --}}
                    @if (($split['below'] ?? 0) > 0)
                        <p class="mt-3 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-300">
                            <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0" />
                            <span>
                                A further <strong>{{ indianAmount($split['below']) }}</strong> came in below
                                {{ $stage->label() }} — it counts as <strong>partially met</strong>, not as a pass.
                            </span>
                        </p>
                    @endif

                    @if ($isCount)
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            OTP is the bottom rung, so every case you declare counts toward it.
                            {{ $row['actual_otp'] }} {{ \Illuminate\Support\Str::plural('case', $row['actual_otp']) }}
                            were opened in the LMS today, for reference — that figure does not settle the commitment.
                        </p>
                    @endif
                </div>

                {{-- Where the declared business actually sits. One ladder, top
                     rung first, dimmed below the committed stage. --}}
                @if ($entries->isNotEmpty())
                    <div class="flex flex-wrap gap-x-5 gap-y-2 border-t border-gray-100 bg-gray-50/70 px-5 py-3 dark:border-white/5 dark:bg-white/5">
                        @foreach ($split['stages'] as $value => $totals)
                            @php $rung = \App\Enums\CommitmentStage::from($value); @endphp
                            <div class="flex items-center gap-2 text-xs {{ $totals['counts'] ? '' : 'opacity-50' }}">
                                <span class="inline-block h-2 w-2 rounded-full" style="background: {{ $rung->hex() }}"></span>
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
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 px-5 py-3.5 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">1</span>
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
                    <span class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $commitment->remarks }}</span>
                @endif
            </div>
        @else
            <form wire:submit="save">
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2.5">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">1</span>
                            Give today's commitment
                        </span>
                    </x-slot>

                    <x-slot name="description">
                        A stage and a number — no customer needed yet. Once given it is locked for the day.
                    </x-slot>

                    {{ $this->form }}

                    <div class="mt-5">
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
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $submitted ? 'bg-success-600' : 'bg-primary-600' }} text-xs font-bold text-white">2</span>
                        Close the day
                    </span>
                </x-slot>

                <x-slot name="description">
                    Due by {{ $eveningDue }}. Part of the commitment is a fine answer, and so is none of it — it just has to be stated.
                </x-slot>

                @if ($submitted)
                    <div class="flex flex-wrap items-center gap-3 rounded-xl bg-success-50 p-4 text-sm text-success-800 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-400/30">
                        <x-filament::icon icon="heroicon-m-check-badge" class="h-5 w-5 shrink-0" />
                        <span class="flex-1">Submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}.</span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-m-pencil-square" wire:click="reopenFinalStatus">
                            Edit
                        </x-filament::button>
                    </div>

                    @if ($commitment->declaration_note)
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            <span class="font-semibold text-gray-700 dark:text-gray-200">Note:</span>
                            {{ $commitment->declaration_note }}
                        </p>
                    @endif
                @elseif ($this->declarationMode === null)
                    {{-- Meeting the commitment is not compulsory; declaring the
                         outcome is. So the first question is simply whether
                         there is anything to feed in — the customer list is
                         never put in front of somebody who had no customers.
                         Solid, pressable buttons on purpose: this is a choice,
                         not another panel of fields. --}}
                    <p class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">How did today end?</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            wire:click="chooseDeclarationMode('cases')"
                            class="group flex cursor-pointer items-center gap-3 rounded-xl bg-success-600 px-5 py-4 text-start text-white shadow-sm transition hover:bg-success-500 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-success-600 active:scale-[0.99]"
                        >
                            <x-filament::icon icon="heroicon-o-check-badge" class="h-7 w-7 shrink-0" />
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
                            <x-filament::icon icon="heroicon-o-x-circle" class="h-7 w-7 shrink-0" />
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

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <x-filament::button type="submit" icon="heroicon-m-check-badge" color="success">
                                Submit final status
                            </x-filament::button>

                            <x-filament::button type="button" color="gray" icon="heroicon-m-bookmark" wire:click="saveFulfilment">
                                Save for now
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
                    {{-- The zeros ARE the declaration: a figure against every
                         rung, rather than one vague "nothing". --}}
                    <div class="rounded-xl bg-gray-50 p-5 dark:bg-white/5">
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Nothing at any stage today</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Confirm the zeros below. This closes the day as <strong>failed</strong> and unlocks the rest of
                            the LMS. Anything that did come through belongs on a named case instead.
                        </p>

                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            @foreach ($ladder as $rung)
                                <label class="block">
                                    <span class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        <span class="inline-block h-2 w-2 rounded-full" style="background: {{ $rung->hex() }}"></span>
                                        {{ $rung->label() }}
                                    </span>
                                    <input
                                        type="number"
                                        min="0"
                                        wire:model="nilStages.{{ $rung->value }}"
                                        class="mt-1 block w-full rounded-lg border-none bg-white py-2 text-sm tabular-nums text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    />
                                </label>
                            @endforeach
                        </div>

                        <textarea
                            wire:model="nothingReason"
                            rows="2"
                            placeholder="Anything worth noting? (optional)"
                            class="mt-4 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                        ></textarea>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <x-filament::button size="sm" color="danger" icon="heroicon-m-flag" wire:click="declareNothing">
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

                {{-- What has been claimed so far. Reference and remarks move
                     under the name; the table is for scanning, not for holding
                     every field the row owns. --}}
                @if ($entries->isNotEmpty())
                    <div class="mt-6 overflow-x-auto">
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
                                        <td class="dc-num font-semibold tabular-nums">
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
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Everything you are carrying right now, at whatever stage it is sitting. Separate from today's
                    achievement, and excludes disbursal, dropped and rejected.
                </p>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($ladder as $rung)
                        @php $totals = $row['pipeline']['stages'][$rung->value] ?? ['amount' => 0, 'count' => 0]; @endphp
                        <div class="dc-card" style="border-color: {{ $rung->hex() }}55; box-shadow: inset 3px 0 0 0 {{ $rung->hex() }}">
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
                        ({{ $mFmt($monthly['pending']) }} ÷ {{ $monthly['remaining_working_days'] }} days left of
                        {{ $monthly['total_working_days'] }}). MTD is the sum of each day's submitted fulfilment.
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
