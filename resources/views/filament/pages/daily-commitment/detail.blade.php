<x-filament-panels::page>
    @php
        $commitment = $this->commitment;
        $row = $this->row;
        $entries = $commitment->entries;
        $stage = $commitment->commitment_stage;
        $ladder = \App\Enums\CommitmentStage::ladder();
        $isCount = $stage->isCount();
    @endphp

    {{-- Who / when --}}
    <div class="fynn-hero">
        <span class="fynn-avatar fynn-avatar--lg">
            {{ $commitment->employee?->initials }}
        </span>
        <div class="fynn-hero-main">
            <div class="fynn-hero-title">
                <span>{{ $commitment->employee?->emp_name }}</span>
                <span class="dc-level {{ \App\Models\Employee::designationColorClass($commitment->employee?->designation) }}">{{ \App\Models\Employee::designationOptions()[$commitment->employee?->designation] ?? '—' }}</span>
                <span class="fynn-chip">
                    <x-filament::icon icon="heroicon-m-identification" />
                    {{ $commitment->employee?->emp_id }}
                </span>
            </div>
            <div class="fynn-hero-meta">
                <span class="fynn-chip">
                    <x-filament::icon icon="heroicon-m-calendar-days" />
                    {{ $commitment->date->format('l, d M Y') }}
                </span>
                <x-daily-commitment.presence-chip :present="$row['present']" />
                @if ($commitment->submitted_at)
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-check-circle" />
                        final status submitted {{ $commitment->submitted_at->format('d M Y, H:i') }}
                    </span>
                @else
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-clock" />
                        final status not submitted
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Headline --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-daily-commitment.kpi label="Commitment" :amount="$row['target']" :count="$isCount" :hint="$stage->label()" :accent="$stage->hex()" icon="heroicon-o-hand-raised" />
        <x-daily-commitment.kpi label="Achievement" :amount="$row['achieved']" :count="$isCount" accent="#22c55e" icon="heroicon-o-banknotes" />
        <x-daily-commitment.kpi label="Pending" :amount="$row['pending']" :count="$isCount" accent="#f97316" icon="heroicon-o-clock" />
        <x-daily-commitment.kpi label="Achievement %" :value="$row['percentage'] . '%'" accent="#3b82f6" icon="heroicon-o-chart-pie" />
        <div class="dc-card" style="--dc-accent: #64748b">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-flag" /></span>
                <div class="dc-card-label">Final stage</div>
            </div>
            <div class="mt-2.5"><x-daily-commitment.stage-chip :stage="$row['current_stage']" muted="Not started" /></div>
        </div>
        <div class="dc-card" style="--dc-accent: #8b5cf6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-check-badge" /></span>
                <div class="dc-card-label">Result</div>
            </div>
            <div class="mt-2.5"><x-daily-commitment.result-chip :result="$row['result']" /></div>
            <div class="dc-card-hint">{{ $row['changes'] }} recorded {{ \Illuminate\Support\Str::plural('change', $row['changes']) }}</div>
        </div>
    </div>

    <div class="mt-1">
        <x-daily-commitment.progress-bar :percentage="$row['percentage']" :color="$stage->hex()" />
    </div>

    @if (! $this->canEditCommitment())
        <p class="fynn-note mt-0">
            <x-filament::icon icon="heroicon-m-lock-closed" />
            <span>The morning commitment is locked. Only an Admin can correct it, and any correction is logged below.</span>
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
        @if ($entries->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-users" /></span>
                <span>No customers have been declared against this commitment yet.</span>
            </div>
        @else
            <div class="fynn-table-wrap overflow-x-auto">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Mobile</th>
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
                                    @if ($entry->remarks)
                                        <div class="text-xs text-gray-500">{{ $entry->remarks }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">{{ $entry->mobile_no ?? '—' }}</td>
                                <td class="text-xs text-gray-500">{{ $entry->reference ?? '—' }}</td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->stage" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->lms_highest_stage" muted="Not in LMS" /></td>
                                <td><x-daily-commitment.stage-chip :stage="$entry->outcome" muted="Live" /></td>
                                <td class="dc-num font-semibold"><x-daily-commitment.amount :value="$entry->amount" /></td>
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
                    <div class="dc-card" style="--dc-accent: {{ $rung->hex() }}">
                        <x-daily-commitment.stage-chip :stage="$rung" />
                        <div class="dc-card-value dc-card-amount">{{ indianAmount($totals['amount']) }}</div>
                        <div class="dc-card-words">{{ indianAmountInWords($totals['amount']) }}</div>
                        <div class="dc-card-hint">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('case', $totals['count']) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Day by day: this employee's promise against their fulfilment, so
         how often they actually keep it is visible rather than inferred.
         The change log below is a different thing — the audit trail of
         this ONE commitment. --}}
    @php $history = $this->history; @endphp
    <x-filament::section
        icon="heroicon-o-chart-bar-square"
        heading="Commitment history"
        :description="$history['tally']['kept'] . ' of ' . $history['tally']['closed'] . ' closed days kept — ' . $history['tally']['kept_percentage'] . '%'"
    >
        <x-daily-commitment.history
            :rows="$history['rows']"
            :filtered="$history['filtered']"
            :tally="$history['tally']"
            :range="$this->historyRange"
            :result="$this->historyResult"
        />
    </x-filament::section>

    {{-- Change log --}}
    <x-filament::section
        icon="heroicon-o-clock"
        heading="Change log"
        :description="$this->logs->count() . ' recorded ' . \Illuminate\Support\Str::plural('change', $this->logs->count())"
    >
        @if ($this->logs->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-clock" /></span>
                <span>Nothing has changed since this commitment was given.</span>
            </div>
        @else
            <div class="fynn-table-wrap overflow-x-auto">
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
