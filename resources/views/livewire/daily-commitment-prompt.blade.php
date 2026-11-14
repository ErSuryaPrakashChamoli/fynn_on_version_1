{{--
    The compulsory daily prompt. Deliberately not a Filament modal: it
    cannot be dismissed, closed on escape or clicked past until the promise
    is given, or answered.

    Both answers are taken HERE — the morning stage-and-number, and the
    evening cases or zeros — so the employee is back on whatever page they
    were working on within seconds. Nothing else in the LMS is closed or
    redirected; this prompt is the whole of the enforcement.

    Every block that can be swapped for another one carries a wire:key.
    Livewire morphs the DOM in place and only re-initialises Alpine on
    elements it has just ADDED, so an <input> reused across a swap keeps
    the wire:model binding it was first initialised with — typing into the
    OTP box would still be writing to `amount`, and the button in the
    footer would still be calling the previous branch's method. The keys
    are what force a replacement instead of a reuse; do not remove them.
--}}
@php
    $blocked = $status['blocked'];
    $reason = $status['reason'];
    $commitment = $status['commitment'];
    $isCount = (bool) $commitment?->commitment_stage->isCount();

    // Presentation only: one look for every raw control in this dialog,
    // matching Filament's own inputs, and the dialog's header / footer bands.
    $field = 'rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500';
    $fieldLabel = 'text-xs font-semibold text-gray-600 dark:text-gray-300';
    $headerBand = 'border-b border-gray-950/5 px-6 py-5 dark:border-white/10';
    $footerBand = 'flex flex-wrap items-center gap-3 border-t border-gray-950/5 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5';
@endphp

