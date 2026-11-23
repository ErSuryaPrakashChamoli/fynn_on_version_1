@php
    /**
     * The analysis behind one cashback form (CashbackAnalysisService::forForm()):
     * the saved one from when it was raised and/or the live one.
     *
     * @var list<array{title: string, analysis: array<string, mixed>}> $blocks
     * @var string|null $note
     */
    $note ??= null;
    $rupees = fn ($amount): string => '₹'.indianNumberFormat(round((float) $amount));
@endphp

<div class="space-y-4">
    @foreach ($blocks as $block)
        @php
            $analysis = $block['analysis'];
            $case = $analysis['case'] ?? [];
            $form = $analysis['form'] ?? [];
        @endphp
        <div class="space-y-2">
            <div class="fynn-subheading">{{ $block['title'] }}</div>
            <p class="fynn-note mt-0">
                <x-filament::icon icon="heroicon-m-calendar-days" />
                <span>
                    {{ $analysis['month_label'] ?? '' }} —
                    {{ ($analysis['month_basis'] ?? 'disbursal') === 'disbursal'
                        ? 'the month this case was disbursed in'.(filled($case['disbursed_on'] ?? null) ? ' ('.\Illuminate\Support\Carbon::parse($case['disbursed_on'])->format('d M Y').')' : '').', where its cashback counts.'
                        : 'the month the form was raised (the case is not disbursed yet).' }}
                    This case: cashback {{ $rupees($case['cashback_amount'] ?? 0) }} ({{ number_format((float) ($case['cashback_percent'] ?? 0), 2) }}%)
                    @if (array_key_exists('subvention_amount', $case))
                        and subvention {{ $rupees($case['subvention_amount']) }} ({{ number_format((float) ($case['subvention_percent'] ?? 0), 2) }}%) —
                        {{ number_format((float) ($case['deductions_percent'] ?? 0), 2) }}% together —
                    @endif
                    on {{ $rupees($case['disbursal_amount'] ?? 0) }} disbursal.
                    This form {{ $form['voucher_no'] ?? '' }}: {{ $rupees($form['amount'] ?? 0) }} {{ strtolower($form['type'] ?? '') }}@if (($form['subvention_amount'] ?? null) !== null), subvention declared {{ $rupees($form['subvention_amount']) }}@endif.
                </span>
            </p>
            @if (empty($analysis['people']))
                <div class="fynn-empty fynn-empty--compact">
                    <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                    <span>Nobody on this case's chain to analyse.</span>
                </div>
            @else
                @include('filament.components.cashback-analysis-rows', ['rows' => $analysis['people'], 'withRole' => true])
            @endif
        </div>
    @endforeach

    @if ($note)
        <p class="fynn-note">{{ $note }}</p>
    @endif
</div>
