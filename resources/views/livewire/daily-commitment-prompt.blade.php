{{--
    The daily commitment block. Deliberately not a Filament modal: this one
    cannot be dismissed, closed on escape or clicked past — the panel stays
    shut until the promise is given, or answered.
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
            <div class="w-full max-w-xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

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

                {{-- 18:30 — the promise has to be answered, case by case. --}}
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
                                    {{ $message }} Passed, partially met or failed — it has to be on the record before
                                    the rest of the LMS opens again.
                                </p>
                            </div>
                        </div>
                    </div>

                    @if ($commitment)
                        <div class="flex flex-wrap items-center gap-x-8 gap-y-3 border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                            <div>
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">You committed to</div>
                                <div class="mt-1">
                                    <x-daily-commitment.stage-chip :stage="$commitment->commitment_stage" />
                                </div>
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
                                @unless ($isCount)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ indianAmountInWords($commitment->commitment_amount) }}
                                    </div>
                                @endunless
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center justify-between gap-3 px-6 py-4">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Due by {{ \App\Services\DailyCommitmentGate::EVENING_DEADLINE }} every day.
                        </span>
                        <x-filament::button wire:click="goToDeclaration" icon="heroicon-o-arrow-right" color="warning">
                            Declare achievement
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
