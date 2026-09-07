{{--
    Pass `amount` for a rupee figure (Indian grouping plus the word
    statement), `count` alongside it for an OTP headcount, or `value`
    for anything that is not money at all.
--}}
@props(['label', 'value' => null, 'amount' => null, 'count' => false, 'hint' => null, 'accent' => null])

<div class="dc-card" @if ($accent) style="border-color: {{ $accent }}55; box-shadow: inset 3px 0 0 0 {{ $accent }}" @endif>
    <div class="dc-card-label">{{ $label }}</div>

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
</div>
