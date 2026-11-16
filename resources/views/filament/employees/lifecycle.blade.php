<div class="space-y-6">
    <dl class="fynn-dl">
        <div class="fynn-dl-item">
            <dt>Employee ID</dt>
            <dd>{{ $employee->emp_id }}</dd>
        </div>
        <div class="fynn-dl-item">
            <dt>Date of Joining</dt>
            <dd>
                {{ $employee->doj ? \Illuminate\Support\Carbon::parse($employee->doj)->format('d M Y') : '-' }}
            </dd>
        </div>
        <div class="fynn-dl-item">
            <dt>Current Reporting Date</dt>
            <dd>
                {{ $employee->reporting_date ? \Illuminate\Support\Carbon::parse($employee->reporting_date)->format('d M Y') : '-' }}
            </dd>
        </div>
        <div class="fynn-dl-item">
            <dt>Exit Date</dt>
            <dd>
                {{ $employee->exit_date ? \Illuminate\Support\Carbon::parse($employee->exit_date)->format('d M Y') : 'Active' }}
            </dd>
        </div>
    </dl>

    <div class="fynn-card">
        <dl class="fynn-dl">
            <div class="fynn-dl-item">
                <dt>Current Team Leader</dt>
                <dd>
                    {{ $employee->superviser?->emp_name ?? '-' }}
                </dd>
            </div>
            <div class="fynn-dl-item">
                <dt>Current Manager</dt>
                <dd>
                    {{ $employee->manager?->emp_name ?? '-' }}
                </dd>
            </div>
            <div class="fynn-dl-item">
                <dt>Current Cluster Manager</dt>
                <dd>
                    {{ $employee->clusterManager?->emp_name ?? '-' }}
                </dd>
            </div>
            <div class="fynn-dl-item">
                <dt>Current Business Head</dt>
                <dd>
                    {{ $employee->businessHead?->emp_name ?? '-' }}
                </dd>
            </div>
        </dl>
    </div>

    <div>
        <h3 class="fynn-subheading">
            Complete Reporting / Transfer History
        </h3>

        <div class="fynn-table-wrap overflow-x-auto">
            <table class="fynn-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Reporting Date</th>
                        <th>Transfer / End Date</th>
                        <th>Team Leader</th>
                        <th>Manager</th>
                        <th>Cluster Manager</th>
                        <th>Business Head</th>
                        <th>Updated By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($histories as $history)
                        @php
                            $eventDate = $history->effective_date?->format('d M Y');
                            $endDate = $history->effective_to?->format('d M Y');

                            $isDesignationChange = $history->change_type === \App\Services\ReportingLineService::CHANGE_DESIGNATION;
                            $designationLabels = \App\Models\Employee::designationOptions();

                            $eventLabel = match (true) {
                                $isDesignationChange => match (true) {
                                    \App\Models\Employee::designationRank($history->new_designation) > \App\Models\Employee::designationRank($history->old_designation) => 'Promotion',
                                    \App\Models\Employee::designationRank($history->new_designation) < \App\Models\Employee::designationRank($history->old_designation) => 'Demotion',
                                    default => 'Designation Change',
                                },
                                default => match ($history->change_type) {
                                    'joining' => 'Joining',
                                    'transfer' => 'Transfer',
                                    'promotion' => 'Promotion',
                                    'exit' => 'Exit',
                                    default => 'Reporting Change',
                                },
                            };

                            $teamLeader = $history->newSupervisor?->emp_name;
                            $manager = $history->newManager?->emp_name;
                            $cluster = $history->newCluster?->emp_name;
                            $businessHead = $history->newBusinessHead?->emp_name;
                        @endphp

                        <tr>
                            <td class="whitespace-nowrap font-medium">
                                {{ $eventLabel }}
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $eventDate ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $endDate ?? ($history->change_type === 'exit' ? $eventDate : 'Present') }}
                            </td>
                            <td>
                                {{ $teamLeader ?? '-' }}
                            </td>
                            <td>
                                {{ $manager ?? '-' }}
                            </td>
                            <td>
                                {{ $cluster ?? '-' }}
                            </td>
                            <td>
                                {{ $businessHead ?? '-' }}
                            </td>
                            <td>
                                {{ $history->updatedBy?->name ?? 'System' }}
                            </td>
                        </tr>

                        @if ($isDesignationChange)
                            <tr class="is-muted bg-gray-50/60 dark:bg-gray-900/40">
                                <td colspan="8" class="text-xs">
                                    Designation:
                                    {{ $designationLabels[$history->old_designation] ?? '-' }}
                                    →
                                    {{ $designationLabels[$history->new_designation] ?? '-' }}
                                </td>
                            </tr>
                        @endif

                        @if ($history->change_type === 'transfer')
                            <tr class="is-muted bg-gray-50/60 dark:bg-gray-900/40">
                                <td colspan="8" class="text-xs">
                                    Previous reporting:
                                    {{ $history->oldSupervisor?->emp_name ?? '-' }}
                                    →
                                    {{ $history->oldManager?->emp_name ?? '-' }}
                                    →
                                    {{ $history->oldCluster?->emp_name ?? '-' }}
                                    →
                                    {{ $history->oldBusinessHead?->emp_name ?? '-' }}
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-gray-500">
                                No reporting history found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
