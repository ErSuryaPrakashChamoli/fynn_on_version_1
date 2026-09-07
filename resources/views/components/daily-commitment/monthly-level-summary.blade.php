{{--
    The monthly targets of this module, one hierarchy level at a time:
    the sum of all the Callers' targets, of all the Team Leaders' and of
    all the Managers', each answerable on its own.

    No grand total, on purpose — see level-summary.blade.php.
--}}
@props(['levels' => []])

<div class="overflow-x-auto">
    <table class="dc-table">
        <thead>
            <tr>
                <th>Level</th>
                <th class="dc-num">With a target</th>
                <th class="dc-num">Monthly target</th>
                <th class="dc-num">MTD achievement</th>
                <th class="dc-num">Pending</th>
                <th class="dc-num">%</th>
                <th style="min-width: 7rem">Progress</th>
                <th class="dc-num">DRR</th>
                <th class="dc-num">Needed / day</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($levels as $level)
                @php $s = $level['summary']; @endphp
                <tr>
                    <td class="text-xs font-bold uppercase {{ \App\Models\Employee::designationColorClass($level['designation']) }}">
                        {{ $level['label'] }}
                    </td>
                    <td class="dc-num">{{ $s['people_with_target'] }}</td>
                    <td class="dc-num font-semibold"><x-daily-commitment.amount :value="$s['target']" /></td>
                    <td class="dc-num font-semibold"><x-daily-commitment.amount :value="$s['achieved']" /></td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$s['pending']" /></td>
                    <td class="dc-num">{{ $s['percentage'] }}%</td>
                    <td><x-daily-commitment.progress-bar :percentage="$s['percentage']" color="#22c55e" /></td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$s['drr']" /></td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$s['required_drr']" /></td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-sm text-gray-500">Nobody in scope has a monthly target yet.</td>
                </tr>
            @endforelse

        </tbody>
    </table>
</div>
