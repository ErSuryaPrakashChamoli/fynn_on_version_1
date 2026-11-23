@php
    /**
     * One row per person (CashbackAnalysisService::breakdown() / forForm()['people']).
     * Analyses saved before subvention was analysed have no subvention keys: those cells show "—".
     *
     * @var list<array<string, mixed>> $rows
     * @var array<string, int|float>|null $total  shown as the footer when given
     * @var bool $withRole  first column shows each person's role on the form
     * @var bool $drillDown  names are buttons that open that person's analysis (Cashback Analysis page only)
     */
    $total ??= null;
    $withRole ??= false;
    $drillDown ??= false;
    $rupees = fn ($amount): string => $amount === null ? '—' : '₹'.indianNumberFormat(round((float) $amount));
    $percent = fn ($value): string => $value === null ? '—' : number_format((float) $value, 2).'%';
    $cases = fn ($count): string => $count === null ? '' : $count.' '.\Illuminate\Support\Str::plural('case', (int) $count);
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
                <th class="fynn-num">Cashback</th>
                <th class="fynn-num">Subvention</th>
                <th class="fynn-num">Disbursal</th>
                <th class="fynn-num">Approval</th>
                <th class="fynn-num">Cashback %</th>
                <th class="fynn-num">Subvention %</th>
                <th class="fynn-num">Cashback + subvention %</th>
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
                        <span class="font-semibold">{{ $rupees($row['cashback_amount']) }}</span>
                        <div class="fynn-list-meta">{{ $cases($row['cases_with_cashback']) }} · {{ $percent($row['cases_with_cashback_percent']) }} of cases</div>
                    </td>
                    <td class="fynn-num">
                        <span class="font-semibold">{{ $rupees($row['subvention_amount'] ?? null) }}</span>
                        @if (array_key_exists('cases_with_subvention', $row))
                            <div class="fynn-list-meta">{{ $cases($row['cases_with_subvention']) }} · {{ $percent($row['cases_with_subvention_percent'] ?? null) }} of cases</div>
                        @endif
                    </td>
                    <td class="fynn-num">{{ $rupees($row['disbursal_amount']) }}</td>
                    <td class="fynn-num">
                        {{ $rupees($row['approval_amount']) }}
                        <div class="fynn-list-meta">{{ $cases($row['approved_cases']) }}</div>
                    </td>
                    <td class="fynn-num font-semibold">{{ $percent($row['cashback_percent']) }}</td>
                    <td class="fynn-num font-semibold">{{ $percent($row['subvention_percent'] ?? null) }}</td>
                    <td class="fynn-num font-semibold">{{ $percent($row['deductions_percent'] ?? null) }}</td>
                </tr>
            @endforeach
        </tbody>
        @if ($total)
            <tfoot>
                <tr>
                    <th @if ($withRole) colspan="2" @endif>Total</th>
                    <td class="fynn-num">{{ $total['cases_punched_in'] }}</td>
                    <td class="fynn-num">{{ $rupees($total['cashback_amount']) }}</td>
                    <td class="fynn-num">{{ $rupees($total['subvention_amount'] ?? null) }}</td>
                    <td class="fynn-num">{{ $rupees($total['disbursal_amount']) }}</td>
                    <td class="fynn-num">{{ $rupees($total['approval_amount']) }}</td>
                    <td class="fynn-num">{{ $percent($total['cashback_percent']) }}</td>
                    <td class="fynn-num">{{ $percent($total['subvention_percent'] ?? null) }}</td>
                    <td class="fynn-num">{{ $percent($total['deductions_percent'] ?? null) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
