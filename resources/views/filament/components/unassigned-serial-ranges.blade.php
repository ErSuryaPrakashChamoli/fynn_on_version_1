@php
    /** @var \Illuminate\Support\Collection<int, array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}> $ranges */
    $totalRecords = $ranges->sum('count');
@endphp

<div class="fynn-card fynn-card--flush">
    <div class="fynn-subheading mb-0 flex items-center justify-between gap-2 border-b border-gray-950/5 bg-gray-50 px-4 py-2.5 dark:border-white/5 dark:bg-white/5">
        <span>Unassigned S. Nos.</span>

        <span class="fynn-chip fynn-chip--accent">
            {{ \Illuminate\Support\Number::format($totalRecords) }} {{ \Illuminate\Support\Str::plural('record', $totalRecords) }}
        </span>
    </div>

    @if ($ranges->isEmpty())
        <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
            Every record is already assigned.
        </p>
    @else
        <div class="max-h-60 overflow-y-auto">
            <table class="fynn-table">
                <thead>
                    <tr>
                        <th class="sticky top-0 z-10">S. No.</th>
                        <th class="sticky top-0 z-10 text-end">Records</th>
                        <th class="sticky top-0 z-10">Configuration</th>
                        <th class="sticky top-0 z-10">Uploaded</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($ranges as $range)
                        <tr>
                            <td class="font-semibold whitespace-nowrap">{{ \App\Support\AiRecordSerialRanges::label($range) }}</td>
                            <td class="text-end">{{ \Illuminate\Support\Number::format($range['count']) }}</td>
                            <td>{{ $range['configuration'] }}</td>
                            <td class="whitespace-nowrap">{{ $range['uploaded_on'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="border-t border-gray-950/5 px-4 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
        Covers every month, not only the month selected at the top. Each line is one template with no assigned record in between — pick it in a row below, or type part of it to split it between users.
    </p>
</div>
