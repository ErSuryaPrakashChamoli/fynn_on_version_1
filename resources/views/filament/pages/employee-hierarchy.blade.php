<x-filament-panels::page>
    <x-filament::section
        heading="Find Employee"
        description="Start typing an employee ID or name — matching employees appear in a dropdown so you can pick the right one."
        icon="heroicon-o-magnifying-glass"
    >
        {{ $this->form }}
    </x-filament::section>

    @if ($employee)
        @php
            $roleColor = \App\Models\Employee::designationColorClass($employee->designation);
            $roleLabel = \App\Models\Employee::designationOptions()[$employee->designation] ?? '—';
            $chainTopFirst = array_reverse($this->bottomToTop);
        @endphp

        {{-- Profile header --}}
        <div class="fynn-hero">
            <span class="fynn-avatar fynn-avatar--lg">
                {{ $employee->initials }}
            </span>

            <div class="fynn-hero-main">
                <div class="fynn-hero-title">
                    <span>{{ $employee->emp_name }}</span>
                    <span class="dc-level {{ $roleColor }}">{{ $roleLabel }}</span>
                    @if ($employee->exit_status === 'yes')
                        <x-filament::badge color="danger" size="sm">Exited</x-filament::badge>
                    @endif
                </div>
                <div class="fynn-hero-meta">
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-identification" />
                        ID: {{ $employee->emp_id }}
                    </span>
                    @if ($employee->position)
                        <span class="fynn-chip">
                            <x-filament::icon icon="heroicon-m-briefcase" />
                            {{ $employee->position }}
                        </span>
                    @endif
                    @if ($employee->email)
                        <span class="fynn-chip">
                            <x-filament::icon icon="heroicon-m-envelope" />
                            {{ $employee->email }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="fynn-hero-aside">
                <span class="fynn-chip fynn-chip--accent px-3 py-1 text-sm font-bold">
                    <x-filament::icon icon="heroicon-m-flag" />
                    Current Month Target: {{ \App\Filament\Pages\EmployeeHierarchy::targetLabel($employee) }}
                </span>
            </div>
        </div>

        {{-- Reporting To --}}
        <x-filament::section icon="heroicon-o-arrow-up-circle">
            <x-slot name="heading">
                Reporting To <span class="font-normal text-gray-500 dark:text-gray-400">— bottom to top</span>
            </x-slot>

            <div class="overflow-x-auto">
                <div class="eh-chain py-2">
                    @foreach ($chainTopFirst as $index => $person)
                        @include('filament.pages.partials.employee-hierarchy-node-label', ['person' => $person, 'isHighlighted' => $person->is($employee)])

                        @if (! $loop->last)
                            <div class="eh-chain-connector"></div>
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($chainTopFirst) === 1)
                <p class="fynn-note">
                    <x-filament::icon icon="heroicon-m-information-circle" />
                    <span>This employee has no one above them in the hierarchy.</span>
                </p>
            @endif
        </x-filament::section>

        {{-- Reportees --}}
        <x-filament::section icon="heroicon-o-user-group">
            <x-slot name="heading">
                Reportees <span class="font-normal text-gray-500 dark:text-gray-400">— top to bottom</span>
            </x-slot>

            @php $tree = $this->downwardTree; @endphp

            @if (empty($tree['children']))
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                    <span>No one currently reports to {{ $employee->emp_name }}.</span>
                </div>
            @else
                <ul class="eh-vtree">
                    @include('filament.pages.partials.employee-hierarchy-node', ['node' => $tree, 'highlightId' => $employee->id])
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
