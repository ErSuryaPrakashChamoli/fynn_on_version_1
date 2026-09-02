<x-filament-panels::page>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">
            Find Employee
        </label>
        <p class="mb-3 text-xs text-gray-500">
            Start typing an employee ID or name — matching employees appear in a dropdown so you can pick the right one.
        </p>

        {{ $this->form }}
    </div>

    @if ($employee)
        @php
            $roleColor = \App\Models\Employee::designationColorClass($employee->designation);
            $roleLabel = \App\Models\Employee::designationOptions()[$employee->designation] ?? '—';
            $chainTopFirst = array_reverse($this->bottomToTop);
        @endphp

        {{-- Profile header --}}
        <div class="flex flex-wrap items-center gap-4 border-b border-gray-200 pb-5 dark:border-gray-700">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {{ $employee->initials }}
            </span>

            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $employee->emp_name }}</span>
                    <span class="text-xs font-bold uppercase tracking-wide {{ $roleColor }}">{{ $roleLabel }}</span>
                    @if ($employee->exit_status === 'yes')
                        <span class="text-xs font-semibold uppercase tracking-wide text-rose-500">Exited</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-bold text-gray-800 dark:text-gray-200">ID: {{ $employee->emp_id }}</span>
                    @if ($employee->position) &middot; {{ $employee->position }} @endif
                    @if ($employee->email) &middot; {{ $employee->email }} @endif
                </div>
                <div class="text-base font-extrabold text-gray-900 dark:text-white">
                    Current Month Target: {{ \App\Filament\Pages\EmployeeHierarchy::targetLabel($employee) }}
                </div>
            </div>
        </div>

        {{-- Reporting To --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Reporting To <span class="font-normal normal-case text-gray-400">— bottom to top</span>
            </h3>

            <div class="overflow-x-auto">
                <div class="eh-chain py-6">
                    @foreach ($chainTopFirst as $index => $person)
                        @include('filament.pages.partials.employee-hierarchy-node-label', ['person' => $person, 'isHighlighted' => $person->is($employee)])

                        @if (! $loop->last)
                            <div class="eh-chain-connector"></div>
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($chainTopFirst) === 1)
                <p class="text-sm text-gray-500">This employee has no one above them in the hierarchy.</p>
            @endif
        </div>

        {{-- Reportees --}}
        <div>
            <h3 class="border-b border-gray-200 pb-2 text-sm font-bold uppercase tracking-wide text-gray-700 dark:border-gray-700 dark:text-gray-300">
                Reportees <span class="font-normal normal-case text-gray-400">— top to bottom</span>
            </h3>

            @php $tree = $this->downwardTree; @endphp

            @if (empty($tree['children']))
                <p class="pt-3 text-sm text-gray-500">No one currently reports to {{ $employee->emp_name }}.</p>
            @else
                <ul class="eh-vtree py-4">
                    @include('filament.pages.partials.employee-hierarchy-node', ['node' => $tree, 'highlightId' => $employee->id])
                </ul>
            @endif
        </div>
    @endif
</x-filament-panels::page>
