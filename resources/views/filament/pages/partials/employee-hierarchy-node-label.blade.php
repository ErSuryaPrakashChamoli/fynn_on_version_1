@php
    $roleColor = \App\Models\Employee::designationColorClass($person->designation);
    $roleLabel = \App\Models\Employee::designationOptions()[$person->designation] ?? '—';
    $isHighlighted ??= false;
    $inline ??= false;
@endphp

<div class="{{ $inline ? 'eh-node-inline' : 'eh-node' }}">
    <span class="text-base font-bold text-gray-900 dark:text-white{{ $isHighlighted ? ' border-b-2 border-amber-500 pb-0.5' : '' }}">
        {{ $person->emp_name }}
    </span>
    <span class="text-xs font-bold uppercase tracking-wide {{ $roleColor }}">
        {{ $roleLabel }}
    </span>
    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">
        ID: {{ $person->emp_id }}
    </span>
    <span class="text-sm font-extrabold text-gray-900 dark:text-white">
        Target: {{ \App\Filament\Pages\EmployeeHierarchy::targetLabel($person) }}
    </span>
    @if ($person->exit_status === 'yes')
        <span class="text-xs font-bold uppercase tracking-wide text-rose-600 dark:text-rose-400">Exited</span>
    @endif
    @if ($isHighlighted)
        <span class="text-xs font-bold uppercase tracking-widest text-amber-600 dark:text-amber-400">Searched</span>
    @endif
</div>