<div>
    @if ($blocked)
        <div
            class="fixed inset-0 z-[9999] flex items-center-safe justify-center overflow-y-auto bg-gray-100 p-4 dark:bg-gray-950"
            style="background-image: radial-gradient(70% 55% at 50% 0%, color-mix(in oklab, var(--primary-500) 14%, transparent), transparent 70%)"
            role="dialog"
            aria-modal="true"
            aria-labelledby="daily-commitment-prompt-heading"
        >
            <div class="fynn-card fynn-card--flush w-full max-w-3xl rounded-2xl shadow-2xl">

                {{-- 09:50 — the promise itself, given here and now. --}}
                @if ($reason === \App\Services\DailyCommitmentGate::REASON_COMMIT)
                    <div wire:key="prompt-commit" class="{{ $headerBand }}" style="background: linear-gradient(180deg, var(--fynn-surface-2), var(--fynn-surface))">
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-500/15 text-primary-800 ring-1 ring-inset ring-primary-500/25 dark:text-primary-300">
                                <x-filament::icon
                                    icon="heroicon-o-sun"
                                    class="h-6 w-6"
                                />
                            </span>
                            <div class="min-w-0">
                                <h2 id="daily-commitment-prompt-heading" class="text-lg font-extrabold tracking-tight text-gray-950 dark:text-white">
                                    Today's commitment
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $message }} Just a stage and a number — no customer needed yet.
                                    Once given it is locked for the day.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div wire:key="prompt-commit-fields" class="grid gap-4 px-6 py-6 sm:grid-cols-2">
                        <label wire:key="commit-stage" class="block">
                            <span class="{{ $fieldLabel }}">I commit to</span>
                            <select
                                wire:model.live="stage"
                                class="mt-1.5 block w-full {{ $field }}"
                            >
                                @foreach ($stageOptions as $value => $label)
                                    {{-- @selected, not left to Alpine: until it boots the
                                         browser shows option[0], which is a different stage
                                         from the one the server is holding. --}}
                                    <option value="{{ $value }}" @selected($stage === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('stage')
                                <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                            @enderror
                        </label>

                        @if ($stage === \App\Enums\CommitmentStage::Otp->value)
                            <label wire:key="commit-count" class="block">
                                <span class="{{ $fieldLabel }}">Number of OTPs</span>
                                <input
                                    type="number"
                                    min="1"
                                    wire:model.live.debounce.400ms="count"
                                    class="mt-1.5 block w-full tabular-nums {{ $field }}"
                                />
                                @error('count')
                                    <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                                @enderror
                            </label>
                        @else
                            <label wire:key="commit-amount" class="block">
                                <span class="{{ $fieldLabel }}">Amount (₹)</span>
                                <input
                                    type="number"
                                    min="1"
                                    wire:model.live.debounce.400ms="amount"
                                    placeholder="e.g. 1000000 for ₹10,00,000"
                                    class="mt-1.5 block w-full tabular-nums {{ $field }}"
                                />
                                {{-- Read straight back in both forms: the number is
                                     locked the moment it is given, so a stray zero
                                     has to be caught before it is saved. --}}
                                @if (filled($amount))
                                    <span class="mt-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                        ₹{{ indianNumberFormat($amount) }} · {{ indianAmountInWordsWithPaise($amount) }}
                                    </span>
                                @endif
                                @error('amount')
                                    <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif
                    </div>

                    <div wire:key="prompt-commit-footer" class="{{ $footerBand }} justify-between">
                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4 shrink-0" />
                            Due by {{ \App\Services\DailyCommitmentGate::MORNING_DEADLINE }} every day.
                        </span>
                        <x-filament::button wire:click="giveCommitment" icon="heroicon-o-check" size="lg">
                            Give commitment
                        </x-filament::button>
                    </div>
                @endif

                {{-- 18:30 — the promise has to be answered, here and now. --}}
                @if ($reason === \App\Services\DailyCommitmentGate::REASON_DECLARE)
                    <div wire:key="prompt-declare" class="{{ $headerBand }}" style="background: linear-gradient(180deg, var(--fynn-surface-2), var(--fynn-surface))">
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-warning-500/15 text-warning-700 ring-1 ring-inset ring-warning-500/25 dark:text-warning-300">
                                <x-filament::icon
                                    icon="heroicon-o-clipboard-document-check"
                                    class="h-6 w-6"
                                />
                            </span>
                            <div class="min-w-0">
                                <h2 id="daily-commitment-prompt-heading" class="text-lg font-extrabold tracking-tight text-gray-950 dark:text-white">
                                    Close today's commitment
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $message }} Answer it here and carry straight on with what you were doing.
                                </p>
                            </div>
                        </div>
                    </div>

                    @if ($commitment)
                        <div wire:key="prompt-declare-summary" class="px-6 pt-5">
                            <dl class="fynn-dl sm:grid-cols-2">
                            <div class="fynn-dl-item">
                                <dt>You committed to</dt>
                                <dd class="mt-1.5"><x-daily-commitment.stage-chip :stage="$commitment->commitment_stage" /></dd>
                            </div>
                            <div class="fynn-dl-item">
                                <dt>
                                    {{ $status['date']?->format('d M Y') }}
                                </dt>
                                <dd class="mt-0.5 text-lg font-extrabold tracking-tight tabular-nums">
                                    @if ($isCount)
                                        {{ number_format($commitment->commitment_count) }}
                                        {{ \Illuminate\Support\Str::plural('OTP', $commitment->commitment_count) }}
                                    @else
                                        {{ indianAmount($commitment->commitment_amount) }}
                                    @endif
                                </dd>
                            </div>
                            </dl>
                        </div>
                    @endif

                    {{-- Which of the two ways is this day being closed? --}}
                    @if ($mode === null)
                        <div wire:key="declare-choose" class="grid gap-3 px-6 py-5 sm:grid-cols-2">
                            <button
                                type="button"
                                wire:click="chooseMode('cases')"
                                class="group flex cursor-pointer items-center gap-3 rounded-xl bg-success-600 px-4 py-3.5 text-start text-white shadow-sm transition hover:bg-success-500 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-success-600 active:scale-[0.99]"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-inset ring-white/20">
                                    <x-filament::icon icon="heroicon-o-check-badge" class="h-6 w-6" />
                                </span>
                                <span class="flex-1">
                                    <span class="block text-sm font-bold">I have business to declare</span>
                                    <span class="mt-0.5 block text-xs text-white/80">Name each case and its mobile</span>
                                </span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5 shrink-0 transition group-hover:translate-x-0.5" />
                            </button>

                            <button
                                type="button"
                                wire:click="chooseMode('failed')"
                                class="group flex cursor-pointer items-center gap-3 rounded-xl bg-danger-600 px-4 py-3.5 text-start text-white shadow-sm transition hover:bg-danger-500 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger-600 active:scale-[0.99]"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-inset ring-white/20">
                                    <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6" />
                                </span>
                                <span class="flex-1">
                                    <span class="block text-sm font-bold">Nothing came through</span>
                                    <span class="mt-0.5 block text-xs text-white/80">Zero against every stage</span>
                                </span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5 shrink-0 transition group-hover:translate-x-0.5" />
                            </button>
                        </div>
                    @elseif ($mode === 'cases')
                        <div wire:key="declare-cases" class="max-h-[45vh] space-y-3 overflow-y-auto px-6 py-5">
                            @foreach ($cases as $index => $case)
                                {{-- Keyed on the row: removeCase() re-indexes the array,
                                     so without this the surviving rows keep the bindings
                                     of the rows above them. --}}
                                <div wire:key="case-{{ $index }}" class="fynn-card fynn-card--muted grid gap-2 p-3 sm:grid-cols-12">
                                    <input
                                        type="text"
                                        wire:model="cases.{{ $index }}.customer_name"
                                        placeholder="Customer name"
                                        class="sm:col-span-4 {{ $field }}"
                                    />
                                    <input
                                        type="tel"
                                        wire:model="cases.{{ $index }}.mobile_no"
                                        placeholder="Mobile"
                                        class="tabular-nums sm:col-span-3 {{ $field }}"
                                    />
                                    <select
                                        wire:model="cases.{{ $index }}.stage"
                                        class="sm:col-span-2 {{ $field }}"
                                    >
                                        <option value="" @selected(blank($case['stage'] ?? null))>Stage…</option>
                                        @foreach ($ladderOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(($case['stage'] ?? null) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div wire:key="case-amount-{{ $index }}" class="sm:col-span-2">
                                        <input
                                            type="number"
                                            min="0"
                                            wire:model.live.debounce.400ms="cases.{{ $index }}.amount"
                                            placeholder="Amount"
                                            class="block w-full tabular-nums {{ $field }}"
                                        />
                                        {{-- Same read-back as the commitment amount above. --}}
                                        @if (filled($case['amount'] ?? null))
                                            <span class="mt-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                                ₹{{ indianNumberFormat($case['amount']) }} · {{ indianAmountInWordsWithPaise($case['amount']) }}
                                            </span>
                                        @endif
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="removeCase({{ $index }})"
                                        class="flex items-center justify-center rounded-lg py-2 text-gray-400 transition hover:bg-danger-500/10 hover:text-danger-600 sm:col-span-1 dark:hover:text-danger-400"
                                        title="Remove"
                                    >
                                        <x-filament::icon icon="heroicon-m-trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            @endforeach

                            <button
                                type="button"
                                wire:click="addCase"
                                class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-300 py-2.5 text-xs font-semibold text-gray-600 transition hover:border-primary-500 hover:bg-primary-500/5 hover:text-primary-800 dark:border-white/15 dark:text-gray-300 dark:hover:text-primary-300"
                            >
                                <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                                Add another case
                            </button>
                        </div>

                        <div wire:key="declare-cases-footer" class="{{ $footerBand }}">
                            <x-filament::button wire:click="submitCases" icon="heroicon-m-check-badge" color="success" size="lg">
                                Submit and carry on
                            </x-filament::button>

                            <button type="button" wire:click="chooseMode(null)" class="text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white">
                                Back
                            </button>

                            <button type="button" wire:click="goToDeclaration" class="ms-auto text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white">
                                Open the full screen instead
                            </button>
                        </div>
                    @else
                        <div wire:key="declare-failed" class="px-6 py-5">
                            <p class="fynn-callout fynn-callout--danger text-xs">
                                Confirm the zeros. This closes the day as <strong>failed</strong>, which is a legitimate
                                answer — anything that did come through belongs on a named case instead.
                            </p>

                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                                @foreach (\App\Enums\CommitmentStage::ladder() as $rung)
                                    <label wire:key="nil-{{ $rung->value }}" class="block">
                                        <span class="flex items-center gap-1.5 {{ $fieldLabel }}">
                                            <span class="dc-dot" style="background: {{ $rung->hex() }}"></span>
                                            {{ $rung->label() }}
                                        </span>
                                        <input
                                            type="number"
                                            min="0"
                                            wire:model="nilStages.{{ $rung->value }}"
                                            class="mt-1.5 block w-full tabular-nums {{ $field }}"
                                        />
                                    </label>
                                @endforeach
                            </div>

                            <textarea
                                wire:model="note"
                                rows="2"
                                placeholder="Anything worth noting? (optional)"
                                class="mt-4 block w-full {{ $field }}"
                            ></textarea>
                        </div>

                        <div wire:key="declare-failed-footer" class="{{ $footerBand }}">
                            <x-filament::button wire:click="declareFailed" icon="heroicon-m-flag" color="danger" size="lg">
                                Record as failed
                            </x-filament::button>

                            <button type="button" wire:click="chooseMode(null)" class="text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white">
                                Back
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
