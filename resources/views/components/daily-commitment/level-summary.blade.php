{{--
    Commitment and achievement one hierarchy level at a time.

    There is deliberately no grand total row. A Manager's commitment
    already covers the team underneath them, so adding the Managers', the
    Team Leaders' and the Callers' figures together counts the same
    business two and three times over — a number nobody is answerable
    for. Each level stands on its own line and nowhere else.
--}}
@props(['levels' => []])

<div class="overflow-x-auto">
    <table class="dc-table">
        <thead>
            <tr>
                <th>Level</th>
                <th class="dc-num">People</th>
                <th class="dc-num">Committed (₹)</th>
                <th class="dc-num">Committed OTPs</th>
                <th class="dc-num">Achievement</th>
                <th class="dc-num">Pending</th>
                <th class="dc-num">%</th>
                <th style="min-width: 7rem">Progress</th>
                <th class="dc-num">Met</th>
                <th class="dc-num">Failed</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($levels as $level)
                @php $s = $level['summary']; @endphp
                <tr>
                    <td class="text-xs font-bold uppercase {{ \App\Models\Employee::designationColorClass($level['designation']) }}">
                        {{ $level['label'] }}
                    </td>
                    <td class="dc-num">
                        {{ $s['with_commitment'] }} / {{ $s['people'] }}
                    </td>
                    <td class="dc-num font-semibold">
                        <x-daily-commitment.amount :value="$s['committed_amount']" />
                    </td>
                    <td class="dc-num" title="OTP commitments are a headcount, never added to a rupee total">
                        {{ $s['committed_count'] > 0 ? number_format($s['committed_count']).' OTP' : '—' }}
                    </td>
                    <td class="dc-num font-semibold">
                        <x-daily-commitment.amount :value="$s['achieved_amount']" />
                    </td>
                    <td class="dc-num">
                        <x-daily-commitment.amount :value="$s['pending_amount']" />
                    </td>
                    <td class="dc-num">{{ $s['percentage'] }}%</td>
                    <td>
                        <x-daily-commitment.progress-bar :percentage="$s['percentage']" color="#22c55e" />
                    </td>
                    <td class="dc-num">{{ $s['met'] + $s['overachieved'] }}</td>
                    <td class="dc-num">{{ $s['failed'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-sm text-gray-500">Nobody in scope for this period.</td>
                </tr>
            @endforelse

        </tbody>
    </table>
</div>
