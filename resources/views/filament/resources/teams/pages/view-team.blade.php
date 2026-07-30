<x-filament-panels::page>

    <x-filament::section>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">

            <div>
                <div class="text-sm text-gray-500">Employee Name</div>
                <div class="text-lg font-bold">{{ $this->record->emp_name }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Employee ID</div>
                <div>{{ $this->record->emp_id }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Designation</div>
                <div>
                    {{ \App\Models\Employee::designationOptions()[$this->record->designation] ?? '-' }}
                </div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Date of Joining</div>
                <div>{{ optional($this->record->doj)->format('d M Y') ?? '-' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Reporting Date</div>
                <div>{{ optional($this->record->reporting_date)->format('d M Y') ?? '-' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Mobile</div>
                <div>{{ $this->record->mobile }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Email</div>
                <div>{{ $this->record->email }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Exit Status</div>
                <div>{{ ucfirst($this->record->exit_status ?? 'No') }}</div>
            </div>

        </div>

    </x-filament::section>

    <x-filament::section class="mt-6">

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">

            <div>
                <div class="text-sm text-gray-500">Cluster Manager</div>
                <div>{{ $this->record->cluster?->emp_name ?? '-' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Manager</div>
                <div>{{ $this->record->manager?->emp_name ?? '-' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Team Leader</div>
                <div>{{ $this->record->superviser?->emp_name ?? '-' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">Team Size</div>
                <div>{{ \App\Support\HierarchyHelper::children($this->record)->count() }}</div>
            </div>

        </div>

    </x-filament::section>

    <x-filament::section class="mt-6">

        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-4">

            <div>
                <div class="text-xs text-gray-500">Target</div>
                <div class="text-lg font-bold">
                    ₹{{ number_format($this->performance['target'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Actual</div>
                <div class="text-lg font-bold text-success-600">
                    ₹{{ number_format($this->performance['actual'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Cashback</div>
                <div>
                    ₹{{ number_format($this->performance['cashback'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Subvention</div>
                <div>
                    ₹{{ number_format($this->performance['subvention'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Docking</div>
                <div>
                    ₹{{ number_format($this->performance['docking'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Count Achievement</div>
                <div>
                    ₹{{ number_format($this->performance['count_achievement'] ?? 0) }}
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Achievement %</div>
                <div>
                    {{ number_format($this->performance['percentage'] ?? 0, 2) }}%
                </div>
            </div>

            <div>
                <div class="text-xs text-gray-500">Incentive</div>
                <div class="text-primary-600 font-bold">
                    ₹{{ number_format($this->performance['incentive'] ?? 0) }}
                </div>
            </div>

        </div>

    </x-filament::section>

    <div class="mt-6">
        {{ $this->table }}
    </div>

</x-filament-panels::page>
