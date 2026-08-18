<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="text-xs font-medium text-gray-500">Employee ID</div>
            <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->emp_id }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Date of Joining</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->doj ? \Illuminate\Support\Carbon::parse($employee->doj)->format('d M Y') : '-' }}
            </div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Current Reporting Date</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->reporting_date ? \Illuminate\Support\Carbon::parse($employee->reporting_date)->format('d M Y') : '-' }}
            </div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Exit Date</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->exit_date ? \Illuminate\Support\Carbon::parse($employee->exit_date)->format('d M Y') : 'Active' }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700 md:grid-cols-3">
        <div>
            <div class="text-xs font-medium text-gray-500">Current Team Leader</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->superviser?->emp_name ?? '-' }}
            </div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Current Manager</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->manager?->emp_name ?? '-' }}
            </div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Current Cluster Manager</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $employee->clusterManager?->emp_name ?? '-' }}
            </div>
        </div>
    </div>

    <div>
        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">
            Complete Reporting / Transfer History
        </h3>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Event</th>
                        <th class="px-4 py-3 font-semibold">Reporting Date</th>
                        <th class="px-4 py-3 font-semibold">Transfer / End Date</th>
                        <th class="px-4 py-3 font-semibold">Team Leader</th>
                        <th class="px-4 py-3 font-semibold">Manager</th>
                        <th class="px-4 py-3 font-semibold">Cluster Manager</th>
                        <th class="px-4 py-3 font-semibold">Updated By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($histories as $history)
                        @php
                            $eventDate = $history->effective_date?->format('d M Y');
                            $endDate = $history->effective_to?->format('d M Y');

                            $eventLabel = match ($history->change_type) {
                                'joining' => 'Joining',
                                'transfer' => 'Transfer',
                                'promotion' => 'Promotion',
                                'exit' => 'Exit',
                                default => 'Reporting Change',
                            };

                            $teamLeader = $history->newSupervisor?->emp_name;
                            $manager = $history->newManager?->emp_name;
                            $cluster = $history->newCluster?->emp_name;
                        @endphp

                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">
                                {{ $eventLabel }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{ $eventDate ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{ $endDate ?? ($history->change_type === 'exit' ? $eventDate : 'Present') }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $teamLeader ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $manager ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $cluster ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $history->updatedBy?->name ?? 'System' }}
                            </td>
                        </tr>

                        @if ($history->change_type === 'transfer')
                            <tr class="bg-gray-50/60 dark:bg-gray-900/40">
                                <td colspan="7" class="px-4 pb-3 pt-0 text-xs text-gray-500">
                                    Previous reporting:
                                    {{ $history->oldSupervisor?->emp_name ?? '-' }}
                                    →
                                    {{ $history->oldManager?->emp_name ?? '-' }}
                                    →
                                    {{ $history->oldCluster?->emp_name ?? '-' }}
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-500">
                                No reporting history found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
