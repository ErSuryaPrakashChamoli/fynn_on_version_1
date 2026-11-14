@php
    $roleColor = \App\Models\Employee::designationColorClass($person->designation);
    $roleLabel = \App\Models\Employee::designationOptions()[$person->designation] ?? '—';
    $isHighlighted ??= false;
    $inline ??= false;
@endphp

<div class="{{ $inline ? 'eh-node-inline' : 'eh-node' }}">
    <span class="text-base font-bold text-gray-950 dark:text-white{{ $isHighlighted ? ' border-b-2 border-amber-500 pb-0.5' : '' }}">
        {{ $person->emp_name }}
    </span>
    <span class="dc-level {{ $roleColor }}">
        {{ $roleLabel }}
    </span>
    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">
        ID: {{ $person->emp_id }}
    </span>
    <span class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">
        Target: {{ \App\Filament\Pages\EmployeeHierarchy::targetLabel($person) }}
    </span>
    @if ($person->exit_status === 'yes')
        <x-filament::badge color="danger" size="sm">Exited</x-filament::badge>
    @endif
    @if ($isHighlighted)
        <span class="fynn-chip fynn-chip--accent">
            <x-filament::icon icon="heroicon-m-magnifying-glass" />
            Searched
        </span>
    @endif
</div>
