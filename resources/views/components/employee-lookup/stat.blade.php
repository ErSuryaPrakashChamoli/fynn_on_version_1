{{--
    One figure on the Employee Lookup page. With `figure` the whole card is a
    button that opens the "how is this calculated?" pop-up
    (EmployeeLookup::explainAction); with `href` it links to the list the
    figure counts; with neither it is a plain card. `icon` (a Heroicon
    name) and `accent` (any CSS colour) are optional decoration.
--}}
@props([
    'label',
    'value',
    'figure' => null,
    'href' => null,
    'hint' => null,
    'large' => false,
    'icon' => null,
    'accent' => null,
])

@php
    $classes = ['fynn-kpi', 'fynn-kpi--lg' => $large];
    $style = $accent ? "--fynn-kpi-accent: {$accent}" : null;
    $hint ??= $figure ? 'How is this calculated?' : 'View list';
@endphp

@if ($figure)
    <button type="button" wire:click="mountAction('explain', { figure: '{{ $figure }}' })" {{ $attributes->class($classes) }} @if ($style) style="{{ $style }}" @endif>
@elseif ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} @if ($style) style="{{ $style }}" @endif>
@else
    <div {{ $attributes->class($classes) }} @if ($style) style="{{ $style }}" @endif>
@endif
        <div class="fynn-kpi-head">
            @if ($icon)
                <span class="fynn-kpi-icon"><x-filament::icon :icon="$icon" /></span>
            @endif
            <div class="fynn-kpi-label">{{ $label }}</div>
        </div>

        <div class="fynn-kpi-value">{{ $value }}</div>

        @if ($slot->isNotEmpty())
            <div class="fynn-kpi-body">{{ $slot }}</div>
        @endif

        @if ($figure || $href)
            <span class="fynn-link-hint">
                {{ $hint }}
                <x-filament::icon icon="heroicon-m-arrow-right" />
            </span>
        @endif
@if ($figure)
    </button>
@elseif ($href)
    </a>
@else
    </div>
@endif
