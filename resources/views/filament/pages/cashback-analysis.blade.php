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
                Cashback and subvention read the MIS figure when the settlement has one, else the case's own (which follows every pending or approved cashback form).
            </span>
        </p>
    </div>

    @php
        $kpi = fn (string $label, string $icon, string $accent, string $value, string $hint): array => compact('label', 'icon', 'accent', 'value', 'hint');
        $cases = fn (int $count): string => $count.' '.\Illuminate\Support\Str::plural('case', $count);
        $rows = [
            'The month\'s business' => [
                $kpi('Cases punched in', 'heroicon-o-folder-open', '#3b82f6', (string) $figures['cases_punched_in'], 'Disbursed in '.$monthLabel),
                $kpi('Total disbursal', 'heroicon-o-building-library', '#0d9488', $rupees($figures['disbursal_amount']), 'On the '.$cases($figures['cases_punched_in']).' punched in'),
                $kpi('Total approval', 'heroicon-o-check-badge', '#22c55e', $rupees($figures['approval_amount']), $cases($figures['approved_cases']).' approved in '.$monthLabel),
                $kpi('Cashback + subvention % of disbursal', 'heroicon-o-scale', '#ef4444', number_format($figures['deductions_percent'], 2).'%', $rupees($figures['deductions_amount']).' of '.$rupees($figures['disbursal_amount'])),
            ],
            'Cashback' => [
                $kpi('Cases with cashback', 'heroicon-o-receipt-refund', '#8b5cf6', (string) $figures['cases_with_cashback'], number_format($figures['cases_with_cashback_percent'], 2).'% of cases punched in'),
                $kpi('Total cashback', 'heroicon-o-banknotes', '#f59e0b', $rupees($figures['cashback_amount']), 'Average '.$rupees($figures['average_cashback']).' per case with cashback'),
                $kpi('Cashback % of disbursal', 'heroicon-o-percent-badge', '#ef4444', number_format($figures['cashback_percent'], 2).'%', $rupees($figures['cashback_amount']).' of '.$rupees($figures['disbursal_amount'])),
            ],
            'Subvention' => [
                $kpi('Cases with subvention', 'heroicon-o-building-office-2', '#6366f1', (string) $figures['cases_with_subvention'], number_format($figures['cases_with_subvention_percent'], 2).'% of cases punched in'),
                $kpi('Total subvention', 'heroicon-o-currency-rupee', '#f97316', $rupees($figures['subvention_amount']), 'Average '.$rupees($figures['average_subvention']).' per case with subvention'),
                $kpi('Subvention % of disbursal', 'heroicon-o-chart-pie', '#ef4444', number_format($figures['subvention_percent'], 2).'%', $rupees($figures['subvention_amount']).' of '.$rupees($figures['disbursal_amount'])),
            ],
        ];
    @endphp

    {{-- The month's cashback and subvention against the business they were given on --}}
    @foreach ($rows as $heading => $cards)
        <div class="space-y-2">
            <div class="fynn-subheading">{{ $heading }}</div>
            <div @class(['grid grid-cols-1 gap-4 sm:grid-cols-2', 'lg:grid-cols-4' => count($cards) === 4, 'lg:grid-cols-3' => count($cards) === 3])>
                @foreach ($cards as $card)
                    <div class="fynn-kpi" style="--fynn-kpi-accent: {{ $card['accent'] }}">
                        <div class="fynn-kpi-head">
                            <span class="fynn-kpi-icon"><x-filament::icon :icon="$card['icon']" /></span>
                            <div class="fynn-kpi-label">{{ $card['label'] }}</div>
                        </div>
                        <div class="fynn-kpi-value">{{ $card['value'] }}</div>
                        <div class="fynn-kpi-hint">{{ $card['hint'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

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
        <x-filament::section :heading="($isCompany ? 'By branch' : 'By team member').' — cashback and subvention'" icon="heroicon-o-user-group">
            @include('filament.components.cashback-analysis-rows', ['rows' => $breakdown, 'total' => $figures, 'drillDown' => true])
            <p class="fynn-note">Click a name to analyse that person. Each row counts only what they did inside this branch, so the rows add up to the total.</p>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
