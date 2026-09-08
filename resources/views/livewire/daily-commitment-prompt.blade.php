{{--
    The compulsory daily prompt. Deliberately not a Filament modal: it
    cannot be dismissed, closed on escape or clicked past until the promise
    is given, or answered.

    Both answers are taken HERE — the morning stage-and-number, and the
    evening cases or zeros — so the employee is back on whatever page they
    were working on within seconds. Nothing else in the LMS is closed or
    redirected; this prompt is the whole of the enforcement.
--}}
@php
    $blocked = $status['blocked'];
    $reason = $status['reason'];
    $commitment = $status['commitment'];
    $isCount = (bool) $commitment?->commitment_stage->isCount();
@endphp

<div>
    @if ($blocked)
        <div
            class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-gray-100 p-4 dark:bg-gray-950"
            role="dialog"
            aria-modal="true"
            aria-labelledby="daily-commitment-prompt-heading"
        >
            <div class="w-full max-w-3xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

                {{-- 09:50 — the promise itself, given here and now. --}}
                @if ($reason === \App\Services\DailyCommitmentGate::REASON_COMMIT)
                    <div class="border-b border-gray-200 px-6 py-5 dark:border-white/10">
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                icon="heroicon-o-sun"
                                class="mt-0.5 h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                            />
                            <div>
                                <h2 id="daily-commitment-prompt-heading" class="text-lg font-semibold text-gray-950 dark:text-white">
                                    Today's commitment
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $message }} Just a stage and a number — no customer needed yet.
                                    Once given it is locked for the day.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 px-6 py-5">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">I commit to</span>
                            <select
                                wire:model.live="stage"
                                class="mt-1 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            >
                                @foreach ($stageOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('stage')
                                <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                            @enderror
                        </label>

                        @if ($stage === \App\Enums\CommitmentStage::Otp->value)
                            <label class="block">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Number of OTPs</span>
                                <input
                                    type="number"
                                    min="1"
                                    wire:model.live.debounce.400ms="count"
                                    class="mt-1 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                />
                                @error('count')
                                    <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                                @enderror
                            </label>
                        @else
                            <label class="block">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Amount (₹)</span>
                                <input
                                    type="number"
                                    min="1"
                                    wire:model.live.debounce.400ms="amount"
                                    placeholder="e.g. 1000000 for ₹10,00,000"
                                    class="mt-1 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                />
                                {{-- Read straight back in both forms: the number is
                                     locked the moment it is given, so a stray zero
                                     has to be caught before it is saved. --}}
                                @if (filled($amount))
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                        {{ indianAmount($amount) }} — {{ indianAmountInWords($amount) }}
                                    </span>
                                @endif
                                @error('amount')
                                    <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-6 py-4 dark:border-white/10">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Due by {{ \App\Services\DailyCommitmentGate::MORNING_DEADLINE }} every day.
                        </span>
                        <x-filament::button wire:click="commit" icon="heroicon-o-check">
                            Give commitment
                        </x-filament::button>
                    </div>
                @endif

                {{-- 18:30 — the promise has to be answered, here and now. --}}
                @if ($reason === \App\Services\DailyCommitmentGate::REASON_DECLARE)
                    <div class="border-b border-gray-200 px-6 py-5 dark:border-white/10">
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                icon="heroicon-o-clipboard-document-check"
                                class="mt-0.5 h-6 w-6 shrink-0 text-warning-600 dark:text-warning-400"
                            />
                            <div>
                                <h2 id="daily-commitment-prompt-heading" class="text-lg font-semibold text-gray-950 dark:text-white">
                                    Close today's commitment
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $message }} Answer it here and carry straight on with what you were doing.
                                </p>
                            </div>
                        </div>
                    </div>

                    @if ($commitment)
                        <div class="flex flex-wrap items-center gap-x-8 gap-y-3 border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                            <div>
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">You committed to</div>
                                <div class="mt-1"><x-daily-commitment.stage-chip :stage="$commitment->commitment_stage" /></div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                    {{ $status['date']?->format('d M Y') }}
                                </div>
                                <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">
                                    @if ($isCount)
                                        {{ number_format($commitment->commitment_count) }}
                                        {{ \Illuminate\Support\Str::plural('OTP', $commitment->commitment_count) }}
                                    @else
                                        {{ indianAmount($commitment->commitment_amount) }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Which of the two ways is this day being closed? --}}
                    @if ($mode === null)
                        <div class="grid gap-3 px-6 py-5 sm:grid-cols-2">
                            <button
                                type="button"
                                wire:click="chooseMode('cases')"
                                class="group flex cursor-pointer items-center gap-3 rounded-xl bg-success-600 px-4 py-3.5 text-start text-white shadow-sm transition hover:bg-success-500 active:scale-[0.99]"
                            >
                                <x-filament::icon icon="heroicon-o-check-badge" class="h-6 w-6 shrink-0" />
                                <span class="flex-1">
                                    <span class="block text-sm font-bold">I have business to declare</span>
                                    <span class="mt-0.5 block text-xs text-white/80">Name each case and its mobile</span>
                                </span>
                            </button>

                            <button
                                type="button"
                                wire:click="chooseMode('failed')"
                                class="group flex cursor-pointer items-center gap-3 rounded-xl bg-danger-600 px-4 py-3.5 text-start text-white shadow-sm transition hover:bg-danger-500 active:scale-[0.99]"
                            >
                                <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6 shrink-0" />
                                <span class="flex-1">
                                    <span class="block text-sm font-bold">Nothing came through</span>
                                    <span class="mt-0.5 block text-xs text-white/80">Zero against every stage</span>
                                </span>
                            </button>
                        </div>
                    @elseif ($mode === 'cases')
                        <div class="max-h-[45vh] space-y-3 overflow-y-auto px-6 py-5">
                            @foreach ($cases as $index => $case)
                                <div class="grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-12 dark:bg-white/5">
                                    <input
                                        type="text"
                                        wire:model="cases.{{ $index }}.customer_name"
                                        placeholder="Customer name"
                                        class="rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 sm:col-span-4 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    />
                                    <input
                                        type="tel"
                                        wire:model="cases.{{ $index }}.mobile_no"
                                        placeholder="Mobile"
                                        class="rounded-lg border-none bg-white py-2 text-sm tabular-nums text-gray-950 shadow-sm ring-1 ring-gray-950/10 sm:col-span-3 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    />
                                    <select
                                        wire:model="cases.{{ $index }}.stage"
                                        class="rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 sm:col-span-2 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    >
                                        <option value="">Stage…</option>
                                        @foreach ($ladderOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <input
                                        type="number"
                                        min="0"
                                        wire:model="cases.{{ $index }}.amount"
                                        placeholder="Amount"
                                        class="rounded-lg border-none bg-white py-2 text-sm tabular-nums text-gray-950 shadow-sm ring-1 ring-gray-950/10 sm:col-span-2 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    />
                                    <button
                                        type="button"
                                        wire:click="removeCase({{ $index }})"
                                        class="flex items-center justify-center rounded-lg text-gray-400 transition hover:text-danger-600 sm:col-span-1"
                                        title="Remove"
                                    >
                                        <x-filament::icon icon="heroicon-m-trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            @endforeach

                            <button
                                type="button"
                                wire:click="addCase"
                                class="flex items-center gap-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                                Add another case
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 px-6 py-4 dark:border-white/10">
                            <x-filament::button wire:click="submitCases" icon="heroicon-m-check-badge" color="success">
                                Submit and carry on
                            </x-filament::button>

                            <button type="button" wire:click="chooseMode(null)" class="text-xs text-gray-500 hover:underline dark:text-gray-400">
                                Back
                            </button>

                            <button type="button" wire:click="goToDeclaration" class="ms-auto text-xs text-gray-500 hover:underline dark:text-gray-400">
                                Open the full screen instead
                            </button>
                        </div>
                    @else
                        <div class="px-6 py-5">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Confirm the zeros. This closes the day as <strong>failed</strong>, which is a legitimate
                                answer — anything that did come through belongs on a named case instead.
                            </p>

                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                                @foreach (\App\Enums\CommitmentStage::ladder() as $rung)
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
                                wire:model="note"
                                rows="2"
                                placeholder="Anything worth noting? (optional)"
                                class="mt-4 block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            ></textarea>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 px-6 py-4 dark:border-white/10">
                            <x-filament::button wire:click="declareFailed" icon="heroicon-m-flag" color="danger">
                                Record as failed
                            </x-filament::button>

                            <button type="button" wire:click="chooseMode(null)" class="text-xs text-gray-500 hover:underline dark:text-gray-400">
                                Back
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
