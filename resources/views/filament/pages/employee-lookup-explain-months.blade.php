{{--
    Month-by-month table for an explain pop-up over a period longer than a
    month. $rows: each ['month' => Carbon, ...$columns keys]; $columns:
    key => heading. Each month links to the page analysing just that month.
--}}
<div>
    <h4 class="fynn-subheading">Month by month</h4>
    <div class="fynn-table-wrap overflow-x-auto">
        <table class="fynn-table">
            <thead>
                <tr>
                    <th class="{{ $thClass }}">Month</th>
                    @foreach ($columns as $heading)
                        <th class="{{ $thClass }} text-end">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="{{ $tdClass }}">
                            <x-filament::link :href="$monthUrl($row['month'])">{{ $row['month']->format('F Y') }}</x-filament::link>
                        </td>
                        @foreach (array_keys($columns) as $key)
                            <td class="{{ $tdNumber }}">{{ $money($row[$key]) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="{{ $tdClass }} font-semibold">Total</td>
                    @foreach (array_keys($columns) as $key)
                        <td class="{{ $tdNumber }} font-bold">{{ $money(array_sum(array_column($rows, $key))) }}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>
