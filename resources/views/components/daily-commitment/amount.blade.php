{{--
    Every rupee figure in this module reads the same way: Indian digit
    grouping on top, the word statement underneath, so a number can be
    read back without counting commas. Count-based (OTP) figures are
    plain numbers — a headcount has no word statement.
--}}
@props(['value' => 0, 'count' => false, 'words' => true])

@if ($count)
    <span class="dc-amount"><span class="dc-amount-value">{{ number_format((float) $value) }}</span></span>
@else
    <span class="dc-amount">
        <span class="dc-amount-value">{{ indianAmount($value) }}</span>
        @if ($words)
            <span class="dc-amount-words">{{ indianAmountInWords($value) }}</span>
        @endif
    </span>
@endif
