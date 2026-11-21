@php
    /** @var array<string, int|float> $figures */
    /** @var list<array<string, mixed>> $breakdown */
    /** @var array<string, int|float> $requests */
    /** @var string $subject */
    /** @var string $monthLabel */
    /** @var bool $isCurrentMonth */
    /** @var bool $isCompany */
    $rupees = fn ($amount): string => indianAmount(round((float) $amount));
@endphp

<x-filament-panels::page>
    <div class="fynn-card">
        {{ $this->form }}
        <p class="fynn-note">
            <x-filament::icon icon="heroicon-m-calendar-days" />
            <span>
                Analysing <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $subject }}</span>
                for {{ $monthLabel }}{{ $isCurrentMonth ? ' till '.now()->format('d M Y') : '' }}{{ $isCompany ? '' : ', with their team down the line' }}.
                Cases punched in are the cases disbursed in the month, each counted for the team its owner was in on that day — the same cases as the achievement figures.
                Cashback reads the MIS figure when the settlement has one, else the case's own (every pending or approved cashback form, less returns).
            </span>
        </p>
    </div>

    {{-- The month's cashback against the business it was given on --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-folder-open" /></span>
                <div class="fynn-kpi-label">Cases punched in</div>
            </div>
            <div class="fynn-kpi-value">{{ $figures['cases_punched_in'] }}</div>
            <div class="fynn-kpi-hint">Disbursed in {{ $monthLabel }}</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #8b5cf6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-receipt-refund" /></span>
                <div class="fynn-kpi-label">Cases with cashback</div>
            </div>
            <div class="fynn-kpi-value">{{ $figures['cases_with_cashback'] }}</div>
            <div class="fynn-kpi-hint">{{ number_format($figures['cases_with_cashback_percent'], 2) }}% of cases punched in</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-banknotes" /></span>
                <div class="fynn-kpi-label">Total cashback</div>
            </div>
            <div class="fynn-kpi-value">{{ $rupees($figures['cashback_amount']) }}</div>
            <div class="fynn-kpi-hint">Average {{ $rupees($figures['average_cashback']) }} per case with cashback</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #0d9488">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-building-library" /></span>
                <div class="fynn-kpi-label">Total disbursal</div>
            </div>
            <div class="fynn-kpi-value">{{ $rupees($figures['disbursal_amount']) }}</div>
            <div class="fynn-kpi-hint">On the {{ $figures['cases_punched_in'] }} cases punched in</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #22c55e">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-check-badge" /></span>
                <div class="fynn-kpi-label">Total approval</div>
            </div>
            <div class="fynn-kpi-value">{{ $rupees($figures['approval_amount']) }}</div>
            <div class="fynn-kpi-hint">{{ $figures['approved_cases'] }} {{ \Illuminate\Support\Str::plural('case', $figures['approved_cases']) }} approved in {{ $monthLabel }}</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #ef4444">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-percent-badge" /></span>
                <div class="fynn-kpi-label">Cashback % of disbursal</div>
            </div>
            <div class="fynn-kpi-value">{{ number_format($figures['cashback_percent'], 2) }}%</div>
            <div class="fynn-kpi-hint">{{ $rupees($figures['cashback_amount']) }} of {{ $rupees($figures['disbursal_amount']) }}</div>
        </div>
    </div>

    {{-- How the month's cashback requests stand --}}
    <x-filament::section heading="Cashback requests raised in {{ $monthLabel }}" icon="heroicon-o-clipboard-document-list">
        <div class="fynn-table-wrap overflow-x-auto">
            <table class="fynn-table">
                <thead>
                    <tr>
                        <th class="fynn-num">Raised</th>
                        <th class="fynn-num">Waiting for approval</th>
                        <th class="fynn-num">Approved</th>
                        <th class="fynn-num">Paid</th>
                        <th class="fynn-num">Rejected / retrieved</th>
                        <th class="fynn-num">Returned by customers</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fynn-num">{{ $requests['raised'] }} · {{ $rupees($requests['raised_amount']) }}</td>
                        <td class="fynn-num">{{ $requests['pending'] }} · {{ $rupees($requests['pending_amount']) }}</td>
                        <td class="fynn-num">{{ $requests['approved'] }} · {{ $rupees($requests['approved_amount']) }}</td>
                        <td class="fynn-num">{{ $requests['paid'] }} · {{ $rupees($requests['paid_amount']) }}</td>
                        <td class="fynn-num">{{ $requests['rejected'] }}</td>
                        <td class="fynn-num">{{ $rupees($requests['returned_amount']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="fynn-note">Forms raised this month, whichever month their case was disbursed in — a form on an earlier month's case adds to that month's cashback above.</p>
    </x-filament::section>

    {{-- Split by the people reporting to them --}}
    @if ($breakdown !== [])
        <x-filament::section :heading="$isCompany ? 'By branch' : 'By team member'" icon="heroicon-o-user-group">
            @include('filament.components.cashback-analysis-rows', ['rows' => $breakdown, 'total' => $figures, 'drillDown' => true])
            <p class="fynn-note">Click a name to analyse that person. Each row counts only what they did inside this branch, so the rows add up to the total.</p>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
