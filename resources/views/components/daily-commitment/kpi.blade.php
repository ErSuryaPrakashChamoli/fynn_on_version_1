{{--
    Pass `amount` for a rupee figure (Indian grouping plus the word
    statement), `count` alongside it for an OTP headcount, or `value`
    for anything that is not money at all. `accent` colours the tile's
    rail and glow; `icon` (a Heroicon name) adds a tinted glyph beside
    the label.
--}}
@props(['label', 'value' => null, 'amount' => null, 'count' => false, 'hint' => null, 'accent' => null, 'icon' => null])

<div {{ $attributes->class('dc-card') }} @if ($accent) style="--dc-accent: {{ $accent }}" @endif>
    <div class="fynn-kpi-head">
        @if ($icon)
            <span class="fynn-kpi-icon"><x-filament::icon :icon="$icon" /></span>
        @endif
        <div class="dc-card-label">{{ $label }}</div>
    </div>

    @if (! is_null($amount))
        <div class="dc-card-value {{ $count ? '' : 'dc-card-amount' }}">
            {{ $count ? number_format((float) $amount) : indianAmount($amount) }}
        </div>
        @unless ($count)
            <div class="dc-card-words">{{ indianAmountInWords($amount) }}</div>
        @endunless
    @else
        <div class="dc-card-value">{{ $value }}</div>
    @endif

    @if ($hint)
        <div class="dc-card-hint">{{ $hint }}</div>
    @endif

    {{ $slot }}
</div>
