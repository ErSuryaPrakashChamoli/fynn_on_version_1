{{--
    The monthly-target block. Deliberately not a Filament modal: this one
    cannot be dismissed, closed on escape or clicked past — the panel stays
    shut until the month's targets exist.

    Every block that can be swapped for another one carries a wire:key.
    Livewire morphs the DOM in place and only re-initialises Alpine on
    elements it has just ADDED, so an <input> reused across a swap keeps
    the wire:model binding it was first initialised with — the number
    typed for OTPs would still be writing to `amount`. The keys are what
    force a replacement instead of a reuse; do not remove them.
--}}
@php
    $blocked = $status['blocked'];
    $month = $status['month']->format('F Y');
    $missing = $status['missing'];

    // Presentation only: one look for every raw control in this dialog,
    // matching Filament's own inputs.
    $field = 'rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500';
    $fieldLabel = 'text-xs font-semibold text-gray-600 dark:text-gray-300';
@endphp

<div>
    @if ($blocked)
        <div
            class="fixed inset-0 z-[9999] flex items-center-safe justify-center overflow-y-auto bg-gray-100 p-4 dark:bg-gray-950"
            style="background-image: radial-gradient(70% 55% at 50% 0%, color-mix(in oklab, var(--primary-500) 14%, transparent), transparent 70%)"
            role="dialog"
            aria-modal="true"
            aria-labelledby="monthly-target-prompt-heading"
        >
            <div class="fynn-card fynn-card--flush w-full max-w-3xl rounded-2xl shadow-2xl">

                {{-- Whoever owns the targets fixes them here and now. --}}
                @if ($status['reason'] === \App\Services\MonthlyTargetGate::REASON_SET_TARGETS)
                    <div class="border-b border-gray-950/5 px-6 py-5 dark:border-white/10" style="background: linear-gradient(180deg, var(--fynn-surface-2), var(--fynn-surface))">
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-500/15 text-primary-800 ring-1 ring-inset ring-primary-500/25 dark:text-primary-300">
                                <x-filament::icon
                                    icon="heroicon-o-flag"
                                    class="h-6 w-6"
                                />
                            </span>
                            <div class="min-w-0">
                                <h2 id="monthly-target-prompt-heading" class="text-lg font-extrabold tracking-tight text-gray-950 dark:text-white">
                                    Fix monthly targets — {{ $month }}
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $missing->count() }} {{ str('team member')->plural($missing->count()) }}
                                    {{ $missing->count() === 1 ? 'has' : 'have' }} no commitment target for {{ $month }}.
                                    The rest of the LMS stays locked until every target is fixed. Somebody who has gone
                                    inactive can be ticketed instead — their target is then skipped for the month.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- One stage and one number, copied onto every blank row. --}}
                    <div class="flex flex-wrap items-end gap-3 border-b border-gray-950/5 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                        <label class="{{ $fieldLabel }}">
                            Same for everyone
                            <select
                                wire:model.live="bulkStage"
                                class="mt-1.5 block w-44 {{ $field }}"
                            >
                                <option value="" @selected(blank($bulkStage))>Stage…</option>
                                @foreach ($stageOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($bulkStage === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($bulkStage === \App\Enums\CommitmentStage::Otp->value)
                            <label wire:key="bulk-count" class="{{ $fieldLabel }}">
                                Target OTPs
                                <input
                                    type="number"
                                    min="1"
                                    wire:model="bulkCount"
                                    class="mt-1.5 block w-40 tabular-nums {{ $field }}"
                                />
                            </label>
                        @else
                            <label wire:key="bulk-amount" class="{{ $fieldLabel }}">
                                Target amount (₹)
                                <input
                                    type="number"
                                    min="1"
                                    wire:model.live.debounce.500ms="bulkAmount"
                                    class="mt-1.5 block w-40 tabular-nums {{ $field }}"
                                />
                                @if (filled($bulkAmount))
                                    <span class="mt-1 block font-medium text-gray-500 dark:text-gray-400">
                                        ₹{{ indianNumberFormat($bulkAmount) }} · {{ indianAmountInWordsWithPaise($bulkAmount) }}
                                    </span>
                                @endif
                            </label>
                        @endif

                        <x-filament::button
                            color="gray"
                            size="sm"
                            icon="heroicon-o-arrows-pointing-out"
                            wire:click="applyToAll"
                        >
                            Apply to all
                        </x-filament::button>
                    </div>

                    <div class="max-h-[45vh] overflow-y-auto px-6 py-4">
                        <div class="space-y-2.5">
                            @foreach ($missing as $employee)
                                @php
                                    $rowStage = $targets[$employee->id]['stage'] ?? null;
                                @endphp

                                <div
                                    wire:key="monthly-target-{{ $employee->id }}"
                                    class="fynn-card fynn-card--muted flex flex-wrap items-center gap-3 px-3 py-2.5"
                                >
                                    <div class="flex min-w-48 flex-1 items-center gap-3">
                                        <span class="fynn-avatar fynn-avatar--sm">{{ $employee->initials }}</span>
                                        <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ $employee->emp_name }}
                                        </p>
                                        <p class="text-xs {{ \App\Models\Employee::designationColorClass($employee->designation) }}">
                                            {{ $employee->emp_id }} · {{ $designations[$employee->designation] ?? '—' }}
                                        </p>
                                        </div>
                                    </div>

                                    <select
                                        wire:model.live="targets.{{ $employee->id }}.stage"
                                        class="w-44 {{ $field }}"
                                    >
                                        <option value="" @selected(blank($rowStage))>Stage…</option>
                                        @foreach ($stageOptions as $value => $label)
                                            <option value="{{ $value }}" @selected($rowStage === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>

                                    @if ($rowStage === \App\Enums\CommitmentStage::Otp->value)
                                        <input
                                            wire:key="row-count-{{ $employee->id }}"
                                            type="number"
                                            min="1"
                                            placeholder="OTPs"
                                            wire:model="targets.{{ $employee->id }}.count"
                                            class="w-36 tabular-nums {{ $field }}"
                                        />
                                    @else
                                        <input
                                            wire:key="row-amount-{{ $employee->id }}"
                                            type="number"
                                            min="1"
                                            placeholder="Amount (₹)"
                                            wire:model.live.debounce.500ms="targets.{{ $employee->id }}.amount"
                                            class="w-36 tabular-nums {{ $field }}"
                                        />

                                        @if (filled($targets[$employee->id]['amount'] ?? null))
                                            <span class="w-full text-xs font-medium text-gray-500 sm:w-auto dark:text-gray-400">
                                                ₹{{ indianNumberFormat($targets[$employee->id]['amount']) }} · {{ indianAmountInWordsWithPaise($targets[$employee->id]['amount']) }}
                                            </span>
                                        @endif
                                    @endif

                                    {{-- Nobody should invent a number for someone who has
                                         stopped turning up: raise the ticket instead. --}}
                                    @if ($inactiveFor === $employee->id)
                                        <div wire:key="inactive-form-{{ $employee->id }}" class="w-full border-t border-gray-950/5 pt-2 dark:border-white/5">
                                            <textarea
                                                rows="2"
                                                placeholder="Why is {{ $employee->emp_name }} inactive? (seen by the Admin who reviews the ticket)"
                                                wire:model="inactiveReason"
                                                class="mt-2 block w-full {{ $field }}"
                                            ></textarea>

                                            <div class="mt-2 flex items-center gap-2">
                                                <x-filament::button
                                                    size="xs"
                                                    color="danger"
                                                    icon="heroicon-o-ticket"
                                                    wire:click="raiseInactivity({{ $employee->id }})"
                                                    wire:loading.attr="disabled"
                                                >
                                                    Raise inactivity ticket
                                                </x-filament::button>

                                                <x-filament::button size="xs" color="gray" wire:click="cancelInactive">
                                                    Cancel
                                                </x-filament::button>
                                            </div>
                                        </div>
                                    @else
                                        <x-filament::button
                                            wire:key="inactive-ask-{{ $employee->id }}"
                                            size="xs"
                                            color="gray"
                                            icon="heroicon-o-user-minus"
                                            wire:click="askInactive({{ $employee->id }})"
                                        >
                                            Mark inactive
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-950/5 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                        <a
                            href="{{ \Filament\Facades\Filament::getLogoutUrl() }}"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 underline-offset-2 transition hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white"
                            onclick="event.preventDefault(); document.getElementById('monthly-target-prompt-logout').submit();"
                        >
                            <x-filament::icon icon="heroicon-m-arrow-right-on-rectangle" class="h-4 w-4" />
                            Sign out
                        </a>

                        <form id="monthly-target-prompt-logout" method="POST" action="{{ \Filament\Facades\Filament::getLogoutUrl() }}" class="hidden">
                            @csrf
                        </form>

                        <x-filament::button
                            icon="heroicon-o-check"
                            wire:click="saveTargets"
                            wire:loading.attr="disabled"
                            size="lg"
                        >
                            Save targets
                        </x-filament::button>
                    </div>

                {{-- Nothing this user can do but chase whoever owns their target. --}}
                @else
                    <div class="px-6 py-10 text-center" style="background: radial-gradient(60% 50% at 50% 0%, color-mix(in oklab, var(--warning-500) 10%, transparent), transparent 70%)">
                        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-warning-500/15 text-warning-700 ring-8 ring-warning-500/5 dark:text-warning-300">
                            <x-filament::icon
                                icon="heroicon-o-lock-closed"
                                class="h-8 w-8"
                            />
                        </span>

                        <h2 id="monthly-target-prompt-heading" class="mt-5 text-xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                            Your {{ $month }} target has not been fixed
                        </h2>

                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                            @if ($status['setter'])
                                Please ask <span class="font-semibold text-gray-950 dark:text-white">{{ $status['setter']->emp_name }}</span>
                                ({{ $status['setter']->emp_id }}), your {{ \App\Models\Employee::designationOptions()[$status['setter']->designation] ?? 'Manager' }}, to fix your monthly commitment target.
                            @elseif (\Filament\Facades\Filament::auth()->user()?->employee?->designation === \App\Models\Employee::DESIGNATION_CALLER)
                                Nobody above you in the reporting line can set it yet. Please ask the Admin to assign your Team Leader or Manager.
                            @else
                                Please ask the Admin to fix your monthly commitment target.
                            @endif
                            The LMS opens again as soon as it is set.
                        </p>

                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            <x-filament::button
                                color="gray"
                                icon="heroicon-o-arrow-path"
                                wire:click="refreshStatus"
                                wire:loading.attr="disabled"
                            >
                                Check again
                            </x-filament::button>

                            <x-filament::button
                                color="gray"
                                outlined
                                icon="heroicon-o-arrow-right-on-rectangle"
                                onclick="document.getElementById('monthly-target-prompt-logout').submit();"
                            >
                                Sign out
                            </x-filament::button>
                        </div>

                        <form id="monthly-target-prompt-logout" method="POST" action="{{ \Filament\Facades\Filament::getLogoutUrl() }}" class="hidden">
                            @csrf
                        </form>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
