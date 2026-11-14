{{-- One person in an explain pop-up table: name, employee ID, and whether they moved team or were not on the rolls in the period. --}}
<div class="flex flex-wrap items-center gap-1.5">
    <span>{{ $person['name'] }}</span>
    <span class="text-xs text-gray-500">({{ $person['emp_id'] ?: '—' }})</span>
    @if (! $person['active'])
        <x-filament::badge color="gray" size="sm">Not active in period</x-filament::badge>
    @elseif ($person['moved'] ?? false)
        <x-filament::badge color="info" size="sm">Moved</x-filament::badge>
    @endif
</div>
