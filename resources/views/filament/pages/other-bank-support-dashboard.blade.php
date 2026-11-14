@php
    /** @var \Illuminate\Support\Carbon $month */
    /** @var array<string, mixed> $summary */
    /** @var array<string, mixed>|null $myPerformance */
    /** @var \Illuminate\Support\Collection $teamRows */
    /** @var \Illuminate\Support\Collection<int, \App\Models\OtherBankIncentiveSlab> $slabs */
@endphp

<x-filament-panels::page>
    <p class="fynn-note mt-0 text-sm">
        <x-filament::icon icon="heroicon-m-calendar-days" />
        <span>
            {{ $month->format('F Y') }} — change the month from the selector in the top bar.
            Other-bank business is already part of the LMS total below; it is never added to it.
        </span>
    </p>

    {{-- Business --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-building-library" /></span>
                <div class="fynn-kpi-label">Total business (LMS)</div>
            </div>
            <div class="fynn-kpi-value">{{ indianAmount($summary['total_achievement']) }}</div>
            <div class="fynn-kpi-hint">Count achievement · {{ $summary['total_files'] }} files disbursed</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #0d9488">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-building-office-2" /></span>
                <div class="fynn-kpi-label">Other bank business</div>
            </div>
            <div class="fynn-kpi-value">{{ indianAmount($summary['other_bank_achievement']) }}</div>
            <div class="fynn-kpi-hint">Count achievement · {{ $summary['other_bank_files'] }} files disbursed</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #8b5cf6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-chart-pie" /></span>
                <div class="fynn-kpi-label">Share of total business</div>
            </div>
            <div class="fynn-kpi-value">{{ number_format($summary['share_percentage'], 2) }}%</div>
            <div class="fynn-kpi-hint">Other bank out of the LMS total</div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-banknotes" /></span>
                <div class="fynn-kpi-label">Other bank disbursed</div>
            </div>
            <div class="fynn-kpi-value">{{ indianAmount($summary['other_bank_disbursed']) }}</div>
            <div class="fynn-kpi-hint">Gross, before deductions</div>
        </div>
    </div>

    {{-- My target & incentive --}}
    @if ($myPerformance)
        <x-filament::section heading="My target & incentive" icon="heroicon-o-trophy">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-flag" /></span>
                        <div class="fynn-kpi-label">Target</div>
                    </div>
                    <div class="fynn-kpi-value">
                        {{ $myPerformance['target'] > 0 ? indianAmount($myPerformance['target']) : 'Not set' }}
                    </div>
                    @if ($myPerformance['target'] <= 0)
                        <div class="fynn-kpi-hint">The Admin has not set this month's target yet.</div>
                    @endif
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #22c55e">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-chart-bar" /></span>
                        <div class="fynn-kpi-label">Achieved</div>
                    </div>
                    <div class="fynn-kpi-value">{{ number_format($myPerformance['percentage'], 2) }}%</div>
                    <div class="fynn-kpi-hint">{{ indianAmount($myPerformance['achievement']) }} of target</div>
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-gift" /></span>
                        <div class="fynn-kpi-label">Incentive earned</div>
                    </div>
                    <div class="fynn-kpi-value">{{ indianAmount($myPerformance['incentive']) }}</div>
                    <div class="fynn-kpi-hint">
                        {{ $myPerformance['slab'] ? 'Slab from '.indianAmount($myPerformance['slab']->min_achievement) : 'No slab reached yet' }}
                    </div>
                </div>

                <div class="fynn-kpi" style="--fynn-kpi-accent: #0d9488">
                    <div class="fynn-kpi-head">
                        <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-up-circle" /></span>
                        <div class="fynn-kpi-label">Next slab</div>
                    </div>
                    @if ($myPerformance['next_slab'])
                        <div class="fynn-kpi-value">{{ indianAmount($myPerformance['remaining_to_next']) }}</div>
                        <div class="fynn-kpi-hint">
                            more to reach {{ indianAmount($myPerformance['next_slab']->min_achievement) }} ({{ $myPerformance['next_slab']->payoutLabel() }})
                        </div>
                    @else
                        <div class="fynn-kpi-value">—</div>
                        <div class="fynn-kpi-hint">{{ $slabs->isEmpty() ? 'No slabs defined' : 'Top slab reached' }}</div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- Team (Admin) --}}
    @if ($isAdmin)
        <x-filament::section heading="Support team" icon="heroicon-o-user-group">
            @if ($teamRows->isEmpty())
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                    <span>No active user holds the Other Bank Support role.</span>
                </div>
            @else
                <div class="fynn-table-wrap overflow-x-auto">
                    <table class="fynn-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="text-end">Target</th>
                                <th class="text-end">Achievement</th>
                                <th class="text-end">Achieved</th>
                                <th class="text-end">Incentive</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($teamRows as $row)
                                <tr>
                                    <td class="font-medium">{{ $row['user']->name }}</td>
                                    <td class="fynn-num">{{ $row['target'] > 0 ? indianAmount($row['target']) : 'Not set' }}</td>
                                    <td class="fynn-num">{{ indianAmount($row['achievement']) }}</td>
                                    <td class="fynn-num">{{ number_format($row['percentage'], 2) }}%</td>
                                    <td class="fynn-num font-semibold">{{ indianAmount($row['incentive']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Slabs --}}
    <x-filament::section icon="heroicon-o-queue-list">
        <x-slot name="heading">
            Incentive slabs
            @if ($slabs->isNotEmpty())
                <span class="font-normal text-gray-500 dark:text-gray-400">— in force since {{ $slabs->first()->effective_month->format('M Y') }}</span>
            @endif
        </x-slot>

        @if ($slabs->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-queue-list" /></span>
                <span>The Admin has not defined incentive slabs for this month.</span>
            </div>
        @else
            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th>Count achievement from</th>
                            <th class="text-end">Payout</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($slabs as $slab)
                            <tr>
                                <td class="font-medium tabular-nums">{{ indianAmount($slab->min_achievement) }}</td>
                                <td class="fynn-num">{{ $slab->payoutLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
