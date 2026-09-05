<x-filament-panels::page>
    @php
        $commitment = $this->commitment;
        $row = $this->row;
        $entries = $commitment->entries;
        $stage = $commitment->commitment_stage;
        $ladder = \App\Enums\CommitmentStage::ladder();
        $fmt = fn ($v) => $stage->isCount() ? number_format($v) : shortIndianAmount($v);
    @endphp

    {{-- Who / when --}}
    <div class="flex flex-wrap items-center gap-4 border-b border-gray-200 pb-5 dark:border-gray-700">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            {{ $commitment->employee?->initials }}
        </span>
        <div class="min-w-0">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $commitment->employee?->emp_name }}</span>
                <span class="text-xs font-bold uppercase tracking-wide {{ \App\Models\Employee::designationColorClass($commitment->employee?->designation) }}">
                    {{ \App\Models\Employee::designationOptions()[$commitment->employee?->designation] ?? '—' }}
                </span>
                <span class="text-xs text-gray-500">{{ $commitment->employee?->emp_id }}</span>
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400">
                {{ $commitment->date->format('l, d M Y') }}
                · {{ $row['present'] ? 'Present' : 'Absent' }}
                @if ($commitment->submitted_at)
                    · final status submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}
                @else
                    · final status not submitted
                @endif
            </div>
        </div>
    </div>

    {{-- Headline --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-daily-commitment.kpi label="Commitment" :value="$fmt($row['target'])" :hint="$stage->label()" :accent="$stage->hex()" />
        <x-daily-commitment.kpi label="Achievement" :value="$fmt($row['achieved'])" accent="#22c55e" />
        <x-daily-commitment.kpi label="Pending" :value="$fmt($row['pending'])" accent="#f97316" />
        <x-daily-commitment.kpi label="Achievement %" :value="$row['percentage'] . '%'" accent="#3b82f6" />
        <div class="dc-card">
            <div class="dc-card-label">Final stage</div>
            <div class="mt-2"><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="Not started" /></div>
        </div>
        <div class="dc-card">
            <div class="dc-card-label">Result</div>
            <div class="mt-2"><x-daily-commitment.result-chip :result="$row['result']" /></div>
            <div class="dc-card-hint">{{ $row['changes'] }} recorded {{ \Illuminate\Support\Str::plural('change', $row['changes']) }}</div>
        </div>
    </div>

    <div class="mt-1">
        <x-daily-commitment.progress-bar :percentage="$row['percentage']" :color="$stage->hex()" />
    </div>

    @if (! $this->canEditCommitment())
        <p class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4" />
            The morning commitment is locked. Only an Admin can correct it, and any correction is logged below.
        </p>
    @endif

    @if ($commitment->remarks)
        <x-filament::section icon="heroicon-o-chat-bubble-left-ellipsis" heading="Remarks" compact>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $commitment->remarks }}</p>
        </x-filament::section>
    @endif

    {{-- Customer-wise fulfilment --}}
    <x-filament::section
        icon="heroicon-o-users"
        heading="Customers declared"
        :description="$entries->count() . ' ' . \Illuminate\Support\Str::plural('case', $entries->count()) . ' claimed against this commitment'"
    >
        @if ($stage->isCount())
            <p class="text-sm text-gray-500">
                An OTP commitment is counted automatically from the cases opened that day
                ({{ $row['actual_otp'] }}), so no customer list is declared.
            </p>
        @elseif ($entries->isEmpty())
            <p class="text-sm text-gray-500">No customers have been declared against this commitment yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Lead / App ID</th>
                            <th>Declared stage</th>
                            <th>LMS highest stage</th>
                            <th>Outcome</th>
                            <th class="dc-num">Amount</th>
                            <th>Counts?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php $counts = $entry->countsToward($stage); @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold">{{ $entry->customer_name }}</div>
                                    @if ($entry->customer)
                                        <div class="text-xs text-gray-500">{{ $entry->customer->mobile_no }}</div>
                                    @endif
                                    @if ($entry->remarks)
                                        <div class="text-xs text-gray-500">{{ $entry->remarks }}</div>
                                    @endif
                                </td>
                                <td class="text-xs text-gray-500">{{ $entry->reference ?? '—' }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->stage" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->lms_highest_stage" muted="Not in LMS" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->outcome" muted="Live" /></td>
                                <td class="dc-num font-semibold">{{ shortIndianAmount($entry->amount) }}</td>
                                <td>
                                    @if ($counts)
                                        <span class="dc-chip bg-green-100 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30">
                                            Counts at {{ $entry->effectiveStage()->label() }}
                                        </span>
                                    @else
                                        <span class="dc-chip bg-gray-200 text-gray-600 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30">
                                            Below {{ $stage->label() }}
                                        </span>
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

    {{-- Change log --}}
    <x-filament::section
        icon="heroicon-o-clock"
        heading="Change log"
        :description="$this->logs->count() . ' recorded ' . \Illuminate\Support\Str::plural('change', $this->logs->count())"
    >
        @if ($this->logs->isEmpty())
            <p class="text-sm text-gray-500">Nothing has changed since this commitment was given.</p>
        @else
            <div class="overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Change</th>
                            <th>By</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->logs as $log)
                            <tr>
                                <td class="whitespace-nowrap">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                                <td>
                                    <span class="dc-chip {{ $log->change_type === 'admin_correction'
                                        ? 'bg-orange-100 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-400/30'
                                        : 'bg-blue-100 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-400/30' }}">
                                        {{ \Illuminate\Support\Str::headline($log->change_type) }}
                                    </span>
                                </td>
                                <td class="text-xs text-gray-500">{{ $log->employee?->emp_name ?? 'System' }}</td>
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
                                <td class="text-xs text-gray-500">{{ $log->note ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
