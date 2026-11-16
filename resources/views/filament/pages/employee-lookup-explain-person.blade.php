{{--
    One person in an explain pop-up table: name and employee ID, then
    whether they were not on the rolls in the period, joined or left during
    it (with the date), and the day they moved into / out of the team (a
    mover with no dated move keeps a plain "Moved").
--}}
@php
    $movements = $person['movements'] ?? [];
    $joinedDuringPeriod = $person['joined_during_period'] ?? null;
    $leftDuringPeriod = $person['left_during_period'] ?? null;
    $moveDateFormat = isset($period) && $period->start->year !== $period->end->year ? 'd M Y' : 'd M';
@endphp

<div class="min-w-0">
    <div class="flex flex-wrap items-baseline gap-x-1.5">
        <span class="font-medium text-gray-950 dark:text-white">{{ $person['name'] }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $person['emp_id'] ?: '—' }}</span>
    </div>

    @if (! $person['active'] || $joinedDuringPeriod !== null || $leftDuringPeriod !== null || ($person['moved'] ?? false))
        <div class="mt-1 flex flex-wrap gap-1">
            @if (! $person['active'])
                <x-filament::badge color="gray" size="sm">Not active in period</x-filament::badge>
            @else
                @if ($joinedDuringPeriod !== null)
                    <x-filament::badge
                        color="info"
                        size="sm"
                        icon="heroicon-m-user-plus"
                        :tooltip="'On the rolls from '.$joinedDuringPeriod->format('d M Y')"
                    >New joiner · {{ $joinedDuringPeriod->format($moveDateFormat) }}</x-filament::badge>
                @endif

                @if ($leftDuringPeriod !== null)
                    <x-filament::badge
                        color="danger"
                        size="sm"
                        icon="heroicon-m-user-minus"
                        :tooltip="'Last day on the rolls: '.$leftDuringPeriod->format('d M Y')"
                    >Left · {{ $leftDuringPeriod->format($moveDateFormat) }}</x-filament::badge>
                @endif
            @endif

            @if ($person['active'] && ($person['moved'] ?? false))
                @forelse ($movements as $movement)
                    @if ($movement['direction'] === 'in')
                        <x-filament::badge
                            color="success"
                            size="sm"
                            icon="heroicon-m-arrow-right-end-on-rectangle"
                            :tooltip="'First day in this team: '.$movement['on']->format('d M Y')"
                        >Moved in · {{ $movement['on']->format($moveDateFormat) }}</x-filament::badge>
                    @else
                        <x-filament::badge
                            color="warning"
                            size="sm"
                            icon="heroicon-m-arrow-left-start-on-rectangle"
                            :tooltip="'Last day in this team: '.$movement['on']->format('d M Y')"
                        >Moved out · {{ $movement['on']->format($moveDateFormat) }}</x-filament::badge>
                    @endif
                @empty
                    <x-filament::badge color="gray" size="sm">Moved</x-filament::badge>
                @endforelse
            @endif
        </div>
    @endif
</div>
