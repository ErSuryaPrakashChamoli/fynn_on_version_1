<x-filament-panels::page>
    @php($report = $this->getReport())
    @php($headline = $report['headline'])

    <x-filament::section>
        <x-slot name="heading">Pipeline Funnel</x-slot>
        <x-slot name="description">How cases progress from lead to disbursal.</x-slot>

        @php($top = max(1, ...array_values($report['funnel'] ?: [1])))

        <div class="space-y-3">
            @foreach ($report['funnel'] as $stage => $count)
                <div>
                    <div class="mb-1 flex items-baseline justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $stage }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ number_format($count) }}
                            <span class="ml-1 text-xs">({{ $top > 0 ? round(($count / $top) * 100) : 0 }}%)</span>
                        </span>
                    </div>

                    <div class="academy-progress" style="height: 0.625rem; border-radius: 9999px; background: rgb(0 0 0 / 0.06); overflow: hidden;">
                        <div style="height:100%; border-radius:9999px; background: linear-gradient(90deg,#A6D900,#7FAF00); width: {{ $top > 0 ? round(($count / $top) * 100) : 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Product Mix</x-slot>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Product</th>
                        <th class="pb-2 text-right">Applications</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($report['products'] as $product => $count)
                        <tr>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $product }}</td>
                            <td class="py-2 text-right font-medium text-gray-900 dark:text-gray-100">
                                {{ number_format($count) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Lender Performance</x-slot>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Lender</th>
                        <th class="pb-2 text-right">Cases</th>
                        <th class="pb-2 text-right">Disbursed</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($report['lenders'] as $lender)
                        <tr>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $lender->lender }}</td>
                            <td class="py-2 text-right text-gray-700 dark:text-gray-300">{{ number_format($lender->cases) }}</td>
                            <td class="py-2 text-right font-medium text-gray-900 dark:text-gray-100">
                                ₹{{ number_format($lender->amount / 100000, 2) }} L
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Team Leaderboard</x-slot>
        <x-slot name="description">Disbursed value per executive.</x-slot>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="pb-2">#</th>
                    <th class="pb-2">Executive</th>
                    <th class="pb-2 text-right">Disbursed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($report['team'] as $name => $amount)
                    <tr>
                        <td class="py-2 text-gray-400">{{ $loop->iteration }}</td>
                        <td class="py-2 font-medium text-gray-800 dark:text-gray-200">{{ $name }}</td>
                        <td class="py-2 text-right text-gray-700 dark:text-gray-300">
                            ₹{{ number_format($amount / 100000, 2) }} L
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
