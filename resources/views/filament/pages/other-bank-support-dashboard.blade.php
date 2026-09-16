@php
    /** @var \Illuminate\Support\Carbon $month */
    /** @var array<string, mixed> $summary */
    /** @var array<string, mixed>|null $myPerformance */
    /** @var \Illuminate\Support\Collection $teamRows */
    /** @var \Illuminate\Support\Collection<int, \App\Models\OtherBankIncentiveSlab> $slabs */
@endphp

<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ $month->format('F Y') }} — change the month from the selector in the top bar.
        Other-bank business is already part of the LMS total below; it is never added to it.
    </p>

    {{-- Business --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total business (LMS)</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianAmount($summary['total_achievement']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Count achievement · {{ $summary['total_files'] }} files disbursed</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Other bank business</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianAmount($summary['other_bank_achievement']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Count achievement · {{ $summary['other_bank_files'] }} files disbursed</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Share of total business</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ number_format($summary['share_percentage'], 2) }}%</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Other bank out of the LMS total</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Other bank disbursed</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianAmount($summary['other_bank_disbursed']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Gross, before deductions</div>
        </div>
    </div>

    {{-- My target & incentive --}}
    @if ($myPerformance)
        <div>
            <h3 class="border-b border-gray-200 pb-2 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                My target &amp; incentive
            </h3>

            <div class="grid grid-cols-1 gap-4 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">
                        {{ $myPerformance['target'] > 0 ? indianAmount($myPerformance['target']) : 'Not set' }}
                    </div>
                    @if ($myPerformance['target'] <= 0)
                        <div class="text-xs text-gray-500 dark:text-gray-400">The Admin has not set this month's target yet.</div>
                    @endif
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Achieved</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ number_format($myPerformance['percentage'], 2) }}%</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ indianAmount($myPerformance['achievement']) }} of target</div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Incentive earned</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianAmount($myPerformance['incentive']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $myPerformance['slab'] ? 'Slab from '.indianAmount($myPerformance['slab']->min_achievement) : 'No slab reached yet' }}
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Next slab</div>
                    @if ($myPerformance['next_slab'])
                        <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ indianAmount($myPerformance['remaining_to_next']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            more to reach {{ indianAmount($myPerformance['next_slab']->min_achievement) }} ({{ $myPerformance['next_slab']->payoutLabel() }})
                        </div>
                    @else
                        <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">—</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $slabs->isEmpty() ? 'No slabs defined' : 'Top slab reached' }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Team (Admin) --}}
    @if ($isAdmin)
        <div>
            <h3 class="border-b border-gray-200 pb-2 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Support team
            </h3>

            @if ($teamRows->isEmpty())
                <p class="py-4 text-sm text-gray-500 dark:text-gray-400">No active user holds the Other Bank Support role.</p>
            @else
                <div class="overflow-x-auto py-4">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2">User</th>
                                <th class="px-3 py-2 text-right">Target</th>
                                <th class="px-3 py-2 text-right">Achievement</th>
                                <th class="px-3 py-2 text-right">Achieved</th>
                                <th class="px-3 py-2 text-right">Incentive</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($teamRows as $row)
                                <tr>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $row['user']->name }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['target'] > 0 ? indianAmount($row['target']) : 'Not set' }}</td>
                                    <td class="px-3 py-2 text-right">{{ indianAmount($row['achievement']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['percentage'], 2) }}%</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ indianAmount($row['incentive']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- Slabs --}}
    <div>
        <h3 class="border-b border-gray-200 pb-2 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
            Incentive slabs
            @if ($slabs->isNotEmpty())
                <span class="font-normal normal-case text-gray-500">— in force since {{ $slabs->first()->effective_month->format('M Y') }}</span>
            @endif
        </h3>

        @if ($slabs->isEmpty())
            <p class="py-4 text-sm text-gray-500 dark:text-gray-400">The Admin has not defined incentive slabs for this month.</p>
        @else
            <div class="overflow-x-auto py-4">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Count achievement from</th>
                            <th class="px-3 py-2 text-right">Payout</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($slabs as $slab)
                            <tr>
                                <td class="px-3 py-2 text-gray-950 dark:text-white">{{ indianAmount($slab->min_achievement) }}</td>
                                <td class="px-3 py-2 text-right">{{ $slab->payoutLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
