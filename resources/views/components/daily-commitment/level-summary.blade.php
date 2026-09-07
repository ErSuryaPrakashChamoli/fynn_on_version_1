{{--
    Commitment and achievement one hierarchy level at a time. A blended
    "everyone below me" total cannot say who is behind, so the levels are
    the rows and the combined figure is only a quiet footer.
--}}
@props(['levels' => [], 'total' => null, 'totalLabel' => 'All levels combined'])

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

            @if ($total)
                <tr class="dc-total-row">
                    <td>{{ $totalLabel }}</td>
                    <td class="dc-num">{{ $total['with_commitment'] }} / {{ $total['people'] }}</td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$total['committed_amount']" /></td>
                    <td class="dc-num">{{ $total['committed_count'] > 0 ? number_format($total['committed_count']).' OTP' : '—' }}</td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$total['achieved_amount']" /></td>
                    <td class="dc-num"><x-daily-commitment.amount :value="$total['pending_amount']" /></td>
                    <td class="dc-num">{{ $total['percentage'] }}%</td>
                    <td><x-daily-commitment.progress-bar :percentage="$total['percentage']" color="#6b7280" /></td>
                    <td class="dc-num">{{ $total['met'] + $total['overachieved'] }}</td>
                    <td class="dc-num">{{ $total['failed'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
