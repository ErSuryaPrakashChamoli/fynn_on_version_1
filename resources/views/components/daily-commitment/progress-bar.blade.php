@props(['percentage' => 0, 'color' => '#22c55e', 'showValue' => false])

@php $width = min(max((float) $percentage, 0), 100); @endphp

@if ($showValue)
    <div class="dc-progress">
        <div class="dc-bar" title="{{ $percentage }}%" role="progressbar" aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100">
            <span style="width: {{ $width }}%; background: {{ $color }}"></span>
        </div>
        <span class="dc-progress-value">{{ $percentage }}%</span>
    </div>
@else
    <div class="dc-bar" title="{{ $percentage }}%" role="progressbar" aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100">
        <span style="width: {{ $width }}%; background: {{ $color }}"></span>
    </div>
@endif
