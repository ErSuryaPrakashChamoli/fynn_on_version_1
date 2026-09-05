{{--
    The monthly-target block. Deliberately not a Filament modal: this one
    cannot be dismissed, closed on escape or clicked past — the panel stays
    shut until the month's targets exist.
--}}
@php
    $blocked = $status['blocked'];
    $month = $status['month']->format('F Y');
    $missing = $status['missing'];
@endphp

<div>
    @if ($blocked)
        <div
            class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-gray-100 p-4 dark:bg-gray-950"
            role="dialog"
            aria-modal="true"
            aria-labelledby="monthly-target-prompt-heading"
        >
            <div class="w-full max-w-3xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

                {{-- Whoever owns the targets fixes them here and now. --}}
                @if ($status['reason'] === \App\Services\MonthlyTargetGate::REASON_SET_TARGETS)
                    <div class="border-b border-gray-200 px-6 py-5 dark:border-white/10">
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                icon="heroicon-o-flag"
                                class="mt-0.5 h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                            />
                            <div>
                                <h2 id="monthly-target-prompt-heading" class="text-lg font-semibold text-gray-950 dark:text-white">
                                    Fix monthly targets — {{ $month }}
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $missing->count() }} {{ str('team member')->plural($missing->count()) }}
                                    {{ $missing->count() === 1 ? 'has' : 'have' }} no commitment target for {{ $month }}.
                                    The rest of the LMS stays locked until every target is fixed.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- One stage and one number, copied onto every blank row. --}}
                    <div class="flex flex-wrap items-end gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            Same for everyone
                            <select
                                wire:model.live="bulkStage"
                                class="mt-1 block w-44 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            >
                                <option value="">Stage…</option>
                                @foreach ($stageOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($bulkStage === \App\Enums\CommitmentStage::Otp->value)
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Target OTPs
                                <input
                                    type="number"
                                    min="1"
                                    wire:model="bulkCount"
                                    class="mt-1 block w-40 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                />
                            </label>
                        @else
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Target amount (₹)
                                <input
                                    type="number"
                                    min="1"
                                    wire:model="bulkAmount"
                                    class="mt-1 block w-40 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                />
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
                        <div class="space-y-3">
                            @foreach ($missing as $employee)
                                @php
                                    $rowStage = $targets[$employee->id]['stage'] ?? null;
                                @endphp

                                <div
                                    wire:key="monthly-target-{{ $employee->id }}"
                                    class="flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5"
                                >
                                    <div class="min-w-48 flex-1">
                                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                                            {{ $employee->emp_name }}
                                        </p>
                                        <p class="text-xs {{ \App\Models\Employee::designationColorClass($employee->designation) }}">
                                            {{ $employee->emp_id }} · {{ $designations[$employee->designation] ?? '—' }}
                                        </p>
                                    </div>

                                    <select
                                        wire:model.live="targets.{{ $employee->id }}.stage"
                                        class="w-44 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                    >
                                        <option value="">Stage…</option>
                                        @foreach ($stageOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>

                                    @if ($rowStage === \App\Enums\CommitmentStage::Otp->value)
                                        <input
                                            type="number"
                                            min="1"
                                            placeholder="OTPs"
                                            wire:model="targets.{{ $employee->id }}.count"
                                            class="w-36 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                        />
                                    @else
                                        <input
                                            type="number"
                                            min="1"
                                            placeholder="Amount (₹)"
                                            wire:model="targets.{{ $employee->id }}.amount"
                                            class="w-36 rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                                        />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-6 py-4 dark:border-white/10">
                        <a
                            href="{{ \Filament\Facades\Filament::getLogoutUrl() }}"
                            class="text-sm text-gray-500 underline dark:text-gray-400"
                            onclick="event.preventDefault(); document.getElementById('monthly-target-prompt-logout').submit();"
                        >
                            Sign out
                        </a>

                        <form id="monthly-target-prompt-logout" method="POST" action="{{ \Filament\Facades\Filament::getLogoutUrl() }}" class="hidden">
                            @csrf
                        </form>

                        <x-filament::button
                            icon="heroicon-o-check"
                            wire:click="saveTargets"
                            wire:loading.attr="disabled"
                        >
                            Save targets
                        </x-filament::button>
                    </div>

                {{-- Nothing this user can do but chase whoever owns their target. --}}
                @else
                    <div class="px-6 py-8 text-center">
                        <x-filament::icon
                            icon="heroicon-o-lock-closed"
                            class="mx-auto h-10 w-10 text-warning-500"
                        />

                        <h2 id="monthly-target-prompt-heading" class="mt-4 text-lg font-semibold text-gray-950 dark:text-white">
                            Your {{ $month }} target has not been fixed
                        </h2>

                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                            @if ($status['setter'])
                                Please ask <span class="font-medium text-gray-950 dark:text-white">{{ $status['setter']->emp_name }}</span>
                                ({{ $status['setter']->emp_id }}), your Manager, to fix your monthly commitment target.
                            @else
                                Please ask your Manager to have the Admin fix your monthly commitment target.
                            @endif
                            The LMS opens again as soon as it is set.
                        </p>

                        <div class="mt-6 flex items-center justify-center gap-3">
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
