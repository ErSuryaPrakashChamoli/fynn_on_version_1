@php
    /**
     * One row per person (CashbackAnalysisService::breakdown() / forForm()['people']).
     *
     * @var list<array<string, mixed>> $rows
     * @var array<string, int|float>|null $total  shown as the footer when given
     * @var bool $withRole  first column shows each person's role on the form
     * @var bool $drillDown  names are buttons that open that person's analysis (Cashback Analysis page only)
     */
    $total ??= null;
    $withRole ??= false;
    $drillDown ??= false;
    $rupees = fn ($amount): string => '₹'.indianNumberFormat(round((float) $amount));
@endphp

<div class="fynn-table-wrap overflow-x-auto">
    <table class="fynn-table">
        <thead>
            <tr>
                @if ($withRole)
                    <th>Role</th>
                @endif
                <th>Employee</th>
                <th class="fynn-num">Cases punched in</th>
                <th class="fynn-num">Cases with cashback</th>
                <th class="fynn-num">Cashback</th>
                <th class="fynn-num">Disbursal</th>
                <th class="fynn-num">Approval</th>
                <th class="fynn-num">Cashback %</th>
                <th class="fynn-num">Avg / case</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr @class(['is-muted' => $row['employee_id'] === null])>
                    @if ($withRole)
                        <td class="font-medium">{{ $row['role'] ?? '' }}</td>
                    @endif
                    <td>
                        @if ($drillDown && $row['employee_id'] !== null)
                            <button type="button" wire:click="analyseEmployee({{ (int) $row['employee_id'] }})" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $row['name'] }}</button>
                        @else
                            <span class="font-medium">{{ $row['name'] }}</span>
                        @endif
                        @if (filled($row['designation'] ?? null))
                            <div class="fynn-list-meta">{{ $row['designation'] }}</div>
                        @endif
                    </td>
                    <td class="fynn-num">{{ $row['cases_punched_in'] }}</td>
                    <td class="fynn-num">
                        {{ $row['cases_with_cashback'] }}
                        <div class="fynn-list-meta">{{ number_format($row['cases_with_cashback_percent'], 2) }}% of cases</div>
                    </td>
                    <td class="fynn-num font-semibold">{{ $rupees($row['cashback_amount']) }}</td>
                    <td class="fynn-num">{{ $rupees($row['disbursal_amount']) }}</td>
                    <td class="fynn-num">
                        {{ $rupees($row['approval_amount']) }}
                        <div class="fynn-list-meta">{{ $row['approved_cases'] }} {{ \Illuminate\Support\Str::plural('case', $row['approved_cases']) }}</div>
                    </td>
                    <td class="fynn-num font-semibold">{{ number_format($row['cashback_percent'], 2) }}%</td>
                    <td class="fynn-num">{{ $rupees($row['average_cashback']) }}</td>
                </tr>
            @endforeach
        </tbody>
        @if ($total)
            <tfoot>
                <tr>
                    <th @if ($withRole) colspan="2" @endif>Total</th>
                    <td class="fynn-num">{{ $total['cases_punched_in'] }}</td>
                    <td class="fynn-num">{{ $total['cases_with_cashback'] }}</td>
                    <td class="fynn-num">{{ $rupees($total['cashback_amount']) }}</td>
                    <td class="fynn-num">{{ $rupees($total['disbursal_amount']) }}</td>
                    <td class="fynn-num">{{ $rupees($total['approval_amount']) }}</td>
                    <td class="fynn-num">{{ number_format($total['cashback_percent'], 2) }}%</td>
                    <td class="fynn-num">{{ $rupees($total['average_cashback']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
