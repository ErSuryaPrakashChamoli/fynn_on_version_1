@props(['percentage' => 0, 'color' => '#22c55e'])

<div class="dc-bar" title="{{ $percentage }}%">
    <span style="width: {{ min(max((float) $percentage, 0), 100) }}%; background: {{ $color }}"></span>
</div>
